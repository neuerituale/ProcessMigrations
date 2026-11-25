<?php namespace ProcessWire;

/**
 * One step of the schema / data migration (<migrations path>/Migration_<version>.php)
 *
 * Schema changes in MySQL commit implicitly, so a migration can't be rolled back:
 * write every step idempotent, a failed migration is simply run again.
 */
abstract class MigrationStep extends Wire {

	/**
	 * Set by migrations whose result is only available in a new request, e.g. the $languages API variable
	 * after installing LanguageSupport. The remaining migrations then run on the next call.
	 */
	public bool $needsNewRequest = false;

	/**
	 * What the migration does (shown in Setup → Migrations)
	 */
	abstract public static function description(): string;

	abstract public function up(): void;

	/**
	 * Create a field or update label and settings. An existing field of another type is left alone (see convertField()).
	 *
	 * @param array $settings field settings, 'options' => [value => title] for FieldtypeOptions
	 */
	protected function ensureField(string $name, string $type, string $label, array $settings = []): Field {
		$fields = $this->wire()->fields;
		$field = $fields->get($name);
		if($field && $field->type->className() !== $type) {
			$this->warning("Field $name is " . $field->type->className() . ", not $type: left unchanged");
			return $field;
		}
		if(!$field) {
			$field = new Field();
			$field->type = $this->wire()->modules->get($type);
			$field->name = $name;
		}
		$options = $settings['options'] ?? null;
		unset($settings['options']);
		$field->label = $label;
		if($options !== null) $field->set('inputfieldClass', 'InputfieldSelect');
		foreach($settings as $key => $value) $field->set($key, $value);
		$fields->save($field);

		if($options !== null) $this->setOptions($field, $options);
		return $field;
	}

	/**
	 * Options of a FieldtypeOptions field: [value => title]. The option ids are the positions, so only append new values.
	 */
	protected function setOptions(Field $field, array $options): void {
		$lines = [];
		$id = 0;
		foreach($options as $value => $title) $lines[] = ++$id . "=$value|$title";
		$field->type->get('manager')->setOptionsString($field, implode("\n", $lines));
	}

	/**
	 * Change the type of a field that ProcessWire can't convert (e.g. text to options):
	 * remember the values, recreate the field at the same position in its fieldgroups and write the values back.
	 * Does nothing if the field already has the type.
	 */
	protected function convertField(string $name, string $type, string $label, array $settings = []): void {
		$fields = $this->wire()->fields;
		$database = $this->wire()->database;
		$field = $fields->get($name);
		if(!$field || $field->type->className() === $type) {
			$this->ensureField($name, $type, $label, $settings);
			return;
		}

		$values = $database->query('SELECT pages_id, data FROM `' . $database->escapeTable($field->getTable()) . '`')->fetchAll(\PDO::FETCH_KEY_PAIR);

		// position in every fieldgroup: the field before it
		$positions = [];
		foreach($this->wire()->fieldgroups as $fieldgroup) {
			if(!$fieldgroup->hasField($field)) continue;
			$names = array_values($fieldgroup->explode('name'));
			$index = array_search($name, $names);
			$positions[$fieldgroup->name] = $index > 0 ? $names[$index - 1] : null;
			$fieldgroup->remove($field);
			$fieldgroup->save();
		}
		$fields->delete($field);

		$field = $this->ensureField($name, $type, $label, $settings);

		foreach($positions as $fieldgroupName => $after) {
			$fieldgroup = $this->wire()->fieldgroups->get($fieldgroupName);
			if($after && $fieldgroup->hasField($after)) {
				$fieldgroup->insertAfter($field, $fieldgroup->getField($after));
			} else {
				$fieldgroup->add($field);
			}
			$fieldgroup->save();
		}

		$pages = $this->wire()->pages;
		foreach($values as $pageId => $value) {
			$page = $pages->get((int) $pageId);
			if(!$page->id || (string) $value === '') continue;
			$page->of(false);
			$page->set($name, (string) $value);
			$page->save($name, ['quiet' => true]);
		}
		$this->message("Field $name converted to $type (" . count($values) . ' values)');
	}

	/**
	 * Create a template (with its own fieldgroup) or add missing fields and apply settings
	 *
	 * @param array $settings template settings, 'parentTemplates' / 'childTemplates' as template names, 'noFile' => 0 for templates with a file
	 */
	protected function ensureTemplate(string $name, array $fieldNames, array $settings = []): Template {
		$templates = $this->wire()->templates;
		$template = $templates->get($name);
		if(!$template) {
			$fieldgroup = new Fieldgroup();
			$fieldgroup->name = $name;
			$this->wire()->fieldgroups->save($fieldgroup);
			$template = new Template();
			$template->name = $name;
			$template->fieldgroup = $fieldgroup;
			$templates->save($template);
		}

		$fieldgroup = $template->fieldgroup;
		foreach($fieldNames as $fieldName) {
			if(!$fieldgroup->hasField($fieldName)) $fieldgroup->add($fieldName);
		}
		$fieldgroup->save();

		foreach(['parentTemplates', 'childTemplates'] as $key) {
			if(!isset($settings[$key])) continue;
			$ids = [];
			foreach($settings[$key] as $templateName) {
				$related = $templates->get($templateName);
				if($related) $ids[] = $related->id;
			}
			$template->set($key, $ids);
			unset($settings[$key]);
		}
		// templates without a file by default (data only); pages that render pass 'noFile' => 0
		$template->set('noFile', 1);
		foreach($settings as $key => $value) $template->set($key, $value);
		$templates->save($template);
		return $template;
	}

	/**
	 * Install a module (e.g. a core fieldtype) if it isn't yet
	 */
	protected function ensureModule(string $name): void {
		$modules = $this->wire()->modules;
		if($modules->isInstalled($name)) return;
		if(!$modules->isInstallable($name)) $modules->refresh();
		// a module that came with this deployment is found by refresh() only in the next request: point to its file
		if(!$modules->isInstallable($name)) {
			foreach(["$name.module.php", "$name.module"] as $fileName) {
				$file = $this->wire()->config->paths->siteModules . "$name/$fileName";
				if(!is_file($file)) continue;
				$modules->installableFile($name, $file);
				require_once $file;
				break;
			}
		}
		if(!$modules->install($name)) throw new WireException("Module $name could not be installed");
		// otherwise the next request still takes the module for uninstalled
		$modules->refresh();
		$this->message("Installed $name");
	}

	/**
	 * Add a column to a custom table if it doesn't exist yet
	 *
	 * @param string $definition column definition, e.g. 'TEXT NULL AFTER title'
	 */
	protected function ensureColumn(string $table, string $column, string $definition): void {
		$database = $this->wire()->database;
		$table = $database->escapeTable($table);
		$column = $database->escapeCol($column);
		$query = $database->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
		$query->execute([':column' => $column]);
		if($query->rowCount()) return;
		$database->exec("ALTER TABLE `$table` ADD `$column` $definition");
		$this->message("Column $table.$column added");
	}

}
