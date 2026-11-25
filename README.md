# ProcessMigrations

## What it does
Schema and data changes of a ProcessWire site (fields, templates, modules, custom tables, content) are written as small PHP classes, the migrations. Every migration has a version number and runs once, in order. Which versions ran is stored in the database, so the state survives deployments: you deploy the code, the pending migrations run, and every environment (local, staging, production) ends up with the same schema.

The migrations live **outside of the module directory** (default: `/site/migrations/`), so updating or reinstalling the module never touches them.

## Features
- Overview of all migrations with their state in Setup → Migrations
- Run all pending migrations or a single one (again) from the admin
- Run them from a cron job, a deploy script or the command line (`migrate.php`)
- Auto mode for sites without a deploy pipeline: pending migrations run after a request, at the cost of one `glob()` per request
- A database lock keeps two runs from migrating at the same time
- Every run is logged (Setup → Logs → migrations)
- Helpers for idempotent migrations: `ensureField()`, `ensureTemplate()`, `ensureModule()`, `convertField()`, `ensureColumn()`
- Migrations that need a fresh request (e.g. after installing `LanguageSupport`) can say so, the remaining ones run on the next call

## Install
1. Copy the files of this module to `/site/modules/ProcessMigrations/`
2. In admin: Modules → Refresh. Install ProcessMigrations.
3. The directory `/site/migrations/` is created if it doesn't exist. Change it in the module settings if you like.
4. Go to Setup → Migrations

## Install via composer
1. Execute the following command in your website root directory.
   ```bash
   composer require nr/processmigrations
   ```

## Permission
The module creates the permission `migrations`. Don't give it to any role: migrations change the schema and install modules, that's a job for superusers (who have every permission anyway).

## Migrations directory
The directory is set in the module settings, relative to the ProcessWire root or as an absolute path. Default: `site/migrations/`.

To keep it in the code instead of the database, set it in `/site/config.php`. It then takes precedence over the module setting:

```php
$config->migrationsPath = 'site/migrations/';
```

Keep the directory in your version control, it's part of your code.

## Write a migration
A migration is a file `Migration_<version>.php` in the migrations directory with the class `Migration_<version>` in the `ProcessWire` namespace, extending `MigrationStep`. The version is a positive integer: count up (`1`, `2`, `3` …) or, in a team with parallel branches, use a timestamp (`202611251430`) to avoid conflicts. Migrations run in ascending order of their version. Two files with the same version (`Migration_2.php`, `Migration_02.php`) are an error: the second one is ignored with a warning. A file with a syntax error stops every run until it is fixed, so no later migration runs before it.

```php
<?php namespace ProcessWire;

// /site/migrations/Migration_1.php
class Migration_1 extends MigrationStep {

	public static function description(): string {
		return 'Products: fields and template';
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
```

A copy of this example is in `example/Migration_1.php`.

`description()` is shown in the admin and written to the log. `up()` does the work. A migration is a `Wire` object, so all API variables are available via `$this->wire()`.

### Idempotent, no rollback
Schema changes in MySQL commit implicitly, so a failed migration can't be rolled back. Write every migration **idempotent**: running it twice must give the same result as running it once. A failed migration is then simply fixed and run again. The helpers below follow this rule.

There is no `down()`: to undo a change, write a new migration.

### Assets
Files a migration needs (images, JSON, SQL …) are best placed next to it, e.g. in `/site/migrations/assets/`, and referenced with `__DIR__ . '/assets/…'`.

### Helpers

| Method | What it does |
| --- | --- |
| `ensureField($name, $type, $label, $settings = [])` | Creates a field or updates its label and settings. `$settings['options']` (`[value => title]`) sets the options of a `FieldtypeOptions` field. A field of another type is left alone with a warning. An unknown fieldtype, an invalid name or `options` for another fieldtype throw before anything is saved. |
| `setOptions($field, $options)` | Options of a `FieldtypeOptions` field. The option ids are the positions: only append new values. |
| `convertField($name, $type, $label, $settings = [])` | Changes the type of a field ProcessWire can't convert itself (e.g. text to options): keeps the values, the position and the context settings in all fieldgroups. Values the new type doesn't accept (e.g. a text without a matching option) are listed in a warning. An unknown fieldtype throws before the old field is deleted. |
| `ensureTemplate($name, $fieldNames, $settings = [])` | Creates a template with its own fieldgroup or adds missing fields (never removes any). `parentTemplates` / `childTemplates` are given as template names, unknown names give a warning. Unknown fields throw before anything is saved. New templates are created without a file (`noFile`), pass `'noFile' => 0` for templates that render; existing templates keep their `noFile`. |
| `ensureModule($name)` | Installs a module, also one that came with the same deployment. |
| `ensureColumn($table, $column, $definition)` | Adds a column to a custom table, e.g. `ensureColumn('orders', 'note', 'TEXT NULL AFTER total')`. |

### Needs a new request
Some changes are only available in the next request, e.g. the `$languages` API variable after installing `LanguageSupport`, or new fieldtypes. Set `$this->needsNewRequest = true;` at the end of `up()`: `runPending()` stops after this migration, the remaining ones run on the next call. `migrate.php` starts a new process for them by itself, the auto mode in the next request.

## Run the migrations
Pick the way that fits your hosting. Best is a deploy script (`migrate.php`): an error stops the deployment right away. Without one, use the auto mode or a cron job.

### Admin
Setup → Migrations lists all migrations. *Run pending migrations* runs the pending ones in order, *Run* / *Run again* a single one.

### Auto mode
For sites without a deploy pipeline: upload the files, open the site, done. Set it in the module settings:

| Setting | Pending migrations run … |
| --- | --- |
| Off (default) | only from the admin, `migrate.php`, a cron job or the API |
| Superuser | when a superuser opens any page, admin or frontend (recommended) |
| Always | on any request, also of guests. The migrations then run as superuser. |

Or in `/site/config.php`, which takes precedence over the module setting:

```php
$config->migrationsAutoRun = 'superuser'; // '', 'superuser' or 'always'
```

How it works:
- The migrations run in `ProcessWire::finished`, after the page is rendered. The schema doesn't change under the running request, and an error doesn't break the page the visitor already got.
- **Cost per request:** one `glob()` on the migrations directory. The module keeps a signature of the migration files (names and modification times) of the last run without pending migrations. As long as it matches, there is no database query and no migration file is loaded. Only when a file is added, removed or changed, the module looks into the database once.
- A failed migration is logged and not tried again until a migration file changes (fix it and upload it) or it runs successfully in the admin or with `migrate.php`. Superusers see the error in the admin.
- Superusers see in the admin which migrations ran automatically.
- The lock keeps parallel requests from migrating at the same time; they just skip.
- The request that runs the migrations takes longer. With *Superuser* that's you, with *Always* it's the first visitor after a deployment.
- The auto mode never runs on the command line, use `migrate.php` there.

### Command line / deploy script
```bash
php site/modules/ProcessMigrations/migrate.php            # run all pending migrations
php site/modules/ProcessMigrations/migrate.php --list     # list all migrations and their state
php site/modules/ProcessMigrations/migrate.php --run=3    # run migration 3 (again)
php site/modules/ProcessMigrations/migrate.php --once     # don't start a new process after "needs a new request"
```

The script bootstraps ProcessWire from the root `index.php`, runs as superuser, installs the module if necessary and exits with `1` on errors, so a deploy pipeline stops.

### Cron job
With [ProcessCronJobs](https://github.com/neuerituale/ProcessCronJobs):

```php
wire()->addHookBefore('ProcessCronJobs::register', function(HookEvent $event) {
	/** @var ProcessCronJobs $processCronJobs */
	$processCronJobs = $event->object;
	$processCronJobs->add(
		'RunMigrations',
		function() {
			wire()->modules->get('ProcessMigrations')->runPending();
		},
		// as superuser: installing modules and changing the schema need it
		['lazyCron' => 'LazyCron::everyMinute', 'user' => wire()->config->superUserPageID]
	);
});
```

`runPending()` is cheap when nothing is pending, it only compares the files with the table and doesn't take the lock.

### API
```php
/** @var ProcessMigrations $migrations */
$migrations = $modules->get('ProcessMigrations');

$migrations->runPending();          // run pending migrations, returns the executed versions
$migrations->runVersion(3);         // run migration 3 (again)
$migrations->migrations();          // [version => class name] of all migrations
$migrations->executed();            // [version => timestamp] of the executed ones
$migrations->pending();             // [version => class name] of the pending ones
$migrations->getMigrationsPath();   // absolute path of the migrations directory
$migrations->getAutoRun();          // auto mode: '', 'superuser' or 'always'
```

`runPending()` and `runVersion()` throw the exception of a failed migration. `runPending()` returns an empty array without doing anything if another run holds the lock; `runVersion()` throws in that case.

### Hooks
- `ProcessMigrations::run(int $version, string $class)` – runs one migration, e.g. to clear caches after it
- `ProcessMigrations::getMigrationsPath()` – change the migrations directory at runtime

## Uninstall
Uninstalling drops the table `process_migrations` with the executed versions. The migration files stay. After a new install all migrations are pending again – they are idempotent, so running them again is safe.

## Tests
Integration tests against the ProcessWire installation the module lives in (each case in its own PHP process):

```bash
php site/modules/ProcessMigrations/tests/run.php            # all cases
php site/modules/ProcessMigrations/tests/run.php field cli  # cases whose file name contains "field" or "cli"
```

**Development installations only:** the cases change fields, templates, modules and pages. The runner copies all tables into `<dbName>_pmtest_snapshot` first and restores them after every case; an aborted run is restored on the next start. The HTTP cases (auto mode, admin) need the site reachable at `https://<first httpHost>` or `PM_TEST_URL`, otherwise they are skipped. `NOTE` lines are observations, not failures.
