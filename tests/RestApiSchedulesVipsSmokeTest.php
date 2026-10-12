<?php
/*
 * Standalone CI regression test for the schedule and virtual IP API surface
 * of the WebUI 2.0 (schemas, "fields"/"display", password handling, strict
 * schedule validation); run with `php tests/RestApiSchedulesVipsSmokeTest.php`.
 */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('restapi/framework.inc');
require_once('restapi/schema.inc');
require_once('restapi/routes_firewall.inc');

/* The GUI save code reads optional form fields without isset(); that is all it may warn about. */
set_error_handler(function ($no, $msg, $file, $line) {
	if ((strpos($msg, 'Undefined array key') === 0) || ($msg === 'Undefined variable $errmsg')) {
		return true;
	}
	fwrite(STDERR, "FAIL: PHP warning: {$msg} ({$file}:{$line})\n");
	exit(1);
}, E_WARNING | E_NOTICE | E_DEPRECATED);

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function fn_source($code, $name) {
	$start = strpos($code, "function {$name}(");
	check($start !== false, "function {$name}() exists");
	return substr($code, $start, strpos($code, "\n}\n", $start) - $start) . "\n}\n";
}

/* Raises the RestApiError of $fn, or null. */
function api_error(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e;
	}
	return null;
}

/* ---- Config and system stubs ---- */
define('VIP_ALL', 1);
define('VIP_CARP', 2);
define('DMYPWD', '********');
$GLOBALS['config'] = array();
function config_get_path($path, $default = null) {
	$node = $GLOBALS['config'];
	foreach (explode('/', $path) as $key) {
		if (!is_array($node) || !array_key_exists($key, $node)) {
			return $default;
		}
		$node = $node[$key];
	}
	return $node;
}
function config_set_path($path, $value) {
	$node = &$GLOBALS['config'];
	foreach (explode('/', $path) as $key) {
		if (!isset($node[$key]) || !is_array($node[$key])) {
			$node[$key] = array();
		}
		$node = &$node[$key];
	}
	$node = $value;
}
function write_config($desc) { $GLOBALS['writes'][] = $desc; return true; }
function filter_configure() { return 0; }
function is_validaliasname($name) { return (bool)preg_match('/^(?!\d+$)(?!_+$)[A-Za-z0-9_]{1,31}$/', (string)$name); }
function invalidaliasnamemsg($name, $object = 'alias') {
	return sprintf('The %1$s name must be less than 32 characters long, may not consist of only numbers, may not consist of only underscores, and may only contain the following characters: %2$s', $object, 'a-z, A-Z, 0-9, _');
}
function get_filter_rules_list() { return $GLOBALS['rules']; }
function is_schedule_inuse($name) {
	foreach (get_filter_rules_list() as $rule) {
		if (($rule['sched'] ?? '') == $name) {
			return true;
		}
	}
	return false;
}
function filter_get_time_based_rule_status($schedule) { return ($schedule['name'] ?? '') === 'Office'; }
function get_configured_interface_with_descr($all = false) { return array('wan' => 'WAN', 'lan' => 'LAN'); }
function get_configured_vip_list($family = 'all', $type = VIP_ALL) {
	$out = array();
	foreach (config_get_path('virtualip/vip', array()) as $vip) {
		if (($type === VIP_ALL) || ($vip['mode'] === 'carp')) {
			if (!empty($vip['uniqid'])) {
				$out["_vip{$vip['uniqid']}"] = $vip['subnet'];
			}
		}
	}
	return $out;
}
function get_vip_descr($address) {
	foreach (config_get_path('virtualip/vip', array()) as $vip) {
		if ($vip['subnet'] === $address) {
			return $vip['descr'] ?? '';
		}
	}
	return '';
}
function get_configured_vip($name) { return array(); }
function find_last_used_vhid() { return 5; }
function do_input_validation($post, $reqd, $reqdn, &$errors) {
	foreach ($reqd as $i => $f) {
		if (empty($post[$f])) {
			$errors[] = sprintf(gettext('The field %s is required.'), $reqdn[$i]);
		}
	}
}
function saveVIP($post, $json = false) { $GLOBALS['vip_saved'] = $post; return json_encode(array('input_errors' => array())); }
function mark_subsystem_dirty($s) {}
function is_subsystem_dirty($s) { return false; }

/* The real schedule save code of the 1.x pages. */
$sched_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/firewall_schedule.inc");
foreach (array('deleteSchedule', 'schedulecmp', 'schedule_sort', 'saveSchedule', 'schedule_strict_errors') as $fn) {
	eval(fn_source($sched_inc, $fn));
}

/* ---- Schemas ---- */
$vs = restapi_schema_get('firewall/virtual_ips');
$ss = restapi_schema_get('firewall/schedules');
check(is_array($vs) && is_array($ss) && $vs['resource'] === 'firewall/virtual_ips' && $ss['resource'] === 'firewall/schedules', 'both schemas are registered');
check(restapi_schemas()['firewall/virtual_ips'][1] === 'firewall_virtual_ip_edit.php' && restapi_schemas()['firewall/virtual_ips'][2] === 'firewall.vips' &&
    restapi_schemas()['firewall/schedules'][1] === 'firewall_schedule_edit.php' && restapi_schemas()['firewall/schedules'][2] === 'firewall.schedules',
    'reading a schema needs the edit page privilege of its area');
$vf = restapi_schema_fields($vs);
check(array_diff(array_keys($vf), array('mode', 'interface', 'type', 'subnet', 'subnet_bits', 'noexpand', 'vhid', 'advbase', 'advskew', 'password', 'descr')) === array(),
    'every virtual IP schema field is an edit page form field');
$byname = array();
foreach ($vs['sections'] as $sec) {
	foreach ($sec['fields'] as $f) {
		$byname[$f['name']] = $f + array('section' => $sec);
	}
}
check(array_column($byname['mode']['options'], 'value') === array('ipalias', 'carp', 'proxyarp', 'other') && $byname['mode']['type'] === 'segmented',
    'the mode is a segmented choice of the four VIP types');
check(count($byname['vhid']['options']) === 255 && $byname['vhid']['default'] === '6' && count($byname['advskew']['options']) === 255,
    'VHID 1-255 (default: the next unused), skew 0-254');
check($byname['password']['type'] === 'secret' && $byname['password']['visibleWhen'] === array('field' => 'mode', 'equals' => 'carp') &&
    strpos($byname['password']['help'], 'leave it empty to keep') !== false, 'the CARP password is a secret field, only for CARP, documented as kept when empty');
check($byname['vhid']['section']['visibleWhen'] === array('field' => 'mode', 'equals' => 'carp'), 'the CARP section shows only for CARP');
check($byname['type']['visibleWhen']['in'] === array('proxyarp', 'other') && $byname['noexpand']['type'] === 'switch', 'address type and expansion for Proxy ARP and Other only');
$GLOBALS['config']['virtualip']['vip'] = array(array('mode' => 'carp', 'interface' => 'wan', 'subnet' => '198.51.100.10', 'subnet_bits' => '24',
    'uniqid' => 'c1', 'vhid' => '5', 'descr' => 'Cluster'));
$ifopts = array_column(restapi_schema_get('firewall/virtual_ips')['sections'][0]['fields'][1]['options'], 'label', 'value');
check($ifopts === array('wan' => 'WAN', 'lan' => 'LAN', '_vipc1' => '198.51.100.10 (Cluster)', 'lo0' => 'Localhost'),
    'interface choices are the edit page\'s (interfaces, CARP parents, Localhost)');

$sf = restapi_schema_fields($ss);
check(array_keys($sf) === array('name', 'descr', 'ranges', 'ranges.*.weekdays', 'ranges.*.dates', 'ranges.*.start', 'ranges.*.stop', 'ranges.*.description'),
    'schedule schema: name, descr and a ranges grid {weekdays, dates, start, stop, description}');
$grid = $ss['sections'][1]['fields'][0];
check($grid['type'] === 'entry-grid' && $grid['fields'][0]['type'] === 'checklist' && array_column($grid['fields'][0]['options'], 'value') === array('1', '2', '3', '4', '5', '6', '7') &&
    $grid['fields'][2]['type'] === 'datetime' && $grid['fields'][2]['mode'] === 'time', 'weekdays are a checklist 1-7, times are time inputs');

/* ---- Virtual IP save messages land on their fields ---- */
$vip_src = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/firewall_virtual_ip.inc");
$cases = array(
	array('The interface chosen for the VIP does not support CARP mode.', null, 'interface'),
	array('The interface chosen for the VIP does not support Proxy ARP mode.', null, 'interface'),
	array('A valid IP address must be specified.', null, 'subnet'),
	array('This IP address is being used by another interface or VIP.', null, 'subnet'),
	array('The interface chosen for the VIP has no IPv4 or IPv6 address configured so it cannot be used as a parent for the VIP.', null, 'interface'),
	array('%1$s tunnel %2$s is sent from this VIP, so it must stay an IP Alias or CARP address of the same address family.', array('GRE', 'gre0'), 'mode'),
	array('The network address cannot be used for this VIP', null, 'subnet'),
	array('The broadcast address cannot be used for this VIP', null, 'subnet'),
	array('VHID %1$s is already in use on interface %2$s. Pick a unique number on this interface.', array('5', 'WAN'), 'vhid'),
	array('A CARP password that is shared between the two VHID members must be specified.', null, 'password'),
	array('Password and confirm password must match', null, 'password'),
	array('For this type of vip localhost is not allowed.', null, 'interface'),
	array('A CARP parent interface can only be used with IP Alias type Virtual IPs.', null, 'interface'),
	array('An IPv4 Virtual IP cannot have an IPv6 CARP parent.', null, 'interface'),
	array('An IPv6 Virtual IP cannot have an IPv4 CARP parent.', null, 'interface'),
	array('Only IPv4 addresses are valid for Proxy ARP VIPs.', null, 'subnet'),
);
foreach ($cases as list($template, $args, $field)) {
	check(strpos($vip_src, $template) !== false, "saveVIP() still says \"{$template}\"");
	$msg = $args ? vsprintf($template, $args) : $template;
	$r = restapi_errors_to_fields(array($msg), $vs, array());
	check(array_keys($r['fields']) === array($field), "\"{$msg}\" lands on {$field}" . (empty($r['fields']) ? '' : ' (got ' . key($r['fields']) . ')'));
}
$r = restapi_errors_to_fields(array('The field Type is required.'), $vs, array());
check(array_keys($r['fields']) === array('mode'), 'do_input_validation()\'s required Type lands on the mode');

/* ---- Virtual IP output ---- */
$GLOBALS['config']['virtualip']['vip'] = array(
	array('mode' => 'ipalias', 'interface' => 'lan', 'subnet' => '192.0.2.5', 'subnet_bits' => '24', 'type' => 'single', 'uniqid' => 'a1', 'descr' => 'Alias'),
	array('mode' => 'carp', 'interface' => 'wan', 'subnet' => '198.51.100.10', 'subnet_bits' => '24', 'type' => 'single', 'uniqid' => 'c1',
	    'vhid' => '5', 'advbase' => '1', 'advskew' => '0', 'password' => 'S3cretCarp', 'descr' => 'Cluster'),
	array('mode' => 'proxyarp', 'interface' => 'wan', 'subnet' => '203.0.113.0', 'subnet_bits' => '28', 'type' => 'network', 'noexpand' => '', 'descr' => ''),
	array('mode' => 'ipalias', 'interface' => '_vipc1', 'subnet' => '198.51.100.11', 'subnet_bits' => '24', 'type' => 'single', 'uniqid' => 'a2'),
	array('mode' => 'other', 'interface' => 'wan', 'subnet' => '203.0.113.50', 'type' => 'single'),
);
$list = restapi_h_vip_list(array())['data'];
check(strpos(json_encode($list), 'S3cretCarp') === false, 'the list never contains the CARP password');
check($list[0]['display'] === array('type' => 'IP Alias', 'interface' => 'LAN', 'address' => '192.0.2.5/24', 'vhid' => '', 'description' => 'Alias'),
    'IP alias display: type, interface name, address/bits, no vhid');
check($list[1]['display']['type'] === 'CARP' && $list[1]['display']['vhid'] === '5' && $list[1]['id'] === 1 && $list[1]['vhid'] === '5' &&
    !isset($list[1]['password']) && !isset($list[1]['fields']['password']), 'CARP display has the vhid; stored keys stay; no password');
check($list[1]['fields'] === array('mode' => 'carp', 'interface' => 'wan', 'subnet' => '198.51.100.10', 'descr' => 'Cluster', 'uniqid' => 'c1',
    'subnet_bits' => '24', 'type' => 'single', 'vhid' => '5', 'advbase' => '1', 'advskew' => '0'), 'CARP fields are the edit form fields');
check($list[2]['display']['type'] === 'Proxy ARP' && $list[2]['display']['address'] === '203.0.113.0/28' && $list[2]['fields']['noexpand'] === 'yes',
    'proxy ARP network: CIDR address, noexpand as a ticked checkbox');
check($list[3]['display']['interface'] === '198.51.100.10 (Cluster)', 'a CARP parent is named like the list page names it');
check($list[4]['display']['address'] === '203.0.113.50' && $list[4]['display']['type'] === 'Other' && !isset($list[4]['fields']['subnet_bits']),
    'without a prefix length the address is shown alone and the page default stays');

/* ---- Virtual IP updates: password kept, false clears a checkbox ---- */
restapi_h_vip_update(array('params' => array('id' => '1'), 'body' => array('descr' => 'Renamed'), 'query' => array()));
check($GLOBALS['vip_saved']['password'] === 'S3cretCarp' && $GLOBALS['vip_saved']['password_confirm'] === 'S3cretCarp' &&
    $GLOBALS['vip_saved']['descr'] === 'Renamed' && $GLOBALS['vip_saved']['vhid'] === '5', 'a PUT without a password keeps the stored one');
restapi_h_vip_update(array('params' => array('id' => '1'), 'body' => array('password' => ''), 'query' => array()));
check($GLOBALS['vip_saved']['password'] === 'S3cretCarp', 'an empty password keeps the stored one');
restapi_h_vip_update(array('params' => array('id' => '1'), 'body' => array('password' => 'N3w'), 'query' => array()));
check($GLOBALS['vip_saved']['password'] === 'N3w' && $GLOBALS['vip_saved']['password_confirm'] === 'N3w', 'a new password replaces it (confirmed)');
restapi_h_vip_update(array('params' => array('id' => '2'), 'body' => array('noexpand' => false), 'query' => array()));
check(!isset($GLOBALS['vip_saved']['noexpand']) && $GLOBALS['vip_saved']['type'] === 'network' && $GLOBALS['vip_saved']['id'] === '2',
    'false clears a checkbox; other fields keep their value');
restapi_h_vip_update(array('params' => array('id' => '2'), 'body' => array('mode' => 'ipalias'), 'query' => array()));
check(!isset($GLOBALS['vip_saved']['type']), 'IP alias and CARP are saved as single addresses, like the edit page posts them');
restapi_h_vip_create(array('body' => array('mode' => 'carp', 'interface' => 'lan', 'subnet' => '192.0.2.9', 'password' => 'p'), 'query' => array()));
check($GLOBALS['vip_saved']['password_confirm'] === 'p' && $GLOBALS['vip_saved']['id'] === '', 'a create confirms the password it sends');
restapi_h_vip_create(array('body' => array('mode' => 'carp', 'interface' => 'lan', 'subnet' => '192.0.2.9'), 'query' => array()));
check(!isset($GLOBALS['vip_saved']['password']) && $GLOBALS['vip_saved']['password_confirm'] === '', 'a create without a password leaves it to saveVIP() to refuse');

/* ---- Schedules: output ---- */
$GLOBALS['config']['schedules']['schedule'] = array(
	array('name' => 'Holidays', 'descr' => 'Closed', 'schedlabel' => 'h1', 'timerange' => array(
		array('month' => '12,12,12,1', 'day' => '24,25,26,1', 'hour' => '0:00-23:59', 'rangedescr' => ''))),
	array('name' => 'Office', 'descr' => 'Work', 'schedlabel' => 'o1', 'timerange' => array(
		array('position' => '1,2,3,4,5', 'hour' => '8:00-17:00', 'rangedescr' => 'Day'),
		array('position' => '1,3,6,7', 'hour' => '18:00-20:30', 'rangedescr' => ''))),
);
$GLOBALS['rules'] = array(array('descr' => 'Office web', 'sched' => 'Office'), array('descr' => 'Office mail', 'sched' => 'Office'), array('descr' => 'x'));
$list = restapi_h_sched_list(array())['data'];
check($list[1]['name'] === 'Office' && $list[1]['description'] === 'Work' && $list[1]['ranges'][0]['weekdays'] === array(1, 2, 3, 4, 5) &&
    $list[1]['descr'] === 'Work' && $list[1]['timerange'][0]['position'] === '1,2,3,4,5' && $list[1]['schedlabel'] === 'o1',
    'existing keys stay; the stored fields are added');
check($list[1]['display'] === array('ranges' => array('Mon–Fri 08:00–17:00 (Day)', 'Mon, Wed, Sat–Sun 18:00–20:30'), 'active' => true,
    'used_by' => 2, 'description' => 'Work'), 'weekday ranges read like the list page; active and use count');
check($list[0]['display']['ranges'] === array('December 24–26, January 1 00:00–23:59') && $list[0]['display']['active'] === false &&
    $list[0]['display']['used_by'] === 0, 'date ranges are summarised as runs of days');
check($list[1]['fields'] === array('name' => 'Office', 'descr' => 'Work', 'ranges' => array(
    array('weekdays' => array('1', '2', '3', '4', '5'), 'dates' => '', 'start' => '08:00', 'stop' => '17:00', 'description' => 'Day'),
    array('weekdays' => array('1', '3', '6', '7'), 'dates' => '', 'start' => '18:00', 'stop' => '20:30', 'description' => ''))),
    'fields are the schema\'s editor fields with HH:MM times');
check($list[0]['fields']['ranges'][0]['dates'] === '12-24, 12-25, 12-26, 01-01' && $list[0]['fields']['ranges'][0]['weekdays'] === array(),
    'date ranges are a dates text in the editor fields');

/* ---- Schedules: saves ---- */
$put = function ($name, $body) {
	restapi_request_context(array('schema' => 'firewall/schedules', 'body' => $body));
	return restapi_h_sched_update(array('params' => array('name' => $name), 'body' => $body, 'query' => array()));
};
$fields = $list[0]['fields'];
$fields['descr'] = 'Shop closed';
$out = $put('Holidays', $fields)['data'];
check($out['timerange'][0]['month'] === '12,12,12,1' && $out['timerange'][0]['day'] === '24,25,26,1' && $out['timerange'][0]['hour'] === '00:00-23:59' &&
    $out['description'] === 'Shop closed', 'the editor fields round-trip date ranges untouched (month and day)');
$out = $put('Holidays', array('descr' => 'Again'))['data'];
check($out['timerange'][0]['month'] === '12,12,12,1' && $out['descr'] === 'Again', 'a partial update keeps the ranges');

$e = api_error(function () use ($put) { $put('Office', array('ranges' => array(array('weekdays' => array('1'), 'start' => '18:00', 'stop' => '08:00')))); });
check($e && $e->status === 422 && isset($e->payload()['error']['details']['fields']['ranges.0.start']), 'start later than stop is refused, on the start cell');
$e = api_error(function () use ($put) { $put('Office', array('ranges' => array(array('weekdays' => array('1'), 'start' => '25:00', 'stop' => '26:00')))); });
check($e && $e->status === 422 && count($e->payload()['error']['details']['messages']) === 2, 'hours above 23 are refused');
$e = api_error(function () use ($put) { $put('Office', array('ranges' => array(array('weekdays' => array('1'), 'dates' => '12-24', 'start' => '08:00', 'stop' => '09:00')))); });
check($e && $e->status === 422 && isset($e->payload()['error']['details']['fields']['ranges']), 'a range with weekdays and dates is refused');
$e = api_error(function () use ($put) { $put('Office', array('ranges' => array(array('start' => '08:00', 'stop' => '09:00')))); });
check($e && $e->status === 422 && isset($e->payload()['error']['details']['fields']['ranges']), 'a range without days is refused');
$e = api_error(function () use ($put) { $put('Office', array('name' => 'Office2')); });
check($e && $e->status === 422 && array_keys($e->payload()['error']['details']['fields']) === array('name'), 'a schedule in use keeps its name');
$e = api_error(function () use ($put) { $put('Holidays', array('name' => 'Office')); });
check($e && $e->status === 422 && array_keys($e->payload()['error']['details']['fields']) === array('name'), 'names are unique');
$e = api_error(function () use ($put) { $put('Holidays', array('name' => 'bad name')); });
check($e && $e->status === 422 && array_keys($e->payload()['error']['details']['fields']) === array('name'), 'names follow the alias name rules');
$out = $put('Holidays', array('name' => 'Closed'))['data'];
check($out['name'] === 'Closed', 'an unused schedule can be renamed');
$out = $put('Office', array('ranges' => array(array('weekdays' => array('5', '1'), 'start' => '09:00', 'stop' => '09:00'))))['data'];
check($out['timerange'] === array(array('position' => '1,5', 'hour' => '09:00-09:00', 'rangedescr' => '')), 'start equal to stop is allowed, as on the page');

restapi_request_context(array('schema' => 'firewall/schedules', 'body' => array()));
$out = restapi_h_sched_create(array('body' => array('name' => 'Stored', 'descr' => 'raw', 'timerange' => array(
    array('month' => '7', 'day' => '4', 'hour' => '10:00-12:00', 'rangedescr' => 'Fourth'),
    array('position' => '6,7', 'hour' => '0:00-23:59'))), 'query' => array()));
check($out['status'] === 201 && $out['data']['timerange'] === array(array('month' => '7', 'day' => '4', 'hour' => '10:00-12:00', 'rangedescr' => 'Fourth'),
    array('position' => '6,7', 'hour' => '0:00-23:59', 'rangedescr' => '')) && $out['data']['description'] === 'raw',
    'the stored timerange form is accepted as a body');

/* 1.x behaviour is unchanged: the page's own call is not strict. */
$r = saveSchedule(array('name' => 'Legacy', 'descr' => '', 'schedule0' => '1', 'starttime0' => '18:00', 'stoptime0' => '8:00', 'timedescr0' => ''), null);
check($r['input_errors'] === array() && $r['schedule']['timerange'][0]['hour'] === '18:00-8:00', 'saveSchedule() without $strict validates as before');
check(schedule_strict_errors(array('name' => 'x', 'schedule0' => '1', 'starttime0' => '8:00', 'stoptime0' => '24:00'), null, array()) === array(),
    '24:00 (end of day, older configurations) is accepted as a stop time');

/* ---- Schedules: delete ---- */
$GLOBALS['config']['schedules']['schedule'] = array_values($GLOBALS['config']['schedules']['schedule']);
$e = api_error(function () { restapi_h_sched_delete(array('params' => array('name' => 'Office'))); });
check($e && $e->status === 409 && $e->payload()['error']['code'] === 'in_use' && strpos($e->payload()['error']['message'], 'Office web') !== false,
    'deleting a schedule a rule uses is refused (409 in_use, names the rule)');
$out = restapi_h_sched_delete(array('params' => array('name' => 'Legacy')));
check($out['data']['deleted'] === 'Legacy' && api_error(function () { restapi_sched_index('Legacy'); })->status === 404, 'an unused schedule is deleted');

/* ---- Wiring ---- */
$fw = file_get_contents("{$root}/src/etc/inc/restapi/routes_firewall.inc");
check(substr_count($fw, "'schema' => 'firewall/virtual_ips'") === 2 && substr_count($fw, "'schema' => 'firewall/schedules'") === 2,
    'virtual IP and schedule saves name their schema (POST and PUT)');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi.inc"), "'schedules-vips'") !== false, 'the capability is announced');
check(strpos($fw, 'saveSchedule(restapi_sched_post($body), $id, true)') !== false, 'the API saves schedules strictly');
$page = file_get_contents("{$root}/src/usr/local/www/firewall_schedule_edit.php");
check(strpos($page, 'saveSchedule($_POST, (isset($id) && $a_schedules[$id]) ? $id : null);') !== false, 'the 1.x page saves as before (not strict)');

echo "REST API schedules and virtual IPs smoke test passed.\n";
