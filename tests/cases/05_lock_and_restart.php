<?php namespace ProcessWire;

/**
 * The lock against parallel runs and $needsNewRequest
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$dir = T::dir();
$config = T::wire()->config;
$other = new \PDO("mysql:host=$config->dbHost;port=$config->dbPort;dbname=$config->dbName", $config->dbUser, $config->dbPass);
$lockName = $other->quote(ProcessMigrations::lockName);

T::migration(1);
T::migration(2);

T::section('Another run holds the lock');
T::eq(1, (int) $other->query("SELECT GET_LOCK($lockName, 0)")->fetchColumn(), 'a second connection gets the lock');
$start = microtime(true);
T::eq([], $module->runPending(), 'runPending() skips silently');
T::ok(microtime(true) - $start < 2, 'runPending() does not wait for the lock');
T::eq([], T::trace(), 'nothing ran');
T::throws(fn() => $module->runVersion(1), 'Another migration is running', 'runVersion() throws');
T::eq([], T::executed(), 'nothing recorded');
$other->query("SELECT RELEASE_LOCK($lockName)");
T::eq([1, 2], $module->runPending(), 'after release, runPending() runs');

T::section('Lock is held while a migration runs');
T::migration(3, '
	$pdo = new \PDO("mysql:host=' . $config->dbHost . ';port=' . $config->dbPort . ';dbname=' . $config->dbName . '", "' . $config->dbUser . '", "' . $config->dbPass . '");
	file_put_contents(__DIR__ . "/lockstate", $pdo->query("SELECT IS_FREE_LOCK(\'' . ProcessMigrations::lockName . '\')")->fetchColumn());
');
$module->runPending();
T::eq('0', @file_get_contents($dir . 'lockstate'), 'during up() the lock is taken');
T::eq('1', (string) $other->query("SELECT IS_FREE_LOCK($lockName)")->fetchColumn(), 'afterwards it is free');

T::section('A migration runs another one (nested lock)');
T::migration(4, '$this->wire()->modules->get("ProcessMigrations")->runVersion(1);');
T::noThrow(fn() => $module->runPending(), 'runVersion() inside a migration works (GET_LOCK is re-entrant per connection)');
T::eq('1', (string) $other->query("SELECT IS_FREE_LOCK($lockName)")->fetchColumn(), 'the lock is free afterwards');

T::section('$needsNewRequest');
T::migration(5, '$this->needsNewRequest = true;');
T::migration(6);
T::migration(7, '$this->needsNewRequest = true;');
T::migration(8);
T::eq([5], $module->runPending(), 'runPending() stops after a migration that needs a new request');
T::eq([6, 7, 8], array_keys($module->pending()), 'the rest stays pending');
T::eq([6, 7], $module->runPending(), 'the next call goes on up to the next one that needs a new request');
T::eq([8], $module->runPending(), 'and then the rest');
T::eq([], $module->runPending(), 'then nothing');
T::noThrow(fn() => $module->runVersion(5), 'runVersion() of a migration that needs a new request');

T::done();
