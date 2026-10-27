<?php
/**
 * Response time with a realistic school: 3 classes x 40 students, 12 assignments each, ~60 % submitted, 20 days of attendance, quiz attempts.
 * Planning figure in docs/CAPACITY.md: "a normal page is finished in a fraction of a second" (0.2 s per page in the worker formula).
 * Gate used here: the MEDIAN of 5 requests of every key page must be under 0.5 s on the test machine, and no single request over 2 s.
 * The measured numbers are printed so they can be put in qa/test-results.md — a faster or slower machine moves them.
 */
test('performance: key pages with 120 students, 36 assignments, 1 440 submissions, 2 400 attendance rows — median under 0.5 s', function () {
    $db = TW('db'); $pdo = TW('pdo'); $hash = password_hash(TEST_PW, PASSWORD_DEFAULT); $now = date('Y-m-d H:i:s');
    $admin = mkUser('PfAdmin', 'teacher', 1); $t = mkUser('PfTeacher', 'teacher'); $classes = []; $students = [];
    $ins = $pdo->prepare("INSERT INTO users (name, email, password, role, is_approved) VALUES (?, ?, ?, 'student', 1)");
    for ($c = 0; $c < 3; $c++) {
        $ids = []; for ($i = 0; $i < 40; $i++) { $ins->execute(["Pupil $c-$i", "pf{$c}_{$i}@example.invalid", $hash]); $ids[] = (int) $pdo->lastInsertId(); }
        $cid = mkClass($t, "Perf class $c", $ids); $classes[] = $cid; $students[$cid] = $ids;
        for ($a = 0; $a < 12; $a++) {
            $aid = (int) $db->insert('assignments', ['class_id' => $cid, 'teacher_id' => $t, 'title' => "Task $a", 'description' => 'Do it', 'due_date' => date('Y-m-d H:i:s', strtotime('+' . ($a - 6) . ' days')), 'max_marks' => 20]);
            foreach ($ids as $k => $sid) if ($k % 5 < 3) $db->insert('submissions', ['assignment_id' => $aid, 'student_id' => $sid, 'content' => 'answer', 'status' => $k % 2 ? 'graded' : 'submitted', 'marks_obtained' => $k % 2 ? 10 + $k % 10 : null]);
        }
        $qz = (int) $db->insert('quizzes', ['class_id' => $cid, 'teacher_id' => $t, 'title' => 'Quiz', 'due_date' => date('Y-m-d H:i:s', strtotime('+2 days'))]);
        foreach ($ids as $sid) $db->insert('quiz_attempts', ['quiz_id' => $qz, 'student_id' => $sid, 'started_at' => $now, 'submitted_at' => $now, 'score' => 3, 'max_score' => 5, 'timed_out' => 0]);
        for ($d = 0; $d < 20; $d++) foreach ($ids as $k => $sid) if ($k < 20) $db->insert('attendance', ['class_id' => $cid, 'student_id' => $sid, 'date' => date('Y-m-d', strtotime("-$d days")), 'session' => 1, 'status' => $k % 7 ? 'present' : 'absent']);
    }
    $c0 = $classes[0]; $s0 = $students[$c0][0]; $as0 = $db->fetchOne("SELECT id FROM assignments WHERE class_id = ? ORDER BY id LIMIT 1", [$c0])['id'];
    $pages = [
        'student' => ['pages/dashboard.php', "pages/classes/stream.php?id=$c0", "pages/classes/classwork.php?id=$c0", "pages/assignments/view.php?id=$as0", "pages/classes/grades.php?id=$c0"],
        'teacher' => ['pages/dashboard.php', "pages/classes/people.php?id=$c0", "pages/classes/grades.php?id=$c0", "pages/assignments/view.php?id=$as0", "pages/attendance/class.php?id=$c0", "pages/classes/insights.php?id=$c0", 'pages/reports/index.php'],
        'admin'   => ['pages/dashboard.php', 'pages/settings/system.php', 'pages/teachers/index.php'],
    ];
    $who = ['student' => client($s0), 'teacher' => client($t), 'admin' => client($admin)]; $rows = []; $bad = [];
    foreach ($pages as $role => $urls) foreach ($urls as $u) {
        $who[$role]->get($u); if ($who[$role]->code !== 200 || strlen($who[$role]->body) < 5000) { $bad[] = "$role $u → HTTP {$who[$role]->code} " . $who[$role]->redirect . " (" . strlen($who[$role]->body) . " bytes) — not a real page"; continue; }   // warm-up request, not timed
        $ts = []; for ($i = 0; $i < 5; $i++) { $t0 = microtime(true); $who[$role]->get($u); $ts[] = (microtime(true) - $t0) * 1000; }
        sort($ts); $med = $ts[2]; $max = $ts[4]; $rows[] = sprintf('%-8s %-52s median %5.0f ms  max %5.0f ms  %s', $role, $u, $med, $max, round(strlen($who[$role]->body) / 1024) . ' KB');
        if ($med > 500) $bad[] = "$role $u median " . round($med) . ' ms (limit 500)'; if ($max > 2000) $bad[] = "$role $u slowest " . round($max) . ' ms (limit 2000)';
    }
    fwrite(STDERR, "performance (PHP " . PHP_VERSION . ", built-in server, 1 worker, " . php_uname('s') . ' ' . php_uname('m') . "):\n  " . implode("\n  ", $rows) . "\n");
    ok(!$bad, implode("\n   ", $bad));
});
