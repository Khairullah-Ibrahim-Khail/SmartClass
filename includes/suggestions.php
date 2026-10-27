<?php
/**
 * SmartClass - teacher "Suggestions" (a recommender, not a controller)
 *
 * Looks at the data of the classes a teacher teaches and lists, most urgent first, what is worth the teacher's attention. Plain rules and SQL — no AI,
 * nothing is sent, graded or changed: every suggestion is a link to the page where the teacher decides. A teacher can dismiss one for 14 days.
 *
 *   suggestionsFor($teacher_id, $limit)   [['key', 'level' (high|medium|low), 'icon', 'title', 'detail', 'url', 'class']] not dismissed, sorted
 *   suggestionDismiss($teacher_id, $key)  hide one for 14 days
 *
 * Rules (thresholds are constants below): low attendance (< 75 % over 30 days, at least 4 marked days) · two or more past-due assignments not handed in ·
 * work waiting to be graded for more than 7 days · attendance not taken for 7 days · a quiz with an average below 50 % · students who have not signed in for 14 days.
 */
const SUGG_ATT_MIN_PCT = 75, SUGG_ATT_MIN_DAYS = 4, SUGG_MISSING_MIN = 2, SUGG_GRADE_WAIT_DAYS = 7, SUGG_GRADE_MIN = 3, SUGG_QUIZ_AVG_PCT = 50, SUGG_QUIZ_MIN_ATTEMPTS = 3, SUGG_INACTIVE_DAYS = 14, SUGG_DISMISS_DAYS = 14;

function suggestionsFor($teacher_id, $limit = 8) {
    global $db; $teacher_id = (int) $teacher_id; $out = [];
    $classes = $db->fetchAll("SELECT c.id, c.class_name FROM classes c WHERE c.is_active = 1 AND " . teachesClassSql('c') . " ORDER BY c.class_name", [$teacher_id]);
    if (!$classes) return [];
    $base = APP_URL . '/pages/';
    foreach ($classes as $c) {
        $cid = (int) $c['id']; $cn = $c['class_name'];
        // 1. low attendance
        foreach ($db->fetchAll("SELECT u.id, u.name, COUNT(*) AS days, SUM(a.status <> 'absent') AS here FROM attendance a INNER JOIN users u ON u.id = a.student_id
                 INNER JOIN class_members m ON m.class_id = a.class_id AND m.student_id = a.student_id
                 WHERE a.class_id = ? AND a.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY u.id, u.name HAVING COUNT(*) >= ? AND SUM(a.status <> 'absent') * 100 < ? * COUNT(*) ORDER BY SUM(a.status <> 'absent') / COUNT(*) LIMIT 5", [$cid, SUGG_ATT_MIN_DAYS, SUGG_ATT_MIN_PCT]) as $r) {
            $pct = (int) round(100 * $r['here'] / $r['days']);
            $out[] = ['key' => "att:$cid:{$r['id']}", 'level' => 'high', 'icon' => 'fa-user-clock', 'class' => $cn, 'title' => $r['name'] . ' attended only ' . $pct . '% of the last 30 days',
                'detail' => 'Present ' . (int) $r['here'] . ' of ' . (int) $r['days'] . ' marked days. Consider talking to the student or the family.', 'url' => $base . 'classes/student.php?class_id=' . $cid . '&student_id=' . (int) $r['id']];
        }
        // 2. past-due work not handed in
        foreach ($db->fetchAll("SELECT u.id, u.name, COUNT(*) AS n FROM class_members m INNER JOIN users u ON u.id = m.student_id
                 INNER JOIN assignments a ON a.class_id = m.class_id AND a.due_date < NOW() AND a.due_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = m.student_id
                 WHERE m.class_id = ? AND s.id IS NULL GROUP BY u.id, u.name HAVING n >= ? ORDER BY n DESC LIMIT 5", [$cid, SUGG_MISSING_MIN]) as $r) {
            $out[] = ['key' => "miss:$cid:{$r['id']}", 'level' => 'high', 'icon' => 'fa-file-circle-exclamation', 'class' => $cn, 'title' => $r['name'] . ' has not handed in ' . (int) $r['n'] . ' assignments',
                'detail' => 'Past-due in the last 30 days. A reminder or a conversation may help.', 'url' => $base . 'classes/student.php?class_id=' . $cid . '&student_id=' . (int) $r['id']];
        }
        // 3. grading backlog
        foreach ($db->fetchAll("SELECT a.id, a.title, COUNT(*) AS n, MIN(s.submitted_at) AS oldest FROM assignments a INNER JOIN submissions s ON s.assignment_id = a.id AND s.status IN ('submitted', 'late')
                 AND s.submitted_at < DATE_SUB(NOW(), INTERVAL ? DAY) WHERE a.class_id = ? GROUP BY a.id, a.title HAVING n >= ? ORDER BY n DESC LIMIT 3", [SUGG_GRADE_WAIT_DAYS, $cid, SUGG_GRADE_MIN]) as $r) {
            $out[] = ['key' => "grade:{$r['id']}", 'level' => 'medium', 'icon' => 'fa-inbox', 'class' => $cn, 'title' => (int) $r['n'] . ' submissions wait for grading: ' . $r['title'],
                'detail' => 'The oldest was handed in on ' . date('M j', strtotime($r['oldest'])) . '. Students are waiting for feedback.', 'url' => $base . 'assignments/view.php?id=' . (int) $r['id']];
        }
        // 4. attendance not taken
        $att = $db->fetchOne("SELECT MAX(date) AS last_day, (SELECT COUNT(*) FROM class_members WHERE class_id = ?) AS members FROM attendance WHERE class_id = ?", [$cid, $cid]);
        if ($att && $att['last_day'] && (int) $att['members'] > 0 && $att['last_day'] < date('Y-m-d', strtotime('-' . 7 . ' days')))
            $out[] = ['key' => "noatt:$cid", 'level' => 'low', 'icon' => 'fa-clipboard-check', 'class' => $cn, 'title' => 'Attendance was last taken on ' . date('M j', strtotime($att['last_day'])),
                'detail' => 'No attendance for more than 7 days in this class.', 'url' => attendanceUrl($cid)];
        // 5. a hard quiz
        foreach ($db->fetchAll("SELECT q.id, q.title, COUNT(*) AS n, AVG(t.score / t.max_score) * 100 AS avgp FROM quizzes q INNER JOIN quiz_attempts t ON t.quiz_id = q.id AND t.submitted_at IS NOT NULL AND t.max_score > 0
                 WHERE q.class_id = ? AND t.submitted_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) GROUP BY q.id, q.title HAVING n >= ? AND avgp < ? ORDER BY avgp LIMIT 2", [$cid, SUGG_QUIZ_MIN_ATTEMPTS, SUGG_QUIZ_AVG_PCT]) as $r) {
            $out[] = ['key' => "quiz:{$r['id']}", 'level' => 'medium', 'icon' => 'fa-chart-line', 'class' => $cn, 'title' => 'The class average on "' . $r['title'] . '" is ' . (int) round($r['avgp']) . '%',
                'detail' => 'Based on ' . (int) $r['n'] . ' attempts. The topic may need to be taught again.', 'url' => $base . 'quizzes/view.php?id=' . (int) $r['id']];
        }
        // 6. students who stopped signing in
        $ina = $db->fetchAll("SELECT u.name FROM class_members m INNER JOIN users u ON u.id = m.student_id WHERE m.class_id = ? AND u.is_active = 1
                 AND COALESCE(u.last_login, m.joined_at) < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY u.name LIMIT 20", [$cid, SUGG_INACTIVE_DAYS]);
        if ($ina) { $names = array_column($ina, 'name');
            $out[] = ['key' => "idle:$cid", 'level' => 'low', 'icon' => 'fa-user-slash', 'class' => $cn, 'title' => count($ina) . ' student' . (count($ina) > 1 ? 's have' : ' has') . ' not signed in for ' . SUGG_INACTIVE_DAYS . ' days',
                'detail' => implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' and ' . (count($names) - 5) . ' more' : ''), 'url' => $base . 'classes/people.php?id=' . $cid]; }
    }
    $hidden = array_flip(array_column($db->fetchAll("SELECT suggestion_key FROM suggestion_dismissals WHERE user_id = ? AND dismissed_until > NOW()", [$teacher_id]), 'suggestion_key'));
    $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
    $out = array_values(array_filter($out, fn($s) => !isset($hidden[$s['key']])));
    usort($out, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return array_slice($out, 0, $limit);
}

function suggestionDismiss($teacher_id, $key) {
    global $db; $key = mb_substr((string) $key, 0, 120);
    if (!preg_match('/^[a-z]+:[0-9]+(:[0-9]+)?$/', $key)) return false;
    $db->query("INSERT INTO suggestion_dismissals (user_id, suggestion_key, dismissed_until) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY)) ON DUPLICATE KEY UPDATE dismissed_until = VALUES(dismissed_until)", [(int) $teacher_id, $key, SUGG_DISMISS_DAYS]);
    return true;
}

/** The suggestions that can become a message to students, with the proposed text (the teacher reads, edits and approves it — nothing is sent by itself). */
function suggestionAction($key, $class_name = '') {
    $t = explode(':', $key)[0];
    $cn = $class_name !== '' ? ' in ' . $class_name : '';
    $map = [
        'miss' => ['label' => 'Remind the student', 'text' => "You have assignments{$cn} that are past due. Please hand them in, or talk to me if you need help or more time."],
        'att'  => ['label' => 'Check in with the student', 'text' => "I noticed you missed several lessons{$cn}. Is everything okay? Please talk to me if there is anything I can help with."],
        'idle' => ['label' => 'Remind the students', 'text' => "We have not seen you{$cn} for a while. Please sign in and look at the new lectures and work."],
    ];
    return $map[$t] ?? null;
}

/** Who would receive the message of a suggestion: [student ids]. Checked against the teacher's own class and its members. */
function suggestionRecipients($teacher_id, $key) {
    global $db;
    if (!preg_match('/^(miss|att|idle):([0-9]+)(?::([0-9]+))?$/', $key, $m)) return null;
    $cid = (int) $m[2];
    if (!$db->fetchOne("SELECT c.id FROM classes c WHERE c.id = ? AND " . teachesClassSql('c'), [$cid, (int) $teacher_id])) return null;
    if ($m[1] === 'idle') return array_map('intval', array_column($db->fetchAll("SELECT u.id FROM class_members m INNER JOIN users u ON u.id = m.student_id WHERE m.class_id = ? AND u.is_active = 1
        AND COALESCE(u.last_login, m.joined_at) < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 30", [$cid, SUGG_INACTIVE_DAYS]), 'id'));
    $sid = (int) ($m[3] ?? 0);
    return $db->fetchOne("SELECT 1 FROM class_members WHERE class_id = ? AND student_id = ?", [$cid, $sid]) ? [$sid] : null;
}
