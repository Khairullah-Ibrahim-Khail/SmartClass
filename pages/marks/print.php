<?php
/**
 * Final marks as a printable / "Save as PDF" document.
 *   ?class_id=<class>[&student=<id>]
 * Class: one row per student — the marks of each part of the marking scheme (mid exam, final exam, activities, quizzes,
 * assignments), the total, percent and grade. With &student=: that student's statement with the items behind each part.
 * Teacher of the class: everything.  Student: only their own statement (published exams / released results only).
 * No KPI tiles — header, plain summary line, tables, signature lines.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$class = getAccessibleClass((int) ($_GET['class_id'] ?? 0), $current_user_id, $current_user_role);
if (!$class) redirect(APP_URL . '/pages/marks/view.php');
$class_id = (int) $class['id'];
$is_owner = !empty($class['is_owner']);
gradingUseClass($class_id);
$only = $is_owner ? (int) ($_GET['student'] ?? 0) : (int) $current_user_id;
$fm = classFinalMarks($class_id, !$is_owner, $only ?: null);
$rows = $fm['rows'];
$scheme = $fm['scheme'];
$comps = markingComponents();
if ($only && !$rows) redirect(APP_URL . '/pages/marks/view.php?class_id=' . $class_id);
$landscape = !$only && count($scheme) >= 5;
$m = fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$gpa_on = gradingShowGpa();
$scale = gradingScale()['bands'];
$scale_key = implode(' · ', array_map(fn($r) => $r['label'] . ' ' . $r['pct'] . ($r['marks'] !== null ? ' = ' . $r['marks'] . ' marks' : '') . ($r['gpa'] !== null ? ' (GPA ' . $r['gpa'] . ')' : ''), gradingRanges($fm['weight_all'])));

$school = APP_NAME === 'SmartClass' ? 'Smart Class' : APP_NAME;
$logo = (string) getSetting('school_logo', '');
$brand = strtolower((string) getSetting('brand_color', '#4361ee'));
if (!preg_match('/^#[0-9a-f]{6}$/', $brand)) $brand = '#4361ee';
$e = fn($v) => htmlspecialchars((string) $v);
$title = pt($only ? 'Final marks statement' : 'Final marks');
$basis_note = gradingBasis() === 'all' ? 'the total out of all ' . $m($fm['weight_all']) . ' marks (a part without marks counts as 0)' : 'the parts that have marks so far';
logActivity('marks_report', 'marks', "Opened final marks report for {$class['class_name']}");

// the items behind each part, for one student
$items = [];
if ($only) {
    $book = getClassGradebook($class_id, $only, !$is_owner);
    foreach ($book['columns'] as $i => $c) {
        $cell = $book['rows'][0]['cells'][$i] ?? null;
        if ($cell && ($cell['score'] !== null || in_array($cell['state'], ['missing', 'timeout'], true)))
            $items[$c['type'] === 'quiz' ? 'quiz' : 'assignment'][] = [$c['title'], $cell['score'] ?? 0, $c['max'], $cell['state']];
    }
    foreach ($db->fetchAll(
        "SELECT e.title, e.kind, e.max_marks, m.marks, m.absent FROM exams e INNER JOIN exam_marks m ON m.exam_id = e.id AND m.student_id = ?
         WHERE e.class_id = ? AND e.kind IN ('mid', 'final', 'activity')" . ($is_owner ? '' : ' AND e.results_published_at IS NOT NULL') . " ORDER BY e.exam_date", [$only, $class_id]) as $x) {
        if ($x['absent'] || $x['marks'] !== null) $items[$x['kind']][] = [$x['title'], $x['absent'] ? 0 : $x['marks'], $x['max_marks'], $x['absent'] ? 'absent' : 'done'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo printLang()['lang']; ?>" dir="<?php echo printLang()['rtl'] ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e($title . ' - ' . $class['class_name']); ?></title>
<style>
  :root { --brand: <?php echo $brand; ?>; --ink: #172033; --line: #cfd6e4; --soft: #f4f6fb; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 13px/1.45 "Poppins", "Segoe UI", Arial, sans-serif; color: var(--ink); background: #e9ecf3; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 10px 18px; background: #172033; color: #fff; }
  .bar a, .bar button { font: inherit; color: #fff; background: var(--brand); border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer; text-decoration: none; }
  .bar a.ghost { background: transparent; border: 1px solid rgba(255,255,255,.4); }
  .sheet { background: #fff; width: <?php echo $landscape ? '297mm' : '210mm'; ?>; min-height: 190mm; margin: 18px auto; padding: 14mm; box-shadow: 0 4px 24px rgba(0,0,0,.15); }
  header.doc { display: flex; align-items: center; gap: 14px; padding-bottom: 10px; border-bottom: 3px solid var(--brand); margin-bottom: 14px; }
  header.doc img { height: 54px; width: auto; max-width: 90px; object-fit: contain; }
  header.doc h1 { margin: 0; font-size: 20px; } header.doc .school { font-size: 12px; color: #475467; text-transform: uppercase; letter-spacing: .06em; }
  header.doc .right { margin-left: auto; text-align: right; font-size: 12px; color: #475467; }
  h2 { font-size: 15px; margin: 16px 0 6px; } h2 small { font-weight: 400; color: #667085; font-size: 12px; margin-left: 8px; }
  .line { margin: 0 0 12px; padding: 6px 0; border-bottom: 1px solid var(--line); font-size: 13px; color: #475467; }
  .line .kv { margin-right: 18px; white-space: nowrap; } .line b { color: var(--ink); }
  .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 12px; }
  .meta div { background: var(--soft); border: 1px solid var(--line); border-radius: 8px; padding: 7px 10px; }
  .meta small { display: block; color: #667085; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; } .meta b { font-size: 14px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  tr { page-break-inside: avoid; } thead { display: table-header-group; }
  th, td { border: 1px solid var(--line); padding: 5px 7px; text-align: center; vertical-align: top; }
  th { background: var(--soft); font-size: 11.5px; } td.name, th.name { text-align: left; }
  th small { display: block; font-weight: 400; color: #667085; font-size: 10px; }
  .gcv { color: var(--gc-ink); font-weight: 600; } td.cellbg.gcv { background: var(--gc-bg); }
  .s-na { color: #667085; font-size: 11px; }
  .fb { text-align: left; font-size: 11.5px; color: #475467; }
  .page { page-break-before: always; break-before: page; }
  .meta + .page, header.doc + .page { page-break-before: auto; break-before: auto; }
  .key { font-size: 11.5px; color: #475467; margin: 6px 0 0; }
  .bars { margin: 6px 0 10px; } .bars div { display: grid; grid-template-columns: 150px 1fr 28px; gap: 8px; align-items: center; margin: 3px 0; font-size: 12px; }
  .bars i { display: block; height: 9px; border-radius: 9px; background: var(--brand); }
  .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; margin-top: 40px; page-break-inside: avoid; }
  .sign div { border-top: 1px solid #667085; padding-top: 4px; font-size: 12px; color: #475467; text-align: center; }
  footer.doc { margin-top: 18px; font-size: 10.5px; color: #667085; display: flex; justify-content: space-between; }
  .none { padding: 30px; text-align: center; color: #667085; }
  @page { size: A4 <?php echo $landscape ? 'landscape' : 'portrait'; ?>; margin: 8mm; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; width: auto; padding: 0; min-height: 0; } }
  @media (max-width: 800px) { .sheet { width: auto; margin: 0; padding: 14px; overflow-x: auto; } .meta { grid-template-columns: 1fr 1fr; } }
  <?php echo gradingCss(); ?>
  <?php echo printLangCss(); ?>
</style>
</head>
<body>
<div class="bar">
    <span><?php echo $e($title); ?> · <?php echo $e($class['class_name']); ?></span>
    <span><a class="ghost" href="view.php?class_id=<?php echo $class_id; ?>">← <?php echo pt('Back'); ?></a> <?php echo printLangBar(); ?><button type="button" onclick="window.print()"><?php echo pt('Print / Save as PDF'); ?></button></span>
</div>
<div class="sheet">
    <header class="doc">
        <?php if ($logo !== ''): ?><img src="<?php echo APP_URL; ?>/pages/branding/logo.php?v=<?php echo urlencode(basename($logo)); ?>" alt=""><?php endif; ?>
        <div><div class="school"><?php echo $e($school); ?></div><h1><?php echo $e($title); ?></h1></div>
        <div class="right"><b><?php echo $e($class['class_name']); ?></b><br><?php echo pt('Teacher'); ?>: <?php echo $e($class['teacher_name']); ?><br><?php echo pt('Generated'); ?> <?php echo pdate('now', true); ?></div>
    </header>

<?php if (!$rows || !$scheme): ?>
    <p class="none"><?php echo pt(!$rows ? 'This class has no students yet.' : 'No marking scheme is set for this class.'); ?></p>
<?php elseif ($only): $r = $rows[0]; $gb = gradeBand($r['percent']); ?>
    <p class="line"><span class="kv"><?php echo pt('Student'); ?>: <b><?php echo $e($r['name']); ?></b></span>
        <span class="kv"><?php echo pt('Total'); ?>: <b><?php echo $r['percent'] === null ? '—' : $m($r['total']) . ' / ' . $m($fm['weight_all']); ?></b></span>
        <span class="kv"><?php echo pt('Percent'); ?>: <b><?php echo $r['percent'] === null ? '—' : $r['percent'] . '%'; ?></b></span>
        <span class="kv"><?php echo pt('Grade'); ?>: <b class="<?php echo $gb['cls'] ?? ''; ?>"><?php echo $gb ? $e($gb['label']) : '—'; ?></b></span><?php if ($gpa_on && $gb): ?><span class="kv">GPA: <b><?php echo number_format(gradeGpa($r['percent']), 2); ?></b></span><?php endif; ?></p>
    <table>
        <thead><tr><th class="name"><?php echo pt('Part'); ?></th><th><?php echo pt('Worth'); ?></th><th><?php echo pt('Result'); ?></th><th><?php echo pt('Marks earned'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($scheme as $k => $w): $pt = $r['parts'][$k]; ?>
            <tr><td class="name"><?php echo $e(pt($comps[$k])); ?>
                    <?php foreach ($items[$k] ?? [] as [$it, $sc, $mx, $st]): ?><div class="fb"><?php echo $e($it); ?>: <?php echo $st === 'absent' ? pt('absent') : ($st === 'missing' ? pt('not handed in') : ($st === 'timeout' ? pt('over time') : $m($sc) . ' / ' . $m($mx))); ?></div><?php endforeach; ?></td>
                <td><?php echo $m($w); ?></td><td><?php echo $pt['pct'] === null ? '—' : $pt['pct'] . '%'; ?></td><td><b><?php echo $pt['earned'] === null ? '—' : $m($pt['earned']); ?></b></td></tr>
        <?php endforeach; ?>
        <tr><th class="name"><?php echo pt('Total'); ?></th><th><?php echo $m($fm['weight_all']); ?></th><th><?php echo $r['percent'] === null ? '—' : $r['percent'] . '%'; ?></th><th><?php echo $r['percent'] === null ? '—' : $m($r['total']); ?></th></tr>
        </tbody>
    </table>
    <p class="key"><?php echo pt("Each part's marks = (marks earned ÷ marks possible on its items) × the part's worth."); ?> <?php echo printLang()['rtl'] ? '' : 'The grade is based on ' . $e($basis_note) . '.'; ?> <?php echo pt('Grading scale'); ?>: <?php echo $e($scale_key); ?>.</p>
<?php else: ?>
    <p class="line"><span class="kv"><?php echo pt('Students'); ?>: <b><?php echo count($rows); ?></b></span><span class="kv"><?php echo pt('Total marks'); ?>: <b><?php echo $m($fm['weight_all']); ?></b></span></p>
    <table>
        <thead><tr><th style="width:30px;">#</th><th class="name"><?php echo pt('Student'); ?></th>
            <?php foreach ($scheme as $k => $w): ?><th><?php echo $e(pt($comps[$k])); ?><small><?php echo pt('Out of'); ?> <?php echo $m($w); ?></small></th><?php endforeach; ?>
            <th><?php echo pt('Total'); ?><small><?php echo pt('Out of'); ?> <?php echo $m($fm['weight_all']); ?></small></th><th><?php echo pt('Percent'); ?></th><?php if ($gpa_on): ?><th>GPA</th><?php endif; ?><th><?php echo pt('Grade'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): $gb = gradeBand($r['percent']); ?>
            <tr><td><?php echo $i + 1; ?></td><td class="name"><?php echo $e($r['name']); ?></td>
                <?php foreach ($scheme as $k => $w): $pt = $r['parts'][$k]; ?><td><?php echo $pt['earned'] === null ? '—' : $m($pt['earned']); ?></td><?php endforeach; ?>
                <td><b><?php echo $r['percent'] === null ? '—' : $m($r['total']); ?></b></td><td><?php echo $r['percent'] === null ? '—' : $r['percent'] . '%'; ?></td>
                <?php if ($gpa_on): ?><td><?php echo $gb ? number_format(gradeGpa($r['percent']), 2) : '—'; ?></td><?php endif; ?><td class="<?php echo $gb['cls'] ?? ''; ?>"><?php echo $gb ? $e($gb['label']) : '—'; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="key"><?php echo pt("Each part's marks = (marks earned ÷ marks possible on its items) × the part's worth."); ?> <?php echo pt('"—" = no marks recorded yet.'); ?> <?php echo printLang()['rtl'] ? '' : 'The grade is based on ' . $e($basis_note) . '.'; ?> <?php echo pt('Grading scale'); ?>: <?php echo $e($scale_key); ?>.</p>
<?php endif; ?>

    <div class="sign"><div><?php echo pt('Class teacher'); ?><br><?php echo $e($class['teacher_name']); ?></div><div><?php echo pt('Head of school / Principal'); ?></div><div><?php echo pt('Date & stamp'); ?></div></div>
    <footer class="doc"><span><?php echo $e($school); ?> · <?php echo $e($class['class_name']); ?></span><span><?php echo pt('Generated by'); ?> SmartClass · <?php echo date('Y-m-d H:i'); ?></span></footer>
</div>
</body>
</html>
