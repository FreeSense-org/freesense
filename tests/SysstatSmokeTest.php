<?php
/*
 * Standalone CI regression test for the system statistics sampler
 * (src/etc/inc/sysstat.inc, src/usr/local/sbin/freesense-sysstatd); run
 * with `php tests/SysstatSmokeTest.php`.
 */

$root = dirname(__DIR__);
$src = file_get_contents("{$root}/src/etc/inc/sysstat.inc");

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function fn_source($code, $name) {
	$start = strpos($code, "function {$name}(");
	check($start !== false, "function {$name}() exists");
	return substr($code, $start, strpos($code, "\n}\n", $start) - $start) . "\n}\n";
}

foreach (['sysstat_parse_cptimes', 'sysstat_cpu_use', 'sysstat_cpu_split'] as $fn) {
	eval(fn_source($src, $fn));
}

check(sysstat_parse_cptimes("10 0 5 1 84 20 0 10 0 70") === [[10, 0, 5, 1, 84], [20, 0, 10, 0, 70]], 'kern.cp_times per core');
check(sysstat_parse_cptimes('1 2 3') === [] && sysstat_parse_cptimes('') === [], 'a broken cp_times gives no cores');

$use = sysstat_cpu_use([[100, 0, 50, 10, 840], [100, 0, 0, 0, 900]], [[150, 0, 70, 10, 1020], [100, 0, 0, 0, 1150]]);
check($use['cores'][0]['busy'] === 28.0 && $use['cores'][0]['user'] === 20.0 && $use['cores'][0]['system'] === 8.0, 'per-core busy, user and system');
check($use['cores'][1]['busy'] === 0.0, 'an idle core');
check($use['total']['busy'] === 14.0, 'the total over every core');
check(sysstat_cpu_use([[1, 1, 1, 1, 1]], [[1, 1, 1, 1, 1], [2, 2, 2, 2, 2]]) === null, 'a changed core count gives no reading');
check(sysstat_cpu_use([[5, 0, 0, 0, 5]], [[4, 0, 0, 0, 9]]) === null, 'a counter going back gives no reading');
check(sysstat_cpu_use([], [[1, 1, 1, 1, 1]]) === null, 'no previous sample');

/* Requests read the snapshot; only the sampler runs commands */
$metrics = file_get_contents("{$root}/src/etc/inc/status_metrics.inc");
$system = fn_source($metrics, 'status_metrics_system');
check(strpos($system, 'sysstat_read()') !== false && strpos($system, 'shell_exec') === false, 'status/system reads the sampler snapshot and runs no command');
foreach (['status_metrics_sensors', 'status_metrics_states', 'status_metrics_parse_swapinfo', 'status_metrics_parse_mbuf', 'status_metrics_parse_df'] as $need) {
	check(strpos($metrics, "function {$need}(") !== false, "the sampler's helper {$need}() exists");
}
check(strpos(fn_source($src, 'sysstat_run'), 'SYSSTAT_IDLE') !== false, 'the sampler stops when nobody reads');
$daemon = file_get_contents("{$root}/src/usr/local/sbin/freesense-sysstatd");
check(strpos($daemon, 'flock($fp, LOCK_EX | LOCK_NB)') !== false, 'only one sampler runs');

/* The old stats pipeline is gone */
foreach (['src/usr/local/www/getstats.php', 'src/usr/local/www/stats.php', 'src/usr/local/sbin/show_filter_reload_status.php', 'src/usr/local/bin/runmsntp.sh',
    'src/usr/local/bin/filterparser.php', 'src/usr/local/bin/usermgrwhoami'] as $gone) {
	check(!file_exists("{$root}/{$gone}"), "{$gone} is removed");
}
$fn = file_get_contents("{$root}/src/usr/local/www/includes/functions.inc.php");
check(strpos($fn, 'function get_stats(') === false && strpos($fn, 'function cpu_usage(') === false, 'the old stats helpers are removed');
check(strpos(file_get_contents("{$root}/src/usr/local/www/status_graph_cpu.php"), "fetch('/api/v1/status/system'") !== false, 'the CPU page reads the API');

echo "SysstatSmokeTest: OK\n";
