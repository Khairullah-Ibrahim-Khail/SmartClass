<?php
test('shared rules: "teaches this class", "attended" and the attendance percentage live in one place', function () {
    $db = TW('db'); $t = mkUser('RuT', 'teacher'); $co = mkUser('RuC', 'teacher'); $x = mkUser('RuX', 'teacher'); $c = mkClass($t, 'RuClass');
    $db->insert('class_teachers', ['class_id' => $c, 'teacher_id' => $co, 'role' => 'co_teacher']);
    eq("EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = c.id AND ct.teacher_id = ?)", teachesClassSql('c'), 'the exact condition the pages used to write by hand');
    foreach ([[$t, true, 'the owner'], [$co, true, 'a co-teacher'], [$x, false, 'another teacher']] as [$u, $yes, $who]) {
        $r = $db->fetchOne("SELECT c.id FROM classes c WHERE c.id = ? AND " . teachesClassSql('c'), [$c, $u]);
        eq($yes, (bool) $r, "$who: " . ($yes ? 'teaches' : 'does not teach') . ' the class');
    }
    eq("status IN ('present', 'late', 'official')", attendedSql()); eq("a.status IN ('present', 'late', 'official')", attendedSql('a.status')); eq(['present', 'late', 'official'], ATTENDED_STATUSES);
    $s = mkUser('RuS'); $db->insert('class_members', ['class_id' => $c, 'student_id' => $s]);
    foreach (['2026-03-01' => 'present', '2026-03-02' => 'late', '2026-03-03' => 'official', '2026-03-04' => 'absent'] as $d => $st) $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => $d, 'status' => $st]);
    $r = $db->fetchOne("SELECT COUNT(*) n, SUM(" . attendedSql() . ") ok FROM attendance WHERE class_id = ?", [$c]); eq(4, (int) $r['n']); eq(3, (int) $r['ok'], 'present, late and official count; absent does not');
    eq(75.0, attendancePct($r['ok'], $r['n'])); eq(null, attendancePct(0, 0), 'nothing marked → no percentage'); eq(33.3, attendancePct(1, 3)); eq(100.0, attendancePct(5, 5));
});
