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
	    strpos($helper, 'for candidate in /usr/sbin/zdb /rescue/zdb /sbin/zdb') !== false,
	    'ZFS recovery does not resolve zdb from the FreeBSD 16 base layout');
	check_recovery(
	    strpos($helper, '/usr/bin/timeout 15 "${zdb_tool}" -l') !== false,
	    'ZFS recovery still invokes a hard-coded zdb path');
	check_recovery(
	    strpos($helper, '/usr/bin/timeout 15 /sbin/zdb') === false,
	    'ZFS recovery uses the nonexistent FreeBSD 16 /sbin/zdb path');
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
check_recovery(
    strpos($import, 'Detected a FreeSense configuration.') !== false &&
    strpos($import, '/bin/cp "${SRC}" "${STAGE}"') !== false,
    'installer import still rejects already-converted FreeSense configurations');

$assemble = file_get_contents(__DIR__ . '/../tools/ci/freesense-assemble-iso.sh');
check_recovery($assemble !== false, 'ISO assembly implementation is unreadable');
check_recovery(
    strpos($assemble, '${_root}/usr/sbin/zdb') !== false &&
    strpos($assemble, '${_root}/rescue/zdb') !== false,
    'ISO assembly does not refuse media missing the ZFS inspection tool');

echo "Installer recovery helpers: valid\n";
