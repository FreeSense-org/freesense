<?php
/*
 * Standalone CI regression test for the interface configuration API
 * (GET/PUT /api/v1/interfaces/config, interfaces_edit.inc); run with
 * `php tests/RestApiInterfacesConfigSmokeTest.php`.
 */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
if (!defined('DMYPWD')) {
	define('DMYPWD', '********');
}
require_once('restapi/framework.inc');
require_once('restapi/routes_v1.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function api_status(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e->status;
	}
	return null;
}

function api_code(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e->error_code;
	}
	return null;
}

$fn_body = function ($src, $fn) {
	$body = substr($src, strpos($src, "function {$fn}("));
	return substr($body, 0, strpos($body, "\n}\n") + 2);
};

$inc_file = "{$root}/src/usr/local/FreeSense/include/www/interfaces_edit.inc";
$page_file = "{$root}/src/usr/local/www/interfaces.php";
$routes_file = "{$root}/src/etc/inc/restapi/routes_ifconfig.inc";
$inc = file_get_contents($inc_file);
$page = file_get_contents($page_file);
$routes_src = file_get_contents($routes_file);

/* ------------------------------------------------------------------ */
/* Routes and guards                                                   */
/* ------------------------------------------------------------------ */

$v1 = restapi_routes_v1();
$mine = array();
foreach ($v1 as $r) {
	if (strpos($r['path'], '/v1/interfaces/config') === 0) {
		$mine["{$r['method']} {$r['path']}"] = $r;
	}
}
check(array_keys($mine) === array('GET /v1/interfaces/config', 'GET /v1/interfaces/config/pending',
    'POST /v1/interfaces/config/apply', 'GET /v1/interfaces/config/{name}', 'PUT /v1/interfaces/config/{name}'),
    'the interface config routes: list, pending, apply, get and update (static paths before {name})');
foreach ($mine as $key => $r) {
	check($r['page'] === 'interfaces.php' && $r['area'] === 'interfaces', "{$key} is guarded by the interfaces.php privilege (area interfaces)");
	check(function_exists($r['handler']), "{$key} handler exists");
	check($r['write'] === in_array($r['method'], array('PUT', 'POST'), true), "{$key} is a write exactly when it changes state");
}
check($mine['PUT /v1/interfaces/config/{name}']['schema'] === 'interfaces/config', 'a PUT keys its 422 messages by the interfaces/config schema');
list($r) = restapi_match($v1, 'GET', '/v1/interfaces/config/pending');
check($r['handler'] === 'restapi_h_ifconfig_pending', 'GET .../pending is not taken for an interface name');
list($r) = restapi_match($v1, 'POST', '/v1/interfaces/config/apply');
check($r['handler'] === 'restapi_h_ifconfig_apply', 'POST .../apply reaches the apply handler');
list($r, $params) = restapi_match($v1, 'PUT', '/v1/interfaces/config/opt1');
check($r['handler'] === 'restapi_h_ifconfig_set' && $params === array('name' => 'opt1'), 'PUT .../{name} addresses an interface by name');
check(api_status(function () use ($v1) { restapi_match($v1, 'DELETE', '/v1/interfaces/config/wan'); }) === 405, 'interfaces cannot be deleted here');

/* Apply requires {"confirm": true} and checks it before applying anything */
check(api_status(function () { restapi_ifconfig_confirm(array()); }) === 400 &&
    api_code(function () { restapi_ifconfig_confirm(array('confirm' => 'yes')); }) === 'confirmation_required' &&
    api_status(function () { restapi_ifconfig_confirm(array('confirm' => 1)); }) === 400, 'apply without {"confirm": true} is a 400');
check(api_status(function () { restapi_ifconfig_confirm(array('confirm' => true)); }) === null, '{"confirm": true} is accepted');
$apply = $fn_body($routes_src, 'restapi_h_ifconfig_apply');
check(strpos($apply, 'restapi_ifconfig_confirm($req[\'body\'])') !== false &&
    strpos($apply, 'restapi_ifconfig_confirm(') < strpos($apply, 'interfaces_edit_apply('), 'the apply handler checks the confirmation first');
$set = $fn_body($routes_src, 'restapi_h_ifconfig_set');
check(strpos($set, 'interfaces_edit_save(') !== false && strpos($set, 'interfaces_edit_apply(') === false &&
    strpos($set, 'restapi_validation_error($input_errors)') !== false, 'a PUT saves through the page\'s save (pending), never applies, and returns its errors as a 422');
check(strpos($set, 'restapi_ifconfig_check(') < strpos($set, 'interfaces_edit_save('), 'a PUT checks the fields against the form before saving');
check(strpos($routes_src, 'write_config(') === false && strpos($routes_src, 'config_set_path(') === false,
    'the API writes interfaces only through interfaces_edit_save()');

/* ------------------------------------------------------------------ */
/* Field mapping (API <-> the page's POST)                             */
/* ------------------------------------------------------------------ */

$fields = restapi_ifconfig_fields();
foreach (array('enable', 'description', 'ipv4_type', 'ipv4_address', 'ipv4_subnet', 'gateway', 'ipv6_type', 'ipv6_address',
    'ipv6_subnet', 'gatewayv6', 'track6_interface', 'track6_prefix_id', 'spoof_mac', 'mtu', 'mss', 'media', 'block_private',
    'block_bogons', 'dhcp_hostname', 'dhcp_alias_address', 'dhcp_reject_from', 'dhcp6_prefix_delegation_size',
    'dhcp6_send_prefix_hint', 'dhcp6_use_ipv4', 'slaac_use_ipv4') as $api) {
	check(isset($fields[$api]), "API field {$api} is writable");
}
check(count(array_unique(array_column($fields, 0))) === count($fields), 'every API field has its own form field');
foreach ($fields as $api => $def) {
	check(preg_match('/^[a-z0-9_]+$/', $api) === 1 && in_array($def[1], array('bool', 'string'), true), "{$api}: snake_case name, bool or string");
}

$base = array('descr' => 'LAN', 'type' => 'staticv4', 'type6' => 'none', 'ipaddr' => '192.168.1.1', 'subnet' => '24', 'gateway' => 'none',
    'enable' => 'yes', 'blockbogons' => 'yes', 'mtu' => '', 'save' => 'Save', 'if' => 'lan');
$post = restapi_ifconfig_to_post(array('ipv4_address' => '10.0.0.1', 'ipv4_subnet' => 8, 'block_bogons' => false, 'block_private' => true,
    'enable' => true, 'mtu' => '1400', 'track6_prefix_id' => 'a1', 'media' => '1000baseT full-duplex', 'nonsense' => 'x'), $base);
check($post['ipaddr'] === '10.0.0.1' && $post['subnet'] === '8' && !isset($post['blockbogons']) && $post['blockpriv'] === 'yes' &&
    $post['enable'] === 'yes' && $post['mtu'] === '1400' && $post['track6-prefix-id--hex'] === 'a1' &&
    $post['mediaopt'] === '1000baseT full-duplex' && !isset($post['nonsense']),
    'API values become the form fields (a cleared boolean unticks its checkbox; values are strings)');
check($post['descr'] === 'LAN' && $post['gateway'] === 'none' && $post['save'] === 'Save' && $post['if'] === 'lan',
    'fields the request does not name keep the form\'s values');

/* Both directions: every field survives API -> POST -> API */
$values = array();
foreach ($fields as $api => $def) {
	$values[$api] = ($def[1] === 'bool') ? (strlen($api) % 2 === 0) : "v-{$api}";
}
check(restapi_ifconfig_from_post(restapi_ifconfig_to_post($values, array())) === $values, 'API -> POST -> API is lossless for every field');
$all_on = array();
foreach ($fields as $api => $def) {
	$all_on[$def[0]] = ($def[1] === 'bool') ? 'yes' : "p-{$def[0]}";
}
check(restapi_ifconfig_to_post(restapi_ifconfig_from_post($all_on), array()) === $all_on, 'POST -> API -> POST is lossless for every field');

/* The form values ($pconfig) as API fields */
$pconfig = array('enable' => true, 'descr' => 'WAN', 'type' => 'dhcp', 'type6' => 'track6', 'track6-interface' => 'opt2',
    'track6-prefix-id' => 26, 'blockpriv' => true, 'blockbogons' => false, 'spoofmac' => null, 'mtu' => '1492', 'mss' => null,
    'dhcphostname' => 'fw', 'dhcp6-ia-pd-len' => null);
$api = restapi_ifconfig_from_pconfig($pconfig, '1000baseT full-duplex');
check($api['enable'] === true && $api['block_private'] === true && $api['block_bogons'] === false && $api['ipv4_type'] === 'dhcp' &&
    $api['ipv6_type'] === 'track6' && $api['track6_interface'] === 'opt2' && $api['track6_prefix_id'] === '1a' &&
    $api['mtu'] === '1492' && $api['mss'] === '' && $api['spoof_mac'] === '' && $api['dhcp_hostname'] === 'fw' &&
    $api['media'] === '1000baseT full-duplex' && $api['dhcp6_prefix_delegation_size'] === 'none' && $api['description'] === 'WAN',
    'the form values read as API fields (prefix ID in hexadecimal, unset delegation size "none")');
check(restapi_ifconfig_from_pconfig(array())['track6_prefix_id'] === '0' && restapi_ifconfig_from_pconfig(array())['ipv4_type'] === 'none',
    'an empty form reads as type none, prefix ID 0');

/* Selects read as the option the form shows */
$shown = restapi_ifconfig_shown(array('gateway' => '', 'ipv4_type' => 'dhcp', 'media' => 'x'), array('gateway' => 'none', 'type' => 'dhcp'));
check($shown['gateway'] === 'none' && $shown['ipv4_type'] === 'dhcp' && $shown['media'] === 'x',
    'a select reads as the option the form shows; a select the form lacks keeps the stored value');

/* A PUT changes only what differs, so a GET result can be sent back */
$current = array('enable' => true, 'mtu' => '1500', 'gateway' => 'none', 'spoof_mac' => '');
check(restapi_ifconfig_changes(array('enable' => true, 'mtu' => 1500, 'gateway' => 'none', 'kind' => 'vlan'),
    array('enable' => true, 'mtu' => '1500', 'gateway' => 'none', 'kind' => 'vlan'), $current) === array(),
    'values equal to the current ones are no change (read-only fields are ignored)');
check(restapi_ifconfig_changes(array('enable' => false, 'mtu' => '9000'), array('enable' => false, 'mtu' => '9000'), $current) ===
    array('enable' => false, 'mtu' => '9000'), 'changed values are the PUT\'s changes');

/* Body types (restapi_svc_merge with the field types) */
$types = restapi_ifconfig_types();
check(api_code(function () use ($types) { restapi_svc_merge(array('bogus' => 1), array(), $types); }) === 'unknown_field', 'unknown fields are a 400');
check(api_status(function () use ($types) { restapi_svc_merge(array('enable' => 'yes'), array(), $types); }) === 400, 'booleans must be booleans');
check(api_status(function () use ($types) { restapi_svc_merge(array('mtu' => array(1)), array(), $types); }) === 400, 'strings must be scalars');
check(restapi_svc_merge(array('mtu' => 1500, 'kind' => 'x', 'choices' => null) + array(), array('kind' => 'ethernet'),
    $types + array('choices' => 'ro'))['mtu'] === '1500', 'numbers are accepted as strings; read-only fields are ignored');

/* Fields and choices the form does not offer are refused */
$out = array('address_configurable' => true, 'ipv4_type_choices' => array('none' => 'None', 'staticv4' => 'Static IPv4', 'dhcp' => 'DHCP'),
    'ipv6_type_choices' => array('none' => 'None', 'track6' => 'Track'), 'gateway_choices' => array('none' => 'None', 'WANGW' => 'WANGW - 192.0.2.1'),
    'gatewayv6_choices' => array('none' => 'None'), 'media_choices' => array(), 'dhcp6_prefix_delegation_size_choices' => array('none' => 'None', 0 => '64'),
    'track6_interface_choices' => array('wan' => 'WAN'));
$form = array('type' => 'staticv4', 'type6' => 'none', 'ipaddr' => '', 'gateway' => 'none', 'gatewayv6' => 'none', 'mtu' => '',
    'track6-interface' => '', 'dhcp6-ia-pd-len' => 'none');
check(api_status(function () use ($form, $out) { restapi_ifconfig_check(array('gateway' => 'WANGW', 'ipv4_type' => 'dhcp', 'mtu' => '1400',
    'dhcp6_prefix_delegation_size' => '0', 'track6_interface' => 'wan', 'enable' => false), $form, $out); }) === null, 'listed choices pass');
check(api_code(function () use ($form, $out) { restapi_ifconfig_check(array('gateway' => 'OTHERGW'), $form, $out); }) === 'invalid_field' &&
    api_code(function () use ($form, $out) { restapi_ifconfig_check(array('ipv4_type' => 'pppoe'), $form, $out); }) === 'invalid_field' &&
    api_code(function () use ($form, $out) { restapi_ifconfig_check(array('track6_interface' => 'lan'), $form, $out); }) === 'invalid_field',
    'a gateway, type or tracked interface the form does not offer is a 400');
check(api_code(function () use ($form, $out) { restapi_ifconfig_check(array('spoof_mac' => '00:11:22:33:44:55'), $form, $out); }) === 'field_unavailable' &&
    api_code(function () use ($form, $out) { restapi_ifconfig_check(array('media' => 'autoselect'), $form, $out); }) === 'field_unavailable',
    'a field the form does not show for this interface (VLAN MAC address, media without options) is a 400');
$out['address_configurable'] = false;
check(api_code(function () use ($form, $out) { restapi_ifconfig_check(array('ipv4_type' => 'none'), $form, $out); }) === 'field_unavailable',
    'OpenVPN/IPsec/GIF/GRE interfaces have no address configuration here');

/* Summaries and kinds */
$s = restapi_ifconfig_summary('lan', array('if' => 'em1', 'enable' => '', 'ipaddr' => '192.168.1.1', 'subnet' => '24', 'ipaddrv6' => 'track6',
    'gateway' => 'GW', 'mtu' => '1500'), 'LAN');
check($s === array('name' => 'lan', 'descr' => 'LAN', 'enable' => true, 'port' => 'em1', 'ipv4_type' => 'staticv4', 'ipv4_address' => '192.168.1.1',
    'ipv4_subnet' => '24', 'ipv6_type' => 'track6', 'ipv6_address' => '', 'ipv6_subnet' => '', 'gateway' => 'GW', 'gatewayv6' => '', 'mtu' => '1500'),
    'a static LAN summary has the list fields');
$s = restapi_ifconfig_summary('wan', array('if' => 'pppoe0', 'ipaddr' => 'pppoe', 'ipaddrv6' => '2001:db8::1', 'subnetv6' => '64', 'gatewayv6' => 'GW6'));
check($s['descr'] === 'WAN' && $s['enable'] === false && $s['ipv4_type'] === 'pppoe' && $s['ipv4_address'] === '' &&
    $s['ipv6_type'] === 'staticv6' && $s['ipv6_subnet'] === '64' && $s['gatewayv6'] === 'GW6', 'a PPPoE WAN with static IPv6');
check(restapi_ifconfig_summary('opt1', array('if' => 'em2', 'ipaddr' => 'dhcp', 'ipaddrv6' => 'dhcp6'), 'OPT1')['ipv4_type'] === 'dhcp' &&
    restapi_ifconfig_summary('opt2', array('if' => 'l2tp1'), 'OPT2')['ipv4_type'] === 'l2tp' &&
    restapi_ifconfig_summary('opt3', array('if' => 'em3', 'ipaddr' => 'none?'), 'OPT3')['ipv4_type'] === 'none', 'summary types');
foreach (array(array(array('if' => 'em0'), 'em0', 'ethernet'), array(array('if' => 'em0.100'), 'em0.100', 'vlan'),
    array(array('if' => 'lagg0.10'), 'lagg0.10', 'vlan'), array(array('if' => 'lagg0'), 'lagg0', 'lagg'),
    array(array('if' => 'em0', 'ipaddr' => 'pppoe'), 'pppoe0', 'pppoe'), array(array('if' => 'pppoe1'), 'pppoe1', 'pppoe'),
    array(array('if' => 'ath0_wlan0', 'wireless' => array()), 'ath0_wlan0', 'wireless'), array(array('if' => 'ovpns1'), 'ovpns1', 'openvpn'),
    array(array('if' => 'ipsec1'), 'ipsec1', 'ipsec'), array(array('if' => 'gre0'), 'gre0', 'gre'), array(array('if' => 'bridge0'), 'bridge0', 'bridge')) as $k) {
	check(restapi_ifconfig_kind($k[0], $k[1]) === $k[2], "{$k[1]} is a {$k[2]} interface");
}

/* ------------------------------------------------------------------ */
/* The unchanged form's POST (interfaces_edit_form_post), with stubs   */
/* ------------------------------------------------------------------ */

$GLOBALS['fake_config'] = array('interfaces' => array(
	'wan' => array('if' => 'em0', 'descr' => 'WAN', 'enable' => '', 'ipaddr' => 'dhcp', 'ipaddrv6' => 'dhcp6', 'blockpriv' => '', 'blockbogons' => ''),
	'lan' => array('if' => 'em1', 'descr' => 'LAN', 'enable' => '', 'ipaddr' => '192.168.1.1', 'subnet' => '24', 'ipaddrv6' => 'track6',
	    'track6-interface' => 'wan', 'track6-prefix-id' => '0'),
	'opt1' => array('if' => 'em1.100', 'descr' => 'VLAN100', 'ipaddr' => '10.1.0.1', 'subnet' => '24', 'gateway' => 'V100GW'),
));
function array_get_path(array &$arr, string $path, $default = null) {
	$el = $arr;
	foreach (explode('/', $path) as $key) {
		if (!is_array($el) || !array_key_exists($key, $el)) {
			return $default;
		}
		$el = $el[$key];
	}
	return $el;
}
function array_set_path(array &$arr, string $path, $value, $default = null) {
	$el = &$arr;
	foreach (explode('/', $path) as $key) {
		if (!isset($el[$key]) || !is_array($el[$key])) {
			$el[$key] = isset($el[$key]) ? $el[$key] : array();
		}
		$el = &$el[$key];
	}
	$el = $value;
	return $value;
}
function config_get_path(string $path, $default = null) {
	return array_get_path($GLOBALS['fake_config'], $path, $default);
}
function interface_is_vlan($if) {
	return (strpos((string)$if, '.') !== false) ? array('if' => $if) : null;
}
function is_pseudo_interface($if, $x = false) {
	return false;
}
function is_ipaddrv4($a) {
	return filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}
function is_ipaddrv6($a) {
	return filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}
function get_configured_interface_list_by_realif() {
	return array('em0' => 'wan', 'em1' => 'lan');
}
function interfaces_edit_mediaopts($if) {
	return ($if === 'wan') ? array('autoselect', '1000baseT full-duplex') : array();
}
function interfaces_edit_track6_interfaces() {
	return array('wan' => array('name' => 'WAN', 'ipv6_num_prefix_ids' => 255));
}
foreach (array('interfaces_edit_select_value', 'interfaces_edit_form_post', 'interfaces_edit_gateway_choices', 'interfaces_edit_ipv4_types',
    'interfaces_edit_ipv6_types', 'interfaces_edit_pd_len_choices', 'interfaces_edit_media_choices', 'remove_bad_chars') as $fn) {
	$code = $fn_body($inc, $fn);
	check(strpos($code, "function {$fn}(") === 0, "interfaces_edit.inc defines {$fn}()");
	eval($code);
}

check(interfaces_edit_select_value(null, array_combine(range(32, 1), range(32, 1))) === '32' &&
    interfaces_edit_select_value('24', array_combine(range(32, 1), range(32, 1))) === '24' &&
    interfaces_edit_select_value('GW', array('none' => 'None')) === 'none' &&
    interfaces_edit_select_value(null, array('' => '', 'wan' => 'WAN')) === '' &&
    interfaces_edit_select_value('0', array('none' => 'None', 16 => '48', 0 => '64')) === '0',
    'a select posts the option the page marks selected, else its first option');
check(remove_bad_chars('My LAN-2') === 'MyLAN2', 'descriptions keep letters, digits and underscores');

$ctx = function ($if, array $extra = array()) {
	$wancfg = $GLOBALS['fake_config']['interfaces'][$if];
	return $extra + array('wancfg' => $wancfg, 'a_gateways' => array(array('interface' => 'opt1', 'name' => 'V100GW', 'gateway' => '10.1.0.254'),
	    array('interface' => 'wan', 'name' => 'WAN_DHCP6', 'gateway' => 'dynamic')),
	    'realifname' => $wancfg['if'], 'show_address_controls' => true, 'bridged' => false, 'a_ppps' => array(), 'pppid' => 0,
	    'ppp_found' => false, 'interfaces' => array('wan' => 'WAN', 'lan' => 'LAN', 'opt1' => 'VLAN100'), 'wl_modes' => null);
};

$lan_pconfig = array('enable' => true, 'descr' => 'LAN', 'type' => 'staticv4', 'ipaddr' => '192.168.1.1', 'subnet' => '24', 'gateway' => null,
    'type6' => 'track6', 'track6-interface' => 'wan', 'track6-prefix-id' => '0', 'ptpid' => 0, 'blockpriv' => false, 'spoofmac' => null,
    'mtu' => null, 'mss' => null, 'dhcp6-ia-pd-len' => null);
$lan_post = interfaces_edit_form_post('lan', $lan_pconfig, $ctx('lan'));
check($lan_post['type'] === 'staticv4' && $lan_post['subnet'] === '24' && $lan_post['gateway'] === 'none' && $lan_post['enable'] === 'yes' &&
    !isset($lan_post['blockpriv']) && $lan_post['track6-interface'] === 'wan' && $lan_post['track6-prefix-id--hex'] === '0' &&
    $lan_post['ipv6-num-prefix-ids-wan'] === '255' && $lan_post['descr'] === 'LAN' && $lan_post['if'] === 'lan' && $lan_post['save'] === 'Save' &&
    $lan_post['alias-subnet'] === '32' && $lan_post['subnetv6'] === '128' && $lan_post['dhcp6-ia-pd-len'] === '0' && /* null == 0: the page selects "64" */
    $lan_post['adv_dhcp_pt_values'] === 'SavedCfg' && $lan_post['spoofmac'] === '' && $lan_post['mtu'] === '' &&
    $lan_post['gatewayname4'] === 'LANGW' && !isset($lan_post['defaultgw4']) && !isset($lan_post['mediaopt']) && !isset($lan_post['ppp_port']),
    'the unchanged LAN form posts what the browser sends (selects, hidden prefix ID ranges, empty text fields, no media without options)');
check(array_filter($lan_post, 'is_string') === $lan_post, 'every posted value is a string');
$lan_api = restapi_ifconfig_shown(restapi_ifconfig_from_pconfig($lan_pconfig, ''), $lan_post);
$round = array_intersect_key(restapi_ifconfig_from_post($lan_post), $lan_api);
/* (subnet selects without a stored value show their first option, 32 or 128) */
foreach (array('media', 'ipv6_subnet', 'dhcp_alias_subnet') as $absent) {
	unset($lan_api[$absent], $round[$absent]);
}
check($round === array_intersect_key($lan_api, $round) && count($round) > 20, 'the unchanged form\'s POST reads back as the GET values');

$wan_pconfig = array('enable' => true, 'descr' => 'WAN', 'type' => 'dhcp', 'type6' => 'dhcp6', 'blockpriv' => true, 'blockbogons' => true,
    'dhcp6-ia-pd-len' => '8', 'dhcp6-ia-pd-send-hint' => true, 'adv_dhcp_pt_values' => 'DHCP', 'adv_dhcp_pt_timeout' => '5');
$wan_post = interfaces_edit_form_post('wan', $wan_pconfig, $ctx('wan', array('bridged' => 'bridge0')));
check($wan_post['mediaopt'] === '' && $wan_post['dhcp6-ia-pd-len'] === '8' && $wan_post['dhcp6-ia-pd-send-hint'] === 'yes' &&
    $wan_post['blockpriv'] === 'yes' && $wan_post['defaultgw4'] === 'yes' && !isset($wan_post['mtu']) &&
    $wan_post['adv_dhcp_pt_values'] === 'DHCP' && $wan_post['adv_dhcp_pt_timeout'] === '60' && $wan_post['adv_dhcp_pt_retry'] === '300',
    'the WAN form: media autoselect, stored prefix delegation, the DHCP timing preset as the page fills it, no MTU on a bridge member');

$vlan_post = interfaces_edit_form_post('opt1', array('descr' => 'VLAN100', 'type' => 'staticv4', 'gateway' => 'V100GW', 'spoofmac' => 'aa:bb:cc:dd:ee:ff',
    'dhcpvlanenable' => true), $ctx('opt1'));
check(!isset($vlan_post['spoofmac']) && $vlan_post['gateway'] === 'V100GW' && $vlan_post['dhcpvlanenable'] === 'yes' &&
    !isset($vlan_post['enable']) && array_key_exists('dhcpcvpt', $vlan_post),
    'a VLAN posts no MAC address (the page disables it) but the VLAN priority fields');

$ppp_post = interfaces_edit_form_post('wan', array('type' => 'pppoe', 'pppoe_username' => 'u', 'pppoe_password' => 'secret', 'port' => 'em0',
    'pppoe_dialondemand' => true, 'pppoe_monthly' => true, 'pppoe-reset-type' => 'preset', 'ptpid' => '0'),
    $ctx('wan', array('ppp_found' => true, 'pppid' => 0, 'a_ppps' => array(array('ports' => 'em0', 'type' => 'pppoe')))));
check($ppp_post['type'] === 'pppoe' && $ppp_post['pppoe_password'] === DMYPWD && $ppp_post['pppoe_password_confirm'] === DMYPWD &&
    $ppp_post['pppoe_dialondemand'] === 'enable' && $ppp_post['pppoe_pr_preset_val'] === 'monthly' && $ppp_post['ppp_port'] === 'em0' &&
    $ppp_post['pppoe-reset-type'] === 'preset' && $ppp_post['ppp_password'] === '',
    'a PPPoE interface keeps its PPP settings: passwords post the placeholder that keeps them');

/* ------------------------------------------------------------------ */
/* The page is a thin wrapper around interfaces_edit.inc               */
/* ------------------------------------------------------------------ */

check(strpos($inc, '$_POST') === false && strpos($inc, '$_REQUEST') === false && strpos($inc, '$_GET') === false &&
    strpos($inc, 'header(') === false && strpos($inc, 'exit;') === false, 'interfaces_edit.inc takes its form fields as parameters and never redirects');
foreach (array('interfaces_edit_context($if)', 'interfaces_edit_config($if, $ctx)', 'interfaces_edit_save($if, $_POST, $input_errors, $ctx)',
    'interfaces_edit_apply($input_errors)', 'interfaces_edit_mediaopts($if)', 'interfaces_edit_ipv4_types($pconfig, $ctx)') as $call) {
	check(strpos($page, $call) !== false, "interfaces.php calls {$call}");
}
check(strpos($page, 'write_config(') === false && strpos($page, 'mark_subsystem_dirty(') === false && strpos($page, 'interface_bring_down(') === false &&
    strpos($page, 'function handle_wireless_post(') === false && strpos($page, 'function check_wireless_mode(') === false &&
    strpos($page, 'function remove_bad_chars(') === false, 'interfaces.php saves and applies nothing itself');
check(strpos($page, 'header("Location: interfaces.php?if={$if}");') > strpos($page, 'interfaces_edit_save('), 'the page still redirects after a save');
$save = $fn_body($inc, 'interfaces_edit_save');
foreach (array('write_config("Interfaces settings changed");', "mark_subsystem_dirty('interfaces');", 'array_set_path($toapplylist, "{$if}/ifcfg", $old_wancfg);',
    'file_put_contents(interfaces_edit_apply_file(), serialize($toapplylist));', 'handle_pppoe_reset($post);', 'configure_cron();',
    'interfaces_edit_handle_wireless_post($post, $wancfg, $wlanbaseif, $wl_countries_attr);',
    'interfaces_edit_check_wireless_mode($post, $wancfg, $wlanif, $wlanbaseif, $input_errors);',
    'validate_gateway($gateway_settings4', 'save_gateway($gateway_settings4);') as $needle) {
	check(strpos($save, $needle) !== false, "interfaces_edit_save() keeps {$needle}");
}
check(strpos($save, 'if (!$input_errors) {') < strpos($save, 'write_config('), 'the save validates before it writes');
check(strpos($save, 'interface_configure(') === false && strpos($save, 'filter_configure(') === false, 'a save only records the change (nothing is applied)');
$apply_fn = $fn_body($inc, 'interfaces_edit_apply');
foreach (array("clear_subsystem_dirty('interfaces');", 'interface_configure($ifapply, true);', 'filter_configure();', 'setup_gateways_monitor();',
    "send_event(\"service reload packages\");", '@unlink(interfaces_edit_apply_file());') as $needle) {
	check(strpos($apply_fn, $needle) !== false, "interfaces_edit_apply() keeps {$needle}");
}
check(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('interfaces_edit.inc');") !== false,
    'the API front controller loads interfaces_edit.inc');
check(strpos($inc, "Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)") !== false &&
    strpos($inc, "Copyright (c) 2025-2026 The FreeSense Project") !== false, 'interfaces_edit.inc keeps the page\'s copyright lines');

/* ------------------------------------------------------------------ */
/* Schema                                                              */
/* ------------------------------------------------------------------ */

check(restapi_schemas()['interfaces/config'] === array('restapi_schema_interfaces_config', 'interfaces.php', 'interfaces'),
    'the interfaces/config schema is registered with the interfaces.php privilege');
$schema = restapi_schema_get('interfaces/config');
$known = array('text', 'number', 'secret', 'select', 'checklist', 'switch', 'segmented', 'textarea', 'address', 'port', 'typeahead',
    'entry-grid', 'file', 'datetime', 'color');
$sfields = restapi_schema_fields($schema);
$ids = array_column($schema['sections'], 'id');
check($ids === array('general', 'ipv4', 'ipv6', 'dhcp', 'reserved') && $schema['order'] === $ids, 'sections: General, IPv4, IPv6, DHCP client, Reserved networks');
foreach ($schema['sections'] as $sec) {
	foreach ($sec['fields'] as $f) {
		check(!empty($f['label']) && in_array($f['type'], $known, true), "{$f['name']}: a label and a known type");
		check(isset($fields[$f['name']]), "{$f['name']}: a schema field is a writable API field");
		if (isset($f['visibleWhen']['field'])) {
			check(isset($sfields[$f['visibleWhen']['field']]), "{$f['name']}: visibleWhen names a schema field");
		}
		if (isset($f['optionsFrom'])) {
			check(in_array($f['optionsFrom'], restapi_ifconfig_ro_fields(), true), "{$f['name']}: options come from a GET field");
		}
	}
}
check(count($sfields) === count($fields) && !array_diff_key($fields, $sfields), 'every writable API field is in the schema');
$dhcp_sec = $schema['sections'][3];
check(!empty($dhcp_sec['advanced']) && ($dhcp_sec['visibleWhen'] ?? null) === array('field' => 'ipv4_type', 'equals' => 'dhcp'),
    'the DHCP client section is advanced and shown for DHCP');
check(array_column($schema['sections'][0]['fields'][3]['options'], 'value') === restapi_ifconfig_ipv6_types() &&
    array_column($schema['sections'][0]['fields'][2]['options'], 'value') === restapi_ifconfig_ipv4_types(), 'the type options are the API enums');

/* The page's validation messages land on their fields */
$messages = array(
	'The field "Description" is required.' => 'description',
	'An interface with the specified description already exists.' => 'description',
	'Cannot use a reserved keyword as an interface name: pass' => 'description',
	'Sorry, an alias with the name LAN already exists.' => 'description',
	'OpenVPN and VTI interface descriptions must be less than 26 characters long.' => 'description',
	'The DHCP Server is active on this interface and it can be used only with a static IP configuration. Please disable the DHCP Server service on this interface first, then change the interface configuration.' => 'ipv4_type',
	'The DHCP Server is active on this interface and it can be used only with IPv4 subnet < 31. Please disable the DHCP Server service on this interface first, then change the interface configuration.' => 'ipv4_subnet',
	'The Router Advertisements Server is active on this interface and it can be used only with a static IPv6 configuration. Please disable the Router Advertisements Server service on this interface first, then change the interface configuration.' => 'ipv6_type',
	'This interface is referenced by IPv4 VIPs. Please delete these VIPs before setting the interface configuration type to \'none\'.' => 'ipv4_type',
	'The field "IPv4 address" is required.' => 'ipv4_address',
	'A valid IPv4 address must be specified.' => 'ipv4_address',
	'IPv4 address 10.0.0.1/24 is being used by or overlaps with: LAN (10.0.0.0/24)' => 'ipv4_address',
	'This IPv4 address is the network address and cannot be used' => 'ipv4_address',
	'A valid subnet bit count must be specified.' => 'ipv4_subnet',
	'A valid IPv4 gateway must be specified.' => 'gateway',
	'A valid IPv6 gateway must be specified.' => 'gatewayv6',
	'The field "IPv6 address" is required.' => 'ipv6_address',
	'IPv6 link local addresses cannot be configured as an interface IP address.' => 'ipv6_address',
	'DHCPv6 Prefix Delegation size must be provided when Send IPv6 prefix hint flag is checked' => 'dhcp6_prefix_delegation_size',
	'6RD Prefix must be a valid IPv6 prefix.' => 'prefix_6rd',
	'6RD Border Relay must be an IPv4 address.' => 'gateway_6rd',
	'Only one interface can be configured as 6to4.' => 'ipv6_type',
	'A valid interface to track must be selected.' => 'track6_interface',
	'A valid hexadecimal number must be entered for the IPv6 prefix ID.' => 'track6_prefix_id',
	'This track6 prefix ID is already being used in LAN.' => 'track6_prefix_id',
	'A valid alias IP address must be specified.' => 'dhcp_alias_address',
	'A valid alias subnet bit count must be specified.' => 'dhcp_alias_subnet',
	'An invalid IP address was detected in the \'Reject leases from\' field.' => 'dhcp_reject_from',
	'A valid MAC address must be specified.' => 'spoof_mac',
	'The MTU must be between 576 and 9000 bytes.' => 'mtu',
	'The MTU of a VLAN cannot be greater than that of its parent interface.' => 'mtu',
	'The MSS must be an integer between 576 and 65535 bytes.' => 'mss',
	'In order to block bogon networks the Firewall Maximum Table Entries value in System / Advanced / Firewall must be increased at least to 400000.' => 'block_bogons',
);
foreach ($messages as $msg => $field) {
	$r = restapi_errors_to_fields(array($msg), $schema);
	check(array_keys($r['fields']) === array($field), "\"{$msg}\" maps to {$field} (got " . implode(',', array_keys($r['fields'])) . ')');
}
$r = restapi_errors_to_fields(array('PPPoE Password and confirmed password must match!'), $schema);
check($r['fields'] === array() && count($r['unmatched']) === 1, 'messages about read-only PPP settings stay in the error summary');

echo "REST API interface config smoke test passed.\n";
