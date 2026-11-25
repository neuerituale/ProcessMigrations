<?php namespace ProcessWire;

/**
 * Run the migrations from the command line, e.g. in a deploy script
 *
 *   php site/modules/ProcessMigrations/migrate.php            run all pending migrations
 *   php site/modules/ProcessMigrations/migrate.php --list     show all migrations and their state
 *   php site/modules/ProcessMigrations/migrate.php --run=3    run migration 3 (again)
 *
 * Runs as superuser: installing modules and changing the schema need it.
 * Exits with 1 on errors. Repeats runPending() while migrations ask for a new request.
 */

if(PHP_SAPI !== 'cli') exit(1);

// in a new process each time: some migrations (e.g. installing LanguageSupport) need a new request
$options = getopt('', ['list', 'run:', 'once']);

include dirname(__DIR__, 3) . '/index.php';

/** @var ProcessWire $wire */
$modules = $wire->modules;
$users = $wire->users;
$users->setCurrentUser($users->get($wire->config->superUserPageID));

if(!$modules->isInstalled('ProcessMigrations')) {
	if(!$modules->isInstallable('ProcessMigrations')) $modules->refresh();
	$modules->install('ProcessMigrations');
}

/** @var ProcessMigrations $migrations */
$migrations = $modules->get('ProcessMigrations');

try {
	if(isset($options['list'])) {
		$executed = $migrations->executed();
		foreach($migrations->migrations() as $version => $class) {
			$state = isset($executed[$version]) ? date('Y-m-d H:i', $executed[$version]) : 'pending';
			echo str_pad((string) $version, 14) . str_pad($state, 18) . $class::description() . PHP_EOL;
		}
		exit(0);
	}

	if(isset($options['run'])) {
		$migrations->runVersion((int) $options['run']);
		echo "Migration {$options['run']} executed" . PHP_EOL;
		exit(0);
	}

	$done = $migrations->runPending();
	echo count($done) ? 'Executed: ' . implode(', ', $done) . PHP_EOL : 'Nothing to do' . PHP_EOL;

	// more pending after a migration that needs a new request: start a new process
	if(count($done) && count($migrations->pending()) && !isset($options['once'])) {
		passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__), $code);
		exit($code);
	}
} catch(\Throwable $e) {
	fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
	exit(1);
}
