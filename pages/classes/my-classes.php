<?php
/**
 * My Classes — class cards (Google Classroom style), per designs/pages/myClasses.html
 * Each card: colour band (or class image), name, teacher / code, what needs
 * attention (to grade · due soon · missing), the next due item, and quick
 * links into the class hub (Stream, Classwork, People, Grades).
 * Teacher: archived classes listed below (restore from class settings).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

// Student cancels one of their own pending join requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_request' && isStudent()) {
    if (verifyCsrf()) {
        $db->delete('class_join_requests', "id = ? AND student_id = ? AND status = 'pending'", [(int) ($_POST['request_id'] ?? 0), $current_user_id]);
        Session::flash('classes_msg', ['success', 'Request cancelled', 'You can ask again with the class code.']);
    }
    redirect(APP_URL . '/pages/classes/my-classes.php');
}

$now      = date('Y-m-d H:i:s');
$in_week  = date('Y-m-d H:i:s', strtotime('+7 days'));

if (isTeacher()) {
    $classes = $db->fetchAll(
        "SELECT c.*,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS student_count,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id AND cm.joined_at >= ?) AS new_students,
                (SELECT COUNT(*) FROM submissions s INNER JOIN assignments a ON s.assignment_id = a.id
                  WHERE a.class_id = c.id AND s.status IN ('submitted', 'late')) AS to_grade,
                (SELECT COUNT(*) FROM class_join_requests r INNER JOIN users ru ON r.student_id = ru.id AND ru.is_active = 1
                  WHERE r.class_id = c.id AND r.status = 'pending') AS join_requests,
                (SELECT ct2.role FROM class_teachers ct2 WHERE ct2.class_id = c.id AND ct2.teacher_id = ?) AS my_role
         FROM classes c
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1
         ORDER BY c.created_at DESC",
        [date('Y-m-d H:i:s', strtotime('-7 days')), $current_user_id, $current_user_id]
    );
} else {
    $classes = $db->fetchAll(
        "SELECT c.*, u.name AS teacher_name,
                (SELECT COUNT(*) FROM class_members cm2 WHERE cm2.class_id = c.id) AS student_count,
                (SELECT COUNT(*) FROM assignments a WHERE a.class_id = c.id AND a.due_date BETWEEN ? AND ?
                   AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?)) AS due_soon,
                (SELECT COUNT(*) FROM assignments a WHERE a.class_id = c.id AND a.due_date < ?
                   AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?)) AS missing,
                (SELECT COUNT(*) FROM assignments a WHERE a.class_id = c.id) AS assignment_count,
                (SELECT COUNT(*) FROM submissions s INNER JOIN assignments a2 ON s.assignment_id = a2.id
                  WHERE a2.class_id = c.id AND s.student_id = ?) AS completed_count
         FROM classes c
         INNER JOIN class_members cm ON cm.class_id = c.id
         INNER JOIN users u ON c.teacher_id = u.id
         WHERE cm.student_id = ? AND c.is_active = 1
         ORDER BY cm.joined_at DESC",
        [$now, $in_week, $current_user_id, $now, $current_user_id, $current_user_id, $current_user_id]
    );
}

// Next due item per class (assignment or quiz); students: only work they have not done
foreach ($classes as &$c) {
    $next = $db->fetchOne(
        "SELECT * FROM (
            SELECT 'assignment' AS kind, a.id, a.title, a.due_date FROM assignments a
             WHERE a.class_id = ? AND a.due_date >= ?
               AND (? = 'teacher' OR NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?))
            UNION ALL
            SELECT 'quiz' AS kind, q.id, q.title, q.due_date FROM quizzes q
             WHERE q.class_id = ? AND q.due_date >= ?
               AND (? = 'teacher' OR NOT EXISTS (SELECT 1 FROM quiz_attempts t WHERE t.quiz_id = q.id AND t.student_id = ? AND t.submitted_at IS NOT NULL))
         ) x ORDER BY due_date LIMIT 1",
        [$c['id'], $now, $current_user_role, $current_user_id, $c['id'], $now, $current_user_role, $current_user_id]
    );
    $c['next_due'] = $next ?: null;
    $c['unread_msgs'] = unreadMessageCount($c['id'], $current_user_id, isTeacher());
}
unset($c);

// Student: classes they asked to join that are waiting for the teacher
$my_requests = isStudent() ? $db->fetchAll(
    // class name only until the teacher approves (no teacher, image or description)
    "SELECT r.id, r.created_at, c.class_name FROM class_join_requests r
     INNER JOIN classes c ON r.class_id = c.id AND c.is_active = 1
     WHERE r.student_id = ? AND r.status = 'pending' ORDER BY r.created_at DESC", [$current_user_id]) : [];

// Archived classes (teacher only) — restorable from class settings
$archived = isTeacher() ? $db->fetchAll(
    "SELECT id, class_name, class_code, updated_at FROM classes WHERE teacher_id = ? AND is_active = 0 ORDER BY updated_at DESC",
    [$current_user_id]) : [];

$task_result = Session::flash('classes_msg');
if (!$task_result && ($_GET['error'] ?? '') === 'not_found') {
    $task_result = ['error', 'Not available', 'That class does not exist, is archived, or you are not a member.'];
}

$page_title = 'My Classes';
$page_subtitle = isTeacher()
    ? 'Classes you teach — open one to post, give work and grade'
    : 'Classes you are enrolled in';
$active_menu = 'classes';

$page_script = "
    const search = document.getElementById('classSearch');
    if (search) search.addEventListener('input', function() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        document.querySelectorAll('.gc-card').forEach(function(card) {
            const hit = card.dataset.search.indexOf(q) !== -1;
            card.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        document.getElementById('noMatch').style.display = shown ? 'none' : 'block';
    });
    document.querySelectorAll('[data-copy]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            navigator.clipboard.writeText(el.dataset.copy).then(function() {
                Swal.fire({ icon: 'success', titleText: (el.dataset.copyLabel || 'Copied') + ' copied', timer: 1200, showConfirmButton: false });
            });
        });
    });";
if ($task_result) {
    $page_script .= "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<div class="page-toolbar">
    <?php if (count($classes) > 3): ?>
    <div class="search-box">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" id="classSearch" placeholder="Search your classes" aria-label="Search your classes">
    </div>
    <?php else: ?><span></span><?php endif; ?>
    <?php if (isTeacher()): ?>
    <a href="create.php" class="btn-app btn-sm"><i class="fas fa-plus"></i> Create Class</a>
    <?php else: ?>
    <a href="join.php" class="btn-app btn-sm"><i class="fas fa-sign-in-alt"></i> Join Class</a>
    <?php endif; ?>
</div>

<?php if ($my_requests): ?>
<div class="content-card pending-requests">
    <div class="card-head"><h2><i class="fas fa-hourglass-half"></i> Waiting for your teacher (<?php echo count($my_requests); ?>)</h2></div>
    <?php foreach ($my_requests as $r): ?>
    <div class="person-row">
        <span class="work-icon"><i class="fas fa-user-clock" aria-hidden="true"></i></span>
        <div style="flex:1;min-width:0;">
            <strong><?php echo htmlspecialchars($r['class_name']); ?></strong>
            <div class="notif-meta">Asked <?php echo timeAgo($r['created_at']); ?> — you will be notified when the teacher approves you</div>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="cancel_request">
            <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
            <button type="submit" class="link-btn" style="color:var(--danger);">Cancel request</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($classes)): ?>
<div class="empty-state onboarding">
    <i class="fas fa-chalkboard"></i>
    <h3><?php echo isTeacher() ? 'Create your first class' : 'Join your first class'; ?></h3>
    <p style="margin-top:6px;">
        <?php echo isTeacher()
            ? 'A class gets its own stream, classwork, gradebook and a join code to share with students.'
            : 'Ask your teacher for the class code or invite link — joining takes one step.'; ?>
    </p>
    <div style="margin-top:16px;">
        <?php if (isTeacher()): ?>
        <a href="create.php" class="btn-app"><i class="fas fa-plus"></i> Create Class</a>
        <?php else: ?>
        <a href="join.php" class="btn-app"><i class="fas fa-sign-in-alt"></i> Join a Class</a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="gc-grid">
    <?php foreach ($classes as $c):
        $cid = (int) $c['id'];
        $hub = 'stream.php?id=' . $cid;   // class pages: stream / classwork / people / grades
    ?>
    <article class="gc-card" data-search="<?php echo htmlspecialchars(mb_strtolower($c['class_name'] . ' ' . ($c['teacher_name'] ?? '') . ' ' . $c['class_code'] . ' ' . $c['tags'])); ?>">
        <a href="<?php echo $hub; ?>" class="gc-band" style="background: <?php echo classTheme($c); ?>;">
            <?php if (!empty($c['image'])): ?>
            <img src="<?php echo htmlspecialchars($c['image']); ?>" alt="" onerror="this.remove()">
            <?php endif; ?>
            <span class="gc-name"><?php echo htmlspecialchars($c['class_name']); ?></span>
            <span class="gc-sub">
                <?php if (isTeacher()): ?>
                <?php echo (int) $c['student_count']; ?> student<?php echo $c['student_count'] == 1 ? '' : 's'; ?><?php echo ($c['my_role'] ?? '') === 'co_teacher' ? ' · Co-teacher' : ''; ?>
                <?php else: ?>
                <?php echo htmlspecialchars($c['teacher_name']); ?>
                <?php endif; ?>
            </span>
        </a>
        <?php if (!isTeacher()): ?>
        <span class="gc-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($c['teacher_name'], 0, 1))); ?></span>
        <?php endif; ?>

        <div class="gc-body">
            <div class="gc-chips">
                <?php if ($c['unread_msgs']): ?>
                <a href="messages.php?id=<?php echo $cid; ?>" class="gc-chip info"><i class="fas fa-user-lock"></i> <?php echo (int) $c['unread_msgs']; ?> unread message<?php echo $c['unread_msgs'] == 1 ? '' : 's'; ?></a>
                <?php endif; ?>
                <?php if (isTeacher()): ?>
                <a href="grades.php?id=<?php echo $cid; ?>" class="gc-chip <?php echo $c['to_grade'] ? 'warn' : 'ok'; ?>">
                    <i class="fas fa-clipboard-list"></i> <?php echo $c['to_grade'] ? (int) $c['to_grade'] . ' to grade' : 'All graded'; ?>
                </a>
                <?php if ($c['join_requests']): ?>
                <a href="people.php?id=<?php echo $cid; ?>#requests" class="gc-chip warn"><i class="fas fa-user-clock"></i> <?php echo (int) $c['join_requests']; ?> join request<?php echo $c['join_requests'] == 1 ? '' : 's'; ?></a>
                <?php endif; ?>
                <?php if ($c['new_students']): ?>
                <a href="people.php?id=<?php echo $cid; ?>" class="gc-chip info"><i class="fas fa-user-plus"></i> <?php echo (int) $c['new_students']; ?> new this week</a>
                <?php endif; ?>
                <?php else: ?>
                <?php if ($c['missing']): ?>
                <a href="classwork.php?id=<?php echo $cid; ?>&amp;type=assignment" class="gc-chip danger"><i class="fas fa-exclamation-circle"></i> <?php echo (int) $c['missing']; ?> missing</a>
                <?php endif; ?>
                <?php if ($c['due_soon']): ?>
                <a href="classwork.php?id=<?php echo $cid; ?>&amp;type=assignment" class="gc-chip warn"><i class="fas fa-clock"></i> <?php echo (int) $c['due_soon']; ?> due this week</a>
                <?php endif; ?>
                <?php if (!$c['missing'] && !$c['due_soon']): ?>
                <span class="gc-chip ok"><i class="fas fa-check"></i> All caught up</span>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($c['next_due']): ?>
            <a class="gc-next" href="<?php echo $c['next_due']['kind'] === 'quiz' ? '../quizzes/view.php?id=' : '../assignments/view.php?id='; ?><?php echo (int) $c['next_due']['id']; ?>">
                <span class="form-hint" style="margin:0;">Due <?php echo formatDate($c['next_due']['due_date'], 'D, M d · h:i A'); ?></span>
                <span class="gc-next-title"><i class="fas <?php echo $c['next_due']['kind'] === 'quiz' ? 'fa-question-circle' : 'fa-tasks'; ?>"></i>
                    <?php echo htmlspecialchars($c['next_due']['title']); ?></span>
            </a>
            <?php else: ?>
            <p class="gc-next empty">No upcoming work</p>
            <?php endif; ?>

            <?php if (!isTeacher() && (int) $c['assignment_count'] > 0):
                $pct = round((int) $c['completed_count'] / (int) $c['assignment_count'] * 100); ?>
            <div class="gc-progress" title="<?php echo (int) $c['completed_count']; ?> of <?php echo (int) $c['assignment_count']; ?> assignments turned in">
                <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $pct; ?>%"></div></div>
                <span><?php echo $pct; ?>%</span>
            </div>
            <?php endif; ?>
        </div>

        <nav class="gc-actions" aria-label="<?php echo htmlspecialchars($c['class_name']); ?> sections">
            <a href="<?php echo $hub; ?>" title="Stream" aria-label="Stream"><i class="fas fa-stream"></i></a>
            <a href="classwork.php?id=<?php echo $cid; ?>" title="Classwork" aria-label="Classwork"><i class="fas fa-book-open"></i></a>
            <a href="people.php?id=<?php echo $cid; ?>" title="People" aria-label="People"><i class="fas fa-users"></i></a>
            <a href="grades.php?id=<?php echo $cid; ?>" title="Grades" aria-label="Grades"><i class="fas fa-chart-line"></i></a>
            <?php if (isTeacher()): ?>
            <button type="button" class="gc-code" data-copy="<?php echo htmlspecialchars($c['class_code']); ?>" data-copy-label="Class code" title="Copy class code">
                <i class="fas fa-key"></i> <?php echo htmlspecialchars($c['class_code']); ?>
            </button>
            <a href="edit.php?id=<?php echo $cid; ?>" title="Class settings" aria-label="Class settings"><i class="fas fa-cog"></i></a>
            <?php endif; ?>
        </nav>
    </article>
    <?php endforeach; ?>
</div>
<p id="noMatch" class="empty-state" style="display:none;">No classes match your search.</p>
<?php endif; ?>

<?php if ($archived): ?>
<details class="archived-classes">
    <summary><i class="fas fa-archive"></i> Archived classes (<?php echo count($archived); ?>)</summary>
    <?php foreach ($archived as $a): ?>
    <div class="settings-row">
        <div><strong><?php echo htmlspecialchars($a['class_name']); ?></strong>
            <span class="form-hint" style="margin:0;"> · archived <?php echo timeAgo($a['updated_at']); ?></span></div>
        <a href="edit.php?id=<?php echo (int) $a['id']; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-undo"></i> Open / restore</a>
    </div>
    <?php endforeach; ?>
</details>
<?php endif; ?>

<?php
// Classes of closed years: the record, readable but no longer editable (grouped by year)
$past = isTeacher()
    ? $db->fetchAll("SELECT c.id, c.class_name, y.name AS year_name, y.starts_on FROM classes c INNER JOIN class_teachers ct ON ct.class_id = c.id AND ct.teacher_id = ? LEFT JOIN academic_years y ON c.year_id = y.id WHERE c.is_active = 0 ORDER BY y.starts_on DESC, c.class_name", [$current_user_id])
    : $db->fetchAll("SELECT c.id, c.class_name, y.name AS year_name, y.starts_on FROM classes c INNER JOIN class_members cm ON cm.class_id = c.id AND cm.student_id = ? LEFT JOIN academic_years y ON c.year_id = y.id WHERE c.is_active = 0 ORDER BY y.starts_on DESC, c.class_name", [$current_user_id]);
if ($past):
    $by_year = [];
    foreach ($past as $pc) $by_year[$pc['year_name'] ?: 'Earlier'][] = $pc; ?>
<section class="content-card past-years">
    <div class="card-head"><h2><i class="fas fa-box-archive"></i> Previous years</h2><span class="form-hint" style="margin:0;">Kept as a record — you can read and print, not change</span></div>
    <?php foreach ($by_year as $yn => $list): ?>
    <div class="py-year"><strong><?php echo htmlspecialchars($yn); ?></strong>
        <span class="py-list"><?php foreach ($list as $pc): ?><a class="chip" href="<?php echo APP_URL; ?>/pages/classes/stream.php?id=<?php echo (int) $pc['id']; ?>"><?php echo htmlspecialchars($pc['class_name']); ?></a><?php endforeach; ?></span></div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
