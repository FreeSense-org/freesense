<?php
/*
 * fleet_agent.inc
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
 * The managed-box agent: what joining a Fleet controller changes on this
 * firewall, and how to undo it.
 *
 * Joining creates
 *   - the API-only user "fleet-controller" and its API key (read-only for the
 *     "monitor" role); nobody knows the user's password,
 *   - a self-signed certificate for the box's API, which the controller pins,
 *   - the WireGuard interface wgfleet0 (method "wireguard"),
 *   - a REST API listener bound to the overlay address only, open only to the
 *     controller's networks, and
 *   - the <fleet> section of config.xml.
 * If the REST API was off, it is turned on for the listener only (not on the
 * WebGUI port), so joining adds no exposure. The WireGuard private key lives
 * in /cf/conf/fleet/wg.key, outside config.xml.
 *
 * Leaving (or Emergency Detach, which works without the controller) removes
 * all of it, keeping a copy of the last configuration. Functions marked
 * "pure" touch neither the system nor config.xml.
 */

require_once('fleet_node.inc');
require_once('fleet_join.inc');
require_once('fleet_guard.inc');
require_once('restapi/framework.inc');

define('FLEET_USER', 'fleet-controller');
define('FLEET_WG_IF', 'wgfleet0');
define('FLEET_WG_KEY', FLEET_NODE_DIR . '/wg.key');
define('FLEET_ARCHIVE_DIR', FLEET_NODE_DIR . '/archive');
define('FLEET_PROGRESS_FILE', '/var/run/fleet-join.json');
define('FLEET_NODE_API_PORT', 8444);
define('FLEET_WG_MTU', 1380);
/* Inside a tailnet (MTU 1280) the tunnel's packets plus WireGuard's 80 bytes must still fit. */
define('FLEET_WG_MTU_TAILNET', 1200);
define('FLEET_WG_KEEPALIVE', 25);
/* Where the controller can be reached (a standby controller adds a second endpoint), and when to try the next one. */
define('FLEET_ENDPOINT_STATE', FLEET_NODE_DIR . '/endpoint.json');
define('FLEET_ENDPOINT_STALE', 180);
define('FLEET_ENDPOINT_CRON', '/usr/local/bin/php /usr/local/sbin/freesense-fleet-endpoints');
define('FLEET_LISTENER_DESCR', 'FreeSense Fleet');
/* Extra connection paths (fleet_paths.inc) are listeners described "FreeSense Fleet path: <method>". */
define('FLEET_PATH_DESCR', 'FreeSense Fleet path: ');

/* pure: a REST API listener Fleet owns (the tunnel listener or a path). */
function fleet_listener_is_fleet($l): bool {
	$d = is_array($l) ? (string)($l['descr'] ?? '') : '';
	return ($d === FLEET_LISTENER_DESCR) || (strncmp($d, FLEET_PATH_DESCR, strlen(FLEET_PATH_DESCR)) === 0);
}
define('FLEET_CERT_DESCR', 'FreeSense Fleet node API');

/* ------------------------------------------------------------------ */
/* Pure builders                                                       */
/* ------------------------------------------------------------------ */

/*
 * pure: the privileges of the fleet-controller user. Everyone reads what the
 * heartbeat and backups need; "managed" may also change the areas Fleet
 * manages. REST API access itself is the page-system-restapi-keys privilege.
 */
function fleet_access_privs(string $role): array {
	$read = array('system', 'status', 'config', 'backup', 'packages', 'logs', 'management');
	$write = array('management', 'config', 'backup', 'packages', 'operations', 'system.settings', 'firewall.aliases', 'services.misc', 'firewall.rules', 'firewall.nat', 'firewall.vips');
	$privs = array('page-system-restapi-keys');
	foreach ($read as $area) {
		$privs[] = restapi_area_privilege($area, 'read');
	}
	if ($role === 'managed') {
		/* Templates read what they push. */
		foreach (array('firewall.aliases', 'firewall.rules', 'firewall.nat', 'firewall.vips') as $area) {
			$privs[] = restapi_area_privilege($area, 'read');
		}
		foreach ($write as $area) {
			$privs[] = restapi_area_privilege($area, 'write');
		}
	}
	return $privs;
}

/* pure: the config.xml entry of the fleet-controller user. */
function fleet_access_user_entry(int $uid, string $password_hash, string $role): array {
	return array(
		'name' => FLEET_USER,
		'descr' => 'FreeSense Fleet controller (API only)',
		'scope' => 'user',
		'uid' => (string)$uid,
		'bcrypt-hash' => $password_hash,
		'expires' => '',
		'authorizedkeys' => '',
		'ipsecpsk' => '',
		'priv' => fleet_access_privs($role),
	);
}

/* pure: a peer {public_key, endpoint "host:port"|"[v6]:port"|"", allowed_ips [CIDR...]}. */
function fleet_valid_peer($peer): bool {
	if (!is_array($peer) || !preg_match('~^[A-Za-z0-9+/]{43}=$~D', (string)($peer['public_key'] ?? ''))) {
		return false;
	}
	$endpoint = (string)($peer['endpoint'] ?? '');
	if (($endpoint !== '') && !preg_match('~^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9][A-Za-z0-9.-]*):[0-9]{1,5}$~D', $endpoint)) {
		return false;
	}
	$ips = $peer['allowed_ips'] ?? null;
	if (!is_array($ips) || empty($ips)) {
		return false;
	}
	foreach ($ips as $cidr) {
		if (!is_string($cidr) || !restapi_valid_network($cidr) || (strpos($cidr, '/') === false)) {
			return false;
		}
	}
	return true;
}

/* pure: whether two networks (CIDR or address) overlap. */
function fleet_networks_overlap(string $a, string $b): bool {
	return restapi_ip_in_network(explode('/', $a)[0], $b) || restapi_ip_in_network(explode('/', $b)[0], $a);
}

/* pure: the first of $local that overlaps one of $overlay, or null (E107). */
function fleet_overlay_conflict(array $overlay, array $local): ?string {
	foreach ($local as $l) {
		foreach ($overlay as $o) {
			if (fleet_networks_overlap($l, $o)) {
				return $l;
			}
		}
	}
	return null;
}

/* pure: the networks the controller's tunnel traffic comes from (the peers' allowed ips). */
function fleet_controller_networks(array $peers): array {
	$nets = array();
	foreach ($peers as $peer) {
		foreach ($peer['allowed_ips'] as $cidr) {
			$nets[$cidr] = $cidr;
		}
	}
	return array_values($nets);
}

/* pure: the tunnel's MTU: smaller when a peer's endpoint is a tailnet address (100.64.0.0/10 or fd7a:115c:a1e0::/48). */
function fleet_tunnel_mtu(array $peers): int {
	foreach ($peers as $peer) {
		$host = trim((string)preg_replace('/:\d+$/', '', (string)($peer['endpoint'] ?? '')), '[]');
		if ((ip2long($host) !== false) && ((ip2long($host) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000))) {
			return FLEET_WG_MTU_TAILNET;
		}
		if (stripos($host, 'fd7a:115c:a1e0:') === 0) {
			return FLEET_WG_MTU_TAILNET;
		}
	}
	return FLEET_WG_MTU;
}

/*
 * pure: the commands that bring the tunnel up, as argument lists. The node
 * starts the connection (NAT safe); there is no listen port.
 */
function fleet_tunnel_commands(string $overlay_address, array $peers): array {
	$v6 = (strpos($overlay_address, ':') !== false);
	$cmds = array(
		array('/sbin/ifconfig', 'wg', 'create', 'name', FLEET_WG_IF),
		array('/usr/bin/wg', 'set', FLEET_WG_IF, 'private-key', FLEET_WG_KEY),
	);
	foreach ($peers as $peer) {
		$set = array('/usr/bin/wg', 'set', FLEET_WG_IF, 'peer', $peer['public_key']);
		if (!empty($peer['endpoint'])) {
			array_push($set, 'endpoint', $peer['endpoint']);
		}
		array_push($set, 'persistent-keepalive', (string)FLEET_WG_KEEPALIVE, 'allowed-ips', implode(',', $peer['allowed_ips']));
		$cmds[] = $set;
	}
	$cmds[] = array('/sbin/ifconfig', FLEET_WG_IF, $v6 ? 'inet6' : 'inet', $overlay_address, $v6 ? 'prefixlen' : 'netmask',
	    $v6 ? '128' : '255.255.255.255');
	$cmds[] = array('/sbin/ifconfig', FLEET_WG_IF, 'mtu', (string)fleet_tunnel_mtu($peers), 'up');
	foreach (fleet_controller_networks($peers) as $cidr) {
		$cmds[] = array('/sbin/route', '-n', 'add', (strpos($cidr, ':') !== false) ? '-inet6' : '-inet', $cidr, '-interface', FLEET_WG_IF);
	}
	return $cmds;
}

/*
 * pure: the hostname and domain the controller named this box (its naming
 * group's pattern and domain), each null when not sent, not valid, or
 * already set.
 */
function fleet_join_names(array $m, string $hostname, string $domain): array {
	$host = strtolower(trim((string)($m['hostname'] ?? '')));
	$dom = strtolower(trim((string)($m['domain'] ?? '')));
	$host = (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D', $host) && ($host !== strtolower($hostname))) ? $host : null;
	$dom = (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D', $dom) && ($dom !== strtolower($domain))) ? $dom : null;
	return array($host, $dom);
}

/* pure: the <fleet> section for a membership the controller granted. */
function fleet_membership_section(array $m, int $now, array $restapi_before): array {
	return array(
		'enable' => true,
		'node_id' => $m['node_id'],
		'controller_url' => $m['controller_url'],
		'controller_fingerprint' => $m['controller_fingerprint'],
		'controller_signing_key' => $m['controller_signing_key'],
		'method' => $m['method'],
		'overlay_address' => $m['overlay_address'],
		'role' => $m['role'],
		'hostname' => $m['hostname'],
		'domain' => (string)($m['domain'] ?? ''),
		'joined' => (string)$now,
		'listener_certref' => $m['listener_certref'],
		/* config.xml keeps a list under a wrapper element: <peer><item>...</item></peer>. */
		'peer' => array('item' => array_map(function ($p) {
			return array('public_key' => $p['public_key'], 'endpoint' => (string)($p['endpoint'] ?? ''),
			    'allowed_ips' => implode(' ', $p['allowed_ips']));
		}, $m['peers'])),
		/* What the REST API setting was before, so leaving restores it. */
		'restapi_was_enabled' => !empty($restapi_before['enable']) ? 'yes' : 'no',
		'restapi_was_guiapi' => !empty($restapi_before['noguiapi']) ? 'no' : 'yes',
	);
}

/* pure: the listener the controller reaches this box's API on. */
function fleet_listener_post(array $m): array {
	return array('descr' => FLEET_LISTENER_DESCR, 'address' => 'fleet', 'port' => FLEET_NODE_API_PORT,
	    'certref' => $m['listener_certref'], 'allowednetworks' => implode(' ', fleet_controller_networks($m['peers'])),
	    'ratelimit' => '50', 'enable' => true, 'firewall' => true);
}

/*
 * pure: the early pf rule that lets the controller reach this box's API
 * through the tunnel (only from the controller's networks, only to the
 * overlay address and API port). Empty when not enrolled over WireGuard.
 */
function fleet_filter_early_rules_for(array $m, array $peers): string {
	$nets = fleet_controller_networks($peers);
	if (!$m['enrolled'] || ($m['method'] !== 'wireguard') || empty($nets) || !filter_var($m['overlay_address'], FILTER_VALIDATE_IP)) {
		return '';
	}
	$family = (strpos($m['overlay_address'], ':') !== false) ? 'inet6' : 'inet';
	return "pass in quick on " . FLEET_WG_IF . " {$family} proto tcp from { " . implode(' ', $nets) . " } to {$m['overlay_address']} port " .
	    FLEET_NODE_API_PORT . " keep state label \"USER_RULE: FreeSense Fleet controller access\"\n";
}

/* What filter.inc adds to the early rules. */
function fleet_filter_early_rules(): string {
	return fleet_filter_early_rules_for(fleet_node_membership(), fleet_stored_peers());
}

/*
 * pure: a configuration to restore, with this box's fleet membership taken
 * from the running configuration: the <fleet> section, the fleet-controller
 * user and its API keys, the API certificate and the tunnel listener. A
 * restore from the controller must never cut the box off from it, whatever
 * the backup contained (also a backup from before the box joined).
 */
function fleet_restore_carry(array $restored, array $running): array {
	$mine = function ($list, callable $is) {
		return array_values(array_filter(is_array($list) ? $list : array(), function ($x) use ($is) {
			return is_array($x) && $is($x);
		}));
	};
	$theirs = function ($list, callable $is) {
		return array_values(array_filter(is_array($list) ? $list : array(), function ($x) use ($is) {
			return is_array($x) && !$is($x);
		}));
	};
	$is_user = function ($u) {
		return ($u['name'] ?? '') === FLEET_USER;
	};
	$is_token = function ($t) {
		return ($t['username'] ?? '') === FLEET_USER;
	};
	$is_cert = function ($c) {
		return ($c['descr'] ?? '') === FLEET_CERT_DESCR;
	};
	$is_listener = function ($l) {
		return fleet_listener_is_fleet($l);
	};

	if (isset($running['fleet'])) {
		$restored['fleet'] = $running['fleet'];
	} else {
		unset($restored['fleet']);
	}
	$restored['system']['user'] = array_merge($theirs($restored['system']['user'] ?? null, $is_user), $mine($running['system']['user'] ?? null, $is_user));
	$restored['system']['nextuid'] = (string)max((int)($restored['system']['nextuid'] ?? 0), (int)($running['system']['nextuid'] ?? 0));
	$restored['cert'] = array_merge($theirs($restored['cert'] ?? null, $is_cert), $mine($running['cert'] ?? null, $is_cert));
	$restored['restapi']['apitoken'] = array_merge($theirs($restored['restapi']['apitoken'] ?? null, $is_token),
	    $mine($running['restapi']['apitoken'] ?? null, $is_token));
	$restored['restapi']['apilistener'] = array_merge($theirs($restored['restapi']['apilistener'] ?? null, $is_listener),
	    $mine($running['restapi']['apilistener'] ?? null, $is_listener));
	if (isset($running['fleet'])) {
		/* The listener needs the REST API on; the WebGUI's own API stays as the backup had it. */
		$restored['restapi']['enable'] = true;
		if (!isset($restored['restapi']['noguiapi']) && !empty($running['restapi']['noguiapi'])) {
			$restored['restapi']['noguiapi'] = true;
		}
	}
	foreach (array('cert', 'restapi') as $k) {
		foreach ($restored[$k] as $sub => $v) {
			if ($v === array()) {
				unset($restored[$k][$sub]);
			}
		}
	}
	if ($restored['cert'] === array()) {
		unset($restored['cert']);
	}
	return $restored;
}

/* pure: why a parsed configuration cannot be restored on this box, or null. */
function fleet_restore_problem($parsed, string $running_version): ?string {
	if (!is_array($parsed) || !isset($parsed['version']) || !isset($parsed['system'])) {
		return 'This is not a FreeSense configuration.';
	}
	if (version_compare((string)$parsed['version'], $running_version, '>')) {
		return "The configuration is from a newer FreeSense (config version {$parsed['version']}, this firewall has {$running_version}). Update the firewall first.";
	}
	return null;
}

/*
 * Restore a configuration from the controller. Runs inside a commit-confirm
 * window, so the box goes back by itself if the controller cannot confirm.
 */
function fleet_restore_apply(string $xml, string $description): void {
	global $config;
	require_once('xmlparse.inc');
	$root = g_get('xml_rootobj');
	/* The parser reads files only; both files hold secrets, so they are root-only and removed after. */
	$in = g_get('tmp_path') . '/fleet-restore-in-' . bin2hex(random_bytes(6)) . '.xml';
	$tmp = g_get('tmp_path') . '/fleet-restore-' . bin2hex(random_bytes(6)) . '.xml';
	$umask = umask(077);
	try {
		$parsed = (@file_put_contents($in, $xml) === false) ? -1 : parse_xml_config($in, array($root, 'pfsense'));
	} finally {
		@unlink($in);
		umask($umask);
	}
	$problem = fleet_restore_problem($parsed, (string)config_get_path('version', ''));
	if ($problem !== null) {
		throw new InvalidArgumentException($problem);
	}
	$merged = fleet_restore_carry($parsed, $config);
	$umask = umask(077);
	$written = @file_put_contents($tmp, dump_xml_config($merged, $root));
	umask($umask);
	if ($written === false) {
		throw new RuntimeException('The configuration could not be written.');
	}
	try {
		if (!config_restore($tmp, $description)) {
			throw new RuntimeException('The configuration could not be restored.');
		}
	} finally {
		@unlink($tmp);
	}
	/* Rules and aliases now; some settings (interfaces, services) apply fully after a reboot. */
	require_once('filter.inc');
	filter_configure();
}

/* pure: kern.boottime ("{ sec = 1792097681, usec = 168518 } Thu Oct ...") as a Unix time, or 0. */
function fleet_boottime_parse(string $sysctl): int {
	return preg_match('/\bsec = (\d+)/', $sysctl, $m) ? (int)$m[1] : 0;
}

/* pure: what the progress file holds after each join step. */
function fleet_progress_record(array $steps, string $name, string $state, string $text, int $now): array {
	$steps[$name] = array('state' => $state, 'text' => $text, 'time' => $now);
	return $steps;
}

/* ------------------------------------------------------------------ */
/* System access                                                       */
/* ------------------------------------------------------------------ */

/* Run a command (no shell); returns array(exit code, output lines). */
function fleet_exec(array $argv): array {
	$proc = proc_open($argv, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($proc)) {
		return array(127, array('could not run ' . $argv[0]));
	}
	$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return array(proc_close($proc), array_values(array_filter(explode("\n", trim($out)), 'strlen')));
}

/* The subnets configured on this box, as CIDR strings. */
function fleet_local_networks(): array {
	$nets = array();
	foreach (get_configured_interface_list() as $ifname) {
		$ip = get_interface_ip($ifname);
		$bits = get_interface_subnet($ifname);
		if (is_ipaddrv4($ip) && ($bits !== false) && ($bits !== '')) {
			$nets[] = "{$ip}/{$bits}";
		}
		$ip6 = get_interface_ipv6($ifname);
		$bits6 = get_interface_subnetv6($ifname);
		if (is_ipaddrv6($ip6) && ($bits6 !== false) && ($bits6 !== '')) {
			$nets[] = "{$ip6}/{$bits6}";
		}
	}
	return $nets;
}

/* Take the tunnel down (also when it is half up). */
function fleet_tunnel_down(): void {
	fleet_exec(array('/sbin/ifconfig', FLEET_WG_IF, 'destroy'));
}

/* Bring the tunnel up from the stored membership; returns an error text or null. */
function fleet_tunnel_up(array $m, array $peers): ?string {
	fleet_tunnel_down();
	foreach (fleet_tunnel_commands($m['overlay_address'], $peers) as $argv) {
		list($rc, $out) = fleet_exec($argv);
		if ($rc !== 0) {
			fleet_tunnel_down();
			return basename($argv[0]) . ' failed: ' . implode(' ', $out);
		}
	}
	return null;
}

/* ------------------------------------------------------------------ */
/* Controller endpoints (primary and standby controller)               */
/* ------------------------------------------------------------------ */

/* pure: a controller endpoint list as sent (1-4 host:port, no repeats), or null when it is not one. */
function fleet_endpoints_valid($list): ?array {
	if (!is_array($list) || empty($list) || (count($list) > 4) || (array_values($list) !== $list)) {
		return null;
	}
	foreach ($list as $e) {
		if (!is_string($e) || !preg_match('~^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9][A-Za-z0-9.-]*):[0-9]{1,5}$~D', $e) || ((int)substr(strrchr($e, ':'), 1) > 65535)) {
			return null;
		}
	}
	return (count(array_unique($list)) === count($list)) ? $list : null;
}

/* pure: the endpoint after $current in $list (the first when $current is not in it). */
function fleet_endpoint_next(array $list, string $current): string {
	$i = array_search($current, $list, true);
	return ($i === false) ? $list[0] : $list[($i + 1) % count($list)];
}

/*
 * pure: what the endpoint check does with the controller's last handshake
 * ($handshake, 0 = none) and the last switch: array('good'|'keep'|'switch',
 * endpoint). A new endpoint gets FLEET_ENDPOINT_STALE seconds to answer.
 */
function fleet_endpoint_decide(array $list, string $current, int $handshake, int $last_switch, int $now): array {
	if (($handshake > 0) && (($now - $handshake) <= FLEET_ENDPOINT_STALE)) {
		return array('good', $current);
	}
	if ((count($list) < 2) || (($now - $last_switch) < FLEET_ENDPOINT_STALE)) {
		return array('keep', $current);
	}
	return array('switch', fleet_endpoint_next($list, $current));
}

/* pure: the controller's peer (the one with an endpoint), or null. */
function fleet_controller_peer(array $peers): ?array {
	foreach ($peers as $p) {
		if (!empty($p['endpoint'])) {
			return $p;
		}
	}
	return null;
}

/* pure: $peers with the controller peer's endpoint set to $endpoint. */
function fleet_peers_with_endpoint(array $peers, string $endpoint): array {
	foreach ($peers as $i => $p) {
		if (!empty($p['endpoint'])) {
			$peers[$i]['endpoint'] = $endpoint;
			break;
		}
	}
	return $peers;
}

/* The controller endpoints this firewall knows: the stored list, else the controller peer's endpoint. */
function fleet_controller_endpoints(): array {
	$list = fleet_endpoints_valid(array_values(array_filter(explode(',', (string)config_get_path('fleet/controller_endpoints', '')), 'strlen')));
	if ($list !== null) {
		return $list;
	}
	$peer = fleet_controller_peer(fleet_stored_peers());
	return ($peer === null) ? array() : array($peer['endpoint']);
}

/* {endpoint, switched, good}: what the endpoint check last did. */
function fleet_endpoint_state(): array {
	$s = json_decode((string)@file_get_contents(FLEET_ENDPOINT_STATE), true);
	return is_array($s) ? $s : array();
}

function fleet_endpoint_state_save(array $s): void {
	@file_put_contents(FLEET_ENDPOINT_STATE, json_encode($s));
}

/* The endpoint the tunnel uses now: the last one the check set, if still in the list. */
function fleet_endpoint_current(): string {
	$list = fleet_controller_endpoints();
	$cur = (string)(fleet_endpoint_state()['endpoint'] ?? '');
	return in_array($cur, $list, true) ? $cur : (string)($list[0] ?? '');
}

/*
 * Store the controller endpoints and the controller epoch. A lower epoch
 * than the stored one comes from a controller that was replaced: refused
 * (FLEET-E412). Runs the endpoint check every minute when there is more
 * than one endpoint. Returns 'set' or 'unchanged'.
 */
function fleet_endpoints_set(array $list, int $epoch): string {
	if (fleet_endpoints_valid($list) === null) {
		throw new InvalidArgumentException('invalid_endpoints: endpoints must be 1-4 different host:port values');
	}
	$stored = (int)config_get_path('fleet/controller_epoch', 0);
	if ($epoch < $stored) {
		throw new DomainException(sprintf('FLEET-E412: A newer controller (epoch %d) manages this firewall; this controller (epoch %d) was replaced.', $stored, $epoch));
	}
	$joined = implode(',', $list);
	if (($joined === (string)config_get_path('fleet/controller_endpoints', '')) && ($epoch === $stored)) {
		return 'unchanged';
	}
	config_set_path('fleet/controller_endpoints', $joined);
	config_set_path('fleet/controller_epoch', (string)$epoch);
	install_cron_job(FLEET_ENDPOINT_CRON, count($list) > 1, '*', '*', '*', '*', '*', 'root', false);
	write_config(sprintf('Fleet: the controller can be reached at %s', implode(', ', $list)));
	return 'set';
}

/* Every minute (cron): when the controller has not answered for a while, try its next endpoint. Returns what was done. */
function fleet_endpoint_check(int $now): string {
	$m = fleet_node_membership();
	$list = fleet_controller_endpoints();
	if (!$m['enrolled'] || ($m['method'] !== 'wireguard') || (count($list) < 2)) {
		install_cron_job(FLEET_ENDPOINT_CRON, false, '*', '*', '*', '*', '*', 'root');
		return 'one endpoint';
	}
	$peer = fleet_controller_peer(fleet_stored_peers());
	if ($peer === null) {
		return 'no controller peer';
	}
	$st = fleet_endpoint_state();
	$cur = fleet_endpoint_current();
	$hs = 0;
	foreach (fleet_exec(array('/usr/bin/wg', 'show', FLEET_WG_IF, 'latest-handshakes'))[1] as $line) {
		$f = preg_split('/\s+/', trim($line));
		if (($f[0] ?? '') === $peer['public_key']) {
			$hs = (int)($f[1] ?? 0);
		}
	}
	list($act, $ep) = fleet_endpoint_decide($list, $cur, $hs, (int)($st['switched'] ?? 0), $now);
	if ($act === 'switch') {
		fleet_exec(array('/usr/bin/wg', 'set', FLEET_WG_IF, 'peer', $peer['public_key'], 'endpoint', $ep));
		fleet_endpoint_state_save(array('endpoint' => $ep, 'switched' => $now, 'good' => $st['good'] ?? ''));
		logger(LOG_NOTICE, localize_text('Fleet: the controller did not answer at %s; trying %s', $cur, $ep));
	} elseif (($act === 'good') && ((($st['good'] ?? '') !== $cur) || (($st['endpoint'] ?? '') !== $cur))) {
		fleet_endpoint_state_save(array('endpoint' => $cur, 'switched' => (int)($st['switched'] ?? 0), 'good' => $cur));
	}
	return "{$act} {$ep}";
}

/* The peers stored in config.xml <fleet>. */
function fleet_stored_peers(): array {
	return fleet_peers_from_section((array)config_get_path('fleet/peer', []));
}

/*
 * pure: the peers of a <fleet><peer> section as read back from config.xml:
 * <peer><item>..</item></peer> gives ['item' => entry] for one peer and
 * ['item' => [entry, ...]] for several.
 */
function fleet_peers_from_section(array $section): array {
	$items = $section['item'] ?? $section;
	if (is_array($items) && isset($items['public_key'])) {
		$items = array($items);
	}
	$peers = array();
	foreach ((array)$items as $p) {
		if (is_array($p)) {
			$peers[] = array('public_key' => (string)($p['public_key'] ?? ''), 'endpoint' => (string)($p['endpoint'] ?? ''),
			    'allowed_ips' => preg_split('/\s+/', trim((string)($p['allowed_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY));
		}
	}
	return array_values(array_filter($peers, 'fleet_valid_peer'));
}

/* At boot: bring the tunnel up for a managed box (rc.bootup, before the listeners start). */
function fleet_boot(): void {
	$m = fleet_node_membership();
	if ($m['enrolled']) {
		fleet_access_refresh($m['role']);
	}
	if (!$m['enrolled'] || ($m['method'] !== 'wireguard') || !is_file(FLEET_WG_KEY)) {
		return;
	}
	$peers = fleet_stored_peers();
	$good = (string)(fleet_endpoint_state()['good'] ?? '');
	if (in_array($good, fleet_controller_endpoints(), true)) {
		$peers = fleet_peers_with_endpoint($peers, $good);
	}
	fleet_endpoint_state_save(array('endpoint' => $good ?: (string)(fleet_controller_peer($peers)['endpoint'] ?? ''), 'switched' => time(), 'good' => $good));
	$error = fleet_tunnel_up($m, $peers);
	if ($error !== null) {
		logger(LOG_ERR, localize_text('Fleet: the management tunnel did not start: %s', $error));
	}
}

/*
 * After a System update a role may grant more (or less): bring the
 * fleet-controller user's privileges in line with what this version gives
 * its role. Saves only when they differ.
 */
function fleet_access_refresh(string $role): bool {
	foreach (config_get_path('system/user', array()) as $i => $u) {
		if (!is_array($u) || (($u['name'] ?? '') !== FLEET_USER)) {
			continue;
		}
		$want = fleet_access_privs($role);
		if ((array)($u['priv'] ?? array()) === $want) {
			return false;
		}
		config_set_path("system/user/{$i}/priv", $want);
		$was = fleet_lock_bypass();
		fleet_lock_bypass(true);
		write_config('Fleet: controller access updated for this version');
		fleet_lock_bypass($was);
		logger(LOG_NOTICE, 'Fleet: the controller access was updated for this version.');
		return true;
	}
	return false;
}

/*
 * Create the fleet-controller user, its API key and the API certificate.
 * Returns array(api key, undo, array(cert_sha256, certref)). Throws on failure
 * after undoing its own work.
 */
function fleet_access_create(string $role): array {
	fleet_lock_bypass(true);
	try {
		return fleet_access_create_unlocked($role);
	} finally {
		fleet_lock_bypass(false);
	}
}

function fleet_access_create_unlocked(string $role): array {
	require_once('auth.inc');
	require_once('certs.inc');
	require_once('restapi.inc');
	if (restapi_local_user(FLEET_USER) !== null) {
		throw new RuntimeException('The user ' . FLEET_USER . ' already exists.');
	}
	$undo = function () {
		fleet_access_remove();
	};
	try {
		$uid = (int)config_get_path('system/nextuid');
		config_set_path('system/nextuid', $uid + 1);
		$entry = fleet_access_user_entry($uid, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), $role);
		config_set_path('system/user/', $entry);
		write_config('Fleet: user ' . FLEET_USER . ' created.');
		/* getUserEntry() reads an index built when auth.inc loaded. */
		$GLOBALS['userindex'] = index_users();
		local_user_set($entry);

		$post = array('username' => FLEET_USER, 'descr' => 'FreeSense Fleet controller', 'expires_days' => '');
		if ($role !== 'managed') {
			$post['readonly'] = true;
		}
		$token = restapi_create_token($post);
		if (!empty($token['input_errors'])) {
			throw new RuntimeException(implode(' ', $token['input_errors']));
		}

		$cert = array('refid' => uniqid(), 'descr' => FLEET_CERT_DESCR);
		$dn = array('organizationName' => 'FreeSense Fleet node', 'commonName' => config_get_path('system/hostname') . '-fleet');
		if (!cert_create($cert, null, 0, 3650, $dn, 'self-signed', 'sha256', 'ECDSA', 'prime256v1')) {
			throw new RuntimeException('The API certificate could not be created.');
		}
		config_set_path('cert/', $cert);
		write_config('Fleet: API certificate created.');
		return array($token['token'], $undo, array('cert_sha256' => fleet_cert_fingerprint(base64_decode($cert['crt'])),
		    'certref' => $cert['refid']));
	} catch (Throwable $e) {
		$undo();
		throw $e;
	}
}

/* Remove the fleet-controller user, its keys and the API certificate. */
function fleet_access_remove(): void {
	require_once('auth.inc');
	foreach (config_get_path('system/user', []) as $idx => $user) {
		if (is_array($user) && (($user['name'] ?? '') === FLEET_USER)) {
			local_user_del($user);
			local_user_revoke_api_keys(FLEET_USER);
			config_del_path("system/user/{$idx}");
			config_set_path('system/user', array_values(config_get_path('system/user', [])));
		}
	}
	foreach (config_get_path('cert', []) as $idx => $cert) {
		if (is_array($cert) && (($cert['descr'] ?? '') === FLEET_CERT_DESCR)) {
			config_del_path("cert/{$idx}");
			config_set_path('cert', array_values(config_get_path('cert', [])));
		}
	}
	write_config('Fleet: access for the controller removed.');
	$GLOBALS['userindex'] = index_users();
}

/*
 * Apply a membership: key file, <fleet> section, listener, tunnel. Returns an
 * undo callable. Throws (after undoing its own work) with the reason.
 */
function fleet_apply(array $m): callable {
	fleet_lock_bypass(true);
	try {
		return fleet_apply_unlocked($m);
	} finally {
		fleet_lock_bypass(false);
	}
}

function fleet_apply_unlocked(array $m): callable {
	require_once('restapi.inc');
	require_once('restapi_listener.inc');
	foreach ($m['peers'] as $peer) {
		if (!fleet_valid_peer($peer)) {
			throw new RuntimeException('The controller sent an invalid tunnel peer.');
		}
	}
	if (!preg_match('~^[0-9a-fA-F:.]+$~D', $m['overlay_address']) || !filter_var($m['overlay_address'], FILTER_VALIDATE_IP)) {
		throw new RuntimeException('The controller sent an invalid overlay address.');
	}
	$conflict = fleet_overlay_conflict(array_merge(array($m['overlay_address'] . '/' . (strpos($m['overlay_address'], ':') ? '128' : '32')),
	    fleet_controller_networks($m['peers'])), fleet_local_networks());
	if ($conflict !== null) {
		throw new FleetJoinError('E107', "The management network overlaps a local subnet ({$conflict}). Choose another overlay on the controller.");
	}
	$restapi_before = (array)config_get_path('restapi', []);
	$m['controller_networks'] = fleet_controller_networks($m['peers']);

	$undo = function () use ($restapi_before) {
		fleet_leave('rollback', $restapi_before);
	};
	try {
		if (!fleet_node_dir(FLEET_NODE_DIR) || !fleet_node_write(FLEET_WG_KEY, $m['wg_private'] . "\n")) {
			throw new RuntimeException('The tunnel key could not be saved.');
		}
		config_set_path('fleet', fleet_membership_section($m, time(), $restapi_before));
		/* The name the controller gave this box (its naming group). */
		list($new_host, $new_domain) = fleet_join_names($m, (string)config_get_path('system/hostname', ''), (string)config_get_path('system/domain', ''));
		if ($new_host !== null) {
			config_set_path('system/hostname', $new_host);
		}
		if ($new_domain !== null) {
			config_set_path('system/domain', $new_domain);
		}
		if (empty($restapi_before['enable'])) {
			/* On for the listener only: nothing new on the WebGUI port. */
			config_set_path('restapi/enable', true);
			config_set_path('restapi/noguiapi', true);
		}
		write_config('Fleet: joined ' . $m['controller_url'] . '.');
		if ($m['method'] === 'wireguard') {
			$error = fleet_tunnel_up($m, $m['peers']);
			if ($error !== null) {
				throw new RuntimeException($error);
			}
		}
		$saved = restapi_listener_save(fleet_listener_post($m));
		if (!empty($saved['input_errors'])) {
			throw new RuntimeException('The management API listener could not be set up: ' . implode(' ', $saved['input_errors']));
		}
		if (($new_host !== null) || ($new_domain !== null)) {
			system_hostname_configure();
			system_hosts_generate();
			system_resolvconf_generate();
		}
		logger(LOG_NOTICE, localize_text('Fleet: this firewall is now managed by %s.', $m['controller_url']));
		return $undo;
	} catch (Throwable $e) {
		$undo();
		throw $e;
	}
}

/*
 * Leave the fleet: $why is "leave", "detach" (works without the controller) or
 * "rollback" (a failed join). Removes the listener, tunnel, access and <fleet>,
 * restores the REST API setting and keeps a copy of the last configuration.
 */
function fleet_leave(string $why, ?array $restapi_before = null): void {
	fleet_lock_bypass(true);
	try {
		fleet_leave_unlocked($why, $restapi_before);
	} finally {
		fleet_lock_bypass(false);
	}
}

function fleet_leave_unlocked(string $why, ?array $restapi_before): void {
	require_once('restapi_listener.inc');
	if (($why !== 'rollback') && is_file(g_get('conf_path') . '/config.xml')) {
		if (fleet_node_dir(FLEET_ARCHIVE_DIR)) {
			@copy(g_get('conf_path') . '/config.xml', FLEET_ARCHIVE_DIR . '/config-' . time() . '.xml');
			@chmod(FLEET_ARCHIVE_DIR . '/config-' . time() . '.xml', 0600);
		}
	}
	if ($restapi_before === null) {
		$restapi_before = array(
			'enable' => (config_get_path('fleet/restapi_was_enabled') === 'yes'),
			'noguiapi' => (config_get_path('fleet/restapi_was_guiapi') === 'no'),
		);
	}
	$list = array_values(array_filter((array)config_get_path('restapi/apilistener', []), function ($l) {
		return !fleet_listener_is_fleet($l);
	}));
	config_set_path('restapi/apilistener', $list);
	if (empty($restapi_before['enable'])) {
		config_del_path('restapi/enable');
		config_del_path('restapi/noguiapi');
	}
	config_del_path('fleet');
	fleet_access_remove();
	fleet_tunnel_down();
	@unlink(FLEET_WG_KEY);
	@unlink(FLEET_POLICY_BUNDLE);
	/* Other boxes' replicas are fleet data; a box that left keeps none. */
	foreach (glob(FLEET_VAULT_DIR . '/*') ?: array() as $file) {
		@unlink($file);
	}
	@rmdir(FLEET_VAULT_DIR);
	write_config("Fleet: left the fleet ({$why}).");
	restapi_listeners_apply();
	logger(LOG_NOTICE, localize_text('Fleet: this firewall left its fleet (%s).', $why));
}

/* The progress of a join, for the WebUI and console (a JSON file in RAM). */
function fleet_progress_write(string $name, string $state, string $text): void {
	$steps = @json_decode((string)@file_get_contents(FLEET_PROGRESS_FILE), true);
	$steps = fleet_progress_record(is_array($steps) ? $steps : array(), $name, $state, $text, time());
	@file_put_contents(FLEET_PROGRESS_FILE, json_encode($steps), LOCK_EX);
	@chmod(FLEET_PROGRESS_FILE, 0600);
}

/* The inventory sent when enrolling. */
function fleet_inventory(): array {
	$serial = trim((string)@shell_exec('/bin/kenv -q smbios.system.serial 2>/dev/null'));
	return array('hostname' => config_get_path('system/hostname'), 'version' => trim((string)@file_get_contents('/etc/version')),
	    'platform' => php_uname('m'), 'serial' => $serial, 'cpu' => get_single_sysctl('hw.model'),
	    'memory_bytes' => (int)get_single_sysctl('hw.physmem'), 'instance_id' => (string)config_get_path('cloudinit/instance_id', ''));
}

/* The real $io for fleet_join_run(). */
function fleet_join_io(array $link): array {
	return array(
		'post' => function ($path, $body, $bearer) use ($link) { return fleet_http_pinned($link, 'POST', $path, $body, $bearer); },
		'get' => function ($path, $bearer) use ($link) { return fleet_http_pinned($link, 'GET', $path, null, $bearer); },
		'wg_keypair' => 'fleet_wg_keypair',
		'clock' => 'time',
		'create_access' => 'fleet_access_create',
		'apply' => 'fleet_apply',
		'inventory' => 'fleet_inventory',
		'step' => 'fleet_progress_write',
		'sleep' => 'sleep',
	);
}

/*
 * Join with a link and the key as typed. Refuses a box that is already
 * managed (E109). Returns the membership; throws FleetJoinError.
 */
function fleet_join(string $link_text, string $key_typed): array {
	if (fleet_node_membership()['enrolled']) {
		throw new FleetJoinError('E109', 'This firewall is already managed. Leave its fleet first.');
	}
	$link = fleet_join_link_parse($link_text);
	$key = fleet_join_key_normalize($key_typed);
	if ($key === null) {
		throw new FleetJoinError('E103', 'The join key is not valid. Check it for typing mistakes.');
	}
	@unlink(FLEET_PROGRESS_FILE);
	return fleet_join_run($link, $key, fleet_join_io($link));
}
