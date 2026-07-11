<?php
/* Standalone CI smoke test; run with `php tests/ConfigImportGuardSmokeTest.php`. */
if (!function_exists('gettext')) {
	function gettext($message) {
		return $message;
	}
}
require_once(__DIR__ . '/../src/etc/inc/config_import_guard.inc');

function check_import($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$foreign = '<opnsense><system><hostname>router</hostname></system>' .
	'<OPNsense><unbound><general><enabled>1</enabled></general></unbound></OPNsense></opnsense>';
$converted = freesense_config_convert_foreign($foreign, 'opnsense');
check_import(strpos($converted['xml'], '<freesense>') !== false, 'root was not renamed');
check_import(strpos($converted['xml'], '<OPNsense>') === false, 'MVC subtree was not removed');
check_import(substr_count($converted['xml'], '<unbound>') === 1, 'resolver was not seeded exactly once');
check_import(strpos($converted['xml'], '<enable></enable>') !== false, 'seeded resolver is not enabled');

$with_resolver = '<opnsense><system></system><unbound><enable></enable></unbound></opnsense>';
$converted = freesense_config_convert_foreign($with_resolver, 'opnsense');
check_import(substr_count($converted['xml'], '<unbound>') === 1, 'compatible resolver was duplicated');

echo "Config import guard: valid\n";
