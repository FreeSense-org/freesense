<?php

function fail_channel_validation(string $message): never
{
	fwrite(STDERR, "FreeSense channel validation: {$message}\n");
	exit(1);
}

if ($argc !== 4) {
	fail_channel_validation('usage: payload.json devel|stable current-system-fingerprint');
}

[$script, $payloadPath, $selectedName, $currentSystem] = $argv;
unset($script);

if (!in_array($selectedName, ['devel', 'stable'], true)) {
	fail_channel_validation('selected channel must be devel or stable');
}
if (preg_match('/\A[0-9a-f]{64}\z/D', $currentSystem) !== 1) {
	fail_channel_validation('current system fingerprint must be SHA-256');
}

$payloadBytes = file_get_contents($payloadPath);
if ($payloadBytes === false || $payloadBytes === '') {
	fail_channel_validation('payload is missing or empty');
}
try {
	$payload = json_decode($payloadBytes, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
	fail_channel_validation('payload is not valid JSON');
}
if (!is_array($payload)
	|| ($payload['schema_version'] ?? '') !== 'freesense.channels/v1'
	|| !is_array($payload['channels'] ?? null)) {
	fail_channel_validation('payload schema is not freesense.channels/v1');
}

$channel = $payload['channels'][$selectedName] ?? null;
if (!is_array($channel) || ($channel['name'] ?? '') !== $selectedName) {
	fail_channel_validation('selected channel is missing or misnamed');
}
if (!is_string($channel['description'] ?? null) || $channel['description'] === ''
	|| !is_string($channel['package_train'] ?? null)
	|| preg_match('/\A[0-9]+\.[0-9]+\z/D', $channel['package_train']) !== 1
	|| !is_string($channel['abi'] ?? null) || $channel['abi'] === ''
	|| !is_string($channel['altabi'] ?? null) || $channel['altabi'] === ''
	|| !is_bool($channel['default'] ?? null)) {
	fail_channel_validation('selected channel metadata is incomplete');
}

$system = $channel['system'] ?? null;
if (!is_array($system) || ($system['fingerprint'] ?? '') !== $currentSystem) {
	fail_channel_validation('selected channel does not reference the current system');
}
$expectedSystemUrl = "https://pkg.freesense.org/v1/artifacts/system/{$currentSystem}/amd64";
if (($system['url'] ?? '') !== $expectedSystemUrl
	|| !is_int($system['generation'] ?? null) || $system['generation'] <= 0
	|| !is_string($system['published_at'] ?? null) || $system['published_at'] === ''
	|| !is_bool($system['verified'] ?? null)) {
	fail_channel_validation('selected system metadata is invalid');
}

$packages = $channel['packages'] ?? null;
if ($packages !== null) {
	if (!is_array($packages)
		|| ($packages['system_fingerprint'] ?? '') !== $currentSystem
		|| !is_string($packages['fingerprint'] ?? null)
		|| preg_match('/\A[0-9a-f]{64}\z/D', $packages['fingerprint']) !== 1) {
		fail_channel_validation('optional packages are not bound to the current system');
	}
	$expectedPackagesUrl = 'https://pkg.freesense.org/v1/artifacts/packages/'
		. $channel['package_train'] . '/' . $packages['fingerprint'] . '/amd64';
	if (($packages['url'] ?? '') !== $expectedPackagesUrl
		|| !is_int($packages['generation'] ?? null) || $packages['generation'] <= 0
		|| !is_string($packages['published_at'] ?? null) || $packages['published_at'] === ''
		|| !is_bool($packages['verified'] ?? null)) {
		fail_channel_validation('optional package metadata is invalid');
	}
}

fwrite(STDOUT, "FreeSense channel payload selects {$selectedName} system {$currentSystem}.\n");
