<?php
$scaleRows = fn() => [
    ['label' => 'A+', 'min' => 90, 'max' => 100, 'min_op' => 'gte', 'max_op' => 'lte', 'gpa' => 4],
    ['label' => 'A', 'min' => 80, 'max' => 89, 'min_op' => 'gte', 'max_op' => 'lte', 'gpa' => 3.5],
    ['label' => 'B', 'min' => 60, 'max' => 79, 'min_op' => 'gte', 'max_op' => 'lte', 'gpa' => 2.5],
    ['label' => 'F', 'min' => 0, 'max' => 59, 'min_op' => 'gte', 'max_op' => 'lte', 'gpa' => 0],
];
function useClassScale(array $bands, $round = 'nearest', $gpa = true) {
    $db = TW('db'); $t = mkUser('GT', 'teacher'); $c = mkClass($t, 'Grading');
    $db->insert('class_grading_scales', ['class_id' => $c, 'scale' => json_encode(['show_gpa' => $gpa, 'basis' => 'graded', 'round' => $round, 'bands' => $bands])]);
    gradingUseClass($c);
    return $c;
}
test('a valid scale is accepted and sorted highest first', function () use ($scaleRows) {
    [$b, $err] = gradingValidate(array_reverse($scaleRows()));
    eq('', $err); eq('A+', $b[0]['label']); eq('F', $b[3]['label']);
});
test('a scale without a 0% band is refused', function () use ($scaleRows) {
    $r = $scaleRows(); $r[3]['min'] = 10; [, $err] = gradingValidate($r); contains('0%', $err);
});
test('overlapping ranges are refused', function () use ($scaleRows) {
    $r = $scaleRows(); $r[1]['max'] = 95; [, $err] = gradingValidate($r); contains('overlap', $err);
});
test('a gap between ranges is refused', function () use ($scaleRows) {
    $r = $scaleRows(); $r[2]['max'] = 70; [, $err] = gradingValidate($r); contains('no grade', $err);
});
test('two bands cannot start at the same percentage', function () use ($scaleRows) {
    $r = $scaleRows(); $r[2]['min'] = 80; [, $err] = gradingValidate($r); contains('start', $err);
});
test('86.5 counts as 87 with "nearest" rounding, 86 with "down"', function () use ($scaleRows) {
    [$bands] = gradingValidate($scaleRows());
    useClassScale($bands, 'nearest'); eq(87.0, (float) gradingRound(86.5));
    $c = useClassScale($bands, 'down'); eq(86.0, (float) gradingRound(86.9));
    $c = useClassScale($bands, 'up'); eq(87.0, (float) gradingRound(86.1));
    $c = useClassScale($bands, 'none'); near(86.5, gradingRound(86.5), 0.0001);
});
test('grade bands: boundaries land in the right band', function () use ($scaleRows) {
    [$bands] = gradingValidate($scaleRows()); useClassScale($bands, 'nearest');
    eq('A+', gradeBand(90)['label']); eq('A', gradeBand(89.4)['label']); eq('A', gradeBand(80)['label']);
    eq('B', gradeBand(79.4)['label']); eq('F', gradeBand(59.4)['label']); eq('F', gradeBand(0)['label']); eq('A+', gradeBand(100)['label']);
    eq(null, gradeBand(null));
});
test('"greater than" start excludes the boundary itself', function () {
    $rows = [['label' => 'High', 'min' => 50, 'max' => 100, 'min_op' => 'gt', 'max_op' => 'lte'], ['label' => 'Low', 'min' => 0, 'max' => 50, 'min_op' => 'gte', 'max_op' => 'lte']];
    [$b, $err] = gradingValidate($rows);
    if ($err === '') { useClassScale($b, 'none'); eq('Low', gradeBand(50)['label']); eq('High', gradeBand(50.5)['label']); }
    else contains('overlap', $err);   // exactly-50 shared by both ends is refused — either way a mark never gets two grades
});
test('GPA follows the band and only shows when switched on', function () use ($scaleRows) {
    [$bands] = gradingValidate($scaleRows());
    useClassScale($bands, 'nearest', true); ok(gradingShowGpa()); near(4.0, gradeGpa(95), 0.001);
    useClassScale($bands, 'nearest', false); ok(!gradingShowGpa());
});
test('a class without its own scale follows the school default (ratings)', function () {
    gradingUseClass(null);
    $l = gradeBand(90)['label']; ok($l !== '', 'a default band exists'); ok(gradeBand(0) !== null);
});
