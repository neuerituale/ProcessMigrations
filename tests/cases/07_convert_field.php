<?php namespace ProcessWire;

/**
 * MigrationStep::convertField(): text to options with values, positions in fieldgroups
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
T::dir();
$wire = T::wire();
$fields = $wire->fields;
$pages = $wire->pages;

function step(string $code): void {
	static $version = 0;
	$version++;
	T::migration($version, $code);
	T::wire()->modules->get('ProcessMigrations')->runVersion($version);
}

T::section('Setup: text field pm_color on basic-page between title and pm_after');
step('
	$this->ensureModule("FieldtypeOptions");
	$this->ensureField("pm_color", "FieldtypeText", "Color");
	$this->ensureField("pm_after", "FieldtypeText", "After");
	$this->ensureTemplate("basic-page", ["pm_color", "pm_after"]);
	$this->ensureTemplate("pm_other", ["pm_color", "title"]);
');
$template = $wire->templates->get('basic-page');
T::eq(['title', 'pm_color', 'pm_after'], array_values($template->fieldgroup->explode('name')), 'fieldgroup order before');
$template->fieldgroup->setFieldContextArray($fields->get('pm_color')->id, ['columnWidth' => 50]);
$template->fieldgroup->saveContext();

$home = $pages->get('/');
$values = ['red' => 'red', 'green' => 'green', 'Blue' => 'Blue (title instead of value)', '' => 'empty', 'purple' => 'not an option', '2' => 'an option id'];
$ids = [];
foreach($values as $value => $label) {
	$page = $pages->add('basic-page', $home, ['title' => "pm $label", 'pm_color' => (string) $value]);
	$ids[(string) $value] = $page->id;
}
$other = $pages->add('pm_other', $home, ['title' => 'pm other', 'pm_color' => 'green']);
$oldId = $fields->get('pm_color')->id;

T::section('Convert');
T::notices();
T::noThrow(fn() => step('$this->convertField("pm_color", "FieldtypeOptions", "Color", ["options" => ["red" => "Red", "green" => "Green", "blue" => "Blue"]]);'), 'convertField() text → options');
$messages = implode("\n", T::notices('NoticeMessage'));
T::contains('Field pm_color converted to FieldtypeOptions', $messages, 'it reports the conversion');

$field = $fields->get('pm_color');
T::eq('FieldtypeOptions', $field->type->className(), 'the type is changed');
T::ok(!T::tableExists("field_pm_color") || $field->id !== $oldId, 'the field is a new one');
$template = $wire->templates->get('basic-page');
T::eq(['title', 'pm_color', 'pm_after'], array_values($template->fieldgroup->explode('name')), 'the position in basic-page is kept');
T::eq(['pm_color', 'title'], array_values($wire->templates->get('pm_other')->fieldgroup->explode('name')), 'the position at the start of pm_other is kept');
$context = $template->fieldgroup->getFieldContextArray($field->id);
T::ok(($context['columnWidth'] ?? null) == 50, 'the context settings of the fieldgroup (column width) are kept', 'context: ' . json_encode($context));

$pages->uncacheAll();
$after = fn(string $value) => (string) $pages->get($ids[$value])->getUnformatted('pm_color')->value;
T::eq('red', $after('red'), 'value red is kept');
T::eq('green', $after('green'), 'value green is kept');
T::eq('green', (string) $pages->get($other->id)->getUnformatted('pm_color')->value, 'the value on the other template is kept');
T::eq('', $after(''), 'an empty value stays empty');
T::note('"Blue" (matches a title, not a value) becomes "' . $after('Blue') . '"');
T::note('"2" (matches an option id) becomes "' . $after('2') . '"');
$purple = $after('purple');
T::ok($purple !== '' || str_contains($messages . implode("\n", T::notices()), 'purple'), 'a value without an option ("purple") is kept or reported, not lost silently', 'value after: "' . $purple . '"');

T::section('Again: already an options field');
$id = $fields->get('pm_color')->id;
T::noThrow(fn() => step('$this->convertField("pm_color", "FieldtypeOptions", "Color", ["options" => ["red" => "Red", "green" => "Green", "blue" => "Blue"]]);'), 'convertField() again');
T::eq($id, $fields->get('pm_color')->id, 'the field is not recreated');
$pages->uncacheAll();
T::eq('red', $after('red'), 'the values stay');

T::section('Field that does not exist');
T::noThrow(fn() => step('$this->convertField("pm_new", "FieldtypeOptions", "New", ["options" => ["a" => "A"]]);'), 'convertField() of a missing field creates it');
T::eq('FieldtypeOptions', $fields->get('pm_new')?->type->className(), 'with the type');

T::section('Field that is in no template');
step('$this->ensureField("pm_loose", "FieldtypeText", "Loose");');
T::noThrow(fn() => step('$this->convertField("pm_loose", "FieldtypeTextarea", "Loose");'), 'convertField() of a field without fieldgroups');
T::eq('FieldtypeTextarea', $fields->get('pm_loose')?->type->className(), 'converted');

T::section('Field with multiple values (no pages_id key)');
step('$this->ensureField("pm_multi", "FieldtypeOptions", "Multi", ["options" => ["a" => "A", "b" => "B"], "inputfieldClass" => "InputfieldCheckboxes"]);');
step('$this->ensureTemplate("pm_other", ["pm_multi"]);');
$other = $pages->get($other->id);
$other->of(false);
$other->pm_multi = 'a|b';
$other->save();
$pages->uncacheAll();
T::eq(2, $pages->get($other->id)->getUnformatted('pm_multi')->count(), 'setup: two values');
T::noThrow(fn() => step('$this->convertField("pm_multi", "FieldtypeText", "Multi as text");'), 'convertField() of a multi value field to text');
$pages->uncacheAll();
T::note('multi value a|b as text: "' . $pages->get($other->id)->getUnformatted('pm_multi') . '" (FETCH_KEY_PAIR keeps one row per page)');

T::section('Field type that does not exist');
step('$this->ensureField("pm_keep", "FieldtypeText", "Keep");');
step('$this->ensureTemplate("pm_other", ["pm_keep"]);');
$other = $pages->get($other->id);
$other->of(false);
$other->pm_keep = 'precious';
$other->save();
T::throws(fn() => step('$this->convertField("pm_keep", "FieldtypeDoesNotExist", "Keep");'), null, 'convertField() to a missing type throws');
$pages->uncacheAll();
$keep = $fields->get('pm_keep');
T::ok($keep instanceof Field, 'the field still exists (deleted only after the new type is known)', 'the field pm_keep was deleted');
T::eq('precious', $keep ? (string) $pages->get($other->id)->getUnformatted('pm_keep') : '', 'the value is not lost');

T::done();
