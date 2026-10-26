<?php
/**
 * View Resources — per designs/pages/resources/teacher.html (table) and
 * studentViewReesources (download only).
 *   ?class_id=N  one class, otherwise every class the user can access.
 * Teachers can delete their own class resources (POST + CSRF + ownership check).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$categories = resourceCategories();
$task_result = null;

// ---------- Delete (teacher, own class only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!isTeacher()) {
        $task_result = ['error', 'Not allowed', 'Only teachers can delete resources.'];
    } else {
        $resource = $db->fetchOne(
            "SELECT r.*, c.class_name FROM resources r
             INNER JOIN classes c ON r.class_id = c.id
             WHERE r.id = ? AND " . teachesClassSql('c') . "",
            [(int) ($_POST['resource_id'] ?? 0), $current_user_id]
        );
        if (!$resource) {
            $task_result = ['error', 'Not found', 'That resource does not exist or is not in one of your classes.'];
        } else {
            $db->delete('resources', 'id = ?', [$resource['id']]);
            deleteItemProgress('resource', $resource['id']);
            $abs = uploadAbsolutePath($resource['file_path']);
            if ($abs) {
                @unlink($abs);
            }
            logActivity('delete_resource', 'resources', "Deleted resource #{$resource['id']} \"{$resource['title']}\" from {$resource['class_name']}");
            // Post/Redirect/Get so a refresh does not re-submit
            Session::flash('resource_deleted', $resource['title']);
            redirect(APP_URL . '/pages/resources/view.php' . (!empty($_GET['class_id']) ? '?class_id=' . (int) $_GET['class_id'] : ''));
        }
    }
}

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));

$class = null;
$filter_id = (int) ($_GET['class_id'] ?? 0);
if ($filter_id > 0) {
    $class = getAccessibleClass($filter_id, $current_user_id, $current_user_role);
    if (!$class) {
        redirect(APP_URL . '/pages/resources/view.php?error=not_found');
    }
    $class_ids = [$class['id']];
}

// Filters: category, file type, search; 20 per page
$cat = isset($categories[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$ftype = preg_match('/^[a-z0-9]{1,6}$/', (string) ($_GET['type'] ?? '')) ? $_GET['type'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$resources = []; $total = 0; $per = pagerPerPage(); $types_here = [];
if ($class_ids) {
    $in = implode(',', array_fill(0, count($class_ids), '?'));
    $types_here = array_column($db->fetchAll("SELECT DISTINCT file_type FROM resources WHERE class_id IN ({$in}) AND file_type IS NOT NULL AND file_type <> '' ORDER BY file_type", $class_ids), 'file_type');
    $where = ''; $params = $class_ids;
    if ($cat !== '') { $where .= ' AND r.category = ?'; $params[] = $cat; }
    if ($ftype !== '') { $where .= ' AND r.file_type = ?'; $params[] = $ftype; }
    if ($q !== '') { $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%'; $where .= ' AND (r.title LIKE ? OR r.description LIKE ? OR r.original_name LIKE ?)'; array_push($params, $like, $like, $like); }
    $total = (int) $db->fetchOne("SELECT COUNT(*) c FROM resources r WHERE r.class_id IN ({$in}){$where}", $params)['c'];
    [$page_no, $pages, $offset] = pagerWindow($total, $per);
    $resources = $db->fetchAll(
        "SELECT r.id, r.class_id, r.title, r.description, r.category, r.original_name,
                r.file_type, r.file_size, r.download_count, r.created_at,
                c.class_name, u.name AS uploaded_by_name
         FROM resources r
         INNER JOIN classes c ON r.class_id = c.id
         INNER JOIN users u ON r.uploaded_by = u.id
         WHERE r.class_id IN ({$in}){$where}
         ORDER BY r.created_at DESC, r.id DESC LIMIT {$per} OFFSET {$offset}",
        $params
    );
}
$filtered = $cat !== '' || $ftype !== '' || $q !== '';

$page_title = $class ? $class['class_name'] . ' - Resources' : 'Resources';
$page_subtitle = isTeacher() ? 'Study files you shared with your classes' : 'Study files shared by your teachers';
$active_menu = 'resources';

$saved = Session::flash('resource_msg');
$deleted = Session::flash('resource_deleted');
if ($saved) {
    $task_result = $saved;
} elseif ($deleted !== null) {
    $task_result = ['success', 'Resource deleted', '"' . $deleted . '" was removed.'];
} elseif (($_GET['error'] ?? '') === 'not_found') {
    $task_result = ['error', 'Not available', 'That class or file does not exist or you do not have access to it.'];
}

$page_script = "";
$page_scripts = ['assets/js/class.js'];   // the delete confirmation (form[data-confirm])
if ($task_result) {
    $page_script .= "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", title: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($task_result[0] === 'success' ? ", timer: 1800, showConfirmButton: false" : "") . " });";
}

$type_icons = ['pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word', 'ppt' => 'fa-file-powerpoint',
               'pptx' => 'fa-file-powerpoint', 'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'zip' => 'fa-file-archive',
               'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image', 'png' => 'fa-file-image', 'mp4' => 'fa-file-video',
               'txt' => 'fa-file-alt'];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<div class="page-toolbar">
    <?php if (!empty($classes)): ?>
    <form method="GET" class="filter-form" data-live data-no-ajax>
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int) $c['id']; ?>" <?php echo ($class && (int) $class['id'] === (int) $c['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c['class_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="cat" aria-label="Category"><option value="">All categories</option><?php foreach ($categories as $k => $l): ?><option value="<?php echo htmlspecialchars($k); ?>" <?php echo $cat === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?></select>
        <select name="type" aria-label="File type"><option value="">All file types</option><?php foreach ($types_here as $t): ?><option value="<?php echo htmlspecialchars($t); ?>" <?php echo $ftype === $t ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($t)); ?></option><?php endforeach; ?></select>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search files…" aria-label="Search files" style="min-width:170px;">
        <?php if ($filtered): ?><a class="link-btn" href="view.php<?php echo $class ? '?class_id=' . (int) $class['id'] : ''; ?>">Clear filters</a><?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
            <?php echo pagerPerSelect($per, $total); ?>
    </form>
    <?php else: ?><span></span><?php endif; ?>

    <?php if (isTeacher() && !empty($classes)): ?>
    <a href="upload.php<?php echo $class ? '?class_id=' . (int) $class['id'] : ''; ?>" class="btn-app btn-sm">
        <i class="fas fa-upload"></i> Upload Resource
    </a>
    <?php endif; ?>
</div>
<div data-live-results>

<?php if (empty($resources)): ?>
<div class="empty-state">
    <i class="fas fa-folder-open"></i>
    <h3><?php echo $filtered ? 'No files match these filters' : 'No resources yet'; ?></h3>
    <p style="margin-top:6px;">
        <?php if (empty($classes)): ?>
            <?php echo isTeacher() ? 'Create a class first, then share files with it.' : 'Join a class to see its resources.'; ?>
        <?php else: ?>
            <?php echo isTeacher() ? 'Upload the first study file for your students.' : 'Your teachers have not shared any files yet.'; ?>
        <?php endif; ?>
    </p>
</div>
<?php else: ?>
<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-folder-open"></i> <?php echo isTeacher() ? 'Uploaded Resources' : 'Shared Resources'; ?> (<?php echo (int) $total; ?>)</h2>
    </div>
    <div class="table-wrap">
        <table class="app-table">
            <thead>
                <tr>
                    <th>Resource</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Size</th>
                    <th style="width:100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resources as $r): ?>
                <tr id="res-<?php echo (int) $r['id']; ?>">
                    <td>
                        <div class="resource-title">
                            <i class="fas <?php echo $type_icons[$r['file_type']] ?? 'fa-file'; ?>"></i>
                            <?php echo htmlspecialchars($r['title']); ?>
                        </div>
                        <?php if (!empty($r['description'])): ?>
                        <div class="resource-description"><?php echo htmlspecialchars($r['description']); ?></div>
                        <?php endif; ?>
                        <div class="resource-meta">
                            <?php if (!$class): ?>
                            <span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($r['class_name']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($r['uploaded_by_name']); ?></span>
                            <span><i class="fas fa-download"></i> <?php echo (int) $r['download_count']; ?> downloads</span>
                        </div>
                    </td>
                    <td><span class="category-tag"><?php echo htmlspecialchars($categories[$r['category']] ?? 'Other'); ?></span></td>
                    <td><span class="file-type"><?php echo htmlspecialchars($r['file_type'] ?? ''); ?></span></td>
                    <td><?php echo formatDate($r['created_at'], 'M d, Y'); ?></td>
                    <td><?php echo formatBytes($r['file_size']); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a class="icon-btn" href="download.php?id=<?php echo (int) $r['id']; ?>"
                                title="Download <?php echo htmlspecialchars($r['original_name'] ?? ''); ?>" aria-label="Download">
                                <i class="fas fa-download"></i>
                            </a>
                            <?php if (isTeacher()): ?>
                            <a class="icon-btn" href="edit.php?id=<?php echo (int) $r['id']; ?>" title="Edit title and details" aria-label="Edit resource"><i class="fas fa-pen"></i></a>
                            <form method="POST" class="delete-resource" data-confirm="Delete this resource?" data-confirm-text="<?php echo htmlspecialchars($r['title']); ?> will be removed for every student." data-confirm-button="Yes, delete">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="resource_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" class="icon-btn danger" title="Delete Resource" aria-label="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php echo pagerHtml($total, $page_no, $per); ?>
<?php endif; ?>

</div><!-- /data-live-results -->
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
