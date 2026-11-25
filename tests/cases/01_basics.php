<?php namespace ProcessWire;

/**
 * Discovery, order, recording, running again
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$config = T::wire()->config;

T::section('Installation');
T::ok(T::tableExists(ProcessMigrations::table), 'install() creates the table');
T::ok(T::wire()->permissions->get(ProcessMigrations::permissionName)->id > 0, 'install() creates the permission');
T::ok(T::wire()->pages->get('template=admin, name=migrations, parent.name=setup')->id > 0, 'install() creates the admin page under Setup');

T::section('No migrations');
$config->migrationsPath = PM_TEST_ROOT . 'site/assets/cache/pm-tests/does-not-exist/';
T::eq([], $module->migrations(), 'a missing directory has no migrations');
T::eq([], $module->runPending(), 'runPending() with a missing directory does nothing');
$dir = T::dir();
T::eq([], $module->migrations(), 'an empty directory has no migrations');
T::eq([], $module->pending(), 'an empty directory has nothing pending');

T::section('Order');
T::migration(10);
T::migration(2);
T::migration(1);
T::migration(202611251430, '', 'Timestamp version');
T::migration('007', '', 'Leading zeros');
T::eq([1, 2, 7, 10, 202611251430], array_keys($module->migrations()), 'versions are sorted numerically, not by file name');
T::eq('ProcessWire\Migration_007', $module->migrations()[7], 'leading zeros: version 7, class Migration_007');
T::eq([1, 2, 7, 10, 202611251430], array_keys($module->pending()), 'all are pending');

T::section('runPending()');
$before = time();
$done = $module->runPending();
T::eq([1, 2, 7, 10, 202611251430], $done, 'runPending() returns the executed versions in order');
T::eq(['1', '2', '007', '10', '202611251430'], T::trace(), 'up() was called once each, in order');
T::eq([1, 2, 7, 10, 202611251430], T::executed(), 'all versions are recorded, also the BIGINT timestamp');
T::eq([], $module->pending(), 'nothing pending afterwards');
T::eq([], $module->runPending(), 'a second runPending() does nothing');
T::eq(5, count(T::trace()), 'a second runPending() does not call up() again');

$executed = $module->executed();
$offsets = array_map(fn($time) => $time - $before, $executed);
T::ok(min($offsets) >= -2 && max($offsets) <= 60, 'executed() timestamps match PHP time() (MySQL and PHP time zone agree)', 'offsets in seconds: ' . implode(', ', $offsets));

$log = T::log();
T::contains('Migration 1: Test migration 1', $log, 'the log has the description');
T::contains('Migration 202611251430 done', $log, 'the log has "done"');
T::ok(strpos($log, 'Migration 2 done') < strpos($log, 'Migration 10:'), 'the log is in order');

T::section('runVersion()');
sleep(1);
$timeBefore = $module->executed()[2];
$module->runVersion(2);
T::eq(['1', '2', '007', '10', '202611251430', '2'], T::trace(), 'runVersion() runs an executed migration again');
T::ok($module->executed()[2] > $timeBefore, 'runVersion() updates the execution time');
T::throws(fn() => $module->runVersion(999), 'Migration 999 not found', 'runVersion() of an unknown version throws');
T::eq([1, 2, 7, 10, 202611251430], T::executed(), 'an unknown version is not recorded');

T::section('Deleted migration file');
unlink($dir . 'Migration_10.php');
T::ok(!isset($module->migrations()[10]), 'a deleted file is not listed any more');
T::ok(in_array(10, T::executed(), true), 'its record stays in the table');
T::eq([], $module->pending(), 'it does not make anything pending');

T::section('Version 0');
T::migration(0, '', 'Version zero');
T::eq([0], array_keys($module->pending()), 'Migration_0 is a valid version');
T::eq([0], $module->runPending(), 'Migration_0 runs');

T::done();
