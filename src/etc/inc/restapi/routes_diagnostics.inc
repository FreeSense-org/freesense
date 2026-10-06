<?php
/*
 * restapi/routes_diagnostics.inc
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
 * REST API v1 routes for Diagnostics > Ping, Traceroute, DNS Lookup and
 * States (area diagnostics).
 *
 * Requests are checked by the pages' own functions (diag_tools.inc,
 * diag_dump_states.inc) and the commands run as argument lists, never
 * through a shell. Scopes:
 *   - diagnostics:read  DNS lookup (POST, but it changes nothing and only
 *     sends the queries the firewall's resolver sends anyway) and the
 *     state table listing;
 *   - diagnostics:write ping and traceroute (the firewall sends packets
 *     to any host from a chosen source address, including VPN tunnels,
 *     and a request holds a PHP worker for up to a minute; a read-only
 *     monitoring key should not be able to) and killing states.
 * Run times are bounded: a ping may take at most 30 seconds (count x wait)
 * and is stopped after count x wait + 12 s (ping waits 10 s for late replies
 * after the last one); a traceroute is stopped after 60 s. A stopped command
 * gets SIGINT, so its output until then (for ping the statistics) is
 * returned, with "timed_out": true.
 * Resetting the whole state table is not available here (it is an
 * operation with its own confirmation); killing states needs
 * {"confirm": true} and a specific source.
 */

require_once('restapi/framework.inc');

define('RESTAPI_PING_MAX_SECONDS', 30);
/* Ping waits up to 10 s after the last ping for late replies. */
define('RESTAPI_PING_GRACE', 12);
define('RESTAPI_TRACEROUTE_TIMEOUT', 60);
define('RESTAPI_STATES_LIMIT_DEFAULT', 500);
define('RESTAPI_STATES_LIMIT_MAX', 10000);
/* The widest networks whose states DELETE /v1/diagnostics/states kills by source alone. */
define('RESTAPI_KILL_MIN_PREFIX_V4', 8);
define('RESTAPI_KILL_MIN_PREFIX_V6', 32);

function restapi_routes_diagnostics() {
	$host = array('type' => 'string', 'description' => 'Hostname or IP address');
	$ipprotocol = array('type' => 'string', 'enum' => array('ipv4', 'ipv6'), 'description' => 'Default ipv4');
	return array(
		restapi_route('POST', '/v1/diagnostics/ping', 'restapi_h_diag_ping', array(
			'page' => 'diag_ping.php', 'area' => 'diagnostics', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('host'), 'properties' => array(
			    'host' => $host,
			    'count' => array('type' => 'integer', 'description' => 'Number of pings, 1-10 (default 3)'),
			    'wait' => array('type' => 'integer', 'description' => 'Seconds between pings, 1-10 (default 1); count x wait at most ' .
			        RESTAPI_PING_MAX_SECONDS),
			    'ipprotocol' => $ipprotocol,
			    'source' => array('type' => 'string', 'description' => 'Source address: an interface, VIP or address the GUI lists ' .
			        '(see GET /v1/diagnostics/sources); default automatic'))),
			'summary' => 'Ping a host from the firewall (like Diagnostics > Ping); returns the output and the statistics')),
		restapi_route('POST', '/v1/diagnostics/traceroute', 'restapi_h_diag_traceroute', array(
			'page' => 'diag_traceroute.php', 'area' => 'diagnostics', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('host'), 'properties' => array(
			    'host' => $host,
			    'maxttl' => array('type' => 'integer', 'description' => 'Maximum number of hops, 1-64 (default 18)'),
			    'protocol' => array('type' => 'string', 'enum' => array('udp', 'icmp'), 'description' => 'Default udp'),
			    'ipprotocol' => $ipprotocol,
			    'source' => array('type' => 'string', 'description' => 'Source address (see GET /v1/diagnostics/sources); default any'),
			    'resolve' => array('type' => 'boolean', 'description' => 'Look up the names of the hops (slower)'))),
			'summary' => 'Trace the route to a host (like Diagnostics > Traceroute); stopped after ' . RESTAPI_TRACEROUTE_TIMEOUT . ' s')),
		restapi_route('GET', '/v1/diagnostics/sources', 'restapi_h_diag_sources', array(
			'page' => 'diag_ping.php', 'area' => 'diagnostics',
			'summary' => 'The source addresses ping and traceroute accept')),
		restapi_route('POST', '/v1/diagnostics/dns-lookup', 'restapi_h_diag_dns', array(
			'page' => 'diag_dns.php', 'area' => 'diagnostics', 'safe' => true,
			'body' => array('type' => 'object', 'required' => array('hostname'), 'properties' => array(
			    'hostname' => array('type' => 'string', 'description' => 'Hostname, or an IP address for a reverse (PTR) lookup'),
			    'type' => array('type' => 'string', 'enum' => array('A', 'AAAA', 'CNAME', 'PTR'),
			        'description' => 'Only this record type (default A, AAAA and CNAME; PTR for an address)'))),
			'summary' => 'Look up a host with the firewall\'s resolver and time each DNS server (like Diagnostics > DNS Lookup; never creates an alias)')),
		restapi_route('GET', '/v1/diagnostics/states', 'restapi_h_diag_states', array(
			'page' => 'diag_dump_states.php', 'area' => 'diagnostics',
			'query' => array(
			    'interface' => 'Only this interface (e.g. lan, enc0, lo0; default all)',
			    'filter' => 'Only states containing this text (e.g. an address, icmp or ESTABLISHED)',
			    'ruleid' => 'Only states of these rule IDs (comma separated; not combined with interface)',
			    'limit' => 'At most this many states, 1-' . RESTAPI_STATES_LIMIT_MAX . ' (default ' . RESTAPI_STATES_LIMIT_DEFAULT . ')'),
			'summary' => 'The firewall state table, filtered like Diagnostics > States')),
		restapi_route('DELETE', '/v1/diagnostics/states', 'restapi_h_diag_states_kill', array(
			'page' => 'diag_dump_states.php', 'area' => 'diagnostics', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('source', 'confirm'), 'properties' => array(
			    'source' => array('type' => 'string', 'description' => 'An address or subnet (at least /' . RESTAPI_KILL_MIN_PREFIX_V4 .
			        ' for IPv4, /' . RESTAPI_KILL_MIN_PREFIX_V6 . ' for IPv6); without destination, the states from and to it are killed'),
			    'destination' => array('type' => 'string', 'description' => 'An address: kill only the states from source (then an address too) to it'),
			    'confirm' => array('type' => 'boolean', 'description' => 'Must be true'))),
			'summary' => 'Kill the states of an address or subnet, or from one address to another (never the whole table)')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* 400 for fields the endpoint does not know. */
function restapi_diag_fields(array $body, array $known) {
	foreach (array_keys($body) as $field) {
		if (!in_array($field, $known, true)) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$field}\".");
		}
	}
}

/* A string field (trimmed of nothing); $default when absent. 400 when it is not a string or too long. */
function restapi_diag_string(array $body, $field, $default = null, $max = 253) {
	if (!array_key_exists($field, $body) || ($body[$field] === null)) {
		if ($default === null) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" is required.");
		}
		return $default;
	}
	$value = $body[$field];
	if (!is_string($value) || (strlen($value) > $max) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
		throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be a string of at most {$max} printable characters.");
	}
	return $value;
}

/* An integer field between $min and $max (numeric strings of digits are accepted); $default when absent. */
function restapi_diag_int(array $body, $field, $min, $max, $default) {
	if (!array_key_exists($field, $body) || ($body[$field] === null)) {
		return $default;
	}
	$value = $body[$field];
	if (is_string($value) && preg_match('/^[0-9]{1,5}$/D', $value)) {
		$value = (int)$value;
	}
	if (!is_int($value) || ($value < $min) || ($value > $max)) {
		throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be an integer from {$min} to {$max}.");
	}
	return $value;
}

function restapi_diag_bool(array $body, $field) {
	$value = $body[$field] ?? false;
	if (!is_bool($value)) {
		throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be true or false.");
	}
	return $value;
}

/* One of a list of values; $default when absent. */
function restapi_diag_enum(array $body, $field, array $values, $default) {
	$value = $body[$field] ?? $default;
	if (!is_string($value) || !in_array($value, $values, true)) {
		throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be one of: " . implode(', ', $values) . '.');
	}
	return $value;
}

/* The page's form post for a ping request body. */
function restapi_diag_ping_post(array $body) {
	restapi_diag_fields($body, array('host', 'count', 'wait', 'ipprotocol', 'source'));
	$count = restapi_diag_int($body, 'count', 1, 10, 3);
	$wait = restapi_diag_int($body, 'wait', 1, 10, 1);
	if ($count * $wait > RESTAPI_PING_MAX_SECONDS) {
		throw new RestApiError(400, 'invalid_field', 'A ping may take at most ' . RESTAPI_PING_MAX_SECONDS .
		    ' seconds: count x wait must be at most ' . RESTAPI_PING_MAX_SECONDS . '.');
	}
	return array(
		'host' => restapi_diag_string($body, 'host'),
		'count' => (string)$count,
		'wait' => (string)$wait,
		'ipproto' => restapi_diag_enum($body, 'ipprotocol', array('ipv4', 'ipv6'), 'ipv4'),
		'sourceip' => restapi_diag_string($body, 'source', '', 64),
	);
}

/* The page's form post for a traceroute request body (checkboxes only when ticked). */
function restapi_diag_traceroute_post(array $body) {
	restapi_diag_fields($body, array('host', 'maxttl', 'protocol', 'ipprotocol', 'source', 'resolve'));
	$post = array(
		'host' => restapi_diag_string($body, 'host'),
		'ttl' => (string)restapi_diag_int($body, 'maxttl', 1, 64, 18),
		'ipproto' => restapi_diag_enum($body, 'ipprotocol', array('ipv4', 'ipv6'), 'ipv4'),
		'sourceip' => restapi_diag_string($body, 'source', 'any', 64),
	);
	if ($post['sourceip'] === '') {
		$post['sourceip'] = 'any';
	}
	if (restapi_diag_enum($body, 'protocol', array('udp', 'icmp'), 'udp') === 'icmp') {
		$post['useicmp'] = 'yes';
	}
	if (restapi_diag_bool($body, 'resolve')) {
		$post['resolve'] = 'yes';
	}
	return $post;
}

/* The summary of ping output: packets sent and received, loss and round-trip times. */
function restapi_diag_ping_stats($output) {
	$stats = array('transmitted' => null, 'received' => null, 'packet_loss' => null, 'rtt' => null);
	if (preg_match('/(\d+) packets transmitted, (\d+) packets received,(?: \+\d+ duplicates,)? ([0-9.]+)% packet loss/', $output, $m)) {
		$stats['transmitted'] = (int)$m[1];
		$stats['received'] = (int)$m[2];
		$stats['packet_loss'] = (float)$m[3];
	}
	if (preg_match('#round-trip min/avg/max/(?:std-?dev) = ([0-9.]+)/([0-9.]+)/([0-9.]+)/([0-9.]+) ms#', $output, $m)) {
		$stats['rtt'] = array('min' => (float)$m[1], 'avg' => (float)$m[2], 'max' => (float)$m[3], 'stddev' => (float)$m[4]);
	}
	return $stats;
}

/* The hop lines of traceroute output (continuation lines belong to the hop above). */
function restapi_diag_traceroute_hops($output) {
	$hops = array();
	foreach (preg_split('/\r?\n/', (string)$output) as $line) {
		if (preg_match('/^\s*(\d+)\s+(.*)$/', $line, $m)) {
			$hops[] = array('hop' => (int)$m[1], 'text' => trim($m[2]));
		} elseif ((trim($line) !== '') && !empty($hops)) {
			$hops[count($hops) - 1]['text'] .= "\n" . trim($line);
		}
	}
	return $hops;
}

/* The record types the lookup asks for: the page's (null) or one type; PTR only for an address. */
function restapi_diag_dns_types($type, $is_ip) {
	if ($type === null) {
		return null;
	}
	$map = array('A' => 1, 'AAAA' => 28, 'CNAME' => 5);
	if ($is_ip) {
		if ($type !== 'PTR') {
			throw new RestApiError(400, 'invalid_field', 'An IP address is looked up as PTR (omit "type" or use "PTR").');
		}
		return null;
	}
	if (!isset($map[$type])) {
		throw new RestApiError(400, 'invalid_field', 'Field "type" must be A, AAAA or CNAME for a hostname.');
	}
	return array(constant('DNS_' . $type));
}

/* "  12 msec" => 12; "No response" => null. */
function restapi_diag_query_ms($text) {
	return preg_match('/^\s*(\d+)\s*msec\s*$/', (string)$text, $m) ? (int)$m[1] : null;
}

/* The state filter of GET /v1/diagnostics/states as the page's form post, checked. */
function restapi_diag_states_post(array $query, array $interfaces) {
	$post = array('interface' => 'all', 'filter' => '');
	if (isset($query['interface']) && ($query['interface'] !== '')) {
		if (!is_string($query['interface']) || !array_key_exists($query['interface'], $interfaces)) {
			throw new RestApiError(400, 'invalid_field', 'Query "interface" must be one of: ' . implode(', ', array_keys($interfaces)) . '.');
		}
		$post['interface'] = $query['interface'];
	}
	if (isset($query['filter'])) {
		$post['filter'] = restapi_diag_string($query, 'filter', '', 100);
	}
	if (isset($query['ruleid']) && ($query['ruleid'] !== '')) {
		if (!is_string($query['ruleid']) || !preg_match('/^[0-9]{1,10}(,[0-9]{1,10}){0,49}$/D', $query['ruleid'])) {
			throw new RestApiError(400, 'invalid_field', 'Query "ruleid" must be a comma separated list of rule IDs.');
		}
		$post['ruleid'] = $query['ruleid'];
	}
	return $post;
}

/* The prefix length of an address or CIDR network ("" for anything else). */
function restapi_diag_prefix($net) {
	if (is_string($net) && preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#D', $net, $m)) {
		return (int)$m[2];
	}
	return '';
}

/* ------------------------------------------------------------------ */
/* Handlers                                                            */
/* ------------------------------------------------------------------ */

function restapi_h_diag_ping($req) {
	$post = restapi_diag_ping_post($req['body']);
	$check = diag_ping_check($post);
	if (!empty($check['input_errors'])) {
		throw restapi_validation_error($check['input_errors']);
	}
	$run = diag_exec(diag_ping_command($check['host'], $check['ipproto'], $check['sourceip'], $check['count'], $check['wait']),
	    (int)$check['count'] * (int)$check['wait'] + RESTAPI_PING_GRACE);
	if (empty($run['stdout'])) {
		$messages = array(sprintf(gettext('Host "%s" did not respond or could not be resolved.'), $check['host_utf8']));
		if (trim($run['stderr']) !== '') {
			$messages[] = trim($run['stderr']);
		}
		throw restapi_validation_error($messages);
	}
	return array('data' => array(
		'host' => $check['host_utf8'],
		'ipprotocol' => $check['ipproto'],
		'source' => $check['sourceip'],
		'count' => (int)$check['count'],
		'wait' => (int)$check['wait'],
	) + restapi_diag_ping_stats($run['stdout']) + array(
		'timed_out' => $run['timed_out'],
		'output' => $run['stdout'],
	));
}

function restapi_h_diag_traceroute($req) {
	$post = restapi_diag_traceroute_post($req['body']);
	$check = diag_traceroute_check($post);
	if (!empty($check['input_errors'])) {
		throw restapi_validation_error($check['input_errors']);
	}
	$run = diag_exec(diag_traceroute_command($check['host'], $check['ipproto'], $check['sourceip'], $check['ttl'],
	    isset($check['resolve']), isset($check['useicmp'])), RESTAPI_TRACEROUTE_TIMEOUT);
	if (empty($run['stdout']) && !$run['timed_out']) {
		$messages = array(sprintf(gettext('Error: %s could not be traced/resolved'), $check['host_utf8']));
		if (trim($run['stderr']) !== '') {
			$messages[] = trim($run['stderr']);
		}
		throw restapi_validation_error($messages);
	}
	return array('data' => array(
		'host' => $check['host_utf8'],
		'ipprotocol' => $check['ipproto'],
		'source' => $check['sourceip'],
		'maxttl' => (int)$check['ttl'],
		'protocol' => isset($check['useicmp']) ? 'icmp' : 'udp',
		'resolve' => isset($check['resolve']),
		'hops' => restapi_diag_traceroute_hops($run['stdout']),
		'timed_out' => $run['timed_out'],
		'output' => $run['stdout'],
	));
}

function restapi_h_diag_sources($req) {
	$out = array();
	foreach (get_possible_traffic_source_addresses(true) as $value => $label) {
		$out[] = array('source' => (string)$value, 'description' => html_entity_decode((string)$label, ENT_QUOTES));
	}
	return array('data' => $out);
}

function restapi_h_diag_dns($req) {
	$body = $req['body'];
	restapi_diag_fields($body, array('hostname', 'type'));
	$hostname = restapi_diag_string($body, 'hostname');
	$type = isset($body['type']) ? restapi_diag_enum($body, 'type', array('A', 'AAAA', 'CNAME', 'PTR'), null) : null;
	list($host, $host_utf8) = diag_dns_host($hostname);
	$types = restapi_diag_dns_types($type, is_ipaddr($host));
	$lookup = diag_dns_lookup(array('host' => $hostname), $host, $types);
	if (!empty($lookup['input_errors'])) {
		throw restapi_validation_error($lookup['input_errors']);
	}
	$servers = array();
	foreach ((array)$lookup['dns_speeds'] as $qt) {
		$servers[] = array(
			'server' => trim($qt['dns_server']),
			'query_time' => trim($qt['query_time']),
			'query_time_ms' => restapi_diag_query_ms($qt['query_time']),
		);
	}
	$answers = array();
	foreach ((array)$lookup['resolved'] as $r) {
		$answers[] = array('type' => $r['type'], 'data' => $r['data']);
	}
	return array('data' => array(
		'hostname' => $host_utf8,
		'query' => $host,
		'kind' => $lookup['type'],
		'address' => $lookup['ipaddr'],
		'resolved' => !empty($answers),
		'answers' => $answers,
		'servers' => $servers,
	));
}

function restapi_h_diag_states($req) {
	$query = $req['query'];
	$limit = restapi_diag_int($query, 'limit', 1, RESTAPI_STATES_LIMIT_MAX, RESTAPI_STATES_LIMIT_DEFAULT);
	$post = restapi_diag_states_post($query, diag_states_interfaces());
	$errors = diag_states_filter_errors($post);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	/* "filter" is always set: the API bounds the answer instead of requiring a filter (System > General Setup). */
	$states = process_state_req($post, $post, false, true);
	$out = array();
	foreach (array_slice($states, 0, $limit) as $s) {
		$out[] = array(
			'interface' => $s['interface'],
			'protocol' => $s['proto'],
			'source' => $s['srcip'],
			'destination' => $s['dstip'],
			'display' => $s['display'],
			'state' => $s['state'],
			'packets' => $s['packets'],
			'bytes' => $s['bytes'],
		) + $s['details'];
	}
	return array('data' => array(
		'total' => count($states),
		'returned' => count($out),
		'truncated' => count($states) > count($out),
		'states' => $out,
	));
}

function restapi_h_diag_states_kill($req) {
	$body = $req['body'];
	restapi_diag_fields($body, array('source', 'destination', 'confirm'));
	if (($body['confirm'] ?? null) !== true) {
		throw new RestApiError(400, 'confirmation_required',
		    'Killing states cuts the matching connections and requires {"confirm": true}.');
	}
	$source = restapi_diag_string($body, 'source', null, 64);
	$destination = isset($body['destination']) ? restapi_diag_string($body, 'destination', null, 64) : null;
	$client = (string)($_SERVER['REMOTE_ADDR'] ?? '');

	if ($destination !== null) {
		/* The remove button of a state row: both are addresses. */
		if (!is_ipaddr($source) || !is_ipaddr($destination)) {
			throw new RestApiError(400, 'invalid_field', 'With a destination, source and destination must both be IP addresses.');
		}
		if (in_array($client, array($source, $destination), true)) {
			throw new RestApiError(409, 'own_connection', 'These states include the connection of this request.');
		}
		diag_states_kill_pair($source, $destination);
		return array('data' => array('killed' => array(array('source' => $source, 'destination' => $destination))));
	}

	$tokill = diag_states_kill_target($source);
	if ($tokill === '') {
		throw new RestApiError(400, 'invalid_field', 'Field "source" must be an IP address or a subnet.');
	}
	$prefix = restapi_diag_prefix($tokill);
	$min = is_subnetv6($tokill) ? RESTAPI_KILL_MIN_PREFIX_V6 : RESTAPI_KILL_MIN_PREFIX_V4;
	if (($prefix === '') || ($prefix < $min)) {
		throw new RestApiError(400, 'invalid_field', "The source network is too wide (at least /{$min}); the whole state table cannot be killed here.");
	}
	if (($client !== '') && restapi_ip_in_network($client, $tokill)) {
		throw new RestApiError(409, 'own_connection', 'These states include the connection of this request.');
	}
	$tokill = diag_states_kill_filter($source);
	$any = is_subnetv6($tokill) ? '::/0' : '0.0.0.0/0';
	return array('data' => array('killed' => array(
		array('source' => $tokill, 'destination' => $any),
		array('source' => $any, 'destination' => $tokill),
	)));
}

/* ------------------------------------------------------------------ */
/* Status: NDP table                                                   */
/* ------------------------------------------------------------------ */

function restapi_h_status_ndp($req) {
	$hwif = array();
	foreach (get_configured_interface_with_descr() as $key => $descr) {
		$hwif[config_get_path("interfaces/{$key}/if")] = $descr;
	}
	$out = array();
	foreach (diag_ndp_table() as $entry) {
		$out[] = array(
			'ipv6' => $entry['ipv6'],
			'mac' => $entry['mac'],
			'hostname' => trim(str_replace("Z_ ", "", $entry['dnsresolve'])),
			'interface' => $hwif[$entry['interface']] ?? $entry['interface'],
			'port' => $entry['interface'],
			'expiration' => $entry['expiration'],
		);
	}
	return array('data' => $out);
}
