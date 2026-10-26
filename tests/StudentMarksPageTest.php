<?php
test('student marks page: a summary, one row per class with the grade and the parts, "provisional" while parts are missing, the class average only when 3+ students have marks, exams in their own tab — and no other student\'s marks anywhere', function () {
    $db = TW('db'); $t = mkUser('SmT', 'teacher'); $a = mkUser('SmA'); $b = mkUser('SmB'); $c3 = mkUser('SmC'); $z = mkUser('SmZ');
    $cls = mkClass($t, 'Marks Class', [$a, $b, $c3]); $small = mkClass($t, 'Tiny Class', [$a, $b]); $empty = mkClass($t, 'Empty Class', [$a]);
    $mid = (int) $db->insert('exams', ['class_id' => $cls, 'created_by' => $t, 'title' => 'Mid test', 'kind' => 'mid', 'exam_date' => date('Y-m-d', strtotime('-10 day')), 'max_marks' => 50, 'results_published_at' => date('Y-m-d H:i:s')]);
    foreach ([[$a, 40], [$b, 30], [$c3, 10]] as [$sid, $m]) $db->insert('exam_marks', ['exam_id' => $mid, 'student_id' => $sid, 'marks' => $m, 'absent' => 0, 'remarks' => $sid === $a ? 'Well done' : '']);
    $hidden = (int) $db->insert('exams', ['class_id' => $cls, 'created_by' => $t, 'title' => 'Secret final', 'kind' => 'final', 'exam_date' => date('Y-m-d'), 'max_marks' => 100]);   // not published
    foreach ([$a, $b, $c3] as $sid) $db->insert('exam_marks', ['exam_id' => $hidden, 'student_id' => $sid, 'marks' => 99, 'absent' => 0]);
    $tiny = (int) $db->insert('exams', ['class_id' => $small, 'created_by' => $t, 'title' => 'Tiny mid', 'kind' => 'mid', 'exam_date' => date('Y-m-d'), 'max_marks' => 10, 'results_published_at' => date('Y-m-d H:i:s')]);
    foreach ([[$a, 9], [$b, 3]] as [$sid, $m]) $db->insert('exam_marks', ['exam_id' => $tiny, 'student_id' => $sid, 'marks' => $m, 'absent' => 0]);
    $h = client($a); $h->get('pages/marks/view.php'); ok($h->shown());
    contains('mk-summary', $h->body); contains('Overall average', $h->body); contains('Classes with marks', $h->body); contains('Best class', $h->body); contains('Needs attention', $h->body);
    contains('Marks Class', $h->body); contains('Tiny Class', $h->body); contains('Empty Class', $h->body, 'a class with no marks is still listed'); contains('Your marks appear here when your teacher enters them', $h->body); contains('Pending', $h->body);
    contains('Provisional', $h->body, 'only one part is marked, so the grade is provisional'); contains('Mid exam', $h->body); contains('Statement (PDF)', $h->body); contains('How grades work', $h->body);
    contains('class average 53.3%', $h->body, '(80% + 60% + 20%) ÷ 3 — shown because three students have marks'); notContains('class average 60%', $h->body, 'the two-student class shows no average (it would reveal the other student\'s mark)');
    notContains('Secret final', $h->body, 'an unpublished exam is not counted or listed');
    $h->get('pages/marks/view.php?tab=exams'); contains('Mid test', $h->body); contains('Well done', $h->body); contains('40 / 50', $h->body); notContains('Secret final', $h->body); notContains('30 / 50', $h->body, 'another student\'s mark is never shown'); notContains('10 / 50', $h->body);
    $h->get('pages/marks/view.php?class_id=' . $cls); contains('<strong>Marks Class</strong>', $h->body); notContains('<strong>Tiny Class</strong>', $h->body, 'the class filter works'); contains('open', $h->body);
    $out = client($z); $out->get('pages/marks/view.php'); ok($out->shown()); notContains('Marks Class', $out->body, 'a student outside the classes sees none of it'); contains('No classes yet', $out->body);
    $ht = client($t); $ht->get('pages/marks/view.php'); ok($ht->shown()); notContains('mk-summary', $ht->body, 'the teacher\'s page is the unchanged one'); contains('Add Marks', $ht->body); contains('Final marks', $ht->body);
});
