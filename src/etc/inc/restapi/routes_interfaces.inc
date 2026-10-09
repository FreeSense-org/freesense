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
 * For the WebUI every entry also carries its interface name under the
 * stored key (vlanif, laggif, ...), the stored fields, "fields" (the edit
 * form, a valid PUT body) and "display" (the list page's columns as text,
 * in_use / in_use_reason when a delete would be refused, assigned_to). The
 * editors are described by the schemas network/{vlans,vxlans,qinq,bridges,laggs,
 * gifs,gres,groups,assignments} (schemas/network_interfaces.inc), which key
 * 422 messages to fields.
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
			'list' => 'interfaces_vlan.php', 'edit' => 'interfaces_vlan_edit.php', 'schema' => 'network/vlans',
			'fields' => array('if', 'tag_type', 'tag', 'pcp', 'descr'),
			'checkboxes' => array(),
			'defaults' => array('tag_type' => 'ctag'),
		),
		'vxlans' => array(
			'label' => 'VXLAN', 'config' => 'vxlans/vxlan', 'ifkey' => 'vxlanif',
			'list' => 'interfaces_vxlan.php', 'edit' => 'interfaces_vxlan_edit.php', 'schema' => 'network/vxlans',
			'fields' => array('if', 'ipproto', 'mode', 'vni', 'remote-addr', 'mcastgroup', 'localport',
			    'remoteport', 'ttl', 'descr'),
			'checkboxes' => array('learn', 'allowrule'),
			'defaults' => array('ipproto' => 'inet', 'mode' => 'unicast', 'learn' => 'yes'),
		),
		'gifs' => array(
			'label' => 'GIF tunnel', 'config' => 'gifs/gif', 'ifkey' => 'gifif',
			'list' => 'interfaces_gif.php', 'edit' => 'interfaces_gif_edit.php', 'schema' => 'network/gifs',
			'fields' => array('if', 'remote-addr', 'tunnel-local-addr', 'tunnel-remote-addr', 'tunnel-remote-net', 'descr'),
			'checkboxes' => array('link1', 'link2'),
			'defaults' => array(),
		),
		'gres' => array(
			'label' => 'GRE tunnel', 'config' => 'gres/gre', 'ifkey' => 'greif',
			'list' => 'interfaces_gre.php', 'edit' => 'interfaces_gre_edit.php', 'schema' => 'network/gres',
			'fields' => array('if', 'remote-addr', 'tunnel-local-addr', 'tunnel-remote-addr', 'tunnel-remote-net',
			    'tunnel-local-addr6', 'tunnel-remote-addr6', 'tunnel-remote-net6', 'descr'),
			'checkboxes' => array('link1'),
			/* The subnet selects post their first choice when left alone. */
			'defaults' => array('tunnel-remote-net' => '32', 'tunnel-remote-net6' => '128'),
		),
		'laggs' => array(
			'label' => 'LAGG', 'config' => 'laggs/lagg', 'ifkey' => 'laggif',
			'list' => 'interfaces_lagg.php', 'edit' => 'interfaces_lagg_edit.php', 'schema' => 'network/laggs',
			'fields' => array('proto', 'failovermaster', 'lacptimeout', 'lagghash', 'descr'),
			'lists' => array('members'),
			'checkboxes' => array(),
			/* The selects post their first choice when left alone. */
			'defaults' => array('proto' => 'none', 'failovermaster' => 'auto', 'lacptimeout' => 'slow', 'lagghash' => 'l2,l3,l4'),
		),
		'qinqs' => array(
			'label' => 'QinQ', 'config' => 'qinqs/qinqentry', 'ifkey' => 'vlanif',
			'list' => 'interfaces_qinq.php', 'edit' => 'interfaces_qinq_edit.php', 'schema' => 'network/qinq',
			'fields' => array('if', 'tag_type', 'tag', 'descr'),
			/* the member tag rows (member0, member1, ...): tags or ranges such as "10-20" */
			'lists' => array('members'),
			'checkboxes' => array('autogroup'),
			'defaults' => array('tag_type' => 'stag'),
		),
		'groups' => array(
			'label' => 'interface group', 'config' => 'ifgroups/ifgroupentry', 'ifkey' => 'ifname',
			'list' => 'interfaces_groups.php', 'edit' => 'interfaces_groups_edit.php', 'schema' => 'network/groups',
			'fields' => array('ifname', 'descr'),
			'lists' => array('members'),
			'checkboxes' => array(),
			'defaults' => array(),
		),
		'bridges' => array(
			'label' => 'bridge', 'config' => 'bridges/bridged', 'ifkey' => 'bridgeif',
			'list' => 'interfaces_bridge.php', 'edit' => 'interfaces_bridge_edit.php', 'schema' => 'network/bridges',
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
		    'tags or ranges, e.g. ["10","20-30"], or rows [{"tag":"10"}] as "fields" returns them), autogroup (boolean: add to the QinQ interface group), descr.',
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
		    ' Checkboxes are booleans. GET returns the current values as "fields" and the list columns as "display".' .
		    (isset($t['schema']) ? " Form schema: GET /api/v1/schema/{$t['schema']}." : ''));
		$schema = isset($t['schema']) ? array('schema' => $t['schema']) : array();
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
			'page' => $t['list'], 'area' => 'interfaces',
			'summary' => "List {$label}s (stored form, plus \"fields\": the edit form fields, and \"display\": the list columns as text)"));
		$routes[] = restapi_route('GET', "{$base}/{id}", 'restapi_h_iftun_get', array(
			'page' => $t['list'], 'area' => 'interfaces', 'summary' => $get));
		$routes[] = restapi_route('POST', $base, 'restapi_h_iftun_create', array(
			'page' => $t['edit'], 'area' => 'interfaces', 'write' => true, 'body' => $body,
			'summary' => $create) + $schema);
		$routes[] = restapi_route('PUT', "{$base}/{id}", 'restapi_h_iftun_update', array(
			'page' => $t['edit'], 'area' => 'interfaces', 'write' => true, 'body' => $body,
			'summary' => "Update {$a} {$label} (omitted fields keep their value; false clears a checkbox; applied at once)") + $schema);
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

/* QinQ member tags sent as a list of tags/ranges or as entry-grid rows {tag} → a list of strings. */
function restapi_iftun_qinq_tags(array $members) {
	$out = array();
	foreach ($members as $m) {
		if (is_array($m)) {
			$m = $m['tag'] ?? '';
		}
		if (!is_scalar($m) && ($m !== null)) {
			throw new RestApiError(400, 'invalid_field', 'Each QinQ member must be a tag, a range or a row {"tag": ...}.');
		}
		$out[] = is_bool($m) ? '' : (string)$m;
	}
	return $out;
}

/* Stored QinQ member tags ("10 11 12 20") as runs for display: ["10-12", "20"]. */
function restapi_iftun_tag_runs(array $tags) {
	$nums = array_values(array_unique(array_map('intval', array_filter($tags, 'is_numeric'))));
	sort($nums);
	$runs = array();
	foreach ($nums as $n) {
		$last = count($runs) - 1;
		if (($last >= 0) && ($runs[$last][1] + 1 === $n)) {
			$runs[$last][1] = $n;
		} else {
			$runs[] = array($n, $n);
		}
	}
	return array_map(function ($r) {
		return ($r[0] === $r[1]) ? (string)$r[0] : "{$r[0]}-{$r[1]}";
	}, $runs);
}

/*
 * What the list pages need to name interfaces: the interface each port is
 * assigned to (port => wan/lan/optN) and each interface's label (its
 * description or the upper-case name, like get_configured_interface_with_descr()).
 */
function restapi_iftun_display_ctx(array $interfaces) {
	$assigned = array();
	$labels = array();
	foreach ($interfaces as $name => $cfg) {
		if (!is_array($cfg)) {
			continue;
		}
		$labels[(string)$name] = (string)((($cfg['descr'] ?? '') !== '') ? $cfg['descr'] : strtoupper((string)$name));
		if (isset($cfg['if']) && is_string($cfg['if']) && ($cfg['if'] !== '')) {
			$assigned[$cfg['if']] = (string)$name;
		}
	}
	return array('assigned' => $assigned, 'labels' => $labels);
}

/* The label of the interface a port is assigned to, or ''. */
function restapi_iftun_assigned_to($port, array $ctx) {
	$name = $ctx['assigned'][(string)$port] ?? '';
	return ($name === '') ? '' : ($ctx['labels'][$name] ?? strtoupper($name));
}

/* Assigned interfaces (lan, opt1) as their labels; unknown names stay as they are. */
function restapi_iftun_labels(array $names, array $ctx) {
	return array_values(array_map(function ($n) use ($ctx) {
		return $ctx['labels'][$n] ?? $n;
	}, $names));
}

/* A parent port with the label of its assignment, as the VLAN list shows it: "em1 (LAN)". */
function restapi_iftun_port_label($port, array $ctx) {
	$label = restapi_iftun_assigned_to($port, $ctx);
	return (string)$port . (($label === '') ? '' : " ({$label})");
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
		/* rows of the schema's entry grid (the page's member0, member1, ... inputs) */
		$post['members'] = array_map(function ($tag) {
			return array('tag' => $tag);
		}, restapi_iftun_split($entry['members'] ?? '', ' '));
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
	/* Output only (a client may send a GET item back). */
	unset($body['fields'], $body['display'], $body['config'], $body['member_choices'], $body['failovermaster_choices']);
	unset($current['member_choices'], $current['failovermaster_choices']);
	if ($res === 'qinqs') {
		if (isset($body['members']) && is_array($body['members'])) {
			$body['members'] = restapi_iftun_qinq_tags($body['members']);
		}
		if (isset($current['members']) && is_array($current['members'])) {
			$current['members'] = restapi_iftun_qinq_tags($current['members']);
		}
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
	$post = restapi_body_over($body, $current);
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

/*
 * Why the entry at position $idx cannot be deleted (the message the delete
 * function gives), or '' when it can: the in-use checks of
 * interfaces_tunnels.inc and interfaces_l2.inc.
 */
function restapi_iftun_in_use($res, $idx, array $entry) {
	switch ($res) {
	case 'vlans':
		return vlan_inuse($entry) ? gettext("This VLAN cannot be deleted because it is still being used as an interface.") : '';
	case 'vxlans':
		$why = vxlan_inuse($idx);
		return ($why === false) ? '' : (string)$why;
	case 'gifs':
		return gif_inuse($idx) ? gettext("This gif TUNNEL cannot be deleted because it is still being used as an interface.") : '';
	case 'gres':
		return gre_inuse($idx) ? gettext("This GRE tunnel cannot be deleted because it is still being used as an interface.") : '';
	case 'laggs':
		$why = lagg_inuse($idx);
		return ($why === false) ? '' : (string)$why;
	case 'qinqs':
		if (vlan_inuse($entry)) {
			return gettext("This QinQ cannot be deleted because it is still being used as an interface.");
		}
		foreach (restapi_iftun_split($entry['members'] ?? '', ' ') as $tag) {
			if (qinq_inuse($entry, $tag)) {
				return gettext("This QinQ cannot be deleted because one of it tags is still being used as an interface.");
			}
		}
		return '';
	case 'groups':
		$name = restapi_iftun_ifname($res, $entry);
		$users = interfaces_group_users($name);
		return empty($users) ? '' : sprintf(gettext('Interface group "%1$s" cannot be deleted because it is in use by %2$s.'),
		    $name, implode(', ', $users));
	case 'bridges':
		return bridge_inuse($idx) ? gettext("This bridge cannot be deleted because it is assigned as an interface.") : '';
	}
	return '';
}

/*
 * The list page's columns as plain text (labels, not ids; members as
 * lists), plus in_use / in_use_reason (a delete would be refused) and
 * assigned_to (the label of the interface the entry is assigned as).
 */
function restapi_iftun_display($res, $idx, array $entry, array $ctx) {
	$ifname = restapi_iftun_ifname($res, $entry);
	$parent = (string)($entry['if'] ?? '');
	$d = array();
	switch ($res) {
	case 'vlans':
	case 'qinqs':
		$types = interfaces_vlan_tag_types();
		$type = (string)($entry['tag_type'] ?? '');
		if ($type === '') {
			$type = ($res === 'vlans') ? 'ctag' : 'stag';
		}
		$d = array('interface' => $ifname, 'parent' => restapi_iftun_port_label($parent, $ctx), 'tag' => (string)($entry['tag'] ?? ''),
		    'tag_type' => (string)($types[$type] ?? $type));
		if ($res === 'vlans') {
			$d['priority'] = (string)($entry['pcp'] ?? '');
		} else {
			$d['members'] = restapi_iftun_tag_runs(restapi_iftun_split($entry['members'] ?? '', ' '));
			$d['autogroup'] = restapi_iftun_flag($entry, 'autogroup');
		}
		break;
	case 'vxlans':
		/* interfaces_vxlan.php: the name as it is, the parent's label, "Remote / Group" */
		$multicast = (($entry['mode'] ?? '') === 'multicast');
		$d = array('interface' => $ifname, 'parent' => (string)convert_friendly_interface_to_friendly_descr($parent),
		    'vni' => (string)($entry['vni'] ?? ''),
		    'mode' => $multicast ? 'multicast' : 'unicast',
		    'family' => (($entry['ipproto'] ?? 'inet') === 'inet6') ? 'IPv6' : 'IPv4',
		    'remote' => (string)($multicast ? ($entry['mcastgroup'] ?? '') : ($entry['remote-addr'] ?? '')),
		    /* the parent's address of the tunnel's family ('' while it has none: the tunnel then waits) */
		    'local' => (string)interface_vxlan_local_address($entry),
		    'port' => (string)vxlan_localport($entry),
		    'remote_port' => (string)vxlan_remoteport($entry),
		    'learning' => !isset($entry['nolearn']),
		    'allowrule' => isset($entry['allowrule']));
		break;
	case 'gifs':
	case 'gres':
		/* interfaces_gre.php names a VIP parent by the interface it is on */
		if (($res === 'gres') && (substr($parent, 0, 4) === '_vip')) {
			$parent = convert_real_interface_to_friendly_descr(get_real_interface($parent));
		}
		$tunnels = array();
		foreach (array('' => 'tunnel-remote-net', '6' => 'tunnel-remote-net6') as $sfx => $net) {
			if (!empty($entry["tunnel-local-addr{$sfx}"]) || !empty($entry["tunnel-remote-addr{$sfx}"])) {
				$tunnels[] = ($entry["tunnel-local-addr{$sfx}"] ?? '') . ' → ' . ($entry["tunnel-remote-addr{$sfx}"] ?? '') .
				    (empty($entry[$net]) ? '' : "/{$entry[$net]}");
			}
		}
		$d = array('interface' => $ifname, 'parent' => (string)convert_friendly_interface_to_friendly_descr($parent),
		    'remote' => (string)($entry['remote-addr'] ?? ''), 'tunnel' => $tunnels);
		break;
	case 'laggs':
		$d = array('interface' => strtoupper($ifname), 'members' => restapi_iftun_split($entry['members'] ?? '', ','),
		    'protocol' => strtoupper((string)($entry['proto'] ?? '')));
		break;
	case 'bridges':
		$d = array('interface' => strtoupper($ifname), 'members' => restapi_iftun_labels(restapi_iftun_split($entry['members'] ?? '', ','), $ctx),
		    'stp' => restapi_iftun_flag($entry, 'enablestp'));
		break;
	case 'groups':
		$d = array('name' => $ifname, 'members' => restapi_iftun_labels(restapi_iftun_split($entry['members'] ?? '', ' '), $ctx));
		break;
	}
	$d['description'] = (string)($entry['descr'] ?? '');
	$why = restapi_iftun_in_use($res, $idx, $entry);
	$d['in_use'] = ($why !== '');
	$d['in_use_reason'] = $why;
	if ($res === 'groups') {
		$d['assigned_to'] = '';
	} elseif ($res === 'qinqs') {
		/* the QinQ interface or any of its member interfaces */
		$labels = array();
		foreach (array_merge(array($ifname), array_map(function ($tag) use ($ifname) {
			return "{$ifname}.{$tag}";
		}, restapi_iftun_split($entry['members'] ?? '', ' '))) as $port) {
			$label = restapi_iftun_assigned_to($port, $ctx);
			if ($label !== '') {
				$labels[] = $label;
			}
		}
		$d['assigned_to'] = implode(', ', $labels);
	} else {
		$d['assigned_to'] = restapi_iftun_assigned_to($ifname, $ctx);
	}
	return $d;
}

/* The LAGG edit page's member choices for the LAGG at $idx (its own members stay available): port => "port (mac)". */
function restapi_iftun_lagg_choices($idx) {
	$out = array();
	foreach (interfaces_lagg_port_list($idx) as $ifn => $info) {
		$out[(string)$ifn] = $ifn . (empty($info['mac']) ? '' : " ({$info['mac']})");
	}
	return $out;
}

/*
 * One entry: the API's own keys (id, interface or name, ...), the interface
 * name under the stored key (vlanif, laggif, bridgeif, gifif, greif, or
 * ifname for groups), the stored fields, "fields" (the edit form, also a
 * valid PUT body) and "display". $ctx is restapi_iftun_display_ctx(),
 * passed in for lists. A single LAGG also carries the member choices of its
 * edit page (member_choices, failovermaster_choices; also inside "fields"
 * for the schema's optionsFrom).
 */
function restapi_iftun_out($res, $idx, ?array $ctx = null, $single = true) {
	$t = restapi_iftun_types()[$res];
	$entry = (array)config_get_path($t['config'] . "/{$idx}", []);
	$ifname = restapi_iftun_ifname($res, $entry);
	$ctx = $ctx ?? restapi_iftun_display_ctx(restapi_ifassign_interfaces());
	if ($res === 'groups') {
		$out = array(
			'id' => (int)$idx,
			'name' => $ifname,
			'descr' => (string)($entry['descr'] ?? ''),
			'used_by' => interfaces_group_users($ifname),
		);
	} else {
		$assigned = ($ifname === '') ? null : convert_real_interface_to_friendly_interface_name($ifname);
		$out = array(
			'id' => (int)$idx,
			'interface' => $ifname,
			'assigned_as' => empty($assigned) ? null : $assigned,
			'present' => ($ifname !== '') && does_interface_exist($ifname),
			'descr' => (string)($entry['descr'] ?? ''),
		);
	}
	$out[$t['ifkey']] = $ifname;
	$out += $entry;
	$out['config'] = $entry;
	$out['fields'] = restapi_iftun_fields($res, $entry);
	if (($res === 'laggs') && $single) {
		$out['member_choices'] = restapi_iftun_lagg_choices($idx);
		$out['failovermaster_choices'] = array('auto' => 'auto') + $out['member_choices'];
		$out['fields']['member_choices'] = $out['member_choices'];
		$out['fields']['failovermaster_choices'] = $out['failovermaster_choices'];
	}
	$out['display'] = restapi_iftun_display($res, $idx, $entry, $ctx);
	return $out;
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
	$ctx = restapi_iftun_display_ctx(restapi_ifassign_interfaces());
	$out = array();
	foreach (array_keys(restapi_iftun_entries($res)) as $idx) {
		$out[] = restapi_iftun_out($res, $idx, $ctx, false);
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
 * {"confirm": true} (a DELETE may send ?confirm=true instead). WAN and LAN
 * are never deleted through the API.
 *
 * The list is {data: [assignment], meta: {available_ports, ports,
 * reboot_needed, pending}}. When a configuration restore left an interface
 * mismatch (or the interfaces were changed elsewhere and wait for a
 * reload), the page shows "Apply Changes": GET .../pending tells, and
 * POST .../apply {"confirm": true} does what the button does (a reboot
 * when the mismatch is pending, otherwise a filter reload).
 */
function restapi_routes_ifassign() {
	$base = '/v1/interfaces/assignments';
	$page = 'interfaces_assign.php';
	$confirm = array('type' => 'boolean', 'description' => 'Must be true: the change reconfigures interfaces at once');
	$port_body = array('type' => 'object', 'required' => array('port', 'confirm'), 'properties' => array(
	    'port' => array('type' => 'string', 'description' => 'A network port from meta.available_ports (add) or meta.ports (change), e.g. em2, em1.100, lagg0'),
	    'confirm' => $confirm));
	$schema = array('schema' => 'network/assignments');
	return array(
		restapi_route('GET', $base, 'restapi_h_ifassign_list', array('page' => $page, 'area' => 'interfaces',
		    'summary' => 'Interface assignments with display texts; meta lists the free ports, all ports and whether an apply is pending')),
		/* before {name}, which would match these words */
		restapi_route('GET', "{$base}/pending", 'restapi_h_ifassign_pending', array('page' => $page, 'area' => 'interfaces',
		    'summary' => 'Whether the page would show "Apply Changes" (interface mismatch after a restore: the apply reboots)')),
		restapi_route('POST', "{$base}/apply", 'restapi_h_ifassign_apply', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => array(
		    'confirm' => array('type' => 'boolean', 'description' => 'Must be true: with an interface mismatch pending the firewall reboots'))),
		    'summary' => 'The page\'s "Apply Changes": reboot when an interface mismatch is pending (202), otherwise reload the filter')),
		restapi_route('GET', "{$base}/{name}", 'restapi_h_ifassign_get', array('page' => $page, 'area' => 'interfaces',
		    'summary' => 'Get an interface assignment by interface name (wan, lan, opt1, ...)')),
		restapi_route('POST', $base, 'restapi_h_ifassign_create', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => $port_body,
		    'summary' => 'Assign a port as the next interface (LAN when there is none, else the next optN; not enabled)') + $schema),
		restapi_route('PUT', "{$base}/{name}", 'restapi_h_ifassign_update', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => $port_body,
		    'summary' => 'Move an interface to another port (the interface is reconfigured at once)') + $schema),
		restapi_route('DELETE', "{$base}/{name}", 'restapi_h_ifassign_delete', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => array('type' => 'object', 'required' => array('confirm'),
		    'properties' => array('confirm' => $confirm)), 'query' => array('confirm' => 'true instead of the {"confirm": true} body'),
		    'summary' => 'Unassign an interface that is not in use (never WAN or LAN); removes its DHCP settings, rules and port forwards')),
	);
}

/* 400 unless the body carries {"confirm": true} (or, for a DELETE, the query confirm=true). */
function restapi_ifassign_confirm(array $body, array $query = array()) {
	if ((($body['confirm'] ?? null) !== true) && (($query['confirm'] ?? null) !== 'true')) {
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

/* The page's "Addresses" column: static addresses with their prefix, dynamic types by name. */
function restapi_ifassign_addresses(array $ifcfg) {
	$types = array('dhcp' => 'DHCP', 'pppoe' => 'PPPoE', 'pptp' => 'PPTP', 'l2tp' => 'L2TP', 'ppp' => 'PPP',
	    'dhcp6' => 'DHCPv6', 'slaac' => 'SLAAC', 'track6' => gettext('Track interface'), '6rd' => '6rd', '6to4' => '6to4');
	$addrs = array();
	foreach (array(array('ipaddr', 'subnet'), array('ipaddrv6', 'subnetv6')) as $af) {
		$addr = is_scalar($ifcfg[$af[0]] ?? null) ? (string)$ifcfg[$af[0]] : '';
		if (($addr === '') || ($addr === 'none')) {
			continue;
		}
		$addrs[] = $types[$addr] ?? ($addr . (empty($ifcfg[$af[1]]) ? '' : '/' . $ifcfg[$af[1]]));
	}
	return $addrs;
}

/*
 * Why the interface cannot be unassigned through the API, or '': WAN and
 * LAN never; otherwise the checks of the page's delete button
 * (interfaces_assign_in_use()).
 */
function restapi_ifassign_blocked($name) {
	if ($name === 'wan') {
		return gettext("The WAN interface cannot be deleted.");
	}
	if (restapi_ifassign_protected($name)) {
		return 'WAN and LAN cannot be unassigned through the API.';
	}
	return interfaces_assign_in_use($name);
}

/*
 * One assignment: interface (wan, lan, optN), description, port, port_descr
 * (the port select's text), removable and display (the page's columns as
 * text, in_use / in_use_reason: why a delete would be refused). name and
 * descr repeat interface and description for older clients.
 */
function restapi_ifassign_out($name, array $ifcfg, array $descrs) {
	$name = (string)$name;
	$port = (string)($ifcfg['if'] ?? '');
	$real = get_real_interface($name);
	$descr = (string)((($ifcfg['descr'] ?? '') !== '') ? $ifcfg['descr'] : strtoupper($name));
	$blocked = restapi_ifassign_blocked($name);
	$enabled = restapi_iftun_flag($ifcfg, 'enable');
	return array(
		'interface' => $name,
		'description' => $descr,
		'port' => $port,
		'port_descr' => (string)($descrs[$port] ?? $port),
		'removable' => ($blocked === ''),
		'name' => $name,
		'descr' => $descr,
		'enabled' => $enabled,
		'present' => !empty($real) && does_interface_exist($real),
		'display' => array(
			'interface' => $descr,
			'port' => (string)($descrs[$port] ?? $port),
			'addresses' => restapi_ifassign_addresses($ifcfg),
			'enabled' => $enabled,
			'in_use' => ($blocked !== ''),
			'in_use_reason' => $blocked,
			'assigned_to' => $descr,
		),
	);
}

function restapi_ifassign_interfaces() {
	$interfaces = config_get_path('interfaces', []);
	return is_array($interfaces) ? $interfaces : array();
}

/* Ports as [{value, label}] in the order of $ports. */
function restapi_ifassign_port_choices(array $ports, array $descrs) {
	$out = array();
	foreach (array_keys($ports) as $port) {
		$out[] = array('value' => (string)$port, 'label' => (string)($descrs[$port] ?? $port));
	}
	return $out;
}

/* Whether the page shows "Apply Changes", and its text. */
function restapi_ifassign_pending_state() {
	$reboot = interfaces_assign_reboot_needed();
	$reload = interfaces_assign_reload_pending();
	$message = '';
	if ($reboot) {
		$message = gettext("Interface mismatch detected. Please resolve the mismatch, save and then click 'Apply Changes'. The firewall will reboot afterwards.");
	} elseif ($reload) {
		$message = gettext("The interface configuration has been changed.") . ' ' . gettext("The changes must be applied for them to take effect.");
	}
	return array('pending' => ($reboot || $reload), 'reboot_needed' => $reboot, 'reload_pending' => $reload, 'message' => $message);
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
	$state = restapi_ifassign_pending_state();
	return array('data' => $assignments, 'meta' => array(
		'available_ports' => restapi_ifassign_port_choices(interfaces_assign_unused_ports($portlist), $descrs),
		'ports' => restapi_ifassign_port_choices($portlist, $descrs),
		'reboot_needed' => $state['reboot_needed'],
		'pending' => $state['pending'],
	));
}

function restapi_h_ifassign_pending($req) {
	return array('data' => restapi_ifassign_pending_state());
}

/*
 * The page's "Apply Changes" through interfaces_assign_apply. With an interface
 * mismatch pending this reboots, which like POST /v1/system/reboot needs an
 * administrator; the reboot runs after the response.
 */
function restapi_h_ifassign_apply($req) {
	restapi_ifassign_confirm($req['body']);
	if (interfaces_assign_reboot_needed()) {
		restapi_ops_require_admin($req, 'Rebooting for an interface mismatch');
		restapi_ops_after_response(RESTAPI_OPS_DELAY, function () {
			interfaces_assign_apply();
		});
		return array('status' => 202, 'data' => array('applied' => false, 'rebooting' => true, 'after_seconds' => RESTAPI_OPS_DELAY,
		    'message' => gettext("The system is now rebooting. Please wait.")));
	}
	$result = interfaces_assign_apply();
	return array('data' => array('applied' => true, 'rebooting' => false, 'retval' => (int)$result['retval']) + restapi_ifassign_pending_state());
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
	restapi_ifassign_confirm($req['body'], $req['query'] ?? array());
	if (!interfaces_assign_delete($name, $input_errors)) {
		throw new RestApiError(409, 'in_use', implode(' ', array_map(function ($m) {
			return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
		}, $input_errors)));
	}
	return array('data' => array('deleted' => $name, 'reboot_needed' => interfaces_assign_reboot_needed()));
}
