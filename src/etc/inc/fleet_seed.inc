<?php
/*
 * fleet_seed.inc
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
 * Zero-touch joining from a seed: fleet-join.conf on a USB stick, CD or
 * second disk (next to an optional config.xml, the Deployment Factory's seed
 * image) or in cloud-init user data.
 *
 *   {"version": 1, "link": "https://.../fleet/join#...", "mode": "attended"}
 *   {"version": 1, "link": "...", "mode": "pin", "sealed_key": {"salt", "nonce", "tag", "data"}}
 *   {"version": 1, "link": "...", "mode": "unattended", "key": "FLT-..."}
 *
 * rc.ecl copies the file to /cf/conf/fleet/seed.json early in boot. At the
 * end of boot an unattended seed joins in the background; an attended or PIN
 * seed waits for console option 17, which asks for the key or the PIN, so
 * boot never stops at a prompt. The seed is deleted once the box joined, and
 * the key is never logged. Functions marked "pure" touch neither the system
 * nor config.xml.
 */

require_once('fleet_join.inc');

define('FLEET_SEED_FILE', FLEET_NODE_DIR . '/seed.json');
define('FLEET_SEED_NAME', 'fleet-join.conf');
define('FLEET_SEED_MODES', array('attended', 'pin', 'unattended'));
define('FLEET_SEED_PBKDF2_ROUNDS', 200000);

/* pure: the 32-byte key a PIN unlocks. */
function fleet_seed_pin_key(string $pin, string $salt): string {
	return hash_pbkdf2('sha256', $pin, $salt, FLEET_SEED_PBKDF2_ROUNDS, 32, true);
}

/* pure: a PIN is 6 to 8 digits. */
function fleet_seed_pin_valid(string $pin): bool {
	return (bool)preg_match('/^[0-9]{6,8}$/D', $pin);
}

/* pure: seal a join key to a PIN (AES-256-GCM); the Deployment Factory does the same. */
function fleet_seed_seal_key(string $key_display, string $pin): array {
	$salt = random_bytes(16);
	$nonce = random_bytes(12);
	$tag = '';
	$data = openssl_encrypt($key_display, 'aes-256-gcm', fleet_seed_pin_key($pin, $salt), OPENSSL_RAW_DATA, $nonce, $tag, 'FreeSense-Fleet-Seed-v1');
	return array('salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag), 'data' => base64_encode((string)$data));
}

/* pure: the join key a PIN unseals, or null for a wrong PIN or a damaged seed. */
function fleet_seed_open_key(array $sealed, string $pin): ?string {
	$parts = array();
	foreach (array('salt', 'nonce', 'tag', 'data') as $f) {
		$parts[$f] = base64_decode((string)($sealed[$f] ?? ''), true);
		if (($parts[$f] === false) || ($parts[$f] === '')) {
			return null;
		}
	}
	$key = openssl_decrypt($parts['data'], 'aes-256-gcm', fleet_seed_pin_key($pin, $parts['salt']), OPENSSL_RAW_DATA, $parts['nonce'],
	    $parts['tag'], 'FreeSense-Fleet-Seed-v1');
	return ($key === false) ? null : $key;
}

/* pure: parse a seed; returns it checked, or throws FleetJoinError E122 (unusable seed). */
function fleet_seed_parse(string $text): array {
	$seed = json_decode($text, true);
	if (!is_array($seed) || (($seed['version'] ?? null) !== 1) || !in_array($seed['mode'] ?? '', FLEET_SEED_MODES, true) ||
	    !is_string($seed['link'] ?? null)) {
		throw new FleetJoinError('E122', 'The fleet-join.conf seed is not valid.');
	}
	fleet_join_link_parse($seed['link']);
	if (($seed['mode'] === 'unattended') && (fleet_join_key_normalize((string)($seed['key'] ?? '')) === null)) {
		throw new FleetJoinError('E122', 'The unattended seed holds no valid join key.');
	}
	if (($seed['mode'] === 'pin') && !is_array($seed['sealed_key'] ?? null)) {
		throw new FleetJoinError('E122', 'The PIN seed holds no sealed join key.');
	}
	if (($seed['mode'] !== 'unattended') && isset($seed['key'])) {
		throw new FleetJoinError('E122', 'Only an unattended seed may hold the join key in plain text.');
	}
	if (isset($seed['tailscale'])) {
		$ts = $seed['tailscale'];
		$plain = is_array($ts) && isset($ts['key']);
		if (!is_array($ts) || ($plain === is_array($ts['sealed'] ?? null)) || ($plain && !fleet_seed_tailscale_key_valid((string)$ts['key']))) {
			throw new FleetJoinError('E122', 'The seed\'s Tailscale key is not valid.');
		}
		if ($plain && ($seed['mode'] !== 'unattended')) {
			throw new FleetJoinError('E122', 'Only an unattended seed may hold the Tailscale key in plain text.');
		}
	}
	return $seed;
}

/* pure: a Tailscale auth key. */
function fleet_seed_tailscale_key_valid(string $key): bool {
	return (bool)preg_match('/^tskey-auth-[A-Za-z0-9-]{10,200}$/D', $key);
}

/*
 * pure: the one-time Tailscale key a seed holds, or null (none, or the
 * secret is wrong). Unattended seeds hold it plainly; otherwise it is
 * sealed to the PIN (PIN seeds) or to the join key's data (attended
 * seeds: typing the join key unlocks it too).
 */
function fleet_seed_tailscale_key(array $seed, string $secret): ?string {
	$ts = $seed['tailscale'] ?? null;
	if (!is_array($ts)) {
		return null;
	}
	$key = isset($ts['key']) ? (string)$ts['key'] : fleet_seed_open_key((array)($ts['sealed'] ?? array()), $secret);
	return (($key !== null) && fleet_seed_tailscale_key_valid($key)) ? $key : null;
}

/*
 * Bring Tailscale up with a one-time key before joining over the tailnet:
 * install the Tailscale package when it is missing, log in, wait for a
 * tailnet address. The key is used once and then cleared from the
 * configuration. Returns the address; throws FleetJoinError E133.
 */
function fleet_seed_tailscale_up(string $authkey, callable $say): string {
	if (!is_file('/usr/local/pkg/tailscale/tailscale.inc')) {
		$say('Installing Tailscale (needs internet and the FreeSense package repository)');
		require_once('pkg-utils.inc');
		pkg_install('FreeSense-pkg-Tailscale');
		if (!is_file('/usr/local/pkg/tailscale/tailscale.inc')) {
			throw new FleetJoinError('E133', 'Tailscale could not be installed. The firewall needs internet and the FreeSense package repository; check its WAN and DNS, then try again.');
		}
	}
	$say('Logging in to Tailscale');
	config_set_path('installedpackages/tailscaleauth/config/0/preauthkey', $authkey);
	config_set_path('installedpackages/tailscale/config/0/enable', 'on');
	write_config('Fleet: Tailscale logged in with the join seed\'s one-time key.');
	require_once('/usr/local/pkg/tailscale/tailscale.inc');
	tailscale_resync_config_hook();
	$ip = '';
	for ($i = 0; ($i < 45) && ($ip === ''); $i++) {
		sleep(2);
		$out = array();
		exec('/usr/local/bin/tailscale ip -4 2>/dev/null', $out, $rc);
		$ip = (($rc === 0) && filter_var(trim((string)($out[0] ?? '')), FILTER_VALIDATE_IP)) ? trim($out[0]) : '';
	}
	/* Used once: it must not stay in config.xml or its backups. */
	config_set_path('installedpackages/tailscaleauth/config/0/preauthkey', '');
	write_config('Fleet: the one-time Tailscale key was used and removed.');
	if ($ip === '') {
		throw new FleetJoinError('E133', 'Tailscale did not come up within 90 seconds. The key may be used up or expired, or the firewall cannot reach the Tailscale control plane (HTTPS 443).');
	}
	return $ip;
}

/* Called by rc.ecl for each mounted medium: keep a seed found there. */
function fleet_seed_capture(string $mountpoint): bool {
	foreach (array('/', '/config/') as $dir) {
		$file = rtrim($mountpoint, '/') . $dir . FLEET_SEED_NAME;
		if (!is_file($file) || (filesize($file) > 65536)) {
			continue;
		}
		$text = (string)file_get_contents($file);
		try {
			fleet_seed_parse($text);
		} catch (FleetJoinError $e) {
			echo "\n" . $e->getMessage() . "\n";
			return false;
		}
		if (fleet_node_dir(FLEET_NODE_DIR) && fleet_node_write(FLEET_SEED_FILE, $text)) {
			echo " -> found " . FLEET_SEED_NAME . "\n";
			return true;
		}
	}
	return false;
}

/* The seed waiting on this box, or null. */
function fleet_seed_pending(): ?array {
	if (!is_file(FLEET_SEED_FILE)) {
		return null;
	}
	try {
		return fleet_seed_parse((string)file_get_contents(FLEET_SEED_FILE));
	} catch (FleetJoinError $e) {
		return null;
	}
}

/* Forget the seed (after joining, or when an admin discards it). */
function fleet_seed_clear(): void {
	@unlink(FLEET_SEED_FILE);
}

/* At the end of boot: start an unattended join, or tell the admin a seed waits. */
function fleet_seed_boot(): void {
	$seed = fleet_seed_pending();
	if (($seed === null) || (config_get_path('fleet/node_id', '') !== '')) {
		return;
	}
	if ($seed['mode'] === 'unattended') {
		mwexec_bg('/usr/local/bin/php /usr/local/sbin/freesense-join seed');
		return;
	}
	file_notice('fleet-seed', 'A FreeSense Fleet join seed is waiting. Open console option 17 to enter the ' .
	    (($seed['mode'] === 'pin') ? 'PIN' : 'join key') . ' and join.', 'Fleet', '', 1, true);
}
