<?php namespace ProcessWire;

/**
 * Setup → Migrations as a logged in superuser: list, run, CSRF, notices of the auto mode
 */

require dirname(__DIR__) . '/lib.php';

if(!T::baseUrl()) {
	T::skip('the site is not reachable over HTTP (set PM_TEST_URL)');
	T::done();
}

$module = T::module();
$dir = T::dir();
T::saveConfig(['migrationsPath' => $dir, 'autoRun' => '']);
$wire = T::wire();
$admin = $wire->config->urls->admin;
$jar = $dir . 'cookies';

// the database is restored after the case: a known password for the superuser
$user = $wire->users->get($wire->config->superUserPageID);
$user->of(false);
$user->pass = 'pm-Test-Pass-4711';
$user->save();

/**
 * Hidden inputs (CSRF token) of the first form in $html
 */
function hiddenInputs(string $html): array {
	$fields = [];
	preg_match_all('/<input[^>]+type=[\'"]hidden[\'"][^>]*>/i', $html, $matches);
	foreach($matches[0] as $input) {
		if(preg_match('/name=[\'"]([^\'"]+)/', $input, $n) && preg_match('/value=[\'"]([^\'"]*)/', $input, $v)) $fields[$n[1]] = html_entity_decode($v[1]);
	}
	return $fields;
}

T::section('Login');
[, $html] = T::http($admin, null, $jar);
[$status, $html] = T::http($admin, hiddenInputs($html) + ['login_name' => $user->name, 'login_pass' => 'pm-Test-Pass-4711', 'login_submit' => 1], $jar);
T::ok(str_contains($html, 'logout'), 'logged in as superuser', mb_substr(strip_tags($html), 0, 300));

T::section('List');
T::migration(1, '', 'First <i>one</i>');
T::migration(2, '', 'Second');
[$status, $html] = T::http($admin . 'setup/migrations/', null, $jar);
T::eq(200, $status, 'Setup → Migrations loads');
T::contains('2 pending migrations', $html, 'two pending');
T::contains('First &lt;i&gt;one&lt;/i&gt;', $html, 'descriptions escaped');

T::section('Run one');
$token = hiddenInputs($html);
[$status, $html, $url] = T::http($admin . 'setup/migrations/run/', $token + ['version' => '1'], $jar);
T::eq([1], T::executed(), 'migration 1 ran');
T::ok(str_ends_with(rtrim($url, '/'), 'setup/migrations'), 'redirect back to the list (a reload does not post again)', $url);
T::contains('Migration 1 executed', $html, 'the message is shown');
T::contains('1 pending migration', $html, 'one pending left');

T::section('Run pending');
[, $html] = T::http($admin . 'setup/migrations/run/', hiddenInputs($html) + ['version' => 'pending'], $jar);
T::eq([1, 2], T::executed(), 'the rest ran');
T::contains('Executed: 2', $html, 'the message is shown');
T::contains('All migrations executed', $html, 'all executed');

T::section('Errors');
touch($dir . 'fail');
T::migration(3, 'if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("admin <b>boom</b>");');
[, $html] = T::http($admin . 'setup/migrations/', null, $jar);
[$status, $html] = T::http($admin . 'setup/migrations/run/', hiddenInputs($html) + ['version' => 'pending'], $jar);
T::eq(200, $status, 'a failing migration does not break the page');
T::contains('admin &lt;b&gt;boom&lt;/b&gt;', $html, 'the error is shown, escaped');
unlink($dir . 'fail');

[, $html] = T::http($admin . 'setup/migrations/run/', hiddenInputs($html) + ['version' => '999'], $jar);
T::contains('Migration 999 not found', $html, 'unknown version: error shown');

T::section('CSRF and method');
$before = count(T::trace());
[$status, $html] = T::http($admin . 'setup/migrations/run/', ['version' => '1'], $jar);
T::eq($before, count(T::trace()), 'a POST without CSRF token runs nothing');
T::note("status without token: $status");
[$status, , $url] = T::http($admin . 'setup/migrations/run/?version=1', null, $jar);
T::eq($before, count(T::trace()), 'a GET runs nothing');
T::ok(str_ends_with(rtrim($url, '/'), 'setup/migrations'), 'a GET redirects to the list', $url);

T::section('Guest');
[$status, $html] = T::http($admin . 'setup/migrations/run/', ['version' => '1']);
T::eq($before, count(T::trace()), 'a guest runs nothing');

T::section('Notices of the auto mode');
T::saveConfig(['autoRun' => 'superuser', 'autoDone' => [], 'autoFailed' => [], 'signature' => '']);
T::migration(4);
T::http('/', null, $jar);
for($i = 0; $i < 20 && !in_array(4, T::executed(), true); $i++) usleep(100000);
T::ok(in_array(4, T::executed(), true), 'mode superuser: a request of the superuser runs it');
usleep(300000);
[, $html] = T::http($admin . 'page/', null, $jar);
T::contains('Migrations executed automatically: ', $html, 'the next admin page tells the superuser');
[, $html] = T::http($admin . 'page/', null, $jar);
T::ok(!str_contains($html, 'Migrations executed automatically'), 'only once');

touch($dir . 'fail');
T::migration(5, 'if(is_file(__DIR__ . "/fail")) throw new \RuntimeException("auto <script>x</script>");');
T::http('/', null, $jar);
usleep(500000);
[, $html] = T::http($admin, null, $jar);
T::contains('Migration 5 failed in the auto mode', $html, 'a failure is shown in the admin');
T::ok(!str_contains($html, '<script>x</script>'), 'the error is escaped');
[, $html] = T::http($admin, null, $jar);
T::contains('Migration 5 failed in the auto mode', $html, 'on every admin page until it is solved');

T::done();
