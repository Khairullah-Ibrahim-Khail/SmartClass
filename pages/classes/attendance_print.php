<?php
/**
 * Attendance report as a printable / "Save as PDF" document for authorities.
 *   ?id=<class>&from=Y-m-d&to=Y-m-d&layout=summary|register[&student=<id>]
 * Teacher (class owner): every student, or one with &student=.  Student: only their own record.
 * Standalone page (no app chrome): school name + logo, class, period, totals, table, signature lines.
 * Registers are grouped one table per month (day numbers across), the usual paper register.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$class = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$class) redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
$class_id = (int) $class['id'];
$is_owner = !empty($class['is_owner']);

[$from, $to] = attendancePeriod($class_id, $_GET['from'] ?? '', $_GET['to'] ?? '');
$layout = in_array($_GET['layout'] ?? '', ['register', 'compact'], true) ? $_GET['layout'] : 'summary';   // compact = the letter register (P/L/A/O)
$wide = $layout !== 'summary';
$only = $is_owner ? (int) ($_GET['student'] ?? 0) : (int) $current_user_id;

$members = $db->fetchAll(
    "SELECT u.id, u.name FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
     WHERE cm.class_id = ?" . ($only ? " AND u.id = ?" : "") . " ORDER BY u.name", $only ? [$class_id, $only] : [$class_id]);
if ($only && !$members) redirect(attendanceUrl($class_id));

$marks = [];   // student => "date#session" => mark
$days = [];    // marked dates in the period
$slots = [];   // "date#session" => [date, session] — the columns of a register (a day with one session = one column, as always)
$sess_per_date = [];
if ($from) {
    foreach ($db->fetchAll("SELECT student_id, date, session, status, note, excused, reason FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ? ORDER BY date, session", [$class_id, $from, $to]) as $m) {
        $k = $m['date'] . '#' . (int) $m['session'];
        $marks[(int) $m['student_id']][$k] = $m;
        $days[$m['date']] = true;
        $slots[$k] = [$m['date'], (int) $m['session']];
    }
}
foreach ($slots as [$sd, $ss]) $sess_per_date[$sd] = 0;
foreach ($slots as [$sd, $ss]) $sess_per_date[$sd]++;
$days = array_keys($days);
$summary = getAttendanceSummary($class_id, $only ?: null, $from, $to);
$statuses = attendanceStatuses();
$letters = ['present' => 'P', 'late' => 'L', 'absent' => 'A', 'official' => 'O'];

$rates = array_values(array_filter(array_column($summary, 'rate'), fn($r) => $r !== null));
$class_rate = $rates ? round(array_sum($rates) / count($rates), 1) : null;
$totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'official' => 0];
foreach ($summary as $s) foreach ($totals as $k => $_) $totals[$k] += $s[$k];

$school = APP_NAME === 'SmartClass' ? 'Smart Class' : APP_NAME;
$logo = (string) getSetting('school_logo', '');
$brand = strtolower((string) getSetting('brand_color', '#4361ee'));
if (!preg_match('/^#[0-9a-f]{6}$/', $brand)) $brand = '#4361ee';
$e = fn($v) => htmlspecialchars((string) $v);
$lang = in_array($_GET['lang'] ?? '', ['ps', 'fa'], true) ? $_GET['lang'] : 'en';   // ps = Pashto, fa = Dari; both print right-to-left
$rtl = $lang !== 'en';
$tr = [
    'Student attendance record' => ['ps' => 'د زده‌کوونکي د حاضرۍ ریکارډ', 'fa' => 'سوابق حاضری شاگرد'],
    'Attendance register' => ['ps' => 'د حاضرۍ دفتر', 'fa' => 'دفتر حاضری'],
    'Attendance report' => ['ps' => 'د حاضرۍ راپور', 'fa' => 'گزارش حاضری'],
    'Teacher' => ['ps' => 'ښوونکی', 'fa' => 'استاد'],
    'Generated' => ['ps' => 'جوړ شوی', 'fa' => 'تهیه شده'],
    'No attendance was recorded in this period.' => ['ps' => 'په دې موده کې هېڅ حاضري نه ده ثبت شوې.', 'fa' => 'در این مدت هیچ حاضری ثبت نشده است.'],
    'Period' => ['ps' => 'موده', 'fa' => 'مدت'],
    'Class days recorded' => ['ps' => 'ثبت شوې درسي ورځې', 'fa' => 'روزهای ثبت‌شده'],
    'Attendance rate' => ['ps' => 'د حاضرۍ کچه', 'fa' => 'درصد حاضری'],
    'Class attendance' => ['ps' => 'د ټولګي حاضري', 'fa' => 'حاضری صنف'],
    'Student' => ['ps' => 'زده‌کوونکی', 'fa' => 'شاگرد'],
    'Students' => ['ps' => 'زده‌کوونکي', 'fa' => 'شاگردان'],
    'Present' => ['ps' => 'حاضر', 'fa' => 'حاضر'],
    'Late' => ['ps' => 'ناوخته', 'fa' => 'دیر'],
    'Absent' => ['ps' => 'غیرحاضر', 'fa' => 'غایب'],
    'of which excused' => ['ps' => 'له دې جملې معذور', 'fa' => 'از آن جمله موجه'],
    'Official present' => ['ps' => 'رسمي حاضر', 'fa' => 'حاضر رسمی'],
    'Official Present' => ['ps' => 'رسمي حاضر', 'fa' => 'حاضر رسمی'],
    'Official' => ['ps' => 'رسمي', 'fa' => 'رسمی'],
    'Days recorded' => ['ps' => 'ثبت شوې ورځې', 'fa' => 'روزهای ثبت‌شده'],
    'Attendance' => ['ps' => 'حاضري', 'fa' => 'حاضری'],
    'Total' => ['ps' => 'مجموعه', 'fa' => 'مجموع'],
    'Totals' => ['ps' => 'مجموعې', 'fa' => 'مجموع'],
    'Summary for the whole period' => ['ps' => 'د ټولې مودې لنډیز', 'fa' => 'خلاصهٔ کل مدت'],
    'Day-by-day record' => ['ps' => 'ورځ په ورځ ریکارډ', 'fa' => 'سوابق روزانه'],
    'Date' => ['ps' => 'نېټه', 'fa' => 'تاریخ'],
    'Day' => ['ps' => 'ورځ', 'fa' => 'روز'],
    'Status' => ['ps' => 'حالت', 'fa' => 'وضعیت'],
    'Reason' => ['ps' => 'لامل', 'fa' => 'دلیل'],
    'Remark' => ['ps' => 'یادښت', 'fa' => 'ملاحظه'],
    'Not recorded' => ['ps' => 'نه دی ثبت شوی', 'fa' => 'ثبت نشده'],
    'Unexcused' => ['ps' => 'بې‌عذره', 'fa' => 'بدون عذر'],
    'Excused' => ['ps' => 'معذور', 'fa' => 'موجه'],
    'Class teacher' => ['ps' => 'د ټولګي ښوونکی', 'fa' => 'استاد صنف'],
    'Head of school / Principal' => ['ps' => 'د ښوونځي مدیر', 'fa' => 'مدیر مکتب'],
    'Date & stamp' => ['ps' => 'نېټه او مهر', 'fa' => 'تاریخ و مهر'],
    'Print / Save as PDF' => ['ps' => 'چاپ / د PDF په توګه خوندي کول', 'fa' => 'چاپ / ذخیره به‌صورت PDF'],
    'Back' => ['ps' => 'شاته', 'fa' => 'بازگشت'],
    'no data' => ['ps' => 'معلومات نشته', 'fa' => 'اطلاعاتی نیست'],
    'Generated by' => ['ps' => 'جوړ شوی په', 'fa' => 'تهیه شده توسط'],
    'not recorded for that student' => ['ps' => 'د دې زده‌کوونکي لپاره نه دی ثبت شوی', 'fa' => 'برای این شاگرد ثبت نشده'],
];
$t = fn($k) => $rtl ? ($tr[$k][$lang] ?? $k) : $k;
$wdays = ['ps' => ['یکشنبه', 'دوشنبه', 'درې‌شنبه', 'څلورشنبه', 'پنجشنبه', 'جمعه', 'شنبه'], 'fa' => ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه']];
$dayName = fn($d) => $rtl ? $wdays[$lang][(int) date('w', strtotime($d))] : date('l', strtotime($d));
$fmt = fn($d) => $rtl ? date('Y-m-d', strtotime($d)) : date('M j, Y', strtotime($d));
$monthName = fn($ym) => $rtl ? $ym : date('F Y', strtotime($ym . '-01'));
$title = $t($only ? 'Student attendance record' : ($wide ? 'Attendance register' : 'Attendance report'));
logActivity('attendance_report', 'attendance', "Opened {$layout} attendance report for {$class['class_name']} ({$from} – {$to})");
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo $rtl ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e($title . ' - ' . $class['class_name']); ?></title>
<style>
  :root { --brand: <?php echo $brand; ?>; --ink: #172033; --line: #cfd6e4; --soft: #f4f6fb; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 13px/1.45 <?php echo $rtl ? '"Noto Naskh Arabic", "Noto Sans Arabic", "Bahij Titr", "Amiri", Tahoma' : '"Poppins"'; ?>, "Segoe UI", Arial, sans-serif; color: var(--ink); background: #e9ecf3; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 10px 18px; background: #172033; color: #fff; }
  .bar a, .bar button { font: inherit; color: #fff; background: var(--brand); border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer; text-decoration: none; }
  .bar a.ghost { background: transparent; border: 1px solid rgba(255,255,255,.4); }
  .sheet { background: #fff; width: <?php echo $wide ? '297mm' : '210mm'; ?>; min-height: 200mm; margin: 18px auto; padding: 14mm; box-shadow: 0 4px 24px rgba(0,0,0,.15); }
  header.doc { display: flex; align-items: center; gap: 14px; padding-bottom: 10px; border-bottom: 3px solid var(--brand); margin-bottom: 14px; }
  header.doc img { height: 54px; width: auto; max-width: 90px; object-fit: contain; }
  header.doc h1 { margin: 0; font-size: 20px; } header.doc .school { font-size: 12px; color: #475467; text-transform: uppercase; letter-spacing: .06em; }
  [dir=rtl] header.doc .school, [dir=rtl] .meta small { text-transform: none; letter-spacing: 0; } [dir=rtl] .line .kv, [dir=rtl] table.reg th.wd { white-space: normal; }
  header.doc .right { margin-inline-start: auto; text-align: end; font-size: 12px; color: #475467; }
  h2 { font-size: 15px; margin: 18px 0 6px; }
  .line { margin: 0 0 12px; padding: 6px 0; border-bottom: 1px solid var(--line); font-size: 13px; color: #475467; }
  .line .kv { margin-inline-end: 18px; white-space: nowrap; } .line b { color: var(--ink); }
  .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 12px; }
  .meta div { background: var(--soft); border: 1px solid var(--line); border-radius: 8px; padding: 7px 10px; }
  .meta small { display: block; color: #667085; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; } .meta b { font-size: 14px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 10px; page-break-inside: auto; }
  tr { page-break-inside: avoid; }
  th, td { border: 1px solid var(--line); padding: 5px 7px; text-align: center; }
  th { background: var(--soft); font-size: 11.5px; } td.name, th.name { text-align: start; }
  thead { display: table-header-group; }
  .low { color: #b42318; font-weight: 600; } .good { color: #067647; font-weight: 600; }
  table.reg td, table.reg th { padding: 3px 2px; font-size: 10.5px; min-width: 17px; }
  table.reg th.wd { font-weight: 500; font-size: 9px; color: #667085; }
  table.reg th.tot { font-size: 9.5px; min-width: 44px; }
  table.reg td.cell { padding: 4px 1px; font-size: 14px; font-weight: 700; line-height: 1.1; }
  table.reg td.cell.cp { font-size: 10.5px; padding: 3px 2px; }
  td.cell.P { background: #e8f7f1; } td.cell.L { background: #fdf1de; } td.cell.A { background: #fde8ec; } td.cell.O { background: #e3f4fa; } td.cell.N { color: #98a2b3; }
  .lg { display: inline-block; padding: 1px 9px; border-radius: 99px; margin-inline-end: 6px; font-weight: 600; font-size: 11px; border: 1px solid var(--line); }
  .lg.P { background: #e8f7f1; color: #067647; } .lg.L { background: #fdf1de; color: #b54708; } .lg.A { background: #fde8ec; color: #b42318; } .lg.O { background: #e3f4fa; color: #0b7896; }
  .month-page { page-break-before: always; break-before: page; }
  .meta + .month-page, .line + .month-page { page-break-before: auto; break-before: auto; }
  h2 small { font-weight: 400; color: #667085; font-size: 12px; margin-inline-start: 8px; }
  table.reg td.name { min-width: 120px; white-space: nowrap; font-size: 11px; }
  .sheet.wide .sign { margin-top: 14px; } .sheet.wide header.doc { margin-bottom: 8px; padding-bottom: 6px; } .sheet.wide .meta { margin-bottom: 4px; } .sheet.wide h2 { margin: 6px 0 4px; }
  .sheet.wide .meta { grid-template-columns: repeat(4, 1fr); } .sheet.wide .meta div { padding: 4px 8px; }
  td.A, td.cell.A span { color: #b42318; } td.L, td.cell.L span { color: #b54708; } td.O, td.cell.O span { color: #0b7896; } td.P, td.cell.P span { color: #067647; }
  .key { font-size: 11.5px; color: #475467; margin: 6px 0 0; }
  .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; margin-top: 46px; }
  .sign div { border-top: 1px solid #667085; padding-top: 4px; font-size: 12px; color: #475467; text-align: center; }
  footer.doc { margin-top: 20px; font-size: 10.5px; color: #667085; display: flex; justify-content: space-between; }
  .none { padding: 30px; text-align: center; color: #667085; }
  @page { size: A4 <?php echo $wide ? "landscape" : "portrait"; ?>; margin: 8mm; }
  @media print { body { background: #fff; } .month-page:first-of-type { } .bar { display: none; } .sheet { margin: 0; box-shadow: none; width: auto; padding: 0; min-height: 0; } }
  @media (max-width: 800px) { .sheet { width: auto; margin: 0; padding: 14px; overflow-x: auto; } .meta { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="bar">
    <span><?php echo $e($title); ?> · <?php echo $from ? $e($fmt($from) . ' – ' . $fmt($to)) : $t('no data'); ?></span>
    <span>
        <a class="ghost" href="<?php echo attendanceUrl($class_id); ?><?php echo $is_owner ? '&amp;view=report&amp;from=' . $e($from) . '&amp;to=' . $e($to) : ''; ?>">← <?php echo $t('Back'); ?></a>
        <?php foreach (['en' => 'English', 'ps' => 'پښتو', 'fa' => 'دری'] as $lc => $ln): ?><a class="ghost" style="<?php echo $lc === $lang ? 'background:rgba(255,255,255,.18);' : ''; ?>" href="?<?php echo $e(http_build_query(array_merge($_GET, ['lang' => $lc]))); ?>"><?php echo $ln; ?></a> <?php endforeach; ?>
        <button type="button" onclick="window.print()"><?php echo $t('Print / Save as PDF'); ?></button>
    </span>
</div>
<div class="sheet<?php echo $wide ? " wide" : ""; ?>">
    <header class="doc">
        <?php if ($logo !== ''): ?><img src="<?php echo APP_URL; ?>/pages/branding/logo.php?v=<?php echo urlencode(basename($logo)); ?>" alt=""><?php endif; ?>
        <div><div class="school"><?php echo $e($school); ?></div><h1><?php echo $e($title); ?></h1></div>
        <div class="right"><b><?php echo $e($class['class_name']); ?></b><br><?php echo $t('Teacher'); ?>: <?php echo $e($class['teacher_name']); ?><br><?php echo $t('Generated'); ?> <?php echo $rtl ? date('Y-m-d H:i') : date('M j, Y, g:i A'); ?></div>
    </header>

<?php if (!$from || !$days): ?>
    <p class="none"><?php echo $t('No attendance was recorded in this period.'); ?></p>
<?php else: ?>
    <p class="line"><span class="kv"><?php echo $t('Period'); ?>: <b><?php echo $e($fmt($from)); ?> – <?php echo $e($fmt($to)); ?></b></span>
        <span class="kv"><?php echo $t('Class days recorded'); ?>: <b><?php echo count($days); ?></b></span>
        <span class="kv"><?php echo $t($only ? 'Attendance rate' : 'Class attendance'); ?>: <b><?php echo $class_rate === null ? '—' : $class_rate . '%'; ?></b></span>
        <span class="kv"><?php echo $t($only ? 'Student' : 'Students'); ?>: <b><?php echo $only ? $e($members[0]['name']) : count($members); ?></b></span></p>

<?php
    $by_month = [];
    foreach ($days as $d) $by_month[substr($d, 0, 7)][] = $d;
    $slots_by_month = [];
    foreach ($slots as $sl) $slots_by_month[substr($sl[0], 0, 7)][] = $sl;
    $multi = count($by_month) > 1;
    /** Per-student totals table for a set of days (period or one month). */
    $summaryTable = function ($sum, $with_total) use ($members, $only, $e, $t) {
        $tot = ['present' => 0, 'late' => 0, 'absent' => 0, 'official' => 0, 'excused' => 0];
        foreach ($sum as $s) foreach ($tot as $k => $_) $tot[$k] += $s[$k];
        $rs = array_values(array_filter(array_column($sum, 'rate'), fn($r) => $r !== null));
        $avg = $rs ? round(array_sum($rs) / count($rs), 1) : null; ?>
    <table>
        <thead><tr><th style="width:32px;">#</th><th class="name"><?php echo $t('Student'); ?></th><th><?php echo $t('Present'); ?></th><th><?php echo $t('Late'); ?></th><th><?php echo $t('Absent'); ?></th><th><?php echo $t('of which excused'); ?></th><th><?php echo $t('Official present'); ?></th><th><?php echo $t('Days recorded'); ?></th><th><?php echo $t('Attendance'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($members as $i => $m): $s = $sum[(int) $m['id']] ?? null; ?>
            <tr><td><?php echo $i + 1; ?></td><td class="name"><?php echo $e($m['name']); ?></td>
                <td><?php echo $s['present'] ?? 0; ?></td><td><?php echo $s['late'] ?? 0; ?></td><td><?php echo $s['absent'] ?? 0; ?></td><td><?php echo $s['excused'] ?? 0; ?></td><td><?php echo $s['official'] ?? 0; ?></td><td><?php echo $s['total'] ?? 0; ?></td>
                <td class="<?php echo $s && $s['rate'] !== null ? ($s['rate'] < attLow() ? 'low' : 'good') : ''; ?>"><?php echo $s && $s['rate'] !== null ? $s['rate'] . '%' : '—'; ?></td></tr>
        <?php endforeach; ?>
        <?php if ($with_total && !$only): ?>
            <tr><th></th><th class="name"><?php echo $t('Total'); ?></th><th><?php echo $tot['present']; ?></th><th><?php echo $tot['late']; ?></th><th><?php echo $tot['absent']; ?></th><th><?php echo $tot['excused']; ?></th><th><?php echo $tot['official']; ?></th><th></th><th><?php echo $avg === null ? '—' : $avg . '%'; ?></th></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php };
    $legend = fn() => '<p class="key"><span class="lg P">' . $t('Present') . '</span><span class="lg L">' . $t('Late') . '</span><span class="lg A">' . $t('Absent') . '</span><span class="lg O">' . $t('Official present') . '</span> ' . ($rtl
        ? $t('Attendance') . ' = (' . $t('Present') . ' + ' . $t('Late') . ' + ' . $t('Official present') . ') ÷ ' . $t('Days recorded') . ' · < ' . attLow() . '%'
        : 'Attendance = (Present + Late + Official present) ÷ days recorded. Below ' . attLow() . '% is shown in red.') . '</p>';
    $first = true;
?>
<?php if ($layout === 'summary'): ?>
    <h2><?php echo $t('Summary for the whole period'); ?></h2>
    <?php $summaryTable($summary, true); echo $legend(); ?>

    <?php if ($multi): foreach ($by_month as $ym => $mdays):
        $m_from = max($from, $ym . '-01'); $m_to = min($to, date('Y-m-t', strtotime($ym . '-01')));
        $msum = getAttendanceSummary($class_id, $only ?: null, $m_from, $m_to); ?>
    <section class="month-page">
        <h2><?php echo $monthName($ym); ?> <small><?php echo count($mdays); ?> <?php echo $rtl ? $t('Days recorded') : 'class day' . (count($mdays) == 1 ? '' : 's'); ?></small></h2>
        <?php $summaryTable($msum, true); echo $legend(); ?>
    </section>
    <?php endforeach; endif; ?>

    <?php if ($only): foreach ($by_month as $ym => $mdays): ?>
    <section class="month-page">
        <h2><?php echo $t('Day-by-day record'); ?> — <?php echo $monthName($ym); ?></h2>
        <table><thead><tr><th><?php echo $t('Date'); ?></th><th><?php echo $t('Day'); ?></th><th><?php echo $t('Status'); ?></th><th><?php echo $t('Reason'); ?></th><th class="name"><?php echo $t('Remark'); ?></th></tr></thead><tbody>
        <?php foreach ($slots_by_month[$ym] ?? [] as [$d, $sn]): $mk = $marks[$only][$d . '#' . $sn] ?? null; ?>
            <tr><td><?php echo $e($fmt($d)); ?><?php echo $sess_per_date[$d] > 1 ? ' · ' . $sn : ''; ?></td><td><?php echo $e($dayName($d)); ?></td>
                <td class="<?php echo $mk ? $letters[$mk['status']] : ''; ?>"><?php echo $mk ? $e($rtl ? $t($statuses[$mk['status']][0]) : $statuses[$mk['status']][0]) : $t('Not recorded'); ?></td>
                <td><?php echo $mk && $mk['excused'] !== null ? ((int) $mk['excused'] === 0 ? $t('Unexcused') : $e($rtl ? $t('Excused') : (attendanceReasons()[$mk['reason']] ?? 'Excused'))) : ''; ?></td><td class="name"><?php echo $e($mk['note'] ?? ''); ?></td></tr>
        <?php endforeach; ?></tbody></table>
    </section>
    <?php endforeach; endif; ?>

<?php else: ?>
    <?php foreach ($by_month as $ym => $mdays): $mslots = $slots_by_month[$ym] ?? []; ?>
    <section class="month-page">
    <h2><?php echo $monthName($ym); ?> <small><?php echo count($mdays); ?> <?php echo $rtl ? $t('Days recorded') : 'class day' . (count($mdays) == 1 ? '' : 's'); ?></small></h2>
    <table class="reg">
        <thead>
            <tr><th class="name" rowspan="2"><?php echo $t('Student'); ?></th><?php foreach ($mslots as [$d, $sn]): ?><th><?php echo (int) substr($d, 8); ?><?php echo $sess_per_date[$d] > 1 ? '<small style="display:block;font-weight:400;font-size:8px;">s' . $sn . '</small>' : ''; ?></th><?php endforeach; ?>
                <th colspan="4"><?php echo $t('Totals'); ?></th></tr>
            <tr><?php foreach ($mslots as [$d, $sn]): ?><th class="wd"><?php echo $e($rtl ? mb_substr($dayName($d), 0, 2) : date('D', strtotime($d))); ?></th><?php endforeach; ?>
                <th class="tot"><?php echo $layout === 'compact' ? 'P' : $t('Present'); ?></th><th class="tot"><?php echo $layout === 'compact' ? 'L' : $t('Late'); ?></th><th class="tot"><?php echo $layout === 'compact' ? 'A' : $t('Absent'); ?></th><th class="tot"><?php echo $layout === 'compact' ? 'O' : $t('Official'); ?></th></tr>
        </thead>
        <tbody>
        <?php foreach ($members as $m): $cnt = ['present' => 0, 'late' => 0, 'absent' => 0, 'official' => 0]; ?>
            <tr><td class="name"><?php echo $e($m['name']); ?></td>
            <?php foreach ($mslots as [$d, $sn]): $st = $marks[(int) $m['id']][$d . '#' . $sn]['status'] ?? ''; if ($st) $cnt[$st]++; ?>
                <td class="cell<?php echo $layout === 'compact' ? ' cp' : ''; ?> <?php echo $st ? $letters[$st] : 'N'; ?>"><?php echo $st ? ($layout === 'compact' ? $letters[$st] : ['present' => '✓', 'absent' => '✗', 'late' => 'L', 'official' => 'O'][$st]) : '·'; ?></td>
            <?php endforeach; ?>
            <td><?php echo $cnt['present']; ?></td><td><?php echo $cnt['late']; ?></td><td><?php echo $cnt['absent']; ?></td><td><?php echo $cnt['official']; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="key"><span class="lg P"><?php echo $layout === 'compact' ? 'P = ' : '✓ '; ?><?php echo $t('Present'); ?></span><span class="lg L">L = <?php echo $t('Late'); ?></span><span class="lg A"><?php echo $layout === 'compact' ? 'A = ' : '✗ '; ?><?php echo $t('Absent'); ?></span><span class="lg O">O = <?php echo $t('Official present'); ?></span> · “·” = <?php echo $t('not recorded for that student'); ?>.</p>
    </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

    <div class="sign"><div><?php echo $t('Class teacher'); ?><br><?php echo $e($class['teacher_name']); ?></div><div><?php echo $t('Head of school / Principal'); ?></div><div><?php echo $t('Date & stamp'); ?></div></div>
    <footer class="doc"><span><?php echo $e($school); ?> · <?php echo $e($class['class_name']); ?></span><span><?php echo $t('Generated by'); ?> SmartClass · <?php echo date('Y-m-d H:i'); ?></span></footer>
</div>
</body>
</html>
