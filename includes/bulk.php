<?php
/**
 * SmartClass - bulk actions for teachers (loaded by includes/functions.php)
 *   bulkGradeSubmissions()   give the same marks (and feedback) to several submissions of an assignment —
 *                            every one goes through gradeSubmission(), so the checks, the "graded by" record,
 *                            AI-source tracking and the held / released rules are exactly the same as one by one
 *   studentsWithMissingWork() enrolled students who have not handed in an assignment / finished a quiz
 *   remindMissingWork()      notify those students (in the app, and by email if they chose that); a student
 *                            reminded about the same item in the last REMIND_COOLDOWN_HOURS is skipped
 */

const BULK_MAX_GRADE = 200;
const REMIND_COOLDOWN_HOURS = 6;

/**
 * @param int[] $submission_ids  ids posted by the browser (checked here against the assignment)
 * @return array ['ok' => bool, 'graded' => n, 'skipped' => n, 'error' => string]
 */
function bulkGradeSubmissions(array $assignment, array $class, $teacher_id, array $submission_ids, $marks_raw, $feedback) {
    global $db;
    $ids = array_values(array_unique(array_filter(array_map('intval', $submission_ids))));
    if (!$ids) return ['ok' => false, 'error' => 'Tick at least one submission.'];
    if (count($ids) > BULK_MAX_GRADE) return ['ok' => false, 'error' => 'Grade at most ' . BULK_MAX_GRADE . ' submissions at once.'];
    if (!is_numeric($marks_raw) || (float) $marks_raw < 0 || (float) $marks_raw > (float) $assignment['max_marks']) {
        return ['ok' => false, 'error' => 'Enter marks from 0 to ' . (int) $assignment['max_marks'] . '.'];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $subs = $db->fetchAll(
        "SELECT s.*, u.name AS student_name FROM submissions s INNER JOIN users u ON s.student_id = u.id
         INNER JOIN class_members cm ON cm.student_id = s.student_id AND cm.class_id = ?
         WHERE s.assignment_id = ? AND s.id IN ({$in})", array_merge([$class['id'], $assignment['id']], $ids));
    $graded = 0;
    foreach ($subs as $s) {
        if (gradeSubmission($assignment, $s, $marks_raw, $feedback, $teacher_id, $class['id']) === null) $graded++;
    }
    return ['ok' => true, 'graded' => $graded, 'skipped' => count($ids) - $graded];
}

/** Students of the class with nothing handed in / no finished attempt: [['id', 'name']]. */
function studentsWithMissingWork($type, $item_id, $class_id) {
    global $db;
    if ($type === 'assignment') {
        return $db->fetchAll(
            "SELECT u.id, u.name FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
             LEFT JOIN submissions s ON s.assignment_id = ? AND s.student_id = u.id
             WHERE cm.class_id = ? AND s.id IS NULL ORDER BY u.name", [(int) $item_id, (int) $class_id]);
    }
    return $db->fetchAll(
        "SELECT u.id, u.name FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
         LEFT JOIN quiz_attempts a ON a.quiz_id = ? AND a.student_id = u.id AND a.submitted_at IS NOT NULL
         WHERE cm.class_id = ? AND a.id IS NULL ORDER BY u.name", [(int) $item_id, (int) $class_id]);
}

/** @return array ['sent' => n, 'recent' => n (reminded lately, skipped), 'none' => bool (nobody is missing work)] */
function remindMissingWork($type, array $item, array $class, $teacher_id, $note) {
    global $db;
    $missing = studentsWithMissingWork($type, $item['id'], $class['id']);
    if (!$missing) return ['sent' => 0, 'recent' => 0, 'none' => true];
    $link = 'pages/' . ($type === 'assignment' ? 'assignments' : 'quizzes') . '/view.php?id=' . (int) $item['id'];
    $note = mb_substr(trim((string) $note), 0, 500);
    $title = ($type === 'assignment' ? 'Reminder: ' : 'Reminder: quiz ') . $item['title'];
    $due = !empty($item['due_date']) ? ' It is due ' . formatDate($item['due_date'], 'M d, h:i A') . '.' : '';
    $message = "You have not " . ($type === 'assignment' ? 'handed in this assignment' : 'taken this quiz') . " in {$class['class_name']} yet." . $due . ($note !== '' ? " Your teacher says: {$note}" : '');
    $sent = $recent = 0;
    foreach ($missing as $m) {
        $already = $db->fetchOne(
            "SELECT id FROM notifications WHERE user_id = ? AND type = 'work_reminder' AND link = ? AND created_at >= ?",
            [$m['id'], $link, date('Y-m-d H:i:s', strtotime('-' . REMIND_COOLDOWN_HOURS . ' hours'))]);
        if ($already) { $recent++; continue; }
        notify($m['id'], 'work_reminder', $title, $message, $link, $teacher_id, $class['id']);
        $sent++;
    }
    return ['sent' => $sent, 'recent' => $recent, 'none' => false];
}
