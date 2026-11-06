<?php
/* Standalone CI regression test; run with `php tests/LockSafetySmokeTest.php`. */

$work_dir = sys_get_temp_dir() . '/freesense-lock-safety-' . getmypid();
@mkdir($work_dir, 0755, true);
// util.inc requires the PEAR Net_IPv6 package, which is not part of this tree.
@mkdir($work_dir . '/stub/Net', 0755, true);
file_put_contents($work_dir . '/stub/Net/IPv6.php', "<?php\nclass Net_IPv6 {}\n");

set_include_path(get_include_path() . PATH_SEPARATOR . realpath(__DIR__ . '/../src/etc/inc') .
    PATH_SEPARATOR . $work_dir . '/stub');
require_once('globals.inc');
require_once('config.lib.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$tmp = $work_dir . '/tmp';
$run = $work_dir . '/run';
mkdir($tmp);
mkdir($run);
g_set('tmp_path', $tmp, true);
g_set('varrun_path', $run, true);

$held = lock('config', LOCK_EX);
check(is_resource($held), 'lock must return a handle');
check(file_exists($run . '/locks/config.lock'), 'lock files must live under varrun_path/locks');
check((fileperms($run . '/locks') & 0777) === 0700, 'the lock directory must be private');
check(try_lock('config', 0) === null, 'an exclusive lock must keep others out');
unlock($held);
$again = try_lock('config', 0);
check(is_resource($again), 'a released lock must be free');
unlock($again);

$shared = lock('config', LOCK_SH);
check(try_lock('config', 0) === null, 'a shared lock must block an exclusive one');
$reader = config_cache_lock();
check(is_resource($reader), 'the config cache must share with other readers');
unlock($reader);
unlock($shared);

$writer = lock('config', LOCK_EX);
check(config_cache_lock() === null, 'the config cache must use the same lock file as writers');
unlock($writer);

// Nothing planted in the world-writable directory may be touched.
$victim = $work_dir . '/victim';
file_put_contents($victim, 'untouched');
chmod($victim, 0600);
symlink($victim, $tmp . '/planted.lock');
symlink($work_dir . '/created-by-attack', $tmp . '/dangling.lock');
unlock(lock('planted', LOCK_EX));
unlock(lock('dangling', LOCK_EX));
clearstatcache();
check(file_get_contents($victim) === 'untouched', 'a link in the temporary directory must not be followed');
check((fileperms($victim) & 0777) === 0600, 'a link target must keep its mode');
check(!file_exists($work_dir . '/created-by-attack'), 'a dangling link must not create its target');

// Opening a lock file must not truncate it, whatever it is.
file_put_contents($run . '/locks/data.lock', 'content');
unlock(lock('data', LOCK_EX));
check(file_get_contents($run . '/locks/data.lock') === 'content', 'taking a lock must not truncate its file');

// A name can't reach outside the directory, even with a zone name in it.
unlock(lock('../../escape', LOCK_EX));
check(!file_exists($work_dir . '/escape.lock'), 'a lock name must not leave the lock directory');
check(file_exists($run . '/locks/.._.._escape.lock'), 'an unsafe name must stay inside the directory');
unlock(lock('voucher-guest_zone.1', LOCK_EX));
check(file_exists($run . '/locks/voucher-guest_zone.1.lock'), 'ordinary names must be kept as they are');

unlock_force('voucher-guest_zone.1');
check(!file_exists($run . '/locks/voucher-guest_zone.1.lock'), 'unlock_force must remove the file');

// The shell guards around shutdown must use the same file.
foreach (['FreeSense-rc.shutdown', 'rc.halt', 'rc.reboot'] as $script) {
	$text = file_get_contents(__DIR__ . '/../src/etc/' . $script);
	check(str_contains($text, 'lockf -s -k -t 30 /var/run/locks/config.lock'), "{$script} must guard on the config lock");
	check(str_contains($text, 'mkdir -p -m 0700 /var/run/locks'), "{$script} must create the directory first");
}

exec('rm -rf ' . escapeshellarg($work_dir));
echo "LockSafetySmokeTest OK\n";
