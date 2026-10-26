<?php
/**
 * Research project report — a printable / "Save as PDF" record of one project: the proposal, the milestones, what the teacher answered, the sources,
 * the grade and signature lines. Plain header + tables (no stat tiles). The project's student and the teachers of its class only.
 *   ?id=N   ?lang=ps|fa|en
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$p = researchProject((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$p) redirect(APP_URL . '/pages/research/projects.php');
$ms = researchMilestones($p['id']); $ups = researchUpdates($p['id']); $sources = researchSources($p['id']);
$statuses = researchStatuses(); $msS = researchMilestoneStatuses();
$school = APP_NAME === 'SmartClass' ? 'Smart Class' : APP_NAME;
$logo = (string) getSetting('school_logo', '');
$brand = strtolower((string) getSetting('brand_color', '#4361ee')); if (!preg_match('/^#[0-9a-f]{6}$/', $brand)) $brand = '#4361ee';
$e = fn($v) => htmlspecialchars((string) $v);
$rtl = i18nRtl();
$fmt = fn($d) => $d ? date('M j, Y', strtotime($d)) : '—';
$teachers = array_column($db->fetchAll("SELECT u.name FROM class_teachers ct INNER JOIN users u ON u.id = ct.teacher_id WHERE ct.class_id = ? ORDER BY u.name", [(int) $p['class_id']]), 'name');
logActivity('research_report', 'research', "Opened the report of research project #{$p['id']}");
i18nStart();   // Pashto / Dari: this standalone page is translated on its way out, like the app pages
$feedback = array_values(array_filter($ups, fn($u) => in_array($u['kind'], ['decision', 'submission'], true)));
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e('Research project report - ' . $p['title']); ?></title>
<style>
  :root { --brand: <?php echo $brand; ?>; --ink: #172033; --line: #cfd6e4; --soft: #f4f6fb; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 13px/1.5 <?php echo $rtl ? '"Noto Naskh Arabic", "Noto Sans Arabic", "Bahij Titr", "Amiri", Tahoma' : '"Poppins"'; ?>, "Segoe UI", Arial, sans-serif; color: var(--ink); background: #e9edf5; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 10px 18px; background: #172033; color: #fff; }
  .bar a, .bar button { font: inherit; color: #fff; background: var(--brand); border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer; text-decoration: none; }
  .bar a.ghost { background: transparent; border: 1px solid rgba(255,255,255,.4); }
  .sheet { background: #fff; width: 210mm; min-height: 280mm; margin: 18px auto; padding: 14mm; box-shadow: 0 4px 24px rgba(0,0,0,.15); }
  header.doc { display: flex; align-items: center; gap: 14px; padding-bottom: 10px; border-bottom: 3px solid var(--brand); margin-bottom: 14px; }
  header.doc img { height: 54px; width: auto; max-width: 90px; object-fit: contain; }
  header.doc h1 { margin: 0; font-size: 20px; } header.doc .school { font-size: 12px; color: #475467; text-transform: uppercase; letter-spacing: .06em; }
  [dir=rtl] header.doc .school { text-transform: none; letter-spacing: 0; }
  header.doc .right { margin-inline-start: auto; text-align: end; font-size: 12px; color: #475467; }
  h2 { font-size: 15px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid var(--line); }
  .kv { display: grid; grid-template-columns: 150px 1fr; gap: 3px 12px; margin: 0 0 6px; } .kv dt { color: #667085; } .kv dd { margin: 0; overflow-wrap: anywhere; }
  .text { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0 0 8px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 10px; } tr { page-break-inside: avoid; } thead { display: table-header-group; }
  th, td { border: 1px solid var(--line); padding: 5px 8px; text-align: start; vertical-align: top; } th { background: var(--soft); font-size: 11.5px; }
  .ok { color: #067647; font-weight: 600; } .late { color: #b54708; font-weight: 600; } .bad { color: #b42318; font-weight: 600; }
  ol.src { margin: 0; padding-inline-start: 20px; } ol.src li { margin-bottom: 4px; overflow-wrap: anywhere; }
  .grade { font-size: 18px; font-weight: 700; }
  .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; margin-top: 48px; } .sign div { border-top: 1px solid #667085; padding-top: 4px; font-size: 12px; color: #475467; text-align: center; }
  footer.doc { margin-top: 20px; font-size: 10.5px; color: #667085; display: flex; justify-content: space-between; }
  @page { size: A4 portrait; margin: 8mm; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; width: auto; padding: 0; min-height: 0; } }
  @media (max-width: 800px) { .sheet { width: auto; margin: 0; padding: 14px; } .kv { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="bar">
    <span>Research project report</span>
    <span>
        <a class="ghost" href="<?php echo htmlspecialchars(backUrl('project.php?id=' . (int) $p['id'])); ?>"><span aria-hidden="true">←</span> <span>Back</span></a>
        <?php foreach (['en' => 'English', 'ps' => 'پښتو', 'fa' => 'دری'] as $lc => $ln): ?><a class="ghost" style="<?php echo $lc === i18nLang() ? 'background:rgba(255,255,255,.18);' : ''; ?>" href="report.php?id=<?php echo (int) $p['id']; ?>&amp;lang=<?php echo $lc; ?>"><?php echo $ln; ?></a><?php endforeach; ?>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </span>
</div>
<div class="sheet">
    <header class="doc">
        <?php if ($logo !== ''): ?><img src="<?php echo APP_URL; ?>/pages/branding/logo.php?v=<?php echo urlencode(basename($logo)); ?>" alt=""><?php endif; ?>
        <div><div class="school"><?php echo $e($school); ?></div><h1>Research project report</h1></div>
        <div class="right"><b><?php echo $e($p['class_name']); ?></b><br>Generated <?php echo $e($fmt(date('Y-m-d'))); ?></div>
    </header>

    <dl class="kv">
        <dt>Project</dt><dd><b><?php echo $e($p['title']); ?></b></dd>
        <dt>Student</dt><dd><?php echo $e($p['student_name']); ?></dd>
        <dt>Class</dt><dd><?php echo $e($p['class_name']); ?></dd>
        <dt>Supervisor</dt><dd><?php echo $e($p['supervisor_name'] ?: implode(', ', $teachers) ?: '—'); ?></dd>
        <dt>Stage</dt><dd><?php echo $e($statuses[$p['status']][0]); ?></dd>
        <dt>Proposal sent</dt><dd><?php echo $e($fmt($p['submitted_at'])); ?></dd>
        <dt>Approved</dt><dd><?php echo $e($fmt($p['approved_at'])); ?></dd>
        <?php if ($p['status'] === 'completed'): ?><dt>Completed</dt><dd><?php echo $e($fmt($p['completed_at'])); ?></dd><?php endif; ?>
    </dl>

    <h2>Proposal</h2>
    <p class="text"><b>Research question</b><br><?php echo $e($p['question'] !== '' ? $p['question'] : '—'); ?></p>
    <p class="text"><b>Plan</b><br><?php echo $e((string) $p['plan'] !== '' ? $p['plan'] : '—'); ?></p>

    <h2>Milestones</h2>
    <?php if (!$ms): ?><p class="text">No milestones.</p><?php else: ?>
    <table><thead><tr><th style="width:34px;">#</th><th>Milestone</th><th style="width:100px;">Due</th><th style="width:150px;">Status</th></tr></thead><tbody>
        <?php foreach ($ms as $i => $m): ?><tr><td><?php echo $i + 1; ?></td><td><?php echo $e($m['title']); ?></td><td><?php echo $e($fmt($m['due_date'])); ?></td>
            <td class="<?php echo $m['status'] === 'accepted' ? 'ok' : ($m['status'] === 'changes' ? 'bad' : ($m['status'] === 'submitted' ? 'late' : '')); ?>"><?php echo $e($msS[$m['status']][0]); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <h2>Hand-ins and teacher feedback</h2>
    <?php if (!$feedback): ?><p class="text">Nothing yet.</p><?php else: ?>
    <table><thead><tr><th style="width:100px;">Date</th><th style="width:130px;">By</th><th>What</th></tr></thead><tbody>
        <?php foreach ($feedback as $u): ?><tr><td><?php echo $e($fmt($u['created_at'])); ?></td><td><?php echo $e((string) $u['author_name']); ?></td>
            <td><?php echo $u['kind'] === 'submission' ? '<i>Handed in: ' . $e((string) $u['milestone_title']) . '</i><br>' : ''; ?><span class="text"><?php echo $e((string) $u['body']); ?></span><?php echo $u['file_name'] ? '<br><small>File: ' . $e($u['file_name']) . '</small>' : ''; ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>

    <h2>Sources</h2>
    <?php if (!$sources): ?><p class="text">No sources listed.</p><?php else: ?>
    <ol class="src"><?php foreach ($sources as $s): ?><li><?php echo $e(trim(($s['authors'] ? $s['authors'] . '. ' : '') . $s['title'] . ($s['year'] ? ' (' . (int) $s['year'] . ')' : '') . '.')); ?><?php echo $s['url'] ? ' ' . $e($s['url']) : ''; ?></li><?php endforeach; ?></ol>
    <?php endif; ?>

    <?php if ($p['status'] === 'completed'): ?>
    <h2>Result</h2>
    <p class="text"><span class="grade"><?php echo $p['grade'] !== null ? $e(rtrim(rtrim(number_format((float) $p['grade'], 2), '0'), '.') . ' / 100') : 'Completed (no grade given)'; ?></span></p>
    <?php if ($p['final_feedback']): ?><p class="text"><?php echo $e($p['final_feedback']); ?></p><?php endif; ?>
    <?php endif; ?>

    <div class="sign"><div>Student</div><div>Supervisor</div><div>Head of school / Principal</div></div>
    <footer class="doc"><span><?php echo $e($school); ?></span><span><?php echo $e($p['title']); ?></span></footer>
</div>
</body>
</html>
