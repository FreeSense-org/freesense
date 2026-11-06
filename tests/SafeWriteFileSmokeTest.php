<?php
/* Standalone CI regression test; run with `php tests/SafeWriteFileSmokeTest.php`. */

$work_dir = sys_get_temp_dir() . '/freesense-safe-write-' . getmypid();
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

$dir = $work_dir . '/files';
mkdir($dir);
$listing = fn() => array_values(array_diff(scandir($dir), ['.', '..']));
$mode = function ($path) { clearstatcache(); return fileperms($path) & 0777; };

check(safe_write_file("{$dir}/a", 'first') === true, 'must write a new file');
check(safe_write_file("{$dir}/a", 'second') === true, 'must replace a file');
check(file_get_contents("{$dir}/a") === 'second', 'must hold the new content');
check($listing() === ['a'], 'must leave no temporary file');

check(safe_write_file("{$dir}/a", ['one', 'two']) === true, 'must accept lines');
check(file_get_contents("{$dir}/a") === "one\ntwo\n", 'lines must each end in a newline');

check(safe_write_file("{$dir}/a", '') === true, 'must accept empty content');
check(file_get_contents("{$dir}/a") === '', 'empty content must empty the file');
check($listing() === ['a'], 'empty content must leave no temporary file');

$bytes = random_bytes(4096) . "\0\r\n";
check(safe_write_file("{$dir}/a", $bytes, true) === true, 'must accept binary content');
check(file_get_contents("{$dir}/a") === $bytes, 'binary content must survive');

$large = str_repeat('0123456789', 1 << 18);
check(safe_write_file("{$dir}/a", $large) === true, 'must accept large content');
check(strlen(file_get_contents("{$dir}/a")) === strlen($large), 'large content must survive');

chmod("{$dir}/a", 0600);
safe_write_file("{$dir}/a", 'x');
check($mode("{$dir}/a") === 0600, 'a private file must stay private');
chmod("{$dir}/a", 0750);
safe_write_file("{$dir}/a", 'x');
check($mode("{$dir}/a") === 0750, 'a file must keep its mode');

$old = umask(0022);
safe_write_file("{$dir}/new", 'x');
umask($old);
check($mode("{$dir}/new") === 0644, 'a new file must follow the umask');

$victim = "{$work_dir}/victim";
file_put_contents($victim, 'untouched');
symlink($victim, "{$dir}/b." . getmypid());
check(safe_write_file("{$dir}/b", 'content') === true, 'must write past a planted name');
check(file_get_contents($victim) === 'untouched', 'a planted link must not be followed');
unlink("{$dir}/b." . getmypid());

check(safe_write_file("{$work_dir}/missing/file", 'x') === false, 'a missing directory must fail');
mkdir("{$dir}/isdir");
check(@safe_write_file("{$dir}/isdir", 'x') === false, 'a directory in the way must fail');
check(array_filter($listing(), fn($e) => $e[0] === '.') === [], 'a failure must leave no temporary file');

exec('rm -rf ' . escapeshellarg($work_dir));
echo "SafeWriteFileSmokeTest OK\n";
