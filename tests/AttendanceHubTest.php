<?php
test('attendance module: the teacher\'s Today list shows each class with the right state, the ones still to take come first; students do not get it', function () {
    $db = TW('db'); $t = mkUser('AhT', 'teacher'); $o = mkUser('AhO', 'teacher');
    $s1 = mkUser('AhS1'); $s2 = mkUser('AhS2');
    $todo = mkClass($t, 'Hub Todo', [$s1, $s2]); $done = mkClass($t, 'Hub Done', [$s1, $s2]); $part = mkClass($t, 'Hub Part', [$s1, $s2]); $empty = mkClass($t, 'Hub Empty'); $noc = mkClass($t, 'Hub NoClass', [$s1]);
    $other = mkClass($o, 'Hub Other Teacher', [$s1]); $today = date('Y-m-d');
    foreach ([$s1, $s2] as $s) $db->insert('attendance', ['class_id' => $done, 'student_id' => $s, 'date' => $today, 'status' => 'present']);
    $db->insert('attendance', ['class_id' => $part, 'student_id' => $s1, 'date' => $today, 'status' => 'late']);
    $db->insert('attendance_days', ['class_id' => $noc, 'date' => $today, 'label' => 'Holiday']);
    setSetting('attendance_off_weekdays', '');
    $by = []; foreach (attendanceTodayList($t) as $r) $by[$r['class_name']] = $r['state'];
    $want = ['Hub Done' => 'done', 'Hub Empty' => 'empty', 'Hub NoClass' => 'noclass', 'Hub Part' => 'partial', 'Hub Todo' => 'todo']; $got = array_intersect_key($by, $want); ksort($got); eq($want, $got, 'one state per class');
    ok(!isset($by['Hub Other Teacher']), 'only the teacher\'s own classes');
    $list = array_column(attendanceTodayList($t), 'class_name'); ok(array_search('Hub Todo', $list) < array_search('Hub Done', $list) && array_search('Hub Part', $list) < array_search('Hub Empty', $list), 'classes still to take come first');
    $h = client($t); $h->get('pages/attendance/view.php'); ok($h->shown());
    contains('Today', $h->body); contains('Hub Todo', $h->body); contains('Not taken today', $h->body); contains('Taken today', $h->body); contains('1 / 2 marked today', $h->body); contains('No students', $h->body); contains('No class today', $h->body);
    contains('pages/attendance/class.php?id=' . $todo . '&amp;date=' . $today, $h->body, 'each row opens that class\'s sheet for today, inside the module'); notContains('pages/classes/attendance.php', $h->body, 'no link to the old address');
    notContains('Hub Other Teacher', $h->body);
    setSetting('attendance_off_weekdays', (string) date('N')); $off = []; foreach (attendanceTodayList($t) as $r) $off[$r['class_name']] = $r['state']; eq('off', $off['Hub Todo'], 'a weekly day off with nothing marked'); eq('done', $off['Hub Done']); setSetting('attendance_off_weekdays', '');
    $hs = client($s1); $hs->get('pages/attendance/view.php'); ok($hs->shown()); notContains('att-today', $hs->body, 'students have no Today list');
    $ho = client(mkUser('AhNew', 'teacher')); $ho->get('pages/attendance/view.php'); notContains('att-today', $ho->body, 'a teacher with no classes sees the empty state');
});

test('attendance hub: teacher sees stat tiles, a card per class with its own Take attendance button, the 7-day strip and who needs attention; a student sees a card with a rate ring per class and none of the teacher parts', function () {
    $db = TW('db'); $t = mkUser('AhT', 'teacher'); $s1 = mkUser('AhS1'); $s2 = mkUser('AhS2'); $c = mkClass($t, 'Hub Cards', [$s1, $s2]);
    $d = date('Y-m-d', strtotime('-1 day'));
    foreach ([$s1 => 'present', $s2 => 'absent'] as $sid => $st) $db->query("INSERT INTO attendance (class_id, student_id, date, session, status, marked_by) VALUES (?, ?, ?, 1, ?, ?)", [$c, $sid, $d, $st, $t]);
    $h = client($t); $h->get('pages/attendance/view.php'); ok($h->shown());
    contains('att-hub-stats', $h->body); contains('Hub Cards', $h->body); contains('att-card', $h->body); contains('Take attendance', $h->body); contains('att-week', $h->body); contains('Needs attention', $h->body);
    contains("pages/attendance/class.php?id=$c&amp;date=" . date('Y-m-d'), $h->body, 'the card button opens that class for today');
    $st = client($s1); $st->get('pages/attendance/view.php'); ok($st->shown());
    contains('ac-ring', $st->body, 'student sees a rate ring'); contains('Hub Cards', $st->body); notContains('att-hub-stats', $st->body); notContains('att-week', $st->body);
});
