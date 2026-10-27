<?php
/**
 * Route matrix: EVERY page of the system (pages/**.php) is requested as a visitor, a student outside the class, a student of the class, a teacher of the class and the
 * administrator, with the ids of real fixtures — a page must never crash (HTTP 5xx), print a PHP error, or show private content to a visitor who is not signed in.
 */
function rmFixtures() {
    static $f = null; if ($f) return $f;
    $db = TW('db'); $admin = mkUser('RmAdmin', 'teacher', 1); $t = mkUser('RmTeacher', 'teacher'); $s = mkUser('RmStudent'); $o = mkUser('RmOutsider');
    $c = mkClass($t, 'RmClass', [$s]); $now = date('Y-m-d H:i:s'); $later = date('Y-m-d H:i:s', strtotime('+3 days'));
    $topic = (int) $db->insert('class_topics', ['class_id' => $c, 'name' => 'Rivers', 'position' => 1]);
    $lec = (int) $db->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Rivers lecture', 'status' => 'published', 'content' => "## Rivers\n\n- water\n- land", 'topic_id' => $topic]);
    $as = (int) $db->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Essay', 'description' => "Write:\n- one\n- two", 'due_date' => $later, 'max_marks' => 20]);
    $sub = (int) $db->insert('submissions', ['assignment_id' => $as, 'student_id' => $s, 'content' => 'My answer', 'status' => 'submitted']);
    $qz = (int) $db->insert('quizzes', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Quiz', 'description' => 'A quiz', 'due_date' => $later]);
    $qq = (int) $db->insert('quiz_questions', ['quiz_id' => $qz, 'question' => 'What is a river?', 'question_type' => 'choice', 'marks' => 2, 'position' => 1]);
    $db->insert('quiz_options', ['question_id' => $qq, 'option_text' => 'Water', 'is_correct' => 1, 'position' => 1]); $db->insert('quiz_options', ['question_id' => $qq, 'option_text' => 'Rock', 'is_correct' => 0, 'position' => 2]);
    $db->insert('quiz_attempts', ['quiz_id' => $qz, 'student_id' => $s, 'started_at' => $now, 'submitted_at' => $now, 'score' => 2, 'max_score' => 2, 'timed_out' => 0]);
    $ex = (int) $db->insert('exams', ['class_id' => $c, 'created_by' => $t, 'title' => 'Mid exam', 'kind' => 'mid', 'exam_date' => $later, 'max_marks' => 30, 'instructions' => "Bring:\n- a pen"]);
    $an = (int) $db->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => "Quiz on Monday\n\nBring:\n- a pen"]);
    $ev = (int) $db->insert('class_events', ['class_id' => $c, 'created_by' => $t, 'title' => 'Trip', 'description' => 'A trip', 'starts_at' => $later]);
    $th = (int) $db->insert('forum_threads', ['class_id' => $c, 'user_id' => $t, 'title' => 'Floods', 'body' => 'Why?']); $db->insert('forum_posts', ['thread_id' => $th, 'user_id' => $s, 'body' => 'Because rain']);
    $rs = (int) $db->insert('resources', ['class_id' => $c, 'uploaded_by' => $t, 'title' => 'Notes', 'description' => 'Notes', 'category' => 'notes']);
    $ri = (int) $db->insert('research_items', ['class_id' => $c, 'added_by' => $t, 'kind' => 'book', 'title' => 'A book', 'summary' => 'Useful']);
    return $f = compact('admin', 't', 's', 'o', 'c', 'topic', 'lec', 'as', 'sub', 'qz', 'ex', 'an', 'ev', 'th', 'rs', 'ri');
}

/** The ids each module understands: ?id= is the main object of the module, ?class_id= the class. */
function rmUrls($page, array $f) {
    $mod = explode('/', $page)[0] ?? ''; $main = ['announcements' => $f['an'], 'assignments' => $f['as'], 'exams' => $f['ex'], 'events' => $f['ev'], 'forums' => $f['th'], 'lectures' => $f['lec'], 'quizzes' => $f['qz'],
        'resources' => $f['rs'], 'research' => $f['ri'], 'classes' => $f['c'], 'attendance' => $f['c'], 'marks' => $f['c'], 'settings' => $f['c'], 'students' => $f['c'], 'reports' => $f['c']][$mod] ?? $f['c'];
    $u = ['pages/' . $page, 'pages/' . $page . '?id=' . $main, 'pages/' . $page . '?class_id=' . $f['c'] . '&id=' . $f['c']];
    if ($mod === 'assignments') $u[] = 'pages/' . $page . '?id=' . $f['as'] . '&s=' . $f['s']; if ($mod === 'classes') $u[] = 'pages/' . $page . '?class_id=' . $f['c'] . '&student_id=' . $f['s'];
    return array_unique($u);
}

/** display_errors is off, so PHP warnings only reach logs/php-error.log: remember where the log ends now, read what was added later. */
function rmLogPos() { $f = dirname(__DIR__) . '/logs/php-error.log'; clearstatcache(); return is_file($f) ? filesize($f) : 0; }
function rmLogNew($pos) {
    $f = dirname(__DIR__) . '/logs/php-error.log'; if (!is_file($f)) return [];
    $fh = fopen($f, 'r'); fseek($fh, $pos); $txt = (string) stream_get_contents($fh); fclose($fh); $out = [];
    foreach (explode("\n", $txt) as $l) if (preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|SQLSTATE|Uncaught/', $l)) $out[preg_replace('/^\[[^\]]*\]\s*/', '', $l)] = true;
    return array_keys($out);
}

const RM_PUBLIC = ['auth/login.php', 'auth/register.php', 'auth/forgot_password.php', 'auth/reset_password.php', 'auth/code_login.php', 'auth/pending.php', 'auth/logout.php', 'auth/teacher_invite.php', 'auth/support.php', 'auth/two_factor.php',
    'email/guardian_stop.php', 'email/unsubscribe.php', 'whatsapp/webhook.php', 'notifications/latest.php'];
const RM_PHP_ERROR = '/(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error|Parse error)(?:<\/b>)?:\s.{0,300}? on line (?:<b>)?\d+/s';

test('route matrix: every page as every kind of visitor — no crash, no PHP error text, nothing private for a visitor who is not signed in', function () {
    $f = rmFixtures(); $pages = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/pages', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (substr($file, -4) !== '.php') continue; $rel = str_replace(dirname(__DIR__) . '/pages/', '', $file);
        if (basename($rel)[0] === '_') continue;   // partial templates are not pages
        $pages[] = $rel;
    }
    sort($pages); ok(count($pages) > 120, 'pages found: ' . count($pages));
    $who = ['visitor' => anon(), 'outsider' => client($f['o']), 'student' => client($f['s']), 'teacher' => client($f['t']), 'admin' => client($f['admin'])];
    $bad = []; $n = 0; $slow = []; $pos = rmLogPos();
    foreach ($pages as $page) {
        if ($page === 'auth/logout.php') continue;   // it ends the session of whoever calls it
        foreach (rmUrls($page, $f) as $url) foreach ($who as $role => $h) {
            if ($role === 'visitor' && !in_array($page, RM_PUBLIC, true) === false) { /* public pages are asked as a visitor too */ }
            $t0 = microtime(true); $h->get($url); $n++; $ms = (microtime(true) - $t0) * 1000; if ($ms > 1500) $slow[] = "$role $url " . round($ms) . ' ms';
            if ($h->code >= 500 || $h->code === 0) $bad[] = "$role GET $url → HTTP {$h->code}";
            elseif (preg_match(RM_PHP_ERROR, $h->body, $m)) $bad[] = "$role GET $url → PHP error: " . substr(preg_replace('/\s+/', ' ', strip_tags($m[0])), 0, 160);
            elseif ($role === 'visitor' && !in_array($page, RM_PUBLIC, true) && $h->code === 200 && strpos($h->body, 'name="password"') === false && strpos($h->body, 'login') === false) $bad[] = "visitor GET $url → HTTP 200 with a page that is not the sign-in page (private page open to a visitor?)";
        }
    }
    fwrite(STDERR, "route matrix: $n requests; slowest over 1.5 s: " . count($slow) . ($slow ? ' (' . implode('; ', array_slice($slow, 0, 5)) . ')' : '') . "\n");
    foreach (rmLogNew($pos) as $l) $bad[] = 'error log: ' . substr($l, 0, 220);
    ok(!$bad, count($bad) . " problem(s):\n   " . implode("\n   ", array_slice($bad, 0, 40)));
});
