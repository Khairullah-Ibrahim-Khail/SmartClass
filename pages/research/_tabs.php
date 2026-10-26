<?php
/** The two tabs of the Research module: Projects (the process between a student and a teacher) and Library (shared reading). $research_tab = 'projects' | 'library'. */
$wait = researchWaiting($current_user_id, $current_user_role);
?>
<div class="page-toolbar" style="margin-bottom:12px;">
    <div class="tab-pills">
        <a href="<?php echo APP_URL; ?>/pages/research/projects.php" class="<?php echo $research_tab === 'projects' ? 'active' : ''; ?>"><i class="fas fa-diagram-project"></i> Projects<?php echo $wait ? ' <span class="count-pill">' . $wait . '</span>' : ''; ?></a>
        <a href="<?php echo APP_URL; ?>/pages/research/view.php" class="<?php echo $research_tab === 'library' ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> Library</a>
    </div>
</div>
