<?php
/**
 * Review a student before approving — ?id=N
 * Shows who the person is (name, email, photo, when they registered, whether the email was confirmed, last sign-in) and which classes they asked
 * to join — then Approve / Reject. Who sees whom is the same as on the approval list: a teacher sees only students who asked to join one of
 * THEIR classes (and only those classes); the super admin sees every waiting or rejected account.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/dashboard.php');

$is_admin = isAdmin();
$sid = (int) ($_GET['id'] ?? 0);
$in_scope = isset(array_column(getPendingStudentsFor($current_user_id, $is_admin), null, 'id')[$sid])
    || ($is_admin && $db->fetchOne("SELECT id FROM users WHERE id = ? AND role = 'student' AND is_approved = 0 AND is_active = 0", [$sid]));
$s = $in_scope ? $db->fetchOne("SELECT * FROM users WHERE id = ? AND role = 'student'", [$sid]) : null;
if (!$s) redirect(APP_URL . '/pages/students/approve.php');

$requests = $db->fetchAll(
    "SELECT r.*, c.class_name, c.class_code, t.name AS teacher_name FROM class_join_requests r
     INNER JOIN classes c ON r.class_id = c.id INNER JOIN users t ON c.teacher_id = t.id
     WHERE r.student_id = ?" . ($is_admin ? '' : " AND " . teachesClassSql('c')) . " ORDER BY r.created_at DESC",
    $is_admin ? [$sid] : [$sid, $current_user_id]);
$rejected = (int) $s['is_active'] === 0;
$initial = htmlspecialchars(mb_strtoupper(mb_substr($s['name'], 0, 1)));

$page_title = 'Review student';
$page_subtitle = 'Check who they are before you decide';
$active_menu = 'students';
$page_scripts = ['assets/js/class.js'];
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>approve.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Waiting for approval</a>

<div class="content-card review-card">
    <div class="card-inner review-head">
        <?php if (!empty($s['photo'])): ?><img class="review-photo" src="<?php echo APP_URL; ?>/pages/profile/photo.php?u=<?php echo (int) $s['id']; ?>" alt="">
        <?php else: ?><span class="review-avatar" aria-hidden="true"><?php echo $initial; ?></span><?php endif; ?>
        <div>
            <h2 style="margin:0;font-size:1.3rem;"><?php echo htmlspecialchars($s['name']); ?></h2>
            <div class="form-hint" style="margin:2px 0 8px;"><?php echo htmlspecialchars($s['email']); ?></div>
            <span class="status-badge <?php echo $rejected ? 'missing' : 'pending'; ?>"><?php echo $rejected ? 'Rejected earlier' : 'Waiting for approval'; ?></span>
        </div>
    </div>
    <div class="card-inner" style="padding-top:0;">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Registered</div><div class="value"><?php echo formatDate($s['created_at'], 'M d, Y h:i A'); ?><br><span class="form-hint" style="margin:0;"><?php echo timeAgo($s['created_at']); ?></span></div></div>
            <div class="detail-item"><div class="label">Email confirmed</div><div class="value"><?php echo $s['email_verified_at'] ? '<span class="status-badge graded">Yes</span> ' . formatDate($s['email_verified_at'], 'M d, Y') : '<span class="status-badge late">Not confirmed</span>'; ?></div></div>
            <div class="detail-item"><div class="label">Last sign-in</div><div class="value"><?php echo $s['last_login'] ? timeAgo($s['last_login']) : 'Never'; ?></div></div>
            <div class="detail-item"><div class="label">Class requests</div><div class="value"><?php echo count($requests); ?></div></div>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-chalkboard"></i> Classes they asked to join</h2></div>
    <?php if (!$requests): ?>
    <div class="empty-state" style="border:none;"><i class="fas fa-chalkboard"></i><p>They did not ask to join any class<?php echo $is_admin ? '' : ' of yours'; ?> — they only created an account.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="app-table">
        <thead><tr><th>Class</th><?php if ($is_admin): ?><th>Teacher</th><?php endif; ?><th>Code used</th><th>Asked</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
        <tr><td><strong><?php echo htmlspecialchars($r['class_name']); ?></strong></td>
            <?php if ($is_admin): ?><td><?php echo htmlspecialchars($r['teacher_name']); ?></td><?php endif; ?>
            <td><code><?php echo htmlspecialchars($r['class_code']); ?></code></td>
            <td><?php echo timeAgo($r['created_at']); ?></td>
            <td><span class="status-badge <?php echo $r['status'] === 'approved' ? 'graded' : ($r['status'] === 'declined' ? 'missing' : 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<div class="content-card">
    <div class="card-inner" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <form method="POST" action="approve.php">
            <?php echo csrfField(); ?><input type="hidden" name="student_id" value="<?php echo (int) $s['id']; ?>">
            <button type="submit" name="decision" value="approve" class="btn-app"><i class="fas fa-check"></i> <?php echo $rejected ? 'Approve instead' : 'Approve'; ?></button>
        </form>
        <?php if (!$rejected): ?>
        <form method="POST" action="approve.php" data-confirm="Reject <?php echo htmlspecialchars($s['name']); ?>?" data-confirm-text="<?php echo $is_admin ? 'They will not be able to sign in. You can approve them later from the Rejected list.' : 'Their request to your class is declined. Other teachers can still accept them.'; ?>" data-confirm-button="Reject">
            <?php echo csrfField(); ?><input type="hidden" name="student_id" value="<?php echo (int) $s['id']; ?>"><input type="hidden" name="decision" value="reject">
            <button type="submit" class="btn-app btn-danger"><i class="fas fa-times"></i> Reject</button>
        </form>
        <?php endif; ?>
        <a class="btn-app btn-outline" href="approve.php">Back to the list</a>
    </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
