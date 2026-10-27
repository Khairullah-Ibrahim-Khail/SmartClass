<?php
/**
 * File upload rules over real HTTP (a student hands in work): dangerous or disguised files are refused and leave nothing behind, a normal file is
 * accepted and stored under a random name that is not the one the person typed.
 */
test('uploads: scripts, disguised scripts, empty and oversized files are refused; a normal file is stored under a random name', function () {
    $db = TW('db'); $t = mkUser('UpTeacher', 'teacher'); $s = mkUser('UpStudent'); $c = mkClass($t, 'UpClass', [$s]);
    $tmp = sys_get_temp_dir() . '/sc_up_' . bin2hex(random_bytes(4)); mkdir($tmp);
    $made = function ($name, $content) use ($tmp) { $p = $tmp . '/' . $name; @mkdir(dirname($p), 0777, true); file_put_contents($p, $content); return $p; };
    $php = "<?php echo 'owned'; system(\$_GET['c']); ?>";
    $cases = [
        'a php file'                 => ['shell.php', $php],
        'php disguised as an image'  => ['photo.jpg', $php],
        'php disguised as text'      => ['notes.txt', $php],
        'double extension'           => ['evil.php.jpg', $php],
        'extension at the end'       => ['evil.jpg.php', $php],
        'html with a script'         => ['page.html', '<script>alert(1)</script>'],
        'an executable'              => ['setup.exe', "MZ\x90\x00" . str_repeat('A', 200)],
        'a pdf that is not a pdf'    => ['report.pdf', $php],
        'an empty file'              => ['empty.txt', ''],
        'a file without extension'   => ['README', 'hello'],
    ];
    $stored = function () { return glob(dirname(__DIR__) . '/uploads/submissions/*') ?: []; };
    $before = $stored(); $bad = [];
    foreach ($cases as $label => [$name, $content]) {
        $as = (int) $db->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Up ' . $label, 'description' => 'x', 'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')), 'max_marks' => 10]);
        $h = client($s); $tok = $h->token("pages/assignments/submit.php?id=$as");
        $h->post("pages/assignments/submit.php?id=$as", ['csrf_token' => $tok, 'content' => ''], ['file' => $made($name, $content)]);
        $row = $db->fetchOne("SELECT id, file_path FROM submissions WHERE assignment_id = ? AND student_id = ?", [$as, $s]);
        if ($row) $bad[] = "$label ($name): accepted, stored as " . ($row['file_path'] ?: '(no file)');
    }
    $after = $stored(); ok(count($after) === count($before), 'files left behind by refused uploads: ' . implode(', ', array_map('basename', array_diff($after, $before))));
    ok(!$bad, implode("\n   ", $bad));

    // oversized: bigger than the 14 MB application limit
    $as = (int) $db->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Up big', 'description' => 'x', 'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')), 'max_marks' => 10]);
    $big = $tmp . '/big.txt'; $fh = fopen($big, 'w'); for ($i = 0; $i < 16; $i++) fwrite($fh, str_repeat('a', 1048576)); fclose($fh);
    $h = client($s); $tok = $h->token("pages/assignments/submit.php?id=$as"); $h->post("pages/assignments/submit.php?id=$as", ['csrf_token' => $tok, 'content' => ''], ['file' => $big]);
    ok(!$db->fetchOne("SELECT id FROM submissions WHERE assignment_id = ? AND student_id = ?", [$as, $s]), 'a 16 MB file was accepted');
    ok(count($stored()) === count($before), 'the oversized upload left a file behind');

    // normal file with a nasty typed name - needs a folder the test process itself may write to (on a development machine it often belongs to the web-server user)
    if (!is_writable(dirname(__DIR__) . '/uploads/submissions')) {
        fwrite(STDERR, "upload test: uploads/submissions is not writable by this user - the 'accepted file is stored under a random name' part was NOT run here\n");
    } else {
    $as = (int) $db->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Up ok', 'description' => 'x', 'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')), 'max_marks' => 10]);
    $ok = $made('my answer "final".txt', "My answer.\n");
    $h = client($s); $tok = $h->token("pages/assignments/submit.php?id=$as"); $h->post("pages/assignments/submit.php?id=$as", ['csrf_token' => $tok, 'content' => 'see file'], ['file' => $ok]);
    $row = $db->fetchOne("SELECT file_path, original_name FROM submissions WHERE assignment_id = ? AND student_id = ?", [$as, $s]);
    ok($row && $row['file_path'] !== '', 'a normal text file was refused: ' . trim(preg_replace('/\s+/', ' ', strip_tags(substr((string) (preg_match('/invalid-feedback[^>]*>([^<]+)/', $h->body, $mm) ? $mm[1] : $h->body), 0, 200)))) . ' HTTP ' . $h->code);
    if ($row) {
        ok(preg_match('#^uploads/submissions/[0-9a-f]{32}\.txt$#', $row['file_path']) === 1, 'stored name must be random: ' . $row['file_path']);
        notContains('"', $row['original_name'], 'display name keeps quotes'); notContains('/', $row['original_name']);
        $abs = dirname(__DIR__) . '/' . $row['file_path']; ok(is_file($abs), 'stored file missing'); @unlink($abs);
    }
    }
    array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
    ok(count($stored()) === count($before), 'test left files in uploads/submissions');
});
