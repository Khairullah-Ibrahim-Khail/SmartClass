<?php
/**
 * SmartClass - releasing results (loaded by includes/functions.php)
 *
 * Every assignment and quiz has a release mode:
 *   instant  a student sees a grade / score as soon as it exists (the default, as before)
 *   manual   hidden from students until the teacher releases the results
 * Releasing sets results_released_at, notifies each student with a result ONCE (in-app + email, through
 * notify()), and from then on new grades are visible at once. While held, every student-facing page
 * shows "Result pending" instead of marks (resultsReleased() / sqlResultsVisible()).
 */

/** Can students see the results of this assignment / quiz row? (needs release_mode + results_released_at) */
function resultsReleased($item) {
    return !is_array($item) || ($item['release_mode'] ?? 'instant') !== 'manual' || !empty($item['results_released_at']);
}

/** SQL condition on alias $alias (assignments or quizzes): results are visible to students. */
function sqlResultsVisible($alias) {
    return "({$alias}.release_mode = 'instant' OR {$alias}.results_released_at IS NOT NULL)";
}

/** Release modes for forms: key => [label, hint]. */
function releaseModes() {
    return [
        'instant' => ['As soon as it is graded', 'Students see their result right away'],
        'manual'  => ['When I release the results', 'Hidden from students until you press "Release results"'],
    ];
}

/** Post the chosen release mode ('instant'|'manual') from a form, defaulting to instant. */
function postedReleaseMode($key = 'release_mode') {
    return (($_POST[$key] ?? '') === 'manual') ? 'manual' : 'instant';
}

/** The release switch block (teacher pages). */
function releaseModeField($selected) {
    $out = '<fieldset class="release-field"><legend>Results</legend>';
    foreach (releaseModes() as $k => [$label, $hint]) {
        $out .= '<label class="switch-row"><input type="radio" name="release_mode" value="' . $k . '"' . ($selected === $k ? ' checked' : '') . '>'
              . '<span><strong>' . htmlspecialchars($label) . '</strong><small class="form-hint" style="display:block;margin:0;">' . htmlspecialchars($hint) . '</small></span></label>';
    }
    return $out . '</fieldset>';
}

/**
 * Release the results of an assignment ('assignment') or quiz ('quiz').
 * $item = the DB row (with class_id, title, max_marks / id). The caller has checked the teacher teaches the class.
 * Marks the item released (atomically — a second click does nothing) and notifies each student that has a
 * result. Returns the number of students notified, or null when it was already released.
 */
function releaseResults($type, array $item, array $class, $teacher_id) {
    global $db;
    $table = $type === 'quiz' ? 'quizzes' : 'assignments';
    $claimed = $db->query("UPDATE {$table} SET results_released_at = NOW() WHERE id = ? AND results_released_at IS NULL", [(int) $item['id']])->rowCount();
    if (!$claimed) return null;

    $n = 0;
    if ($type === 'quiz') {
        foreach ($db->fetchAll("SELECT student_id, score, max_score, timed_out FROM quiz_attempts WHERE quiz_id = ? AND submitted_at IS NOT NULL", [(int) $item['id']]) as $a) {
            $pct = $a['max_score'] > 0 ? round($a['score'] / $a['max_score'] * 100) : 0;
            $msg = $a['timed_out'] ? 'Time ran out, so your answers were not counted.'
                 : 'You scored ' . (0 + $a['score']) . '/' . (int) $a['max_score'] . " ({$pct}%) in {$class['class_name']}.";
            notify($a['student_id'], 'result_released', 'Results released: ' . $item['title'], $msg,
                'pages/quizzes/view.php?id=' . (int) $item['id'], $teacher_id, $class['id']);
            waOnResult($class['id'], $a['student_id'], 'quiz', $item['id'], $item['title'], $class['class_name'], $teacher_id);
            $n++;
        }
    } else {
        foreach ($db->fetchAll("SELECT student_id, marks_obtained, feedback FROM submissions WHERE assignment_id = ? AND status = 'graded'", [(int) $item['id']]) as $s) {
            $msg = 'You got ' . (0 + $s['marks_obtained']) . '/' . (int) $item['max_marks'] . " in {$class['class_name']}."
                 . (trim((string) $s['feedback']) !== '' ? ' Feedback: ' . mb_strimwidth(trim($s['feedback']), 0, 280, '…') : '');
            notify($s['student_id'], 'result_released', 'Results released: ' . $item['title'], $msg,
                'pages/assignments/view.php?id=' . (int) $item['id'], $teacher_id, $class['id']);
            waOnResult($class['id'], $s['student_id'], 'assignment', $item['id'], $item['title'], $class['class_name'], $teacher_id);
            $n++;
        }
    }
    logActivity('release_results', $table, "Released the results of {$type} #{$item['id']} \"{$item['title']}\" in {$class['class_name']} ({$n} students notified)");
    return $n;
}

/** Switch an item between instant and manual (teacher of the class). Returns true when it changed. */
function setReleaseMode($type, $item_id, $mode) {
    global $db;
    $table = $type === 'quiz' ? 'quizzes' : 'assignments';
    $mode = $mode === 'manual' ? 'manual' : 'instant';
    return (bool) $db->query("UPDATE {$table} SET release_mode = ? WHERE id = ? AND release_mode <> ?", [$mode, (int) $item_id, $mode])->rowCount();
}

/** Held results of an item: how many students have a result that is still hidden (teacher banner). */
function heldResultCount($type, $item_id) {
    global $db;
    return $type === 'quiz'
        ? (int) $db->fetchOne("SELECT COUNT(*) AS c FROM quiz_attempts WHERE quiz_id = ? AND submitted_at IS NOT NULL", [(int) $item_id])['c']
        : (int) $db->fetchOne("SELECT COUNT(*) AS c FROM submissions WHERE assignment_id = ? AND status = 'graded'", [(int) $item_id])['c'];
}
