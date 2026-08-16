<?php
/* Standalone CI guard for target-specific FreeBSD kernel module selection. */

$defaults = file_get_contents(__DIR__ . '/../tools/builder_defaults.sh');
$sample = file_get_contents(__DIR__ . '/../build.conf.sample');
if ($defaults === false || $sample === false) {
	fwrite(STDERR, "Unable to read kernel module policy inputs\n");
	exit(1);
}

if (preg_match('/^export MODULES_OVERRIDE=/m', $sample)) {
	fwrite(STDERR, "build.conf.sample must not override target-specific modules\n");
	exit(1);
}

if (!preg_match('/^\texport MODULES_OVERRIDE_arm64="([^"]+)"/m', $defaults, $matches)) {
	fwrite(STDERR, "ARM64 kernel module policy is missing\n");
	exit(1);
}

foreach (['aesni', 'amdsmn', 'amdtemp', 'coretemp', 'vmm'] as $module) {
	if (preg_match('/(^| )' . preg_quote($module, '/') . '( |$)/', $matches[1])) {
		fwrite(STDERR, "ARM64 kernel modules include x86-only module {$module}\n");
		exit(1);
	}
}

$amd64 = '${MODULES_OVERRIDE_base} aesni amdsmn amdtemp blake2 coretemp cpuctl cxgbe/tom ipmi ix ixv nmdm qlnx sfxge vmm';
if (strpos($defaults, 'MODULES_OVERRIDE_amd64="' . $amd64 . '"') === false) {
	fwrite(STDERR, "amd64 kernel module policy changed unexpectedly\n");
	exit(1);
}

echo "Target-specific kernel module policy smoke test passed.\n";
