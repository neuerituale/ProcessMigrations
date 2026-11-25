<?php namespace ProcessWire;

/**
 * MigrationStep::ensureTemplate()
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
T::dir();
$wire = T::wire();
$templates = $wire->templates;

function step(string $code): void {
	static $version = 0;
	$version++;
	T::migration($version, $code);
	T::wire()->modules->get('ProcessMigrations')->runVersion($version);
}

T::section('Create');
step('$this->ensureField("pm_a", "FieldtypeText", "A"); $this->ensureField("pm_b", "FieldtypeText", "B");');
T::noThrow(fn() => step('$this->ensureTemplate("pm_product", ["title", "pm_a"], ["parentTemplates" => ["home"], "label" => "Product"]);'), 'create a template');
$template = $templates->get('pm_product');
T::ok($template instanceof Template, 'the template exists');
T::eq('pm_product', $template?->fieldgroup->name, 'with its own fieldgroup');
T::eq(['title', 'pm_a'], array_values($template->fieldgroup->explode('name')), 'with the fields in order');
T::eq([$templates->get('home')->id], array_values($template->parentTemplates), 'parentTemplates by name');
T::eq(1, (int) $template->noFile, 'noFile by default');
T::eq('Product', $template->label, 'other settings');
$id = $template->id;

T::section('Again');
T::noThrow(fn() => step('$this->ensureTemplate("pm_product", ["title", "pm_a", "pm_b"], ["noFile" => 0]);'), 'ensureTemplate() again with one more field');
$template = $templates->get('pm_product');
T::eq($id, $template->id, 'the same template');
T::eq(['title', 'pm_a', 'pm_b'], array_values($template->fieldgroup->explode('name')), 'the new field is appended, none duplicated');
T::eq(0, (int) $template->noFile, 'noFile => 0');
T::eq([$templates->get('home')->id], array_values($template->parentTemplates), 'parentTemplates not given: unchanged');
T::eq('Product', $template->label, 'settings not given: unchanged');

T::noThrow(fn() => step('$this->ensureTemplate("pm_product", ["pm_a"]);'), 'ensureTemplate() with fewer fields');
T::eq(['title', 'pm_a', 'pm_b'], array_values($templates->get('pm_product')->fieldgroup->explode('name')), 'fields are never removed');
T::eq(0, (int) $templates->get('pm_product')->noFile, 'noFile is not reset when not given (an earlier migration set it to 0)');

T::section('Existing template with a file');
T::eq(0, (int) $templates->get('basic-page')->noFile, 'basic-page has a file');
T::noThrow(fn() => step('$this->ensureTemplate("basic-page", ["pm_a"]);'), 'add a field to basic-page');
T::ok($templates->get('basic-page')->fieldgroup->hasField('pm_a'), 'the field is added');
T::eq(0, (int) $templates->get('basic-page')->noFile, 'basic-page keeps its file (noFile not forced to 1)');

T::section('Unknown names');
T::notices();
T::noThrow(fn() => step('$this->ensureTemplate("pm_child", [], ["parentTemplates" => ["pm_product", "pm_does_not_exist"], "childTemplates" => []]);'), 'unknown parent template name does not throw');
$warnings = implode("\n", T::notices());
T::eq([$templates->get('pm_product')->id], array_values($templates->get('pm_child')->parentTemplates), 'only the known parent template is set');
T::ok(str_contains($warnings, 'pm_does_not_exist'), 'an unknown template name is reported (a typo goes unnoticed otherwise)', $warnings ?: 'no warning');

T::throws(fn() => step('$this->ensureTemplate("pm_broken", ["title", "pm_does_not_exist"]);'), null, 'an unknown field name throws');
$broken = $templates->get('pm_broken');
T::note('after the failure the template pm_broken ' . ($broken ? 'exists with fields: ' . implode(', ', $broken->fieldgroup->explode('name')) : 'does not exist'));
T::noThrow(fn() => step('$this->ensureField("pm_does_not_exist", "FieldtypeText", "Now"); $this->ensureTemplate("pm_broken", ["title", "pm_does_not_exist"]);'), 'running it again after the field exists');
T::eq(['title', 'pm_does_not_exist'], array_values($templates->get('pm_broken')->fieldgroup->explode('name')), 'the template is complete');

T::section('Template sharing the fieldgroup of another');
$shared = new Template();
$shared->name = 'pm_shared';
$shared->fieldgroup = $templates->get('basic-page')->fieldgroup;
$templates->save($shared);
step('$this->ensureField("pm_c", "FieldtypeText", "C");');
T::noThrow(fn() => step('$this->ensureTemplate("pm_shared", ["pm_c"]);'), 'ensureTemplate() on a template with a shared fieldgroup');
T::note('pm_c added to basic-page too (shared fieldgroup): ' . var_export($templates->get('basic-page')->fieldgroup->hasField('pm_c'), true));

T::section('Template name');
T::throws(fn() => step('$this->ensureTemplate("", ["title"]);'), null, 'an empty template name throws');
T::note('templates after "": ' . implode(', ', array_filter($templates->getAll()->explode('name'), fn($n) => !in_array($n, ['home', 'admin', 'role', 'user', 'permission', 'basic-page'], true))));

T::done();
