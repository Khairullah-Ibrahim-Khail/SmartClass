<?php
test('attendance summary counts every session as one mark and computes the rate', function () {
    $db = TW('db'); $t = mkUser('AT', 'teacher'); $s = mkUser('As'); $c = mkClass($t, 'Att', [$s]);
    foreach ([['2026-02-02', 1, 'present'], ['2026-02-02', 2, 'absent'], ['2026-02-03', 1, 'late'], ['2026-02-04', 1, 'official']] as [$d, $n, $st])
        $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => $d, 'session' => $n, 'status' => $st]);
    $sum = getAttendanceSummary($c, $s)[$s];
    eq(4, (int) $sum['total']); eq(1, (int) $sum['present']); eq(1, (int) $sum['late']); eq(1, (int) $sum['absent']); eq(1, (int) $sum['official']);
    near(75.0, (float) $sum['rate'], 0.1, 'present + late + official = 3 of 4');
    $slots = attendanceSlots($c, '2026-02-01', '2026-02-28'); eq(4, count($slots)); eq([1, 2], attendanceSessionsOn($c, '2026-02-02'));
});
test('session numbers are clamped to 1-8', function () { eq(1, attendanceSession(0)); eq(1, attendanceSession('x')); eq(8, attendanceSession(8)); eq(1, attendanceSession(9)); eq(3, attendanceSession('3')); });
test('teacher saves two sessions, edits one, clears one — over the real web pages', function () {
    $db = TW('db'); $t = mkUser('AT2', 'teacher'); $s1 = mkUser('Aq1'); $s2 = mkUser('Aq2'); $c = mkClass($t, 'AttWeb', [$s1, $s2]);
    $h = client($t); $tok = $h->token("pages/attendance/class.php?id=$c"); ok($tok !== '', 'csrf token on the page');
    $d = date('Y-m-d');
    $save = fn($n, $a, $b) => $h->post("pages/classes/attendance.php?id=$c", ['csrf_token' => $tok, 'action' => 'save_attendance', 'date' => $d, 'session' => $n, "status" => [$s1 => $a, $s2 => $b]]);
    $save(1, 'present', 'absent'); eq(302, $h->code); $save(2, 'late', 'present');
    $rows = $db->fetchAll('SELECT student_id, session, status FROM attendance WHERE class_id = ? ORDER BY session, student_id', [$c]);
    eq(4, count($rows)); eq('absent', $rows[1]['status']); eq('late', $rows[2]['status']);
    $save(1, 'present', 'present');   // edit session 1 only
    eq('present', $db->fetchOne('SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND session = 1', [$c, $s2])['status']);
    eq('late', $db->fetchOne('SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND session = 2', [$c, $s1])['status'], 'session 2 untouched');
    $h->post("pages/classes/attendance.php?id=$c", ['csrf_token' => $tok, 'action' => 'clear_date', 'date' => $d, 'session' => 2]);
    eq(0, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE class_id = ? AND session = 2', [$c])['c']); eq(2, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE class_id = ? AND session = 1', [$c])['c']);
    ok((int) $db->fetchOne('SELECT COUNT(*) c FROM attendance_log WHERE class_id = ?', [$c])['c'] >= 6, 'every change is logged');
});
test('a save without every student marked is refused and saves nothing', function () {
    $db = TW('db'); $t = mkUser('AT3', 'teacher'); $s1 = mkUser('Ar1'); $s2 = mkUser('Ar2'); $c = mkClass($t, 'AttPartial', [$s1, $s2]);
    $h = client($t); $tok = $h->token("pages/attendance/class.php?id=$c");
    $h->post("pages/classes/attendance.php?id=$c", ['csrf_token' => $tok, 'action' => 'save_attendance', 'date' => date('Y-m-d'), 'session' => 1, 'status' => [$s1 => 'present']]);
    eq(0, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE class_id = ?', [$c])['c']);
});
test('a student cannot take attendance and the future cannot be marked', function () {
    $db = TW('db'); $t = mkUser('AT4', 'teacher'); $s = mkUser('As4'); $c = mkClass($t, 'AttDeny', [$s]);
    $hs = client($s); $tok = $hs->token("pages/attendance/class.php?id=$c");
    $hs->post("pages/classes/attendance.php?id=$c", ['csrf_token' => $tok, 'action' => 'save_attendance', 'date' => date('Y-m-d'), 'session' => 1, 'status' => [$s => 'present']]);
    eq(0, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE class_id = ?', [$c])['c'], 'student mark must not be saved');
    $ht = client($t); $tt = $ht->token("pages/attendance/class.php?id=$c");
    $ht->post("pages/classes/attendance.php?id=$c", ['csrf_token' => $tt, 'action' => 'save_attendance', 'date' => date('Y-m-d', strtotime('+3 days')), 'session' => 1, 'status' => [$s => 'present']]);
    eq(0, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE class_id = ?', [$c])['c'], 'future day must not be saved');
});
