<?php
/**
 * WhatsApp (loaded by includes/functions.php) — optional; nothing happens until somebody configures it in Settings › WhatsApp.
 *
 *  LEVEL 1  click to chat   waClickUrl()  → a wa.me link with the message ready; the person presses Send in their own WhatsApp.
 *  LEVEL 2  automatic       waQueue()     → a row in whatsapp_queue; waProcessQueue() (every 5 minutes, from cron/send_emails.php) sends it with the
 *                                           official WhatsApp Business Cloud API, through the sender of the class's teacher or the school's sender.
 * Rules for automatic sending: the school switches are on, the sender is enabled and has its kind switched on, the RECIPIENT gave a number and agreed
 * (Settings › WhatsApp; a "STOP" reply withdraws it), nothing at night (quiet hours), a daily limit per sender, one message per kind / person / ref,
 * three tries. Texts are editable per kind and language (school wording, or a teacher's own); each text uses every {placeholder} of its kind exactly
 * once, so the same text can be submitted to Meta as a template ({{1}}, {{2}} …).
 *
 *   waSetting($key)                 a setting with its default (all of them are editable in Settings › WhatsApp)
 *   waKinds()                       kind => [label, help, params, audience, click_only, text[en|ps|fa]]
 *   waText($kind, $lang, $owner)    ['body', 'template'] — the teacher's wording, else the school's, else the built-in one
 *   waRender($body, $params)        the finished message
 *   waNormalizeNumber($raw)         digits with country code, or null
 *   waCanReach($user_id)            number + agreement present?
 *   waSenderFor($class_id, $teacher_id)  the enabled sender to use for that class (the teacher's own, else the school's), or null
 *   waQueue(...) / waProcessQueue() / waClickUrl(...) / waDailyJobs() / waHandleWebhook(...)
 */

function waDefaults() {
    return [
        'wa_enabled' => '0',          // master switch
        'wa_click_on' => '1',         // level 1 buttons
        'wa_auto_on' => '0',          // level 2 automatic sending
        'wa_teachers_own' => '1',     // teachers may use their own sender
        'wa_quiet_from' => '21:00', 'wa_quiet_to' => '07:00',
        'wa_default_cc' => '93',      // country code added to numbers written with a leading 0
        'wa_default_lang' => 'en',
        'wa_api_version' => 'v21.0',
        'wa_retry_max' => '3', 'wa_retry_gap_min' => '15',
        'wa_deadline_time' => '17:00', // the "due tomorrow" summary to students
        'wa_todo_time' => '07:30',     // the "today" summary to teachers
        'wa_stop_words' => 'STOP, UNSUBSCRIBE, ودرېږه, توقف',
        'wa_webhook_token' => '',
        'wa_app_secret' => '',        // stored encrypted
        'wa_require_number' => '1',   // a student must give a valid WhatsApp number (own or a family member's) when WhatsApp is on
        'wa_school_name' => '',       // wording {school}; empty = the school's name
    ];
}

function waSetting($key) {
    $d = waDefaults();
    $v = getSetting($key, $d[$key] ?? '');
    if ($key === 'wa_app_secret') return decryptSecret((string) $v);
    return (string) $v;
}

function waOn() { return waSetting('wa_enabled') === '1'; }
function waClickOn() { return waOn() && waSetting('wa_click_on') === '1'; }
function waAutoOn() { return waOn() && waSetting('wa_auto_on') === '1'; }

// ---------------------------------------------------------------- message kinds & texts
function waKinds() {
    return [
        'absence' => ['label' => 'Absence alert', 'help' => 'To a student when they are marked absent', 'params' => ['name', 'class', 'date'], 'audience' => 'student', 'auto' => true,
            'text' => ['en' => 'Hello {name}, you were marked absent in {class} on {date}. If this is a mistake, please tell your teacher.',
                       'ps' => 'سلام {name}، تاسو د {class} په ټولګي کې د {date} په ورځ غیرحاضر نښه شوئ. که دا تېروتنه ده، مهرباني وکړئ ښوونکي ته ووایاست.',
                       'fa' => 'سلام {name}، شما در صنف {class} در تاریخ {date} غایب ثبت شده‌اید. اگر اشتباه است، لطفاً به آموزگار خود بگویید.']],
        'warning' => ['label' => 'Attendance warning', 'help' => 'To a student whose attendance is below the limit (when the teacher sends a reminder)', 'params' => ['name', 'class', 'percent', 'limit'], 'audience' => 'student', 'auto' => true,
            'text' => ['en' => 'Hello {name}, your attendance in {class} is {percent}% (the limit is {limit}%). Please attend the next classes.',
                       'ps' => 'سلام {name}، ستاسو حاضري په {class} کې {percent}٪ ده (حد {limit}٪ دی). مهرباني وکړئ راتلونکو درسونو ته حاضر شئ.',
                       'fa' => 'سلام {name}، حاضری شما در {class} {percent}٪ است (حد {limit}٪ است). لطفاً در درس‌های بعدی حاضر شوید.']],
        'deadline' => ['label' => 'Due tomorrow (students)', 'help' => 'One message in the afternoon with what is due tomorrow — only when something is', 'params' => ['name', 'items'], 'audience' => 'student', 'auto' => true,
            'text' => ['en' => 'Hello {name}, due tomorrow: {items}.', 'ps' => 'سلام {name}، سبا سپارل کېږي: {items}.', 'fa' => 'سلام {name}، فردا تحویل می‌شود: {items}.']],
        'teacher_todo' => ['label' => 'Today for teachers', 'help' => 'One message in the morning with what needs the teacher — only when something does', 'params' => ['name', 'items'], 'audience' => 'teacher', 'auto' => true,
            'text' => ['en' => 'Good morning {name}. Today: {items}.', 'ps' => 'سهار مو پخیر {name}. نن: {items}.', 'fa' => 'صبح بخیر {name}. امروز: {items}.']],
        'research_due' => ['label' => 'Research milestone due', 'help' => 'To a student two days before a research milestone is due', 'params' => ['name', 'title', 'date'], 'audience' => 'student', 'auto' => true,
            'text' => ['en' => 'Hello {name}, your research milestone "{title}" is due on {date}.',
                       'ps' => 'سلام {name}، ستاسو د څېړنې پړاو «{title}» د {date} په ورځ سپارل کېږي.',
                       'fa' => 'سلام {name}، مرحلهٔ تحقیق شما «{title}» در تاریخ {date} تحویل می‌شود.']],
        'result' => ['label' => 'Result announced', 'help' => 'To a student when the result of a quiz or assignment is released (the marks themselves are not sent)', 'params' => ['name', 'title', 'class'], 'audience' => 'student', 'auto' => true,
            'text' => ['en' => 'Hello {name}, the result of "{title}" in {class} is now available. Please open SmartClass to see it.',
                       'ps' => 'سلام {name}، د {class} د «{title}» پایله اوس شته. مهرباني وکړئ د لیدو لپاره SmartClass پرانیزئ.',
                       'fa' => 'سلام {name}، نتیجهٔ «{title}» در {class} اکنون موجود است. لطفاً برای دیدن آن SmartClass را باز کنید.']],
        'test' => ['label' => 'Test message', 'help' => 'Sent to yourself by the "Send me a test" button', 'params' => ['name', 'school'], 'audience' => 'any', 'auto' => false,
            'text' => ['en' => 'Hello {name}, this is a test message from {school}. WhatsApp is working.', 'ps' => 'سلام {name}، دا د {school} له خوا یو ازموینیز پیغام دی. واټس‌اپ کار کوي.', 'fa' => 'سلام {name}، این یک پیام آزمایشی از {school} است. واتس‌اپ کار می‌کند.']],
        'announcement' => ['label' => 'Share an announcement', 'help' => 'Level 1 only: the "Share on WhatsApp" button of an announcement', 'params' => ['class', 'text'], 'audience' => 'any', 'auto' => false, 'click_only' => true,
            'text' => ['en' => '{class}: {text}', 'ps' => '{class}: {text}', 'fa' => '{class}: {text}']],
    ];
}

function waLangs() { return ['en' => 'English', 'ps' => 'پښتو', 'fa' => 'دری']; }
function waLang($code) { return isset(waLangs()[$code]) ? $code : (isset(waLangs()[waSetting('wa_default_lang')]) ? waSetting('wa_default_lang') : 'en'); }

/** Check a wording: only this kind's {placeholders}; for automatic kinds every one exactly once (so it can be a Meta template). Returns an error text or null. */
function waValidateText($kind, $body) {
    $k = waKinds()[$kind] ?? null;
    if (!$k) return 'Unknown message.';
    $body = trim($body);
    if ($body === '') return 'Write the message text.';
    if (mb_strlen($body) > 900) return 'The text must be under 900 characters.';
    preg_match_all('/\{(\w+)\}/', $body, $m);
    foreach ($m[1] as $tok) if (!in_array($tok, $k['params'], true)) return 'Unknown placeholder {' . $tok . '}. You can use: {' . implode('}, {', $k['params']) . '}.';
    if (empty($k['click_only'])) {
        $count = array_count_values($m[1]);
        foreach ($k['params'] as $p) if (($count[$p] ?? 0) !== 1) return 'Use {' . $p . '} exactly once (the text is also sent to WhatsApp as a template).';
        if (preg_match('/^\s*\{\w+\}|\{\w+\}\s*$/u', $body)) return 'WhatsApp does not allow a text that starts or ends with a placeholder. Add a word before or after.';
        if (strpos($body, "\n\n\n") !== false) return 'Remove the extra empty lines.';
    }
    return null;
}

/** ['body', 'template'] for a kind and language: the owner's own wording, else the school's, else the built-in text. */
function waText($kind, $lang, $owner_id = null) {
    global $db;
    $k = waKinds()[$kind] ?? null; if (!$k) return null;
    $lang = isset(waLangs()[$lang]) ? $lang : 'en';
    $row = null;
    if ($owner_id) $row = $db->fetchOne("SELECT body, template_name FROM whatsapp_texts WHERE owner_id = ? AND kind = ? AND lang = ?", [(int) $owner_id, $kind, $lang]);
    if (!$row) $row = $db->fetchOne("SELECT body, template_name FROM whatsapp_texts WHERE owner_id IS NULL AND kind = ? AND lang = ?", [$kind, $lang]);
    return ['body' => $row['body'] ?? $k['text'][$lang], 'template' => trim((string) ($row['template_name'] ?? '')) !== '' ? $row['template_name'] : null, 'custom' => (bool) $row];
}

function waRender($body, array $params) {
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => (string) ($params[$m[1]] ?? ''), $body);
}

/** The same text as a Meta template body: {name} → {{1}} in the order of the kind's placeholders. */
function waMetaText($kind, $body) {
    $k = waKinds()[$kind]; $i = 0; $map = [];
    foreach ($k['params'] as $p) $map[$p] = '{{' . (++$i) . '}}';
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => $map[$m[1]] ?? $m[0], $body);
}

function waSchoolName() { return waSetting('wa_school_name') !== '' ? waSetting('wa_school_name') : (defined('APP_NAME') ? APP_NAME : 'SmartClass'); }

// ---------------------------------------------------------------- numbers & agreement
/** "0700 123 456" / "+93 700 123 456" / "0093700123456" → "93700123456"; null when it cannot be a phone number. */
function waNormalizeNumber($raw) {
    $d = preg_replace('/[^\d+]/', '', (string) $raw);
    if ($d === '' ) return null;
    if (strpos($d, '+') > 0) return null;
    $d = ltrim($d, '+');
    if (strncmp($d, '00', 2) === 0) $d = substr($d, 2);
    elseif ($d[0] === '0') $d = preg_replace('/\D/', '', waSetting('wa_default_cc')) . substr($d, 1);
    return preg_match('/^[1-9]\d{7,14}$/', $d) ? $d : null;
}

function waPerson($user_id) {
    global $db;
    return $db->fetchOne("SELECT id, name, role, lang, whatsapp_number, whatsapp_consent_at FROM users WHERE id = ? AND is_active = 1", [(int) $user_id]);
}

function waCanReach($user_id) {
    $u = waPerson($user_id);
    return $u && !empty($u['whatsapp_number']) && !empty($u['whatsapp_consent_at']);
}

// ---------------------------------------------------------------- senders
function waSenderOf($owner_id) {
    global $db;
    return $db->fetchOne("SELECT * FROM whatsapp_senders WHERE " . ($owner_id ? 'owner_id = ?' : 'owner_id IS NULL'), $owner_id ? [(int) $owner_id] : []);
}

function waSenderKinds($sender) {
    $j = json_decode((string) ($sender['kinds'] ?? ''), true);
    if (!is_array($j)) $j = array_keys(array_filter(waKinds(), fn($k) => !empty($k['auto'])));
    return array_values(array_intersect($j, array_keys(waKinds())));
}

function waSenderReady($s) {
    return $s && (int) $s['is_enabled'] === 1 && $s['phone_number_id'] !== '' && decryptSecret((string) $s['token']) !== '';
}

/** Save a sender (owner NULL = the school's). $token '' keeps the stored one. Returns ['ok', 'errors']. */
function waSaveSender($owner_id, array $in) {
    global $db;
    $e = [];
    $label = trim((string) ($in['label'] ?? '')); $pid = trim((string) ($in['phone_number_id'] ?? '')); $bid = trim((string) ($in['business_id'] ?? '')); $token = trim((string) ($in['token'] ?? ''));
    $limit = trim((string) ($in['daily_limit'] ?? '0')) === '' ? 0 : (int) $in['daily_limit'];
    if (mb_strlen($label) > 100) $e['label'] = 'The label must be under 100 characters.';
    if ($pid !== '' && !preg_match('/^\d{5,25}$/', $pid)) $e['phone_number_id'] = 'The phone number ID is only digits (copy it from your WhatsApp Business account).';
    if ($bid !== '' && !preg_match('/^\d{5,25}$/', $bid)) $e['business_id'] = 'The business account ID is only digits.';
    if ($token !== '' && (strlen($token) < 20 || strlen($token) > 600 || preg_match('/\s/', $token))) $e['token'] = 'The access token looks wrong (no spaces, 20 to 600 characters).';
    if (!preg_match('/^\d{0,6}$/', trim((string) ($in['daily_limit'] ?? '0')))) $e['daily_limit'] = 'Enter a whole number, or 0 for no limit.';
    $cur = waSenderOf($owner_id);
    if (!empty($in['is_enabled']) && ($pid === '' || ($token === '' && !($cur && decryptSecret((string) $cur['token']) !== '')))) $e['is_enabled'] = 'Enter the phone number ID and the access token before switching this sender on.';
    $kinds = array_values(array_intersect((array) ($in['kinds'] ?? []), array_keys(array_filter(waKinds(), fn($k) => empty($k['click_only'])))));
    if ($e) return ['ok' => false, 'errors' => $e];
    $row = ['label' => $label, 'phone_number_id' => $pid, 'business_id' => $bid, 'is_enabled' => !empty($in['is_enabled']) ? 1 : 0, 'daily_limit' => $limit, 'kinds' => json_encode($kinds)];
    if ($token !== '') $row['token'] = encryptSecret($token);
    if ($cur) $db->update('whatsapp_senders', $row, 'id = ?', [$cur['id']]);
    else $db->insert('whatsapp_senders', $row + ['owner_id' => $owner_id ?: null]);
    return ['ok' => true, 'errors' => []];
}

/** The enabled sender for a class: the class teachers' own (the acting one first) when teachers may use their own, else the school's. */
function waSenderFor($class_id, $teacher_id = null) {
    global $db;
    if (waSetting('wa_teachers_own') === '1') {
        $ids = array_map('intval', array_column($db->fetchAll("SELECT teacher_id FROM class_teachers WHERE class_id = ? ORDER BY teacher_id = ? DESC, teacher_id", [(int) $class_id, (int) $teacher_id]), 'teacher_id'));
        foreach ($ids as $tid) { $s = waSenderOf($tid); if (waSenderReady($s)) return $s; }
    }
    $s = waSenderOf(null);
    return waSenderReady($s) ? $s : null;
}

// ---------------------------------------------------------------- level 1: click to chat
/** A wa.me link with the finished message for this person, or null (switched off, no number, no agreement). */
function waClickUrl($user_id, $kind, array $params, $owner_id = null) {
    if (!waClickOn()) return null;
    $u = waPerson($user_id);
    if (!$u || empty($u['whatsapp_number']) || empty($u['whatsapp_consent_at'])) return null;
    $lang = waLang($u['lang'] ?? '');
    $t = waText($kind, $lang, $owner_id);
    if (!$t) return null;
    return 'https://wa.me/' . $u['whatsapp_number'] . '?text=' . rawurlencode(waRender($t['body'], $params + ['name' => $u['name'], 'school' => waSchoolName()]));
}

/** Share text without a recipient (an announcement): wa.me/?text=… lets the person choose the chat or group. */
function waShareUrl($kind, array $params, $lang = null, $owner_id = null) {
    if (!waClickOn()) return null;
    $t = waText($kind, waLang($lang), $owner_id);
    return $t ? 'https://wa.me/?text=' . rawurlencode(waRender($t['body'], $params + ['school' => waSchoolName()])) : null;
}

// ---------------------------------------------------------------- level 2: queue and sending
function waTimeToday($hhmm, $day = null) { return date('Y-m-d', strtotime($day ?: 'today')) . ' ' . (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hhmm) ? $hhmm : '00:00') . ':00'; }

/** Is $ts (unix) inside the quiet hours? (from > to means over midnight, e.g. 21:00–07:00) */
function waIsQuiet($ts = null) {
    $ts = $ts ?: time(); $hm = date('H:i', $ts); $f = waSetting('wa_quiet_from'); $t = waSetting('wa_quiet_to');
    if ($f === $t) return false;
    return $f < $t ? ($hm >= $f && $hm < $t) : ($hm >= $f || $hm < $t);
}

/** The first moment from $ts on that is not quiet. */
function waNextOpen($ts = null) {
    $ts = $ts ?: time();
    if (!waIsQuiet($ts)) return $ts;
    $to = waSetting('wa_quiet_to'); $day = date('Y-m-d', $ts);
    $cand = strtotime(waTimeToday($to, $day));
    return $cand > $ts ? $cand : strtotime(waTimeToday($to, date('Y-m-d', strtotime($day . ' +1 day'))));
}

/**
 * Queue one automatic message. Returns the queue id, or null with the reason in $why: switches off, nobody reachable, no sender, kind not switched on for the sender,
 * already queued (same kind + person + ref).
 */
function waQueue($recipient_id, $kind, $ref, array $params, $class_id = null, $acting_teacher_id = null, &$why = null) {
    global $db;
    $k = waKinds()[$kind] ?? null;
    if (!$k || !empty($k['click_only'])) { $why = 'unknown kind'; return null; }
    if (!waAutoOn()) { $why = 'automatic sending is off'; return null; }
    $u = waPerson($recipient_id);
    if (!$u || empty($u['whatsapp_number']) || empty($u['whatsapp_consent_at'])) { $why = 'no number or no agreement'; return null; }
    $sender = $class_id ? waSenderFor($class_id, $acting_teacher_id) : null;
    if (!$sender) { $s = waSenderOf(null); $sender = waSenderReady($s) ? $s : null; }
    if (!$sender) { $why = 'no sender'; return null; }
    if (!in_array($kind, waSenderKinds($sender), true)) { $why = 'this message is not switched on for the sender'; return null; }
    $lang = waLang($u['lang'] ?? '');
    $owner = $sender['owner_id'] ? (int) $sender['owner_id'] : null;
    $full = $params + ['name' => $u['name'], 'school' => waSchoolName()];
    $body = waRender(waText($kind, $lang, $owner)['body'], $full);
    $ref = mb_substr((string) $ref, 0, 60);
    if ($db->fetchOne("SELECT id FROM whatsapp_queue WHERE kind = ? AND recipient_id = ? AND ref = ?", [$kind, (int) $recipient_id, $ref])) { $why = 'already queued'; return null; }
    return (int) $db->insert('whatsapp_queue', ['sender_id' => (int) $sender['id'], 'recipient_id' => (int) $recipient_id, 'kind' => $kind, 'ref' => $ref, 'lang' => $lang,
        'params' => json_encode($full, JSON_UNESCAPED_UNICODE), 'body' => $body, 'status' => 'pending', 'send_after' => date('Y-m-d H:i:s')]);
}

function waApiBase() {
    $o = getenv('SC_WA_BASE');   // tests point this at a fake server
    return $o ? rtrim($o, '/') : 'https://graph.facebook.com/' . preg_replace('/[^v\d.]/', '', waSetting('wa_api_version'));
}

/** Send one template message. $http (tests) = fn($url, $payload, $headers) → [status, body]. Returns ['ok', 'id', 'error']. */
function waSendTemplate($sender, $to, $template, $langcode, array $values, ?callable $http = null) {
    $url = waApiBase() . '/' . $sender['phone_number_id'] . '/messages';
    $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $template, 'language' => ['code' => $langcode],
        'components' => [['type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => preg_replace('/\s*[\r\n\t]+\s*/', ' ', mb_substr((string) $v, 0, 400))], $values)]]]];
    $headers = ['Authorization: Bearer ' . decryptSecret((string) $sender['token']), 'Content-Type: application/json'];
    if ($http) { [$status, $body] = $http($url, $payload, $headers); }
    else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (getenv('SC_WA_BASE') ? CURLPROTO_HTTP : 0)]);
        $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
        if ($body === false) return ['ok' => false, 'id' => null, 'error' => 'Could not reach WhatsApp: ' . $err];
    }
    $j = json_decode((string) $body, true);
    if ($status >= 200 && $status < 300 && !empty($j['messages'][0]['id'])) return ['ok' => true, 'id' => (string) $j['messages'][0]['id'], 'error' => null];
    return ['ok' => false, 'id' => null, 'error' => mb_substr((string) ($j['error']['message'] ?? ('HTTP ' . $status)), 0, 280)];
}

/** Send what is due. Returns ['sent', 'failed', 'skipped', 'waiting']. */
function waProcessQueue($limit = 30, ?callable $http = null) {
    global $db;
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'waiting' => 0];
    if (!waAutoOn()) return $out;
    $rows = $db->fetchAll("SELECT q.*, s.owner_id AS sender_owner, s.phone_number_id, s.token, s.is_enabled, s.daily_limit, s.kinds FROM whatsapp_queue q INNER JOIN whatsapp_senders s ON s.id = q.sender_id
        WHERE q.status = 'pending' AND q.send_after <= NOW() ORDER BY q.id LIMIT " . (int) $limit);
    $max = max(1, (int) waSetting('wa_retry_max')); $gap = max(1, (int) waSetting('wa_retry_gap_min'));
    $mark = fn($id, $status, $err = null, $extra = []) => $db->update('whatsapp_queue', ['status' => $status, 'error' => $err] + $extra, 'id = ?', [$id]);
    foreach ($rows as $q) {
        $id = (int) $q['id'];
        if (!waSenderReady($q) || !in_array($q['kind'], waSenderKinds($q), true)) { $mark($id, 'skipped', 'The sender was switched off or no longer sends this message.'); $out['skipped']++; continue; }
        $u = waPerson($q['recipient_id']);
        if (!$u || empty($u['whatsapp_number']) || empty($u['whatsapp_consent_at'])) { $mark($id, 'skipped', 'The person withdrew their agreement or number.'); $out['skipped']++; continue; }
        if (waIsQuiet()) { $db->update('whatsapp_queue', ['send_after' => date('Y-m-d H:i:s', waNextOpen())], 'id = ?', [$id]); $out['waiting']++; continue; }
        $sent_today = (int) $db->fetchOne("SELECT COUNT(*) c FROM whatsapp_queue WHERE sender_id = ? AND status = 'sent' AND sent_at >= CURDATE()", [(int) $q['sender_id']])['c'];
        if ((int) $q['daily_limit'] > 0 && $sent_today >= (int) $q['daily_limit']) { $db->update('whatsapp_queue', ['send_after' => date('Y-m-d H:i:s', strtotime('tomorrow ' . waSetting('wa_quiet_to')))], 'id = ?', [$id]); $out['waiting']++; continue; }
        $lang = $q['lang']; $owner = $q['sender_owner'] ? (int) $q['sender_owner'] : null;
        $t = waText($q['kind'], $lang, $owner);
        $tpl = $t['template'] ?? ($lang !== 'en' ? (waText($q['kind'], 'en', $owner)['template'] ?? null) : null);
        if (!$tpl) { $mark($id, 'failed', 'No WhatsApp template name is set for this message in ' . (waLangs()[$lang] ?? $lang) . '. Set it in Settings › WhatsApp › Messages.', ['attempts' => $q['attempts'] + 1]); $out['failed']++; continue; }
        $useLang = $t['template'] ? $lang : 'en';
        $params = json_decode((string) $q['params'], true) ?: [];
        $values = array_map(fn($p) => $params[$p] ?? '', waKinds()[$q['kind']]['params']);
        $r = waSendTemplate($q, $u['whatsapp_number'], $tpl, $useLang, $values, $http);
        if ($r['ok']) { $mark($id, 'sent', null, ['attempts' => $q['attempts'] + 1, 'sent_at' => date('Y-m-d H:i:s'), 'wa_message_id' => $r['id']]); $out['sent']++; }
        elseif ($q['attempts'] + 1 >= $max) { $mark($id, 'failed', $r['error'], ['attempts' => $q['attempts'] + 1]); $out['failed']++; }
        else { $db->update('whatsapp_queue', ['attempts' => $q['attempts'] + 1, 'error' => $r['error'], 'send_after' => date('Y-m-d H:i:s', time() + $gap * 60)], 'id = ?', [$id]); $out['waiting']++; }
    }
    return $out;
}

/** "Send me a test": straight to the person's own number through the given sender (not queued). Returns ['ok', 'error']. */
function waSendTest($sender, $user_id, ?callable $http = null) {
    global $db;
    $u = waPerson($user_id);
    if (!$u || empty($u['whatsapp_number']) || empty($u['whatsapp_consent_at'])) return ['ok' => false, 'error' => 'Save your own WhatsApp number and agree to messages first.'];
    if (!waSenderReady($sender)) return ['ok' => false, 'error' => 'Switch this sender on and enter its phone number ID and access token first.'];
    $lang = waLang($u['lang'] ?? ''); $owner = !empty($sender['owner_id']) ? (int) $sender['owner_id'] : null;
    $t = waText('test', $lang, $owner); $tpl = $t['template'] ?? waText('test', 'en', $owner)['template'];
    if (!$tpl) return ['ok' => false, 'error' => 'Set the template name of the "Test message" in Settings › WhatsApp › Messages (the template Meta approved).'];
    $r = waSendTemplate($sender, $u['whatsapp_number'], $tpl, $t['template'] ? $lang : 'en', [$u['name'], waSchoolName()], $http);
    $db->update('whatsapp_senders', ['last_test_at' => date('Y-m-d H:i:s'), 'last_test_ok' => $r['ok'] ? 1 : 0], 'id = ?', [(int) $sender['id']]);
    return ['ok' => $r['ok'], 'error' => $r['error']];
}

// ---------------------------------------------------------------- events and daily summaries
/** A student was marked absent: queue the alert (once per student and day / session). */
function waOnAbsence($class_id, $student_id, $date, $session, $class_name, $acting_teacher_id) {
    try { waQueue($student_id, 'absence', 'abs:' . $class_id . ':' . $date . ':' . (int) $session, ['class' => $class_name, 'date' => date('M j, Y', strtotime($date))], $class_id, $acting_teacher_id); } catch (Throwable $e) { error_log('whatsapp: ' . $e->getMessage()); }
}

function waOnWarning($class_id, $student_id, $class_name, $percent, $limit, $acting_teacher_id) {
    try { waQueue($student_id, 'warning', 'warn:' . $class_id . ':' . date('Y-m-d'), ['class' => $class_name, 'percent' => rtrim(rtrim(number_format((float) $percent, 1), '0'), '.'), 'limit' => (int) $limit], $class_id, $acting_teacher_id); } catch (Throwable $e) { error_log('whatsapp: ' . $e->getMessage()); }
}

/** Called every few minutes (cron/send_emails.php): builds the daily summaries once the set times have passed, then sends what is due. */
function waDailyJobs(?callable $http = null, $now = null) {
    $now = $now ?: time(); $today = date('Y-m-d', $now); $r = ['deadline' => 0, 'todo' => 0, 'sent' => ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'waiting' => 0]];
    if (!waAutoOn()) return $r;
    if (date('H:i', $now) >= waSetting('wa_deadline_time')) $r['deadline'] = waQueueDeadlineSummaries(date('Y-m-d', strtotime($today . ' +1 day')), $today);
    if (date('H:i', $now) >= waSetting('wa_todo_time')) $r['todo'] = waQueueTeacherTodo($today);
    $r['sent'] = waProcessQueue(30, $http);
    return $r;
}

function waQueueDeadlineSummaries($tomorrow, $ref_day) {
    global $db; $n = 0;
    $people = $db->fetchAll("SELECT u.id FROM users u WHERE u.role = 'student' AND u.is_active = 1 AND u.whatsapp_number IS NOT NULL AND u.whatsapp_consent_at IS NOT NULL");
    foreach ($people as $p) {
        $sid = (int) $p['id'];
        if ($db->fetchOne("SELECT id FROM whatsapp_queue WHERE kind = 'deadline' AND recipient_id = ? AND ref = ?", [$sid, 'due:' . $ref_day])) continue;
        $items = [];
        foreach ($db->fetchAll("SELECT a.title, c.class_name, c.id AS cid FROM assignments a INNER JOIN classes c ON c.id = a.class_id INNER JOIN class_members m ON m.class_id = c.id AND m.student_id = ?
                                WHERE c.is_active = 1 AND DATE(a.due_date) = ? AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?)", [$sid, $tomorrow, $sid]) as $a) $items[] = $a['title'] . ' (' . $a['class_name'] . ')';
        foreach ($db->fetchAll("SELECT z.title, c.class_name FROM quizzes z INNER JOIN classes c ON c.id = z.class_id INNER JOIN class_members m ON m.class_id = c.id AND m.student_id = ?
                                WHERE c.is_active = 1 AND DATE(z.due_date) = ? AND NOT EXISTS (SELECT 1 FROM quiz_attempts t WHERE t.quiz_id = z.id AND t.student_id = ? AND t.submitted_at IS NOT NULL)", [$sid, $tomorrow, $sid]) as $z) $items[] = $z['title'] . ' (' . $z['class_name'] . ')';
        if (!$items) continue;
        $cid = (int) ($db->fetchOne("SELECT class_id FROM class_members WHERE student_id = ? ORDER BY joined_at LIMIT 1", [$sid])['class_id'] ?? 0);
        if (waQueue($sid, 'deadline', 'due:' . $ref_day, ['items' => mb_strimwidth(implode(', ', $items), 0, 350, '…')], $cid ?: null)) $n++;
    }
    return $n;
}

function waQueueTeacherTodo($day) {
    global $db; $n = 0;
    foreach ($db->fetchAll("SELECT u.id, u.name FROM users u WHERE u.role = 'teacher' AND u.is_active = 1 AND u.whatsapp_todo = 1 AND u.whatsapp_number IS NOT NULL AND u.whatsapp_consent_at IS NOT NULL") as $t) {
        $tid = (int) $t['id'];
        if ($db->fetchOne("SELECT id FROM whatsapp_queue WHERE kind = 'teacher_todo' AND recipient_id = ? AND ref = ?", [$tid, 'todo:' . $day])) continue;
        $items = [];
        $att = (int) $db->fetchOne("SELECT COUNT(*) c FROM classes c WHERE c.is_active = 1 AND " . teachesClassSql('c') . " AND EXISTS (SELECT 1 FROM class_members cm WHERE cm.class_id = c.id)
            AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.class_id = c.id AND a.date = ?) AND NOT EXISTS (SELECT 1 FROM attendance_days d WHERE d.class_id = c.id AND d.date = ?)", [$tid, $day, $day])['c'];
        if ($att && !in_array((int) date('N', strtotime($day)), attendanceOffWeekdays(), true)) $items[] = $att . ' class' . ($att == 1 ? '' : 'es') . ' without attendance';
        $grade = (int) $db->fetchOne("SELECT COUNT(*) c FROM submissions s INNER JOIN assignments a ON s.assignment_id = a.id INNER JOIN classes c ON a.class_id = c.id WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND s.status IN ('submitted', 'late')", [$tid])['c'];
        if ($grade) $items[] = $grade . ' submission' . ($grade == 1 ? '' : 's') . ' to grade';
        try { $rw = researchWaiting($tid, 'teacher'); } catch (Throwable $e) { $rw = 0; }
        if ($rw) $items[] = $rw . ' research project' . ($rw == 1 ? '' : 's') . ' waiting';
        if (!$items) continue;
        $cid = (int) ($db->fetchOne("SELECT class_id FROM class_teachers WHERE teacher_id = ? LIMIT 1", [$tid])['class_id'] ?? 0);
        if (waQueue($tid, 'teacher_todo', 'todo:' . $day, ['items' => implode(', ', $items)], $cid ?: null, $tid)) $n++;
    }
    return $n;
}

// ---------------------------------------------------------------- webhook (inbound: verification + STOP)
function waVerifySignature($raw, $header) {
    $secret = waSetting('wa_app_secret');
    if ($secret === '' || !preg_match('/^sha256=([0-9a-f]{64})$/i', (string) $header, $m)) return false;
    return hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($m[1]));
}

/** An inbound text from $from: a stop word withdraws that number's agreement. Returns how many people were switched off. */
function waHandleInbound($from, $text) {
    global $db;
    $words = array_filter(array_map(fn($w) => mb_strtolower(trim($w)), preg_split('/[,،;\n]+/u', waSetting('wa_stop_words'))));
    if (!in_array(mb_strtolower(trim((string) $text)), $words, true)) return 0;
    $num = waNormalizeNumber($from) ?? preg_replace('/\D/', '', (string) $from);
    $n = (int) $db->fetchOne("SELECT COUNT(*) c FROM users WHERE whatsapp_number = ? AND whatsapp_consent_at IS NOT NULL", [$num])['c'];
    if ($n) {
        $db->query("UPDATE users SET whatsapp_consent_at = NULL WHERE whatsapp_number = ?", [$num]);
        $db->query("UPDATE whatsapp_queue q INNER JOIN users u ON u.id = q.recipient_id SET q.status = 'skipped', q.error = 'The person replied STOP.' WHERE u.whatsapp_number = ? AND q.status = 'pending'", [$num]);
    }
    return $n;
}

/** The small WhatsApp icon button for a wa.me link ('' when there is no link: switched off, no number, or no agreement). */
function waButton($url, $label = 'Open WhatsApp') {
    if (!$url) return '';
    return '<a class="icon-btn wa-btn" href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer" title="' . htmlspecialchars(t($label)) . '" aria-label="' . htmlspecialchars(t($label)) . '"><i class="fa-brands fa-whatsapp"></i></a>';
}

/** A result was released to a student (quiz or assignment). The marks are never put in the message. */
function waOnResult($class_id, $student_id, $kind_label, $item_id, $title, $class_name, $acting_teacher_id) {
    try { waQueue($student_id, 'result', 'res:' . $kind_label . ':' . (int) $item_id, ['title' => $title, 'class' => $class_name], $class_id, $acting_teacher_id); } catch (Throwable $e) { error_log('whatsapp: ' . $e->getMessage()); }
}

/** Whose number it is. Keys are stored; labels are translated on the page. */
function waRelations() { return ['self' => 'My own number', 'father' => 'My father', 'uncle' => 'My uncle', 'brother' => 'My elder brother', 'other' => 'Another family member']; }

/** Must a student give a number? Only while WhatsApp is on and the school asks for it. */
function waNumberRequired() { return waOn() && waSetting('wa_require_number') === '1'; }

/**
 * Save a number for a person: the number, whose it is, and the school's agreement (recorded now — the school's rule for students is that the number is part of registering; STOP still stops it).
 * Returns the normalised number, or null when it cannot be a phone number.
 */
function waSavePerson($user_id, $raw, $relation = 'self') {
    global $db;
    $num = waNormalizeNumber($raw);
    if (!$num) return null;
    $rel = isset(waRelations()[$relation]) ? $relation : 'self';
    $same = $db->fetchOne("SELECT whatsapp_number, whatsapp_consent_at FROM users WHERE id = ?", [(int) $user_id]);
    $db->query("UPDATE users SET whatsapp_number = ?, whatsapp_relation = ?, whatsapp_consent_at = ? WHERE id = ?",
        [$num, $rel, ($same && $same['whatsapp_number'] === $num && $same['whatsapp_consent_at']) ? $same['whatsapp_consent_at'] : date('Y-m-d H:i:s'), (int) $user_id]);
    return $num;
}
