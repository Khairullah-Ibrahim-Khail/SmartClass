<?php
/** Add a research item (teachers): class, type, title, authors, year, link (http/https), summary, tags. Inline errors (client + server); CSRF; PRG. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/research/view.php');

$kinds = ['paper' => 'Paper', 'article' => 'Article', 'book' => 'Book', 'dataset' => 'Dataset', 'project' => 'Project', 'other' => 'Other'];
$classes = getUserClasses($current_user_id, 'teacher');
$by_id = array_column($classes, 'class_name', 'id');
// ?id=N → edit one library item of your classes (the class stays)
$edit = ((int) ($_GET['id'] ?? $_POST['id'] ?? 0)) ? $db->fetchOne("SELECT r.* FROM research_items r INNER JOIN classes c ON r.class_id = c.id WHERE r.id = ? AND " . teachesClassSql('c'), [(int) ($_GET['id'] ?? $_POST['id']), $current_user_id]) : null;
if (((int) ($_GET['id'] ?? $_POST['id'] ?? 0)) && !$edit) { Session::flash('research_msg', ['error', 'Not found', 'That item is not in one of your classes.']); redirect(APP_URL . '/pages/research/view.php'); }
$p = fn($k) => trim((string) ($_POST[$k] ?? ''));
$form = ['class_id' => $edit ? (int) $edit['class_id'] : (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0), 'kind' => $p('kind') ?: 'paper', 'title' => $p('title'), 'authors' => $p('authors'),
         'year' => $p('year'), 'url' => $p('url'), 'summary' => $p('summary'), 'tags' => $p('tags')];
if ($edit && $_SERVER['REQUEST_METHOD'] !== 'POST') $form = ['class_id' => (int) $edit['class_id'], 'kind' => $edit['kind'], 'title' => $edit['title'], 'authors' => (string) $edit['authors'], 'year' => $edit['year'] ? (string) $edit['year'] : '', 'url' => (string) $edit['url'], 'summary' => (string) $edit['summary'], 'tags' => (string) $edit['tags']];
$errors = array_fill_keys(['class_id', 'kind', 'title', 'authors', 'year', 'url', 'summary', 'tags'], '');
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        if (!isset($by_id[$form['class_id']])) $errors['class_id'] = 'Choose one of your classes';
        if (!isset($kinds[$form['kind']])) $errors['kind'] = 'Choose a type';
        if ($form['title'] === '') $errors['title'] = 'Title is required'; elseif (mb_strlen($form['title']) > 250) $errors['title'] = 'Title must be under 250 characters';
        if (mb_strlen($form['authors']) > 250) $errors['authors'] = 'Authors must be under 250 characters';
        if ($form['year'] !== '' && (!preg_match('/^\d{4}$/', $form['year']) || (int) $form['year'] < 1000 || (int) $form['year'] > (int) date('Y') + 1)) $errors['year'] = 'Enter a 4-digit year (or leave it empty)';
        if ($form['url'] !== '') {
            $scheme = strtolower((string) parse_url($form['url'], PHP_URL_SCHEME));
            if (!filter_var($form['url'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) $errors['url'] = 'Enter a full link starting with http:// or https://';
            elseif (mb_strlen($form['url']) > 500) $errors['url'] = 'The link is too long';
        }
        if (mb_strlen($form['summary']) > 3000) $errors['summary'] = 'Summary must be under 3,000 characters';
        if (mb_strlen($form['tags']) > 200) $errors['tags'] = 'Tags must be under 200 characters';
        if (!array_filter($errors)) {
            $row = ['kind' => $form['kind'], 'title' => $form['title'], 'authors' => $form['authors'] ?: null, 'year' => $form['year'] !== '' ? (int) $form['year'] : null, 'url' => $form['url'] ?: null, 'summary' => $form['summary'] ?: null, 'tags' => $form['tags'] ?: null];
            if ($edit) {
                $db->update('research_items', $row, 'id = ?', [$edit['id']]);
                logActivity('edit_research', 'research', "Edited research item #{$edit['id']} \"{$form['title']}\"");
                Session::flash('research_msg', ['success', 'Saved', '"' . $form['title'] . '" was updated.']);
                redirect(APP_URL . '/pages/research/view.php?class_id=' . $form['class_id']);
            }
            $id = $db->insert('research_items', $row + ['class_id' => $form['class_id'], 'added_by' => $current_user_id]);
            logActivity('add_research', 'research', "Added research item #{$id} \"{$form['title']}\" to {$by_id[$form['class_id']]}");
            Session::flash('research_msg', ['success', 'Added', '"' . $form['title'] . '" is in the library.']);
            redirect(APP_URL . '/pages/research/view.php?class_id=' . $form['class_id']);
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}

$page_title = $edit ? 'Edit research item' : 'Add Research';
$page_subtitle = $edit ? 'Change the details of this library item' : 'Share something worth reading with your class';
$active_menu = 'research';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const rf = document.getElementById('researchForm');
    if (rf) { bindLiveClear(rf); rf.addEventListener('submit', function (e) {
        let ok = true;
        [['class_id', 'Choose one of your classes'], ['title', 'Title is required']].forEach(function (p) { const el = document.getElementById(p[0]); if (!el.value.trim()) { setFieldError(el, p[1]); ok = false; } });
        const u = document.getElementById('url'); if (u.value.trim() && !/^https?:\\/\\//i.test(u.value.trim())) { setFieldError(u, 'Enter a full link starting with http:// or https://'); ok = false; }
        if (!ok) e.preventDefault();
    }); }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Research library</a>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>You have no classes yet</h3></div>
<?php else: ?>
<form method="POST" id="researchForm" class="form-card" novalidate>
    <?php echo csrfField(); ?><?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>"><input type="hidden" name="class_id" value="<?php echo (int) $edit['class_id']; ?>"><?php endif; ?>
    <div class="form-group">
        <label for="class_id">Class <span class="required-mark">*</span></label>
        <select id="class_id" name="class_id" <?php echo $edit ? 'disabled' : ''; ?> class="<?php echo $errors['class_id'] ? 'is-invalid' : ''; ?>"><option value="">Choose a class</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?></select>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['class_id']); ?></div>
    </div>
    <div class="form-group">
        <label for="kind">Type</label>
        <select id="kind" name="kind"><?php foreach ($kinds as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $form['kind'] === $k ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select>
    </div>
    <div class="form-group">
        <label for="title">Title <span class="required-mark">*</span></label>
        <input type="text" id="title" name="title" maxlength="250" value="<?php echo htmlspecialchars($form['title']); ?>" class="<?php echo $errors['title'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['title']); ?></div>
    </div>
    <div class="form-group">
        <label for="authors">Authors <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
        <input type="text" id="authors" name="authors" maxlength="250" value="<?php echo htmlspecialchars($form['authors']); ?>" class="<?php echo $errors['authors'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['authors']); ?></div>
    </div>
    <div class="form-group">
        <label for="year">Year <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
        <input type="text" id="year" name="year" maxlength="4" inputmode="numeric" value="<?php echo htmlspecialchars($form['year']); ?>" class="<?php echo $errors['year'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['year']); ?></div>
    </div>
    <div class="form-group">
        <label for="url">Link <span class="form-hint" style="display:inline;margin:0;">optional — DOI, article or download page</span></label>
        <input type="url" id="url" name="url" maxlength="500" placeholder="https://" value="<?php echo htmlspecialchars($form['url']); ?>" class="<?php echo $errors['url'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['url']); ?></div>
    </div>
    <div class="form-group">
        <label for="tags">Tags <span class="form-hint" style="display:inline;margin:0;">optional, comma separated</span></label>
        <input type="text" id="tags" name="tags" maxlength="200" value="<?php echo htmlspecialchars($form['tags']); ?>" class="<?php echo $errors['tags'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['tags']); ?></div>
    </div>
    <div class="form-group">
        <label for="summary">Why it is worth reading <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
        <?php echo aiWriteButton('research', '#summary', ['class' => '#class_id', 'title' => '#title', 'use' => true, 'ctx' => ['kind' => '#kind', 'authors' => '#authors', 'year' => '#year', 'tags' => '#tags']]); ?>
        <textarea id="summary" name="summary" rows="4" maxlength="3000" class="<?php echo $errors['summary'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['summary']); ?></textarea>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['summary']); ?></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-<?php echo $edit ? 'save' : 'plus'; ?>"></i> <?php echo $edit ? 'Save changes' : 'Add to library'; ?></button><a href="view.php" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
