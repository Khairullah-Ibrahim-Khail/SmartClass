<?php
/**
 * View Students (teacher) — sidebar "Students › View Students".
 * Every student enrolled in the teacher's classes: search by name/email, filter
 * by class, and per class the overall grade, attendance and missing work, with a
 * link to the student's progress page in that class.
 * (Rebuilt: the old version used tables and an include path that do not exist.)
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/dashboard.php');
}

$classes = getUserClasses($current_user_id, 'teacher');
$class_ids = array_map('intval', array_column($classes, 'id'));
$class_names = array_column($classes, 'class_name', 'id');

$filter_class = (int) ($_GET['class_id'] ?? 0);
if ($filter_class && !in_array($filter_class, $class_ids, true)) {
    $filter_class = 0;   // not one of this teacher's classes
}
$search = trim((string) ($_GET['q'] ?? ''));

// Per-class gradebook + attendance, computed once per class
$stats = [];   // [class_id][student_id] => [percent, missing, attendance]
foreach ($filter_class ? [$filter_class] : $class_ids as $cid) {
    $book = getClassGradebook($cid);
    $att = getAttendanceSummary($cid);
    foreach ($book['rows'] as $r) {
        $stats[$cid][$r['id']] = [
            'percent' => $r['percent'],
            'missing' => count(array_filter($r['cells'], fn($c) => $c['state'] === 'missing')),
            'attendance' => $att[$r['id']]['rate'] ?? null,
        ];
    }
}

$students = [];
if ($class_ids) {
    $ids = $filter_class ? [$filter_class] : $class_ids;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $where = '';
    if ($search !== '') {
        $where = ' AND (u.name LIKE ? OR u.email LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($params, $like, $like);
    }
    foreach ($db->fetchAll(
        "SELECT u.id, u.name, u.email, u.last_login, cm.class_id, cm.joined_at
         FROM class_members cm
         INNER JOIN users u ON cm.student_id = u.id
         WHERE cm.class_id IN ({$in}){$where}
         ORDER BY u.name, cm.joined_at LIMIT 5000", $params) as $row) {
        $sid = (int) $row['id'];
        $students[$sid] = $students[$sid] ?? ['id' => $sid, 'name' => $row['name'], 'email' => $row['email'],
                                               'last_login' => $row['last_login'], 'classes' => []];
        $students[$sid]['classes'][] = ['id' => (int) $row['class_id'], 'joined' => $row['joined_at']]
            + ($stats[(int) $row['class_id']][$sid] ?? ['percent' => null, 'missing' => 0, 'attendance' => null]);
    }
}

// Pages: by student (each student may take several rows, one per class)
$total_students = count($students);
$per = pagerPerPage();
[$page_no, $pages, $offset] = pagerWindow($total_students, $per);
$students = array_slice($students, $offset, $per, true);

$pending = (int) $db->count('users', "role = 'student' AND is_approved = 0 AND is_active = 1");

$page_title = 'Students';
$page_subtitle = 'Everyone enrolled in your classes';
$active_menu = 'students';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<div class="page-toolbar">
    <form method="GET" class="filter-form" data-live data-no-ajax>
        <div class="search-box">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name or email" aria-label="Search students">
        </div>
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int) $c['id']; ?>" <?php echo $filter_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php echo pagerPerSelect($per, $total_students); ?>
        <noscript><button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-filter"></i> Apply</button></noscript>
        <?php if ($search !== '' || $filter_class): ?><a href="viewStudents.php" class="link-btn">Clear</a><?php endif; ?>
    </form>
    <?php if ($pending): ?>
    <a href="approve.php" class="btn-app btn-sm"><i class="fas fa-user-clock"></i> <?php echo $pending; ?> waiting for approval</a>
    <?php endif; ?>
</div>
<div data-live-results>

<?php if (!$classes): ?>
<div class="empty-state">
    <i class="fas fa-chalkboard"></i>
    <h3>You have no classes yet</h3>
    <p style="margin-top:6px;">Students appear here once they join one of your classes.</p>
</div>
<?php elseif (!$students): ?>
<div class="empty-state">
    <i class="fas fa-user-graduate"></i>
    <h3><?php echo $search !== '' ? 'No students match your search' : 'No students yet'; ?></h3>
    <p style="margin-top:6px;"><?php echo $search !== '' ? 'Try another name or email.' : 'Share a class code or invite link from a class People page.'; ?></p>
</div>
<?php else: ?>
<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-user-graduate"></i> <?php echo $total_students; ?> student<?php echo $total_students == 1 ? '' : 's'; ?></h2>
    </div>
    <div class="table-wrap">
        <table class="app-table roster-table">
            <thead>
                <tr><th>Student</th><th>Class</th><th>Overall grade</th><th>Attendance</th><th>Missing work</th><th>Last login</th></tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): $n = count($s['classes']); ?>
                <?php foreach ($s['classes'] as $i => $c): ?>
                <tr<?php echo $i > 0 ? ' class="roster-cont"' : ''; ?>>
                    <?php if ($i === 0): ?>
                    <td rowspan="<?php echo $n; ?>">
                        <div class="person-row" style="padding:0;border:none;">
                            <?php echo avatarHtml($s['id'], $s['name']); ?>
                            <div style="min-width:0;"><strong><?php echo htmlspecialchars($s['name']); ?></strong>
                                <div class="notif-meta"><?php echo htmlspecialchars($s['email']); ?></div></div>
                        </div>
                    </td>
                    <?php endif; ?>
                    <td><a class="person-link" href="<?php echo APP_URL; ?>/pages/classes/student.php?class_id=<?php echo $c['id']; ?>&amp;student_id=<?php echo $s['id']; ?>">
                        <?php echo htmlspecialchars($class_names[$c['id']] ?? ''); ?></a>
                        <div class="notif-meta">joined <?php echo formatDate($c['joined'], 'M d, Y'); ?></div></td>
                    <td><?php echo $c['percent'] === null ? '<span class="form-hint" style="margin:0;">—</span>' : '<span class="percentage">' . $c['percent'] . '%</span>'; ?></td>
                    <td><?php if ($c['attendance'] === null): ?><span class="form-hint" style="margin:0;">—</span>
                        <?php else: ?><span class="<?php echo $c['attendance'] < attLow() ? 'status-badge missing' : 'marks'; ?>"><?php echo $c['attendance']; ?>%</span><?php endif; ?></td>
                    <td><?php echo $c['missing'] ? '<span class="status-badge missing">' . $c['missing'] . ' missing</span>' : '—'; ?></td>
                    <?php if ($i === 0): ?>
                    <td rowspan="<?php echo $n; ?>"><?php echo $s['last_login'] ? timeAgo($s['last_login']) : 'never'; ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php echo pagerHtml($total_students, $page_no, $per); ?>
<?php endif; ?>
</div><!-- /data-live-results -->

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
