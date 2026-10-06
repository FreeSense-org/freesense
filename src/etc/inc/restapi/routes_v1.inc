<?php
/*
 * restapi/routes_v1.inc
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
 * REST API v1 routes. Every handler reuses the function the matching GUI
 * page calls, and every route names that page so the GUI privilege for it
 * decides access. The special page "@authenticated" allows any valid key.
 *
 * A handler receives $req (params, query, body, user, token) and returns
 * array('data' => ...) for JSON, or array('raw' => string, 'type' => media type).
 * The front controller loads every library the handlers use.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_firewall.inc');
require_once('restapi/routes_nat.inc');
require_once('restapi/routes_routing.inc');
require_once('restapi/routes_interfaces.inc');
require_once('restapi/routes_services.inc');
require_once('restapi/routes_unbound.inc');
require_once('restapi/routes_ntp.inc');
require_once('restapi/routes_ddns.inc');
require_once('restapi/routes_dhcp.inc');
require_once('restapi/routes_vpn.inc');
require_once('restapi/routes_openvpn.inc');
require_once('restapi/routes_pki.inc');
require_once('restapi/routes_users.inc');

function restapi_routes_v1() {
	$routes = array(
		restapi_route('GET', '/v1/openapi.json', 'restapi_h_openapi', array(
			'page' => '@authenticated', 'summary' => 'This OpenAPI document')),
		restapi_route('GET', '/v1/me', 'restapi_h_me', array(
			'page' => '@authenticated',
			'summary' => 'The user and API key making the request')),

		restapi_route('GET', '/v1/system/info', 'restapi_h_system_info', array(
			'page' => 'index.php', 'area' => 'system',
			'summary' => 'Hostname, version, platform and uptime')),

		restapi_route('GET', '/v1/status/interfaces', 'restapi_h_status_interfaces', array(
			'page' => 'status_interfaces.php', 'area' => 'status',
			'summary' => 'Status and counters of the assigned interfaces')),
		restapi_route('GET', '/v1/status/gateways', 'restapi_h_status_gateways', array(
			'page' => 'status_gateways.php', 'area' => 'status',
			'summary' => 'Gateway monitoring status (latency, loss, state)')),
		restapi_route('GET', '/v1/status/services', 'restapi_h_status_services', array(
			'page' => 'status_services.php', 'area' => 'status',
			'summary' => 'Services and whether they are running')),
		restapi_route('GET', '/v1/status/arp', 'restapi_h_status_arp', array(
			'page' => 'diag_arp.php', 'area' => 'status', 'summary' => 'ARP table')),
		restapi_route('GET', '/v1/status/dhcp-leases', 'restapi_h_status_dhcp_leases', array(
			'page' => 'status_dhcp_leases.php', 'area' => 'status', 'summary' => 'DHCPv4 leases')),

		restapi_route('GET', '/v1/config/revisions', 'restapi_h_revisions', array(
			'page' => 'diag_confbak.php', 'area' => 'config',
			'summary' => 'Configuration history (newest first)')),
		restapi_route('GET', '/v1/config/revisions/{time}', 'restapi_h_revision_get', array(
			'page' => 'diag_confbak.php', 'area' => 'config', 'produces' => 'application/xml',
			'summary' => 'Download a configuration revision as XML ("current" for the running one)')),
		restapi_route('GET', '/v1/config/revisions/{time}/diff', 'restapi_h_revision_diff', array(
			'page' => 'diag_confbak.php', 'area' => 'config', 'produces' => 'text/plain',
			'query' => array('against' => 'Revision time to compare with, or "current" (default)'),
			'summary' => 'Unified diff between a revision and another revision')),
		restapi_route('POST', '/v1/config/revisions/{time}/restore', 'restapi_h_revision_restore', array(
			'page' => 'diag_confbak.php', 'area' => 'config', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'),
			    'properties' => array('confirm' => array('type' => 'boolean'))),
			'summary' => 'Revert the running configuration to a revision')),

		restapi_route('GET', '/v1/backup/remote', 'restapi_h_remote_backup', array(
			'page' => 'diag_backup_remote.php', 'area' => 'backup',
			'summary' => 'Remote backup settings, targets and last results (no secrets)')),
		restapi_route('POST', '/v1/backup/remote/run', 'restapi_h_remote_backup_run', array(
			'page' => 'diag_backup_remote.php', 'area' => 'backup', 'write' => true,
			'body' => array('type' => 'object', 'properties' => array(
			    'target' => array('type' => 'string', 'description' => 'Target id; all enabled targets when omitted'),
			    'force' => array('type' => 'boolean', 'description' => 'Upload even when the configuration is unchanged'))),
			'summary' => 'Run remote backups now')),
		restapi_route('POST', '/v1/backup/remote/targets/{id}/test', 'restapi_h_remote_backup_test', array(
			'page' => 'diag_backup_remote.php', 'area' => 'backup', 'write' => true,
			'summary' => 'Upload, list and delete a probe file on a target')),
		restapi_route('GET', '/v1/backup/remote/targets/{id}/files', 'restapi_h_remote_backup_files', array(
			'page' => 'diag_backup_remote.php', 'area' => 'backup',
			'summary' => 'Backups of this firewall stored on a target (newest first)')),
	);

	$routes = array_merge($routes, restapi_routes_firewall(), restapi_routes_nat(), restapi_routes_routing(),
	    restapi_routes_interfaces(), restapi_routes_services(), restapi_routes_unbound(), restapi_routes_ntp(),
	    restapi_routes_ddns(), restapi_routes_dhcp(), restapi_routes_vpn(), restapi_routes_openvpn(),
	    restapi_routes_pki(), restapi_routes_users());

	/* Packages may add resources: return a list of restapi_route() entries. */
	if (function_exists('pkg_call_plugins')) {
		foreach (pkg_call_plugins('plugin_restapi', array('version' => 'v1')) as $pkg => $extra) {
			foreach ((array)$extra as $route) {
				if (is_array($route) && isset($route['regex'], $route['page'], $route['handler'])) {
					$routes[] = $route;
				}
			}
		}
	}
	return $routes;
}

/* ------------------------------------------------------------------ */
/* Meta                                                                */
/* ------------------------------------------------------------------ */

function restapi_h_openapi($req) {
	return array('raw' => json_encode(restapi_openapi(restapi_routes_v1(),
	    trim((string)@file_get_contents('/etc/version')) ?: '1'), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
	    'type' => 'application/json');
}

function restapi_h_me($req) {
	$token = $req['token'];
	/* What this key can call: the user's scopes, narrowed by the key. */
	$allowed = array();
	$scopes = array();
	foreach (restapi_routes_v1() as $route) {
		if ($route['page'] === '@authenticated') {
			continue;
		}
		try {
			restapi_authorize(array('user' => $req['user'], 'token' => $token), $route);
		} catch (RestApiError $e) {
			continue;
		}
		$allowed[] = "{$route['method']} /api{$route['path']}";
		$scopes[$route['scope']] = true;
	}
	return array('data' => array(
		'user' => $req['user']['name'],
		'key' => array(
			'id' => $token['id'],
			'description' => $token['descr'] ?? '',
			'readonly' => !empty($token['readonly']),
			'expires' => empty($token['expires']) ? null : gmdate('c', (int)$token['expires']),
			'limited_to_scopes' => restapi_token_scopes($token) ?: null,
		),
		'effective_scopes' => array_keys($scopes),
		'endpoints' => $allowed,
		'admin' => isset($req['user']['uid']) && ((string)$req['user']['uid'] === '0'),
		'config_readonly' => userHasPrivilege($req['user'], 'user-config-readonly'),
	));
}

/* ------------------------------------------------------------------ */
/* System and status                                                   */
/* ------------------------------------------------------------------ */

function restapi_h_system_info($req) {
	$boottime = 0;
	if (preg_match('/sec = (\d+)/', (string)get_single_sysctl('kern.boottime'), $m)) {
		$boottime = (int)$m[1];
	}
	return array('data' => array(
		'hostname' => config_get_path('system/hostname'),
		'domain' => config_get_path('system/domain'),
		'product' => g_get('product_label'),
		'version' => trim((string)@file_get_contents('/etc/version')),
		'freebsd' => php_uname('r'),
		'platform' => php_uname('m'),
		'cpu' => get_single_sysctl('hw.model'),
		'cpu_count' => (int)get_single_sysctl('hw.ncpu'),
		'memory_bytes' => (int)get_single_sysctl('hw.physmem'),
		'uptime_seconds' => ($boottime > 0) ? time() - $boottime : null,
		'config_revision' => array(
			'time' => (int)config_get_path('revision/time', 0),
			'description' => config_get_path('revision/description', ''),
		),
	));
}

function restapi_h_status_interfaces($req) {
	$out = array();
	foreach (get_configured_interface_with_descr() as $ifname => $descr) {
		$info = get_interface_info($ifname);
		if (!is_array($info)) {
			continue;
		}
		$out[] = array('name' => $ifname, 'description' => $descr) + $info;
	}
	return array('data' => $out);
}

function restapi_h_status_gateways($req) {
	$status = return_gateways_status(true);
	return array('data' => is_array($status) ? array_values($status) : array());
}

function restapi_h_status_services($req) {
	$out = array();
	foreach (get_services() as $service) {
		if (empty($service['name'])) {
			continue;
		}
		$out[] = array(
			'name' => $service['name'],
			'description' => $service['description'] ?? '',
			'running' => (bool)get_service_status($service),
		);
	}
	return array('data' => $out);
}

function restapi_h_status_arp($req) {
	require_once('diag_arp.inc');
	$table = json_decode(prepare_ARP_table(true), true);
	return array('data' => is_array($table) ? $table : array());
}

function restapi_h_status_dhcp_leases($req) {
	$leases = system_get_dhcpleases(false);
	return array('data' => array(
		'leases' => array_values($leases['lease'] ?? array()),
		'failover' => array_values($leases['failover'] ?? array()),
	));
}

/* ------------------------------------------------------------------ */
/* Configuration history                                               */
/* ------------------------------------------------------------------ */

/* Path of a revision file; "current" is the running configuration. */
function restapi_revision_path($time) {
	if ($time === 'current') {
		return g_get('conf_path') . '/config.xml';
	}
	if (!ctype_digit((string)$time)) {
		throw new RestApiError(400, 'invalid_revision', 'A revision is a UNIX timestamp or "current".');
	}
	$path = g_get('conf_path') . "/backup/config-{$time}.xml";
	if (!is_file($path)) {
		throw new RestApiError(404, 'not_found', 'No configuration revision with that time.');
	}
	return $path;
}

function restapi_h_revisions($req) {
	cleanup_backupcache(false);
	$backups = get_backups() ?: array();
	unset($backups['versions']);
	$out = array(array(
		'time' => 'current',
		'revision_time' => (int)config_get_path('revision/time', 0),
		'description' => config_get_path('revision/description', ''),
		'version' => config_get_path('version'),
		'size' => (int)@filesize(g_get('conf_path') . '/config.xml'),
	));
	foreach ($backups as $b) {
		$out[] = array(
			'time' => (int)$b['time'],
			'description' => $b['description'],
			'version' => $b['version'],
			'size' => (int)$b['filesize'],
		);
	}
	return array('data' => $out);
}

function restapi_h_revision_get($req) {
	return array('raw' => file_get_contents(restapi_revision_path($req['params']['time'])),
	    'type' => 'application/xml');
}

function restapi_h_revision_diff($req) {
	$old = restapi_revision_path($req['params']['time']);
	$new = restapi_revision_path((string)($req['query']['against'] ?? 'current'));
	$lines = array();
	exec('/usr/bin/diff -u ' . escapeshellarg($old) . ' ' . escapeshellarg($new), $lines);
	return array('raw' => implode("\n", $lines) . (empty($lines) ? '' : "\n"), 'type' => 'text/plain');
}

function restapi_h_revision_restore($req) {
	if (($req['body']['confirm'] ?? false) !== true) {
		throw new RestApiError(400, 'confirmation_required',
		    'Reverting the configuration requires {"confirm": true}.');
	}
	$time = $req['params']['time'];
	if ($time === 'current') {
		throw new RestApiError(400, 'invalid_revision', 'The running configuration cannot be restored onto itself.');
	}
	$path = restapi_revision_path($time);
	$descr = '';
	foreach (get_backups() ?: array() as $key => $b) {
		if (($key !== 'versions') && ((string)$b['time'] === (string)$time)) {
			$descr = $b['description'];
		}
	}
	if (!config_restore($path, $descr)) {
		throw new RestApiError(500, 'restore_failed', 'The configuration could not be reverted.');
	}
	return array('data' => array(
		'restored' => (int)$time,
		'message' => 'Configuration reverted. Reboot or apply the affected features to activate it.',
	));
}

/* ------------------------------------------------------------------ */
/* Remote backups                                                      */
/* ------------------------------------------------------------------ */

function restapi_remote_target($id) {
	list(, $t) = remote_backup_find_target($id);
	if ($t === null) {
		throw new RestApiError(404, 'not_found', 'No remote backup target with that id.');
	}
	return $t;
}

function restapi_h_remote_backup($req) {
	$settings = remote_backup_settings();
	$settings['passphrase_set'] = !empty($settings['passphrase']);
	unset($settings['passphrase']);
	$state = remote_backup_state_read();
	$targets = array();
	foreach (remote_backup_targets() as $t) {
		$t = remote_backup_target_for_display($t);
		foreach (array('s3_secret_key', 'dav_password') as $secret) {
			if (isset($t[$secret])) {
				$t[$secret] = ($t[$secret] !== '') ? '(set)' : '';
			}
		}
		$t['state'] = $state[$t['id']] ?? null;
		$targets[] = $t;
	}
	return array('data' => array('settings' => $settings, 'targets' => $targets));
}

function restapi_h_remote_backup_run($req) {
	$target = $req['body']['target'] ?? null;
	if ($target !== null) {
		restapi_remote_target((string)$target);
	}
	$results = remote_backup_run(($target === null) ? null : (string)$target, 'manual',
	    !empty($req['body']['force']));
	return array('data' => array('results' => $results));
}

function restapi_h_remote_backup_test($req) {
	$result = remote_backup_test_target(restapi_remote_target($req['params']['id']));
	return array('data' => $result);
}

function restapi_h_remote_backup_files($req) {
	$names = remote_backup_list(restapi_remote_target($req['params']['id']));
	if (!is_array($names)) {
		throw new RestApiError(502, 'storage_error', (string)$names);
	}
	return array('data' => $names);
}
