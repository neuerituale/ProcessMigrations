<?php namespace ProcessWire;

/**
 * The example migration of the module runs on a fresh site, twice
 */

require dirname(__DIR__) . '/lib.php';

$module = T::module();
$dir = T::dir();
copy(dirname(__DIR__, 2) . '/example/Migration_1.php', $dir . 'Migration_1.php');

T::eq([1], T::noThrow(fn() => $module->runPending(), 'the example runs'), 'version 1 executed');
$wire = T::wire();
T::eq('FieldtypeOptions', $wire->fields->get('color')?->type->className(), 'field color');
T::eq(3, $wire->fields->get('color')?->type->getOptions($wire->fields->get('color'))->count(), 'with 3 options');
T::eq('FieldtypeDecimal', $wire->fields->get('price')?->type->className(), 'field price');
$product = $wire->templates->get('product');
T::eq(['title', 'color', 'price'], $product ? array_values($product->fieldgroup->explode('name')) : [], 'template product with the fields');
T::eq(0, (int) $product?->noFile, 'product has a file');
T::noThrow(fn() => $module->runVersion(1), 'the example runs again (idempotent)');
T::eq(1, $wire->fields->find('name=color')->count(), 'still one field color');

$page = $wire->pages->add('product', '/', ['title' => 'Shirt', 'color' => 'blue', 'price' => 19.9]);
$wire->pages->uncacheAll();
$page = $wire->pages->get($page->id);
T::eq('blue', (string) $page->getUnformatted('color')->value, 'a product with color blue');
T::eq(19.9, (float) $page->price, 'and price 19.90');

T::done();
