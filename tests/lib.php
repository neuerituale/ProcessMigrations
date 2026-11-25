<?php namespace ProcessWire;

/**
 * Bootstrap and helpers for one test case (tests/cases/*.php), run by tests/run.php
 *
 * Every case runs in its own PHP process: the classes Migration_<version> can be declared only once per process.
 * run.php restores the database after each case, so a case may change the schema freely.
 */

if(PHP_SAPI !== 'cli') exit(1);
if(!getenv('PM_TEST_RUNNER')) {
	fwrite(STDERR, "Start the tests with: php " . dirname(__DIR__) . "/tests/run.php\n");
	exit(2);
}

define('PM_TEST_ROOT', dirname(__DIR__, 4) . '/');
define('PM_TEST_CASE', basename($_SERVER['argv'][0], '.php'));

include PM_TEST_ROOT . 'index.php';

/** @var ProcessWire $wire */
$wire->users->setCurrentUser($wire->users->get($wire->config->superUserPageID));

final class T {

	public static int $pass = 0;
	public static int $fail = 0;

	/** @var string[] */
	private static array $cleanup = [];

	public static function wire(): ProcessWire {
		return $GLOBALS['wire'];
	}

	public static function module(): ProcessMigrations {
		$modules = self::wire()->modules;
		if(!$modules->isInstalled('ProcessMigrations')) {
			if(!$modules->isInstallable('ProcessMigrations')) $modules->refresh();
			$modules->install('ProcessMigrations');
		}
		return $modules->get('ProcessMigrations');
	}

	/* ***** Files *** */

	/**
	 * Fresh migrations directory of this case, also set as $config->migrationsPath
	 */
	public static function dir(string $sub = ''): string {
		$dir = PM_TEST_ROOT . 'site/assets/cache/pm-tests/' . PM_TEST_CASE . ($sub ? "-$sub" : '') . '/';
		if(!is_dir($dir)) mkdir($dir, 0777, true);
		self::wire()->config->migrationsPath = $dir;
		return $dir;
	}

	/**
	 * Write Migration_<version>.php with the given body of up()
	 *
	 * Every generated migration appends its version to the file "trace" in the migrations directory.
	 */
	public static function migration(string|int $version, string $up = '', string $description = '', string $extra = ''): string {
		$dir = self::wire()->config->migrationsPath ?: self::dir();
		$description = var_export($description ?: "Test migration $version", true);
		$code = <<<PHP
		<?php namespace ProcessWire;

		class Migration_$version extends MigrationStep {

			public static function description(): string {
				return $description;
			}

			public function up(): void {
				file_put_contents(__DIR__ . '/trace', "$version\\n", FILE_APPEND);
				$up
			}

			$extra
		}

		PHP;
		return self::file("Migration_$version.php", $code);
	}

	public static function file(string $name, string $content): string {
		$dir = self::wire()->config->migrationsPath ?: self::dir();
		$file = $dir . $name;
		file_put_contents($file, $content);
		return $file;
	}

	/**
	 * Versions in the order up() was called
	 *
	 * @return string[]
	 */
	public static function trace(): array {
		$file = self::wire()->config->migrationsPath . 'trace';
		return is_file($file) ? array_values(array_filter(explode("\n", file_get_contents($file)), 'strlen')) : [];
	}

	/**
	 * A site module that "came with the deployment": site/modules/<name>/<name>.module.php, removed at the end
	 */
	public static function siteModule(string $name, string $body = '', array $info = [], string $extension = '.module.php'): string {
		$dir = self::wire()->config->paths->siteModules . $name . '/';
		if(!is_dir($dir)) mkdir($dir, 0777, true);
		self::$cleanup[] = $dir;
		$info = var_export($info + ['title' => $name, 'version' => 1], true);
		file_put_contents($dir . $name . $extension, <<<PHP
		<?php namespace ProcessWire;

		class $name extends WireData implements Module {
			public static function getModuleInfo() { return $info; }
			$body
		}

		PHP);
		return $dir;
	}

	/* ***** State *** */

	/**
	 * Executed versions straight from the database
	 *
	 * @return int[]
	 */
	public static function executed(): array {
		$database = self::wire()->database;
		if(!self::tableExists(ProcessMigrations::table)) return [];
		return array_map('intval', $database->query('SELECT version FROM `' . ProcessMigrations::table . '` ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN));
	}

	public static function tableExists(string $table): bool {
		$query = self::wire()->database->prepare('SHOW TABLES LIKE :table');
		$query->execute([':table' => $table]);
		return (bool) $query->rowCount();
	}

	public static function log(): string {
		$file = self::wire()->config->paths->logs . ProcessMigrations::logName . '.txt';
		return is_file($file) ? file_get_contents($file) : '';
	}

	/**
	 * Module config as stored in the database (another request may have changed it)
	 */
	public static function storedConfig(): array {
		$query = self::wire()->database->prepare('SELECT data FROM modules WHERE class=:class');
		$query->execute([':class' => 'ProcessMigrations']);
		return json_decode((string) $query->fetchColumn(), true) ?: [];
	}

	/**
	 * Save module config (used by other processes: migrate.php, HTTP requests)
	 */
	public static function saveConfig(array $data): void {
		$modules = self::wire()->modules;
		$modules->saveConfig('ProcessMigrations', array_merge($modules->getConfig('ProcessMigrations'), $data));
	}

	/**
	 * Texts of the notices of the given type (NoticeWarning, NoticeError, NoticeMessage) since the last call
	 *
	 * @return string[]
	 */
	public static function notices(string $type = 'NoticeWarning'): array {
		$texts = [];
		$notices = self::wire()->notices;
		foreach($notices->getArray() as $notice) {
			if($notice->className() !== $type) continue;
			$texts[] = (string) $notice->text;
			$notices->remove($notice);
		}
		return $texts;
	}

	/* ***** Processes *** */

	/**
	 * Run a PHP file, @return array [exit code, stdout, stderr]
	 */
	public static function php(string $file, array $args = []): array {
		$command = array_merge([PHP_BINARY, $file], $args);
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PM_TEST_ROOT);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $out, $err];
	}

	public static function baseUrl(): ?string {
		static $url = false;
		if($url !== false) return $url;
		$url = getenv('PM_TEST_URL') ?: 'https://' . (self::wire()->config->httpHosts[0] ?? 'localhost');
		[$status] = self::http('/');
		if(!$status) $url = null;
		return $url;
	}

	/**
	 * HTTP request against the site, @return array [status, body, final url]
	 */
	public static function http(string $path, ?array $post = null, string $cookieJar = ''): array {
		$base = self::baseUrl() ?? '';
		$curl = curl_init(rtrim($base, '/') . $path);
		curl_setopt_array($curl, [
			\CURLOPT_RETURNTRANSFER => true,
			\CURLOPT_FOLLOWLOCATION => true,
			\CURLOPT_SSL_VERIFYPEER => false,
			\CURLOPT_SSL_VERIFYHOST => 0,
			\CURLOPT_TIMEOUT => 60,
		]);
		if($cookieJar) curl_setopt_array($curl, [\CURLOPT_COOKIEJAR => $cookieJar, \CURLOPT_COOKIEFILE => $cookieJar]);
		if($post !== null) curl_setopt_array($curl, [\CURLOPT_POST => true, \CURLOPT_POSTFIELDS => http_build_query($post)]);
		$body = (string) curl_exec($curl);
		$result = [(int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE), $body, (string) curl_getinfo($curl, \CURLINFO_EFFECTIVE_URL)];
		return $result;
	}

	/* ***** Assertions *** */

	public static function ok(mixed $condition, string $message, string $detail = ''): bool {
		if($condition) {
			self::$pass++;
			echo "  ok   $message\n";
			return true;
		}
		self::$fail++;
		echo "  FAIL $message\n";
		if($detail !== '') echo '       ' . str_replace("\n", "\n       ", rtrim($detail)) . "\n";
		return false;
	}

	public static function eq(mixed $expected, mixed $actual, string $message): bool {
		return self::ok($expected === $actual, $message, 'expected ' . self::export($expected) . ', got ' . self::export($actual));
	}

	public static function contains(string $needle, string $haystack, string $message): bool {
		return self::ok(str_contains($haystack, $needle), $message, 'missing "' . $needle . '" in: ' . mb_substr($haystack, 0, 600));
	}

	/**
	 * $callback must throw a Throwable whose message contains $contains (if given)
	 */
	public static function throws(callable $callback, ?string $contains, string $message): ?\Throwable {
		try {
			$callback();
		} catch(\Throwable $e) {
			self::ok($contains === null || str_contains($e->getMessage(), $contains), $message, get_class($e) . ': ' . $e->getMessage());
			return $e;
		}
		self::ok(false, $message, 'nothing was thrown');
		return null;
	}

	public static function noThrow(callable $callback, string $message): mixed {
		try {
			$result = $callback();
			self::ok(true, $message);
			return $result;
		} catch(\Throwable $e) {
			self::ok(false, $message, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
			return null;
		}
	}

	/**
	 * An observation that is not a failure (questionable but intended or undecided behavior)
	 */
	public static function note(string $message): void {
		echo "  NOTE $message\n";
	}

	public static function section(string $title): void {
		echo "\n  -- $title\n";
	}

	public static function skip(string $message): void {
		echo "  SKIP $message\n";
	}

	private static function export(mixed $value): string {
		return str_replace("\n", ' ', var_export($value, true));
	}

	public static function done(): never {
		foreach(self::$cleanup as $dir) wireRmdir($dir, true);
		echo "\nRESULT pass=" . self::$pass . ' fail=' . self::$fail . "\n";
		exit(self::$fail ? 1 : 0);
	}
}

// a fatal error must not look like a passed case
register_shutdown_function(function() {
	$error = error_get_last();
	if($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
		echo "\nFATAL {$error['message']} in {$error['file']}:{$error['line']}\n";
	}
});
