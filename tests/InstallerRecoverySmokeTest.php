<?php
/* Standalone CI smoke test; run with `php tests/InstallerRecoverySmokeTest.php`. */

function check_recovery($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$recover = file_get_contents(__DIR__ . '/../tools/installer/recover_configxml.sh');
$import = file_get_contents(__DIR__ . '/../tools/installer/import_foreign_config.sh');

check_recovery($recover !== false, 'recovery helper is unreadable');
check_recovery($import !== false, 'foreign import helper is unreadable');

foreach (array($recover, $import) as $helper) {
	check_recovery(
	    strpos($helper, '> "${zdb_log}" 2>&1') !== false ||
	    strpos($helper, '>"${zdb_log}" 2>&1') !== false,
	    'ZFS label output does not capture both stdout and stderr');
	check_recovery(
	    strpos($helper, "\$2 == \"/\"") !== false,
	    'ZFS root discovery has no mountpoint fallback');
	check_recovery(
	    strpos($helper, 'suffix="${mp}"') !== false ||
	    strpos($helper, 'suffix="${wanted_mountpoint}"') !== false,
	    'ZFS config dataset discovery has no legacy-name fallback');
}

check_recovery(
    strpos($recover, '--fselect "${browse_root}/"') !== false,
    'advanced recovery has no path selector');
check_recovery(
    strpos($recover, '--yes-label "Force copy"') !== false,
    'forced recovery has no explicit warning confirmation');
check_recovery(
    strpos($recover, '"${browse_root}"/*)') !== false,
    'manual selection is not confined to the mounted source');
check_recovery(
    strpos($recover, 'Select system root dataset') !== false,
    'advanced recovery has no dataset selector');
check_recovery(
    strpos($recover, 'readonly=on') !== false,
    'ZFS recovery import is not read-only');

echo "Installer recovery helpers: valid\n";
