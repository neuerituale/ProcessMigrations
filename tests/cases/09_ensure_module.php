<?php namespace ProcessWire;

/**
 * MigrationStep::ensureModule(): core modules, missing modules, modules that came with the deployment
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
T::dir();
$modules = T::wire()->modules;

function step(string $code): void {
	static $version = 0;
	$version++;
	T::migration($version, $code);
	T::wire()->modules->get('ProcessMigrations')->runVersion($version);
}

T::section('Core module');
T::ok(!$modules->isInstalled('FieldtypeOptions'), 'FieldtypeOptions is not installed');
T::noThrow(fn() => step('$this->ensureModule("FieldtypeOptions");'), 'ensureModule() installs a core module');
T::ok($modules->isInstalled('FieldtypeOptions'), 'it is installed');
T::notices('NoticeMessage');
T::noThrow(fn() => step('$this->ensureModule("FieldtypeOptions");'), 'ensureModule() again');
T::ok(!str_contains(implode("\n", T::notices('NoticeMessage')), 'Installed FieldtypeOptions'), 'no second "Installed"');
T::noThrow(fn() => step('$this->ensureModule("FieldtypeText");'), 'ensureModule() of a permanent core module');

T::section('Core module with dependencies');
T::noThrow(fn() => step('$this->ensureModule("FieldtypeRepeater");'), 'ensureModule("FieldtypeRepeater") installs its dependencies');
T::ok($modules->isInstalled('InputfieldRepeater'), 'InputfieldRepeater is installed with it');

T::section('Module that does not exist');
$e = T::throws(fn() => step('$this->ensureModule("PmTestDoesNotExist");'), 'Module PmTestDoesNotExist could not be installed', 'a missing module throws');
T::ok(!in_array(5, T::executed(), true), 'the migration is not recorded');
T::throws(fn() => step('$this->ensureModule("");'), null, 'an empty module name throws');

T::section('Site module that came with this deployment (not in the modules cache yet)');
T::siteModule('PmTestDeployed', 'public function hello() { return "hi"; }');
T::ok(!$modules->isInstallable('PmTestDeployed'), 'setup: the new module is not known yet');
T::noThrow(fn() => step('$this->ensureModule("PmTestDeployed");'), 'ensureModule() installs it');
T::ok($modules->isInstalled('PmTestDeployed'), 'it is installed');
T::eq('hi', $modules->get('PmTestDeployed')?->hello(), 'and usable');

T::siteModule('PmTestOldStyle', '', [], '.module');
T::noThrow(fn() => step('$this->ensureModule("PmTestOldStyle");'), 'the same with a .module file');
T::ok($modules->isInstalled('PmTestOldStyle'), 'it is installed');

T::section('Site module in a directory of another name');
$dir = T::siteModule('PmTestDir');
rename($dir . 'PmTestDir.module.php', $dir . 'PmTestOtherName.module.php');
file_put_contents($dir . 'PmTestOtherName.module.php', str_replace('class PmTestDir ', 'class PmTestOtherName ', file_get_contents($dir . 'PmTestOtherName.module.php')));
$e = null;
try { step('$this->ensureModule("PmTestOtherName");'); } catch(\Throwable $e) {}
T::note('module PmTestOtherName in site/modules/PmTestDir/: ' . ($e ? 'throws: ' . $e->getMessage() : 'installed'));

T::section('Site module with an unmet requirement');
T::siteModule('PmTestNeedy', '', ['requires' => ['PmTestDoesNotExist']]);
$e = T::throws(fn() => step('$this->ensureModule("PmTestNeedy");'), null, 'a module with a missing requirement throws');
T::note('message: ' . ($e ? $e->getMessage() : '-'));
T::ok(!$modules->isInstalled('PmTestNeedy'), 'it is not installed');

T::section('Site module whose install() throws');
T::siteModule('PmTestBrokenInstall', 'public function ___install() { throw new WireException("install failed"); }');
T::throws(fn() => step('$this->ensureModule("PmTestBrokenInstall");'), null, 'the exception of install() reaches the migration');
T::note('after install() threw, ProcessWire takes PmTestBrokenInstall for installed: ' . var_export($modules->isInstalled('PmTestBrokenInstall'), true) . ' (a second run of ensureModule() skips it)');

T::section('Site module with a syntax error');
$dir = T::siteModule('PmTestSyntax');
file_put_contents($dir . 'PmTestSyntax.module.php', '<?php namespace ProcessWire; class PmTestSyntax extends WireData implements Module { this is not php }');
T::throws(fn() => step('$this->ensureModule("PmTestSyntax");'), null, 'a module with a syntax error throws');

T::section('Next request knows the installed module');
[$code, $out] = T::php(PM_TEST_ROOT . 'index.php');
$check = T::php('-r', ['chdir("' . PM_TEST_ROOT . '"); include "index.php"; echo $wire->modules->isInstalled("PmTestDeployed") ? "yes" : "no";']);
T::note('a new process sees PmTestDeployed installed: ' . trim(preg_replace('/\s+/', ' ', $check[1])));

T::done();
