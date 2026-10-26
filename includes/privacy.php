<?php
/**
 * SmartClass - privacy and data protection (loaded by includes/functions.php)
 *   retentionRules()                 what is cleaned up and after how long (school settings, 0 = keep)
 *   retentionRun($dry)               delete what is older than the rules; returns [label => rows] (dry = only count)
 *   privacyExport($user_id)          everything the system holds about one person, as an array (JSON for download)
 *   privacyEligibility($user_id)     [canAnonymise, canDelete, reason] — admins and the last administrator are protected
 *   privacyAnonymise($user_id)       keep the school records (marks, attendance, work) but remove the person: name, e-mail, photo, contacts,
 *                                    sign-in data, notifications, AI chats and activity are gone; the account is closed
 *   privacyDelete($user_id)          remove the account and everything that depends on it (students and teachers without classes only)
 *   safeguardScan($text)             'self_harm' / 'abuse' / 'violence' / 'bullying' / 'distress' when a message mentions harm or pain, else null
 *   safeguardReplyCue($reply)        true when the AI's own answer reads like a crisis answer (a second net for what the patterns miss)
 *   safeguardFlag(...)               record it and tell the class teachers and the administrators
 * Who may read personal data: see docs/PRIVACY.md.
 */

function retentionRules() {
    // setting key => [label, default, table, date column, extra condition]
    return [
        'retention_activity_months' => ['Activity log (who did what)', 12, 'activity_log', 'created_at', '1=1'],
        'retention_ai_usage_months' => ['AI usage records', 12, 'ai_usage', 'created_at', '1=1'],
        'retention_ai_chats_months' => ['AI Tutor conversations', 12, 'ai_tutor_chats', 'updated_at', '1=1'],
        'retention_notifications_months' => ['Notifications that were read', 6, 'notifications', 'created_at', 'is_read = 1'],
        'retention_email_months' => ['Sent e-mail copies', 3, 'email_queue', 'created_at', "status IN ('sent', 'digested')"],
        'retention_attendance_log_months' => ['Attendance edit history', 24, 'attendance_log', 'changed_at', '1=1'],
    ];
}

function retentionMonths($key) {
    $rules = retentionRules();
    $v = getSetting($key, null);
    return $v === null || $v === '' ? (int) $rules[$key][1] : max(0, min(120, (int) $v));
}

function retentionRun($dry = false) {
    global $db;
    $out = [];
    foreach (retentionRules() as $key => [$label, $def, $table, $col, $cond]) {
        $m = retentionMonths($key);
        if ($m <= 0) { $out[$label] = null; continue; }   // kept forever
        $where = "{$col} < ? AND ({$cond})";
        $cut = date('Y-m-d H:i:s', strtotime("-{$m} months"));
        try {
            if ($dry) $out[$label] = (int) $db->fetchOne("SELECT COUNT(*) c FROM {$table} WHERE {$where}", [$cut])['c'];
            else $out[$label] = $db->query("DELETE FROM {$table} WHERE {$where}", [$cut])->rowCount();
        } catch (Throwable $e) { $out[$label] = 0; }
    }
    // ever-short-lived data, always cleaned
    if (!$dry) {
        foreach (["login_codes" => "expires_at < NOW() - INTERVAL 1 DAY", "password_resets" => "expires_at < NOW() - INTERVAL 7 DAY", "attendance_checkin_tries" => "tried_at < NOW() - INTERVAL 1 DAY"] as $t => $w) {
            try { $db->query("DELETE FROM {$t} WHERE {$w}"); } catch (Throwable $e) {}
        }
    }
    return $out;
}

const PRIVACY_SECRET_COLUMNS = ['password', 'totp_secret', 'totp_last_step', 'code_hash', 'token_hash', 'email_token', 'api_key', 'key_hint', 'rkey'];

/** Every table / column that points at users(id). */
function privacyUserColumns() {
    global $db;
    static $cols = null;
    if ($cols === null) {
        $cols = $db->fetchAll("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users' ORDER BY TABLE_NAME, COLUMN_NAME");
    }
    return $cols;
}

function privacyExport($user_id) {
    global $db;
    $user_id = (int) $user_id;
    $me = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$user_id]);
    if (!$me) return null;
    foreach (PRIVACY_SECRET_COLUMNS as $c) unset($me[$c]);
    $out = ['exported_at' => date('c'), 'system' => APP_NAME, 'account' => $me, 'records' => []];
    $ids = [];   // table => ids found, to follow children
    foreach (privacyUserColumns() as $r) {
        $t = $r['t']; $c = $r['c'];
        if (in_array($t, ['ai_providers', 'rate_limits'], true) && $c === 'owner_id') {   // provider settings: say that one exists, never its key
            $n = (int) $db->fetchOne("SELECT COUNT(*) c FROM {$t} WHERE {$c} = ?", [$user_id])['c'];
            if ($n) $out['records']["{$t}.{$c}"] = ["{$n} saved item(s) (secret keys are never exported)"];
            continue;
        }
        $rows = $db->fetchAll("SELECT * FROM {$t} WHERE {$c} = ? LIMIT 5000", [$user_id]);
        if (!$rows) continue;
        foreach ($rows as &$row) foreach (PRIVACY_SECRET_COLUMNS as $s) unset($row[$s]);
        unset($row);
        $out['records']["{$t}.{$c}"] = $rows;
        if (isset($rows[0]['id'])) $ids[$t] = array_merge($ids[$t] ?? [], array_column($rows, 'id'));
    }
    // children of the person's own rows
    $child = function ($table, $col, $parent) use ($db, $ids, &$out) {
        $p = $ids[$parent] ?? []; if (!$p) return;
        $in = implode(',', array_map('intval', $p));
        $rows = $db->fetchAll("SELECT * FROM {$table} WHERE {$col} IN ({$in}) LIMIT 20000");
        if ($rows) $out['records']["{$table} (of {$parent})"] = $rows;
    };
    $child('ai_tutor_messages', 'chat_id', 'ai_tutor_chats');
    $child('quiz_answers', 'attempt_id', 'quiz_attempts');
    $child('private_messages', 'id', 'private_messages');
    return $out;
}

/** [canAnonymise, canDelete, reason] */
function privacyEligibility($user_id) {
    global $db;
    $u = $db->fetchOne("SELECT id, role, is_admin, is_active FROM users WHERE id = ?", [(int) $user_id]);
    if (!$u) return [false, false, 'No such person.'];
    if ($u['is_admin']) return [false, false, 'An administrator account cannot be removed here — make someone else the administrator first.'];
    $classes = (int) $db->fetchOne("SELECT COUNT(*) c FROM class_teachers WHERE teacher_id = ?", [(int) $user_id])['c'] + (int) $db->fetchOne("SELECT COUNT(*) c FROM classes WHERE teacher_id = ?", [(int) $user_id])['c'];
    $can_delete = $u['role'] === 'student' || $classes === 0;
    return [true, $can_delete, $can_delete ? '' : 'A teacher who still teaches classes can be anonymised, but only deleted after the classes are moved or archived.'];
}

function privacyAnonymise($user_id) {
    global $db;
    $user_id = (int) $user_id;
    [$can] = privacyEligibility($user_id);
    if (!$can) return false;
    $u = $db->fetchOne("SELECT photo FROM users WHERE id = ?", [$user_id]);
    if ($u && $u['photo'] && ($abs = uploadAbsolutePath($u['photo']))) @unlink($abs);
    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $db->update('users', ['name' => 'Former user #' . $user_id, 'email' => 'removed-' . $user_id . '@removed.invalid', 'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'photo' => null, 'is_active' => 0, 'last_login' => null, 'guardian_name' => null, 'guardian_email' => null, 'guardian_phone' => null,
            'totp_secret' => null, 'totp_enabled' => 0, 'totp_last_step' => 0, 'email_token' => null], 'id = ?', [$user_id]);
        foreach (['notifications' => 'user_id', 'email_queue' => 'user_id', 'email_preferences' => 'user_id', 'login_codes' => 'user_id', 'password_resets' => 'user_id',
                  'user_backup_codes' => 'user_id', 'ai_tutor_chats' => 'student_id', 'attendance_checkin_tries' => 'student_id', 'class_join_requests' => 'student_id', 'activity_log' => 'user_id', 'ai_usage' => 'user_id'] as $t => $c) {
            try { $db->query("DELETE FROM {$t} WHERE {$c} = ?", [$user_id]); } catch (Throwable $e) {}
        }
        try { $db->query("DELETE FROM ai_providers WHERE owner_id = ?", [$user_id]); } catch (Throwable $e) {}
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return true;
}

function privacyDelete($user_id) {
    global $db;
    [, $can_del] = privacyEligibility($user_id);
    if (!$can_del) return false;
    $u = $db->fetchOne("SELECT photo FROM users WHERE id = ?", [(int) $user_id]);
    $files = [];
    foreach ([['submissions', 'student_id', 'file_path'], ['forum_attachments', 'user_id', 'file_path'], ['resources', 'uploaded_by', 'file_path']] as [$t, $c, $f]) {
        try { foreach ($db->fetchAll("SELECT {$f} p FROM {$t} WHERE {$c} = ? AND {$f} IS NOT NULL", [(int) $user_id]) as $r) $files[] = $r['p']; } catch (Throwable $e) {}
    }
    if ($u && $u['photo']) $files[] = $u['photo'];
    $db->delete('users', 'id = ?', [(int) $user_id]);
    foreach ($files as $f) if ($abs = uploadAbsolutePath($f)) @unlink($abs);
    return true;
}

/** Words that mean "a young person may be in danger". English, with a few Pashto / Dari / Urdu phrases written in Latin letters. */
function safeguardPatterns() {
    $who = "(some ?one|some ?body|he|she|they|people|everyone|everybody|my (dad|father|mom|mum|mother|uncle|aunt\\w*|brother|sister|cousin|teacher|step\\w+|husband|boss|neighbou?r|friend|\\w+))";
    $hurt = "(hurt|hurts|hurting|hit|hits|hitting|beat|beats|beating|slap\\w*|punch\\w*|kick\\w*|attack\\w*|abus\\w*|molest\\w*|rap(e|es|ed|ing)|touch(es|ed|ing)?|chok\\w*|burn\\w*|lock(s|ed|ing)?|threaten\\w*|forc(e|es|ed|ing)|stalk\\w*)";
    return [
        'self_harm' => '/\b(kill (my ?self|me)|suicid\w*|want(ed)? to die|wanna die|end (my|it all)|(take|end(ing)?) my (own )?life|hurt(ing)? my ?self|cut(ting)? my ?self|slit my|hang(ing)? my ?self|overdos\w*|jump(ing)? (off|from) (a |the )?(roof|bridge|building)|self[- ]?harm|no reason to live|better off dead|khud ?kushi)\b/i',
        'abuse' => '/\b((am|was|is|being|get|getting|been) (being )?(sexually |physically |emotionally )?abused|abus(e|es|ed|ing) me|abus(e|ed) (at|in) (home|school|my house)|touch(es|ed)? me (in|where)|sexual(ly)? (assault\w*|harass\w*)( me)?|(hurt|hurts|hurting|harm|harms|harming) me)\b'
            . '|\b' . $who . '\b[^.?!\n]{0,20}\b' . $hurt . ' (me|my|myself)\b/i',
        'violence' => '/\b(bring (a )?(gun|knife|weapon)|shoot (up|them|the school)|bomb (the|my) school|kill (them|him|her|everyone|my (teacher|classmate)s?))\b/i',
        'bullying' => '/\b(bull(y|ied|ies|ying)|everyone hates me|they (hit|beat|threaten(ed)?) me|threaten(ed|ing)? me)\b/i',
        // a young person in pain who did not say why: "I am hurting", "I feel unsafe", "I hate my life"
        'distress' => '/\b((i am|i\'m|im|i feel|feeling|i\'ve been|ive been|i have been) (really |so |very |too |just |truly )?(hurting|hurt|in pain|suffering|broken|depress\w*|hopeless|worthless|unsafe|not safe|scared to go home|afraid to go home)'
            . '|(i|we) (don\'t|do not|dont) feel safe|i hate my life|i can\'?t (go on|take (it|this) anymore|do this anymore)|(nobody|no one|no body) (cares|loves me)|i want to disappear|(i )?(feel like )?giving up on life|life is not worth)\b/i',
    ];
}

/** Category of a harm / distress message, else null. English first, then Pashto / Dari written in Arabic script. */
function safeguardScan($text) {
    foreach (safeguardPatterns() as $cat => $re) if (preg_match($re, (string) $text)) return $cat;
    // Pashto / Dari (Arabic script): "I want to die", "I will kill myself", "suicide"
    if (preg_match('/(خودکشی|خودکشي|ځان\s*(ووژن|وژن)\w*|خود\s*را\s*(می\x{200C}?\s*)?بکش\w*|می\x{200C}?\s*خواهم\s*بمیرم|غواړم\s*مړ\s*شم|مرګ\s*غواړم)/u', (string) $text)) return 'self_harm';
    // "someone hits / hurts me" (Dari: مرا می‌زند، اذیتم می‌کند؛ Pashto: ما وهي، ما ځوروي)
    if (preg_match('/(مرا\s*(می\x{200C}?\s*)?(زن\w*|آزار\w*|اذیت\w*|لت\s*و\s*کوب\w*|تهدید\w*)|اذیتم\s*(می\x{200C}?\s*)?کن\w*|آزارم\s*(می\x{200C}?\s*)?ده\w*|ما\s*(وهي|وهلي|وهم|ځوروي|ځوروي\w*|ځوروو|ځورول\w*)|ماته\s*(ربړ\w*|ځور\w*|زیان\w*))/u', (string) $text)) return 'abuse';
    // "I am hurting / suffering" (Dari: درد می‌کشم، رنج می‌برم؛ Pashto: زه ځوریږم، زه ربړېږم)
    if (preg_match('/(من\s*(درد\s*می\x{200C}?\s*کشم|رنج\s*می\x{200C}?\s*برم|اذیت\s*می\x{200C}?\s*شوم|احساس\s*امنیت\s*نمی\x{200C}?\s*کنم)|زه\s*(ځوریږم|ځورېږم|ربړېږم|ربړیږم|ځوریږم)|ځوریږم|ځورېږم|ربړېږم|زندگی\s*ام\s*را\s*دوست\s*ندارم)/u', (string) $text)) return 'distress';
    return null;
}

/** The AI tutor itself sounded the alarm in its answer (it recognised danger the patterns missed): crisis words in the REPLY. */
function safeguardReplyCue($reply) {
    return (bool) preg_match('/\b(emergency (services|number|help)|crisis (line|hotline|helpline|center|centre)|helpline|hotline|call (the )?police|call (an )?ambulance|your safety (is|matters)|you are not alone|you\'re not alone|trusted adult|trusted grown-?up|if you are in (immediate )?danger|someone is hurting you|being hurt)\b/i', (string) $reply);
}

function safeguardingOn() { return getSetting('ai_safeguarding', '1') !== '0'; }

/** What the tutor says instead of an AI answer when a message mentions harm (fixed text, never generated). */
function safeguardReply($category) {
    $core = "I'm really sorry you are going through this — what you wrote matters. You do not have to handle it alone. "
          . "Please talk to a grown-up you trust today: your teacher, a parent, a family member or the school counsellor. "
          . "I have let your teacher know that you might want some support, so that someone can check in with you kindly.";
    if (in_array($category, ['self_harm', 'abuse', 'distress'], true)) $core .= " If you are in danger right now, tell someone near you immediately or call your local emergency number.";
    return $core;
}

function safeguardFlag($student_id, $class_id, $chat_id, $category, $text) {
    global $db;
    $id = (int) $db->insert('safeguarding_flags', ['student_id' => (int) $student_id, 'class_id' => (int) $class_id, 'chat_id' => $chat_id ?: null, 'category' => $category, 'snippet' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $text)), 0, 300)]);
    $who = $db->fetchOne("SELECT name FROM users WHERE id = ?", [(int) $student_id])['name'] ?? 'A student';
    $cls = $db->fetchOne("SELECT class_name FROM classes WHERE id = ?", [(int) $class_id])['class_name'] ?? '';
    $label = ['self_harm' => 'may be thinking of harming themselves', 'abuse' => 'may be being hurt by someone', 'violence' => 'mentioned hurting others', 'bullying' => 'may be being bullied', 'distress' => 'is hurting and may need support'][$category] ?? 'may need support';
    $title = "Please check in: {$who} {$label}";
    $msg = "Class {$cls} — from the AI Tutor. Open Safeguarding to see the message and mark it handled.";
    $told = [];
    foreach ($db->fetchAll("SELECT teacher_id id FROM class_teachers WHERE class_id = ?", [(int) $class_id]) as $t) $told[(int) $t['id']] = true;
    foreach ($db->fetchAll("SELECT id FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1") as $a) $told[(int) $a['id']] = true;
    foreach (array_keys($told) as $uid) notify($uid, 'safeguarding', $title, $msg, 'pages/ai/safeguarding.php');
    logActivity('safeguarding_flag', 'ai', "Flag #{$id} ({$category}) in class #{$class_id}", (int) $student_id);
    return $id;
}
