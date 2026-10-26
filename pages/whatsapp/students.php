<?php
/**
 * WhatsApp numbers — who can be reached and who cannot. A teacher sees the students of their own classes, the administrator every student.
 *   ?f=all|with|without|stopped   ?q=name   ?p=page
 * Ready = a number and the school's agreement; Stopped = the number replied STOP; Missing = no number yet. The number is added or changed on the student's page.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/settings/profile.php');
$admin = isAdmin(); $uid = (int) $current_user_id;
$f = in_array($_GET['f'] ?? '', ['with', 'without', 'stopped'], true) ? $_GET['f'] : 'all';
$q = trim((string) ($_GET['q'] ?? ''));

$scope = $admin ? "u.role = 'student' AND u.is_active = 1 AND u.is_approved = 1"
    : "u.role = 'student' AND u.is_active = 1 AND EXISTS (SELECT 1 FROM class_members cm INNER JOIN classes c ON c.id = cm.class_id WHERE cm.student_id = u.id AND c.is_active = 1 AND " . teachesClassSql('c') . ")";
$sp = $admin ? [] : [$uid];
$count = fn($extra) => (int) $db->fetchOne("SELECT COUNT(*) c FROM users u WHERE {$scope} {$extra}", $sp)['c'];
$n = ['all' => $count(''), 'with' => $count("AND u.whatsapp_number IS NOT NULL AND u.whatsapp_consent_at IS NOT NULL"),
      'without' => $count("AND u.whatsapp_number IS NULL"), 'stopped' => $count("AND u.whatsapp_number IS NOT NULL AND u.whatsapp_consent_at IS NULL")];
$where = $scope . ['all' => '', 'with' => " AND u.whatsapp_number IS NOT NULL AND u.whatsapp_consent_at IS NOT NULL", 'without' => " AND u.whatsapp_number IS NULL", 'stopped' => " AND u.whatsapp_number IS NOT NULL AND u.whatsapp_consent_at IS NULL"][$f];
$params = $sp;
if ($q !== '') { $where .= " AND u.name LIKE ?"; $params[] = '%' . $q . '%'; }
$total = (int) $db->fetchOne("SELECT COUNT(*) c FROM users u WHERE {$where}", $params)['c'];
$per = pagerPerPage(); [$page, $pages, $offset] = pagerWindow($total, $per);
$cls = $admin ? "1 = 1" : teachesClassSql('c');
$rows = $db->fetchAll("SELECT u.id, u.name, u.whatsapp_number, u.whatsapp_consent_at, u.whatsapp_relation,
        (SELECT GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR '||') FROM class_members cm INNER JOIN classes c ON c.id = cm.class_id WHERE cm.student_id = u.id AND c.is_active = 1 AND {$cls}) AS classes,
        (SELECT c.id FROM class_members cm INNER JOIN classes c ON c.id = cm.class_id WHERE cm.student_id = u.id AND c.is_active = 1 AND {$cls} ORDER BY c.class_name LIMIT 1) AS first_class
    FROM users u WHERE {$where} ORDER BY (u.whatsapp_number IS NULL) DESC, u.name LIMIT {$per} OFFSET {$offset}",
    $admin ? $params : array_merge([$uid, $uid], $params));
$base = APP_URL . '/pages/whatsapp/students.php';
$tab = fn($key, $label) => '<a class="wa-tab' . ($f === $key ? ' on' : '') . '" href="' . $base . ($key === 'all' ? '' : '?f=' . $key) . ($q !== '' ? ($key === 'all' ? '?' : '&') . 'q=' . urlencode($q) : '') . '">' . t($label) . ' <b>' . $n[$key] . '</b></a>';
$rel = waRelations();

$page_title = 'WhatsApp numbers'; $page_subtitle = 'Who can receive messages, and who still needs a number'; $active_menu = 'settings'; $settings_page = true;
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="set-section">
    <header><h3>Students and their WhatsApp numbers</h3><p><?php echo $admin ? 'Every student of the school.' : 'The students of your classes.'; ?> <?php echo waOn() ? '' : 'WhatsApp is switched off at the moment, so nothing is sent.'; ?></p></header>
    <div class="body">
        <div class="wa-bar"><div class="wa-tabs" data-live-results><?php echo $tab('all', 'All'), $tab('with', 'Ready'), $tab('without', 'No number'), $tab('stopped', 'Stopped'); ?></div>
            <form method="GET" class="wa-find" role="search" data-live data-no-ajax><input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search a student" aria-label="Search a student" autocomplete="off"><button class="btn-app btn-sm"><i class="fas fa-search"></i> Search</button><?php echo pagerPerSelect($per, $total); ?></form></div>
        <div data-live-results>
        <?php if (!$rows): ?><p class="form-hint" style="margin:14px 0 0;">No students here.</p><?php else: ?>
        <div class="table-wrap"><table class="app-table wa-table"><thead><tr><th>Student</th><th class="wa-col-class">Class</th><th>WhatsApp number</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): $ready = $r['whatsapp_number'] && $r['whatsapp_consent_at']; ?>
            <tr><td><span class="wa-who"><?php echo avatarHtml($r['id'], $r['name']); ?> <strong><?php echo htmlspecialchars($r['name']); ?></strong></span></td>
                <td class="wa-classes wa-col-class"><?php $cl = $r['classes'] === null ? [] : explode('||', $r['classes']); if ($cl): ?><span class="wa-class" title="<?php echo htmlspecialchars(implode(', ', $cl)); ?>"><?php echo htmlspecialchars($cl[0]); ?></span><?php if (count($cl) > 1): ?> <span class="wa-more" title="<?php echo htmlspecialchars(implode(', ', $cl)); ?>">+<?php echo count($cl) - 1; ?></span><?php endif; endif; ?></td>
                <td><?php echo $r['whatsapp_number'] ? '<span dir="ltr">+' . htmlspecialchars($r['whatsapp_number']) . '</span><div class="form-hint" style="margin:0;">' . htmlspecialchars(t($rel[$r['whatsapp_relation'] ?: 'self'] ?? 'My own number')) . '</div>' : '—'; ?></td>
                <td><?php echo $ready ? '<span class="status-badge graded">Ready</span>' : ($r['whatsapp_number'] ? '<span class="status-badge late">Stopped</span>' : '<span class="status-badge missing">No number</span>'); ?></td>
                <td class="nowrap"><?php if ($r['first_class']): ?><a class="icon-btn" title="<?php echo $r['whatsapp_number'] ? 'Change' : 'Add the number'; ?>" aria-label="<?php echo $r['whatsapp_number'] ? 'Change' : 'Add the number'; ?>" href="<?php echo APP_URL; ?>/pages/classes/student.php?class_id=<?php echo (int) $r['first_class']; ?>&amp;student_id=<?php echo (int) $r['id']; ?>"><i class="fas fa-pen"></i></a><?php endif; ?>
                    <?php echo waButton(waClickUrl($r['id'], 'test', ['name' => $r['name'], 'school' => waSchoolName()], $uid), 'Message on WhatsApp'); ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php echo pagerHtml($total, $page, $per); endif; ?>
        </div>
    </div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
