<?php
/**
 * SmartClass - final marks (loaded by includes/functions.php)
 *
 * The total a student ends the class with, built from parts, each worth a number of marks the teacher sets per
 * class (the marking scheme): Mid exam, Final exam, Activities, Quizzes, Assignments — e.g. 20 + 40 + 10 + 15 + 15 = 100.
 *   mid / final / activity   the class's exams of that kind (set when creating the exam); several of one kind are pooled
 *   quiz / assignment        the class's quizzes / assignments (the gradebook figures)
 * Part earned = (marks earned ÷ marks possible on that part's items) × the part's weight.
 * An item counts once it has a mark, or when a student missed it (absent, not handed in past the due date = 0).
 * Students only ever get exams whose results were published and quiz results that were released.
 *   markingComponents()                 key => label
 *   markingScheme($class_id)            key => weight (default 20 / 40 / 10 / 15 / 15)
 *   classFinalMarks($class_id, $student_view, $only_student)
 *        => ['scheme' => key => weight, 'weight_all' => n, 'rows' => [ id, name, parts[key] => [pct, earned, weight, items],
 *            total, weight_done, percent_so_far, final_percent, percent ]]
 *   'percent' follows Settings › Grading scale › "Grade is based on": marked parts only, or all parts (missing = 0).
 */

function markingComponents() {
    return ['mid' => 'Mid exam', 'final' => 'Final exam', 'activity' => 'Activities', 'quiz' => 'Quizzes', 'assignment' => 'Assignments'];
}

function markingDefaults() {
    return ['mid' => 20.0, 'final' => 40.0, 'activity' => 10.0, 'quiz' => 15.0, 'assignment' => 15.0];
}

function markingScheme($class_id) {
    global $db;
    $scheme = markingDefaults();
    $rows = $db->fetchAll("SELECT component, weight FROM class_marking_scheme WHERE class_id = ?", [(int) $class_id]);
    foreach ($rows as $r) $scheme[$r['component']] = (float) $r['weight'];
    return $scheme;
}

function examKinds() {
    return ['mid' => 'Mid exam', 'final' => 'Final exam', 'activity' => 'Activity / practical / oral', 'other' => 'Other (not in the final marks)'];
}

function classFinalMarks($class_id, $student_view = false, $only_student = null) {
    global $db;
    gradingUseClass($class_id);
    $scheme = markingScheme($class_id);
    $active = array_filter($scheme, fn($w) => $w > 0);
    $weight_all = array_sum($active);
    $book = getClassGradebook($class_id, $only_student, $student_view);
    $students = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name']], $book['rows']);

    // pool[student][component] = [earned, possible, items]
    $pool = [];
    $add = function ($sid, $comp, $earned, $possible) use (&$pool) {
        if ($possible <= 0) return;
        $p = $pool[$sid][$comp] ?? [0.0, 0.0, 0];
        $pool[$sid][$comp] = [$p[0] + $earned, $p[1] + $possible, $p[2] + 1];
    };

    // quizzes / assignments from the gradebook
    foreach ($book['columns'] as $i => $c) {
        $comp = $c['type'] === 'quiz' ? 'quiz' : 'assignment';
        foreach ($book['rows'] as $r) {
            $cell = $r['cells'][$i];
            if ($cell['score'] !== null) $add($r['id'], $comp, (float) $cell['score'], (float) $c['max']);
            elseif (in_array($cell['state'], ['missing', 'timeout'], true)) $add($r['id'], $comp, 0.0, (float) $c['max']);
        }
    }
    // exams
    $exams = $db->fetchAll("SELECT id, kind, max_marks FROM exams WHERE class_id = ? AND kind IN ('mid', 'final', 'activity')" . ($student_view ? " AND results_published_at IS NOT NULL" : ''), [(int) $class_id]);
    if ($exams) {
        $kind = array_column($exams, 'kind', 'id');
        $max = array_column($exams, 'max_marks', 'id');
        $in = implode(',', array_fill(0, count($exams), '?'));
        foreach ($db->fetchAll("SELECT exam_id, student_id, marks, absent FROM exam_marks WHERE exam_id IN ({$in})", array_keys($kind)) as $m) {
            if ($only_student !== null && (int) $m['student_id'] !== (int) $only_student) continue;
            if ($m['absent']) $add((int) $m['student_id'], $kind[$m['exam_id']], 0.0, (float) $max[$m['exam_id']]);
            elseif ($m['marks'] !== null) $add((int) $m['student_id'], $kind[$m['exam_id']], (float) $m['marks'], (float) $max[$m['exam_id']]);
        }
    }

    $rows = [];
    foreach ($students as $st) {
        $parts = []; $total = 0.0; $done = 0.0;
        foreach ($active as $key => $w) {
            $p = $pool[$st['id']][$key] ?? null;
            if ($p && $p[1] > 0) {
                $pct = $p[0] / $p[1] * 100;
                $earned = round($pct / 100 * $w, 2);
                $total += $earned; $done += $w;
                $parts[$key] = ['pct' => round($pct, 1), 'earned' => $earned, 'weight' => $w, 'items' => $p[2]];
            } else {
                $parts[$key] = ['pct' => null, 'earned' => null, 'weight' => $w, 'items' => 0];
            }
        }
        $so_far = $done > 0 ? round($total / $done * 100, 1) : null;
        $final = ($done > 0 && $weight_all > 0) ? round($total / $weight_all * 100, 1) : null;
        $rows[] = ['id' => $st['id'], 'name' => $st['name'], 'parts' => $parts, 'total' => round($total, 2), 'weight_done' => $done,
                   'percent_so_far' => $so_far, 'final_percent' => $final, 'percent' => gradingBasis() === 'all' ? $final : $so_far];
    }
    return ['scheme' => $active, 'weight_all' => $weight_all, 'rows' => $rows];
}
