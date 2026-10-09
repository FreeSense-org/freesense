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
 * delete, reorder and apply companions). Entries are addressed by position,
 * like the port forwards in routes_firewall.inc.
 *
 * Every entry is returned with "fields" (the edit form's fields, which the
 * create and update routes take) and "display" (the list page's columns as
 * plain text). The display helpers and the reorder code at the end of this
 * file are shared with the port forwards. Additive (capability "nat-v2").
 *
 * The front controller loads firewall_nat_1to1.inc, firewall_nat_out.inc
 * and firewall_nat_npt.inc at file scope.
 */

require_once('restapi/framework.inc');

/* What differs between the three kinds. */
function restapi_nat_kinds() {
	return array(
		'one-to-one' => array(
			'type' => 'binat',
			'schema' => 'firewall/nat_one_to_one',
			'reorder' => 'reorder1to1NATrules',
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
			'type' => 'nat',
			'schema' => 'firewall/nat_outbound',
			'reorder' => 'outNATrulesreorder',
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
			'type' => 'npt',
			'schema' => 'firewall/nat_npt',
			'reorder' => 'reordernptNATrules',
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
		/* Specific paths before the {id} routes (the first matching route wins). */
		$routes[] = restapi_route('POST', "{$base}/order", 'restapi_h_nat_order', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat', 'write' => true,
			'body' => restapi_nat_order_body(), 'query' => $apply_query,
			'summary' => "Reorder the {$k['label']}s (like dragging them on the list page)"));
		$routes[] = restapi_route('GET', "{$base}/{id}", 'restapi_h_nat_get', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat',
			'summary' => "Get a {$k['label']} (stored form, plus \"fields\": the edit form fields, and \"display\": the list columns as text)"));
		$routes[] = restapi_route('POST', $base, 'restapi_h_nat_create', array(
			'page' => $k['edit_page'], 'area' => 'firewall.nat', 'write' => true, 'schema' => $k['schema'],
			'body' => $body, 'query' => $apply_query, 'summary' => "Create a {$k['label']}"));
		$routes[] = restapi_route('PUT', "{$base}/{id}", 'restapi_h_nat_update', array(
			'page' => $k['edit_page'], 'area' => 'firewall.nat', 'write' => true, 'schema' => $k['schema'],
			'body' => $body, 'query' => $apply_query,
			'summary' => "Update a {$k['label']} (omitted fields keep their value; false clears a checkbox)"));
		$routes[] = restapi_route('POST', "{$base}/{id}/toggle", 'restapi_h_nat_toggle', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat', 'write' => true,
			'query' => $apply_query, 'summary' => "Enable or disable a {$k['label']}"));
		$routes[] = restapi_route('DELETE', "{$base}/{id}", 'restapi_h_nat_delete', array(
			'page' => $k['list_page'], 'area' => 'firewall.nat', 'write' => true,
			'query' => $apply_query, 'summary' => "Delete a {$k['label']}"));
	}
	$routes[] = restapi_route('GET', '/v1/firewall/nat/outbound-mode', 'restapi_h_natout_mode_get', array(
		'page' => 'firewall_nat_out.php', 'area' => 'firewall.nat',
		'summary' => 'Outbound NAT mode (automatic, hybrid, advanced or disabled) and the mode "choices"'));
	$routes[] = restapi_route('PUT', '/v1/firewall/nat/outbound-mode', 'restapi_h_natout_mode_set', array(
		'page' => 'firewall_nat_out.php', 'area' => 'firewall.nat', 'write' => true,
		'body' => array('type' => 'object', 'required' => array('mode'), 'properties' => array(
		    'mode' => array('type' => 'string', 'enum' => array('automatic', 'hybrid', 'advanced', 'disabled')))),
		'query' => $apply_query,
		'summary' => 'Set the outbound NAT mode (switching to advanced creates rules from the automatic ones)'));
	$routes[] = restapi_route('GET', '/v1/firewall/nat/outbound-automatic', 'restapi_h_natout_auto', array(
		'page' => 'firewall_nat_out.php', 'area' => 'firewall.nat',
		'summary' => 'The automatic outbound NAT rules the firewall generates (with "display" like the mappings)'));
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
	return array('id' => (int)$id, 'fields' => restapi_nat_form_fields($k, call_user_func($k['get'], $id), $raw)) + $raw +
	    array('display' => call_user_func("restapi_nat_display_{$k['type']}", $raw));
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

/* The four modes as firewall_nat_out.php offers them (radio caption and the hint under it). */
function restapi_natout_mode_choices() {
	return array(
		array('value' => 'automatic', 'label' => gettext('Automatic outbound NAT rule generation'),
		    'help' => gettext('IPsec passthrough included')),
		array('value' => 'hybrid', 'label' => gettext('Hybrid Outbound NAT rule generation'),
		    'help' => gettext('Automatic Outbound NAT + rules below')),
		array('value' => 'advanced', 'label' => gettext('Manual Outbound NAT rule generation'),
		    'help' => gettext('AON - Advanced Outbound NAT')),
		array('value' => 'disabled', 'label' => gettext('Disable Outbound NAT rule generation'),
		    'help' => gettext('No Outbound NAT rules')),
	);
}

function restapi_h_natout_mode_get($req) {
	return array('data' => array('mode' => config_get_path('nat/outbound/mode', 'automatic'),
	    'choices' => restapi_natout_mode_choices()));
}

function restapi_h_natout_mode_set($req) {
	$mode = (string)($req['body']['mode'] ?? '');
	if (!in_array($mode, array('automatic', 'hybrid', 'advanced', 'disabled'), true)) {
		throw restapi_validation_error(array('"mode" must be automatic, hybrid, advanced or disabled.'));
	}
	saveNAToutMode(array('mode' => $mode), true);
	$extra = restapi_want_apply($req) ? array('apply' => array('applied' => applyoutNATrules() == 0)) : array();
	return array('data' => array('mode' => config_get_path('nat/outbound/mode', 'automatic'),
	    'choices' => restapi_natout_mode_choices(), 'pending' => restapi_fw_pending_list()) + $extra);
}

function restapi_h_natout_auto($req) {
	$out = array();
	foreach ((array)getAutoRules() as $rule) {
		$out[] = (array)$rule + array('display' => restapi_nat_display_nat((array)$rule, true));
	}
	return array('data' => $out);
}

function restapi_h_nat_order($req) {
	list(, $k) = restapi_nat_kind($req);
	return array('data' => restapi_nat_reorder($k['type'], $k['path'], $req['body']['order'] ?? null, $k['reorder'], $k['apply'], $req));
}

/* ------------------------------------------------------------------ */
/* Reorder (all four kinds)                                            */
/* ------------------------------------------------------------------ */

function restapi_nat_order_body() {
	return array('type' => 'object', 'required' => array('order'), 'properties' => array(
	    'order' => array('type' => 'array', 'items' => array('type' => 'integer'),
	    'description' => 'Every id of this kind, in the new order')));
}

/*
 * The new order as integer ids. It must list every id exactly once (a
 * permutation), like the list page posts all rows when its order is saved;
 * anything else is a 422.
 */
function restapi_nat_order_ids(array $ids, $order) {
	if (!is_array($order) || !array_is_list($order)) {
		throw new RestApiError(400, 'invalid_field', '"order" must be a list of ids.');
	}
	$given = array();
	foreach ($order as $v) {
		if (is_int($v) || (is_string($v) && ctype_digit($v))) {
			$given[] = (int)$v;
		} else {
			$given = null;
			break;
		}
	}
	$sorted = (array)$given;
	sort($sorted);
	$want = array_map('intval', array_values($ids));
	sort($want);
	if (empty($want) || ($given === null) || ($sorted !== $want)) {
		throw new RestApiError(422, 'validation_failed', 'The request failed validation.', array('messages' =>
		    array('"order" must list every id exactly once: ' . implode(', ', $want))));
	}
	return $given;
}

/*
 * Reorder one NAT kind like its list page's drag and drop + Save:
 * set_anynat_rules_order() through the page's reorder function, staged
 * (natconf dirty) unless ?apply=true. Port forward separators stay where
 * they are (the list page re-posts them unchanged), and the filter rules
 * associated with port forwards are not touched: they keep their link by
 * associated-rule-id, not by position.
 */
function restapi_nat_reorder($type, $path, $order, $reorder_fn, $apply_fn, $req) {
	$ids = array_keys((array)config_get_path($path, []));
	$given = restapi_nat_order_ids($ids, $order);
	$changed = ($given !== array_map('intval', $ids));
	if ($changed) {
		if ($reorder_fn === null) {
			/*
			 * reorderNATrules() (port forwards) applies at once in JSON mode
			 * and drops the separators unless they are posted; do its rule
			 * part here so the change is staged like the other kinds.
			 */
			set_anynat_rules_order($type, $given);
			write_config(gettext('NAT: Rule order changed'));
		} else {
			/* With $json set the page functions neither redirect nor mark NAT dirty. */
			call_user_func($reorder_fn, array('rule' => $given), true);
		}
		/* get_anynat_rules_list() caches the sorted rules per request. */
		$GLOBALS["{$type}_rules_map"] = array();
		mark_subsystem_dirty('natconf');
	}
	$extra = restapi_want_apply($req) ? array('apply' => array('applied' => call_user_func($apply_fn) == 0)) : array();
	return array('changed' => $changed, 'order' => $given, 'pending' => restapi_fw_pending_list()) + $extra;
}

/* ------------------------------------------------------------------ */
/* List columns as text (all four kinds)                               */
/* ------------------------------------------------------------------ */

/*
 * A stored flag. The 1:1 and NPt save functions store unticked boxes as
 * false; in the request that saved them the key is still set.
 */
function restapi_nat_flag(array $e, $key) {
	return isset($e[$key]) && ($e[$key] !== false);
}

/* guiconfig.inc's pprint_address(): "*" for any, special networks by their label, "! " when inverted. */
function restapi_nat_pprint_address($adr, array $flags = array()) {
	static $specialnets = array();
	$adr = is_array($adr) ? $adr : array();
	if (isset($adr['any']) || (($adr['network'] ?? '') === 'any')) {
		$text = '*';
	} elseif (!empty($adr['network'])) {
		$key = implode(',', $flags);
		if (!isset($specialnets[$key])) {
			$specialnets[$key] = function_exists('get_specialnet') ? (array)get_specialnet('', $flags) : array();
		}
		$text = (string)($specialnets[$key][$adr['network']] ?? $adr['network']);
	} else {
		$text = (string)($adr['address'] ?? '');
	}
	return (isset($adr['not']) ? '! ' : '') . $text;
}

/* guiconfig.inc's pprint_port(): "*" when empty, a well-known name for a single port, "a - b" for a range. */
function restapi_nat_pprint_port($port) {
	global $wkports;
	$port = (string)$port;
	if (($port === '') || ($port === '0')) {
		return '*';
	}
	list($begin, $end) = array_pad(explode('-', $port, 2), 2, '');
	if (($end === '') || ($begin === $end)) {
		return $begin . (isset($wkports[$begin]) ? " ({$wkports[$begin]})" : '');
	}
	return "{$begin} - {$end}";
}

function restapi_nat_ifdescr($if) {
	$if = (string)$if;
	$if = ($if === '') ? 'wan' : $if;
	return function_exists('convert_friendly_interface_to_friendly_descr') ?
	    (string)(convert_friendly_interface_to_friendly_descr($if) ?? strtoupper($if)) : strtoupper($if);
}

/* "IPv4 TCP", "IPv6 *", "IPv4+6 UDP" like the rule display. */
function restapi_nat_protocol($family, $proto) {
	$families = array('inet' => 'IPv4', 'inet6' => 'IPv6', 'inet46' => 'IPv4+6', '' => 'IPv4+6');
	$proto = strtoupper((string)$proto);
	return ($families[(string)$family] ?? 'IPv4') . ' ' . ((($proto === '') || ($proto === 'ANY')) ? '*' : $proto);
}

/* Port forward (firewall_nat.php). */
function restapi_nat_display_rdr(array $e) {
	$src = (array)($e['source'] ?? array());
	$dst = (array)($e['destination'] ?? array());
	/* The NAT port column: local-port, extended to a range as long as the destination range. */
	$localport = (string)($e['local-port'] ?? '');
	list($begin, $end) = array_pad(explode('-', (string)($dst['port'] ?? ''), 2), 2, '');
	if (($end !== '') && ctype_digit($localport) && ctype_digit($begin) && ctype_digit($end)) {
		$localport .= '-' . ((int)$localport + (int)$end - (int)$begin);
	}
	$aid = (string)($e['associated-rule-id'] ?? '');
	return array(
		'interface' => restapi_nat_ifdescr($e['interface'] ?? ''),
		'protocol' => restapi_nat_protocol($e['ipprotocol'] ?? 'inet', $e['protocol'] ?? ''),
		'source' => restapi_nat_pprint_address($src, array(SPECIALNET_ANY, SPECIALNET_CLIENTS, SPECIALNET_IFADDR, SPECIALNET_IFNET)),
		'source_ports' => restapi_nat_pprint_port($src['port'] ?? ''),
		'destination' => restapi_nat_pprint_address($dst, array(SPECIALNET_ANY, SPECIALNET_SELF, SPECIALNET_CLIENTS,
		    SPECIALNET_IFADDR, SPECIALNET_IFNET, SPECIALNET_VIPS)),
		'destination_ports' => restapi_nat_pprint_port($dst['port'] ?? ''),
		'target' => restapi_nat_pprint_address(array('network' => (string)($e['target'] ?? '')), array(SPECIALNET_IFADDR)),
		'target_ports' => restapi_nat_pprint_port($localport),
		'enabled' => !restapi_nat_flag($e, 'disabled'),
		'no_rdr' => restapi_nat_flag($e, 'nordr'),
		'filter_rule' => ($aid === 'pass') ? 'pass' : (($aid !== '') ? 'associated' : 'none'),
		'description' => (string)($e['descr'] ?? ''),
	);
}

/*
 * Outbound mapping (firewall_nat_out.php). With $auto (an automatic rule),
 * "source" is the list of networks the rule covers.
 */
function restapi_nat_display_nat(array $e, $auto = false) {
	$src = (array)($e['source'] ?? array());
	if (restapi_nat_flag($e, 'nonat')) {
		$translation = gettext('No NAT');
	} elseif ((string)($e['target'] ?? '') === '') {
		$translation = gettext('Interface address');
	} elseif (empty($e['target_subnet'])) {
		$translation = restapi_nat_pprint_address(array('network' => (string)$e['target']),
		    array(SPECIALNET_NETAL, SPECIALNET_IFADDR, SPECIALNET_VIPS));
	} else {
		$translation = "{$e['target']}/{$e['target_subnet']}";
	}
	return array(
		'interface' => restapi_nat_ifdescr($e['interface'] ?? ''),
		'protocol' => restapi_nat_protocol($e['ipprotocol'] ?? '', $e['protocol'] ?? ''),
		'source' => $auto ? array_values(array_filter(explode(' ', (string)($src['network'] ?? '')), 'strlen')) :
		    restapi_nat_pprint_address($src, array(SPECIALNET_ANY, SPECIALNET_SELF, SPECIALNET_IFNET, SPECIALNET_GROUP)),
		'source_ports' => restapi_nat_pprint_port($e['sourceport'] ?? ''),
		'destination' => restapi_nat_pprint_address($e['destination'] ?? array(), array(SPECIALNET_ANY, SPECIALNET_IFNET, SPECIALNET_GROUP)),
		'destination_ports' => restapi_nat_pprint_port($e['dstport'] ?? ''),
		'translation' => $translation,
		'translation_port' => ((string)($e['natport'] ?? '') === '') ? '*' : (string)$e['natport'],
		'static_port' => restapi_nat_flag($e, 'staticnatport'),
		'eim' => restapi_nat_flag($e, 'eimnat'),
		'no_nat' => restapi_nat_flag($e, 'nonat'),
		'enabled' => !restapi_nat_flag($e, 'disabled'),
		'description' => (string)($e['descr'] ?? ''),
	);
}

/* 1:1 mapping (firewall_nat_1to1.php). */
function restapi_nat_display_binat(array $e) {
	return array(
		'interface' => restapi_nat_ifdescr($e['interface'] ?? ''),
		'external' => restapi_nat_pprint_address(array('network' => (string)($e['external'] ?? '')), array(SPECIALNET_IFADDR)),
		'internal' => restapi_nat_pprint_address($e['source'] ?? array(),
		    array(SPECIALNET_ANY, SPECIALNET_CLIENTS, SPECIALNET_IFADDR, SPECIALNET_IFSUB)),
		'destination' => restapi_nat_pprint_address($e['destination'] ?? array(),
		    array(SPECIALNET_ANY, SPECIALNET_CLIENTS, SPECIALNET_IFADDR, SPECIALNET_IFSUB, SPECIALNET_VIPS)),
		'enabled' => !restapi_nat_flag($e, 'disabled'),
		'no_binat' => restapi_nat_flag($e, 'nobinat'),
		'description' => (string)($e['descr'] ?? ''),
	);
}

/* NPt mapping (firewall_nat_npt.php): a tracked interface shows its delegated prefix. */
function restapi_nat_display_npt(array $e) {
	$dst = (array)($e['destination'] ?? array());
	if (!empty($dst['network'])) {
		$net = explode('/', (string)$dst['network'])[0];
		$track6 = (config_get_path("interfaces/{$net}/ipaddrv6") === 'track6') && function_exists('get_interface_track6ip') ?
		    get_interface_track6ip($net) : null;
		if (is_array($track6) && !empty($track6[0])) {
			$external = config_get_path("interfaces/{$net}/descr", strtoupper($net)) . ' (' . gen_subnetv6($track6[0], $track6[1]) . "/{$track6[1]})";
		} else {
			$external = restapi_nat_pprint_address(array('network' => $net) + $dst);
		}
	} else {
		$external = restapi_nat_pprint_address($dst);
	}
	return array(
		'interface' => restapi_nat_ifdescr($e['interface'] ?? ''),
		'internal' => restapi_nat_pprint_address($e['source'] ?? array()),
		'external' => $external,
		'enabled' => !restapi_nat_flag($e, 'disabled'),
		'description' => (string)($e['descr'] ?? ''),
	);
}
