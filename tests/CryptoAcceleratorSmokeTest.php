<?php
/*
 * Standalone CI regression test; run with `php tests/CryptoAcceleratorSmokeTest.php`.
 *
 * The crypto accelerator choices (System > Advanced > Miscellaneous) and the
 * modules load_crypto() loads at boot. The real functions are taken from the
 * source files and run against stubs for the kernel and the configuration.
 */

function check_crypto($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* Copy one top-level function out of a PHP source file. */
function crypto_extract_function(string $file, string $name): string {
	$src = file_get_contents(__DIR__ . '/../' . $file);
	check_crypto($src !== false, "cannot read {$file}");
	check_crypto(preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $src, $m) === 1,
	    "{$file} must define {$name}()");
	return $m[0];
}

/* Stubs: the kernel modules that exist and are loaded, and the configuration. */
$GLOBALS['ko'] = [];
$GLOBALS['loaded'] = [];
$GLOBALS['cfg'] = [];
$GLOBALS['kldload'] = [];

function is_module_loaded($module) { return in_array($module, $GLOBALS['loaded'], true); }
function gettext($s) { return $s; }
function localize_text($s, ...$a) { return vsprintf($s, $a); }
function logger($level, $msg) { }
function mute_kernel_msgs() { }
function unmute_kernel_msgs() { }
function config_get_path($path, $default = null) { return $GLOBALS['cfg'][$path] ?? $default; }
function config_path_enabled($section, $key) { return !empty($GLOBALS['cfg']["{$section}/{$key}"]); }
function mwexec($cmd) {
	check_crypto(preg_match("/^\/sbin\/kldload '([a-z0-9_]+)'$/", $cmd, $m) === 1, "unexpected command {$cmd}");
	$GLOBALS['kldload'][] = $m[1];
	return 0;
}
if (!defined('LOG_INFO')) { define('LOG_INFO', 6); }
if (!defined('LOG_WARNING')) { define('LOG_WARNING', 4); }

/* The real functions read /boot/kernel; point them at a fake kernel directory. */
$kernel = sys_get_temp_dir() . '/crypto-smoke-' . getmypid();
@mkdir($kernel, 0700, true);
$code = '';
foreach (['crypto_module_available', 'crypto_offload_drivers', 'crypto_offload_modules', 'crypto_offload_available',
    'crypto_qat_modules', 'crypto_qat_available', 'load_crypto'] as $fn) {
	$code .= crypto_extract_function('src/etc/inc/freesense-utils.inc', $fn);
}
$code .= crypto_extract_function('src/usr/local/FreeSense/include/www/system_advanced_misc.inc', 'getSystemAdvancedMiscCryptoModules');
check_crypto(substr_count($code, '"/boot/kernel/') >= 3, 'the module checks must look in /boot/kernel');
eval(str_replace('"/boot/kernel/', '"' . $kernel . '/', $code));

function crypto_kernel(array $modules) {
	global $kernel;
	foreach (glob("{$kernel}/*.ko") as $f) { unlink($f); }
	foreach ($modules as $m) { touch("{$kernel}/{$m}.ko"); }
}

/* amd64 build with Part A: aesni, ossl and QAT, no IPsec-MB driver yet. */
crypto_kernel(['aesni', 'ossl', 'cryptodev', 'qat', 'qat_c2xxx']);
$choices = getSystemAdvancedMiscCryptoModules();
check_crypto(array_keys($choices) === ['aesni', 'aesni_cryptodev', 'ossl', 'ossl_cryptodev', 'cryptodev'],
    'amd64 choices: ' . implode(',', array_keys($choices)));
check_crypto(crypto_qat_available(), 'QAT is offered when the kernel ships qat.ko');

/* Every stored value must split on "_" into loadable module names. */
foreach (array_keys($choices) as $value) {
	foreach (explode('_', $value) as $module) {
		check_crypto(in_array($module, ['aesni', 'ossl', 'ipsecmb', 'cryptodev'], true), "choice {$value} splits into {$module}");
	}
}

/* A build that carries the IPsec-MB driver offers it. */
crypto_kernel(['aesni', 'ossl', 'ipsecmb', 'cryptodev', 'qat', 'qat_c2xxx']);
check_crypto(isset(getSystemAdvancedMiscCryptoModules()['ipsecmb_cryptodev']), 'ipsecmb is offered when ipsecmb.ko exists');

/* arm64: only ossl and cryptodev, no QAT. */
crypto_kernel(['ossl', 'cryptodev']);
check_crypto(array_keys(getSystemAdvancedMiscCryptoModules()) === ['ossl', 'ossl_cryptodev', 'cryptodev'],
    'arm64 choices: ' . implode(',', array_keys(getSystemAdvancedMiscCryptoModules())));
check_crypto(!crypto_qat_available(), 'QAT is not offered without qat.ko');

/* Names are checked before they reach a path. */
check_crypto(!crypto_module_available('../ossl') && !crypto_module_available('ossl.ko') && !crypto_module_available(''),
    'crypto_module_available() rejects anything but a plain module name');

/* load_crypto(): the chosen accelerator, cryptodev, and QAT when its flag is set. */
crypto_kernel(['aesni', 'ossl', 'cryptodev', 'qat', 'qat_c2xxx']);
$GLOBALS['cfg'] = ['system/crypto_hardware' => 'ossl_cryptodev', 'system/crypto_qat' => true];
$GLOBALS['kldload'] = [];
load_crypto();
check_crypto($GLOBALS['kldload'] === ['ossl', 'cryptodev', 'qat', 'qat_c2xxx'], 'loaded: ' . implode(',', $GLOBALS['kldload']));

/* Already loaded modules are not loaded again; QAT stays off without its flag. */
$GLOBALS['cfg'] = ['system/crypto_hardware' => 'aesni'];
$GLOBALS['loaded'] = ['aesni'];
$GLOBALS['kldload'] = [];
load_crypto();
check_crypto($GLOBALS['kldload'] === [], 'nothing to load: ' . implode(',', $GLOBALS['kldload']));

/* A module missing from this kernel (ipsecmb on a build without it) is skipped, not kldloaded. */
$GLOBALS['cfg'] = ['system/crypto_hardware' => 'ipsecmb_cryptodev'];
$GLOBALS['loaded'] = [];
$GLOBALS['kldload'] = [];
load_crypto();
check_crypto($GLOBALS['kldload'] === ['cryptodev'], 'missing ipsecmb.ko: ' . implode(',', $GLOBALS['kldload']));

/* An unknown stored value loads nothing. */
$GLOBALS['cfg'] = ['system/crypto_hardware' => 'hifn_ubsec'];
$GLOBALS['kldload'] = [];
load_crypto();
check_crypto($GLOBALS['kldload'] === [], 'unknown value: ' . implode(',', $GLOBALS['kldload']));

/* AMD CCP and Chelsio ccr: offered only when the kernel ships them, loaded only when their flag is set. */
crypto_kernel(['aesni', 'ossl', 'cryptodev', 'qat', 'qat_c2xxx', 'ccp', 'ccr']);
check_crypto(crypto_offload_available('ccp') && crypto_offload_available('ccr'), 'CCP and ccr are offered when their .ko files exist');
check_crypto(!crypto_offload_available('hifn') && crypto_offload_modules('hifn') === [], 'an unknown offload driver has no modules');
$GLOBALS['cfg'] = ['system/crypto_hardware' => 'aesni', 'system/crypto_ccp' => true, 'system/crypto_ccr' => true];
$GLOBALS['loaded'] = [];
$GLOBALS['kldload'] = [];
load_crypto();
check_crypto($GLOBALS['kldload'] === ['aesni', 'ccp', 'ccr'], 'CCP and ccr flags: ' . implode(',', $GLOBALS['kldload']));

/* All three offload flags together, next to the software accelerator. */
$GLOBALS['cfg'] = ['system/crypto_hardware' => 'ossl', 'system/crypto_qat' => true, 'system/crypto_ccp' => true, 'system/crypto_ccr' => true];
$GLOBALS['kldload'] = [];
load_crypto();
check_crypto($GLOBALS['kldload'] === ['ossl', 'qat', 'qat_c2xxx', 'ccp', 'ccr'], 'all offload flags: ' . implode(',', $GLOBALS['kldload']));

/* A flag for a driver this kernel lacks (arm64) is skipped. */
crypto_kernel(['ossl', 'cryptodev']);
check_crypto(!crypto_offload_available('ccp') && !crypto_offload_available('ccr'), 'no CCP or ccr without their .ko files');
$GLOBALS['cfg'] = ['system/crypto_hardware' => 'ossl', 'system/crypto_ccp' => true];
$GLOBALS['kldload'] = [];
load_crypto();
check_crypto($GLOBALS['kldload'] === ['ossl'], 'missing ccp.ko: ' . implode(',', $GLOBALS['kldload']));

crypto_kernel([]);
@rmdir($kernel);

/* The page, the REST API and the dashboard know the new settings. */
$page = file_get_contents(__DIR__ . '/../src/usr/local/www/system_advanced_misc.php');
check_crypto(strpos($page, "if (crypto_qat_available()) {") !== false && strpos($page, "'crypto_qat',") !== false,
    'the Miscellaneous page shows the QAT checkbox only when the drivers exist');
$misc = file_get_contents(__DIR__ . '/../src/usr/local/FreeSense/include/www/system_advanced_misc.inc');
foreach (["'crypto_ccp',", "'crypto_ccr',", "crypto_offload_available('ccp')", "crypto_offload_available('ccr')"] as $needle) {
	check_crypto(strpos($page, $needle) !== false, "the Miscellaneous page has {$needle}");
}
check_crypto(strpos($misc, "\$pconfig[\"crypto_{\$key}\"] = config_path_enabled('system', \"crypto_{\$key}\");") !== false &&
    strpos($misc, "if ((\$post[\"crypto_{\$key}\"] == \"yes\") && crypto_offload_available(\$key)) {") !== false,
    'every offload flag is read and saved');
$routes = file_get_contents(__DIR__ . '/../src/etc/inc/restapi/routes_system.inc');
check_crypto(strpos($routes, "\$types[\"crypto_{\$key}\"] = 'bool';") !== false &&
    strpos($routes, "\$out[\"crypto_{\$key}\"] = (bool)\$p[\"crypto_{\$key}\"];") !== false,
    'the REST API exposes every offload flag');
$dash = file_get_contents(__DIR__ . '/../src/usr/local/www/includes/functions.inc.php');
foreach (['"OSSL"', '"IPSECMB"', "is_module_loaded('qat_c2xxx')", '"CCP"', '"CCR"', '"ARMV8"', '"SAFEXCEL"',
    "crypto_device_attached('t6nex')", 'interfaces_with_ipsec_offload()'] as $needle) {
	check_crypto(strpos($dash, $needle) !== false, "the dashboard crypto status knows {$needle}");
}

echo "Crypto accelerator smoke test passed.\n";
