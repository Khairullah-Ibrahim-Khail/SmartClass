<?php
/**
 * SmartClass - shared top of every class page (pages/classes/{stream,classwork,calendar,people,grades,attendance,messages,insights}.php)
 *
 * Include right after includes/auth.php, before any output. It:
 *   - loads the class from ?id=N and checks access (owner teacher / enrolled student),
 *   - sets $class, $class_id, $is_owner, $student_count, $invite_link,
 *   - sets the shared layout options ($active_menu, $hide_page_head, $page_scripts),
 *   - provides classUrl(), classSections(), classTop() (banner + section tabs)
 *     and classResultScript() (SweetAlert2 task result).
 */

$class_id = (int) ($_GET['id'] ?? 0);
// Ownership (teacher) / enrollment (student) check
$GLOBALS['sc_view_archived'] = true;   // a past year's class opens read-only
$class = getAccessibleClass($class_id, $current_user_id, $current_user_role);
$GLOBALS['sc_view_archived'] = false;
if (!$class) {
    redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
}
$is_owner = $class['is_owner'];
$is_archived = !empty($class['is_archived']);
if ($is_archived && $_SERVER['REQUEST_METHOD'] === 'POST') {   // the record of a closed year cannot be changed
    Session::flash('class_msg', ['info', 'Read only', 'This class belongs to a closed academic year, so it cannot be changed.']);
    redirect(defined('ATT_MODULE') ? attendanceUrl($class['id']) : APP_URL . '/pages/classes/' . basename($_SERVER['SCRIPT_NAME']) . '?id=' . (int) $class['id']);
}
gradingUseClass($class_id);   // this class's grading scale (the teacher's), else the school's
$student_count = (int) $db->count('class_members', 'class_id = ?', [$class_id]);
$invite_link = APP_URL . '/pages/classes/join.php?code=' . rawurlencode($class['class_code']);
$unread_messages = unreadMessageCount($class_id, $current_user_id, $is_owner);
if (!$is_archived) publishDueAnnouncements($class_id);   // scheduled posts whose time has come notify the class once
if (!$is_archived) publishDueLectures($class_id);        // same for scheduled lectures

$active_menu = 'classes';
$hide_page_head = true;          // the class banner is the page heading
$page_scripts = ['assets/js/class.js'];
$page_subtitle = 'Class';

/** URL of a class page, e.g. classUrl('classwork', '&type=quiz'). */
function classUrl($page, $extra = '') {
    global $class_id;
    if ($page === 'attendance') return attendanceUrl($class_id, $extra);   // the Attendance module is the home of attendance (the class tab just opens it)
    return APP_URL . '/pages/classes/' . $page . '.php?id=' . $class_id . $extra;
}

/** Section pages shown as tabs: key => label. Insights is teacher-only. */
function classSections($is_owner) {
    $sections = ['stream' => 'Stream', 'classwork' => 'Classwork', 'calendar' => 'Calendar', 'people' => 'People', 'grades' => 'Grades', 'attendance' => 'Attendance', 'messages' => 'Messages'];
    if ($is_owner) {
        $sections['insights'] = 'Insights';
    }
    return $sections;
}

/** SweetAlert2 snippet for a [icon, title, text] task result (title/text shown as plain text). */
function classResultScript($result) {
    if (!$result) return '';
    return "
    Swal.fire({ icon: " . json_encode($result[0]) . ", titleText: " . json_encode($result[1]) . ",
                text: " . json_encode($result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($result[0] === 'success' ? ", timer: 1600, showConfirmButton: false" : "") . " });";
}

/** Class banner + section tabs. Call right after including app_header.php. */
function classTop($active) {
    global $class, $class_id, $is_owner, $student_count, $unread_messages, $current_user_id;
    $ov = getClassOverview($class, $current_user_id, $is_owner);
    $co_teachers = array_values(array_filter(getClassTeachers($class_id), fn($t) => $t['role'] === 'co_teacher'));
    global $db, $is_archived;
    $year_name = !empty($class['year_id']) ? ($db->fetchOne("SELECT name FROM academic_years WHERE id = ?", [$class['year_id']])['name'] ?? '') : '';
    ?>
<div class="class-banner" style="background: <?php echo classTheme($class); ?>;">
    <?php if (!empty($class['image'])): ?>
    <img src="<?php echo htmlspecialchars($class['image']); ?>" alt="" class="banner-img" onerror="this.remove()">
    <?php endif; ?>
    <div class="banner-body">
        <h1><?php echo htmlspecialchars($class['class_name']); ?></h1>
        <?php if (!empty($class['description'])): ?><p><?php echo htmlspecialchars($class['description']); ?></p><?php endif; ?>
        <div class="banner-meta">
            <span><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($class['teacher_name']); ?><?php if ($co_teachers): ?>
                <span class="co-count" title="<?php echo htmlspecialchars('Co-teachers: ' . implode(', ', array_column($co_teachers, 'name'))); ?>">+ <?php echo count($co_teachers); ?> co-teacher<?php echo count($co_teachers) == 1 ? '' : 's'; ?></span><?php endif; ?></span>
            <span><i class="fas fa-users"></i> <?php echo $student_count; ?> student<?php echo $student_count == 1 ? '' : 's'; ?></span>
            <?php if ($year_name !== ''): ?><span><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($year_name); ?></span><?php endif; ?>
            <?php if ($is_owner && !$is_archived): ?>
            <button type="button" class="banner-chip" data-copy="<?php echo htmlspecialchars($class['class_code']); ?>" data-copy-label="Class code" title="Copy class code">
                <i class="fas fa-key"></i> <?php echo htmlspecialchars($class['class_code']); ?> <i class="fas fa-copy"></i>
            </button>
            <?php endif; ?>
            <?php foreach (array_filter(array_map('trim', explode(',', (string) $class['tags']))) as $tag): ?>
            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($tag); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($is_owner && !$is_archived): ?>
    <a href="<?php echo APP_URL; ?>/pages/classes/edit.php?id=<?php echo $class_id; ?>" class="banner-settings" title="Class settings" aria-label="Class settings"><i class="fas fa-cog"></i></a>
    <?php endif; ?>
</div>
<?php if ($is_archived): ?>
<div class="arch-note" role="status"><i class="fas fa-box-archive"></i> <div><strong>Closed year<?php echo $year_name !== '' ? ' — ' . htmlspecialchars($year_name) : ''; ?>.</strong>
    This class is kept as a record: you can read it and print its reports, but it can no longer be changed.</div></div>
<?php endif; ?>

<nav class="class-tabs" aria-label="Class sections">
    <?php foreach (classSections($is_owner) as $key => $label): ?>
    <a href="<?php echo classUrl($key); ?>" class="<?php echo $active === $key ? 'active' : ''; ?>" <?php echo $active === $key ? 'aria-current="page"' : ''; ?>><?php echo $label; ?><?php
        if ($key === 'messages' && $unread_messages && $active !== 'messages'): ?> <span class="tab-badge" aria-label="<?php echo $unread_messages; ?> unread"><?php echo $unread_messages; ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>

<?php classOverviewStrip($ov); ?>
<?php
}

/** One tile of the overview strip (all values are numbers or pre-escaped by the caller). */
function overviewTile($href, $icon, $tone, $label, $value, $sub) {
    ?>
    <a class="ov-tile <?php echo $tone; ?>" href="<?php echo $href; ?>">
        <span class="ov-icon" aria-hidden="true"><i class="fas <?php echo $icon; ?>"></i></span>
        <span class="ov-text"><span class="ov-label"><?php echo $label; ?></span>
            <strong class="ov-value"><?php echo $value; ?></strong>
            <span class="ov-sub"><?php echo $sub; ?></span></span>
    </a>
    <?php
}

/** Overview strip under the tabs: the class at a glance, every tile links to its section. */
function classOverviewStrip($ov) {
    global $is_owner, $class_id;
    echo '<div class="class-overview" data-ajax-sync="overview" aria-label="Class overview">';
    if ($is_owner) {
        overviewTile(classUrl('people'), 'fa-users', $ov['requests'] ? 'warn' : 'blue', 'Students', $ov['students'],
            $ov['requests'] ? $ov['requests'] . ' join request' . ($ov['requests'] == 1 ? '' : 's') . ' waiting' : 'no join requests');
        overviewTile(classUrl('grades'), 'fa-chart-line', 'green', 'Class average',
            $ov['average'] === null ? '—' : $ov['average'] . '%', $ov['average'] === null ? 'nothing graded yet' : 'of graded work');
        $taken = $ov['marked_today'] > 0;
        overviewTile(classUrl('attendance'), 'fa-clipboard-check', $taken ? 'green' : 'warn', "Today's attendance",
            $taken ? 'Taken' : 'Not taken', $taken ? $ov['marked_today'] . ' of ' . $ov['students'] . ' marked' : 'take attendance');
        overviewTile(classUrl('grades'), 'fa-inbox', $ov['to_grade'] ? 'warn' : 'muted', 'To grade', $ov['to_grade'],
            $ov['to_grade'] ? 'turned in, not graded' : 'all caught up');
    } else {
        overviewTile(classUrl('grades'), 'fa-chart-line', 'green', 'My grade',
            $ov['grade'] === null ? '—' : $ov['grade'] . '%', $ov['grade'] === null ? 'nothing graded yet' : 'of graded work');
        overviewTile(classUrl('attendance'), 'fa-clipboard-check', $ov['attendance'] !== null && $ov['attendance'] < attLow() ? 'warn' : 'blue', 'My attendance',
            $ov['attendance'] === null ? '—' : $ov['attendance'] . '%', $ov['att_days'] ? $ov['att_days'] . ' day' . ($ov['att_days'] == 1 ? '' : 's') . ' marked' : 'not marked yet');
        overviewTile(classUrl('classwork'), 'fa-exclamation-circle', $ov['missing'] ? 'danger' : 'muted', 'Missing work', $ov['missing'],
            $ov['missing'] ? 'past due, not turned in' : 'nothing missing');
        $n = $ov['next'];
        overviewTile($n ? APP_URL . '/' . $n['url'] : classUrl('classwork'), 'fa-calendar-day', 'blue', 'Next due',
            $n ? htmlspecialchars($n['title']) : 'Nothing due', $n ? formatDate($n['due'], 'D, M d · h:i A') : 'you are all set');
    }
    echo '</div>';
}


/**
 * Heading of the Attendance module around one class's attendance (ATT_MODULE): back to the module, the class name, a class switcher (the person's own classes)
 * and a link to the class itself. The page below it is the same sheet / report / own record as in the class.
 */
function attendanceModuleTop() {
    global $class, $class_id, $is_owner, $current_user_id, $current_user_role, $date, $view;
    $classes = getUserClasses($current_user_id, $current_user_role);
    $keep = $is_owner && ($view ?? '') === 'report' ? '&view=report' : ($is_owner && ($view ?? '') === 'edit' ? '&view=edit' : '');
    ?>
<div class="att-module-top">
    <a class="back-link" href="<?php echo htmlspecialchars(backUrl(APP_URL . '/pages/attendance/view.php')); ?>"><i class="fas fa-arrow-left"></i> Attendance</a>
    <div class="att-module-title">
        <span class="work-icon" style="background: <?php echo classTheme($class); ?>;"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
        <div><h1><?php echo htmlspecialchars($class['class_name']); ?></h1><p class="page-subtitle"><?php echo $is_owner ? 'Take attendance and read the report' : 'Your attendance in this class'; ?></p></div>
    </div>
    <div class="att-module-tools">
        <?php if (count($classes) > 1): ?>
        <label class="sr-only" for="attClassSwitch">Class</label>
        <select id="attClassSwitch" class="att-class-switch" data-base="<?php echo htmlspecialchars(APP_URL . '/pages/attendance/class.php?id='); ?>" data-keep="<?php echo htmlspecialchars($keep); ?>">
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $c['id'] === (int) $class_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <a class="btn-app btn-sm btn-outline" href="<?php echo APP_URL; ?>/pages/classes/stream.php?id=<?php echo (int) $class_id; ?>"><i class="fas fa-chalkboard-teacher"></i> Open class</a>
    </div>
</div>
<script>
  (function () { var s = document.getElementById("attClassSwitch"); if (s) s.addEventListener("change", function () { location.href = s.dataset.base + s.value + s.dataset.keep; }); })();
</script>
<?php
}
