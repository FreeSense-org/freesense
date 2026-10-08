<?php
/* Standalone CI regression test for log cursors (?after=, ?before=); run with `php tests/RestApiLogCursorSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
require_once('restapi/routes_logs.inc');
require_once('restapi/routes_operations.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}
$status = function (callable $f) { try { $f(); } catch (RestApiError $e) { return $e->status; } return null; };

/* Ids */
$a = array('time' => 'Oct  8 21:00:01', 'act' => 'block', 'srcip' => '198.51.100.7');
$b = array('time' => 'Oct  8 21:00:01', 'act' => 'pass', 'srcip' => '198.51.100.7');
check(preg_match('/^[0-9a-f]{16}$/', restapi_log_entry_id($a)) && (restapi_log_entry_id($a) === restapi_log_entry_id($a)) &&
    (restapi_log_entry_id($a) !== restapi_log_entry_id($b)), 'entry ids are stable 16-hex hashes of the entry');

/* Cursor parameters */
check(restapi_log_cursor(array()) === array('after' => null, 'before' => null), 'no cursor');
check(restapi_log_cursor(array('after' => '0123456789abcdef'))['after'] === '0123456789abcdef', 'an after cursor');
check($status(function () { restapi_log_cursor(array('after' => '../etc/passwd')); }) === 400, 'a malformed cursor is 400');
check($status(function () { restapi_log_cursor(array('after' => '0123456789abcdef', 'before' => '0123456789abcdef')); }) === 400,
    'after and before together are 400');

/* Paging: 10 entries, oldest first, ids e0..e9 */
$rows = array();
for ($i = 0; $i < 10; $i++) {
	$rows[] = array('id' => "e{$i}", 'n' => $i);
}
$ids = function ($page) { return array_column($page, 'id'); };

list($page, $reset, $more, $found) = restapi_log_page($rows, array('after' => 'e6', 'before' => null), 50, true);
check($ids($page) === array('e7', 'e8', 'e9') && !$reset && $found, 'after: newer entries, oldest first');
list($page) = restapi_log_page($rows, array('after' => 'e2', 'before' => null), 3, true);
check($ids($page) === array('e3', 'e4', 'e5'), 'after: limited to "lines", continuing from the cursor');
list($page, $reset) = restapi_log_page($rows, array('after' => 'e9', 'before' => null), 50, true);
check($page === array() && !$reset, 'after the newest entry: nothing new');
list($page, $reset, , $found) = restapi_log_page($rows, array('after' => 'gone', 'before' => null), 3, true);
check($ids($page) === array('e7', 'e8', 'e9') && $reset && !$found, 'a rotated-away cursor resets to the newest entries');

list($page, $reset, $more) = restapi_log_page($rows, array('after' => null, 'before' => 'e5'), 3, true);
check($ids($page) === array('e4', 'e3', 'e2') && !$reset && $more, 'before: older entries, newest first, more remain');
list($page, , $more) = restapi_log_page($rows, array('after' => null, 'before' => 'e2'), 50, true);
check($ids($page) === array('e1', 'e0') && !$more, 'before: reaching the start of the log');
list($page, , $more) = restapi_log_page($rows, array('after' => null, 'before' => 'e2'), 50, false);
check($more, 'before: an incomplete window may have older entries');

list($page, , $more) = restapi_log_page($rows, array('after' => null, 'before' => null), 4, true);
check($ids($page) === array('e9', 'e8', 'e7', 'e6') && $more, 'no cursor: the newest entries, newest first');

/* Duplicate ids (identical lines): after uses the last occurrence */
$dup = array(array('id' => 'x'), array('id' => 'y'), array('id' => 'x'), array('id' => 'z'));
list($page) = restapi_log_page($dup, array('after' => 'x', 'before' => null), 10, true);
check($ids($page) === array('z'), 'after a repeated entry continues after its last occurrence');

/* Wiring */
$src = file_get_contents("{$root}/src/etc/inc/restapi/routes_logs.inc");
check(substr_count($src, 'restapi_log_cursor(') === 4, 'system, VPN and firewall logs accept cursors');
check(strpos($src, "Cursors (\"after\", \"before\") work on parsed entries only.") !== false, 'raw format refuses cursors');
check(strpos($src, 'array(($lines * 2) + 200, 2000, RESTAPI_LOG_SCAN_MAX)') !== false, 'live tail searches a small window first');

/* Jobs: the package operation as a WebUI job */
$st = array('state' => 'running', 'running' => true, 'mode' => 'firmwareupdate', 'package' => null, 'reboot_needed' => false,
    'notice' => null, 'progress' => array('current' => 3, 'total' => 12), 'log' => array('>>> Updating repositories', 'done.', '>>> Fetching FreeSense-system', ''));
$job = restapi_job_view($st, 2);
check($job['id'] === 'packages' && $job['title'] === 'System update' && $job['state'] === 'running' && $job['percent'] === 25 &&
    $job['step'] === '>>> Fetching FreeSense-system' && $job['cursor'] === 4, 'a running system update as a job');
check(array_column($job['log'], 'n') === array(3, 4), 'only log lines after the cursor are returned, numbered');
$job = restapi_job_view(array('state' => 'none', 'running' => false, 'mode' => null, 'package' => null, 'reboot_needed' => false,
    'notice' => null, 'progress' => null, 'log' => array()));
check($job['title'] === 'No operation' && $job['percent'] === null && $job['log'] === array() && $job['cursor'] === 0, 'no operation');
$job = restapi_job_view(array('state' => 'failed', 'running' => false, 'mode' => 'installpkg', 'package' => 'FreeSense-pkg-nut',
    'reboot_needed' => false, 'notice' => null, 'progress' => array('current' => 0, 'total' => 0), 'log' => array('x')));
check($job['title'] === 'Install FreeSense-pkg-nut' && $job['percent'] === null, 'package titles; zero totals give no percent');

echo "REST API log cursor and job smoke test passed.\n";
