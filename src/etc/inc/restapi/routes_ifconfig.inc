<?php
/*
 * restapi/routes_ifconfig.inc
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
 * REST API v1 routes for one interface's configuration: the Interfaces >
 * WAN, LAN, OPTn page (interfaces.php).
 *
 * Writes go through the functions the page calls (interfaces_edit.inc), so
 * validation and the stored result are the page's. A PUT is the page's
 * form saved with the fields the request names changed: the request is
 * mapped onto the POST the unchanged form sends (interfaces_edit_form_post())
 * and the rest keeps its value. Like the page, a save only records the
 * change: the interface is reconfigured by POST .../apply, which requires
 * {"confirm": true} because it brings interfaces down and up.
 *
 * PPP (PPP, PPPoE, PPTP, L2TP) and wireless settings are read-only here
 * ("ppp" and "wireless" in GET); a PUT on such an interface keeps them.
 *
 * The front controller loads interfaces_edit.inc at file scope.
 */

require_once('restapi/framework.inc');

function restapi_routes_ifconfig() {
	$base = '/v1/interfaces/config';
	$page = 'interfaces.php';
	$area = 'interfaces';
	$body = array('type' => 'object', 'description' => 'Fields of the interface form (omitted fields keep their value): ' .
	    'enable, block_private, block_bogons, ipv6_use_ipv4_link, dhcp6_send_prefix_hint, dhcp6_use_ipv4, dhcp6_prefix_only, ' .
	    'dhcp6_without_ra, slaac_use_ipv4 (booleans); description; ipv4_type (none|staticv4|dhcp|ppp|pppoe|pptp|l2tp, from ' .
	    '"ipv4_type_choices"), ipv4_address, ipv4_subnet (bits), gateway (from "gateway_choices", "none" on a LAN); ipv6_type ' .
	    '(none|staticv6|dhcp6|slaac|6rd|6to4|track6), ipv6_address, ipv6_subnet, gatewayv6 (from "gatewayv6_choices"); ' .
	    'track6_interface (from "track6_interface_choices"), track6_prefix_id (hexadecimal, up to "track6_prefix_id_max" of the ' .
	    'tracked interface); prefix_6rd, gateway_6rd, prefix_6rd_v4_length; spoof_mac, mtu, mss, media (from "media_choices"; ' .
	    '"" for autoselect); dhcp_hostname, dhcp_alias_address, dhcp_alias_subnet, dhcp_reject_from; dhcp6_prefix_delegation_size ' .
	    '(from "dhcp6_prefix_delegation_size_choices"). Saving records the change; POST /api/v1/interfaces/config/apply applies it.');
	$confirm = array('type' => 'object', 'required' => array('confirm'), 'properties' => array(
	    'confirm' => array('type' => 'boolean', 'description' => 'Must be true: applying reconfigures the changed interfaces at once')));

	return array(
		restapi_route('GET', $base, 'restapi_h_ifconfig_list', array('page' => $page, 'area' => $area,
		    'summary' => 'Address configuration of every assigned interface (summary)')),
		/* before {name}: the static paths must match first */
		restapi_route('GET', "{$base}/pending", 'restapi_h_ifconfig_pending', array('page' => $page, 'area' => $area,
		    'summary' => 'Whether saved interface changes wait to be applied, and for which interfaces')),
		restapi_route('POST', "{$base}/apply", 'restapi_h_ifconfig_apply', array('page' => $page, 'area' => $area,
		    'write' => true, 'body' => $confirm,
		    'summary' => 'Apply saved interface changes (reconfigures the interfaces, gateways and the filter at once)')),
		restapi_route('GET', "{$base}/{name}", 'restapi_h_ifconfig_get', array('page' => $page, 'area' => $area,
		    'summary' => 'One interface\'s configuration (wan, lan, opt1, ...) with the choices of its selects')),
		restapi_route('PUT', "{$base}/{name}", 'restapi_h_ifconfig_set', array('page' => $page, 'area' => $area,
		    'write' => true, 'body' => $body, 'schema' => 'interfaces/config',
		    'summary' => 'Change an interface\'s configuration (saved as pending; omitted fields keep their value)')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/*
 * The writable API fields: API name => array(form field, type). Booleans
 * are checkboxes that post "yes" when ticked.
 */
function restapi_ifconfig_fields() {
	return array(
		'enable' => array('enable', 'bool'),
		'description' => array('descr', 'string'),
		'ipv4_type' => array('type', 'string'),
		'ipv4_address' => array('ipaddr', 'string'),
		'ipv4_subnet' => array('subnet', 'string'),
		'gateway' => array('gateway', 'string'),
		'ipv6_type' => array('type6', 'string'),
		'ipv6_address' => array('ipaddrv6', 'string'),
		'ipv6_subnet' => array('subnetv6', 'string'),
		'gatewayv6' => array('gatewayv6', 'string'),
		'ipv6_use_ipv4_link' => array('ipv6usev4iface', 'bool'),
		'track6_interface' => array('track6-interface', 'string'),
		'track6_prefix_id' => array('track6-prefix-id--hex', 'string'),
		'prefix_6rd' => array('prefix-6rd', 'string'),
		'gateway_6rd' => array('gateway-6rd', 'string'),
		'prefix_6rd_v4_length' => array('prefix-6rd-v4plen', 'string'),
		'spoof_mac' => array('spoofmac', 'string'),
		'mtu' => array('mtu', 'string'),
		'mss' => array('mss', 'string'),
		'media' => array('mediaopt', 'string'),
		'dhcp_hostname' => array('dhcphostname', 'string'),
		'dhcp_alias_address' => array('alias-address', 'string'),
		'dhcp_alias_subnet' => array('alias-subnet', 'string'),
		'dhcp_reject_from' => array('dhcprejectfrom', 'string'),
		'dhcp6_prefix_delegation_size' => array('dhcp6-ia-pd-len', 'string'),
		'dhcp6_send_prefix_hint' => array('dhcp6-ia-pd-send-hint', 'bool'),
		'dhcp6_use_ipv4' => array('dhcp6usev4iface', 'bool'),
		'dhcp6_prefix_only' => array('dhcp6prefixonly', 'bool'),
		'dhcp6_without_ra' => array('dhcp6withoutra', 'bool'),
		'slaac_use_ipv4' => array('slaacusev4iface', 'bool'),
		'block_private' => array('blockpriv', 'bool'),
		'block_bogons' => array('blockbogons', 'bool'),
	);
}

/* The read-only fields of GET (ignored in a PUT body, so a GET result can be sent back). */
function restapi_ifconfig_ro_fields() {
	return array('name', 'port', 'port_descr', 'real_interface', 'kind', 'bridge', 'address_configurable', 'pending', 'ppp',
	    'wireless', 'ipv4_type_choices', 'ipv6_type_choices', 'gateway_choices', 'gatewayv6_choices', 'track6_interface_choices',
	    'track6_prefix_id_max', 'media_choices', 'dhcp6_prefix_delegation_size_choices', 'note', 'applied');
}

/* field => bool | string | ro, for restapi_svc_merge(). */
function restapi_ifconfig_types() {
	$types = array();
	foreach (restapi_ifconfig_fields() as $api => $def) {
		$types[$api] = $def[1];
	}
	foreach (restapi_ifconfig_ro_fields() as $field) {
		$types[$field] = 'ro';
	}
	return $types;
}

function restapi_ifconfig_ipv4_types() {
	return array('none', 'staticv4', 'dhcp', 'ppp', 'pppoe', 'pptp', 'l2tp');
}

function restapi_ifconfig_ipv6_types() {
	return array('none', 'staticv6', 'dhcp6', 'slaac', '6rd', '6to4', 'track6');
}

/* The API fields from the page's form values ($pconfig); $media is the stored "media mediaopt". */
function restapi_ifconfig_from_pconfig(array $pconfig, $media = '') {
	$out = array();
	foreach (restapi_ifconfig_fields() as $api => list($field, $type)) {
		if ($type === 'bool') {
			$out[$api] = !empty($pconfig[$field]);
		} elseif ($field === 'track6-prefix-id--hex') {
			$id = $pconfig['track6-prefix-id'] ?? '';
			$out[$api] = sprintf('%x', ($id === '' || $id === null) ? 0 : (int)$id);
		} elseif ($field === 'mediaopt') {
			$out[$api] = (string)$media;
		} elseif ($field === 'descr') {
			$out[$api] = (string)($pconfig['descr'] ?? '');
		} elseif ($field === 'dhcp6-ia-pd-len') {
			$v = $pconfig[$field] ?? null;
			$out[$api] = (($v === null) || ($v === '')) ? 'none' : (string)$v;
		} else {
			$v = $pconfig[$field] ?? '';
			$out[$api] = is_array($v) ? '' : (string)$v;
		}
	}
	$out['ipv4_type'] = (string)($pconfig['type'] ?? 'none');
	$out['ipv6_type'] = (string)($pconfig['type6'] ?? 'none');
	return $out;
}

/*
 * Put API values onto the form's POST: booleans tick or clear their
 * checkbox, the rest replace the field. Only the fields given change.
 */
function restapi_ifconfig_to_post(array $values, array $post) {
	$fields = restapi_ifconfig_fields();
	foreach ($values as $api => $value) {
		if (!isset($fields[$api])) {
			continue;
		}
		list($field, $type) = $fields[$api];
		if ($type === 'bool') {
			if ($value) {
				$post[$field] = 'yes';
			} else {
				unset($post[$field]);
			}
		} else {
			$post[$field] = (string)$value;
		}
	}
	return $post;
}

/*
 * The selects as the form shows them: a stored value the form does not
 * offer shows (and is saved as) the first choice, e.g. gateway "none" on a
 * DHCP interface. $form_post is the unchanged form's POST.
 */
function restapi_ifconfig_shown(array $values, array $form_post) {
	$fields = restapi_ifconfig_fields();
	foreach (array('ipv4_type', 'ipv6_type', 'gateway', 'gatewayv6', 'track6_interface', 'prefix_6rd_v4_length', 'media',
	    'dhcp6_prefix_delegation_size') as $api) {
		if (array_key_exists($fields[$api][0], $form_post)) {
			$values[$api] = (string)$form_post[$fields[$api][0]];
		}
	}
	return $values;
}

/*
 * The fields a PUT changes: the body's writable fields whose value differs
 * from the current one (so a GET result can be sent back as it is).
 */
function restapi_ifconfig_changes(array $body, array $values, array $current) {
	$out = array();
	foreach (restapi_ifconfig_fields() as $api => list($field, $type)) {
		if (!array_key_exists($api, $body)) {
			continue;
		}
		$same = ($type === 'bool') ? ((bool)$values[$api] === (bool)($current[$api] ?? false)) :
		    ((string)$values[$api] === (string)($current[$api] ?? ''));
		if (!$same) {
			$out[$api] = $values[$api];
		}
	}
	return $out;
}

/* The API fields a form POST carries (the inverse of restapi_ifconfig_to_post()). */
function restapi_ifconfig_from_post(array $post) {
	$out = array();
	foreach (restapi_ifconfig_fields() as $api => list($field, $type)) {
		if ($type === 'bool') {
			$out[$api] = !empty($post[$field]);
		} elseif (array_key_exists($field, $post)) {
			$out[$api] = (string)$post[$field];
		}
	}
	return $out;
}

/*
 * What kind of link an interface is, from its configuration and real
 * interface name: wireless, ppp, pppoe, pptp, l2tp, vlan, lagg, bridge,
 * gif, gre, vxlan, openvpn, ipsec (VTI), l2tp-server or ethernet.
 */
function restapi_ifconfig_kind(array $ifcfg, $realif) {
	$port = (string)($ifcfg['if'] ?? '');
	if (is_array($ifcfg['wireless'] ?? null)) {
		return 'wireless';
	}
	if (in_array((string)($ifcfg['ipaddr'] ?? ''), array('ppp', 'pppoe', 'pptp', 'l2tp'), true)) {
		return (string)$ifcfg['ipaddr'];
	}
	if (preg_match('/^[a-z0-9_]+\.[0-9]+/i', $port)) {
		return 'vlan';
	}
	$prefixes = array('pppoe' => 'pppoe', 'pptp' => 'pptp', 'l2tps' => 'l2tp-server', 'l2tp' => 'l2tp', 'ppp' => 'ppp',
	    'lagg' => 'lagg', 'bridge' => 'bridge', 'gif' => 'gif', 'gre' => 'gre', 'vxlan' => 'vxlan', 'ovpns' => 'openvpn', 'ovpnc' => 'openvpn',
	    'ipsec' => 'ipsec');
	foreach (array($port, (string)$realif) as $name) {
		foreach ($prefixes as $prefix => $kind) {
			if (preg_match('/^' . preg_quote($prefix, '/') . '[0-9]/', $name)) {
				return $kind;
			}
		}
	}
	return 'ethernet';
}

/* One interface of the list: its address configuration as stored. */
function restapi_ifconfig_summary($name, array $ifcfg, $descr = null) {
	$ipaddr = (string)($ifcfg['ipaddr'] ?? '');
	$port = (string)($ifcfg['if'] ?? '');
	if (in_array($ipaddr, array('dhcp', 'ppp', 'pppoe', 'pptp', 'l2tp'), true)) {
		$type4 = $ipaddr;
	} elseif (filter_var($ipaddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
		$type4 = 'staticv4';
	} elseif (in_array(preg_replace('/[0-9]+$/', '', $port), array('ppp', 'pppoe', 'pptp', 'l2tp'), true)) {
		$type4 = preg_replace('/[0-9]+$/', '', $port);
	} else {
		$type4 = 'none';
	}
	$ipaddr6 = (string)($ifcfg['ipaddrv6'] ?? '');
	if (in_array($ipaddr6, array('slaac', 'dhcp6', '6to4', 'track6', '6rd'), true)) {
		$type6 = $ipaddr6;
	} elseif (filter_var($ipaddr6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
		$type6 = 'staticv6';
	} else {
		$type6 = 'none';
	}
	if ((string)($ifcfg['descr'] ?? '') !== '') {
		$descr = (string)$ifcfg['descr'];
	} elseif (in_array($name, array('wan', 'lan'), true)) {
		$descr = strtoupper($name);
	} elseif ($descr === null) {
		$descr = strtoupper($name);
	}
	return array(
		'name' => (string)$name,
		'descr' => (string)$descr,
		'enable' => (isset($ifcfg['enable']) && ($ifcfg['enable'] !== false)),
		'port' => $port,
		'ipv4_type' => $type4,
		'ipv4_address' => ($type4 === 'staticv4') ? $ipaddr : '',
		'ipv4_subnet' => ($type4 === 'staticv4') ? (string)($ifcfg['subnet'] ?? '') : '',
		'ipv6_type' => $type6,
		'ipv6_address' => ($type6 === 'staticv6') ? $ipaddr6 : '',
		'ipv6_subnet' => ($type6 === 'staticv6') ? (string)($ifcfg['subnetv6'] ?? '') : '',
		'gateway' => ($type4 === 'staticv4') ? (string)($ifcfg['gateway'] ?? '') : '',
		'gatewayv6' => ($type6 === 'staticv6') ? (string)($ifcfg['gatewayv6'] ?? '') : '',
		'mtu' => (string)($ifcfg['mtu'] ?? ''),
	);
}

/* 400 unless the body carries {"confirm": true}. */
function restapi_ifconfig_confirm(array $body) {
	if (($body['confirm'] ?? null) !== true) {
		throw new RestApiError(400, 'confirmation_required',
		    'Applying interface changes reconfigures interfaces at once and requires {"confirm": true}.');
	}
}

/*
 * Check the fields a PUT changes against what the page's form offers for
 * this interface: the configuration types and selects must be listed choices,
 * and a field the form does not show (a VLAN's MAC address, a bridge
 * member's MTU, speed and duplex without media options, addresses on an
 * OpenVPN/IPsec/GIF/GRE interface) cannot be set. $form_post is the
 * unchanged form's POST, $out the GET data (with the *_choices).
 */
function restapi_ifconfig_check(array $changes, array $form_post, array $out) {
	$fields = restapi_ifconfig_fields();
	foreach ($changes as $api => $value) {
		if (!isset($fields[$api])) {
			continue;
		}
		list($field, $type) = $fields[$api];
		if (!$out['address_configurable'] && in_array($api, array('ipv4_type', 'ipv6_type'), true)) {
			throw new RestApiError(400, 'field_unavailable', 'This interface type does not support manual address configuration.');
		}
		if (($type === 'string') && !array_key_exists($field, $form_post)) {
			throw new RestApiError(400, 'field_unavailable', "\"{$api}\" cannot be set on this interface.");
		}
	}
	$enums = array(
		'ipv4_type' => array_keys($out['ipv4_type_choices']),
		'ipv6_type' => array_keys($out['ipv6_type_choices']),
		'gateway' => array_keys($out['gateway_choices']),
		'gatewayv6' => array_keys($out['gatewayv6_choices']),
		'media' => array_keys($out['media_choices']),
		'dhcp6_prefix_delegation_size' => array_keys($out['dhcp6_prefix_delegation_size_choices']),
		'track6_interface' => array_merge(array(''), array_keys($out['track6_interface_choices'])),
	);
	foreach ($enums as $api => $choices) {
		if (array_key_exists($api, $changes) && !in_array((string)$changes[$api], array_map('strval', $choices), true)) {
			throw new RestApiError(400, 'invalid_field', "\"{$api}\" must be one of: " . implode(', ', array_map('strval', $choices)) . '.');
		}
	}
}

/* ------------------------------------------------------------------ */
/* Handlers                                                            */
/* ------------------------------------------------------------------ */

/* The name of an assigned interface (404 otherwise). */
function restapi_ifconfig_name($name) {
	$ifdescrs = get_configured_interface_with_descr(true);
	if (!is_string($name) || !array_key_exists($name, $ifdescrs)) {
		throw new RestApiError(404, 'not_found', 'No assigned interface with that name.');
	}
	return $name;
}

/* Choices as an API map (labels without HTML escaping). */
function restapi_ifconfig_choices(array $options) {
	$out = array();
	foreach ($options as $value => $label) {
		$out[(string)$value] = html_entity_decode((string)$label, ENT_QUOTES);
	}
	return $out;
}

/* GET data of one interface from its context, form values and the unchanged form's POST. */
function restapi_ifconfig_out($name, array $ctx, array $pconfig, array $form_post) {
	$wancfg = $ctx['wancfg'];
	$media = rtrim(config_get_path("interfaces/{$name}/media", "") . ' ' . config_get_path("interfaces/{$name}/mediaopt"));
	$out = array('name' => $name) + restapi_ifconfig_shown(restapi_ifconfig_from_pconfig($pconfig, $media), $form_post);

	$port = (string)($wancfg['if'] ?? '');
	$descrs = function_exists('interfaces_assign_port_descrs') ? interfaces_assign_port_descrs(interfaces_assign_port_list()) : array();
	$out['port'] = $port;
	$out['port_descr'] = html_entity_decode((string)($descrs[$port] ?? ''), ENT_QUOTES);
	$out['real_interface'] = (string)$ctx['realifname'];
	$out['kind'] = restapi_ifconfig_kind($wancfg, $ctx['realifname']);
	$out['bridge'] = empty($ctx['bridged']) ? null : (string)$ctx['bridged'];
	$out['address_configurable'] = (bool)$ctx['show_address_controls'];
	$out['pending'] = in_array($name, interfaces_edit_pending()['interfaces'], true);

	$out['ppp'] = null;
	if ($ctx['ppp_found']) {
		$ppp = (array)array_get_path($ctx['a_ppps'], (string)$ctx['pppid'], []);
		$out['ppp'] = array(
			'type' => (string)($ppp['type'] ?? ''),
			'ports' => (string)($ppp['ports'] ?? ''),
			'username' => (string)($ppp['username'] ?? ''),
			'password' => restapi_svc_mask($ppp['password'] ?? ''),
			'phone' => (string)($ppp['phone'] ?? ''),
			'apn' => (string)($ppp['apn'] ?? ''),
			'provider' => (string)($ppp['provider'] ?? ''),
			'hostuniq' => (string)($ppp['hostuniq'] ?? ''),
			'dial_on_demand' => isset($ppp['ondemand']) && ($ppp['ondemand'] !== false),
			'idle_timeout' => (string)($ppp['idletimeout'] ?? ''),
			'local_ip' => (string)($ppp['localip'] ?? ''),
			'remote_ip' => (string)($ppp['gateway'] ?? ''),
			'reset_type' => (string)($ppp['pppoe-reset-type'] ?? ''),
		);
	}
	$out['wireless'] = null;
	if (is_array($wancfg['wireless'] ?? null)) {
		$out['wireless'] = array(
			'standard' => (string)($pconfig['standard'] ?? ''),
			'mode' => (string)($pconfig['mode'] ?? ''),
			'ssid' => (string)($pconfig['ssid'] ?? ''),
			'channel' => (string)($pconfig['channel'] ?? ''),
			'channel_width' => (string)($pconfig['channel_width'] ?? ''),
			'hide_ssid' => !empty($pconfig['hidessid_enable']),
			'wme' => !empty($pconfig['wme_enable']),
			'wpa' => !empty($pconfig['wpa_enable']),
			'wpa_mode' => (string)($pconfig['wpa_mode'] ?? ''),
			'wpa_key_mgmt' => (string)($pconfig['wpa_key_mgmt'] ?? ''),
			'passphrase' => restapi_svc_mask($pconfig['passphrase'] ?? ''),
		);
	}
	if (($out['ppp'] !== null) || ($out['wireless'] !== null)) {
		$out['note'] = 'PPP and wireless settings are read-only through the API; edit them on the Interfaces page. ' .
		    'A PUT keeps them unchanged.';
	}

	$track6 = interfaces_edit_track6_interfaces();
	$media_choices = array();
	$mediaopts = interfaces_edit_mediaopts($name);
	if (count($mediaopts) > 0) {
		$media_choices = interfaces_edit_media_choices($mediaopts);
		unset($media_choices[' ']);
	}
	$out['ipv4_type_choices'] = restapi_ifconfig_choices(interfaces_edit_ipv4_types($pconfig, $ctx));
	$out['ipv6_type_choices'] = restapi_ifconfig_choices(interfaces_edit_ipv6_types());
	$out['gateway_choices'] = restapi_ifconfig_choices(interfaces_edit_gateway_choices($name, $ctx['a_gateways'], 4));
	$out['gatewayv6_choices'] = restapi_ifconfig_choices(interfaces_edit_gateway_choices($name, $ctx['a_gateways'], 6));
	$out['track6_interface_choices'] = restapi_ifconfig_choices(array_map(function ($t) {
		return $t['name'];
	}, $track6));
	$out['track6_prefix_id_max'] = array_map(function ($t) {
		return sprintf('%x', $t['ipv6_num_prefix_ids']);
	}, $track6);
	$out['media_choices'] = restapi_ifconfig_choices($media_choices);
	$out['dhcp6_prefix_delegation_size_choices'] = restapi_ifconfig_choices(interfaces_edit_pd_len_choices());
	return $out;
}

function restapi_ifconfig_result($name, $wireless_info = false) {
	$ctx = interfaces_edit_context($name, $wireless_info);
	$pconfig = interfaces_edit_config($name, $ctx);
	return restapi_ifconfig_out($name, $ctx, $pconfig, interfaces_edit_form_post($name, $pconfig, $ctx));
}

function restapi_h_ifconfig_list($req) {
	$out = array();
	foreach (get_configured_interface_with_descr(true) as $name => $descr) {
		$out[] = restapi_ifconfig_summary($name, (array)config_get_path("interfaces/{$name}", []), $descr);
	}
	return array('data' => $out);
}

function restapi_h_ifconfig_get($req) {
	return array('data' => restapi_ifconfig_result(restapi_ifconfig_name($req['params']['name'])));
}

function restapi_h_ifconfig_set($req) {
	$name = restapi_ifconfig_name($req['params']['name']);
	$body = $req['body'];

	/* The page's context with the wireless details its save validates against. */
	$ctx = interfaces_edit_context($name, true);
	$pconfig = interfaces_edit_config($name, $ctx);
	$form_post = interfaces_edit_form_post($name, $pconfig, $ctx);
	$out = restapi_ifconfig_out($name, $ctx, $pconfig, $form_post);
	$changes = restapi_ifconfig_changes($body, restapi_svc_merge($body, $out, restapi_ifconfig_types()), $out);
	restapi_ifconfig_check($changes, $form_post, $out);

	$post = restapi_ifconfig_to_post($changes, $form_post);
	$input_errors = array();
	if (!interfaces_edit_save($name, $post, $input_errors, $ctx)) {
		throw restapi_validation_error($input_errors);
	}
	return array('data' => restapi_ifconfig_result($name) + array('applied' => false));
}

function restapi_h_ifconfig_pending($req) {
	return array('data' => interfaces_edit_pending());
}

function restapi_h_ifconfig_apply($req) {
	restapi_ifconfig_confirm($req['body']);
	$input_errors = array();
	$retval = interfaces_edit_apply($input_errors);
	if ($retval === null) {
		return array('data' => array('applied' => false, 'message' => html_entity_decode(strip_tags((string)($input_errors[0] ?? '')), ENT_QUOTES)));
	}
	return array('data' => array('applied' => true, 'filter_reload_status' => (int)$retval));
}
