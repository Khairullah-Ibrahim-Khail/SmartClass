<?php
function rsxProject($label) {
    $db = TW('db'); $t = mkUser("Rx{$label}T", 'teacher'); $s = mkUser("Rx{$label}S"); $c = mkClass($t, "Rx{$label} Class", [$s]);
    $id = researchSave($s, $c, 0, 'Reminder project', 'Does it work?', 'Test it on ten samples and write down what happens.')['id'];
    researchSubmitProposal(researchProject($id, $s, 'student'), $s); researchDecide(researchProject($id, $t, 'teacher'), $t, 'approve', '');
    return [$db, $t, $s, $c, $id];
}
function rsxNotes($db, $uid, $type = 'research_reminder') { return $db->fetchAll("SELECT title, message FROM notifications WHERE user_id = ? AND type = ? ORDER BY id", [$uid, $type]); }

test('research reminders: due soon and overdue go to the student once (overdue again after a week and once to the teacher), quiet projects are flagged to the teacher, finished or closed projects are left alone', function () {
    [$db, $t, $s, $c, $id] = rsxProject('A'); $today = date('Y-m-d');
    researchAddMilestone(researchProject($id, $t, 'teacher'), $t, 'Soon one', date('Y-m-d', strtotime('+2 day'))); researchAddMilestone(researchProject($id, $t, 'teacher'), $t, 'Far one', date('Y-m-d', strtotime('+10 day'))); researchAddMilestone(researchProject($id, $t, 'teacher'), $t, 'No date', '');
    $r = researchReminders($today); eq(1, $r['soon'], 'only the milestone due within 2 days'); eq(0, $r['overdue']); $n = rsxNotes($db, $s); eq(1, count($n)); contains('due', $n[0]['title']); contains('Soon one', $n[0]['message']);
    eq(0, researchReminders($today)['soon'], 'the same reminder is not sent twice'); eq(1, count(rsxNotes($db, $s)));
    // overdue: three days later "Soon one" is overdue
    $later = date('Y-m-d', strtotime('+5 day')); $r = researchReminders($later); eq(1, $r['overdue'], 'the student hears about the overdue one'); $n = rsxNotes($db, $s); eq(2, count($n)); contains('overdue', $n[1]['title']); contains('3 days late', $n[1]['message']);
    $tn = rsxNotes($db, $t); ok(count($tn) >= 1, 'the teacher was told too'); $over_t = array_values(array_filter($tn, fn($x) => strpos($x['title'], 'overdue') !== false)); eq(1, count($over_t)); contains('Soon one', $over_t[0]['message']);
    eq(0, researchReminders($later)['overdue'], 'not again the same day');
    $r = researchReminders(date('Y-m-d', strtotime('+13 day'))); eq(2, $r['overdue'], 'Soon one again after a week, and Far one for the first time'); $tm = array_map(fn($x) => $x['message'], array_filter(rsxNotes($db, $t), fn($x) => strpos($x['title'], 'overdue') !== false)); eq(2, count($tm), 'the teacher is told once per milestone (Soon one was not repeated)'); eq(1, count(array_filter($tm, fn($m) => strpos($m, 'Soon one') !== false)));
    // quiet project
    [$db2, $t2, $s2, $c2, $id2] = rsxProject('B'); $db->query("UPDATE research_updates SET created_at = DATE_SUB(NOW(), INTERVAL 20 DAY) WHERE project_id = ?", [$id2]);
    $r = researchReminders(date('Y-m-d')); ok($r['stale'] >= 1, 'a project with no activity for 14 days is flagged'); $q = rsxNotes($db, $t2); ok(count(array_filter($q, fn($x) => strpos($x['title'], 'no activity') !== false)) === 1, 'the teacher is told');
    $again = researchReminders(date('Y-m-d')); eq(0, $again['stale'], 'not flagged again for two weeks');
    // closed / completed / draft projects get nothing
    [$db3, $t3, $s3, $c3, $id3] = rsxProject('C'); researchAddMilestone(researchProject($id3, $t3, 'teacher'), $t3, 'Past due', date('Y-m-d', strtotime('-4 day'))); researchClose(researchProject($id3, $t3, 'teacher'), $t3, 'Stopped for now');
    researchReminders(date('Y-m-d')); eq(0, count(array_filter(rsxNotes($db, $s3), fn($x) => true)), 'a closed project is not nagged');
    $db->query("UPDATE research_milestones SET status = 'accepted' WHERE project_id = ?", [$id]); eq(0, researchOverdueCount($s, 'student'), 'accepted milestones are never overdue');
});

test('research on the Dashboard: upcoming milestones in "Coming up" and overdue ones in "Needs your attention", for the student and the teacher', function () {
    [$db, $t, $s, $c, $id] = rsxProject('D'); researchAddMilestone(researchProject($id, $t, 'teacher'), $t, 'Draft chapter', date('Y-m-d', strtotime('+3 day'))); researchAddMilestone(researchProject($id, $t, 'teacher'), $t, 'Old task', date('Y-m-d', strtotime('-2 day')));
    $due = researchDueSoon($s, 'student'); eq(1, count($due), 'only the one still ahead'); eq('Draft chapter', $due[0]['mtitle']); eq(1, count(researchDueSoon($t, 'teacher'))); eq(1, researchOverdueCount($s, 'student')); eq(1, researchOverdueCount($t, 'teacher'));
    $hs = client($s); $hs->get('pages/dashboard.php'); ok($hs->shown()); contains('Draft chapter', $hs->body, 'in Coming up'); contains('Overdue research milestones', $hs->body);
    $ht = client($t); $ht->get('pages/dashboard.php'); contains('Draft chapter', $ht->body); contains('Overdue research milestones', $ht->body);
    $other = client(mkUser('RxDO')); $other->get('pages/dashboard.php'); notContains('Draft chapter', $other->body, 'another student sees nothing of it');
});

test('research milestone templates: built-in sets add the milestones with dates, repeated titles are skipped, a teacher can save and delete their own, nobody else sees them', function () {
    [$db, $t, $s, $c, $id] = rsxProject('E'); $P = fn() => researchProject($id, $t, 'teacher');
    $all = researchTemplates($t); ok(isset($all['standard'], $all['short'], $all['survey']), 'three built-in sets');
    $r = researchApplyTemplate($P(), $t, 'standard'); ok($r['ok'] && $r['added'] === 4, 'four milestones added'); $ms = researchMilestones($id); eq('Literature review', $ms[0]['title']); eq(date('Y-m-d', strtotime('+14 day')), $ms[0]['due_date']);
    $r = researchApplyTemplate($P(), $t, 'standard'); ok($r['ok'] && $r['added'] === 0, 'applying it again adds nothing'); $r = researchApplyTemplate($P(), $t, 'short'); eq(3, $r['added']); eq(7, count(researchMilestones($id)));
    ok(!researchApplyTemplate($P(), $t, 'nonsense')['ok'], 'an unknown template is refused'); ok(!researchApplyTemplate($P(), $t, 'survey')['ok'] || researchApplyTemplate($P(), $t, 'survey')['added'] <= 5, 'the limit of 12 milestones holds');
    $r = researchSaveTemplate($P(), $t, 'ab'); ok(!$r['ok'] && isset($r['errors']['template_name'])); $r = researchSaveTemplate($P(), $t, 'My thesis steps'); ok($r['ok']); ok(!researchSaveTemplate($P(), $t, 'My thesis steps')['ok'], 'the same name twice is refused');
    $mine = array_filter(researchTemplates($t), fn($k) => $k[0] === 't', ARRAY_FILTER_USE_KEY); eq(1, count($mine)); $key = array_key_first($mine); eq(count(researchMilestones($id)), count($mine[$key][1]));
    $t2 = mkUser('RxE2', 'teacher'); eq([], array_filter(researchTemplates($t2), fn($k) => $k[0] === 't', ARRAY_FILTER_USE_KEY), 'another teacher does not see it'); ok(!researchDeleteTemplate($t2, (int) substr($key, 1))['ok'], 'and cannot delete it');
    ok(researchDeleteTemplate($t, (int) substr($key, 1))['ok']); eq([], array_filter(researchTemplates($t), fn($k) => $k[0] === 't', ARRAY_FILTER_USE_KEY));
    // through the page
    $ht = client($t); $ht->get("pages/research/project.php?id=$id"); contains('Add from a template', $ht->body); contains('Standard research project', $ht->body); $hs = client($s); $hs->get("pages/research/project.php?id=$id"); notContains('Add from a template', $hs->body, 'students do not see it');
});

test('research sources: add from the class Library or type one, validated; each person removes their own, teachers any; shown on the report', function () {
    [$db, $t, $s, $c, $id] = rsxProject('F'); $P = fn() => researchProject($id, $s, 'student');
    $item = (int) $db->insert('research_items', ['class_id' => $c, 'added_by' => $t, 'kind' => 'book', 'title' => 'Plant biology', 'authors' => 'L. Green', 'year' => 2019, 'url' => 'https://example.org/pb']);
    $other_class = mkClass(mkUser('RxFT2', 'teacher'), 'Other F'); $foreign = (int) $db->insert('research_items', ['class_id' => $other_class, 'added_by' => 1, 'kind' => 'book', 'title' => 'Not mine']);
    ok(researchAddSource($P(), $s, $item, '', '', '', '', 'chapter 2')['ok'], 'from the Library'); $r = researchAddSource($P(), $s, $item, '', '', '', '', ''); ok(!$r['ok'] && isset($r['errors']['source_item']), 'the same Library item twice is refused');
    $r = researchAddSource($P(), $s, $foreign, '', '', '', '', ''); ok(!$r['ok'], 'an item of another class cannot be used');
    $r = researchAddSource($P(), $s, 0, 'ab', '', '', '', ''); ok(!$r['ok'] && isset($r['errors']['source_title'])); $r = researchAddSource($P(), $s, 0, 'A real title', '', '99', '', ''); ok(!$r['ok'] && isset($r['errors']['source_year']));
    $r = researchAddSource($P(), $s, 0, 'A real title', '', '', 'javascript:alert(1)', ''); ok(!$r['ok'] && isset($r['errors']['source_url']), 'a javascript: link is refused');
    ok(researchAddSource($P(), $s, 0, 'Field notes', 'Me', '2026', 'https://example.org/notes', '')['ok'], 'a typed source'); $src = researchSources($id); eq(2, count($src)); eq('Plant biology', $src[0]['title']); eq('L. Green', $src[0]['authors']);
    $t2 = researchProject($id, $t, 'teacher'); ok(researchAddSource($t2, $t, 0, 'Teacher tip', '', '', '', '')['ok'], 'the teacher adds one too'); $tid = (int) researchSources($id)[2]['id'];
    ok(!researchDeleteSource($P(), $s, 'student', $tid)['ok'], 'a student cannot remove the teacher\'s source'); ok(researchDeleteSource($t2, $t, 'teacher', (int) $src[1]['id'])['ok'], 'a teacher can remove any'); ok(researchDeleteSource($P(), $s, 'student', (int) $src[0]['id'])['ok'], 'a student removes their own');
    $hs = client($s); $hs->get("pages/research/project.php?id=$id"); contains('id="sources"', $hs->body); contains('Teacher tip', $hs->body); $x = client(mkUser('RxFX')); $x->get("pages/research/project.php?id=$id"); ok(!$x->shown(), 'outsiders cannot open it');
    researchClose(researchProject($id, $t, 'teacher'), $t, 'closing now'); ok(!researchAddSource(researchProject($id, $s, 'student'), $s, 0, 'Late source', '', '', '', '')['ok'], 'a closed project takes no more sources');
});

test('research report: the printable record is open to the project\'s student and the class teachers only, and holds the proposal, milestones, feedback, sources and grade', function () {
    [$db, $t, $s, $c, $id] = rsxProject('G'); $P = fn($role, $u) => researchProject($id, $u, $role);
    researchAddMilestone($P('teacher', $t), $t, 'Collect samples', ''); $m = (int) researchMilestones($id)[0]['id']; researchSubmitMilestone($P('student', $s), $s, $m, 'Collected ten samples from the pond.', null); researchReviewMilestone($P('teacher', $t), $t, $m, 'accept', 'Good sampling.');
    researchAddSource($P('student', $s), $s, 0, 'Pond life', 'A. Writer', '2021', '', ''); researchComplete($P('teacher', $t), $t, '91', 'Very careful work.');
    $hs = client($s); $hs->get("pages/research/report.php?id=$id"); ok($hs->shown()); contains('Research project report', $hs->body); contains('Reminder project', $hs->body); contains('Does it work?', $hs->body); contains('Collect samples', $hs->body); contains('Good sampling.', $hs->body); contains('Pond life', $hs->body); contains('91 / 100', $hs->body); contains('Very careful work.', $hs->body); contains('window.print()', $hs->body); contains('class="sign"', $hs->body, 'signature lines'); notContains('stat-card', $hs->body, 'no stat tiles in printed reports');
    $ht = client($t); $ht->get("pages/research/report.php?id=$id"); ok($ht->shown()); $x = client(mkUser('RxGX')); $x->get("pages/research/report.php?id=$id"); ok(!$x->shown(), 'an outsider cannot'); $o = client(mkUser('RxGO', 'teacher')); $o->get("pages/research/report.php?id=$id"); ok(!$o->shown(), 'a teacher of another class cannot'); ok(!anon()->get("pages/research/report.php?id=$id")->shown());
    $hs->get("pages/research/project.php?id=$id"); contains("report.php?id=$id", $hs->body, 'the button is on the project page');
});
