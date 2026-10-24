<?php
/*
 * Standalone CI regression test; run with `php tests/CpuFeaturesSmokeTest.php`.
 *
 * CPU support: the early microcode loader settings, what the dashboard shows
 * about microcode and crypto instructions, and Intel Speed Shift / AMD CPPC.
 * The real functions are taken from the source files and run against stubs.
 */

function check_cpu($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function cpu_extract_function(string $file, string $name): string {
	$src = file_get_contents(__DIR__ . '/../' . $file);
	check_cpu($src !== false, "cannot read {$file}");
	check_cpu(preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $src, $m) === 1,
	    "{$file} must define {$name}()");
	return $m[0];
}

/* Stubs: sysctls, configuration, boot messages and the sysctls written. */
$GLOBALS['sysctl'] = [];
$GLOBALS['cfg'] = [];
$GLOBALS['written'] = [];
$root = sys_get_temp_dir() . '/cpu-smoke-' . getmypid();
@mkdir("{$root}/log", 0700, true);
@mkdir("{$root}/boot/firmware", 0700, true);
$g = ['varlog_path' => "{$root}/log"];

function get_single_sysctl($name) { return $GLOBALS['sysctl'][$name] ?? ''; }
function set_single_sysctl($name, $value) { $GLOBALS['written'][$name] = $value; }
function config_get_path($path, $default = null) { return $GLOBALS['cfg'][$path] ?? $default; }
function config_path_enabled($section, $key) { return !empty($GLOBALS['cfg']["{$section}/{$key}"]); }
function gettext($s) { return $s; }

$code = '';
foreach (['cpu_vendor', 'cpu_microcode_file', 'cpu_microcode_parse', 'cpu_microcode_status',
    'cpu_crypto_features_parse', 'cpu_crypto_features'] as $fn) {
	$code .= cpu_extract_function('src/etc/inc/freesense-utils.inc', $fn);
}
foreach (['system_hwpstate_vendor', 'system_hwpstate_active', 'system_hwpstate_epp_node',
    'system_hwpstate_getepp', 'system_hwpstate_setepp'] as $fn) {
	$code .= cpu_extract_function('src/etc/inc/system.inc', $fn);
}
eval($code);

function cpu_boot(array $lines) {
	global $root;
	file_put_contents("{$root}/log/dmesg.boot", implode("\n", $lines) . "\n");
}

/* --- Early microcode: the loader file ------------------------------------ */
$intel_boot = [
	'CPU: Intel(R) Atom(TM) CPU C3758 @ 2.20GHz (2200.08-MHz K8-class CPU)',
	'  Origin="GenuineIntel"  Id=0x506f1  Family=0x6  Model=0x5f  Stepping=1',
	'  Features2=0x4ff8ebbf<SSE3,PCLMULQDQ,DTES64,MON,DS_CPL,VMX,EST,TM2,SSSE3,SDBG,CX16,xTPR,PDCM,SSE4.1,SSE4.2,x2APIC,MOVBE,POPCNT,TSCDLT,AESNI,XSAVE,OSXSAVE,RDRAND>',
	'  Structured Extended Features=0x2294e283<FSGSBASE,TSCADJ,SMEP,ERMS,NFPUSG,MPX,PQE,RDSEED,SMAP,CLFLUSHOPT,PROCTRACE,SHA>',
	'CPU microcode: updated from 0x2e to 0x38',
];
touch("{$root}/boot/firmware/intel-ucode.bin");
cpu_boot($intel_boot);
$GLOBALS['sysctl'] = ['hw.machine' => 'amd64', 'kern.vm_guest' => 'none', 'hw.model' => 'Intel(R) Atom(TM) CPU C3758'];
check_cpu(cpu_vendor() === 'intel', 'Intel from Origin=');
check_cpu(cpu_microcode_file($root) === '/boot/firmware/intel-ucode.bin', 'Intel bare metal loads intel-ucode.bin');

$GLOBALS['cfg'] = ['system/microcode_early_disable' => true];
check_cpu(cpu_microcode_file($root) === null, 'the setting turns early loading off');
$GLOBALS['cfg'] = [];

$GLOBALS['sysctl']['kern.vm_guest'] = 'vmware';
check_cpu(cpu_microcode_file($root) === null, 'virtual machines are skipped');
$GLOBALS['sysctl']['kern.vm_guest'] = 'none';

$GLOBALS['sysctl']['hw.machine'] = 'arm64';
check_cpu(cpu_microcode_file($root) === null, 'arm64 has no x86 microcode');
$GLOBALS['sysctl']['hw.machine'] = 'amd64';

/* AMD whose file is not installed: nothing, then the file appears. */
cpu_boot(['  Origin="AuthenticAMD"  Id=0xb40f40  Family=0x1a  Model=0x44  Stepping=0']);
check_cpu(cpu_vendor() === 'amd', 'AMD from Origin=');
check_cpu(cpu_microcode_file($root) === null, 'no amd-ucode.bin, no loader line');
touch("{$root}/boot/firmware/amd-ucode.bin");
check_cpu(cpu_microcode_file($root) === '/boot/firmware/amd-ucode.bin', 'AMD loads amd-ucode.bin');

/* Without boot messages the model name decides; an unknown CPU gets nothing. */
@unlink("{$root}/log/dmesg.boot");
$GLOBALS['sysctl']['hw.model'] = 'QEMU Virtual CPU version 2.5+';
check_cpu(cpu_vendor() === null && cpu_microcode_file($root) === null, 'unknown vendor, no loader line');

/* The loader lines are written and managed by setup_loader_settings(). */
$utils = file_get_contents(__DIR__ . '/../src/etc/inc/freesense-utils.inc');
foreach (["\$microcode = cpu_microcode_file(\$path);", "'cpu_microcode_load=\"YES\"'", "'cpu_microcode_name=\"' . \$microcode . '\"'",
    "\"cpu_microcode_load\",", "\"cpu_microcode_name\",", "'machdep.hwpstate_amd_cppc_enable=\"0\"'", "\"machdep.hwpstate_amd_cppc_enable\","] as $needle) {
	check_cpu(strpos($utils, $needle) !== false, "setup_loader_settings() is missing {$needle}");
}

/* --- Microcode status ------------------------------------------------------ */
check_cpu(cpu_microcode_parse(['CPU microcode: updated from 0x2E to 0x38']) === ['state' => 'updated', 'from' => '0x2e', 'to' => '0x38'], 'updated');
check_cpu(cpu_microcode_parse(['CPU microcode: no matching update found'])['state'] === 'current', 'no newer update');
check_cpu(cpu_microcode_parse(['CPU microcode: microcode verification failed'])['reason'] === 'verification', 'verification failed');
check_cpu(cpu_microcode_parse(['CPU microcode load failed. BIOS update advised'])['reason'] === 'bios', 'BIOS update advised');
check_cpu(cpu_microcode_parse(['CPU: AMD Ryzen 9 9950X', 'random: unblocking device.']) === null, 'nothing reported');

cpu_boot($intel_boot);
check_cpu(cpu_microcode_status() === 'Updated at boot from 0x2e to 0x38', 'dashboard: ' . cpu_microcode_status());
$GLOBALS['sysctl']['kern.vm_guest'] = 'kvm';
check_cpu(cpu_microcode_status() === 'Provided by the hypervisor', 'dashboard in a VM');
$GLOBALS['sysctl']['kern.vm_guest'] = 'none';
$GLOBALS['sysctl']['hw.machine'] = 'arm64';
check_cpu(cpu_microcode_status() === null, 'no microcode row on arm64');
$GLOBALS['sysctl']['hw.machine'] = 'amd64';

/* --- Crypto instructions --------------------------------------------------- */
check_cpu(cpu_crypto_features_parse($intel_boot) === ['AES-NI', 'SHA'], 'Atom C3758: ' . implode(',', cpu_crypto_features_parse($intel_boot)));
$zen5 = [
	'  Features2=0x7ef8320b<SSE3,PCLMULQDQ,MON,SSSE3,FMA,CX16,SSE4.1,SSE4.2,x2APIC,MOVBE,POPCNT,AESNI,XSAVE,OSXSAVE,AVX,F16C,RDRAND>',
	'  Structured Extended Features=0xf1bf97a9<FSGSBASE,BMI1,AVX2,SMEP,BMI2,INVPCID,PQM,PQE,AVX512F,AVX512DQ,RDSEED,ADX,SMAP,AVX512IFMA,CLFLUSHOPT,CLWB,AVX512CD,SHA,AVX512BW,AVX512VL>',
	'  Structured Extended Features2=0x405fce<AVX512VBMI,UMIP,PKU,OSPKE,AVX512VBMI2,GFNI,VAES,VPCLMULQDQ,AVX512VNNI,AVX512BITALG,AVX512VPOPCNTDQ,RDPID,MOVDIRI>',
];
check_cpu(cpu_crypto_features_parse($zen5) === ['AES-NI', 'SHA', 'AVX2', 'AVX-512', 'VAES'], 'Zen 5: ' . implode(',', cpu_crypto_features_parse($zen5)));
$a76 = ['  Instruction Set Attributes 0 = <RNDR,SHA3,SM4,SM3,CRC32,SHA2+SHA512,SHA1,AES+PMULL,Atomic>'];
check_cpu(cpu_crypto_features_parse($a76) === ['AES', 'SHA'], 'arm64: ' . implode(',', cpu_crypto_features_parse($a76)));
check_cpu(cpu_crypto_features_parse(['  Instruction Set Attributes 0 = <CRC32>']) === [], 'Raspberry Pi 4: no crypto instructions');

/* --- Intel Speed Shift / AMD CPPC ------------------------------------------ */
$GLOBALS['sysctl'] = ['dev.cpufreq.0.freq_driver' => 'hwpstate_intel0', 'dev.hwpstate_intel.0.epp' => '50', 'hw.ncpu' => '2'];
check_cpu(system_hwpstate_vendor() === 'intel' && system_hwpstate_getepp() === 50, 'Intel Speed Shift, epp 50');
$GLOBALS['cfg'] = ['system/hwpstate_epp' => '20'];
$GLOBALS['written'] = [];
system_hwpstate_setepp();
check_cpu($GLOBALS['written'] === ['dev.hwpstate_intel.0.epp' => 20, 'dev.hwpstate_intel.1.epp' => 20], 'Intel epp is written as is');

/* AMD CPPC: epp 0-255, shown and stored on the page's 0-100 scale */
$GLOBALS['sysctl'] = ['dev.cpufreq.0.freq_driver' => 'hwpstate0', 'dev.hwpstate.0.epp' => '128', 'hw.ncpu' => '2'];
check_cpu(system_hwpstate_vendor() === 'amd' && system_hwpstate_active(), 'AMD CPPC is detected');
check_cpu(system_hwpstate_getepp() === 50, 'AMD epp 128 reads as 50, got ' . system_hwpstate_getepp());
$GLOBALS['cfg'] = ['system/hwpstate_epp' => '100'];
$GLOBALS['written'] = [];
system_hwpstate_setepp();
check_cpu($GLOBALS['written'] === ['dev.hwpstate.0.epp' => 255, 'dev.hwpstate.1.epp' => 255], 'AMD epp 100 is written as 255');

/* Older AMD (Cool'n'Quiet, no epp) and no driver at all are not hwpstate. */
$GLOBALS['sysctl'] = ['dev.cpufreq.0.freq_driver' => 'hwpstate0'];
check_cpu(system_hwpstate_vendor() === null && system_hwpstate_getepp() === null, "Cool'n'Quiet is not CPPC");
$GLOBALS['sysctl'] = [];
$GLOBALS['written'] = [];
system_hwpstate_setepp();
check_cpu(!system_hwpstate_active() && $GLOBALS['written'] === [], 'nothing is written without a driver');

foreach (glob("{$root}/log/*") as $f) { unlink($f); }
foreach (glob("{$root}/boot/firmware/*") as $f) { unlink($f); }
@rmdir("{$root}/boot/firmware"); @rmdir("{$root}/boot"); @rmdir("{$root}/log"); @rmdir($root);

/* The page, the widget and the REST API carry the new settings. */
$page = file_get_contents(__DIR__ . '/../src/usr/local/www/system_advanced_misc.php');
foreach (["'microcode_early',", "'Power Savings - ' . \$hwpstate_name", "\$hwpstate_amd ? 'AMD CPPC' : 'Intel Speed Shift'"] as $needle) {
	check_cpu(strpos($page, $needle) !== false, "the Miscellaneous page is missing {$needle}");
}
$widget = file_get_contents(__DIR__ . '/../src/usr/local/www/widgets/widgets/system_information.widget.php');
foreach (["'microcode' => gettext('CPU Microcode')", '$microcode = cpu_microcode_status();', '$cpu_features = cpu_crypto_features();'] as $needle) {
	check_cpu(strpos($widget, $needle) !== false, "the System Information widget is missing {$needle}");
}
$routes = file_get_contents(__DIR__ . '/../src/etc/inc/restapi/routes_system.inc');
foreach (["\$types['microcode_early'] = 'bool';", "'microcode' => cpu_microcode_status(),", "\$reboot[] = 'microcode';"] as $needle) {
	check_cpu(strpos($routes, $needle) !== false, "the REST API is missing {$needle}");
}

echo "CPU features smoke test passed.\n";
