<?php
// Copy the newest backup (backups/all/<date>/, made by bin/backup_all.php) to a place that is NOT on this server.
//   php bin/backup_offsite.php --to=/mnt/usb/smartclass            (a folder: external disk, mounted share)
//   php bin/backup_offsite.php --to=user@host:/backups/smartclass   (another server, over SSH; needs a key login)
//   Target can also come from the SC_OFFSITE_TARGET environment variable.
//   Options: --keep=14 (copies kept in a folder target; default 14)
//            --port=2222 --key=/path/to/private_key   (SSH target: another port / a specific key; the key must log in without a password)
// Cron, after the nightly backup:  30 3 * * *  php /path/bin/backup_offsite.php --to=...
// Every copied file is compared with the original (SHA-256) before the run counts as OK; exit code 1 otherwise.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opt = getopt('', ['to:', 'keep:', 'port:', 'key:']);
$to = rtrim((string) ($opt['to'] ?? getenv('SC_OFFSITE_TARGET') ?: ''), '/');
$keep = max(1, (int) ($opt['keep'] ?? 14));
if ($to === '') { fwrite(STDERR, "Give a target: --to=/folder or --to=user@host:/folder (or set SC_OFFSITE_TARGET).\n"); exit(2); }

$root = getenv('SC_BACKUP_ROOT') ?: dirname(__DIR__) . '/backups/all';   // SC_BACKUP_ROOT: for tests
$dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
sort($dirs);
$src = end($dirs);
if (!$src) { fwrite(STDERR, "No backup found in {$root} — run php bin/backup_all.php first.\n"); exit(2); }
$files = glob($src . '/*.sql.gz') ?: [];
if (!$files) { fwrite(STDERR, "The newest backup folder is empty: {$src}\n"); exit(2); }
$name = basename($src);

$remote = (bool) preg_match('/^[^\/]+@[^\/:]+:/', $to);
if ($remote) {
    $ssh = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'StrictHostKeyChecking=accept-new'];   // the first connection records the server's key; a CHANGED key is refused
    $sshOnly = isset($opt['port']) ? ['-p', (string) (int) $opt['port']] : [];   // ssh wants -p, scp wants -P
    $scpOnly = isset($opt['port']) ? ['-P', (string) (int) $opt['port']] : [];
    if (isset($opt['key'])) { $ssh = array_merge($ssh, ['-i', (string) $opt['key'], '-o', 'IdentitiesOnly=yes']); }
    [$host, $path] = explode(':', $to, 2);
    $run = function (array $cmd): array { $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); return [proc_close($p), trim($out)]; };
    [$rc, $msg] = $run(array_merge(['ssh'], $ssh, $sshOnly, [$host, 'mkdir -p ' . escapeshellarg("{$path}/{$name}")]));
    if ($rc) { fwrite(STDERR, "FAIL cannot reach {$host}: {$msg}\n"); exit(1); }
    $bad = 0;
    foreach ($files as $f) {
        [$rc, $msg] = $run(array_merge(['scp', '-q'], $ssh, $scpOnly, [$f, "{$host}:{$path}/{$name}/"]));
        if ($rc) { $bad++; echo "FAIL " . basename($f) . ": {$msg}\n"; continue; }
        [$rc, $out] = $run(array_merge(['ssh'], $ssh, $sshOnly, [$host, 'sha256sum ' . escapeshellarg("{$path}/{$name}/" . basename($f))]));
        $ok = $rc === 0 && strncmp($out, hash_file('sha256', $f), 64) === 0;
        echo ($ok ? 'OK   ' : 'FAIL ') . basename($f) . ($ok ? '' : ' (copy differs from the original)') . "\n"; $bad += $ok ? 0 : 1;
    }
    exit($bad ? 1 : 0);
}

$dest = "{$to}/{$name}";
$project = realpath(dirname(__DIR__));
$probe = $to; while (!file_exists($probe) && dirname($probe) !== $probe) $probe = dirname($probe);
if (strpos((realpath($probe) ?: $probe) . '/', $project . '/') === 0) { fwrite(STDERR, "FAIL the target is inside the SmartClass folder — that is not off-server.\n"); exit(1); }
if (!is_dir($to) && !@mkdir($to, 0770, true)) { fwrite(STDERR, "FAIL cannot create {$to}\n"); exit(1); }
if (!is_dir($dest) && !@mkdir($dest, 0770, true)) { fwrite(STDERR, "FAIL cannot create {$dest}\n"); exit(1); }
$bad = 0;
foreach ($files as $f) {
    $t = $dest . '/' . basename($f);
    $ok = @copy($f, $t) && hash_file('sha256', $f) === hash_file('sha256', $t);
    echo ($ok ? 'OK   ' : 'FAIL ') . basename($f) . "\n"; $bad += $ok ? 0 : 1;
}
$have = glob($to . '/*', GLOB_ONLYDIR) ?: []; sort($have);
foreach (array_slice($have, 0, max(0, count($have) - $keep)) as $old) { array_map('unlink', glob("{$old}/*.sql.gz") ?: []); @rmdir($old); }
exit($bad ? 1 : 0);
