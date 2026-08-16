<?php
/* Standalone CI guard for the seeded ARM64-world cross compiler. */

$builder = file_get_contents(__DIR__ . '/../tools/builder_common.sh');
if ($builder === false) {
	fwrite(STDERR, "Unable to read builder_common.sh\n");
	exit(1);
}

$required = [
	'configure_seeded_world_compiler',
	'--target=${TARGET_ARCH}-unknown-freebsd${_freebsd_major}.0',
	'--sysroot=${STAGE_CHROOT_DIR}',
	'seeded-world compiler cannot link a ${TARGET_ARCH} executable',
	'tail -n 120 "${LOGFILE}"',
];
foreach ($required as $needle) {
	if (strpos($builder, $needle) === false) {
		fwrite(STDERR, "Missing ARM64 cross-compiler guard: {$needle}\n");
		exit(1);
	}
}

if (substr_count($builder, "\t\tconfigure_seeded_world_compiler\n") !== 2) {
	fwrite(STDERR, "Both seeded-world paths must configure the compiler\n");
	exit(1);
}

echo "ARM64 seeded-world cross-compiler smoke test passed.\n";
