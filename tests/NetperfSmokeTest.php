<?php
/*
 * System > Advanced > Performance: the netperf.inc pure functions (loader
 * lines, validation, pending reboot, mbuf parsing) and how the page, the
 * Miscellaneous split, loader.conf and the REST route are wired.
 */
$root = dirname(__DIR__);
set_include_path($root . '/src/etc/inc' . PATH_SEPARATOR . get_include_path());

function gettext($text) {
	return $text;
}
function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Performance: {$message}\n");
		exit(1);
	}
}

require_once('netperf.inc');

/* netisr threads: only with the RSS kernel, and only when automatic */
check(netperf_netisr_mode(null) === 'auto' && netperf_netisr_mode('single') === 'single' && netperf_netisr_mode('x') === 'auto',
    'Anything but "single" is automatic.');
check(netperf_loader_lines([], true) === ['net.isr.maxthreads="-1"', 'net.isr.bindthreads="1"'],
    'An RSS kernel gets one bound netisr thread per CPU by default.');
check(netperf_loader_lines([], false) === [], 'Without RSS nothing is written.');
check(netperf_loader_lines(['netisr_threads' => 'single'], true) === [], 'Single thread keeps the FreeBSD default.');

/* network buffers */
check(netperf_loader_lines(['netisr_threads' => 'single', 'nmbclusters' => '1000000'], true) === ['kern.ipc.nmbclusters="1000000"'],
    'A buffer limit becomes a loader line.');
foreach (['1000', '99999999', 'abc', '1e6', "100000\"\nkern.evil=\"1", '-1'] as $bad) {
	check(netperf_validate_nmbclusters($bad) !== null, 'Refused buffer limit: ' . json_encode($bad));
	check(netperf_loader_lines(['netisr_threads' => 'single', 'nmbclusters' => $bad], true) === [],
	    'An invalid buffer limit from config.xml is never written: ' . json_encode($bad));
}
check(netperf_validate_nmbclusters('') === null && netperf_validate_nmbclusters('262144') === null, 'Empty and sane limits pass.');
check(netperf_loader_keys() === ['net.isr.maxthreads', 'net.isr.bindthreads', 'kern.ipc.nmbclusters'], 'Managed loader keys.');

/* pending reboot: wanted against booted (kenv) */
$keys = netperf_loader_keys();
check(netperf_pending(['net.isr.maxthreads="-1"', 'net.isr.bindthreads="1"'], ['net.isr.maxthreads' => '-1', 'net.isr.bindthreads' => '1'], $keys) === [],
    'Nothing is pending when the kernel booted with the wanted values.');
check(array_keys(netperf_pending(['net.isr.maxthreads="-1"', 'net.isr.bindthreads="1"'], [], $keys)) === ['net.isr.maxthreads', 'net.isr.bindthreads'],
    'New values are pending until a reboot.');
check(netperf_pending([], ['kern.ipc.nmbclusters' => '1000000'], $keys) === ['kern.ipc.nmbclusters' => ['wanted' => null, 'booted' => '1000000']],
    'A removed value is pending while the kernel still has it.');

/* mbuf zone from vmstat -z --libxo=json */
$json = json_encode(['vmstat' => ['memory-zone-statistics' => ['zone' => [
	['name' => 'mbuf', 'limit' => 0, 'used' => 10, 'free' => 5, 'fail' => 0],
	['name' => 'mbuf_cluster', 'limit' => 507647, 'used' => 1534, 'free' => 1014, 'fail' => 3],
]]]]);
check(netperf_parse_mbuf_zone($json) === ['limit' => 507647, 'used' => 1534, 'free' => 1014, 'failures' => 3], 'The mbuf cluster zone parses.');
check(netperf_parse_mbuf_zone('{}') === null && netperf_parse_mbuf_zone('not json') === null, 'Missing data gives null.');

/* pf SYN cookies, checked against the FreeBSD 16 pfctl grammar (parse.y syncookie_opt) */
check(netperf_pf_syncookies('', '', '') === null && netperf_pf_syncookies('never', '', '') === null, 'Never writes nothing (pf default).');
check(netperf_pf_syncookies('always', '50', '10') === 'set syncookies always', 'Always ignores the watermarks.');
check(netperf_pf_syncookies('adaptive', '', '') === 'set syncookies adaptive (start 25%, end 12%)', 'Adaptive defaults to 25/12.');
check(netperf_pf_syncookies('adaptive', '70', '40') === 'set syncookies adaptive (start 70%, end 40%)', 'Adaptive takes start and end.');
check(netperf_pf_syncookies('adaptive', '10', '20') === null && netperf_pf_syncookies('adaptive', '0', '') === null &&
    netperf_pf_syncookies('adaptive', "50%, end 1%) \nset skip on", '') === null && netperf_pf_syncookies('sometimes', '', '') === null,
    'Invalid values never reach pf.conf.');
check(netperf_validate_syncookies('adaptive', '10', '20') !== [] && netperf_validate_syncookies('adaptive', '101', '') !== [] &&
    netperf_validate_syncookies('bogus', '', '') !== [] && netperf_validate_syncookies('always', 'x', 'y') === [],
    'Validation refuses bad modes and percentages (only for adaptive).');
$filter = file_get_contents("{$root}/src/etc/inc/filter.inc");
check(strpos($filter, "\$syncookies = netperf_pf_syncookies((string)config_get_path('system/syncookies', ''),") !== false,
    'The pf options carry the SYN cookie line.');
$fw = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_advanced_firewall.inc");
check(strpos($fw, "'syncookies' => ['enum', 'label' => gettext('SYN Cookies'), 'values' => ['never', 'adaptive', 'always']],") !== false &&
    strpos($fw, 'foreach (netperf_validate_syncookies(') !== false, 'The Firewall & NAT saver checks the SYN cookie fields.');

/* wiring */
$utils = file_get_contents("{$root}/src/etc/inc/freesense-utils.inc");
check(strpos($utils, "require_once(\"netperf.inc\");") !== false, 'freesense-utils.inc loads netperf.inc.');
check(strpos($utils, "\$data = array_merge(\$data, netperf_current_loader_lines());") !== false, 'setup_loader_settings() writes the netperf lines.');
check(strpos($utils, "\"net.pf.states_hashsize\"\n\t\t), netperf_loader_keys());") !== false, 'load_loader_conf() treats the netperf keys as managed.');

$tabs = file_get_contents("{$root}/src/usr/local/www/includes/tabs/system.inc");
check(strpos($tabs, "[gettext('Performance'), 'system_advanced_performance.php'],") !== false, 'The Performance tab is registered.');
$privs = file_get_contents("{$root}/src/etc/inc/priv.defs.inc");
check(strpos($privs, "\$priv_list['page-system-advanced-performance']['match'][] = \"system_advanced_performance.php*\";") !== false,
    'The Performance page has its privilege.');

$page = file_get_contents("{$root}/src/usr/local/www/system_advanced_performance.php");
foreach (["'netisr_threads',", "'nmbclusters',", 'saveSystemAdvancedPerformance($_POST)', "'powerd_enable',", "'pti_disabled',", "'mds_disable',",
    "'crypto_hardware',", "'thermal_hardware',", 'netperf_pending('] as $needle) {
	check(strpos($page, $needle) !== false, "The Performance page is missing {$needle}");
}
$misc_page = file_get_contents("{$root}/src/usr/local/www/system_advanced_misc.php");
check(strpos($misc_page, 'saveSystemAdvancedMisc(systemAdvancedMiscKeepFields($_POST, getSystemAdvancedHardwareFields()))') !== false,
    'Saving Miscellaneous keeps the fields it no longer shows.');
foreach (["'powerd_enable',", "'crypto_hardware',", "'pti_disabled',", "'hwpstate_epp',"] as $moved) {
	check(strpos($misc_page, $moved) === false, "Miscellaneous must no longer render {$moved}");
}
$logic = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_advanced_performance.inc");
check(strpos($logic, '$misc = saveSystemAdvancedMisc($misc_post);') !== false, 'The Performance page saves the hardware fields through the Miscellaneous logic.');

$routes = file_get_contents("{$root}/src/etc/inc/restapi/routes_system.inc");
check(strpos($routes, "restapi_route('GET', '/v1/system/advanced/performance', 'restapi_h_sysadv_perf_get'") !== false &&
    strpos($routes, "restapi_route('PUT', '/v1/system/advanced/performance', 'restapi_h_sysadv_perf_set'") !== false,
    'The REST API has the Performance route.');
check(strpos($routes, "saveSystemAdvancedPerformance(array('netisr_threads' => \$values['netisr_threads'], 'nmbclusters' => \$values['nmbclusters']), false)") !== false,
    'The REST route saves only the network settings (the hardware fields stay on the misc route).');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi.inc"), "'ha-link', 'performance');") !== false, 'The performance capability is reported.');

echo "Performance smoke test passed.\n";
