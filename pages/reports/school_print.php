<?php
/**
 * School report as a printable / "Save as PDF" document (super admin): every class with teacher, students, average grade,
 * attendance, work handed in and students needing attention. No KPI tiles; totals only, no student names.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();
$rows = schoolClassReport();
$tot = array_sum(array_column($rows, 'students'));
$avgs = array_filter(array_column($rows, 'average'), fn($v) => $v !== null);
$atts = array_filter(array_column($rows, 'attendance'), fn($v) => $v !== null);
$f = fn($v) => $v === null ? '—' : $v . '%';
$school = APP_NAME === 'SmartClass' ? 'Smart Class' : APP_NAME;
$logo = (string) getSetting('school_logo', '');
$brand = strtolower((string) getSetting('brand_color', '#4361ee'));
if (!preg_match('/^#[0-9a-f]{6}$/', $brand)) $brand = '#4361ee';
$e = fn($v) => htmlspecialchars((string) $v);
logActivity('school_report', 'reports', 'Opened the school report');
?>
<!DOCTYPE html>
<html lang="<?php echo printLang()['lang']; ?>" dir="<?php echo printLang()['rtl'] ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo pt('School report'); ?> - <?php echo $e($school); ?></title>
<style>
  :root { --brand: <?php echo $brand; ?>; --ink: #172033; --line: #cfd6e4; --soft: #f4f6fb; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 13px/1.45 "Poppins", "Segoe UI", Arial, sans-serif; color: var(--ink); background: #e9ecf3; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 10px 18px; background: #172033; color: #fff; }
  .bar a, .bar button { font: inherit; color: #fff; background: var(--brand); border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer; text-decoration: none; }
  .bar a.ghost { background: transparent; border: 1px solid rgba(255,255,255,.4); }
  .sheet { background: #fff; width: 210mm; min-height: 190mm; margin: 18px auto; padding: 14mm; box-shadow: 0 4px 24px rgba(0,0,0,.15); }
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
  @page { size: A4 portrait; margin: 8mm; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; width: auto; padding: 0; min-height: 0; } }
  @media (max-width: 800px) { .sheet { width: auto; margin: 0; padding: 14px; overflow-x: auto; } .meta { grid-template-columns: 1fr 1fr; } }
  .lowc { color: #b42318; font-weight: 600; }
  <?php echo printLangCss(); ?>
</style>
</head>
<body>
<div class="bar"><span><?php echo pt('School report'); ?></span><span><a class="ghost" href="school.php">← <?php echo pt('Back'); ?></a> <?php echo printLangBar(); ?><button type="button" onclick="window.print()"><?php echo pt('Print / Save as PDF'); ?></button></span></div>
<div class="sheet">
    <header class="doc">
        <?php if ($logo !== ''): ?><img src="<?php echo APP_URL; ?>/pages/branding/logo.php?v=<?php echo urlencode(basename($logo)); ?>" alt=""><?php endif; ?>
        <div><div class="school"><?php echo $e($school); ?></div><h1><?php echo pt('School report'); ?></h1></div>
        <div class="right"><?php echo pt('Generated'); ?> <?php echo pdate('now', true); ?></div>
    </header>
    <p class="line"><span class="kv"><?php echo pt('Classes'); ?>: <b><?php echo count($rows); ?></b></span><span class="kv"><?php echo pt('Students'); ?>: <b><?php echo $tot; ?></b></span>
        <span class="kv"><?php echo pt('Average grade'); ?>: <b><?php echo $f($avgs ? round(array_sum($avgs) / count($avgs), 1) : null); ?></b></span><span class="kv"><?php echo pt('Attendance'); ?>: <b><?php echo $f($atts ? round(array_sum($atts) / count($atts), 1) : null); ?></b></span></p>
    <table>
        <thead><tr><th style="width:30px;">#</th><th class="name"><?php echo pt('Class'); ?></th><th class="name"><?php echo pt('Teacher'); ?></th><th><?php echo pt('Students'); ?></th><th><?php echo pt('Average grade'); ?></th><th><?php echo pt('Attendance'); ?></th><th><?php echo pt('Work handed in'); ?></th><th><?php echo pt('Need attention'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr><td><?php echo $i + 1; ?></td><td class="name"><?php echo $e($r['name']); ?></td><td class="name"><?php echo $e($r['teacher']); ?></td><td><?php echo (int) $r['students']; ?></td>
                <td class="<?php echo $r['average'] !== null && $r['average'] < lowGradePct() ? 'lowc' : ''; ?>"><?php echo $f($r['average']); ?></td>
                <td class="<?php echo $r['attendance'] !== null && $r['attendance'] < attLow() ? 'lowc' : ''; ?>"><?php echo $f($r['attendance']); ?></td>
                <td><?php echo $f($r['submission']); ?></td><td><?php echo $r['at_risk'] ?: '—'; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (printLang()['rtl']): $ps = printLang()['lang'] === 'ps'; ?>
    <p class="key"><?php echo pt("Average grade = marks earned ÷ marks of graded work, per class. Attendance = the average of the students' attendance."); ?>
        <?php echo $ps ? '«پاملرنې ته اړتیا» = هغه زده‌کوونکي چې ټيټې نمرې (تر ' . lowGradePct() . '% ښکته)، ټيټه حاضري (تر ' . attLow() . '% ښکته) یا ۲ یا ډېر نشته توکي لري. سور = تر دغو حدونو ښکته.'
                      : '«نیازمند توجه» = شاگردانی که نمرهٔ پایین (کمتر از ' . lowGradePct() . '%)، حاضری پایین (کمتر از ' . attLow() . '%) یا ۲ آیتم یا بیشتر تسلیم‌نشده دارند. سرخ = پایین‌تر از این حدود.'; ?></p>
    <?php else: ?>
    <p class="key">Average grade = marks earned ÷ marks of graded work, per class. Attendance = the average of the students' attendance. "Need attention" = students with low grades (below <?php echo lowGradePct(); ?>%), low attendance (below <?php echo attLow(); ?>%) or 2 or more missing items. Red = below those limits.</p>
    <?php endif; ?>
    <div class="sign"><div><?php echo pt('Prepared by'); ?></div><div><?php echo pt('Head of school / Principal'); ?></div><div><?php echo pt('Date & stamp'); ?></div></div>
    <footer class="doc"><span><?php echo $e($school); ?></span><span><?php echo pt('Generated by'); ?> SmartClass · <?php echo date('Y-m-d H:i'); ?></span></footer>
</div>
</body>
</html>
