<?php
/*
 * fleet_paths.inc
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
 * Fleet connection paths: besides the management tunnel a firewall joined
 * with, the controller may reach its API another way:
 *
 *   direct     on an internal interface (LAN, MPLS, an existing VPN): the
 *              controller routes to the firewall's address
 *   tailscale  on the firewall's tailnet address (tailscale0)
 *
 * A path is one more REST API listener on the Fleet port with the Fleet
 * certificate, open only to the controller's address(es), with its own
 * firewall rule. The tunnel stays, so a path that stops working never
 * cuts the firewall off: the controller falls back to the tunnel. A
 * direct path on a WAN interface is refused (FLEET-E135) unless the
 * controller asks for it explicitly. Functions marked "pure" touch
 * neither the system nor config.xml.
 */

require_once('fleet_agent.inc');
/* restapi_listener.inc (and the interface code) are loaded where they are used, so the pure part loads on its own. */

define('FLEET_PATH_METHODS', array('direct', 'tailscale'));
define('FLEET_PATH_MAX_ALLOWED', 4);

/* pure: the listener description of a path (and how it is found again). */
function fleet_path_descr(string $method): string {
	return FLEET_PATH_DESCR . $method;
}

/* pure: the path method a listener is for, or null. */
function fleet_path_method_of(array $l): ?string {
	$d = (string)($l['descr'] ?? '');
	if (strncmp($d, FLEET_PATH_DESCR, strlen(FLEET_PATH_DESCR)) !== 0) {
		return null;
	}
	$m = substr($d, strlen(FLEET_PATH_DESCR));
	return in_array($m, FLEET_PATH_METHODS, true) ? $m : null;
}

/*
 * pure: why a path cannot be set, or null. $choices: key => {label,
 * addresses, wan}. $allowed: the controller's address(es) on that path.
 */
function fleet_path_problem(string $method, string $key, array $choices, array $allowed, bool $wan_ok): ?string {
	if (!in_array($method, FLEET_PATH_METHODS, true)) {
		return 'The connection method is direct or tailscale.';
	}
	if (($method === 'tailscale') !== ($key === 'tailscale')) {
		return ($method === 'tailscale') ? 'A Tailscale path uses the firewall\'s tailnet address (tailscale0).' : 'A direct path uses an interface or virtual IP, not tailscale0.';
	}
	if (!isset($choices[$key])) {
		return ($key === 'tailscale') ? 'FLEET-E131: Tailscale is not running on this firewall (no tailnet address).' :
		    'That is not an interface or virtual IP of this firewall.';
	}
	if (empty($choices[$key]['addresses'])) {
		return 'That interface has no address right now.';
	}
	if (!empty($choices[$key]['wan']) && !$wan_ok) {
		return 'FLEET-E135: That is a WAN interface. A direct path on WAN puts the API on the internet; choose an internal interface, or confirm WAN with the controller\'s address as the only one allowed.';
	}
	if (empty($allowed) || (count($allowed) > FLEET_PATH_MAX_ALLOWED)) {
		return 'Give the controller\'s address on this path (1 to ' . FLEET_PATH_MAX_ALLOWED . ' addresses).';
	}
	foreach ($allowed as $a) {
		if (!is_string($a) || (filter_var($a, FILTER_VALIDATE_IP) === false)) {
			return 'The allowed addresses are single IP addresses (the controller), not networks.';
		}
	}
	return null;
}

/* pure: the REST API listener a path is. */
function fleet_path_post(string $method, string $key, array $allowed, string $certref): array {
	return array('descr' => fleet_path_descr($method), 'address' => $key, 'port' => FLEET_NODE_API_PORT, 'certref' => $certref,
	    'allowednetworks' => implode(' ', $allowed), 'ratelimit' => '50', 'enable' => true, 'firewall' => true);
}

/* Where a path may listen: key => {label, addresses, wan}. Not the tunnel or localhost. */
function fleet_path_choices(): array {
	require_once('restapi_listener.inc');
	require_once('interfaces.inc');
	require_once('gwlb.inc');
	$out = array();
	foreach (restapi_listener_address_choices() as $key => $label) {
		if (in_array($key, array('fleet', 'lo0'), true)) {
			continue;
		}
		$r = restapi_listener_resolve(array('address' => $key));
		$wan = (config_get_path("interfaces/{$key}") !== null) && (interface_has_gateway($key) || interface_has_gatewayv6($key));
		$out[$key] = array('label' => (string)$label, 'addresses' => $r['addresses'], 'wan' => $wan);
	}
	return $out;
}

/* The paths this firewall has: method => {key, label, addresses, allowed, id}. */
function fleet_paths(): array {
	require_once('restapi_listener.inc');
	$choices = null;
	$out = array();
	foreach (restapi_listeners() as $l) {
		$method = fleet_path_method_of($l);
		if ($method === null) {
			continue;
		}
		$choices = $choices ?? fleet_path_choices();
		$out[$method] = array('key' => $l['address'], 'label' => (string)($choices[$l['address']]['label'] ?? $l['address']),
		    'addresses' => restapi_listener_resolve($l)['addresses'], 'allowed' => preg_split('/\s+/', trim((string)$l['allowednetworks']), -1, PREG_SPLIT_NO_EMPTY),
		    'id' => $l['id'], 'enable' => !empty($l['enable']));
	}
	return $out;
}

/* Add or change a path. Returns the path, or throws InvalidArgumentException with the reason. */
function fleet_path_set(string $method, string $key, array $allowed, bool $wan_ok): array {
	require_once('restapi_listener.inc');
	$m = fleet_node_membership();
	$problem = fleet_path_problem($method, $key, fleet_path_choices(), array_values($allowed), $wan_ok);
	if ($problem !== null) {
		throw new InvalidArgumentException($problem);
	}
	$current = fleet_paths()[$method] ?? null;
	$saved = restapi_listener_save(fleet_path_post($method, $key, array_values($allowed), (string)$m['listener_certref']), ($current === null) ? null : $current['id']);
	if (!empty($saved['input_errors'])) {
		throw new InvalidArgumentException(implode(' ', $saved['input_errors']));
	}
	logger(LOG_NOTICE, localize_text('Fleet: %1$s path on %2$s for %3$s.', $method, $key, implode(', ', $allowed)));
	return fleet_paths()[$method];
}

/* Remove a path (the tunnel stays). Returns whether there was one. */
function fleet_path_remove(string $method): bool {
	require_once('restapi_listener.inc');
	$current = fleet_paths()[$method] ?? null;
	if ($current === null) {
		return false;
	}
	restapi_listener_delete($current['id']);
	logger(LOG_NOTICE, localize_text('Fleet: %s path removed.', $method));
	return true;
}
