<?php
/**
 * SmartClass - school-wide figures for the super admin (loaded by includes/functions.php)
 *   schoolClassReport() => one row per active class: teacher, students, average grade, attendance, work handed in, students needing attention.
 * Only totals per class — no student names. Built from classReport(), the same numbers as the class pages.
 */
function schoolClassReport() {
    global $db;
    $rows = [];
    foreach ($db->fetchAll(
        "SELECT c.id, c.class_name, u.name AS teacher_name FROM classes c INNER JOIN users u ON c.teacher_id = u.id
         WHERE c.is_active = 1 ORDER BY c.class_name") as $c) {
        $r = classReport((int) $c['id']);
        $s = $r['summary'];
        $rows[] = ['id' => (int) $c['id'], 'name' => $c['class_name'], 'subject' => '', 'teacher' => $c['teacher_name'], 'students' => $s['students'],
                   'average' => $s['average'], 'attendance' => $s['attendance'], 'submission' => $s['submission_rate'], 'at_risk' => $s['at_risk'],
                   'work' => $r['assignments'] + $r['quizzes']];
    }
    return $rows;
}
