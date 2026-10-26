<?php
// SmartClass - demo classes owned by the school admin account admin@test.com (command line only).
//
//   php config/seed_admin_demo.php            give admin@test.com five classes of its own (copies of demo classes 30-34)
//   php config/seed_admin_demo.php --remove   delete those classes again (class codes starting with ADMD) and nothing else
//
// Each class is made with copyClass (topics, lectures, assignments, quizzes, resources) and then gets the same students,
// published lectures, the exams with their marks, events, timetable, announcements, research items, forum topics,
// attendance, marking scheme and the students' submissions of the class it was copied from.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/db.php';
if (!function_exists('generateClassCode')) { function generateClassCode($length = 8) { return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length)); } }   // the web version lives in includes/auth.php (needs a login)

if (in_array('--remove', array_slice($argv, 1), true)) {
    echo 'Removed ', $db->delete('classes', "class_code LIKE 'ADMD%'"), " admin demo classes (with all their content).\n";
    exit;
}
$admin = $db->fetchOne("SELECT id FROM users WHERE email = 'admin@test.com' AND is_admin = 1");
if (!$admin) exit("admin@test.com must exist (and be the school admin) first.\n");
$aid = (int) $admin['id'];
if ($db->fetchOne("SELECT id FROM classes WHERE class_code LIKE 'ADMD%' LIMIT 1")) exit("Already added. Run with --remove first to start over.\n");

/** Copy rows of $table where $where, giving them the new class (and the admin as author in the listed columns). Returns [old id => new id]. */
$copyRows = function ($table, $where, array $params, array $set) use ($db) {
    $map = [];
    foreach ($db->fetchAll("SELECT * FROM {$table} WHERE {$where}", $params) as $row) {
        $old = $row['id']; unset($row['id']);
        foreach ($set as $k => $v) if (array_key_exists($k, $row)) $row[$k] = $v;
        $map[(int) $old] = (int) $db->insert($table, $row);
    }
    return $map;
};

$made = [];
foreach ([30, 31, 32, 33, 34] as $src_id) {
    $src = $db->fetchOne("SELECT * FROM classes WHERE id = ?", [$src_id]);
    if (!$src) continue;
    $r = copyClass($src, $aid, $src['class_name'] . ' · Admin', ['lectures', 'assignments', 'quizzes', 'resources']);
    $cid = (int) $r['class_id'];
    do { $code = 'ADMD' . strtoupper(bin2hex(random_bytes(2))); } while ($db->exists('classes', 'class_code', $code));
    $db->update('classes', ['class_code' => $code], 'id = ?', [$cid]);

    $db->query("UPDATE lectures SET status = 'published', publish_at = NULL, notified_at = NOW() WHERE class_id = ?", [$cid]);
    foreach ($db->fetchAll("SELECT title, due_date FROM assignments WHERE class_id = ?", [$src_id]) as $a) {
        $db->query("UPDATE assignments SET due_date = ? WHERE class_id = ? AND title = ?", [$a['due_date'], $cid, $a['title']]);
    }
    foreach ($db->fetchAll("SELECT student_id, joined_at FROM class_members WHERE class_id = ?", [$src_id]) as $m) {
        $db->insert('class_members', ['class_id' => $cid, 'student_id' => $m['student_id'], 'joined_at' => $m['joined_at']]);
    }
    $new = ['class_id' => $cid];
    $ex = $copyRows('exams', 'class_id = ?', [$src_id], $new + ['created_by' => $aid]);
    foreach ($ex as $old_ex => $new_ex) $copyRows('exam_marks', 'exam_id = ?', [$old_ex], ['exam_id' => $new_ex]);
    $copyRows('class_events', 'class_id = ?', [$src_id], $new + ['created_by' => $aid]);
    $copyRows('class_schedule', 'class_id = ?', [$src_id], $new + ['created_by' => $aid]);
    $copyRows('announcements', 'class_id = ?', [$src_id], $new + ['user_id' => $aid, 'notified_at' => date('Y-m-d H:i:s')]);
    $copyRows('research_items', 'class_id = ?', [$src_id], $new + ['added_by' => $aid]);
    $copyRows('attendance', 'class_id = ?', [$src_id], $new + ['marked_by' => $aid]);
    $copyRows('class_marking_scheme', 'class_id = ?', [$src_id], $new);
    foreach ($copyRows('forum_threads', 'class_id = ?', [$src_id], $new) as $old_t => $new_t) $copyRows('forum_posts', 'thread_id = ?', [$old_t], ['thread_id' => $new_t]);
    // the students' work: matched by assignment title
    $new_asg = array_column($db->fetchAll("SELECT id, title FROM assignments WHERE class_id = ?", [$cid]), 'id', 'title');
    foreach ($db->fetchAll("SELECT id, title FROM assignments WHERE class_id = ?", [$src_id]) as $a) {
        if (!isset($new_asg[$a['title']])) continue;
        foreach ($db->fetchAll("SELECT * FROM submissions WHERE assignment_id = ?", [$a['id']]) as $s) {
            unset($s['id']); $s['assignment_id'] = $new_asg[$a['title']]; if ($s['graded_by']) $s['graded_by'] = $aid;
            $db->insert('submissions', $s);
        }
    }
    $made[] = $src['class_name'] . ' · Admin (#' . $cid . ')';
}
// a few more reading-list items so the Research page is not thin
$extra = [
    ['paper', 'A Brief History of Mathematical Notation', 'Florian Cajori', 1929, 'https://archive.org/details/historyofmathema00cajouoft', 'How symbols for numbers and operations developed.', 'history, notation'],
    ['article', 'The Feynman Lectures on Physics — Introduction', 'R. P. Feynman', 1963, 'https://www.feynmanlectures.caltech.edu/I_01.html', 'Atoms in motion: a first look at physics.', 'physics, atoms'],
    ['book', 'Pride and Prejudice', 'Jane Austen', 1813, 'https://www.gutenberg.org/ebooks/1342', 'Free full text for the class reading list.', 'novel, classic'],
    ['dataset', 'Periodic Table of Elements (open data)', 'PubChem', 2023, 'https://pubchem.ncbi.nlm.nih.gov/periodic-table/', 'Properties of all elements, downloadable.', 'chemistry, data'],
    ['project', 'Cell Model Project Guide', 'School Biology Team', 2026, null, 'Build a 3D model of a plant or animal cell.', 'biology, project'],
];
$cls = array_column($db->fetchAll("SELECT id FROM classes WHERE class_code LIKE 'ADMD%' ORDER BY id"), 'id');
foreach ($extra as $i => [$kind, $title, $authors, $year, $url, $sum, $tags]) {
    $db->insert('research_items', ['class_id' => $cls[$i % count($cls)], 'added_by' => $aid, 'kind' => $kind, 'title' => $title, 'authors' => $authors, 'year' => $year, 'url' => $url, 'summary' => $sum, 'tags' => $tags]);
}
$db->insert('activity_log', ['user_id' => $aid, 'action' => 'seed_demo', 'module' => 'system', 'details' => '[demo] admin classes added', 'ip_address' => 'cli']);
echo "Added for admin@test.com:\n  ", implode("\n  ", $made), "\n";
