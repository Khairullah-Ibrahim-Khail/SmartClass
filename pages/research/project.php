<?php
/**
 * Research › one project — the running process between the student and the class teacher.
 * Top: the stages (Proposal → Approved → Milestones → Completed). Left: the proposal, the milestones and the actions that are open to YOU
 * right now. Right: the timeline of everything that happened, with the discussion.
 * POST (CSRF) action = decide | add_milestone | delete_milestone | submit_milestone | review_milestone | comment | complete | close
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$p = researchProject((int) ($_GET['id'] ?? $_POST['id'] ?? 0), $current_user_id, $current_user_role);
if (!$p) { Session::flash('research_msg', ['error', 'Not found', 'That project does not exist or is not yours to open.']); redirect(APP_URL . '/pages/research/projects.php'); }
$teacher = isTeacher();
$self = APP_URL . '/pages/research/project.php?id=' . (int) $p['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? ''; $r = null; $added_n = 0;
    if (!verifyCsrf()) { Session::flash('research_msg', ['error', 'Session expired', 'Please reload the page and try again.']); redirect($self); }
    if ($teacher) {
        if ($a === 'decide') $r = researchDecide($p, $current_user_id, (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''));
        elseif ($a === 'add_milestone') $r = researchAddMilestone($p, $current_user_id, (string) ($_POST['milestone_title'] ?? ''), (string) ($_POST['due_date'] ?? ''));
        elseif ($a === 'delete_milestone') $r = researchDeleteMilestone($p, $current_user_id, (int) ($_POST['milestone_id'] ?? 0));
        elseif ($a === 'review_milestone') $r = researchReviewMilestone($p, $current_user_id, (int) ($_POST['milestone_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''));
        elseif ($a === 'complete') $r = researchComplete($p, $current_user_id, (string) ($_POST['grade'] ?? ''), (string) ($_POST['feedback'] ?? ''));
        elseif ($a === 'close') $r = researchClose($p, $current_user_id, (string) ($_POST['close_note'] ?? ''));
        elseif ($a === 'apply_template') { $r = researchApplyTemplate($p, $current_user_id, (string) ($_POST['template'] ?? '')); if ($r['ok']) $added_n = (int) ($r['added'] ?? 0); }
        elseif ($a === 'save_template') $r = researchSaveTemplate($p, $current_user_id, (string) ($_POST['template_name'] ?? ''));
        elseif ($a === 'delete_template') $r = researchDeleteTemplate($current_user_id, (int) ($_POST['template_id'] ?? 0));
    } else {
        if ($a === 'submit_milestone') $r = researchSubmitMilestone($p, $current_user_id, (int) ($_POST['milestone_id'] ?? 0), (string) ($_POST['body'] ?? ''), $_FILES['file'] ?? null);
    }
    if ($a === 'add_source') $r = researchAddSource($p, $current_user_id, (int) ($_POST['source_item'] ?? 0), (string) ($_POST['source_title'] ?? ''), (string) ($_POST['source_authors'] ?? ''), (string) ($_POST['source_year'] ?? ''), (string) ($_POST['source_url'] ?? ''), (string) ($_POST['source_note'] ?? ''));
    if ($a === 'delete_source') $r = researchDeleteSource($p, $current_user_id, $current_user_role, (int) ($_POST['source_id'] ?? 0));
    if ($a === 'comment') $r = researchComment($p, $current_user_id, $current_user_role, (string) ($_POST['comment'] ?? ''), $_FILES['file'] ?? null);
    if ($r === null) { Session::flash('research_msg', ['error', 'Not allowed', 'You cannot do that on this project.']); redirect($self); }
    if ($r['ok']) {
        logActivity('research_' . $a, 'research', "Project #{$p['id']} \"{$p['title']}\": {$a}");
        Session::flash('research_msg', ['success', 'Done', [
            'decide' => 'Your decision was saved and the student was told.', 'add_milestone' => 'Milestone added.', 'delete_milestone' => 'Milestone removed.', 'submit_milestone' => 'Your work was sent to your teacher.',
            'review_milestone' => 'Your review was saved and the student was told.', 'comment' => 'Message sent.', 'complete' => 'The project is completed.', 'close' => 'The project was closed.',
            'apply_template' => ($added_n ?? 0) . ' milestone' . (($added_n ?? 0) == 1 ? '' : 's') . ' added from the template.', 'save_template' => 'Your template was saved.', 'delete_template' => 'The template was deleted.',
            'add_source' => 'Source added.', 'delete_source' => 'Source removed.'][$a] ?? 'Saved.']);
    } else {
        Session::flash('research_form', ['errors' => $r['errors'], 'old' => $_POST, 'action' => $a]);
        if (!$r['errors']) Session::flash('research_msg', ['error', 'Not done', $r['error']]);
    }
    redirect($self . ($a === 'comment' ? '#timeline' : ''));
}

$task_result = Session::flash('research_msg');
$form = Session::flash('research_form') ?: ['errors' => [], 'old' => [], 'action' => ''];
$E = $form['errors']; $O = $form['old'];
$p = researchProject($p['id'], $current_user_id, $current_user_role);   // fresh
$statuses = researchStatuses(); [$sl, $sb, $si] = $statuses[$p['status']];
$ms = researchMilestones($p['id']); $ups = researchUpdates($p['id']); [$acc, $tot] = researchProgress($p['id']);
$msS = researchMilestoneStatuses();
$sources = researchSources($p['id']);
$templates = $teacher ? researchTemplates($current_user_id) : [];
$lib_items = $p['status'] !== 'closed' ? $db->fetchAll("SELECT id, title, authors, year FROM research_items WHERE class_id = ? ORDER BY title LIMIT 200", [(int) $p['class_id']]) : [];
$used_items = array_filter(array_column($sources, 'item_id'));
$subs = []; foreach ($ups as $u) if ($u['kind'] === 'submission' && $u['milestone_id']) $subs[(int) $u['milestone_id']] = $u;   // the latest hand-in per milestone
$stage = ['draft' => 0, 'changes' => 0, 'proposed' => 1, 'active' => 2, 'completed' => 4, 'closed' => 1][$p['status']];
if ($p['status'] === 'active' && $tot && $acc === $tot) $stage = 3;
$steps = ['Proposal', 'Teacher decision', 'Milestones', 'Final review', 'Completed'];
$err = fn($k) => !empty($E[$k]) ? '<div class="invalid-feedback show">' . htmlspecialchars(t($E[$k])) . '</div>' : '';
$page_title = $p['title'];
$page_subtitle = $teacher ? $p['student_name'] . ' · ' . $p['class_name'] : $p['class_name'];
$active_menu = 'research';
$page_scripts = ['assets/js/class.js'];
$page_script = ($task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode(t($task_result[1])) . ", text: " . json_encode(t($task_result[2])) . ", confirmButtonColor: '#4361ee'" . ($task_result[0] === 'success' ? ", timer: 1600, showConfirmButton: false" : "") . " });" : '');
$hide_page_head = false;
include dirname(__DIR__, 2) . '/includes/app_header.php';
$next_ok = $p['status'] === 'active';
?>
<div class="rs-top">
    <a href="<?php echo htmlspecialchars(backUrl('projects.php')); ?>" class="link-btn"><i class="fas fa-arrow-left"></i> Research projects</a>
    <span style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a class="btn-app btn-sm btn-outline" href="report.php?id=<?php echo (int) $p['id']; ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Project report (PDF)</a>
        <span class="status-badge <?php echo $sb; ?>"><i class="fas <?php echo $si; ?>" aria-hidden="true"></i> <?php echo $sl; ?></span></span>
</div>

<ol class="rs-steps" aria-label="Stages">
    <?php foreach ($steps as $i => $name): $cls = $p['status'] === 'closed' ? ($i === 0 ? 'done' : '') : ($i < $stage ? 'done' : ($i === $stage ? 'now' : '')); ?>
    <li class="<?php echo $cls; ?>"><span class="rs-dot"><?php echo $cls === 'done' ? '<i class="fas fa-check"></i>' : $i + 1; ?></span><span class="rs-step-name"><?php echo $name; ?></span></li>
    <?php endforeach; ?>
</ol>
<?php if ($p['status'] === 'closed'): ?><div class="att-warning" role="status"><i class="fas fa-ban"></i><div><strong>This project was closed.</strong> You can still read everything that happened below.</div></div><?php endif; ?>

<div class="rs-layout">
<div class="rs-left">

    <section class="content-card rs-proposal">
        <div class="card-head"><h2><i class="fas fa-lightbulb"></i> Proposal</h2>
            <?php if (!$teacher && in_array($p['status'], ['draft', 'changes'], true)): ?><a class="btn-app btn-sm btn-outline" href="start.php?id=<?php echo (int) $p['id']; ?>"><i class="fas fa-pen"></i> Edit</a><?php endif; ?></div>
        <div class="rs-body">
            <div class="rs-field"><span>Research question</span><p><?php echo htmlspecialchars($p['question'] !== '' ? $p['question'] : '—'); ?></p></div>
            <div class="rs-field"><span>Plan</span><p class="prewrap"><?php echo htmlspecialchars((string) $p['plan'] !== '' ? $p['plan'] : '—'); ?></p></div>
            <?php if ($p['status'] === 'completed'): ?>
            <div class="rs-final"><strong><i class="fas fa-circle-check"></i> Completed <?php echo date('M j, Y', strtotime($p['completed_at'])); ?></strong>
                <?php if ($p['grade'] !== null): ?><span class="rs-grade"><?php echo rtrim(rtrim(number_format((float) $p['grade'], 2), '0'), '.'); ?>/100</span><?php endif; ?>
                <?php if ($p['final_feedback']): ?><p class="prewrap"><?php echo htmlspecialchars($p['final_feedback']); ?></p><?php endif; ?></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($teacher && $p['status'] === 'proposed'): ?>
    <section class="content-card rs-decide">
        <div class="card-head"><h2><i class="fas fa-gavel"></i> Your decision on this proposal</h2></div>
        <form method="POST" class="rs-body" novalidate>
            <?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="decide">
            <label for="rsNote">Note to the student <small class="form-hint" style="display:inline;margin:0;">(needed when you ask for changes or close)</small></label>
            <textarea id="rsNote" name="note" rows="3" maxlength="3000" class="<?php echo !empty($E['note']) ? 'is-invalid' : ''; ?>" placeholder="What is good, and what must change?"><?php echo htmlspecialchars((string) ($O['note'] ?? '')); ?></textarea>
            <?php echo $err('note'); ?>
            <div class="form-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <button name="decision" value="approve" class="btn-app"><i class="fas fa-check"></i> Approve</button>
                <button name="decision" value="changes" class="btn-app btn-outline"><i class="fas fa-rotate-left"></i> Ask for changes</button>
                <button name="decision" value="close" class="btn-app btn-outline" style="color:var(--danger);"><i class="fas fa-ban"></i> Close project</button>
            </div>
        </form>
    </section>
    <?php elseif (!$teacher && $p['status'] === 'proposed'): ?>
    <div class="att-warning" role="status"><i class="fas fa-hourglass-half"></i><div><strong>Your proposal is with your teacher.</strong> You will be notified when they decide. You can write to them below.</div></div>
    <?php elseif (!$teacher && $p['status'] === 'changes'): ?>
    <div class="att-warning" role="status"><i class="fas fa-rotate-left"></i><div><strong>Your teacher asked for changes.</strong> Read the note in the timeline, then <a href="start.php?id=<?php echo (int) $p['id']; ?>">edit your proposal</a> and send it again.</div></div>
    <?php endif; ?>

    <?php if (in_array($p['status'], ['active', 'completed'], true) || $ms): ?>
    <section class="content-card rs-milestones" id="milestones">
        <div class="card-head"><h2><i class="fas fa-flag-checkered"></i> Milestones</h2><span class="form-hint" style="margin:0;"><?php echo $acc; ?> / <?php echo $tot; ?> accepted</span></div>
        <?php if ($tot): ?><div class="rs-progress big" style="margin:0 18px 6px;"><i style="width:<?php echo round($acc / $tot * 100); ?>%"></i></div><?php endif; ?>
        <?php if (!$ms): ?><p class="form-hint" style="margin:10px 18px 16px;"><?php echo $teacher ? 'Add the steps the student must finish — for example a literature review, the method, a first draft and the final report.' : 'Your teacher will add the milestones. You will be notified.'; ?></p><?php endif; ?>
        <?php foreach ($ms as $m): [$ml, $mb] = $msS[$m['status']]; $late = $m['due_date'] && $m['due_date'] < date('Y-m-d') && in_array($m['status'], ['open', 'changes'], true); $sub = $subs[(int) $m['id']] ?? null; $mid = (int) $m['id']; ?>
        <div class="rs-ms <?php echo $m['status']; ?>">
            <div class="rs-ms-head">
                <span class="rs-ms-title"><strong><?php echo htmlspecialchars($m['title']); ?></strong>
                    <?php if ($m['due_date']): ?><span class="form-hint <?php echo $late ? 'rs-late' : ''; ?>" style="margin:0;"><i class="fas fa-calendar-day"></i> Due <?php echo date('M j, Y', strtotime($m['due_date'])); ?><?php echo $late ? ' — late' : ''; ?></span><?php endif; ?></span>
                <span class="status-badge <?php echo $mb; ?>"><?php echo $ml; ?></span>
                <?php if ($teacher && $p['status'] === 'active' && $m['status'] === 'open'): ?>
                <form method="POST" class="rs-inline" data-confirm="Remove this milestone?" data-confirm-text="Nothing was handed in for it yet." data-confirm-button="Remove"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="delete_milestone"><input type="hidden" name="milestone_id" value="<?php echo $mid; ?>">
                    <button class="icon-btn" title="Remove milestone" aria-label="Remove milestone"><i class="fas fa-trash"></i></button></form>
                <?php endif; ?>
            </div>
            <?php if ($sub && in_array($m['status'], ['submitted', 'accepted'], true)): ?>
            <div class="rs-work"><span class="form-hint" style="margin:0 0 4px;display:block;"><i class="fas fa-file-lines"></i> Handed in <?php echo timeAgo($sub['created_at']); ?></span>
                <?php if ($sub['body'] !== null && $sub['body'] !== ''): ?><p class="prewrap"><?php echo htmlspecialchars($sub['body']); ?></p><?php endif; ?>
                <?php if ($sub['file_path']): ?><a class="rs-file" href="file.php?id=<?php echo (int) $sub['id']; ?>"><i class="fas fa-paperclip"></i> <?php echo htmlspecialchars($sub['file_name']); ?></a><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($teacher && $p['status'] === 'active' && $m['status'] === 'submitted'): ?>
            <form method="POST" class="rs-review" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="review_milestone"><input type="hidden" name="milestone_id" value="<?php echo $mid; ?>">
                <textarea name="note" rows="2" maxlength="3000" placeholder="Feedback for the student (needed when you ask for changes)" aria-label="Feedback" class="<?php echo !empty($E['note_' . $mid]) ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars((string) ($O['note'] ?? '') && ($O['milestone_id'] ?? 0) == $mid ? $O['note'] : ''); ?></textarea>
                <?php echo $err('note_' . $mid); ?>
                <div style="display:flex;gap:8px;margin-top:8px;"><button name="decision" value="accept" class="btn-app btn-sm"><i class="fas fa-check"></i> Accept</button><button name="decision" value="changes" class="btn-app btn-sm btn-outline"><i class="fas fa-rotate-left"></i> Ask for changes</button></div></form>
            <?php elseif (!$teacher && $p['status'] === 'active' && in_array($m['status'], ['open', 'changes'], true)): ?>
            <form method="POST" enctype="multipart/form-data" class="rs-review" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="submit_milestone"><input type="hidden" name="milestone_id" value="<?php echo $mid; ?>">
                <textarea name="body" rows="3" maxlength="8000" placeholder="What did you do for this milestone? Add notes, findings or a link." aria-label="Your work" class="<?php echo !empty($E['body_' . $mid]) ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars(($O['milestone_id'] ?? 0) == $mid ? (string) ($O['body'] ?? '') : ''); ?></textarea>
                <?php echo $err('body_' . $mid); ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;"><input type="file" name="file" aria-label="Attach a file (optional)"><button class="btn-app btn-sm"><i class="fas fa-paper-plane"></i> <?php echo $m['status'] === 'changes' ? 'Hand in again' : 'Hand in'; ?></button></div>
                <?php echo $err('file_' . $mid); ?></form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($teacher && $p['status'] === 'active'): ?>
        <div class="rs-templates">
            <form method="POST" class="rs-add-row" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="apply_template">
                <div class="grow"><label for="rsTpl">Add from a template</label>
                    <select id="rsTpl" name="template"><?php foreach ($templates as $k => [$tn, $ti]): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($tn); ?></option><?php endforeach; ?></select></div>
                <button class="btn-app btn-sm btn-outline"><i class="fas fa-layer-group"></i> Add milestones</button>
            </form>
            <?php echo $err('template'); ?>
            <?php if ($tot): ?>
            <form method="POST" class="rs-add-row" style="margin-top:10px;" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="save_template">
                <div class="grow"><label for="rsTplName">Save these milestones as my template</label><input type="text" id="rsTplName" name="template_name" maxlength="100" placeholder="Template name" value="<?php echo htmlspecialchars((string) ($O['template_name'] ?? '')); ?>" class="<?php echo !empty($E['template_name']) ? 'is-invalid' : ''; ?>"></div>
                <button class="btn-app btn-sm btn-outline"><i class="fas fa-bookmark"></i> Save template</button>
            </form>
            <?php echo $err('template_name'); ?>
            <?php endif; ?>
            <?php $mine = array_filter($templates, fn($k) => $k[0] === 't', ARRAY_FILTER_USE_KEY); if ($mine): ?>
            <div class="rs-mytpl"><?php foreach ($mine as $k => [$tn]): ?>
                <form method="POST" class="rs-inline" data-confirm="Delete this template?" data-confirm-text="<?php echo htmlspecialchars($tn); ?>" data-confirm-button="Delete"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?php echo (int) substr($k, 1); ?>">
                    <span class="rs-chip"><?php echo htmlspecialchars($tn); ?> <button class="icon-btn" title="Delete template" aria-label="Delete template <?php echo htmlspecialchars($tn); ?>"><i class="fas fa-xmark"></i></button></span></form>
            <?php endforeach; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($teacher && $p['status'] === 'active'): ?>
        <form method="POST" class="rs-add" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="add_milestone">
            <div class="rs-add-row">
                <div class="grow"><label for="rsMt">New milestone</label><input type="text" id="rsMt" name="milestone_title" maxlength="150" placeholder="e.g. Literature review" value="<?php echo htmlspecialchars((string) ($O['milestone_title'] ?? '')); ?>" class="<?php echo !empty($E['milestone_title']) ? 'is-invalid' : ''; ?>"></div>
                <div><label for="rsDue">Due date</label><input type="date" id="rsDue" name="due_date" value="<?php echo htmlspecialchars((string) ($O['due_date'] ?? '')); ?>" class="<?php echo !empty($E['due_date']) ? 'is-invalid' : ''; ?>"></div>
                <button class="btn-app btn-sm"><i class="fas fa-plus"></i> Add</button>
            </div>
            <?php echo $err('milestone_title'); echo $err('due_date'); ?>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="content-card rs-sources" id="sources">
        <div class="card-head"><h2><i class="fas fa-book-bookmark"></i> Sources</h2><span class="form-hint" style="margin:0;"><?php echo count($sources); ?></span></div>
        <?php if (!$sources): ?><p class="form-hint" style="margin:6px 18px 12px;">The articles, books and datasets this project uses. Add them from your class Library or type them in.</p><?php endif; ?>
        <?php foreach ($sources as $sr): ?>
        <div class="rs-src">
            <div class="rs-src-main"><strong><?php echo htmlspecialchars($sr['title']); ?></strong>
                <span class="form-hint" style="margin:0;"><?php echo htmlspecialchars(trim(($sr['authors'] ?? '') . ($sr['year'] ? ' (' . (int) $sr['year'] . ')' : ''))); ?><?php echo $sr['item_id'] ? ' · <i class="fas fa-book-open"></i> Library' : ''; ?></span>
                <?php if ($sr['note']): ?><span class="form-hint" style="margin:0;"><?php echo htmlspecialchars($sr['note']); ?></span><?php endif; ?>
                <?php if ($sr['url']): ?><a href="<?php echo htmlspecialchars($sr['url']); ?>" target="_blank" rel="noopener noreferrer" class="rs-file"><i class="fas fa-external-link-alt"></i> Open</a><?php endif; ?></div>
            <?php if ($teacher || (int) $sr['added_by'] === (int) $current_user_id): ?>
            <form method="POST" class="rs-inline" data-confirm="Remove this source?" data-confirm-text="<?php echo htmlspecialchars(mb_strimwidth($sr['title'], 0, 80, '…')); ?>" data-confirm-button="Remove"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="delete_source"><input type="hidden" name="source_id" value="<?php echo (int) $sr['id']; ?>">
                <button class="icon-btn" title="Remove source" aria-label="Remove source"><i class="fas fa-trash"></i></button></form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($p['status'] !== 'closed'): ?>
        <form method="POST" class="rs-add" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="add_source">
            <?php if ($lib_items): ?>
            <div class="rs-add-row"><div class="grow"><label for="rsSrcItem">From the class Library</label>
                <select id="rsSrcItem" name="source_item" class="<?php echo !empty($E['source_item']) ? 'is-invalid' : ''; ?>"><option value="0">— or type one below —</option>
                    <?php foreach ($lib_items as $li): if (isset($used_items[$li['id']]) || in_array($li['id'], $used_items)) continue; ?><option value="<?php echo (int) $li['id']; ?>"><?php echo htmlspecialchars($li['title'] . ($li['year'] ? ' (' . (int) $li['year'] . ')' : '')); ?></option><?php endforeach; ?></select></div></div>
            <?php echo $err('source_item'); ?>
            <?php endif; ?>
            <div class="rs-add-row" style="margin-top:8px;">
                <div class="grow"><label for="rsSrcTitle">Title</label><input type="text" id="rsSrcTitle" name="source_title" maxlength="250" value="<?php echo htmlspecialchars((string) ($O['source_title'] ?? '')); ?>" class="<?php echo !empty($E['source_title']) ? 'is-invalid' : ''; ?>"></div>
                <div class="grow"><label for="rsSrcAuthors">Authors</label><input type="text" id="rsSrcAuthors" name="source_authors" maxlength="250" value="<?php echo htmlspecialchars((string) ($O['source_authors'] ?? '')); ?>"></div>
                <div><label for="rsSrcYear">Year</label><input type="text" id="rsSrcYear" name="source_year" maxlength="4" inputmode="numeric" style="width:90px;" value="<?php echo htmlspecialchars((string) ($O['source_year'] ?? '')); ?>"></div>
            </div>
            <?php echo $err('source_title'); echo $err('source_authors'); echo $err('source_year'); ?>
            <div class="rs-add-row" style="margin-top:8px;">
                <div class="grow"><label for="rsSrcUrl">Link (optional)</label><input type="text" id="rsSrcUrl" name="source_url" maxlength="500" placeholder="https://" value="<?php echo htmlspecialchars((string) ($O['source_url'] ?? '')); ?>" class="<?php echo !empty($E['source_url']) ? 'is-invalid' : ''; ?>"></div>
                <div class="grow"><label for="rsSrcNote">Note (optional)</label><input type="text" id="rsSrcNote" name="source_note" maxlength="300" value="<?php echo htmlspecialchars((string) ($O['source_note'] ?? '')); ?>"></div>
                <button class="btn-app btn-sm"><i class="fas fa-plus"></i> Add source</button>
            </div>
            <?php echo $err('source_url'); echo $err('source_note'); ?>
        </form>
        <?php endif; ?>
    </section>

    <?php if ($teacher && $p['status'] === 'active'): ?>
    <section class="content-card rs-complete">
        <div class="card-head"><h2><i class="fas fa-award"></i> Finish the project</h2></div>
        <form method="POST" class="rs-body" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="complete">
            <p class="form-hint" style="margin:0 0 10px;">When every milestone is accepted you can complete the project with a final grade and feedback.</p>
            <div class="rs-add-row"><div><label for="rsGrade">Grade (0–100, optional)</label><input type="number" id="rsGrade" name="grade" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($O['grade'] ?? '')); ?>" class="<?php echo !empty($E['grade']) ? 'is-invalid' : ''; ?>"></div></div>
            <?php echo $err('grade'); ?>
            <label for="rsFb" style="margin-top:10px;">Final feedback (optional)</label><?php echo aiWriteButton('feedback', '#rsFb', ['class_id' => (int) $p['class_id'], 'use' => true, 'label' => 'Improve my wording']); ?><textarea id="rsFb" name="feedback" rows="3" maxlength="4000"><?php echo htmlspecialchars((string) ($O['feedback'] ?? '')); ?></textarea><?php echo $err('feedback'); ?>
            <div style="margin-top:10px;"><button class="btn-app" <?php echo ($tot && $acc === $tot) ? '' : 'title="Every milestone must be accepted first"'; ?>><i class="fas fa-circle-check"></i> Complete project</button></div>
        </form>
        <form method="POST" class="rs-body rs-close" novalidate style="padding-top:0;"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="close">
            <details><summary class="link-btn" style="color:var(--danger);">Close this project without completing it</summary>
                <textarea name="close_note" rows="2" maxlength="3000" placeholder="Reason (the student will read it)" aria-label="Reason" class="<?php echo !empty($E['close_note']) ? 'is-invalid' : ''; ?>" style="margin-top:8px;"></textarea><?php echo $err('close_note'); ?>
                <button class="btn-app btn-sm btn-outline" style="margin-top:8px;color:var(--danger);"><i class="fas fa-ban"></i> Close project</button></details></form>
    </section>
    <?php endif; ?>
</div>

<aside class="rs-right" id="timeline">
    <section class="content-card">
        <div class="card-head"><h2><i class="fas fa-timeline"></i> Timeline &amp; discussion</h2></div>
        <div class="rs-feed">
            <?php if (!$ups): ?><p class="form-hint" style="margin:14px 18px;">Nothing has happened yet.</p><?php endif; ?>
            <?php foreach (array_reverse($ups) as $u): $mine = (int) $u['author_id'] === (int) $current_user_id; ?>
            <div class="rs-ev <?php echo $u['kind']; ?><?php echo $mine ? ' mine' : ''; ?>">
                <span class="rs-ev-dot"><i class="fas <?php echo ['comment' => 'fa-comment', 'submission' => 'fa-file-arrow-up', 'decision' => 'fa-gavel', 'system' => 'fa-circle-info'][$u['kind']]; ?>"></i></span>
                <div class="rs-ev-body">
                    <div class="rs-ev-head"><strong><?php echo $u['kind'] === 'system' ? 'SmartClass' : htmlspecialchars((string) ($u['author_name'] ?? 'Someone')); ?></strong>
                        <?php if ($u['author_role'] === 'teacher'): ?><span class="rs-role">Teacher</span><?php endif; ?><span class="form-hint" style="margin:0;"><?php echo timeAgo($u['created_at']); ?></span></div>
                    <?php if ($u['kind'] === 'submission'): ?><div class="form-hint" style="margin:0 0 2px;"><i class="fas fa-flag-checkered"></i> Handed in: <?php echo htmlspecialchars((string) $u['milestone_title']); ?></div><?php endif; ?>
                    <?php if ($u['body'] !== null && $u['body'] !== ''): ?><p class="prewrap"><?php echo htmlspecialchars($u['body']); ?></p><?php endif; ?>
                    <?php if ($u['file_path']): ?><a class="rs-file" href="file.php?id=<?php echo (int) $u['id']; ?>"><i class="fas fa-paperclip"></i> <?php echo htmlspecialchars($u['file_name']); ?></a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($p['status'] !== 'draft'): ?>
        <form method="POST" enctype="multipart/form-data" class="rs-compose" novalidate><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="comment">
            <textarea name="comment" rows="3" maxlength="4000" placeholder="<?php echo $teacher ? 'Write to the student…' : 'Write to your teacher…'; ?>" aria-label="Message" class="<?php echo !empty($E['comment']) ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars((string) ($form['action'] === 'comment' ? ($O['comment'] ?? '') : '')); ?></textarea>
            <?php echo $err('comment'); echo $err('comment_file'); ?>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;"><input type="file" name="file" aria-label="Attach a file (optional)"><button class="btn-app btn-sm"><i class="fas fa-paper-plane"></i> Send</button></div>
        </form>
        <?php endif; ?>
    </section>
</aside>
</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
