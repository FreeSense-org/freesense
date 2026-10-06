<?php
/*
 * restapi/routes_ntp.inc
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
 * REST API v1 routes for the NTP server (area services.time): the settings
 * and the access restrictions of Services > NTP.
 *
 * Writes go through the functions the pages call (services_ntpd.inc), so
 * validation is the GUI's, and like the pages they reconfigure ntpd at once.
 * Settings are flat objects of the form fields (checkboxes as booleans); a
 * PUT changes only the fields it sends. The authentication key reads as
 * "(set)" and sending "(set)" keeps it. The leap seconds file is the text
 * field "leaptext"; "leapfile_base64" (write only) replaces it with a
 * base64 encoded file, like the page's upload.
 * The Serial GPS and PPS tabs have no API yet.
 */

require_once('restapi/framework.inc');

function restapi_routes_ntp() {
	$area = 'services.time';
	$settings_body = array('type' => 'object', 'description' => 'The NTP settings form: enable, statsgraph, logpeer, logsys, ' .
	    'clockstats, loopstats, peerstats, serverauth (booleans); interface (list from "interface_choices"; empty: all); servers ' .
	    '(list of {server, prefer, noselect, auth (booleans), type: server|pool|peer}, at most 10; empty: pool.ntp.org); ' .
	    'ntpmaxpeers, ntporphan, ntpminpoll, ntpmaxpoll (from "poll_choices"), dnsresolv (auto|inet|inet6), leaptext (the leap ' .
	    'seconds file), leapfile_base64 (write only: a base64 encoded leap seconds file that replaces leaptext), ' .
	    'serverauthkeyid, serverauthkey ("(set)" keeps it), serverauthalgo (md5|sha1|sha256). Omitted fields keep their value. ' .
	    'Saving restarts ntpd at once.');
	$acl_body = array('type' => 'object', 'description' => 'Default access restrictions as the ACLs form shows them: kod, ' .
	    'nomodify, noquery, noserve, nopeer, notrap (booleans); networks: list of {network: "address/bits", kod, nomodify, ' .
	    'noquery, noserve, nopeer, notrap}, at most 50, replaces the list. Omitted fields keep their value. Saving restarts ' .
	    'ntpd at once.');

	return array(
		restapi_route('GET', '/v1/services/ntp', 'restapi_h_ntp_get', array(
			'page' => 'services_ntpd.php', 'area' => $area,
			'summary' => 'NTP server settings (the authentication key reads as "(set)") and whether ntpd runs')),
		restapi_route('PUT', '/v1/services/ntp', 'restapi_h_ntp_set', array(
			'page' => 'services_ntpd.php', 'area' => $area, 'write' => true, 'body' => $settings_body,
			'summary' => 'Change NTP server settings (applied at once; omitted fields keep their value)')),
		restapi_route('GET', '/v1/services/ntp/acls', 'restapi_h_ntp_acls_get', array(
			'page' => 'services_ntpd_acls.php', 'area' => $area,
			'summary' => 'NTP default and custom access restrictions')),
		restapi_route('PUT', '/v1/services/ntp/acls', 'restapi_h_ntp_acls_set', array(
			'page' => 'services_ntpd_acls.php', 'area' => $area, 'write' => true, 'body' => $acl_body,
			'summary' => 'Change NTP access restrictions (applied at once; "networks" replaces the list)')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* Time servers (API list of {server, prefer, noselect, auth, type}) as the settings form's rows. */
function restapi_ntp_server_rows($servers) {
	if (!is_array($servers) || !array_is_list($servers)) {
		throw new RestApiError(400, 'invalid_field', '"servers" must be a list of {server, prefer, noselect, auth, type}.');
	}
	if (count($servers) > 10) {
		throw new RestApiError(400, 'invalid_field', 'At most 10 time servers can be configured.');
	}
	$post = array();
	foreach ($servers as $i => $row) {
		if (is_string($row)) {
			$row = array('server' => $row);
		}
		if (!is_array($row) || array_is_list($row)) {
			throw new RestApiError(400, 'invalid_field', '"servers" must be a list of {server, prefer, noselect, auth, type}.');
		}
		foreach ($row as $key => $value) {
			if (!in_array($key, array('server', 'prefer', 'noselect', 'auth', 'type'), true)) {
				throw new RestApiError(400, 'unknown_field', "Unknown time server field \"{$key}\".");
			}
		}
		if (!is_string($row['server'] ?? null)) {
			throw new RestApiError(400, 'invalid_field', 'Every time server needs a "server" string.');
		}
		$type = $row['type'] ?? 'server';
		if (!in_array($type, array('server', 'pool', 'peer'), true)) {
			throw new RestApiError(400, 'invalid_field', 'A time server "type" is server, pool or peer.');
		}
		$post["server{$i}"] = $row['server'];
		foreach (array('prefer' => 'servprefer', 'noselect' => 'servselect', 'auth' => 'servauth') as $flag => $field) {
			if (isset($row[$flag]) && !is_bool($row[$flag])) {
				throw new RestApiError(400, 'invalid_field', "Time server field \"{$flag}\" must be a boolean.");
			}
			if (!empty($row[$flag])) {
				$post["{$field}{$i}"] = 'yes';
			}
		}
		$post["servistype{$i}"] = $type;
	}
	return $post;
}

/* Custom restrictions (API list of {network, flags...}) as the ACLs form's rows; an empty list posts the form's empty row. */
function restapi_ntp_acl_rows($networks) {
	if (!is_array($networks) || !array_is_list($networks)) {
		throw new RestApiError(400, 'invalid_field', '"networks" must be a list of {network, kod, nomodify, noquery, noserve, nopeer, notrap}.');
	}
	if (count($networks) > 50) {
		throw new RestApiError(400, 'invalid_field', 'At most 50 networks can be configured.');
	}
	if (empty($networks)) {
		return array('acl_network0' => '', 'mask0' => '128');
	}
	$flags = array('kod', 'nomodify', 'noquery', 'noserve', 'nopeer', 'notrap');
	$post = array();
	foreach ($networks as $i => $row) {
		if (!is_array($row) || array_is_list($row) || !is_string($row['network'] ?? null)) {
			throw new RestApiError(400, 'invalid_field', 'Every network needs a "network" string ("address/bits").');
		}
		foreach ($row as $key => $value) {
			if ($key === 'network') {
				continue;
			}
			if (!in_array($key, $flags, true)) {
				throw new RestApiError(400, 'unknown_field', "Unknown network field \"{$key}\".");
			}
			if (!is_bool($value)) {
				throw new RestApiError(400, 'invalid_field', "Network field \"{$key}\" must be a boolean.");
			}
		}
		$parts = explode('/', $row['network'], 2);
		$post["acl_network{$i}"] = $parts[0];
		$post["mask{$i}"] = $parts[1] ?? '';
		foreach ($flags as $flag) {
			if (!empty($row[$flag])) {
				$post["{$flag}{$i}"] = 'yes';
			}
		}
	}
	return $post;
}

/* Stored restriction rows as API networks (the empty placeholder row is left out). */
function restapi_ntp_acl_networks($rows) {
	$out = array();
	foreach ((array)$rows as $row) {
		if (!is_array($row) || ((string)($row['acl_network'] ?? '') === '')) {
			continue;
		}
		$item = array('network' => $row['acl_network'] . (((string)($row['mask'] ?? '') !== '') ? "/{$row['mask']}" : ''));
		foreach (array('kod', 'nomodify', 'noquery', 'noserve', 'nopeer', 'notrap') as $flag) {
			$item[$flag] = !empty($row[$flag]);
		}
		$out[] = $item;
	}
	return $out;
}

/* A select's value: the stored one when the form offers it, else the option the form preselects (the first). */
function restapi_select_choice($value, array $options) {
	$keys = array_map('strval', array_keys($options));
	return in_array((string)$value, $keys, true) ? (string)$value : (string)($keys[0] ?? '');
}

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function restapi_ntp_types() {
	$types = array('servers' => 'objects', 'interface' => 'list', 'leapfile_base64' => 'string',
	    'running' => 'ro', 'interface_choices' => 'ro', 'poll_choices' => 'ro', 'dnsresolv_choices' => 'ro', 'serverauthalgo_choices' => 'ro');
	foreach (array('enable', 'statsgraph', 'logpeer', 'logsys', 'clockstats', 'loopstats', 'peerstats', 'serverauth') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('ntpmaxpeers', 'ntporphan', 'ntpminpoll', 'ntpmaxpoll', 'leaptext', 'dnsresolv', 'serverauthkeyid',
	    'serverauthkey', 'serverauthalgo') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/* The settings as the page's form shows them, with the authentication key in clear (never returned). */
function restapi_ntp_settings() {
	global $ntp_auth_halgos;

	$pconfig = ntpd_settings();
	$poll = system_ntp_poll_values();
	$servers = array();
	foreach (ntpd_timeserver_rows() as $row) {
		if ((string)$row['server'] === '') {
			continue;
		}
		$servers[] = array('server' => (string)$row['server'], 'prefer' => (bool)$row['prefer'], 'noselect' => (bool)$row['noselect'],
		    'auth' => (bool)$row['auth'], 'type' => $row['type']);
	}
	return array(
		'enable' => ($pconfig['enable'] == 'enabled'),
		'interface' => array_values(array_map('strval', array_filter($pconfig['interface'], 'strlen'))),
		'servers' => $servers,
		'ntpmaxpeers' => (string)($pconfig['ntpmaxpeers'] ?? ''),
		'ntporphan' => (string)($pconfig['orphan'] ?? ''),
		'ntpminpoll' => restapi_select_choice($pconfig['ntpminpoll'] ?? '', $poll),
		'ntpmaxpoll' => restapi_select_choice($pconfig['ntpmaxpoll'] ?? '', $poll),
		'statsgraph' => !empty($pconfig['statsgraph']),
		'logpeer' => !empty($pconfig['logpeer']),
		'logsys' => !empty($pconfig['logsys']),
		'clockstats' => !empty($pconfig['clockstats']),
		'loopstats' => !empty($pconfig['loopstats']),
		'peerstats' => !empty($pconfig['peerstats']),
		'leaptext' => (string)base64_decode(chunk_split((string)($pconfig['leapsec'] ?? ''))),
		'dnsresolv' => restapi_select_choice($pconfig['dnsresolv'] ?? '', ntpd_dnsresolv_choices()),
		'serverauth' => !empty($pconfig['serverauth']),
		'serverauthkeyid' => (string)($pconfig['serverauthkeyid'] ?? ''),
		'serverauthkey' => (string)base64_decode((string)($pconfig['serverauthkey'] ?? '')),
		'serverauthalgo' => restapi_select_choice($pconfig['serverauthalgo'] ?? '', $ntp_auth_halgos),
	);
}

function restapi_ntp_out(array $settings) {
	global $ntp_auth_halgos;

	$settings['serverauthkey'] = restapi_svc_mask($settings['serverauthkey']);
	$settings['running'] = isvalidpid(g_get('varrun_path') . '/ntpd.pid');
	$settings['interface_choices'] = restapi_svc_choices(ntpd_build_interface_list(array())['options']);
	$settings['poll_choices'] = restapi_svc_choices(system_ntp_poll_values());
	$settings['dnsresolv_choices'] = ntpd_dnsresolv_choices();
	$settings['serverauthalgo_choices'] = $ntp_auth_halgos;
	return $settings;
}

function restapi_h_ntp_get($req) {
	return array('data' => restapi_ntp_out(restapi_ntp_settings()));
}

function restapi_h_ntp_set($req) {
	$body = $req['body'];
	if (($body['serverauthkey'] ?? null) === '(set)') {
		unset($body['serverauthkey']);
	}
	$types = restapi_ntp_types();
	$values = restapi_svc_merge($body, restapi_ntp_settings(), $types);
	$leapfile = null;
	if (isset($values['leapfile_base64'])) {
		$leapfile = base64_decode($values['leapfile_base64'], true);
		if ($leapfile === false) {
			throw new RestApiError(400, 'invalid_field', '"leapfile_base64" must be base64 encoded.');
		}
	}
	$post = restapi_svc_post($values, $types) + restapi_ntp_server_rows($values['servers']);
	unset($post['leapfile_base64']);
	$rv = ntpd_save_settings($post, $leapfile);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_ntp_out(restapi_ntp_settings()) + array('applied' => ($rv['retval'] == 0)));
}

/* ------------------------------------------------------------------ */
/* Access restrictions                                                 */
/* ------------------------------------------------------------------ */

function restapi_ntp_acl_types() {
	$types = array('networks' => 'objects');
	foreach (ntpd_acl_flags() as $flag) {
		$types[$flag] = 'bool';
	}
	return $types;
}

/* The ACLs form: kod, nomodify, nopeer and notrap are stored inverted ("on" when the box is cleared). */
function restapi_ntp_acls() {
	$pconfig = config_get_path('ntpd', []);
	return array(
		'kod' => empty($pconfig['kod']),
		'nomodify' => empty($pconfig['nomodify']),
		'noquery' => !empty($pconfig['noquery']),
		'noserve' => !empty($pconfig['noserve']),
		'nopeer' => empty($pconfig['nopeer']),
		'notrap' => empty($pconfig['notrap']),
		'networks' => restapi_ntp_acl_networks(ntpd_acl_rows()),
	);
}

function restapi_h_ntp_acls_get($req) {
	return array('data' => restapi_ntp_acls());
}

function restapi_h_ntp_acls_set($req) {
	$types = restapi_ntp_acl_types();
	$values = restapi_svc_merge($req['body'], restapi_ntp_acls(), $types);
	$post = restapi_svc_post($values, $types) + restapi_ntp_acl_rows($values['networks']);
	$rv = ntpd_save_acls($post);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_ntp_acls() + array('applied' => ($rv['retval'] == 0)));
}
