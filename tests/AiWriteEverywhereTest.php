<?php
require_once __DIR__ . '/AiStreamTest.php';   // aiMockStart(): a fake AI provider
/** AI help in the teacher's text boxes: every helper writes for the class's own topics, takes the form's facts, refuses other teachers' classes, and the buttons appear only for teachers who have AI. */
function aiWClass($teacher, $topic = 'Rivers and lakes', $lecture = 'Lecture on the Nile') {
    $c = mkClass($teacher, 'AiW ' . mt_rand(100, 999), []);
    TW('db')->insert('class_topics', ['class_id' => $c, 'name' => $topic, 'position' => 1]);
    TW('db')->insert('lectures', ['class_id' => $c, 'teacher_id' => $teacher, 'title' => $lecture, 'status' => 'published', 'content' => 'x']);
    return $c;
}
function aiWPost($h, $task, $class, array $more = []) {
    $h->post('pages/ai/assist.php', ['task' => $task, 'class_id' => $class] + $more, [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]);
    return json_decode($h->body, true);
}

test('AI writing helpers: every kind of text box drafts from the title, the form\'s facts and the class\'s own topics; empty requests and other teachers\' classes are refused', function () {
    aiMockStart(); $t = mkUser('AwT', 'teacher'); $o = mkUser('AwO', 'teacher'); $c = aiWClass($t); $h = client($t);
    foreach (['event' => ['ctx[location]' => 'Main hall'], 'resource' => ['ctx[category]' => 'Notes'], 'forum' => [], 'class' => ['ctx[tags]' => 'geography'], 'quiz' => ['ctx[time_limit]' => '20'],
              'research' => ['ctx[kind]' => 'Book', 'ctx[authors]' => 'A. Writer'], 'exam' => ['ctx[kind]' => 'mid', 'ctx[duration]' => '90']] as $task => $facts) {
        $r = aiWPost($h, $task, $c, ['title' => 'Title for ' . $task] + $facts); ok($r['ok'] ?? false, "$task: " . $h->body);
        contains('CTXSEEN', $r['text'], "$task is told the class's topics and lectures"); if ($facts) contains('FACTSEEN', $r['text'], "$task gets the form's facts");
    }
    $r = aiWPost($h, 'improve', $c, ['text' => 'my rough text about the river trip']); ok($r['ok'], $h->body); contains('CTXSEEN', $r['text']);
    $r = aiWPost($h, 'improve', $c, ['text' => '']); ok(!$r['ok'] && $h->code === 422, 'nothing to improve');
    $r = aiWPost($h, 'event', $c, ['title' => '']); ok(!$r['ok'] && $h->code === 422, 'a title (or a description) is needed');
    $r = aiWPost($h, 'assignment', $c, ['title' => 'Rivers essay']); contains('CTXSEEN', $r['text'], 'the older helpers are topic-aware too');
    $ho = client($o); $r = aiWPost($ho, 'event', $c, ['title' => 'x']); eq(403, $ho->code, 'a teacher who does not teach the class gets nothing');
    $none = aiWPost($h, 'event', 0, ['title' => 'No class yet']); ok($none['ok'], 'a form without a class yet still works'); notContains('CTXSEEN', $none['text'], 'and is told no class topics');
    $r = aiWPost($h, 'nonsense', $c, ['title' => 'x']); ok(!$r['ok'], 'unknown helper');
    aiMockReset();
});

test('the AI button appears on every teacher form that has a description / instructions box — and never for students or teachers without AI', function () {
    aiMockStart(); $t = mkUser('AbT', 'teacher'); $s = mkUser('AbS'); $c = aiWClass($t); $h = client($t);
    foreach (['pages/exams/create.php' => 'exam', 'pages/events/create.php' => 'event', 'pages/resources/upload.php' => 'resource', 'pages/forums/create.php' => 'forum', 'pages/classes/create.php' => 'class', 'pages/quizzes/create.php' => 'quiz', 'pages/research/add.php' => 'research', 'pages/announcements/create.php' => 'announcement', 'pages/assignments/create.php' => 'assignment'] as $url => $task) {
        $h->get($url); contains('data-ai-task="' . $task . '"', $h->body, "$url has the $task helper"); eq(1, substr_count($h->body, 'assets/js/ai-assist.js'), "$url loads the script once");
    }
    $hs = client($s); $hs->get('pages/forums/create.php'); notContains('data-ai-task', $hs->body, 'a student gets no writing helper');
    setSetting('ai_enabled', '0'); aiProviderRows(true); $h2 = client($t); $h2->get('pages/exams/create.php'); notContains('data-ai-task', $h2->body, 'no AI, no button');
    aiMockReset();
});
