<?php namespace ProcessWire;

/**
 * Example migration: copy it to your migrations directory (default /site/migrations/) and adapt it
 */
class Migration_1 extends MigrationStep {

	public static function description(): string {
		return 'Example: options field "color" and template "product"';
	}

	public function up(): void {
		$this->ensureModule('FieldtypeOptions');

		$this->ensureField('color', 'FieldtypeOptions', 'Color', [
			'options' => ['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue'],
		]);
		$this->ensureField('price', 'FieldtypeDecimal', 'Price', ['digits' => 10, 'precision' => 2]);

		$this->ensureTemplate('product', ['title', 'color', 'price'], [
			'noFile' => 0,
			'parentTemplates' => ['home'],
		]);
	}

}
