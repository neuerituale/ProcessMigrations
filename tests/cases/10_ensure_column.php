<?php namespace ProcessWire;

/**
 * MigrationStep::ensureColumn()
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
T::dir();
$database = T::wire()->database;

function step(string $code): void {
	static $version = 0;
	$version++;
	T::migration($version, $code);
	T::wire()->modules->get('ProcessMigrations')->runVersion($version);
}

$columns = function(string $table) use($database): array {
	return $database->query("SHOW COLUMNS FROM `$table`")->fetchAll(\PDO::FETCH_COLUMN);
};

$database->exec('CREATE TABLE pm_custom (id INT UNSIGNED NOT NULL PRIMARY KEY, title VARCHAR(100), abc INT)');

T::section('Add');
T::noThrow(fn() => step('$this->ensureColumn("pm_custom", "note", "TEXT NULL AFTER title");'), 'add a column');
T::eq(['id', 'title', 'note', 'abc'], $columns('pm_custom'), 'the column is added after title');
T::noThrow(fn() => step('$this->ensureColumn("pm_custom", "note", "TEXT NULL AFTER title");'), 'again');
T::eq(['id', 'title', 'note', 'abc'], $columns('pm_custom'), 'not added twice');

T::section('LIKE wildcards in the column name');
T::noThrow(fn() => step('$this->ensureColumn("pm_custom", "a_c", "INT NULL");'), 'add a_c while abc exists');
T::ok(in_array('a_c', $columns('pm_custom'), true), 'a_c is added (_ is no wildcard)', 'columns: ' . implode(', ', $columns('pm_custom')));

T::section('Errors');
T::throws(fn() => step('$this->ensureColumn("pm_does_not_exist", "x", "INT");'), null, 'a missing table throws');
T::throws(fn() => step('$this->ensureColumn("pm_custom", "bad", "NOT A TYPE");'), null, 'an invalid definition throws');
T::ok(!in_array('bad', $columns('pm_custom'), true), 'nothing added');

T::section('Names that need escaping');
$e = null;
try { step('$this->ensureColumn("pm_custom", "my-col", "INT NULL");'); } catch(\Throwable $e) {}
T::note('column "my-col": ' . ($e ? 'throws: ' . $e->getMessage() : 'columns now: ' . implode(', ', $columns('pm_custom'))));
$e = null;
try { step('$this->ensureColumn("pm_custom", "x`; DROP TABLE pm_custom; --", "INT NULL");'); } catch(\Throwable $e) {}
T::ok(T::tableExists('pm_custom'), 'a column name with SQL in it does not drop the table');

T::section('Field table');
step('$this->ensureField("pm_text", "FieldtypeText", "Text");');
T::noThrow(fn() => step('$this->ensureColumn("field_pm_text", "extra", "INT NULL");'), 'add a column to a field table');
T::ok(in_array('extra', $columns('field_pm_text'), true), 'added');

T::done();
