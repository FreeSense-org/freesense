<?php
/* Standalone CI regression test for the NAT API (schemas, display texts, reorder); run with `php tests/RestApiNatSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));

/* The pieces of util.inc, globals.inc, interfaces.inc and the GUI includes the NAT API uses, as small stand-ins. */
function gettext($t) { return $t; }
foreach (array('ANY' => 1, 'NET' => 4, 'NETAL' => 5, 'SELF' => 6, 'CLIENTS' => 7, 'IFADDR' => 8, 'IFSUB' => 9, 'IFNET' => 10,
    'GROUP' => 11, 'VIPS' => 12, 'CHECKPERM' => 30, 'COMPAT_ADDR' => 22, 'COMPAT_ADDRAL' => 33) as $n => $v) {
	define("SPECIALNET_{$n}", $v);
}
function get_specialnet($net = '', array $flags = array()) {
	$all = array(
		SPECIALNET_ANY => array('any' => 'Any'),
		SPECIALNET_COMPAT_ADDR => array('single' => 'Address'),
		SPECIALNET_COMPAT_ADDRAL => array('single' => 'Address or Alias'),
		SPECIALNET_NET => array('network' => 'Network'),
		SPECIALNET_NETAL => array('network' => 'Network or Alias'),
		SPECIALNET_SELF => array('(self)' => 'This Firewall (self)'),
		SPECIALNET_IFADDR => array('wanip' => 'WAN address', 'lanip' => 'LAN address'),
		SPECIALNET_IFSUB => array('wan' => 'WAN subnet', 'lan' => 'LAN subnet'),
		SPECIALNET_IFNET => array('wan' => 'WAN subnets', 'lan' => 'LAN subnets'),
		SPECIALNET_VIPS => array('198.51.100.0/28' => 'Subnet: 198.51.100.0/28 (pool)'),
	);
	$flags = empty($flags) ? array(SPECIALNET_ANY, SPECIALNET_SELF, SPECIALNET_IFADDR, SPECIALNET_IFSUB, SPECIALNET_IFNET, SPECIALNET_VIPS) : $flags;
	$list = array();
	foreach ($flags as $f) {
		$list = array_merge($list, $all[$f] ?? array());
	}
	return ($net === '') ? $list : array_key_exists($net, $list);
}
function get_ipprotocols($type = '') {
	if ($type === 'portsonly') {
		return array('tcp' => 'TCP', 'udp' => 'UDP', 'tcp/udp' => 'TCP/UDP', 'sctp' => 'SCTP');
	}
	return array('any' => 'Any', 'tcp' => 'TCP', 'udp' => 'UDP', 'tcp/udp' => 'TCP/UDP', 'icmp' => 'ICMP', 'sctp' => 'SCTP');
}
function filter_get_interface_list() { return array('wan' => 'WAN', 'lan' => 'LAN'); }
function create_interface_list() { return array('wan' => 'WAN', 'lan' => 'LAN'); }
function convert_friendly_interface_to_friendly_descr($if) { return strtoupper($if); }
function config_get_path($path, $default = null) { return $GLOBALS['cfg'][$path] ?? $default; }
function write_config($msg) { $GLOBALS['log'][] = "write_config:{$msg}"; return true; }
function mark_subsystem_dirty($s) { $GLOBALS['dirty'][$s] = true; }
function is_subsystem_dirty($s) { return !empty($GLOBALS['dirty'][$s]); }
function set_anynat_rules_order($type, array $order) { $GLOBALS['log'][] = "order:{$type}:" . implode(',', $order); return true; }
function reorder1to1NATrules($post, $json = false) { $GLOBALS['log'][] = 'reorder1to1:' . implode(',', $post['rule']) . ':' . var_export($json, true); }
function outNATrulesreorder($post, $json = false) { $GLOBALS['log'][] = 'reorderout:' . implode(',', $post['rule']); }
function reordernptNATrules($post, $json = false) { $GLOBALS['log'][] = 'reordernpt:' . implode(',', $post['rule']); }
function applyNATrules() { $GLOBALS['log'][] = 'apply'; unset($GLOBALS['dirty']['natconf']); return 0; }
function apply1to1NATrules() { return applyNATrules(); }
function applyoutNATrules() { return applyNATrules(); }
function applynptNATrules() { return applyNATrules(); }
function build_srctype_list() { return get_specialnet('', array(SPECIALNET_ANY, SPECIALNET_COMPAT_ADDRAL, SPECIALNET_NET, SPECIALNET_IFADDR, SPECIALNET_IFNET)); }
function build_dsttype_list() { return get_specialnet('', array(SPECIALNET_ANY, SPECIALNET_COMPAT_ADDRAL, SPECIALNET_NET, SPECIALNET_SELF, SPECIALNET_IFADDR, SPECIALNET_IFNET)); }
function is_alias($a) { return $a === 'WebServers'; }
function getAutoRules() {
	return array(array('interface' => 'wan', 'source' => array('network' => '127.0.0.0/8 ::1/128 192.168.228.0/24'), 'dstport' => '500',
	    'target' => 'wanip', 'destination' => array('any' => true), 'staticnatport' => true, 'descr' => 'Auto created rule for ISAKMP'));
}

require_once('restapi/routes_v1.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function api_error(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e;
	}
	return null;
}

/* ------------------------------------------------------------------ */
/* Schemas                                                             */
/* ------------------------------------------------------------------ */

$expect = array(
	'firewall/nat_port_forwards' => array('disabled', 'nordr', 'interface', 'ipprotocol', 'proto', 'srctype', 'srcnot', 'src', 'srcmask',
	    'srcbeginport', 'srcendport', 'dsttype', 'dstnot', 'dst', 'dstmask', 'dstbeginport', 'dstendport', 'localtype', 'localip',
	    'localbeginport', 'descr', 'filter-rule-association', 'natreflection', 'nosync'),
	'firewall/nat_outbound' => array('disabled', 'nonat', 'interface', 'ipprotocol', 'protocol', 'source_type', 'source', 'source_subnet',
	    'sourceport', 'destination_type', 'destination_not', 'destination', 'destination_subnet', 'dstport', 'target_type', 'target',
	    'target_subnet', 'poolopts', 'source_hash_key', 'natport', 'staticnatport', 'eimnat', 'descr', 'nosync'),
	'firewall/nat_one_to_one' => array('disabled', 'nobinat', 'interface', 'ipprotocol', 'exttype', 'external', 'srctype', 'srcnot', 'src',
	    'srcmask', 'dsttype', 'dstnot', 'dst', 'dstmask', 'descr', 'natreflection'),
	'firewall/nat_npt' => array('disabled', 'interface', 'srcnot', 'src', 'srcmask', 'dstnot', 'dsttype', 'dst', 'dstmask', 'descr'),
);
$schemas = restapi_schemas();
foreach ($expect as $res => $names) {
	check(isset($schemas[$res]) && ($schemas[$res][2] === 'firewall.nat'), "{$res} is registered in area firewall.nat");
	$s = restapi_schema_get($res);
	check(!empty($s['summary']) && !empty($s['summaryIcon']), "{$res} has a summary");
	check(array_keys(restapi_schema_fields($s)) === $names, "{$res}: fields are the edit page's form fields (" .
	    implode(', ', array_keys(restapi_schema_fields($s))) . ')');
}
check($schemas['firewall/nat_port_forwards'][1] === 'firewall_nat_edit.php' && $schemas['firewall/nat_outbound'][1] === 'firewall_nat_out_edit.php' &&
    $schemas['firewall/nat_one_to_one'][1] === 'firewall_nat_1to1_edit.php' && $schemas['firewall/nat_npt'][1] === 'firewall_nat_npt_edit.php',
    'reading a NAT schema needs its edit page');

function field_of($res, $name) {
	foreach (restapi_schema_get($res)['sections'] as $sec) {
		foreach ($sec['fields'] as $f) {
			if ($f['name'] === $name) {
				return $f + array('section' => $sec);
			}
		}
	}
	return null;
}
$pf = 'firewall/nat_port_forwards';
check(array_column(field_of($pf, 'filter-rule-association')['options'], 'value') === array('', 'add-associated', 'add-unassociated', 'pass') &&
    field_of($pf, 'filter-rule-association')['default'] === 'add-associated', 'the filter rule association offers the new-rule choices');
check(array_column(field_of($pf, 'natreflection')['options'], 'value') === array('default', 'enable', 'purenat', 'disable'), 'NAT reflection modes');
check(array_column(field_of($pf, 'localtype')['options'], 'value') === array('single', 'wanip', 'lanip'), 'redirect target types (build_localtype_list)');
check(field_of($pf, 'localtype')['section']['visibleWhen'] === array('field' => 'nordr', 'truthy' => false), 'No RDR hides the redirect target');
check(field_of($pf, 'srctype')['section']['advanced'] === true, 'the port forward source is advanced, like "Display Advanced"');
check(field_of($pf, 'dstbeginport')['visibleWhen']['in'] === array('tcp', 'udp', 'tcp/udp', 'sctp'), 'ports only for protocols with ports');
check(array_column(field_of('firewall/nat_outbound', 'source_type')['options'], 'value') === array('any', '(self)', 'network', 'wan', 'lan'),
    'outbound source types');
check(field_of('firewall/nat_outbound', 'poolopts')['visibleWhen']['in'] === array('network', '198.51.100.0/28'),
    'pool options for a custom network and for VIP subnets');
check(array_column(field_of('firewall/nat_npt', 'dsttype')['options'], 'value') === array('network'), 'NPt destination type "Prefix"');

/* Real input_errors of the save functions land on their fields */
$cases = array(
	$pf => array(
		'The field Destination port from is required.' => 'dstbeginport',
		'The field Redirect target IP is required.' => 'localip',
		'"10.0.0.300" is not a valid redirect target IP address or host alias.' => 'localip',
		'Redirect interface must have IPv4 address.' => 'localtype',
		'Redirect target port 99999 is not valid. It must be a port alias or integer between 1 and 65535.' => 'localbeginport',
		'The target port range must be an integer between 1 and 65535.' => 'localbeginport',
		'99999 is not a valid start source port. It must be a port alias or integer between 1 and 65535.' => 'srcbeginport',
		'Destination port range From/To values must a port number or alias, but not both.' => 'dstbeginport',
		'The destination port range overlaps with an existing entry.' => 'dstbeginport',
		'Source must be IPv4.' => 'src',
		'A valid destination bit count must be specified.' => 'dstmask',
		'The submitted interface does not exist.' => 'interface',
		"The submitted interface does not support the 'Any' destination type with enabled NAT reflection." => 'dsttype',
		"IPv6 address family doesn't support NAT + Proxy reflection mode." => 'natreflection',
	),
	'firewall/nat_outbound' => array(
		'The field Source bit count is required.' => 'source_subnet',
		'A valid source must be specified.' => 'source',
		'A valid port or port alias must be supplied for the source port entry.' => 'sourceport',
		'A valid port or port alias must be supplied for the destination port entry.' => 'dstport',
		'A valid port must be supplied for the NAT port entry.' => 'natport',
		'Negating destination address of "any" is invalid.' => 'destination_not',
		'A valid target type must be specified.' => 'target_type',
		'A valid target IP address or alias must be specified when using the Network type.' => 'target',
		'A valid target bit count must be specified when using the Network type.' => 'target_subnet',
		'Only Round Robin pool options may be chosen when selecting an alias.' => 'poolopts',
		'Incorrect format for source-hash key, "0x" must be followed by exactly 32 hexadecimal characters.' => 'source_hash_key',
	),
	'firewall/nat_one_to_one' => array(
		'The field External subnet is required.' => 'external',
		'The external subnet IP is not from the specified address family.' => 'external',
		'The internal IP is not from the specified address family.' => 'src',
		'The field Source address is required.' => 'src',
		'A valid internal bit count must be specified.' => 'srcmask',
		'The destination address is not from the specified address family.' => 'dst',
		'The interface does not have an address from the specified address family.' => 'interface',
	),
	'firewall/nat_npt' => array(
		'The field Source prefix is required.' => 'src',
		'The specified source address is not a valid IPv6 prefix' => 'src',
		'The specified destination address is not a valid IPv6 prefix' => 'dst',
		'The specified destination address and interface IPv6 address cannot overlap' => 'dst',
		'The specified source prefix size must be equal to the destination prefix size.' => 'srcmask',
	),
);
foreach ($cases as $res => $msgs) {
	$schema = restapi_schema_get($res);
	foreach ($msgs as $msg => $field) {
		$r = restapi_errors_to_fields(array($msg), $schema, array());
		check(($r['fields'][$field] ?? null) === $msg, "{$res}: \"{$msg}\" lands on {$field} (got " . json_encode($r) . ')');
	}
}

/* ------------------------------------------------------------------ */
/* Display texts                                                       */
/* ------------------------------------------------------------------ */

$d = restapi_nat_display_rdr(array('interface' => 'wan', 'ipprotocol' => 'inet', 'protocol' => 'tcp', 'source' => array('any' => ''),
    'destination' => array('network' => 'wanip', 'port' => '8080-8081'), 'target' => '10.0.0.5', 'local-port' => '80',
    'associated-rule-id' => 'nat_5f0c', 'descr' => 'web'));
check($d === array('interface' => 'WAN', 'protocol' => 'IPv4 TCP', 'source' => '*', 'source_ports' => '*', 'destination' => 'WAN address',
    'destination_ports' => '8080 - 8081', 'target' => '10.0.0.5', 'target_ports' => '80 - 81', 'enabled' => true, 'no_rdr' => false,
    'filter_rule' => 'associated', 'description' => 'web'), 'port forward display ' . json_encode($d));
$d = restapi_nat_display_rdr(array('interface' => 'lan', 'ipprotocol' => 'inet6', 'protocol' => 'tcp/udp', 'disabled' => true,
    'source' => array('network' => 'lan', 'not' => true), 'destination' => array('address' => 'WebServers', 'port' => '443'),
    'target' => 'lanip', 'local-port' => '8443', 'associated-rule-id' => 'pass'));
check($d['protocol'] === 'IPv6 TCP/UDP' && $d['source'] === '! LAN subnets' && $d['destination'] === 'WebServers' && $d['destination_ports'] === '443' &&
    $d['target'] === 'LAN address' && $d['target_ports'] === '8443' && $d['enabled'] === false && $d['filter_rule'] === 'pass',
    'port forward display: special nets by label, aliases by name, "! " when inverted, pass');
check(restapi_nat_display_rdr(array('nordr' => true))['filter_rule'] === 'none' && restapi_nat_display_rdr(array())['interface'] === 'WAN',
    'no association is "none"; no interface is WAN like the list page');

$d = restapi_nat_display_nat(array('interface' => 'wan', 'source' => array('network' => 'lan'), 'destination' => array('any' => true),
    'target' => 'wanip', 'staticnatport' => true, 'descr' => 'x'));
check($d['interface'] === 'WAN' && $d['protocol'] === 'IPv4+6 *' && $d['source'] === 'LAN subnets' && $d['source_ports'] === '*' &&
    $d['destination'] === '*' && $d['destination_ports'] === '*' && $d['translation'] === 'WAN address' && $d['translation_port'] === '*' &&
    $d['static_port'] === true && $d['enabled'] === true && $d['description'] === 'x', 'outbound display ' . json_encode($d));
$d = restapi_nat_display_nat(array('interface' => 'wan', 'ipprotocol' => 'inet', 'protocol' => 'udp', 'source' => array('network' => '10.0.0.0/24'),
    'sourceport' => '5060', 'destination' => array('network' => '192.0.2.0/24', 'not' => true), 'dstport' => '1000:2000', 'target' => '198.51.100.8',
    'target_subnet' => '29', 'natport' => '1024:65535'));
check($d['protocol'] === 'IPv4 UDP' && $d['source'] === '10.0.0.0/24' && $d['source_ports'] === '5060' && $d['destination'] === '! 192.0.2.0/24' &&
    $d['translation'] === '198.51.100.8/29' && $d['translation_port'] === '1024:65535' && $d['static_port'] === false, 'outbound display: networks and ports');
check(restapi_nat_display_nat(array('nonat' => true, 'target' => 'wanip'))['translation'] === 'No NAT' &&
    restapi_nat_display_nat(array('target' => ''))['translation'] === 'Interface address', 'outbound translation: No NAT, interface address');

$auto = restapi_h_natout_auto(array())['data'][0];
check($auto['descr'] === 'Auto created rule for ISAKMP' && $auto['target'] === 'wanip' && $auto['display']['source'] === array('127.0.0.0/8', '::1/128', '192.168.228.0/24') &&
    $auto['display']['translation'] === 'WAN address' && $auto['display']['destination_ports'] === '500' && $auto['display']['static_port'] === true &&
    $auto['display']['interface'] === 'WAN', 'automatic outbound rules keep their keys and get a display with the source networks as a list');

$GLOBALS['cfg'] = array('nat/outbound/mode' => 'hybrid');
$mode = restapi_h_natout_mode_get(array())['data'];
check($mode['mode'] === 'hybrid' && array_column($mode['choices'], 'value') === array('automatic', 'hybrid', 'advanced', 'disabled') &&
    $mode['choices'][2]['label'] === 'Manual Outbound NAT rule generation' && $mode['choices'][2]['help'] === 'AON - Advanced Outbound NAT',
    'the outbound NAT mode comes with its four choices');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi/routes_nat.inc"), "'choices' => restapi_natout_mode_choices(), 'pending'") !== false,
    'setting the mode also returns the choices');

$d = restapi_nat_display_binat(array('interface' => 'wan', 'ipprotocol' => 'inet', 'external' => '198.51.100.10',
    'source' => array('address' => '10.0.0.10'), 'destination' => array('any' => ''), 'disabled' => false, 'nobinat' => false, 'descr' => 'cam'));
check($d === array('interface' => 'WAN', 'external' => '198.51.100.10', 'internal' => '10.0.0.10', 'destination' => '*', 'enabled' => true,
    'no_binat' => false, 'description' => 'cam'), '1:1 display (a stored false is not set) ' . json_encode($d));
check(restapi_nat_display_binat(array('external' => 'wanip', 'source' => array('network' => 'lan')))['external'] === 'WAN address' &&
    restapi_nat_display_binat(array('external' => 'wanip', 'source' => array('network' => 'lan')))['internal'] === 'LAN subnet',
    '1:1 display: interface address and subnet labels');

$GLOBALS['cfg'] = array();
$d = restapi_nat_display_npt(array('interface' => 'wan', 'source' => array('address' => 'fd00::/64'),
    'destination' => array('address' => '2001:db8::/64', 'not' => ''), 'disabled' => true));
check($d === array('interface' => 'WAN', 'internal' => 'fd00::/64', 'external' => '! 2001:db8::/64', 'enabled' => false, 'description' => ''),
    'NPt display ' . json_encode($d));

/* Port forward association: one select for new and existing rules */
check(restapi_natpf_association(array('filter-rule-association' => 'add-associated', 'associated-rule-id' => 'nat_1'), array(), 'nat_1') ===
    array('associated-rule-id' => 'nat_1'), 'an update keeps the linked rule');
check(restapi_natpf_association(array('filter-rule-association' => 'add-associated'), array('filter-rule-association' => 'add-associated'), '') ===
    array('filter-rule-association' => 'add-associated', 'associated-rule-id' => ''), 'a new rule gets a new linked rule');
check(restapi_natpf_association(array('filter-rule-association' => '', 'associated-rule-id' => 'nat_1'), array('filter-rule-association' => ''), 'nat_1') ===
    array('associated-rule-id' => ''), 'none drops the link');
check(restapi_natpf_association(array('filter-rule-association' => 'pass'), array(), 'nat_1')['associated-rule-id'] === 'pass', 'pass');
check(restapi_natpf_association(array('filter-rule-association' => 'add-unassociated', 'associated-rule-id' => 'nat_1'), array(), 'nat_1') ===
    array('filter-rule-association' => 'add-unassociated', 'associated-rule-id' => ''), 'an unassociated rule replaces the link');
check(restapi_natpf_association(array('filter-rule-association' => 'add-associated', 'associated-rule-id' => 'new'), array('associated-rule-id' => 'new'), 'nat_1') ===
    array('associated-rule-id' => 'new'), 'the edit page\'s associated-rule-id is passed on as posted');
$ff = restapi_natpf_form_fields(array('interface' => 'wan', 'ipprotocol' => 'inet', 'proto' => 'tcp', 'src' => 'any', 'dst' => 'wanip',
    'dstbeginport' => '80', 'dstendport' => '80', 'localip' => '10.0.0.5', 'localbeginport' => '80', 'associated-rule-id' => 'nat_1',
    'disabled' => false, 'nordr' => false, 'nosync' => true));
check($ff['srctype'] === 'any' && $ff['dsttype'] === 'wanip' && $ff['localtype'] === 'single' && $ff['filter-rule-association'] === 'add-associated' &&
    !isset($ff['disabled']) && $ff['nosync'] === 'yes', 'port forward fields carry the association as filter-rule-association');

/* ------------------------------------------------------------------ */
/* Reorder                                                             */
/* ------------------------------------------------------------------ */

check(restapi_nat_order_ids(array(0, 1, 2), array(2, '0', 1)) === array(2, 0, 1), 'a permutation is accepted (digit strings too)');
foreach (array(array(0, 1), array(0, 0, 1), array(0, 1, 2, 3), array(0, 1, 'x'), array(0, 1, 1.5), array()) as $bad) {
	$e = api_error(function () use ($bad) { restapi_nat_order_ids(array(0, 1, 2), $bad); });
	check(($e !== null) && ($e->status === 422) && (strpos($e->payload()['error']['details']['messages'][0], '0, 1, 2') !== false),
	    'not a permutation is a 422 listing the ids: ' . json_encode($bad));
}
check(api_error(function () { restapi_nat_order_ids(array(0), 'x'); })->status === 400 &&
    api_error(function () { restapi_nat_order_ids(array(0), array('a' => 0)); })->status === 400, '"order" must be a list');
check(api_error(function () { restapi_nat_order_ids(array(), array()); })->status === 422, 'nothing to reorder is a 422');

$GLOBALS['cfg'] = array('nat/onetoone' => array(array('descr' => 'a'), array('descr' => 'b'), array('descr' => 'c')),
    'nat/rule' => array(array('descr' => 'a'), array('descr' => 'b')));
$GLOBALS['log'] = array();
$GLOBALS['dirty'] = array();
$out = restapi_h_nat_order(array('path' => '/v1/firewall/nat/one-to-one/order', 'body' => array('order' => array(2, 0, 1)), 'query' => array()));
check($out['data'] === array('changed' => true, 'order' => array(2, 0, 1), 'pending' => array('nat')) &&
    $GLOBALS['log'] === array('reorder1to1:2,0,1:true'), '1:1 reorder goes through the page function in JSON mode and stages NAT');
$GLOBALS['log'] = array();
$out = restapi_h_natpf_order(array('path' => '/v1/firewall/nat/port-forwards/order', 'body' => array('order' => array(1, 0)), 'query' => array('apply' => 'true')));
check($out['data']['changed'] && $out['data']['apply']['applied'] &&
    $GLOBALS['log'] === array('order:rdr:1,0', 'write_config:NAT: Rule order changed', 'apply'), 'port forward reorder, applied with ?apply=true');
$GLOBALS['log'] = array();
$GLOBALS['dirty'] = array();
$out = restapi_h_natpf_order(array('path' => '/v1/firewall/nat/port-forwards/order', 'body' => array('order' => array(0, 1)), 'query' => array()));
check($out['data']['changed'] === false && $GLOBALS['log'] === array() && empty($GLOBALS['dirty']), 'the same order changes nothing');
check(api_error(function () { restapi_h_nat_order(array('path' => '/v1/firewall/nat/one-to-one/order', 'body' => array('order' => array(0, 1)), 'query' => array())); })->status === 422,
    'a partial order is refused');

/* ------------------------------------------------------------------ */
/* Wiring                                                              */
/* ------------------------------------------------------------------ */

$routes = array_merge(restapi_routes_firewall(), restapi_routes_nat());
$index = array();
foreach ($routes as $i => $r) {
	$index["{$r['method']} {$r['path']}"] = $i;
}
foreach (array('port-forwards' => array('restapi_h_natpf_order', 'firewall/nat_port_forwards'), 'outbound' => array('restapi_h_nat_order', 'firewall/nat_outbound'),
    'one-to-one' => array('restapi_h_nat_order', 'firewall/nat_one_to_one'), 'npt' => array('restapi_h_nat_order', 'firewall/nat_npt')) as $kind => $want) {
	$base = "/v1/firewall/nat/{$kind}";
	list($route) = restapi_match($routes, 'POST', "{$base}/order");
	check($route['handler'] === $want[0] && $route['write'] && $route['area'] === 'firewall.nat', "POST {$base}/order is the reorder route");
	check($index["POST {$base}/order"] < $index["GET {$base}/{id}"] && $index["POST {$base}/order"] < $index["PUT {$base}/{id}"],
	    "{$base}/order is registered before the {id} routes");
	check($routes[$index["POST {$base}"]]['schema'] === $want[1] && $routes[$index["PUT {$base}/{id}"]]['schema'] === $want[1],
	    "{$kind} saves name their schema {$want[1]}");
}
check(preg_match("/function restapi_capabilities\(\) \{\n\treturn array\([^)]*'nat-v2'/", file_get_contents("{$root}/src/etc/inc/restapi.inc")) === 1,
    'GET /api/v1/meta reports the capability nat-v2');

echo "REST API NAT smoke test passed.\n";
