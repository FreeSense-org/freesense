<?php
/* Standalone CI regression test; run with `php tests/ArraySetPathSmokeTest.php`. */

$work_dir = sys_get_temp_dir() . '/freesense-array-set-path-' . getmypid();
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

$vacant = ['blank' => '', 'nothing' => null, 'none' => []];
foreach (array_keys($vacant) as $key) {
	check(array_set_path($vacant, "{$key}/x", 1) === 1, "must write below vacant {$key}");
	check($vacant[$key] === ['x' => 1], "vacant {$key} must become an array");
}

$real = ['zero' => '0', 'number' => 0, 'off' => false, 'text' => 'value'];
$before = $real;
foreach (array_keys($real) as $key) {
	check(array_set_path($real, "{$key}/x", 1, 'refused') === 'refused', "must refuse to write below {$key}");
}
check($real === $before, 'real values must not be destroyed');

$tree = ['a' => ['b' => 'c']];
check(array_set_path($tree, 'a/d/e', 2) === 2, 'must create arrays along the way');
check($tree === ['a' => ['b' => 'c', 'd' => ['e' => 2]]], 'must keep what was already there');
check(array_set_path($tree, 'a/', 'more') === 'more', 'a trailing slash must append');
check($tree['a'][0] === 'more', 'the appended value must be there');

exec('rm -rf ' . escapeshellarg($work_dir));
echo "ArraySetPathSmokeTest OK\n";
