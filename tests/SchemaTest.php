<?php
test('fresh install schema has every core table', function () {
    $tables = array_map(fn($r) => array_values($r)[0], TW('db')->fetchAll('SHOW TABLES'));
    foreach (['users', 'classes', 'class_members', 'attendance', 'lectures', 'assignments', 'quizzes', 'exams', 'ai_tutor_chats', 'academic_years', 'schema_migrations'] as $t) ok(in_array($t, $tables, true), "table $t missing");
    ok(count($tables) >= 60, 'expected 60+ tables, got ' . count($tables));
});
test('attendance allows several sessions per day but only one mark per session', function () {
    $db = TW('db'); $t = mkUser('T', 'teacher'); $s = mkUser('S'); $c = mkClass($t, 'C', [$s]);
    $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => '2026-01-05', 'session' => 1, 'status' => 'present']);
    $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => '2026-01-05', 'session' => 2, 'status' => 'absent']);
    $dup = false; try { $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => '2026-01-05', 'session' => 2, 'status' => 'late']); } catch (Throwable $e) { $dup = true; }
    ok($dup, 'a second mark for the same session must be refused');
});
