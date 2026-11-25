<?php namespace ProcessWire;

/**
 * Schema and data migrations in single steps (Migration_<version>.php in the migrations directory)
 *
 * The migrations live outside the module directory (default: /site/migrations/), so the module
 * can be updated without touching them. Pending migrations run in order: manually in
 * Setup → Migrations, by a cron job, a deploy script, the CLI (migrate.php) or the auto mode, all of them via runPending().
 * Which versions ran is stored in self::table, so it survives deployments.
 * A lock keeps two runs from migrating at the same time.
 *
 * Auto mode (for sites without a deploy pipeline): after a request is rendered, pending migrations run
 * (ProcessWire::finished). A signature of the migration files (names and modification times) is kept in the
 * module config: as long as it matches, a request costs one glob() and no database query.
 *
 * @property string $migrationsPath directory of the migrations, relative to the ProcessWire root or absolute
 * @property string $autoRun self::autoRunOff, self::autoRunSuperuser or self::autoRunAlways
 * @property string $signature signature of the migration files when nothing was pending
 * @property array $autoFailed failed auto run: signature, version, error, time
 * @property array $autoDone versions executed by the auto mode, not yet shown to a superuser
 */
class ProcessMigrations extends Process implements Module, ConfigurableModule {

	const table = 'process_migrations';
	const lockName = 'process-migrations';
	const logName = 'migrations';
	const permissionName = 'migrations';
	const defaultPath = 'site/migrations/';

	const autoRunOff = '';
	const autoRunSuperuser = 'superuser';
	const autoRunAlways = 'always';

	/** @var bool whether the table was checked in this request */
	private bool $tableChecked = false;

	/** @var \Throwable[] migration files that could not be loaded in this request: [file => error] */
	private array $loadErrors = [];

	/** @var int|null version of the migration that runs right now */
	private ?int $running = null;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Process Migrations',
			'version' => 101,
			'summary' => 'Run schema and data migrations step by step, from the admin, a cron job or the CLI.',
			'author' => 'Neue Rituale',
			'href' => 'https://github.com/neuerituale/ProcessMigrations',
			'icon' => 'database',
			'singular' => true,
			// for the auto mode; does nothing else when it's off
			'autoload' => true,
			'permission' => self::permissionName,
			'permissions' => [
				self::permissionName => 'Run migrations (recommended for superusers only)',
			],
			'page' => [
				'name' => 'migrations',
				'parent' => 'setup',
				'title' => 'Migrations',
			],
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1'],
		];
	}

	public function __construct() {
		parent::__construct();
		$this->set('migrationsPath', self::defaultPath);
		$this->set('autoRun', self::autoRunOff);
		$this->set('signature', '');
		$this->set('autoFailed', []);
		$this->set('autoDone', []);
	}

	public function init(): void {
		parent::init();
		// init() may be called again when the Process runs in the admin: hook only once
		static $hooked = false;
		if($hooked || $this->getAutoRun() === self::autoRunOff || $this->wire()->config->cli) return;
		$hooked = true;
		$this->addHookAfter('ProcessWire::finished', $this, 'hookFinished');
	}

	public function ready(): void {
		if($this->getAutoRun() === self::autoRunOff) return;
		$user = $this->wire()->user;
		$page = $this->wire()->page;
		if(!$user->isSuperuser() || !$page || $page->template->name !== 'admin') return;

		// tell superusers in the admin what the auto mode did
		$url = $this->wire()->config->urls->admin . 'setup/migrations/';
		if(count($this->autoDone)) {
			$this->message(sprintf($this->_('Migrations executed automatically: %s'), implode(', ', $this->autoDone)));
			$this->saveState('autoDone', []);
		}
		if(count($this->autoFailed)) {
			$this->error(sprintf(
				$this->_('Migration %1$s failed in the auto mode: %2$s. It runs again as soon as a migration file changes, or run it in %3$s.'),
				$this->autoFailed['version'] ?? '?',
				$this->wire()->sanitizer->entities($this->autoFailed['error'] ?? ''),
				"<a href='$url'>Setup → Migrations</a>"
			), Notice::allowMarkup);
		}
	}

	/**
	 * Auto mode: run pending migrations after the request is rendered
	 *
	 * Runs in ProcessWire::finished, so the schema doesn't change under the current request
	 * and an error doesn't break the page the visitor already got.
	 */
	protected function hookFinished(): void {
		$users = $this->wire()->users;
		$mode = $this->getAutoRun();
		$user = $this->wire()->user;
		if($mode === self::autoRunSuperuser && !$user->isSuperuser()) return;

		// cheap: unchanged files and nothing was pending last time
		$signature = $this->signature();
		if($signature === $this->signature) return;
		// failed with exactly these files: don't try again in every request
		if(($this->autoFailed['signature'] ?? null) === $signature) return;

		ignore_user_abort(true);
		if(function_exists('set_time_limit')) @set_time_limit(0);
		// installing modules and changing the schema need a superuser
		$switchUser = !$user->isSuperuser();
		if($switchUser) $users->setCurrentUser($users->get($this->wire()->config->superUserPageID));
		try {
			$done = $this->runPending();
			if(count($done)) $this->saveState('autoDone', array_merge($this->autoDone, $done));
			if(count($this->autoFailed)) $this->saveState('autoFailed', []);
			// remember the files only when all ran; otherwise (lock, needsNewRequest) the next request goes on
			if(!count($this->pending())) $this->saveState('signature', $signature);
		} catch(\Throwable $e) {
			$this->saveState('autoFailed', [
				'signature' => $signature,
				'version' => $this->failedVersion($e),
				'error' => $e->getMessage(),
				'time' => time(),
			]);
		} finally {
			if($switchUser) $users->setCurrentUser($user);
		}
	}

	/**
	 * Version of the migration that caused $e: the one running, the file that failed to load or the first pending one
	 */
	private function failedVersion(\Throwable $e): string {
		if($this->running !== null) return (string) $this->running;
		if(preg_match('/Migration_(\d+)\.php$/', $e->getFile(), $matches)) return (string) (int) $matches[1];
		try {
			return (string) array_key_first($this->pending());
		} catch(\Throwable) {
			return '?';
		}
	}

	/**
	 * Auto mode, $config->migrationsAutoRun (e.g. in config.php) takes precedence over the module setting
	 */
	public function getAutoRun(): string {
		$mode = $this->wire()->config->get('migrationsAutoRun');
		if($mode === null) $mode = $this->autoRun;
		if($mode === true) $mode = self::autoRunAlways;
		return in_array($mode, [self::autoRunSuperuser, self::autoRunAlways], true) ? $mode : self::autoRunOff;
	}

	/**
	 * Names and modification times of the migration files: changes when a migration is added, removed or edited
	 */
	private function signature(): string {
		$parts = [];
		foreach(glob($this->getMigrationsPath() . 'Migration_*.php') ?: [] as $file) $parts[] = basename($file) . ':' . filemtime($file);
		return md5(implode('|', $parts));
	}

	private function saveState(string $key, mixed $value): void {
		$this->set($key, $value);
		$this->wire()->modules->saveConfig($this, $key, $value);
	}

	public function ___install(): void {
		parent::___install();
		$this->ensureTable(true);
		$path = $this->getMigrationsPath();
		if(!is_dir($path) && !wireMkdir($path, true)) $this->warning(sprintf($this->_('Could not create the directory %s'), $path));
	}

	public function ___uninstall(): void {
		parent::___uninstall();
		// the migration files stay, only the record of executed versions is removed
		$this->wire()->database->exec('DROP TABLE IF EXISTS `' . self::table . '`');
		$this->tableChecked = false;
	}

	/* ***** Admin *** */

	public function ___execute(): string {
		$modules = $this->wire()->modules;
		$sanitizer = $this->wire()->sanitizer;
		$this->headline($this->_('Migrations'));
		$this->browserTitle($this->_('Migrations'));

		$path = $this->getMigrationsPath();
		if(!is_dir($path)) $this->warning(sprintf($this->_('The migrations directory %s does not exist'), $path));

		$migrations = $this->migrations();
		$executed = $this->executed();
		$pending = array_diff_key($migrations, $executed);

		/** @var MarkupAdminDataTable $table */
		$table = $modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->setSortable(false);
		$table->headerRow([$this->_('Version'), $this->_('Description'), $this->_('Executed'), '']);
		foreach($migrations as $version => $class) {
			$done = array_key_exists($version, $executed);
			$table->row([
				$version,
				$sanitizer->entities($class::description()),
				$done ? wireDate('Y-m-d H:i', $executed[$version]) : '<strong>' . $this->_('pending') . '</strong>',
				$this->button((string) $version, $done ? $this->_('Run again') : $this->_('Run'), true),
			]);
		}

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->action = './run/';
		$form->method = 'post';

		/** @var InputfieldMarkup $markup */
		$markup = $modules->get('InputfieldMarkup');
		$markup->label = count($pending)
			? sprintf($this->_n('%d pending migration', '%d pending migrations', count($pending)), count($pending))
			: $this->_('All migrations executed');
		$markup->description = sprintf($this->_('Migrations are read from %s. They are idempotent, running one again is safe.'), $path);
		$markup->notes = [
			self::autoRunOff => $this->_('Auto mode: off'),
			self::autoRunSuperuser => $this->_('Auto mode: pending migrations run when a superuser opens a page'),
			self::autoRunAlways => $this->_('Auto mode: pending migrations run on any request'),
		][$this->getAutoRun()];
		$markup->value = count($migrations)
			? $table->render() . (count($pending) ? '<p>' . $this->button('pending', $this->_('Run pending migrations')) . '</p>' : '')
			: '<p>' . $this->_('No migrations found.') . '</p>';
		$form->add($markup);

		return $form->render();
	}

	/**
	 * Run one migration (version) or all pending ones ("pending"), POST from execute()
	 */
	public function ___executeRun(): void {
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		if(!$input->requestMethod('POST')) $session->location('../');
		$session->CSRF->validate();

		$version = (string) $input->post('version');
		try {
			if($version === 'pending') {
				$done = $this->runPending();
				$this->message(count($done) ? sprintf($this->_('Executed: %s'), implode(', ', $done)) : $this->_('Nothing to do'));
			} else {
				$this->runVersion((int) $version);
				$this->message(sprintf($this->_('Migration %d executed'), $version));
			}
		} catch(\Throwable $e) {
			$this->error($e->getMessage());
		}
		// redirect, so a reload does not run it again
		$session->location('../');
	}

	private function button(string $value, string $label, bool $secondary = false): string {
		$sanitizer = $this->wire()->sanitizer;
		$confirm = $sanitizer->entities($this->_('Run now?'));
		return '<button type="submit" name="version" value="' . $sanitizer->entities($value) . '" ' .
			'class="ui-button ui-widget ui-corner-all ui-state-default' . ($secondary ? ' ui-priority-secondary' : '') . '" ' .
			'onclick="return confirm(\'' . $confirm . '\')"><span class="ui-button-text">' . $sanitizer->entities($label) . '</span></button>';
	}

	/* ***** Running *** */

	/**
	 * Run all pending migrations in order (stops at the first error, which is thrown)
	 *
	 * Stops early, too, when a migration sets $needsNewRequest: call it again in the next request.
	 *
	 * @return int[] executed versions, empty if nothing was pending or another run holds the lock
	 */
	public function runPending(): array {
		// cheap check without lock, a cron job may call this every minute
		if(!count($this->pending())) return [];
		$done = [];
		$this->withLock(function() use(&$done) {
			// check again, another run may have finished in the meantime
			foreach($this->pending() as $version => $class) {
				$restart = $this->run($version, $class);
				$done[] = $version;
				if($restart) break;
			}
		}, false);
		return $done;
	}

	/**
	 * Run one migration, no matter whether it ran before
	 */
	public function runVersion(int $version): void {
		$migrations = $this->migrations();
		if(!isset($migrations[$version])) throw new WireException(sprintf($this->_('Migration %d not found'), $version));
		$this->withLock(fn() => $this->run($version, $migrations[$version]));
	}

	/**
	 * @return bool whether the next migration needs a new request
	 */
	protected function ___run(int $version, string $class): bool {
		$log = $this->wire()->log;
		$this->running = $version;
		try {
			$log->save(self::logName, "Migration $version: " . $class::description());
			/** @var MigrationStep $migration */
			$migration = $this->wire(new $class());
			$migration->up();
		} catch(\Throwable $e) {
			$log->save(self::logName, "Migration $version failed: " . $e->getMessage());
			throw $e;
		} finally {
			$this->running = null;
		}
		$query = $this->wire()->database->prepare('INSERT INTO `' . self::table . '` (version, executed) VALUES(:version, NOW()) ON DUPLICATE KEY UPDATE executed=NOW()');
		$query->execute([':version' => $version]);
		$log->save(self::logName, "Migration $version done");
		// a failure of the auto mode is solved once the migration ran, wherever
		if(($this->autoFailed['version'] ?? null) === (string) $version) $this->saveState('autoFailed', []);
		return $migration->needsNewRequest;
	}

	/**
	 * Run $callback while holding the lock
	 *
	 * @param bool $throw throw if another run holds the lock (otherwise skip silently)
	 */
	private function withLock(callable $callback, bool $throw = true): void {
		// GET_LOCK is bound to the connection: always the writer connection
		$pdo = $this->wire()->database->pdo();
		$query = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
		$query->execute([':name' => self::lockName]);
		if((int) $query->fetchColumn() !== 1) {
			if($throw) throw new WireException($this->_('Another migration is running, please try again later'));
			return;
		}
		try {
			$callback();
		} finally {
			$pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute([':name' => self::lockName]);
		}
	}

	/* ***** State *** */

	/**
	 * Absolute path of the migrations directory, with trailing slash
	 *
	 * $config->migrationsPath (e.g. in config.php) takes precedence over the module setting.
	 */
	public function ___getMigrationsPath(): string {
		$config = $this->wire()->config;
		$path = trim((string) $config->get('migrationsPath'));
		if($path === '') $path = trim((string) $this->migrationsPath);
		if($path === '') $path = self::defaultPath;
		if(!str_starts_with($path, '/')) $path = $config->paths->root . ltrim($path, '/');
		return rtrim($path, '/') . '/';
	}

	/**
	 * All migrations: [version => class name], sorted by version
	 */
	public function migrations(): array {
		require_once __DIR__ . '/MigrationStep.php';
		$migrations = [];
		$files = [];
		foreach(glob($this->getMigrationsPath() . 'Migration_*.php') ?: [] as $file) {
			if(!is_file($file) || !preg_match('/Migration_(\d+)\.php$/', $file, $matches)) continue;
			$version = (int) $matches[1];
			$class = __NAMESPACE__ . '\\Migration_' . $matches[1];
			if(isset($files[$version])) {
				$this->warning(sprintf($this->_('%1$s has the same version as %2$s and is ignored'), basename($file), basename($files[$version])));
				continue;
			}
			$files[$version] = $file;
			// a file that failed (syntax error) is marked as included by PHP: fail again instead of skipping it
			if(isset($this->loadErrors[$file])) throw $this->loadErrors[$file];
			try {
				require_once $file;
			} catch(\Throwable $e) {
				$this->loadErrors[$file] = $e;
				throw $e;
			}
			if(class_exists($class, false) && is_subclass_of($class, MigrationStep::class) && !(new \ReflectionClass($class))->isAbstract()) {
				$migrations[$version] = $class;
			} else {
				$this->warning(sprintf($this->_('%1$s does not define the class %2$s extending MigrationStep'), basename($file), $class));
			}
		}
		ksort($migrations);
		return $migrations;
	}

	/**
	 * Executed migrations: [version => timestamp]
	 */
	public function executed(): array {
		$this->ensureTable();
		$rows = $this->wire()->database->query('SELECT version, executed FROM `' . self::table . '`')->fetchAll(\PDO::FETCH_KEY_PAIR);
		return array_map('strtotime', $rows);
	}

	/**
	 * Migrations not executed yet: [version => class name]
	 */
	public function pending(): array {
		return array_diff_key($this->migrations(), $this->executed());
	}

	private function ensureTable(bool $force = false): void {
		if($this->tableChecked && !$force) return;
		$this->tableChecked = true;
		$config = $this->wire()->config;
		// BIGINT: versions may be timestamps like 202611251430
		$this->wire()->database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::table . '` (version BIGINT UNSIGNED NOT NULL PRIMARY KEY, executed DATETIME NOT NULL) ' .
			"ENGINE=$config->dbEngine DEFAULT CHARSET=$config->dbCharset"
		);
	}

	/* ***** Config *** */

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields): void {
		$config = $this->wire()->config;

		/** @var InputfieldText $field */
		$field = $this->wire()->modules->get('InputfieldText');
		$field->attr('name', 'migrationsPath');
		$field->attr('value', $this->migrationsPath);
		$field->label = $this->_('Migrations directory');
		$field->description = $this->_('Relative to the ProcessWire root or absolute. Keep it outside of this module directory, so updates of the module leave your migrations alone.');
		$field->notes = sprintf($this->_('Currently used: %s'), $this->getMigrationsPath());
		if($config->get('migrationsPath')) {
			$field->notes .= "\n" . $this->_('Overridden by $config->migrationsPath.');
		}
		$inputfields->add($field);

		/** @var InputfieldRadios $field */
		$field = $this->wire()->modules->get('InputfieldRadios');
		$field->attr('name', 'autoRun');
		$field->label = $this->_('Auto mode');
		$field->description = $this->_('Run pending migrations automatically after a request is rendered. For sites without a deploy script or cron job. A request costs one look into the migrations directory as long as no migration file changes.');
		$field->addOption(self::autoRunOff, $this->_('Off: run them in Setup → Migrations, by a cron job or with migrate.php'));
		$field->addOption(self::autoRunSuperuser, $this->_('When a superuser opens any page (recommended)'));
		$field->addOption(self::autoRunAlways, $this->_('On any request, also of guests (runs as superuser)'));
		$field->attr('value', $this->autoRun);
		$field->notes = $this->_('A failed migration is not tried again until a migration file changes. Superusers see the result in the admin.');
		if($config->get('migrationsAutoRun') !== null) {
			$field->notes .= "\n" . sprintf($this->_('Overridden by $config->migrationsAutoRun (currently: %s).'), $this->getAutoRun() ?: 'off');
		}
		$inputfields->add($field);
	}

}
