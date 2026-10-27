<?php
// Automated tests:   php tests/run.php [filter]      e.g.  php tests/run.php attendance
// Builds a throw-away database (sc_test_<random>) from config/install_schema.sql (so a fresh install is tested too), starts PHP's
// built-in web server on a free port pointed at it, runs every tests/*Test.php, then removes the database. The live database is never touched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/includes/instances.php';
require_once dirname(__DIR__) . '/includes/migrator.php';
$filter = $argv[1] ?? '';
$cfg = instanceMasterConfig();
$root = instancePdo($cfg);
$dbName = 'sc_test_' . bin2hex(random_bytes(4));
$server = null; $pipes = [];
$cleanup = function () use (&$server, $root, $dbName) {
    if ($server) { @proc_terminate($server); @proc_close($server); $server = null; }
    try { $root->exec("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (Throwable $e) {}
};
register_shutdown_function($cleanup);
$t0 = microtime(true);
try {
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = instancePdo(['db_host' => $cfg['db_host'], 'db_name' => $dbName, 'db_user' => $cfg['db_user'], 'db_pass' => $cfg['db_pass']]);
    foreach (migrationStatements(file_get_contents(dirname(__DIR__) . '/config/install_schema.sql')) as $s) $pdo->exec($s);
    $m = migrateDatabase($pdo, ['baseline' => '999']);
    if ($m['error']) throw new RuntimeException('migrations: ' . $m['error']);
} catch (Throwable $e) { fwrite(STDERR, "Could not build the test database: " . $e->getMessage() . "\n"); exit(2); }

// web server
$port = 0;
for ($p = 18400; $p < 18500; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); $port = $p; break; } }
$docroot = dirname(dirname(__DIR__));
$base_path = '/' . ltrim(substr(dirname(__DIR__), strlen($docroot)), '/');
putenv('SC_TEST_DB=' . $dbName);
$server = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $docroot], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, array_merge(getenv(), ['SC_TEST_DB' => $dbName]));
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

// In-process side: the same code, the same database
$_SERVER += ['HTTP_HOST' => "127.0.0.1:$port", 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'SCRIPT_NAME' => '/x.php'];
require dirname(__DIR__) . '/config/db.php';
if (DB_NAME !== $dbName) { fwrite(STDERR, "SAFETY STOP: the app is not using the test database (" . DB_NAME . ").\n"); exit(2); }
$GLOBALS['T'] = ['db' => $db, 'base' => "http://127.0.0.1:$port$base_path", 'dbname' => $dbName, 'pdo' => $pdo];
require __DIR__ . '/factory.php';
foreach (glob(__DIR__ . '/*Test.php') as $f) { if ($filter === '' || stripos(basename($f), $filter) !== false) require_once $f; }

foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try { $fn(); $GLOBALS['__pass']++; echo "  ok    $name\n"; }
    catch (AssertFail $e) { $GLOBALS['__fail'][] = [$name, $e->getMessage()]; echo "  FAIL  $name\n        " . $e->getMessage() . "\n"; }
    catch (Throwable $e) { $GLOBALS['__fail'][] = [$name, get_class($e) . ': ' . $e->getMessage()]; echo "  ERROR $name\n        " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
}
$n = count($GLOBALS['__tests']);
printf("\n%d tests, %d passed, %d failed (%.1f s)\n", $n, $GLOBALS['__pass'], count($GLOBALS['__fail']), microtime(true) - $t0);
$cleanup();
exit($GLOBALS['__fail'] ? 1 : 0);
