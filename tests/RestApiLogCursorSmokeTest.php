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

/* WebUI format */
if (!function_exists('is_numericint')) {
	function is_numericint($v) { return is_string($v) && ctype_digit($v); }
}
date_default_timezone_set('Europe/Copenhagen');
$now = strtotime('2026-10-09 12:00:00');
check(restapi_log_webui_time('Oct  9 03:08:43', $now) === '2026-10-09T03:08:43+02:00', 'syslog time as ISO 8601 with the offset');
check(restapi_log_webui_time('Dec 31 23:59:59', strtotime('2027-01-01 00:30:00')) === '2026-12-31T23:59:59+01:00', 'last year\'s entries just after new year');
check(restapi_log_webui_time('Oct 11 08:00:00', $now) === '2025-10-11T08:00:00+02:00', 'a time more than a day ahead is last year\'s');
$GLOBALS['buffer_rules_normal'] = array('1000000104' => 'USER_RULE: Allow LAN to any', '@5' => 'Default deny rule IPv4');
$GLOBALS['buffer_rules_rdr'] = array();
$fw = array('time' => 'Oct  9 03:08:43', 'rulenum' => '5', 'subrulenum' => '', 'tracker' => '1000000104', 'realint' => 'em1', 'interface' => 'LAN',
    'act' => 'pass', 'direction' => 'in', 'proto' => 'TCP', 'tcpflags' => 'S', 'srcip' => '192.168.1.10', 'srcport' => '51544',
    'dstip' => '203.0.113.5', 'dstport' => '443', 'length' => '60');
$e = restapi_log_webui_entry($fw, true, array('em1' => 'lan'));
check($e['id'] === restapi_log_entry_id($fw) && $e['action'] === 'pass' && $e['iface'] === 'lan' && $e['iface_descr'] === 'LAN' &&
    $e['dir'] === 'in' && $e['proto'] === 'TCP:S' && $e['src'] === '192.168.1.10' && $e['srcport'] === 51544 && $e['dstport'] === 443 &&
    $e['rule'] === 'Allow LAN to any' && $e['rule_id'] === '1000000104' && $e['len'] === 60, 'a firewall line in the viewer\'s shape (same id as parsed)');
check(restapi_log_webui_entry(array('tracker' => '0', 'rulenum' => '5') + $fw, true, array())['rule'] === 'Default deny rule IPv4' &&
    restapi_log_webui_entry(array('tracker' => '9') + $fw, true, array())['rule'] === null, 'rules by number without a tracker; unknown rules are null');
check(restapi_log_webui_entry(array('srcport' => '', 'proto' => 'ICMP', 'tcpflags' => '') + $fw, true, array())['srcport'] === null, 'no port for ICMP');
$sys = restapi_log_webui_entry(array('time' => 'Oct  9 03:09:53', 'host' => 'fw', 'process' => 'php-fpm', 'pid' => '428', 'message' => 'Hello'), false, array());
check(array_keys($sys) === array('id', 'time', 'host', 'process', 'pid', 'severity', 'message') && $sys['pid'] === 428 && $sys['severity'] === null,
    'a system line in the viewer\'s shape');
$f = array('q' => '', 'action' => array(), 'iface' => array(), 'process' => array());
check(restapi_log_webui_match($e, $f) && restapi_log_webui_match($e, array('action' => array('pass', 'block')) + $f) &&
    !restapi_log_webui_match($e, array('action' => array('block')) + $f) && !restapi_log_webui_match($e, array('iface' => array('wan')) + $f) &&
    restapi_log_webui_match($e, array('q' => 'allow lan') + $f) && restapi_log_webui_match($e, array('q' => '203.0.113') + $f) &&
    !restapi_log_webui_match($e, array('q' => restapi_log_entry_id($fw)) + $f), 'filters combine; search looks at every field but the id');
check(restapi_log_webui_list(array('action' => ' Block, ,pass'), 'action') === array('block', 'pass'), 'comma lists');
check(restapi_log_webui_limit(array()) === 100 && $status(function () { restapi_log_webui_limit(array('limit' => '501')); }) === 400, 'limit 1-500');

/* Wiring */
$src = file_get_contents("{$root}/src/etc/inc/restapi/routes_logs.inc");
check(substr_count($src, 'restapi_log_cursor(') === 5, 'system, VPN and firewall logs (and the WebUI format) accept cursors');
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
