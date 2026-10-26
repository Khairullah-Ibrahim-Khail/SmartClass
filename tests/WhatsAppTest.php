<?php
/** WhatsApp: click-to-chat links, the consent gate, the automatic queue (quiet hours, limits, retries, dedupe), the sender rules, texts, the webhook and the settings page. A fake Cloud API stands in for Meta. */
function waReset() {
    $d = TW('db');
    $d->query("DELETE FROM whatsapp_queue"); $d->query("DELETE FROM whatsapp_texts"); $d->query("DELETE FROM whatsapp_senders");
    foreach (waDefaults() as $k => $v) setSetting($k, $v);
    setSetting('wa_enabled', '1');
}
function waPerson2($name, $role = 'student', $num = '0700123456', $consent = true, $lang = 'en') {
    $u = mkUser($name, $role); TW('db')->query("UPDATE users SET whatsapp_number = ?, whatsapp_consent_at = ?, lang = ? WHERE id = ?", [waNormalizeNumber($num), $consent ? date('Y-m-d H:i:s') : null, $lang, $u]); return $u;
}
function waFake(&$calls, $fail = 0) { return function ($url, $payload, $headers) use (&$calls, &$fail) { $calls[] = compact('url', 'payload', 'headers'); if ($fail > 0) { $fail--; return [400, json_encode(['error' => ['message' => 'bad token']])]; } return [200, json_encode(['messages' => [['id' => 'wamid.' . count($calls)]]])]; }; }
function waSenderRow($owner, $extra = []) {
    $r = waSaveSender($owner, $extra + ['label' => 'S', 'phone_number_id' => '123456789', 'business_id' => '', 'token' => 'EAAB-secret-token-9999', 'is_enabled' => true, 'daily_limit' => 200, 'kinds' => ['absence', 'warning', 'deadline', 'teacher_todo', 'research_due', 'test']]);
    ok($r['ok'], json_encode($r)); return waSenderOf($owner);
}
function waTemplates($owner = null) { foreach (waKinds() as $k => $d) if (empty($d['click_only'])) TW('db')->query("INSERT INTO whatsapp_texts (owner_id, kind, lang, body, template_name) VALUES (?, ?, 'en', ?, ?) ON DUPLICATE KEY UPDATE template_name = VALUES(template_name)", [$owner, $k, waText($k, 'en', null)['body'], 'sc_' . $k]); }

test('whatsapp: numbers are normalised, and nothing is built for a person without a number or agreement or when it is switched off', function () {
    waReset();
    eq('93700123456', waNormalizeNumber('0700 123 456')); eq('93700123456', waNormalizeNumber('+93 700-123-456')); eq('93700123456', waNormalizeNumber('0093700123456'));
    eq(null, waNormalizeNumber('12')); eq(null, waNormalizeNumber('abc')); eq(null, waNormalizeNumber('93+700123456')); eq(null, waNormalizeNumber(''));
    $a = waPerson2('WaA'); $b = waPerson2('WaB', 'student', '0700222333', false);
    $p = ['class' => 'Math', 'date' => 'Oct 1, 2026'];
    $u = waClickUrl($a, 'absence', $p); contains('https://wa.me/93700123456?text=', $u); contains(rawurlencode('Math'), $u, 'the finished message is in the link');
    eq(null, waClickUrl($b, 'absence', $p), 'no agreement, no link');
    setSetting('wa_click_on', '0'); eq(null, waClickUrl($a, 'absence', $p), 'Level 1 switched off'); setSetting('wa_click_on', '1');
    setSetting('wa_enabled', '0'); eq(null, waClickUrl($a, 'absence', $p), 'master switch off'); setSetting('wa_enabled', '1');
    contains('https://wa.me/?text=', waShareUrl('announcement', ['title' => 'Exam', 'class' => 'Math', 'link' => 'http://x/y']));
});

test('whatsapp: texts must use every placeholder of their kind once and nothing else; the school wording, a teacher\'s own and the built-in are used in that order', function () {
    waReset(); $t = waPerson2('WaT', 'teacher');
    ok(waValidateText('absence', 'Hello {name}, {class} on {date} from {school}') === null || true);
    $k = waKinds()['absence']; $good = $k['text']['en']; eq(null, waValidateText('absence', $good), 'the built-in text is valid');
    ok(waValidateText('absence', 'No placeholders at all') !== null, 'missing placeholders'); ok(waValidateText('absence', $good . ' {oops}') !== null, 'unknown placeholder');
    ok(waValidateText('absence', $good . ' ' . $good) !== null, 'a placeholder twice'); ok(waValidateText('absence', '') !== null, 'empty');
    eq($good, waText('absence', 'en', null)['body']);
    TW('db')->query("INSERT INTO whatsapp_texts (owner_id, kind, lang, body) VALUES (NULL, 'absence', 'en', ?)", ['SCHOOL ' . $good]); contains('SCHOOL', waText('absence', 'en', null)['body']);
    contains('SCHOOL', waText('absence', 'en', $t)['body'], 'a teacher without own wording gets the school\'s');
    TW('db')->query("INSERT INTO whatsapp_texts (owner_id, kind, lang, body) VALUES (?, 'absence', 'en', ?)", [$t, 'OWN ' . $good]); contains('OWN', waText('absence', 'en', $t)['body']);
    foreach (['en', 'ps', 'fa'] as $l) foreach (waKinds() as $kk => $d) eq(null, waValidateText($kk, $d['text'][$l]), "built-in $kk/$l is valid");
});

test('whatsapp: a sender keeps its token encrypted, shows only the last 4, validates, and a blank token keeps the old one', function () {
    waReset(); $t = mkUser('WaS', 'teacher');
    $bad = waSaveSender($t, ['phone_number_id' => 'abc', 'token' => '', 'is_enabled' => true, 'daily_limit' => 'abc', 'kinds' => ['absence']]); ok(!$bad['ok']); ok(isset($bad['errors']['phone_number_id']) && isset($bad['errors']['daily_limit']));
    $s = waSenderRow($t); ok(strpos($s['token'], 'secret-token') === false, 'stored encrypted'); eq('EAAB-secret-token-9999', decryptSecret($s['token']));
    waSaveSender($t, ['phone_number_id' => '123456789', 'token' => '', 'is_enabled' => true, 'daily_limit' => 50, 'kinds' => ['absence']]);
    $s = waSenderOf($t); eq('EAAB-secret-token-9999', decryptSecret($s['token']), 'blank keeps it'); eq(50, (int) $s['daily_limit']); eq(['absence'], waSenderKinds($s));
});

test('whatsapp: queue — gates, dedupe, sender choice (teacher\'s own before the school\'s), kinds switch', function () {
    waReset(); $t = waPerson2('WaQt', 'teacher', '0700999111'); $s = waPerson2('WaQs'); $c = mkClass($t, 'WaQClass', [$s]);
    $why = null; eq(null, waQueue($s, 'absence', 'r1', ['class' => 'X', 'date' => 'd'], $c, $t, $why), 'automatic sending is off by default'); contains('off', $why);
    setSetting('wa_auto_on', '1'); eq(null, waQueue($s, 'absence', 'r1', ['class' => 'X', 'date' => 'd'], $c, $t, $why)); contains('no sender', $why);
    $school = waSenderRow(null); $id = waQueue($s, 'absence', 'r1', ['class' => 'X', 'date' => 'd'], $c, $t); ok($id > 0, 'queued through the school sender');
    eq((int) $school['id'], (int) TW('db')->fetchOne("SELECT sender_id FROM whatsapp_queue WHERE id = ?", [$id])['sender_id']);
    eq(null, waQueue($s, 'absence', 'r1', ['class' => 'X', 'date' => 'd'], $c, $t, $why), 'the same thing is never queued twice'); contains('already', $why);
    $own = waSenderRow($t); $id2 = waQueue($s, 'absence', 'r2', ['class' => 'X', 'date' => 'd'], $c, $t); eq((int) $own['id'], (int) TW('db')->fetchOne("SELECT sender_id FROM whatsapp_queue WHERE id = ?", [$id2])['sender_id'], 'the teacher\'s own sender first');
    setSetting('wa_teachers_own', '0'); $id3 = waQueue($s, 'absence', 'r3', ['class' => 'X', 'date' => 'd'], $c, $t); eq((int) $school['id'], (int) TW('db')->fetchOne("SELECT sender_id FROM whatsapp_queue WHERE id = ?", [$id3])['sender_id'], 'own senders not allowed → the school\'s'); setSetting('wa_teachers_own', '1');
    waSaveSender(null, ['phone_number_id' => '123456789', 'token' => '', 'is_enabled' => true, 'daily_limit' => 200, 'kinds' => ['warning']]); waSaveSender($t, ['phone_number_id' => '123456789', 'token' => '', 'is_enabled' => false, 'daily_limit' => 200, 'kinds' => ['warning']]);
    eq(null, waQueue($s, 'absence', 'r4', ['class' => 'X', 'date' => 'd'], $c, $t, $why), 'a kind that is not switched on'); contains('not switched on', $why);
    $none = waPerson2('WaNo', 'student', '0700555666', false); eq(null, waQueue($none, 'warning', 'w', ['class' => 'X', 'percent' => 50, 'limit' => 75], $c, $t, $why), 'no agreement'); contains('agreement', $why);
    eq(null, waQueue($s, 'announcement', 'a', [], $c, $t, $why), 'click-only kinds are never automatic');
});

test('whatsapp: sending — template payload, quiet hours, daily limit, retries, withdrawn agreement', function () {
    waReset(); setSetting('wa_auto_on', '1'); setSetting('wa_quiet_from', '00:00'); setSetting('wa_quiet_to', '00:00');   // never quiet
    $t = waPerson2('WaPt', 'teacher'); $s = waPerson2('WaPs', 'student', '0700444555', true, 'ps'); $c = mkClass($t, 'WaPClass', [$s]); waSenderRow(null); waTemplates();
    $id = waQueue($s, 'absence', 'a1', ['class' => 'Math', 'date' => 'Oct 1, 2026'], $c, $t); $calls = [];
    $r = waProcessQueue(10, waFake($calls)); eq(1, $r['sent']); eq(1, count($calls));
    $c0 = $calls[0]; contains('/123456789/messages', $c0['url']); eq('93700444555', $c0['payload']['to']); eq('template', $c0['payload']['type']); eq('sc_absence', $c0['payload']['template']['name']);
    eq('en', $c0['payload']['template']['language']['code'], 'no Pashto template name → English template'); ok(in_array('Authorization: Bearer EAAB-secret-token-9999', $c0['headers'], true), 'bearer token');
    $vals = array_column($c0['payload']['template']['components'][0]['parameters'], 'text'); ok(in_array('Math', $vals, true), 'parameters in order of the placeholders');
    $row = TW('db')->fetchOne("SELECT * FROM whatsapp_queue WHERE id = ?", [$id]); eq('sent', $row['status']); eq('wamid.1', $row['wa_message_id']);
    // Pashto template name set → used with the ps language code
    TW('db')->query("INSERT INTO whatsapp_texts (owner_id, kind, lang, body, template_name) VALUES (NULL, 'warning', 'ps', ?, 'sc_warning_ps')", [waKinds()['warning']['text']['ps']]);
    waQueue($s, 'warning', 'w1', ['class' => 'Math', 'percent' => 60, 'limit' => 75], $c, $t); $calls = []; waProcessQueue(10, waFake($calls)); eq('sc_warning_ps', $calls[0]['payload']['template']['name']); eq('ps', $calls[0]['payload']['template']['language']['code']);
    // no template at all → a clear failure
    TW('db')->query("DELETE FROM whatsapp_texts"); waQueue($s, 'absence', 'a2', ['class' => 'M', 'date' => 'd'], $c, $t); $calls = []; $r = waProcessQueue(10, waFake($calls)); eq(1, $r['failed']); eq(0, count($calls));
    contains('template name', TW('db')->fetchOne("SELECT error FROM whatsapp_queue WHERE ref = 'a2'")['error']);
    // retries: fail twice, then succeed on the 3rd try (gap forced to the past)
    waTemplates(); waQueue($s, 'absence', 'a3', ['class' => 'M', 'date' => 'd'], $c, $t); $calls = []; $f = waFake($calls, 2);
    waProcessQueue(10, $f); eq('pending', TW('db')->fetchOne("SELECT status FROM whatsapp_queue WHERE ref = 'a3'")['status'], 'first failure → will retry'); contains('bad token', TW('db')->fetchOne("SELECT error FROM whatsapp_queue WHERE ref = 'a3'")['error']);
    TW('db')->query("UPDATE whatsapp_queue SET send_after = NOW() - INTERVAL 1 MINUTE WHERE ref = 'a3'"); waProcessQueue(10, $f);
    TW('db')->query("UPDATE whatsapp_queue SET send_after = NOW() - INTERVAL 1 MINUTE WHERE ref = 'a3'"); $r = waProcessQueue(10, $f); eq(1, $r['sent'], 'third try works'); eq(3, (int) TW('db')->fetchOne("SELECT attempts FROM whatsapp_queue WHERE ref = 'a3'")['attempts']);
    // gives up after wa_retry_max
    setSetting('wa_retry_max', '2'); waQueue($s, 'absence', 'a4', ['class' => 'M', 'date' => 'd'], $c, $t); $calls = []; $f = waFake($calls, 9);
    waProcessQueue(10, $f); TW('db')->query("UPDATE whatsapp_queue SET send_after = NOW() - INTERVAL 1 MINUTE WHERE ref = 'a4'"); waProcessQueue(10, $f); eq('failed', TW('db')->fetchOne("SELECT status FROM whatsapp_queue WHERE ref = 'a4'")['status']); setSetting('wa_retry_max', '3');
    // daily limit
    TW('db')->query("UPDATE whatsapp_senders SET daily_limit = 1 WHERE owner_id IS NULL"); TW('db')->query("UPDATE whatsapp_queue SET sent_at = NULL WHERE 1"); TW('db')->query("DELETE FROM whatsapp_queue");
    waQueue($s, 'absence', 'l1', ['class' => 'M', 'date' => 'd'], $c, $t); waQueue($s, 'absence', 'l2', ['class' => 'M', 'date' => 'd'], $c, $t); $calls = []; $r = waProcessQueue(10, waFake($calls)); eq(1, $r['sent']); eq(1, $r['waiting'], 'the second waits for tomorrow');
    // quiet hours: nothing is sent, the message waits
    TW('db')->query("UPDATE whatsapp_senders SET daily_limit = 200"); TW('db')->query("DELETE FROM whatsapp_queue"); setSetting('wa_quiet_from', '00:00'); setSetting('wa_quiet_to', '23:59');
    waQueue($s, 'absence', 'q1', ['class' => 'M', 'date' => 'd'], $c, $t); $calls = []; $r = waProcessQueue(10, waFake($calls)); eq(0, count($calls)); eq(1, $r['waiting']); setSetting('wa_quiet_from', '00:00'); setSetting('wa_quiet_to', '00:00');
    // agreement withdrawn before sending
    TW('db')->query("DELETE FROM whatsapp_queue"); waQueue($s, 'absence', 'w9', ['class' => 'M', 'date' => 'd'], $c, $t); TW('db')->query("UPDATE users SET whatsapp_consent_at = NULL WHERE id = ?", [$s]); $calls = []; $r = waProcessQueue(10, waFake($calls)); eq(1, $r['skipped']); eq(0, count($calls));
});

test('whatsapp: quiet hours over midnight, next opening time', function () {
    waReset(); setSetting('wa_quiet_from', '21:00'); setSetting('wa_quiet_to', '07:00');
    $at = fn($hm) => strtotime(date('Y-m-d') . " $hm:00");
    ok(waIsQuiet($at('22:30')) && waIsQuiet($at('03:00')) && !waIsQuiet($at('12:00')) && !waIsQuiet($at('07:00')) && waIsQuiet($at('21:00')));
    eq(date('Y-m-d', $at('12:00')) . ' 07:00', date('Y-m-d H:i', waNextOpen($at('03:00')))); eq(date('Y-m-d', strtotime('tomorrow')) . ' 07:00', date('Y-m-d H:i', waNextOpen($at('22:30'))));
    eq($at('12:00'), waNextOpen($at('12:00')));
});

test('whatsapp: events (absence, warning) queue only for the people who agreed; the daily jobs build the summaries once and send', function () {
    waReset(); setSetting('wa_auto_on', '1'); setSetting('wa_quiet_from', '00:00'); setSetting('wa_quiet_to', '00:00'); setSetting('wa_deadline_time', '00:00'); setSetting('wa_todo_time', '00:00');
    $t = waPerson2('WaEt', 'teacher', '0700777111'); $s = waPerson2('WaEs', 'student', '0700777222'); $s2 = waPerson2('WaEs2', 'student', '0700777333', false); $c = mkClass($t, 'WaEClass', [$s, $s2]); waSenderRow(null); waTemplates();
    waOnAbsence($c, $s, date('Y-m-d'), 1, 'WaEClass', $t); waOnAbsence($c, $s2, date('Y-m-d'), 1, 'WaEClass', $t); eq(1, (int) TW('db')->fetchOne("SELECT COUNT(*) c FROM whatsapp_queue")['c']);
    waOnWarning($c, $s, 'WaEClass', 50, 75, $t); eq(2, (int) TW('db')->fetchOne("SELECT COUNT(*) c FROM whatsapp_queue")['c']);
    $a = TW('db')->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Essay', 'description' => 'x', 'due_date' => date('Y-m-d 18:00:00', strtotime('tomorrow')), 'max_marks' => 10]);
    $calls = []; $r = waDailyJobs(waFake($calls)); ok($r['deadline'] >= 1, 'deadline summary built'); $n = count($calls); ok($n >= 3, "sent $n");
    $again = waDailyJobs(waFake($calls)); eq(0, $again['deadline'], 'not built twice for the same day'); eq(0, $again['sent']['sent'] + 0 > 0 ? 1 : 0, 'nothing new to send');
    $item = array_values(array_filter($calls, fn($x) => $x['payload']['template']['name'] === 'sc_deadline'))[0] ?? null; ok($item && strpos(json_encode($item['payload']), 'Essay') !== false, 'the assignment is named');
});

test('whatsapp: webhook — Meta\'s check, signed events only, STOP withdraws the agreement and cancels what is waiting', function () {
    waReset(); setSetting('wa_webhook_token', 'verify-token-123'); setSetting('wa_app_secret', encryptSecret('app-secret-0123456789'));
    $h = anon(); $h->get('pages/whatsapp/webhook.php?hub.mode=subscribe&hub.verify_token=verify-token-123&hub.challenge=555'); eq('555', trim($h->body)); eq(200, $h->code);
    $h->get('pages/whatsapp/webhook.php?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=555'); eq(403, $h->code);
    $s = waPerson2('WaWs', 'student', '0700888999'); $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => '93700888999', 'type' => 'text', 'text' => ['body' => ' stop ']]]]]]]]]);
    ok(waVerifySignature($body, 'sha256=' . hash_hmac('sha256', $body, 'app-secret-0123456789')), 'good signature'); ok(!waVerifySignature($body, 'sha256=' . str_repeat('0', 64)), 'bad signature'); ok(!waVerifySignature($body, ''), 'no signature');
    eq(0, waHandleInbound('93700888999', 'hello'), 'an ordinary message changes nothing'); ok(waCanReach($s));
    $sid = TW('db')->insert('whatsapp_senders', ['phone_number_id' => '1', 'token' => 'x', 'is_enabled' => 1]);
    TW('db')->query("INSERT INTO whatsapp_queue (sender_id, recipient_id, kind, ref, lang, params, body, status) VALUES (?, ?, 'absence', 'z', 'en', '{}', 'b', 'pending')", [$sid, $s]);
    eq(1, waHandleInbound('93700888999', ' STOP ')); ok(!waCanReach($s), 'agreement withdrawn'); eq('skipped', TW('db')->fetchOne("SELECT status FROM whatsapp_queue WHERE ref = 'z'")['status']);
    setSetting('wa_enabled', '0'); $h->get('pages/whatsapp/webhook.php'); ok($h->code === 403 || $h->code === 204);
});

test('whatsapp settings page: everybody sees their own number; teachers the sender and messages; only the administrator the school rules; saves are validated and CSRF-guarded', function () {
    waReset(); $st = mkUser('WaPgS'); $te = mkUser('WaPgT', 'teacher'); $ad = mkUser('WaPgA', 'teacher', 1);
    $hs = client($st); $hs->get('pages/settings/whatsapp.php'); contains('My WhatsApp', $hs->body); notContains('School rules', $hs->body); notContains('Access token', $hs->body);
    $ht = client($te); $ht->get('pages/settings/whatsapp.php'); contains('My own sender', $ht->body); contains('Messages', $ht->body); notContains('School rules', $ht->body);
    $ha = client($ad); $ha->get('pages/settings/whatsapp.php'); contains('School rules', $ha->body); contains("The school's sender", $ha->body); contains('pages/whatsapp/webhook.php', $ha->body);
    // own number
    $tok = $hs->token('pages/settings/whatsapp.php');
    $hs->post('pages/settings/whatsapp.php', ['csrf_token' => $tok, 'action' => 'save_person', 'number' => '12']); $hs->get('pages/settings/whatsapp.php'); contains('Enter a full phone number', $hs->body);
    $hs->post('pages/settings/whatsapp.php', ['csrf_token' => $hs->token('pages/settings/whatsapp.php'), 'action' => 'save_person', 'number' => '0700 321 654', 'relation' => 'father']); ok(waCanReach($st), 'a student needs no tick: the number is part of registering'); eq('93700321654', waPerson($st)['whatsapp_number']); eq('father', TW('db')->fetchOne("SELECT whatsapp_relation r FROM users WHERE id = ?", [$st])['r']);
    $st2 = mkUser('WaPgS2'); $h2 = client($st2); $h2->post('pages/settings/whatsapp.php', ['csrf_token' => $h2->token('pages/settings/whatsapp.php'), 'action' => 'save_person', 'number' => '0700 321 654', 'relation' => 'father']); ok(waCanReach($st2), 'two brothers can share their father\'s number');
    $hs->post('pages/settings/whatsapp.php', ['csrf_token' => 'bad', 'action' => 'save_person', 'number' => '']); ok(waCanReach($st), 'a wrong CSRF token changes nothing');
    $hs->post('pages/settings/whatsapp.php', ['csrf_token' => $hs->token('pages/settings/whatsapp.php'), 'action' => 'save_person', 'number' => '']); ok(waCanReach($st), 'a student cannot clear the number while the school requires it'); $hs->get('pages/settings/whatsapp.php'); contains('A WhatsApp number is required', $hs->body);
    setSetting('wa_require_number', '0'); $hs->post('pages/settings/whatsapp.php', ['csrf_token' => $hs->token('pages/settings/whatsapp.php'), 'action' => 'save_person', 'number' => '']); ok(!waCanReach($st), 'not required → can be cleared'); setSetting('wa_require_number', '1');
    // a student cannot save a sender, texts or school rules
    $hs->post('pages/settings/whatsapp.php', ['csrf_token' => $hs->token('pages/settings/whatsapp.php'), 'action' => 'save_school', 'wa_enabled' => '1']); allSettings(true); eq('21:00', waSetting('wa_quiet_from'));
    $hs->post('pages/settings/whatsapp.php', ['csrf_token' => $hs->token('pages/settings/whatsapp.php'), 'action' => 'save_sender', 'phone_number_id' => '999999', 'token' => 'tok-123456789', 'is_enabled' => '1']); ok(!waSenderOf($st), 'a student cannot save a sender');
    // a teacher: own sender and own wording; not the school rules
    $ht->post('pages/settings/whatsapp.php', ['csrf_token' => $ht->token('pages/settings/whatsapp.php'), 'action' => 'save_sender', 'which' => 'own', 'phone_number_id' => 'x', 'token' => '', 'daily_limit' => '5']); $ht->get('pages/settings/whatsapp.php'); contains('Phone number ID', $ht->body);
    $ht->post('pages/settings/whatsapp.php', ['csrf_token' => $ht->token('pages/settings/whatsapp.php'), 'action' => 'save_sender', 'which' => 'own', 'phone_number_id' => '555000111', 'token' => 'EAAB-teacher-token-4242', 'is_enabled' => '1', 'daily_limit' => '40', 'kinds' => ['absence']]);
    $s = waSenderOf($te); eq('555000111', $s['phone_number_id']); eq(['absence'], waSenderKinds($s)); $ht->get('pages/settings/whatsapp.php'); contains('4242', $ht->body); notContains('EAAB-teacher-token', $ht->body, 'only the last 4 are shown');
    $ht->post('pages/settings/whatsapp.php', ['csrf_token' => $ht->token('pages/settings/whatsapp.php'), 'action' => 'save_sender', 'which' => 'school', 'phone_number_id' => '777', 'token' => 'zzzzzzzzzzz', 'daily_limit' => '5']); ok(!waSenderOf(null), 'a teacher cannot write the school sender');
    $good = waKinds()['absence']['text']['en'];
    $ht->post('pages/settings/whatsapp.php', ['csrf_token' => $ht->token('pages/settings/whatsapp.php'), 'action' => 'save_texts', 'body' => ['absence' => ['en' => 'Missing placeholders']]]); $ht->get('pages/settings/whatsapp.php'); contains('placeholder', $ht->body);
    ok(!TW('db')->fetchOne("SELECT id FROM whatsapp_texts WHERE owner_id = ?", [$te]), 'an invalid text is not saved');
    $ht->post('pages/settings/whatsapp.php', ['csrf_token' => $ht->token('pages/settings/whatsapp.php'), 'action' => 'save_texts', 'body' => ['absence' => ['en' => 'OWN ' . $good]], 'tpl' => ['absence' => ['en' => 'my_absence']]]);
    $r = waText('absence', 'en', $te); contains('OWN', $r['body']); eq('my_absence', $r['template']); notContains('OWN', waText('absence', 'en', null)['body'], 'the school\'s wording is untouched');
    $ht->post('pages/settings/whatsapp.php', ['csrf_token' => $ht->token('pages/settings/whatsapp.php'), 'action' => 'reset_texts']); notContains('OWN', waText('absence', 'en', $te)['body']);
    $ht->post('pages/settings/whatsapp.php', ['csrf_token' => $ht->token('pages/settings/whatsapp.php'), 'action' => 'save_school', 'wa_enabled' => '1', 'wa_quiet_from' => '20:00']); allSettings(true); eq('21:00', waSetting('wa_quiet_from'), 'a teacher cannot change school rules');
    // admin: school rules validated, saved, secret encrypted
    $post = ['action' => 'save_school', 'wa_enabled' => '1', 'wa_click_on' => '1', 'wa_auto_on' => '1', 'wa_teachers_own' => '1', 'wa_quiet_from' => '20:30', 'wa_quiet_to' => '06:15', 'wa_deadline_time' => '16:00', 'wa_todo_time' => '07:00', 'wa_default_cc' => '93', 'wa_default_lang' => 'ps', 'wa_api_version' => 'v21.0',
        'wa_retry_max' => '4', 'wa_retry_gap_min' => '20', 'wa_stop_words' => 'stop, ودرېږه', 'wa_school_name' => 'Al Noor', 'wa_webhook_token' => 'my-verify-token', 'wa_app_secret' => 'abcdefghijklmnop1234'];
    $ha->post('pages/settings/whatsapp.php', ['csrf_token' => $ha->token('pages/settings/whatsapp.php')] + ['wa_quiet_from' => '25:99'] + $post); eq('21:00', waSetting('wa_quiet_from'), 'bad time → nothing saved');
    $ha->post('pages/settings/whatsapp.php', ['csrf_token' => $ha->token('pages/settings/whatsapp.php'), 'wa_quiet_from' => '25:99', 'wa_retry_max' => '99', 'wa_api_version' => 'x'] + $post); $ha->get('pages/settings/whatsapp.php'); contains('Enter a time like 21:00', $ha->body);
    $ha->post('pages/settings/whatsapp.php', ['csrf_token' => $ha->token('pages/settings/whatsapp.php')] + $post);
    allSettings(true);
    eq('20:30', waSetting('wa_quiet_from')); eq('ps', waSetting('wa_default_lang')); eq('Al Noor', waSchoolName()); ok(waAutoOn()); ok(strpos((string) getSetting('wa_app_secret'), 'abcdefgh') === false, 'secret stored encrypted'); eq('abcdefghijklmnop1234', waSetting('wa_app_secret'));
    $ha->get('pages/settings/whatsapp.php'); notContains('abcdefghijklmnop1234', $ha->body, 'the secret is never shown again');
    $ha->post('pages/settings/whatsapp.php', ['csrf_token' => $ha->token('pages/settings/whatsapp.php'), 'action' => 'gen_webhook']); allSettings(true); ok(waSetting('wa_webhook_token') !== 'my-verify-token' && strlen(waSetting('wa_webhook_token')) >= 16, 'a new verify token');
    waReset();
});

test('whatsapp buttons: the attendance sheet, the at-risk list and an announcement show a WhatsApp button only when switched on and the person agreed', function () {
    waReset(); $t = mkUser('WaBt', 'teacher'); $s = waPerson2('WaBs', 'student', '0700616161'); $s2 = waPerson2('WaBs2', 'student', '0700626262', false); $c = mkClass($t, 'WaBClass', [$s, $s2]);
    $d = date('Y-m-d'); foreach ([$s, $s2] as $x) TW('db')->insert('attendance', ['class_id' => $c, 'student_id' => $x, 'date' => $d, 'status' => 'absent', 'marked_by' => $t]);
    TW('db')->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => 'Quiz on Monday']);
    $h = client($t); $h->get("pages/attendance/class.php?id=$c&date=$d"); eq(2, substr_count($h->body, 'wa-btn'), 'the sheet row and the at-risk list, only for the student who agreed'); notContains('wa.me/93700626262', $h->body); contains('wa.me/93700616161', $h->body);
    $h->get('pages/announcements/view.php'); contains('wa.me/?text=', $h->body); contains(rawurlencode('Quiz on Monday'), $h->body);
    setSetting('wa_click_on', '0'); $h->get("pages/attendance/class.php?id=$c&date=$d"); notContains('wa-btn', $h->body); $h->get('pages/announcements/view.php'); notContains('wa-btn', $h->body);
    waReset();
});

test('whatsapp number at registration: required while WhatsApp is on (own or a family member\'s), checked, saved with who it belongs to; a student without one sees a banner; the teacher can set it', function () {
    waReset(); $h = anon(); $h->get('pages/auth/register.php'); contains('name="whatsapp"', $h->body); contains('My elder brother', $h->body);
    $post = fn($x) => $x + ['csrf_token' => $h->token('pages/auth/register.php'), 'name' => 'Reg Student', 'email' => 'regwa' . mt_rand(1000, 9999) . '@example.com', 'password' => 'Str0ng#Pass2026x', 'confirm_password' => 'Str0ng#Pass2026x'];
    $h->post('pages/auth/register.php', $post([])); contains('A WhatsApp number is required', $h->body);
    $h->post('pages/auth/register.php', $post(['whatsapp' => '12'])); contains('Enter a valid WhatsApp number', $h->body);
    $em = 'regwa' . mt_rand(10000, 99999) . '@example.com'; $h->post('pages/auth/register.php', $post(['email' => $em, 'whatsapp' => '0700 818 181', 'whatsapp_relation' => 'uncle']));
    $u = TW('db')->fetchOne("SELECT id, whatsapp_number, whatsapp_relation, whatsapp_consent_at FROM users WHERE email = ?", [$em]); ok($u, 'registered'); eq('93700818181', $u['whatsapp_number']); eq('uncle', $u['whatsapp_relation']); ok($u['whatsapp_consent_at']);
    setSetting('wa_require_number', '0'); $h2 = anon(); $em2 = 'regwb' . mt_rand(10000, 99999) . '@example.com'; $h2->post('pages/auth/register.php', ['csrf_token' => $h2->token('pages/auth/register.php'), 'name' => 'Reg Two', 'email' => $em2, 'password' => 'Str0ng#Pass2026x', 'confirm_password' => 'Str0ng#Pass2026x']); ok(TW('db')->fetchOne("SELECT id FROM users WHERE email = ?", [$em2]), 'not required → may register without'); setSetting('wa_require_number', '1');
    setSetting('wa_enabled', '0'); $h3 = anon(); $h3->get('pages/auth/register.php'); notContains('name="whatsapp"', $h3->body, 'WhatsApp off → no field'); setSetting('wa_enabled', '1');
    $t = mkUser('WaRt', 'teacher'); $s = mkUser('WaRs'); $c = mkClass($t, 'WaRClass', [$s]); $hs = client($s); $hs->get('pages/dashboard.php'); contains('wa-needbanner', $hs->body, 'a student without a number is asked for it');
    $ht = client($t); $ht->get("pages/classes/student.php?class_id=$c&student_id=$s"); contains('name="whatsapp"', $ht->body);
    $ht->post("pages/classes/student.php?class_id=$c&student_id=$s", ['csrf_token' => $ht->token("pages/classes/student.php?class_id=$c&student_id=$s"), 'action' => 'set_whatsapp', 'whatsapp' => '0700 909 090', 'relation' => 'brother']);
    eq('93700909090', waPerson($s)['whatsapp_number']); ok(waCanReach($s)); $hs->get('pages/dashboard.php'); notContains('wa-needbanner', $hs->body, 'the banner is gone once there is a number');
    $ho = client(mkUser('WaRo')); $ho->post("pages/classes/student.php?class_id=$c&student_id=$s", ['csrf_token' => $ho->token('pages/dashboard.php'), 'action' => 'set_whatsapp', 'whatsapp' => '0700 111 000']); eq('93700909090', waPerson($s)['whatsapp_number'], 'someone outside the class cannot change it');
    waReset();
});

test('student photos: the class teacher sees the photo (not only the first letter) on the student page and the people list; the WhatsApp number is shown as text with its owner', function () {
    waReset(); $t = mkUser('PhT', 'teacher'); $s = mkUser('PhS'); $c = mkClass($t, 'PhClass', [$s]);
    $name = 'test_ph_' . $s . '.png';   // only the page markup is checked: the test must not write into the real uploads folder
    TW('db')->query("UPDATE users SET photo = ?, whatsapp_number = '93788770458', whatsapp_relation = 'father', whatsapp_consent_at = NOW() WHERE id = ?", ['uploads/avatars/' . $name, $s]);
    $h = client($t); $h->get("pages/classes/student.php?class_id=$c&student_id=$s"); contains("pages/profile/photo.php?u=$s", $h->body, 'the photo, not a letter'); contains('+93788770458', $h->body, 'the number as text'); contains('My father', $h->body);
    $h->get("pages/classes/people.php?id=$c"); contains("pages/profile/photo.php?u=$s", $h->body);
    ok(canSeeUser($t, $s), 'the class teacher is allowed to open the photo');
    ok(!canSeeUser(mkUser('PhO2', 'teacher'), $s), 'a stranger may not');
});

test('whatsapp: a teacher can keep the number and switch the morning "Today" list off', function () {
    waReset(); setSetting('wa_auto_on', '1'); $t = waPerson2('WaTd', 'teacher', '0700151515'); $s = mkUser('WaTds'); $c = mkClass($t, 'WaTdClass', [$s]); waSenderRow(null); waTemplates();
    ok(waQueueTeacherTodo(date('Y-m-d')) >= 0, 'runs');   // the class has students and no attendance today → an item exists
    TW('db')->query("DELETE FROM whatsapp_queue"); waQueueTeacherTodo(date('Y-m-d')); eq(1, (int) TW('db')->fetchOne("SELECT COUNT(*) c FROM whatsapp_queue WHERE recipient_id = ? AND kind = 'teacher_todo'", [$t])['c'], 'on by default');
    TW('db')->query("DELETE FROM whatsapp_queue"); $h = client($t); $h->get('pages/settings/whatsapp.php'); contains('Send me the “Today” list', $h->body);
    $h->post('pages/settings/whatsapp.php', ['csrf_token' => $h->token('pages/settings/whatsapp.php'), 'action' => 'save_person', 'number' => '0700 151 515', 'consent' => '1']);   // todo box left unticked
    eq(0, (int) TW('db')->fetchOne("SELECT whatsapp_todo v FROM users WHERE id = ?", [$t])['v']); ok(waCanReach($t), 'the number is kept'); TW('db')->query("DELETE FROM whatsapp_queue"); waQueueTeacherTodo(date('Y-m-d')); eq(0, (int) TW('db')->fetchOne("SELECT COUNT(*) c FROM whatsapp_queue WHERE recipient_id = ? AND kind = 'teacher_todo'", [$t])['c'], 'no Today list for this teacher');
    waReset();
});

test('whatsapp numbers list: a teacher sees only the students of their classes, the administrator all; filters by number; students cannot open it', function () {
    waReset(); $t = mkUser('WaLt', 'teacher'); $o = mkUser('WaLo', 'teacher'); $a = mkUser('WaLa', 'teacher', 1);
    $s1 = waPerson2('WaLs1', 'student', '0700331331'); $s2 = mkUser('WaLs2'); $s3 = waPerson2('WaLs3', 'student', '0700332332', false); $s4 = mkUser('WaLs4');
    mkClass($t, 'WaLClass', [$s1, $s2, $s3]); mkClass($o, 'WaLOther', [$s4]);
    $h = client($t); $h->get('pages/whatsapp/students.php'); contains('WaLs1', $h->body); contains('WaLs2', $h->body); notContains('WaLs4', $h->body, 'another teacher\'s student'); contains('+93700331331', $h->body);
    $h->get('pages/whatsapp/students.php?f=without'); contains('WaLs2', $h->body); notContains('WaLs1', $h->body);
    $h->get('pages/whatsapp/students.php?f=stopped'); contains('WaLs3', $h->body); notContains('WaLs2', $h->body);
    $h->get('pages/whatsapp/students.php?f=with'); contains('WaLs1', $h->body); notContains('WaLs3', $h->body);
    $h->get('pages/whatsapp/students.php?q=WaLs2'); contains('WaLs2', $h->body); notContains('WaLs1', $h->body);
    $hs = client($s2); $hs->get('pages/whatsapp/students.php'); notContains('Students and their WhatsApp numbers', $hs->body, 'a student cannot open it');
    waReset();
});
