<?php
/*
 * restapi/routes_logs.inc
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
 * REST API v1 routes for Status > System Logs (area logs, read only).
 *
 * Only the logs the GUI log pages show can be read, by name; a name maps to a
 * fixed file in /var/log (never a path from the request). Each family needs
 * the privilege of its GUI page: the system logs (status_logs.php), the
 * firewall log (status_logs_filter.php) and the PPPoE/L2TP server logs
 * (status_logs_vpn.php). Entries are read with the GUI's own functions:
 * "parsed" entries come from conv_log_filter() (the formatted view), "raw"
 * lines from dump_log() (the raw view). At most 2000 entries are returned
 * (default 50); a filter is a regular expression checked like the GUI's
 * filter (unsafe patterns are refused).
 *
 * Parsed entries carry a stable "id" (a hash of the entry). The WebUI's
 * live log viewer pages with cursors:
 *   ?after=<id>   entries newer than <id>, oldest first (live tail)
 *   ?before=<id>  entries older than <id>, newest first (scrolling back)
 * "last_id" is the newest entry in the log (the next ?after=), "reset" is
 * true when the cursor entry was rotated away (the newest entries follow),
 * "has_more" says older entries may exist (?before=).
 */

require_once('restapi/framework.inc');

define('RESTAPI_LOG_LINES_DEFAULT', 50);
define('RESTAPI_LOG_LINES_MAX', 2000);
/* How far back a cursor is searched (log lines). */
define('RESTAPI_LOG_SCAN_MAX', 20000);

function restapi_routes_logs() {
	$q = array(
		'lines' => 'Number of entries, 1-' . RESTAPI_LOG_LINES_MAX . ' (default ' . RESTAPI_LOG_LINES_DEFAULT . ')',
		'filter' => 'Only entries matching this regular expression (case-insensitive)',
		'format' => 'parsed (default; fields of the formatted view) or raw (the log lines)',
		'after' => 'Cursor: only entries newer than this entry id, oldest first (parsed only)',
		'before' => 'Cursor: only entries older than this entry id, newest first (parsed only)',
	);
	$fwq = $q + array(
		'interface' => 'Only this interface (e.g. wan or its description)',
		'action' => 'Only these actions (e.g. block, or "pass block"); not combined with filter',
		'srcip' => 'Only this source address (not combined with filter)',
		'dstip' => 'Only this destination address (not combined with filter)',
		'srcport' => 'Only this source port (not combined with filter)',
		'dstport' => 'Only this destination port (not combined with filter)',
		'proto' => 'Only this protocol, e.g. TCP (not combined with filter)',
	);
	return array(
		restapi_route('GET', '/v1/logs', 'restapi_h_logs_list', array(
			'page' => 'status_logs.php', 'area' => 'logs',
			'summary' => 'The logs that can be read, with their endpoint, GUI page and size')),
		/* Before /v1/logs/{name}: "firewall" is not a system log name. */
		restapi_route('GET', '/v1/logs/firewall', 'restapi_h_logs_firewall', array(
			'page' => 'status_logs_filter.php', 'area' => 'logs', 'query' => $fwq,
			'summary' => 'Firewall log entries, parsed (time, interface, action, protocol, addresses, ports, rule numbers)')),
		restapi_route('GET', '/v1/logs/vpn/{name}', 'restapi_h_logs_vpn', array(
			'page' => 'status_logs_vpn.php', 'area' => 'logs', 'query' => $q,
			'summary' => 'PPPoE/L2TP server logs: logins, pppoe, l2tp')),
		restapi_route('GET', '/v1/logs/{name}', 'restapi_h_logs_get', array(
			'page' => 'status_logs.php', 'area' => 'logs', 'query' => $q,
			'summary' => 'A system log (system, dhcpd, auth, ipsec, openvpn, resolver, gateways, ...; see GET /v1/logs)')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/*
 * The logs the API reads: name => file in /var/log, GUI label, family (the
 * GUI page) and the formats the GUI offers. The system family is the list of
 * status_logs.php; dmesg.boot and userlog only have a raw view, utx only a
 * parsed one (it is read with last(1)).
 */
function restapi_log_catalog() {
	$both = array('parsed', 'raw');
	$system = array(
		'system' => array('system.log', 'General'),
		'dhcpd' => array('dhcpd.log', 'DHCP'),
		'auth' => array('auth.log', 'Authentication'),
		'portalauth' => array('portalauth.log', 'Captive Portal Auth'),
		'ipsec' => array('ipsec.log', 'IPsec'),
		'ppp' => array('ppp.log', 'PPP'),
		'openvpn' => array('openvpn.log', 'OpenVPN'),
		'ntpd' => array('ntpd.log', 'NTP'),
		'gateways' => array('gateways.log', 'Gateways'),
		'routing' => array('routing.log', 'Routing'),
		'resolver' => array('resolver.log', 'DNS Resolver'),
		'wireless' => array('wireless.log', 'Wireless'),
		'nginx' => array('nginx.log', 'GUI Service'),
		'dmesg.boot' => array('dmesg.boot', 'OS Boot', array('raw')),
		'utx' => array('utx.log', 'OS User Events', array('parsed')),
		'userlog' => array('userlog', 'OS Account Changes', array('raw')),
	);
	$out = array();
	foreach ($system as $name => $l) {
		$out[$name] = array('family' => 'system', 'file' => $l[0], 'label' => $l[1], 'formats' => $l[2] ?? $both,
		    'page' => 'status_logs.php', 'endpoint' => "/v1/logs/{$name}");
	}
	$out['firewall'] = array('family' => 'firewall', 'file' => 'filter.log', 'label' => 'Firewall', 'formats' => $both,
	    'page' => 'status_logs_filter.php', 'endpoint' => '/v1/logs/firewall');
	foreach (array('logins' => array('vpn.log', 'PPPoE/L2TP Logins'), 'pppoe' => array('poes.log', 'PPPoE Service'),
	    'l2tp' => array('l2tps.log', 'L2TP Service')) as $name => $l) {
		$out["vpn/{$name}"] = array('family' => 'vpn', 'file' => $l[0], 'label' => $l[1], 'formats' => $both,
		    'page' => 'status_logs_vpn.php', 'endpoint' => "/v1/logs/vpn/{$name}");
	}
	return $out;
}

/* The catalogue entry of a log of a family, or a 404 (no other file can be named). */
function restapi_log_entry($family, $name) {
	$key = ($family === 'vpn') ? "vpn/{$name}" : (string)$name;
	$catalog = restapi_log_catalog();
	if (!isset($catalog[$key]) || ($catalog[$key]['family'] !== $family)) {
		throw new RestApiError(404, 'not_found', 'No log with that name; see GET /api/v1/logs.');
	}
	return $catalog[$key];
}

/* Full path of a catalogue file. */
function restapi_log_path(array $entry, $logdir = '/var/log') {
	return rtrim($logdir, '/') . '/' . basename($entry['file']);
}

/* The "lines" parameter: default 50, 1 to 2000. */
function restapi_log_lines(array $query) {
	if (!isset($query['lines']) || ($query['lines'] === '')) {
		return RESTAPI_LOG_LINES_DEFAULT;
	}
	$lines = $query['lines'];
	if (!is_string($lines) || !ctype_digit($lines) || ((int)$lines < 1) || ((int)$lines > RESTAPI_LOG_LINES_MAX)) {
		throw new RestApiError(400, 'invalid_parameter', '"lines" must be a number from 1 to ' . RESTAPI_LOG_LINES_MAX . '.');
	}
	return (int)$lines;
}

/* The "format" parameter: one the log offers (parsed when it has it). */
function restapi_log_format(array $query, array $entry) {
	$format = $query['format'] ?? '';
	if (!is_string($format) || ($format === '')) {
		return $entry['formats'][0];
	}
	if (!in_array($format, $entry['formats'], true)) {
		throw new RestApiError(400, 'invalid_parameter', 'This log can be read as: ' . implode(', ', $entry['formats']) . '.');
	}
	return $format;
}

/* A text query parameter: a string of at most 200 printable characters ("" when absent). */
function restapi_log_text(array $query, $name) {
	$value = $query[$name] ?? '';
	if (!is_string($value) || (strlen($value) > 200) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
		throw new RestApiError(400, 'invalid_parameter', "\"{$name}\" must be text of at most 200 characters.");
	}
	return $value;
}

/* Stable id of a parsed entry. */
function restapi_log_entry_id(array $row) {
	return substr(hash('sha256', json_encode($row)), 0, 16);
}

/* The cursor parameters: array('after' => id|null, 'before' => id|null), or a 400. */
function restapi_log_cursor(array $query) {
	$out = array('after' => null, 'before' => null);
	foreach (array('after', 'before') as $k) {
		$v = $query[$k] ?? '';
		if (($v === '') || ($v === null)) {
			continue;
		}
		if (!is_string($v) || !preg_match('/^[0-9a-f]{16}$/', $v)) {
			throw new RestApiError(400, 'invalid_parameter', "\"{$k}\" must be an entry id from a previous response.");
		}
		$out[$k] = $v;
	}
	if (($out['after'] !== null) && ($out['before'] !== null)) {
		throw new RestApiError(400, 'invalid_parameter', 'Use either "after" or "before", not both.');
	}
	return $out;
}

/*
 * Page a list of parsed entries (oldest first, each with an id) by cursor.
 * $complete says the list reaches the start of the log (nothing older).
 * Returns array(entries, reset, has_more, found).
 */
function restapi_log_page(array $rows, array $cursor, $lines, $complete) {
	$ids = array_column($rows, 'id');
	if ($cursor['after'] !== null) {
		$at = array_search($cursor['after'], array_reverse($ids, true), true);
		if ($at === false) {
			return array(array_slice($rows, -$lines), true, !$complete || (count($rows) > $lines), false);
		}
		return array(array_slice($rows, $at + 1, $lines), false, true, true);
	}
	if ($cursor['before'] !== null) {
		$at = array_search($cursor['before'], $ids, true);
		if ($at === false) {
			return array(array(), true, false, false);
		}
		$start = max(0, $at - $lines);
		return array(array_reverse(array_slice($rows, $start, $at - $start)), false, ($start > 0) || !$complete, true);
	}
	return array(array_reverse(array_slice($rows, -$lines)), false, !$complete || (count($rows) > $lines), true);
}

/* ------------------------------------------------------------------ */
/* Reading                                                             */
/* ------------------------------------------------------------------ */

/* A filter regular expression made safe like the GUI's raw filter, or a 400. */
function restapi_log_filter(array $query, $name = 'filter') {
	$filter = restapi_log_text($query, $name);
	if ($filter === '') {
		return '';
	}
	$clean = cleanup_regex_pattern($filter);
	if (($clean === '') || (@preg_match('/' . $clean . '/i', '') === false)) {
		throw new RestApiError(400, 'invalid_parameter', "\"{$name}\" is not a usable regular expression.");
	}
	return $clean;
}

/* True when the GUI shows this log newest first (General Logging Options, overridden per log). */
function restapi_log_newest_first($path) {
	$reverse = config_path_enabled('syslog', 'reverse');
	$cronorder = config_get_path('syslog/' . basename($path, '.log') . '_settings/cronorder');
	if ($cronorder == 'forward') {
		$reverse = false;
	}
	if ($cronorder == 'reverse') {
		$reverse = true;
	}
	return (bool)$reverse;
}

/*
 * Read a log like its GUI page: parsed entries (conv_log_filter, the
 * formatted view) or raw lines (dump_log, the raw view). $match is a filter
 * expression or, for the firewall log, an array of field filters.
 */
function restapi_log_read($name, array $entry, $format, $lines, $match, $interface = '', array $cursor = array()) {
	$path = restapi_log_path($entry, g_get('varlog_path'));
	$out = array('name' => $name, 'label' => $entry['label'], 'format' => $format,
	    'newest_first' => restapi_log_newest_first($path));
	$cursor += array('after' => null, 'before' => null);
	$paging = ($cursor['after'] !== null) || ($cursor['before'] !== null);
	if ($paging && ($format === 'raw')) {
		throw new RestApiError(400, 'invalid_parameter', 'Cursors ("after", "before") work on parsed entries only.');
	}
	if (($entry['file'] !== 'utx.log') && !is_file($path)) {
		return $out + array('exists' => false, 'count' => 0, 'entries' => array(), 'last_id' => null);
	}
	if ($paging) {
		/* Search a small window first (live tail usually finds its cursor there), then widen. */
		foreach (array(($lines * 2) + 200, 2000, RESTAPI_LOG_SCAN_MAX) as $scan) {
			$rows = array_values(array_filter((array)conv_log_filter($path, $scan, $scan, $match, $interface), 'is_array'));
			if ($out['newest_first']) {
				$rows = array_reverse($rows);
			}
			foreach ($rows as $i => $row) {
				$rows[$i] = array('id' => restapi_log_entry_id($row)) + $row;
			}
			$complete = count($rows) < $scan;
			list($page, $reset, $more, $found) = restapi_log_page($rows, $cursor, $lines, $complete);
			if ($found || $complete || ($scan === RESTAPI_LOG_SCAN_MAX)) {
				break;
			}
		}
		$last = empty($rows) ? null : $rows[count($rows) - 1]['id'];
		return $out + array('exists' => true, 'order' => ($cursor['after'] !== null) ? 'oldest_first' : 'newest_first',
		    'count' => count($page), 'entries' => $page, 'last_id' => $last, 'reset' => $reset, 'has_more' => $more);
	}
	if ($format === 'raw') {
		/* The system and resolver logs hide PPP lines in the GUI's raw view. */
		$inverse = in_array($name, array('system', 'resolver'), true) ? array('ppp') : null;
		$rows = dump_log($path, $lines, true, ($match !== '') ? array($match) : null, $inverse, 'none');
		$rows = array_values(array_map('strval', (array)$rows));
	} else {
		$rows = conv_log_filter($path, $lines, $lines + 100, $match, $interface);
		$rows = array_values(array_filter((array)$rows, 'is_array'));
		foreach ($rows as $i => $row) {
			$rows[$i] = array('id' => restapi_log_entry_id($row)) + $row;
		}
	}
	$last = null;
	if (($format !== 'raw') && !empty($rows)) {
		$last = $out['newest_first'] ? $rows[0]['id'] : $rows[count($rows) - 1]['id'];
	}
	return $out + array('exists' => true, 'count' => count($rows), 'entries' => $rows, 'last_id' => $last);
}

function restapi_h_logs_list($req) {
	$out = array();
	foreach (restapi_log_catalog() as $name => $entry) {
		$path = restapi_log_path($entry, g_get('varlog_path'));
		$out[] = array(
			'name' => $name,
			'label' => $entry['label'],
			'endpoint' => '/api' . $entry['endpoint'],
			'gui_page' => $entry['page'],
			'formats' => $entry['formats'],
			'exists' => is_file($path),
			'size_bytes' => is_file($path) ? (int)filesize($path) : 0,
		);
	}
	return array('data' => $out);
}

function restapi_h_logs_get($req) {
	$name = $req['params']['name'];
	$entry = restapi_log_entry('system', $name);
	$format = restapi_log_format($req['query'], $entry);
	return array('data' => restapi_log_read($name, $entry, $format, restapi_log_lines($req['query']),
	    restapi_log_filter($req['query']), '', restapi_log_cursor($req['query'])));
}

function restapi_h_logs_vpn($req) {
	$name = $req['params']['name'];
	$entry = restapi_log_entry('vpn', $name);
	$format = restapi_log_format($req['query'], $entry);
	return array('data' => restapi_log_read("vpn/{$name}", $entry, $format, restapi_log_lines($req['query']),
	    restapi_log_filter($req['query']), '', restapi_log_cursor($req['query'])));
}

function restapi_h_logs_firewall($req) {
	$q = $req['query'];
	$entry = restapi_log_entry('firewall', 'firewall');
	$format = restapi_log_format($q, $entry);
	$lines = restapi_log_lines($q);
	$filter = restapi_log_filter($q);
	/* Field filters like the GUI's advanced filter (match_filter_field()); "act" is a list of actions. */
	$fields = array();
	foreach (array('action' => 'act', 'srcip' => 'srcip', 'dstip' => 'dstip', 'srcport' => 'srcport', 'dstport' => 'dstport',
	    'proto' => 'proto') as $param => $key) {
		$value = ($param === 'action') ? restapi_log_text($q, $param) : restapi_log_filter($q, $param);
		if ($value !== '') {
			$fields[$key] = $value;
		}
	}
	if (!empty($fields) && (($filter !== '') || ($format === 'raw'))) {
		throw new RestApiError(400, 'invalid_parameter', 'Field filters (action, srcip, ...) only work on parsed entries and not ' .
		    'together with "filter".');
	}
	$interface = restapi_log_text($q, 'interface');
	if ($interface !== '') {
		if ($format === 'raw') {
			throw new RestApiError(400, 'invalid_parameter', '"interface" only works on parsed entries.');
		}
		/* Like the page: an interface name is shown (and matched) by its description. */
		$iflist = get_configured_interface_with_descr(true);
		if (isset($iflist[$interface])) {
			$interface = $iflist[$interface];
		}
	}
	$data = restapi_log_read('firewall', $entry, $format, $lines, empty($fields) ? $filter : $fields, $interface,
	    restapi_log_cursor($q));
	return array('data' => $data);
}
