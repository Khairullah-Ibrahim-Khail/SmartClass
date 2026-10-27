<?php
/**
 * SmartClass - interface languages (loaded by includes/functions.php)
 * English (default), Pashto (ps) and Dari (fa); Pashto and Dari are written right-to-left.
 *   i18nLang() / i18nRtl()        the language of this request: ?lang= (remembered) > the person's saved language > cookie > school default (Settings › Appearance) > en
 *   i18nSet($lang)                remember a choice (cookie, session and — when signed in — the account)
 *   t($english)                   one phrase, for code that builds text itself
 *   i18nStart()                   call at the top of a page: the whole page is translated on its way out (text between tags and the title / placeholder /
 *                                 aria-label attributes that EXACTLY match a phrase in includes/lang/strings.php, plus a few number patterns).
 *                                 User content (lecture text, posts, code, anything between <!--no-i18n--> … <!--/no-i18n-->) is never touched.
 *   i18nHtmlAttrs()               lang="…" dir="…" for the <html> tag
 * Missing phrases simply stay in English — nothing breaks. Add phrases to includes/lang/strings.php.
 */
function i18nLanguages() { return ['en' => ['English', 'English'], 'ps' => ['پښتو', 'Pashto'], 'fa' => ['دری', 'Dari']]; }

function i18nLang() {
    static $lang = null;
    if ($lang !== null) return $lang;
    $ok = fn($v) => is_string($v) && isset(i18nLanguages()[$v]);
    if (PHP_SAPI !== 'cli' && isset($_GET['lang']) && $ok($_GET['lang']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { i18nSet($_GET['lang']); return $lang = $_GET['lang']; }
    foreach ([$_SESSION['lang'] ?? null, $_COOKIE['sc_lang'] ?? null, function_exists('getSetting') ? getSetting('default_language', '') : null] as $c) if ($ok($c)) return $lang = $c;
    return $lang = 'en';
}

/** The address of THIS page with a language chosen: same path, same query, only ?lang= replaced. */
function i18nSwitchUrl($code) {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) parse_url($uri, PHP_URL_PATH); parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    $q['lang'] = $code;
    return $path . '?' . http_build_query($q);
}

function i18nRtl() { return i18nLang() !== 'en'; }

function i18nSet($lang) {
    global $db;
    if (!isset(i18nLanguages()[$lang])) return;
    $_SESSION['lang'] = $lang;
    if (PHP_SAPI !== 'cli' && !headers_sent()) setcookie('sc_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => function_exists('requestIsHttps') && requestIsHttps()]);
    if (!empty($_SESSION['user_id'])) { try { $db->query("UPDATE users SET lang = ? WHERE id = ?", [$lang, (int) $_SESSION['user_id']]); } catch (Throwable $e) {} }
}

/** At sign-in: the person's saved language wins over the cookie. */
function i18nOnLogin($user) {
    if (!empty($user['lang']) && isset(i18nLanguages()[$user['lang']])) $_SESSION['lang'] = $user['lang'];
}

function i18nHtmlAttrs() { return 'lang="' . i18nLang() . '" dir="' . (i18nRtl() ? 'rtl' : 'ltr') . '"'; }

/** <script> tags that translate what the browser builds itself (pop-ups, inline errors). Empty in English. */
function i18nLiveScripts() {
    $l = i18nLang(); if ($l === 'en') return '';
    $v = max(filemtime(__DIR__ . '/lang/strings.php'), filemtime(__DIR__ . '/lang/strings_more.php'));
    return '<script defer src="' . APP_URL . '/assets/js/i18n-dict.php?lang=' . $l . '&v=' . $v . '"></script><script defer src="' . asset('assets/js/i18n-live.js') . '"></script>';
}

/** Corrections by a native speaker (lang/overrides.php); they win over the built-in wording. */
function i18nOverrides() {
    static $o = null;
    if ($o === null) { $f = getenv('SC_LANG_OVERRIDES') ?: __DIR__ . '/lang/overrides.php'; $o = is_file($f) ? (require $f) : []; $o += ['strings' => [], 'fragments' => [], 'rules' => []]; }
    return $o;
}

function i18nStrings() {
    static $s = null;
    if ($s === null) $s = array_merge(require __DIR__ . '/lang/strings.php', require __DIR__ . '/lang/strings_more.php', i18nOverrides()['strings']);
    return $s;
}

function t($english) {
    $l = i18nLang(); if ($l === 'en') return $english;
    $e = i18nStrings()[$english] ?? null;
    return $e ? ($e[$l === 'ps' ? 0 : 1] ?: $english) : $english;
}

/** Number patterns: "12 students", "out of 50", "Back to X" … returns the translation or null. */
function i18nRule($s, $l) {
    $i = $l === 'ps' ? 0 : 1;
    static $rules = null;
    if ($rules === null) $rules = [
        ['/^(\d+) students?$/', ['%s زده‌کوونکي', '%s شاگرد']], ['/^(\d+) days? marked$/', ['%s ورځې ثبت شوې', '%s روز ثبت‌شده']], ['/^(\d+) marks?$/', ['%s نمرې', '%s نمره']],
        ['/^(\d+) replies$/', ['%s ځوابونه', '%s پاسخ']], ['/^1 reply$/', ['۱ ځواب', '۱ پاسخ']], ['/^(\d+) questions?$/', ['%s پوښتنې', '%s پرسش']], ['/^(\d+) min(?:utes)?(?: read)?$/', ['%s دقیقې', '%s دقیقه']],
        ['/^(\d+) to grade$/', ['%s د نمرې ورکولو لپاره', '%s برای نمره‌دهی']], ['/^(\d+) join requests? waiting$/', ['%s د ګډون غوښتنې انتظار باسي', '%s درخواست پیوستن در انتظار']],
        ['/^(\d+) missing$/', ['%s نشته', '%s تسلیم نشده']], ['/^out of (\d+)$/', ['له %s څخه', 'از %s']], ['/^Back to (.+)$/', ['بېرته %s ته', 'بازگشت به %s']],
        ['/^Marks for (.+)$/', ['د %s نمرې', 'نمرات %s']], ['/^Feedback for (.+)$/', ['د %s لپاره نظر', 'نظر برای %s']], ['/^Select (.+)$/', ['%s وټاکئ', '%s را انتخاب کنید']],
        ['/^(\d+) (?:days?|hours?) ago$/', null],
    ];
    foreach ($rules as [$re, $tr]) {
        if ($tr && preg_match($re, $s, $m)) return str_replace('%s', $m[1] ?? '', $tr[$i]);
    }
    foreach ([
        'New lecture: ' => ['نوی لکچر: ', 'لکچر جدید: '], 'New resource: ' => ['نوې سرچینه: ', 'منبع جدید: '], 'New forum topic in ' => ['په فورم کې نوې موضوع: ', 'موضوع جدید در فورم: '], 'Request to join ' => ['ګډون غوښتنه: ', 'درخواست پیوستن: '],
        'Submitted: ' => ['تسلیم شو: ', 'تسلیم شد: '], 'Quiz completed: ' => ['ازموینه بشپړه شوه: ', 'کویز تکمیل شد: '], 'New reply: ' => ['نوی ځواب: ', 'پاسخ جدید: '], 'You joined ' => ['تاسو یوځای شوئ: ', 'شما پیوستید: '],
        'posted a new lecture: ' => ['نوی لکچر یې خپور کړ: ', 'لکچر جدید منتشر کرد: '], 'posted a new quiz: ' => ['نوې ازموینه یې خپره کړه: ', 'کویز جدید منتشر کرد: '], 'posted a new assignment: ' => ['نوې دنده یې خپره کړه: ', 'وظیفه جدید منتشر کرد: '],
        'Delete event ' => ['پېښه ړنګه کړئ: ', 'حذف رویداد: '], 'Remind ' => null,
    ] as $prefix => $tr) { if ($tr && strpos($s, $prefix) === 0 && strlen($s) > strlen($prefix)) return $tr[$i] . substr($s, strlen($prefix)); }
    if (preg_match('/^(.+) sent you a private message$/', $s, $m)) return $i === 0 ? $m[1] . ' تاسو ته شخصي پیغام ولېږه' : $m[1] . ' برای شما پیام خصوصی فرستاد';
    if (preg_match('/^(\d+) questions? · (\d+) marks?$/', $s, $m)) return $i === 0 ? $m[1] . ' پوښتنې · ' . $m[2] . ' نمرې' : $m[1] . ' پرسش · ' . $m[2] . ' نمره';
    if (preg_match('/^(\d+) class comments?$/', $s, $m)) return $i === 0 ? $m[1] . ' د ټولګي نظرونه' : $m[1] . ' نظر صنفی';
    if (preg_match('/^(\d+) downloads?$/', $s, $m)) return $i === 0 ? $m[1] . ' ځله ښکته شوی' : $m[1] . ' بار دانلود شده';
    if (preg_match('/^Remind (\d+) students? who did not hand in$/', $s, $m)) return $i === 0 ? $m[1] . ' زده‌کوونکو ته یادونه وکړئ چې نه دي تسلیم کړي' : 'به ' . $m[1] . ' شاگرد که تسلیم نکرده‌اند یادآوری کنید';
    if (preg_match('/^(\d+) co-teachers?$/', $s, $m) || preg_match('/^\+ (\d+) co-teachers?$/', $s, $m)) return $i === 0 ? '+ ' . $m[1] . ' همکار ښوونکی' : '+ ' . $m[1] . ' استاد همکار';
    if (preg_match('/^(\d+) (minute|hour|day|week|month)s? ago$/', $s, $m)) {
        $u = ['minute' => ['دقیقې', 'دقیقه'], 'hour' => ['ساعته', 'ساعت'], 'day' => ['ورځې', 'روز'], 'week' => ['اونۍ', 'هفته'], 'month' => ['میاشتې', 'ماه']][$m[2]][$i];
        return $i === 0 ? $m[1] . ' ' . $u . ' مخکې' : $m[1] . ' ' . $u . ' پیش';
    }
    if ($s === '1 hour ago') return $i === 0 ? '۱ ساعت مخکې' : '۱ ساعت پیش';
    return null;
}

/** Gregorian month / weekday names and AM / PM inside a date-like text ("Tue, Oct 13, 2026 · 02:00 PM"); null when the text has no date. */
function i18nDateWords($s, $l) {
    if (!preg_match('/\d/', $s) || !preg_match('/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec|January|February|March|April|June|July|August|September|October|November|December|AM|PM)\b/', $s)) return null;
    if (preg_match('/[A-Za-z]{4,}\s+[A-Za-z]{4,}\s+[A-Za-z]{4,}/', preg_replace('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|January|February|March|April|June|July|August|September|October|November|December)\b/', '', $s))) return null;   // a real sentence, not a date
    $i = $l === 'ps' ? 0 : 1;
    $m = $i === 0 ? ['Jan' => 'جنوري', 'Feb' => 'فبروري', 'Mar' => 'مارچ', 'Apr' => 'اپرېل', 'May' => 'مۍ', 'Jun' => 'جون', 'Jul' => 'جولای', 'Aug' => 'اګست', 'Sep' => 'سپتمبر', 'Sept' => 'سپتمبر', 'Oct' => 'اکتوبر', 'Nov' => 'نومبر', 'Dec' => 'دسمبر']
                   : ['Jan' => 'جنوری', 'Feb' => 'فبروری', 'Mar' => 'مارچ', 'Apr' => 'اپریل', 'May' => 'می', 'Jun' => 'جون', 'Jul' => 'جولای', 'Aug' => 'اگست', 'Sep' => 'سپتمبر', 'Sept' => 'سپتمبر', 'Oct' => 'اکتوبر', 'Nov' => 'نومبر', 'Dec' => 'دسمبر'];
    $full = ['January' => 'Jan', 'February' => 'Feb', 'March' => 'Mar', 'April' => 'Apr', 'June' => 'Jun', 'July' => 'Jul', 'August' => 'Aug', 'September' => 'Sep', 'October' => 'Oct', 'November' => 'Nov', 'December' => 'Dec'];
    $d = $i === 0 ? ['Mon' => 'دوشنبه', 'Tue' => 'سه‌شنبه', 'Wed' => 'چارشنبه', 'Thu' => 'پنجشنبه', 'Fri' => 'جمعه', 'Sat' => 'شنبه', 'Sun' => 'یکشنبه', 'Monday' => 'دوشنبه', 'Tuesday' => 'سه‌شنبه', 'Wednesday' => 'چارشنبه', 'Thursday' => 'پنجشنبه', 'Friday' => 'جمعه', 'Saturday' => 'شنبه', 'Sunday' => 'یکشنبه']
                   : ['Mon' => 'دوشنبه', 'Tue' => 'سه‌شنبه', 'Wed' => 'چهارشنبه', 'Thu' => 'پنج‌شنبه', 'Fri' => 'جمعه', 'Sat' => 'شنبه', 'Sun' => 'یکشنبه', 'Monday' => 'دوشنبه', 'Tuesday' => 'سه‌شنبه', 'Wednesday' => 'چهارشنبه', 'Thursday' => 'پنج‌شنبه', 'Friday' => 'جمعه', 'Saturday' => 'شنبه', 'Sunday' => 'یکشنبه'];
    $ampm = $i === 0 ? ['AM' => 'غ.م', 'PM' => 'غ.و'] : ['AM' => 'ق.ظ', 'PM' => 'ب.ظ'];
    $o = preg_replace_callback('/\b(' . implode('|', array_merge(array_keys($full), array_keys($m), array_keys($d), ['AM', 'PM'])) . ')\b/', function ($x) use ($full, $m, $d, $ampm) {
        $w = $x[1]; if (isset($full[$w])) $w = $full[$w];
        return $m[$w] ?? $d[$w] ?? $ampm[$w] ?? $x[1];
    }, $s);
    return $o === $s ? null : $o;
}

/** One plain-text string → its translation, or null when nothing in it is known. */
/** Sentence patterns from lang/rules.php: the first one that matches gives the translation. */
function i18nPattern($s, $l, $depth) {
    static $rules = null;
    if ($rules === null) {
        $rules = require __DIR__ . '/lang/rules.php';
        foreach ($rules as $k => $r) if (isset(i18nOverrides()['rules'][$r[0]])) { $rules[$k][1] = i18nOverrides()['rules'][$r[0]][0]; $rules[$k][2] = i18nOverrides()['rules'][$r[0]][1]; }
    }
    $i = $l === 'ps' ? 1 : 2;
    foreach ($rules as $rule) {
        if (!preg_match($rule[0], $s, $m)) continue;
        return preg_replace_callback('/\{(~?)(\d+)\}/', function ($x) use ($m, $l, $depth) {
            $v = $m[(int) $x[2]] ?? '';
            if ($x[1] === '~' && $v !== '' && $depth < 3 && ($t = i18nTranslateString(trim($v), $l, $depth + 1)) !== null) return $t;
            return $v;
        }, $rule[$i]);
    }
    return null;
}

function i18nTranslateString($s, $l, $depth = 0) {
    $strings = i18nStrings(); $i = $l === 'ps' ? 0 : 1;
    if (isset($strings[$s]) && ($strings[$s][$i] ?? '') !== '') return $strings[$s][$i];
    if (($r = i18nRule($s, $l)) !== null) return $r;
    if (($r = i18nPattern($s, $l, $depth)) !== null) return $r;
    if (preg_match('/^\+(\d+) (.+)$/', $s, $m) && ($r = i18nTranslateString($m[2], $l, $depth + 1)) !== null) return '+' . $m[1] . ' ' . $r;
    if (preg_match('/^(\d+) (.+)$/', $s, $m) && ($r = i18nTranslateString($m[2], $l, $depth + 1)) !== null) return $m[1] . ' ' . $r;
    if (preg_match('/^(.+?) (\d+\+?)$/', $s, $m) && strlen($m[1]) > 3 && ($r = i18nTranslateString($m[1], $l, $depth + 1)) !== null) return $r . ' ' . $m[2];
    if (preg_match('/^Classes where your attendance is below (\d+)%$/', $s, $m)) return $i === 0 ? 'ټولګي چې ستاسو حاضري یې تر ' . $m[1] . '٪ ښکته ده' : 'صنف‌هایی که حاضری شما کمتر از ' . $m[1] . '٪ است';
    if (preg_match('/^(\d+) assignments? \((\d+) due in 48h\)$/', $s, $m)) return $i === 0 ? $m[1] . ' دندې (' . $m[2] . ' په ۴۸ ساعتونو کې)' : $m[1] . ' وظیفه (' . $m[2] . ' در ۴۸ ساعت)';
    if (preg_match('/^(\d+(?:\.\d+)?)% of (\d+) items?$/', $s, $m)) return $i === 0 ? $m[1] . '٪ له ' . $m[2] . ' توکي' : $m[1] . '٪ از ' . $m[2] . ' مورد';
    if (preg_match('/^(\d+)\/(\d+) (present|submitted|completed)$/', $s, $m)) { $w = ['present' => ['حاضر', 'حاضر'], 'submitted' => ['تسلیم شوي', 'تسلیم‌شده'], 'completed' => ['بشپړ شوي', 'تکمیل‌شده']][$m[3]][$i]; return $m[1] . '/' . $m[2] . ' ' . $w; }
    if (preg_match('/^(\d+) of (\d+) (done|lectures done|students)(.*)$/', $s, $m)) { $w = ['done' => ['بشپړ', 'انجام‌شده'], 'lectures done' => ['لکچرونه بشپړ شوي', 'لکچر انجام‌شده'], 'students' => ['زده‌کوونکي', 'شاگرد']][$m[3]][$i]; return $m[1] . ($i === 0 ? ' له ' : ' از ') . $m[2] . ' ' . $w . $m[4]; }
    if (preg_match('/^Download (.+)$/', $s, $m)) return ($i === 0 ? 'ښکته کړئ: ' : 'دانلود: ') . $m[1];
    if (preg_match('/^No class: (.+)$/', $s, $m)) return ($i === 0 ? 'ټولګی نشته: ' : 'صنف نیست: ') . $m[1];
    if (preg_match('/^Graded: (.+)$/', $s, $m)) return ($i === 0 ? 'کره شو: ' : 'نمره شد: ') . $m[1];
    if (preg_match('/^Open in the class \((\d+) comments?\)$/', $s, $m)) return $i === 0 ? 'په ټولګي کې پرانیزئ (' . $m[1] . ' نظرونه)' : 'باز کردن در صنف (' . $m[1] . ' نظر)';
    if (preg_match('/^You · (.+)$/', $s, $m)) return ($i === 0 ? 'تاسو · ' : 'شما · ') . (i18nDateWords($m[1], $l) ?? $m[1]);
    if (preg_match('/^(.+) joined (.+)$/', $s, $m)) return $i === 0 ? $m[1] . ' له ' . $m[2] . ' سره یوځای شو' : $m[1] . ' به ' . $m[2] . ' پیوست';
    if (preg_match('/^(.+) accepted your invite$/', $s, $m)) return $i === 0 ? $m[1] . ' ستاسو بلنه ومنله' : $m[1] . ' دعوت شما را پذیرفت';
    if (preg_match('/^(.+) submitted: (.+)$/', $s, $m)) return $i === 0 ? $m[1] . ' تسلیم کړ: ' . $m[2] : $m[1] . ' تسلیم کرد: ' . $m[2];
    if (preg_match('/^(.+) completed quiz (.+) — (.+)$/', $s, $m)) return $i === 0 ? $m[1] . ' ازموینه بشپړه کړه ' . $m[2] . ' — ' . $m[3] : $m[1] . ' کویز را تکمیل کرد ' . $m[2] . ' — ' . $m[3];
    if (preg_match('/^New lecture in (.+?): (.+)$/', $s, $m)) return $i === 0 ? 'په ' . $m[1] . ' کې نوی لکچر: ' . $m[2] : 'لکچر جدید در ' . $m[1] . ': ' . $m[2];
    if (preg_match('/^Good (morning|afternoon|evening), (.+)$/', $s, $m)) { $g = i18nTranslateString('Good ' . $m[1], $l, $depth + 1); if ($g !== null) return $g . '، ' . $m[2]; }
    foreach (['due ' => ['وروستۍ نېټه: ', 'موعد: '], 'Due ' => ['وروستۍ نېټه: ', 'موعد: '], 'Posted ' => ['خپور شو ', 'منتشر شد '], 'Graded ' => ['کره شو ', 'نمره شد '], 'Score ' => ['نمره ', 'نمره ']] as $pre => $tr) {
        if (strpos($s, $pre) === 0 && strlen($s) > strlen($pre)) { $rest = substr($s, strlen($pre)); return $tr[$i] . (i18nDateWords($rest, $l) ?? $rest); }
    }
    if ($depth < 2) {
        foreach ([' · ', ' – ', ' - '] as $sep) {
            if (strpos($s, $sep) === false) continue;
            $parts = explode($sep, $s); $changed = false;
            foreach ($parts as $k => $p) { $t = i18nTranslateString(trim($p), $l, $depth + 1); if ($t !== null) { $parts[$k] = $t; $changed = true; } }
            if ($changed) return implode($sep, $parts);
        }
        if (substr($s, -1) === ':' && ($x = i18nTranslateString(rtrim(substr($s, 0, -1)), $l, $depth + 1)) !== null) return $x . ':';
    }
    if (($r = i18nFragments($s, $l)) !== null) return $r;
    return i18nDateWords($s, $l);
}

/** A text made of system wording + names ("Hira Nazari was sent to its teacher."): the known pieces are translated, the rest stays. */
function i18nFragments($s, $l) {
    static $frags = null;
    if ($frags === null) {
        $frags = array_merge(require __DIR__ . '/lang/fragments.php', i18nOverrides()['fragments']);
        uksort($frags, fn($a, $b) => strlen($b) <=> strlen($a));   // longest piece first
    }
    $i = $l === 'ps' ? 0 : 1; $out = $s;
    foreach ($frags as $en => $tr) if (strpos($out, $en) !== false && ($tr[$i] ?? '') !== '') $out = str_replace($en, $tr[$i], $out);
    return $out === $s ? null : $out;
}

function i18nTranslateText($text, $l) {
    $dec = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $trim = preg_replace('/\s+/u', ' ', trim($dec));   // a sentence broken over several lines in the page still matches its phrase
    if ($trim === '' || !preg_match('/[A-Za-z]{2}/', $trim)) return null;
    $tr = i18nTranslateString($trim, $l);
    if ($tr === null) return null;
    $lead = substr($dec, 0, strlen($dec) - strlen(ltrim($dec))); $trail = substr($dec, strlen(rtrim($dec)));
    return $lead . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8') . $trail;
}

/** The output callback. */
function i18nTranslateHtml($html) {
    $l = i18nLang();
    if ($l === 'en' || $html === '' || $html[0] === '{' || $html[0] === '[' || stripos($html, '<html') === false) return $html;   // never touch JSON / fragments / downloads
    $parts = preg_split('#(<script\b.*?</script>|<style\b.*?</style>|<textarea\b[^>]*>.*?</textarea>|<pre\b.*?</pre>|<code\b.*?</code>|<!--no-i18n-->.*?<!--/no-i18n-->)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $k => &$p) {
        if ($k % 2 === 1) {   // a protected block — only a <textarea>'s own attributes (placeholder …) are translated, never what is typed in it
            if (preg_match('/^<textarea\b([^>]*)>/i', $p, $tm)) {
                $attrs = preg_replace_callback('/\b(title|placeholder|aria-label|alt|data-tooltip|data-confirm|data-confirm-text|data-confirm-button|data-copy-label|data-expand-label|data-collapse-label)="([^"]*)"/', function ($m) use ($l) {
                    $t = i18nTranslateText(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $l);
                    return $t === null ? $m[0] : $m[1] . '="' . htmlspecialchars(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '"';
                }, $tm[1]);
                $p = '<textarea' . $attrs . '>' . substr($p, strlen($tm[0]));
            }
            continue;
        }
        $p = preg_replace_callback('/(^|>)([^<>]+)(?=<|$)/', function ($m) use ($l) { $t = i18nTranslateText($m[2], $l); return $t === null ? $m[0] : $m[1] . $t; }, $p);   // also the text right before / after a protected <code> block
        $p = preg_replace_callback('/\b(title|placeholder|aria-label|alt|data-tooltip|data-confirm|data-confirm-text|data-confirm-button|data-copy-label|data-expand-label|data-collapse-label)="([^"]*)"/', function ($m) use ($l) {
            $dec = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'); $t = i18nTranslateText($dec, $l);
            return $t === null ? $m[0] : $m[1] . '="' . htmlspecialchars(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '"';
        }, $p);
    }
    unset($p);
    $out = implode('', $parts);
    // Bootstrap's mirrored build for the sign-in pages
    $out = str_replace('assets/vendor/bootstrap/bootstrap.min.css', 'assets/vendor/bootstrap/bootstrap.rtl.min.css', $out);
    return $out;
}

function i18nStart() {
    if (PHP_SAPI === 'cli' || i18nLang() === 'en') return;
    ob_start('i18nTranslateHtml');
}
