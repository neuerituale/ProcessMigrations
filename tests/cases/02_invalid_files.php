<?php namespace ProcessWire;

/**
 * Files in the migrations directory that are no valid migrations
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
T::dir();

T::migration(1);
T::file('Migration_abc.php', '<?php namespace ProcessWire; class Migration_abc {}');
T::file('Migration_2.txt', 'not php');
T::file('migration_3.php', '<?php namespace ProcessWire; class Migration_3 extends MigrationStep { static function description(): string { return ""; } function up(): void {} }');
T::file('Migration_4.php', '<?php namespace ProcessWire; class SomethingElse {}');
T::file('Migration_5.php', '<?php namespace ProcessWire; class Migration_5 {}');
T::file('Migration_6.php', '<?php namespace Other; class Migration_6 extends \ProcessWire\MigrationStep { static function description(): string { return ""; } function up(): void {} }');
T::file('Migration_7.php', '<?php namespace ProcessWire; abstract class Migration_7 extends MigrationStep {}');
T::file('Migration_8.php.bak', '<?php');
T::file('Migration_-9.php', '<?php');

T::notices();
$migrations = T::noThrow(fn() => $module->migrations(), 'invalid files do not break migrations()');
$warnings = implode("\n", T::notices());
T::eq([1], array_keys((array) $migrations), 'only the valid migration is listed');

T::section('Warnings');
T::contains('Migration_4.php does not define the class ProcessWire\Migration_4', $warnings, 'file without the class: warning');
T::contains('Migration_5.php does not define the class ProcessWire\Migration_5 extending MigrationStep', $warnings, 'class not extending MigrationStep: warning');
T::contains('Migration_6.php', $warnings, 'class in another namespace: warning');
T::ok(!str_contains($warnings, 'Migration_abc'), 'Migration_abc.php is ignored silently (no version)');
T::ok(!str_contains($warnings, 'migration_3'), 'lower case migration_3.php is ignored silently', $warnings);
T::ok(!str_contains($warnings, 'Migration_-9'), 'Migration_-9.php is ignored silently', $warnings);

T::section('Abstract class');
T::contains('Migration_7.php', $warnings, 'an abstract migration class is warned about');
$migrations = $module->migrations();
if(isset($migrations[7])) {
	T::throws(fn() => $module->runPending(), null, 'an abstract migration is listed: running it throws');
	T::ok(false, 'an abstract migration class is not listed as a migration', 'listed as ' . $migrations[7]);
} else {
	T::ok(true, 'an abstract migration class is not listed as a migration');
}

T::section('Running with invalid files around');
T::notices();
$done = T::noThrow(fn() => $module->runPending(), 'runPending() with invalid files around');
T::ok(in_array(1, (array) $done, true), 'the valid migration ran');

T::section('Directory named like a migration');
mkdir(T::wire()->config->migrationsPath . 'Migration_10.php');
$migrations = T::noThrow(fn() => $module->migrations(), 'a directory Migration_10.php does not break migrations()');
T::ok(!isset($migrations[10]), 'a directory Migration_10.php is not a migration');

T::done();
