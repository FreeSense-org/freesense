<?php
/* Standalone CI regression test; run with `php tests/PackageRestoreSmokeTest.php`. */

require_once(__DIR__ . '/../src/etc/inc/package_restore.inc');
require_once(__DIR__ . '/../src/etc/inc/xmlparse.inc');

function check_package_restore($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function package_restore_fixture() {
	return array(
		'installedpackages' => array(
			'package' => array(
				array(
					'name' => 'Secure Web Gateway',
					'internal_name' => 'WebGateway',
					'configurationfile' => 'webgateway.xml',
				),
				array(
					'name' => 'Retired Package',
					'internal_name' => 'oldthing',
					'configurationfile' => 'oldthing.xml',
				),
			),
			'menu' => array(array(
				'name' => 'Ghost Menu',
				'section' => 'Services',
				'url' => '/ghost.php',
			)),
			'service' => array(array('name' => 'ghost')),
			'webgateway' => array('config' => array(array('enabled' => 'on'))),
			'oldthing' => array('config' => array(array('value' => 'preserve-me'))),
			'miniupnpd' => array('config' => array(array('enable' => 'on'))),
			'openntpd' => array('config' => array(array('interface' => 'lan'))),
			'mystery' => array('config' => array(array('value' => 'unknown-owner'))),
		),
	);
}

$config = package_restore_fixture();
$result = freesense_package_restore_extract(
    $config, array('WebGateway'), array('WebGateway'));

check_package_restore(
    !isset($config['installedpackages']['package']) &&
    !isset($config['installedpackages']['menu']) &&
    !isset($config['installedpackages']['service']),
    'restored package inventory, menus, or services remained active');
check_package_restore(
    isset($config['installedpackages']['miniupnpd']) &&
    isset($config['installedpackages']['openntpd']),
    'base-system settings stored under installedpackages were removed');
check_package_restore(
    !isset($config['installedpackages']['webgateway']) &&
    !isset($config['installedpackages']['oldthing']) &&
    !isset($config['installedpackages']['mystery']),
    'optional or unowned package settings remained active');
check_package_restore(
    count($result['pending']) === 1 &&
    $result['pending'][0]['target'] === 'WebGateway' &&
    isset($result['pending'][0]['settings']['webgateway']),
    'available selected package settings were not isolated as pending');

$quarantine_reasons = array_column($result['quarantine'], 'reason', 'internal_name');
check_package_restore(
    ($quarantine_reasons['oldthing'] ?? '') === 'missing',
    'missing package settings were not quarantined');
check_package_restore(
    ($quarantine_reasons['mystery'] ?? '') === 'orphan',
    'unowned package settings were not quarantined');

$round_trip_path =
    freesense_package_restore_xml_path('Secure Web Gateway');
$round_trip_config = array('installedpackages' => array('package' => array(
	array(
		'name' => 'Secure Web Gateway',
		'internal_name' => 'WebGateway',
		'restore_config_paths' => $round_trip_path,
	),
)));
$round_trip_xml = dump_xml_config($round_trip_config, 'freesense');
check_package_restore(
    !str_contains($round_trip_xml, '<0>') &&
    !str_contains($round_trip_xml, '</0>'),
    'package restore metadata serialized numeric XML element names');
$round_trip_file = tempnam(sys_get_temp_dir(), 'freesense-restore-xml-');
check_package_restore(
    $round_trip_file !== false &&
    file_put_contents($round_trip_file, $round_trip_xml) !== false,
    'package restore XML fixture could not be written');
$round_trip_parsed = parse_xml_config($round_trip_file, array('freesense'));
@unlink($round_trip_file);
check_package_restore(
    ($round_trip_parsed['installedpackages']['package'][0]
        ['restore_config_paths'] ?? '') ===
        'installedpackages/securewebgateway',
    'package restore metadata did not survive an XML round trip');

$offline_config = package_restore_fixture();
$offline = freesense_package_restore_extract($offline_config, null, null);
$offline_status = array_column($offline['pending'], 'status', 'internal_name');
check_package_restore(
    ($offline_status['WebGateway'] ?? '') === 'unknown' &&
    ($offline_status['oldthing'] ?? '') === 'unknown',
    'repository failure was treated as proof that packages are missing');
check_package_restore(
    !in_array('missing', array_column($offline['quarantine'], 'reason'), true),
    'offline restore destructively quarantined an inventoried package');

$deselected_config = package_restore_fixture();
$deselected = freesense_package_restore_extract(
    $deselected_config, array('WebGateway', 'oldthing'), array());
$deselected_reasons = array_column(
    $deselected['quarantine'], 'reason', 'internal_name');
check_package_restore(
    ($deselected_reasons['WebGateway'] ?? '') === 'deselected' &&
    ($deselected_reasons['oldthing'] ?? '') === 'deselected',
    'unchecked available package settings were not quarantined');

$specific_config = array('installedpackages' => array(
	'package' => array(
		array('name' => 'Web Gateway', 'internal_name' => 'WebGateway'),
		array('name' => 'Web Gateway AV', 'internal_name' => 'WebGateway-AV'),
	),
	'webgateway' => array('owner' => 'base'),
	'webgatewayav' => array('owner' => 'av'),
));
$specific = freesense_package_restore_extract(
    $specific_config, array('WebGateway', 'WebGateway-AV'), null);
$specific_pending = array_column($specific['pending'], null, 'internal_name');
check_package_restore(
    isset($specific_pending['WebGateway']['settings']['webgateway']) &&
    !isset($specific_pending['WebGateway']['settings']['webgatewayav']) &&
    isset($specific_pending['WebGateway-AV']['settings']['webgatewayav']) &&
    !isset($specific_pending['WebGateway-AV']['settings']['webgateway']),
    'specific package names claimed settings belonging to a broader package');

$signature_a = freesense_package_restore_inventory_signature(array(
	'installedpackages' => array('package' => array(
		array('name' => 'Display A', 'internal_name' => 'package-a'),
		array('name' => 'Display B', 'internal_name' => 'package-b'),
	)),
));
$signature_b = freesense_package_restore_inventory_signature(array(
	'installedpackages' => array('package' => array(
		array('name' => 'Renamed B', 'internal_name' => 'package-b'),
		array('name' => 'Renamed A', 'internal_name' => 'package-a'),
	)),
));
check_package_restore(
    hash_equals($signature_a, $signature_b),
    'package inventory signature depends on display name or ordering');

$test_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'freesense-package-restore-' . bin2hex(random_bytes(6));
$g = array('conf_path' => $test_root);
$saved = freesense_package_restore_save($result, 'smoke-test');
check_package_restore(
    is_array($saved) && $saved['pending'] === 1 &&
    $saved['quarantined'] === 2,
    'pending/quarantine state was not saved');
check_package_restore(
    is_readable(freesense_package_restore_pending_path()),
    'pending restore manifest is unreadable');
$records = freesense_package_restore_list_quarantine();
check_package_restore(
    count($records) === 1 &&
    count($records[0]['packages']) === 2,
    'quarantine record could not be listed');
check_package_restore(
    freesense_package_restore_quarantine_path('../config') === null,
    'unsafe quarantine identifier was accepted');
check_package_restore(
    freesense_package_restore_delete_quarantine($records[0]['id']) &&
    empty(freesense_package_restore_list_quarantine()),
    'explicit quarantine deletion failed');

$config = array('system' => array('hostname' => 'restore-test'));
$prepared = freesense_package_restore_prepare_active(
    null, 'replacement-smoke-test', true);
check_package_restore(
    is_array($prepared) && !$prepared['existing'] &&
    !file_exists(freesense_package_restore_pending_path()),
    'a new package-free restore retained stale pending package intent');

@unlink(freesense_package_restore_pending_path());
@unlink($test_root . DIRECTORY_SEPARATOR . 'needs_package_sync');
@rmdir(freesense_package_restore_quarantine_dir());
@rmdir($test_root);

if (!function_exists('array_get_path')) {
	function array_get_path(array &$array, string $path, $default = null) {
		$value = &$array;
		foreach (explode('/', trim($path, '/')) as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) {
				return $default;
			}
			$value = &$value[$part];
		}
		return $value;
	}
}
require_once(__DIR__ . '/../src/etc/inc/config_import_pkgmap.inc');
$source_config = array('installedpackages' => array('package' => array(
	array('name' => 'Secure Web Gateway', 'internal_name' => 'WebGateway'),
)));
$source_packages = freesense_import_source_packages($source_config, 'freesense');
check_package_restore(
    count($source_packages) === 1 &&
    $source_packages[0]['name'] === 'WebGateway' &&
    $source_packages[0]['raw'] === 'Secure Web Gateway',
    'same-lineage restore used the display name instead of internal package name');

echo "Package restore reconciliation: valid\n";
