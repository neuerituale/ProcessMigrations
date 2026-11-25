<?php namespace ProcessWire;

/**
 * A migration that throws: what is recorded, what runs, the lock, running again after the fix
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$dir = T::dir();
$fail = $dir . 'fail';
touch($fail);

T::migration(1);
T::migration(2, 'if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("boom");');
T::migration(3);

T::section('Exception in up()');
T::throws(fn() => $module->runPending(), 'boom', 'runPending() throws the exception of the migration');
T::eq([1], T::executed(), 'the migration before is recorded, the failed one not');
T::eq(['1', '2'], T::trace(), 'the migrations after the failed one do not run');
T::eq([2, 3], array_keys($module->pending()), 'the failed one and the rest are pending');
T::contains('Migration 2 failed: boom', T::log(), 'the failure is logged');
T::ok(!str_contains(T::log(), 'Migration 2 done'), 'no "done" for the failed one');

$database = T::wire()->database;
$other = new \PDO('mysql:host=' . T::wire()->config->dbHost . ';port=' . T::wire()->config->dbPort . ';dbname=' . T::wire()->config->dbName, T::wire()->config->dbUser, T::wire()->config->dbPass);
$lock = $other->query("SELECT GET_LOCK('" . ProcessMigrations::lockName . "', 0)")->fetchColumn();
T::eq(1, (int) $lock, 'the lock is released after the exception');
$other->query("SELECT RELEASE_LOCK('" . ProcessMigrations::lockName . "')");

T::section('Again, still failing');
T::throws(fn() => $module->runPending(), 'boom', 'it fails again');
T::eq(['1', '2', '2'], T::trace(), 'migration 1 is not run again');

T::section('Fixed');
unlink($fail);
T::eq([2, 3], $module->runPending(), 'after the fix the rest runs');
T::eq([1, 2, 3], T::executed(), 'all recorded');

T::section('runVersion() of a failing migration');
touch($fail);
T::throws(fn() => $module->runVersion(2), 'boom', 'runVersion() throws');
T::eq([1, 2, 3], T::executed(), 'the record of the earlier run stays');
$lock = $other->query("SELECT GET_LOCK('" . ProcessMigrations::lockName . "', 0)")->fetchColumn();
T::eq(1, (int) $lock, 'the lock is released after runVersion() failed');
$other->query("SELECT RELEASE_LOCK('" . ProcessMigrations::lockName . "')");
unlink($fail);

T::section('PHP errors (not exceptions)');
T::migration(4, '$x = null; $x->method();');
T::throws(fn() => $module->runPending(), 'method', 'an Error (call on null) is thrown like an exception');
T::contains('Migration 4 failed', T::log(), 'and logged');
unlink($dir . 'Migration_4.php');

T::migration(5, 'strlen();');
T::throws(fn() => $module->runPending(), null, 'an ArgumentCountError is thrown');
unlink($dir . 'Migration_5.php');

T::migration(6, 'trigger_error("just a warning", E_USER_WARNING);');
$e = null;
try { $module->runPending(); } catch(\Throwable $e) {}
T::note('E_USER_WARNING in up() with $config->debug=' . var_export(T::wire()->config->debug, true) . ': ' . ($e ? 'thrown (' . $e->getMessage() . ')' : 'not thrown, migration recorded: ' . var_export(in_array(6, T::executed(), true), true)));

T::section('Partial schema change, then the error');
T::migration(7, '
	$this->ensureField("pm_partial", "FieldtypeText", "Partial");
	if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("after the field");
');
touch($fail);
T::throws(fn() => $module->runPending(), 'after the field', 'fails after creating a field');
T::ok(T::wire()->fields->get('pm_partial') instanceof Field, 'the field stays (no rollback)');
unlink($fail);
T::notices();
T::noThrow(fn() => $module->runPending(), 'running it again works, ensureField() is idempotent');
T::eq(1, T::wire()->fields->find('name=pm_partial')->count(), 'still one field pm_partial');

T::section('Exception in description()');
T::migration(8, '', '', '');
file_put_contents($dir . 'Migration_8.php', str_replace("return 'Test migration 8';", 'throw new \RuntimeException("no description");', file_get_contents($dir . 'Migration_8.php')));
T::throws(fn() => $module->runPending(), 'no description', 'an exception in description() stops the run');
T::ok(!in_array(8, T::executed(), true), 'the migration is not recorded');

T::done();
