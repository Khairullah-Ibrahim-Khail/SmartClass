<?php
/**
 * Run a lecture's code on the school's code runner (languages a browser cannot run: C, C++, C#, Java, PHP, Go, Rust, SQL …).
 * POST lecture_id, lang (the tag written after ```), code, stdin (optional) — CSRF via X-CSRF-Token. JSON answer.
 * Only people who can open the lecture may run; 12 runs a minute per person; the runner address comes from Settings › Code runner.
 * Python, JavaScript, HTML and CSS never come here — they run inside the reader's own browser.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fail = function ($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail('Use POST.', 405);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);

$lecture = $db->fetchOne("SELECT * FROM lectures WHERE id = ?", [(int) ($_POST['lecture_id'] ?? 0)]);
$class = $lecture ? getAccessibleClass($lecture['class_id'], $current_user_id, $current_user_role) : null;
if (!$class || (!$class['is_owner'] && lectureState($lecture) !== 'published')) $fail('Lecture not found.', 404);
$lang = strtolower(trim((string) ($_POST['lang'] ?? '')));
if (codeRunKind($lang) !== 'remote') $fail('Running this language is not set up on this school\'s server.', 422);
$guard = rateGuard('code_run', (string) $current_user_id, CODE_RUN_PER_MINUTE, 60);
if (!$guard['ok']) $fail('You are running code very fast. Please wait ' . rateWaitText($guard['wait']) . ' and try again.', 429);

$r = codeRunRemote($lang, (string) ($_POST['code'] ?? ''), (string) ($_POST['stdin'] ?? ''));
if (!$r['ok']) $fail($r['error'], 502);
echo json_encode($r);
