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
 * REST API v1 interface routes: VLANs, VXLANs, GIF and GRE tunnels.
 *
 * Writes go through the functions the Interfaces pages call
 * (interfaces_tunnels.inc), so validation and in-use checks are the GUI's.
 * Like the GUI, a change takes effect at once: the interface is created,
 * recreated or destroyed by the request itself (there is no apply step).
 *
 * Entries are addressed by their position in config.xml, like the GUI, or
 * by their interface name (em1.100, vxlan0, gif0, gre0).
 *
 * The front controller loads interfaces_tunnels.inc at file scope.
 */

require_once('restapi/framework.inc');

/*
 * The resources: config path, interface name key, GUI pages, the edit
 * form's fields (checkboxes separately) and what a new form posts.
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
	);
	$routes = array();
	foreach (restapi_iftun_types() as $res => $t) {
		$base = "/v1/interfaces/{$res}";
		$label = $t['label'];
		$body = array('type' => 'object', 'description' => $bodies[$res] .
		    ' Checkboxes are booleans. GET returns the current values as "fields".');
		$routes[] = restapi_route('GET', $base, 'restapi_h_iftun_list', array(
			'page' => $t['list'], 'area' => 'interfaces', 'summary' => "List {$label}s"));
		$routes[] = restapi_route('GET', "{$base}/{id}", 'restapi_h_iftun_get', array(
			'page' => $t['list'], 'area' => 'interfaces',
			'summary' => "Get a {$label} by position or interface name"));
		$routes[] = restapi_route('POST', $base, 'restapi_h_iftun_create', array(
			'page' => $t['edit'], 'area' => 'interfaces', 'write' => true, 'body' => $body,
			'summary' => "Create a {$label} (the interface is created at once)"));
		$routes[] = restapi_route('PUT', "{$base}/{id}", 'restapi_h_iftun_update', array(
			'page' => $t['edit'], 'area' => 'interfaces', 'write' => true, 'body' => $body,
			'summary' => "Update a {$label} (omitted fields keep their value; applied at once)"));
		$routes[] = restapi_route('DELETE', "{$base}/{id}", 'restapi_h_iftun_delete', array(
			'page' => $t['list'], 'area' => 'interfaces', 'write' => true,
			'summary' => "Delete a {$label} that is not in use (the interface is destroyed at once)"));
	}
	return $routes;
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

/* The kernel interface name of a stored entry. */
function restapi_iftun_ifname($res, array $entry) {
	$t = restapi_iftun_types()[$res];
	$name = (string)($entry[$t['ifkey']] ?? '');
	if (($name === '') && ($res === 'vlans') && isset($entry['if'], $entry['tag'])) {
		$name = "{$entry['if']}.{$entry['tag']}";
	}
	return $name;
}

/*
 * The edit form's fields for a stored entry, preselected the way the edit
 * page does: checkboxes are "yes" when ticked and absent otherwise, a GIF
 * sent from an address carries it as "parent|address", an empty GRE subnet
 * select posts its first choice.
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
	}
	return $post;
}

/*
 * The form post for a create ($current null: what a new form posts) or an
 * update (the body over the current fields; a checkbox sent as false is
 * unticked). Every text field is present, like a browser post.
 */
function restapi_iftun_post($res, array $body, $current = null) {
	$t = restapi_iftun_types()[$res];
	if ($current === null) {
		$current = $t['defaults'];
	}
	$post = restapi_body_as_post($body) + $current;
	foreach ($body as $key => $value) {
		if ($value === false) {
			unset($post[$key]);
		}
	}
	foreach ($t['fields'] as $field) {
		if (!isset($post[$field])) {
			$post[$field] = '';
		} elseif (is_array($post[$field])) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be a string or number.");
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

/* Position of the entry named by {id}: a position or an interface name. */
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
	throw new RestApiError(404, 'not_found', "No {$label} with that id or interface name.");
}

function restapi_iftun_out($res, $idx) {
	$entry = (array)config_get_path(restapi_iftun_types()[$res]['config'] . "/{$idx}", []);
	$ifname = restapi_iftun_ifname($res, $entry);
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
	$current = restapi_iftun_fields($res, restapi_iftun_entries($res)[$id]);
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
	}
	if (!$ok) {
		throw new RestApiError(409, 'in_use', implode(' ', array_map(function ($m) {
			return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
		}, $input_errors)));
	}
	return array('data' => array('deleted' => $id, 'interface' => $ifname));
}
