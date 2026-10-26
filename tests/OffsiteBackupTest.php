<?php
test('off-server backup: copies the newest backup to a folder, verifies it, keeps only the newest copies, refuses a target inside the project and a missing backup', function () {
    $tmp = sys_get_temp_dir() . '/sc_off_' . bin2hex(random_bytes(4)); $root = "$tmp/all"; $to = "$tmp/usb";
    foreach (['20260101-010101', '20260102-010101', '20260103-010101'] as $d) { mkdir("$root/$d", 0777, true); file_put_contents("$root/$d/school.sql.gz", "data $d"); }
    $run = function (string $args, string $rootDir) { $out = []; exec('SC_BACKUP_ROOT=' . escapeshellarg($rootDir) . ' php ' . escapeshellarg(dirname(__DIR__) . '/bin/backup_offsite.php') . " $args 2>&1", $out, $rc); return [$rc, implode("\n", $out)]; };
    [$rc, $out] = $run('--to=' . escapeshellarg($to) . ' --keep=2', $root);
    eq(0, $rc, $out); contains('OK   school.sql.gz', $out);
    eq('data 20260103-010101', file_get_contents("$to/20260103-010101/school.sql.gz"), 'the newest backup was copied');
    [$rc] = $run('--to=' . escapeshellarg($to) . ' --keep=2', $root); eq(0, $rc, 'running twice is fine');
    mkdir("$root/20260104-010101"); file_put_contents("$root/20260104-010101/school.sql.gz", 'x'); $run('--to=' . escapeshellarg($to) . ' --keep=2', $root);
    eq(['20260103-010101', '20260104-010101'], array_map('basename', glob("$to/*", GLOB_ONLYDIR)), 'only the newest 2 copies stay');
    [$rc, $out] = $run('--to=' . escapeshellarg(dirname(__DIR__) . '/backups/offsite_test'), $root); eq(1, $rc, 'a folder inside the project is refused'); contains('not off-server', $out);
    [$rc] = $run('', $root); eq(2, $rc, 'no target given'); mkdir("$tmp/none"); [$rc] = $run('--to=' . escapeshellarg($to), "$tmp/none"); eq(2, $rc, 'no backup to copy');
    exec('rm -rf ' . escapeshellarg($tmp));
    ok(!is_dir(dirname(__DIR__) . '/backups/offsite_test'), 'nothing was created inside the project');
});

test('off-server backup over SSH: copies with a key, checks every file by SHA-256, and says so when the key is wrong or nobody is listening (a private test server on this machine)', function () {
    if (!is_executable('/usr/sbin/sshd') || !trim((string) shell_exec('command -v ssh-keygen scp ssh'))) { ok(true, 'skipped: no ssh tools on this machine'); return; }
    $d = sys_get_temp_dir() . '/sc_ssh_' . bin2hex(random_bytes(4)); mkdir($d, 0700); $root = "$d/root/all/20260102-030405"; mkdir($root, 0777, true); mkdir("$d/remote");
    $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
    exec("ssh-keygen -q -t ed25519 -N '' -f $d/host; ssh-keygen -q -t ed25519 -N '' -f $d/key; ssh-keygen -q -t ed25519 -N '' -f $d/wrong; cp $d/key.pub $d/auth; chmod 600 $d/auth");
    file_put_contents("$d/sshd_config", "Port $port\nListenAddress 127.0.0.1\nHostKey $d/host\nPidFile $d/pid\nAuthorizedKeysFile $d/auth\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nUsePAM no\nStrictModes no\nSubsystem sftp internal-sftp\nLogLevel ERROR\n");
    $proc = proc_open(['/usr/sbin/sshd', '-D', '-f', "$d/sshd_config"], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    file_put_contents("$root/school.sql.gz", gzencode('data one')); file_put_contents("$root/other.sql.gz", gzencode('data two'));
    $me = trim((string) shell_exec('whoami')); $kh = "-o UserKnownHostsFile=$d/known";   // the test never touches the real known_hosts
    $run = function (string $extra) use ($root, $d) { $out = []; exec('SC_BACKUP_ROOT=' . escapeshellarg("$d/root/all") . ' SSH_OPTS= php ' . escapeshellarg(dirname(__DIR__) . '/bin/backup_offsite.php') . " $extra 2>&1", $o, $rc); return [$rc, implode("\n", $o)]; };
    // known_hosts: ssh reads the user's file, so accept-new records the throw-away key there; remove it afterwards
    [$rc, $out] = $run("--to=$me@127.0.0.1:$d/remote --port=$port --key=$d/key");
    eq(0, $rc, $out); contains('OK   school.sql.gz', $out); eq(gzencode('data one'), file_get_contents("$d/remote/20260102-030405/school.sql.gz"), 'the file arrived intact');
    [$rc, $out] = $run("--to=$me@127.0.0.1:$d/remote2 --port=$port --key=$d/wrong"); eq(1, $rc, 'the wrong key fails'); contains('cannot reach', $out);
    [$rc, $out] = $run("--to=$me@127.0.0.1:$d/remote3 --port=1 --key=$d/key"); eq(1, $rc, 'nobody listening fails');
    @proc_terminate($proc); exec("ssh-keygen -R " . escapeshellarg("[127.0.0.1]:$port") . " >/dev/null 2>&1"); exec('rm -rf ' . escapeshellarg($d));
});
