<?php
/**
 * First-run guide for a new teacher: create a class → get students in → take attendance.
 * Every step is "done" only when the data really exists (nothing is ticked by hand), so the guide shows on the dashboard until the three are real
 * and then goes away by itself.
 */
function teacherFirstRun($teacherId) {
    global $db;
    $teacherId = (int) $teacherId;
    $mine = '' . teachesClassSql('c') . '';
    $first = $db->fetchOne("SELECT c.id, c.class_name, c.class_code FROM classes c WHERE $mine ORDER BY c.is_active DESC, c.id ASC LIMIT 1", [$teacherId]);
    $classId = $first ? (int) $first['id'] : 0;
    $hasStudents = $first && (int) ($db->fetchOne("SELECT COUNT(*) AS n FROM class_members cm INNER JOIN classes c ON c.id = cm.class_id WHERE $mine", [$teacherId])['n'] ?? 0) > 0;
    $hasAttendance = $first && (int) ($db->fetchOne("SELECT COUNT(*) AS n FROM attendance a INNER JOIN classes c ON c.id = a.class_id WHERE $mine", [$teacherId])['n'] ?? 0) > 0;
    $steps = [
        ['key' => 'class', 'icon' => 'fa-chalkboard-teacher', 'title' => 'Create your first class', 'text' => 'Give it a name — you get a class code at once.',
         'done' => (bool) $first, 'url' => 'pages/classes/create.php', 'cta' => 'Create a class'],
        ['key' => 'students', 'icon' => 'fa-user-plus', 'title' => 'Invite your students',
         'text' => $first ? 'Share the class code ' . $first['class_code'] . ' or the invite link — students who join appear on the People page.' : 'Create a class first, then share its code with your students.',
         'done' => $hasStudents, 'url' => $classId ? 'pages/classes/people.php?id=' . $classId : 'pages/classes/create.php', 'cta' => $classId ? 'Open People' : 'Create a class'],
        ['key' => 'attendance', 'icon' => 'fa-clipboard-check', 'title' => 'Take attendance',
         'text' => 'Mark who is present — it takes a few taps and builds the attendance report.',
         'done' => $hasAttendance, 'url' => $classId ? 'pages/attendance/class.php?id=' . $classId : 'pages/classes/create.php', 'cta' => $classId ? 'Take attendance' : 'Create a class'],
    ];
    $done = count(array_filter($steps, fn($s) => $s['done']));
    return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'finished' => $done === count($steps)];
}
