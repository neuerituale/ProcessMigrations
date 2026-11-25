<?php

/**
 * Integration tests of ProcessMigrations against the ProcessWire installation this module lives in
 *
 *   php site/modules/ProcessMigrations/tests/run.php              all cases
 *   php site/modules/ProcessMigrations/tests/run.php field cli    cases whose file name contains "field" or "cli"
 *
 * WARNING: the cases change the database of this installation (fields, templates, modules, pages).
 * Before the first case all tables are copied into the database <dbName>_pmtest_snapshot, after every case
 * they are restored from it, at the end the copy is dropped. An aborted run is restored on the next start.
 * Use a development installation only.
 *
 * Environment: PM_TEST_URL base URL of the site for the HTTP cases (default: https://<first httpHost>)
 */

if(PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__, 4) . '/';
$casesDir = __DIR__ . '/cases/';
$scratch = $root . 'site/assets/cache/pm-tests/';
$logFile = $root . 'site/assets/logs/migrations.txt';

// database credentials, from a separate process: this one must not keep a booted ProcessWire
$code = 'include "index.php"; $c = $wire->config; echo json_encode(["dbName" => $c->dbName, "dbHost" => $c->dbHost, "dbPort" => $c->dbPort, "dbSocket" => $c->dbSocket, "dbUser" => $c->dbUser, "dbPass" => $c->dbPass]);';
$json = shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
$config = (object) (json_decode((string) $json, true) ?: exit("Could not read the database config: $json\n"));
$dbName = $config->dbName;
$snapshot = $dbName . '_pmtest_snapshot';
$dsn = $config->dbSocket
	? "mysql:unix_socket={$config->dbSocket};dbname=$dbName"
	: 'mysql:host=' . ($config->dbHost ?: 'localhost') . ';port=' . ($config->dbPort ?: 3306) . ";dbname=$dbName";
$pdo = new PDO($dsn, $config->dbUser, $config->dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tables = function(string $db) use($pdo): array {
	$query = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_TYPE=\'BASE TABLE\'');
	$query->execute([':db' => $db]);
	return $query->fetchAll(PDO::FETCH_COLUMN);
};
$copy = function(string $from, string $to) use($pdo, $tables): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
	foreach($tables($to) as $table) $pdo->exec("DROP TABLE `$to`.`$table`");
	foreach($tables($from) as $table) {
		$pdo->exec("CREATE TABLE `$to`.`$table` LIKE `$from`.`$table`");
		$pdo->exec("INSERT INTO `$to`.`$table` SELECT * FROM `$from`.`$table`");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
};
$snapshotExists = (bool) $pdo->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=' . $pdo->quote($snapshot))->fetchColumn();

$cleanFiles = function() use($scratch, $root): void {
	if(is_dir($scratch)) exec('rm -rf ' . escapeshellarg($scratch));
	foreach(glob($root . 'site/modules/PmTest*', GLOB_ONLYDIR) ?: [] as $dir) exec('rm -rf ' . escapeshellarg($dir));
};

if($snapshotExists && count($tables($snapshot))) {
	echo "Restoring the database from the snapshot of an aborted run\n";
	$copy($snapshot, $dbName);
	$cleanFiles();
} else {
	$pdo->exec("CREATE DATABASE IF NOT EXISTS `$snapshot`");
	$copy($dbName, $snapshot);
}

$logBackup = is_file($logFile) ? file_get_contents($logFile) : null;
// install() creates the default directory: remove it again if it didn't exist and stays empty
$defaultDir = $root . 'site/migrations';
$defaultDirExisted = is_dir($defaultDir);

$filters = array_slice($argv, 1);
$cases = array_filter(glob($casesDir . '*.php'), function($file) use($filters) {
	if(!count($filters)) return true;
	foreach($filters as $filter) if(str_contains(basename($file), $filter)) return true;
	return false;
});

$results = [];
$start = microtime(true);
try {
	foreach($cases as $file) {
		$name = basename($file, '.php');
		echo "\n== $name\n";
		if(is_file($logFile)) unlink($logFile);
		$caseStart = microtime(true);
		$process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root, getenv() + ['PM_TEST_RUNNER' => '1']);
		$output = '';
		while(!feof($pipes[1])) {
			$chunk = fread($pipes[1], 8192);
			$output .= $chunk;
			echo $chunk;
		}
		fclose($pipes[1]);
		$code = proc_close($process);
		preg_match('/RESULT pass=(\d+) fail=(\d+)/', $output, $m);
		$results[$name] = [
			'code' => $code,
			'pass' => (int) ($m[1] ?? 0),
			'fail' => (int) ($m[2] ?? 0),
			'notes' => substr_count($output, '  NOTE '),
			'complete' => isset($m[1]),
			'time' => microtime(true) - $caseStart,
		];
		$copy($snapshot, $dbName);
		$cleanFiles();
	}
} finally {
	$copy($snapshot, $dbName);
	$pdo->exec("DROP DATABASE `$snapshot`");
	$cleanFiles();
	if($logBackup !== null) file_put_contents($logFile, $logBackup);
	elseif(is_file($logFile)) unlink($logFile);
	if(!$defaultDirExisted && is_dir($defaultDir) && !count(glob("$defaultDir/*"))) rmdir($defaultDir);
}

echo "\n" . str_repeat('=', 64) . "\n";
$failed = 0;
foreach($results as $name => $r) {
	$state = !$r['complete'] ? 'CRASH' : ($r['fail'] || $r['code'] ? 'FAIL ' : 'ok   ');
	if($state !== 'ok   ') $failed++;
	printf("%s %-32s %3d passed %3d failed %2d notes %5.1fs\n", $state, $name, $r['pass'], $r['fail'], $r['notes'], $r['time']);
}
printf("\n%d cases, %d failed, %.1fs. Database restored.\n", count($results), $failed, microtime(true) - $start);
exit($failed ? 1 : 0);
