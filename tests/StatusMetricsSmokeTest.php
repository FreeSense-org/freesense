<?php
/*
 * Standalone CI regression test for the WebUI status routes
 * (src/etc/inc/status_metrics.inc, src/etc/inc/restapi/routes_status.inc);
 * run with `php tests/StatusMetricsSmokeTest.php`.
 *
 * The pure parsing functions are extracted and run on sample command output.
 */

$root = dirname(__DIR__);
$src = file_get_contents("{$root}/src/etc/inc/status_metrics.inc");
$routes_src = file_get_contents("{$root}/src/etc/inc/restapi/routes_status.inc");

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

define('STATUS_METRICS_MAX_AGE', 30);
foreach (array('status_metrics_rate', 'status_metrics_cpu_percent', 'status_metrics_parse_pfinfo', 'status_metrics_parse_df',
    'status_metrics_parse_swapinfo', 'status_metrics_parse_mbuf', 'status_metrics_parse_temp', 'status_metrics_parse_xport',
    'status_metrics_ranges') as $fn) {
	eval(fn_source($src, $fn));
}
eval(fn_source($routes_src, 'restapi_notice_list'));

/* Rates */
check(status_metrics_rate(1000, 100.0, 3000, 102.0) === 1000.0, 'a rate is the counter delta per second');
check(status_metrics_rate(null, null, 3000, 102.0) === null, 'no previous sample: no rate');
check(status_metrics_rate(5000, 100.0, 1000, 102.0) === null, 'a counter that went backwards (reset) gives no rate');
check(status_metrics_rate(1000, 100.0, 3000, 100.0 + STATUS_METRICS_MAX_AGE + 1) === null, 'a stale sample gives no rate');
check(status_metrics_cpu_percent(array(100, 0, 50, 10, 840), array(150, 0, 70, 10, 1020)) === 28.0, 'CPU busy percent from cp_time ticks');
check(status_metrics_cpu_percent(array(1, 2, 3), array(1, 2, 3, 4, 5)) === null, 'malformed cp_time gives null');

/* pfctl -si */
$pf = status_metrics_parse_pfinfo(<<<'TXT'
Status: Enabled for 0 days 01:23:45           Debug: Urgent

Interface Stats for igc0              IPv4             IPv6
  Bytes In                        12345678                0

State Table                          Total             Rate
  current entries                    18420
  searches                        98765432         4321.5/s
  inserts                           654321          123.4/s
  removals                          635901          120.1/s
Counters
  match                             701234          130.2/s
TXT);
check($pf['current'] === 18420 && $pf['searches'] === 98765432 && $pf['search_rate'] === 4321.5 &&
    $pf['insert_rate'] === 123.4 && $pf['removal_rate'] === 120.1, 'pfctl -si state table numbers and rates');

/* df */
$df = status_metrics_parse_df(<<<'TXT'
Filesystem          Type  1024-blocks    Used     Avail Capacity  Mounted on
pfSense/ROOT/default zfs     104857600 4194304 100663296     4%    /
tmpfs               tmpfs      524288   30720    493568     6%    /tmp
zroot/var/log       zfs      104857600 1310720 100663296     1%    /var/log with space
TXT);
check(count($df) === 3 && $df[0]['mount'] === '/' && $df[0]['type'] === 'zfs' && $df[0]['total_bytes'] === 104857600 * 1024 &&
    $df[0]['used_bytes'] === 4194304 * 1024, 'df -kT rows in bytes');
check($df[2]['mount'] === '/var/log with space', 'a mount point with spaces is kept whole');

/* swapinfo */
check(status_metrics_parse_swapinfo("Device          1K-blocks     Used    Avail Capacity\n/dev/ada0p3        4194304   102400  4091904     2%\n") ===
    array('total_bytes' => 4194304 * 1024, 'used_bytes' => 102400 * 1024), 'one swap device');
check(status_metrics_parse_swapinfo("Device 1K-blocks Used Avail Capacity\n/dev/a 100 10 90 10%\n/dev/b 300 30 270 10%\nTotal 400 40 360 10%\n") ===
    array('total_bytes' => 400 * 1024, 'used_bytes' => 40 * 1024), 'several devices use the Total line');
check(status_metrics_parse_swapinfo("Device 1K-blocks Used Avail Capacity\n") === array('total_bytes' => 0, 'used_bytes' => 0), 'no swap is zero');

/* vmstat -z */
check(status_metrics_parse_mbuf('{"vmstat":{"memory-zone-statistics":{"zone":[{"name":"mbuf","limit":0,"used":10,"free":5},{"name":"mbuf_cluster","limit":253590,"used":6100,"free":900}]}}}') ===
    array('used' => 7000, 'max' => 253590), 'mbuf clusters in use and their limit');
check(status_metrics_parse_mbuf('nonsense') === array('used' => null, 'max' => null), 'unparsable vmstat output gives nulls');

/* temperatures */
check(status_metrics_parse_temp('45.0C') === 45.0 && status_metrics_parse_temp('') === null && status_metrics_parse_temp('-273.2C') === null,
    'sysctl temperatures parse; missing or bogus sensors are null');

/* rrdtool xport */
$x = status_metrics_parse_xport(<<<'XML'
<?xml version="1.0" encoding="ISO-8859-1"?>
<xport>
  <meta>
    <start>1700000000</start><step>60</step><end>1700000120</end><rows>3</rows><columns>2</columns>
    <legend><entry>in_bps</entry><entry>out_bps</entry></legend>
  </meta>
  <data>
    <row><t>1700000000</t><v>1.2345678900e+06</v><v>NaN</v></row>
    <row><t>1700000060</t><v>2.0000000000e+06</v><v>3.5000000000e+05</v></row>
    <row><t>1700000120</t><v>nan</v><v>3.0000000000e+05</v></row>
  </data>
</xport>
XML);
check($x['step'] === 60 && $x['t'] === array(1700000000, 1700000060, 1700000120) &&
    $x['in_bps'] === array(1234567.89, 2000000.0, null) && $x['out_bps'] === array(null, 350000.0, 300000.0), 'rrdtool xport rows; NaN becomes null');
check(status_metrics_parse_xport('not xml') === null, 'broken xport output gives null');
$x2 = status_metrics_parse_xport('<?xml version="1.0"?><xport><meta><start>1791496680</start><end>1791496860</end><step>60</step><rows>3</rows><columns>1</columns>' .
    '<legend><entry>in_bps</entry></legend></meta><data><row><v>6.16e+01</v></row><row><v>NaN</v></row><row><v>6.0e+01</v></row></data></xport>');
check($x2['t'] === array(1791496680, 1791496740, 1791496800) && $x2['in_bps'] === array(61.6, null, 60.0),
    'rows without <t> (rrdtool 1.11 default) are timed from start and step');
check(array_keys(status_metrics_ranges()) === array('10m', '1h', '24h', '7d', '30d'), 'history ranges');

/* notices */
$n = restapi_notice_list(array(
	1700000000 => array('id' => 'Gateway', 'notice' => 'WAN2 is down &amp; &lt;b&gt;', 'url' => '', 'category' => 'General', 'priority' => '2'),
	1700000100 => array('id' => 'Update', 'notice' => 'An update is available', 'url' => '/system/update', 'category' => 'General', 'priority' => '0'),
));
check($n[0]['id'] === '1700000100' && $n[0]['level'] === 'info' && $n[1]['level'] === 'crit' && $n[1]['text'] === 'WAN2 is down & <b>',
    'notices: newest first, levels from priority, stored HTML entities decoded (the WebUI escapes text itself)');
check(restapi_notice_list(false) === array(), 'no notice queue is an empty list');

/* wiring */
$front = file_get_contents("{$root}/src/usr/local/www/api/index.php");
$v1 = file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc");
check(strpos($front, "require_once('status_metrics.inc');") !== false && strpos($front, "require_once('notices.inc');") !== false,
    'the front controller loads the status libraries');
check(strpos($v1, "require_once('restapi/routes_status.inc');") !== false && strpos($v1, 'restapi_routes_status()') !== false, 'status routes are registered');
check(strpos($src, "escapeshellarg(\$file)") !== false && strpos($src, "preg_match('/^[a-z][a-z0-9_]{0,31}\$/', (string)\$if)") !== false,
    'the RRD path is built only from a validated interface name and passed escaped');
foreach (array("'/v1/status/system'" => "'index.php'", "'/v1/status/traffic'" => "'status_graph.php'", "'/v1/status/states'" => "'diag_states_summary.php'",
    "'/v1/notices/{id}'" => "'index.php'") as $path => $page) {
	$at = strpos($routes_src, $path);
	check(($at !== false) && (strpos($routes_src, "'page' => {$page}", $at) !== false) &&
	    (strpos($routes_src, "'page' => {$page}", $at) < (strpos($routes_src, 'restapi_route(', $at + 1) ?: PHP_INT_MAX)),
	    "{$path} is guarded by the GUI page {$page}");
}

/* History routes (API level 8): system and gateway RRDs, one validated range, escaped paths */
foreach (array("'/v1/status/system/history'" => "'index.php'", "'/v1/status/gateways/history'" => "'status_gateways.php'") as $path => $page) {
	$at = strpos($routes_src, $path);
	check(($at !== false) && (strpos($routes_src, "'page' => {$page}", $at) < (strpos($routes_src, 'restapi_route(', $at + 1) ?: PHP_INT_MAX)),
	    "{$path} is guarded by the GUI page {$page}");
}
check(strpos(fn_source($routes_src, 'restapi_status_range'), 'status_metrics_ranges()') !== false, 'history ranges are validated against the known list');
$gwh = fn_source($src, 'status_metrics_gateway_history');
check(strpos($gwh, "preg_match('/^[A-Za-z0-9_.-]{1,64}\$/'") !== false && strpos($gwh, 'escapeshellarg($file)') !== false,
    'gateway RRD paths are built only from validated names and passed escaped');
check(strpos($gwh, 'CDEF:dms=d,1000,*') !== false, 'gateway delay is converted from seconds to ms');
$sysh = fn_source($src, 'status_metrics_system_history');
check(strpos($sysh, 'CDEF:cpu=u,n,ADDNAN,s,ADDNAN,i,ADDNAN') !== false && strpos($sysh, 'CDEF:mem=a,w,ADDNAN,l,ADDNAN') !== false,
    'CPU = user+nice+system+interrupt, memory = active+wired+laundry');

/* Rates within one batch: a sample under a second old falls back to the one before it */
eval(fn_source($src, 'status_metrics_rate_base'));
$old = array('t' => 100.0, 'in' => 1000);
check(status_metrics_rate_base(array('t' => 104.0, 'in' => 1500, 'prev' => $old), 104.001) === $old, 'a fresh sample uses the previous one');
check(status_metrics_rate_base(array('t' => 104.0, 'in' => 1500, 'prev' => $old), 106.0)['in'] === 1500, 'a sample a second or more old is used');
check(status_metrics_rate_base(array('t' => 104.0, 'in' => 1500, 'prev' => null), 104.001)['in'] === 1500, 'without a previous sample the latest is used');
check(status_metrics_rate_base(array(), 1.0) === array(), 'no sample');

/* WireGuard dump: interface lines skipped, peers parsed, (none) emptied */
eval(fn_source($src, 'status_metrics_parse_wg_dump'));
$dump = "tun_wg0\tPRIV\tPUBA\t51820\toff\n" .
    "tun_wg0\tPEER1=\t(none)\t203.0.113.9:51820\t10.6.0.2/32\t1791516000\t1048576\t524288\t25\n" .
    "tun_wg0\tPEER2=\t(none)\t(none)\t(none)\t0\t0\t0\toff\n";
$wg = status_metrics_parse_wg_dump($dump);
check(count($wg) === 2 && $wg[0]['endpoint'] === '203.0.113.9:51820' && $wg[0]['handshake'] === 1791516000 && $wg[0]['rx'] === 1048576 &&
    $wg[0]['tx'] === 524288 && $wg[1]['endpoint'] === '' && $wg[1]['allowed_ips'] === '' && $wg[1]['handshake'] === 0,
    'wg show all dump peers are parsed');
check(status_metrics_parse_wg_dump('') === array(), 'no WireGuard output, no peers');
$at = strpos($routes_src, "'/v1/status/vpn'");
check(($at !== false) && (strpos($routes_src, "'page' => 'index.php'", $at) < (strpos($routes_src, 'restapi_route(', $at + 1) ?: PHP_INT_MAX)),
    '/v1/status/vpn is guarded by the dashboard privilege');

/* Gateway numbers without units (routes_v1.inc) */
eval(fn_source($v1, 'restapi_gateway_number'));
check(restapi_gateway_number('0.753ms') === 0.753 && restapi_gateway_number('0.0%') === 0.0 && restapi_gateway_number('12ms') === 12.0 &&
    restapi_gateway_number('') === null && restapi_gateway_number(null) === null && restapi_gateway_number('~') === null,
    'dpinger values become numbers (or null)');

echo "Status metrics smoke test passed.\n";
