<?php

$builder = file_get_contents(dirname(__DIR__) . '/tools/builder_common.sh');
if ($builder === false) {
	fwrite(STDERR, "Unable to read Poudriere builder source.\n");
	exit(1);
}

$required = [
	'poudriere_pin_ports_tree()',
	'FREESENSE_PORTS_COMMIT is not a full Git commit',
	'git -C "${_tree}" rev-parse HEAD',
	'PACKAGE_FETCH_BLACKLIST="${_package_fetch_blacklist}"',
	'poudriere bulk -b latest',
	'MAKE_JOBS_NUMBER_LIMIT=${FREESENSE_MAKE_JOBS_NUMBER_LIMIT}',
	'_pkgbase="${_pkgbase}*"',
];
foreach ($required as $contract) {
	if (!str_contains($builder, $contract)) {
		fwrite(STDERR, "Poudriere reuse contract is missing: {$contract}\n");
		exit(1);
	}
}

$legacy = [
	'FREESENSE_PIN_STRICT',
	'ports-cache/stock',
	'freesense_freebsd_ports_repo',
	'lean-dedup',
];
foreach ($legacy as $pattern) {
	if (str_contains($builder, $pattern)) {
		fwrite(STDERR, "Legacy Poudriere fallback remains: {$pattern}\n");
		exit(1);
	}
}

echo "Poudriere reuse smoke test passed.\n";
