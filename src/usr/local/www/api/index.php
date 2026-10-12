<?php
/*
 * api/index.php
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
 * REST API front controller. nginx sends every /api/ request here.
 * There is no GUI session, CSRF token or HTML: requests authenticate with
 * "Authorization: Bearer fsk_<id>_<secret>" and get JSON back.
 */

/* Privilege: none of its own; each endpoint checks the GUI page it mirrors. */

ini_set('display_errors', '0');
ini_set('session.use_cookies', '0');
ini_set('max_execution_time', '300');

require_once('config.inc');
require_once('functions.inc');
require_once('auth.inc');
require_once('priv.inc');
/* backup.inc must be loaded at file scope: rrd_data_xml() uses its globals. */
require_once('backup.inc');
require_once('remote_backup.inc');
/* The GUI's shared firewall save functions; they set globals at file scope. */
require_once('alias-utils.inc');
require_once('firewall_nat.inc');
require_once('firewall_nat_1to1.inc');
require_once('firewall_nat_out.inc');
require_once('firewall_nat_npt.inc');
require_once('firewall_virtual_ip.inc');
require_once('firewall_schedule.inc');
require_once('firewall_rules.inc');
/* The routing pages' shared functions (gateways, gateway groups, static routes). */
require_once('system_routing.inc');
/* The Interfaces pages' shared functions (VLAN, VXLAN, GIF, GRE). */
require_once('interfaces_tunnels.inc');
/* The Interfaces pages' shared functions (LAGG, QinQ, interface groups, bridges). */
require_once('interfaces_l2.inc');
/* The Interface Assignments page's shared functions. */
require_once('interfaces_assign.inc');
/* The interface edit page's shared functions (Interfaces > WAN, LAN, OPTn). */
require_once('interfaces_edit.inc');
/* The Services pages' shared functions (DNS Forwarder, UPnP, Wake-on-LAN, IGMP Proxy, DHCP Relay, SNMP). */
require_once('services_dnsmasq.inc');
require_once('services_upnp.inc');
require_once('services_wol.inc');
require_once('services_igmpproxy.inc');
require_once('services_dhcp_relay.inc');
require_once('services_snmp.inc');
/* The DNS Resolver pages' shared functions. */
require_once('services_unbound.inc');
/* The NTP, Dynamic DNS and RFC 2136 pages' shared functions (they define constants at file scope). */
require_once('services_ntpd.inc');
require_once('services_dyndns.inc');
/* The DHCP and DHCPv6 server pages' shared functions (settings, static mappings). */
require_once('services_dhcp.inc');
/* The VPN pages' shared functions (L2TP, IPsec pre-shared keys and tunnel list actions). */
require_once('vpn_l2tp.inc');
require_once('vpn_ipsec.inc');
/* The OpenVPN pages' shared functions (servers, clients, client specific overrides). */
require_once('vpn_openvpn.inc');
/* The certificate manager pages' shared functions (certificate authorities, revocation lists). */
require_once('system_certificates.inc');
/* The user manager pages' shared functions (users, groups, privileges, authentication servers). */
require_once('system_usermanager.inc');
require_once('system_authservers.inc');
/*
 * System > General Setup, High Availability, Update Settings, System > Advanced (Admin Access, Firewall & NAT,
 * Networking, Miscellaneous, Notifications) and the System Tunables; they set globals at file scope.
 * The log and package libraries for the read-only status routes.
 */
require_once('system_general.inc');
require_once('system_hasync.inc');
require_once('system_update_settings.inc');
require_once('system_advanced_admin.inc');
require_once('system_advanced_firewall.inc');
require_once('system_advanced_network.inc');
require_once('system_advanced_misc.inc');
require_once('system_advanced_notifications.inc');
require_once('system_advanced_sysctl.inc');
require_once('syslog.inc');
require_once('pkg-utils.inc');
/* The Diagnostics pages' shared functions (ping, traceroute, DNS lookup, states, NDP table). */
require_once('diag_tools.inc');
require_once('diag_dump_states.inc');
require_once('diag_ndp.inc');
/* The operations' shared functions (service control, reboot, halt, state table reset, Package Installer). */
require_once('status_services.inc');
require_once('diag_system.inc');
require_once('pkg_mgr_install.inc');
/* The Update Center: System > Boot Environments' shared functions (routes_update.inc). */
require_once('system_boot_environments.inc');
require_once('restapi.inc');
require_once('restapi_session.inc');
/* Live status for the WebUI (routes_status.inc). */
require_once('status_metrics.inc');
require_once('notices.inc');
/* The caller's profile, preferences, sessions and dashboard layout (routes_me.inc). */
require_once('webui_prefs.inc');
require_once('restapi_listener.inc');
require_once('restapi_log.inc');
require_once('restapi/routes_v1.inc');

/* What the request log records about this request (filled in as it is handled). */
$restapi_log_ctx = array('t' => microtime(true), 'k' => '', 'u' => '', 'w' => null, 'l' => '');

function restapi_respond($status, $body, $type = 'application/json', array $headers = array()) {
	http_response_code($status);
	header("Content-Type: {$type}; charset=utf-8");
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	foreach ($headers as $name => $value) {
		header("{$name}: {$value}");
	}
	echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
	restapi_log_response($status, is_array($body) ? (string)($body['error']['code'] ?? '') : '');
	exit;
}

/* Log the finished request (after the response has been sent to the client). */
function restapi_log_response($status, $code) {
	global $restapi_log_ctx, $method, $path, $remote_ip;
	$ms = (int)round((microtime(true) - $restapi_log_ctx['t']) * 1000);
	if (function_exists('fastcgi_finish_request')) {
		fastcgi_finish_request();
	}
	$write = $restapi_log_ctx['w'] ?? !in_array($method, array('GET', 'HEAD', 'OPTIONS'), true);
	/* WebUI sessions poll constantly: log their changes and failures, not every read. */
	if (($restapi_log_ctx['k'] === 'session') && !$write && ($status < 400)) {
		return;
	}
	try {
		restapi_reqlog_request(array('t' => $restapi_log_ctx['t'], 'ip' => $remote_ip, 'm' => $method, 'p' => '/api' . $path,
		    's' => $status, 'ms' => $ms, 'k' => $restapi_log_ctx['k'], 'u' => $restapi_log_ctx['u'], 'l' => $restapi_log_ctx['l'],
		    'w' => $write, 'c' => $code, 'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
	} catch (Throwable $e) {
		/* Logging never changes the answer. */
	}
}

$remote_ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = preg_replace('#^/api(?=/)#', '', rtrim($path, '/'));

$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
/* The WebUI calls the API as the signed-in GUI user (restapi_session.inc). */
$webui = restapi_session_requested($authorization);

try {
	if ($webui) {
		/* Only on the WebGUI port, with the GUI's own access rules. */
		if (restapi_request_listener() !== null) {
			throw new RestApiError(404, 'not_on_listener', 'WebUI sessions are only accepted on the WebGUI port.');
		}
		$listener = null;
	} else {
		if (!restapi_enabled()) {
			throw new RestApiError(404, 'api_disabled', 'The REST API is disabled (System > REST API).');
		}
		$settings = restapi_settings();
		/* An API listener (System > REST API > Listeners), or the WebGUI port (null). */
		$listener = restapi_request_listener();
		if ($listener !== null) {
			$restapi_log_ctx['l'] = $listener['id'];
			if (!$listener['enable']) {
				throw new RestApiError(404, 'listener_disabled', 'This API listener is disabled.');
			}
		} elseif (!$settings['guiapi']) {
			throw new RestApiError(404, 'not_on_webgui', 'The REST API is not served on the WebGUI port; use an API listener.');
		}
		$local = in_array($remote_ip, array('127.0.0.1', '::1'), true);
		if (!$settings['allowhttp'] && !$local && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
			throw new RestApiError(403, 'https_required', 'The REST API only accepts HTTPS requests.');
		}
		/* A listener's own allowed networks replace the general ones. */
		$networks = restapi_parse_networks((($listener !== null) && ($listener['allowednetworks'] !== '')) ?
		    $listener['allowednetworks'] : $settings['allowednetworks']);
		if (!restapi_ip_allowed($remote_ip, $networks)) {
			throw new RestApiError(403, 'source_not_allowed', 'Requests from this address are not allowed.');
		}
	}

	list($route, $params) = restapi_match(restapi_routes_v1(), $method, $path);
	$restapi_log_ctx['w'] = !empty($route['write']);
	if (($listener !== null) && $listener['readonly'] && $route['write']) {
		throw new RestApiError(403, 'listener_read_only', 'This API listener only allows requests that do not change anything.');
	}
	if ($webui) {
		$restapi_log_ctx['k'] = 'session';
		$ctx = restapi_session_authenticate($remote_ip);
	} else {
		/* The key ID (never the secret) for the log, also when authentication fails. */
		$claimed = restapi_parse_authorization($authorization);
		$restapi_log_ctx['k'] = is_array($claimed) ? $claimed[0] : '';
		$ctx = restapi_authenticate($authorization, $remote_ip);
	}
	$restapi_log_ctx['u'] = (string)$ctx['user']['name'];
	restapi_authorize($ctx, $route);

	if ($route['write'] && !empty($_SERVER['HTTP_IF_MATCH']) &&
	    (trim($_SERVER['HTTP_IF_MATCH']) !== restapi_config_etag())) {
		throw new RestApiError(412, 'config_changed',
		    'The configuration changed since the ETag in If-Match was read.');
	}

	$req = array(
		'path' => $path,
		'params' => $params,
		'query' => $_GET,
		'body' => in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), true) ?
		    restapi_decode_body(file_get_contents('php://input')) : array(),
		'user' => $ctx['user'],
		'token' => $ctx['token'],
		'session' => !empty($ctx['session']),
		'session_id' => $ctx['session_id'] ?? null,
		'login_time' => $ctx['login_time'] ?? null,
	);

	/* Lets restapi_validation_error() key messages by the route's resource schema. */
	restapi_request_context(array('schema' => $route['schema'] ?? null, 'body' => $req['body']));

	/*
	 * Attribute config changes to the key: write_config() records
	 * $_SESSION['Username'] and authsource in the revision. Holding the
	 * session counter at 1 stops write_config() from opening a real PHP
	 * session (and sending a cookie) for an API request.
	 */
	global $session_opencounter;
	$session_opencounter = 1;
	$_SESSION = array('Username' => $ctx['user']['name'],
	    'authsource' => empty($ctx['session']) ? "API key {$ctx['token']['id']}" : "{$ctx['authsource']} (WebUI)");
	try {
		$result = call_user_func($route['handler'], $req);
	} finally {
		$session_opencounter = 0;
	}

	$headers = array('ETag' => restapi_config_etag());
	$status = (int)($result['status'] ?? 200);
	if (isset($result['raw'])) {
		restapi_respond($status, $result['raw'], $result['type'] ?? 'text/plain', $headers);
	}
	restapi_respond($status, restapi_result_body($result), 'application/json', $headers);
} catch (RestApiError $e) {
	$headers = (($e->status === 401) && !$webui) ? array('WWW-Authenticate' => 'Bearer realm="FreeSense"') : array();
	restapi_respond($e->status, $e->payload(), 'application/json', $headers);
} catch (Throwable $e) {
	logger(LOG_ERR, localize_text('REST API error in %s %s: %s', $method, $path, $e->getMessage()));
	restapi_respond(500, array('error' => array('code' => 'internal_error',
	    'message' => 'Internal error; see the system log.')));
}
