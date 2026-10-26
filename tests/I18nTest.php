<?php
test('i18n: phrases, patterns, dates and composite strings translate; unknown text and user content stay as written', function () {
    foreach (['ps', 'fa'] as $l) {
        ok(i18nTranslateString('Dashboard', $l) !== null, "dictionary $l"); ok(strpos(i18nTranslateString('12 students', $l), '12') !== false, "number rule $l");
        $d = i18nTranslateString('Tue, Oct 13, 2026 · 02:00 PM', $l); ok($d !== null && strpos($d, 'Oct') === false && strpos($d, 'PM') === false, "date words $l: $d");
        $c = i18nTranslateString('Assignment · out of 50 · due Oct 23, 02:42 AM', $l); ok($c !== null && strpos($c, 'Assignment') === false && strpos($c, 'due') === false, "composite $l: $c");
        $g = i18nTranslateString('Good morning, Mr. Khairullah', $l); ok($g !== null && strpos($g, 'Mr. Khairullah') !== false && strpos($g, 'Good') === false, "greeting keeps the name $l");
        eq(null, i18nTranslateString('Photosynthesis turns light into sugar', $l), 'unknown text is left alone'); eq(null, i18nTranslateString('Ahmed Khan', $l), 'a person\'s name is left alone');
    }
    eq('ډشبورډ', i18nTranslateString('Dashboard', 'ps')); eq('داشبورد', i18nTranslateString('Dashboard', 'fa'));
});
test('i18n: the page is translated on its way out, user content and code are not; html lang / dir are set; ?lang= is remembered', function () {
    $u = mkUser('I18nT', 'teacher'); $c = mkClass($u, 'Dashboard'); TW('db')->insert('lectures', ['class_id' => $c, 'teacher_id' => $u, 'title' => 'L', 'status' => 'published', 'content' => "Dashboard Classes Settings\n\n```python\nprint('Dashboard')\n```"]);
    $h = client($u); $h->get('pages/dashboard.php?lang=ps'); ok($h->shown()); contains('dir="rtl"', $h->body); contains('lang="ps"', $h->body); contains('ډشبورډ', $h->body, 'menu translated'); contains('rtl.css', $h->body);
    $h->get('pages/dashboard.php'); contains('ډشبورډ', $h->body, 'the choice is remembered (cookie / account)'); eq('ps', TW('db')->fetchOne('SELECT lang FROM users WHERE id = ?', [$u])['lang'], 'saved on the account');
    $l = (int) TW('db')->fetchOne('SELECT id FROM lectures WHERE class_id = ?', [$c])['id']; $h->get("pages/lectures/view.php?id=$l");
    preg_match('#<div class="lec-body">(.*?)</div>\s*(?:<|$)#s', $h->body, $m); contains('Dashboard Classes Settings', $h->body, 'the teacher\'s lecture text keeps its words ... '); contains("print(&#039;Dashboard&#039;)", $h->body, 'code is never translated');
    $h->get('pages/dashboard.php?lang=en'); notContains('dir="rtl"', $h->body); contains('lang="en"', $h->body); eq('en', TW('db')->fetchOne('SELECT lang FROM users WHERE id = ?', [$u])['lang']);
    $a = anon(); $a->get('pages/auth/login.php?lang=fa'); contains('dir="rtl"', $a->body); contains('bootstrap.rtl.min.css', $a->body, 'Bootstrap\'s mirrored build on the sign-in page');
});
test('i18n: a saved language follows the person at the next sign-in; JSON answers are never touched', function () {
    $u = mkUser('I18nS'); TW('db')->update('users', ['lang' => 'fa'], 'id = ?', [$u]); $h = client($u); $h->get('pages/dashboard.php'); contains('lang="fa"', $h->body);
    $t = mkUser('I18nJ', 'teacher'); $c = mkClass($t, 'J', [$u]); $hs = client($u); $tok = $hs->token('pages/classes/my-classes.php'); $hs->get('pages/ai/tutor.php?ajax=list&class_id=' . $c . '&lang=ps'); ok(json_decode($hs->body, true) !== null, 'valid JSON even with ?lang=ps');
});
test('the header has the language dropdown: its options link to THIS page with the other language (no trip to Settings)', function () {
    $u = mkUser('I18nSb', 'teacher'); $h = client($u); $h->get('pages/classes/my-classes.php?x=1'); contains('<details class="hl-dd"', $h->body, 'a dropdown'); notContains('sidebar-lang', $h->body, 'not in the sidebar');
    preg_match_all('#<li role="option"[^>]*><a href="([^"]+)"#', $h->body, $m); eq(3, count($m[1]), 'three languages');
    foreach ($m[1] as $href) { contains('my-classes.php', $href); contains('x=1', $href, 'the page and its query are kept'); notContains('settings', $href); }
    contains('lang=ps', implode(' ', $m[1])); contains('lang=fa', implode(' ', $m[1]));
    notContains('appearance.php#language', $h->body, 'no header link that leaves the page');
});
test('i18n: sentence patterns, text before a <code> block, and the phrase list for the browser', function () {
    foreach (['ps', 'fa'] as $l) {
        foreach (['Report card of Ahmed Khan (PDF)', 'Remove Ahmed Khan', '28/50 marks · final 26.2%', '10 of 12 days attended', 'Move the 9 students too', '83 models from OpenAI, loaded automatically.', 'Grade — Ahmed Khan, Worksheet 1', '5 minutes ago'] as $s) {
            $r = i18nTranslateString($s, $l); ok($r !== null && !preg_match('/[A-Za-z]{4,}/', preg_replace('/Ahmed Khan|OpenAI|Worksheet/', '', $r)), "pattern $l: $s => $r");
        }
        contains('Ahmed Khan', i18nTranslateString('Remove Ahmed Khan', $l), 'the name is kept as written');
    }
    $_COOKIE['sc_lang'] = 'ps'; $h = client(mkUser('I18nC', 'teacher')); $h->get('pages/dashboard.php?lang=ps'); ok($h->shown());
    $a = anon(); $a->get('assets/js/i18n-dict.php?lang=fa'); contains('window.SC_DICT=', $a->body); contains('نام صنف الزامی است', $a->body, 'form messages are in the list');
    $a->get('assets/js/i18n-dict.php?lang=en'); eq('window.SC_DICT={};', trim($a->body), 'English has no list');
    $p = anon(); $p->get('pages/auth/login.php?lang=ps'); contains('i18n-live.js', $p->body, 'the live translator is loaded in Pashto');
    $p->get('pages/auth/login.php?lang=en'); notContains('i18n-live.js', $p->body);
});
