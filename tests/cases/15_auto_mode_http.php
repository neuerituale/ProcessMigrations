<?php namespace ProcessWire;

/**
 * Auto mode with real HTTP requests: guest and superuser, signature, failures, restart
 */

require dirname(__DIR__) . '/lib.php';

if(!T::baseUrl()) {
	T::skip('the site is not reachable over HTTP (set PM_TEST_URL)');
	T::done();
}

$module = T::module();
$dir = T::dir();
T::saveConfig(['migrationsPath' => $dir, 'autoRun' => 'always', 'signature' => '', 'autoFailed' => [], 'autoDone' => []]);

/**
 * Executed versions, after waiting for the request's finished hook
 */
function executedAfter(array $expected): array {
	for($i = 0; $i < 20 && T::executed() !== $expected; $i++) usleep(100000);
	return T::executed();
}

$runs = fn(int $version) => substr_count(T::log(), "Migration $version: ");

T::section('Guest request, mode always');
T::migration(1);
[$status, $body] = T::http('/');
T::eq(200, $status, 'the page is delivered');
T::eq([1], executedAfter([1]), 'the pending migration ran after the request');
$stored = T::storedConfig();
T::eq([1], $stored['autoDone'] ?? null, 'autoDone remembers it for the superuser');
T::ok(($stored['signature'] ?? '') !== '', 'the signature is saved');
T::ok(!str_contains($body, 'Migration'), 'the guest sees nothing of it');

T::section('Unchanged files');
T::http('/');
T::http('/');
usleep(300000);
T::eq(1, $runs(1), 'no further run while nothing changed');

T::section('New migration file');
T::migration(2);
T::http('/');
T::eq([1, 2], executedAfter([1, 2]), 'a new file runs on the next request');
T::eq([1, 2], T::storedConfig()['autoDone'] ?? null, 'autoDone accumulates');

T::section('$needsNewRequest');
T::migration(3, '$this->needsNewRequest = true;');
T::migration(4);
T::http('/');
T::eq([1, 2, 3], executedAfter([1, 2, 3]), 'the first request stops after the migration that needs a new request');
T::http('/');
T::eq([1, 2, 3, 4], executedAfter([1, 2, 3, 4]), 'the next request runs the rest (signature was not saved)');

T::section('Failing migration');
touch($dir . 'fail');
T::migration(5, 'if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("auto boom");');
[$status] = T::http('/');
T::eq(200, $status, 'the page is still delivered');
usleep(500000);
$stored = T::storedConfig();
T::eq('5', $stored['autoFailed']['version'] ?? null, 'autoFailed has the version');
T::eq('auto boom', $stored['autoFailed']['error'] ?? null, 'and the error');
T::http('/');
T::http('/');
usleep(300000);
T::eq(1, $runs(5), 'it is not tried again in every request');

T::section('Fixed by changing the file');
unlink($dir . 'fail');
touch($dir . 'Migration_5.php', time() + 10);
clearstatcache();
T::http('/');
T::eq([1, 2, 3, 4, 5], executedAfter([1, 2, 3, 4, 5]), 'a changed file is tried again');
usleep(300000);
T::eq([], T::storedConfig()['autoFailed'] ?? null, 'autoFailed is cleared');

T::section('Failure fixed elsewhere (CLI) while the file stays');
touch($dir . 'fail');
T::migration(6, 'if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("boom 6");');
T::http('/');
usleep(500000);
T::eq('6', T::storedConfig()['autoFailed']['version'] ?? null, 'migration 6 failed in the auto mode');
unlink($dir . 'fail');
[$code] = T::php(dirname(__DIR__, 2) . '/migrate.php');
T::eq(0, $code, 'migrate.php runs it');
T::eq([], T::storedConfig()['autoFailed'] ?? null, 'that clears autoFailed');
T::http('/');
usleep(300000);
T::eq(2, $runs(6), 'the auto mode does not run it again');

T::section('Syntax error in a migration file');
T::file('Migration_7.php', '<?php namespace ProcessWire; class Migration_7 extends MigrationStep { this is not php }');
T::migration(8);
[$status, $body] = T::http('/');
T::eq(200, $status, 'the page is still delivered');
T::ok(!str_contains($body, 'ParseError') && !str_contains($body, 'syntax error'), 'the guest does not see the error', mb_substr(strip_tags($body), -400));
usleep(500000);
$stored = T::storedConfig();
T::eq('7', $stored['autoFailed']['version'] ?? null, 'autoFailed names the broken file 7', json_encode($stored['autoFailed'] ?? null));
T::ok(!in_array(8, T::executed(), true), 'migration 8 does not run before the broken 7');
unlink($dir . 'Migration_7.php');
T::http('/');
executedAfter([1, 2, 3, 4, 5, 6, 8]);

T::section('Mode superuser');
T::saveConfig(['autoRun' => 'superuser']);
T::migration(9);
T::http('/');
usleep(500000);
T::ok(!in_array(9, T::executed(), true), 'a guest request does not run migrations');

T::section('Mode off');
T::saveConfig(['autoRun' => '']);
T::http('/');
usleep(300000);
T::ok(!in_array(9, T::executed(), true), 'nothing runs');

T::section('Request that ends with an error page (404)');
T::saveConfig(['autoRun' => 'always']);
[$status] = T::http('/pm-does-not-exist/');
T::eq(404, $status, '404');
T::note('migrations after a 404: ' . (in_array(9, executedAfter(array_merge(T::executed(), [9])), true) ? 'ran' : 'did not run'));

T::done();
