<?php
/**
 * SmartClass - class reports (loaded by includes/functions.php)
 *   classReport($class_id, $student_id = null, $student_view = false)
 *     per student: attendance %, assignments handed in, quizzes finished, average grade %, missing work, risk flags
 *     plus class totals. Uses the same gradebook as the Grades tab, so numbers always match it.
 *     $student_view hides results that are still held (manual release).
 */


function classReport($class_id, $student_id = null, $student_view = false) {
    global $db;
    $book = getClassGradebook($class_id, $student_id, $student_view);
    $att = [];
    $params = [(int) $class_id];
    $only = '';
    if ($student_id !== null) { $only = ' AND student_id = ?'; $params[] = (int) $student_id; }
    foreach ($db->fetchAll("SELECT student_id, COUNT(*) AS marked, SUM(" . attendedSql('status') . ") AS attended FROM attendance WHERE class_id = ?{$only} GROUP BY student_id", $params) as $a) {
        $att[(int) $a['student_id']] = $a;
    }
    $cols = $book['columns'];
    $n_assign = count(array_filter($cols, fn($c) => $c['type'] === 'assignment'));
    $n_quiz = count($cols) - $n_assign;
    $out = [];
    foreach ($book['rows'] as $r) {
        $submitted = $quiz_done = $missing = 0;
        foreach ($r['cells'] as $i => $cell) {
            $isA = $cols[$i]['type'] === 'assignment';
            if ($cell['state'] === 'missing') $missing++;
            if ($isA && in_array($cell['state'], ['submitted', 'late', 'graded', 'held'], true)) $submitted++;
            if (!$isA && in_array($cell['state'], ['done', 'timeout', 'held'], true)) $quiz_done++;
        }
        $a = $att[$r['id']] ?? null;
        $att_pct = $a ? attendancePct($a['attended'], $a['marked']) : null;
        $risk = [];
        if ($r['percent'] !== null && $r['percent'] < lowGradePct()) $risk[] = 'Low grades';
        if ($att_pct !== null && $att_pct < attLow()) $risk[] = 'Low attendance';
        if ($missing >= 2) $risk[] = $missing . ' missing';
        $out[] = ['id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'attendance' => $att_pct, 'attendance_marked' => (int) ($a['marked'] ?? 0),
                  'submitted' => $submitted, 'quiz_done' => $quiz_done, 'missing' => $missing, 'average' => $r['percent'], 'risk' => $risk];
    }
    $avgs = array_filter(array_column($out, 'average'), fn($x) => $x !== null);
    $atts = array_filter(array_column($out, 'attendance'), fn($x) => $x !== null);
    $possible_sub = $n_assign * count($out);
    return ['students' => $out, 'assignments' => $n_assign, 'quizzes' => $n_quiz,
            'summary' => ['students' => count($out),
                          'average' => $avgs ? round(array_sum($avgs) / count($avgs), 1) : null,
                          'attendance' => $atts ? round(array_sum($atts) / count($atts), 1) : null,
                          'submission_rate' => $possible_sub ? round(array_sum(array_column($out, 'submitted')) / $possible_sub * 100) : null,
                          'at_risk' => count(array_filter($out, fn($s) => $s['risk']))]];
}
