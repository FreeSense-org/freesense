<?php
$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/usr/local/www/interfaces_nic_settings.php');
$utils = file_get_contents($root . '/src/etc/inc/freesense-utils.inc');
$advanced = file_get_contents($root . '/src/usr/local/www/system_advanced_network.php');
$menu = file_get_contents($root . '/src/usr/local/www/head.inc');

foreach (['overview', 'configure', 'recommendations', 'diagnostics', 'Confirm and apply', 'hn_altq_enable'] as $required) {
	if (strpos($page, $required) === false) {
		fwrite(STDERR, "NIC Settings page is missing {$required}.\n");
		exit(1);
	}
}

foreach (['nic_settings_profiles', 'nic_settings_effective', "'firewall'", "'throughput'", "'latency'", "'capture'"] as $required) {
	if (strpos($utils, $required) === false) {
		fwrite(STDERR, "NIC policy backend is missing {$required}.\n");
		exit(1);
	}
}

foreach (['disablechecksumoffloading', 'disablesegmentationoffloading', 'disablelargereceiveoffloading'] as $removed) {
	if (strpos($advanced, "new Form_Checkbox(\n\t'{$removed}'") !== false) {
		fwrite(STDERR, "Advanced Networking still renders the legacy {$removed} control.\n");
		exit(1);
	}
}

if (strpos($menu, '/interfaces_nic_settings.php') === false) {
	fwrite(STDERR, "Interfaces menu does not link to NIC Settings.\n");
	exit(1);
}

echo "NIC Settings smoke test passed.\n";
