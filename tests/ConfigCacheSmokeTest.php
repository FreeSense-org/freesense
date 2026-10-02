<?php
/* Standalone CI regression test; run with `php tests/ConfigCacheSmokeTest.php`. */

$work_dir = sys_get_temp_dir() . '/freesense-config-cache-' . getmypid();
@mkdir($work_dir . '/conf', 0755, true);
// util.inc requires the PEAR Net_IPv6 package, which is not part of this tree.
@mkdir($work_dir . '/stub/Net', 0755, true);
file_put_contents($work_dir . '/stub/Net/IPv6.php', "<?php\nclass Net_IPv6 {}\n");

set_include_path(get_include_path() . PATH_SEPARATOR . realpath(__DIR__ . '/../src/etc/inc') .
    PATH_SEPARATOR . $work_dir . '/stub');
require_once('globals.inc');
require_once('config.lib.inc');

function check_cache($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

g_set('tmp_path', $work_dir, true);
g_set('conf_path', $work_dir . '/conf', true);
$root = g_get('xml_rootobj');
$cache_file = $work_dir . '/config.cache';
$lock_file = $work_dir . '/config.lock';

file_put_contents($work_dir . '/conf/config.xml',
    "<?xml version=\"1.0\"?>\n<{$root}><version>1</version><marker>xml</marker></{$root}>\n");
touch($work_dir . '/conf/config.xml', time() - 60);

check_cache(config_read_file(false, true), 'config.xml must be readable');
check_cache(config_get_path('marker') === 'xml', 'first read must parse config.xml');
check_cache(file_exists($cache_file), 'first read must write the cache');

// A leftover config.lock with no lock held must not disable the cache.
touch($lock_file);
file_put_contents($cache_file, serialize(['version' => '1', 'marker' => 'cache']));
check_cache(config_read_file(false, true), 'cached config must be readable');
check_cache(config_get_path('marker') === 'cache', 'cache must be used while config.lock is not held');

// While a writer holds the exclusive lock the cache is neither read nor rewritten.
$writer = fopen($lock_file, 'c');
check_cache(flock($writer, LOCK_EX), 'test must take the writer lock');
unlink($cache_file);
check_cache(config_read_file(false, true), 'config.xml must be readable during a write');
check_cache(config_get_path('marker') === 'xml', 'config.xml must be parsed during a write');
check_cache(!file_exists($cache_file), 'cache must not be written during a write');
flock($writer, LOCK_UN);
fclose($writer);

check_cache(config_read_file(false, true), 'config.xml must be readable after a write');
check_cache(file_exists($cache_file), 'cache must be rebuilt after a write');

exec('rm -rf ' . escapeshellarg($work_dir));

echo "Config cache smoke test passed.\n";
