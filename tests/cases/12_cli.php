<?php namespace ProcessWire;

/**
 * migrate.php: exit codes, --list, --run, restart after $needsNewRequest, --once, installing the module
 *
 * migrate.php is a new process: it reads the migrations directory from the module config.
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$dir = T::dir();
T::saveConfig(['migrationsPath' => $dir]);
$cli = dirname(__DIR__, 2) . '/migrate.php';
$migrate = fn(string ...$args) => T::php($cli, $args);

T::migration(1, '', 'First');
T::migration(2, '', 'Second');

T::section('--list');
[$code, $out, $err] = $migrate('--list');
T::eq(0, $code, 'exit 0');
T::ok(preg_match('/^1\s+pending\s+First$/m', $out) && preg_match('/^2\s+pending\s+Second$/m', $out), 'both pending with description', $out . $err);

T::section('Run pending');
[$code, $out, $err] = $migrate();
T::eq(0, $code, 'exit 0');
T::contains('Executed: 1, 2', $out, 'prints the executed versions');
T::eq([1, 2], T::executed(), 'recorded');
[$code, $out] = $migrate();
T::eq(0, $code, 'exit 0 again');
T::contains('Nothing to do', $out, 'nothing to do');
[, $out] = $migrate('--list');
T::ok(preg_match('/^1\s+\d{4}-\d\d-\d\d \d\d:\d\d\s+First$/m', $out), '--list shows the execution time', $out);

T::section('--run');
[$code, $out] = $migrate('--run=1');
T::eq(0, $code, '--run=1 exit 0');
T::contains('Migration 1 executed', $out, 'it says so');
T::eq(['1', '2', '1'], T::trace(), 'it ran again');
[$code, $out, $err] = $migrate('--run=999');
T::eq(1, $code, '--run=999 exit 1');
T::contains('Error: Migration 999 not found', $err, 'error on STDERR');
[$code, , $err] = $migrate('--run=abc');
T::note('--run=abc: exit ' . $code . ', ' . trim($err) . ' ((int) "abc" is 0)');

T::section('Failure');
touch($dir . 'fail');
T::migration(3, 'if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("boom");');
T::migration(4);
[$code, $out, $err] = $migrate();
T::eq(1, $code, 'a failing migration: exit 1');
T::contains('Error: boom', $err, 'the error on STDERR');
T::eq([1, 2], T::executed(), 'nothing new recorded');
unlink($dir . 'fail');
[$code, $out] = $migrate();
T::eq(0, $code, 'fixed: exit 0');
T::contains('Executed: 3, 4', $out, 'the rest runs');

T::section('Restart after $needsNewRequest');
T::migration(5, '$this->needsNewRequest = true; file_put_contents(__DIR__ . "/pids", getmypid() . "\n", FILE_APPEND);');
T::migration(6, 'file_put_contents(__DIR__ . "/pids", getmypid() . "\n", FILE_APPEND); $this->needsNewRequest = true;');
T::migration(7, 'file_put_contents(__DIR__ . "/pids", getmypid() . "\n", FILE_APPEND);');
[$code, $out, $err] = $migrate();
T::eq(0, $code, 'exit 0');
T::ok(str_contains($out, "Executed: 5\n") && str_contains($out, "Executed: 6\n") && str_contains($out, "Executed: 7\n"), 'each part in its own run', $out . $err);
$pids = array_filter(explode("\n", (string) @file_get_contents($dir . 'pids')));
T::eq(3, count(array_unique($pids)), 'three processes');
T::eq([1, 2, 3, 4, 5, 6, 7], T::executed(), 'all recorded');

T::section('--once');
T::migration(8, '$this->needsNewRequest = true;');
T::migration(9);
[$code, $out] = $migrate('--once');
T::eq(0, $code, 'exit 0');
T::contains('Executed: 8', $out, 'runs up to the restart');
T::ok(!str_contains($out, 'Executed: 9'), 'and stops');
[$code, $out] = $migrate();
T::contains('Executed: 9', $out, 'the next call runs the rest');

T::section('Failure in the restarted process');
touch($dir . 'fail');
T::migration(10, '$this->needsNewRequest = true;');
T::migration(11, 'if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("late boom");');
[$code, $out, $err] = $migrate();
T::eq(1, $code, 'the exit code of the restarted process is passed on');
T::contains('Error: late boom', $err, 'its error is on STDERR');
unlink($dir . 'fail');

T::section('Lock held by another process');
$config = T::wire()->config;
$other = new \PDO("mysql:host=$config->dbHost;port=$config->dbPort;dbname=$config->dbName", $config->dbUser, $config->dbPass);
$other->query("SELECT GET_LOCK('" . ProcessMigrations::lockName . "', 0)");
[$code, $out] = $migrate();
T::eq(0, $code, 'pending with the lock taken: exit 0');
T::note('output with the lock taken: ' . trim($out) . ' (nothing ran, but nothing says why)');
[$code, , $err] = $migrate('--run=1');
T::eq(1, $code, '--run with the lock taken: exit 1');
T::contains('Another migration is running', $err, 'it says why');
$other->query("SELECT RELEASE_LOCK('" . ProcessMigrations::lockName . "')");
$migrate();

T::section('Not as web request');
T::note('migrate.php exits silently when not run by the CLI (PHP_SAPI check); the web server may still serve site/modules/*.php: ProcessWire\'s .htaccess blocks it');
[$status] = T::baseUrl() ? T::http('/site/modules/ProcessMigrations/migrate.php') : [0];
if(T::baseUrl()) T::ok(in_array($status, [403, 404], true), 'migrate.php is not reachable over HTTP', "status $status");

T::section('Installs the module');
T::wire()->modules->uninstall('ProcessMigrations');
T::ok(!T::wire()->modules->isInstalled('ProcessMigrations'), 'setup: uninstalled');
[$code, $out, $err] = $migrate('--list');
T::eq(0, $code, 'migrate.php works without the module installed');
T::ok(T::tableExists(ProcessMigrations::table), 'it installed the module');

T::done();
