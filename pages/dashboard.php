<?php
/**
 * Dashboard — role-based, all data live from the database (per myProject/main.html).
 * Uses the shared app layout (includes/app_header.php + app_footer.php),
 * so the sidebar/menu comes from includes/menu.php (single source of truth).
 *
 * Every number, trend line and list item is a real query scoped to the user:
 * teachers see their own classes, students only classes they are enrolled in.
 * Every list item links to the page where it can be acted on.
 */
require_once dirname(__DIR__) . '/includes/auth.php';

$role = $current_user_role;
$uid  = $current_user_id;
$now      = date('Y-m-d H:i:s');
$week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$in_48h   = date('Y-m-d H:i:s', strtotime('+48 hours'));

/** Single COUNT(*) AS c query. */
function dashCount($sql, $params) {
    global $db;
    return (int) ($db->fetchOne($sql, $params)['c'] ?? 0);
}

// ---------------------------------------------------------------
// Build roadmap (shown in the System Modules card)
// ---------------------------------------------------------------
$roadmap = [
    ['name' => 'Authentication & Accounts',       'icon' => 'fa-lock',               'status' => 'live', 'priority' => 'high'],
    ['name' => 'Dashboard',                        'icon' => 'fa-home',               'status' => 'live', 'priority' => 'high'],
    ['name' => 'Classes (create / join by code)',  'icon' => 'fa-chalkboard-teacher', 'status' => 'live', 'priority' => 'high'],
    ['name' => 'Student Approvals',                'icon' => 'fa-user-check',         'status' => 'live', 'priority' => 'high'],
    ['name' => 'Lectures',                         'icon' => 'fa-video',              'status' => 'live', 'priority' => 'high'],
    ['name' => 'Resources',                        'icon' => 'fa-folder-open',        'status' => 'live', 'priority' => 'high'],
    ['name' => 'Assignments & Grading',            'icon' => 'fa-tasks',              'status' => 'live', 'priority' => 'high'],
    ['name' => 'Attendance',                       'icon' => 'fa-clipboard-check',    'status' => 'live', 'priority' => 'medium'],
    ['name' => 'Notifications Center',             'icon' => 'fa-bell',               'status' => 'live', 'priority' => 'medium'],
    ['name' => 'Profile & Settings + Backup',      'icon' => 'fa-cog',                'status' => 'soon', 'priority' => 'medium'],
    ['name' => 'Reports',                          'icon' => 'fa-chart-bar',          'status' => 'soon', 'priority' => 'medium'],
    ['name' => 'Quizzes',                          'icon' => 'fa-question-circle',    'status' => 'live', 'priority' => 'planned'],
    ['name' => 'Research',                         'icon' => 'fa-flask',              'status' => 'soon', 'priority' => 'planned'],
];
$live_count = count(array_filter($roadmap, fn($m) => $m['status'] === 'live'));

$url = fn($path) => APP_URL . '/' . $path;

if ($role === 'teacher') {
    // ---------------------------------------------------------------
    // Teacher: stats with real trend lines
    // ---------------------------------------------------------------
    $classes_total = $db->count('classes', '' . teachesClassSql('classes') . ' AND is_active = 1', [$uid]);
    $classes_week  = $db->count('classes', '' . teachesClassSql('classes') . ' AND is_active = 1 AND created_at >= ?', [$uid, $week_ago]);

    $students_total = dashCount(
        "SELECT COUNT(DISTINCT cm.student_id) AS c FROM class_members cm
         INNER JOIN classes c ON cm.class_id = c.id WHERE " . teachesClassSql('c') . " AND c.is_active = 1", [$uid]);
    $joins_week = dashCount(
        "SELECT COUNT(*) AS c FROM class_members cm
         INNER JOIN classes c ON cm.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND cm.joined_at >= ?", [$uid, $week_ago]);

    // Waiting accounts this teacher may see (own requesters; super admin: everyone)
    $pending_list   = getPendingStudentsFor($uid, isAdmin());
    $pending_total  = count($pending_list);
    $pending_oldest = $pending_list ? min(array_column($pending_list, 'created_at')) : null;

    $lectures_total = dashCount(
        "SELECT COUNT(*) AS c FROM lectures l INNER JOIN classes c ON l.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1", [$uid]);
    $lectures_week = dashCount(
        "SELECT COUNT(*) AS c FROM lectures l INNER JOIN classes c ON l.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND l.created_at >= ?", [$uid, $week_ago]);

    $resources_total = dashCount(
        "SELECT COUNT(*) AS c FROM resources r INNER JOIN classes c ON r.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1", [$uid]);
    $downloads_total = dashCount(
        "SELECT COALESCE(SUM(r.download_count), 0) AS c FROM resources r INNER JOIN classes c ON r.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1", [$uid]);

    $to_grade = dashCount(
        "SELECT COUNT(*) AS c FROM submissions s
         INNER JOIN assignments a ON s.assignment_id = a.id
         INNER JOIN classes c ON a.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND s.status IN ('submitted', 'late')", [$uid]);
    $to_grade_late = dashCount(
        "SELECT COUNT(*) AS c FROM submissions s
         INNER JOIN assignments a ON s.assignment_id = a.id
         INNER JOIN classes c ON a.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND s.status = 'late'", [$uid]);

    $stats = [
        ['icon' => 'fa-chalkboard-teacher', 'label' => 'My Classes', 'value' => $classes_total,
         'change' => '+' . $classes_week . ' this week', 'url' => $url('pages/classes/my-classes.php')],
        ['icon' => 'fa-user-graduate', 'label' => 'Students', 'value' => $students_total,
         'change' => '+' . $joins_week . ' joined this week', 'url' => $url('pages/classes/my-classes.php')],
        ['icon' => 'fa-user-clock', 'label' => 'Pending Approvals', 'value' => $pending_total,
         'change' => $pending_oldest ? 'Oldest waiting since ' . timeAgo($pending_oldest) : 'All caught up',
         'warn' => $pending_total > 0, 'url' => $url('pages/students/approve.php')],
        ['icon' => 'fa-video', 'label' => 'Lectures', 'value' => $lectures_total,
         'change' => '+' . $lectures_week . ' this week', 'url' => $url('pages/lectures/view.php')],
        ['icon' => 'fa-folder-open', 'label' => 'Resources', 'value' => $resources_total,
         'change' => $downloads_total . ' downloads', 'url' => $url('pages/resources/view.php')],
        ['icon' => 'fa-clipboard-list', 'label' => 'To Grade', 'value' => $to_grade,
         'change' => $to_grade ? $to_grade_late . ' submitted late' : 'Nothing waiting',
         'warn' => $to_grade > 0, 'url' => $url('pages/assignments/view.php')],
    ];

    // ---------------------------------------------------------------
    // Teacher: recent activity (registrations, submissions, joins)
    // ---------------------------------------------------------------
    $activity = [];
    if (isAdmin()) {
        // Super admin: every new student account
        foreach ($db->fetchAll(
            "SELECT id, name, created_at, is_approved FROM users WHERE role = 'student' AND is_active = 1
             ORDER BY created_at DESC LIMIT 5") as $r) {
            $activity[] = ['icon' => 'fa-user-plus', 'class' => 'success',
                'text' => 'New student registered: ' . $r['name'] . ($r['is_approved'] ? '' : ' (awaiting approval)'),
                'time' => $r['created_at'], 'url' => $r['is_approved'] ? null : $url('pages/students/review.php?id=' . (int) $r['id'])];
        }
    } else {
        // Teacher: only students who asked to join one of their classes
        foreach ($db->fetchAll(
            "SELECT u.name, r.created_at, r.status, c.id AS class_id, c.class_name FROM class_join_requests r
             INNER JOIN users u ON r.student_id = u.id INNER JOIN classes c ON r.class_id = c.id
             WHERE " . teachesClassSql('c') . " AND c.is_active = 1 ORDER BY r.created_at DESC LIMIT 5", [$uid]) as $r) {
            $activity[] = ['icon' => 'fa-user-plus', 'class' => 'success',
                'text' => $r['name'] . ' asked to join ' . $r['class_name'] . ($r['status'] === 'pending' ? ' (waiting)' : ''),
                'time' => $r['created_at'], 'url' => $url('pages/classes/people.php?id=' . (int) $r['class_id'] . '#requests')];
        }
    }
    foreach ($db->fetchAll(
        "SELECT u.name, a.id, a.title, s.submitted_at, s.status FROM submissions s
         INNER JOIN assignments a ON s.assignment_id = a.id
         INNER JOIN classes c ON a.class_id = c.id
         INNER JOIN users u ON s.student_id = u.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 ORDER BY s.submitted_at DESC LIMIT 5", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-file-upload', 'class' => 'info',
            'text' => $r['name'] . ' submitted' . ($r['status'] === 'late' ? ' late' : '') . ': ' . $r['title'],
            'time' => $r['submitted_at'], 'url' => $url('pages/assignments/view.php?id=' . (int) $r['id'])];
    }
    foreach ($db->fetchAll(
        "SELECT u.name, q.id, q.title, a.score, a.max_score, a.submitted_at FROM quiz_attempts a
         INNER JOIN quizzes q ON a.quiz_id = q.id
         INNER JOIN classes c ON q.class_id = c.id
         INNER JOIN users u ON a.student_id = u.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND a.submitted_at IS NOT NULL
         ORDER BY a.submitted_at DESC LIMIT 5", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-question-circle', 'class' => 'warning',
            'text' => $r['name'] . ' completed quiz ' . $r['title'] . ' — ' . (0 + $r['score']) . '/' . (int) $r['max_score'],
            'time' => $r['submitted_at'], 'url' => $url('pages/quizzes/view.php?id=' . (int) $r['id'])];
    }
    foreach ($db->fetchAll(
        "SELECT u.name, c.id, c.class_name, cm.joined_at FROM class_members cm
         INNER JOIN classes c ON cm.class_id = c.id
         INNER JOIN users u ON cm.student_id = u.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 ORDER BY cm.joined_at DESC LIMIT 5", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-door-open', 'class' => 'primary',
            'text' => $r['name'] . ' joined ' . $r['class_name'],
            'time' => $r['joined_at'], 'url' => $url('pages/classes/stream.php?id=' . (int) $r['id'])];
    }

    // ---------------------------------------------------------------
    // Teacher: upcoming deadlines with submission progress
    // ---------------------------------------------------------------
    $deadlines = [];
    foreach ($db->fetchAll(
        "SELECT a.id, a.title, a.due_date, c.class_name,
                (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS submitted,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = a.class_id) AS students
         FROM assignments a
         INNER JOIN classes c ON a.class_id = c.id
         WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND a.due_date >= ?
         ORDER BY a.due_date ASC LIMIT 4", [$uid, $now]) as $d) {
        $deadlines[] = $d + ['note' => $d['submitted'] . '/' . $d['students'] . ' submitted'];
    }

    // ---------------------------------------------------------------
    // Teacher: second row — pending approvals + work to grade
    // ---------------------------------------------------------------
    $side_left = [
        'title' => 'Pending Approvals', 'icon' => 'fa-user-clock',
        'link' => $url('pages/students/approve.php'), 'link_label' => 'Review All',
        'empty' => 'No students are waiting for approval',
        'items' => array_map(fn($r) => [
            'icon' => 'fa-user-clock', 'class' => 'warning',
            'text' => $r['name'] . ' — ' . $r['email'], 'sub' => 'Registered ' . timeAgo($r['created_at']) . ' · tap to review',
            'url' => $url('pages/students/review.php?id=' . (int) $r['id']),
        ], array_slice($pending_list, 0, 5)),
    ];
    $side_right = [
        'title' => 'Needs Grading', 'icon' => 'fa-clipboard-list',
        'link' => $url('pages/assignments/view.php'), 'link_label' => 'All Assignments',
        'empty' => 'No submissions are waiting to be graded',
        'items' => array_map(fn($r) => [
            'icon' => 'fa-file-signature', 'class' => $r['status'] === 'late' ? 'warning' : 'info',
            'text' => $r['name'] . ' — ' . $r['title'],
            'sub' => $r['class_name'] . ' · submitted ' . ($r['status'] === 'late' ? 'late ' : '') . timeAgo($r['submitted_at']),
            'url' => $url('pages/assignments/view.php?id=' . (int) $r['id']),
        ], $db->fetchAll(
            "SELECT u.name, a.id, a.title, c.class_name, s.submitted_at, s.status FROM submissions s
             INNER JOIN assignments a ON s.assignment_id = a.id
             INNER JOIN classes c ON a.class_id = c.id
             INNER JOIN users u ON s.student_id = u.id
             WHERE " . teachesClassSql('c') . " AND c.is_active = 1 AND s.status IN ('submitted', 'late')
             ORDER BY s.submitted_at ASC LIMIT 5", [$uid])),
    ];
} else {
    // ---------------------------------------------------------------
    // Student: stats with real trend lines (enrolled, active classes only)
    // ---------------------------------------------------------------
    $mine = "INNER JOIN class_members cm ON cm.class_id = x.class_id AND cm.student_id = ?
             INNER JOIN classes c ON c.id = x.class_id AND c.is_active = 1";

    $classes_total = dashCount(
        "SELECT COUNT(*) AS c FROM class_members cm INNER JOIN classes c ON c.id = cm.class_id AND c.is_active = 1
         WHERE cm.student_id = ?", [$uid]);
    $classes_week = dashCount(
        "SELECT COUNT(*) AS c FROM class_members cm INNER JOIN classes c ON c.id = cm.class_id AND c.is_active = 1
         WHERE cm.student_id = ? AND cm.joined_at >= ?", [$uid, $week_ago]);

    $not_submitted = "NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = x.id AND s.student_id = ?)";
    $todo = dashCount(
        "SELECT COUNT(*) AS c FROM assignments x {$mine} WHERE x.due_date >= ? AND {$not_submitted}", [$uid, $now, $uid]);
    $due_soon = dashCount(
        "SELECT COUNT(*) AS c FROM assignments x {$mine} WHERE x.due_date BETWEEN ? AND ? AND {$not_submitted}", [$uid, $now, $in_48h, $uid]);
    $open_quizzes = dashCount(
        "SELECT COUNT(*) AS c FROM quizzes x {$mine}
         WHERE (x.due_date IS NULL OR x.due_date >= ?)
           AND NOT EXISTS (SELECT 1 FROM quiz_attempts qa WHERE qa.quiz_id = x.id AND qa.student_id = ? AND qa.submitted_at IS NOT NULL)",
        [$uid, $now, $uid]);
    $missing = dashCount(
        "SELECT COUNT(*) AS c FROM assignments x {$mine} WHERE x.due_date < ? AND {$not_submitted}", [$uid, $now, $uid]);

    $submitted_total = dashCount(
        "SELECT COUNT(*) AS c FROM submissions s
         INNER JOIN assignments x ON s.assignment_id = x.id {$mine} WHERE s.student_id = ?", [$uid, $uid]);
    $graded_total = dashCount(
        "SELECT COUNT(*) AS c FROM submissions s
         INNER JOIN assignments x ON s.assignment_id = x.id {$mine} WHERE s.student_id = ? AND s.status = 'graded' AND " . sqlResultsVisible('x'), [$uid, $uid]);

    // Students only count published lectures (no drafts / not-yet-scheduled ones)
    $lectures_total = dashCount("SELECT COUNT(*) AS c FROM lectures x {$mine} WHERE " . lectureVisibleSql('x'), [$uid]);
    $lectures_week  = dashCount("SELECT COUNT(*) AS c FROM lectures x {$mine} WHERE " . lectureVisibleSql('x') . " AND COALESCE(x.publish_at, x.created_at) >= ?", [$uid, $week_ago]);
    $resources_total = dashCount("SELECT COUNT(*) AS c FROM resources x {$mine}", [$uid]);
    $resources_week  = dashCount("SELECT COUNT(*) AS c FROM resources x {$mine} WHERE x.created_at >= ?", [$uid, $week_ago]);

    $stats = [
        ['icon' => 'fa-chalkboard-teacher', 'label' => 'Enrolled Classes', 'value' => $classes_total,
         'change' => '+' . $classes_week . ' this week', 'url' => $url('pages/classes/my-classes.php')],
        ['icon' => 'fa-clipboard-check', 'label' => 'To Do', 'value' => $todo + $open_quizzes,
         'change' => $todo . ' assignments (' . $due_soon . ' due in 48h) · ' . $open_quizzes . ' quizzes',
         'warn' => $due_soon > 0, 'url' => $url($open_quizzes && !$todo ? 'pages/quizzes/view.php' : 'pages/assignments/view.php')],
        ['icon' => 'fa-exclamation-circle', 'label' => 'Missing', 'value' => $missing,
         'change' => $missing ? 'Past due — you can still submit late' : 'Nothing missing',
         'warn' => $missing > 0, 'url' => $url('pages/assignments/view.php')],
        ['icon' => 'fa-check-circle', 'label' => 'Submitted', 'value' => $submitted_total,
         'change' => $graded_total . ' graded', 'url' => $url('pages/assignments/view.php')],
        ['icon' => 'fa-video', 'label' => 'Lectures', 'value' => $lectures_total,
         'change' => '+' . $lectures_week . ' new this week', 'url' => $url('pages/lectures/view.php')],
        ['icon' => 'fa-folder-open', 'label' => 'Resources', 'value' => $resources_total,
         'change' => '+' . $resources_week . ' new this week', 'url' => $url('pages/resources/view.php')],
    ];

    // ---------------------------------------------------------------
    // Student: recent activity (joins, new lectures/resources/assignments, grades)
    // ---------------------------------------------------------------
    $activity = [];
    foreach ($db->fetchAll(
        "SELECT c.id, c.class_name, cm.joined_at FROM class_members cm
         INNER JOIN classes c ON cm.class_id = c.id AND c.is_active = 1
         WHERE cm.student_id = ? ORDER BY cm.joined_at DESC LIMIT 3", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-door-open', 'class' => 'success', 'text' => 'You joined ' . $r['class_name'],
            'time' => $r['joined_at'], 'url' => $url('pages/classes/stream.php?id=' . (int) $r['id'])];
    }
    foreach ($db->fetchAll(
        "SELECT x.id, x.title, COALESCE(x.publish_at, x.created_at) AS created_at, c.class_name FROM lectures x {$mine}
         WHERE " . lectureVisibleSql('x') . " ORDER BY created_at DESC LIMIT 4", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-video', 'class' => 'primary', 'text' => 'New lecture in ' . $r['class_name'] . ': ' . $r['title'],
            'time' => $r['created_at'], 'url' => $url('pages/lectures/view.php?id=' . (int) $r['id'])];
    }
    foreach ($db->fetchAll(
        "SELECT x.class_id, x.title, x.created_at, c.class_name FROM resources x {$mine}
         ORDER BY x.created_at DESC LIMIT 4", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-folder-open', 'class' => 'info', 'text' => 'New resource in ' . $r['class_name'] . ': ' . $r['title'],
            'time' => $r['created_at'], 'url' => $url('pages/resources/view.php?class_id=' . (int) $r['class_id'])];
    }
    foreach ($db->fetchAll(
        "SELECT x.id, x.title, x.created_at, c.class_name FROM assignments x {$mine}
         ORDER BY x.created_at DESC LIMIT 4", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-tasks', 'class' => 'warning', 'text' => 'New assignment in ' . $r['class_name'] . ': ' . $r['title'],
            'time' => $r['created_at'], 'url' => $url('pages/assignments/view.php?id=' . (int) $r['id'])];
    }
    foreach ($db->fetchAll(
        "SELECT x.id, x.title, x.created_at, c.class_name FROM quizzes x {$mine}
         ORDER BY x.created_at DESC LIMIT 4", [$uid]) as $r) {
        $activity[] = ['icon' => 'fa-question-circle', 'class' => 'warning', 'text' => 'New quiz in ' . $r['class_name'] . ': ' . $r['title'],
            'time' => $r['created_at'], 'url' => $url('pages/quizzes/view.php?id=' . (int) $r['id'])];
    }
    foreach ($db->fetchAll(
        "SELECT x.id, x.title, x.max_marks, s.marks_obtained, s.graded_at FROM submissions s
         INNER JOIN assignments x ON s.assignment_id = x.id {$mine}
         WHERE s.student_id = ? AND s.status = 'graded' AND " . sqlResultsVisible('x') . " ORDER BY s.graded_at DESC LIMIT 4", [$uid, $uid]) as $r) {
        $activity[] = ['icon' => 'fa-star', 'class' => 'success',
            'text' => 'Graded: ' . $r['title'] . ' — ' . (0 + $r['marks_obtained']) . '/' . (int) $r['max_marks'],
            'time' => $r['graded_at'], 'url' => $url('pages/assignments/view.php?id=' . (int) $r['id'])];
    }

    // ---------------------------------------------------------------
    // Student: upcoming deadlines with own status
    // ---------------------------------------------------------------
    $deadlines = [];
    foreach ($db->fetchAll(
        "SELECT x.id, x.title, x.due_date, c.class_name,
                (SELECT s.id FROM submissions s WHERE s.assignment_id = x.id AND s.student_id = ?) AS submission_id
         FROM assignments x {$mine}
         WHERE x.due_date >= ? ORDER BY x.due_date ASC LIMIT 4", [$uid, $uid, $now]) as $d) {
        $deadlines[] = $d + ['note' => $d['submission_id'] ? 'Submitted' : 'Not submitted yet'];
    }

    // ---------------------------------------------------------------
    // Student: second row — recent grades + newest resources
    // ---------------------------------------------------------------
    $side_left = [
        'title' => 'Recent Grades', 'icon' => 'fa-star',
        'link' => $url('pages/assignments/view.php'), 'link_label' => 'All Assignments',
        'empty' => 'No graded work yet',
        'items' => array_map(fn($r) => [
            'icon' => 'fa-star', 'class' => 'success',
            'text' => $r['title'] . ' — ' . (0 + $r['marks_obtained']) . '/' . (int) $r['max_marks'],
            'sub' => $r['class_name'] . ' · graded ' . timeAgo($r['graded_at']),
            'url' => $url('pages/assignments/view.php?id=' . (int) $r['id']),
        ], $db->fetchAll(
            "SELECT x.id, x.title, x.max_marks, c.class_name, s.marks_obtained, s.graded_at FROM submissions s
             INNER JOIN assignments x ON s.assignment_id = x.id {$mine}
             WHERE s.student_id = ? AND s.status = 'graded' AND " . sqlResultsVisible('x') . " ORDER BY s.graded_at DESC LIMIT 5", [$uid, $uid])),
    ];
    $side_right = [
        'title' => 'New Resources', 'icon' => 'fa-folder-open',
        'link' => $url('pages/resources/view.php'), 'link_label' => 'All Resources',
        'empty' => 'Your teachers have not shared any files yet',
        'items' => array_map(fn($r) => [
            'icon' => 'fa-file-download', 'class' => 'info',
            'text' => $r['title'], 'sub' => $r['class_name'] . ' · ' . formatBytes($r['file_size']) . ' · ' . timeAgo($r['created_at']),
            'url' => $url('pages/resources/download.php?id=' . (int) $r['id']),
        ], $db->fetchAll(
            "SELECT x.id, x.title, x.file_size, x.created_at, c.class_name FROM resources x {$mine}
             ORDER BY x.created_at DESC LIMIT 5", [$uid])),
    ];
}

usort($activity, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
$activity = array_slice($activity, 0, 6);

$page = 'dashboard';
$page_title = 'Smart Class';
$page_subtitle = 'Welcome, ' . $current_user_name . ' — ' . ($role === 'teacher' ? 'Teacher' : 'Student') . ' workspace';
$active_menu = 'dashboard';
$hide_page_head = true;   // the greeting below is the page heading

// Student: "Plan my week" shows only when AI is allowed in at least one of their classes
$can_plan = false;
if ($role === 'student') {
    foreach (getUserClasses($current_user_id, 'student') as $pc) {
        if (aiAvailableFor('study', $current_user_id, (int) $pc['id'])) { $can_plan = true; break; }
    }
}
if ($can_plan) {
    $page_script = "
    (function () {
        const btn = document.getElementById('planBtn'), out = document.getElementById('planOut');
        if (!btn) return;
        btn.addEventListener('click', function () {
            const label = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Planning…';
            out.hidden = false; out.className = 'study-out'; out.textContent = 'The AI is looking at your open work…';
            fetch('" . APP_URL . "/pages/ai/plan.php', { method: 'POST', headers: { 'X-CSRF-Token': (document.querySelector('meta[name=csrf-token]') || {}).content || '' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (!d.ok) throw new Error(d.error || 'The AI could not make a plan.'); out.textContent = d.text; btn.innerHTML = '<i class=\"fas fa-redo\"></i> Plan again'; })
                .catch(function (e) { out.classList.add('is-error'); out.textContent = e.message; btn.innerHTML = label; })
                .finally(function () { btn.disabled = false; });
        });
    })();";
}

// ---------------------------------------------------------------
// Extra data for the dashboard layout: greeting, today's timetable, what is coming up, my classes, what needs attention
// ---------------------------------------------------------------
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$parts = preg_split('/\s+/', trim($current_user_name));
$short_name = (in_array(rtrim(strtolower($parts[0]), '.'), ['mr', 'mrs', 'ms', 'dr', 'prof'], true) && isset($parts[1])) ? $parts[0] . ' ' . $parts[1] : $parts[0];
$scope = $role === 'student'
    ? "EXISTS (SELECT 1 FROM class_members cm0 WHERE cm0.class_id = c.id AND cm0.student_id = ?)"
    : "EXISTS (SELECT 1 FROM class_teachers ct0 WHERE ct0.class_id = c.id AND ct0.teacher_id = ?)";

$today_slots = [];
$coming = [];
$my_classes = [];
try {
    $today_slots = $db->fetchAll("SELECT s.start_time, s.end_time, s.room, s.note, c.id AS class_id, c.class_name FROM class_schedule s INNER JOIN classes c ON s.class_id = c.id
                                  WHERE s.day_of_week = ? AND c.is_active = 1 AND {$scope} ORDER BY s.start_time", [(int) date('N'), $uid]);
    foreach ($db->fetchAll("SELECT e.id, e.title, e.starts_at AS at, e.location, c.id AS class_id, c.class_name FROM class_events e INNER JOIN classes c ON e.class_id = c.id
                            WHERE e.starts_at >= ? AND e.starts_at <= DATE_ADD(?, INTERVAL 14 DAY) AND c.is_active = 1 AND {$scope} ORDER BY e.starts_at LIMIT 5", [$now, $now, $uid]) as $r) {
        $coming[] = ['kind' => 'Event', 'icon' => 'fa-calendar-check', 'title' => $r['title'], 'sub' => $r['class_name'] . ($r['location'] ? ' · ' . $r['location'] : ''), 'at' => $r['at'], 'url' => $url('pages/events/view.php?class_id=' . (int) $r['class_id'])];
    }
    foreach ($db->fetchAll("SELECT x.id, x.title, x.exam_date AS at, x.venue, c.id AS class_id, c.class_name FROM exams x INNER JOIN classes c ON x.class_id = c.id
                            WHERE x.exam_date >= ? AND x.exam_date <= DATE_ADD(?, INTERVAL 30 DAY) AND c.is_active = 1 AND {$scope} ORDER BY x.exam_date LIMIT 5", [$now, $now, $uid]) as $r) {
        $coming[] = ['kind' => 'Exam', 'icon' => 'fa-file-alt', 'title' => $r['title'], 'sub' => $r['class_name'] . ($r['venue'] ? ' · ' . $r['venue'] : ''), 'at' => $r['at'], 'url' => $url('pages/exams/view.php?class_id=' . (int) $r['class_id'])];
    }
    foreach ($deadlines as $d) {
        $coming[] = ['kind' => 'Due', 'icon' => 'fa-tasks', 'title' => $d['title'], 'sub' => $d['class_name'] . ' · ' . $d['note'], 'at' => $d['due_date'], 'url' => $url('pages/assignments/view.php?id=' . (int) $d['id'])];
    }
    foreach (researchDueSoon($uid, $current_user_role, 14, 4) as $r) {
        $coming[] = ['kind' => 'Research', 'icon' => 'fa-flask', 'title' => $r['mtitle'], 'sub' => $r['ptitle'] . (isTeacher() ? ' · ' . $r['student_name'] : ''), 'at' => $r['due_date'], 'url' => $url('pages/research/project.php?id=' . (int) $r['pid'])];
    }
    usort($coming, fn($a, $b) => strtotime($a['at']) - strtotime($b['at']));
    $coming = array_slice($coming, 0, 6);
    $my_classes = $db->fetchAll("SELECT c.id, c.class_name, c.theme, (SELECT COUNT(*) FROM class_members m WHERE m.class_id = c.id) AS members FROM classes c
                                 WHERE c.is_active = 1 AND {$scope} ORDER BY c.created_at DESC LIMIT 6", [$uid]);
} catch (Throwable $e) {
    error_log('SmartClass: dashboard extras: ' . $e->getMessage());
}

// "Needs attention": the few things that are waiting for this person, each with a count and a link
$attention = [];
$research_wait = 0; $research_late = 0; try { $research_wait = researchWaiting($uid, $current_user_role); $research_late = researchOverdueCount($uid, $current_user_role); } catch (Throwable $e) { /* research tables not migrated yet */ }
if ($role === 'teacher') {
    $join_requests = dashCount("SELECT COUNT(*) AS c FROM class_join_requests r INNER JOIN classes c ON r.class_id = c.id WHERE r.status = 'pending' AND c.is_active = 1 AND " . teachesClassSql('c'), [$uid]);
    // Classes whose attendance has not been taken today (not on a weekly day off or a no-class day)
    $att_open = [];
    if (!in_array((int) date('N'), attendanceOffWeekdays(), true)) {
        $att_open = $db->fetchAll(
            "SELECT c.id FROM classes c WHERE c.is_active = 1 AND " . teachesClassSql('c') . "
               AND EXISTS (SELECT 1 FROM class_members cm WHERE cm.class_id = c.id)
               AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.class_id = c.id AND a.date = CURDATE())
               AND NOT EXISTS (SELECT 1 FROM attendance_days d WHERE d.class_id = c.id AND d.date = CURDATE())", [$uid]);
    }
    $attention = [
        ['icon' => 'fa-clipboard-check', 'label' => 'Classes without attendance today', 'count' => count($att_open),
         'url' => count($att_open) === 1 ? $url('pages/attendance/class.php?id=' . (int) $att_open[0]['id']) : $url('pages/attendance/markAttendance.php')],
        ['icon' => 'fa-user-clock', 'label' => 'Students waiting for approval', 'count' => $pending_total, 'url' => $url('pages/students/approve.php')],
        ['icon' => 'fa-user-plus', 'label' => 'Requests to join your classes', 'count' => $join_requests, 'url' => $url('pages/classes/my-classes.php')],
        ['icon' => 'fa-clipboard-list', 'label' => 'Submissions to grade', 'count' => $to_grade, 'url' => $url('pages/assignments/view.php')],
        ['icon' => 'fa-flask', 'label' => 'Research projects waiting for you', 'count' => $research_wait, 'url' => $url('pages/research/projects.php')],
        ['icon' => 'fa-hourglass-end', 'label' => 'Overdue research milestones', 'count' => $research_late, 'url' => $url('pages/research/projects.php?status=active')],
    ];
} else {
    $low_att = (int) ($db->fetchOne(
        "SELECT COUNT(*) AS c FROM (SELECT a.class_id FROM attendance a INNER JOIN classes c ON a.class_id = c.id
          WHERE a.student_id = ? AND c.is_active = 1 GROUP BY a.class_id HAVING AVG(" . attendedSql('a.status') . ") < ? / 100) x", [$uid, attLow()])['c'] ?? 0);
    $attention = [
        ['icon' => 'fa-user-times', 'label' => 'Classes where your attendance is below ' . attLow() . '%', 'count' => $low_att, 'url' => $url('pages/attendance/view.php')],
        ['icon' => 'fa-hourglass-half', 'label' => 'Assignments due in the next 48 hours', 'count' => $due_soon, 'url' => $url('pages/assignments/view.php')],
        ['icon' => 'fa-exclamation-circle', 'label' => 'Past-due work not handed in', 'count' => $missing, 'url' => $url('pages/assignments/view.php')],
        ['icon' => 'fa-question-circle', 'label' => 'Quizzes you can still take', 'count' => $open_quizzes, 'url' => $url('pages/quizzes/view.php')],
        ['icon' => 'fa-flask', 'label' => 'Research projects that need your action', 'count' => $research_wait, 'url' => $url('pages/research/projects.php')],
        ['icon' => 'fa-hourglass-end', 'label' => 'Overdue research milestones', 'count' => $research_late, 'url' => $url('pages/research/projects.php?status=active')],
    ];
}
$quick = $role === 'teacher'
    ? [['fa-plus', 'New class', 'pages/classes/create.php'], ['fa-clipboard-check', 'Attendance', 'pages/attendance/markAttendance.php'], ['fa-tasks', 'New assignment', 'pages/assignments/create.php'],
       ['fa-question-circle', 'New quiz', 'pages/quizzes/create.php'], ['fa-bullhorn', 'Announce', 'pages/announcements/create.php']]
    : [['fa-sign-in-alt', 'Join a class', 'pages/classes/join.php'], ['fa-tasks', 'Assignments', 'pages/assignments/view.php'], ['fa-question-circle', 'Quizzes', 'pages/quizzes/view.php'],
       ['fa-chart-pie', 'My report', 'pages/reports/index.php']];
$kpi_tone = ['fa-chalkboard-teacher' => 'indigo', 'fa-user-graduate' => 'teal', 'fa-user-clock' => 'amber', 'fa-video' => 'violet', 'fa-folder-open' => 'sky', 'fa-clipboard-list' => 'amber',
             'fa-clipboard-check' => 'amber', 'fa-exclamation-circle' => 'rose', 'fa-check-circle' => 'teal'];

$first_run = $role === 'teacher' ? teacherFirstRun($uid) : null;

include dirname(__DIR__) . '/includes/app_header.php';
?>

<!-- Greeting + quick actions -->
<section class="dash-hero">
    <div>
        <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($short_name); ?></h1>
        <p><?php echo date('l, F j'); ?> · <?php echo $role === 'teacher' ? (isAdmin() ? 'Teacher &amp; school admin' : 'Teacher') : 'Student'; ?></p>
    </div>
    <div class="dash-quick">
        <?php foreach ($quick as [$qi, $ql, $qu]): ?><a class="btn-app btn-sm btn-outline" href="<?php echo $url($qu); ?>"><i class="fas <?php echo $qi; ?>"></i> <?php echo $ql; ?></a><?php endforeach; ?>
    </div>
</section>

<?php $todo = array_values(array_filter($attention ?? [], fn($a) => (int) $a['count'] > 0)); ?>
<section class="dash-now<?php echo $todo ? '' : ' clear'; ?>" aria-label="What needs you now">
    <?php if (!$todo): ?>
    <span class="dn-ico ok"><i class="fas fa-circle-check" aria-hidden="true"></i></span><span class="dn-text"><strong>You are all caught up</strong> <span>Nothing needs you right now.</span></span>
    <?php else: ?>
    <span class="dn-ico"><i class="fas fa-bolt" aria-hidden="true"></i></span>
    <span class="dn-text"><strong>Needs you now</strong>
        <?php foreach (array_slice($todo, 0, 3) as $ti => $ta): ?><a class="dn-chip" href="<?php echo $url($ta['url']); ?>"><b><?php echo (int) $ta['count']; ?></b> <span><?php echo htmlspecialchars($ta['label']); ?></span></a><?php endforeach; ?>
        <?php if (count($todo) > 3): ?><span class="form-hint" style="margin:0;">+<?php echo count($todo) - 3; ?> <span>more below</span></span><?php endif; ?></span>
    <a class="btn-app btn-sm dn-go" href="<?php echo $url($todo[0]['url']); ?>">Start here <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    <?php endif; ?>
</section>

<?php if ($first_run && !$first_run['finished']): ?>
<!-- First-run guide: shown until the three steps really exist -->
<div class="content-card firstrun-card" id="firstRun">
    <div class="card-head"><h2><i class="fas fa-flag-checkered"></i> Getting started</h2>
        <span class="form-hint" style="margin:0;"><?php echo (int) $first_run['done']; ?> of <?php echo (int) $first_run['total']; ?> done</span></div>
    <div class="card-inner">
        <div class="firstrun-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int) $first_run['total']; ?>" aria-valuenow="<?php echo (int) $first_run['done']; ?>"><i style="width:<?php echo round($first_run['done'] / $first_run['total'] * 100); ?>%"></i></div>
        <ol class="firstrun-steps">
            <?php $next_found = false; foreach ($first_run['steps'] as $st): $is_next = !$st['done'] && !$next_found; if ($is_next) $next_found = true; ?>
            <li class="<?php echo $st['done'] ? 'is-done' : ($is_next ? 'is-next' : ''); ?>">
                <span class="fr-mark" aria-hidden="true"><i class="fas <?php echo $st['done'] ? 'fa-check' : $st['icon']; ?>"></i></span>
                <span class="fr-text"><strong><?php echo htmlspecialchars($st['title']); ?></strong><small><?php echo htmlspecialchars($st['text']); ?></small></span>
                <?php if ($st['done']): ?><span class="fr-state">Done</span><?php elseif ($is_next): ?><a class="btn-app btn-sm" href="<?php echo $url($st['url']); ?>"><?php echo htmlspecialchars($st['cta']); ?></a><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php if ($can_plan): ?>
<div class="content-card plan-card">
    <div class="card-head"><h2><i class="fas fa-robot"></i> Plan my week</h2>
        <button type="button" class="btn-app btn-sm" id="planBtn"><i class="fas fa-magic"></i> Make a plan</button></div>
    <div class="card-inner">
        <p class="form-hint" style="margin:0;">The AI looks at your open assignments and quizzes and suggests what to do on which day. Only titles, class names and due dates are sent.</p>
        <div class="study-out" id="planOut" role="status" hidden></div>
    </div>
</div>
<?php endif; ?>

<!-- Key numbers -->
<section class="dash-kpis" aria-label="Key numbers">
    <?php foreach ($stats as $stat): $tone = $kpi_tone[$stat['icon']] ?? 'indigo'; ?>
    <a class="dash-kpi tone-<?php echo $tone; ?>" href="<?php echo htmlspecialchars($stat['url']); ?>">
        <span class="kpi-icon"><i class="fas <?php echo $stat['icon']; ?>" aria-hidden="true"></i></span>
        <span class="kpi-label"><?php echo htmlspecialchars($stat['label']); ?></span>
        <span class="kpi-value"><?php echo number_format($stat['value']); ?></span>
        <span class="kpi-note<?php echo !empty($stat['warn']) ? ' warn' : ''; ?>"><?php echo htmlspecialchars($stat['change']); ?></span>
    </a>
    <?php endforeach; ?>
</section>

<!-- Row 1: what needs me / today / coming up -->
<div class="dash-row three">
    <div class="content-card">
        <div class="card-head"><h2><i class="fas fa-bell"></i> Needs your attention</h2></div>
        <div class="attn-list">
            <?php foreach ($attention as $a): ?>
            <a class="attn-row" href="<?php echo htmlspecialchars($a['url']); ?>">
                <span class="attn-icon<?php echo $a['count'] ? ' on' : ''; ?>"><i class="fas <?php echo $a['icon']; ?>"></i></span>
                <span class="attn-label"><?php echo htmlspecialchars($a['label']); ?></span>
                <span class="attn-count<?php echo $a['count'] ? ' on' : ''; ?>"><?php echo $a['count'] ? (int) $a['count'] : '<i class="fas fa-check"></i>'; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="content-card">
        <div class="card-head"><h2><i class="fas fa-clock"></i> Today</h2><a href="<?php echo $url('pages/schedules/view.php'); ?>" class="view-all">Timetable</a></div>
        <div class="feed">
            <?php if (!$today_slots): ?>
            <div class="feed-empty"><i class="fas fa-mug-hot"></i> No lessons scheduled for <?php echo date('l'); ?></div>
            <?php else: foreach (array_slice($today_slots, 0, 5) as $sl): $live = date('H:i:s') >= $sl['start_time'] && date('H:i:s') < $sl['end_time']; ?>
            <a class="feed-row<?php echo $live ? ' is-live' : ''; ?>" href="<?php echo $url('pages/classes/' . ($role === 'teacher' && date('H:i:s') >= $sl['start_time'] ? 'attendance' : 'stream') . '.php?id=' . (int) $sl['class_id']); ?>">
                <span class="slot-time"><?php echo date('g:i', strtotime($sl['start_time'])); ?><small><?php echo date('A', strtotime($sl['start_time'])); ?></small></span>
                <span class="feed-text"><strong><?php echo htmlspecialchars($sl['class_name']); ?></strong>
                    <small><?php echo date('g:i A', strtotime($sl['start_time'])) . ' – ' . date('g:i A', strtotime($sl['end_time'])) . ($sl['room'] ? ' · ' . htmlspecialchars($sl['room']) : ''); ?><?php echo $live ? ' · <b>Now</b>' : ''; ?><?php echo ($role === 'teacher' && date('H:i:s') >= $sl['start_time']) ? ' · take attendance' : ''; ?></small></span>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="content-card">
        <div class="card-head"><h2><i class="fas fa-calendar-alt"></i> Coming up</h2></div>
        <div class="feed">
            <?php if (!$coming): ?>
            <div class="feed-empty"><i class="fas fa-calendar-check"></i> Nothing due or scheduled soon</div>
            <?php else: foreach (array_slice($coming, 0, 4) as $c): $t = strtotime($c['at']); $soon = ($t - time()) < 172800; ?>
            <a class="feed-row" href="<?php echo htmlspecialchars($c['url']); ?>">
                <span class="date-chip<?php echo $soon ? ' soon' : ''; ?>"><b><?php echo date('j', $t); ?></b><small><?php echo date('M', $t); ?></small></span>
                <span class="feed-text"><strong><?php echo htmlspecialchars($c['title']); ?></strong>
                    <small><span class="kind-tag"><?php echo $c['kind']; ?></span> <?php echo date('D g:i A', $t); ?> · <?php echo htmlspecialchars($c['sub']); ?></small></span>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Row 2: the two role panels (teacher: approvals + grading, student: grades + resources) -->
<div class="dash-row two">
    <?php foreach ([$side_left, $side_right] as $panel): ?>
    <div class="content-card">
        <div class="card-head"><h2><i class="fas <?php echo $panel['icon']; ?>"></i> <?php echo htmlspecialchars($panel['title']); ?></h2>
            <a href="<?php echo htmlspecialchars($panel['link']); ?>" class="view-all"><?php echo htmlspecialchars($panel['link_label']); ?></a></div>
        <div class="feed">
            <?php if (empty($panel['items'])): ?>
            <div class="feed-empty"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($panel['empty']); ?></div>
            <?php else: foreach (array_slice($panel['items'], 0, 4) as $it): ?>
            <a class="feed-row" href="<?php echo htmlspecialchars($it['url']); ?>">
                <span class="feed-icon tone-<?php echo htmlspecialchars($it['class']); ?>"><i class="fas <?php echo $it['icon']; ?>"></i></span>
                <span class="feed-text"><strong><?php echo htmlspecialchars($it['text']); ?></strong><small><?php echo htmlspecialchars($it['sub']); ?></small></span>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Row 3: recent activity + my classes -->
<div class="dash-row two">
    <div class="content-card">
        <div class="card-head"><h2><i class="fas fa-history"></i> Recent activity</h2></div>
        <div class="feed">
            <?php if (empty($activity)): ?>
            <div class="feed-empty"><i class="fas fa-info-circle"></i> Activity will appear here as the system is used</div>
            <?php else: foreach (array_slice($activity, 0, 5) as $a): $tag = $a['url'] ? 'a' : 'div'; ?>
            <<?php echo $tag; ?> class="feed-row"<?php echo $a['url'] ? ' href="' . htmlspecialchars($a['url']) . '"' : ''; ?>>
                <span class="feed-icon tone-<?php echo htmlspecialchars($a['class']); ?>"><i class="fas <?php echo $a['icon']; ?>"></i></span>
                <span class="feed-text"><strong><?php echo htmlspecialchars($a['text']); ?></strong><small><?php echo timeAgo($a['time']); ?></small></span>
            </<?php echo $tag; ?>>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="content-card">
        <div class="card-head"><h2><i class="fas fa-chalkboard"></i> My classes</h2><a href="<?php echo $url('pages/classes/my-classes.php'); ?>" class="view-all">See all</a></div>
        <div class="feed">
            <?php if (!$my_classes): ?>
            <div class="feed-empty"><i class="fas fa-chalkboard"></i> <?php echo $role === 'teacher' ? 'Create your first class to get started' : 'Join a class with its code to get started'; ?></div>
            <?php else: foreach (array_slice($my_classes, 0, 5) as $mc): ?>
            <a class="feed-row" href="<?php echo $url('pages/classes/stream.php?id=' . (int) $mc['id']); ?>">
                <span class="class-dot" style="background: <?php echo classTheme($mc); ?>;"></span>
                <span class="feed-text"><strong><?php echo htmlspecialchars($mc['class_name']); ?></strong>
                    <small><?php echo (int) $mc['members']; ?> student<?php echo (int) $mc['members'] === 1 ? '' : 's'; ?></small></span>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/app_footer.php'; ?>
