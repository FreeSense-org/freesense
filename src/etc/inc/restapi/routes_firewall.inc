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
	    'dstbeginport, dstendport, localtype, localip, localbeginport, natreflection, descr, disabled, nordr, ' .
	    'filter-rule-association, after). GET returns the same shape.');
	$vip_body = array('type' => 'object', 'description' => 'The same fields as the virtual IP edit page ' .
	    '(mode, interface, subnet, subnet_bits, type, descr, vhid, advbase, advskew, password, noexpand).');
	$sched_body = array('type' => 'object', 'properties' => array(
		'name' => array('type' => 'string'),
		'description' => array('type' => 'string'),
		'ranges' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
		    'weekdays' => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => '1 = Monday ... 7 = Sunday'),
		    'dates' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => '"MM-DD" or "YYYY-MM-DD"'),
		    'start' => array('type' => 'string', 'description' => 'HH:MM'),
		    'stop' => array('type' => 'string', 'description' => 'HH:MM'),
		    'description' => array('type' => 'string')))),
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

		/* Aliases */
		restapi_route('GET', '/v1/firewall/aliases', 'restapi_h_alias_list', array(
			'page' => 'firewall_aliases.php', 'area' => 'firewall.aliases', 'summary' => 'List aliases')),
		restapi_route('GET', '/v1/firewall/aliases/{name}', 'restapi_h_alias_get', array(
			'page' => 'firewall_aliases.php', 'area' => 'firewall.aliases', 'summary' => 'Get an alias')),
		restapi_route('POST', '/v1/firewall/aliases', 'restapi_h_alias_create', array(
			'page' => 'firewall_aliases_edit.php', 'area' => 'firewall.aliases', 'write' => true,
			'body' => $alias_body, 'query' => $apply_query, 'summary' => 'Create an alias')),
		restapi_route('PUT', '/v1/firewall/aliases/{name}', 'restapi_h_alias_update', array(
			'page' => 'firewall_aliases_edit.php', 'area' => 'firewall.aliases', 'write' => true,
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
		restapi_route('GET', '/v1/firewall/nat/port-forwards/{id}', 'restapi_h_natpf_get', array(
			'page' => 'firewall_nat.php', 'area' => 'firewall.nat', 'summary' => 'Get a port forward')),
		restapi_route('POST', '/v1/firewall/nat/port-forwards', 'restapi_h_natpf_create', array(
			'page' => 'firewall_nat_edit.php', 'area' => 'firewall.nat', 'write' => true,
			'body' => $natpf_body, 'query' => $apply_query, 'summary' => 'Create a port forward')),
		restapi_route('PUT', '/v1/firewall/nat/port-forwards/{id}', 'restapi_h_natpf_update', array(
			'page' => 'firewall_nat_edit.php', 'area' => 'firewall.nat', 'write' => true,
			'body' => $natpf_body, 'query' => $apply_query,
			'summary' => 'Update a port forward (omitted fields keep their value)')),
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
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips', 'summary' => 'List virtual IPs')),
		restapi_route('GET', '/v1/firewall/virtual-ips/{id}', 'restapi_h_vip_get', array(
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips', 'summary' => 'Get a virtual IP')),
		restapi_route('POST', '/v1/firewall/virtual-ips', 'restapi_h_vip_create', array(
			'page' => 'firewall_virtual_ip_edit.php', 'area' => 'firewall.vips', 'write' => true,
			'body' => $vip_body, 'query' => $apply_query, 'summary' => 'Create a virtual IP')),
		restapi_route('PUT', '/v1/firewall/virtual-ips/{id}', 'restapi_h_vip_update', array(
			'page' => 'firewall_virtual_ip_edit.php', 'area' => 'firewall.vips', 'write' => true,
			'body' => $vip_body, 'query' => $apply_query,
			'summary' => 'Update a virtual IP (omitted fields keep their value)')),
		restapi_route('DELETE', '/v1/firewall/virtual-ips/{id}', 'restapi_h_vip_delete', array(
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips', 'write' => true,
			'summary' => 'Delete a virtual IP that nothing uses (applies at once)')),
		restapi_route('POST', '/v1/firewall/virtual-ips/apply', 'restapi_h_vip_apply', array(
			'page' => 'firewall_virtual_ip.php', 'area' => 'firewall.vips', 'write' => true,
			'summary' => 'Apply pending virtual IP changes')),

		/* Schedules */
		restapi_route('GET', '/v1/firewall/schedules', 'restapi_h_sched_list', array(
			'page' => 'firewall_schedule.php', 'area' => 'firewall.schedules', 'summary' => 'List schedules')),
		restapi_route('GET', '/v1/firewall/schedules/{name}', 'restapi_h_sched_get', array(
			'page' => 'firewall_schedule.php', 'area' => 'firewall.schedules', 'summary' => 'Get a schedule')),
		restapi_route('POST', '/v1/firewall/schedules', 'restapi_h_sched_create', array(
			'page' => 'firewall_schedule_edit.php', 'area' => 'firewall.schedules', 'write' => true,
			'body' => $sched_body, 'summary' => 'Create a schedule (applies at once, like the GUI)')),
		restapi_route('PUT', '/v1/firewall/schedules/{name}', 'restapi_h_sched_update', array(
			'page' => 'firewall_schedule_edit.php', 'area' => 'firewall.schedules', 'write' => true,
			'body' => $sched_body, 'summary' => 'Replace a schedule\'s ranges (applies at once)')),
		restapi_route('DELETE', '/v1/firewall/schedules/{name}', 'restapi_h_sched_delete', array(
			'page' => 'firewall_schedule.php', 'area' => 'firewall.schedules', 'write' => true,
			'summary' => 'Delete a schedule that no rule uses')),

		/* Filter rules */
		restapi_route('GET', '/v1/firewall/rules', 'restapi_h_rule_list', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules',
			'query' => array('interface' => 'Only rules of this interface (e.g. lan, wan, or "floating")'),
			'summary' => 'List filter rules in evaluation order')),
		restapi_route('GET', '/v1/firewall/rules/{id}', 'restapi_h_rule_get', array(
			'page' => 'firewall_rules.php', 'area' => 'firewall.rules',
			'summary' => 'Get a filter rule (stored form, plus "fields": the edit form fields)')),
		restapi_route('POST', '/v1/firewall/rules', 'restapi_h_rule_create', array(
			'page' => 'firewall_rules_edit.php', 'area' => 'firewall.rules', 'write' => true,
			'body' => $rule_body, 'query' => $apply_query, 'summary' => 'Create a filter rule')),
		restapi_route('PUT', '/v1/firewall/rules/{id}', 'restapi_h_rule_update', array(
			'page' => 'firewall_rules_edit.php', 'area' => 'firewall.rules', 'write' => true,
			'body' => $rule_body, 'query' => $apply_query,
			'summary' => 'Update a filter rule (omitted fields keep their value)')),
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

function restapi_natpf_out($id) {
	return array('id' => (int)$id) + (array)getNATRule($id);
}

function restapi_natpf_save($post, $id, $req) {
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
	$post = restapi_body_as_post($req['body']);
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
	foreach (array('created', 'updated') as $meta) {
		unset($post[$meta]);
	}
	return $post;
}

function restapi_h_natpf_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_natpf_rules(), 'port forward');
	$post = restapi_body_as_post($req['body']) + restapi_natpf_form_fields(getNATRule($id));
	unset($post['after']);
	$extra = restapi_natpf_save($post, $id, $req);
	return array('data' => restapi_natpf_out($id) + $extra);
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

function restapi_vip_out($id) {
	$vip = config_get_path("virtualip/vip/{$id}", []);
	unset($vip['password']);
	return array('id' => (int)$id) + $vip;
}

function restapi_vip_save($post, $id, $req) {
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
	return array('data' => array_map('restapi_vip_out', array_keys(restapi_vips())));
}

function restapi_h_vip_get($req) {
	return array('data' => restapi_vip_out(restapi_index_param($req['params']['id'], restapi_vips(), 'virtual IP')));
}

function restapi_h_vip_create($req) {
	list(, $extra) = restapi_vip_save(restapi_body_as_post($req['body']), null, $req);
	return array('status' => 201, 'data' => restapi_vip_out(count(restapi_vips()) - 1) + $extra);
}

function restapi_h_vip_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_vips(), 'virtual IP');
	$current = config_get_path("virtualip/vip/{$id}", []);
	$post = restapi_body_as_post($req['body']) + array_filter($current, 'is_scalar');
	if (!isset($req['body']['password']) && isset($current['password'])) {
		$post['password'] = $current['password'];
	}
	$post['password_confirm'] = $post['password'] ?? '';
	list(, $extra) = restapi_vip_save($post, $id, $req);
	return array('data' => restapi_vip_out($id) + $extra);
}

function restapi_h_vip_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_vips(), 'virtual IP');
	$result = restapi_json_result(deleteVIP($id, true));
	if (!empty($result['input_errors'])) {
		throw new RestApiError(409, 'in_use', implode(' ', array_map('strip_tags', $result['input_errors'])));
	}
	return array('data' => array('deleted' => $id));
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

function restapi_sched_out($sched) {
	$ranges = array();
	foreach ((array)($sched['timerange'] ?? array()) as $range) {
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
	return array('name' => $sched['name'], 'description' => $sched['descr'] ?? '', 'ranges' => $ranges);
}

/* API ranges to the schedule edit page's numbered form fields. */
function restapi_sched_post($body) {
	$post = array('name' => (string)($body['name'] ?? ''), 'descr' => (string)($body['description'] ?? ''));
	$ranges = $body['ranges'] ?? array();
	if (!is_array($ranges) || !array_is_list($ranges)) {
		throw new RestApiError(400, 'invalid_field', '"ranges" must be a list.');
	}
	foreach ($ranges as $i => $range) {
		if (!is_array($range)) {
			throw new RestApiError(400, 'invalid_field', 'Each range must be an object.');
		}
		$parts = array();
		foreach ((array)($range['weekdays'] ?? array()) as $wd) {
			$parts[] = (string)(int)$wd;
		}
		foreach ((array)($range['dates'] ?? array()) as $date) {
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
	$result = saveSchedule(restapi_sched_post($body), $id);
	if (!empty($result['input_errors'])) {
		throw restapi_validation_error($result['input_errors']);
	}
	return restapi_sched_out(config_get_path('schedules/schedule/' . restapi_sched_index((string)$body['name'])));
}

function restapi_h_sched_list($req) {
	return array('data' => array_values(array_map('restapi_sched_out',
	    array_filter(config_get_path('schedules/schedule', []), 'is_array'))));
}

function restapi_h_sched_get($req) {
	return array('data' => restapi_sched_out(config_get_path('schedules/schedule/' .
	    restapi_sched_index($req['params']['name']))));
}

function restapi_h_sched_create($req) {
	return array('status' => 201, 'data' => restapi_sched_save($req['body'], null));
}

function restapi_h_sched_update($req) {
	$idx = restapi_sched_index($req['params']['name']);
	$current = restapi_sched_out(config_get_path("schedules/schedule/{$idx}"));
	return array('data' => restapi_sched_save($req['body'] + $current, $idx));
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
	return array('id' => (int)$id) + $rule;
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
	$post = restapi_body_as_post($req['body']) + filter_rule_form_fields(getFilterRule($id));
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

function restapi_h_rule_get($req) {
	$rules = restapi_rules();
	$id = restapi_index_param($req['params']['id'], $rules, 'filter rule');
	return array('data' => restapi_rule_out($id, $rules[$id]) +
	    array('fields' => filter_rule_form_fields(getFilterRule($id))));
}

function restapi_h_rule_apply($req) {
	return array('data' => restapi_fw_apply(array('filter')));
}
