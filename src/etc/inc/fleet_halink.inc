<?php
/*
 * fleet_halink.inc
 *
 * part of FreeSense (https://freesense.org)
 * Copyright (c) 2025-2026 FreeSense
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
 * The encrypted link of an HA pair: a WireGuard tunnel straight between the
 * two firewalls of the pair, assigned as an interface, that the pair's state
 * sync (pfsync) runs over. It does not depend on the Fleet controller, so
 * failover works when the controller is down.
 *
 * Fleet sets it up through the management API in two steps: prepare makes
 * this box's key pair (the private key stays here, in FLEET_HALINK_KEY, until
 * the tunnel is made) and returns the public key; set makes the tunnel with
 * the other box's public key. It uses the FreeSense WireGuard package's own
 * configuration, so the tunnel shows under VPN > WireGuard. Everything it
 * makes carries FLEET_HALINK_MARK, and delete removes only that.
 */

define('FLEET_HALINK_MARK', '[Fleet:HA]');
define('FLEET_HALINK_PKG', 'FreeSense-pkg-WireGuard');
define('FLEET_HALINK_KEY', '/var/db/fleet-halink.key');
define('FLEET_HALINK_DESCR', 'HALINK');

/* ------------------------------------------------------------------ */
/* Pure                                                                */
/* ------------------------------------------------------------------ */

/* pure: a WireGuard key (32 bytes, base64). */
function fleet_halink_key_valid(string $key): bool {
	return (bool)preg_match('/^[A-Za-z0-9+\/]{43}=$/D', $key);
}

/* pure: "host:port" (a DNS name or an IPv4 address) as array(host, port), or null. '' is no endpoint: array('', 0). */
function fleet_halink_endpoint(string $endpoint): ?array {
	if ($endpoint === '') {
		return array('', 0);
	}
	if (!preg_match('/^(.+):(\d{1,5})$/D', $endpoint, $m) || ((int)$m[2] < 1) || ((int)$m[2] > 65535)) {
		return null;
	}
	$host = $m[1];
	$name = '/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D';
	return (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || preg_match($name, $host)) ? array($host, (int)$m[2]) : null;
}

/*
 * pure: what is wrong with a link request ($b: address, bits, peer_address,
 * peer_public_key, peer_endpoint, keepalive), a list of sentences; empty
 * when it is fine. The link is a /30: the two addresses are its hosts.
 */
function fleet_halink_errors(array $b): array {
	$e = array();
	foreach (array('address', 'peer_address') as $f) {
		if (!filter_var((string)($b[$f] ?? ''), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$e[] = "{$f} must be an IPv4 address.";
		}
	}
	if (($b['bits'] ?? null) !== 30) {
		$e[] = 'bits must be 30: the link is a /30 between the two firewalls.';
	}
	if (empty($e) && (ip2long($b['address']) === ip2long($b['peer_address']))) {
		$e[] = 'address and peer_address must differ.';
	}
	if (empty($e) && ((ip2long($b['address']) & 0xfffffffc) !== (ip2long($b['peer_address']) & 0xfffffffc))) {
		$e[] = 'address and peer_address must be in the same /30.';
	}
	if (!fleet_halink_key_valid((string)($b['peer_public_key'] ?? ''))) {
		$e[] = 'peer_public_key must be a WireGuard public key.';
	}
	if (fleet_halink_endpoint((string)($b['peer_endpoint'] ?? '')) === null) {
		$e[] = 'peer_endpoint must be host:port, or empty when this box waits for the peer to connect.';
	}
	$k = $b['keepalive'] ?? 0;
	if (!is_int($k) || ($k < 0) || ($k > 3600)) {
		$e[] = 'keepalive must be 0 to 3600 seconds.';
	}
	return $e;
}

/* pure: the tunnel post the WireGuard package takes (wg_do_tunnel_post). */
function fleet_halink_tunnel_post(string $private_key, int $port, string $address, int $bits): array {
	return array('index' => '', 'enabled' => 'yes', 'descr' => FLEET_HALINK_MARK . ' HA link', 'listenport' => (string)$port,
	    'privatekey' => $private_key, 'address0' => $address, 'address_subnet0' => (string)$bits, 'address_descr0' => '');
}

/* pure: the peer post (wg_do_peer_post): the other firewall, reached only at its /32. */
function fleet_halink_peer_post(string $tunnel, string $public_key, string $peer_address, string $endpoint, int $keepalive): array {
	list($host, $port) = fleet_halink_endpoint($endpoint);
	return array('index' => '', 'enabled' => 'yes', 'tun' => $tunnel, 'descr' => FLEET_HALINK_MARK . ' HA peer', 'endpoint' => $host,
	    'port' => ($port > 0) ? (string)$port : '', 'persistentkeepalive' => ($keepalive > 0) ? (string)$keepalive : '', 'publickey' => $public_key,
	    'presharedkey' => '', 'address0' => $peer_address, 'address_subnet0' => '32', 'address_descr0' => '');
}

/* pure: the first optN not in $names, as "optN". */
function fleet_halink_free_opt(array $names): string {
	for ($i = 1; ; $i++) {
		if (!in_array("opt{$i}", $names, true)) {
			return "opt{$i}";
		}
	}
}

/* pure: the newest handshake of "wg show <if> latest-handshakes" output (epoch seconds), 0 for none. */
function fleet_halink_handshake(string $out): int {
	$latest = 0;
	foreach (preg_split('/\R/', trim($out)) as $line) {
		$f = preg_split('/\s+/', trim($line));
		if (count($f) === 2) {
			$latest = max($latest, (int)$f[1]);
		}
	}
	return $latest;
}

/* pure: the IPv4 addresses in "wg show <if> endpoints" output ("key<TAB>host:port" lines). */
function fleet_halink_endpoint_hosts(string $out): array {
	$hosts = array();
	foreach (preg_split('/\R/', trim($out)) as $line) {
		$f = preg_split('/\s+/', trim($line));
		if ((count($f) === 2) && preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/D', $f[1], $m)) {
			$hosts[$m[1]] = true;
		}
	}
	return array_keys($hosts);
}

/* ------------------------------------------------------------------ */
/* The box                                                             */
/* ------------------------------------------------------------------ */

/*
 * Drop the pf states to and from these hosts. The link's own packets (pfsync
 * over the tunnel, and the tunnel's packets on the WAN) make states that
 * pfsync syncs: left from an earlier link, a state is never made again
 * under the rules that keep it out of pfsync, and the two firewalls keep
 * refreshing it for each other (a storm of packets).
 */
function fleet_halink_kill_states(array $hosts): void {
	foreach ($hosts as $h) {
		if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			mwexec('/sbin/pfctl -k ' . escapeshellarg($h) . ' -k 0.0.0.0/0');
			mwexec('/sbin/pfctl -k 0.0.0.0/0 -k ' . escapeshellarg($h));
		}
	}
}

/* The hosts this firewall's link talks to now: the peer's link address and the address the peer's packets come from. */
function fleet_halink_hosts(string $tun, array $cfg): array {
	$hosts = array();
	foreach ((array)($cfg['addresses']['row'] ?? array()) as $r) {
		$hosts[] = (string)($r['address'] ?? '');
	}
	foreach (config_get_path('installedpackages/wireguard/peers/item', array()) as $p) {
		if (is_array($p) && (($p['tun'] ?? '') === $tun)) {
			foreach ((array)($p['allowedips']['row'] ?? array()) as $r) {
				$hosts[] = (string)($r['address'] ?? '');
			}
			$hosts[] = gethostbyname((string)($p['endpoint'] ?? ''));
		}
	}
	$out = array();
	exec('/usr/bin/wg show ' . escapeshellarg($tun) . ' endpoints 2>/dev/null', $out);
	return array_values(array_unique(array_filter(array_merge($hosts, fleet_halink_endpoint_hosts(implode("
", $out))))));
}

define('FLEET_HALINK_LOCK', '/var/run/fleet-halink-install.lock');

/* Whether the WireGuard package is here. */
function fleet_halink_installed(): bool {
	return is_file('/usr/local/pkg/wireguard/includes/wg.inc');
}

/* Whether an install started by fleet_halink_install_start() still runs (a lock younger than ten minutes). */
function fleet_halink_installing(): bool {
	return is_file(FLEET_HALINK_LOCK) && ((time() - (int)@filemtime(FLEET_HALINK_LOCK)) < 600);
}

/* Install the WireGuard package. Throws RuntimeException. */
function fleet_halink_install(): void {
	if (fleet_halink_installed()) {
		return;
	}
	@touch(FLEET_HALINK_LOCK);
	try {
		require_once('pkg-utils.inc');
		pkg_install(FLEET_HALINK_PKG);
	} finally {
		@unlink(FLEET_HALINK_LOCK);
	}
	if (!fleet_halink_installed()) {
		throw new RuntimeException('The WireGuard package could not be installed. The firewall needs internet and the FreeSense package repository.');
	}
}

/* Load the WireGuard package, installing it when it is missing. Throws RuntimeException. */
function fleet_halink_load(): void {
	fleet_halink_install();
	require_once('functions.inc');
	require_once('interfaces.inc');
	require_once('wireguard/includes/wg.inc');
	require_once('/usr/local/FreeSense/include/www/interfaces_assign.inc');
}

/* The link this box has: array(tunnel name, tunnel config, interface name or '') or null. */
function fleet_halink_find(): ?array {
	foreach (config_get_path('installedpackages/wireguard/tunnels/item', array()) as $t) {
		if (is_array($t) && (strpos((string)($t['descr'] ?? ''), FLEET_HALINK_MARK) === 0)) {
			$if = '';
			foreach (config_get_path('interfaces', array()) as $name => $c) {
				if (is_array($c) && (($c['if'] ?? '') === $t['name'])) {
					$if = (string)$name;
				}
			}
			return array((string)$t['name'], $t, $if);
		}
	}
	return null;
}

/* Turn the package's service on and start it. */
function fleet_halink_service(): void {
	if (!wg_is_service_enabled()) {
		$cfg = config_get_path('installedpackages/wireguard/config/0', array());
		$r = wg_do_settings_post(array('enable' => 'yes') + (array)$cfg);
		if (!empty($r['input_errors'])) {
			throw new RuntimeException('WireGuard could not be turned on: ' . implode(' ', $r['input_errors']));
		}
	}
	if (!wg_is_service_running()) {
		wg_toggle_wireguard();
		for ($i = 0; ($i < 10) && !wg_is_service_running(); $i++) {
			sleep(1);
		}
	}
	if (!wg_is_service_running()) {
		throw new RuntimeException('The WireGuard service did not start.');
	}
}

/* Step 1: make this box's key pair and keep the private key. Returns array(public key, listen port). */
function fleet_halink_prepare(int $port): array {
	if (($port < 1) || ($port > 65535)) {
		throw new InvalidArgumentException('listen_port must be a UDP port.');
	}
	fleet_halink_load();
	fleet_halink_service();
	$k = wg_gen_keypair();
	if (!fleet_halink_key_valid((string)($k['pubkey'] ?? '')) || !fleet_halink_key_valid((string)($k['privkey'] ?? ''))) {
		throw new RuntimeException('A WireGuard key pair could not be made.');
	}
	umask(0077);
	if (file_put_contents(FLEET_HALINK_KEY, json_encode(array('private' => $k['privkey'], 'public' => $k['pubkey'], 'port' => $port))) === false) {
		throw new RuntimeException('The key could not be kept.');
	}
	chmod(FLEET_HALINK_KEY, 0600);
	return array($k['pubkey'], $port);
}

/*
 * Step 2: make the tunnel with the other box's public key, assign it as the
 * next free optN and give it its address. Replaces a link made before.
 * Returns array(interface, tunnel). Throws InvalidArgumentException (bad
 * request), DomainException (not prepared) or RuntimeException.
 */
function fleet_halink_set(array $b): array {
	$errors = fleet_halink_errors($b);
	if (!empty($errors)) {
		throw new InvalidArgumentException(implode(' ', $errors));
	}
	$kept = json_decode((string)@file_get_contents(FLEET_HALINK_KEY), true);
	if (!is_array($kept) || !fleet_halink_key_valid((string)($kept['private'] ?? ''))) {
		throw new DomainException('Prepare the link first: this box has no key for it.');
	}
	fleet_halink_load();
	if (fleet_halink_find() !== null) {
		fleet_halink_delete();
	}
	fleet_halink_service();
	$t = wg_do_tunnel_post(fleet_halink_tunnel_post($kept['private'], (int)$kept['port'], $b['address'], (int)$b['bits']));
	if (!empty($t['input_errors'])) {
		throw new RuntimeException('The tunnel was refused: ' . implode(' ', $t['input_errors']));
	}
	$tun = (string)$t['tuns_to_sync'][0];
	$p = wg_do_peer_post(fleet_halink_peer_post($tun, $b['peer_public_key'], $b['peer_address'], (string)($b['peer_endpoint'] ?? ''), $b['keepalive']));
	if (!empty($p['input_errors'])) {
		fleet_halink_delete();
		throw new RuntimeException('The peer was refused: ' . implode(' ', $p['input_errors']));
	}
	wg_tunnel_sync(array($tun));
	$opt = fleet_halink_free_opt(array_keys(config_get_path('interfaces', array())));
	config_set_path("interfaces/{$opt}", array('descr' => FLEET_HALINK_DESCR, 'if' => $tun, 'enable' => '', 'ipaddr' => $b['address'], 'subnet' => (string)$b['bits'], 'mtu' => '1420'));
	write_config('Fleet: HA link ' . $tun . ' assigned as ' . $opt . '.');
	interface_configure($opt, true);
	filter_configure();
	fleet_halink_kill_states(array($b['address'], $b['peer_address'], gethostbyname((string)(fleet_halink_endpoint((string)($b['peer_endpoint'] ?? '')) ?? array(''))[0])));
	@unlink(FLEET_HALINK_KEY);
	return array($opt, $tun);
}

/* The link as it is now. */
function fleet_halink_status(): array {
	if (!fleet_halink_installed()) {
		return array('present' => false);
	}
	fleet_halink_load();
	$found = fleet_halink_find();
	if ($found === null) {
		return array('present' => false, 'prepared' => is_file(FLEET_HALINK_KEY));
	}
	list($tun, $cfg, $if) = $found;
	$out = array();
	exec('/usr/bin/wg show ' . escapeshellarg($tun) . ' latest-handshakes 2>/dev/null', $out);
	$row = (array)($cfg['addresses']['row'][0] ?? array());
	return array('present' => true, 'interface' => $if, 'tunnel' => $tun, 'address' => (string)($row['address'] ?? ''), 'public_key' => (string)($cfg['publickey'] ?? ''),
	    'latest_handshake' => fleet_halink_handshake(implode("\n", $out)));
}

/* Remove the link: its interface, peers and tunnel. Nothing to remove is fine. Throws RuntimeException when the interface is in use. */
function fleet_halink_delete(): string {
	@unlink(FLEET_HALINK_KEY);
	if (!fleet_halink_installed()) {
		return 'absent';
	}
	fleet_halink_load();
	$found = fleet_halink_find();
	if ($found === null) {
		return 'absent';
	}
	list($tun, $cfg, $if) = $found;
	$hosts = fleet_halink_hosts($tun, $cfg);
	if ($if !== '') {
		$errors = array();
		if (!interfaces_assign_delete($if, $errors)) {
			throw new RuntimeException('The link is in use: ' . implode(' ', array_map(function ($m) {
				return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
			}, $errors)));
		}
	}
	$peers = array();
	foreach (config_get_path('installedpackages/wireguard/peers/item', array()) as $idx => $peer) {
		if (is_array($peer) && (($peer['tun'] ?? '') === $tun)) {
			$peers[] = $idx;
		}
	}
	/* Last first: a delete may renumber the peers after it. */
	rsort($peers);
	foreach ($peers as $idx) {
		wg_delete_peer($idx);
	}
	wg_delete_tunnel($tun);
	wg_tunnel_sync(array($tun));
	fleet_halink_kill_states($hosts);
	/* HA sync, switched off by Fleet before, may still name the interface; it must not name one that is gone. */
	if (($if !== '') && (config_get_path('hasync/pfsyncinterface') === $if)) {
		config_del_path('hasync/pfsyncinterface');
		write_config('Fleet: HA link removed; state sync no longer names its interface.');
	}
	return 'removed';
}
