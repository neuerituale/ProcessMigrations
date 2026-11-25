<?php namespace ProcessWire;

/**
 * getMigrationsPath(), getAutoRun(), the admin page, uninstall and install again
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$config = T::wire()->config;
$root = $config->paths->root;

T::section('getMigrationsPath()');
$config->migrationsPath = null;
T::eq($root . 'site/migrations/', $module->getMigrationsPath(), 'default: site/migrations/');
$module->migrationsPath = 'site/other';
T::eq($root . 'site/other/', $module->getMigrationsPath(), 'module setting relative, without trailing slash');
$module->migrationsPath = '/site/other/';
T::eq('/site/other/', $module->getMigrationsPath(), 'a leading slash is an absolute path');
$module->migrationsPath = '  ';
T::eq($root . 'site/migrations/', $module->getMigrationsPath(), 'blank module setting: default');
$config->migrationsPath = '/tmp/pm-abs//';
T::eq('/tmp/pm-abs/', $module->getMigrationsPath(), '$config->migrationsPath takes precedence, trailing slashes trimmed');
$config->migrationsPath = 'site/rel/';
T::eq($root . 'site/rel/', $module->getMigrationsPath(), '$config->migrationsPath relative');
$module->migrationsPath = 'site/migrations/';

T::section('getAutoRun()');
foreach([
	[null, '', ''],
	[null, 'superuser', 'superuser'],
	[null, 'always', 'always'],
	[null, 'bogus', ''],
	[true, '', 'always'],
	[false, 'always', ''],
	['superuser', 'always', 'superuser'],
	['', 'always', ''],
	['ALWAYS', '', ''],
	[1, '', ''],
] as [$override, $setting, $expected]) {
	$config->migrationsAutoRun = $override;
	$module->autoRun = $setting;
	T::eq($expected, $module->getAutoRun(), '$config ' . var_export($override, true) . ', setting ' . var_export($setting, true) . ' → ' . var_export($expected, true));
}
$config->migrationsAutoRun = null;
$module->autoRun = '';

T::section('Admin page');
T::dir();
T::migration(1, '', 'Already <b>done</b> & "quoted"');
T::migration(2, '', '<script>alert(1)</script>');
$module->runVersion(1);
$html = T::noThrow(fn() => $module->execute(), 'execute() renders');
$html = (string) $html;
T::contains('1 pending migration', $html, 'it counts the pending migrations');
T::contains('Already &lt;b&gt;done&lt;/b&gt; &amp;', $html, 'descriptions are escaped');
T::ok(!str_contains($html, '<script>alert(1)</script>'), 'no script from a description');
T::contains('value="pending"', $html, 'the button to run the pending ones');
T::contains('Run again', $html, 'executed migrations can be run again');
T::contains('<strong>pending</strong>', $html, 'migration 2 is pending');
T::contains("action='./run/'", str_replace('"', "'", $html), 'the form posts to ./run/');

$module->runPending();
$html = (string) $module->execute();
T::contains('All migrations executed', $html, 'all executed');
T::ok(!str_contains($html, 'value="pending"'), 'no button for pending');

$config->migrationsPath = $root . 'site/assets/cache/pm-tests/missing/';
T::notices();
$html = (string) $module->execute();
T::contains('No migrations found', $html, 'no migrations');
T::contains('does not exist', implode("\n", T::notices()), 'missing directory: warning');

T::section('Module config form');
$wrapper = new InputfieldWrapper();
T::wire()->wire($wrapper);
T::noThrow(fn() => $module->getModuleConfigInputfields($wrapper), 'config inputfields');
T::ok($wrapper->getChildByName('migrationsPath') && $wrapper->getChildByName('autoRun'), 'with migrationsPath and autoRun');
$config->migrationsAutoRun = 'always';
$wrapper = new InputfieldWrapper();
T::wire()->wire($wrapper);
$module->getModuleConfigInputfields($wrapper);
T::contains('Overridden by', (string) $wrapper->getChildByName('autoRun')->notes, 'the override is shown');
$config->migrationsAutoRun = null;

T::section('Uninstall (in its own request)');
T::dir('reinstall');
T::migration(3);
$module->runPending();
[$code, $out, $err] = T::php('-r', ['chdir("' . PM_TEST_ROOT . '"); include "index.php"; $wire->users->setCurrentUser($wire->users->get($wire->config->superUserPageID)); $wire->modules->uninstall("ProcessMigrations"); echo $wire->modules->isInstalled("ProcessMigrations") ? "installed" : "uninstalled";']);
T::contains('uninstalled', $out, 'uninstall', $out . $err);
T::ok(!T::tableExists(ProcessMigrations::table), 'the table is dropped');
T::ok(is_file(T::wire()->config->migrationsPath . 'Migration_3.php'), 'the migration files stay');
$query = T::wire()->database->query("SELECT COUNT(*) FROM pages WHERE name='migrations' AND parent_id=(SELECT id FROM pages WHERE name='setup' LIMIT 1)");
T::eq(0, (int) $query->fetchColumn(), 'the admin page is moved to the trash');

T::section('Install, uninstall, install in the same request');
// this process still takes the module for installed: install it in a new one
[, $out, $err] = T::php('-r', ['chdir("' . PM_TEST_ROOT . '"); include "index.php"; $wire->users->setCurrentUser($wire->users->get($wire->config->superUserPageID)); $wire->modules->refresh(); $wire->modules->install("ProcessMigrations"); echo $wire->modules->isInstalled("ProcessMigrations") ? "installed" : "uninstalled";']);
T::contains('installed', $out, 'install in a new request', $out . $err);
T::ok(T::tableExists(ProcessMigrations::table), 'the table exists');
$modules = T::wire()->modules;
$modules->get('ProcessMigrations')->pending();
$modules->uninstall('ProcessMigrations');
T::noThrow(fn() => $modules->install('ProcessMigrations'), 'install again');
T::ok(T::tableExists(ProcessMigrations::table), 'the table exists after reinstall in the same request');
T::noThrow(fn() => $modules->get('ProcessMigrations')->pending(), 'pending() after reinstall works');

T::section('Install creates the default directory');
$created = !is_dir($root . 'site/migrations/');
T::note('site/migrations/ ' . (is_dir($root . 'site/migrations/') ? 'exists' : 'does not exist') . ' after install (install() uses $config->migrationsPath, set by this test)');

T::done();
