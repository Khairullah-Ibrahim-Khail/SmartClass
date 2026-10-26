<?php
test('first-run guide: a new teacher sees three open steps; each one ticks only when the data really exists; it disappears when all three are real', function () {
    $db = TW('db'); $t = mkUser('OnbT', 'teacher'); $h = client($t);
    $r = teacherFirstRun($t); eq(0, $r['done'], 'nothing done yet'); eq(3, $r['total']); ok(!$r['finished']);
    $h->get('pages/dashboard.php'); contains('id="firstRun"', $h->body, 'shown on the dashboard'); contains('Create your first class', $h->body); contains('0 of 3 done', $h->body);

    $c = mkClass($t, 'OnbClass'); $r = teacherFirstRun($t); eq(1, $r['done'], 'class made'); ok($r['steps'][0]['done'] && !$r['steps'][1]['done']);
    $h->get('pages/dashboard.php'); contains('1 of 3 done', $h->body); contains('Invite your students', $h->body);
    $code = $db->fetchOne('SELECT class_code FROM classes WHERE id = ?', [$c])['class_code']; contains($code, $h->body, 'the class code is shown in the invite step');
    contains('people.php?id=' . $c, $h->body, 'the next step links to the People page of the class');

    $s = mkUser('OnbS'); $db->insert('class_members', ['class_id' => $c, 'student_id' => $s]); eq(2, teacherFirstRun($t)['done'], 'a student joined');
    $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => date('Y-m-d'), 'status' => 'present']); $r = teacherFirstRun($t); eq(3, $r['done']); ok($r['finished']);
    $h->get('pages/dashboard.php'); notContains('id="firstRun"', $h->body, 'gone once everything is done');

    $o = mkUser('OnbO', 'teacher'); eq(0, teacherFirstRun($o)['done'], 'another teacher\'s class does not count');
    $st = mkUser('OnbSt'); $hs = client($st); $hs->get('pages/dashboard.php'); notContains('id="firstRun"', $hs->body, 'students never see it');
});
