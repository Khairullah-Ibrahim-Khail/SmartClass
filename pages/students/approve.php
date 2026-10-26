<?php
/**
 * Approve Students
 * New student accounts wait here (is_approved = 0) until approved. Who sees whom:
 *   teacher      only accounts that asked to join one of THEIR classes (and only their class names)
 *   super admin  every waiting account, also those without a class, plus the Rejected list
 *   Approve -> is_approved = 1, is_active = 1, and the requests for the approver's classes are accepted
 *   Reject  -> teacher: declines the requests for their own classes (the account is untouched —
 *              other teachers may still accept it); super admin: is_active = 0 (cannot log in)
 * POST + CSRF; the target must be in the viewer's own list (checked server-side).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/dashboard.php');
}

$task_result = null;
$is_admin = isAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'] ?? '';
    $sid = (int) ($_POST['student_id'] ?? 0);
    // Only students this viewer may decide on: their own requesters (admin: anyone waiting or rejected)
    $in_scope = isset(array_column(getPendingStudentsFor($current_user_id, $is_admin), null, 'id')[$sid])
        || ($is_admin && $db->fetchOne("SELECT id FROM users WHERE id = ? AND role = 'student' AND is_approved = 0 AND is_active = 0", [$sid]));
    $student = $in_scope ? $db->fetchOne(
        "SELECT id, name, email, is_approved, is_active FROM users WHERE id = ? AND role = 'student'", [$sid]) : null;

    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!$student || !in_array($decision, ['approve', 'reject'], true)) {
        $task_result = ['error', 'Not found', 'That student account does not exist.'];
    } elseif ($decision === 'approve') {
        $db->update('users', ['is_approved' => 1, 'is_active' => 1], "id = ? AND role = 'student'", [$student['id']]);
        // Their pending request for one of MY classes is approved in the same step
        foreach ($db->fetchAll(
            "SELECT c.*, r.id AS request_id FROM class_join_requests r INNER JOIN classes c ON r.class_id = c.id
             WHERE r.student_id = ? AND r.status = 'pending' AND " . teachesClassSql('c') . "", [$student['id'], $current_user_id]) as $own_class) {
            decideJoinRequest($own_class, $own_class['request_id'], true, $current_user_id);   // $own_class['id'] = class id
        }
        logActivity('approve_student', 'students', "Approved student #{$student['id']} {$student['name']} ({$student['email']})");
        notify(
            $student['id'],
            'student_approved',
            'Your account was approved',
            "{$current_user_name} approved your account. You can now join classes with a class code.",
            'pages/classes/join.php',
            $current_user_id
        );
        Session::flash('approval', ['success', 'Student approved', $student['name'] . ' can now log in and join classes.']);
        redirect(APP_URL . '/pages/students/approve.php');
    } elseif (!$is_admin) {
        // A teacher declines only the requests for their own classes; the account stays waiting
        $n = 0;
        foreach ($db->fetchAll(
            "SELECT c.*, r.id AS request_id FROM class_join_requests r INNER JOIN classes c ON r.class_id = c.id
             WHERE r.student_id = ? AND r.status = 'pending' AND " . teachesClassSql('c'), [$student['id'], $current_user_id]) as $own_class) {
            if (decideJoinRequest($own_class, $own_class['request_id'], false, $current_user_id)) $n++;
        }
        logActivity('decline_student', 'students', "Declined {$student['name']} ({$student['email']}) for {$n} of my classes");
        Session::flash('approval', ['success', 'Request declined', $student['name'] . ' will not join your class' . ($n == 1 ? '' : 'es') . ' and has been told.']);
        redirect(APP_URL . '/pages/students/approve.php');
    } else {
        if ((int) $student['is_approved'] === 1) {
            $task_result = ['error', 'Already approved', $student['name'] . ' is already an approved student.'];
        } else {
            $db->update('users', ['is_active' => 0], "id = ? AND role = 'student' AND is_approved = 0", [$student['id']]);
            logActivity('reject_student', 'students', "Rejected student #{$student['id']} {$student['name']} ({$student['email']})");
            Session::flash('approval', ['success', 'Registration rejected', $student['name'] . ' cannot log in. You can still approve them later.']);
            redirect(APP_URL . '/pages/students/approve.php');
        }
    }
}

$task_result = Session::flash('approval') ?? $task_result;

$pending = getPendingStudentsFor($current_user_id, $is_admin);
$rejected = $is_admin ? $db->fetchAll(
    "SELECT id, name, email, created_at, updated_at FROM users
     WHERE role = 'student' AND is_approved = 0 AND is_active = 0
     ORDER BY updated_at DESC"
) : [];

$page_title = 'Approve Students';
$page_subtitle = $is_admin ? 'Every new student account waiting for approval (you are the super admin)'
                            : 'New students who asked to join one of your classes';
$active_menu = 'students';

$page_script = "
    document.querySelectorAll('.approval-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const btn = e.submitter;
            if (!btn || btn.value !== 'reject') return;
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                titleText: 'Reject ' + form.dataset.name + '?',
                text: " . json_encode($is_admin ? 'They will not be able to log in. You can approve them later from the Rejected list.'
                                                : 'Their request to your class is declined. Other teachers can still accept them.') . ",
                showCancelButton: true,
                confirmButtonText: 'Yes, reject',
                confirmButtonColor: '#ef476f'
            }).then(function(r) {
                if (!r.isConfirmed) return;
                const d = document.createElement('input');
                d.type = 'hidden'; d.name = 'decision'; d.value = 'reject';
                form.appendChild(d);
                form.submit();
            });
        });
    });";
if ($task_result) {
    $page_script .= "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", title: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($task_result[0] === 'success' ? ", timer: 2200, showConfirmButton: false" : "") . " });";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-user-clock"></i> Pending Approval (<?php echo count($pending); ?>)</h2>
    </div>
    <?php if (empty($pending)): ?>
    <div class="empty-state" style="border:none;">
        <i class="fas fa-user-check"></i>
        <p>No students are waiting for approval.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="app-table">
            <thead>
                <tr><th>Student</th><th>Email</th><th>Wants to join</th><th>Registered</th><th style="width:220px;">Decision</th></tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $p): ?>
                <tr>
                    <td class="nowrap"><?php if (!empty($p['photo'])): ?><img class="req-photo" src="<?php echo APP_URL; ?>/pages/profile/photo.php?u=<?php echo (int) $p['id']; ?>" alt="" loading="lazy"><?php else: ?><span class="avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($p['name'], 0, 1))); ?></span><?php endif; ?>
                        <a href="review.php?id=<?php echo (int) $p['id']; ?>"><strong><?php echo htmlspecialchars($p['name']); ?></strong></a></td>
                    <td><?php echo htmlspecialchars($p['email']); ?></td>
                    <td><?php echo $p['wants'] ? htmlspecialchars($p['wants']) : '<span class="form-hint" style="margin:0;">No class yet</span>'; ?></td>
                    <td><?php echo timeAgo($p['created_at']); ?></td>
                    <td>
                        <form method="POST" class="approval-form action-buttons" data-name="<?php echo htmlspecialchars($p['name']); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="student_id" value="<?php echo (int) $p['id']; ?>">
                            <a class="btn-app btn-sm btn-outline" href="review.php?id=<?php echo (int) $p['id']; ?>"><i class="fas fa-id-card"></i> Review</a>
                            <button type="submit" name="decision" value="approve" class="btn-app btn-sm">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button type="submit" name="decision" value="reject" class="btn-app btn-sm btn-danger">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($rejected)): ?>
<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-user-slash"></i> Rejected (<?php echo count($rejected); ?>)</h2>
    </div>
    <div class="table-wrap">
        <table class="app-table">
            <thead>
                <tr><th>Student</th><th>Email</th><th>Rejected</th><th style="width:220px;">Decision</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rejected as $p): ?>
                <tr>
                    <td><a href="review.php?id=<?php echo (int) $p['id']; ?>"><strong><?php echo htmlspecialchars($p['name']); ?></strong></a></td>
                    <td><?php echo htmlspecialchars($p['email']); ?></td>
                    <td><?php echo timeAgo($p['updated_at']); ?></td>
                    <td>
                        <form method="POST" class="approval-form" data-name="<?php echo htmlspecialchars($p['name']); ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="student_id" value="<?php echo (int) $p['id']; ?>">
                            <button type="submit" name="decision" value="approve" class="btn-app btn-sm btn-outline">
                                <i class="fas fa-undo"></i> Approve instead
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
