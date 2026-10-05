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
/* The Services pages' shared functions (DNS Forwarder, UPnP, Wake-on-LAN, IGMP Proxy, DHCP Relay, SNMP). */
require_once('services_dnsmasq.inc');
require_once('services_upnp.inc');
require_once('services_wol.inc');
require_once('services_igmpproxy.inc');
require_once('services_dhcp_relay.inc');
require_once('services_snmp.inc');
require_once('restapi.inc');
require_once('restapi/routes_v1.inc');

function restapi_respond($status, $body, $type = 'application/json', array $headers = array()) {
	http_response_code($status);
	header("Content-Type: {$type}; charset=utf-8");
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	foreach ($headers as $name => $value) {
		header("{$name}: {$value}");
	}
	echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
	exit;
}

$remote_ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = preg_replace('#^/api(?=/)#', '', rtrim($path, '/'));

try {
	if (!restapi_enabled()) {
		throw new RestApiError(404, 'api_disabled', 'The REST API is disabled (System > REST API).');
	}
	$settings = restapi_settings();
	$local = in_array($remote_ip, array('127.0.0.1', '::1'), true);
	if (!$settings['allowhttp'] && !$local && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
		throw new RestApiError(403, 'https_required', 'The REST API only accepts HTTPS requests.');
	}
	if (!restapi_ip_allowed($remote_ip, restapi_parse_networks($settings['allowednetworks']))) {
		throw new RestApiError(403, 'source_not_allowed', 'Requests from this address are not allowed.');
	}

	list($route, $params) = restapi_match(restapi_routes_v1(), $method, $path);
	$ctx = restapi_authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? '', $remote_ip);
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
	);

	/*
	 * Attribute config changes to the key: write_config() records
	 * $_SESSION['Username'] and authsource in the revision. Holding the
	 * session counter at 1 stops write_config() from opening a real PHP
	 * session (and sending a cookie) for an API request.
	 */
	global $session_opencounter;
	$session_opencounter = 1;
	$_SESSION = array('Username' => $ctx['user']['name'], 'authsource' => "API key {$ctx['token']['id']}");
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
	restapi_respond($status, array('data' => $result['data'] ?? null), 'application/json', $headers);
} catch (RestApiError $e) {
	$headers = ($e->status === 401) ? array('WWW-Authenticate' => 'Bearer realm="FreeSense"') : array();
	restapi_respond($e->status, $e->payload(), 'application/json', $headers);
} catch (Throwable $e) {
	logger(LOG_ERR, localize_text('REST API error in %s %s: %s', $method, $path, $e->getMessage()));
	restapi_respond(500, array('error' => array('code' => 'internal_error',
	    'message' => 'Internal error; see the system log.')));
}
