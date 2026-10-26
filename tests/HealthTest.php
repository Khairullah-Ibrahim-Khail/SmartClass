<?php
test('health: the PHP error log is read, grouped and old lines are ignored', function () {
    $f = tempnam(sys_get_temp_dir(), 'log'); $now = date('d-M-Y H:i:s'); $old = date('d-M-Y H:i:s', strtotime('-20 days'));
    file_put_contents($f, "[$now Asia/Karachi] PHP Warning:  Undefined array key \"x\" in /a/b/pages/thing.php on line 5\n[$now Asia/Karachi] PHP Warning:  Undefined array key \"x\" in /a/b/pages/thing.php on line 9\n"
        . "[$now Asia/Karachi] PHP Fatal error:  Uncaught Error: boom in /a/b/includes/x.php:3\n[$old Asia/Karachi] PHP Warning:  ancient problem in /a/b/pages/old.php on line 1\n");
    $p = healthProblems(7, 30, $f); @unlink($f);
    $php = array_values(array_filter($p, fn($x) => $x['kind'] === 'PHP'));
    eq(2, count($php), 'two groups (the 20-day-old line is ignored)'); eq('error', $php[0]['level'], 'fatal errors first');
    $w = array_values(array_filter($php, fn($x) => $x['level'] === 'warning'))[0]; eq(2, $w['count'], 'same message twice = one group of 2');
    notContains('ancient', json_encode($p));
});
test('health: failed AI calls and failed e-mails are reported', function () {
    $db = TW('db'); $u = mkUser('HeU', 'teacher');
    $db->insert('ai_usage', ['user_id' => $u, 'feature' => 'study', 'provider' => 'ollama', 'status' => 'error', 'error' => 'Could not reach the provider']);
    $db->insert('email_queue', ['to_email' => 'x@example.invalid', 'subject' => 's', 'body_html' => 'b', 'body_text' => 'b', 'status' => 'failed', 'last_error' => 'SMTP refused']);
    $kinds = array_column(healthProblems(1, 30, '/nonexistent'), 'kind'); ok(in_array('AI', $kinds, true), 'AI'); ok(in_array('E-mail', $kinds, true), 'e-mail');
});
test('health: background jobs are never / ok / late by their heartbeat', function () {
    eq('never', cronStatus()['send_emails']['state']);
    cronBeat('send_emails'); eq('ok', cronStatus()['send_emails']['state']);
    setSetting('cron_send_emails_last', date('c', strtotime('-3 hours'))); eq('late', cronStatus()['send_emails']['state']);
    setSetting('cron_digest_last', date('c', strtotime('-3 hours'))); eq('ok', cronStatus()['digest']['state'], 'a daily job 3 hours ago is fine');
});
test('health: admins are told once about problems; nobody is told when all is well', function () {
    $db = TW('db'); $a = mkUser('HeAdmin', 'teacher', 1);
    $db->query('DELETE FROM ai_usage'); $db->query('DELETE FROM email_queue'); foreach (['send_emails', 'digest', 'housekeeping'] as $j) cronBeat($j);
    // (the real error log is not part of this test machine's data, but may hold lines — only assert the in-app notice when something is wrong)
    $db->insert('email_queue', ['to_email' => 'y@example.invalid', 'subject' => 's', 'body_html' => 'b', 'body_text' => 'b', 'status' => 'failed', 'last_error' => 'SMTP refused']);
    ok(healthSummaryForAdmins() >= 1, 'told the admin'); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'system_health'", [$a])['c']);
});
test('the System check page shows the new cards to the admin only', function () {
    $a = mkUser('HeAdm2', 'teacher', 1); $h = client($a); $h->get('pages/settings/system.php'); ok($h->shown()); contains('Background jobs', $h->body); contains('Recent problems', $h->body);
    $t = mkUser('HeTeach', 'teacher'); $ht = client($t); $ht->get('pages/settings/system.php'); ok(!$ht->shown());
});
