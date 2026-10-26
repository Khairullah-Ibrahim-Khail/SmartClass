<?php
test('question bank: publishing with "keep" stores the questions once, privately; the bank lists, searches and deletes only the teacher\'s own', function () {
    $db = TW('db'); $t = mkUser('QbT', 'teacher'); $o = mkUser('QbO', 'teacher'); $c = mkClass($t, 'QbClass'); $h = client($t);
    $tok = $h->token('pages/quizzes/create.php');
    $post = ['csrf_token' => $tok, 'class_id' => $c, 'title' => 'Unit 1 quiz', 'release_mode' => 'instant', 'save_to_bank' => '1', 'bank_tag' => 'Unit 1',
        'questions' => [
            ['type' => 'choice', 'text' => 'What is 2 + 2?', 'marks' => '2', 'options' => ['3', '4', '5'], 'correct' => '1'],
            ['type' => 'short', 'text' => 'Explain gravity.', 'marks' => '5', 'model_answer' => 'A force that attracts masses.'],
        ]];
    $h->post('pages/quizzes/create.php', $post);
    eq(1, (int) $db->fetchOne('SELECT COUNT(*) c FROM quizzes WHERE class_id = ?', [$c])['c'], 'the quiz was published');
    eq(2, (int) $db->fetchOne('SELECT COUNT(*) c FROM question_bank WHERE teacher_id = ?', [$t])['c'], 'both questions are kept');
    $post['csrf_token'] = $h->token('pages/quizzes/create.php'); $post['title'] = 'Unit 1 again'; $h->post('pages/quizzes/create.php', $post);
    eq(2, (int) $db->fetchOne('SELECT COUNT(*) c FROM question_bank WHERE teacher_id = ?', [$t])['c'], 'the same questions are not kept twice');
    $x = ['csrf_token' => $h->token('pages/quizzes/create.php'), 'class_id' => $c, 'title' => 'No keep', 'release_mode' => 'instant', 'questions' => [['type' => 'short', 'text' => 'Not kept?', 'marks' => '1', 'model_answer' => 'x']]];
    $h->post('pages/quizzes/create.php', $x); eq(2, (int) $db->fetchOne('SELECT COUNT(*) c FROM question_bank WHERE teacher_id = ?', [$t])['c'], 'without the tick nothing is kept');

    $h->get('pages/quizzes/bank.php'); $j = json_decode($h->body, true); ok($j['ok']); eq(2, count($j['questions'])); eq(['Unit 1' => 2], $j['tags']);
    $mc = array_values(array_filter($j['questions'], fn($q) => $q['type'] === 'choice'))[0]; eq(['3', '4', '5'], $mc['options']); eq(1, $mc['correct']); eq(2, $mc['marks']); eq('Unit 1', $mc['tag']);
    $h->get('pages/quizzes/bank.php?q=gravity'); eq(1, count(json_decode($h->body, true)['questions']), 'search');
    $h->get('pages/quizzes/bank.php?tag=Other'); eq(0, count(json_decode($h->body, true)['questions']), 'tag filter');

    $ho = client($o); $ho->get('pages/quizzes/bank.php'); eq(0, count(json_decode($ho->body, true)['questions']), 'another teacher sees nothing of it');
    $ho->post('pages/quizzes/bank.php', ['csrf_token' => $ho->token('pages/dashboard.php'), 'action' => 'delete', 'id' => $mc['id']], [], ['X-CSRF-Token' => $ho->token('pages/dashboard.php')]);
    eq(2, (int) $db->fetchOne('SELECT COUNT(*) c FROM question_bank WHERE teacher_id = ?', [$t])['c'], 'and cannot delete it');
    $h->post('pages/quizzes/bank.php', ['action' => 'delete', 'id' => $mc['id']], [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]);
    eq(1, (int) $db->fetchOne('SELECT COUNT(*) c FROM question_bank WHERE teacher_id = ?', [$t])['c'], 'the owner can delete');
    $st = mkUser('QbS'); $hs = client($st); $hs->get('pages/quizzes/bank.php'); eq(403, $hs->code, 'a student has no bank');
});
