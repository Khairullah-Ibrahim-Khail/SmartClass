<?php
test('research project: draft → proposal → teacher asks for changes → resubmit → approve, with validation at every step and the right people told', function () {
    $db = TW('db'); $t = mkUser('RsT', 'teacher'); $t2 = mkUser('RsT2', 'teacher'); $s = mkUser('RsS'); $s2 = mkUser('RsS2'); $c = mkClass($t, 'Rs Class', [$s, $s2]); $c2 = mkClass($t2, 'Other Class', [$s2]);
    $r = researchSave($s, $c, 0, 'ab', '', ''); ok(!$r['ok'] && isset($r['errors']['title']), 'a too-short title is refused');
    $r = researchSave($s, $c2, 0, 'A good title', 'q', 'plan'); ok(!$r['ok'], 'only a class you are in'); 
    $r = researchSave($s, $c, 0, 'Sleep and exam results', '', ''); ok($r['ok']); $id = $r['id']; $p = researchProject($id, $s, 'student'); eq('draft', $p['status']);
    $r = researchSubmitProposal($p, $s); ok(!$r['ok'] && isset($r['errors']['question']) && isset($r['errors']['plan']), 'question and plan are needed before sending');
    ok(researchProject($id, $s2, 'student') === null, 'another student cannot see it'); ok(researchProject($id, $t2, 'teacher') === null, 'a teacher of another class cannot see it'); ok(researchProject($id, $t, 'teacher') !== null, 'the class teacher can');
    $r = researchComment(researchProject($id, $s, 'student'), $s, 'student', 'hello', null); ok(!$r['ok'], 'no discussion before the proposal is sent');
    researchSave($s, $c, $id, 'Sleep and exam results', 'Does sleep time change exam marks?', 'I will survey 30 students and compare sleep hours with their marks.');
    $p = researchProject($id, $s, 'student'); $r = researchSubmitProposal($p, $s); ok($r['ok'], json_encode($r)); eq('proposed', researchProject($id, $s, 'student')['status']);
    eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'research_update'", [$t])['c'], 'the teacher was told'); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'research_update'", [$t2])['c'], 'the other teacher was not');
    $p = researchProject($id, $t, 'teacher');
    $r = researchDecide($p, $t, 'changes', ''); ok(!$r['ok'] && isset($r['errors']['note']), 'asking for changes needs a note');
    $r = researchDecide($p, $t, 'maybe', 'x'); ok(!$r['ok']);
    $r = researchDecide($p, $t, 'changes', 'Narrow the question to one grade.'); ok($r['ok']); eq('changes', researchProject($id, $s, 'student')['status']); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'research_update'", [$s])['c'], 'the student was told');
    $r = researchSave($s, $c, $id, 'Sleep and exam results (Grade 10)', 'Does sleep time change Grade 10 marks?', 'Survey 30 Grade 10 students and compare sleep with marks.'); ok($r['ok'], 'the student edits after a request for changes');
    $r = researchSubmitProposal(researchProject($id, $s, 'student'), $s); ok($r['ok']); $r = researchSubmitProposal(researchProject($id, $s, 'student'), $s); ok(!$r['ok'], 'cannot send twice');
    $r = researchDecide(researchProject($id, $t, 'teacher'), $t, 'approve', ''); ok($r['ok']); $p = researchProject($id, $s, 'student'); eq('active', $p['status']); eq($t, (int) $p['supervisor_id']);
    $r = researchDecide($p, $t, 'approve', ''); ok(!$r['ok'], 'a decided proposal cannot be decided again');
    $log = array_column(researchUpdates($id), 'kind'); ok(in_array('decision', $log, true) && in_array('system', $log, true), 'the timeline records what happened');
    $d = researchSave($s, $c, 0, 'Another idea', '', ''); $dp = researchProject($d['id'], $s, 'student'); ok(!researchDeleteDraft($dp, $s2)['ok'], 'only the owner deletes a draft'); ok(researchDeleteDraft($dp, $s)['ok']); ok(researchProject($d['id'], $s, 'student') === null);
});

test('research project: milestones are set by the teacher, handed in by the student, reviewed, and the project is completed with a grade only when all are accepted', function () {
    $db = TW('db'); $t = mkUser('RmT', 'teacher'); $s = mkUser('RmS'); $c = mkClass($t, 'Rm Class', [$s]);
    $id = researchSave($s, $c, 0, 'Water quality study', 'Is the school well water safe?', 'Collect samples from 5 wells and test pH and turbidity.')['id']; researchSubmitProposal(researchProject($id, $s, 'student'), $s);
    $P = fn($role = 'teacher', $u = null) => researchProject($id, $u ?? ($role === 'teacher' ? $t : $s), $role);
    ok(!researchAddMilestone($P(), $t, 'Literature review', '')['ok'], 'no milestones before approval'); researchDecide($P(), $t, 'approve', '');
    $r = researchAddMilestone($P(), $t, 'x', ''); ok(!$r['ok'] && isset($r['errors']['milestone_title']), 'a milestone needs a title'); $r = researchAddMilestone($P(), $t, 'Method', '2026-13-45'); ok(!$r['ok'] && isset($r['errors']['due_date']), 'a bad date is refused');
    ok(researchAddMilestone($P(), $t, 'Literature review', date('Y-m-d', strtotime('+7 day')))['ok']); ok(researchAddMilestone($P(), $t, 'Final report', '')['ok']);
    $ms = researchMilestones($id); eq(2, count($ms)); $m1 = (int) $ms[0]['id']; $m2 = (int) $ms[1]['id']; eq([0, 2], researchProgress($id));
    ok(!researchComplete($P(), $t, '90', '')['ok'], 'not complete while milestones are open');
    $r = researchSubmitMilestone($P('student'), $s, $m1, '', null); ok(!$r['ok'] && isset($r['errors']['body_' . $m1]), 'handing in nothing is refused');
    $o = mkUser('RmO'); ok(!researchSubmitMilestone($P('student'), $o, $m1, 'my work', null)['ok'], 'only the project owner hands in');
    $r = researchSubmitMilestone($P('student'), $s, $m1, 'Read 6 papers; notes attached in the report.', null); ok($r['ok'], json_encode($r)); eq('submitted', $db->fetchOne("SELECT status FROM research_milestones WHERE id = ?", [$m1])['status']);
    ok(!researchSubmitMilestone($P('student'), $s, $m1, 'again', null)['ok'], 'cannot hand in twice while it waits'); ok(!researchDeleteMilestone($P(), $t, $m1)['ok'], 'a milestone with work cannot be removed');
    $r = researchReviewMilestone($P(), $t, $m1, 'changes', ''); ok(!$r['ok'] && isset($r['errors']['note_' . $m1]), 'changes need feedback');
    ok(researchReviewMilestone($P(), $t, $m1, 'changes', 'Add the sources list.')['ok']); eq('changes', $db->fetchOne("SELECT status FROM research_milestones WHERE id = ?", [$m1])['status']);
    ok(researchSubmitMilestone($P('student'), $s, $m1, 'Sources added.', null)['ok'], 'the student hands in again'); ok(researchReviewMilestone($P(), $t, $m1, 'accept', '')['ok']); eq([1, 2], researchProgress($id));
    ok(!researchReviewMilestone($P(), $t, $m1, 'accept', '')['ok'], 'an accepted milestone is not reviewed again');
    ok(researchSubmitMilestone($P('student'), $s, $m2, 'Final report text.', null)['ok']); ok(researchReviewMilestone($P(), $t, $m2, 'accept', 'Well done.')['ok']); eq([2, 2], researchProgress($id));
    $r = researchComplete($P(), $t, '120', ''); ok(!$r['ok'] && isset($r['errors']['grade']), 'a grade above 100 is refused'); $r = researchComplete($P(), $t, 'abc', ''); ok(!$r['ok']);
    $r = researchComplete($P(), $t, '88.5', 'Excellent method.'); ok($r['ok'], json_encode($r)); $p = $P('student'); eq('completed', $p['status']); eq(88.5, (float) $p['grade']); eq('Excellent method.', $p['final_feedback']);
    ok(!researchAddMilestone($p, $t, 'More', '')['ok'], 'a completed project takes no more milestones'); ok(researchWaiting($t, 'teacher') === 0, 'nothing waits for the teacher any more');
    $n = $db->fetchAll("SELECT title FROM notifications WHERE user_id = ? AND type = 'research_update'", [$s]); ok(count($n) >= 5, 'the student was told at each step: ' . count($n));
});

test('research project: discussion both ways, closing, and the pages (list, start, project) work for the student and the teacher and are closed to outsiders', function () {
    $db = TW('db'); $t = mkUser('RpT', 'teacher'); $s = mkUser('RpS'); $x = mkUser('RpX'); $c = mkClass($t, 'Rp Class', [$s]);
    $hs = client($s); $ht = client($t);
    $hs->get('pages/research/projects.php'); ok($hs->shown()); contains('Start a project', $hs->body); contains('No research projects yet', $hs->body);
    $hs->get('pages/research/start.php'); ok($hs->shown()); contains('Send to my teacher', $hs->body); $tok = $hs->token('pages/research/start.php');
    $hs->post('pages/research/start.php', ['csrf_token' => $tok, 'action' => 'send', 'class_id' => $c, 'title' => 'ab', 'question' => '', 'plan' => '']); contains('at least 4 characters', $hs->body, 'the error shows under the title field');
    $hs->post('pages/research/start.php', ['csrf_token' => $tok, 'action' => 'send', 'class_id' => $c, 'title' => 'Bridges and loads', 'question' => 'Which shape carries most weight?', 'plan' => 'Build three paper bridges and load them with coins until they fail.']);
    $id = (int) $db->fetchOne("SELECT id FROM research_projects WHERE student_id = ?", [$s])['id']; ok($id > 0); eq('proposed', $db->fetchOne("SELECT status FROM research_projects WHERE id = ?", [$id])['status'], 'sent from the form');
    $ht->get('pages/research/projects.php'); contains('Bridges and loads', $ht->body); contains('Needs your review', $ht->body, 'the proposal waits for the teacher');
    $ht->get("pages/research/project.php?id=$id"); ok($ht->shown()); contains('Your decision on this proposal', $ht->body); contains('rs-steps', $ht->body); $tt = $ht->token("pages/research/project.php?id=$id");
    $ht->post("pages/research/project.php?id=$id", ['csrf_token' => $tt, 'id' => $id, 'action' => 'decide', 'decision' => 'changes', 'note' => '']); $ht->get("pages/research/project.php?id=$id"); contains('Tell the student what to change', $ht->body, 'the missing note shows as an error');
    $ht->post("pages/research/project.php?id=$id", ['csrf_token' => $tt, 'id' => $id, 'action' => 'decide', 'decision' => 'approve', 'note' => 'Good start.']); eq('active', $db->fetchOne("SELECT status FROM research_projects WHERE id = ?", [$id])['status']);
    $ht->post("pages/research/project.php?id=$id", ['csrf_token' => 'bad', 'id' => $id, 'action' => 'add_milestone', 'milestone_title' => 'Draft', 'due_date' => '']); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM research_milestones WHERE project_id = ?", [$id])['c'], 'no CSRF token, no change');
    $ht->post("pages/research/project.php?id=$id", ['csrf_token' => $tt, 'id' => $id, 'action' => 'add_milestone', 'milestone_title' => 'Build the bridges', 'due_date' => date('Y-m-d', strtotime('+5 day'))]); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM research_milestones WHERE project_id = ?", [$id])['c']);
    $hs->get("pages/research/project.php?id=$id"); ok($hs->shown()); contains('Build the bridges', $hs->body); contains('name="action" value="submit_milestone"', $hs->body, 'the student sees the hand-in form'); notContains('Your decision on this proposal', $hs->body); notContains('value="add_milestone"', $hs->body, 'students cannot add milestones');
    $st = $hs->token("pages/research/project.php?id=$id"); $mid = (int) $db->fetchOne("SELECT id FROM research_milestones WHERE project_id = ?", [$id])['id'];
    $hs->post("pages/research/project.php?id=$id", ['csrf_token' => $st, 'id' => $id, 'action' => 'decide', 'decision' => 'close', 'note' => 'I close it myself']); eq('active', $db->fetchOne("SELECT status FROM research_projects WHERE id = ?", [$id])['status'], 'a student cannot decide');
    $hs->post("pages/research/project.php?id=$id", ['csrf_token' => $st, 'id' => $id, 'action' => 'comment', 'comment' => 'Can I use coins as the load?']); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM research_updates WHERE project_id = ? AND kind = 'comment'", [$id])['c']);
    $ht->post("pages/research/project.php?id=$id", ['csrf_token' => $tt, 'id' => $id, 'action' => 'comment', 'comment' => 'Yes, count each coin.']); eq(2, (int) $db->fetchOne("SELECT COUNT(*) c FROM research_updates WHERE project_id = ? AND kind = 'comment'", [$id])['c'], 'the teacher answers');
    $ht->get("pages/research/project.php?id=$id"); contains('Can I use coins as the load?', $ht->body); contains('Yes, count each coin.', $ht->body);
    // outsiders
    $ox = client($x); $ox->get("pages/research/project.php?id=$id"); ok(!$ox->shown(), 'another student cannot open it'); $o2 = client(mkUser('RpT2', 'teacher')); $o2->get("pages/research/project.php?id=$id"); ok(!$o2->shown(), 'a teacher of another class cannot');
    ok(!anon()->get("pages/research/project.php?id=$id")->shown(), 'sign-in needed'); $ht->get('pages/research/start.php'); ok(!$ht->shown(), 'teachers do not get the start page');
    $uid = (int) $db->fetchOne("SELECT id FROM research_updates WHERE project_id = ? LIMIT 1", [$id])['id']; $ox->get("pages/research/file.php?id=$uid"); ok(!$ox->shown(), 'files are not served to outsiders');
    // closing
    $ht->post("pages/research/project.php?id=$id", ['csrf_token' => $tt, 'id' => $id, 'action' => 'close', 'close_note' => '']); eq('active', $db->fetchOne("SELECT status FROM research_projects WHERE id = ?", [$id])['status'], 'closing needs a reason');
    $ht->post("pages/research/project.php?id=$id", ['csrf_token' => $tt, 'id' => $id, 'action' => 'close', 'close_note' => 'The topic is no longer offered.']); eq('closed', $db->fetchOne("SELECT status FROM research_projects WHERE id = ?", [$id])['status']);
    $library = client($s); $library->get('pages/research/view.php'); ok($library->shown()); contains('Projects', $library->body, 'the Library page has the Projects tab');
});
