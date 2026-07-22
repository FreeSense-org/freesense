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
	'poudriere bulk ${_poudriere_test_flag}',
	'-f ${_bulk} -j ${jail_name} -p ${POUDRIERE_PORTS_NAME}',
	'MAKE_JOBS_NUMBER_LIMIT=${FREESENSE_MAKE_JOBS_NUMBER_LIMIT}',
];
foreach ($required as $contract) {
	if (!str_contains($builder, $contract)) {
		fwrite(STDERR, "Pinned source-build contract is missing: {$contract}\n");
		exit(1);
	}
}
if (preg_match_all('/^[\t ]*if ! poudriere bulk /m', $builder) !== 1) {
	fwrite(STDERR, "Expected exactly one Poudriere bulk invocation.\n");
	exit(1);
}

$forbidden = [
	'poudriere_package_fetch_blacklist',
	'FREESENSE_USE_PACKAGE_FETCH',
	'PACKAGE_FETCH_BLACKLIST',
	'poudriere bulk -b',
	'must-build.extra',
];
foreach ($forbidden as $pattern) {
	if (str_contains($builder, $pattern)) {
		fwrite(STDERR, "Forbidden Poudriere fallback remains: {$pattern}\n");
		exit(1);
	}
}

echo "Pinned Poudriere source-build smoke test passed.\n";
