<?php

$root = dirname(__DIR__);
$validator = $root . '/tools/ci/freesense-validate-channel.php';
$assembler = $root . '/tools/ci/freesense-assemble-iso.sh';
$fingerprint = str_repeat('a', 64);
$packageFingerprint = str_repeat('b', 64);
$payload = [
	'schema_version' => 'freesense.channels/v1',
	'channels' => [
		'devel' => [
			'name' => 'devel',
			'description' => 'Development version',
			'package_train' => '16.0',
			'abi' => 'FreeBSD:16:amd64',
			'altabi' => 'freebsd:16:x86:64',
			'default' => true,
			'system' => [
				'fingerprint' => $fingerprint,
				'url' => "https://pkg.freesense.org/v1/artifacts/system/{$fingerprint}/amd64",
				'generation' => 7,
				'published_at' => '2026-07-22T00:00:00Z',
				'verified' => false,
			],
			'packages' => [
				'fingerprint' => $packageFingerprint,
				'system_fingerprint' => $fingerprint,
				'url' => "https://pkg.freesense.org/v1/artifacts/packages/16.0/{$packageFingerprint}/amd64",
				'generation' => 8,
				'published_at' => '2026-07-22T00:05:00Z',
				'verified' => false,
			],
		],
	],
];

function runValidator(string $validator, array $payload, string $channel, string $fingerprint): int
{
	$path = tempnam(sys_get_temp_dir(), 'freesense-channel-');
	if ($path === false || file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR)) === false) {
		throw new RuntimeException('Unable to create channel fixture.');
	}
	$command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($validator) . ' '
		. escapeshellarg($path) . ' ' . escapeshellarg($channel) . ' '
		. escapeshellarg($fingerprint);
	exec($command . ' 2>&1', $output, $status);
	unlink($path);
	return $status;
}

if (runValidator($validator, $payload, 'devel', $fingerprint) !== 0) {
	fwrite(STDERR, "Valid channel payload was rejected.\n");
	exit(1);
}
$stablePayload = $payload;
$stablePayload['channels']['stable'] = $stablePayload['channels']['devel'];
$stablePayload['channels']['stable']['name'] = 'stable';
$stablePayload['channels']['stable']['description'] = 'Stable version';
$stablePayload['channels']['stable']['default'] = false;
if (runValidator($validator, $stablePayload, 'stable', $fingerprint) !== 0) {
	fwrite(STDERR, "Valid stable channel payload was rejected.\n");
	exit(1);
}

$wrongSchema = $payload;
$wrongSchema['schema_version'] = 'freesense.channels/v0';
if (runValidator($validator, $wrongSchema, 'devel', $fingerprint) === 0) {
	fwrite(STDERR, "Unsupported channel payload schema was accepted.\n");
	exit(1);
}
if (runValidator($validator, $payload, 'stable', $fingerprint) === 0) {
	fwrite(STDERR, "Missing selected channel was accepted.\n");
	exit(1);
}
if (runValidator($validator, $payload, 'devel', str_repeat('c', 64)) === 0) {
	fwrite(STDERR, "Mismatched current system was accepted.\n");
	exit(1);
}
$wrongBinding = $payload;
$wrongBinding['channels']['devel']['packages']['system_fingerprint'] = str_repeat('c', 64);
if (runValidator($validator, $wrongBinding, 'devel', $fingerprint) === 0) {
	fwrite(STDERR, "Mismatched optional-package system binding was accepted.\n");
	exit(1);
}

$source = file_get_contents($assembler);
if ($source === false) {
	fwrite(STDERR, "Unable to read ISO assembler.\n");
	exit(1);
}
$requiredSourceContracts = [
	'${FREESENSE_ASSEMBLY_CHANNEL_PAYLOAD:?verified channel payload is required}',
	'${FREESENSE_ASSEMBLY_CHANNEL:?selected channel is required}',
	'${FREESENSE_SYSTEM_FINGERPRINT:?current system fingerprint is required}',
	'cmp -s "${_channel_payload}" "${_share}/repos.manifest.json"',
	'"/usr/local/sbin/${PRODUCT_NAME}-repoc" -l',
	'[ -s "${_selected}.conf" ]',
	'[ -f "${_selected}.default" ]',
];
foreach ($requiredSourceContracts as $contract) {
	if (!str_contains($source, $contract)) {
		fwrite(STDERR, "ISO assembler is missing channel closure contract: {$contract}\n");
		exit(1);
	}
}
$worldSeed = strpos($source, 'freesense-dist-world.sh');
$pkgBootstrap = strpos($source, 'tar -xpf "${_pkg_package}"');
$pkgRegister = strpos($source, 'pkg add /tmp/pkg-bootstrap.pkg');
$packageInstall = strpos($source, 'pkg add "$@"');
if ($worldSeed === false || $pkgBootstrap === false || $pkgRegister === false
	|| $packageInstall === false
	|| !($worldSeed < $pkgBootstrap && $pkgBootstrap < $pkgRegister
		&& $pkgRegister < $packageInstall)) {
	fwrite(STDERR, "ISO assembler does not seed pinned world before its pkg-only bootstrap.\n");
	exit(1);
}
$installDiagnostics = [
	'unable to register the pinned pkg bootstrap',
	'unable to install the pinned System package closure',
	'package install epoch mismatch',
];
foreach ($installDiagnostics as $diagnostic) {
	if (!str_contains($source, $diagnostic)) {
		fwrite(STDERR, "ISO assembler is missing package failure diagnostic: {$diagnostic}\n");
		exit(1);
	}
}
$unusedOutputContracts = [
	'gzip -kf "${ISOPATH}"',
	'"${ISOPATH}.sha256"',
	'.sbom.cdx.json',
	'.provenance.json',
];
foreach ($unusedOutputContracts as $contract) {
	if (str_contains($source, $contract)) {
		fwrite(STDERR, "ISO assembler still creates unpublished output: {$contract}\n");
		exit(1);
	}
}
$defaultInstall = strpos($source, 'pkg add -f /tmp/default-config.pkg');
$channelInstall = strpos($source, "\tinstall_assembly_channel\n");
$logInitialize = strpos($source, 'LOGFILE="${BUILDER_LOGS}/isoimage.${TARGET}"');
$distribution = strpos($source, "\tcreate_distribution_tarball\n");
if ($defaultInstall === false || $channelInstall === false || $logInitialize === false
	|| $distribution === false
	|| !($defaultInstall < $channelInstall && $channelInstall < $logInitialize
		&& $logInitialize < $distribution)) {
	fwrite(STDERR, "ISO finalization order is invalid.\n");
	exit(1);
}

echo "ISO channel closure smoke test passed.\n";
