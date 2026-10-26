<?php
test('top-bar search: finds things in the person\'s own classes only, students see no other people and no unpublished posts, teachers find their students, wildcards are plain text, short or too many searches are refused', function () {
    $db = TW('db'); $t = mkUser('SeT', 'teacher'); $t2 = mkUser('SeT2', 'teacher'); $s = mkUser('Se Student'); $s2 = mkUser('Se Other'); $x = mkUser('SeX');
    $c = mkClass($t, 'Zebra Biology', [$s, $s2]); $c2 = mkClass($t2, 'Zebra Secret Chem', [$x]);
    $db->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Zebra stripes lecture', 'content' => 'x', 'status' => 'published']);
    $db->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Zebra draft lecture', 'content' => 'x', 'status' => 'draft']);
    $db->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Zebra later lecture', 'content' => 'x', 'status' => 'published', 'publish_at' => date('Y-m-d H:i:s', strtotime('+3 day'))]);
    $db->insert('lectures', ['class_id' => $c2, 'teacher_id' => $t2, 'title' => 'Zebra other-class lecture', 'content' => 'x', 'status' => 'published']);
    $db->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => 'Zebra trip on Friday', 'pinned' => 0, 'notified_at' => date('Y-m-d H:i:s')]);
    $db->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => 'Zebra scheduled notice', 'pinned' => 0, 'publish_at' => date('Y-m-d H:i:s', strtotime('+2 day'))]);
    $db->insert('resources', ['class_id' => $c, 'uploaded_by' => $t, 'title' => 'Zebra notes', 'category' => 'research-paper', 'file_path' => 'x/z', 'original_name' => 'z.pdf', 'file_type' => 'pdf', 'file_size' => 5]);
    $db->insert('forum_threads', ['class_id' => $c, 'user_id' => $s, 'title' => 'Why zebra stripes?', 'body' => 'b', 'last_post_at' => date('Y-m-d H:i:s')]);
    $titles = fn($groups) => array_merge(...array_map(fn($g) => array_column($g['items'], 'title'), $groups ?: [['items' => []]]));
    $g = searchEverything('zebra', $s, 'student'); $tt = $titles($g);
    ok(in_array('Zebra Biology', $tt, true) && in_array('Zebra stripes lecture', $tt, true) && in_array('Zebra notes', $tt, true) && in_array('Why zebra stripes?', $tt, true), 'class, published lecture, resource and forum topic: ' . json_encode($tt));
    ok(!in_array('Zebra draft lecture', $tt, true) && !in_array('Zebra later lecture', $tt, true), 'a student never finds a draft or not-yet-published lecture'); ok(!in_array('Zebra other-class lecture', $tt, true) && !in_array('Zebra Secret Chem', $tt, true), 'nothing from another class');
    ok(count(array_filter($tt, fn($x) => strpos($x, 'scheduled notice') !== false)) === 0 && count(array_filter($tt, fn($x) => strpos($x, 'trip on Friday') !== false)) === 1, 'only the published announcement');
    ok(!in_array('student', array_column($g, 'kind'), true), 'students get no people search'); eq([], searchEverything('Se Other', $s, 'student'), 'a student cannot look up a classmate');
    $gt = searchEverything('zebra', $t, 'teacher'); $ttt = $titles($gt); ok(in_array('Zebra draft lecture', $ttt, true) && in_array('Zebra later lecture', $ttt, true), 'the teacher also finds drafts and scheduled lectures'); ok(!in_array('Zebra other-class lecture', $ttt, true), 'but only in their classes');
    $st = array_values(array_filter(searchEverything('Se Student', $t, 'teacher'), fn($g) => $g['kind'] === 'student')); eq(1, count($st)); eq('Se Student', $st[0]['items'][0]['title']); contains('student_id=' . $s, $st[0]['items'][0]['url']); eq([], array_filter(searchEverything('SeX', $t, 'teacher'), fn($g) => $g['kind'] === 'student'), 'not another teacher\'s student');
    eq([], searchEverything('z', $s, 'student'), 'one letter is not a search'); eq([], searchEverything('%%', $s, 'student'), 'percent is not a wildcard'); eq([], searchEverything('_____', $s, 'student'), 'underscore is not a wildcard'); eq([], searchEverything('zebra', mkUser('SeLone'), 'student'), 'a student with no class finds nothing');
    // the endpoint
    $h = client($s); $h->get('pages/search.php?q=zebra'); $j = json_decode($h->body, true); ok($j['ok'] && count($j['groups']) >= 3, 'JSON answer'); $h->get('pages/search.php?q=z'); eq([], json_decode($h->body, true)['groups']); ok(!anon()->get('pages/search.php?q=zebra')->shown(), 'sign-in needed');
    $h->get('pages/dashboard.php'); contains('id="scSearchBtn"', $h->body, 'the search icon is in the top bar'); contains('id="scSearchInput"', $h->body, 'it expands into a field in the header'); notContains('id="scSearch"', $h->body, 'no pop-up window'); notContains('newDropdown', $h->body, 'no extra "New" menu — the greeting already has the create buttons');
    $ht = client($t); $ht->get('pages/dashboard.php'); contains('assets/js/search.js', $ht->body); contains('New class', $ht->body, 'the greeting\'s create buttons are still there'); notContains('newDropdown', $ht->body);
});

test('dashboard: "needs you now" lists what has a count, with the first as "Start here", or says all caught up', function () {
    $db = TW('db'); $t = mkUser('DnT', 'teacher'); $s = mkUser('DnS'); $c = mkClass($t, 'Dn Class', [$s]); $h = client($t);
    $h->get('pages/dashboard.php'); contains('dash-now', $h->body); contains('Needs you now', $h->body, 'a class with students and no attendance today needs the teacher'); contains('Start here', $h->body); contains('Classes without attendance today', $h->body);
    $e = client(mkUser('DnE', 'teacher')); $e->get('pages/dashboard.php'); contains('You are all caught up', $e->body, 'a teacher with nothing waiting'); notContains('Start here', $e->body);
});
