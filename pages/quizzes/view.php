<?php
/**
 * Quizzes
 *   (none) / ?class_id=N  list of quizzes in the user's classes, with status/score
 *   ?id=N                 one quiz
 *       teacher: every student's attempt + answer key; delete (POST + CSRF)
 *       student: start / continue, or — after submitting — score and answer review
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

/** 7.50 -> "7.5", 10.00 -> "10". */
function quizMarks($m) {
    return rtrim(rtrim(number_format((float) $m, 2, '.', ''), '0'), '.');
}

$quiz_id = (int) ($_GET['id'] ?? 0);
$task_result = null;

if ($quiz_id > 0) {
    $quiz = $db->fetchOne("SELECT * FROM quizzes WHERE id = ?", [$quiz_id]);
    // Ownership / membership check on the quiz's class
    $class = $quiz ? getAccessibleClass($quiz['class_id'], $current_user_id, $current_user_role) : null;
    if (!$class) {
        redirect(APP_URL . '/pages/quizzes/view.php?error=not_found');
    }

    // ---------- Private comment from a student about this quiz (goes to the class Messages tab) ----------
    $pc_error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'private_comment' && isStudent()) {
        $body = trim($_POST['body'] ?? '');
        if (!verifyCsrf()) {
            $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
        } elseif ($body === '' || mb_strlen($body) > 2000) {
            $pc_error = 'Write a comment (up to 2,000 characters)';
        } else {
            postPrivateMessage($class, $current_user_id, $current_user_id, $current_user_name,
                messageContext($class['id'], 'quiz', $quiz['id']), $body);
            redirect(APP_URL . '/pages/quizzes/view.php?id=' . $quiz['id'] . '#private');
        }
    }
    // ---------- Result release (teacher of the class): mode switch + "Release results" ----------
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['set_release_mode', 'release_results'], true)) {
        if (!verifyCsrf() || !isTeacher()) {
            Session::flash('quiz_msg', ['error', 'Not allowed', 'Only a teacher of this class can do that.']);
        } elseif ($_POST['action'] === 'set_release_mode') {
            $mode = postedReleaseMode();
            if (setReleaseMode('quiz', $quiz['id'], $mode)) {
                logActivity('release_mode', 'quizzes', "Set the results of quiz #{$quiz['id']} to {$mode}");
                Session::flash('quiz_msg', ['success', 'Saved', $mode === 'manual' ? 'Students will not see their score until you release the results.' : 'Students see their score as soon as they submit.']);
            }
        } else {
            $n = releaseResults('quiz', $quiz, $class, $current_user_id);
            Session::flash('quiz_msg', $n === null ? ['info', 'Already released', 'The results of this quiz were released before.']
                : ['success', 'Results released', $n . ' student' . ($n == 1 ? ' was' : 's were') . ' notified (in the app and by email).']);
        }
        redirect(APP_URL . '/pages/quizzes/view.php?id=' . $quiz['id']);
    }
    // ---------- Remind students who have not taken the quiz (teacher) ----------
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind_missing') {
        if (!verifyCsrf() || !isTeacher()) {
            Session::flash('quiz_msg', ['error', 'Not allowed', 'Only a teacher of this class can do that.']);
        } else {
            $r = remindMissingWork('quiz', $quiz, $class, $current_user_id, $_POST['note'] ?? '');
            Session::flash('quiz_msg', $r['none'] ? ['info', 'Nobody is missing', 'Every student has finished this quiz.']
                : ['success', $r['sent'] . ' reminder' . ($r['sent'] == 1 ? '' : 's') . ' sent', 'Students who had a reminder for this in the last ' . REMIND_COOLDOWN_HOURS . ' hours were skipped' . ($r['recent'] ? " ({$r['recent']})" : '') . '.']);
            if (!$r['none']) logActivity('remind_missing', 'quizzes', "Reminded {$r['sent']} students about quiz #{$quiz['id']}");
        }
        redirect(APP_URL . '/pages/quizzes/view.php?id=' . $quiz['id']);
    }
    // ---------- Reactivate the quiz for chosen students (teacher): selected, everyone, or those who did not finish ----------
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen_quiz') {
        $ids = [];
        $until = null;
        $err = '';
        if (!verifyCsrf() || !isTeacher()) {
            $err = 'Only a teacher of this class can reactivate a quiz.';
        } else {
            $scope = $_POST['scope'] ?? 'selected';
            if ($scope === 'all') {
                $ids = array_column($db->fetchAll("SELECT student_id FROM class_members WHERE class_id = ?", [$class['id']]), 'student_id');
            } elseif ($scope === 'unfinished') {
                $ids = array_column($db->fetchAll(
                    "SELECT cm.student_id FROM class_members cm LEFT JOIN quiz_attempts a ON a.quiz_id = ? AND a.student_id = cm.student_id
                     WHERE cm.class_id = ? AND (a.id IS NULL OR a.submitted_at IS NULL)", [$quiz['id'], $class['id']]), 'student_id');
            } else {
                $ids = is_array($_POST['student_ids'] ?? null) ? $_POST['student_ids'] : [];
            }
            if (empty($_POST['no_deadline'])) {
                $d = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($_POST['until'] ?? ''));
                if (!$d || $d->getTimestamp() <= time()) $err = 'Choose a new deadline in the future, or tick "No deadline".';
                elseif ($d->getTimestamp() > strtotime('+1 year')) $err = 'The new deadline must be within a year.';
                else $until = $d->format('Y-m-d H:i:s');
            }
            if (!$err && !$ids) $err = 'Select at least one student.';
        }
        if ($err) {
            Session::flash('quiz_msg', ['error', 'Not reactivated', $err]);
        } else {
            $keep = !empty($_POST['keep_attempt']);
            $r = quizReopen($quiz, $class, $ids, $until, $current_user_id, $keep);
            if (!$r['done']) {
                Session::flash('quiz_msg', ['error', 'Nobody reactivated', $keep && $r['finished'] ? 'They have all finished the quiz already — nothing to extend. Untick "Only give more time" to let them take it again.' : 'The chosen people are not students of this class.']);
                redirect(APP_URL . '/pages/quizzes/view.php?id=' . $quiz['id']);
            }
            logActivity('reopen_quiz', 'quizzes', "Reactivated quiz #{$quiz['id']} \"{$quiz['title']}\" for {$r['done']} student(s)");
            Session::flash('quiz_msg', ['success', 'Quiz reactivated for ' . $r['done'] . ' student' . ($r['done'] == 1 ? '' : 's'),
                ($keep ? 'They have more time' : 'They were notified and can take it again') . ($until ? ' until ' . date('M d, h:i A', strtotime($until)) : ' with no deadline') . ($keep ? '. Nobody\'s attempt was cleared' . ($r['finished'] ? ' (' . $r['finished'] . ' finished already, left alone)' : '') . '.' : '. Their earlier scores are kept in the history.')]);
        }
        redirect(APP_URL . '/pages/quizzes/view.php?id=' . $quiz['id']);
    }
    // ---------- Short answers: mark one (teacher) or let the AI mark the waiting ones ----------
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['mark_answer', 'ai_mark_short'], true)) {
        if (!verifyCsrf() || !isTeacher()) {
            Session::flash('quiz_msg', ['error', 'Not allowed', 'Only a teacher of this class can mark answers.']);
        } elseif ($_POST['action'] === 'mark_answer') {
            $ans = $db->fetchOne(
                "SELECT a.* FROM quiz_answers a INNER JOIN quiz_questions q ON a.question_id = q.id
                 WHERE a.id = ? AND q.quiz_id = ? AND q.question_type = 'short'", [(int) ($_POST['answer_id'] ?? 0), $quiz['id']]);
            $qrow = $ans ? $db->fetchOne("SELECT * FROM quiz_questions WHERE id = ?", [$ans['question_id']]) : null;
            if (!$qrow) {
                Session::flash('quiz_msg', ['error', 'Not found', 'That answer is not part of this quiz.']);
            } elseif ($err = saveShortMark($ans, $qrow, $_POST['marks'] ?? '', $_POST['feedback'] ?? '', 'teacher')) {
                Session::flash('quiz_msg', ['error', 'Not saved', $err]);
            } else {
                logActivity('mark_short_answer', 'quizzes', "Marked a short answer of quiz #{$quiz['id']}");
                Session::flash('quiz_msg', ['success', 'Saved', 'The marks were saved.']);
            }
        } else {
            $r = aiMarkShortAnswers($quiz, $current_user_id);
            if (!$r['ok']) Session::flash('quiz_msg', ['error', 'AI could not mark', $r['error']]);
            else {
                logActivity('ai_mark_short', 'quizzes', "AI marked {$r['marked']} short answers of quiz #{$quiz['id']}");
                Session::flash('quiz_msg', ['success', $r['marked'] . ' answer' . ($r['marked'] == 1 ? '' : 's') . ' marked',
                    'Read the AI\'s marks below — change any of them.' . ($r['flagged'] ? " {$r['flagged']} answer(s) tried to influence the marker and were left for you." : '')
                    . ($r['left'] - $r['flagged'] > 0 ? ' Press the button again for the rest.' : '')]);
            }
        }
        redirect(APP_URL . '/pages/quizzes/view.php?id=' . $quiz['id'] . '#shortmarks');
    }
    // ---------- Delete (teacher who owns the class) ----------
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
        } elseif (!isTeacher() || ($_POST['action'] ?? '') !== 'delete') {
            $task_result = ['error', 'Not allowed', 'Only the class teacher can delete a quiz.'];
        } else {
            $db->delete('quizzes', 'id = ? AND class_id = ?', [$quiz['id'], $class['id']]);
            logActivity('delete_quiz', 'quizzes', "Deleted quiz #{$quiz['id']} \"{$quiz['title']}\" from {$class['class_name']}");
            Session::flash('quiz_deleted', $quiz['title']);
            redirect(APP_URL . '/pages/quizzes/view.php?class_id=' . $class['id']);
        }
    }

    $questions = $db->fetchAll("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id", [$quiz['id']]);
    $max_score = array_sum(array_column($questions, 'marks'));
    $options_by_q = [];
    foreach ($db->fetchAll(
        "SELECT o.* FROM quiz_options o INNER JOIN quiz_questions q ON o.question_id = q.id
         WHERE q.quiz_id = ? ORDER BY o.question_id, o.position, o.id", [$quiz['id']]) as $o) {
        $options_by_q[$o['question_id']][] = $o;
    }
    $closed = !empty($quiz['due_date']) && time() > strtotime($quiz['due_date']);
    if (isStudent()) $closed = quizClosedFor($quiz, $current_user_id);   // a reopened quiz is open again for that student
    $released = resultsReleased($quiz);
    $flash_msg = Session::flash('quiz_msg');
    if ($flash_msg) $task_result = $flash_msg;

    if (isTeacher()) {
        $roster = $db->fetchAll(
            "SELECT u.id, u.name, u.email, a.started_at, a.submitted_at, a.score, a.max_score, a.timed_out,
                    (SELECT COUNT(*) FROM quiz_reopens r WHERE r.quiz_id = ? AND r.student_id = u.id) AS reopens,
                    (SELECT r.prev_score FROM quiz_reopens r WHERE r.quiz_id = ? AND r.student_id = u.id ORDER BY r.reopened_at DESC, r.id DESC LIMIT 1) AS last_prev_score,
                    (SELECT r.prev_max FROM quiz_reopens r WHERE r.quiz_id = ? AND r.student_id = u.id ORDER BY r.reopened_at DESC, r.id DESC LIMIT 1) AS last_prev_max
             FROM class_members cm
             INNER JOIN users u ON cm.student_id = u.id
             LEFT JOIN quiz_attempts a ON a.quiz_id = ? AND a.student_id = u.id
             WHERE cm.class_id = ? ORDER BY u.name",
            [$quiz['id'], $quiz['id'], $quiz['id'], $quiz['id'], $class['id']]
        );
        $done = array_filter($roster, fn($r) => $r['submitted_at'] !== null);
        $avg = $done ? array_sum(array_map(fn($r) => $r['max_score'] ? $r['score'] / $r['max_score'] * 100 : 0, $done)) / count($done) : null;
    } else {
        $attempt = $db->fetchOne("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?", [$quiz['id'], $current_user_id]);
        $my_answers = [];
        if ($attempt && $attempt['submitted_at'] !== null && $released) {
            foreach ($db->fetchAll("SELECT question_id, option_id, is_correct, answer_text, marks_awarded, feedback, mark_source FROM quiz_answers WHERE attempt_id = ?", [$attempt['id']]) as $a) {
                $my_answers[$a['question_id']] = $a;
            }
        }
        $done_flash = Session::flash('quiz_done');
        if ($done_flash) {
            $task_result = $done_flash['timed_out']
                ? ['warning', 'Time limit exceeded', 'Your answers arrived after the time limit, so they were not counted.']
                : ($released ? ['success', 'Quiz submitted!', 'You scored ' . quizMarks($done_flash['score']) . ' / ' . $done_flash['max'] . '.'
                                . (!empty($done_flash['waiting']) ? ' Your short answers are still to be marked, so this can go up.' : '')]
                             : ['success', 'Quiz submitted!', 'Your teacher will release the results soon.']);
        }
    }

    $page_title = $quiz['title'];
    $page_subtitle = $class['class_name'] . ' · Quiz';
} else {
    // ---------- List ----------
    $quiz = null;
    $classes = getUserClasses($current_user_id, $current_user_role);
    $class_ids = array_map('intval', array_column($classes, 'id'));

    $class = null;
    $filter_id = (int) ($_GET['class_id'] ?? 0);
    if ($filter_id > 0) {
        $class = getAccessibleClass($filter_id, $current_user_id, $current_user_role);
        if (!$class) {
            redirect(APP_URL . '/pages/quizzes/view.php?error=not_found');
        }
        $class_ids = [$class['id']];
    }

    $quizzes = [];
    if ($class_ids) {
        $in = implode(',', array_fill(0, count($class_ids), '?'));
        $common = "(SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count,
                   (SELECT COALESCE(SUM(qq.marks), 0) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS total_marks";
        if (isTeacher()) {
            $quizzes = $db->fetchAll(
                "SELECT q.*, c.class_name, {$common},
                        (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = q.class_id) AS student_count,
                        (SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id = q.id AND a.submitted_at IS NOT NULL) AS done_count,
                        (SELECT AVG(a.score / NULLIF(a.max_score, 0) * 100) FROM quiz_attempts a WHERE a.quiz_id = q.id AND a.submitted_at IS NOT NULL) AS avg_pct
                 FROM quizzes q INNER JOIN classes c ON q.class_id = c.id
                 WHERE q.class_id IN ({$in})
                 ORDER BY q.created_at DESC, q.id DESC",
                $class_ids
            );
        } else {
            $quizzes = $db->fetchAll(
                "SELECT q.*, c.class_name, {$common},
                        a.submitted_at, a.score, a.max_score, a.timed_out, a.id AS attempt_id
                 FROM quizzes q INNER JOIN classes c ON q.class_id = c.id
                 LEFT JOIN quiz_attempts a ON a.quiz_id = q.id AND a.student_id = ?
                 WHERE q.class_id IN ({$in})
                 ORDER BY q.created_at DESC, q.id DESC",
                array_merge([$current_user_id], $class_ids)
            );
        }
    }

    $deleted = Session::flash('quiz_deleted');
    if ($deleted !== null) {
        $task_result = ['success', 'Quiz deleted', '"' . $deleted . '" and all its results were removed.'];
    }

    $page_title = $class ? $class['class_name'] . ' - Quizzes' : 'Quizzes';
    $page_subtitle = isTeacher() ? 'Multiple-choice quizzes in your classes, graded automatically' : 'Quizzes in your classes';
}

if (!$task_result && ($_GET['error'] ?? '') === 'not_found') {
    $task_result = ['error', 'Not available', 'That quiz or class does not exist or you do not have access to it.'];
}

$active_menu = 'quizzes';
$page_scripts = $quiz ? (isTeacher() ? ['assets/js/class.js', 'assets/js/quiz-ai.js', 'assets/js/bulk.js', 'assets/js/quiz-reopen.js'] : ['assets/js/quiz-ai.js']) : [];
$page_script = "
    const f = document.getElementById('classFilter');
    if (f) f.addEventListener('change', function() { f.form.submit(); });
    const del = document.getElementById('deleteQuiz');
    if (del) del.addEventListener('submit', function(e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Delete this quiz?',
            text: 'All questions and every student result will be removed.',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete',
            confirmButtonColor: '#ef476f'
        }).then(function(r) { if (r.isConfirmed) del.submit(); });
    });";
if ($task_result) {
    $page_script .= "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", title: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<?php if ($quiz): ?>
<!-- ===================== One quiz ===================== -->
<a class="back-link" href="<?php ob_start(); ?>view.php?class_id=<?php echo (int) $class['id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>">
    <i class="fas fa-arrow-left"></i> All quizzes in <?php echo htmlspecialchars($class['class_name']); ?>
</a>

<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-question-circle"></i> <?php echo htmlspecialchars($quiz['title']); ?></h2>
        <?php if (isTeacher()): ?>
        <div class="action-buttons">
            <a href="results.php?class_id=<?php echo (int) $class['id']; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-chart-bar"></i> Class Results</a>
            <form method="POST" id="deleteQuiz">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn-app btn-sm btn-danger"><i class="fas fa-trash"></i> Delete</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-inner">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Questions</div><div class="value"><?php echo count($questions); ?></div></div>
            <div class="detail-item"><div class="label">Total Marks</div><div class="value"><?php echo (int) $max_score; ?></div></div>
            <div class="detail-item"><div class="label">Time Limit</div><div class="value"><?php echo $quiz['time_limit'] ? (int) $quiz['time_limit'] . ' min' : 'None'; ?></div></div>
            <div class="detail-item"><div class="label">Due</div><div class="value"><?php echo $quiz['due_date'] ? formatDate($quiz['due_date'], 'M d, Y h:i A') : 'Open'; ?>
                <?php if ($closed): ?><span class="status-badge missing">Closed</span><?php endif; ?></div></div>
            <?php if (isTeacher()): ?>
            <div class="detail-item"><div class="label">Completed</div><div class="value"><?php echo count($done); ?> / <?php echo count($roster); ?></div></div>
            <div class="detail-item"><div class="label">Class Average</div><div class="value"><?php echo $avg === null ? '—' : round($avg, 1) . '%'; ?></div></div>
            <?php endif; ?>
        </div>
        <?php if (!empty($quiz['description'])): ?>
        <div class="prewrap"><?php echo htmlspecialchars($quiz['description']); ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if (isStudent()): ?>
<!-- Student: start / continue / result -->
<?php if (!$attempt || $attempt['submitted_at'] === null): ?>
<div class="content-card">
    <div class="card-inner" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <?php if ($attempt): ?>
        <span class="status-badge late">In progress — started <?php echo timeAgo($attempt['started_at']); ?></span>
        <a href="take.php?id=<?php echo (int) $quiz['id']; ?>" class="btn-app"><i class="fas fa-play"></i> Continue Quiz</a>
        <?php elseif ($closed): ?>
        <span class="status-badge missing">Closed — you did not take this quiz</span>
        <?php else: $ro = quizActiveReopen($quiz['id'], $current_user_id); ?>
        <span class="status-badge pending"><?php echo $ro ? 'Reopened by your teacher' . ($ro['until'] ? ' — until ' . formatDate($ro['until'], 'M d, h:i A') : '') : 'Not started'; ?></span>
        <a href="take.php?id=<?php echo (int) $quiz['id']; ?>" class="btn-app"><i class="fas fa-play"></i> Take Quiz</a>
        <?php endif; ?>
    </div>
</div>
<?php elseif (!$released): ?>
<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-hourglass-half"></i> Submitted — result pending</h2>
        <span class="form-hint" style="margin:0;">Submitted <?php echo formatDate($attempt['submitted_at'], 'M d, Y h:i A'); ?></span>
    </div>
    <div class="card-inner"><p class="form-hint" style="margin:0;">Your teacher will release the results soon — you will get a notification and an email.</p></div>
</div>
<?php else: ?>
<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-star"></i> Your Result:
            <?php echo quizMarks($attempt['score']); ?> / <?php echo (int) $attempt['max_score']; ?>
            (<?php echo $attempt['max_score'] ? round($attempt['score'] / $attempt['max_score'] * 100, 1) : 0; ?>%)</h2>
        <span class="form-hint" style="margin:0;">Submitted <?php echo formatDate($attempt['submitted_at'], 'M d, Y h:i A'); ?></span>
    </div>
    <?php if ($attempt['timed_out']): ?>
    <div class="card-inner"><span class="status-badge missing">Your answers arrived after the time limit and were not counted.</span></div>
    <?php endif; ?>
    <?php $my_waiting = 0; foreach ($questions as $wq) { if ($wq['question_type'] === 'short' && isset($my_answers[$wq['id']]) && $my_answers[$wq['id']]['marks_awarded'] === null) $my_waiting++; }
          if ($my_waiting): ?>
    <div class="card-inner"><span class="status-badge pending"><?php echo $my_waiting; ?> short answer<?php echo $my_waiting == 1 ? ' is' : 's are'; ?> waiting to be marked — your result can go up.</span></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (isTeacher()): ?>
<?php $held_n = $quiz['release_mode'] === 'manual' && !$released ? heldResultCount('quiz', $quiz['id']) : 0; ?>
<div class="content-card release-card<?php echo $quiz['release_mode'] === 'manual' && !$released ? ' is-held' : ''; ?>">
    <div class="release-row">
        <div>
            <strong><i class="fas fa-bullhorn"></i> Results</strong>
            <?php if ($quiz['release_mode'] === 'instant'): ?>
            <div class="notif-meta">Students see their score as soon as they submit.</div>
            <?php elseif (!$released): ?>
            <div class="notif-meta"><?php echo $held_n; ?> submitted result<?php echo $held_n == 1 ? ' is' : 's are'; ?> hidden from students until you release them.</div>
            <?php else: ?>
            <div class="notif-meta">Released on <?php echo formatDate($quiz['results_released_at'], 'M d, Y h:i A'); ?> — new scores are visible at once.</div>
            <?php endif; ?>
        </div>
        <div class="release-actions">
            <form method="POST" class="release-mode-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="set_release_mode">
                <select name="release_mode" aria-label="When students see their score" data-autosubmit>
                    <?php foreach (releaseModes() as $k => [$label]): ?><option value="<?php echo $k; ?>" <?php echo $quiz['release_mode'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
                </select>
            </form>
            <?php if ($quiz['release_mode'] === 'manual' && !$released): ?>
            <form method="POST" data-confirm="Release the results?" data-confirm-text="Every student who submitted is notified now (in the app and by email) and can see their score and answers. Quizzes submitted later are visible at once." data-confirm-button="Release results">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="release_results">
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-paper-plane"></i> Release results</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $qstats = quizQuestionStats($quiz['id']); $stat_n = $qstats ? $qstats[0]['answered'] : 0;
      $can_insight = aiAvailableFor('insights', $current_user_id); $saved_ins = savedQuizInsights($quiz['id']); ?>
<?php if ($stat_n > 0): ?>
<div class="content-card" id="insights" data-quiz="<?php echo (int) $quiz['id']; ?>">
    <div class="card-head"><h2><i class="fas fa-lightbulb"></i> How the class did</h2>
        <?php if ($can_insight): ?>
        <button type="button" class="btn-app btn-sm btn-outline" id="insightBtn"><i class="fas fa-robot"></i> <?php echo $saved_ins ? 'Refresh AI summary' : 'AI summary'; ?></button>
        <?php endif; ?>
    </div>
    <div class="card-inner">
        <div class="ai-status form-hint" id="insightStatus" role="status" hidden></div>
        <div class="insight-box" id="insightBox"<?php echo $saved_ins ? '' : ' hidden'; ?>
             data-saved="<?php echo $saved_ins ? htmlspecialchars(json_encode($saved_ins, JSON_UNESCAPED_UNICODE), ENT_QUOTES) : ''; ?>"></div>
        <div class="qstat-list">
            <?php foreach ($qstats as $qs): ?>
            <div class="qstat">
                <div class="qstat-head"><span class="q-badge">Q<?php echo $qs['number']; ?></span>
                    <span class="qstat-text"><?php echo htmlspecialchars(mb_strimwidth($qs['question'], 0, 120, '…')); ?></span>
                    <strong class="qstat-pct <?php echo $qs['pct'] < 50 ? 'low' : ''; ?>"><?php echo (int) $qs['pct']; ?>% correct</strong></div>
                <div class="qstat-bar" role="img" aria-label="<?php echo (int) $qs['pct']; ?> percent answered correctly"><span style="width:<?php echo (int) $qs['pct']; ?>%"></span></div>
                <div class="qstat-opts">
                    <?php foreach ($qs['options'] as $o): ?>
                    <span class="<?php echo $o['correct'] ? 'is-correct' : ''; ?>"><?php echo htmlspecialchars(mb_strimwidth($o['text'], 0, 40, '…')); ?> · <?php echo (int) $o['picked']; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="form-hint" style="margin:10px 0 0;">Based on <?php echo $stat_n; ?> finished attempt<?php echo $stat_n == 1 ? '' : 's'; ?>. The AI summary sees only the questions and these counts — never student names.</p>
    </div>
</div>
<?php endif; ?>

<?php $short_qs = array_values(array_filter($questions, fn($x) => $x['question_type'] === 'short'));
if ($short_qs):
    $pend_n = count(quizPendingShort($quiz['id']));
    $short_rows = $db->fetchAll(
        "SELECT a.*, u.name AS student_name, u.email FROM quiz_answers a
         INNER JOIN quiz_attempts t ON a.attempt_id = t.id AND t.submitted_at IS NOT NULL AND t.timed_out = 0
         INNER JOIN users u ON t.student_id = u.id
         INNER JOIN quiz_questions q ON a.question_id = q.id
         WHERE q.quiz_id = ? AND q.question_type = 'short' ORDER BY q.position, q.id, u.name", [$quiz['id']]);
    $short_by_q = [];
    foreach ($short_rows as $sr) $short_by_q[$sr['question_id']][] = $sr;
    $can_ai_mark = aiAvailableFor('grading', $current_user_id); ?>
<div class="content-card" id="shortmarks">
    <div class="card-head"><h2><i class="fas fa-pen"></i> Short answers
        <?php if ($pend_n): ?><span class="status-badge pending"><?php echo $pend_n; ?> to mark</span><?php endif; ?></h2>
        <?php if ($pend_n && $can_ai_mark): ?>
        <form method="POST" data-confirm="Mark waiting answers with AI?" data-confirm-text="The AI marks each answer against your expected answer and writes a line of feedback. You can change every mark afterwards. Only the question, expected answer and the written answer are sent — never names." data-confirm-button="Mark with AI">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="ai_mark_short">
            <button type="submit" class="btn-app btn-sm"><i class="fas fa-robot"></i> Mark with AI</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-inner">
        <?php foreach ($short_qs as $sq): $qn = array_search($sq['id'], array_column($questions, 'id')) + 1; ?>
        <h3 style="font-size:1rem;margin:0 0 6px;"><span class="q-badge">Q<?php echo $qn; ?></span> <?php echo htmlspecialchars(mb_strimwidth($sq['question'], 0, 140, '…')); ?></h3>
        <?php if (empty($short_by_q[$sq['id']])): ?><p class="form-hint">No answers yet.</p><?php endif; ?>
        <?php foreach ($short_by_q[$sq['id']] ?? [] as $sr): ?>
        <div class="mark-card">
            <strong><?php echo htmlspecialchars($sr['student_name']); ?></strong>
            <?php if ($sr['marks_awarded'] === null): ?><span class="status-badge pending">Not marked</span>
            <?php elseif ($sr['mark_source'] === 'ai'): ?><span class="status-badge submitted"><i class="fas fa-robot"></i> AI-marked — check</span>
            <?php else: ?><span class="status-badge graded">Marked</span><?php endif; ?>
            <div class="mark-answer"><?php echo $sr['answer_text'] !== null ? htmlspecialchars($sr['answer_text']) : '—'; ?></div>
            <form method="POST" class="mark-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="mark_answer"><input type="hidden" name="answer_id" value="<?php echo (int) $sr['id']; ?>">
                <input type="number" name="marks" min="0" max="<?php echo (int) $sq['marks']; ?>" step="0.5" required aria-label="Marks"
                       value="<?php echo $sr['marks_awarded'] !== null ? quizMarks($sr['marks_awarded']) : ''; ?>" placeholder="/ <?php echo (int) $sq['marks']; ?>">
                <textarea name="feedback" rows="1" maxlength="1000" placeholder="Feedback to the student" aria-label="Feedback"><?php echo htmlspecialchars((string) $sr['feedback']); ?></textarea>
                <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-save"></i> Save</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Teacher: every student's attempt -->
<div class="content-card">
    <?php $quiz_missing = count(array_filter($roster, fn($x) => $x['submitted_at'] === null)); ?>
    <div class="card-head"><h2><i class="fas fa-users"></i> Student Results</h2>
        <?php if ($roster): ?>
        <button type="button" class="btn-app btn-sm btn-outline" id="remindBtn" data-missing="<?php echo $quiz_missing; ?>" <?php echo $quiz_missing ? '' : 'disabled'; ?>><i class="fas fa-bell"></i> Remind <?php echo $quiz_missing; ?> student<?php echo $quiz_missing == 1 ? '' : 's'; ?> who did not finish</button>
        <?php endif; ?>
    </div>
    <form method="POST" id="remindForm"><?php echo csrfField(); ?><input type="hidden" name="action" value="remind_missing"><input type="hidden" name="note"></form>
    <?php if (empty($roster)): ?>
    <div class="empty-state" style="border:none;"><i class="fas fa-user-graduate"></i><p>No students are enrolled in this class yet.</p></div>
    <?php else: ?>
    <form method="POST" id="reopenForm" data-no-confirm>
        <?php echo csrfField(); ?><input type="hidden" name="action" value="reopen_quiz"><input type="hidden" name="scope" value="selected">
        <div class="reopen-bar">
            <span class="reopen-pick">
                <button type="button" class="chip" data-pick="all">Select all</button>
                <button type="button" class="chip" data-pick="unfinished">Not finished</button>
                <button type="button" class="chip" data-pick="none">Clear</button>
            </span>
            <span class="reopen-go">
                <label class="reopen-until">New deadline <input type="datetime-local" name="until" value="<?php echo date('Y-m-d\TH:i', strtotime('+3 days')); ?>" min="<?php echo date('Y-m-d\TH:i'); ?>"></label>
                <label class="chk"><input type="checkbox" name="no_deadline" value="1"> No deadline</label>
                <label class="chk" title="Nobody's attempt is cleared — a half-done attempt just stays open longer"><input type="checkbox" name="keep_attempt" value="1"> Only give more time</label>
                <button type="submit" class="btn-app btn-sm" id="reopenBtn" disabled><i class="fas fa-redo"></i> Reactivate <span id="reopenCount">0 selected</span></button>
                <button type="button" class="btn-app btn-sm btn-outline" id="reopenAll" title="Give every student another attempt"><i class="fas fa-users"></i> Everyone</button>
            </span>
        </div>
        <div class="table-wrap">
        <table class="app-table">
            <thead><tr><th style="width:36px;"><input type="checkbox" id="reopenAllBox" aria-label="Select every student"></th><th>Student</th><th>Status</th><th>Score</th><th>Percentage</th><th>Submitted</th></tr></thead>
            <tbody>
                <?php foreach ($roster as $r):
                    $row_closed = quizClosedFor($quiz, $r['id']);
                    $row_reopen = !$r['submitted_at'] && !$r['started_at'] ? quizActiveReopen($quiz['id'], $r['id']) : null; ?>
                <tr>
                    <td><input type="checkbox" name="student_ids[]" value="<?php echo (int) $r['id']; ?>" class="reopen-box" data-finished="<?php echo $r['submitted_at'] ? 1 : 0; ?>" aria-label="Select <?php echo htmlspecialchars($r['name']); ?>"></td>
                    <td><strong><?php echo htmlspecialchars($r['name']); ?></strong><br>
                        <span class="form-hint" style="margin:0;"><?php echo htmlspecialchars($r['email']); ?></span></td>
                    <td>
                        <?php if ($r['submitted_at']): ?>
                        <span class="status-badge <?php echo $r['timed_out'] ? 'missing' : 'graded'; ?>"><?php echo $r['timed_out'] ? 'Over time' : 'Completed'; ?></span>
                        <?php elseif ($r['started_at']): ?>
                        <span class="status-badge late">In progress</span>
                        <?php elseif ($row_reopen): ?>
                        <span class="status-badge pending" title="Reopened<?php echo $row_reopen['until'] ? ' until ' . formatDate($row_reopen['until'], 'M d, h:i A') : ''; ?>">Reopened — not started</span>
                        <?php else: ?>
                        <span class="status-badge <?php echo $row_closed ? 'missing' : 'pending'; ?>"><?php echo $row_closed ? 'Missed' : 'Not started'; ?></span>
                        <?php endif; ?>
                        <?php if ($r['reopens']): ?><span class="status-badge submitted" title="Reactivated <?php echo (int) $r['reopens']; ?> time(s)<?php echo $r['last_prev_score'] !== null ? '; the score before was ' . quizMarks($r['last_prev_score']) . ' / ' . (int) $r['last_prev_max'] : ''; ?>"><i class="fas fa-redo"></i> ×<?php echo (int) $r['reopens']; ?></span><?php endif; ?>
                    </td>
                    <td class="marks"><?php echo $r['submitted_at'] ? quizMarks($r['score']) . ' / ' . (int) $r['max_score'] : '—'; ?></td>
                    <td><?php if ($r['submitted_at']): ?><span class="percentage"><?php echo $r['max_score'] ? round($r['score'] / $r['max_score'] * 100, 1) : 0; ?>%</span><?php else: ?>—<?php endif; ?></td>
                    <td><?php echo $r['submitted_at'] ? formatDate($r['submitted_at'], 'M d, h:i A') : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="form-hint" style="padding:10px 18px;margin:0;">Reactivating clears the student's earlier attempt so they can take the quiz again (the old score stays in the history, shown as <i class="fas fa-redo"></i>). Students who never took it get access again even after the due date.</p>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (isStudent()):
    $quiz_thread = getThreadMessages($class['id'], $current_user_id, 'quiz', $quiz['id']);
    markThreadRead($class['id'], $current_user_id, 'quiz', $quiz['id'], $current_user_id); ?>
<!-- Student: private comments to the teacher about this quiz -->
<div class="content-card" id="private">
    <div class="card-head"><h2><i class="fas fa-user-lock"></i> Private comments</h2>
        <span class="form-hint" style="margin:0;">Only you and <?php echo htmlspecialchars($class['teacher_name']); ?> can see these</span></div>
    <div class="card-inner pc-thread">
        <?php if (!$quiz_thread): ?><p class="form-hint" style="margin:0;">Had a problem with this quiz? Tell your teacher privately.</p><?php endif; ?>
        <?php foreach ($quiz_thread as $m): ?>
        <div class="comment">
            <span class="avatar<?php echo $m['role'] === 'teacher' ? ' teacher' : ''; ?>" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($m['author'], 0, 1))); ?></span>
            <div class="comment-body"><strong><?php echo htmlspecialchars($m['author']); ?></strong> <span class="notif-meta"><?php echo timeAgo($m['created_at']); ?></span>
                <div class="prewrap"><?php echo htmlspecialchars($m['body']); ?></div></div>
        </div>
        <?php endforeach; ?>
        <form method="POST" class="comment-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="private_comment">
            <div class="form-group" style="flex:1;margin:0;">
                <textarea name="body" rows="1" maxlength="2000" placeholder="Add private comment…" aria-label="Add private comment"
                    class="<?php echo $pc_error ? 'is-invalid' : ''; ?>"></textarea>
                <div class="invalid-feedback"><?php echo htmlspecialchars($pc_error); ?></div>
            </div>
            <button type="submit" class="icon-btn" title="Send" aria-label="Send private comment"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
</div>
<?php else:
    $quiz_convs = (int) $db->fetchOne(
        "SELECT COUNT(DISTINCT student_id) AS c FROM private_messages WHERE class_id = ? AND context_type = 'quiz' AND context_id = ?",
        [$class['id'], $quiz['id']])['c']; ?>
<?php if ($quiz_convs): ?>
<p class="form-hint" style="margin:-8px 0 16px;"><i class="fas fa-user-lock"></i>
    <?php echo $quiz_convs; ?> student<?php echo $quiz_convs == 1 ? '' : 's'; ?> sent private comments about this quiz —
    <a href="<?php echo APP_URL; ?>/pages/classes/messages.php?id=<?php echo (int) $class['id']; ?>">open Messages</a>.</p>
<?php endif; ?>
<?php endif; ?>

<?php if (isTeacher() || ($attempt && $attempt['submitted_at'] !== null && $released)): ?>
<!-- Answer key (teacher) / answer review (student, after submitting only) -->
<div class="content-card">
    <?php $wrong_n = 0; if (isStudent()) foreach ($questions as $wq) { if ($wq['question_type'] === 'choice' && empty($my_answers[$wq['id']]['is_correct'])) $wrong_n++; }
          $can_explain = isStudent() && $wrong_n > 0 && !$attempt['timed_out'] && aiAvailableFor('explain', $current_user_id, $class['id']); ?>
    <div class="card-head"><h2><i class="fas fa-list-ol"></i> <?php echo isTeacher() ? 'Questions & Answer Key' : 'Review Your Answers'; ?></h2>
        <?php if ($can_explain): ?>
        <button type="button" class="btn-app btn-sm" id="explainMistakes" data-quiz="<?php echo (int) $quiz['id']; ?>"><i class="fas fa-robot"></i> Explain my mistakes</button>
        <?php endif; ?>
    </div>
    <div class="card-inner">
        <?php if ($can_explain): ?><div class="ai-status form-hint" id="explainStatus" role="status" hidden></div><?php endif; ?>
        <?php foreach ($questions as $i => $q):
            $mine = $my_answers[$q['id']] ?? null; ?>
        <div class="question-block review" data-qid="<?php echo (int) $q['id']; ?>">
            <div class="question-head">
                <span class="q-badge">Q<?php echo $i + 1; ?></span>
                <span class="form-hint" style="margin:0;"><?php echo (int) $q['marks']; ?> mark<?php echo $q['marks'] == 1 ? '' : 's'; ?></span>
                <?php if (isStudent() && $q['question_type'] === 'short'): ?>
                <span class="status-badge <?php echo !$mine || $mine['marks_awarded'] === null ? 'pending' : ($mine['is_correct'] ? 'graded' : 'late'); ?>">
                    <?php echo !$mine || $mine['marks_awarded'] === null ? 'Waiting to be marked' : quizMarks($mine['marks_awarded']) . ' / ' . (int) $q['marks']; ?>
                </span>
                <?php elseif (isStudent()): ?>
                <span class="status-badge <?php echo $mine && $mine['is_correct'] ? 'graded' : 'missing'; ?>">
                    <?php echo $mine && $mine['is_correct'] ? 'Correct' : (($mine && $mine['option_id']) ? 'Wrong' : 'Not answered'); ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="prewrap question-text"><?php echo htmlspecialchars($q['question']); ?></div>
            <?php if ($q['question_type'] === 'short'): ?>
            <?php if (isStudent()): ?>
            <div class="mark-answer"><strong>Your answer:</strong> <?php echo $mine && $mine['answer_text'] !== null ? htmlspecialchars($mine['answer_text']) : '—'; ?></div>
            <?php if ($mine && $mine['feedback']): ?><div class="form-hint" style="margin:0 0 6px;"><i class="fas fa-comment-dots"></i> <?php echo htmlspecialchars($mine['feedback']); ?><?php echo $mine['mark_source'] === 'ai' ? ' <em>(marked with AI help)</em>' : ''; ?></div><?php endif; ?>
            <?php endif; ?>
            <div class="answer-option is-correct"><i class="fas fa-check-circle"></i> <span class="prewrap"><?php echo htmlspecialchars((string) $q['model_answer']); ?></span><em class="form-hint" style="margin:0 0 0 auto;">expected answer</em></div>
            <?php else: ?>
            <div class="answer-list">
                <?php foreach ($options_by_q[$q['id']] ?? [] as $o):
                    $chosen = $mine && (int) $mine['option_id'] === (int) $o['id'];
                    $cls = $o['is_correct'] ? 'is-correct' : ($chosen ? 'is-wrong' : ''); ?>
                <div class="answer-option <?php echo $cls; ?>">
                    <i class="fas <?php echo $o['is_correct'] ? 'fa-check-circle' : ($chosen ? 'fa-times-circle' : 'fa-circle'); ?>"></i>
                    <span><?php echo htmlspecialchars($o['option_text']); ?></span>
                    <?php if ($chosen): ?><em class="form-hint" style="margin:0 0 0 auto;">your answer</em><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($can_explain): ?><div class="mistake-explain" hidden></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ===================== List ===================== -->
<div class="page-toolbar">
    <?php if (!empty($classes)): ?>
    <form method="GET" class="filter-form">
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int) $c['id']; ?>" <?php echo ($class && (int) $class['id'] === (int) $c['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c['class_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
    </form>
    <?php else: ?><span></span><?php endif; ?>

    <div class="action-buttons">
        <?php if (!empty($classes)): ?>
        <a href="results.php<?php echo $class ? '?class_id=' . (int) $class['id'] : ''; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-chart-bar"></i> Results</a>
        <?php endif; ?>
        <?php if (isTeacher() && !empty($classes)): ?>
        <a href="create.php<?php echo $class ? '?class_id=' . (int) $class['id'] : ''; ?>" class="btn-app btn-sm"><i class="fas fa-plus"></i> Create Quiz</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($quizzes)): ?>
<div class="empty-state">
    <i class="fas fa-question-circle"></i>
    <h3>No quizzes yet</h3>
    <p style="margin-top:6px;">
        <?php if (empty($classes)): ?>
            <?php echo isTeacher() ? 'Create a class first, then give it quizzes.' : 'Join a class to see its quizzes.'; ?>
        <?php else: ?>
            <?php echo isTeacher() ? 'Create the first quiz for your students.' : 'Your teachers have not published any quizzes yet.'; ?>
        <?php endif; ?>
    </p>
</div>
<?php else: ?>
<div class="lecture-list">
    <?php foreach ($quizzes as $q):
        $q_closed = !empty($q['due_date']) && time() > strtotime($q['due_date']); ?>
    <div class="lecture-card">
        <div class="lecture-info">
            <div class="lecture-title"><?php echo htmlspecialchars($q['title']); ?></div>
            <div class="lecture-meta">
                <?php if (!$class): ?><span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($q['class_name']); ?></span><?php endif; ?>
                <span><i class="fas fa-list-ol"></i> <?php echo (int) $q['question_count']; ?> questions · <?php echo (int) $q['total_marks']; ?> marks</span>
                <?php if ($q['time_limit']): ?><span><i class="fas fa-stopwatch"></i> <?php echo (int) $q['time_limit']; ?> min</span><?php endif; ?>
                <span><i class="fas fa-clock"></i> <?php echo $q['due_date'] ? 'Due ' . formatDate($q['due_date'], 'M d, h:i A') : 'No due date'; ?></span>
                <?php if (isTeacher()): ?>
                <span><i class="fas fa-check"></i> <?php echo (int) $q['done_count']; ?>/<?php echo (int) $q['student_count']; ?> completed</span>
                <span><i class="fas fa-percent"></i> avg <?php echo $q['avg_pct'] === null ? '—' : round($q['avg_pct'], 1) . '%'; ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="lecture-actions">
            <?php if (isStudent()): ?>
            <?php if ($q['submitted_at'] && !resultsReleased($q)): ?>
            <span class="status-badge submitted" style="align-self:center;">Result pending</span>
            <?php elseif ($q['submitted_at']): ?>
            <span class="status-badge <?php echo $q['timed_out'] ? 'missing' : 'graded'; ?>" style="align-self:center;">
                <?php echo $q['timed_out'] ? 'Over time · ' : ''; ?><?php echo quizMarks($q['score']); ?>/<?php echo (int) $q['max_score']; ?>
            </span>
            <?php elseif ($q['attempt_id']): ?>
            <span class="status-badge late" style="align-self:center;">In progress</span>
            <?php else: ?>
            <span class="status-badge <?php echo $q_closed ? 'missing' : 'pending'; ?>" style="align-self:center;"><?php echo $q_closed ? 'Missed' : 'Not started'; ?></span>
            <?php endif; ?>
            <?php endif; ?>
            <a href="view.php?id=<?php echo (int) $q['id']; ?>" class="btn-app btn-sm">Open</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
