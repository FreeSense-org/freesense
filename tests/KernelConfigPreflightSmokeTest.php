<?php
/* Standalone CI guard for validating kernel configs before toolchain work. */

$builder = file_get_contents(__DIR__ . '/../tools/builder_common.sh');
if ($builder === false) {
	fwrite(STDERR, "Unable to read builder_common.sh\n");
	exit(1);
}

$configCall = '/usr/sbin/config -I "${FREEBSD_SRC_DIR}/sys/${TARGET}/conf"';
$toolchain = 'pkgbase: building kernel-toolchain';
$configPosition = strpos($builder, $configCall);
$toolchainPosition = strpos($builder, $toolchain);
if ($configPosition === false || $toolchainPosition === false ||
    $configPosition >= $toolchainPosition) {
	fwrite(STDERR, "Kernel config validation must run before kernel-toolchain\n");
	exit(1);
}

foreach ([
	'-I "${FREEBSD_SRC_DIR}/sys/conf"',
	'-d "${_config_dir}" -s "${FREEBSD_SRC_DIR}/sys"',
	'mktemp -d "${SCRATCHDIR}/kernel-config-check.XXXXXX"',
	'kernel configuration ${_kernconf} is incompatible with pinned FreeBSD',
	'tail -n 120 "${LOGFILE}"',
] as $needle) {
	if (strpos($builder, $needle) === false) {
		fwrite(STDERR, "Missing kernel config preflight guard: {$needle}\n");
		exit(1);
	}
}

echo "Kernel config preflight smoke test passed.\n";
