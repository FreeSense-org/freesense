<?php
/* Standalone CI regression test for the Network > Assignments API (schemas, display texts, in-use refusals, wiring); run with `php tests/RestApiAssignmentsSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));

/* ------------------------------------------------------------------ */
/* Stand-ins for util.inc, interfaces.inc and the Interfaces includes  */
/* ------------------------------------------------------------------ */

function gettext($t) { return $t; }
function config_get_path($path, $default = null) {
	$node = $GLOBALS['cfg'];
	foreach (explode('/', trim($path, '/')) as $k) {
		if (!is_array($node) || !array_key_exists($k, $node)) {
			return $default;
		}
		$node = $node[$k];
	}
	return $node;
}
function userHasPrivilege($user, $priv) { return false; }
function does_interface_exist($if) { return $if !== 'gone0'; }
function get_real_interface($name) { return $GLOBALS['cfg']['interfaces'][$name]['if'] ?? (($name === '_vip5f00') ? 'em0' : $name); }
function convert_real_interface_to_friendly_interface_name($real) {
	foreach ($GLOBALS['cfg']['interfaces'] as $name => $cfg) {
		if (($cfg['if'] ?? '') === $real) {
			return $name;
		}
	}
	return null;
}
function convert_friendly_interface_to_friendly_descr($if) {
	$cfg = $GLOBALS['cfg']['interfaces'][$if] ?? null;
	return is_array($cfg) ? ($cfg['descr'] ?? strtoupper($if)) : $if;
}
function convert_real_interface_to_friendly_descr($real) { return convert_friendly_interface_to_friendly_descr(convert_real_interface_to_friendly_interface_name($real)); }
function assigned_port($port) { return convert_real_interface_to_friendly_interface_name($port) !== null; }

/* in-use checks: the same rules as interfaces.inc / interfaces_l2.inc / interfaces_tunnels.inc */
function vlan_inuse($vlan) { return is_array($vlan) && assigned_port($vlan['vlanif'] ?? ''); }
function qinq_inuse($qinq, $tag) { return assigned_port("{$qinq['if']}.{$qinq['tag']}.{$tag}"); }
function gif_inuse($num) { return assigned_port($GLOBALS['cfg']['gifs']['gif'][$num]['gifif']); }
function gre_inuse($num) { return assigned_port($GLOBALS['cfg']['gres']['gre'][$num]['greif']); }
function bridge_inuse($num) { return assigned_port($GLOBALS['cfg']['bridges']['bridged'][$num]['bridgeif']); }
function vxlan_inuse($num) { return false; }
function lagg_inuse($num) {
	$laggif = $GLOBALS['cfg']['laggs']['lagg'][$num]['laggif'];
	if (assigned_port($laggif)) {
		return 'This LAGG interface cannot be deleted because it is still being used.';
	}
	foreach ($GLOBALS['cfg']['vlans']['vlan'] as $vlan) {
		if ($vlan['if'] === $laggif) {
			return 'This LAGG interface cannot be deleted because it is still being used.';
		}
	}
	return false;
}
function interfaces_group_users($name) {
	$users = array();
	foreach ($GLOBALS['cfg']['filter']['rule'] as $i => $rule) {
		if ($rule['interface'] === $name) {
			$users[] = sprintf('firewall rule "%s"', $rule['descr'] ?: $i);
		}
	}
	return $users;
}
function interfaces_group_valid_members(array $members) { return array_values(array_intersect($members, array_keys($GLOBALS['cfg']['interfaces']))); }
function interfaces_vlan_tag_types() { return array('ctag' => 'C-Tag (0x8100)', 'stag' => 'S-Tag (0x88A8)'); }
function interfaces_vlan_parent_list() { return array('em1' => array('mac' => '00:0c:29:00:00:01'), 'em2' => array('mac' => '00:0c:29:00:00:02'), 'lagg0' => array('mac' => '00:0c:29:00:00:09')); }
function interfaces_tunnel_parent_list($type) { return array('wan' => 'WAN', 'lan' => 'LAN', '_vip5f00' => '198.51.100.9 (&quot;carp&quot;)', 'lo0' => 'Localhost'); }
function interfaces_lagg_protos() { return array('none', 'lacp', 'failover', 'loadbalance', 'roundrobin'); }
function interfaces_lagg_port_list($id = null) {
	$free = array('igb4' => array('mac' => '00:0c:29:00:00:14'));
	return ($id === null) ? $free : array('igb2' => array('mac' => '00:0c:29:00:00:12'), 'igb3' => array('mac' => '00:0c:29:00:00:13')) + $free;
}
function interfaces_bridge_iface_list() { return array('lan' => 'LAN', 'opt1' => 'DMZ'); }
function interfaces_bridge_port_list($selection) { return array('list' => interfaces_bridge_iface_list(), 'selected' => array()); }
function interfaces_group_member_list() { return array('wan' => 'WAN', 'lan' => 'LAN', 'opt1' => 'DMZ'); }
$lagg_hash_list = array('l2,l3,l4' => 'Layer 2/3/4 (default)', 'l2' => 'Layer 2 (MAC Address)');

/* save and delete functions: record the post, refuse like the includes */
function interfaces_vlan_save(array $post, $id, $ro) { $GLOBALS['saved'] = $post; return $GLOBALS['save_errors']; }
function interfaces_gif_save(array $post, $id) { $GLOBALS['saved'] = $post; return $GLOBALS['save_errors']; }
function interfaces_qinq_save(array $post, $id, $ro) { $GLOBALS['saved'] = $post; return $GLOBALS['save_errors']; }
function interfaces_lagg_save(array $post, $id) { $GLOBALS['saved'] = $post; return $GLOBALS['save_errors']; }
function interfaces_vlan_delete($id, $ro, &$input_errors) {
	$input_errors = array();
	if (vlan_inuse($GLOBALS['cfg']['vlans']['vlan'][$id])) {
		$input_errors[] = 'This VLAN cannot be deleted because it is still being used as an interface.';
		return false;
	}
	return true;
}
function interfaces_lagg_delete($id, &$input_errors) {
	$input_errors = array();
	if (($why = lagg_inuse($id)) !== false) {
		$input_errors[] = $why;
		return false;
	}
	return true;
}
function interfaces_group_delete($id, &$input_errors) {
	$input_errors = array();
	$name = $GLOBALS['cfg']['ifgroups']['ifgroupentry'][$id]['ifname'];
	if (!empty($users = interfaces_group_users($name))) {
		$input_errors[] = sprintf('Interface group "%1$s" cannot be deleted because it is in use by %2$s.', $name, implode(', ', $users));
		return false;
	}
	return true;
}

/* interfaces_assign.inc */
function interfaces_assign_port_list() {
	return array('em0' => array(), 'em1' => array(), 'em2' => array(), 'em3' => array(), 'em1.10' => array('isvlan' => true),
	    'lagg0' => array('islagg' => true), 'gif0' => array('isgif' => true), 'em2.100.11' => array('isqinq' => true));
}
function interfaces_assign_port_descrs(array $portlist) {
	$out = array();
	foreach (array_keys($portlist) as $p) {
		$out[$p] = ($p === 'em1.10') ? 'VLAN 10 on em1 - lan (dmz)' : "{$p} (00:0c:29)";
	}
	return $out;
}
function interfaces_assign_unused_ports(array $portlist) {
	return array_diff_key($portlist, array_flip(array_column($GLOBALS['cfg']['interfaces'], 'if')));
}
function interfaces_assign_reboot_needed() { return $GLOBALS['reboot_needed']; }
function interfaces_assign_reload_pending() { return false; }
function interfaces_assign_in_use($id) {
	return ($id === 'opt1') ? "The interface is part of a group. Please remove it from the group to continue" : '';
}
function interfaces_assign_delete($id, &$input_errors) {
	$input_errors = array();
	if (($why = interfaces_assign_in_use($id)) !== '') {
		$input_errors[] = $why;
		return false;
	}
	$GLOBALS['deleted'][] = $id;
	return true;
}
function interfaces_assign_apply() { $GLOBALS['applied'] = true; return array('rebooting' => false, 'retval' => 0); }

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

$GLOBALS['cfg'] = array(
	'interfaces' => array(
		'wan' => array('if' => 'em0', 'descr' => 'WAN', 'enable' => '', 'ipaddr' => 'dhcp', 'ipaddrv6' => 'dhcp6'),
		'lan' => array('if' => 'em1', 'descr' => 'LAN', 'enable' => '', 'ipaddr' => '192.168.1.1', 'subnet' => '24'),
		'opt1' => array('if' => 'em1.10', 'descr' => 'DMZ', 'enable' => ''),
		'opt2' => array('if' => 'gif0'),
		'opt3' => array('if' => 'em2.100.11', 'descr' => 'Q11'),
	),
	'vlans' => array('vlan' => array(
		array('if' => 'em1', 'tag' => '10', 'tag_type' => '', 'pcp' => '3', 'descr' => 'dmz', 'vlanif' => 'em1.10'),
		array('if' => 'lagg0', 'tag' => '20', 'tag_type' => 'stag', 'pcp' => '', 'descr' => '', 'vlanif' => 'lagg0.20'),
	)),
	'qinqs' => array('qinqentry' => array(
		array('if' => 'em2', 'tag' => '100', 'tag_type' => 'stag', 'members' => '10 11 12 20', 'autogroup' => true, 'descr' => 'q', 'vlanif' => 'em2.100'),
	)),
	'laggs' => array('lagg' => array(
		array('members' => 'igb2,igb3', 'proto' => 'lacp', 'lacptimeout' => 'fast', 'lagghash' => 'l2', 'descr' => 'uplink', 'laggif' => 'lagg0'),
	)),
	'bridges' => array('bridged' => array(
		array('members' => 'lan,opt1,opt9', 'enablestp' => '', 'descr' => 'br', 'bridgeif' => 'bridge0', 'ifpriority' => 'lan:64', 'ifpathcost' => ''),
	)),
	'gifs' => array('gif' => array(
		array('if' => 'wan', 'remote-addr' => '198.51.100.1', 'tunnel-local-addr' => '10.0.0.1', 'tunnel-remote-addr' => '10.0.0.2',
		    'tunnel-remote-net' => '30', 'link1' => '', 'descr' => 'to branch', 'gifif' => 'gif0'),
	)),
	'gres' => array('gre' => array(
		array('if' => '_vip5f00', 'remote-addr' => '203.0.113.7', 'tunnel-local-addr' => '', 'tunnel-remote-addr' => '',
		    'tunnel-local-addr6' => 'fd00::1', 'tunnel-remote-addr6' => 'fd00::2', 'tunnel-remote-net6' => '64', 'descr' => '', 'greif' => 'gre0'),
	)),
	'ifgroups' => array('ifgroupentry' => array(
		array('ifname' => 'LANS', 'members' => 'lan opt1', 'descr' => 'inside'),
		array('ifname' => 'SPARE', 'members' => '', 'descr' => ''),
	)),
	'filter' => array('rule' => array(array('interface' => 'LANS', 'descr' => 'allow inside'))),
);
$GLOBALS['save_errors'] = array();
$GLOBALS['reboot_needed'] = false;
$v1 = restapi_routes_v1();
$seen = array();
foreach ($v1 as $r) {
	$seen["{$r['method']} {$r['path']}"] = $r;
}

/* ------------------------------------------------------------------ */
/* Schemas                                                             */
/* ------------------------------------------------------------------ */

$expect = array(
	'network/vlans' => array('interfaces_vlan_edit.php', array('if', 'tag_type', 'tag', 'pcp', 'descr')),
	'network/qinq' => array('interfaces_qinq_edit.php', array('if', 'tag_type', 'tag', 'autogroup', 'descr', 'members', 'members.*.tag')),
	'network/bridges' => array('interfaces_bridge_edit.php', array('members', 'descr', 'maxaddr', 'timeout', 'span', 'edge', 'autoedge', 'ptp',
	    'autoptp', 'static', 'private', 'ip6linklocal', 'enablestp', 'proto', 'stp', 'maxage', 'fwdelay', 'hellotime', 'priority', 'holdcnt',
	    'ifpriority.lan', 'ifpathcost.lan', 'ifpriority.opt1', 'ifpathcost.opt1')),
	'network/laggs' => array('interfaces_lagg_edit.php', array('members', 'proto', 'failovermaster', 'lacptimeout', 'lagghash', 'descr')),
	'network/gifs' => array('interfaces_gif_edit.php', array('if', 'remote-addr', 'tunnel-local-addr', 'tunnel-remote-addr', 'tunnel-remote-net',
	    'link1', 'link2', 'descr')),
	'network/gres' => array('interfaces_gre_edit.php', array('if', 'remote-addr', 'tunnel-local-addr', 'tunnel-remote-addr', 'tunnel-remote-net',
	    'tunnel-local-addr6', 'tunnel-remote-addr6', 'tunnel-remote-net6', 'link1', 'descr')),
	'network/groups' => array('interfaces_groups_edit.php', array('ifname', 'descr', 'members')),
	'network/assignments' => array('interfaces_assign.php', array('port')),
);
$registry = restapi_schemas();
foreach ($expect as $res => list($page, $names)) {
	check(isset($registry[$res]) && $registry[$res][1] === $page && $registry[$res][2] === 'interfaces', "{$res}: registered with {$page} in area interfaces");
	$s = restapi_schema_get($res);
	check(!empty($s['title']) && !empty($s['summary']) && !empty($s['summaryIcon']), "{$res}: title and summary");
	check(array_keys(restapi_schema_fields($s)) === $names, "{$res}: fields are the edit page's form fields (" . implode(', ', array_keys(restapi_schema_fields($s))) . ')');
}
/* every API save field is in the schema (iftun "fields"/"checkboxes"/"lists"/"maps") */
foreach (array('vlans' => 'network/vlans', 'qinqs' => 'network/qinq', 'bridges' => 'network/bridges', 'laggs' => 'network/laggs',
    'gifs' => 'network/gifs', 'gres' => 'network/gres', 'groups' => 'network/groups') as $res => $schema) {
	$t = restapi_iftun_types()[$res];
	check(($t['schema'] ?? null) === $schema, "{$res} names schema {$schema}");
	$names = array_keys(restapi_schema_fields(restapi_schema_get($schema)));
	foreach (array_merge($t['fields'], $t['checkboxes'], $t['lists'] ?? array()) as $f) {
		check(in_array($f, $names, true), "{$schema} has the save field {$f}");
	}
	foreach (array_keys($t['maps'] ?? array()) as $map) {
		check(in_array("{$map}.lan", $names, true), "{$schema} has the per-interface fields {$map}.<if>");
	}
}

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
check(array_column(field_of('network/vlans', 'if')['options'], 'label') === array('em1 (00:0c:29:00:00:01) - lan', 'em2 (00:0c:29:00:00:02)', 'lagg0 (00:0c:29:00:00:09)'),
    'VLAN parents are the edit page\'s list: "port (mac) - assignment"');
check(array_column(field_of('network/vlans', 'tag_type')['options'], 'value') === array('ctag', 'stag') && field_of('network/vlans', 'tag_type')['default'] === 'ctag' &&
    field_of('network/qinq', 'tag_type')['default'] === 'stag', 'tag types; VLANs default to C-Tag, QinQs to S-Tag');
check(array_column(field_of('network/gifs', 'if')['options'], 'label')[2] === '198.51.100.9 ("carp")' && field_of('network/gifs', 'if')['strict'] === false,
    'tunnel parents are the page\'s list (VIP labels decoded; legacy "parent|address" values kept)');
check(count(field_of('network/gifs', 'tunnel-remote-net')['options']) === 128 && count(field_of('network/gres', 'tunnel-remote-net')['options']) === 32 &&
    field_of('network/gres', 'tunnel-remote-net')['default'] === '32' && field_of('network/gres', 'tunnel-remote-net6')['default'] === '128',
    'tunnel subnet selects as on the pages');
$lm = field_of('network/laggs', 'members');
check($lm['type'] === 'checklist' && array_column($lm['options'], 'value') === array('igb4') && $lm['optionsFrom'] === 'member_choices',
    'LAGG members: the free ports for a new LAGG, the item\'s member_choices otherwise');
check(array_column(field_of('network/laggs', 'proto')['options'], 'value') === interfaces_lagg_protos() &&
    field_of('network/laggs', 'proto')['options'][1]['label'] === 'LACP' && !empty(field_of('network/laggs', 'proto')['options'][1]['detail']),
    'LAGG protocols with the page\'s help per protocol');
check(field_of('network/laggs', 'failovermaster')['visibleWhen'] === array('field' => 'proto', 'equals' => 'failover') &&
    field_of('network/laggs', 'lacptimeout')['visibleWhen'] === array('field' => 'proto', 'equals' => 'lacp') &&
    field_of('network/laggs', 'lagghash')['visibleWhen'] === array('field' => 'proto', 'in' => array('lacp', 'loadbalance')) &&
    array_column(field_of('network/laggs', 'lagghash')['options'], 'value') === array('l2,l3,l4', 'l2'),
    'LAGG options are shown for their protocols, as the page\'s script does');
check(field_of('network/bridges', 'maxaddr')['section']['advanced'] === true && field_of('network/bridges', 'proto')['section']['visibleWhen'] === array('field' => 'enablestp', 'truthy' => true) &&
    field_of('network/bridges', 'ifpriority.opt1')['label'] === 'DMZ priority', 'bridge advanced and STP sections; per-interface STP fields named after the interface');
check(array_column(field_of('network/bridges', 'span')['options'], 'value') === array('lan', 'opt1'), 'bridge port lists offer the page\'s interfaces');
check(field_of('network/qinq', 'members')['type'] === 'entry-grid' && field_of('network/groups', 'ifname')['maxLength'] === 15 &&
    array_column(field_of('network/groups', 'members')['options'], 'value') === array('wan', 'lan', 'opt1'), 'QinQ tag rows; group name and members');
check(array_column(field_of('network/assignments', 'port')['options'], 'value') === array_keys(interfaces_assign_port_list()), 'assignment ports are the page\'s port select');

/* ------------------------------------------------------------------ */
/* Save messages land on their fields                                  */
/* ------------------------------------------------------------------ */

$tun = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/interfaces_tunnels.inc");
$l2 = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/interfaces_l2.inc");
$asg = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/interfaces_assign.inc");
/* [message, field, source text that must exist in the include (null: built from parts)] */
$messages = array(
	'network/vlans' => array($tun, array(
		array('The field Parent interface is required.', 'if', 'gettext("Parent interface")'),
		array('The field VLAN tag is required.', 'tag', 'gettext("VLAN tag")'),
		array('The VLAN tag must be an integer between 1 and 4094.', 'tag'),
		array('The VLAN Priority must be an integer between 0 and 7.', 'pcp'),
		array('Interface supplied as parent is invalid', 'if'),
		array('The selected VLAN Tag Type is invalid.', 'tag_type'),
		array('The VLAN tag cannot be changed while the interface is assigned.', 'tag'),
		array('A VLAN with the tag 10 is already defined on this interface.', 'tag', 'A VLAN with the tag %s is already defined on this interface.'),
		array('A QinQ VLAN exists on em1 with this tag. Please remove it to use this tag for a normal VLAN.', 'tag',
		    'A QinQ VLAN exists on %s with this tag. Please remove it to use this tag for a normal VLAN.'),
		array('Error occurred creating interface, please retry.', null),
	)),
	'network/qinq' => array($l2, array(
		array('The selected VLAN Tag Type is invalid.', 'tag_type'),
		array('First level tag cannot be empty.', 'tag'),
		array('The first level tag must be an integer between 1 and 4094.', 'tag'),
		array('Modifying the first level tag of an existing entry is not allowed.', 'tag'),
		array('Modifying the interface of an existing entry is not allowed.', 'if'),
		array('Interface supplied as parent is invalid', 'if'),
		array('QinQ level already exists for this interface, edit it!', 'tag'),
		array('A normal VLAN exists with this tag please remove it to use this tag for QinQ first level.', 'tag'),
		array('Tags can contain only numbers or a range  (in format #-#) from 1 to 4094.', 'members',
		    'Tags can contain only numbers or a range  (in format #-#) from %1$s to %2$s.'),
		array('At least one tag must be entered.', 'members'),
		array('This QinQ tag cannot be deleted because it is still being used as an interface.', 'members'),
	)),
	'network/bridges' => array($l2, array(
		array('The field Member Interfaces is required.', 'members', 'gettext("Member Interfaces")'),
		array('Maxage needs to be an integer between 6 and 40.', 'maxage'),
		array('Maxaddr needs to be an integer.', 'maxaddr'),
		array('Timeout needs to be an integer.', 'timeout'),
		array('Forward Delay needs to be an integer between 4 and 30.', 'fwdelay'),
		array('Hello time for STP needs to be an integer between 1 and 2.', 'hellotime'),
		array('Priority for STP needs to be an integer between 0 and 61440.', 'priority'),
		array('Transmit Hold Count for STP needs to be an integer between 1 and 10.', 'holdcnt'),
		array('LAN interface priority for STP needs to be an integer between 0 and 240.', 'ifpriority.lan',
		    '%s interface priority for STP needs to be an integer between 0 and 240.'),
		array('DMZ interface path cost for STP needs to be an integer between 1 and 200000000.', 'ifpathcost.opt1',
		    '%s interface path cost for STP needs to be an integer between 1 and 200000000.'),
		array('At least one member interface must be selected for a bridge.', 'members'),
		array('The interface (LAN) is part of the Captive Portal and cannot be part of the bridge. Remove the interface to continue.', 'members', null),
		array('Sticky interface (LAN) is not part of the bridge. Remove the sticky interface to continue.', 'static',
		    'Sticky interface (%s) is not part of the bridge. Remove the sticky interface to continue.'),
		array('Private interface (LAN) is not part of the bridge. Remove the private interface to continue.', 'private',
		    'Private interface (%s) is not part of the bridge. Remove the private interface to continue.'),
		array('STP interface (LAN) is not part of the bridge. Remove the STP interface to continue.', 'stp',
		    'STP interface (%s) is not part of the bridge. Remove the STP interface to continue.'),
		array('STP interface (LAN) must not be a pseudo-interface or a VLAN interface.', 'stp',
		    'STP interface (%s) must not be a pseudo-interface or a VLAN interface.'),
		array('Edge interface (LAN) is not part of the bridge. Remove the edge interface to continue.', 'edge',
		    'Edge interface (%s) is not part of the bridge. Remove the edge interface to continue.'),
		array('Auto Edge interface (LAN) is not part of the bridge. Remove the auto edge interface to continue.', 'autoedge',
		    'Auto Edge interface (%s) is not part of the bridge. Remove the auto edge interface to continue.'),
		array('PTP interface (LAN) is not part of the bridge. Remove the PTP interface to continue.', 'ptp',
		    'PTP interface (%s) is not part of the bridge. Remove the PTP interface to continue.'),
		array('Auto PTP interface (LAN) is not part of the bridge. Remove the auto PTP interface to continue.', 'autoptp',
		    'Auto PTP interface (%s) is not part of the bridge. Remove the auto PTP interface to continue.'),
		array('A member interface passed does not exist in configuration', 'members'),
		array('A bridge interface cannot be a member of a bridge.', 'members'),
		array('Bridging a wireless interface is only possible in hostap mode.', 'members'),
		array('Span interface (LAN) cannot be part of the bridge. Remove the span interface from bridge members to continue.', 'span',
		    'Span interface (%s) cannot be part of the bridge. Remove the span interface from bridge members to continue.'),
		array('LAN is part of another bridge. Remove the interface from bridge members to continue.', 'members',
		    '%s is part of another bridge. Remove the interface from bridge members to continue.'),
	)),
	'network/laggs' => array($l2, array(
		array('The field Member interfaces is required.', 'members', 'gettext("Member interfaces")'),
		array('The field Lagg protocol is required.', 'proto', 'gettext("Lagg protocol")'),
		array('Interface supplied as member (igb9) is invalid', 'members', 'Interface supplied as member (%s) is invalid'),
		array('Interface supplied as member is invalid', 'members'),
		array('Protocol supplied is invalid', 'proto'),
		array('Failover Master Interface must be selected as member.', 'failovermaster'),
		array('Hash Algorithm is invalid.', 'lagghash'),
	)),
	'network/gifs' => array($tun, array(
		array('The field Parent interface is required.', 'if', 'gettext("Parent interface")'),
		array('The field gif remote address is required.', 'remote-addr', 'gettext("gif remote address")'),
		array('The field gif tunnel local address is required.', 'tunnel-local-addr', 'gettext("gif tunnel local address")'),
		array('The field gif tunnel remote address is required.', 'tunnel-remote-addr', 'gettext("gif tunnel remote address")'),
		array('The field gif tunnel remote netmask is required.', 'tunnel-remote-net', 'gettext("gif tunnel remote netmask")'),
		array('The tunnel local and tunnel remote fields must have valid IP addresses and must not contain CIDR masks or prefixes.', 'tunnel-local-addr'),
		array('The gif tunnel subnet must be an integer.', 'tunnel-remote-net'),
		array('The gif tunnel remote address must be IPv4 where tunnel local address is IPv4.', 'tunnel-remote-addr'),
		array('The gif tunnel subnet must be an integer between 1 and 128.', 'tunnel-remote-net'),
		array('The alias IP address family has to match the family of the remote peer address.', 'remote-addr'),
		array('A gif with the network 10.0.0.2 is already defined.', 'tunnel-remote-addr', 'A gif with the network %s is already defined.'),
	)),
	'network/gres' => array($tun, array(
		array('The field Parent interface is required.', 'if', 'gettext("Parent interface")'),
		array('The field Remote Address is required.', 'remote-addr', 'gettext("Remote Address")'),
		array('The field Local IPv4 tunnel address is required.', 'tunnel-local-addr', 'gettext("Local IPv4 tunnel address")'),
		array('The field Remote IPv4 tunnel address is required.', 'tunnel-remote-addr', 'gettext("Remote IPv4 tunnel address")'),
		array('The field IPv4 tunnel subnet is required.', 'tunnel-remote-net', 'gettext("IPv4 tunnel subnet")'),
		array('The field Local IPv6 tunnel address is required.', 'tunnel-local-addr6', 'gettext("Local IPv6 tunnel address")'),
		array('The field Remote IPv6 tunnel address is required.', 'tunnel-remote-addr6', 'gettext("Remote IPv6 tunnel address")'),
		array('The field IPv6 tunnel subnet is required.', 'tunnel-remote-net6', 'gettext("IPv6 tunnel subnet")'),
		array('The tunnel needs either a valid IPv4 or IPv6 tunnel configuration.', 'tunnel-local-addr'),
		array('The remote address must be a valid IP address.', 'remote-addr'),
		array('The IPv4 local tunnel address must be a valid IPv4 address.', 'tunnel-local-addr'),
		array('The IPv4 remote tunnel address must be a valid IPv4 address.', 'tunnel-remote-addr'),
		array('The IPv6 local tunnel address must be a valid IPv6 address.', 'tunnel-local-addr6'),
		array('The IPv6 remote tunnel address must be a valid IPv6 address.', 'tunnel-remote-addr6'),
		array('The IPv4 tunnel subnet must be an integer between 1 and 32.', 'tunnel-remote-net'),
		array('The IPv6 tunnel subnet must be an integer between 1 and 128.', 'tunnel-remote-net6'),
		array('A GRE tunnel with the same IPv4 tunnel network is already defined.', 'tunnel-remote-addr'),
		array('A GRE tunnel with the same IPv6 tunnel network is already defined.', 'tunnel-remote-addr6'),
	)),
	'network/groups' => array($l2, array(
		array('The field Group Name is required.', 'ifname', 'gettext("Group Name")'),
		array('Cannot use a reserved keyword as an interface name: any', 'ifname', 'Cannot use a reserved keyword as an interface name: %s'),
		array('Group name already exists!', 'ifname'),
		array('Group name cannot have more than 15 characters.', 'ifname'),
		array("Only letters (A-Z), digits (0-9) and '_' are allowed. Please choose another group name.", 'ifname',
		    "Only letters (A-Z), digits (0-9) and '_' are allowed."),
		array('The group name cannot start or end with a digit.', 'ifname'),
		array('Group name cannot start with pkg_', 'ifname'),
		array('The specified group name is already used by an interface. Please choose another name.', 'ifname'),
		array('An alias with this name already exists.', 'ifname'),
		array('Submission contained an invalid interface', 'members'),
	)),
	'network/assignments' => array($asg, array(
		array('Cannot add port em9 because it does not exist.', 'port', 'Cannot add port %1$s because it does not exist.'),
		array('Port em2 is already assigned to LAN.', 'port', 'Port %1$s is already assigned to %2$s.'),
		array('Cannot set port for opt9 because that interface is not assigned.', 'port', 'Cannot set port for %1$s because that interface is not assigned.'),
		array('Cannot set port opt1 because the submitted interface does not exist.', 'port', 'Cannot set port %1$s because the submitted interface does not exist.'),
		array('Port em2  was assigned to 2 interfaces: LAN (LAN) DMZ (OPT1)', 'port', null),
		array('Cannot set port bridge0 to interface OPT1 because this interface is a member of bridge0.', 'port',
		    'Cannot set port %1$s to interface %2$s because this interface is a member of %3$s.'),
		array('Vlan parent interface em5 does not exist anymore so vlan id 10 cannot be created please fix the issue before continuing.', 'port',
		    'Vlan parent interface %1$s does not exist anymore so vlan id %2$s cannot be created please fix the issue before continuing.'),
	)),
);
foreach ($messages as $res => list($source, $list)) {
	$schema = restapi_schema_get($res);
	foreach ($list as $m) {
		$text = (array_key_exists(2, $m) && ($m[2] !== null)) ? $m[2] : $m[0];
		if (!array_key_exists(2, $m) || ($m[2] !== null)) {
			check(strpos($source, $text) !== false, "{$res}: the save function says \"{$text}\"");
		}
		$r = restapi_errors_to_fields(array($m[0]), $schema, array());
		if ($m[1] === null) {
			check($r['fields'] === array() && $r['unmatched'] === array($m[0]), "{$res}: \"{$m[0]}\" stays in the error summary");
		} else {
			check(array_keys($r['fields']) === array($m[1]), "{$res}: \"{$m[0]}\" lands on {$m[1]} (got " . implode(',', array_keys($r['fields'])) . ')');
		}
	}
}

/* ------------------------------------------------------------------ */
/* Items: key, stored fields, fields, display                          */
/* ------------------------------------------------------------------ */

function list_of($res) {
	$out = restapi_h_iftun_list(array('path' => "/v1/interfaces/{$res}"));
	return $out['data'];
}
$vl = list_of('vlans');
check($vl[0]['vlanif'] === 'em1.10' && $vl[0]['interface'] === 'em1.10' && $vl[0]['id'] === 0 && $vl[0]['tag'] === '10' && $vl[0]['pcp'] === '3' &&
    $vl[0]['fields'] === array('if' => 'em1', 'tag_type' => 'ctag', 'tag' => '10', 'pcp' => '3', 'descr' => 'dmz'), 'a VLAN: vlanif, stored fields and form fields');
check($vl[0]['display'] === array('interface' => 'em1.10', 'parent' => 'em1 (LAN)', 'tag' => '10', 'tag_type' => 'C-Tag (0x8100)', 'priority' => '3',
    'description' => 'dmz', 'in_use' => true, 'in_use_reason' => 'This VLAN cannot be deleted because it is still being used as an interface.',
    'assigned_to' => 'DMZ'), 'VLAN display: parent with its assignment, tag type label, in use as DMZ');
check($vl[1]['display']['in_use'] === false && $vl[1]['display']['assigned_to'] === '' && $vl[1]['display']['tag_type'] === 'S-Tag (0x88A8)' &&
    $vl[1]['display']['parent'] === 'lagg0', 'an unassigned VLAN on an unassigned parent');

$qq = list_of('qinqs')[0];
check($qq['vlanif'] === 'em2.100' && $qq['fields']['members'] === array(array('tag' => '10'), array('tag' => '11'), array('tag' => '12'), array('tag' => '20')),
    'a QinQ: vlanif and member rows');
check($qq['display']['members'] === array('10-12', '20') && $qq['display']['autogroup'] === true && $qq['display']['in_use'] === true &&
    $qq['display']['in_use_reason'] === 'This QinQ cannot be deleted because one of it tags is still being used as an interface.' &&
    $qq['display']['assigned_to'] === 'Q11', 'QinQ display: member runs, in use through a member tag');

$lg = list_of('laggs')[0];
check($lg['laggif'] === 'lagg0' && !isset($lg['member_choices']) && $lg['display'] === array('interface' => 'LAGG0', 'members' => array('igb2', 'igb3'),
    'protocol' => 'LACP', 'description' => 'uplink', 'in_use' => true, 'in_use_reason' => 'This LAGG interface cannot be deleted because it is still being used.',
    'assigned_to' => ''), 'LAGG display: upper-case name and protocol, in use as a VLAN parent');
$one = restapi_h_iftun_get(array('path' => '/v1/interfaces/laggs/lagg0', 'params' => array('id' => 'lagg0')))['data'];
check($one['member_choices'] === array('igb2' => 'igb2 (00:0c:29:00:00:12)', 'igb3' => 'igb3 (00:0c:29:00:00:13)', 'igb4' => 'igb4 (00:0c:29:00:00:14)') &&
    $one['failovermaster_choices'] === array('auto' => 'auto') + $one['member_choices'] && $one['fields']['member_choices'] === $one['member_choices'],
    'a single LAGG carries its member choices (own members stay available), also inside fields for optionsFrom');

$br = list_of('bridges')[0];
check($br['bridgeif'] === 'bridge0' && $br['display']['members'] === array('LAN', 'DMZ', 'opt9') && $br['display']['interface'] === 'BRIDGE0' &&
    $br['display']['stp'] === true && $br['display']['in_use'] === false && $br['fields']['ifpriority'] === array('lan' => '64'),
    'bridge display: member labels (unknown ids stay), not in use');

$gif = list_of('gifs')[0];
check($gif['gifif'] === 'gif0' && $gif['display'] === array('interface' => 'gif0', 'parent' => 'WAN', 'remote' => '198.51.100.1',
    'tunnel' => array('10.0.0.1 → 10.0.0.2/30'), 'description' => 'to branch', 'in_use' => true,
    'in_use_reason' => 'This gif TUNNEL cannot be deleted because it is still being used as an interface.', 'assigned_to' => 'OPT2'),
    'GIF display: parent label, peer, tunnel, assigned as OPT2 (no description)');
$gre = list_of('gres')[0];
check($gre['greif'] === 'gre0' && $gre['display']['parent'] === 'WAN' && $gre['display']['tunnel'] === array('fd00::1 → fd00::2/64') &&
    $gre['display']['in_use'] === false, 'GRE display: a VIP parent is named by its interface (like the list page), IPv6-only tunnel');

$groups = list_of('groups');
check($groups[0]['ifname'] === 'LANS' && $groups[0]['name'] === 'LANS' && $groups[0]['display']['members'] === array('LAN', 'DMZ') &&
    $groups[0]['display']['in_use'] === true && $groups[0]['display']['assigned_to'] === '' &&
    $groups[0]['display']['in_use_reason'] === 'Interface group "LANS" cannot be deleted because it is in use by firewall rule "allow inside".',
    'group display: member labels, in use by a rule');
check($groups[1]['display']['members'] === array() && $groups[1]['display']['in_use'] === false, 'an empty unused group');

/* the in-use reasons are the delete functions' messages */
foreach (array("This VLAN cannot be deleted because it is still being used as an interface." => $tun,
    "This gif TUNNEL cannot be deleted because it is still being used as an interface." => $tun,
    "This GRE tunnel cannot be deleted because it is still being used as an interface." => $tun,
    "This QinQ cannot be deleted because it is still being used as an interface." => $l2,
    "This QinQ cannot be deleted because one of it tags is still being used as an interface." => $l2,
    'Interface group "%1$s" cannot be deleted because it is in use by %2$s.' => $l2,
    "This bridge cannot be deleted because it is assigned as an interface." => $l2) as $msg => $src) {
	check(strpos($src, $msg) !== false, "in_use_reason \"{$msg}\" is the delete function's message");
}

/* ------------------------------------------------------------------ */
/* Writes: partial updates, rows, refusals, keyed 422                  */
/* ------------------------------------------------------------------ */

$put = function ($res, $id, array $body) {
	return restapi_h_iftun_update(array('path' => "/v1/interfaces/{$res}/{$id}", 'params' => array('id' => $id), 'body' => $body, 'user' => array()));
};
$put('gifs', 'gif0', array('descr' => 'renamed', 'link1' => false, 'display' => array('x' => 1), 'fields' => array('y' => 2)));
check($GLOBALS['saved']['descr'] === 'renamed' && !isset($GLOBALS['saved']['link1']) && $GLOBALS['saved']['remote-addr'] === '198.51.100.1' &&
    $GLOBALS['saved']['tunnel-remote-net'] === '30' && !isset($GLOBALS['saved']['display']),
    'PUT is partial: omitted fields keep their value, false clears a checkbox, output keys sent back are ignored');
$put('qinqs', 'em2.100', array('members' => array(array('tag' => '10'), array('tag' => '30-32'), '40')));
check($GLOBALS['saved']['member0'] === '10' && $GLOBALS['saved']['member1'] === '30-32' && $GLOBALS['saved']['member2'] === '40' &&
    !isset($GLOBALS['saved']['member3']) && $GLOBALS['saved']['autogroup'] === 'yes', 'QinQ member rows (or plain tags) become the member0.. inputs');
$put('qinqs', 'em2.100', array('descr' => 'x'));
check($GLOBALS['saved']['member3'] === '20' && $GLOBALS['saved']['descr'] === 'x', 'a QinQ update without members keeps the stored rows');
check(api_error(function () use ($put) { $put('qinqs', 'em2.100', array('members' => array(array('tag' => array(1))))); })->status === 400,
    'a QinQ row must hold a tag');
$put('laggs', 'lagg0', array('proto' => 'failover', 'failovermaster' => 'igb3', 'member_choices' => array('a' => 'b')));
check($GLOBALS['saved']['members'] === array('igb2', 'igb3') && $GLOBALS['saved']['proto'] === 'failover' && !isset($GLOBALS['saved']['member_choices']),
    'a LAGG update keeps the members and ignores the choices sent back');

$e = api_error(function () { restapi_h_iftun_delete(array('path' => '/v1/interfaces/vlans/em1.10', 'params' => array('id' => 'em1.10'), 'user' => array())); });
check($e && $e->status === 409 && $e->error_code === 'in_use' && $e->getMessage() === $vl[0]['display']['in_use_reason'],
    'deleting an assigned VLAN is 409 with the page\'s message (the same as display.in_use_reason)');
$e = api_error(function () { restapi_h_iftun_delete(array('path' => '/v1/interfaces/laggs/lagg0', 'params' => array('id' => 'lagg0'), 'user' => array())); });
check($e && $e->status === 409 && $e->getMessage() === $lg['display']['in_use_reason'], 'deleting a LAGG that a VLAN uses is 409');
$e = api_error(function () { restapi_h_iftun_delete(array('path' => '/v1/interfaces/groups/LANS', 'params' => array('id' => 'LANS'), 'user' => array())); });
check($e && $e->status === 409 && $e->getMessage() === $groups[0]['display']['in_use_reason'], 'deleting a group a rule uses is 409');
$ok = restapi_h_iftun_delete(array('path' => '/v1/interfaces/vlans/lagg0.20', 'params' => array('id' => 'lagg0.20'), 'user' => array()));
check($ok['data'] === array('deleted' => 1, 'interface' => 'lagg0.20'), 'an unused VLAN is deleted by its vlanif');

/* a refused save answers 422 keyed by the route's schema */
list($route) = restapi_match($v1, 'POST', '/v1/interfaces/vlans');
check($route['schema'] === 'network/vlans', 'POST /v1/interfaces/vlans names its schema');
$GLOBALS['save_errors'] = array('The VLAN tag must be an integer between 1 and 4094.', 'Interface supplied as parent is invalid');
restapi_request_context(array('schema' => $route['schema'], 'body' => array('if' => 'em7', 'tag' => 5000)));
$e = api_error(function () { restapi_h_iftun_create(array('path' => '/v1/interfaces/vlans', 'body' => array('if' => 'em7', 'tag' => 5000), 'user' => array())); });
check($e && $e->status === 422 && $e->details['fields'] === array('tag' => 'The VLAN tag must be an integer between 1 and 4094.',
    'if' => 'Interface supplied as parent is invalid'), 'VLAN save errors are keyed to tag and if');
$GLOBALS['save_errors'] = array();
restapi_request_context(array('schema' => null, 'body' => array()));

/* ------------------------------------------------------------------ */
/* Assignments                                                         */
/* ------------------------------------------------------------------ */

$al = restapi_h_ifassign_list(array());
check(array_is_list($al['data']) && count($al['data']) === 5, 'GET /v1/interfaces/assignments: data is the list of assignments');
$opt1 = $al['data'][2];
check($opt1['interface'] === 'opt1' && $opt1['description'] === 'DMZ' && $opt1['port'] === 'em1.10' && $opt1['port_descr'] === 'VLAN 10 on em1 - lan (dmz)' &&
    $opt1['removable'] === false && $opt1['display']['in_use'] === true &&
    $opt1['display']['in_use_reason'] === 'The interface is part of a group. Please remove it from the group to continue', 'an assignment in use is not removable');
check($al['data'][0]['removable'] === false && $al['data'][0]['display']['in_use_reason'] === 'The WAN interface cannot be deleted.' &&
    $al['data'][1]['removable'] === false && $al['data'][3]['removable'] === true, 'WAN and LAN are never removable; an unused OPT is');
check($al['data'][0]['display']['addresses'] === array('DHCP', 'DHCPv6') && $al['data'][1]['display']['addresses'] === array('192.168.1.1/24') &&
    $al['data'][3]['description'] === 'OPT2' && $al['data'][3]['display']['enabled'] === false, 'the page\'s address column and default descriptions');
check($al['meta']['available_ports'] === array(array('value' => 'em2', 'label' => 'em2 (00:0c:29)'), array('value' => 'em3', 'label' => 'em3 (00:0c:29)'),
    array('value' => 'lagg0', 'label' => 'lagg0 (00:0c:29)')) && count($al['meta']['ports']) === 8 &&
    $al['meta']['reboot_needed'] === false && $al['meta']['pending'] === false, 'meta: the free ports to add, every port to change to, the apply state');

$e = api_error(function () { restapi_h_ifassign_delete(array('params' => array('name' => 'opt1'), 'body' => array(), 'query' => array('confirm' => 'true'))); });
check($e && $e->status === 409 && $e->getMessage() === $opt1['display']['in_use_reason'], 'unassigning an interface in use is 409 with the page\'s message');
check(api_error(function () { restapi_h_ifassign_delete(array('params' => array('name' => 'wan'), 'body' => array('confirm' => true))); })->status === 403,
    'WAN is never unassigned');
check(api_error(function () { restapi_h_ifassign_delete(array('params' => array('name' => 'opt2'), 'body' => array(), 'query' => array())); })->status === 400,
    'a delete needs the confirmation');
$d = restapi_h_ifassign_delete(array('params' => array('name' => 'opt2'), 'body' => array(), 'query' => array('confirm' => 'true')));
check($d['data']['deleted'] === 'opt2' && $GLOBALS['deleted'] === array('opt2'), 'DELETE ?confirm=true unassigns an unused interface');

check(restapi_h_ifassign_pending(array())['data'] === array('pending' => false, 'reboot_needed' => false, 'reload_pending' => false, 'message' => ''),
    'pending: nothing to apply');
$GLOBALS['reboot_needed'] = true;
check(restapi_h_ifassign_pending(array())['data']['pending'] === true && strpos(restapi_h_ifassign_pending(array())['data']['message'], 'mismatch') !== false,
    'pending: an interface mismatch waits for "Apply Changes"');
check(api_error(function () { restapi_h_ifassign_apply(array('body' => array('confirm' => true), 'user' => array('name' => 'bob'))); })->status === 403,
    'the reboot of a mismatch apply needs an administrator');
$GLOBALS['reboot_needed'] = false;
check(api_error(function () { restapi_h_ifassign_apply(array('body' => array())); })->status === 400, 'apply needs the confirmation');
$ap = restapi_h_ifassign_apply(array('body' => array('confirm' => true)));
check($ap['data']['applied'] === true && $ap['data']['rebooting'] === false && !empty($GLOBALS['applied']), 'apply without a mismatch reloads the filter');

/* ------------------------------------------------------------------ */
/* Wiring                                                              */
/* ------------------------------------------------------------------ */

foreach (array('GET /v1/interfaces/assignments', 'GET /v1/interfaces/assignments/pending', 'POST /v1/interfaces/assignments/apply',
    'GET /v1/interfaces/assignments/{name}', 'POST /v1/interfaces/assignments', 'PUT /v1/interfaces/assignments/{name}',
    'DELETE /v1/interfaces/assignments/{name}') as $key) {
	check(isset($seen[$key]) && $seen[$key]['page'] === 'interfaces_assign.php' && $seen[$key]['area'] === 'interfaces', "route {$key}");
}
list($route) = restapi_match($v1, 'GET', '/v1/interfaces/assignments/pending');
check($route['handler'] === 'restapi_h_ifassign_pending', '"pending" is not taken for an interface name');
list($route) = restapi_match($v1, 'POST', '/v1/interfaces/assignments/apply');
check($route['handler'] === 'restapi_h_ifassign_apply' && $route['write'], 'POST .../apply is a write');
check($seen['POST /v1/interfaces/assignments']['schema'] === 'network/assignments' && $seen['PUT /v1/interfaces/assignments/{name}']['schema'] === 'network/assignments',
    'assignment saves name their schema');
foreach (array('vlans' => 'network/vlans', 'qinqs' => 'network/qinq', 'bridges' => 'network/bridges', 'laggs' => 'network/laggs',
    'gifs' => 'network/gifs', 'gres' => 'network/gres', 'groups' => 'network/groups') as $res => $schema) {
	foreach (array("GET /v1/interfaces/{$res}" => null, "GET /v1/interfaces/{$res}/{id}" => null, "POST /v1/interfaces/{$res}" => $schema,
	    "PUT /v1/interfaces/{$res}/{id}" => $schema, "DELETE /v1/interfaces/{$res}/{id}" => null) as $key => $want) {
		check(isset($seen[$key]) && $seen[$key]['schema'] === $want && $seen[$key]['area'] === 'interfaces', "route {$key}" . ($want ? " names {$want}" : ''));
	}
	list(, $params) = restapi_match($v1, 'GET', "/v1/interfaces/{$res}/em1.10");
	check($params['id'] === 'em1.10', "{$res} can be addressed by its interface name");
}
check(preg_match("/function restapi_capabilities\(\) \{\n\treturn array\([^)]*'assignments'/", file_get_contents("{$root}/src/etc/inc/restapi.inc")) === 1,
    'capability "assignments"');
$front = file_get_contents("{$root}/src/usr/local/www/api/index.php");
foreach (array('interfaces_tunnels.inc', 'interfaces_l2.inc', 'interfaces_assign.inc') as $inc) {
	check(strpos($front, "require_once('{$inc}');") !== false, "the front controller loads {$inc}");
}
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc"), "require_once('restapi/routes_interfaces.inc');") !== false &&
    strpos(file_get_contents("{$root}/src/etc/inc/restapi/schema.inc"), "require_once('restapi/schemas/network_interfaces.inc');") !== false,
    'routes and schemas are loaded');
check(strpos($asg, "define('INTERFACES_ASSIGN_RELOAD_FILE', '/tmp/reload_interfaces');") !== false &&
    strpos(file_get_contents("{$root}/src/usr/local/www/interfaces_assign.php"), 'file_exists("/tmp/reload_interfaces")') !== false,
    'pending reads the file the page checks for its apply box');
check(strpos(file_get_contents("{$root}/.github/workflows/quality.yml"), 'RestApiAssignmentsSmokeTest') !== false, 'CI runs this test');

echo "REST API assignments smoke test passed.\n";
