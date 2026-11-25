<?php namespace ProcessWire;

/**
 * MigrationStep::ensureField() and setOptions()
 *
 * The helpers are protected: they are called through a migration, like in real use.
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$dir = T::dir();
$fields = T::wire()->fields;

/**
 * Run $code as up() of the next migration
 */
function step(string $code): void {
	static $version = 0;
	$version++;
	T::migration($version, $code);
	T::wire()->modules->get('ProcessMigrations')->runVersion($version);
}

T::section('Create and update');
T::noThrow(fn() => step('$this->ensureField("pm_text", "FieldtypeText", "Text", ["maxlength" => 80, "description" => "Hello"]);'), 'create a text field');
$field = $fields->get('pm_text');
T::ok($field instanceof Field, 'the field exists');
T::eq('FieldtypeText', $field?->type->className(), 'with the type');
T::eq('Text', $field?->label, 'with the label');
T::eq(80, (int) $field?->get('maxlength'), 'with the settings');
T::ok(T::tableExists('field_pm_text'), 'its table exists');
$id = $field?->id;

T::noThrow(fn() => step('$this->ensureField("pm_text", "FieldtypeText", "Text 2", ["maxlength" => 120]);'), 'ensureField() again with other label and settings');
$field = $fields->get('pm_text');
T::eq($id, $field->id, 'the same field (not recreated)');
T::eq('Text 2', $field->label, 'the label is updated');
T::eq(120, (int) $field->get('maxlength'), 'the settings are updated');
T::eq('Hello', $field->get('description'), 'settings not given stay');
T::eq(1, $fields->find('name=pm_text')->count(), 'still one field');

T::section('Existing field of another type');
T::notices();
T::noThrow(fn() => step('$this->ensureField("pm_text", "FieldtypeTextarea", "Textarea");'), 'ensureField() with another type does not throw');
T::contains('Field pm_text is FieldtypeText, not FieldtypeTextarea: left unchanged', implode("\n", T::notices()), 'it warns');
T::eq('FieldtypeText', $fields->get('pm_text')->type->className(), 'the type is unchanged');
T::eq('Text 2', $fields->get('pm_text')->label, 'the label is unchanged, too');

T::section('Core field');
T::noThrow(fn() => step('$this->ensureField("title", "FieldtypePageTitle", "Headline");'), 'ensureField() on the core field title');
T::eq('Headline', $fields->get('title')->label, 'the label of title is changed');

T::section('Field type that does not exist');
$e = T::throws(fn() => step('$this->ensureField("pm_nope", "FieldtypeDoesNotExist", "Nope");'), null, 'a missing field type throws');
T::note('message: ' . ($e ? $e->getMessage() : '-'));
T::ok(!$fields->get('pm_nope'), 'no field is created');
T::ok(!T::tableExists('field_pm_nope'), 'no table is created');

T::section('Field type of a module that is not installed');
$installed = T::wire()->modules->isInstalled('FieldtypeOptions');
T::ok(!$installed, 'FieldtypeOptions is not installed yet');
$e = null;
try {
	step('$this->ensureField("pm_auto", "FieldtypeOptions", "Auto", ["options" => ["a" => "A"]]);');
} catch(\Throwable $e) {}
T::note('ensureField() with the not installed FieldtypeOptions: ' . ($e ? 'throws: ' . $e->getMessage() : 'works, installed: ' . var_export(T::wire()->modules->isInstalled('FieldtypeOptions'), true)));

T::section('Invalid field names');
T::throws(fn() => step('$this->ensureField("", "FieldtypeText", "Empty");'), null, 'an empty name throws');
$e = null;
try { step('$this->ensureField("pm text!", "FieldtypeText", "Spaces");'); } catch(\Throwable $e) {}
T::note('name "pm text!": ' . ($e ? 'throws' : 'sanitized, fields now: ' . implode(', ', array_filter($fields->getAll()->explode('name'), fn($n) => str_starts_with($n, 'pm')))) . ' (a second run would not find it by the given name)');
T::throws(fn() => step('$this->ensureField("sort", "FieldtypeText", "Reserved");'), null, 'a reserved name (sort) throws');

T::section('Options');
T::noThrow(fn() => step('$this->ensureModule("FieldtypeOptions"); $this->ensureField("pm_color", "FieldtypeOptions", "Color", ["options" => ["red" => "Red", "green" => "Green"]]);'), 'create an options field');
$options = fn() => array_map(fn($o) => "$o->id=$o->value|$o->title", $fields->get('pm_color')->type->getOptions($fields->get('pm_color'))->getArray());
T::eq(['1=red|Red', '2=green|Green'], array_values($options()), 'the options are set with ids 1, 2');
T::eq('InputfieldSelect', $fields->get('pm_color')->get('inputfieldClass'), 'the input is a select');

T::noThrow(fn() => step('$this->ensureField("pm_color", "FieldtypeOptions", "Color", ["options" => ["red" => "Red", "green" => "Green", "blue" => "Blue"]]);'), 'append an option');
T::eq(['1=red|Red', '2=green|Green', '3=blue|Blue'], array_values($options()), 'the new option gets id 3, the others keep theirs');

$home = T::wire()->pages->get('/');
$home->template->fieldgroup->add('pm_color');
$home->template->fieldgroup->save();
$home->of(false);
$home->pm_color = 'green';
$home->save('pm_color');
T::wire()->pages->uncacheAll();
T::eq('green', (string) T::wire()->pages->get('/')->getUnformatted('pm_color')->value, 'a page has the value green');

T::noThrow(fn() => step('$this->ensureField("pm_color", "FieldtypeOptions", "Color", ["options" => ["green" => "Green", "blue" => "Blue"]]);'), 'remove the first option');
T::wire()->pages->uncacheAll();
$value = (string) T::wire()->pages->get('/')->getUnformatted('pm_color')->value;
T::note('after removing the first option: options ' . implode('; ', $options()) . ", the page that had green has \"$value\" (documented: only append options)");

T::noThrow(fn() => step('$this->ensureField("pm_color", "FieldtypeOptions", "Color", ["options" => ["0" => "Zero", "a|b" => "Pipe", "x=y" => "Equals"]]);'), 'options with 0, a pipe and an equals sign');
T::note('stored: ' . implode('; ', $options()));

T::noThrow(fn() => step('$this->ensureField("pm_color", "FieldtypeOptions", "Color", ["options" => []]);'), 'an empty options array');
T::note('options after an empty array: ' . count($options()) . ' (setOptionsString with "" removes all options and their values)');

T::section('Options on a field that is not an options field');
T::throws(fn() => step('$this->ensureField("pm_text", "FieldtypeText", "Text", ["options" => ["a" => "A"]]);'), null, 'options on a text field throw');
T::ok($fields->get('pm_text')->get('inputfieldClass') !== 'InputfieldSelect', 'the failed call leaves the text field unchanged (no InputfieldSelect saved)', 'inputfieldClass: ' . var_export($fields->get('pm_text')->get('inputfieldClass'), true));

T::done();
