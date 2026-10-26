<?php
/**
 * SmartClass - Single source of truth for the sidebar menu.
 * Used by the dashboard AND every module page (includes/app_header.php).
 *
 * An item with 'url' => null renders as "coming soon".
 * When a module ships, set its items' urls (relative to APP_URL).
 * A section with 'direct' => url is ONE link in the sidebar (no dropdown) — its "Add…" actions
 * live on that page and inside each class. Its items stay listed here for other uses.
 */

function appMenu() {
    return [
        ['id' => 'classes', 'direct' => 'pages/classes/my-classes.php', 'tooltip' => 'Class Management', 'icon' => 'fa-chalkboard-teacher', 'title' => 'Classes',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus',         'label' => 'Create Class', 'url' => 'pages/classes/create.php',     'roles' => ['teacher']],
            ['icon' => 'fa-sign-in-alt',  'label' => 'Join Class',   'url' => 'pages/classes/join.php',       'roles' => ['student']],
            ['icon' => 'fa-eye',          'label' => 'My Classes',   'url' => 'pages/classes/my-classes.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'students', 'tooltip' => 'Students', 'icon' => 'fa-user-graduate', 'title' => 'Students',
         'roles' => ['teacher'], 'items' => [
            ['icon' => 'fa-user-check', 'label' => 'Approve Students', 'url' => 'pages/students/approve.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',        'label' => 'View Students',    'url' => 'pages/students/viewStudents.php', 'roles' => ['teacher']],
        ]],
        ['id' => 'lectures', 'direct' => 'pages/lectures/view.php', 'tooltip' => 'Lectures', 'icon' => 'fa-video', 'title' => 'Lectures',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Add Lecture',   'url' => 'pages/lectures/add.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Lectures', 'url' => 'pages/lectures/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'resources', 'direct' => 'pages/resources/view.php', 'tooltip' => 'Resources', 'icon' => 'fa-folder', 'title' => 'Resources',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Add Resource',   'url' => 'pages/resources/upload.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Resources', 'url' => 'pages/resources/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'assignments', 'direct' => 'pages/assignments/view.php', 'tooltip' => 'Assignments', 'icon' => 'fa-tasks', 'title' => 'Assignments',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Create Assignment', 'url' => 'pages/assignments/create.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Assignments',  'url' => 'pages/assignments/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'quizzes', 'direct' => 'pages/quizzes/view.php', 'tooltip' => 'Quizzes', 'icon' => 'fa-question-circle', 'title' => 'Quizzes',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Create Quiz', 'url' => 'pages/quizzes/create.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Quizzes', 'url' => 'pages/quizzes/view.php', 'roles' => ['teacher', 'student']],
            ['icon' => 'fa-chart-bar', 'label' => 'Quiz Results', 'url' => 'pages/quizzes/results.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'exams', 'direct' => 'pages/exams/view.php', 'tooltip' => 'Exams', 'icon' => 'fa-file-alt', 'title' => 'Exams',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Create Exam', 'url' => 'pages/exams/create.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Exams',  'url' => 'pages/exams/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'marks', 'direct' => 'pages/marks/view.php', 'tooltip' => 'Marks', 'icon' => 'fa-chart-line', 'title' => 'Marks',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Add Marks',  'url' => 'pages/marks/add.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Marks', 'url' => 'pages/marks/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'attendance', 'direct' => 'pages/attendance/view.php', 'tooltip' => 'Attendance', 'icon' => 'fa-clipboard-check', 'title' => 'Attendance',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Mark Attendance', 'url' => 'pages/attendance/markAttendance.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Attendance', 'url' => 'pages/attendance/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'research', 'direct' => 'pages/research/projects.php', 'tooltip' => 'Research', 'icon' => 'fa-flask', 'title' => 'Research',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-diagram-project', 'label' => 'Research Projects', 'url' => 'pages/research/projects.php', 'roles' => ['teacher', 'student']],
            ['icon' => 'fa-plus', 'label' => 'Start a Project', 'url' => 'pages/research/start.php', 'roles' => ['student']],
            ['icon' => 'fa-plus', 'label' => 'Add Research',  'url' => 'pages/research/add.php', 'roles' => ['teacher']],
            ['icon' => 'fa-book-open',  'label' => 'Research Library', 'url' => 'pages/research/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'schedules', 'direct' => 'pages/schedules/view.php', 'tooltip' => 'Schedules', 'icon' => 'fa-calendar-alt', 'title' => 'Schedules',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Add to Timetable', 'url' => 'pages/schedules/create.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Timetable',  'url' => 'pages/schedules/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'tutor', 'direct' => 'pages/ai/tutor.php', 'tooltip' => 'AI Tutor', 'icon' => 'fa-robot', 'title' => 'AI Tutor',
         'roles' => ['student'], 'items' => [
            ['icon' => 'fa-comments', 'label' => 'Ask the tutor', 'url' => 'pages/ai/tutor.php', 'roles' => ['student']],
        ]],
        ['id' => 'safeguarding', 'direct' => 'pages/ai/safeguarding.php', 'tooltip' => 'Safeguarding', 'icon' => 'fa-hand-holding-heart', 'title' => 'Safeguarding',
         'roles' => ['teacher'], 'items' => [
            ['icon' => 'fa-hand-holding-heart', 'label' => 'Students who may need support', 'url' => 'pages/ai/safeguarding.php', 'roles' => ['teacher']],
        ]],
        ['id' => 'forums', 'direct' => 'pages/forums/view.php', 'tooltip' => 'Forums', 'icon' => 'fa-comments', 'title' => 'Forums',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'New Topic', 'url' => 'pages/forums/create.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Forums',  'url' => 'pages/forums/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'events', 'direct' => 'pages/events/view.php', 'tooltip' => 'Events', 'icon' => 'fa-calendar-check', 'title' => 'Events',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Create Event', 'url' => 'pages/events/create.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Events',  'url' => 'pages/events/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'announcements', 'direct' => 'pages/announcements/view.php', 'tooltip' => 'Announcements', 'icon' => 'fa-bullhorn', 'title' => 'Announcements',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-plus', 'label' => 'Create Announcement', 'url' => 'pages/announcements/create.php', 'roles' => ['teacher']],
            ['icon' => 'fa-eye',  'label' => 'View Announcements',  'url' => 'pages/announcements/view.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'customers', 'direct' => 'pages/customers/index.php', 'tooltip' => 'Customers', 'icon' => 'fa-building', 'title' => 'Customers',
         'roles' => ['teacher'], 'items' => [
            ['icon' => 'fa-building', 'label' => 'Customers', 'url' => 'pages/customers/index.php', 'roles' => ['teacher'], 'admin' => true, 'master' => true],
        ]],
        ['id' => 'reports', 'direct' => 'pages/reports/index.php', 'tooltip' => 'Reports', 'icon' => 'fa-chart-pie', 'title' => 'Reports',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-chart-pie', 'label' => 'Reports', 'url' => 'pages/reports/index.php', 'roles' => ['teacher', 'student']],
        ]],
        ['id' => 'notifications', 'direct' => 'pages/notifications/index.php', 'tooltip' => 'Notifications', 'icon' => 'fa-bell', 'title' => 'Notifications',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-eye', 'label' => 'View Notifications', 'url' => 'pages/notifications/index.php', 'roles' => ['teacher', 'student']],
        ]],
        // Settings is ONE link: its pages live in the Settings area's own navigation (includes/settings_nav.php)
        ['id' => 'settings', 'direct' => 'pages/settings/index.php', 'tooltip' => 'Settings', 'icon' => 'fa-cog', 'title' => 'Settings',
         'roles' => ['teacher', 'student'], 'items' => [
            ['icon' => 'fa-cog', 'label' => 'Settings', 'url' => 'pages/settings/index.php', 'roles' => ['teacher', 'student']],
        ]],
    ];
}

/** Items of a section visible to a role. */
function menuVisibleItems($section, $role) {
    return array_values(array_filter($section['items'], function ($i) use ($role) {
        return in_array($role, $i['roles']) && (empty($i['admin']) || (function_exists('isAdmin') && isAdmin()))
            && (empty($i['master']) || (defined('SC_IS_MASTER') && SC_IS_MASTER));   // 'master': only in the original system
    }));
}

/** A section is "live" for a role when at least one visible item has a URL. */
function menuSectionLive($section, $role) {
    foreach (menuVisibleItems($section, $role) as $i) {
        if (!empty($i['url'])) return true;
    }
    return false;
}
