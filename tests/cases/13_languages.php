<?php namespace ProcessWire;

/**
 * The real reason for $needsNewRequest: $languages exists only in the request after installing LanguageSupport
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$dir = T::dir();
T::saveConfig(['migrationsPath' => $dir]);

T::migration(1, '$this->ensureModule("LanguageSupport"); $this->ensureModule("LanguageSupportFields");');
T::migration(2, '
	$languages = $this->wire()->languages;
	if(!$languages) throw new WireException("no \$languages in this request");
	if(!$languages->get("de")->id) {
		$language = $this->wire()->pages->add("language", $languages->getParent(), "de", ["title" => "Deutsch"]);
	}
	$this->ensureField("pm_title_ml", "FieldtypeTextLanguage", "Title (multi language)");
');

T::section('In the same request, without $needsNewRequest');
T::noThrow(fn() => $module->runVersion(1), 'LanguageSupport installs');
$e = null;
try { $module->runVersion(2); } catch(\Throwable $e) {}
T::note('the next migration in the same request: ' . ($e ? 'fails: ' . $e->getMessage() : 'works'));

T::section('With $needsNewRequest, by migrate.php');
T::wire()->database->exec('TRUNCATE ' . ProcessMigrations::table);
file_put_contents($dir . 'Migration_1.php', str_replace('$this->ensureModule("LanguageSupportFields");', '$this->ensureModule("LanguageSupportFields"); $this->needsNewRequest = true;', file_get_contents($dir . 'Migration_1.php')));
[$code, $out, $err] = T::php(dirname(__DIR__, 2) . '/migrate.php');
T::eq(0, $code, 'migrate.php exit 0', $out . $err);
T::eq([1, 2], T::executed(), 'both ran');
[, $check] = T::php('-r', ['chdir("' . PM_TEST_ROOT . '"); include "index.php"; echo $wire->languages->get("de")->id ? "de" : "-", " ", $wire->fields->get("pm_title_ml")?->type;']);
T::contains('de FieldtypeTextLanguage', $check, 'the language and the multi language field exist');

T::done();
