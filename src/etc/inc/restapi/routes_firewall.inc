<?php
/*
 * restapi/routes_firewall.inc
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
 * REST API v1 firewall routes: aliases, NAT port forwards, virtual IPs,
 * schedules and (read-only for now) filter rules.
 *
 * Writes go through the same functions as the GUI pages (saveAlias,
 * saveNATrule, saveVIP, saveSchedule, ...), so validation is identical.
 * Like the GUI, most changes are staged and take effect with the area's
 * apply endpoint; add ?apply=true to a write to apply at once. Deleting a
 * port forward or virtual IP applies immediately, as in the GUI.
 *
 * Aliases and schedules are addressed by name. Port forwards, virtual IPs
 * and filter rules are addressed by their position in config.xml, which
 * shifts when entries are added or removed; send If-Match with the ETag
 * from a previous response to be safe.
 *
 * The front controller loads alias-utils.inc, firewall_nat.inc,
 * firewall_virtual_ip.inc and firewall_schedule.inc at file scope (they
 * set globals the save functions use).
 */

require_once('restapi/framework.inc');

function restapi_routes_firewall() {
	$alias_body = array('type' => 'object', 'properties' => array(
		'name' => array('type' => 'string'),
		'type' => array('type' => 'string', 'enum' => array('host', 'network', 'port', 'url', 'url_ports', 'urltable', 'urltable_ports')),
		'description' => array('type' => 'string'),
		'entries' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
		    'address' => array('type' => 'string', 'description' => 'Host, network (CIDR), range, port or URL'),
		    'detail' => array('type' => 'string')))),
		'update_frequency' => array('type' => 'integer', 'description' => 'Days between URL table downloads'),
	));
	$natpf_body = array('type' => 'object', 'description' => 'The same fields as the port forward edit page ' .
	    '(interface, ipprotocol, proto, srctype, src, srcmask, srcbeginport, srcendport, dsttype, dst, dstmask, ' .
	    'dstbeginport, dstendport, localtype, localip, localbeginport, natreflection, descr, disabled, nordr, nosync, ' .
	    'filter-rule-association (none "", add-associated, add-unassociated or pass; on an update add-associated ' .
	    'keeps an existing linked rule), after). GET returns them as "fields".');
	$vip_body = array('type' => 'object', 'description' => 'The same fields as the virtual IP edit page ' .
	    '(mode, interface, subnet, subnet_bits, type, descr, vhid, advbase, advskew, password, noexpand). ' .
	    'GET returns them as "fields" (never the password). On update a missing or empty password keeps the current one.');
	$sched_body = array('type' => 'object', 'properties' => array(
		'name' => array('type' => 'string'),
		'description' => array('type' => 'string', 'description' => 'Also accepted as "descr"'),
		'ranges' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
		    'weekdays' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => '1 = Monday ... 7 = Sunday'),
		    'dates' => array('type' => 'array', 'items' => array('type' => 'string'),
		    'description' => '"MM-DD" or "YYYY-MM-DD" (a list, or one comma-separated string); a range has weekdays or dates'),
		    'start' => array('type' => 'string', 'description' => 'HH:MM'),
		    'stop' => array('type' => 'string', 'description' => 'HH:MM, not earlier than start'),
		    'description' => array('type' => 'string')))),
		'timerange' => array('type' => 'array', 'description' => 'Instead of "ranges": the stored form ' .
		    '[{position: "1,2,3", month: "12,12", day: "24,25", hour: "8:00-17:00", rangedescr}]'),
	));
	$apply_query = array('apply' => 'true to apply the change at once (default: stage it like the GUI)');
	$rule_body = array('type' => 'object', 'description' => 'The same fields as the rule edit page: ' .
	    'type (pass|block|reject|match), interface (a list for floating rules), floating, direction, quick, ' .
	    'ipprotocol (inet|inet6|inet46), proto, icmptype (list), srctype, src, srcmask, srcnot, srcbeginport, ' .
	    'srcendport, dsttype, dst, dstmask, dstnot, dstbeginport, dstendport, descr, disabled, log, gateway, ' .
	    'sched, defaultqueue, ackqueue, dnpipe, pdnpipe, tag, tagged, statetype, max, ... and "after" (create: ' .
	    'insert after this rule id, -1 for the top). GET /firewall/rules/{id} returns them as "fields".');

	return array(
		restapi_route('GET', '/v1/firewall/pending', 'restapi_h_fw_pending', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules',
			'summary' => 'Firewall changes waiting to be applied, per area')),
		restapi_route('POST', '/v1/firewall/apply', 'restapi_h_fw_apply_all', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules', 'write' => true,
			'summary' => 'Apply every pending firewall change (virtual IPs, then rules, NAT and aliases in one filter reload)')),

		/* Aliases */
		restapi_route('GET', '/v1/firewall/aliases', 'restapi_h_alias_list', array(
			'page' => 'firewall_aliases.php', 'area' => 'firewall.aliases', 'summary' => 'List aliases')),
		restapi_route('GET', '/v1/firewall/aliases/{name}', 'restapi_h_alias_get', array(
			'page' => 'firewall_aliases.php', 'area' => 'firewall.aliases', 'summary' => 'Get an alias')),
		restapi_route('POST', '/v1/firewall/aliases', 'restapi_h_alias_create', array(
			'page' => 'firewall_aliases_edit.php', 'area' => 'firewall.aliases', 'write' => true, 'schema' => 'firewall/aliases',
			'body' => $alias_body, 'query' => $apply_query, 'summary' => 'Create an alias')),
		restapi_route('PUT', '/v1/firewall/aliases/{name}', 'restapi_h_alias_update', array(
			'page' => 'firewall_aliases_edit.php', 'area' => 'firewall.aliases', 'write' => true, 'schema' => 'firewall/aliases',
			'body' => $alias_body, 'query' => $apply_query,
			'summary' => 'Update an alias (omitted fields keep their value; "name" renames it)')),
		restapi_route('DELETE', '/v1/firewall/aliases/{name}', 'restapi_h_alias_delete', array(
			'page' => 'firewall_aliases.php', 'area' => 'firewall.aliases', 'write' => true,
			'query' => $apply_query, 'summary' => 'Delete an alias that nothing references')),
		restapi_route('POST', '/v1/firewall/aliases/apply', 'restapi_h_alias_apply', array(
			'page' => 'firewall_aliases.php', 'area' => 'firewall.aliases', 'write' => true,
			'summary' => 'Apply pending alias changes (reloads the filter)')),

		/* NAT port forwards */
		restapi_route('GET', '/v1/firewall/nat/port-forwards', 'restapi_h_natpf_list', array(
			'page' => 'firewall_nat.php', 'area' => 'firewall.nat', 'summary' => 'List port forwards')),
		/* Specific paths before the {id} routes (the first matching route wins). */
		restapi_route('POST', '/v1/firewall/nat/port-forwards/order', 'restapi_h_natpf_order', array(
			'page' => 'firewall_nat.php', 'area' => 'firewall.nat', 'write' => true,
			'body' => restapi_nat_order_body(), 'query' => $apply_query,
			'summary' => 'Reorder the port forwards (like dragging them on the list page)')),
		restapi_route('GET', '/v1/firewall/nat/port-forwards/{id}', 'restapi_h_natpf_get', array(
			'page' => 'firewall_nat.php', 'area' => 'firewall.nat',
			'summary' => 'Get a port forward (plus "fields": the edit form fields, and "display": the list columns as text)')),
		restapi_route('POST', '/v1/firewall/nat/port-forwards', 'restapi_h_natpf_create', array(
			'page' => 'firewall_nat_edit.php', 'area' => 'firewall.nat', 'write' => true, 'schema' => 'firewall/nat_port_forwards',
			'body' => $natpf_body, 'query' => $apply_query, 'summary' => 'Create a port forward')),
		restapi_route('PUT', '/v1/firewall/nat/port-forwards/{id}', 'restapi_h_natpf_update', array(
			'page' => 'firewall_nat_edit.php', 'area' => 'firewall.nat', 'write' => true, 'schema' => 'firewall/nat_port_forwards',
			'body' => $natpf_body, 'query' => $apply_query,
			'summary' => 'Update a port forward (omitted fields keep their value; false clears a checkbox)')),
		restapi_route('POST', '/v1/firewall/nat/port-forwards/{id}/toggle', 'restapi_h_natpf_toggle', array(
			'page' => 'firewall_nat.php', 'area' => 'firewall.nat', 'write' => true,
			'query' => $apply_query, 'summary' => 'Enable or disable a port forward')),
		restapi_route('DELETE', '/v1/firewall/nat/port-forwards/{id}', 'restapi_h_natpf_delete', array(
			'page' => 'firewall_nat.php', 'area' => 'firewall.nat', 'write' => true,
			'summary' => 'Delete a port forward and its associated rule (applies at once)')),
		restapi_route('POST', '/v1/firewall/nat/apply', 'restapi_h_nat_apply', array(
			'page' => 'firewall_nat.php', 'area' => 'firewall.nat', 'write' => true,
			'summary' => 'Apply pending NAT changes (reloads the filter)')),

		/* Virtual IPs */
		restapi_route('GET', '/v1/firewall/virtual-ips', 'restapi_h_vip_list', array(
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips',
			'summary' => 'List virtual IPs (stored form, plus "fields": the edit form fields, and "display": the list columns as text)')),
		restapi_route('GET', '/v1/firewall/virtual-ips/{id}', 'restapi_h_vip_get', array(
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips', 'summary' => 'Get a virtual IP (with "fields" and "display")')),
		restapi_route('POST', '/v1/firewall/virtual-ips', 'restapi_h_vip_create', array(
			'page' => 'firewall_virtual_ip_edit.php', 'area' => 'firewall.vips', 'write' => true, 'schema' => 'firewall/virtual_ips',
			'body' => $vip_body, 'query' => $apply_query, 'summary' => 'Create a virtual IP')),
		restapi_route('PUT', '/v1/firewall/virtual-ips/{id}', 'restapi_h_vip_update', array(
			'page' => 'firewall_virtual_ip_edit.php', 'area' => 'firewall.vips', 'write' => true, 'schema' => 'firewall/virtual_ips',
			'body' => $vip_body, 'query' => $apply_query,
			'summary' => 'Update a virtual IP (omitted fields keep their value; false clears a checkbox; no password keeps it)')),
		restapi_route('DELETE', '/v1/firewall/virtual-ips/{id}', 'restapi_h_vip_delete', array(
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips', 'write' => true,
			'query' => $apply_query, 'summary' => 'Delete a virtual IP that nothing uses')),
		restapi_route('POST', '/v1/firewall/virtual-ips/apply', 'restapi_h_vip_apply', array(
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips', 'write' => true,
			'summary' => 'Apply pending virtual IP changes')),

		/* Schedules */
		restapi_route('GET', '/v1/firewall/schedules', 'restapi_h_sched_list', array(
			'page' => 'firewall_schedule.php', 'area' => 'firewall.schedules',
			'summary' => 'List schedules (with "fields": the editor fields, and "display": ranges as text, active now, rules using it)')),
		restapi_route('GET', '/v1/firewall/schedules/{name}', 'restapi_h_sched_get', array(
			'page' => 'firewall_schedule.php', 'area' => 'firewall.schedules', 'summary' => 'Get a schedule (with "fields" and "display")')),
		restapi_route('POST', '/v1/firewall/schedules', 'restapi_h_sched_create', array(
			'page' => 'firewall_schedule_edit.php', 'area' => 'firewall.schedules', 'write' => true, 'schema' => 'firewall/schedules',
			'body' => $sched_body, 'summary' => 'Create a schedule (applies at once, like the GUI)')),
		restapi_route('PUT', '/v1/firewall/schedules/{name}', 'restapi_h_sched_update', array(
			'page' => 'firewall_schedule_edit.php', 'area' => 'firewall.schedules', 'write' => true, 'schema' => 'firewall/schedules',
			'body' => $sched_body,
			'summary' => 'Update a schedule (omitted fields keep their value; "ranges" replaces all ranges; a schedule in use keeps its name; applies at once)')),
		restapi_route('DELETE', '/v1/firewall/schedules/{name}', 'restapi_h_sched_delete', array(
			'page' => 'firewall_schedule.php', 'area' => 'firewall.schedules', 'write' => true,
			'summary' => 'Delete a schedule that no rule uses')),

		/* Filter rules */
		restapi_route('GET', '/v1/firewall/rules', 'restapi_h_rule_list', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules',
			'query' => array('interface' => 'Only rules of this interface (e.g. lan, wan, or "floating")'),
			'summary' => 'List filter rules in evaluation order')),
		restapi_route('GET', '/v1/firewall/rules/tabs', 'restapi_h_rule_tabs', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules',
			'summary' => 'The rule list tabs (Floating, then every interface and group) with their rule counts')),
		restapi_route('GET', '/v1/firewall/rules/{id}', 'restapi_h_rule_get', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules',
			'summary' => 'Get a filter rule (stored form, plus "fields": the edit form fields, and "display": the list columns as text)')),
		restapi_route('POST', '/v1/firewall/rules', 'restapi_h_rule_create', array(
			'page' => 'firewall_rules_edit.php', 'area' => 'firewall.rules', 'write' => true, 'schema' => 'firewall/rules',
			'body' => $rule_body, 'query' => $apply_query, 'summary' => 'Create a filter rule')),
		restapi_route('PUT', '/v1/firewall/rules/{id}', 'restapi_h_rule_update', array(
			'page' => 'firewall_rules_edit.php', 'area' => 'firewall.rules', 'write' => true, 'schema' => 'firewall/rules',
			'body' => $rule_body, 'query' => $apply_query,
			'summary' => 'Update a filter rule (omitted fields keep their value; false clears a checkbox)')),
		restapi_route('POST', '/v1/firewall/rules/{id}/toggle', 'restapi_h_rule_toggle', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules', 'write' => true,
			'query' => $apply_query, 'summary' => 'Enable or disable a filter rule')),
		restapi_route('DELETE', '/v1/firewall/rules/{id}', 'restapi_h_rule_delete', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules', 'write' => true,
			'query' => $apply_query, 'summary' => 'Delete a filter rule')),
		restapi_route('POST', '/v1/firewall/rules/order', 'restapi_h_rule_order', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('interface', 'order'), 'properties' => array(
			    'interface' => array('type' => 'string', 'description' => 'Rules tab: an interface or "floating"'),
			    'order' => array('type' => 'array', 'items' => array('type' => 'integer'),
			    'description' => 'Every rule id of that tab, in the new order'))),
			'query' => $apply_query, 'summary' => 'Reorder the rules of one interface')),
		restapi_route('POST', '/v1/firewall/rules/apply', 'restapi_h_rule_apply', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules', 'write' => true,
			'summary' => 'Apply pending filter rule changes (reloads the filter)')),
	);
}

/* ------------------------------------------------------------------ */
/* Shared helpers                                                      */
/* ------------------------------------------------------------------ */

function restapi_want_apply($req) {
	return in_array(strtolower((string)($req['query']['apply'] ?? '')), array('1', 'true', 'yes'), true);
}

/* Reload the filter and clear the given dirty flags when it succeeds. */
function restapi_fw_apply(array $subsystems) {
	$retval = filter_configure();
	if ($retval == 0) {
		foreach ($subsystems as $subsystem) {
			clear_subsystem_dirty($subsystem);
		}
	}
	return array('applied' => ($retval == 0), 'pending' => restapi_fw_pending_list());
}

/* Apply everything pending: virtual IPs first (rules may use them), then one filter reload for rules, NAT and aliases. */
function restapi_h_fw_apply_all($req) {
	$ok = true;
	if (is_subsystem_dirty('vip')) {
		$result = applyVIP();
		$ok = (($result['retval'] ?? 1) == 0);
	}
	$filter = restapi_fw_apply(array('filter', 'natconf', 'aliases'));
	if ($ok && $filter['applied']) {
		FreeSense_handle_custom_code('/usr/local/pkg/firewall_nat/apply');
	}
	return array('data' => array('applied' => $ok && $filter['applied'], 'pending' => restapi_fw_pending_list()));
}

function restapi_fw_pending_list() {
	$pending = array();
	foreach (array('filter' => 'rules', 'natconf' => 'nat', 'aliases' => 'aliases', 'vip' => 'virtual_ips') as $flag => $name) {
		if (is_subsystem_dirty($flag)) {
			$pending[] = $name;
		}
	}
	return $pending;
}

/* Some GUI functions return JSON text instead of an array when $json is set. */
function restapi_json_result($result) {
	return is_string($result) ? json_decode($result, true) : $result;
}

/* A numeric index into a config list, or a 404. */
function restapi_index_param($value, array $list, $what) {
	if (!ctype_digit((string)$value) || !isset($list[(int)$value])) {
		throw new RestApiError(404, 'not_found', "No {$what} with that id.");
	}
	return (int)$value;
}

/* The body as a flat post array (scalars and lists only, like a form). */
function restapi_body_as_post(array $body) {
	$post = array();
	foreach ($body as $key => $value) {
		if (is_bool($value)) {
			if ($value) {
				$post[$key] = 'yes';
			}
		} elseif (is_scalar($value) || is_null($value)) {
			$post[$key] = (string)$value;
		} elseif (is_array($value) && array_is_list($value)) {
			$post[$key] = array_map('strval', array_filter($value, 'is_scalar'));
		} else {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a string, number, boolean or list.");
		}
	}
	return $post;
}

/*
 * A partial update: the body as edit-page POST fields over the current ones.
 * A checkbox is "off" when it is missing from a POST, so false removes it
 * from the merged fields (otherwise the current "yes" would win).
 */
function restapi_body_over(array $body, array $current) {
	$post = restapi_body_as_post($body) + $current;
	foreach ($body as $key => $value) {
		if ($value === false) {
			unset($post[$key]);
		}
	}
	return $post;
}

function restapi_h_fw_pending($req) {
	return array('data' => array('pending' => restapi_fw_pending_list()));
}

/* ------------------------------------------------------------------ */
/* Aliases                                                             */
/* ------------------------------------------------------------------ */

function restapi_alias_index($name) {
	foreach (config_get_path('aliases/alias', []) as $idx => $alias) {
		if (is_array($alias) && ($alias['name'] ?? null) === $name) {
			return $idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No alias with that name.');
}

function restapi_alias_out($alias) {
	$addresses = ($alias['address'] ?? '') === '' ? array() : explode(' ', $alias['address']);
	$details = ($alias['detail'] ?? '') === '' ? array() : explode('||', $alias['detail']);
	$entries = array();
	foreach ($addresses as $i => $address) {
		$entries[] = array('address' => $address, 'detail' => $details[$i] ?? '');
	}
	$out = array(
		'name' => $alias['name'],
		'type' => $alias['type'],
		'description' => $alias['descr'] ?? '',
		'entries' => $entries,
	);
	if (in_array($alias['type'], array('url', 'url_ports', 'urltable', 'urltable_ports'), true)) {
		if (!empty($alias['url'])) {
			$out['entries'] = array(array('address' => $alias['url'], 'detail' => $details[0] ?? ''));
		} elseif (!empty($alias['aliasurl'])) {
			$out['entries'] = array_map(function ($u) {
				return array('address' => $u, 'detail' => '');
			}, (array)$alias['aliasurl']);
		}
		if (isset($alias['updatefreq'])) {
			$out['update_frequency'] = (int)$alias['updatefreq'];
		}
	}
	return $out;
}

/* Turn an API alias body into the firewall_aliases_edit.php form fields. */
function restapi_alias_post($body, $origname) {
	$type = (string)($body['type'] ?? '');
	$post = array(
		'name' => (string)($body['name'] ?? ''),
		'type' => $type,
		'descr' => (string)($body['description'] ?? ''),
		'origname' => $origname,
	);
	$entries = $body['entries'] ?? array();
	if (!is_array($entries) || !array_is_list($entries)) {
		throw new RestApiError(400, 'invalid_field', '"entries" must be a list of {address, detail}.');
	}
	foreach (array_values($entries) as $i => $entry) {
		$address = is_array($entry) ? (string)($entry['address'] ?? '') : (string)$entry;
		$subnet = '';
		if (($type === 'network') && preg_match('#^(.+)/(\d{1,3})$#', $address, $m)) {
			$address = $m[1];
			$subnet = $m[2];
		}
		if (in_array($type, array('urltable', 'urltable_ports'), true)) {
			$subnet = (string)($body['update_frequency'] ?? 7);
		}
		$post["address{$i}"] = $address;
		$post["address_subnet{$i}"] = $subnet;
		$post["detail{$i}"] = is_array($entry) ? (string)($entry['detail'] ?? '') : '';
	}
	return $post;
}

function restapi_alias_save($body, $id, $origname, $req) {
	$errors = saveAlias(restapi_alias_post($body, $origname), $id);
	if (!empty($errors)) {
		throw restapi_validation_error((array)$errors);
	}
	mark_subsystem_dirty('aliases');
	$applied = restapi_want_apply($req) ? restapi_fw_apply(array('aliases')) : null;
	$alias = config_get_path('aliases/alias/' . restapi_alias_index((string)$body['name']));
	return array('data' => restapi_alias_out($alias) + ($applied ? array('apply' => $applied) : array()));
}

function restapi_h_alias_list($req) {
	return array('data' => array_values(array_map('restapi_alias_out',
	    array_filter(config_get_path('aliases/alias', []), 'is_array'))));
}

function restapi_h_alias_get($req) {
	return array('data' => restapi_alias_out(config_get_path('aliases/alias/' .
	    restapi_alias_index($req['params']['name']))));
}

function restapi_h_alias_create($req) {
	$result = restapi_alias_save($req['body'], null, '', $req);
	$result['status'] = 201;
	return $result;
}

function restapi_h_alias_update($req) {
	$idx = restapi_alias_index($req['params']['name']);
	$current = restapi_alias_out(config_get_path("aliases/alias/{$idx}"));
	$body = $req['body'] + $current;
	return restapi_alias_save($body, $idx, $current['name'], $req);
}

function restapi_h_alias_delete($req) {
	$idx = restapi_alias_index($req['params']['name']);
	$error = deleteAlias($idx, restapi_want_apply($req));
	if (!empty($error)) {
		throw new RestApiError(409, 'in_use', html_entity_decode(strip_tags((string)$error), ENT_QUOTES));
	}
	return array('data' => array('deleted' => $req['params']['name'], 'pending' => restapi_fw_pending_list()));
}

function restapi_h_alias_apply($req) {
	return array('data' => restapi_fw_apply(array('aliases')));
}

/* ------------------------------------------------------------------ */
/* NAT port forwards                                                   */
/* ------------------------------------------------------------------ */

function restapi_natpf_rules() {
	return config_get_path('nat/rule', []);
}

/*
 * getNATRule()'s shape (as before), the stored entry's other keys (source,
 * destination, protocol, target, local-port), plus "fields" and "display"
 * like the other NAT kinds. Additive (capability "nat-v2").
 */
function restapi_natpf_out($id) {
	$p = (array)getNATRule($id);
	$raw = (array)config_get_path("nat/rule/{$id}", []);
	return array('id' => (int)$id) + $p + $raw +
	    array('fields' => restapi_natpf_form_fields($p), 'display' => restapi_nat_display_rdr($raw));
}

/*
 * The edit page names its association select "filter-rule-association"
 * (new rule: none, add associated, add unassociated, pass) or
 * "associated-rule-id" (existing rule: none, pass, a linked rule id, new).
 * The API offers the first one for both and turns it into what
 * saveNATrule() expects: on an update "add-associated" keeps the linked
 * rule the entry already has. A body that sends "associated-rule-id"
 * without "filter-rule-association" is passed on as the edit page posts it.
 */
function restapi_natpf_association(array $post, array $body, $current_aid) {
	$current_aid = (string)$current_aid;
	if (!array_key_exists('filter-rule-association', $body) && array_key_exists('associated-rule-id', $body)) {
		unset($post['filter-rule-association']);
		return $post;
	}
	if (!isset($post['filter-rule-association'])) {
		return $post;
	}
	switch ((string)$post['filter-rule-association']) {
		case 'add-associated':
			if (($current_aid !== '') && ($current_aid !== 'pass')) {
				$post['associated-rule-id'] = $current_aid;
				unset($post['filter-rule-association']);
			} else {
				$post['associated-rule-id'] = '';
			}
			break;
		case 'add-unassociated':
			/* Drops a linked rule, then adds an independent copy. */
			$post['associated-rule-id'] = '';
			break;
		case 'pass':
			$post['associated-rule-id'] = 'pass';
			break;
		case '':
			$post['associated-rule-id'] = '';
			unset($post['filter-rule-association']);
			break;
	}
	return $post;
}

/*
 * A port forward is IPv4 or IPv6. The edit page always posts the family;
 * an API body without one gets the page's default (IPv4) instead of an
 * empty value that saveNATrule() would store as is.
 */
function restapi_natpf_family(array $post) {
	if ((string)($post['ipprotocol'] ?? '') === '') {
		$post['ipprotocol'] = 'inet';
	} elseif (!in_array($post['ipprotocol'], array('inet', 'inet6'), true)) {
		throw restapi_validation_error(array(gettext('The address family must be IPv4 or IPv6.')));
	}
	return $post;
}

function restapi_natpf_save($post, $id, $req) {
	$post = restapi_natpf_family($post);
	$result = saveNATrule($post, $id);
	if (!empty($result['input_errors'])) {
		throw restapi_validation_error($result['input_errors']);
	}
	$applied = restapi_want_apply($req) ? array('apply' => array('applied' => applyNATrules() == 0,
	    'pending' => restapi_fw_pending_list())) : array();
	return $applied;
}

function restapi_h_natpf_list($req) {
	$out = array();
	foreach (array_keys(restapi_natpf_rules()) as $id) {
		$out[] = restapi_natpf_out($id);
	}
	return array('data' => $out);
}

function restapi_h_natpf_get($req) {
	return array('data' => restapi_natpf_out(restapi_index_param($req['params']['id'], restapi_natpf_rules(), 'port forward')));
}

function restapi_h_natpf_create($req) {
	$before = count(restapi_natpf_rules());
	$post = restapi_natpf_association(restapi_body_as_post($req['body']), $req['body'], '');
	$extra = restapi_natpf_save($post, null, $req);
	$rules = restapi_natpf_rules();
	/* A new rule goes after "after", or at the end. */
	$id = (isset($post['after']) && ctype_digit($post['after']) && count($rules) > $before) ?
	    (int)$post['after'] + 1 : count($rules) - 1;
	return array('status' => 201, 'data' => restapi_natpf_out($id) + $extra);
}

/*
 * Turn getNATRule()'s stored shape into the edit form's fields, the way
 * firewall_nat_edit.php preselects its srctype/dsttype/localtype selectors
 * (srctype_selected(), dsttype_selected(), localtype_selected()).
 */
function restapi_natpf_form_fields($p) {
	$post = array();
	$checkboxes = array('disabled', 'nordr', 'srcnot', 'dstnot', 'nosync');
	foreach ((array)$p as $key => $value) {
		/* Unticked checkboxes are absent from a form post; the save code tests isset(). */
		if (in_array($key, $checkboxes, true) || is_bool($value)) {
			if ($value) {
				$post[$key] = 'yes';
			}
		} elseif (is_scalar($value)) {
			$post[$key] = (string)$value;
		}
	}
	foreach (array('src' => build_srctype_list(), 'dst' => build_dsttype_list()) as $side => $special) {
		$addr = (string)($p[$side] ?? '');
		$mask = $p["{$side}mask"] ?? null;
		if (array_key_exists($addr, $special)) {
			$post["{$side}type"] = $addr;
			$post[$side] = '';
		} elseif (((($mask == 32) && (($p['ipprotocol'] ?? 'inet') === 'inet')) ||
		    (($mask == 128) && (($p['ipprotocol'] ?? '') === 'inet6')) || is_alias($addr))) {
			$post["{$side}type"] = 'single';
		} else {
			$post["{$side}type"] = 'network';
		}
	}
	if (empty($p['localtype'])) {
		$post['localtype'] = get_specialnet($p['localip'] ?? '', [SPECIALNET_IFADDR]) ? $p['localip'] : 'single';
	}
	/* The association as the API's "filter-rule-association" (see restapi_natpf_association()). */
	$aid = (string)($p['associated-rule-id'] ?? '');
	$post['filter-rule-association'] = ($aid === 'pass') ? 'pass' : (($aid !== '') ? 'add-associated' : '');
	foreach (array('created', 'updated') as $meta) {
		unset($post[$meta]);
	}
	return $post;
}

function restapi_h_natpf_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_natpf_rules(), 'port forward');
	$current = (array)getNATRule($id);
	$post = restapi_body_over($req['body'], restapi_natpf_form_fields($current));
	$post = restapi_natpf_association($post, $req['body'], $current['associated-rule-id'] ?? '');
	unset($post['after']);
	$extra = restapi_natpf_save($post, $id, $req);
	return array('data' => restapi_natpf_out($id) + $extra);
}

function restapi_h_natpf_order($req) {
	return array('data' => restapi_nat_reorder('rdr', 'nat/rule', $req['body']['order'] ?? null, null, 'applyNATrules', $req));
}

function restapi_h_natpf_toggle($req) {
	$id = restapi_index_param($req['params']['id'], restapi_natpf_rules(), 'port forward');
	$action = toggleNATrule(array('id' => $id), true);
	mark_subsystem_dirty('natconf');
	mark_subsystem_dirty('filter');
	$extra = restapi_want_apply($req) ? array('apply' => array('applied' => applyNATrules() == 0)) : array();
	return array('data' => array('id' => $id, 'state' => $action, 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_natpf_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_natpf_rules(), 'port forward');
	deleteNATrule(array('id' => $id), true);
	return array('data' => array('deleted' => $id));
}

function restapi_h_nat_apply($req) {
	$retval = applyNATrules();
	return array('data' => array('applied' => ($retval == 0), 'pending' => restapi_fw_pending_list()));
}

/* ------------------------------------------------------------------ */
/* Virtual IPs                                                         */
/* ------------------------------------------------------------------ */

function restapi_vips() {
	return config_get_path('virtualip/vip', []);
}

/*
 * A virtual IP: its stored fields (never the CARP password), "fields" (the
 * edit form fields, the PUT body) and "display" (the list page columns as
 * text). $labels are restapi_vip_interfaces(false), passed in for lists.
 */
function restapi_vip_out($id, ?array $labels = null) {
	$vip = config_get_path("virtualip/vip/{$id}", []);
	$vip = is_array($vip) ? $vip : array();
	unset($vip['password']);
	return array('id' => (int)$id) + $vip + array('fields' => restapi_vip_fields($vip),
	    'display' => restapi_vip_display($vip, $labels ?? restapi_vip_interfaces(false)));
}

/* A stored virtual IP as firewall_virtual_ip_edit.php fills its form (no password). */
function restapi_vip_fields(array $vip) {
	$fields = array();
	foreach (array('mode', 'interface', 'subnet', 'descr', 'uniqid') as $key) {
		$fields[$key] = (string)($vip[$key] ?? '');
	}
	/* Missing means the page's default (32), which saveVIP() fills in. */
	if (isset($vip['subnet_bits']) && ((string)$vip['subnet_bits'] !== '')) {
		$fields['subnet_bits'] = (string)$vip['subnet_bits'];
	}
	$fields['type'] = (string)(($vip['type'] ?? '') ?: 'single');
	if (($vip['mode'] ?? '') === 'carp') {
		foreach (array('vhid', 'advbase', 'advskew') as $key) {
			$fields[$key] = (string)($vip[$key] ?? '');
		}
	}
	/* Stored as an empty element when set; false only before a reload. */
	if (isset($vip['noexpand']) && ($vip['noexpand'] !== false)) {
		$fields['noexpand'] = 'yes';
	}
	return $fields;
}

/* The list page columns (firewall_virtual_ip.php) as text. */
function restapi_vip_display(array $vip, array $labels) {
	$modes = restapi_vip_modes();
	$mode = (string)($vip['mode'] ?? '');
	$subnet = (string)($vip['subnet'] ?? '');
	$address = $subnet;
	if (in_array(($vip['type'] ?? 'single'), array('single', 'network'), true) && !empty($vip['subnet_bits']) && ($subnet !== '')) {
		$address = "{$subnet}/{$vip['subnet_bits']}";
	}
	$if = (string)($vip['interface'] ?? '');
	return array(
		'type' => (string)($modes[$mode] ?? $mode),
		'interface' => (string)($labels[$if] ?? $if),
		'address' => $address,
		'vhid' => ($mode === 'carp') ? (string)($vip['vhid'] ?? '') : '',
		'description' => (string)($vip['descr'] ?? ''),
	);
}

/*
 * The CARP password of a save: a missing or empty one keeps the stored
 * password (GET never returns it); the confirmation defaults to it.
 */
function restapi_vip_password(array $post, array $body, array $current) {
	if (!isset($body['password']) || ((string)$body['password'] === '')) {
		if (isset($current['password'])) {
			$post['password'] = $current['password'];
		} else {
			unset($post['password']);
		}
		$post['password_confirm'] = $post['password'] ?? '';
	} elseif (!isset($body['password_confirm'])) {
		$post['password_confirm'] = $post['password'];
	}
	return $post;
}

function restapi_vip_save($post, $id, $req) {
	/* The edit page always posts IP Alias and CARP addresses as single addresses. */
	if (in_array($post['mode'] ?? '', array('ipalias', 'carp'), true)) {
		unset($post['type']);
	}
	$post['id'] = ($id === null) ? '' : (string)$id;
	/* saveVIP() only redirects when $json is false, and with $json set it
	 * skips the page's do_input_validation(); run that check here so the
	 * API validates exactly like the GUI. */
	$errors = array();
	do_input_validation($post, array('mode'), array(gettext('Type')), $errors);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$result = restapi_json_result(saveVIP($post, true));
	if (!is_array($result)) {
		throw new RestApiError(500, 'save_failed', 'The virtual IP could not be saved.');
	}
	if (!empty($result['input_errors'])) {
		throw restapi_validation_error($result['input_errors']);
	}
	$extra = restapi_want_apply($req) ? array('apply' => applyVIP() + array('pending' => restapi_fw_pending_list())) : array();
	return array($result, $extra);
}

function restapi_h_vip_list($req) {
	$labels = restapi_vip_interfaces(false);
	return array('data' => array_map(function ($id) use ($labels) {
		return restapi_vip_out($id, $labels);
	}, array_keys(restapi_vips())));
}

function restapi_h_vip_get($req) {
	return array('data' => restapi_vip_out(restapi_index_param($req['params']['id'], restapi_vips(), 'virtual IP')));
}

function restapi_h_vip_create($req) {
	$post = restapi_vip_password(restapi_body_as_post($req['body']), $req['body'], array());
	list(, $extra) = restapi_vip_save($post, null, $req);
	return array('status' => 201, 'data' => restapi_vip_out(count(restapi_vips()) - 1) + $extra);
}

function restapi_h_vip_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_vips(), 'virtual IP');
	$current = config_get_path("virtualip/vip/{$id}", []);
	$post = restapi_body_over($req['body'], restapi_vip_fields($current));
	$post = restapi_vip_password($post, $req['body'], $current);
	list(, $extra) = restapi_vip_save($post, $id, $req);
	return array('data' => restapi_vip_out($id) + $extra);
}

function restapi_h_vip_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_vips(), 'virtual IP');
	$result = restapi_json_result(deleteVIP($id, true));
	if (!empty($result['input_errors'])) {
		throw new RestApiError(409, 'in_use', implode(' ', array_map('strip_tags', $result['input_errors'])));
	}
	/* Honour ?apply=true when the delete left a VIP change pending. */
	$extra = (restapi_want_apply($req) && is_subsystem_dirty('vip')) ?
	    array('apply' => array('applied' => ((applyVIP()['retval'] ?? 1) == 0))) : array();
	return array('data' => array('deleted' => $id, 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_vip_apply($req) {
	$result = applyVIP();
	return array('data' => array('applied' => (($result['retval'] ?? 1) == 0), 'pending' => restapi_fw_pending_list()));
}

/* ------------------------------------------------------------------ */
/* Schedules                                                           */
/* ------------------------------------------------------------------ */

function restapi_sched_index($name) {
	foreach (config_get_path('schedules/schedule', []) as $idx => $sched) {
		if (is_array($sched) && ($sched['name'] ?? null) === $name) {
			return $idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No schedule with that name.');
}

/* Stored time ranges (timerange) as API ranges {weekdays | dates, start, stop, description}. */
function restapi_sched_ranges($timerange) {
	$ranges = array();
	foreach ((array)$timerange as $range) {
		if (!is_array($range)) {
			continue;
		}
		list($start, $stop) = array_pad(explode('-', (string)($range['hour'] ?? ''), 2), 2, '');
		$out = array('start' => $start, 'stop' => $stop, 'description' => $range['rangedescr'] ?? '');
		if (isset($range['position'])) {
			$out['weekdays'] = array_map('intval', explode(',', $range['position']));
		} else {
			$months = explode(',', (string)($range['month'] ?? ''));
			$days = explode(',', (string)($range['day'] ?? ''));
			$out['dates'] = array();
			foreach ($months as $i => $month) {
				$out['dates'][] = sprintf('%02d-%02d', (int)$month, (int)($days[$i] ?? 0));
			}
		}
		$ranges[] = $out;
	}
	return $ranges;
}

/*
 * A schedule: the API form (name, description, ranges), the stored fields
 * (descr, timerange, schedlabel), "fields" (the schema's editor fields, also
 * a valid PUT body) and "display" (the list page's columns as text).
 * $usage is restapi_sched_usage(), passed in for lists.
 */
function restapi_sched_out($sched, ?array $usage = null) {
	$ranges = restapi_sched_ranges($sched['timerange'] ?? array());
	$out = array('name' => $sched['name'], 'description' => $sched['descr'] ?? '', 'ranges' => $ranges);
	$out += array('descr' => (string)($sched['descr'] ?? ''), 'timerange' => array_values(array_filter((array)($sched['timerange'] ?? array()), 'is_array')));
	if (isset($sched['schedlabel'])) {
		$out['schedlabel'] = $sched['schedlabel'];
	}
	$fields = array('name' => (string)$sched['name'], 'descr' => (string)($sched['descr'] ?? ''), 'ranges' => array());
	foreach ($ranges as $r) {
		$fields['ranges'][] = array(
			'weekdays' => array_map('strval', $r['weekdays'] ?? array()),
			'dates' => implode(', ', $r['dates'] ?? array()),
			'start' => restapi_sched_time($r['start']),
			'stop' => restapi_sched_time($r['stop']),
			'description' => (string)$r['description'],
		);
	}
	$usage = $usage ?? restapi_sched_usage();
	$texts = array();
	foreach ($ranges as $r) {
		$texts[] = restapi_sched_range_text($r);
	}
	$out['fields'] = $fields;
	$out['display'] = array(
		'ranges' => $texts,
		'active' => function_exists('filter_get_time_based_rule_status') ? (bool)filter_get_time_based_rule_status($sched) : false,
		'used_by' => (int)($usage[$sched['name']] ?? 0),
		'description' => (string)($sched['descr'] ?? ''),
	);
	return $out;
}

/* "8:0" → "08:00" (as a time input shows it); anything else unchanged. */
function restapi_sched_time($t) {
	return preg_match('/^(\d{1,2}):(\d{1,2})$/', (string)$t, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : (string)$t;
}

/* How many filter rules use each schedule, by name. */
function restapi_sched_usage() {
	$usage = array();
	foreach ((function_exists('get_filter_rules_list') ? get_filter_rules_list() : array()) as $rule) {
		$name = (string)($rule['sched'] ?? '');
		if ($name !== '') {
			$usage[$name] = ($usage[$name] ?? 0) + 1;
		}
	}
	return $usage;
}

/*
 * One range as the list page summarises it: runs of weekdays ("Mon–Fri,
 * Sun") or of dates ("December 24–26"), then the times and the range
 * description: "Mon–Fri 08:00–17:00 (Office hours)".
 */
function restapi_sched_range_text(array $range) {
	$runs = function (array $values, $next) {
		$out = array();
		foreach ($values as $v) {
			$last = count($out) - 1;
			if (($last >= 0) && ($next($out[$last][1]) === $v)) {
				$out[$last][1] = $v;
			} else {
				$out[] = array($v, $v);
			}
		}
		return $out;
	};
	$parts = array();
	if (isset($range['weekdays'])) {
		$names = restapi_sched_weekdays();
		$days = array_values(array_unique(array_map('intval', $range['weekdays'])));
		sort($days);
		foreach ($runs($days, function ($d) { return $d + 1; }) as list($a, $b)) {
			$parts[] = ($names[$a] ?? $a) . (($a === $b) ? '' : '–' . ($names[$b] ?? $b));
		}
	} else {
		$months = array(1 => gettext('January'), gettext('February'), gettext('March'), gettext('April'), gettext('May'), gettext('June'),
		    gettext('July'), gettext('August'), gettext('September'), gettext('October'), gettext('November'), gettext('December'));
		$dates = array();
		foreach ((array)($range['dates'] ?? array()) as $date) {
			list($m, $d) = array_map('intval', explode('-', $date, 2) + array(1 => 0));
			$dates[] = $m * 100 + $d;
		}
		foreach ($runs($dates, function ($d) { return $d + 1; }) as list($a, $b)) {
			$month = $months[intdiv($a, 100)] ?? intdiv($a, 100);
			$parts[] = "{$month} " . ($a % 100) . (($a === $b) ? '' : '–' . ($b % 100));
		}
	}
	$text = implode(', ', $parts) . ' ' . restapi_sched_time($range['start'] ?? '') . '–' . restapi_sched_time($range['stop'] ?? '');
	$descr = (string)($range['description'] ?? '');
	return ($descr === '') ? $text : "{$text} ({$descr})";
}

/*
 * A request body in the API form: "descr" for "description", and the stored
 * "timerange" list for "ranges", so a client may send either shape.
 */
function restapi_sched_body(array $body) {
	if (!array_key_exists('description', $body) && array_key_exists('descr', $body)) {
		$body['description'] = $body['descr'];
	}
	if (!array_key_exists('ranges', $body) && array_key_exists('timerange', $body)) {
		if (!is_array($body['timerange']) || !array_is_list($body['timerange'])) {
			throw new RestApiError(400, 'invalid_field', '"timerange" must be a list.');
		}
		foreach ($body['timerange'] as $range) {
			if (!is_array($range)) {
				throw new RestApiError(400, 'invalid_field', 'Each time range must be an object.');
			}
		}
		$body['ranges'] = restapi_sched_ranges($body['timerange']);
	}
	unset($body['descr'], $body['timerange'], $body['fields'], $body['display'], $body['schedlabel']);
	return $body;
}

/* API ranges to the schedule edit page's numbered form fields. */
function restapi_sched_post($body) {
	$post = array('name' => (string)($body['name'] ?? ''), 'descr' => (string)($body['description'] ?? ''));
	$ranges = $body['ranges'] ?? array();
	if (!is_array($ranges) || !array_is_list($ranges)) {
		throw new RestApiError(400, 'invalid_field', '"ranges" must be a list.');
	}
	if (count($ranges) > 99) {
		throw new RestApiError(400, 'invalid_field', 'A schedule has at most 99 ranges.');
	}
	foreach ($ranges as $i => $range) {
		if (!is_array($range)) {
			throw new RestApiError(400, 'invalid_field', 'Each range must be an object.');
		}
		$parts = array();
		$weekdays = $range['weekdays'] ?? array();
		$weekdays = is_string($weekdays) ? preg_split('/[\s,]+/', $weekdays, -1, PREG_SPLIT_NO_EMPTY) : (array)$weekdays;
		$weekdays = array_values(array_unique(array_map('intval', array_filter($weekdays, 'is_scalar'))));
		sort($weekdays);
		foreach ($weekdays as $wd) {
			$parts[] = (string)$wd;
		}
		$dates = $range['dates'] ?? array();
		$dates = is_string($dates) ? preg_split('/[\s,]+/', $dates, -1, PREG_SPLIT_NO_EMPTY) : (array)$dates;
		foreach ($dates as $date) {
			if (!preg_match('/^(?:\d{4}-)?(\d{1,2})-(\d{1,2})$/D', (string)$date, $m)) {
				throw new RestApiError(400, 'invalid_field', "Date \"{$date}\" must be MM-DD or YYYY-MM-DD.");
			}
			/* The GUI's week/day-of-week prefix is not stored; only month and day are. */
			$parts[] = sprintf('w1p1-m%dd%d', (int)$m[1], (int)$m[2]);
		}
		$post["schedule{$i}"] = implode(',', $parts);
		$post["starttime{$i}"] = (string)($range['start'] ?? '');
		$post["stoptime{$i}"] = (string)($range['stop'] ?? '');
		$post["timedescr{$i}"] = (string)($range['description'] ?? '');
	}
	return $post;
}

function restapi_sched_save($body, $id) {
	/* Strict: also the checks the edit page makes in the browser (start before stop, name of a schedule in use). */
	$result = saveSchedule(restapi_sched_post($body), $id, true);
	if (!empty($result['input_errors'])) {
		throw restapi_validation_error($result['input_errors']);
	}
	return restapi_sched_out(config_get_path('schedules/schedule/' . restapi_sched_index((string)$body['name'])));
}

function restapi_h_sched_list($req) {
	$usage = restapi_sched_usage();
	return array('data' => array_values(array_map(function ($sched) use ($usage) {
		return restapi_sched_out($sched, $usage);
	}, array_filter(config_get_path('schedules/schedule', []), 'is_array'))));
}

function restapi_h_sched_get($req) {
	return array('data' => restapi_sched_out(config_get_path('schedules/schedule/' .
	    restapi_sched_index($req['params']['name']))));
}

function restapi_h_sched_create($req) {
	return array('status' => 201, 'data' => restapi_sched_save(restapi_sched_body($req['body']), null));
}

function restapi_h_sched_update($req) {
	$idx = restapi_sched_index($req['params']['name']);
	$sched = config_get_path("schedules/schedule/{$idx}");
	$current = array('name' => $sched['name'], 'description' => $sched['descr'] ?? '', 'ranges' => restapi_sched_ranges($sched['timerange'] ?? array()));
	return array('data' => restapi_sched_save(restapi_sched_body($req['body']) + $current, $idx));
}

function restapi_h_sched_delete($req) {
	$idx = restapi_sched_index($req['params']['name']);
	$error = deleteSchedule(array('id' => $idx), true);
	if (!empty($error)) {
		throw new RestApiError(409, 'in_use', html_entity_decode(strip_tags((string)$error), ENT_QUOTES));
	}
	return array('data' => array('deleted' => $req['params']['name']));
}

/* ------------------------------------------------------------------ */
/* Filter rules                                                        */
/* ------------------------------------------------------------------ */

function restapi_rule_out($id, $rule) {
	return array('id' => (int)$id) + $rule + array('display' => restapi_rule_display((array)$rule));
}

/*
 * A rule's list columns as text, as the 1.x rule list prints them
 * (pprint_address/pprint_port): "*" for any, interface networks by name,
 * "! " for an inverted match, well-known port names. Additive (API level 8).
 */
function restapi_rule_display(array $rule) {
	global $filter_srcdsttype_flags, $wkports;
	static $specialnets = null, $ifnames = null;
	if ($specialnets === null) {
		$specialnets = function_exists('get_specialnet') ? (array)get_specialnet('', (array)$filter_srcdsttype_flags) : array();
		/* The rule tabs' names: interfaces, groups, IPsec, OpenVPN… */
		$ifnames = function_exists('filter_get_interface_list') ? (array)filter_get_interface_list() : array();
		$ifnames += (array)get_configured_interface_with_descr();
	}
	$addr = function ($a) use ($specialnets) {
		$a = is_array($a) ? $a : array();
		if (isset($a['any']) || (($a['network'] ?? '') === 'any')) {
			$text = '*';
		} elseif (!empty($a['network'])) {
			$text = (string)($specialnets[$a['network']] ?? $a['network']);
		} else {
			$text = (string)($a['address'] ?? '');
		}
		return (isset($a['not']) ? '! ' : '') . $text;
	};
	$port = function ($a) use ($wkports) {
		$p = is_array($a) ? (string)($a['port'] ?? '') : '';
		if ($p === '') {
			return '*';
		}
		list($begin, $end) = array_pad(explode('-', $p, 2), 2, '');
		if (($end === '') || ($begin === $end)) {
			return $begin . (isset($wkports[$begin]) ? " ({$wkports[$begin]})" : '');
		}
		return "{$begin} - {$end}";
	};
	$families = array('inet' => 'IPv4', 'inet6' => 'IPv6', 'inet46' => 'IPv4+6');
	$ifs = array();
	foreach (array_filter(explode(',', (string)($rule['interface'] ?? ''))) as $if) {
		$ifs[] = ($if === 'any') ? gettext('Any') : (string)($ifnames[$if] ?? strtoupper($if));
	}
	return array(
		'tab' => isset($rule['floating']) ? 'floating' : (string)($rule['interface'] ?? ''),
		'interfaces' => implode(', ', $ifs),
		'protocol' => ($families[$rule['ipprotocol'] ?? 'inet'] ?? 'IPv4') . ' ' . (isset($rule['protocol']) ? strtoupper((string)$rule['protocol']) : '*'),
		'source' => $addr($rule['source'] ?? null),
		'source_port' => $port($rule['source'] ?? null),
		'destination' => $addr($rule['destination'] ?? null),
		'destination_port' => $port($rule['destination'] ?? null),
		'gateway' => (string)($rule['gateway'] ?? ''),
		'schedule' => (string)($rule['sched'] ?? ''),
		'enabled' => !isset($rule['disabled']),
		'log' => isset($rule['log']),
		'nat' => isset($rule['associated-rule-id']),
	);
}

/* Rule ids are positions after the same sort firewall_rules.php applies. */
function restapi_rules() {
	filter_rules_sort();
	return get_filter_rules_list();
}

function restapi_rule_result($result, $req, $status = 200) {
	if (!empty($result['input_errors'])) {
		throw restapi_validation_error($result['input_errors']);
	}
	$extra = restapi_want_apply($req) ? array('apply' => restapi_fw_apply(array('filter'))) : array();
	$rules = get_filter_rules_list();
	$id = $result['id'];
	return array('status' => $status, 'data' => (($id !== null && isset($rules[$id])) ?
	    restapi_rule_out($id, $rules[$id]) : array('id' => $id)) + $extra);
}

function restapi_h_rule_create($req) {
	restapi_rules();
	$post = restapi_body_as_post($req['body']);
	$after = $post['after'] ?? null;
	unset($post['after']);
	if (($after !== null) && !is_numericint($after) && ($after !== '-1')) {
		throw new RestApiError(400, 'invalid_field', '"after" must be a rule id or -1.');
	}
	$if = isset($post['floating']) ? 'FloatingRules' : (string)($post['interface'] ?? '');
	return restapi_rule_result(saveFilterRule($post, null, $after, $if), $req, 201);
}

function restapi_h_rule_update($req) {
	$rules = restapi_rules();
	$id = restapi_index_param($req['params']['id'], $rules, 'filter rule');
	$post = restapi_body_over($req['body'], filter_rule_form_fields(getFilterRule($id)));
	unset($post['after']);
	return restapi_rule_result(saveFilterRule($post, $id), $req);
}

function restapi_h_rule_toggle($req) {
	$rules = restapi_rules();
	$id = restapi_index_param($req['params']['id'], $rules, 'filter rule');
	$state = toggleFilterRule($id);
	$extra = restapi_want_apply($req) ? array('apply' => restapi_fw_apply(array('filter'))) : array();
	return array('data' => array('id' => $id, 'state' => $state, 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_rule_delete($req) {
	$rules = restapi_rules();
	$id = restapi_index_param($req['params']['id'], $rules, 'filter rule');
	deleteFilterRule($id, filter_rule_tab($rules[$id]));
	$extra = restapi_want_apply($req) ? array('apply' => restapi_fw_apply(array('filter'))) : array();
	return array('data' => array('deleted' => $id, 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_rule_order($req) {
	$rules = restapi_rules();
	$tab = strtolower((string)($req['body']['interface'] ?? ''));
	$tab = ($tab === 'floating') ? 'FloatingRules' : $tab;
	$order = $req['body']['order'] ?? null;
	if (!is_array($order) || !array_is_list($order)) {
		throw new RestApiError(400, 'invalid_field', '"order" must be a list of rule ids.');
	}
	$ids = array();
	foreach ($rules as $id => $rule) {
		if (strcasecmp(filter_rule_tab($rule), $tab) === 0) {
			$ids[] = $id;
		}
	}
	$given = array_map('intval', $order);
	$sorted_given = $given;
	sort($sorted_given);
	if (empty($ids) || ($sorted_given !== $ids)) {
		throw new RestApiError(422, 'validation_failed', 'The request failed validation.', array('messages' =>
		    array("\"order\" must list every rule id of interface \"{$tab}\" exactly once: " . implode(', ', $ids))));
	}
	/* Keep the tab's separators as they are; the GUI sends them with every reorder. */
	$separators = config_get_path('filter/separator/' . strtolower($tab), []);
	$changed = reorderFilterRules($given, is_array($separators) ? array_values($separators) : null,
	    ($tab === 'FloatingRules') ? 'FloatingRules' : $tab);
	$extra = restapi_want_apply($req) ? array('apply' => restapi_fw_apply(array('filter'))) : array();
	return array('data' => array('changed' => (bool)$changed, 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_rule_list($req) {
	restapi_rules();
	$want = strtolower((string)($req['query']['interface'] ?? ''));
	$out = array();
	foreach (get_filter_rules_list() as $id => $rule) {
		if ($want !== '') {
			$floating = isset($rule['floating']);
			if (($want === 'floating') !== $floating) {
				continue;
			}
			if (!$floating && (strtolower((string)($rule['interface'] ?? '')) !== $want)) {
				continue;
			}
		}
		$out[] = restapi_rule_out($id, $rule);
	}
	return array('data' => $out);
}

/* The 1.x rule list tabs: Floating, then filter_get_interface_list(), as [{id, label, count}]. */
function restapi_h_rule_tabs($req) {
	$counts = array();
	foreach (restapi_rules() as $rule) {
		$tab = isset($rule['floating']) ? 'floating' : (string)($rule['interface'] ?? '');
		$counts[$tab] = ($counts[$tab] ?? 0) + 1;
	}
	$tabs = array(array('id' => 'floating', 'label' => gettext('Floating'), 'count' => $counts['floating'] ?? 0));
	foreach (filter_get_interface_list() as $id => $label) {
		$tabs[] = array('id' => (string)$id, 'label' => (string)$label, 'count' => $counts[$id] ?? 0);
	}
	return array('data' => $tabs);
}

function restapi_h_rule_get($req) {
	$rules = restapi_rules();
	$id = restapi_index_param($req['params']['id'], $rules, 'filter rule');
	return array('data' => restapi_rule_out($id, $rules[$id]) +
	    array('fields' => filter_rule_form_fields(getFilterRule($id))));
}

function restapi_h_rule_apply($req) {
	return array('data' => restapi_fw_apply(array('filter')));
}
