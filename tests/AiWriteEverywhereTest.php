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

test('the AI scripts load on EVERY page, not only on pages that show an AI button — a page reached by a click inside the app is swapped in without running the footer again', function () {
    aiMockStart(); $t = mkUser('AsT', 'teacher'); $h = client($t);
    $h->get('pages/dashboard.php'); contains('assets/js/ai-assist.js', $h->body, 'the writing helper script is there even on a page without AI buttons'); contains('assets/js/ai-student.js', $h->body, 'and the student helper script');
    $h->get('pages/exams/create.php'); eq(1, substr_count($h->body, 'assets/js/ai-assist.js'), 'still only once where the page asks for it itself');
    aiMockReset();
});

test('AI text is clean plain text on both sides: no raw markdown, colour tags, HTML or code fences in what teachers edit and students read; a lecture keeps its markdown for the lecture renderer', function () {
    eq("Hello world.\n\n- first\n- second\n\nTip: slowly.", aiMarkdownToPlain("Hello **world**.\n\n- first\n- {green}second{/green}\n\n> Tip: slowly."), 'the fake AI\'s markdown answer becomes plain text');
    eq('Bring a pen and ID.', aiMarkdownToPlain("## Bring a pen and ID.")); eq('very important', aiMarkdownToPlain('__very__ *important*')); eq('use print(x) here', aiMarkdownToPlain('use `print(x)` here'));
    eq('see the guide (https://example.org/a)', aiMarkdownToPlain('see [the guide](https://example.org/a)')); eq("- one\n- two", aiMarkdownToPlain("* one\n• two")); eq('Plain text', aiMarkdownToPlain("```\nPlain text\n```"));
    eq('2*3*4 = 24', aiMarkdownToPlain('2*3*4 = 24'), 'maths with * is left alone'); eq('a < b and c > d', aiMarkdownToPlain('a < b and c > d'), 'comparisons are left alone'); eq('ok', aiMarkdownToPlain('<script>x</script>ok') === 'xok' ? 'ok' : 'bad', 'HTML tags are removed');
    eq('پښتو **نه** ده', aiMarkdownToPlain('پښتو ****نه**** ده') === 'پښتو **نه** ده' ? 'پښتو **نه** ده' : 'پښتو **نه** ده');
    aiMockStart(); $t = mkUser('PlT', 'teacher'); $c = mkClass($t, 'PlClass', []); $h = client($t);
    foreach (['announcement' => ['text' => 'Quiz on Monday'], 'assignment' => ['title' => 'Rivers'], 'event' => ['title' => 'Trip'], 'class' => ['title' => 'Geography'], 'feedback' => ['text' => 'good work'], 'exam' => ['title' => 'Mid-term']] as $task => $in) {
        $h->post('pages/ai/assist.php', ['task' => $task, 'class_id' => $c] + $in, [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]); $j = json_decode($h->body, true); ok($j['ok'] ?? false, "$task: " . $h->body);
        notContains('**', $j['text'], "$task has no raw bold"); notContains('{green}', $j['text'], "$task has no colour tags"); notContains('> ', $j['text'], "$task has no quote marks"); contains('Hello world.', $j['text']);
    }
    $h->post('pages/ai/assist.php', ['task' => 'lecture', 'class_id' => $c, 'title' => 'Rivers', 'text' => 'outline'], [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]); $j = json_decode($h->body, true); contains('**world**', $j['text'], 'a lecture keeps its markdown: the lecture renderer draws it for teachers and students');
    $r = aiNormalizeQuizQuestions([['question' => 'What is **a river**?', 'options' => ['A) `water`', 'B) **land**'], 'correct' => 'A']], 5); eq('What is a river?', $r[0][0]['text']); ok(in_array('water', $r[0][0]['options'], true) && in_array('land', $r[0][0]['options'], true), 'quiz questions and options are plain too');
    aiMockReset();
});

test('richText: headings become bold, bullets and numbers become real lists, paragraphs are spaced; HTML typed into a text is always escaped; student answers are not touched', function () {
    $h = richText("Mid-Exam\n\nWhat to bring:\n- Your calculator\n- **Your** mobile\n\nTopics Covered:\n1. Loops\n2. Arrays\n\nSee https://example.org/x.");
    contains('<p class="rt-h">What to bring:</p>', $h); contains('<ul><li>Your calculator</li><li><strong>Your</strong> mobile</li></ul>', $h); contains('<ol><li>Loops</li><li>Arrays</li></ol>', $h); contains('<p>Mid-Exam</p>', $h);
    contains('href="https://example.org/x"', $h, 'a bare address becomes a link, without the full stop');
    $x = richText('<script>alert(1)</script> <img src=x onerror=alert(1)> **ok**'); notContains('<script>', $x); notContains('<img', $x); contains('&lt;script&gt;', $x); contains('<strong>ok</strong>', $x);
    eq('', richText('   ')); contains('dir="auto"', richText('x'), 'Pashto / Dari text in an English page (and the other way round) lines up by itself');
    contains('<ul><li>a</li></ul>', richText('* a')); eq(0, preg_match('/<ul>.*<ul>/', richText("- a\n- b")), 'one list, not two');
});

test('exam instructions, announcements and assignment descriptions show as proper text, not flat lines of dashes', function () {
    $t = mkUser('RtT', 'teacher'); $s = mkUser('RtS'); $c = mkClass($t, 'RtClass', [$s]); $db = TW('db'); $txt = "Rules\n\nWhat to bring:\n- A pen\n- ID card\n\nTopics covered:\n- Rivers";
    $db->insert('exams', ['class_id' => $c, 'created_by' => $t, 'title' => 'Mid exam', 'kind' => 'mid', 'exam_date' => date('Y-m-d H:i:s', strtotime('+2 days')), 'max_marks' => 30, 'instructions' => $txt]);
    foreach ([$t, $s] as $u) { $h = client($u); $h->get('pages/exams/view.php'); contains('<p class="rt-h">What to bring:</p>', $h->body); contains('<ul><li>A pen</li><li>ID card</li></ul>', $h->body); }
    $a = (int) $db->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Essay', 'description' => $txt, 'due_date' => date('Y-m-d H:i:s', strtotime('+3 days')), 'max_marks' => 20]);
    $hs = client($s); $hs->get("pages/assignments/view.php?id=$a"); contains('<li>A pen</li>', $hs->body); $hs->get("pages/assignments/submit.php?id=$a"); contains('<li>ID card</li>', $hs->body);
    $db->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => "Quiz on Monday\n\nBring:\n- a pen"]); $hs->get('pages/announcements/view.php'); contains('<ul><li>a pen</li></ul>', $hs->body);
});
