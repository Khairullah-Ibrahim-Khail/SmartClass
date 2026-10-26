<?php
function permWorld() {
    static $w = null;
    if ($w) return $w;
    $db = TW('db');
    $t = mkUser('PT', 'teacher'); $t2 = mkUser('PT2', 'teacher'); $adm = mkUser('PA', 'teacher', 1);
    $s = mkUser('PS'); $s2 = mkUser('PS2');
    $c = mkClass($t, 'PermClass', [$s]);
    $pub = (int) $db->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'PubLecture', 'content' => "# A\n\ntext", 'status' => 'published']);
    $dr = (int) $db->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'DraftLecture', 'content' => "# D\n\nsecret", 'status' => 'draft']);
    $ex = (int) $db->insert('exams', ['class_id' => $c, 'created_by' => $t, 'title' => 'PermExam', 'kind' => 'mid', 'exam_date' => date('Y-m-d H:i:s', strtotime('+3 days')), 'max_marks' => 50]);
    return $w = compact('t', 't2', 'adm', 's', 's2', 'c', 'pub', 'dr', 'ex') + ['H' => []];
}
function who($key) { $w = permWorld(); static $cl = []; return $cl[$key] ??= client($w[$key]); }

test('admin-only pages: admin sees them, teachers and students are turned away', function () {
    foreach (['settings/school', 'settings/email', 'settings/ai', 'settings/limits', 'settings/attendance', 'settings/years', 'settings/system', 'settings/backup', 'settings/grading_default', 'teachers/index', 'customers/index', 'reports/school', 'reports/school_print'] as $p) {
        $page = "pages/$p.php";
        who('adm')->get($page); ok(who('adm')->shown(), "admin must see $p (code " . who('adm')->code . ')');
        who('t')->get($page); ok(!who('t')->shown(), "teacher must NOT see $p");
        who('s')->get($page); ok(!who('s')->shown(), "student must NOT see $p");
    }
});
test('teacher-only pages are closed to students', function () {
    foreach (['settings/marking', 'settings/grading', 'classes/create', 'lectures/add', 'assignments/create', 'quizzes/create', 'exams/create', 'attendance/markAttendance', 'students/viewStudents', 'students/approve'] as $p) {
        who('s')->get("pages/$p.php"); ok(!who('s')->shown(), "student must NOT see $p");
    }
});
test('another teacher and an outsider student cannot open a class or its data', function () {
    $w = permWorld(); $c = $w['c'];
    $pages = ["classes/people.php?id=$c", "classes/grades.php?id=$c", "classes/insights.php?id=$c", "classes/messages.php?id=$c", "classes/attendance.php?id=$c", "attendance/class.php?id=$c",
        "classes/attendance_print.php?id=$c&from=2000-01-01&to=2099-01-01", "classes/grades_print.php?id=$c", "classes/roster_print.php?id=$c", "classes/attendance_export.php?id=$c",
        "classes/attendance_xlsx.php?id=$c", "classes/grades_xlsx.php?id=$c", "classes/roster_export.php?id=$c", "marks/print.php?class_id=$c", "marks/view.php?class_id=$c",
        "lectures/view.php?id={$w['pub']}", "lectures/view.php?id={$w['dr']}", "exams/view.php?id={$w['ex']}"];
    foreach (['t2', 's2'] as $who) foreach ($pages as $p) { who($who)->get("pages/$p"); notContains('PermClass', who($who)->body, "$who opened $p"); notContains('DraftLecture', who($who)->body, "$who opened $p"); ok(who($who)->code !== 200 || strlen(who($who)->body) < 400 || strpos(who($who)->body, 'PubLecture') === false, "$who must not see lecture on $p"); }
});
test('a member student sees the class but not teacher-only data or drafts', function () {
    $w = permWorld(); $c = $w['c'];
    who('s')->get("pages/lectures/view.php?id={$w['pub']}"); ok(who('s')->shown(), 'published lecture visible'); contains('PubLecture', who('s')->body);
    foreach (["lectures/view.php?id={$w['dr']}", "classes/roster_print.php?id=$c", "classes/attendance_export.php?id=$c", "classes/attendance_xlsx.php?id=$c", "classes/grades_xlsx.php?id=$c", "classes/roster_export.php?id=$c", "classes/insights.php?id=$c"] as $p) {
        who('s')->get("pages/$p"); notContains('DraftLecture', who('s')->body, $p); ok(!who('s')->shown() || strpos(who('s')->headers, 'attachment') === false, "student must not download $p");
    }
});
test('the owner teacher can use their own class', function () {
    $w = permWorld(); who('t')->get("pages/classes/roster_print.php?id={$w['c']}"); ok(who('t')->shown(), 'roster print'); who('t')->get("pages/lectures/view.php?id={$w['dr']}"); ok(who('t')->shown(), 'own draft visible'); contains('DraftLecture', who('t')->body);
});
test('every POST needs the CSRF token', function () {
    $w = permWorld(); $h = client($w['t']);
    $h->post("pages/classes/attendance.php?id={$w['c']}", ['action' => 'save_attendance', 'date' => date('Y-m-d'), 'session' => 1, 'status' => [$w['s'] => 'present']]);
    eq(0, (int) TW('db')->fetchOne('SELECT COUNT(*) c FROM attendance WHERE class_id = ?', [$w['c']])['c'], 'no token → nothing saved');
    $h->post('pages/lectures/images.php', ['action' => 'list', 'class_id' => $w['c']]); eq(403, $h->code);
});
test('lecture picture library: only the class teacher; pictures only for members', function () {
    $w = permWorld(); $png = sys_get_temp_dir() . '/t_' . bin2hex(random_bytes(3)) . '.png'; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    if (!function_exists('imagecreatefromstring')) { @unlink($png); return; }
    $th = client($w['t']); $tok = $th->token('pages/lectures/add.php');
    $th->post('pages/lectures/images.php', ['action' => 'upload', 'class_id' => $w['c']], ['image' => $png], ['X-CSRF-Token' => $tok]);
    $up = json_decode($th->body, true);
    if (empty($up['ok']) && strpos((string) ($up['error'] ?? ''), 'writable') !== false) { @unlink($png); return; }   // the test machine cannot write uploads/ — skip the rest
    ok(!empty($up['ok']), 'owner upload: ' . $th->body);
    $id = (int) $up['id'];
    $st = client($w['s']); $stok = $st->token('pages/classes/my-classes.php');
    $st->post('pages/lectures/images.php', ['action' => 'upload', 'class_id' => $w['c']], ['image' => $png], ['X-CSRF-Token' => $stok]); eq(403, $st->code, 'student upload refused');
    $t2 = client($w['t2']); $t2tok = $t2->token('pages/lectures/add.php');
    $t2->post('pages/lectures/images.php', ['action' => 'list', 'class_id' => $w['c']], [], ['X-CSRF-Token' => $t2tok]); eq(403, $t2->code, 'other teacher refused');
    who('s')->get("pages/lectures/image.php?id=$id"); eq(200, who('s')->code, 'member sees the picture');
    who('s2')->get("pages/lectures/image.php?id=$id"); eq(404, who('s2')->code, 'outsider does not');
    anon()->get("pages/lectures/image.php?id=$id"); eq(302, anon()->code, 'anonymous goes to the login');
    $row = TW('db')->fetchOne('SELECT file_path FROM lecture_images WHERE id = ?', [$id]); if ($row && ($abs = uploadAbsolutePath($row['file_path']))) @unlink($abs);
    @unlink($png);
});
test('a student\'s progress page and the parent / guardian contact are for that class\'s teachers only', function () {
    $w = permWorld(); TW('db')->update('users', ['guardian_name' => 'SecretParentName', 'guardian_email' => 'secretparent@example.invalid'], 'id = ?', [$w['s']]);
    foreach (['t2', 's2', 's'] as $who) { who($who)->get("pages/classes/student.php?class_id={$w["c"]}&student_id={$w["s"]}"); notContains('SecretParentName', who($who)->body, "$who"); notContains('secretparent@example.invalid', who($who)->body, "$who"); }
    who('t')->get("pages/classes/student.php?class_id={$w["c"]}&student_id={$w["s"]}"); ok(who('t')->shown(), 'the class teacher opens it'); contains('SecretParentName', who('t')->body, 'and sees the guardian contact');
});
