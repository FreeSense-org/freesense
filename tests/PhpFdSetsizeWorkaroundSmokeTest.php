<?php

$makeConf = file_get_contents(dirname(__DIR__) . '/tools/conf/pfPorts/make.conf');
$pkgUtils = file_get_contents(dirname(__DIR__) . '/src/etc/inc/pkg-utils.inc');
if ($makeConf === false || $pkgUtils === false) {
	fwrite(STDERR, "Unable to read PHP package sources.\n");
	exit(1);
}

if (preg_match('/^\s*PHP_FD_SETSIZE\s*=/m', $makeConf) === 1) {
	fwrite(STDERR, "PHP_FD_SETSIZE must not force a custom php85 build.\n");
	exit(1);
}

foreach (['stream_select($', 'socket_select($'] as $forbidden) {
	if (str_contains($pkgUtils, $forbidden)) {
		fwrite(STDERR, "pkg-utils.inc must not use {$forbidden}.\n");
		exit(1);
	}
}

foreach (['function pkg_drain_pipes(', 'stream_set_blocking($stdout_pipe, false)'] as $required) {
	if (!str_contains($pkgUtils, $required)) {
		fwrite(STDERR, "Missing non-blocking pipe drain: {$required}\n");
		exit(1);
	}
}

echo "PHP FD_SETSIZE workaround smoke test passed.\n";
