<?php
test('page control: the window is kept inside the pages, the numbers have gaps, the links keep the other filters', function () {
    $_GET = ['p' => '99', 'q' => 'abc']; $_SERVER['REQUEST_URI'] = '/x/list.php?q=abc&p=99';
    eq([5, 5, 40], pagerWindow(45, 10), 'a page too far is the last page'); $_GET['p'] = '0'; eq([1, 5, 0], pagerWindow(45, 10), 'page 0 is page 1'); $_GET['p'] = 'zz'; eq(1, pagerWindow(0, 10)[0]);
    eq([1, null, 8, 9, 10, 11, 12, null, 20], pagerNumbers(10, 20), 'first, last and two either side with gaps'); eq([1, 2, 3], pagerNumbers(2, 3));
    $_GET = ['q' => 'abc', 'p' => '3']; $h = pagerHtml(45, 3, 10);
    contains('Showing 21–30 of 45', $h); contains('q=abc', $h, 'the search is kept'); contains('q=abc&amp;p=2', $h); contains('aria-current="page"', $h);
    eq('', pagerHtml(0, 1, 10), 'nothing to page'); contains('Showing all 4', pagerHtml(4, 1, 10), 'one page: just the count'); contains('href="/x/list.php?q=abc"', pagerHtml(45, 2, 10), 'page 1 has the search but no p=');
    $_GET = [];
});

test('long lists are paged and filtered: forums, announcements, events, resources, research library and projects', function () {
    $db = TW('db'); $t = mkUser('LpT', 'teacher'); $s = mkUser('LpS'); $c = mkClass($t, 'Lp Class', [$s]); $h = client($t);
    for ($i = 1; $i <= 17; $i++) $db->insert('forum_threads', ['class_id' => $c, 'user_id' => $s, 'title' => sprintf('Topic %02d', $i), 'body' => 'b', 'last_post_at' => date('Y-m-d H:i:s', strtotime("-$i minute"))]);
    $h->get('pages/forums/view.php'); contains('Topic 01', $h->body); notContains('Topic 11', $h->body, 'only 10 on the first page'); contains('Showing 1–10 of 17', $h->body);
    $h->get('pages/forums/view.php?p=2'); contains('Topic 16', $h->body); notContains('Topic 01<', $h->body); $h->get('pages/forums/view.php?q=Topic+07'); contains('Topic 07', $h->body); notContains('Topic 08', $h->body, 'the search narrows the list');
    for ($i = 1; $i <= 12; $i++) $db->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => "Notice number $i", 'pinned' => 0, 'notified_at' => date('Y-m-d H:i:s')]);
    $h->get('pages/announcements/view.php'); contains('Showing 1–10 of 12', $h->body); $h->get('pages/announcements/view.php?q=number+11'); contains('Notice number 11', $h->body); notContains('Notice number 3<', $h->body);
    for ($i = 1; $i <= 14; $i++) $db->insert('class_events', ['class_id' => $c, 'created_by' => $t, 'title' => sprintf('Event %02d', $i), 'starts_at' => date('Y-m-d H:i:s', strtotime("+$i day"))]);
    $h->get('pages/events/view.php'); contains('Showing 1–10 of 14', $h->body); $h->get('pages/events/view.php?q=Event+13'); contains('Event 13', $h->body); notContains('Event 02', $h->body);
    foreach (['pdf' => 'research-paper', 'docx' => 'presentation'] as $ext => $cat) for ($i = 1; $i <= 12; $i++) $db->insert('resources', ['class_id' => $c, 'uploaded_by' => $t, 'title' => "File $ext $i", 'category' => $cat, 'file_path' => "x/$ext$i", 'original_name' => "f$i.$ext", 'file_type' => $ext, 'file_size' => 100]);
    $h->get('pages/resources/view.php'); contains('Showing 1–10 of 24', $h->body); $h->get('pages/resources/view.php?type=docx'); contains('File docx 5', $h->body); notContains('File pdf 5', $h->body, 'file type filter'); contains('Showing 1–10 of 12', $h->body, 'one page: just the count');
    $h->get('pages/resources/view.php?cat=research-paper&q=pdf+3'); contains('File pdf 3', $h->body); notContains('File docx', $h->body, 'category + search together'); contains('Clear filters', $h->body);
    for ($i = 1; $i <= 17; $i++) $db->insert('research_items', ['class_id' => $c, 'added_by' => $t, 'kind' => 'paper', 'title' => sprintf('Paper %02d', $i)]);
    $h->get('pages/research/view.php'); contains('Showing 1–10 of 17', $h->body);
    for ($i = 1; $i <= 14; $i++) $db->insert('research_projects', ['class_id' => $c, 'student_id' => $s, 'title' => sprintf('Project %02d', $i), 'status' => 'draft']);
    $h->get('pages/research/projects.php'); contains('Showing 1–10 of 14', $h->body); $h->get('pages/research/projects.php?p=2'); contains('Showing 11–14 of 14', $h->body);
});

test('edit: events, timetable slots, resources, library items and announcements — the owner edits, the class is told what changed, outsiders and students cannot', function () {
    $db = TW('db'); $t = mkUser('EdT2', 'teacher'); $t2 = mkUser('EdT3', 'teacher'); $s = mkUser('EdS3'); $c = mkClass($t, 'Ed Class', [$s]); $c2 = mkClass($t2, 'Other Ed', [mkUser('EdS4')]); $h = client($t); $o = client($t2); $st = client($s);
    $post = function ($cl, $path, array $f) { $cl->get($path); $tok = $cl->token($path); $cl->post($path, $f + ['csrf_token' => $tok]); };
    $told = fn($type) => (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = ?", [$s, $type])['c'];
    // ---- event
    $eid = (int) $db->insert('class_events', ['class_id' => $c, 'created_by' => $t, 'title' => 'Trip', 'starts_at' => date('Y-m-d 09:00:00', strtotime('+3 day')), 'location' => 'Park']);
    $h->get("pages/events/create.php?id=$eid"); ok($h->shown()); contains('Edit event', $h->body); contains('value="Trip"', $h->body); contains('Save changes', $h->body);
    $url = "pages/events/create.php?id=$eid"; $start = date('Y-m-d\T09:00', strtotime('+3 day'));
    $post($h, $url, ['id' => $eid, 'class_id' => $c, 'title' => 'Trip', 'starts_at' => $start, 'ends_at' => '', 'location' => 'Park', 'description' => '']); eq(0, $told('event_updated'), 'nothing changed → nobody is told');
    $post($h, $url, ['id' => $eid, 'class_id' => $c, 'title' => 'Museum trip', 'starts_at' => $start, 'ends_at' => '', 'location' => 'Museum', 'description' => 'Bring a pen']);
    $e = $db->fetchOne("SELECT title, location, description FROM class_events WHERE id = ?", [$eid]); eq('Museum trip', $e['title']); eq('Museum', $e['location']); eq(1, $told('event_updated'), 'the students are told');
    $n = $db->fetchOne("SELECT message FROM notifications WHERE user_id = ? AND type = 'event_updated'", [$s])['message']; contains('title', $n); contains('place', $n); contains('details', $n); notContains('time', explode(' — ', $n)[0], 'only what changed is listed');
    $post($h, $url, ['id' => $eid, 'class_id' => $c, 'title' => '', 'starts_at' => $start]); eq('Museum trip', $db->fetchOne("SELECT title FROM class_events WHERE id = ?", [$eid])['title'], 'an empty title is refused');
    $o->get($url); ok(!$o->shown(), 'another teacher cannot edit it'); $st->get($url); ok(!$st->shown(), 'a student cannot');
    // ---- timetable slot
    $sid = (int) $db->insert('class_schedule', ['class_id' => $c, 'created_by' => $t, 'day_of_week' => 2, 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'room' => 'R1']);
    $sid2 = (int) $db->insert('class_schedule', ['class_id' => $c, 'created_by' => $t, 'day_of_week' => 3, 'start_time' => '08:00:00', 'end_time' => '09:00:00']);
    $h->get("pages/schedules/create.php?id=$sid"); ok($h->shown()); contains('Edit timetable slot', $h->body); contains('type="radio"', $h->body, 'one slot = one day');
    $u = "pages/schedules/create.php?id=$sid";
    $post($h, $u, ['id' => $sid, 'class_id' => $c, 'days' => ['3'], 'start_time' => '08:30', 'end_time' => '09:30', 'room' => 'R1', 'note' => '']); eq(2, (int) $db->fetchOne("SELECT day_of_week FROM class_schedule WHERE id = ?", [$sid])['day_of_week'], 'it cannot overlap another slot of the same class');
    $post($h, $u, ['id' => $sid, 'class_id' => $c, 'days' => ['2'], 'start_time' => '08:30', 'end_time' => '09:30', 'room' => 'R2', 'note' => 'Lab']); $r = $db->fetchOne("SELECT start_time, room, note FROM class_schedule WHERE id = ?", [$sid]); eq('08:30:00', $r['start_time']); eq('R2', $r['room']); eq(1, $told('schedule_updated'), 'the class is told');
    $post($h, $u, ['id' => $sid, 'class_id' => $c, 'days' => ['2'], 'start_time' => '08:00', 'end_time' => '09:00', 'room' => 'R2', 'note' => 'Lab']); eq('08:00:00', $db->fetchOne("SELECT start_time FROM class_schedule WHERE id = ?", [$sid])['start_time'], 'moving back onto its own old time is fine (it ignores itself)');
    $o->get($u); ok(!$o->shown());
    // ---- resource
    $rid = (int) $db->insert('resources', ['class_id' => $c, 'uploaded_by' => $t, 'title' => 'Notes', 'category' => 'research-paper', 'file_path' => 'x/n', 'original_name' => 'n.pdf', 'file_type' => 'pdf', 'file_size' => 10]);
    $ru = "pages/resources/edit.php?id=$rid"; $h->get($ru); ok($h->shown()); contains('n.pdf', $h->body, 'the file is shown, not editable');
    $post($h, $ru, ['id' => $rid, 'title' => 'Week 1 notes', 'category' => 'presentation', 'description' => 'Slides and notes']); $r = $db->fetchOne("SELECT title, category, description, file_path FROM resources WHERE id = ?", [$rid]); eq('Week 1 notes', $r['title']); eq('presentation', $r['category']); eq('x/n', $r['file_path'], 'the file stays');
    $post($h, $ru, ['id' => $rid, 'title' => 'Bad', 'category' => 'nonsense']); eq('Week 1 notes', $db->fetchOne("SELECT title FROM resources WHERE id = ?", [$rid])['title'], 'an unknown category is refused'); $o->get($ru); ok(!$o->shown()); $st->get($ru); ok(!$st->shown());
    // ---- library item
    $lid = (int) $db->insert('research_items', ['class_id' => $c, 'added_by' => $t, 'kind' => 'paper', 'title' => 'Old title']);
    $lu = "pages/research/add.php?id=$lid"; $h->get($lu); ok($h->shown()); contains('Edit research item', $h->body);
    $post($h, $lu, ['id' => $lid, 'class_id' => $c, 'kind' => 'book', 'title' => 'New title', 'authors' => 'A. Writer', 'year' => '2020', 'url' => 'https://example.org/x', 'tags' => 'a,b', 'summary' => 'Good']); $r = $db->fetchOne("SELECT kind, title, year FROM research_items WHERE id = ?", [$lid]); eq('book', $r['kind']); eq('New title', $r['title']); eq(2020, (int) $r['year']);
    $post($h, $lu, ['id' => $lid, 'class_id' => $c, 'kind' => 'book', 'title' => 'X', 'url' => 'javascript:alert(1)']); eq('New title', $db->fetchOne("SELECT title FROM research_items WHERE id = ?", [$lid])['title'], 'a javascript: link is refused'); $o->get($lu); ok(!$o->shown());
    // ---- announcements: author only; scheduled keeps its time and is not marked edited
    $aid = (int) $db->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => 'Hello class', 'pinned' => 0, 'notified_at' => date('Y-m-d H:i:s')]);
    $au = "pages/announcements/edit.php?id=$aid"; $h->get($au); ok($h->shown()); contains('marked “Edited”', $h->body);
    $post($h, $au, ['id' => $aid, 'body' => 'Hello class, exam moved', 'pin' => '1']); $r = $db->fetchOne("SELECT body, pinned, edited_at FROM announcements WHERE id = ?", [$aid]); eq('Hello class, exam moved', $r['body']); eq(1, (int) $r['pinned']); ok($r['edited_at'] !== null, 'a published post is marked edited');
    $post($h, $au, ['id' => $aid, 'body' => '']); eq('Hello class, exam moved', $db->fetchOne("SELECT body FROM announcements WHERE id = ?", [$aid])['body'], 'an empty post is refused');
    $future = date('Y-m-d H:i:00', strtotime('+2 day')); $sched = (int) $db->insert('announcements', ['class_id' => $c, 'user_id' => $t, 'body' => 'Later', 'pinned' => 0, 'publish_at' => $future]);
    $su = "pages/announcements/edit.php?id=$sched"; $h->get($su); contains('Goes out at', $h->body); $new = date('Y-m-d\TH:i', strtotime('+3 day'));
    $post($h, $su, ['id' => $sched, 'body' => 'Later (updated)', 'publish_at' => $new]); $r = $db->fetchOne("SELECT body, publish_at, edited_at FROM announcements WHERE id = ?", [$sched]); eq('Later (updated)', $r['body']); eq(date('Y-m-d H:i:00', strtotime('+3 day')), $r['publish_at']); eq(null, $r['edited_at'], 'a scheduled post is just changed');
    $post($h, $su, ['id' => $sched, 'body' => 'x', 'publish_at' => date('Y-m-d\TH:i', strtotime('-1 day'))]); eq('Later (updated)', $db->fetchOne("SELECT body FROM announcements WHERE id = ?", [$sched])['body'], 'a time in the past is refused');
    $co = client(mkUser('EdT5', 'teacher')); $co->get($au); ok(!$co->shown(), 'a teacher who is not the author cannot edit'); $o->get($au); ok(!$o->shown()); $st->get($au); ok(!$st->shown());
    $h->get('pages/announcements/view.php'); contains("edit.php?id=$aid", $h->body, 'the Edit button is on the author\'s own post'); contains('Edited', $h->body);
});

test('back links: they return to the page the person came from (a list keeps its filters), never to the same page, another site or a sign-in page, and every form / detail page has one', function () {
    $_SERVER['HTTP_HOST'] = 'localhost'; $base = (string) parse_url(APP_URL, PHP_URL_PATH); $_SERVER['REQUEST_URI'] = $base . '/pages/events/create.php?id=3';
    $set = function ($ref) { if ($ref === null) unset($_SERVER['HTTP_REFERER']); else $_SERVER['HTTP_REFERER'] = $ref; };
    $set(null); eq('view.php', backUrl('view.php'), 'no referrer → the default');
    $set("http://localhost$base/pages/events/view.php?class_id=5&p=2"); eq("$base/pages/events/view.php?class_id=5&p=2", backUrl('view.php'), 'back to the filtered, paged list the person came from');
    $set("http://localhost$base/pages/dashboard.php"); eq("$base/pages/dashboard.php", backUrl('view.php'), 'from the dashboard → back to the dashboard');
    $set("http://evil.example$base/pages/events/view.php"); eq('view.php', backUrl('view.php'), 'another site is ignored'); $set('https://localhost.evil.example/pages/x.php'); eq('view.php', backUrl('view.php'));
    $set("http://localhost$base/pages/auth/login.php"); eq('view.php', backUrl('view.php'), 'never back to sign-in'); $set("http://localhost$base/pages/events/create.php?id=3&lang=ps"); eq('view.php', backUrl('view.php'), 'not the same page');
    $set("http://localhost$base/pages/search.php?q=x"); eq('view.php', backUrl('view.php'), 'not an endpoint'); $set("http://localhost$base/assets/js/app.js"); eq('view.php', backUrl('view.php'), 'only the app\'s pages'); $set('javascript:alert(1)'); eq('view.php', backUrl('view.php'));
    eq('view.php?id=3&c=2', backUrl('view.php?id=3&amp;c=2'), 'an escaped default is understood'); contains('href="view.php"', backLink('Events', 'view.php')); contains('fa-arrow-left', backLink('Events', 'view.php')); contains('&lt;b&gt;', backLink('<b>', 'x.php'), 'the label is escaped');
    $set(null);
    // through the web: the Referer is honoured on a real page, and the form / detail pages all carry a back link
    $db = TW('db'); $t = mkUser('BkT', 'teacher'); $s = mkUser('BkS'); $c = mkClass($t, 'Bk Class', [$s]); $h = client($t);
    $eid = (int) $db->insert('class_events', ['class_id' => $c, 'created_by' => $t, 'title' => 'Bk event', 'starts_at' => date('Y-m-d H:i:s', strtotime('+2 day'))]);
    $h->get("pages/events/create.php?id=$eid"); contains('class="back-link"', $h->body); contains('href="view.php"', $h->body, 'no referrer: the default');
    foreach (['pages/events/create.php', 'pages/announcements/create.php', 'pages/schedules/create.php', 'pages/forums/create.php', 'pages/exams/create.php', 'pages/research/add.php', 'pages/assignments/create.php?class_id=' . $c, 'pages/quizzes/create.php?class_id=' . $c, 'pages/lectures/add.php?class_id=' . $c, 'pages/resources/upload.php?class_id=' . $c, 'pages/classes/create.php', 'pages/reports/school.php', 'pages/attendance/markAttendance.php'] as $p) { $h->get($p); if ($h->shown()) contains('back-link', $h->body, "a back link on $p"); }
    $hs = client($s); foreach (['pages/research/start.php', 'pages/classes/join.php', 'pages/forums/create.php'] as $p) { $hs->get($p); if ($hs->shown()) contains('back-link', $hs->body, "a back link on $p"); }
});
