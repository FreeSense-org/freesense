<?php
/*
 * restapi/routes_interfaces.inc
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
 * REST API v1 interface routes: VLANs, VXLANs, GIF and GRE tunnels, LAGGs,
 * QinQs, interface groups, bridges and interface assignments.
 *
 * Writes go through the functions the Interfaces pages call
 * (interfaces_tunnels.inc, interfaces_l2.inc, interfaces_assign.inc), so
 * validation and in-use checks are the GUI's. Like the GUI, a change takes
 * effect at once: the interface is created, recreated or destroyed (or
 * assigned and reconfigured) by the request itself (there is no apply step).
 *
 * Entries are addressed by their position in config.xml, like the GUI, or
 * by their interface name (em1.100, vxlan0, gif0, gre0, lagg0, em1.3998,
 * bridge0); interface groups by their name, assignments by interface name
 * (wan, lan, opt1). Assignment changes also require {"confirm": true}.
 *
 * The front controller loads interfaces_tunnels.inc, interfaces_l2.inc and
 * interfaces_assign.inc at file scope.
 */

require_once('restapi/framework.inc');

/*
 * The resources: config path, interface name key, GUI pages, the edit
 * form's fields (checkboxes, multi-selects and per-interface value maps
 * separately) and what a new form posts.
 */
function restapi_iftun_types() {
	return array(
		'vlans' => array(
			'label' => 'VLAN', 'config' => 'vlans/vlan', 'ifkey' => 'vlanif',
			'list' => 'interfaces_vlan.php', 'edit' => 'interfaces_vlan_edit.php',
			'fields' => array('if', 'tag_type', 'tag', 'pcp', 'descr'),
			'checkboxes' => array(),
			'defaults' => array('tag_type' => 'ctag'),
		),
		'vxlans' => array(
			'label' => 'VXLAN', 'config' => 'vxlans/vxlan', 'ifkey' => 'vxlanif',
			'list' => 'interfaces_vxlan.php', 'edit' => 'interfaces_vxlan_edit.php',
			'fields' => array('if', 'ipproto', 'mode', 'vni', 'remote-addr', 'mcastgroup', 'localport',
			    'remoteport', 'ttl', 'descr'),
			'checkboxes' => array('learn', 'allowrule'),
			'defaults' => array('ipproto' => 'inet', 'mode' => 'unicast', 'learn' => 'yes'),
		),
		'gifs' => array(
			'label' => 'GIF tunnel', 'config' => 'gifs/gif', 'ifkey' => 'gifif',
			'list' => 'interfaces_gif.php', 'edit' => 'interfaces_gif_edit.php',
			'fields' => array('if', 'remote-addr', 'tunnel-local-addr', 'tunnel-remote-addr', 'tunnel-remote-net', 'descr'),
			'checkboxes' => array('link1', 'link2'),
			'defaults' => array(),
		),
		'gres' => array(
			'label' => 'GRE tunnel', 'config' => 'gres/gre', 'ifkey' => 'greif',
			'list' => 'interfaces_gre.php', 'edit' => 'interfaces_gre_edit.php',
			'fields' => array('if', 'remote-addr', 'tunnel-local-addr', 'tunnel-remote-addr', 'tunnel-remote-net',
			    'tunnel-local-addr6', 'tunnel-remote-addr6', 'tunnel-remote-net6', 'descr'),
			'checkboxes' => array('link1'),
			/* The subnet selects post their first choice when left alone. */
			'defaults' => array('tunnel-remote-net' => '32', 'tunnel-remote-net6' => '128'),
		),
		'laggs' => array(
			'label' => 'LAGG', 'config' => 'laggs/lagg', 'ifkey' => 'laggif',
			'list' => 'interfaces_lagg.php', 'edit' => 'interfaces_lagg_edit.php',
			'fields' => array('proto', 'failovermaster', 'lacptimeout', 'lagghash', 'descr'),
			'lists' => array('members'),
			'checkboxes' => array(),
			/* The selects post their first choice when left alone. */
			'defaults' => array('proto' => 'none', 'failovermaster' => 'auto', 'lacptimeout' => 'slow', 'lagghash' => 'l2,l3,l4'),
		),
		'qinqs' => array(
			'label' => 'QinQ', 'config' => 'qinqs/qinqentry', 'ifkey' => 'vlanif',
			'list' => 'interfaces_qinq.php', 'edit' => 'interfaces_qinq_edit.php',
			'fields' => array('if', 'tag_type', 'tag', 'descr'),
			/* the member tag rows (member0, member1, ...): tags or ranges such as "10-20" */
			'lists' => array('members'),
			'checkboxes' => array('autogroup'),
			'defaults' => array('tag_type' => 'stag'),
		),
		'groups' => array(
			'label' => 'interface group', 'config' => 'ifgroups/ifgroupentry', 'ifkey' => 'ifname',
			'list' => 'interfaces_groups.php', 'edit' => 'interfaces_groups_edit.php',
			'fields' => array('ifname', 'descr'),
			'lists' => array('members'),
			'checkboxes' => array(),
			'defaults' => array(),
		),
		'bridges' => array(
			'label' => 'bridge', 'config' => 'bridges/bridged', 'ifkey' => 'bridgeif',
			'list' => 'interfaces_bridge.php', 'edit' => 'interfaces_bridge_edit.php',
			'fields' => array('descr', 'maxaddr', 'timeout', 'maxage', 'fwdelay', 'hellotime', 'priority', 'proto', 'holdcnt'),
			'lists' => array('members', 'span', 'edge', 'autoedge', 'ptp', 'autoptp', 'static', 'private', 'stp'),
			'checkboxes' => array('ip6linklocal', 'enablestp'),
			/* Per-interface STP values {"lan": "128"}; the form names their fields "lan" and "lan0". */
			'maps' => array('ifpriority' => '', 'ifpathcost' => '0'),
			'defaults' => array('proto' => 'rstp'),
		),
	);
}

function restapi_routes_interfaces() {
	$bodies = array(
		'vlans' => 'The same fields as the VLAN edit page: if (parent interface, e.g. em1), tag (1-4094), ' .
		    'tag_type (ctag|stag, default ctag), pcp (priority 0-7), descr.',
		'vxlans' => 'The same fields as the VXLAN edit page: if (parent interface or VIP), ipproto (inet|inet6, ' .
		    'default inet), mode (unicast|multicast, default unicast), vni, remote-addr (unicast) or mcastgroup ' .
		    '(multicast), localport, remoteport, ttl, learn (boolean, default true), allowrule (boolean), descr.',
		'gifs' => 'The same fields as the GIF edit page: if (parent interface or VIP), remote-addr, tunnel-local-addr, ' .
		    'tunnel-remote-addr, tunnel-remote-net (bits), link1 (ECN friendly), link2 (no outer source filtering), descr.',
		'gres' => 'The same fields as the GRE edit page: if (parent interface or VIP), remote-addr, tunnel-local-addr, ' .
		    'tunnel-remote-addr, tunnel-remote-net (bits, default 32), tunnel-local-addr6, tunnel-remote-addr6, ' .
		    'tunnel-remote-net6 (bits, default 128), link1 (add static route), descr.',
		'laggs' => 'The same fields as the LAGG edit page: members (list of unassigned ports, e.g. ["igb2","igb3"]), ' .
		    'proto (none|lacp|failover|loadbalance|roundrobin, default none), failovermaster (a member or auto), ' .
		    'lacptimeout (slow|fast), lagghash (l2,l3,l4|l2|l3|l4|l2,l3|l3,l4|l2,l4), descr. ' .
		    'Recreating a LAGG also recreates its VLANs.',
		'qinqs' => 'The same fields as the QinQ edit page: if (parent interface), tag (first level tag; ' .
		    'parent and tag cannot change later), tag_type (ctag|stag, default stag), members (list of inner ' .
		    'tags or ranges, e.g. ["10","20-30"]), autogroup (boolean: add to the QinQ interface group), descr.',
		'groups' => 'The same fields as the interface group edit page: ifname (group name, letters, digits and _, ' .
		    'at most 15, no leading or trailing digit), members (list of assigned interfaces, e.g. ["lan","opt1"]), ' .
		    'descr. Renaming a group also renames it in the firewall rules and port forwards that use it.',
		'bridges' => 'The same fields as the bridge edit page: members (list of assigned interfaces), descr, ' .
		    'maxaddr, timeout, span, edge, autoedge, ptp, autoptp, static, private (lists of interfaces), ' .
		    'ip6linklocal (boolean), enablestp (boolean), proto (rstp|stp, default rstp), stp (list), maxage, ' .
		    'fwdelay, hellotime, priority, holdcnt, ifpriority and ifpathcost (objects of interface => value).',
	);
	$routes = array();
	foreach (restapi_iftun_types() as $res => $t) {
		$base = "/v1/interfaces/{$res}";
		$label = $t['label'];
		$a = preg_match('/^[aeiou]/i', $label) ? 'an' : 'a';
		$body = array('type' => 'object', 'description' => $bodies[$res] .
		    ' Checkboxes are booleans. GET returns the current values as "fields".');
		if ($res === 'groups') {
			$get = "Get {$a} {$label} by position or name";
			$create = "Create {$a} {$label} (applied at once)";
			$delete = "Delete {$a} {$label} that no firewall or NAT rule uses";
		} else {
			$get = "Get {$a} {$label} by position or interface name";
			$create = "Create {$a} {$label} (the interface is created at once)";
			$delete = "Delete {$a} {$label} that is not in use (the interface is destroyed at once)";
		}
		$routes[] = restapi_route('GET', $base, 'restapi_h_iftun_list', array(
			'page' => $t['list'], 'area' => 'interfaces', 'summary' => "List {$label}s"));
		$routes[] = restapi_route('GET', "{$base}/{id}", 'restapi_h_iftun_get', array(
			'page' => $t['list'], 'area' => 'interfaces', 'summary' => $get));
		$routes[] = restapi_route('POST', $base, 'restapi_h_iftun_create', array(
			'page' => $t['edit'], 'area' => 'interfaces', 'write' => true, 'body' => $body,
			'summary' => $create));
		$routes[] = restapi_route('PUT', "{$base}/{id}", 'restapi_h_iftun_update', array(
			'page' => $t['edit'], 'area' => 'interfaces', 'write' => true, 'body' => $body,
			'summary' => "Update {$a} {$label} (omitted fields keep their value; applied at once)"));
		$routes[] = restapi_route('DELETE', "{$base}/{id}", 'restapi_h_iftun_delete', array(
			'page' => $t['list'], 'area' => 'interfaces', 'write' => true, 'summary' => $delete));
	}
	return array_merge($routes, restapi_routes_ifassign());
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* The resource ("vlans", ...) of a request path such as /v1/interfaces/vlans/3. */
function restapi_iftun_resource($path) {
	if (preg_match('#^/v1/interfaces/([a-z]+)(/|$)#', (string)$path, $m) && isset(restapi_iftun_types()[$m[1]])) {
		return $m[1];
	}
	throw new RestApiError(404, 'not_found', 'No such API endpoint.');
}

/* The kernel interface name of a stored entry (the name of a group). */
function restapi_iftun_ifname($res, array $entry) {
	$t = restapi_iftun_types()[$res];
	$name = (string)($entry[$t['ifkey']] ?? '');
	if (($name === '') && in_array($res, array('vlans', 'qinqs'), true) && isset($entry['if'], $entry['tag'])) {
		$name = "{$entry['if']}.{$entry['tag']}";
	}
	return $name;
}

/* A stored flag: present and not false (an in-memory entry may hold false right after a save). */
function restapi_iftun_flag(array $entry, $key) {
	return isset($entry[$key]) && ($entry[$key] !== false);
}

/* A separated list as stored ("em2,em3", "10 11") as a list without empty items. */
function restapi_iftun_split($value, $separator) {
	return array_values(array_filter(explode($separator, is_scalar($value) ? (string)$value : ''), 'strlen'));
}

/*
 * The edit form's fields for a stored entry, preselected the way the edit
 * page does: checkboxes are "yes" when ticked and absent otherwise, a GIF
 * sent from an address carries it as "parent|address", an empty select
 * posts its first choice. Multi-selects are lists; the bridge's
 * per-interface STP values are objects.
 */
function restapi_iftun_fields($res, array $entry) {
	$t = restapi_iftun_types()[$res];
	$post = array();
	foreach ($t['fields'] as $field) {
		$value = $entry[$field] ?? '';
		$post[$field] = is_scalar($value) ? (string)$value : '';
	}
	switch ($res) {
	case 'vlans':
		if ($post['tag_type'] === '') {
			$post['tag_type'] = 'ctag';
		}
		break;
	case 'vxlans':
		if (!isset($entry['nolearn'])) {
			$post['learn'] = 'yes';
		}
		if (isset($entry['allowrule'])) {
			$post['allowrule'] = 'yes';
		}
		break;
	case 'gifs':
		if (!empty($entry['ipaddr'])) {
			$post['if'] .= '|' . $entry['ipaddr'];
		}
		foreach (array('link1', 'link2') as $flag) {
			if (isset($entry[$flag])) {
				$post[$flag] = 'yes';
			}
		}
		break;
	case 'gres':
		foreach ($t['defaults'] as $field => $value) {
			if ($post[$field] === '') {
				$post[$field] = $value;
			}
		}
		if (isset($entry['link1'])) {
			$post['link1'] = 'yes';
		}
		break;
	case 'laggs':
		$post['members'] = restapi_iftun_split($entry['members'] ?? '', ',');
		foreach ($t['defaults'] as $field => $value) {
			if ($post[$field] === '') {
				$post[$field] = $value;
			}
		}
		break;
	case 'qinqs':
		/* the edit page preselects the stored tag type; S-Tag when there is none */
		if (!isset($entry['tag_type'])) {
			$post['tag_type'] = 'stag';
		} elseif (!in_array($post['tag_type'], array('ctag', 'stag'), true)) {
			$post['tag_type'] = 'ctag';
		}
		$post['members'] = restapi_iftun_split($entry['members'] ?? '', ' ');
		if (restapi_iftun_flag($entry, 'autogroup')) {
			$post['autogroup'] = 'yes';
		}
		break;
	case 'groups':
		$post['members'] = restapi_iftun_split($entry['members'] ?? '', ' ');
		break;
	case 'bridges':
		foreach ($t['lists'] as $field) {
			$post[$field] = restapi_iftun_split($entry[$field] ?? '', ',');
		}
		foreach ($t['checkboxes'] as $flag) {
			if (restapi_iftun_flag($entry, $flag)) {
				$post[$flag] = 'yes';
			}
		}
		if (!in_array($post['proto'], array('rstp', 'stp'), true)) {
			$post['proto'] = 'rstp';
		}
		foreach (array_keys($t['maps']) as $map) {
			$post[$map] = array();
			foreach (restapi_iftun_split($entry[$map] ?? '', ',') as $pair) {
				list($ifn, $value) = array_pad(explode(':', $pair, 2), 2, '');
				$post[$map][$ifn] = $value;
			}
		}
		break;
	}
	return $post;
}

/*
 * The form post for a create ($current null: what a new form posts) or an
 * update (the body over the current fields; a checkbox sent as false is
 * unticked). Every text field is present, like a browser post; an empty
 * multi-select is absent. A list may also be sent as a string separated by
 * commas or spaces. QinQ members become the member0, member1, ... rows;
 * bridge ifpriority/ifpathcost objects become the per-interface fields.
 */
function restapi_iftun_post($res, array $body, $current = null) {
	$t = restapi_iftun_types()[$res];
	if ($current === null) {
		$current = $t['defaults'];
	}
	$maps = $t['maps'] ?? array();
	$mapbody = array();
	foreach (array_keys($maps) as $map) {
		if (array_key_exists($map, $body)) {
			if (!is_array($body[$map]) || (!empty($body[$map]) && array_is_list($body[$map]))) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$map}\" must be an object of interface => value.");
			}
			$mapbody[$map] = $body[$map];
			unset($body[$map]);
		}
	}
	$post = restapi_body_as_post($body) + $current;
	foreach ($body as $key => $value) {
		if ($value === false) {
			unset($post[$key]);
		}
	}
	foreach ($t['lists'] ?? array() as $field) {
		if (isset($post[$field]) && !is_array($post[$field])) {
			$post[$field] = preg_split('/[\s,]+/', (string)$post[$field], -1, PREG_SPLIT_NO_EMPTY);
		}
		if (empty($post[$field])) {
			unset($post[$field]);
		} else {
			$post[$field] = array_values(array_map('strval', $post[$field]));
		}
	}
	foreach ($t['fields'] as $field) {
		if (!isset($post[$field])) {
			$post[$field] = '';
		} elseif (is_array($post[$field])) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be a string or number.");
		}
	}
	if ($res === 'qinqs') {
		foreach (array_keys($post) as $key) {
			if (preg_match('/^member[0-9]+$/', (string)$key)) {
				unset($post[$key]);
			}
		}
		foreach ($post['members'] ?? array() as $i => $tag) {
			$post["member{$i}"] = $tag;
		}
		unset($post['members']);
	}
	if (!empty($maps)) {
		$known = array_merge($t['fields'], $t['lists'] ?? array(), $t['checkboxes'], array_keys($maps), array('id', 'save'));
		foreach ($maps as $map => $suffix) {
			$values = ($mapbody[$map] ?? array()) + (is_array($post[$map] ?? null) ? $post[$map] : array());
			unset($post[$map]);
			foreach ($values as $ifn => $value) {
				if (!preg_match('/^[a-z][a-z0-9_]*$/i', (string)$ifn) || in_array($ifn . $suffix, $known, true)) {
					throw new RestApiError(400, 'invalid_field', "\"{$ifn}\" in \"{$map}\" is not an interface name.");
				}
				if (!is_scalar($value) && ($value !== null)) {
					throw new RestApiError(400, 'invalid_field', "The \"{$map}\" value of \"{$ifn}\" must be a string or number.");
				}
				$post[$ifn . $suffix] = is_bool($value) ? '' : (string)$value;
			}
		}
	}
	return $post;
}

/* ------------------------------------------------------------------ */
/* Handlers                                                            */
/* ------------------------------------------------------------------ */

function restapi_iftun_entries($res) {
	$list = config_get_path(restapi_iftun_types()[$res]['config'], []);
	return is_array($list) ? $list : array();
}

/* Position of the entry named by {id}: a position or an interface (group) name. */
function restapi_iftun_index($res, $ref) {
	$entries = restapi_iftun_entries($res);
	$label = restapi_iftun_types()[$res]['label'];
	if (ctype_digit((string)$ref)) {
		return restapi_index_param($ref, $entries, $label);
	}
	foreach ($entries as $idx => $entry) {
		if (is_array($entry) && (restapi_iftun_ifname($res, $entry) === (string)$ref)) {
			return $idx;
		}
	}
	$what = ($res === 'groups') ? 'name' : 'interface name';
	throw new RestApiError(404, 'not_found', "No {$label} with that id or {$what}.");
}

/* The current form fields of an entry, as the edit page preselects them. */
function restapi_iftun_current($res, array $entry) {
	$current = restapi_iftun_fields($res, $entry);
	if ($res === 'groups') {
		/* the edit page drops members that no longer exist (upstream issue 15778) */
		$current['members'] = interfaces_group_valid_members($current['members']);
	}
	return $current;
}

function restapi_iftun_out($res, $idx) {
	$entry = (array)config_get_path(restapi_iftun_types()[$res]['config'] . "/{$idx}", []);
	$ifname = restapi_iftun_ifname($res, $entry);
	if ($res === 'groups') {
		return array(
			'id' => (int)$idx,
			'name' => $ifname,
			'descr' => (string)($entry['descr'] ?? ''),
			'used_by' => interfaces_group_users($ifname),
			'config' => $entry,
			'fields' => restapi_iftun_fields($res, $entry),
		);
	}
	$assigned = ($ifname === '') ? null : convert_real_interface_to_friendly_interface_name($ifname);
	return array(
		'id' => (int)$idx,
		'interface' => $ifname,
		'assigned_as' => empty($assigned) ? null : $assigned,
		'present' => ($ifname !== '') && does_interface_exist($ifname),
		'descr' => (string)($entry['descr'] ?? ''),
		'config' => $entry,
		'fields' => restapi_iftun_fields($res, $entry),
	);
}

/* Run the GUI save function of a resource. */
function restapi_iftun_save($res, array $post, $id, $req) {
	switch ($res) {
	case 'vlans':
		return interfaces_vlan_save($post, $id, userHasPrivilege($req['user'], 'user-config-readonly'));
	case 'vxlans':
		return interfaces_vxlan_save($post, $id);
	case 'gifs':
		return interfaces_gif_save($post, $id);
	case 'gres':
		return interfaces_gre_save($post, $id);
	case 'laggs':
		return interfaces_lagg_save($post, $id);
	case 'qinqs':
		return interfaces_qinq_save($post, $id, userHasPrivilege($req['user'], 'user-config-readonly'));
	case 'groups':
		return interfaces_group_save($post, $id);
	case 'bridges':
		return interfaces_bridge_save($post, $id);
	}
}

function restapi_h_iftun_list($req) {
	$res = restapi_iftun_resource($req['path']);
	$out = array();
	foreach (array_keys(restapi_iftun_entries($res)) as $idx) {
		$out[] = restapi_iftun_out($res, $idx);
	}
	return array('data' => $out);
}

function restapi_h_iftun_get($req) {
	$res = restapi_iftun_resource($req['path']);
	return array('data' => restapi_iftun_out($res, restapi_iftun_index($res, $req['params']['id'])));
}

function restapi_h_iftun_create($req) {
	$res = restapi_iftun_resource($req['path']);
	$errors = restapi_iftun_save($res, restapi_iftun_post($res, $req['body']), null, $req);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$entries = restapi_iftun_entries($res);
	end($entries);
	return array('status' => 201, 'data' => restapi_iftun_out($res, key($entries)));
}

function restapi_h_iftun_update($req) {
	$res = restapi_iftun_resource($req['path']);
	$id = restapi_iftun_index($res, $req['params']['id']);
	$current = restapi_iftun_current($res, restapi_iftun_entries($res)[$id]);
	$errors = restapi_iftun_save($res, restapi_iftun_post($res, $req['body'], $current), $id, $req);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('data' => restapi_iftun_out($res, $id));
}

function restapi_h_iftun_delete($req) {
	$res = restapi_iftun_resource($req['path']);
	$id = restapi_iftun_index($res, $req['params']['id']);
	$ifname = restapi_iftun_ifname($res, restapi_iftun_entries($res)[$id]);
	$input_errors = array();
	$ok = false;
	switch ($res) {
	case 'vlans':
		$ok = interfaces_vlan_delete($id, userHasPrivilege($req['user'], 'user-config-readonly'), $input_errors);
		break;
	case 'vxlans':
		$ok = interfaces_vxlan_delete($id, $input_errors);
		break;
	case 'gifs':
		$ok = interfaces_gif_delete($id, $input_errors);
		break;
	case 'gres':
		$ok = interfaces_gre_delete($id, $input_errors);
		break;
	case 'laggs':
		$ok = interfaces_lagg_delete($id, $input_errors);
		break;
	case 'qinqs':
		$ok = interfaces_qinq_delete($id, userHasPrivilege($req['user'], 'user-config-readonly'), $input_errors);
		break;
	case 'groups':
		$ok = interfaces_group_delete($id, $input_errors);
		break;
	case 'bridges':
		$ok = interfaces_bridge_delete($id, $input_errors);
		break;
	}
	if (!$ok) {
		throw new RestApiError(409, 'in_use', implode(' ', array_map(function ($m) {
			return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
		}, $input_errors)));
	}
	$key = ($res === 'groups') ? 'name' : 'interface';
	return array('data' => array('deleted' => $id, $key => $ifname));
}

/* ------------------------------------------------------------------ */
/* Interface assignments                                               */
/* ------------------------------------------------------------------ */

/*
 * /v1/interfaces/assignments: the Interfaces > Interface Assignments page
 * (interfaces_assign.inc). Assignments are addressed by their interface
 * name (wan, lan, opt1, ...). Like the page, every change reconfigures the
 * interfaces and the filter at once, so each one requires
 * {"confirm": true}. WAN and LAN are never deleted through the API. When a
 * configuration restore left an interface mismatch, responses carry
 * "reboot_needed": the page's "Apply Changes" (a reboot) is not offered.
 */
function restapi_routes_ifassign() {
	$base = '/v1/interfaces/assignments';
	$page = 'interfaces_assign.php';
	$confirm = array('type' => 'boolean', 'description' => 'Must be true: the change reconfigures interfaces at once');
	$port_body = array('type' => 'object', 'required' => array('port', 'confirm'), 'properties' => array(
	    'port' => array('type' => 'string', 'description' => 'A network port from "available_ports" (e.g. em2, em1.100, lagg0)'),
	    'confirm' => $confirm));
	return array(
		restapi_route('GET', $base, 'restapi_h_ifassign_list', array('page' => $page, 'area' => 'interfaces',
		    'summary' => 'Interface assignments (interface => port) and the ports that are not assigned')),
		restapi_route('GET', "{$base}/{name}", 'restapi_h_ifassign_get', array('page' => $page, 'area' => 'interfaces',
		    'summary' => 'Get an interface assignment by interface name (wan, lan, opt1, ...)')),
		restapi_route('POST', $base, 'restapi_h_ifassign_create', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => $port_body,
		    'summary' => 'Assign a port as the next interface (LAN when there is none, else the next optN; not enabled)')),
		restapi_route('PUT', "{$base}/{name}", 'restapi_h_ifassign_update', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => $port_body,
		    'summary' => 'Move an interface to another port (the interface is reconfigured at once)')),
		restapi_route('DELETE', "{$base}/{name}", 'restapi_h_ifassign_delete', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => array('type' => 'object', 'required' => array('confirm'),
		    'properties' => array('confirm' => $confirm)),
		    'summary' => 'Unassign an interface that is not in use (never WAN or LAN); removes its DHCP settings, rules and port forwards')),
	);
}

/* 400 unless the body carries {"confirm": true}. */
function restapi_ifassign_confirm(array $body) {
	if (($body['confirm'] ?? null) !== true) {
		throw new RestApiError(400, 'confirmation_required',
		    'Interface assignment changes reconfigure interfaces at once and require {"confirm": true}.');
	}
}

/* The port named in the body (400 when missing or not a string). */
function restapi_ifassign_port(array $body) {
	$port = $body['port'] ?? null;
	if (!is_string($port) || ($port === '')) {
		throw new RestApiError(400, 'invalid_field', 'Field "port" must be the name of a network port.');
	}
	return $port;
}

/* WAN and LAN are never unassigned through the API. */
function restapi_ifassign_protected($name) {
	return in_array((string)$name, array('wan', 'lan'), true);
}

/* The name of an assigned interface (404 otherwise). */
function restapi_ifassign_name($name, array $interfaces) {
	$name = (string)$name;
	if (!preg_match('/^(wan|lan|opt[0-9]+)$/', $name) || !isset($interfaces[$name]) || !is_array($interfaces[$name])) {
		throw new RestApiError(404, 'not_found', 'No assigned interface with that name.');
	}
	return $name;
}

/* The page's "Save" post: every assigned interface with its port, $name moved to $port. */
function restapi_ifassign_remap_post(array $interfaces, $name, $port) {
	$post = array();
	foreach ($interfaces as $ifname => $ifcfg) {
		if (is_array($ifcfg)) {
			$post[(string)$ifname] = (string)($ifcfg['if'] ?? '');
		}
	}
	$post[(string)$name] = (string)$port;
	$post['Submit'] = 'Save';
	return $post;
}

function restapi_ifassign_out($name, array $ifcfg, array $descrs) {
	$port = (string)($ifcfg['if'] ?? '');
	$real = get_real_interface($name);
	return array(
		'name' => (string)$name,
		'descr' => (string)($ifcfg['descr'] ?? strtoupper($name)),
		'port' => $port,
		'port_descr' => (string)($descrs[$port] ?? $port),
		'enabled' => restapi_iftun_flag($ifcfg, 'enable'),
		'present' => !empty($real) && does_interface_exist($real),
	);
}

function restapi_ifassign_interfaces() {
	$interfaces = config_get_path('interfaces', []);
	return is_array($interfaces) ? $interfaces : array();
}

/* One assignment, with the current reboot flag. */
function restapi_ifassign_result($name, $status = 200) {
	$portlist = interfaces_assign_port_list();
	$out = restapi_ifassign_out($name, (array)config_get_path("interfaces/{$name}", []), interfaces_assign_port_descrs($portlist));
	$out['reboot_needed'] = interfaces_assign_reboot_needed();
	return array('status' => $status, 'data' => $out);
}

function restapi_h_ifassign_list($req) {
	$portlist = interfaces_assign_port_list();
	$descrs = interfaces_assign_port_descrs($portlist);
	$assignments = array();
	foreach (restapi_ifassign_interfaces() as $name => $ifcfg) {
		if (is_array($ifcfg)) {
			$assignments[] = restapi_ifassign_out($name, $ifcfg, $descrs);
		}
	}
	$available = array();
	foreach (array_keys(interfaces_assign_unused_ports($portlist)) as $port) {
		$available[] = array('port' => (string)$port, 'descr' => (string)($descrs[$port] ?? $port));
	}
	return array('data' => array(
		'assignments' => $assignments,
		'available_ports' => $available,
		'reboot_needed' => interfaces_assign_reboot_needed(),
	));
}

function restapi_h_ifassign_get($req) {
	return restapi_ifassign_result(restapi_ifassign_name($req['params']['name'], restapi_ifassign_interfaces()));
}

function restapi_h_ifassign_create($req) {
	restapi_ifassign_confirm($req['body']);
	$port = restapi_ifassign_port($req['body']);
	$name = interfaces_assign_add($port, interfaces_assign_port_list(), $input_errors);
	if ($name === null) {
		throw restapi_validation_error($input_errors);
	}
	return restapi_ifassign_result($name, 201);
}

function restapi_h_ifassign_update($req) {
	$interfaces = restapi_ifassign_interfaces();
	$name = restapi_ifassign_name($req['params']['name'], $interfaces);
	restapi_ifassign_confirm($req['body']);
	$port = restapi_ifassign_port($req['body']);
	if (!interfaces_assign_save(restapi_ifassign_remap_post($interfaces, $name, $port), interfaces_assign_port_list(), $input_errors)) {
		throw restapi_validation_error($input_errors);
	}
	return restapi_ifassign_result($name);
}

function restapi_h_ifassign_delete($req) {
	$name = restapi_ifassign_name($req['params']['name'], restapi_ifassign_interfaces());
	if (restapi_ifassign_protected($name)) {
		throw new RestApiError(403, 'protected_interface', 'WAN and LAN cannot be unassigned through the API.');
	}
	restapi_ifassign_confirm($req['body']);
	if (!interfaces_assign_delete($name, $input_errors)) {
		throw new RestApiError(409, 'in_use', implode(' ', array_map(function ($m) {
			return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
		}, $input_errors)));
	}
	return array('data' => array('deleted' => $name, 'reboot_needed' => interfaces_assign_reboot_needed()));
}
