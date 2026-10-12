<?php
/*
 * restapi/routes_operations.inc
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
 * REST API v1 system operations: service start/stop/restart (Status >
 * Services), the state table reset (Diagnostics > States > Reset States),
 * reboot and halt (area operations) and package installation, reinstallation
 * and removal and the system update (area packages; System > Package Manager
 * and System > Update).
 *
 * Every operation needs the write scope of its area and {"confirm": true}.
 * Reboot, halt, the package operations and the system update additionally
 * need an administrator: the key's user is "admin" (uid 0) or holds "WebCfg -
 * All pages" (page-all), directly or through a group such as "admins". GUI
 * access to the page alone is not enough for these.
 *
 * Operations that cut the API's own connection run after the response has
 * been sent (202 Accepted): reboot and halt (like the pages: in the
 * background, after notifying remote notification targets), and the state
 * table reset (it drops every connection, including this request's).
 * Reroot is not available (it runs synchronously and does not return).
 *
 * Package operations start FreeSense-upgrade in the background through the
 * Package Installer's own function (pkg_mgr_install_start()) and answer 202;
 * GET /v1/packages/operation reports the running or last operation. The
 * Package Installer page reloads the filter and restarts the package
 * services on every poll once the process has ended; for an operation the
 * API started this happens exactly once, on the first status read after the
 * end (guarded by the record of the operation and a lock), so a client must
 * poll until "finished" - like the page, which does it only while a browser
 * polls. Status reads have no other side effects.
 */

require_once('restapi/framework.inc');

/* Seconds between the response and the reboot, halt or state table reset. */
define('RESTAPI_OPS_DELAY', 2);
/* Seconds a service action waits for the service to reach the expected state (the page sleeps 5 s). */
define('RESTAPI_OPS_SERVICE_WAIT', 5);
define('RESTAPI_PKGOP_LINES_DEFAULT', 50);
define('RESTAPI_PKGOP_LINES_MAX', 1000);

function restapi_routes_operations() {
	$confirm = array('confirm' => array('type' => 'boolean', 'description' => 'Must be true'));
	$svc_body = array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm + array(
	    'mode' => array('type' => 'string', 'enum' => array('server', 'client'), 'description' => 'OpenVPN: server or client'),
	    'vpnid' => array('type' => 'integer', 'description' => 'OpenVPN: the instance\'s VPN ID'),
	    'zone' => array('type' => 'string', 'description' => 'Captive portal: the zone')));
	$admin = ' Requires {"confirm": true} and an administrator (user admin or "WebCfg - All pages").';
	$routes = array();
	foreach (array('start' => 'Start', 'stop' => 'Stop', 'restart' => 'Restart') as $action => $verb) {
		$routes[] = restapi_route('POST', "/v1/services/{name}/{$action}", "restapi_h_service_{$action}", array(
			'page' => 'status_services.php', 'area' => 'operations', 'write' => true, 'body' => $svc_body,
			'summary' => "{$verb} a service listed by GET /v1/status/services (like Status > Services). Requires {\"confirm\": true}; " .
			    'OpenVPN instances need mode and vpnid, captive portal zones need zone.'));
	}
	return array_merge($routes, array(
		restapi_route('POST', '/v1/diagnostics/states/reset', 'restapi_h_states_reset', array(
			'page' => 'diag_resetstate.php', 'area' => 'operations', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm + array(
			    'state_table' => array('type' => 'boolean', 'description' => 'Flush the state table (default true)'),
			    'source_tracking' => array('type' => 'boolean', 'description' => 'Flush the source tracking table (default false; ' .
			        'only with sticky connections enabled, like the page)'))),
			'summary' => 'Reset the firewall state table and/or source tracking (like Diagnostics > States > Reset States). Requires ' .
			    '{"confirm": true}. Drops every connection, including this request\'s: answers 202 first and flushes ' .
			    RESTAPI_OPS_DELAY . ' s later.')),
		restapi_route('POST', '/v1/system/reboot', 'restapi_h_system_reboot', array(
			'page' => 'diag_reboot.php', 'area' => 'operations', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm + array(
			    'type' => array('type' => 'string', 'enum' => array('normal', 'fsck'),
			        'description' => 'normal (default) or fsck (reboot with a filesystem check; not on ZFS or ARM, like the page)'))),
			'summary' => 'Reboot the system (like Diagnostics > Reboot; reroot is not available). Answers 202, then reboots ' .
			    RESTAPI_OPS_DELAY . ' s later.' . $admin)),
		restapi_route('POST', '/v1/system/halt', 'restapi_h_system_halt', array(
			'page' => 'diag_halt.php', 'area' => 'operations', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm),
			'summary' => 'Halt and power off the system (like Diagnostics > Halt System); it stays off until powered on locally. ' .
			    'Answers 202, then halts ' . RESTAPI_OPS_DELAY . ' s later.' . $admin)),
		restapi_route('GET', '/v1/jobs/{id}', 'restapi_h_job', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages',
			'query' => array('after' => 'Only log lines after this line number (the cursor from the last response)'),
			'summary' => 'A long operation as a job for the WebUI: "packages" is the package operation or system update ' .
			    '(state, percent, current step, numbered log lines). Like GET /v1/packages/operation, the first read after ' .
			    'an operation the API started has ended does its finished work once.')),
		restapi_route('GET', '/v1/packages/operation', 'restapi_h_pkgop_status', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages',
			'query' => array('lines' => 'Number of log lines to return (default ' . RESTAPI_PKGOP_LINES_DEFAULT . ', at most ' .
			    RESTAPI_PKGOP_LINES_MAX . ')'),
			'summary' => 'Status of the running or last package operation or system update (state, return code, progress, log). ' .
			    'The first read after an operation the API started has ended reloads the filter and restarts the package services ' .
			    '(once), like the Package Installer page.')),
		restapi_route('POST', '/v1/packages/{name}/install', 'restapi_h_pkgop_install', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm),
			'summary' => 'Install a package (full name, e.g. FreeSense-pkg-nut) in the background; answers 202, follow it with ' .
			    'GET /v1/packages/operation.' . $admin)),
		restapi_route('POST', '/v1/packages/{name}/reinstall', 'restapi_h_pkgop_reinstall', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm),
			'summary' => 'Reinstall an installed package in the background; answers 202.' . $admin)),
		restapi_route('DELETE', '/v1/packages/{name}', 'restapi_h_pkgop_delete', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm),
			'summary' => 'Remove an installed package in the background (not a vital one); answers 202.' . $admin)),
		restapi_route('POST', '/v1/system/firmware/update', 'restapi_h_firmware_update', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => $confirm),
			'summary' => 'Start the system update to the latest version of the selected branch (like System > Update; only when an ' .
			    'update is available). It reboots the system when it needs to. Answers 202.' . $admin)),
	));
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* Check a body against field => type (bool, string, int); unknown fields are refused. */
function restapi_ops_body(array $body, array $types) {
	foreach ($body as $key => $value) {
		$type = $types[$key] ?? null;
		if ($type === null) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
		if (($type === 'bool') && !is_bool($value)) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a boolean.");
		}
		if (($type === 'string') && !is_string($value)) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a string.");
		}
		if (($type === 'int') && !is_int($value) && !(is_string($value) && ctype_digit($value))) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a whole number.");
		}
	}
	return $body;
}

/* Every operation needs {"confirm": true} (exactly true). */
function restapi_ops_confirm(array $body, $what) {
	if (($body['confirm'] ?? null) !== true) {
		throw new RestApiError(400, 'confirm_required', "{$what} requires {\"confirm\": true}.");
	}
}

/* An administrator: uid 0 or the "WebCfg - All pages" privilege ($has_priv: callable($user, $priv)). */
function restapi_ops_is_admin($user, callable $has_priv) {
	if (!is_array($user)) {
		return false;
	}
	if (isset($user['uid']) && ((string)$user['uid'] === '0')) {
		return true;
	}
	return (bool)$has_priv($user, 'page-all');
}

function restapi_ops_require_admin(array $req, $what) {
	if (!restapi_ops_is_admin($req['user'] ?? null, 'userHasPrivilege')) {
		throw new RestApiError(403, 'admin_required', "{$what} is only available to administrators (the admin user or users " .
		    'with "WebCfg - All pages", e.g. members of the admins group).');
	}
}

/* The page's reboot mode for the API type. */
function restapi_ops_reboot_mode($type, $fsck_available) {
	if (($type === null) || ($type === 'normal')) {
		return 'reboot';
	}
	if ($type === 'fsck') {
		if (!$fsck_available) {
			throw new RestApiError(422, 'not_available', 'A reboot with a filesystem check is not available on this system (ZFS or ARM).');
		}
		return 'fsckreboot';
	}
	if ($type === 'reroot') {
		throw new RestApiError(422, 'not_available', 'Reroot is not available through the API.');
	}
	throw new RestApiError(422, 'invalid_type', 'Field "type" must be "normal" or "fsck".');
}

/*
 * The get_services() entry the request means: by name, OpenVPN instances by
 * mode and vpnid, captive portal zones by zone.
 */
function restapi_ops_find_service(array $services, $name, array $body) {
	$found = array();
	foreach ($services as $svc) {
		if (is_array($svc) && isset($svc['name']) && ((string)$svc['name'] === (string)$name)) {
			$found[] = $svc;
		}
	}
	if (empty($found)) {
		throw new RestApiError(404, 'not_found', 'No such service (see GET /v1/status/services).');
	}
	if ($name === 'openvpn') {
		if (!isset($body['mode']) || !isset($body['vpnid'])) {
			throw new RestApiError(400, 'missing_field', 'OpenVPN services are addressed by "mode" (server or client) and "vpnid".');
		}
		foreach ($found as $svc) {
			if ((($svc['mode'] ?? null) === $body['mode']) && ((string)($svc['vpnid'] ?? '') === (string)$body['vpnid'])) {
				return $svc;
			}
		}
		throw new RestApiError(404, 'not_found', 'No enabled OpenVPN instance with that mode and vpnid.');
	}
	if ($name === 'captiveportal') {
		if (!isset($body['zone'])) {
			throw new RestApiError(400, 'missing_field', 'Captive portal services are addressed by "zone".');
		}
		foreach ($found as $svc) {
			if ((string)($svc['zone'] ?? '') === (string)$body['zone']) {
				return $svc;
			}
		}
		throw new RestApiError(404, 'not_found', 'No enabled captive portal zone with that name.');
	}
	foreach (array('mode', 'vpnid', 'zone') as $field) {
		if (isset($body[$field])) {
			throw new RestApiError(400, 'unknown_field', "Field \"{$field}\" applies only to OpenVPN and captive portal services.");
		}
	}
	return $found[0];
}

/* The fields the Status > Services buttons post (FreeSenseHelpers.js) for a service. */
function restapi_ops_service_extras($mode, array $svc) {
	$extras = array('ajax' => 'ajax', 'mode' => $mode, 'service' => $svc['name']);
	if ($svc['name'] === 'openvpn') {
		$extras += array('vpnmode' => $svc['mode'], 'zone' => $svc['mode'], 'id' => (string)$svc['vpnid']);
	} elseif ($svc['name'] === 'captiveportal') {
		$extras += array('vpnmode' => $svc['zone'], 'zone' => $svc['zone']);
	}
	return $extras;
}

/* Like the page, which offers start only for a stopped (enabled) service and stop/restart for a running one. */
function restapi_ops_service_allowed($action, $running, $startable) {
	if ($action === 'start') {
		if ($running) {
			throw new RestApiError(409, 'already_running', 'The service is already running.');
		}
		if (!$startable) {
			throw new RestApiError(409, 'service_disabled', 'The service is disabled; enable it in its settings first.');
		}
	} elseif (!$running) {
		throw new RestApiError(409, 'not_running', 'The service is not running.');
	}
}

/* A package name the API accepts: the full name with the package prefix, package-name characters only. */
function restapi_pkgop_valid_name($name, $prefix) {
	return is_string($name) && (strlen($name) <= 128) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $name) &&
	    preg_match('/^' . preg_quote($prefix, '/') . '[a-zA-Z0-9._-]+$/D', $name);
}

/* The last $lines lines of a log. */
function restapi_pkgop_tail($text, $lines) {
	$all = preg_split('/\r?\n/', rtrim((string)$text, "\r\n"));
	if ($all === array('')) {
		return array();
	}
	return array_values(array_slice($all, -$lines));
}

function restapi_pkgop_state($running, $rc) {
	if ($running) {
		return 'running';
	}
	if ($rc === null) {
		return 'stopped';
	}
	return ($rc === 0) ? 'succeeded' : 'failed';
}

function restapi_pkgop_lines(array $query) {
	if (!isset($query['lines']) || ($query['lines'] === '')) {
		return RESTAPI_PKGOP_LINES_DEFAULT;
	}
	$lines = $query['lines'];
	if (!is_string($lines) || !ctype_digit($lines) || ((int)$lines < 1) || ((int)$lines > RESTAPI_PKGOP_LINES_MAX)) {
		throw new RestApiError(400, 'invalid_query', 'lines must be 1-' . RESTAPI_PKGOP_LINES_MAX . '.');
	}
	return (int)$lines;
}

/* Run $fn once the response has been sent: the operation would otherwise cut the connection that carries it. */
function restapi_ops_after_response($delay, callable $fn) {
	register_shutdown_function(function () use ($delay, $fn) {
		ignore_user_abort(true);
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
		sleep($delay);
		/* The page functions print (the pages show it in a <pre>); nobody reads it any more. */
		ob_start();
		try {
			$fn();
		} finally {
			ob_end_clean();
		}
	});
}

/* ------------------------------------------------------------------ */
/* Services                                                            */
/* ------------------------------------------------------------------ */

function restapi_service_control($req, $action) {
	$body = restapi_ops_body($req['body'], array('confirm' => 'bool', 'mode' => 'string', 'vpnid' => 'int', 'zone' => 'string'));
	restapi_ops_confirm($body, ucfirst($action) . 'ing a service');
	$svc = restapi_ops_find_service(get_services(), $req['params']['name'], $body);
	$running = (bool)get_service_status($svc);
	$startable = in_array($svc['name'], array('openvpn', 'captiveportal'), true) || is_service_enabled($svc['name']);
	restapi_ops_service_allowed($action, $running, $startable);

	$mode = "{$action}service";
	$message = status_services_control($mode, htmlspecialchars($svc['name']), restapi_ops_service_extras($mode, $svc));

	/* The page waits 5 s before it shows the list again; wait until the service is in the expected state, at most as long. */
	$expected = ($action !== 'stop');
	for ($i = 0; $i < RESTAPI_OPS_SERVICE_WAIT * 2; $i++) {
		$running = (bool)get_service_status($svc);
		if ($running === $expected) {
			break;
		}
		usleep(500000);
	}
	$out = array('name' => $svc['name'], 'description' => html_entity_decode((string)($svc['description'] ?? ''), ENT_QUOTES));
	foreach (array('mode', 'vpnid', 'zone') as $field) {
		if (isset($svc[$field])) {
			$out[$field] = $svc[$field];
		}
	}
	return array('data' => $out + array(
		'action' => $action,
		'message' => html_entity_decode((string)$message, ENT_QUOTES),
		'running' => $running,
		'expected_state_reached' => ($running === $expected),
	));
}

function restapi_h_service_start($req) {
	return restapi_service_control($req, 'start');
}

function restapi_h_service_stop($req) {
	return restapi_service_control($req, 'stop');
}

function restapi_h_service_restart($req) {
	return restapi_service_control($req, 'restart');
}

/* ------------------------------------------------------------------ */
/* State table reset, reboot, halt                                     */
/* ------------------------------------------------------------------ */

function restapi_h_states_reset($req) {
	$body = restapi_ops_body($req['body'], array('confirm' => 'bool', 'state_table' => 'bool', 'source_tracking' => 'bool'));
	restapi_ops_confirm($body, 'Resetting the state table');
	$statetable = $body['state_table'] ?? true;
	$sourcetracking = $body['source_tracking'] ?? false;
	if (!$statetable && !$sourcetracking) {
		throw new RestApiError(422, 'nothing_selected', 'Please select at least one reset option (state_table or source_tracking).');
	}
	if ($sourcetracking && !diag_resetstate_sourcetracking_available()) {
		throw new RestApiError(422, 'not_available', 'Source tracking can only be reset with sticky connections enabled ' .
		    '(System > Advanced > Miscellaneous), like the page.');
	}
	restapi_ops_after_response(RESTAPI_OPS_DELAY, function () use ($statetable, $sourcetracking) {
		diag_resetstate_run($statetable, $sourcetracking);
	});
	return array('status' => 202, 'data' => array(
		'state_table' => $statetable,
		'source_tracking' => $sourcetracking,
		'after_seconds' => RESTAPI_OPS_DELAY,
		'message' => 'The ' . implode(' and ', array_keys(array_filter(array('state table' => $statetable,
		    'source tracking table' => $sourcetracking)))) . ' will be flushed in ' . RESTAPI_OPS_DELAY .
		    ' seconds. Open connections, including this one, are dropped and must be re-established.',
	));
}

function restapi_h_system_reboot($req) {
	restapi_ops_require_admin($req, 'Rebooting');
	$body = restapi_ops_body($req['body'], array('confirm' => 'bool', 'type' => 'string'));
	restapi_ops_confirm($body, 'Rebooting');
	$mode = restapi_ops_reboot_mode($body['type'] ?? null, diag_reboot_fsck_available());
	$type = ($mode === 'fsckreboot') ? 'fsck' : 'normal';
	if (g_get('debug')) {
		return array('data' => array('action' => 'reboot', 'type' => $type, 'rebooting' => false,
		    'message' => 'Not actually rebooting (DEBUG is set true).'));
	}
	restapi_ops_after_response(RESTAPI_OPS_DELAY, function () use ($mode) {
		diag_reboot_run($mode);
	});
	return array('status' => 202, 'data' => array('action' => 'reboot', 'type' => $type, 'rebooting' => true,
	    'after_seconds' => RESTAPI_OPS_DELAY, 'message' => 'The system is rebooting now.'));
}

function restapi_h_system_halt($req) {
	restapi_ops_require_admin($req, 'Halting the system');
	$body = restapi_ops_body($req['body'], array('confirm' => 'bool'));
	restapi_ops_confirm($body, 'Halting the system');
	if (g_get('debug')) {
		return array('data' => array('action' => 'halt', 'halting' => false, 'message' => 'Not actually halting (DEBUG is set true).'));
	}
	restapi_ops_after_response(RESTAPI_OPS_DELAY, function () {
		diag_halt_run();
	});
	return array('status' => 202, 'data' => array('action' => 'halt', 'halting' => true, 'after_seconds' => RESTAPI_OPS_DELAY,
	    'message' => 'The system is halting now. It stays powered off until it is switched on locally.'));
}

/* ------------------------------------------------------------------ */
/* Package operations and the system update                            */
/* ------------------------------------------------------------------ */

/* The API's record of the operation it started last. */
function restapi_pkgop_record_file() {
	return g_get('varrun_path') . '/' . g_get('product_name') . '-upgrade-API.json';
}

function restapi_pkgop_record() {
	$rec = json_decode((string)@file_get_contents(restapi_pkgop_record_file()), true);
	return is_array($rec) ? $rec : null;
}

function restapi_pkgop_record_write(array $rec) {
	@file_put_contents(restapi_pkgop_record_file(), json_encode($rec, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/*
 * The running or last operation: the API's record when the page's pid file
 * still names the process the API started, otherwise the page's mode file
 * (an operation started in the GUI).
 */
function restapi_pkgop_current(array $paths) {
	$pid = trim((string)@file_get_contents($paths['gui_pidfile']));
	$rec = restapi_pkgop_record();
	if (($rec !== null) && ($pid !== '') && ((string)($rec['pid'] ?? '') === $pid)) {
		return array('source' => 'api', 'mode' => $rec['mode'], 'package' => $rec['package'] ?? null, 'record' => $rec);
	}
	if (file_exists($paths['gui_mode'])) {
		list($mode, $package) = pkg_mgr_install_read_mode($paths['gui_mode']);
		return array('source' => 'gui', 'mode' => $mode ?? 'firmwareupdate', 'package' => $package, 'record' => null);
	}
	return null;
}

/*
 * The page's "finished" work (filter reload, package service restart) for an
 * operation the API started: exactly once, by the first status read after
 * the process has ended.
 */
function restapi_pkgop_finish_once($postlog) {
	$lock = lock('restapi-pkgop', LOCK_EX);
	try {
		$rec = restapi_pkgop_record();
		if (($rec === null) || empty($rec['finish_pending'])) {
			return false;
		}
		$rec['finish_pending'] = false;
		$rec['finished_at'] = time();
		restapi_pkgop_record_write($rec);
	} finally {
		unlock($lock);
	}
	pkg_mgr_install_finish($postlog);
	return true;
}

function restapi_pkgop_status($lines, $finish) {
	$paths = pkg_mgr_install_paths();
	$cur = restapi_pkgop_current($paths);
	if ($cur === null) {
		return array('state' => 'none', 'running' => isvalidpid($paths['gui_pidfile']), 'mode' => null, 'package' => null,
		    'started_by' => null, 'finished' => false, 'rc' => null, 'reboot_needed' => false, 'progress' => null, 'log' => array(),
		    'notice' => null, 'completed' => false);
	}
	$running = pkg_mgr_install_running($paths['gui_pidfile']);
	$firmware = ($cur['mode'] === 'firmwareupdate');
	$postlog = pkg_mgr_install_logfile($firmware, (string)$cur['package']);
	$completed_now = false;
	if (!$running && $finish && ($cur['source'] === 'api')) {
		$completed_now = restapi_pkgop_finish_once($postlog);
	}
	$log = pkg_mgr_install_read_log($postlog, false);
	$status = $log['status'] ?? array();
	$rc = (isset($status['exitstatus']) && is_numeric(trim($status['exitstatus']))) ? (int)trim($status['exitstatus']) : null;
	/* The page shows no result before the process has ended. */
	if ($running) {
		$rc = null;
	}
	$progress = pkg_mgr_install_read_progress($postlog);
	$notice = pkg_mgr_install_ui_notice();
	$rec = restapi_pkgop_record();
	$out = array(
		'state' => restapi_pkgop_state($running, $rc),
		'running' => $running,
		'mode' => $cur['mode'],
		'package' => $cur['package'],
		'started_by' => $cur['source'],
		'finished' => !$running && ($rc !== null),
		'rc' => $rc,
		'reboot_needed' => !$running && (($status['reboot_needed'] ?? '') === 'yes'),
		'progress' => isset($progress['data']['current'], $progress['data']['total']) ?
		    array('current' => (int)$progress['data']['current'], 'total' => (int)$progress['data']['total']) : null,
		'log' => ($log === null) ? array() : restapi_pkgop_tail($log['log'], $lines),
		'notice' => ($notice === '') ? null : $notice,
	);
	if ($cur['source'] === 'api') {
		$out['started_at'] = gmdate('c', (int)($cur['record']['started_at'] ?? 0));
		$out['started_user'] = (string)($cur['record']['user'] ?? '');
		/* Whether the filter reload and package service restart after the end have run. */
		$out['completed'] = empty($rec['finish_pending']);
		$out['completed_now'] = $completed_now;
	}
	return $out;
}

/*
 * The package operation status (restapi_pkgop_status()) as a WebUI job:
 * array(id, title, state, running, percent, step, log => [{n, text}], ...).
 * Log lines are numbered from 1 within the returned tail; $after skips the
 * lines a client already has.
 */
function restapi_job_view(array $st, $after = 0) {
	$titles = array('firmwareupdate' => 'System update', 'installpkg' => 'Install %s', 'reinstallpkg' => 'Reinstall %s',
	    'delete' => 'Remove %s');
	$title = ($st['mode'] === null) ? 'No operation' : sprintf($titles[$st['mode']] ?? 'Package operation %s', (string)$st['package']);
	$lines = array_values((array)($st['log'] ?? array()));
	$log = array();
	foreach ($lines as $i => $text) {
		if ($i + 1 > $after) {
			$log[] = array('n' => $i + 1, 'text' => (string)$text);
		}
	}
	$step = '';
	for ($i = count($lines) - 1; $i >= 0; $i--) {
		if (trim((string)$lines[$i]) !== '') {
			$step = trim((string)$lines[$i]);
			break;
		}
	}
	$p = $st['progress'] ?? null;
	return array(
		'id' => 'packages',
		'title' => $title,
		'state' => $st['state'],
		'running' => (bool)$st['running'],
		'percent' => (is_array($p) && ($p['total'] > 0)) ? (int)min(100, round(100 * $p['current'] / $p['total'])) : null,
		'step' => $step,
		'reboot_needed' => (bool)$st['reboot_needed'],
		'notice' => $st['notice'],
		'started_at' => $st['started_at'] ?? null,
		'cursor' => count($lines),
		'log' => $log,
	);
}

function restapi_h_job($req) {
	if ($req['params']['id'] !== 'packages') {
		throw new RestApiError(404, 'not_found', 'No such job.');
	}
	$after = $req['query']['after'] ?? '0';
	if (!is_string($after) || !ctype_digit($after)) {
		throw new RestApiError(400, 'invalid_query', 'after must be a line number.');
	}
	return array('data' => restapi_job_view(restapi_pkgop_status(RESTAPI_PKGOP_LINES_MAX, true), (int)$after));
}

function restapi_h_pkgop_status($req) {
	return array('data' => restapi_pkgop_status(restapi_pkgop_lines($req['query']), true));
}

/* Refuse a start while packages are locked or another operation the page follows is running. */
function restapi_pkgop_not_running() {
	restapi_pkg_not_busy();
	if (isvalidpid(pkg_mgr_install_paths()['gui_pidfile'])) {
		throw new RestApiError(409, 'operation_running', 'A package operation or system update is running; see GET /v1/packages/operation.');
	}
}

/* An ended API operation whose status nobody has read yet: do its finished work now (it would be lost with its record). */
function restapi_pkgop_complete_previous() {
	$paths = pkg_mgr_install_paths();
	$cur = restapi_pkgop_current($paths);
	if (($cur !== null) && ($cur['source'] === 'api') && !empty($cur['record']['finish_pending']) && !isvalidpid($paths['gui_pidfile'])) {
		restapi_pkgop_finish_once(pkg_mgr_install_logfile($cur['mode'] === 'firmwareupdate', (string)$cur['package']));
	}
}

function restapi_pkgop_name($req) {
	$name = $req['params']['name'];
	if (!restapi_pkgop_valid_name($name, g_get('pkg_prefix')) || !pkg_mgr_install_name_ok($name) || !pkg_valid_name($name)) {
		throw new RestApiError(400, 'invalid_package', 'A package is addressed by its full name, e.g. ' . g_get('pkg_prefix') .
		    'nut (see GET /v1/packages/available and /v1/packages/installed).');
	}
	return $name;
}

/* Start an operation through the Package Installer's function and record it. */
function restapi_pkgop_start($pkgmode, $pkgname, $firmware) {
	/* Every check has passed; only now complete the previous operation (a refused request changes nothing). */
	restapi_pkgop_complete_previous();
	$r = pkg_mgr_install_start($pkgmode, $pkgname, $firmware);
	if (!$r['started']) {
		switch ($r['reason']) {
			case 'vital':
				throw new RestApiError(409, 'vital_package', $r['failmsg']);
			case 'another_instance':
				throw new RestApiError(409, 'upgrade_busy', $r['failmsg']);
			default:
				throw new RestApiError(502, 'start_failed', 'The operation could not be started.',
				    array('messages' => array_values(array_filter(explode("\n", $r['failmsg']), 'strlen'))));
		}
	}
	$modes = array('installed' => 'installpkg', 'reinstallpkg' => 'reinstallpkg', 'delete' => 'delete');
	restapi_pkgop_record_write(array(
		'pid' => $r['pid'],
		'mode' => $firmware ? 'firmwareupdate' : $modes[$pkgmode],
		'package' => $firmware ? null : $pkgname,
		'started_at' => time(),
		'user' => $_SESSION['Username'] ?? '',
		'finish_pending' => true,
	));
	/* id: the job to follow with GET /v1/jobs/{id}. */
	return array('status' => 202, 'data' => array('started' => true, 'id' => 'packages', 'status_url' => '/api/v1/packages/operation') +
	    array('operation' => restapi_pkgop_status(20, false)));
}

function restapi_pkgop_handle($req, $pkgmode) {
	$what = array('installed' => 'Installing packages', 'reinstallpkg' => 'Reinstalling packages', 'delete' => 'Removing packages')[$pkgmode];
	restapi_ops_require_admin($req, $what);
	$body = restapi_ops_body($req['body'], array('confirm' => 'bool'));
	restapi_ops_confirm($body, $what);
	$name = restapi_pkgop_name($req);
	restapi_pkgop_not_running();
	$installed = is_pkg_installed($name);
	if (($pkgmode === 'installed') && $installed) {
		throw new RestApiError(409, 'already_installed', 'The package is already installed; use POST /v1/packages/{name}/reinstall.');
	}
	if (($pkgmode !== 'installed') && !$installed) {
		throw new RestApiError(404, 'not_installed', 'The package is not installed.');
	}
	if ($pkgmode === 'installed') {
		/* The page shows the repository's version with the same query; nothing is started for a name the repository lacks. */
		$out = $err = '';
		if ((pkg_exec('rquery %n ' . escapeshellarg($name), $out, $err) !== 0) ||
		    !in_array($name, array_map('trim', explode("\n", (string)$out)), true)) {
			throw new RestApiError(404, 'not_in_repository', 'No package with that name in the package repository ' .
			    '(see GET /v1/packages/available).');
		}
	}
	if (($pkgmode === 'delete') && is_vital_system_default_package($name)) {
		throw new RestApiError(409, 'vital_package', gettext("This package is vital to system operation and cannot be removed."));
	}
	return restapi_pkgop_start($pkgmode, $name, false);
}

function restapi_h_pkgop_install($req) {
	return restapi_pkgop_handle($req, 'installed');
}

function restapi_h_pkgop_reinstall($req) {
	return restapi_pkgop_handle($req, 'reinstallpkg');
}

function restapi_h_pkgop_delete($req) {
	return restapi_pkgop_handle($req, 'delete');
}

function restapi_h_firmware_update($req) {
	restapi_ops_require_admin($req, 'The system update');
	$body = restapi_ops_body($req['body'], array('confirm' => 'bool'));
	restapi_ops_confirm($body, 'The system update');
	restapi_pkgop_not_running();
	/* Like System > Update, which offers the update only when the check finds a newer version. */
	ob_start();
	try {
		$v = get_system_pkg_version(false);
	} finally {
		ob_end_clean();
	}
	if (!empty($v['pkg_busy'])) {
		throw new RestApiError(409, 'update_busy', 'The update system is busy; try again later.');
	}
	if (isset($v['pkg_version_error']) || !isset($v['installed_version'])) {
		throw new RestApiError(502, 'version_check_failed', 'Unable to check for updates.',
		    array('messages' => array((string)($v['pkg_version_error'] ?? 'unknown error'))));
	}
	if (($v['pkg_version_compare'] ?? null) !== '<') {
		throw new RestApiError(409, 'no_update', 'No update is available (installed ' . $v['installed_version'] . ', latest ' .
		    ($v['version'] ?? '?') . ').');
	}
	return restapi_pkgop_start('', '', true);
}
