<?php
/**
 * Assignments
 *   (none) / ?class_id=N  list of assignments in the user's classes, with status
 *   ?id=N                 one assignment
 *       teacher: every student in the class with their submission + grading form
 *       student: instructions, own submission, grade and feedback
 * Grading is a POST (CSRF + class ownership checked) on this page.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

/** Status key for one student's work on an assignment. */
function submissionStatus($submission, $due_date) {
    if (!$submission) {
        return (!empty($due_date) && time() > strtotime($due_date)) ? 'missing' : 'pending';
    }
    if ($submission['status'] === 'graded') return 'graded';
    if ($submission['status'] === 'returned') return 'returned';
    return $submission['status'] === 'late' ? 'late' : 'submitted';
}

/** 7.50 -> "7.5", 10.00 -> "10" (always formatted first, so 100 never loses zeros). */
function formatMarks($m) {
    return rtrim(rtrim(number_format((float) $m, 2, '.', ''), '0'), '.');
}

$status_labels = ['pending' => 'Not submitted', 'missing' => 'Missing', 'submitted' => 'Submitted',
                  'late' => 'Submitted late', 'graded' => 'Graded', 'held' => 'Result pending', 'returned' => 'Returned for changes'];

$assignment_id = (int) ($_GET['id'] ?? 0);
$task_result = null;
$grade_errors = [];   // submission id => ['marks' => msg]
$pc_errors = [];      // student id => private-comment error

if ($assignment_id > 0) {
    $assignment = $db->fetchOne("SELECT * FROM assignments WHERE id = ?", [$assignment_id]);
    // Ownership / membership check on the assignment's class
    $class = $assignment ? getAccessibleClass($assignment['class_id'], $current_user_id, $current_user_role) : null;
    if (!$class) {
        redirect(APP_URL . '/pages/assignments/view.php?error=not_found');
    }

    // ---------- Delete (class teacher only): submissions and their files go too ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_assignment') {
        if (!verifyCsrf() || !isTeacher()) {
            Session::flash('assignment_msg', ['error', 'Not allowed', 'Only the class teacher can delete this assignment.']);
            redirect(APP_URL . '/pages/assignments/view.php?id=' . $assignment['id']);
        }
        $files = array_column($db->fetchAll("SELECT file_path FROM submissions WHERE assignment_id = ? AND file_path IS NOT NULL", [$assignment['id']]), 'file_path');
        $db->delete('assignments', 'id = ? AND class_id = ?', [$assignment['id'], $class['id']]);   // submissions + comments cascade
        foreach ($files as $f) {
            if ($abs = uploadAbsolutePath($f)) @unlink($abs);
        }
        logActivity('delete_assignment', 'assignments', "Deleted assignment #{$assignment['id']} \"{$assignment['title']}\" from {$class['class_name']} (" . count($files) . " files removed)");
        Session::flash('assignment_msg', ['success', 'Assignment deleted', '"' . $assignment['title'] . '" and its submissions were removed.']);
        redirect(APP_URL . '/pages/assignments/view.php?class_id=' . $class['id']);
    }

    // ---------- Result release (teacher of the class): mode switch + "Release results" ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['set_release_mode', 'release_results'], true)) {
        if (!verifyCsrf() || !isTeacher()) {
            Session::flash('assignment_msg', ['error', 'Not allowed', 'Only a teacher of this class can do that.']);
        } elseif ($_POST['action'] === 'set_release_mode') {
            $mode = postedReleaseMode();
            if (setReleaseMode('assignment', $assignment['id'], $mode)) {
                logActivity('release_mode', 'assignments', "Set the results of assignment #{$assignment['id']} to {$mode}");
                Session::flash('assignment_msg', ['success', 'Saved', $mode === 'manual' ? 'Students will not see their grades until you release the results.' : 'Students see their grades as soon as they are graded.']);
            }
        } else {
            $n = releaseResults('assignment', $assignment, $class, $current_user_id);
            Session::flash('assignment_msg', $n === null ? ['info', 'Already released', 'The results of this assignment were released before.']
                : ['success', 'Results released', $n . ' student' . ($n == 1 ? ' was' : 's were') . ' notified (in the app and by email).']);
        }
        redirect(APP_URL . '/pages/assignments/view.php?id=' . $assignment['id']);
    }

    // ---------- Bulk (teacher): same marks for the ticked submissions / remind students who have not handed in ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['bulk_grade', 'remind_missing'], true)) {
        if (!verifyCsrf() || !isTeacher()) {
            Session::flash('assignment_msg', ['error', 'Not allowed', 'Only a teacher of this class can do that.']);
        } elseif ($_POST['action'] === 'bulk_grade') {
            $r = bulkGradeSubmissions($assignment, $class, $current_user_id, (array) ($_POST['ids'] ?? []), trim((string) ($_POST['marks'] ?? '')), trim((string) ($_POST['feedback'] ?? '')));
            if (!$r['ok']) Session::flash('assignment_msg', ['error', 'Not graded', $r['error']]);
            else {
                logActivity('bulk_grade', 'assignments', "Gave {$_POST['marks']} marks to {$r['graded']} submissions of assignment #{$assignment['id']}");
                Session::flash('assignment_msg', ['success', $r['graded'] . ' graded', 'Every student was notified' . (resultsReleased($assignment) ? '' : ' when you release the results') . '.'
                    . ($r['skipped'] ? " {$r['skipped']} ticked row(s) were not valid and were skipped." : '')]);
            }
        } else {
            $r = remindMissingWork('assignment', $assignment, $class, $current_user_id, $_POST['note'] ?? '');
            Session::flash('assignment_msg', $r['none'] ? ['info', 'Nobody is missing', 'Every student has handed in this assignment.']
                : ['success', $r['sent'] . ' reminder' . ($r['sent'] == 1 ? '' : 's') . ' sent', 'Students who had a reminder for this in the last ' . REMIND_COOLDOWN_HOURS . ' hours were skipped' . ($r['recent'] ? " ({$r['recent']})" : '') . '.']);
            if (!$r['none']) logActivity('remind_missing', 'assignments', "Reminded {$r['sent']} students about assignment #{$assignment['id']}");
        }
        redirect(APP_URL . '/pages/assignments/view.php?id=' . $assignment['id']);
    }

    // ---------- Private comment (student <-> class teacher, one thread per student) ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'private_comment') {
        $thread_student = isTeacher() ? (int) ($_POST['student_id'] ?? 0) : (int) $current_user_id;
        $body = trim($_POST['body'] ?? '');
        $enrolled = $db->fetchOne("SELECT id FROM class_members WHERE class_id = ? AND student_id = ?", [$class['id'], $thread_student]);
        if (!verifyCsrf()) {
            $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
        } elseif (!$enrolled) {
            $task_result = ['error', 'Not found', 'That student is not in this class.'];
        } elseif ($body === '' || mb_strlen($body) > 2000) {
            $pc_errors[$thread_student] = 'Write a comment (up to 2,000 characters)';
        } else {
            // Stored with the class's private messages (also listed in the class Messages tab)
            postPrivateMessage($class, $thread_student, $current_user_id, $current_user_name,
                messageContext($class['id'], 'assignment', $assignment['id']), $body);
            redirect(APP_URL . '/pages/assignments/view.php?id=' . $assignment['id'] . '#pc-' . $thread_student);
        }
    }

    // ---------- Grade a submission (teacher who owns the class) ----------
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submission_id = (int) ($_POST['submission_id'] ?? 0);
        $marks_raw = trim($_POST['marks'] ?? '');
        $feedback = trim($_POST['feedback'] ?? '');

        if (!verifyCsrf()) {
            $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
        } elseif (!isTeacher()) {
            $task_result = ['error', 'Not allowed', 'Only the class teacher can grade.'];
        } else {
            // Submission must belong to THIS assignment (whose class this teacher owns)
            $submission = $db->fetchOne(
                "SELECT s.*, u.name AS student_name FROM submissions s
                 INNER JOIN users u ON s.student_id = u.id
                 WHERE s.id = ? AND s.assignment_id = ?",
                [$submission_id, $assignment['id']]
            );
            if (!$submission) {
                $task_result = ['error', 'Not found', 'That submission does not belong to this assignment.'];
            } elseif ($err = gradeSubmission($assignment, $submission, $marks_raw, $feedback, $current_user_id, $class['id'])) {
                $grade_errors[$submission_id] = $err + ['value' => $marks_raw, 'feedback' => $feedback];
            } else {
                Session::flash('graded', $submission['student_name']);
                redirect(APP_URL . '/pages/assignments/view.php?id=' . $assignment['id']);
            }
        }
    }

    if (isTeacher()) {
        // Every enrolled student + their submission (if any)
        $roster = $db->fetchAll(
            "SELECT u.id AS student_id, u.name, u.email,
                    s.id AS submission_id, s.content, s.file_path, s.original_name, s.submitted_at,
                    s.marks_obtained, s.feedback, s.status, s.grade_source, s.returned_at, s.return_note, s.revision
             FROM class_members cm
             INNER JOIN users u ON cm.student_id = u.id
             LEFT JOIN submissions s ON s.assignment_id = ? AND s.student_id = u.id
             WHERE cm.class_id = ?
             ORDER BY u.name",
            [$assignment['id'], $class['id']]
        );
        $submitted_count = count(array_filter($roster, fn($r) => $r['submission_id'] !== null));
        $graded_count = count(array_filter($roster, fn($r) => $r['status'] === 'graded'));
    } else {
        $mine = $db->fetchOne(
            "SELECT * FROM submissions WHERE assignment_id = ? AND student_id = ?",
            [$assignment['id'], $current_user_id]
        );
    }

    // Private comment threads: the teacher sees every student's, a student only their own
    $threads = [];
    foreach ($db->fetchAll(
        "SELECT m.*, u.name AS author, u.role FROM private_messages m INNER JOIN users u ON m.user_id = u.id
         WHERE m.class_id = ? AND m.context_type = 'assignment' AND m.context_id = ?" . (isTeacher() ? '' : ' AND m.student_id = ?') . "
         ORDER BY m.created_at, m.id",
        isTeacher() ? [$class['id'], $assignment['id']] : [$class['id'], $assignment['id'], $current_user_id]) as $c) {
        $threads[$c['student_id']][] = $c;
    }
    if (isStudent()) {
        markThreadRead($class['id'], $current_user_id, 'assignment', $assignment['id'], $current_user_id);   // student has seen the replies
    }

    $task_result = Session::flash('assignment_msg') ?? $task_result;
    $graded_name = Session::flash('graded');
    if ($graded_name !== null) {
        $task_result = ['success', 'Grade saved', $graded_name . ' has been notified.'];
    }

    $page_title = $assignment['title'];
    $page_subtitle = $class['class_name'] . ' · Assignment';
} else {
    // ---------- List ----------
    $assignment = null;
    $classes = getUserClasses($current_user_id, $current_user_role);
    $class_ids = array_map('intval', array_column($classes, 'id'));

    $class = null;
    $filter_id = (int) ($_GET['class_id'] ?? 0);
    if ($filter_id > 0) {
        $class = getAccessibleClass($filter_id, $current_user_id, $current_user_role);
        if (!$class) {
            redirect(APP_URL . '/pages/assignments/view.php?error=not_found');
        }
        $class_ids = [$class['id']];
    }

    $assignments = [];
    if ($class_ids) {
        $in = implode(',', array_fill(0, count($class_ids), '?'));
        if (isTeacher()) {
            $assignments = $db->fetchAll(
                "SELECT a.*, c.class_name,
                        (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = a.class_id) AS student_count,
                        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS submitted_count,
                        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.status = 'graded') AS graded_count,
                        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.status IN ('submitted', 'late')) AS todo_count,
                        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.status = 'returned') AS returned_count
                 FROM assignments a
                 INNER JOIN classes c ON a.class_id = c.id
                 WHERE a.class_id IN ({$in})
                 ORDER BY a.due_date IS NULL, a.due_date ASC, a.id ASC LIMIT 1000",
                $class_ids
            );
        } else {
            $assignments = $db->fetchAll(
                "SELECT a.*, c.class_name,
                        s.id AS submission_id, s.status AS sub_status, s.marks_obtained
                 FROM assignments a
                 INNER JOIN classes c ON a.class_id = c.id
                 LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ?
                 WHERE a.class_id IN ({$in})
                 ORDER BY a.due_date IS NULL, a.due_date ASC, a.id ASC LIMIT 1000",
                array_merge([$current_user_id], $class_ids)
            );
        }
    }

    $page_title = $class ? $class['class_name'] . ' - Assignments' : 'Assignments';
    $page_subtitle = isTeacher() ? 'Work you gave your classes and its submissions' : 'Your class work and due dates';
}

if (!$task_result && $assignment === null) {
    $task_result = Session::flash('assignment_msg');   // e.g. after deleting an assignment
}
if (!$task_result && ($_GET['error'] ?? '') === 'not_found') {
    $task_result = ['error', 'Not available', 'That assignment, class or file does not exist or you do not have access to it.'];
}

$active_menu = 'assignments';
if (!$assignment && isTeacher() && !empty($classes)) $page_actions = '<a href="create.php' . ($class ? '?class_id=' . (int) $class['id'] : '') . '" class="btn-app"><i class="fas fa-plus"></i> ' . htmlspecialchars(t('Create Assignment')) . '</a>';   // in the header row, beside the title
$page_script = "
    const f = document.getElementById('classFilter');
    if (f) f.addEventListener('change', function() { f.form.submit(); });
    document.querySelectorAll('.grade-form').forEach(function(form) { bindLiveClear(form); });
    const delA = document.getElementById('deleteAssignment');
    if (delA) delA.addEventListener('submit', function(e) {
        if (delA.dataset.confirmed) return;
        e.preventDefault();
        Swal.fire({ icon: 'warning', titleText: 'Delete this assignment?',
                    text: " . json_encode(isset($submitted_count) && $submitted_count ? 'All ' . $submitted_count . ' submissions, their files, grades and private comments will be deleted. This cannot be undone.' : 'Students will no longer see it. This cannot be undone.') . ",
                    showCancelButton: true, confirmButtonText: 'Yes, delete', confirmButtonColor: '#ef476f' })
            .then(function(r) { if (r.isConfirmed) { delA.dataset.confirmed = '1'; delA.submit(); } });
    });";
if ($task_result) {
    $page_script .= "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", title: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($task_result[0] === 'success' ? ", timer: 1800, showConfirmButton: false" : "") . " });";
}

/** One private comment thread (+ reply form) for a student on this assignment. */
function privateThread($comments, $student_id, $error) {
    global $class, $assignment;
    $h = '<div class="pc-thread" id="pc-' . (int) $student_id . '">';
    if (isTeacher()) {
        $h .= '<a class="link-btn" style="font-size:12px;" href="' . APP_URL . '/' . htmlspecialchars(messageThreadUrl($class['id'], $student_id, 'assignment', $assignment['id']))
            . '"><i class="fas fa-inbox"></i> Open in Messages</a>';
    }
    if (!$comments) {
        $h .= '<p class="form-hint" style="margin:0 0 8px;">No private comments yet. Only you and ' . (isTeacher() ? 'this student' : 'your teacher') . ' can see them.</p>';
    }
    foreach ($comments as $c) {
        $h .= '<div class="comment"><span class="avatar' . ($c['role'] === 'teacher' ? ' teacher' : '') . '" aria-hidden="true">'
            . htmlspecialchars(mb_strtoupper(mb_substr($c['author'], 0, 1))) . '</span><div class="comment-body"><strong>'
            . htmlspecialchars($c['author']) . '</strong> <span class="notif-meta">' . timeAgo($c['created_at']) . '</span>'
            . '<div class="prewrap">' . htmlspecialchars($c['body']) . '</div></div></div>';
    }
    $h .= '<form method="POST" class="comment-form">' . csrfField()
        . '<input type="hidden" name="action" value="private_comment">'
        . '<input type="hidden" name="student_id" value="' . (int) $student_id . '">'
        . '<div class="form-group" style="flex:1;margin:0;"><textarea name="body" rows="1" maxlength="2000" placeholder="Add private comment…" aria-label="Add private comment"'
        . ($error ? ' class="is-invalid"' : '') . '></textarea><div class="invalid-feedback">' . htmlspecialchars((string) $error) . '</div></div>'
        . '<button type="submit" class="icon-btn" title="Send" aria-label="Send private comment"><i class="fas fa-paper-plane"></i></button></form></div>';
    return $h;
}

$materials = $assignment ? getAssignmentMaterials($assignment['id']) : [];
$rubric = $assignment ? assignmentRubric($assignment) : [];
$page_scripts = ($assignment && isTeacher()) ? ['assets/js/class.js', 'assets/js/bulk.js', 'assets/js/asg-list.js'] : ['assets/js/asg-list.js'];   // confirm dialogs, bulk actions, the list's filters
$hide_page_head = (bool) $assignment;   // an assignment draws its own header (title, chips, actions, tabs)

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php if ($assignment): ?>
<?php $past_due = !empty($assignment['due_date']) && time() > strtotime($assignment['due_date']); ?>
<?php include __DIR__ . (isTeacher() ? '/_detail_teacher.php' : '/_detail_student.php'); ?>
<?php else: ?>
<div class="page-toolbar">
    <?php if (!empty($classes)): ?>
    <form method="GET" class="filter-form">
        <select id="classFilter" name="class_id" aria-label="<?php echo htmlspecialchars(t('Filter by class')); ?>">
            <option value="0"><?php echo t('All my classes'); ?></option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo ($class && (int) $class['id'] === (int) $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
    </form>
    <?php else: ?><span></span><?php endif; ?>
</div>
<?php if (empty($assignments)): ?>
<div class="empty-state"><i class="fas fa-tasks"></i><h3><?php echo t('No assignments yet'); ?></h3>
    <p style="margin-top:6px;"><?php if (empty($classes)): echo isTeacher() ? 'Create a class first, then give it assignments.' : 'Join a class to see its assignments.'; else: echo isTeacher() ? 'Create the first assignment for your students.' : 'You have no assignments right now.'; endif; ?></p></div>
<?php else: include __DIR__ . '/_list.php'; endif; ?>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
