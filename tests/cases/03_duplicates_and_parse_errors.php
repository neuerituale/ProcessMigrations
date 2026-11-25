<?php namespace ProcessWire;

/**
 * Two files with the same version, a file with a syntax error
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
T::dir();

T::section('Same version twice: Migration_2.php and Migration_02.php');
T::migration(2, '', 'two');
T::migration('02', '', 'zero two');
T::notices();
$migrations = $module->migrations();
$warnings = implode("\n", T::notices());
T::eq(1, count($migrations), 'only one migration with version 2 is listed');
T::ok(str_contains($warnings, 'Migration_02.php') || str_contains($warnings, 'Migration_2.php'), 'a duplicate version is warned about (otherwise one of them never runs)', $warnings ?: 'no warning');
$module->runPending();
T::eq(1, count(T::trace()), 'only one of the two runs');
T::note('the one that runs: Migration_' . T::trace()[0]);

T::section('Syntax error');
unlink(T::wire()->config->migrationsPath . 'Migration_02.php');
T::migration(3);
T::file('Migration_4.php', '<?php namespace ProcessWire; class Migration_4 extends MigrationStep { this is not php }');
T::migration(5);
$e = T::throws(fn() => $module->migrations(), null, 'migrations() throws on a syntax error (the admin page shows the error)');
if($e) T::eq('ParseError', get_class($e), 'it is a ParseError');
// the second call in the same request happens e.g. in the catch block of hookFinished()
T::throws(fn() => $module->migrations(), null, 'a second migrations() in the same request throws again');
T::throws(fn() => $module->runPending(), null, 'a later runPending() in the same request throws, too');
T::ok(!in_array('5', T::trace(), true), 'migration 5 does not run while migration 4 is broken', 'ran: ' . implode(', ', T::trace()));

T::section('Fatal error at the top level of a file');
unlink(T::wire()->config->migrationsPath . 'Migration_4.php');
T::file('Migration_6.php', '<?php namespace ProcessWire; throw new \RuntimeException("top level");');
T::throws(fn() => $module->migrations(), 'top level', 'an exception at the top level of a file is thrown by migrations()');

T::done();
