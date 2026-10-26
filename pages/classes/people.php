<?php
/**
 * Class — People: ?id=N
 * Teacher + students. Teacher: invite (code / link), open a student's progress,
 * remove a student (AJAX removeStudentFromClass, CSRF header). Student: leave the class.
 * Join requests (classes that require approval): the teacher approves / declines each
 * student, or approves all; approving also activates a brand-new student account.
 *   POST approve_request | decline_request | approve_all   class teacher
 *   POST leave_class   enrolled student
 * Teachers card: the owner adds a co-teacher by typing their email (no list of other teachers
 * is ever shown) and removes them;
 * a co-teacher can step down. Co-teachers can do everything except archive the class and
 * manage co-teachers.
 *   POST add_co_teacher | remove_co_teacher   class owner (is_primary)
 *   POST leave_teaching                       co-teacher
 * Teacher also sees each student's overall grade, attendance, missing work and last
 * activity in this class (getClassRoster), can sort by them (?sort=) and download the
 * roster as CSV (roster_export.php).
 * Every POST is CSRF-checked and uses Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

$task_result = null;
$co_error = '';
$co_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (($action === 'approve_request' || $action === 'decline_request') && $is_owner) {
        $req = decideJoinRequest($class, (int) ($_POST['request_id'] ?? 0), $action === 'approve_request', $current_user_id);
        if (!$req) {
            $task_result = ['error', 'Not found', 'That request was already handled.'];
        } else {
            Session::flash('class_msg', $action === 'approve_request'
                ? ['success', 'Student approved', $req['name'] . ' is now in the class and has been notified.']
                : ['success', 'Request declined', $req['name'] . ' has been notified.']);
            redirect(classUrl('people') . '#requests');
        }
    } elseif ($action === 'approve_all' && $is_owner) {
        $n = 0;
        foreach (getJoinRequests($class_id) as $r) {
            if (decideJoinRequest($class, $r['id'], true, $current_user_id)) $n++;
        }
        Session::flash('class_msg', ['success', 'Requests approved', $n . ' student' . ($n == 1 ? '' : 's') . ' added to the class.']);
        redirect(classUrl('people'));
    } elseif ($action === 'add_co_teacher' && $class['is_primary']) {
        $co_email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $cand = filter_var($co_email, FILTER_VALIDATE_EMAIL) ? $db->fetchOne(
            "SELECT id, name, email FROM users WHERE email = ? AND role = 'teacher' AND is_active = 1 AND is_approved = 1", [$co_email]) : null;
        $tid = $cand ? (int) $cand['id'] : 0;
        if ($co_email === '') {
            $co_error = 'Enter your colleague\'s email';
        } elseif (!filter_var($co_email, FILTER_VALIDATE_EMAIL)) {
            $co_error = 'Enter a valid email address';
        } elseif (!$cand) {
            $co_error = 'No teacher account uses this email. Ask the school admin to invite them first.';
        } elseif ($db->fetchOne("SELECT id FROM class_teachers WHERE class_id = ? AND teacher_id = ?", [$class_id, $tid])) {
            $co_error = 'This teacher already teaches this class';
        } else {
            $db->insert('class_teachers', ['class_id' => $class_id, 'teacher_id' => $tid, 'role' => 'co_teacher', 'added_by' => $current_user_id]);
            logActivity('add_co_teacher', 'classes', "Added {$cand['name']} as co-teacher of {$class['class_name']}");
            notify($tid, 'co_teacher_added', 'You are now a co-teacher of ' . $class['class_name'],
                $current_user_name . ' added you. You can post, add classwork, grade and take attendance.',
                'pages/classes/stream.php?id=' . $class_id, $current_user_id, $class_id);
            Session::flash('class_msg', ['success', 'Co-teacher added', $cand['name'] . ' can now teach this class and has been notified.']);
            redirect(classUrl('people') . '#teachers');
        }
    } elseif ($action === 'remove_co_teacher' && $class['is_primary']) {
        $tid = (int) ($_POST['teacher_id'] ?? 0);
        $row = $db->fetchOne("SELECT ct.id, u.name FROM class_teachers ct INNER JOIN users u ON ct.teacher_id = u.id
                              WHERE ct.class_id = ? AND ct.teacher_id = ? AND ct.role = 'co_teacher'", [$class_id, $tid]);
        if (!$row) {
            $task_result = ['error', 'Not found', 'That teacher is not a co-teacher of this class.'];
        } else {
            $db->delete('class_teachers', 'id = ?', [$row['id']]);
            logActivity('remove_co_teacher', 'classes', "Removed {$row['name']} as co-teacher of {$class['class_name']}");
            notify($tid, 'removed_from_class', 'You were removed as co-teacher of ' . $class['class_name'], '', null, $current_user_id, $class_id);
            Session::flash('class_msg', ['success', 'Co-teacher removed', $row['name'] . ' no longer has access to this class. Their posts and work stay.']);
            redirect(classUrl('people') . '#teachers');
        }
    } elseif ($action === 'leave_teaching' && $class['teacher_role'] === 'co_teacher') {
        $db->delete('class_teachers', "class_id = ? AND teacher_id = ? AND role = 'co_teacher'", [$class_id, $current_user_id]);
        logActivity('leave_co_teaching', 'classes', "Stepped down as co-teacher of {$class['class_name']}");
        notify($class['teacher_id'], 'removed_from_class', $current_user_name . ' stepped down as co-teacher of ' . $class['class_name'],
            '', 'pages/classes/people.php?id=' . $class_id . '#teachers', $current_user_id, $class_id);
        Session::flash('classes_msg', ['success', 'You stepped down', 'You are no longer a teacher of ' . $class['class_name'] . '.']);
        redirect(APP_URL . '/pages/classes/my-classes.php');
    } elseif ($action === 'leave_class' && !$is_owner) {
        $db->delete('class_members', 'class_id = ? AND student_id = ?', [$class_id, $current_user_id]);
        logActivity('leave_class', 'classes', "Left class {$class['class_name']}");
        notifyClassTeachers($class_id, 'removed_from_class', $current_user_name . ' left ' . $class['class_name'],
            '', 'pages/classes/people.php?id=' . $class_id, $current_user_id);
        Session::flash('classes_msg', ['success', 'You left the class', 'You can join again with the class code.']);
        redirect(APP_URL . '/pages/classes/my-classes.php');
    } else {
        $task_result = ['error', 'Not allowed', 'You do not have permission to do that.'];
    }
}

// ---------------------------------------------------------------
// Data
// ---------------------------------------------------------------
$task_result = Session::flash('class_msg') ?? $task_result;
$teachers = getClassTeachers($class_id);
$requests = $is_owner ? getJoinRequests($class_id) : [];
$members = $db->fetchAll(
    "SELECT u.id, u.name, u.email, cm.joined_at FROM class_members cm
     INNER JOIN users u ON cm.student_id = u.id
     WHERE cm.class_id = ? ORDER BY u.name", [$class_id]);

// Teacher: numbers per student + sort
$sorts = ['name' => 'Name', 'grade' => 'Lowest grade first', 'attendance' => 'Lowest attendance first', 'missing' => 'Most missing work', 'active' => 'Least recently active'];
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'name';
if ($is_owner && $members) {
    $roster = array_column(getClassRoster($class_id), null, 'id');
    foreach ($members as &$m) {
        $m += $roster[$m['id']] ?? ['last_active' => null, 'percent' => null, 'attendance' => null, 'missing' => 0];
    }
    unset($m);
    $nulls_first = fn($v) => $v === null ? -1 : $v;   // "no grade yet" sorts before the lowest grade
    $cmp = [
        'grade'      => fn($a, $b) => $nulls_first($a['percent']) <=> $nulls_first($b['percent']),
        'attendance' => fn($a, $b) => $nulls_first($a['attendance']) <=> $nulls_first($b['attendance']),
        'missing'    => fn($a, $b) => $b['missing'] <=> $a['missing'],
        'active'     => fn($a, $b) => strcmp((string) $a['last_active'], (string) $b['last_active']),
    ][$sort] ?? null;
    if ($cmp) usort($members, fn($a, $b) => $cmp($a, $b) ?: strcmp($a['name'], $b['name']));
}

$people_script = $is_owner ? "
    document.querySelectorAll('.remove-student').forEach(function(btn) {
        btn.addEventListener('click', function() {
            Swal.fire({
                icon: 'warning',
                titleText: 'Remove ' + btn.dataset.name + '?',
                text: 'They will lose access to this class. Their submitted work is kept and shows again if they rejoin.',
                showCancelButton: true,
                confirmButtonText: 'Yes, remove',
                confirmButtonColor: '#ef476f'
            }).then(function(r) {
                if (!r.isConfirmed) return;
                const fd = new FormData();
                fd.append('action', 'removeStudentFromClass');
                fd.append('class_id', '{$class_id}');
                fd.append('student_id', btn.dataset.id);
                fetch('" . APP_URL . "/ajax/handler.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content
                    },
                    body: fd
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Removed', text: data.message, timer: 1500, showConfirmButton: false })
                            .then(function() { window.location.reload(); });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Failed', text: data.message, confirmButtonColor: '#4361ee' });
                    }
                })
                .catch(function() {
                    Swal.fire({ icon: 'error', title: 'Failed', text: 'Request failed', confirmButtonColor: '#4361ee' });
                });
            });
        });
    });" : '';

$page_title = $class['class_name'] . ' - People';
$page_script = $people_script . classResultScript($task_result);
$page_scripts[] = 'assets/js/people.js';

include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('people');
?>
<div class="people-layout">
    <?php if ($is_owner && $requests): ?>
    <section class="content-card requests-card" id="requests">
        <div class="card-head">
            <h2><i class="fas fa-user-clock"></i> Join requests (<?php echo count($requests); ?>)</h2>
            <?php if (count($requests) > 1): ?>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="approve_all">
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-check-double"></i> Approve all</button>
            </form>
            <?php endif; ?>
        </div>
        <?php foreach ($requests as $r): ?>
        <div class="person-row request-row">
            <?php if (!empty($r['photo'])): ?>
            <img class="req-photo lg" src="<?php echo APP_URL; ?>/pages/profile/photo.php?u=<?php echo (int) $r['student_id']; ?>" alt="Photo of <?php echo htmlspecialchars($r['name']); ?>" loading="lazy">
            <?php else: ?>
            <?php echo avatarHtml($r['student_id'], $r['name']); ?>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                <?php if (!(int) $r['is_approved']): ?><span class="status-badge late">New account</span><?php endif; ?>
                <div class="notif-meta"><?php echo htmlspecialchars($r['email']); ?> · asked <?php echo timeAgo($r['created_at']); ?></div>
                <div class="request-facts">
                    <span title="When the account was created"><i class="fas fa-user-clock"></i> Signed up <?php echo formatDate($r['signed_up'], 'M d, Y'); ?></span>
                    <span class="<?php echo $r['email_verified_at'] ? 'fact-ok' : 'fact-warn'; ?>" title="Verified by signing in with a code sent to this email">
                        <i class="fas fa-<?php echo $r['email_verified_at'] ? 'check-circle' : 'exclamation-circle'; ?>"></i> <?php echo $r['email_verified_at'] ? 'Email verified' : 'Email not verified'; ?></span>
                    <span title="Classes this student is already in"><i class="fas fa-chalkboard"></i> In <?php echo (int) $r['class_count']; ?> class<?php echo (int) $r['class_count'] === 1 ? '' : 'es'; ?></span>
                    <span><i class="fas fa-<?php echo empty($r['photo']) ? 'image' : 'check'; ?>"></i> <?php echo empty($r['photo']) ? 'No profile photo' : 'Has a profile photo'; ?></span>
                </div>
            </div>
            <div class="action-buttons">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="approve_request">
                    <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
                    <button type="submit" class="btn-app btn-sm"><i class="fas fa-check"></i> Approve</button>
                </form>
                <form method="POST" data-confirm="Decline <?php echo htmlspecialchars($r['name']); ?>?" data-confirm-text="They will be told and can ask again with the class code." data-confirm-button="Decline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="decline_request">
                    <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
                    <button type="submit" class="btn-app btn-sm btn-outline btn-danger-outline"><i class="fas fa-times"></i> Decline</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <p class="form-hint requests-note"><i class="fas fa-info-circle"></i> Approved students appear on the attendance sheet, in grades and in the stream.
            <?php if ($class['require_approval']): ?>Change this in <a href="edit.php?id=<?php echo $class_id; ?>">class settings</a>.<?php endif; ?></p>
    </section>
    <?php endif; ?>

    <section class="content-card" id="teachers">
        <div class="card-head"><h2><i class="fas fa-chalkboard-teacher"></i> <?php echo count($teachers) > 1 ? 'Teachers (' . count($teachers) . ')' : 'Teacher'; ?></h2></div>
        <?php foreach ($teachers as $t): ?>
        <div class="person-row">
            <?php echo avatarHtml($t['id'] ?? ($t['teacher_id'] ?? 0), $t['name'], 'teacher'); ?>
            <div style="flex:1;min-width:0;"><strong><?php echo htmlspecialchars($t['name']); ?></strong>
                <span class="role-tag"><?php echo $t['role'] === 'owner' ? 'Owner' : 'Co-teacher'; ?></span>
                <?php echo (int) $t['id'] === (int) $current_user_id ? '<span class="form-hint" style="display:inline;margin:0;"> (you)</span>' : ''; ?>
                <div class="notif-meta"><?php echo htmlspecialchars($t['email']); ?></div></div>
            <?php if ($class['is_primary'] && $t['role'] === 'co_teacher'): ?>
            <form method="POST" data-confirm="Remove <?php echo htmlspecialchars($t['name']); ?> as co-teacher?" data-confirm-text="They lose access to this class. Their posts, lectures and grades stay." data-confirm-button="Remove">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="remove_co_teacher"><input type="hidden" name="teacher_id" value="<?php echo (int) $t['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Remove co-teacher" aria-label="Remove <?php echo htmlspecialchars($t['name']); ?> as co-teacher"><i class="fas fa-user-minus"></i></button>
            </form>
            <?php elseif ((int) $t['id'] === (int) $current_user_id && $t['role'] === 'co_teacher'): ?>
            <form method="POST" data-confirm="Step down as co-teacher?" data-confirm-text="You lose access to this class. The owner can add you again." data-confirm-button="Step down">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="leave_teaching">
                <button type="submit" class="btn-app btn-sm btn-outline btn-danger-outline"><i class="fas fa-sign-out-alt"></i> Step down</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($class['is_primary']): ?>
        <form method="POST" class="co-teacher-add" novalidate>
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_co_teacher">
            <div class="form-group" style="margin:0;flex:1;min-width:0;">
                <label for="coTeacher">Add a co-teacher</label>
                <input type="email" id="coTeacher" name="email" maxlength="100" autocomplete="off" placeholder="colleague@school.edu"
                    class="<?php echo $co_error ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($co_email); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($co_error); ?></div>
                <small class="form-hint">Type the email of a colleague who already has a teacher account. Co-teachers can post, add classwork, grade and take attendance.</small>
            </div>
            <button type="submit" class="btn-app btn-sm"><i class="fas fa-user-plus"></i> Add</button>
        </form>
        <?php endif; ?>
    </section>

    <section class="content-card">
        <div class="card-head">
            <h2><i class="fas fa-user-graduate"></i> Students (<?php echo count($members); ?>)</h2>
            <?php if ($is_owner): ?>
            <div class="action-buttons">
                <?php if ($members): ?>
                <form method="GET" class="people-sort">
                    <input type="hidden" name="id" value="<?php echo $class_id; ?>">
                    <label class="visually-hidden" for="peopleSort">Sort students</label>
                    <select id="peopleSort" name="sort" data-autosubmit>
                        <?php foreach ($sorts as $k => $label): ?>
                        <option value="<?php echo $k; ?>" <?php echo $sort === $k ? 'selected' : ''; ?>>Sort: <?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn-app btn-sm">Sort</button></noscript>
                </form>
                <a class="btn-app btn-sm btn-outline" href="roster_export.php?id=<?php echo $class_id; ?>"><i class="fas fa-file-csv"></i> Roster CSV</a>
                <a class="btn-app btn-sm btn-outline" href="import.php?id=<?php echo $class_id; ?>"><i class="fas fa-file-import"></i> Import CSV</a>
                <a class="btn-app btn-sm btn-outline" target="_blank" href="roster_print.php?id=<?php echo $class_id; ?>"><i class="fas fa-file-pdf"></i> Class list</a>
                <a class="btn-app btn-sm btn-outline" target="_blank" href="roster_print.php?id=<?php echo $class_id; ?>&amp;layout=sheet" title="A blank paper attendance sheet for the month"><i class="fas fa-table"></i> Paper sheet</a>
                <?php endif; ?>
                <button type="button" class="btn-app btn-sm btn-outline" data-copy="<?php echo htmlspecialchars($class['class_code']); ?>" data-copy-label="Class code"><i class="fas fa-key"></i> <?php echo htmlspecialchars($class['class_code']); ?></button>
                <button type="button" class="btn-app btn-sm" data-copy="<?php echo htmlspecialchars($invite_link); ?>" data-copy-label="Invite link"><i class="fas fa-user-plus"></i> Copy invite link</button>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$members): ?>
        <div class="empty-state" style="border:none;">
            <i class="fas fa-user-plus"></i>
            <p><?php echo $is_owner ? 'No students yet. Share the class code or the invite link — students can join in one step.' : 'No other students yet.'; ?></p>
            <?php if ($is_owner): ?><p><a class="btn-app btn-sm btn-outline" href="import.php?id=<?php echo $class_id; ?>"><i class="fas fa-file-import"></i> Import students from a CSV file</a></p><?php endif; ?>
        </div>
        <?php else: ?>
        <?php if ($is_owner && count($members) > 4): ?>
        <div class="people-filter">
            <label class="pf-search"><i class="fas fa-search" aria-hidden="true"></i><input type="search" id="peopleSearch" placeholder="Search students by name or email" autocomplete="off" aria-label="Search students"></label>
            <span class="pf-chips" role="group" aria-label="Filter students">
                <button type="button" class="chip active" data-filter="all">All</button>
                <button type="button" class="chip" data-filter="attention">Need attention</button>
                <button type="button" class="chip" data-filter="missing">Missing work</button>
                <button type="button" class="chip" data-filter="inactive">Inactive 7+ days</button>
            </span>
            <span class="form-hint" id="peopleCount" style="margin:0;" aria-live="polite"></span>
        </div>
        <?php endif; ?>
        <?php foreach ($members as $m):
            $flag_att = $is_owner && (($m['percent'] !== null && $m['percent'] < lowGradePct()) || ($m['attendance'] !== null && $m['attendance'] < attLow()) || $m['missing']);
            $flag_off = $is_owner && (!$m['last_active'] || strtotime($m['last_active']) < strtotime('-7 days')); ?>
        <div class="person-row" data-search="<?php echo htmlspecialchars(mb_strtolower($m['name'] . ' ' . ($is_owner ? $m['email'] : ''))); ?>"
             data-attention="<?php echo $flag_att ? 1 : 0; ?>" data-missing="<?php echo $is_owner && $m['missing'] ? 1 : 0; ?>" data-inactive="<?php echo $flag_off ? 1 : 0; ?>">
            <?php echo avatarHtml($m['id'], $m['name']); ?>
            <div style="flex:1;min-width:0;">
                <?php if ($is_owner): ?>
                <a class="person-link" href="student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo (int) $m['id']; ?>"><strong><?php echo htmlspecialchars($m['name']); ?></strong></a>
                <?php else: ?>
                <strong><?php echo htmlspecialchars($m['name']); ?><?php echo (int) $m['id'] === (int) $current_user_id ? ' (you)' : ''; ?></strong>
                <?php endif; ?>
                <?php if ($is_owner): ?>
                <div class="notif-meta"><?php echo htmlspecialchars($m['email']); ?> · joined <?php echo formatDate($m['joined_at'], 'M d, Y'); ?></div>
                <?php endif; ?>
            </div>
            <?php if ($is_owner): ?>
            <div class="person-stats">
                <span class="pstat<?php echo $m['percent'] !== null && $m['percent'] < lowGradePct() ? ' low' : ''; ?>" title="Overall grade (graded work)"><i class="fas fa-chart-line"></i> <?php echo $m['percent'] === null ? '—' : $m['percent'] . '%'; ?></span>
                <span class="pstat<?php echo $m['attendance'] !== null && $m['attendance'] < attLow() ? ' low' : ''; ?>" title="Attendance"><i class="fas fa-clipboard-check"></i> <?php echo $m['attendance'] === null ? '—' : (0 + $m['attendance']) . '%'; ?></span>
                <?php if ($m['missing']): ?><span class="pstat low" title="Past-due work not turned in"><i class="fas fa-exclamation-circle"></i> <?php echo $m['missing']; ?> missing</span><?php endif; ?>
                <span class="pstat muted" title="<?php echo $m['last_active'] ? 'Last active ' . formatDate($m['last_active'], 'M d, Y h:i A') : 'No activity in this class yet'; ?>"><i class="fas fa-history"></i> <?php echo $m['last_active'] ? timeAgo($m['last_active']) : 'No activity'; ?></span>
            </div>
            <?php endif; ?>
            <?php if ($is_owner): ?>
            <a class="icon-btn" href="<?php echo classUrl('messages', '&amp;c=class&amp;s=' . (int) $m['id']); ?>" title="Message <?php echo htmlspecialchars($m['name']); ?>" aria-label="Message <?php echo htmlspecialchars($m['name']); ?>"><i class="fas fa-comment-dots"></i></a>
            <button type="button" class="icon-btn danger remove-student" data-id="<?php echo (int) $m['id']; ?>"
                data-name="<?php echo htmlspecialchars($m['name']); ?>" title="Remove from class" aria-label="Remove <?php echo htmlspecialchars($m['name']); ?>">
                <i class="fas fa-user-minus"></i>
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <p class="form-hint" id="peopleNone" hidden style="padding:18px;text-align:center;margin:0;">No student matches.</p>
        <?php endif; ?>
    </section>

    <?php if (!$is_owner): ?>
    <form method="POST" class="leave-class" data-confirm="Leave <?php echo htmlspecialchars($class['class_name']); ?>?"
          data-confirm-text="You will lose access to its lectures, files and work. You can join again with the class code." data-confirm-button="Leave class">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="leave_class">
        <button type="submit" class="btn-app btn-sm btn-outline btn-danger-outline"><i class="fas fa-sign-out-alt"></i> Leave class</button>
    </form>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
