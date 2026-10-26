<?php
/**
 * Take Quiz (student only) — ?id=<quiz id>
 *   GET  (no attempt)      start screen
 *   POST action=start      creates the attempt; the server records started_at
 *   GET  (attempt open)    questions + countdown (auto-submits at 0)
 *   POST action=submit     grades against the stored correct options
 * One attempt per student. With a time limit, answers sent later than
 * started_at + limit + 60s grace are not counted (timed_out = 1, score 0).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isStudent()) {
    redirect(APP_URL . '/pages/quizzes/view.php');
}

define('QUIZ_GRACE_SECONDS', 60);

$quiz = $db->fetchOne("SELECT * FROM quizzes WHERE id = ?", [(int) ($_GET['id'] ?? 0)]);
// Membership check: the student must be enrolled in the quiz's class
$class = $quiz ? getAccessibleClass($quiz['class_id'], $current_user_id, 'student') : null;
if (!$class) {
    redirect(APP_URL . '/pages/quizzes/view.php?error=not_found');
}

$view_url = APP_URL . '/pages/quizzes/view.php?id=' . $quiz['id'];
$attempt = $db->fetchOne("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?", [$quiz['id'], $current_user_id]);

if ($attempt && $attempt['submitted_at'] !== null) {
    redirect($view_url);   // already done: the view page shows the result
}

$closed = quizClosedFor($quiz, $current_user_id);   // the due date passed and the teacher has not reopened it for this student
$questions = $db->fetchAll("SELECT id, question, question_type, marks FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id", [$quiz['id']]);
$max_score = array_sum(array_column($questions, 'marks'));

/** Seconds left on an open attempt (null = no limit). */
function secondsLeft($quiz, $attempt) {
    if (empty($quiz['time_limit'])) return null;
    return strtotime($attempt['started_at']) + (int) $quiz['time_limit'] * 60 - time();
}

$task_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCsrf()) {
        $task_error = 'Your session expired. Please reload the page and try again.';
    } elseif ($action === 'start') {
        if ($attempt) {
            redirect(APP_URL . '/pages/quizzes/take.php?id=' . $quiz['id']);
        }
        if ($closed) {
            $task_error = 'This quiz is closed. The due date has passed.';
        } elseif (!$questions) {
            $task_error = 'This quiz has no questions.';
        } else {
            try {
                $db->insert('quiz_attempts', [
                    'quiz_id'    => $quiz['id'],
                    'student_id' => $current_user_id,
                    'started_at' => date('Y-m-d H:i:s'),
                    'max_score'  => $max_score,
                ]);
                logActivity('start_quiz', 'quizzes', "Started quiz #{$quiz['id']} \"{$quiz['title']}\"");
            } catch (PDOException $e) {
                // Unique (quiz_id, student_id): a double click already created it — just continue
            }
            redirect(APP_URL . '/pages/quizzes/take.php?id=' . $quiz['id']);
        }
    } elseif ($action === 'submit') {
        if (!$attempt) {
            $task_error = 'Start the quiz first.';
        } else {
            $left = secondsLeft($quiz, $attempt);
            $timed_out = $left !== null && $left < -QUIZ_GRACE_SECONDS;

            // Correct option per question, and which option ids belong to which question
            $valid = [];
            $correct = [];
            foreach ($db->fetchAll(
                "SELECT o.id, o.question_id, o.is_correct FROM quiz_options o
                 INNER JOIN quiz_questions q ON o.question_id = q.id
                 WHERE q.quiz_id = ?", [$quiz['id']]) as $o) {
                $valid[$o['question_id']][(int) $o['id']] = true;
                if ($o['is_correct']) $correct[$o['question_id']] = (int) $o['id'];
            }

            $posted = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
            $rows = [];
            $score = 0;
            foreach ($questions as $q) {
                $raw = $posted[$q['id']] ?? null;
                if ($q['question_type'] === 'short') {
                    // Typed answer: stored as written; marks come later (teacher / AI) — an empty answer is 0 straight away
                    $typed = is_string($raw) ? trim(str_replace("\r\n", "\n", $raw)) : '';
                    $typed = mb_substr($typed, 0, QUIZ_SHORT_MAX_CHARS);
                    $blank = $timed_out || $typed === '';
                    $rows[] = ['question_id' => $q['id'], 'option_id' => null, 'is_correct' => 0,
                               'answer_text' => $blank ? null : $typed,
                               'marks_awarded' => $blank ? 0 : null,
                               'mark_source' => $blank ? 'auto' : null,
                               'feedback' => $typed === '' && !$timed_out ? 'No answer given.' : null];
                    continue;
                }
                $option_id = (is_string($raw) && preg_match('/^\d+$/', $raw) && isset($valid[$q['id']][(int) $raw])) ? (int) $raw : null;
                $is_correct = !$timed_out && $option_id !== null && $option_id === ($correct[$q['id']] ?? -1);
                if ($is_correct) $score += (int) $q['marks'];
                $rows[] = ['question_id' => $q['id'], 'option_id' => $timed_out ? null : $option_id, 'is_correct' => $is_correct ? 1 : 0];
            }

            // Close the attempt first; the WHERE guard makes a double submit a no-op
            $closed_now = $db->update('quiz_attempts', [
                'submitted_at' => date('Y-m-d H:i:s'),
                'score'        => $score,
                'max_score'    => $max_score,
                'timed_out'    => $timed_out ? 1 : 0,
            ], 'id = ? AND submitted_at IS NULL', [$attempt['id']]);

            $waiting = count(array_filter($rows, fn($r) => array_key_exists('marks_awarded', $r) && $r['marks_awarded'] === null));
            if ($closed_now > 0) {
                foreach ($rows as $r) {
                    $db->insert('quiz_answers', $r + ['attempt_id' => $attempt['id']]);
                }
                logActivity('submit_quiz', 'quizzes', "Submitted quiz #{$quiz['id']} \"{$quiz['title']}\": {$score}/{$max_score}" . ($timed_out ? ' (time limit exceeded)' : ''));
                notifyClassTeachers(
                    $class['id'],
                    'quiz_submitted',
                    "Quiz completed: {$quiz['title']}",
                    "{$current_user_name} scored {$score}/{$max_score}" . ($timed_out ? ' (time limit exceeded, answers not counted)' : '')
                        . ($waiting ? " so far — {$waiting} short answer" . ($waiting == 1 ? '' : 's') . ' to mark' : '') . '.',
                    'pages/quizzes/view.php?id=' . $quiz['id'],
                    $current_user_id
                );
                Session::flash('quiz_done', ['score' => $score, 'max' => $max_score, 'timed_out' => $timed_out, 'waiting' => $waiting]);
            }
            redirect($view_url);
        }
    } else {
        $task_error = 'Unknown action.';
    }
}

$page_title = $quiz['title'];
$page_subtitle = $class['class_name'] . ' · Quiz';
$active_menu = 'quizzes';

$left = $attempt ? secondsLeft($quiz, $attempt) : null;
$options_by_q = [];
if ($attempt) {
    foreach ($db->fetchAll(
        "SELECT o.id, o.question_id, o.option_text FROM quiz_options o
         INNER JOIN quiz_questions q ON o.question_id = q.id
         WHERE q.quiz_id = ? ORDER BY o.question_id, o.position, o.id", [$quiz['id']]) as $o) {
        // never send is_correct to the browser
        $options_by_q[$o['question_id']][] = $o;
    }
}

$page_script = ($task_error ? "
    Swal.fire({ icon: 'error', title: 'Not possible', text: " . json_encode($task_error) . ", confirmButtonColor: '#4361ee' });" : "") . "
    const qform = document.getElementById('takeForm');
    if (qform) {
        let autoSubmit = false;
        const total = " . count($questions) . ";
        qform.addEventListener('submit', function(e) {
            if (autoSubmit) return;
            const answered = new Set(Array.from(qform.querySelectorAll('input[type=radio]:checked')).map(function(r) { return r.name; })).size
                + Array.from(qform.querySelectorAll('textarea.short-answer-box')).filter(function(t) { return t.value.trim() !== ''; }).length;
            e.preventDefault();
            Swal.fire({
                icon: answered < total ? 'warning' : 'question',
                title: 'Submit your answers?',
                text: answered < total
                    ? 'You left ' + (total - answered) + ' of ' + total + ' questions unanswered. You cannot change answers after submitting.'
                    : 'You cannot change answers after submitting.',
                showCancelButton: true,
                confirmButtonText: 'Yes, submit',
                confirmButtonColor: '#4361ee'
            }).then(function(r) { if (r.isConfirmed) { autoSubmit = true; qform.submit(); } });
        });

        const timer = document.getElementById('quizTimer');
        if (timer) {
            let left = parseInt(timer.dataset.left, 10);
            const label = timer.querySelector('span');
            function tick() {
                if (left <= 0) {
                    label.textContent = '0:00';
                    if (!autoSubmit) { autoSubmit = true; qform.submit(); }
                    return;
                }
                const m = Math.floor(left / 60), s = left % 60;
                label.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                timer.classList.toggle('urgent', left <= 60);
                left--;
                setTimeout(tick, 1000);
            }
            tick();
        }
    }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<a class="back-link" href="<?php ob_start(); ?><?php echo $view_url; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Back to quiz</a>

<?php if (!$attempt): ?>
<!-- ===== Start screen ===== -->
<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-question-circle"></i> <?php echo htmlspecialchars($quiz['title']); ?></h2></div>
    <div class="card-inner">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Questions</div><div class="value"><?php echo count($questions); ?></div></div>
            <div class="detail-item"><div class="label">Total Marks</div><div class="value"><?php echo (int) $max_score; ?></div></div>
            <div class="detail-item"><div class="label">Time Limit</div><div class="value"><?php echo $quiz['time_limit'] ? (int) $quiz['time_limit'] . ' min' : 'None'; ?></div></div>
            <div class="detail-item"><div class="label">Due</div><div class="value"><?php echo $quiz['due_date'] ? formatDate($quiz['due_date'], 'M d, h:i A') : 'Open'; ?></div></div>
        </div>
        <?php if (!empty($quiz['description'])): ?>
        <div class="prewrap" style="margin-bottom:16px;"><?php echo htmlspecialchars($quiz['description']); ?></div>
        <?php endif; ?>
        <p class="form-hint" style="margin-bottom:16px;">
            You get one attempt.<?php echo $quiz['time_limit'] ? ' The timer starts when you press Start and the quiz submits itself when time runs out.' : ''; ?>
        </p>
        <?php if ($closed): ?>
        <span class="status-badge missing">Closed — the due date has passed</span>
        <?php elseif (!$questions): ?>
        <span class="status-badge pending">This quiz has no questions yet</span>
        <?php else: ?>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="start">
            <button type="submit" class="btn-app"><i class="fas fa-play"></i> Start Quiz</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ===== Questions ===== -->
<?php if ($left !== null): ?>
<div class="quiz-timer" id="quizTimer" data-left="<?php echo max(0, (int) $left); ?>">
    <i class="fas fa-stopwatch"></i> Time left: <span>--:--</span>
</div>
<?php endif; ?>

<form method="POST" id="takeForm" class="quiz-take">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="submit">
    <?php foreach ($questions as $i => $q): ?>
    <fieldset class="question-block">
        <legend class="question-head">
            <span class="q-badge">Q<?php echo $i + 1; ?></span>
            <span class="form-hint" style="margin:0;"><?php echo (int) $q['marks']; ?> mark<?php echo $q['marks'] == 1 ? '' : 's'; ?></span>
        </legend>
        <div class="prewrap question-text"><?php echo htmlspecialchars($q['question']); ?></div>
        <?php if ($q['question_type'] === 'short'): ?>
        <textarea class="short-answer-box" name="answers[<?php echo (int) $q['id']; ?>]" rows="4" maxlength="<?php echo QUIZ_SHORT_MAX_CHARS; ?>"
                  placeholder="Write your answer here" aria-label="Your answer to question <?php echo $i + 1; ?>"></textarea>
        <?php else: ?>
        <div class="answer-list">
            <?php foreach ($options_by_q[$q['id']] ?? [] as $o): ?>
            <label class="answer-option">
                <input type="radio" name="answers[<?php echo (int) $q['id']; ?>]" value="<?php echo (int) $o['id']; ?>">
                <span><?php echo htmlspecialchars($o['option_text']); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </fieldset>
    <?php endforeach; ?>
    <div class="form-actions" style="margin:10px 0 30px;">
        <button type="submit" class="btn-app"><i class="fas fa-paper-plane"></i> Submit Answers</button>
    </div>
</form>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
