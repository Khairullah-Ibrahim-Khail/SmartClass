<?php
/**
 * SmartClass - the Settings area: ONE list of every setting, grouped. Each is its own page; they all share the same
 * frame (a navigation column on the left, the page on the right — see settingsShellOpen()).
 *   settingsNav($role)   groups => [ ['label','url','icon','desc'] ] visible to the signed-in person
 * A page opts in with   $settings_page = true;   before including includes/app_header.php (app_footer closes the frame).
 */
function settingsNav($role) {
    $admin = function_exists('isAdmin') && isAdmin();
    $teacher = $role === 'teacher';
    $g = [];
    $g['Personal'] = [
        ['Profile', 'pages/settings/profile.php', 'fa-user', 'Your name, e-mail and photo'],
        ['Password & security', 'pages/settings/security.php', 'fa-lock', 'Change your password'],
        ['Two-step sign-in', 'pages/settings/two_factor.php', 'fa-shield-halved', 'An authenticator-app code on top of your password'],
        ['Appearance', 'pages/settings/appearance.php', 'fa-palette', 'Light or dark theme'],
        ['My AI', 'pages/settings/my_ai.php', 'fa-robot', 'Your own AI provider and key'],
    ];
    $comm = [
        ['Notifications', 'pages/settings/notifications.php', 'fa-bell', 'Which e-mails you get, and how often'],
        ['WhatsApp', 'pages/settings/whatsapp.php', 'fa-comment-dots', 'Your number, your own sender, the message texts and the school rules'],
    ];
    if ($teacher) $comm[] = ['WhatsApp numbers', 'pages/whatsapp/students.php', 'fa-address-book', 'Which students can receive WhatsApp messages, and who still needs a number'];
    if ($teacher && $admin) $comm[] = ['E-mail', 'pages/settings/email.php', 'fa-envelope', 'Mail server and which notifications are e-mailed'];
    $g['Communication'] = $comm;
    if ($teacher) {
        $g['Teaching'] = [
            ['My classes', 'pages/settings/classes.php', 'fa-chalkboard', 'Details, join approval, class code and archive — per class'],
            ['Grading scale', 'pages/settings/grading.php', 'fa-award', 'Grades (A+, A, B …), ranges, colours and GPA — per class'],
            ['Marking scheme', 'pages/settings/marking.php', 'fa-sliders-h', 'What the mid exam, final exam, activities, quizzes and assignments are worth'],
        ];
    }
    if ($teacher && $admin) {
        $g['School'] = [
            ['School profile', 'pages/settings/school.php', 'fa-school', 'Name, logo and colour'],
            ['Teachers', 'pages/teachers/index.php', 'fa-chalkboard-teacher', 'Invite teachers and manage accounts'],
            ['Academic years', 'pages/settings/years.php', 'fa-calendar-alt', 'Close a year and start the next one'],
        ];
        $rules = [
            ['At-risk limits', 'pages/settings/limits.php', 'fa-triangle-exclamation', 'When a student is flagged: low attendance, absences in a row, low grade'],
            ['Attendance rules', 'pages/settings/attendance.php', 'fa-clipboard-check', 'When days lock; weekly days off'],
            ['Default grading', 'pages/settings/grading_default.php', 'fa-star', 'The grading scale classes use until their teacher sets one'],
            ['Security rules', 'pages/settings/security_rules.php', 'fa-user-shield', 'HTTPS, two-step for administrators, password rules, sign-in limits'],
            ['Privacy & data', 'pages/settings/privacy.php', 'fa-user-lock', 'How long data is kept; download or remove a person'],
        ];
        if (defined('SC_IS_MASTER') && !SC_IS_MASTER) $rules[] = ['Service terms', 'pages/settings/terms.php', 'fa-file-contract', 'The terms you accepted'];
        $g['Rules & policies'] = $rules;
        $g['System'] = [
            ['AI providers', 'pages/settings/ai.php', 'fa-microchip', 'Providers, models and what each feature may use'],
            ['Code runner', 'pages/settings/code_runner.php', 'fa-play-circle', 'Which languages the Run button of a lecture can run'],
            ['System check', 'pages/settings/system.php', 'fa-stethoscope', 'Is everything installed, writable and in sync?'],
            ['Backup', 'pages/settings/backup.php', 'fa-database', 'Download and restore a backup'],
        ];
    }
    return $g;
}

/** Opening of the frame: a bar across the top — a tab per group, the pages of the open group as pills under it, and a search box that lists matches from every group (assets/js/settings-nav.js). Works without JavaScript: a tab is a link to its first page. */
function settingsShellOpen($role, $here) {
    $groups = settingsNav($role); $open = null;
    foreach ($groups as $g => $items) foreach ($items as $it) if ($it[1] === $here) $open = $g;
    if ($open === null) $open = (string) array_key_first($groups);
    echo '<div class="settings-shell"><nav class="settings-nav settings-top" aria-label="' . htmlspecialchars(t('Settings')) . '">';
    echo '<div class="st-row"><div class="st-tabs" role="tablist">';
    foreach ($groups as $g => $items) {
        echo '<a class="st-tab' . ($g === $open ? ' active' : '') . '" href="' . APP_URL . '/' . $items[0][1] . '"' . ($g === $open ? ' aria-current="true"' : '') . '>' . htmlspecialchars(t($g)) . '</a>';
    }
    echo '</div><div class="sn-find"><i class="fas fa-search" aria-hidden="true"></i><input type="search" id="snFind" placeholder="' . htmlspecialchars(t('Find a setting')) . '" aria-label="' . htmlspecialchars(t('Find a setting')) . '" autocomplete="off"><div class="sn-results" id="snResults" hidden></div></div></div>';
    echo '<div class="st-pills">';
    foreach ($groups[$open] as [$label, $url, $icon, $desc]) {
        $on = $url === $here;
        echo '<a class="sn-item' . ($on ? ' active' : '') . '" href="' . APP_URL . '/' . $url . '" title="' . htmlspecialchars(t($desc)) . '"' . ($on ? ' aria-current="page"' : '') . '><i class="fas ' . $icon . '" aria-hidden="true"></i><span>' . htmlspecialchars(t($label)) . '</span></a>';
    }
    echo '</div><div hidden id="snAll">';
    foreach ($groups as $g => $items) foreach ($items as [$label, $url, $icon, $desc]) {
        echo '<a href="' . APP_URL . '/' . $url . '" data-find="' . htmlspecialchars(mb_strtolower($label . ' ' . $desc . ' ' . $g)) . '" data-icon="' . $icon . '" data-group="' . htmlspecialchars(t($g)) . '" data-label="' . htmlspecialchars(t($label)) . '"></a>';
    }
    echo '</div></nav><div class="settings-main" id="settingsMain">';
}
