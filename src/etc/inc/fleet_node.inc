<?php
/*
 * fleet_node.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * The managed-box side of FreeSense Fleet (central management) that the
 * REST API exposes: membership status, the CARP view, the controller-signed
 * policy bundle and the replica vault, where a box keeps other boxes'
 * encrypted backups for the fleet.
 *
 * Membership lives in config.xml <fleet> (written by the agent when the box
 * joins): enable, node_id, controller_url, controller_fingerprint,
 * controller_signing_key (PEM, ECDSA P-256), method (wireguard, tailscale,
 * direct), overlay_address, role (monitor, managed), joined, vault_quota_pct.
 *
 * Bundles and replicas are kept under /cf/conf/fleet, outside config.xml so
 * they never create config revisions or travel with HA sync. Replicas are
 * ciphertext sealed to the fleet backup key; a box can store them but never
 * read them. Functions marked "pure" touch neither the system nor config.xml.
 */

define('FLEET_NODE_DIR', '/cf/conf/fleet');
define('FLEET_VAULT_DIR', FLEET_NODE_DIR . '/vault');
define('FLEET_POLICY_BUNDLE', FLEET_NODE_DIR . '/policy-bundle.json');
define('FLEET_METHODS', array('wireguard', 'tailscale', 'direct'));
define('FLEET_ROLES', array('monitor', 'managed'));
/* Share of the free space on /cf/conf the vault may use by default, and the limits. */
define('FLEET_VAULT_QUOTA_PCT', 5);
define('FLEET_VAULT_QUOTA_PCT_MAX', 50);
/* Largest single replica (a config.xml with extended data stays far below this). */
define('FLEET_VAULT_BLOB_MAX', 48 * 1048576);
define('FLEET_POLICY_BUNDLE_MAX', 1048576);

/* ------------------------------------------------------------------ */
/* Pure helpers                                                        */
/* ------------------------------------------------------------------ */

/* pure: the <fleet> section as typed membership; enrolled only with a node id and a controller. */
function fleet_membership(array $section): array {
	$enabled = isset($section['enable']) && ($section['enable'] !== false);
	$method = (string)($section['method'] ?? '');
	$role = (string)($section['role'] ?? '');
	$pct = (string)($section['vault_quota_pct'] ?? '');
	$m = array(
		'enrolled' => false,
		'node_id' => (string)($section['node_id'] ?? ''),
		'controller' => array(
			'url' => (string)($section['controller_url'] ?? ''),
			'fingerprint' => strtolower((string)($section['controller_fingerprint'] ?? '')),
			'signing_key' => ((string)($section['controller_signing_key'] ?? '') !== ''),
		),
		'method' => in_array($method, FLEET_METHODS, true) ? $method : null,
		'overlay_address' => (string)($section['overlay_address'] ?? ''),
		'role' => in_array($role, FLEET_ROLES, true) ? $role : 'monitor',
		'joined' => ctype_digit((string)($section['joined'] ?? '')) ? (int)$section['joined'] : null,
		'vault_quota_pct' => (ctype_digit($pct) && ((int)$pct >= 0) && ((int)$pct <= FLEET_VAULT_QUOTA_PCT_MAX)) ?
		    (int)$pct : FLEET_VAULT_QUOTA_PCT,
	);
	$m['enrolled'] = $enabled && fleet_valid_node_id($m['node_id']) && ($m['controller']['url'] !== '') &&
	    ($m['method'] !== null);
	return $m;
}

/* pure: a node id is a UUID-like token chosen by the controller. */
function fleet_valid_node_id($id): bool {
	return is_string($id) && (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{7,63}$/D', $id);
}

/* pure: a replica id: letters, digits, ".", "_" and "-"; up to 96 characters; no dot-only names. */
function fleet_vault_valid_id($id): bool {
	return is_string($id) && (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,95}$/D', $id) &&
	    (strpos($id, '..') === false);
}

/*
 * pure: the bytes the vault may hold: a share of the space it could use,
 * that is the free space plus what the vault already holds.
 */
function fleet_vault_quota_bytes(int $free_bytes, int $used_bytes, int $pct): int {
	return (int)floor(max(0, $free_bytes + $used_bytes) * max(0, min(FLEET_VAULT_QUOTA_PCT_MAX, $pct)) / 100);
}

/*
 * pure: whether a replica of $new_size fits, given what the vault holds and
 * the size of the replica it replaces (0 for a new one).
 */
function fleet_vault_fits(int $new_size, int $used_bytes, int $replaced_size, int $quota_bytes): bool {
	return ($used_bytes - $replaced_size + $new_size) <= $quota_bytes;
}

/*
 * pure: check a replica upload {data (base64), sha256, owner, kind, created}.
 * Returns array(bytes, meta) or throws InvalidArgumentException naming the field.
 */
function fleet_vault_parse_upload(array $body): array {
	foreach (array_keys($body) as $field) {
		if (!in_array($field, array('data', 'sha256', 'owner', 'kind', 'created', 'label'), true)) {
			throw new InvalidArgumentException("{$field}: unknown field");
		}
	}
	$data = $body['data'] ?? null;
	if (!is_string($data) || ($data === '')) {
		throw new InvalidArgumentException('data: the replica is required, base64-encoded');
	}
	$bytes = base64_decode($data, true);
	if (($bytes === false) || ($bytes === '')) {
		throw new InvalidArgumentException('data: not valid base64');
	}
	if (strlen($bytes) > FLEET_VAULT_BLOB_MAX) {
		throw new InvalidArgumentException('data: larger than ' . FLEET_VAULT_BLOB_MAX . ' bytes');
	}
	$sha = strtolower((string)($body['sha256'] ?? ''));
	if (!preg_match('/^[0-9a-f]{64}$/D', $sha)) {
		throw new InvalidArgumentException('sha256: 64 hexadecimal characters are required');
	}
	if (!hash_equals($sha, hash('sha256', $bytes))) {
		throw new InvalidArgumentException('sha256: does not match the data');
	}
	$owner = (string)($body['owner'] ?? '');
	if (!fleet_valid_node_id($owner)) {
		throw new InvalidArgumentException('owner: the node id of the box the backup belongs to is required');
	}
	$kind = (string)($body['kind'] ?? 'config');
	if (!in_array($kind, array('config', 'extended', 'controller'), true)) {
		throw new InvalidArgumentException('kind: must be config, extended or controller');
	}
	$created = $body['created'] ?? null;
	if (!is_int($created) || ($created <= 0)) {
		throw new InvalidArgumentException('created: the backup time as a UNIX timestamp is required');
	}
	$label = (string)($body['label'] ?? '');
	if ((strlen($label) > 120) || preg_match('/[\x00-\x1f\x7f]/', $label)) {
		throw new InvalidArgumentException('label: up to 120 characters and no control characters');
	}
	return array($bytes, array('sha256' => $sha, 'size' => strlen($bytes), 'owner' => $owner, 'kind' => $kind,
	    'created' => $created, 'label' => $label));
}

/*
 * pure: check a policy bundle upload {bundle (JSON text), signature (base64
 * DER ECDSA over the bundle text, SHA-256)} against the controller's key and
 * the bundle already held. Returns the decoded bundle or throws
 * InvalidArgumentException with "<code>: <message>".
 */
function fleet_policy_bundle_verify(array $body, string $signing_key_pem, string $node_id, ?array $current): array {
	$text = $body['bundle'] ?? null;
	$sig = base64_decode((string)($body['signature'] ?? ''), true);
	if (!is_string($text) || ($text === '') || (strlen($text) > FLEET_POLICY_BUNDLE_MAX)) {
		throw new InvalidArgumentException('invalid_bundle: bundle must be the bundle JSON as text, up to 1 MiB');
	}
	if (($sig === false) || ($sig === '')) {
		throw new InvalidArgumentException('invalid_signature: signature must be base64');
	}
	$key = @openssl_pkey_get_public($signing_key_pem);
	if ($key === false) {
		throw new InvalidArgumentException('no_signing_key: this box holds no valid controller signing key');
	}
	if (openssl_verify($text, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
		throw new InvalidArgumentException('bad_signature: the bundle is not signed by this box\'s controller');
	}
	$bundle = json_decode($text, true);
	if (!is_array($bundle) || !is_int($bundle['version'] ?? null) || ($bundle['version'] < 1) ||
	    !is_array($bundle['rules'] ?? null)) {
		throw new InvalidArgumentException('invalid_bundle: the bundle needs a version (integer) and a rules list');
	}
	$target = (string)($bundle['node_id'] ?? '');
	if (($target !== '*') && ($target !== $node_id)) {
		throw new InvalidArgumentException('wrong_node: the bundle was issued for another box');
	}
	if (($current !== null) && ($bundle['version'] < (int)($current['version'] ?? 0))) {
		throw new InvalidArgumentException('stale_bundle: this box already holds a newer bundle (version ' .
		    (int)$current['version'] . ')');
	}
	return $bundle;
}

/*
 * pure: CARP status from the configured VIPs. $vip_status(uniqid) gives
 * MASTER, BACKUP, INIT or "" for one CARP VIP.
 */
function fleet_carp_entries(array $vips, bool $enabled, callable $vip_status): array {
	$out = array();
	foreach ($vips as $vip) {
		if (!is_array($vip) || (($vip['mode'] ?? '') !== 'carp')) {
			continue;
		}
		$state = $enabled ? strtoupper((string)$vip_status((string)($vip['uniqid'] ?? ''))) : 'DISABLED';
		$out[] = array(
			'uniqid' => (string)($vip['uniqid'] ?? ''),
			'interface' => (string)($vip['interface'] ?? ''),
			'vhid' => (int)($vip['vhid'] ?? 0),
			'address' => (string)($vip['subnet'] ?? '') . '/' . (string)($vip['subnet_bits'] ?? ''),
			'description' => (string)($vip['descr'] ?? ''),
			'advbase' => (int)($vip['advbase'] ?? 1),
			'advskew' => (int)($vip['advskew'] ?? 0),
			'state' => in_array($state, array('MASTER', 'BACKUP', 'INIT', 'DISABLED'), true) ? $state : 'UNKNOWN',
		);
	}
	return $out;
}

/*
 * pure: one word for the whole box: "none" (no CARP VIPs), "disabled",
 * "master" (all MASTER), "backup" (all BACKUP) or "mixed" (a split, which on
 * a pair can mean split-brain).
 */
function fleet_carp_summary(array $entries, bool $enabled): string {
	if (empty($entries)) {
		return 'none';
	}
	if (!$enabled) {
		return 'disabled';
	}
	$states = array_unique(array_column($entries, 'state'));
	if ($states === array('MASTER')) {
		return 'master';
	}
	return ($states === array('BACKUP')) ? 'backup' : 'mixed';
}

/* pure: whether this box can roll back a failed update by itself (ZFS boot environments, both switches on). */
function fleet_rollback_protection(bool $be_compatible, array $bootenv): bool {
	return $be_compatible && (($bootenv['enabled'] ?? 'true') !== 'false') &&
	    (($bootenv['automatic_rollback'] ?? 'true') !== 'false');
}

/* ------------------------------------------------------------------ */
/* System access                                                       */
/* ------------------------------------------------------------------ */

function fleet_node_membership(): array {
	$section = config_get_path('fleet', []);
	return fleet_membership(is_array($section) ? $section : []);
}

/* Create a private directory under /cf/conf/fleet. */
function fleet_node_dir(string $dir): bool {
	if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
		return false;
	}
	@chmod($dir, 0700);
	return true;
}

/* Write a file atomically with mode 0600. */
function fleet_node_write(string $path, string $bytes): bool {
	$tmp = $path . '.tmp' . getmypid();
	if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
		@unlink($tmp);
		return false;
	}
	@chmod($tmp, 0600);
	if (!@rename($tmp, $path)) {
		@unlink($tmp);
		return false;
	}
	return true;
}

/* CARP status: array(enabled, maintenance, demotion, summary, vips). */
function fleet_carp_status(): array {
	$enabled = get_carp_status();
	$vips = fleet_carp_entries(config_get_path('virtualip/vip', []), $enabled, function ($uniqid) {
		return get_carp_interface_status("_vip{$uniqid}");
	});
	return array(
		'enabled' => $enabled,
		'maintenance' => config_path_enabled('', 'virtualip_carp_maintenancemode'),
		'demotion' => (int)get_single_sysctl('net.inet.carp.demotion'),
		'summary' => fleet_carp_summary($vips, $enabled),
		'vips' => $vips,
	);
}

/*
 * pure: what HA config sync does on this box: the peer it pushes to (the
 * primary of a pair has one; the secondary has none) and which sections.
 */
function fleet_hasync_summary(array $hasync): array {
	$peer = trim((string)($hasync['synchronizetoip'] ?? ''));
	$sections = array();
	foreach ($hasync as $k => $v) {
		if ((strpos((string)$k, 'synchronize') === 0) && ($k !== 'synchronizetoip') && ($v === 'on')) {
			$sections[] = substr((string)$k, strlen('synchronize'));
		}
	}
	sort($sections);
	return array('peer' => $peer, 'sections' => ($peer === '') ? array() : $sections);
}

/* The local user an HA peer's configuration sync logs in as when Fleet set up the pair. */
define('FLEET_HASYNC_USER', 'fleet-hasync');

/*
 * Create, update ($bcrypt_hash) or remove (null) the fleet-hasync user: a
 * local user with only "System - HA node sync", which the pair's primary
 * logs in as to push its configuration here. Not a Fleet-managed account
 * (users sync never touches it). Returns what was done.
 */
function fleet_hasync_user_set(?string $bcrypt_hash): string {
	require_once('auth.inc');
	if (($bcrypt_hash !== null) && !preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/D', $bcrypt_hash)) {
		throw new InvalidArgumentException('invalid_hash: bcrypt_hash must be a bcrypt hash');
	}
	foreach (config_get_path('system/user', []) as $idx => $u) {
		if (!is_array($u) || (($u['name'] ?? '') !== FLEET_HASYNC_USER)) {
			continue;
		}
		if ($bcrypt_hash === null) {
			local_user_del($u);
			config_del_path("system/user/{$idx}");
			config_set_path('system/user', array_values(config_get_path('system/user', [])));
			write_config('Fleet: HA sync user removed.');
			$GLOBALS['userindex'] = index_users();
			return 'removed';
		}
		config_set_path("system/user/{$idx}/bcrypt-hash", $bcrypt_hash);
		config_set_path("system/user/{$idx}/priv", array('system-xmlrpc-ha-sync'));
		write_config('Fleet: HA sync user updated.');
		local_user_set(config_get_path("system/user/{$idx}"));
		return 'updated';
	}
	if ($bcrypt_hash === null) {
		return 'absent';
	}
	$uid = (int)config_get_path('system/nextuid');
	config_set_path('system/nextuid', $uid + 1);
	$entry = array('name' => FLEET_HASYNC_USER, 'descr' => 'HA configuration sync (set up by FreeSense Fleet)', 'scope' => 'user',
	    'uid' => (string)$uid, 'bcrypt-hash' => $bcrypt_hash, 'priv' => array('system-xmlrpc-ha-sync'), 'expires' => '', 'authorizedkeys' => '', 'ipsecpsk' => '');
	config_set_path('system/user/', $entry);
	write_config('Fleet: HA sync user created.');
	$GLOBALS['userindex'] = index_users();
	local_user_set($entry);
	return 'created';
}

/* The SHA-256 fingerprint of this firewall's WebGUI certificate, or '' (an HA primary pins it for configuration sync). */
function fleet_webgui_cert_sha256(): string {
	require_once('certs.inc');
	$cert = lookup_cert(config_get_path('system/webgui/ssl-certref'));
	$der = is_array($cert) ? fleet_pem_der(base64_decode((string)($cert['crt'] ?? ''))) : '';
	return ($der === '') ? '' : hash('sha256', $der);
}

/* pure: the DER bytes of the first PEM certificate in $pem, or ''. */
function fleet_pem_der(string $pem): string {
	if (!preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)) {
		return '';
	}
	return (string)base64_decode(preg_replace('/\s+/', '', $m[1]), true);
}

/* The policy bundle this box holds, or null. */
function fleet_policy_bundle_read(): ?array {
	$stored = @json_decode((string)@file_get_contents(FLEET_POLICY_BUNDLE), true);
	return (is_array($stored) && is_array($stored['bundle'] ?? null)) ? $stored : null;
}

/* Verify and store a policy bundle; returns the stored record. Throws InvalidArgumentException. */
function fleet_policy_bundle_store(array $body, array $membership): array {
	$current = fleet_policy_bundle_read();
	$bundle = fleet_policy_bundle_verify($body, (string)config_get_path('fleet/controller_signing_key', ''),
	    $membership['node_id'], ($current === null) ? null : $current['bundle']);
	$record = array('bundle' => $bundle, 'text' => (string)$body['bundle'], 'signature' => (string)$body['signature'],
	    'received' => time());
	if (!fleet_node_dir(FLEET_NODE_DIR) || !fleet_node_write(FLEET_POLICY_BUNDLE, json_encode($record, JSON_UNESCAPED_SLASHES))) {
		throw new RuntimeException('The policy bundle could not be saved.');
	}
	return $record;
}

function fleet_vault_path(string $id, string $ext): string {
	return FLEET_VAULT_DIR . "/{$id}.{$ext}";
}

/*
 * The vault as a Fleet archive (.fsfa): a tar.gz of the encrypted replicas
 * and their metadata, written to $out (a stream). A new controller restores
 * the fleet from it with the recovery key (Recover fleet); this box cannot
 * read what is inside. Returns false when the vault is empty or tar fails.
 */
function fleet_vault_export($out): bool {
	if (empty(fleet_vault_list())) {
		return false;
	}
	$p = proc_open(array('/usr/bin/tar', '-czf', '-', '-C', FLEET_VAULT_DIR, '.'), array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($p)) {
		return false;
	}
	stream_copy_to_stream($pipes[1], $out);
	fclose($pipes[1]);
	return proc_close($p) === 0;
}

/* Every replica's metadata, by id. */
function fleet_vault_list(): array {
	$out = array();
	foreach (glob(FLEET_VAULT_DIR . '/*.json') ?: array() as $file) {
		$id = basename($file, '.json');
		$meta = @json_decode((string)@file_get_contents($file), true);
		if (fleet_vault_valid_id($id) && is_array($meta) && is_file(fleet_vault_path($id, 'blob'))) {
			$out[$id] = array('id' => $id) + $meta;
		}
	}
	ksort($out);
	return $out;
}

/* The vault's use and quota in bytes. */
function fleet_vault_usage(array $membership, ?array $list = null): array {
	$list = $list ?? fleet_vault_list();
	$used = array_sum(array_column($list, 'size'));
	$free = @disk_free_space(is_dir(FLEET_NODE_DIR) ? FLEET_NODE_DIR : '/cf/conf');
	$quota = fleet_vault_quota_bytes(($free === false) ? 0 : (int)$free, (int)$used, $membership['vault_quota_pct']);
	return array('replicas' => count($list), 'used_bytes' => (int)$used, 'quota_bytes' => $quota,
	    'quota_pct' => $membership['vault_quota_pct']);
}

/*
 * Store a replica. Returns its metadata and whether it replaced one.
 * Throws InvalidArgumentException (bad upload), LengthException (quota) or
 * RuntimeException (disk).
 */
function fleet_vault_put(string $id, array $body, array $membership): array {
	list($bytes, $meta) = fleet_vault_parse_upload($body);
	$list = fleet_vault_list();
	$usage = fleet_vault_usage($membership, $list);
	$replaced = isset($list[$id]) ? (int)$list[$id]['size'] : 0;
	if (!fleet_vault_fits($meta['size'], $usage['used_bytes'], $replaced, $usage['quota_bytes'])) {
		throw new LengthException(sprintf('The vault quota (%d bytes, %d%% of the free space) would be exceeded.',
		    $usage['quota_bytes'], $usage['quota_pct']));
	}
	$meta['stored'] = time();
	if (!fleet_node_dir(FLEET_VAULT_DIR) || !fleet_node_write(fleet_vault_path($id, 'blob'), $bytes) ||
	    !fleet_node_write(fleet_vault_path($id, 'json'), json_encode($meta, JSON_UNESCAPED_SLASHES))) {
		throw new RuntimeException('The replica could not be written.');
	}
	return array('id' => $id) + $meta + array('replaced' => isset($list[$id]));
}

/* A replica's metadata and bytes, or null. A replica that no longer matches its hash is reported, not served. */
function fleet_vault_get(string $id): ?array {
	$list = fleet_vault_list();
	if (!isset($list[$id])) {
		return null;
	}
	$bytes = (string)@file_get_contents(fleet_vault_path($id, 'blob'));
	return $list[$id] + array('intact' => hash_equals((string)$list[$id]['sha256'], hash('sha256', $bytes)),
	    'bytes' => $bytes);
}

function fleet_vault_delete(string $id): bool {
	if (!isset(fleet_vault_list()[$id])) {
		return false;
	}
	@unlink(fleet_vault_path($id, 'blob'));
	@unlink(fleet_vault_path($id, 'json'));
	return true;
}
