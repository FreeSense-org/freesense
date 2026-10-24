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

foreach (['aesni', 'amdsmn', 'amdtemp', 'coretemp', 'cpuctl', 'ipsecmb', 'qat', 'qat_c2xxx', 'qatfw', 'vmm'] as $module) {
	if (preg_match('/(^| )' . preg_quote($module, '/') . '( |$)/', $matches[1])) {
		fwrite(STDERR, "ARM64 kernel modules include x86-only module {$module}\n");
		exit(1);
	}
}

/*
 * Optional packages load if_wg at runtime, and ossl is a crypto accelerator
 * offered under System > Advanced > Miscellaneous, so every target ships them.
 */
if (!preg_match('/^\texport MODULES_OVERRIDE_base="([^"]+)"/m', $defaults, $base)) {
	fwrite(STDERR, "Base kernel module policy is missing\n");
	exit(1);
}
foreach (['if_wg', 'ossl'] as $module) {
	if (!preg_match('/(^| )' . preg_quote($module, '/') . '( |$)/', $base[1])) {
		fwrite(STDERR, "Base kernel modules must include {$module}\n");
		exit(1);
	}
}

/*
 * QAT (qat, qat_c2xxx) and its firmware (qatfw, qat_c2xxxfw) are amd64-only FreeBSD modules.
 * ipsecmb (Intel IPsec-MB) is amd64-only and built from freesense-os-base patch 0010.
 */
$amd64 = '${MODULES_OVERRIDE_base} aesni amdsmn amdtemp blake2 coretemp cpuctl cxgbe/tom if_vxlan ipmi ipsecmb ix ixv nmdm qat qat_c2xxx qat_c2xxxfw qatfw qlnx sfxge vmm';
if (strpos($defaults, 'MODULES_OVERRIDE_amd64="' . $amd64 . '"') === false) {
	fwrite(STDERR, "amd64 kernel module policy changed unexpectedly\n");
	exit(1);
}

/*
 * VXLAN interfaces load if_vxlan at runtime. The amd64 kernel has no vxlan
 * device, so amd64 ships the module; the arm64 kernel config has
 * "device vxlan" built in, so a module there would duplicate it.
 */
foreach ([$base[1], $matches[1]] as $list) {
	if (preg_match('/(^| )if_vxlan( |$)/', $list)) {
		fwrite(STDERR, "if_vxlan must only be built as a module on amd64\n");
		exit(1);
	}
}

/* The build must fail when a runtime-loaded driver is missing from the kernel package. */
$common = file_get_contents(__DIR__ . '/../tools/builder_common.sh');
if ($common === false ||
    !preg_match('/^\t\tensure_kernel_drivers \$KERNEL_DESTDIR$/m', $common) ||
    !preg_match('/_drivers="if_wg: if_vxlan:vxlan ossl:ossl"/', $common) ||
    !preg_match('/_drivers="\$\{_drivers\} qat: qat_c2xxx: ipsecmb:"/', $common) ||
    !preg_match('/for _driver in \$\{_drivers\}; do/', $common)) {
	fwrite(STDERR, "builder_common.sh must verify if_wg, if_vxlan/vxlan, ossl and (amd64) qat and ipsecmb in every kernel package\n");
	exit(1);
}

echo "Target-specific kernel module policy smoke test passed.\n";
