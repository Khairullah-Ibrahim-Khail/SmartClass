<?php
/**
 * Create Quiz (teacher only)
 * Multiple-choice quiz for one of the teacher's classes: each question has
 * 2–6 options with exactly one correct answer and its own marks.
 * Optional time limit (minutes) and due date. Auto-graded when a student submits.
 * "Generate with AI" (when the teacher has AI): questions drafted from the class's lectures and / or a
 * topic (pages/quizzes/generate.php) are added to the builder as normal questions to review and edit;
 * nothing is saved until the teacher publishes.
 * Inline field errors (per question too); SweetAlert2 for the task result.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/quizzes/view.php');
}

define('QUIZ_MAX_QUESTIONS', 100);
define('QUIZ_MIN_OPTIONS', 2);
define('QUIZ_MAX_OPTIONS', 6);

$classes = getUserClasses($current_user_id, 'teacher');

$can_ai = aiAvailableFor('quiz_generate', $current_user_id);
$lecture_options = [];   // class id => [[id, title], ...] — sources for the AI panel
if ($can_ai) {
    foreach ($db->fetchAll(
        "SELECT l.id, l.class_id, l.title FROM lectures l INNER JOIN classes c ON l.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 ORDER BY l.created_at, l.id", [$current_user_id]) as $l) {
        $lecture_options[(int) $l['class_id']][] = [(int) $l['id'], $l['title']];
    }
}
$field_errors = ['class_id' => '', 'title' => '', 'description' => '', 'time_limit' => '', 'due_date' => '', 'questions' => ''];
$q_errors = [];   // question index => ['text' =>, 'marks' =>, 'options' =>, 'correct' =>]
$csrf_error = false;
$created = null;

// ---------- Normalise posted questions (array keys from the browser are untrusted) ----------
$questions = [];
foreach (array_values(is_array($_POST['questions'] ?? null) ? $_POST['questions'] : []) as $q) {
    if (!is_array($q)) continue;
    $options = [];
    foreach (array_values(is_array($q['options'] ?? null) ? $q['options'] : []) as $o) {
        $options[] = is_string($o) ? trim($o) : '';
    }
    $questions[] = [
        'type'    => ($q['type'] ?? '') === 'short' ? 'short' : 'choice',
        'model'   => is_string($q['model_answer'] ?? null) ? trim($q['model_answer']) : '',
        'text'    => is_string($q['text'] ?? null) ? trim($q['text']) : '',
        'marks'   => is_string($q['marks'] ?? null) ? trim($q['marks']) : '1',
        'options' => $options,
        'correct' => isset($q['correct']) && is_string($q['correct']) && preg_match('/^\d+$/', $q['correct']) ? (int) $q['correct'] : null,
    ];
}
if (!$questions) {
    // Start the builder with one question and four empty options
    $questions[] = ['type' => 'choice', 'model' => '', 'text' => '', 'marks' => '1', 'options' => ['', '', '', ''], 'correct' => null];
}

$form = [
    'class_id'    => (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0),
    'title'       => trim($_POST['title'] ?? ''),
    'description' => trim($_POST['description'] ?? ''),
    'time_limit'  => trim($_POST['time_limit'] ?? ''),
    'due_date'    => trim($_POST['due_date'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $csrf_error = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Server-side ownership check: the class must belong to this teacher
    $class = getAccessibleClass($form['class_id'], $current_user_id, 'teacher');
    if (!$class) {
        $field_errors['class_id'] = 'Please select one of your classes';
    }

    if ($form['title'] === '') {
        $field_errors['title'] = 'Quiz title is required';
    } elseif (mb_strlen($form['title']) > 200) {
        $field_errors['title'] = 'Quiz title must be under 200 characters';
    }

    if (mb_strlen($form['description']) > 5000) {
        $field_errors['description'] = 'Instructions must be under 5,000 characters';
    }

    if ($form['time_limit'] !== '' && (!preg_match('/^\d+$/', $form['time_limit']) || (int) $form['time_limit'] < 1 || (int) $form['time_limit'] > 300)) {
        $field_errors['time_limit'] = 'Time limit must be 1 to 300 minutes (or leave it empty)';
    }

    $due = null;
    if ($form['due_date'] !== '') {
        $due = DateTime::createFromFormat('Y-m-d\TH:i', $form['due_date']);
        if (!$due || $due->format('Y-m-d\TH:i') !== $form['due_date']) {
            $field_errors['due_date'] = 'Please enter a valid date and time';
        } elseif ($due->getTimestamp() <= time()) {
            $field_errors['due_date'] = 'Due date must be in the future';
        }
    }

    if (count($questions) > QUIZ_MAX_QUESTIONS) {
        $field_errors['questions'] = 'A quiz can have at most ' . QUIZ_MAX_QUESTIONS . ' questions';
    }

    foreach ($questions as $i => $q) {
        $e = [];
        if ($q['text'] === '') {
            $e['text'] = 'Question text is required';
        } elseif (mb_strlen($q['text']) > 2000) {
            $e['text'] = 'Question must be under 2,000 characters';
        }
        if (!preg_match('/^\d+$/', $q['marks']) || (int) $q['marks'] < 1 || (int) $q['marks'] > 100) {
            $e['marks'] = 'Marks must be 1 to 100';
        }
        if ($q['type'] === 'short') {
            if ($q['model'] === '') $e['model'] = 'Write the expected answer — the marker compares against it';
            elseif (mb_strlen($q['model']) > 2000) $e['model'] = 'Expected answer must be under 2,000 characters';
            if ($e) $q_errors[$i] = $e;
            continue;
        }
        $n = count($q['options']);
        if ($n < QUIZ_MIN_OPTIONS || $n > QUIZ_MAX_OPTIONS) {
            $e['options'] = 'Give ' . QUIZ_MIN_OPTIONS . ' to ' . QUIZ_MAX_OPTIONS . ' options';
        } elseif (in_array('', $q['options'], true)) {
            $e['options'] = 'Fill in every option or remove the empty ones';
        } elseif (max(array_map('mb_strlen', $q['options'])) > 500) {
            $e['options'] = 'Each option must be under 500 characters';
        } elseif (count(array_unique(array_map('mb_strtolower', $q['options']))) !== $n) {
            $e['options'] = 'Two options are the same';
        }
        if ($q['correct'] === null || $q['correct'] >= $n) {
            $e['correct'] = 'Mark the correct answer';
        }
        if ($e) $q_errors[$i] = $e;
    }

    if (!array_filter($field_errors) && !$q_errors) {
        $quiz_id = $db->insert('quizzes', [
            'topic_id'    => validTopicId($_POST['topic_id'] ?? 0, $class['id']),
            'class_id'    => $class['id'],
            'teacher_id'  => $current_user_id,
            'title'       => $form['title'],
            'description' => $form['description'] !== '' ? $form['description'] : null,
            'time_limit'  => $form['time_limit'] !== '' ? (int) $form['time_limit'] : null,
            'due_date'    => $due ? $due->format('Y-m-d H:i:00') : null,
            'release_mode' => postedReleaseMode(),
        ]);
        try {
            foreach ($questions as $pos => $q) {
                $question_id = $db->insert('quiz_questions', [
                    'quiz_id'  => $quiz_id,
                    'question' => $q['text'],
                    'question_type' => $q['type'],
                    'model_answer'  => $q['type'] === 'short' ? $q['model'] : null,
                    'marks'    => (int) $q['marks'],
                    'position' => $pos,
                ]);
                foreach ($q['type'] === 'short' ? [] : $q['options'] as $opos => $text) {
                    $db->insert('quiz_options', [
                        'question_id' => $question_id,
                        'option_text' => $text,
                        'is_correct'  => $opos === $q['correct'] ? 1 : 0,
                        'position'    => $opos,
                    ]);
                }
            }
        } catch (Exception $e) {
            // Never leave a half-saved quiz behind (questions/options cascade)
            $db->delete('quizzes', 'id = ?', [$quiz_id]);
            throw $e;
        }

        $total = array_sum(array_map(fn($q) => (int) $q['marks'], $questions));
        logActivity('create_quiz', 'quizzes', "Created quiz #{$quiz_id} \"{$form['title']}\" (" . count($questions) . " questions, {$total} marks) in {$class['class_name']}");
        notifyClassStudents(
            $class['id'],
            'quiz_created',
            'New quiz: ' . $form['title'],
            count($questions) . " questions in {$class['class_name']}" . ($due ? ', due ' . $due->format('M d, Y h:i A') : '') . '.',
            'pages/quizzes/view.php?id=' . $quiz_id,
            $current_user_id
        );

        if (!empty($_POST['save_to_bank'])) qbSaveQuestions($current_user_id, $questions, (string) ($_POST['bank_tag'] ?? ''));   // keep them for later quizzes

        $created = ['id' => $quiz_id, 'class_id' => $class['id']];
    }
}

$page_title = 'Create Quiz';
$page_scripts = ['assets/js/quiz-bank.js'];
$page_subtitle = 'Multiple-choice questions are graded automatically; short answers are marked by you or your AI';
$active_menu = 'quizzes';

if ($created) {
    $page_script = "
    Swal.fire({
        icon: 'success',
        title: 'Quiz Published!',
        text: 'Students in this class have been notified.',
        confirmButtonText: 'Open Quiz',
        confirmButtonColor: '#4361ee',
        showCancelButton: true,
        cancelButtonText: 'Create Another'
    }).then(function(r) {
        window.location.href = r.isConfirmed
            ? '" . APP_URL . "/pages/quizzes/view.php?id={$created['id']}'
            : '" . APP_URL . "/pages/quizzes/create.php?class_id={$created['class_id']}';
    });";
} else {
    $page_script = ($csrf_error ? "
    Swal.fire({ icon: 'error', title: 'Session expired',
                text: 'Please submit the form again.', confirmButtonColor: '#4361ee' });" : "") . "
    const form = document.getElementById('quizForm');
    if (form) {
        const list = document.getElementById('questionList');
        const MIN_OPT = " . QUIZ_MIN_OPTIONS . ", MAX_OPT = " . QUIZ_MAX_OPTIONS . ", MAX_Q = " . QUIZ_MAX_QUESTIONS . ";
        bindLiveClear(form);

        // Renumber every name/value so the server receives questions[i][options][j] and correct=j
        function renumber() {
            list.querySelectorAll('.question-block').forEach(function(block, qi) {
                block.querySelector('.q-number').textContent = qi + 1;
                block.querySelector('.q-text').name = 'questions[' + qi + '][text]';
                block.querySelector('.q-marks').name = 'questions[' + qi + '][marks]';
                block.querySelector('.q-type').name = 'questions[' + qi + '][type]';
                block.querySelector('.q-model').name = 'questions[' + qi + '][model_answer]';
                block.dataset.type = block.querySelector('.q-type').value;
                block.querySelectorAll('.option-row').forEach(function(row, oi) {
                    row.querySelector('.o-text').name = 'questions[' + qi + '][options][' + oi + ']';
                    row.querySelector('.o-text').placeholder = 'Option ' + (oi + 1);
                    const radio = row.querySelector('.o-correct');
                    radio.name = 'questions[' + qi + '][correct]';
                    radio.value = oi;
                });
                const nOpt = block.querySelectorAll('.option-row').length;
                block.querySelector('.add-option').disabled = nOpt >= MAX_OPT;
                block.querySelectorAll('.remove-option').forEach(function(b) { b.disabled = nOpt <= MIN_OPT; });
                block.querySelector('.remove-question').disabled = list.children.length <= 1;
            });
            document.getElementById('addQuestion').disabled = list.children.length >= MAX_Q;
            let total = 0;
            list.querySelectorAll('.q-marks').forEach(function(m) { total += parseInt(m.value, 10) || 0; });
            document.getElementById('totalMarks').textContent = total;
            document.getElementById('questionCount').textContent = list.children.length;
        }

        function optionRow() {
            const tpl = document.getElementById('optionTemplate').content.cloneNode(true);
            return tpl.firstElementChild;
        }

        list.addEventListener('click', function(e) {
            const block = e.target.closest('.question-block');
            if (e.target.closest('.add-option')) {
                block.querySelector('.option-list').appendChild(optionRow());
            } else if (e.target.closest('.remove-option')) {
                e.target.closest('.option-row').remove();
            } else if (e.target.closest('.remove-question')) {
                block.remove();
            } else { return; }
            renumber();
        });
        list.addEventListener('input', function(e) { if (e.target.classList.contains('q-marks')) renumber(); });
        list.addEventListener('change', function(e) { if (e.target.classList.contains('q-type')) renumber(); });
        list.addEventListener('change', function(e) {
            if (e.target.classList.contains('o-correct')) {
                const fb = e.target.closest('.question-block').querySelector('.correct-feedback');
                fb.classList.remove('show');
            }
        });

        document.getElementById('addQuestion').addEventListener('click', function() {
            const block = document.getElementById('questionTemplate').content.cloneNode(true).firstElementChild;
            const opts = block.querySelector('.option-list');
            for (let i = 0; i < 4; i++) opts.appendChild(optionRow());
            list.appendChild(block);
            bindLiveClear(block);
            renumber();
            block.querySelector('.q-text').focus();
        });

        form.addEventListener('submit', function(e) {
            renumber();
            let ok = true;
            const cls = document.getElementById('class_id');
            const title = document.getElementById('title');
            const tl = document.getElementById('time_limit');
            const due = document.getElementById('due_date');
            if (!cls.value) { setFieldError(cls, 'Please select one of your classes'); ok = false; }
            if (!title.value.trim()) { setFieldError(title, 'Quiz title is required'); ok = false; }
            if (tl.value && (!/^\\d+$/.test(tl.value) || +tl.value < 1 || +tl.value > 300)) {
                setFieldError(tl, 'Time limit must be 1 to 300 minutes (or leave it empty)'); ok = false;
            }
            if (due.value && new Date(due.value) <= new Date()) { setFieldError(due, 'Due date must be in the future'); ok = false; }

            list.querySelectorAll('.question-block').forEach(function(block) {
                const text = block.querySelector('.q-text');
                const marks = block.querySelector('.q-marks');
                if (!text.value.trim()) { setFieldError(text, 'Question text is required'); ok = false; }
                if (!/^\\d+$/.test(marks.value) || +marks.value < 1 || +marks.value > 100) { setFieldError(marks, 'Marks must be 1 to 100'); ok = false; }
                if (block.dataset.type === 'short') {
                    const model = block.querySelector('.q-model');
                    if (!model.value.trim()) { setFieldError(model, 'Write the expected answer — the marker compares against it'); ok = false; }
                    return;
                }
                const optFb = block.querySelector('.options-feedback');
                const values = Array.from(block.querySelectorAll('.o-text')).map(function(o) { return o.value.trim(); });
                let optMsg = '';
                if (values.indexOf('') !== -1) optMsg = 'Fill in every option or remove the empty ones';
                else if (new Set(values.map(function(v) { return v.toLowerCase(); })).size !== values.length) optMsg = 'Two options are the same';
                optFb.textContent = optMsg; optFb.classList.toggle('show', !!optMsg);
                if (optMsg) ok = false;
                const corFb = block.querySelector('.correct-feedback');
                const hasCorrect = !!block.querySelector('.o-correct:checked');
                corFb.textContent = hasCorrect ? '' : 'Mark the correct answer'; corFb.classList.toggle('show', !hasCorrect);
                if (!hasCorrect) ok = false;
            });
            if (!ok) {
                e.preventDefault();
                const first = form.querySelector('.is-invalid, .invalid-feedback.show');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        list.addEventListener('input', function(e) {
            if (e.target.classList.contains('o-text')) e.target.closest('.question-block').querySelector('.options-feedback').classList.remove('show');
        });
        renumber();
        window.quizRenumber = renumber;" . ($can_ai ? "

        // ---------- AI draft: questions are added to the builder to review and edit ----------
        const LECTURES = " . json_encode($lecture_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";
        const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
        const aiBtn = document.getElementById('aiGenerate');
        const aiErr = document.getElementById('aiError');
        const aiLectures = document.getElementById('aiLectures');
        const classSel = document.getElementById('class_id');
        function showLectures() {
            const items = LECTURES[classSel.value] || [];
            aiLectures.innerHTML = '';
            if (!items.length) { aiLectures.innerHTML = '<p class=\"form-hint\" style=\"margin:0;\">' + (classSel.value ? 'This class has no lectures yet — type a topic below.' : 'Choose the class first.') + '</p>'; return; }
            items.forEach(function(it) {
                const lab = document.createElement('label');
                lab.className = 'material-check';
                const box = document.createElement('input'); box.type = 'checkbox'; box.value = it[0];
                const span = document.createElement('span'); span.textContent = it[1];
                lab.append(box, span);
                aiLectures.appendChild(lab);
            });
        }
        classSel.addEventListener('change', showLectures);
        showLectures();
        function isEmptyBlock(block) {
            return !block.querySelector('.q-text').value.trim() && Array.from(block.querySelectorAll('.o-text')).every(function(o) { return !o.value.trim(); });
        }
        function addDraft(q) {
            const block = document.getElementById('questionTemplate').content.cloneNode(true).firstElementChild;
            block.querySelector('.q-text').value = q.text;
            const opts = block.querySelector('.option-list');
            q.options.forEach(function(text, oi) {
                const row = optionRow();
                row.querySelector('.o-text').value = text;
                opts.appendChild(row);
            });
            list.appendChild(block);
            renumber();
            block.querySelectorAll('.o-correct')[q.correct].checked = true;
            bindLiveClear(block);
            const tag = document.createElement('span');
            tag.className = 'ai-draft-tag'; tag.title = 'Drafted by AI — read it and check the correct answer. The mark goes away when you edit it.';
            tag.innerHTML = '<i class=\"fas fa-robot\"></i> AI draft — check it';
            block.querySelector('.question-head .q-badge').after(tag);
            block.addEventListener('input', function() { tag.remove(); }, { once: true });
            block.addEventListener('change', function() { tag.remove(); }, { once: true });
            return block;
        }
        aiBtn.addEventListener('click', function() {
            aiErr.hidden = true;
            if (!classSel.value) { aiErr.textContent = 'Choose the class first.'; aiErr.hidden = false; return; }
            const fd = new URLSearchParams();
            fd.append('class_id', classSel.value);
            aiLectures.querySelectorAll('input:checked').forEach(function(b) { fd.append('lectures[]', b.value); });
            fd.append('topic', document.getElementById('aiTopic').value);
            fd.append('count', document.getElementById('aiCount').value);
            fd.append('difficulty', document.getElementById('aiDifficulty').value);
            const label = aiBtn.innerHTML;
            aiBtn.disabled = true; aiBtn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Writing questions…';
            fetch('generate.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d.ok) throw new Error(d.error);
                    // replace the untouched first block, keep anything the teacher already wrote
                    const first = list.children.length === 1 ? list.children[0] : null;
                    if (first && isEmptyBlock(first)) first.remove();
                    let firstNew = null;
                    d.questions.forEach(function(q) { if (list.children.length < MAX_Q) { const b = addDraft(q); firstNew = firstNew || b; } });
                    renumber();
                    if (firstNew) firstNew.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    Swal.fire({ icon: 'success', titleText: d.questions.length + ' question' + (d.questions.length === 1 ? '' : 's') + ' added',
                                text: 'Read each one and check the correct answer, then edit what you want. Nothing is published until you press Publish Quiz.' + (d.dropped ? ' (' + d.dropped + ' unusable AI answer' + (d.dropped === 1 ? ' was' : 's were') + ' left out.)' : ''),
                                confirmButtonColor: '#4361ee' });
                })
                .catch(function(e) { aiErr.textContent = e.message || 'The AI could not write questions.'; aiErr.hidden = false; })
                .finally(function() { aiBtn.disabled = false; aiBtn.innerHTML = label; });
        });" : "") . "
    }";
}

/** One option row (also used, with empty values, as the JS template). */
function quizOptionRow($qi, $oi, $text, $checked) {
    return '<div class="option-row">'
        . '<label class="correct-pick" title="Correct answer"><input type="radio" class="o-correct" name="questions[' . $qi . '][correct]" value="' . $oi . '"' . ($checked ? ' checked' : '') . '> <span>Correct</span></label>'
        . '<input type="text" class="o-text" name="questions[' . $qi . '][options][' . $oi . ']" maxlength="500" placeholder="Option ' . ($oi + 1) . '" value="' . htmlspecialchars($text) . '">'
        . '<button type="button" class="icon-btn danger remove-option" title="Remove option" aria-label="Remove option"><i class="fas fa-times"></i></button>'
        . '</div>';
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('Quizzes', 'view.php'); ?>

<?php if (empty($classes)): ?>
<div class="empty-state">
    <i class="fas fa-chalkboard"></i>
    <h3>You have no classes yet</h3>
    <p style="margin-top:6px;">Create a class first, then give it quizzes.</p>
    <div style="margin-top:16px;">
        <a href="<?php echo APP_URL; ?>/pages/classes/create.php" class="btn-app"><i class="fas fa-plus"></i> Create Class</a>
    </div>
</div>
<?php else: ?>
<form method="POST" id="quizForm" novalidate>
    <?php echo csrfField(); ?>

    <div class="form-card" style="margin-bottom:20px;">
        <div class="form-row">
            <div class="form-group">
                <label for="class_id">Class <span class="required-mark">*</span></label>
                <select id="class_id" name="class_id" class="<?php echo $field_errors['class_id'] ? 'is-invalid' : ''; ?>">
                    <option value="">Select a class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['class_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['class_id']); ?></div>
            </div>
            <div class="form-group">
                <label for="title">Quiz Title <span class="required-mark">*</span></label>
                <input type="text" id="title" name="title" maxlength="200" placeholder="e.g. Quiz 1: Python basics"
                    class="<?php echo $field_errors['title'] ? 'is-invalid' : ''; ?>"
                    value="<?php echo htmlspecialchars($form['title']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['title']); ?></div>
            </div>
        </div>

        <?php echo topicSelectField($classes, (int) ($_POST['topic_id'] ?? 0)); ?>

        <div class="form-group">
            <label for="description">Instructions</label>
            <?php echo aiWriteButton('quiz', '#description', ['class' => '#class_id', 'title' => '#title', 'use' => true, 'ctx' => ['time_limit' => '#time_limit', 'due' => '#due_date']]); ?>
            <textarea id="description" name="description" rows="3" maxlength="5000"
                placeholder="Optional instructions shown before students start"
                class="<?php echo $field_errors['description'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['description']); ?></textarea>
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['description']); ?></div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="time_limit">Time Limit (minutes)</label>
                <input type="number" id="time_limit" name="time_limit" min="1" max="300" placeholder="No limit"
                    class="<?php echo $field_errors['time_limit'] ? 'is-invalid' : ''; ?>"
                    value="<?php echo htmlspecialchars($form['time_limit']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['time_limit']); ?></div>
                <small class="form-hint">The quiz submits itself when time runs out.</small>
            </div>
            <div class="form-group">
                <label for="due_date">Due Date</label>
                <input type="datetime-local" id="due_date" name="due_date"
                    class="<?php echo $field_errors['due_date'] ? 'is-invalid' : ''; ?>"
                    value="<?php echo htmlspecialchars($form['due_date']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['due_date']); ?></div>
                <small class="form-hint">Optional. No one can start the quiz after this.</small>
            </div>
            <?php echo releaseModeField(isset($_POST['release_mode']) ? postedReleaseMode() : 'instant'); ?>
        </div>
    </div>

    <?php if ($can_ai): ?>
    <section class="form-card ai-quiz-panel" style="margin-bottom:20px;" aria-labelledby="aiQuizTitle">
        <h2 id="aiQuizTitle" class="ai-h2"><i class="fas fa-robot"></i> Generate questions with AI <span class="status-badge submitted">draft only</span></h2>
        <p class="form-hint" style="margin:-6px 0 12px;">The AI writes questions from your lectures and/or a topic. They appear below as normal questions — you check the answers and edit them before publishing.</p>
        <div class="form-group">
            <label>Lectures to use <span class="form-hint" style="display:inline;margin:0;">tick one or more</span></label>
            <div class="material-checks ai-lectures" id="aiLectures"></div>
        </div>
        <div class="form-group">
            <label for="aiTopic">Topic or notes <span class="form-hint" style="display:inline;margin:0;">optional — used alone or together with the lectures</span></label>
            <textarea id="aiTopic" rows="2" maxlength="3000" placeholder="e.g. Photosynthesis: light reactions and the Calvin cycle"></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="aiCount">Number of questions</label>
                <input type="number" id="aiCount" min="1" max="<?php echo AI_QUIZ_MAX_COUNT; ?>" value="5">
            </div>
            <div class="form-group">
                <label for="aiDifficulty">Difficulty</label>
                <select id="aiDifficulty">
                    <?php foreach (aiQuizDifficulties() as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $k === 'medium' ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <p class="lec-img-error" id="aiError" role="alert" hidden></p>
        <button type="button" class="btn-app" id="aiGenerate"><i class="fas fa-magic"></i> Generate questions</button>
        <small class="form-hint" style="display:block;margin-top:8px;">Only the text of the lectures you tick is sent to the AI — never names or student data. AI can be wrong: always check the correct answer.</small>
    </section>
    <?php else: ?>
    <p class="form-hint" style="margin:0 0 14px;"><i class="fas fa-robot"></i> Want AI to draft questions from your lectures? Add your own AI key in <a href="<?php echo APP_URL; ?>/pages/settings/my_ai.php">Settings › My AI</a> (or ask the school admin to set up AI).</p>
    <?php endif; ?>

    <section class="form-card" id="bankPanel" style="margin-bottom:20px;" aria-labelledby="bankTitle" data-url="<?php echo APP_URL; ?>/pages/quizzes/bank.php">
        <h2 id="bankTitle" class="ai-h2"><i class="fas fa-box-archive"></i> My question bank</h2>
        <p class="form-hint" style="margin:-6px 0 10px;">Questions you kept from earlier quizzes. Add the ones you want — they become normal questions you can still edit. Only you see your bank.</p>
        <button type="button" class="btn-app btn-outline" id="bankOpen"><i class="fas fa-search"></i> Browse my questions</button>
        <div id="bankBox" hidden>
            <div class="form-row" style="margin-top:12px;">
                <div class="form-group" style="margin:0;"><label for="bankSearch" class="sr-only">Search</label><input type="search" id="bankSearch" placeholder="Search my questions" aria-label="Search my questions"></div>
                <div class="form-group" style="margin:0;"><label for="bankTagFilter" class="sr-only">Tag</label><select id="bankTagFilter" aria-label="Tag"><option value="">All tags</option></select></div>
            </div>
            <div id="bankList" class="bank-list" role="list" style="margin-top:10px;"></div>
            <button type="button" class="btn-app btn-sm" id="bankAdd" disabled><i class="fas fa-plus"></i> Add selected <span id="bankCount">0</span></button>
        </div>
    </section>

    <div class="quiz-builder-head">
        <h2><i class="fas fa-list-ol"></i> Questions (<span id="questionCount"><?php echo count($questions); ?></span>)
            · <span id="totalMarks">0</span> marks</h2>
        <small class="form-hint" style="margin:0;">Pick the correct answer with the radio button next to it.</small>
    </div>
    <?php if ($field_errors['questions']): ?>
    <div class="invalid-feedback show" style="margin-bottom:10px;"><?php echo htmlspecialchars($field_errors['questions']); ?></div>
    <?php endif; ?>

    <div id="questionList">
        <?php foreach ($questions as $qi => $q): $e = $q_errors[$qi] ?? []; ?>
        <div class="question-block" data-type="<?php echo $q['type']; ?>">
            <div class="question-head">
                <span class="q-badge">Q<span class="q-number"><?php echo $qi + 1; ?></span></span>
                <select class="q-type" name="questions[<?php echo $qi; ?>][type]" aria-label="Question type">
                    <option value="choice" <?php echo $q['type'] === 'choice' ? 'selected' : ''; ?>>Multiple choice</option>
                    <option value="short" <?php echo $q['type'] === 'short' ? 'selected' : ''; ?>>Short answer</option>
                </select>
                <button type="button" class="icon-btn danger remove-question" title="Remove question" aria-label="Remove question"><i class="fas fa-trash"></i></button>
            </div>
            <div class="question-main">
                <div class="form-group">
                    <textarea class="q-text <?php echo !empty($e['text']) ? 'is-invalid' : ''; ?>" name="questions[<?php echo $qi; ?>][text]" rows="2"
                        placeholder="Type the question" aria-label="Question text"><?php echo htmlspecialchars($q['text']); ?></textarea>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($e['text'] ?? ''); ?></div>
                </div>
                <div class="form-group q-marks-group">
                    <input type="number" class="q-marks <?php echo !empty($e['marks']) ? 'is-invalid' : ''; ?>" name="questions[<?php echo $qi; ?>][marks]"
                        min="1" max="100" value="<?php echo htmlspecialchars($q['marks']); ?>" aria-label="Marks">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($e['marks'] ?? ''); ?></div>
                    <small class="form-hint">marks</small>
                </div>
            </div>
            <div class="form-group model-wrap">
                <textarea class="q-model <?php echo !empty($e['model']) ? 'is-invalid' : ''; ?>" name="questions[<?php echo $qi; ?>][model_answer]" rows="3" maxlength="2000"
                    placeholder="Expected answer — what a good answer must include (students never see this before results are released)" aria-label="Expected answer"><?php echo htmlspecialchars($q['model']); ?></textarea>
                <div class="invalid-feedback"><?php echo htmlspecialchars($e['model'] ?? ''); ?></div>
                <small class="form-hint">Students type their answer. You mark it, or press "Mark with AI" on the quiz page and review the AI's marks.</small>
            </div>
            <div class="option-list">
                <?php foreach ($q['options'] as $oi => $text) echo quizOptionRow($qi, $oi, $text, $q['correct'] === $oi); ?>
            </div>
            <div class="invalid-feedback options-feedback <?php echo !empty($e['options']) ? 'show' : ''; ?>"><?php echo htmlspecialchars($e['options'] ?? ''); ?></div>
            <div class="invalid-feedback correct-feedback <?php echo !empty($e['correct']) ? 'show' : ''; ?>"><?php echo htmlspecialchars($e['correct'] ?? ''); ?></div>
            <button type="button" class="btn-app btn-outline btn-sm add-option"><i class="fas fa-plus"></i> Add option</button>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="form-group" style="margin-top:6px;">
        <label class="switch-row"><input type="checkbox" name="save_to_bank" value="1" <?php echo !empty($_POST['save_to_bank']) ? 'checked' : ''; ?>>
            <span><strong>Keep these questions in my question bank</strong><small class="form-hint" style="display:block;margin:2px 0 0;">So you can reuse them in later quizzes. A question you already kept is not saved twice.</small></span></label>
        <input type="text" name="bank_tag" maxlength="60" placeholder="Tag, e.g. Unit 1 — Algebra (optional)" value="<?php echo htmlspecialchars((string) ($_POST['bank_tag'] ?? '')); ?>" aria-label="Question bank tag" style="margin-top:8px;max-width:360px;">
    </div>

    <div class="form-actions" style="margin:10px auto 30px;">
        <button type="button" class="btn-app btn-outline" id="addQuestion"><i class="fas fa-plus-circle"></i> Add Question</button>
        <button type="submit" class="btn-app"><i class="fas fa-paper-plane"></i> Publish Quiz</button>
        <a href="<?php echo APP_URL; ?>/pages/quizzes/view.php" class="btn-app btn-outline">Cancel</a>
    </div>
</form>

<template id="optionTemplate"><?php echo quizOptionRow(0, 0, '', false); ?></template>
<template id="questionTemplate">
    <div class="question-block" data-type="choice">
        <div class="question-head">
            <span class="q-badge">Q<span class="q-number"></span></span>
            <select class="q-type" aria-label="Question type"><option value="choice">Multiple choice</option><option value="short">Short answer</option></select>
            <button type="button" class="icon-btn danger remove-question" title="Remove question" aria-label="Remove question"><i class="fas fa-trash"></i></button>
        </div>
        <div class="question-main">
            <div class="form-group">
                <textarea class="q-text" rows="2" placeholder="Type the question" aria-label="Question text"></textarea>
                <div class="invalid-feedback"></div>
            </div>
            <div class="form-group q-marks-group">
                <input type="number" class="q-marks" min="1" max="100" value="1" aria-label="Marks">
                <div class="invalid-feedback"></div>
                <small class="form-hint">marks</small>
            </div>
        </div>
        <div class="form-group model-wrap">
            <textarea class="q-model" rows="3" maxlength="2000" placeholder="Expected answer — what a good answer must include (students never see this before results are released)" aria-label="Expected answer"></textarea>
            <div class="invalid-feedback"></div>
            <small class="form-hint">Students type their answer. You mark it, or press "Mark with AI" on the quiz page and review the AI's marks.</small>
        </div>
        <div class="option-list"></div>
        <div class="invalid-feedback options-feedback"></div>
        <div class="invalid-feedback correct-feedback"></div>
        <button type="button" class="btn-app btn-outline btn-sm add-option"><i class="fas fa-plus"></i> Add option</button>
    </div>
</template>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
