<?php
test('final marks: weighted parts add up and missing parts do not count as zero until the basis says so', function () {
    $db = TW('db'); $t = mkUser('FT', 'teacher'); $s1 = mkUser('Fs1'); $s2 = mkUser('Fs2'); $c = mkClass($t, 'Final', [$s1, $s2]);
    gradingUseClass($c);
    foreach (['mid' => 40, 'final' => 60, 'activity' => 0, 'quiz' => 0, 'assignment' => 0] as $k => $w) $db->insert('class_marking_scheme', ['class_id' => $c, 'component' => $k, 'weight' => $w]);
    $e1 = $db->insert('exams', ['class_id' => $c, 'created_by' => $t, 'title' => 'Mid', 'kind' => 'mid', 'exam_date' => '2026-01-10 09:00:00', 'max_marks' => 50, 'results_published_at' => '2026-01-11 00:00:00']);
    $db->insert('exam_marks', ['exam_id' => $e1, 'student_id' => $s1, 'marks' => 40]);   // 80 % of 40 weight = 32
    $db->insert('exam_marks', ['exam_id' => $e1, 'student_id' => $s2, 'absent' => 1]);    // absent counts as 0
    $fm = classFinalMarks($c);
    $rows = array_column($fm['rows'], null, 'id');
    near(32.0, $rows[$s1]['total'], 0.01, 'student 1 total'); near(0.0, $rows[$s2]['total'], 0.01, 'absent student total');
    near(80.0, $rows[$s1]['percent_so_far'], 0.1, 'percent so far'); near(32.0, $rows[$s1]['final_percent'], 0.1, 'final percent counts the exam not yet taken as 0');
    eq(100.0, (float) $fm['weight_all']);
});
test('final marks: students only see published results', function () {
    $db = TW('db'); $t = mkUser('FT2', 'teacher'); $s = mkUser('Fs3'); $c = mkClass($t, 'Final2', [$s]); gradingUseClass($c);
    foreach (['mid' => 100, 'final' => 0, 'activity' => 0, 'quiz' => 0, 'assignment' => 0] as $k => $w) $db->insert('class_marking_scheme', ['class_id' => $c, 'component' => $k, 'weight' => $w]);
    $e = $db->insert('exams', ['class_id' => $c, 'created_by' => $t, 'title' => 'Mid', 'kind' => 'mid', 'exam_date' => '2026-01-10 09:00:00', 'max_marks' => 100]);   // not published
    $db->insert('exam_marks', ['exam_id' => $e, 'student_id' => $s, 'marks' => 90]);
    $teacher_view = array_column(classFinalMarks($c, false)['rows'], null, 'id')[$s];
    $student_view = array_column(classFinalMarks($c, true)['rows'], null, 'id')[$s];
    near(90.0, $teacher_view['total'], 0.01); eq(null, $student_view['percent_so_far'], 'unpublished marks must stay hidden from the student');
});
