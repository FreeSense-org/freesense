<?php
/* Standalone CI regression test; run with `php tests/PackageRegistrationSmokeTest.php`. */

require_once(__DIR__ . '/../src/etc/inc/package_registration.inc');

function check_package_registration($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$stored_menus = array(
	array(
		'name' => 'Suricata',
		'section' => 'Services',
		'url' => '/suricata/old.php',
	),
	array(
		'name' => 'Old Suricata Status',
		'section' => 'Status',
		'url' => '/suricata/old-status.php',
		'package' => 'suricata',
	),
	array(
		'name' => 'HAProxy',
		'section' => 'Services',
		'url' => '/haproxy/haproxy_listeners.php',
		'package' => 'haproxy',
	),
);
$declared_menus = array(array(
	'name' => 'Suricata',
	'section' => 'Services',
	'url' => '/suricata/suricata_overview.php',
));
$menus = freesense_package_reconcile_generated_entries(
    $stored_menus, $declared_menus, 'suricata', array('name', 'section'));

check_package_registration(count($menus) === 2,
    'stale package-owned menu entries were retained');
$by_name = array_column($menus, null, 'name');
check_package_registration(
    ($by_name['Suricata']['url'] ?? '') === '/suricata/suricata_overview.php' &&
    ($by_name['Suricata']['package'] ?? '') === 'suricata',
    'legacy menu entry was not upgraded to current owned metadata');
check_package_registration(
    ($by_name['HAProxy']['package'] ?? '') === 'haproxy',
    'another package menu was modified');

$services = freesense_package_reconcile_generated_entries(
    array(
	array('name' => 'suricata', 'rcfile' => 'old.sh', 'package' => 'suricata'),
	array('name' => 'haproxy', 'rcfile' => 'haproxy.sh', 'package' => 'haproxy'),
    ),
    array(array('name' => 'suricata', 'rcfile' => 'suricata.sh')),
    'suricata', array('name'));
$services_by_name = array_column($services, null, 'name');
check_package_registration(
    count($services) === 2 &&
    ($services_by_name['suricata']['rcfile'] ?? '') === 'suricata.sh' &&
    ($services_by_name['suricata']['package'] ?? '') === 'suricata',
    'owned service metadata was not replaced');

$package_utilities = file_get_contents(
    __DIR__ . '/../src/etc/inc/pkg-utils.inc');
check_package_registration(
    strpos($package_utilities, 'refresh_package_generated_metadata($package_target)') !== false,
    'forced package reinstall does not explicitly refresh generated metadata');

echo "Package registration reconciliation: valid\n";

