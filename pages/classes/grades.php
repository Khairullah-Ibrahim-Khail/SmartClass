<?php
/**
 * Class — Grades: ?id=N
 * Teacher: gradebook (students × assignments + quizzes) with overall %, class
 * averages, CSV export (grades_export.php), click a student for their progress.
 *   - click a turned-in / graded assignment cell to grade it in place (dialog form)
 *     POST grade_cell  CSRF; the submission must belong to an assignment of THIS class;
 *                      same validation + notification as the assignment page (gradeSubmission)
 *   - scores are tinted by percentage (legend above the table); columns sort client-side
 *   Quiz cells are marked automatically and are not editable here.
 * Student: their own grades only.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

$task_result = null;
$grade_error = null;   // ['sub_id', 'field', 'message', 'marks', 'feedback'] → the dialog re-opens with it

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grade_cell') {
    $sub_id = (int) ($_POST['submission_id'] ?? 0);
    $submission = $is_owner ? $db->fetchOne(
        "SELECT s.*, u.name AS student_name FROM submissions s
         INNER JOIN assignments a ON s.assignment_id = a.id
         INNER JOIN users u ON s.student_id = u.id
         WHERE s.id = ? AND a.class_id = ?", [$sub_id, $class_id]) : null;
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!$submission) {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can grade work handed in to this class.'];
    } else {
        $assignment = $db->fetchOne("SELECT * FROM assignments WHERE id = ?", [$submission['assignment_id']]);
        $err = gradeSubmission($assignment, $submission, $_POST['marks'] ?? '', $_POST['feedback'] ?? '', $current_user_id, $class_id);
        if ($err) {
            $grade_error = ['sub_id' => $sub_id, 'field' => key($err), 'message' => current($err),
                            'marks' => (string) ($_POST['marks'] ?? ''), 'feedback' => (string) ($_POST['feedback'] ?? '')];
        } else {
            Session::flash('class_msg', ['success', 'Grade saved', $submission['student_name'] . ' has been notified.']);
            redirect(classUrl('grades', '#sub-' . $sub_id));
        }
    }
}
$task_result = Session::flash('class_msg') ?? $task_result;

// ---------------------------------------------------------------
// Data
// ---------------------------------------------------------------
$book = getClassGradebook($class_id, $is_owner ? null : $current_user_id, !$is_owner);   // students: unreleased results stay hidden
$cols = $book['columns'];
$rows = $book['rows'];

$state_label = [
    'graded' => null, 'done' => null, 'timeout' => 'Over time', 'submitted' => 'Turned in', 'late' => 'Turned in late',
    'missing' => 'Missing', 'pending' => 'Assigned', 'open' => 'In progress', 'held' => 'Result pending', 'returned' => 'Returned for changes',
];
$state_badge = ['graded' => 'graded', 'done' => 'graded', 'timeout' => 'missing', 'submitted' => 'submitted',
                'late' => 'late', 'missing' => 'missing', 'pending' => 'pending', 'open' => 'late', 'held' => 'submitted', 'returned' => 'late'];

/** "17.5" / "20". */
function gbMarks($m) {
    return rtrim(rtrim(number_format((float) $m, 2, '.', ''), '0'), '.');
}

/** Colour band of a percentage, from the school's grading scale (Settings › Grading scale). */
function gbBand($pct) {
    $b = gradeBand($pct);
    return $b ? $b['cls'] : '';
}

/** "B" or "B · GPA 3.00" for a percentage ('' when there is none). */
function gbGradeText($pct) {
    $b = gradeBand($pct);
    return $b ? $b['label'] . (gradingShowGpa() ? ' · GPA ' . number_format(gradeGpa($pct), 2) : '') : '';
}

$page_script = classResultScript($task_result) . ($is_owner ? "
    gradebookInit(" . json_encode($grade_error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ");" : '');
$page_scripts[] = 'assets/js/gradebook.js';

$page_title = $class['class_name'] . ' - Grades';

$can_ai_write = $is_owner && aiAvailableFor('writing', $current_user_id);
if ($can_ai_write) $page_scripts[] = 'assets/js/ai-assist.js';

include dirname(__DIR__, 2) . '/includes/app_header.php';
echo '<style>' . gradingCss() . '</style>';
classTop('grades');
?>
<?php if (!$cols): ?>
<div class="empty-state">
    <i class="fas fa-chart-line"></i>
    <h3>No graded work yet</h3>
    <p style="margin-top:6px;"><?php echo $is_owner ? 'Assignments and quizzes you create show up here as gradebook columns.' : 'Your grades will appear here once your teacher gives assignments or quizzes.'; ?></p>
</div>

<?php elseif ($is_owner): ?>
<div class="page-toolbar">
    <span class="form-hint" style="margin:0;">Overall = marks earned ÷ marks possible on work that has been graded. Click a column title to open it, <i class="fas fa-sort"></i> to sort, and a turned-in assignment cell to grade it.</span>
    <div class="toolbar-actions">
        <a href="grades_print.php?id=<?php echo $class_id; ?>&amp;layout=summary" target="_blank" class="btn-app btn-sm"><i class="fas fa-file-pdf"></i> PDF report</a>
        <a href="grades_print.php?id=<?php echo $class_id; ?>&amp;layout=gradebook" target="_blank" class="btn-app btn-sm btn-outline"><i class="fas fa-table"></i> Gradebook (PDF)</a>
        <a href="grades_export.php?id=<?php echo $class_id; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-file-csv"></i> CSV</a>
        <a href="grades_xlsx.php?id=<?php echo $class_id; ?>" class="btn-app btn-sm btn-outline" data-no-ajax><i class="fas fa-file-excel"></i> Excel</a>
        <a href="../settings/grading_class.php?class_id=<?php echo $class_id; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-award"></i> Grading scale</a>
    </div>
</div>
<div class="gb-legend" aria-label="Colour scale">
    <span>Score:</span>
    <?php foreach (gradingRanges() as $ri => $rg): ?><span class="gb-swatch gc-bg <?php echo $rg['cls']; ?>"><?php echo htmlspecialchars($rg['label']); ?> <?php echo htmlspecialchars($rg['pct'] . ($rg['gpa'] !== null ? ' · GPA ' . $rg['gpa'] : '')); ?></span><?php endforeach; ?>
    <?php if ($is_owner): ?><a class="link-btn" style="font-size:12px;" href="../settings/grading_class.php?class_id=<?php echo $class_id; ?>">Change scale</a><?php endif; ?>
</div>
<?php if (!$rows): ?>
<div class="empty-state"><i class="fas fa-user-graduate"></i><p>No students are enrolled yet.</p></div>
<?php else: ?>
<div class="content-card">
    <div class="table-wrap">
        <table class="app-table gradebook">
            <thead>
                <tr>
                    <th class="sticky-col" aria-sort="ascending"><span class="gb-th">Student <button type="button" class="gb-sort" data-col="0" data-type="text" aria-label="Sort by student name"><i class="fas fa-sort"></i></button></span></th>
                    <th aria-sort="none"><span class="gb-th">Overall <button type="button" class="gb-sort" data-col="1" data-type="num" aria-label="Sort by overall grade"><i class="fas fa-sort"></i></button></span></th>
                    <?php foreach ($cols as $ci => $c): ?>
                    <th aria-sort="none">
                        <button type="button" class="gb-sort gb-sort-corner" data-col="<?php echo $ci + 2; ?>" data-type="num" aria-label="Sort by <?php echo htmlspecialchars($c['title']); ?>"><i class="fas fa-sort"></i></button>
                        <a href="<?php echo APP_URL . '/' . $c['url']; ?>" title="<?php echo htmlspecialchars($c['title']); ?>">
                            <i class="fas <?php echo $c['type'] === 'quiz' ? 'fa-question-circle' : 'fa-tasks'; ?>"></i>
                            <?php echo htmlspecialchars(mb_strimwidth($c['title'], 0, 24, '…')); ?>
                        </a>
                        <div class="gb-sub">out of <?php echo (int) $c['max']; ?><?php echo $c['due'] ? ' · ' . formatDate($c['due'], 'M d') : ''; ?></div>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="sticky-col" data-sort="<?php echo htmlspecialchars(mb_strtolower($r['name'])); ?>"><a class="person-link" href="student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo $r['id']; ?>"><strong><?php echo htmlspecialchars($r['name']); ?></strong></a>
                        <a class="icon-btn" style="margin-left:6px;" target="_blank" href="grades_print.php?id=<?php echo $class_id; ?>&amp;student=<?php echo (int) $r['id']; ?>" title="Report card of <?php echo htmlspecialchars($r['name']); ?> (PDF)" aria-label="Report card of <?php echo htmlspecialchars($r['name']); ?>"><i class="fas fa-file-pdf"></i></a></td>
                    <td class="gb-overall <?php echo gbBand($r['percent']); ?>" data-sort="<?php echo $r['percent'] ?? -1; ?>"><?php echo $r['percent'] === null ? '—' : '<span class="percentage">' . $r['percent'] . '%</span> <small class="gb-gradelabel">' . htmlspecialchars(gbGradeText($r['percent'])) . '</small><small class="gb-marks" title="Marks earned out of marked work · final % counts all work given">' . gbMarks($r['scored']) . '/' . gbMarks($r['possible']) . ' marks' . ($r['final_percent'] !== null && $r['final_percent'] != $r['graded_percent'] ? ' · final ' . $r['final_percent'] . '%' : '') . '</small>'; ?></td>
                    <?php foreach ($r['cells'] as $ci => $cell):
                        $pct = ($cell['score'] !== null && $cell['max'] > 0) ? $cell['score'] / $cell['max'] * 100 : null;
                        $gradable = $cols[$ci]['type'] === 'assignment' && $cell['sub_id'];
                        $sort_val = $pct !== null ? round($pct, 2) : ['missing' => -3, 'pending' => -2, 'open' => -1.5, 'submitted' => -1, 'late' => -1, 'returned' => -1.2][$cell['state']] ?? -2; ?>
                    <td class="gb-cell <?php echo gbBand($pct); ?>" data-sort="<?php echo $sort_val; ?>"<?php echo $gradable ? ' id="sub-' . $cell['sub_id'] . '"' : ''; ?>>
                        <?php if ($gradable): ?>
                        <button type="button" class="gb-grade" data-sub="<?php echo $cell['sub_id']; ?>" data-max="<?php echo (int) $cell['max']; ?>"
                            data-student="<?php echo htmlspecialchars($r['name']); ?>" data-title="<?php echo htmlspecialchars($cols[$ci]['title']); ?>"
                            data-marks="<?php echo $cell['score'] !== null ? gbMarks($cell['score']) : ''; ?>" data-feedback="<?php echo htmlspecialchars((string) $cell['feedback']); ?>"
                            data-url="<?php echo APP_URL . '/' . $cols[$ci]['url']; ?>"
                            aria-label="<?php echo htmlspecialchars(($cell['score'] !== null ? 'Change grade' : 'Grade') . ' — ' . $r['name'] . ', ' . $cols[$ci]['title']); ?>">
                        <?php endif; ?>
                        <?php if ($cell['score'] !== null): ?>
                        <span class="marks"><?php echo gbMarks($cell['score']); ?></span>
                        <?php if ($cell['state'] === 'timeout'): ?><div class="gb-sub danger">over time</div><?php endif; ?>
                        <?php else: ?>
                        <span class="status-badge <?php echo $state_badge[$cell['state']]; ?>"><?php echo $state_label[$cell['state']]; ?></span>
                        <?php endif; ?>
                        <?php if ($gradable): ?><i class="fas fa-pen gb-pen" aria-hidden="true"></i></button><?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="gb-foot">
                <tr>
                    <th class="sticky-col">Class average</th>
                    <?php
                    $pcts = array_filter(array_column($rows, 'percent'), fn($p) => $p !== null);
                    ?>
                    <th><?php echo $pcts ? round(array_sum($pcts) / count($pcts), 1) . '% <small class="gb-gradelabel">' . htmlspecialchars(gbGradeText(array_sum($pcts) / count($pcts))) . '</small>' : '—'; ?></th>
                    <?php foreach ($cols as $i => $c):
                        $scores = array_filter(array_map(fn($r) => $r['cells'][$i]['score'], $rows), fn($s) => $s !== null); ?>
                    <th><?php echo $scores ? gbMarks(array_sum($scores) / count($scores)) . ' / ' . (int) $c['max'] : '—'; ?></th>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<dialog class="gb-dialog" id="gradeDialog" aria-labelledby="gradeDialogTitle">
    <form method="POST" id="gradeForm" novalidate>
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="grade_cell">
        <input type="hidden" name="submission_id" id="gdSub">
        <div class="gd-head">
            <h2 id="gradeDialogTitle">Grade</h2>
            <button type="button" class="icon-btn" id="gdClose" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <p class="notif-meta" id="gdWhat"></p>
        <div class="form-group">
            <label for="gdMarks">Marks <span class="required-mark">*</span> <span class="form-hint" style="display:inline;margin:0;" id="gdOutOf"></span></label>
            <input type="number" name="marks" id="gdMarks" min="0" step="0.01" inputmode="decimal">
            <div class="invalid-feedback"></div>
        </div>
        <div class="form-group">
            <label for="gdFeedback">Feedback <span class="form-hint" style="display:inline;margin:0;">optional — the student sees it</span></label>
            <textarea name="feedback" id="gdFeedback" rows="3" maxlength="2000"></textarea>
            <div class="invalid-feedback"></div>
            <?php if ($can_ai_write): ?>
            <div class="ai-assist-row"><button type="button" class="btn-app btn-sm btn-outline" data-ai-task="feedback" data-target="#gdFeedback" data-class="<?php echo (int) $class_id; ?>" data-use-target="1"><i class="fas fa-robot"></i> Improve wording</button></div>
            <?php endif; ?>
        </div>
        <div class="gd-actions">
            <a class="link-btn" id="gdOpen" href="#">Open the work <i class="fas fa-arrow-right"></i></a>
            <button type="submit" class="btn-app btn-sm"><i class="fas fa-check"></i> Save grade</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php else: $me = $rows[0] ?? null; ?>
<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-chart-line"></i> Your grades <a class="link-btn" style="font-size:13px;margin-left:8px;" href="student.php?class_id=<?php echo $class_id; ?>">My progress</a>
            <a class="link-btn" style="font-size:13px;margin-left:8px;" target="_blank" href="grades_print.php?id=<?php echo $class_id; ?>"><i class="fas fa-file-pdf"></i> Report card (PDF)</a></h2>
        <span><?php echo ($me && $me['percent'] !== null) ? 'Overall <span class="percentage">' . $me['percent'] . '%</span> <small class="gb-gradelabel">' . htmlspecialchars(gbGradeText($me['percent'])) . '</small>' : '<span class="form-hint" style="margin:0;">No graded work yet</span>'; ?></span>
    </div>
    <?php foreach ($cols as $i => $c): $cell = $me['cells'][$i]; ?>
    <a class="classwork-item" href="<?php echo APP_URL . '/' . $c['url']; ?>">
        <span class="work-icon type-<?php echo $c['type']; ?>"><i class="fas <?php echo $c['type'] === 'quiz' ? 'fa-question-circle' : 'fa-tasks'; ?>"></i></span>
        <span class="classwork-main">
            <span class="classwork-title"><?php echo htmlspecialchars($c['title']); ?></span>
            <span class="notif-meta"><?php echo $c['type'] === 'quiz' ? 'Quiz' : 'Assignment'; ?><?php echo $c['due'] ? ' · Due ' . formatDate($c['due'], 'M d, h:i A') : ''; ?></span>
        </span>
        <?php if ($cell['score'] !== null): ?>
        <span class="marks"><?php echo gbMarks($cell['score']); ?> / <?php echo (int) $c['max']; ?></span>
        <?php else: ?>
        <span class="status-badge <?php echo $state_badge[$cell['state']]; ?>"><?php echo $state_label[$cell['state']]; ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
