<?php
/*
 * Standalone CI regression test for the NIC Settings REST API
 * (restapi/routes_nicsettings.inc): the route table, the PUT merge rules and
 * the shape of one adapter; run with `php tests/RestApiNicSettingsSmokeTest.php`.
 */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('restapi/framework.inc');
require_once('restapi/routes_nicsettings.inc');
require_once('nic_tuning.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* the page logic's labels and capability check, loaded as written (the file needs the appliance to include) */
$logic = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/interfaces_nic_settings.inc");
foreach (['nic_ui_setting_labels', 'nic_ui_tuning_labels', 'nic_ui_supported'] as $fn) {
	check(preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $logic, $m) === 1, "interfaces_nic_settings.inc defines {$fn}()");
	eval($m[0]);
}
function restapi_svc_choices(array $options) {
	$out = array();
	foreach ($options as $value => $label) {
		$out[] = array('value' => (string)$value, 'label' => $label);
	}
	return $out;
}

/* routes */
$routes = array();
foreach (restapi_routes_nicsettings() as $route) {
	$routes["{$route['method']} {$route['path']}"] = $route;
}
$base = '/v1/interfaces/nic-settings';
foreach (array("GET {$base}" => false, "PUT {$base}" => true, "GET {$base}/adapters/{id}" => false,
    "PUT {$base}/adapters/{id}" => true, "POST {$base}/adapters/{id}/apply-recommended" => true) as $key => $write) {
	check(isset($routes[$key]), "route {$key} exists");
	check(!empty($routes[$key]['write']) === $write, "route {$key} write flag");
}
$v1 = file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc");
check(strpos($v1, "require_once('restapi/routes_nicsettings.inc');") !== false && strpos($v1, 'restapi_routes_nicsettings(),') !== false,
    'the v1 route table includes the NIC Settings routes');
check(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('interfaces_nic_settings.inc');") !== false,
    'the API loads the page logic');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi.inc"), "'nic-settings');") !== false, 'the nic-settings capability is reported');

/* PUT merge: only the fields it sends, strict names and types */
$current = array('settings' => array('checksum' => 'inherit', 'tso' => 'off'), 'mtu' => '', 'tuning' => array('fc' => '0'));
$names = array_keys(nic_ui_setting_labels());
$tnames = array_keys(nic_ui_tuning_labels());
$merged = restapi_nic_merge(array('settings' => array('checksum' => 'off'), 'tuning' => array('tx_abdicate' => 1), 'mtu' => 9000),
    $current, $names, $tnames);
check($merged === array('settings' => array('checksum' => 'off', 'tso' => 'off'), 'mtu' => '9000',
    'tuning' => array('fc' => '0', 'tx_abdicate' => '1')), 'a PUT changes only what it sends: ' . json_encode($merged));
check(restapi_nic_merge(array(), $current, $names, $tnames) === $current, 'an empty PUT changes nothing');
$refused = array(
	array('bogus' => 1),
	array('settings' => array('turbo' => 'on')),
	array('tuning' => array('rx_budget' => '64')),
	array('settings' => array('on', 'off')),
	array('tuning' => array('fc' => array('0'))),
	array('mtu' => array(1500)),
	array('settings' => 'checksum=off'),
);
foreach ($refused as $body) {
	$threw = false;
	try {
		restapi_nic_merge($body, $current, $names, $tnames);
	} catch (RestApiError $e) {
		$threw = true;
	}
	check($threw, 'refused body: ' . json_encode($body));
}

/* one adapter as the API shows it */
$detect = nic_tuning_detect('em0', nic_tuning_parse_sysctl('dev.em.0', file("{$root}/tests/fixtures/nic/em0.sysctl", FILE_IGNORE_NEW_LINES)));
$nic = array('id' => 'nic_00_0c_29_ec_17_87', 'device' => 'em0', 'driver' => 'em', 'assigned' => true, 'assignment' => 'LAN',
    'hwaddr' => '00:0c:29:ec:17:87', 'up' => true, 'media' => '1000baseT <full-duplex>', 'mtu' => 1500,
    'caps' => array('txcsum' => true, 'rxcsum' => true, 'vlanhwtag' => true), 'encaps' => array(),
    'effective' => array('checksum' => 'on', 'tso' => 'off', 'lro' => 'off', 'vlan' => 'on', 'wol' => 'off', 'ipsec' => 'off',
    'profile' => 'firewall', 'id' => 'nic_00_0c_29_ec_17_87'), 'tuning' => $detect);
$out = restapi_nic_out($nic, $current, 'firewall', 4, 'vmware');
check($out['id'] === 'nic_00_0c_29_ec_17_87' && $out['device'] === 'em0' && $out['assignment'] === 'LAN', 'identity fields');
check($out['supported'] === array('checksum' => true, 'tso' => false, 'lro' => false, 'vlan' => true, 'wol' => false, 'ipsec' => false),
    'supported offloads follow the capabilities');
check(!isset($out['effective']['profile']) && !isset($out['effective']['id']), 'effective holds only the settings');
check($out['tuning']['recommended'] === array('fc' => '0', 'tx_abdicate' => '1') && isset($out['tuning']['differences']['fc']),
    'the firewall profile recommendations are listed with their differences');
check($out['tuning']['apply']['queues'] === 'boot' && $out['tuning']['apply']['fc'] === 'live', 'each control says when it applies');
check(count($out['tuning']['choices']['aim']) === 3 && !isset($out['tuning']['choices']['queues']), 'choice controls list their values');
check($out['hardware']['pci']['device'] === '100f' && $out['hardware']['rx_queues'] === 1, 'hardware detection is included');

/* empty maps must encode as JSON objects, not lists (seen on a VirtIO adapter without tuning controls) */
$vt = $nic;
$vt['tuning'] = nic_tuning_detect('vtnet1', array('%driver' => 'vtnet'));
$json = json_encode(restapi_nic_out($vt, array('settings' => array(), 'mtu' => '', 'tuning' => array()), 'capture', 4, 'kvm'));
check(strpos($json, '"controls":{}') !== false && strpos($json, '"differences":{}') !== false && strpos($json, '"tuning":{}') !== false &&
    strpos($json, '"counters":{}') !== false, 'empty maps are {} in JSON: ' . substr($json, 0, 0));

echo "OK: NIC Settings REST API\n";
