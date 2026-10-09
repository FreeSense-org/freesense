<?php
/*
 * restapi/routes_nat.inc
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
 * REST API v1 routes for 1:1 NAT, outbound NAT and NPt. The three kinds
 * share one table-driven implementation over the GUI's functions
 * (save1to1NATrule, saveoutNATrule, savenptNATrule and their get, toggle,
 * delete and apply companions). Entries are addressed by position, like
 * the port forwards in routes_firewall.inc.
 *
 * The front controller loads firewall_nat_1to1.inc, firewall_nat_out.inc
 * and firewall_nat_npt.inc at file scope.
 */

require_once('restapi/framework.inc');

/* What differs between the three kinds. */
function restapi_nat_kinds() {
	return array(
		'one-to-one' => array(
			'label' => '1:1 NAT mapping',
			'path' => 'nat/onetoone',
			'list_page' => 'firewall_nat_1to1.php',
			'edit_page' => 'firewall_nat_1to1_edit.php',
			'get' => 'get1to1NATRule',
			'save' => 'save1to1NATrule',
			'toggle' => 'toggle1to1NATrule',
			'delete' => 'delete1to1NATrule',
			'apply' => 'apply1to1NATrules',
			'checkboxes' => array('disabled', 'nobinat', 'srcnot', 'dstnot'),
			'fields' => 'interface, ipprotocol, external, exttype, srctype, src, srcmask, srcnot, dsttype, dst, ' .
			    'dstmask, dstnot, natreflection, nobinat, disabled, descr',
		),
		'outbound' => array(
			'label' => 'outbound NAT mapping',
			'path' => 'nat/outbound/rule',
			'list_page' => 'firewall_nat_out.php',
			'edit_page' => 'firewall_nat_out_edit.php',
			'get' => 'getoutNATrule',
			'save' => 'saveoutNATrule',
			'toggle' => 'toggleoutNATrule',
			'delete' => 'deleteoutNATrule',
			'apply' => 'applyoutNATrules',
			'checkboxes' => array('disabled', 'nonat', 'destination_not', 'staticnatport', 'nosync', 'eimnat'),
			'fields' => 'interface, ipprotocol, protocol, source_type, source, source_subnet, sourceport, ' .
			    'destination_type, destination, destination_subnet, destination_not, dstport, target_type, target, ' .
			    'target_subnet, poolopts, source_hash_key, natport, staticnatport, eimnat, nonat, disabled, descr',
		),
		'npt' => array(
			'label' => 'NPt mapping',
			'path' => 'nat/npt',
			'list_page' => 'firewall_nat_npt.php',
			'edit_page' => 'firewall_nat_npt_edit.php',
			'get' => 'getnptNATRule',
			'save' => 'savenptNATrule',
			'toggle' => 'togglenptNATrule',
			'delete' => 'deletenptNATrule',
			'apply' => 'applynptNATrules',
			'checkboxes' => array('disabled', 'srcnot', 'dstnot'),
			'fields' => 'interface, src, srcmask, srcnot, dsttype, dst, dstmask, dstnot, disabled, descr',
		),
	);
}

function restapi_routes_nat() {
	$routes = array();
	$apply_query = array('apply' => 'true to apply the change at once (default: stage it like the GUI)');
	foreach (restapi_nat_kinds() as $kind => $k) {
		$base = "/v1/firewall/nat/{$kind}";
		$body = array('type' => 'object', 'description' => "The same fields as the {$k['label']} edit page: " .
		    $k['fields'] . '; and "after" (create: insert after this id). GET returns the same shape as "fields".');
		$routes[] = restapi_route('GET', $base, 'restapi_h_nat_list', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat', 'summary' => "List {$k['label']}s"));
		$routes[] = restapi_route('GET', "{$base}/{id}", 'restapi_h_nat_get', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat', 'summary' => "Get a {$k['label']}"));
		$routes[] = restapi_route('POST', $base, 'restapi_h_nat_create', array(
			'page' => $k['edit_page'], 'area' => 'firewall.nat', 'write' => true,
			'body' => $body, 'query' => $apply_query, 'summary' => "Create a {$k['label']}"));
		$routes[] = restapi_route('PUT', "{$base}/{id}", 'restapi_h_nat_update', array(
			'page' => $k['edit_page'], 'area' => 'firewall.nat', 'write' => true,
			'body' => $body, 'query' => $apply_query,
			'summary' => "Update a {$k['label']} (omitted fields keep their value)"));
		$routes[] = restapi_route('POST', "{$base}/{id}/toggle", 'restapi_h_nat_toggle', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat', 'write' => true,
			'query' => $apply_query, 'summary' => "Enable or disable a {$k['label']}"));
		$routes[] = restapi_route('DELETE', "{$base}/{id}", 'restapi_h_nat_delete', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat', 'write' => true,
			'query' => $apply_query, 'summary' => "Delete a {$k['label']}"));
	}
	$routes[] = restapi_route('GET', '/v1/firewall/nat/outbound-mode', 'restapi_h_natout_mode_get', array(
		'page' => 'firewall_nat_out.php', 'area' => 'firewall.nat',
		'summary' => 'Outbound NAT mode (automatic, hybrid, advanced or disabled)'));
	$routes[] = restapi_route('PUT', '/v1/firewall/nat/outbound-mode', 'restapi_h_natout_mode_set', array(
		'page' => 'firewall_nat_out.php', 'area' => 'firewall.nat', 'write' => true,
		'body' => array('type' => 'object', 'required' => array('mode'), 'properties' => array(
		    'mode' => array('type' => 'string', 'enum' => array('automatic', 'hybrid', 'advanced', 'disabled')))),
		'query' => $apply_query,
		'summary' => 'Set the outbound NAT mode (switching to advanced creates rules from the automatic ones)'));
	$routes[] = restapi_route('GET', '/v1/firewall/nat/outbound-automatic', 'restapi_h_natout_auto', array(
		'page' => 'firewall_nat_out.php', 'area' => 'firewall.nat',
		'summary' => 'The automatic outbound NAT rules the firewall generates'));
	return $routes;
}

/* The kind from a request path such as /v1/firewall/nat/outbound/3. */
function restapi_nat_kind($req) {
	foreach (restapi_nat_kinds() as $kind => $k) {
		if (strpos($req['path'], "/v1/firewall/nat/{$kind}") === 0) {
			return array($kind, $k);
		}
	}
	throw new RestApiError(404, 'not_found', 'Unknown NAT kind.');
}

function restapi_nat_entries($k) {
	$entries = config_get_path($k['path'], []);
	return is_array($entries) ? $entries : array();
}

function restapi_nat_out($k, $id) {
	$raw = (array)config_get_path("{$k['path']}/{$id}", []);
	return array('id' => (int)$id, 'fields' => restapi_nat_form_fields($k, call_user_func($k['get'], $id), $raw)) + $raw;
}

/*
 * The edit form's fields for a stored entry: checkboxes as "yes" when
 * ticked and absent otherwise, and the type selectors chosen the way the
 * edit pages preselect them.
 */
function restapi_nat_form_fields($k, $p, $raw = null) {
	$raw = ($raw === null) ? null : (array)$raw;
	$post = array();
	foreach ((array)$p as $key => $value) {
		if (in_array($key, $k['checkboxes'], true) || is_bool($value)) {
			/*
			 * The getters test isset(), which is also true for a flag the
			 * rule cache still holds as false right after a save in the same
			 * request; the stored entry (re-read by write_config()) is exact.
			 */
			if (($raw !== null) && in_array($key, array('disabled', 'nobinat', 'nonat', 'staticnatport', 'nosync'), true)) {
				$value = isset($raw[$key]) && ($raw[$key] !== false);
			}
			if ($value) {
				$post[$key] = 'yes';
			}
		} elseif (is_scalar($value)) {
			$post[$key] = (string)$value;
		}
	}
	if (($k['get'] === 'getoutNATrule') && (($post['protocol'] ?? '') === '')) {
		/* No stored protocol means "any", which the edit form preselects. */
		$post['protocol'] = 'any';
	}
	if ($k['get'] === 'get1to1NATRule') {
		/* firewall_nat_1to1.inc's selectors read the global $pconfig. */
		$saved = $GLOBALS['pconfig'] ?? null;
		$GLOBALS['pconfig'] = $p;
		$post['srctype'] = srctype_selected();
		$post['dsttype'] = dsttype_selected();
		$post['exttype'] = exttype_selected();
		$GLOBALS['pconfig'] = $saved;
	} elseif ($k['get'] === 'getnptNATRule') {
		/* As firewall_nat_npt_edit.php's dsttype_selected(). */
		$post['dsttype'] = get_specialnet($p['dst'] ?? '') ? $p['dst'] : 'network';
	}
	unset($post['created'], $post['updated']);
	return $post;
}

function restapi_nat_save($k, $post, $id, $req) {
	if ($k['save'] === 'saveoutNATrule') {
		/* saveoutNATrule() reads the page's global $after. */
		$GLOBALS['after'] = $post['after'] ?? null;
	}
	$result = restapi_json_result(call_user_func($k['save'], $post, $id, false));
	if (!empty($result['input_errors'])) {
		throw restapi_validation_error($result['input_errors']);
	}
	return restapi_want_apply($req) ? array('apply' => array('applied' => call_user_func($k['apply']) == 0,
	    'pending' => restapi_fw_pending_list())) : array();
}

function restapi_h_nat_list($req) {
	list(, $k) = restapi_nat_kind($req);
	$out = array();
	foreach (array_keys(restapi_nat_entries($k)) as $id) {
		$out[] = restapi_nat_out($k, $id);
	}
	return array('data' => $out);
}

function restapi_h_nat_get($req) {
	list(, $k) = restapi_nat_kind($req);
	$id = restapi_index_param($req['params']['id'], restapi_nat_entries($k), $k['label']);
	return array('data' => restapi_nat_out($k, $id));
}

function restapi_h_nat_create($req) {
	list(, $k) = restapi_nat_kind($req);
	$before = count(restapi_nat_entries($k));
	$post = restapi_body_as_post($req['body']);
	$extra = restapi_nat_save($k, $post, null, $req);
	$entries = restapi_nat_entries($k);
	$id = (isset($post['after']) && ctype_digit($post['after']) && count($entries) > $before) ?
	    (int)$post['after'] + 1 : count($entries) - 1;
	return array('status' => 201, 'data' => restapi_nat_out($k, $id) + $extra);
}

function restapi_h_nat_update($req) {
	list(, $k) = restapi_nat_kind($req);
	$id = restapi_index_param($req['params']['id'], restapi_nat_entries($k), $k['label']);
	$post = restapi_body_over($req['body'], restapi_nat_form_fields($k, call_user_func($k['get'], $id),
	    config_get_path("{$k['path']}/{$id}", [])));
	unset($post['after']);
	$extra = restapi_nat_save($k, $post, $id, $req);
	return array('data' => restapi_nat_out($k, $id) + $extra);
}

function restapi_h_nat_toggle($req) {
	list(, $k) = restapi_nat_kind($req);
	$id = restapi_index_param($req['params']['id'], restapi_nat_entries($k), $k['label']);
	/* With $json set the GUI functions neither redirect nor mark NAT dirty. */
	call_user_func($k['toggle'], array('id' => $id), true);
	mark_subsystem_dirty('natconf');
	$state = config_path_enabled("{$k['path']}/{$id}", 'disabled') ? 'disabled' : 'enabled';
	$extra = restapi_want_apply($req) ? array('apply' => array('applied' => call_user_func($k['apply']) == 0)) : array();
	return array('data' => array('id' => $id, 'state' => $state, 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_nat_delete($req) {
	list(, $k) = restapi_nat_kind($req);
	$id = restapi_index_param($req['params']['id'], restapi_nat_entries($k), $k['label']);
	call_user_func($k['delete'], array('id' => $id), true);
	mark_subsystem_dirty('natconf');
	$extra = restapi_want_apply($req) ? array('apply' => array('applied' => call_user_func($k['apply']) == 0)) : array();
	return array('data' => array('deleted' => $id, 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_natout_mode_get($req) {
	return array('data' => array('mode' => config_get_path('nat/outbound/mode', 'automatic')));
}

function restapi_h_natout_mode_set($req) {
	$mode = (string)($req['body']['mode'] ?? '');
	if (!in_array($mode, array('automatic', 'hybrid', 'advanced', 'disabled'), true)) {
		throw restapi_validation_error(array('"mode" must be automatic, hybrid, advanced or disabled.'));
	}
	saveNAToutMode(array('mode' => $mode), true);
	$extra = restapi_want_apply($req) ? array('apply' => array('applied' => applyoutNATrules() == 0)) : array();
	return array('data' => array('mode' => config_get_path('nat/outbound/mode', 'automatic'),
	    'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_natout_auto($req) {
	return array('data' => array_values((array)getAutoRules()));
}
