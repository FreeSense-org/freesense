<?php
/*
 * routes_status.inc
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
 * Live status for the WebUI dashboard and status pages: system metrics,
 * interface traffic (live and history), the state table and notices.
 * The front controller loads status_metrics.inc and notices.inc.
 */

function restapi_routes_status() {
	return array(
		restapi_route('GET', '/v1/status/system', 'restapi_h_status_system', array(
			'page' => 'index.php', 'area' => 'system',
			'summary' => 'Live system metrics: CPU, memory, swap, mbufs, states, temperatures, disks, uptime')),
		restapi_route('GET', '/v1/status/traffic', 'restapi_h_status_traffic', array(
			'page' => 'status_graph.php', 'area' => 'status',
			'query' => array('if' => 'Comma-separated interfaces (wan,lan,opt1); all when omitted'),
			'summary' => 'Current traffic rates (bits/s, packets/s) and byte counters per interface')),
		restapi_route('GET', '/v1/status/traffic/history', 'restapi_h_status_traffic_history', array(
			'page' => 'status_graph.php', 'area' => 'status',
			'query' => array('if' => 'Interface (wan, lan, opt1)', 'range' => '10m, 1h, 24h, 7d or 30d'),
			'summary' => 'Traffic history in bits/s from the interface RRD')),
		restapi_route('GET', '/v1/status/system/history', 'restapi_h_status_system_history', array(
			'page' => 'index.php', 'area' => 'system',
			'query' => array('range' => '10m, 1h, 24h, 7d or 30d'),
			'summary' => 'CPU and memory use in percent over time (system RRDs)')),
		restapi_route('GET', '/v1/status/gateways/history', 'restapi_h_status_gateway_history', array(
			'page' => 'status_gateways.php', 'area' => 'status',
			'query' => array('range' => '10m, 1h, 24h, 7d or 30d'),
			'summary' => 'Latency, jitter (ms) and loss (%) of every gateway over time (quality RRDs)')),
		restapi_route('GET', '/v1/status/states', 'restapi_h_status_states', array(
			'page' => 'diag_states_summary.php', 'area' => 'status',
			'summary' => 'State table size, limit and search/insert/removal rates')),
		restapi_route('GET', '/v1/notices', 'restapi_h_notices', array(
			'page' => 'index.php', 'area' => 'system', 'summary' => 'System notices')),
		restapi_route('DELETE', '/v1/notices/{id}', 'restapi_h_notice_delete', array(
			'page' => 'index.php', 'area' => 'system', 'write' => true,
			'summary' => 'Dismiss a notice ("all" dismisses every notice)')),
	);
}

function restapi_h_status_system($req) {
	return array('data' => status_metrics_system());
}

function restapi_h_status_traffic($req) {
	$ifs = array_values(array_filter(array_map('trim', explode(',', (string)($req['query']['if'] ?? '')))));
	return array('data' => status_metrics_traffic($ifs));
}

function restapi_status_range($req) {
	$range = (string)($req['query']['range'] ?? '1h');
	if (!array_key_exists($range, status_metrics_ranges())) {
		throw new RestApiError(400, 'invalid_range', 'range must be one of ' . implode(', ', array_keys(status_metrics_ranges())) . '.');
	}
	return $range;
}

function restapi_h_status_system_history($req) {
	$data = status_metrics_system_history(restapi_status_range($req));
	if ($data === null) {
		throw new RestApiError(404, 'no_history', 'There is no system history yet.');
	}
	return array('data' => $data);
}

function restapi_h_status_gateway_history($req) {
	$range = restapi_status_range($req);
	return array('data' => status_metrics_gateway_history(array_keys(return_gateways_array()), $range));
}

function restapi_h_status_traffic_history($req) {
	$if = (string)($req['query']['if'] ?? '');
	$range = (string)($req['query']['range'] ?? '1h');
	if (!array_key_exists($range, status_metrics_ranges())) {
		throw new RestApiError(400, 'invalid_range', 'range must be one of ' . implode(', ', array_keys(status_metrics_ranges())) . '.');
	}
	if (!array_key_exists($if, get_configured_interface_with_descr())) {
		throw new RestApiError(404, 'not_found', 'No such interface.');
	}
	$data = status_metrics_traffic_history($if, $range);
	if ($data === null) {
		throw new RestApiError(404, 'no_history', 'There is no traffic history for this interface yet.');
	}
	return array('data' => $data);
}

function restapi_h_status_states($req) {
	return array('data' => status_metrics_states());
}

/* Notices as plain text (the queue stores them HTML-encoded for the old pages). */
function restapi_notice_list($queue) {
	$out = array();
	foreach ((is_array($queue) ? $queue : array()) as $time => $n) {
		$priority = (int)($n['priority'] ?? 1);
		$out[] = array(
			'id' => (string)$time,
			'source' => html_entity_decode((string)($n['id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'text' => html_entity_decode((string)($n['notice'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'url' => html_entity_decode((string)($n['url'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'category' => html_entity_decode((string)($n['category'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'level' => ($priority >= 2) ? 'crit' : (($priority === 1) ? 'warn' : 'info'),
			'time' => gmdate('c', (int)$time),
		);
	}
	usort($out, function ($a, $b) { return strcmp($b['time'], $a['time']); });
	return $out;
}

function restapi_h_notices($req) {
	return array('data' => restapi_notice_list(get_notices()));
}

function restapi_h_notice_delete($req) {
	$id = (string)$req['params']['id'];
	if (($id !== 'all') && !ctype_digit($id)) {
		throw new RestApiError(404, 'not_found', 'No such notice.');
	}
	if ($id !== 'all') {
		$queue = get_notices();
		if (!is_array($queue) || !array_key_exists((int)$id, $queue)) {
			throw new RestApiError(404, 'not_found', 'No such notice.');
		}
	}
	close_notice($id);
	return array('data' => null);
}
