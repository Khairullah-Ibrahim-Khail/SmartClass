<?php
/**
 * Grades as a printable / "Save as PDF" document.
 *   ?id=<class>&layout=summary|gradebook[&student=<id>]
 *   summary    one row per student: graded / missing / overall % / rating, class average, grade spread
 *   gradebook  students × work with the scores, landscape; 6 items per page group (each group on its own page)
 *   student    (with &student=) a report card: every item with status, score, % and the teacher's feedback, plus attendance
 * Teacher (class owner): everything.  Student: only their own report card, results the teacher has not released stay hidden.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$class = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$class) redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
$class_id = (int) $class['id'];
$is_owner = !empty($class['is_owner']);
gradingUseClass($class_id);

$only = $is_owner ? (int) ($_GET['student'] ?? 0) : (int) $current_user_id;
$layout = $only ? 'student' : (($_GET['layout'] ?? '') === 'gradebook' ? 'gradebook' : 'summary');
$book = getClassGradebook($class_id, $only ?: null, !$is_owner);
$cols = $book['columns'];
$rows = $book['rows'];
if ($only && !$rows) redirect(APP_URL . '/pages/classes/grades.php?id=' . $class_id);
$wide = $layout === 'gradebook';

$pcts = array_values(array_filter(array_column($rows, 'percent'), fn($p) => $p !== null));
$avg = $pcts ? round(array_sum($pcts) / count($pcts), 1) : null;
/** [label, css class] for a percentage. */
$rating = function ($p) { $b = gradeBand($p); return $b ? [$b['label'], $b['cls']] : ['—', '']; };
$gpa_on = gradingShowGpa();
$gpaOf = function ($p) use ($gpa_on) { $g = $gpa_on ? gradeGpa($p) : null; return $g !== null ? number_format($g, 2) : '—'; };
$scale = gradingScale()['bands'];
/** "A+ 97–100% · A 93–96% …" for the key under a table. */
$scale_key = implode(' · ', array_map(fn($r) => $r['label'] . ' ' . $r['pct'] . ($r['gpa'] !== null ? ' (GPA ' . $r['gpa'] . ')' : ''), gradingRanges()));
$status = ['graded' => 'Graded', 'done' => 'Completed', 'timeout' => 'Over time', 'submitted' => 'Turned in — not graded yet', 'late' => 'Turned in late — not graded yet',
           'missing' => 'Missing', 'pending' => 'Not due yet', 'open' => 'In progress', 'held' => 'Result pending', 'returned' => 'Returned for changes'];
$m = fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

$school = APP_NAME === 'SmartClass' ? 'Smart Class' : APP_NAME;
$logo = (string) getSetting('school_logo', '');
$brand = strtolower((string) getSetting('brand_color', '#4361ee'));
if (!preg_match('/^#[0-9a-f]{6}$/', $brand)) $brand = '#4361ee';
$e = fn($v) => htmlspecialchars((string) $v);
$title = pt($only ? 'Student report card' : ($wide ? 'Gradebook' : 'Grade report'));
logActivity('grades_report', 'classes', "Opened {$layout} grade report for {$class['class_name']}");
$att = $only ? (getAttendanceSummary($class_id, $only)[$only] ?? null) : null;
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
  .sheet { background: #fff; width: <?php echo $wide ? '297mm' : '210mm'; ?>; min-height: 190mm; margin: 18px auto; padding: 14mm; box-shadow: 0 4px 24px rgba(0,0,0,.15); }
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
  @page { size: A4 <?php echo $wide ? 'landscape' : 'portrait'; ?>; margin: 8mm; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; width: auto; padding: 0; min-height: 0; } }
  @media (max-width: 800px) { .sheet { width: auto; margin: 0; padding: 14px; overflow-x: auto; } .meta { grid-template-columns: 1fr 1fr; } }
  <?php echo gradingCss(); ?>
  <?php echo printLangCss(); ?>
</style>
</head>
<body>
<div class="bar">
    <span><?php echo $e($title); ?> · <?php echo $e($class['class_name']); ?></span>
    <span><a class="ghost" href="grades.php?id=<?php echo $class_id; ?>">← <?php echo pt('Back'); ?></a> <?php echo printLangBar(); ?><button type="button" onclick="window.print()"><?php echo pt('Print / Save as PDF'); ?></button></span>
</div>
<div class="sheet">
    <header class="doc">
        <?php if ($logo !== ''): ?><img src="<?php echo APP_URL; ?>/pages/branding/logo.php?v=<?php echo urlencode(basename($logo)); ?>" alt=""><?php endif; ?>
        <div><div class="school"><?php echo $e($school); ?></div><h1><?php echo $e($title); ?></h1></div>
        <div class="right"><b><?php echo $e($class['class_name']); ?></b><br><?php echo pt('Teacher'); ?>: <?php echo $e($class['teacher_name']); ?><br><?php echo pt('Generated'); ?> <?php echo pdate('now', true); ?></div>
    </header>

<?php if (!$rows || !$cols): ?>
    <p class="none"><?php echo pt(!$rows ? 'This class has no students yet.' : 'No assignments or quizzes have been given yet.'); ?></p>
<?php elseif ($layout === 'student'): $r = $rows[0]; [$rl, $rc] = $rating($r['percent']); ?>
    <p class="line"><span class="kv"><?php echo pt('Student'); ?>: <b><?php echo $e($r['name']); ?></b></span>
        <span class="kv"><?php echo pt('Marks'); ?>: <b><?php echo $r['possible'] > 0 ? $m($r['scored']) . ' / ' . $m($r['possible']) . ' ' . pt('marked') . ' · ' . $m($r['all_possible']) . ' ' . pt('in all') : '—'; ?></b></span>
        <span class="kv"><?php echo pt('Overall'); ?>: <b><?php echo $r['graded_percent'] === null ? '—' : $r['graded_percent'] . '%'; ?></b></span>
        <span class="kv"><?php echo pt('Final'); ?>: <b class="<?php echo $rc; ?>"><?php echo $r['final_percent'] === null ? '—' : $r['final_percent'] . '%'; ?></b></span>
        <span class="kv"><?php echo pt('Grade'); ?>: <b class="<?php echo $rc; ?>"><?php echo $e($rl); ?></b></span><?php if ($gpa_on): ?><span class="kv">GPA: <b><?php echo $gpaOf($r['percent']); ?></b></span><?php endif; ?>
        <span class="kv"><?php echo pt('Attendance'); ?>: <b><?php echo $att && $att['rate'] !== null ? $att['rate'] . '%' : '—'; ?></b></span></p>
    <table>
        <thead><tr><th style="width:28px;">#</th><th class="name"><?php echo pt('Work'); ?></th><th><?php echo pt('Type'); ?></th><th><?php echo pt('Due'); ?></th><th><?php echo pt('Status'); ?></th><th><?php echo pt('Score'); ?></th><th>%</th><th><?php echo pt('Grade'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($cols as $i => $c): $cell = $r['cells'][$i]; $p = ($cell['score'] !== null && $c['max'] > 0) ? round($cell['score'] / $c['max'] * 100) : null; [, $pc] = $rating($p); ?>
            <tr><td><?php echo $i + 1; ?></td>
                <td class="name"><?php echo $e($c['title']); ?><?php if (!empty($cell['feedback'])): ?><div class="fb"><?php echo pt("Teacher's feedback"); ?>: <?php echo $e($cell['feedback']); ?></div><?php endif; ?></td>
                <td><?php echo pt($c['type'] === 'quiz' ? 'Quiz' : 'Assignment'); ?></td>
                <td><?php echo !empty($c['due']) ? pdate($c['due']) : '—'; ?></td>
                <td><?php echo $e(pt($status[$cell['state']] ?? $cell['state'])); ?></td>
                <td><?php echo $cell['score'] !== null ? $m($cell['score']) . ' / ' . $m($c['max']) : '— / ' . $m($c['max']); ?></td>
                <td class="<?php echo $pc; ?>"><?php echo $p === null ? '—' : $p . '%'; ?></td>
                <td class="<?php echo $pc; ?>"><?php echo $p === null ? '—' : $e($rating($p)[0]); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="key"><?php echo pt('Overall = marks earned ÷ marks of work marked so far; Final = marks earned ÷ marks of all work given (not marked / missing = 0).'); ?> <?php echo pt(gradingBasis() === 'all' ? 'Grade based on final %.' : 'Grade based on overall %.'); ?> <?php echo pt('Grading scale'); ?>: <?php echo $e($scale_key); ?>.</p>
    <?php if ($att && $att['total']): ?>
    <p class="key"><?php echo pt('Attendance'); ?>: <?php echo pt('present'); ?> <?php echo $att['present']; ?>, <?php echo pt('late'); ?> <?php echo $att['late']; ?>, <?php echo pt('absent'); ?> <?php echo $att['absent']; ?> (<?php echo pt('excused'); ?> <?php echo $att['excused']; ?>), <?php echo pt('official'); ?> <?php echo $att['official']; ?> — <?php echo $att['total']; ?> <?php echo pt('days recorded'); ?>.</p>
    <?php endif; ?>

<?php elseif ($layout === 'summary'): ?>
    <p class="line"><span class="kv"><?php echo pt('Students'); ?>: <b><?php echo count($rows); ?></b></span>
        <span class="kv"><?php echo pt('Class average'); ?>: <b><?php echo $avg === null ? '—' : $avg . '%'; ?></b></span>
        <span class="kv"><?php echo pt('Work items'); ?>: <b><?php echo count($cols); ?></b></span></p>
    <table>
        <thead><tr><th style="width:32px;">#</th><th class="name"><?php echo pt('Student'); ?></th><th><?php echo pt('Marks earned'); ?></th><th><?php echo pt('Out of'); ?><small><?php echo pt('marked work'); ?></small></th><th><?php echo pt('Overall %'); ?><small><?php echo pt('marked work'); ?></small></th>
            <th><?php echo pt('Final marks'); ?><small><?php echo pt('out of all work'); ?></small></th><th><?php echo pt('Final %'); ?><small><?php echo pt('all work'); ?></small></th><th><?php echo pt('Grade'); ?></th><?php if ($gpa_on): ?><th>GPA</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): [$rl, $rc] = $rating($r['percent']); ?>
            <tr><td><?php echo $i + 1; ?></td><td class="name"><?php echo $e($r['name']); ?></td>
                <td><?php echo $r['possible'] > 0 ? $m($r['scored']) : '—'; ?></td><td><?php echo $r['possible'] > 0 ? $m($r['possible']) : '—'; ?></td>
                <td><?php echo $r['graded_percent'] === null ? '—' : $r['graded_percent'] . '%'; ?></td>
                <td><?php echo $r['possible'] > 0 ? $m($r['scored']) . ' / ' . $m($r['all_possible']) : '—'; ?></td>
                <td><?php echo $r['final_percent'] === null ? '—' : $r['final_percent'] . '%'; ?></td>
                <td class="<?php echo $rc; ?>"><?php echo $e($rl); ?></td><?php if ($gpa_on): ?><td><?php echo $gpaOf($r['percent']); ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <tr><th></th><th class="name"><?php echo pt('Class average'); ?></th><th></th><th></th><th><?php $ga = array_filter(array_column($rows, 'graded_percent'), fn($x) => $x !== null); echo $ga ? round(array_sum($ga) / count($ga), 1) . '%' : '—'; ?></th>
            <th></th><th><?php $fa = array_filter(array_column($rows, 'final_percent'), fn($x) => $x !== null); echo $fa ? round(array_sum($fa) / count($fa), 1) . '%' : '—'; ?></th><th><?php echo $e($rating($avg)[0]); ?></th><?php if ($gpa_on): ?><th><?php echo $gpaOf($avg); ?></th><?php endif; ?></tr>
        </tbody>
    </table>
    <p class="key"><?php echo pt('Overall % = marks earned ÷ marks of the work that has been marked so far. Final = marks earned ÷ marks of all work given (work not marked or not handed in counts as 0).'); ?> <?php echo pt(gradingBasis() === 'all' ? 'The grade is based on the final %.' : 'The grade is based on the overall % (marked work).'); ?></p>
    <h2><?php echo pt('Grade spread'); ?></h2>
    <div class="bars">
        <?php $by_band = []; foreach ($pcts as $pp) { $gi = gradeBand($pp)['index']; $by_band[$gi] = ($by_band[$gi] ?? 0) + 1; }
        foreach ($scale as $bi => $bd): $n = $by_band[$bi] ?? 0; ?>
        <div><span><?php echo $e($bd['label']); ?> <small style="margin:0;"><?php echo $e(gradingRanges()[$bi]['pct']); ?></small></span><span><i style="width:<?php echo count($pcts) ? $n / count($pcts) * 100 : 0; ?>%"></i></span><b><?php echo $n; ?></b></div>
        <?php endforeach; ?>
    </div>

    <h2 class="page"><?php echo pt('Work given'); ?> <small><?php echo pt('average score per item'); ?></small></h2>
    <table>
        <thead><tr><th style="width:28px;">#</th><th class="name"><?php echo pt('Work'); ?></th><th><?php echo pt('Type'); ?></th><th><?php echo pt('Due'); ?></th><th><?php echo pt('Out of'); ?></th><th><?php echo pt('Average'); ?></th><th><?php echo pt('Turned in'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($cols as $i => $c):
            $sc = []; $done = 0;
            foreach ($rows as $r) { $cell = $r['cells'][$i]; if ($cell['score'] !== null && $c['max'] > 0) $sc[] = $cell['score'] / $c['max'] * 100; if (in_array($cell['state'], ['submitted', 'late', 'graded', 'done', 'timeout'], true)) $done++; }
            $a = $sc ? round(array_sum($sc) / count($sc)) : null; ?>
            <tr><td><?php echo $i + 1; ?></td><td class="name"><?php echo $e($c['title']); ?></td><td><?php echo pt($c['type'] === 'quiz' ? 'Quiz' : 'Assignment'); ?></td>
                <td><?php echo !empty($c['due']) ? pdate($c['due']) : '—'; ?></td><td><?php echo $m($c['max']); ?></td>
                <td class="<?php echo $rating($a)[1]; ?>"><?php echo $a === null ? '—' : $a . '%'; ?></td><td><?php echo $done; ?> / <?php echo count($rows); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="key"><?php echo pt('Overall = marks earned ÷ marks possible on graded work.'); ?> <?php echo pt('Grading scale'); ?>: <?php echo $e($scale_key); ?>.</p>

<?php else: $chunks = array_chunk(array_keys($cols), 6); ?>
    <?php foreach ($chunks as $ci => $idx): ?>
    <section class="<?php echo $ci ? 'page' : ''; ?>">
    <h2><?php echo pt('Work'); ?> <?php echo $idx[0] + 1; ?> – <?php echo end($idx) + 1; ?> <?php echo pt('of'); ?> <?php echo count($cols); ?> <small><?php echo pt('part'); ?> <?php echo $ci + 1; ?> <?php echo pt('of'); ?> <?php echo count($chunks); ?></small></h2>
    <table>
        <thead><tr><th class="name"><?php echo pt('Student'); ?></th>
            <?php foreach ($idx as $i): $c = $cols[$i]; ?><th><?php echo $e(mb_strimwidth($c['title'], 0, 26, '…')); ?><small><?php echo pt($c['type'] === 'quiz' ? 'Quiz' : 'Assignment'); ?> · <?php echo pt('Out of'); ?> <?php echo $m($c['max']); ?></small></th><?php endforeach; ?>
            <th><?php echo pt('Marks'); ?><small><?php echo pt('earned / all work'); ?></small></th><th><?php echo pt('Final %'); ?></th><th><?php echo pt('Grade'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): [, $rc] = $rating($r['percent']); ?>
            <tr><td class="name"><?php echo $e($r['name']); ?></td>
            <?php foreach ($idx as $i): $c = $cols[$i]; $cell = $r['cells'][$i]; $p = ($cell['score'] !== null && $c['max'] > 0) ? round($cell['score'] / $c['max'] * 100) : null; ?>
                <td class="cellbg <?php echo $p === null ? 's-na' : $rating($p)[1]; ?>"><?php echo $p !== null ? $m($cell['score']) : $e(pt(['missing' => 'Missing', 'submitted' => 'Turned in', 'late' => 'Late', 'pending' => '—', 'timeout' => 'Over time', 'open' => 'In progress', 'held' => 'Pending'][$cell['state']] ?? '—')); ?></td>
            <?php endforeach; ?>
                <td><?php echo $r['possible'] > 0 ? $m($r['scored']) . ' / ' . $m($r['all_possible']) : '—'; ?></td>
                <td><?php echo $r['final_percent'] === null ? '—' : $r['final_percent'] . '%'; ?></td>
                <td class="<?php echo $rc; ?>"><?php echo $e($rating($r['percent'])[0]); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </section>
    <?php endforeach; ?>
    <p class="key"><?php echo pt('Scores are marks earned. Cell colour follows the grading scale.'); ?> <?php echo $e($scale_key); ?>. <?php echo pt('Overall = marks earned ÷ marks possible on graded work.'); ?></p>
<?php endif; ?>

    <div class="sign"><div><?php echo pt('Class teacher'); ?><br><?php echo $e($class['teacher_name']); ?></div><div><?php echo pt('Head of school / Principal'); ?></div><div><?php echo pt('Date & stamp'); ?></div></div>
    <footer class="doc"><span><?php echo $e($school); ?> · <?php echo $e($class['class_name']); ?></span><span><?php echo pt('Generated by'); ?> SmartClass · <?php echo date('Y-m-d H:i'); ?></span></footer>
</div>
</body>
</html>
