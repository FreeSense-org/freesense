<?php
/*
 * network_interfaces.inc
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
 * Schemas of the Network > Assignments editors (POST/PUT
 * /api/v1/interfaces/{vlans,qinqs,bridges,laggs,gifs,gres,groups} and
 * /api/v1/interfaces/assignments). Field names are the edit pages' form
 * fields, which the API takes; choices come from the same functions the
 * pages call (interfaces_tunnels.inc, interfaces_l2.inc,
 * interfaces_assign.inc), so a form offers what the save functions accept.
 * Labels follow the save functions' messages so restapi_errors_to_fields()
 * keys them; "errorMatch" adds their other wordings.
 *
 *   network/vlans        a VLAN (interfaces_vlan_edit.php)
 *   network/qinq         a QinQ entry; members are rows {tag} (interfaces_qinq_edit.php)
 *   network/bridges      a bridge; per-interface STP values are ifpriority.<if> / ifpathcost.<if>
 *   network/laggs        a LAGG; members and the failover master take the item's
 *                        "member_choices" / "failovermaster_choices" (optionsFrom)
 *   network/gifs         a GIF tunnel
 *   network/gres         a GRE tunnel
 *   network/groups       an interface group
 *   network/assignments  the network port of an interface (add or change)
 *
 * The choice lists need the GUI includes; without them (unit tests) they are empty.
 */

/* A function's list when the include that defines it is loaded, else an empty list. */
function restapi_schema_net_call($fn, ...$args) {
	return function_exists($fn) ? (array)call_user_func_array($fn, $args) : array();
}

/* Port lists ([port => info with "mac"]) as "port (mac)" choices, like the edit pages. */
function restapi_schema_net_ports(array $ports, $with_friendly = false) {
	$out = array();
	foreach ($ports as $ifn => $info) {
		$label = $ifn . (empty($info['mac']) ? '' : " ({$info['mac']})");
		if ($with_friendly && function_exists('convert_real_interface_to_friendly_interface_name')) {
			$iface = convert_real_interface_to_friendly_interface_name($ifn);
			if (isset($iface) && (strlen($iface) > 0)) {
				$label .= " - {$iface}";
			}
		}
		$out[$ifn] = $label;
	}
	return $out;
}

function restapi_schema_net_descr($help = null) {
	return array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
	    'help' => $help ?? gettext('A description for administrative reference (not parsed).'));
}

function restapi_schema_network_vlans() {
	$parents = restapi_schema_net_ports(restapi_schema_net_call('interfaces_vlan_parent_list'), true);
	$tag_types = restapi_schema_net_call('interfaces_vlan_tag_types');
	return array(
		'title' => gettext('VLAN'),
		'summary' => gettext('VLAN {tag} on {if}'),
		'summaryIcon' => 'tags',
		'sections' => array(
			array('id' => 'vlan', 'title' => gettext('VLAN configuration'), 'fields' => array(
				array('name' => 'if', 'type' => 'select', 'label' => gettext('Parent interface'), 'required' => true, 'width' => 'half',
				    'options' => restapi_schema_choices($parents), 'help' => gettext('Only VLAN capable interfaces are shown.'),
				    'errorMatch' => array('supplied as parent')),
				array('name' => 'tag_type', 'type' => 'select', 'label' => gettext('VLAN tag type'), 'required' => true, 'width' => 'half',
				    'default' => 'ctag', 'options' => restapi_schema_choices($tag_types),
				    'help' => gettext('The type of VLAN tag to use (defaults to C-Tag).')),
				array('name' => 'tag', 'type' => 'number', 'label' => gettext('VLAN tag'), 'required' => true, 'min' => 1, 'max' => 4094,
				    'width' => 'half', 'placeholder' => '1', 'help' => gettext('802.1Q VLAN tag (between 1 and 4094). It cannot change while the VLAN is assigned.'),
				    'errorMatch' => array('a vlan with the tag', 'qinq vlan exists')),
				array('name' => 'pcp', 'type' => 'number', 'label' => gettext('VLAN priority'), 'min' => 0, 'max' => 7, 'width' => 'half',
				    'placeholder' => '0', 'help' => gettext('802.1Q VLAN priority (between 0 and 7).')),
				restapi_schema_net_descr(),
			)),
		),
	);
}

function restapi_schema_network_qinq() {
	$parents = restapi_schema_net_ports(restapi_schema_net_call('interfaces_vlan_parent_list'));
	$tag_types = restapi_schema_net_call('interfaces_vlan_tag_types');
	return array(
		'title' => gettext('QinQ'),
		'summary' => gettext('QinQ {tag} on {if}'),
		'summaryIcon' => 'layer-group',
		'sections' => array(
			array('id' => 'qinq', 'title' => gettext('QinQ configuration'), 'fields' => array(
				array('name' => 'if', 'type' => 'select', 'label' => gettext('Parent interface'), 'required' => true, 'width' => 'half',
				    'options' => restapi_schema_choices($parents),
				    'help' => gettext('Only QinQ capable interfaces are shown. It cannot be changed later.'),
				    'errorMatch' => array('supplied as parent', 'modifying the interface')),
				array('name' => 'tag_type', 'type' => 'select', 'label' => gettext('VLAN tag type'), 'required' => true, 'width' => 'half',
				    'default' => 'stag', 'options' => restapi_schema_choices($tag_types),
				    'help' => gettext('The type of VLAN tag to use for the first level tag (defaults to S-Tag).')),
				array('name' => 'tag', 'type' => 'number', 'label' => gettext('First level tag'), 'required' => true, 'min' => 1, 'max' => 4094,
				    'width' => 'half', 'help' => gettext('The outer VLAN tag; the member tags below are stacked on it. It cannot be changed later.'),
				    'errorMatch' => array('qinq level already exists', 'a normal vlan exists')),
				array('name' => 'autogroup', 'type' => 'switch', 'label' => gettext('Interface group'), 'width' => 'half',
				    'text' => gettext('Add the interfaces to the QinQ interface group'), 'help' => gettext('Allows rules to be written more easily.')),
				restapi_schema_net_descr(),
				array('name' => 'members', 'type' => 'entry-grid', 'label' => gettext('Tags'), 'required' => true, 'min' => 1,
				    'addLabel' => gettext('Add tag'), 'help' => gettext('Member tags: a tag (2) or a range (2-3) per row.'),
				    'fields' => array(
					array('name' => 'tag', 'type' => 'text', 'label' => gettext('Tag or range'), 'mono' => true, 'placeholder' => '10-20'),
				    ),
				    'errorMatch' => array('at least one tag', 'qinq tag cannot be deleted')),
			)),
		),
	);
}

function restapi_schema_network_bridges() {
	$ports = restapi_schema_net_call('interfaces_bridge_port_list', '');
	$choices = restapi_schema_choices((array)($ports['list'] ?? array()));
	$ifaces = restapi_schema_net_call('interfaces_bridge_iface_list');
	$stp_on = array('field' => 'enablestp', 'truthy' => true);
	$portlist = function ($name, $label, $help, array $match) use ($choices) {
		return array('name' => $name, 'type' => 'checklist', 'label' => $label, 'options' => $choices, 'help' => $help, 'errorMatch' => $match);
	};

	$per_if = array();
	foreach ($ifaces as $ifn => $ifdescr) {
		$per_if[] = array('name' => "ifpriority.{$ifn}", 'type' => 'number', 'label' => sprintf(gettext('%s priority'), $ifdescr),
		    'min' => 0, 'max' => 240, 'step' => 16, 'placeholder' => '128', 'width' => 'half',
		    'errorMatch' => array(mb_strtolower("{$ifdescr} interface priority")));
		$per_if[] = array('name' => "ifpathcost.{$ifn}", 'type' => 'number', 'label' => sprintf(gettext('%s path cost'), $ifdescr),
		    'min' => 0, 'max' => 200000000, 'placeholder' => '0', 'width' => 'half',
		    'errorMatch' => array(mb_strtolower("{$ifdescr} interface path cost")));
	}

	$sections = array(
		array('id' => 'bridge', 'title' => gettext('Bridge configuration'), 'fields' => array(
			$portlist('members', gettext('Member interfaces'), gettext('Interfaces participating in the bridge.'),
			    array('at least one member interface', 'a member interface passed', 'cannot be a member of a bridge',
			    'bridging a wireless', 'part of another bridge', 'captive portal')) + array('required' => true),
			restapi_schema_net_descr(),
		)),
		array('id' => 'advanced', 'title' => gettext('Advanced configuration'), 'advanced' => true, 'fields' => array(
			array('name' => 'maxaddr', 'type' => 'number', 'label' => gettext('Cache size'), 'min' => 0, 'width' => 'half',
			    'placeholder' => '2000', 'help' => gettext('Size of the bridge address cache. The default is 2000 entries.'),
			    'errorMatch' => array('maxaddr')),
			array('name' => 'timeout', 'type' => 'number', 'label' => gettext('Cache expire time'), 'min' => 0, 'unit' => gettext('seconds'),
			    'width' => 'half', 'placeholder' => '1200',
			    'help' => gettext('Address cache entries expire after this many seconds; 0 never expires them. The default is 1200 seconds.'),
			    'errorMatch' => array('timeout needs')),
			$portlist('span', gettext('Span ports'), gettext('Span ports transmit a copy of every frame received by the bridge, ' .
			    'for passively snooping a bridged network. A span port cannot be a bridge member.'), array('span interface')),
			$portlist('edge', gettext('Edge ports'), gettext('An edge port connects directly to end stations and cannot create bridging ' .
			    'loops; it transitions straight to forwarding.'), array('edge interface')),
			$portlist('autoedge', gettext('Auto edge ports'), gettext('Automatically detect the edge status (the default for all members). ' .
			    'Selecting an interface here disables its auto edge status.'), array('auto edge interface')),
			$portlist('ptp', gettext('PTP ports'), gettext('Mark the interface as a point-to-point link, required for straight transitions ' .
			    'to forwarding on a direct link to another RSTP-capable switch.'), array('ptp interface')),
			$portlist('autoptp', gettext('Auto PTP ports'), gettext('Automatically detect the point-to-point status from the full duplex link ' .
			    'status (the default for members). Selecting an interface here disables it.'), array('auto ptp interface')),
			$portlist('static', gettext('Sticky ports'), gettext('Dynamically learned addresses on a sticky interface are treated as static: ' .
			    'they never age out or move to another interface.'), array('sticky interface')),
			$portlist('private', gettext('Private ports'), gettext('A private interface does not forward traffic to any other private interface.'),
			    array('private interface')),
			array('name' => 'ip6linklocal', 'type' => 'switch', 'label' => gettext('IPv6 auto link-local'),
			    'text' => gettext('Set AUTO_LINKLOCAL on the bridge and clear it on every member'),
			    'help' => gettext('Required when the bridge interface is used for stateless autoconfiguration.')),
			array('name' => 'enablestp', 'type' => 'switch', 'label' => gettext('RSTP/STP'), 'text' => gettext('Enable RSTP/STP')),
		)),
		array('id' => 'stp', 'title' => gettext('RSTP/STP'), 'advanced' => true, 'visibleWhen' => $stp_on, 'fields' => array(
			array('name' => 'proto', 'type' => 'segmented', 'label' => gettext('Protocol'), 'default' => 'rstp',
			    'options' => restapi_schema_choices(array('rstp' => 'RSTP', 'stp' => 'STP')), 'help' => gettext('Protocol used for spanning tree.')),
			$portlist('stp', gettext('STP interfaces'), gettext('Enable the Spanning Tree Protocol on these members to detect and remove loops.'),
			    array('stp interface')),
			array('name' => 'maxage', 'type' => 'number', 'label' => gettext('Valid time'), 'min' => 6, 'max' => 40, 'unit' => gettext('seconds'),
			    'placeholder' => '20', 'width' => 'third', 'help' => gettext('How long an STP configuration is valid. Default 20 seconds.'),
			    'errorMatch' => array('maxage')),
			array('name' => 'fwdelay', 'type' => 'number', 'label' => gettext('Forward time'), 'min' => 4, 'max' => 30, 'unit' => gettext('seconds'),
			    'placeholder' => '15', 'width' => 'third', 'help' => gettext('Time before an interface begins forwarding. Default 15 seconds.'),
			    'errorMatch' => array('forward delay')),
			array('name' => 'hellotime', 'type' => 'number', 'label' => gettext('Hello time'), 'min' => 1, 'max' => 2,
			    'unit' => gettext('seconds'), 'placeholder' => '2', 'width' => 'third',
			    'help' => gettext('Time between STP configuration messages; only changes in legacy STP mode. Default 2 seconds.')),
			array('name' => 'priority', 'type' => 'number', 'label' => gettext('Priority'), 'min' => 0, 'max' => 61440, 'placeholder' => '32768',
			    'width' => 'half', 'help' => gettext('Bridge priority for spanning tree. Default 32768.'), 'errorMatch' => array('priority for stp')),
			array('name' => 'holdcnt', 'type' => 'number', 'label' => gettext('Hold count'), 'min' => 1, 'max' => 10, 'placeholder' => '6',
			    'width' => 'half', 'help' => gettext('Packets transmitted before being rate limited. Default 6.'),
			    'errorMatch' => array('transmit hold count')),
		)),
	);
	if (!empty($per_if)) {
		$sections[] = array('id' => 'stp_ports', 'title' => gettext('Per-interface spanning tree'), 'advanced' => true, 'visibleWhen' => $stp_on,
		    'description' => gettext('Priority 0-240 in steps of 16 (default 128); path cost 1-200000000, 0 or empty for automatic.'),
		    'fields' => $per_if);
	}
	return array(
		'title' => gettext('Bridge'),
		'summary' => gettext('Bridge of {members}'),
		'summaryIcon' => 'bridge',
		'sections' => $sections,
	);
}

function restapi_schema_network_laggs() {
	global $lagg_hash_list;
	$ports = restapi_schema_choices(restapi_schema_net_ports(restapi_schema_net_call('interfaces_lagg_port_list', null)));
	$protos = array(
		'none' => array(gettext('NONE'), gettext('Disables any traffic without disabling the LAGG interface itself.')),
		'lacp' => array(gettext('LACP'), gettext('IEEE 802.3ad Link Aggregation Control Protocol: negotiates aggregable links with the peer; ' .
		    'ports must have the same speed and run full duplex.')),
		'failover' => array(gettext('FAILOVER'), gettext('Sends and receives only through the master port; the next active port takes over when it fails.')),
		'loadbalance' => array(gettext('LOADBALANCE'), gettext('Balances outgoing traffic by a hash of the protocol headers and accepts ' .
		    'incoming traffic on any active port. Static: nothing is negotiated with the peer.')),
		'roundrobin' => array(gettext('ROUNDROBIN'), gettext('Distributes outgoing traffic round-robin over all active ports and accepts ' .
		    'incoming traffic on any active port.')),
	);
	$proto_options = array();
	foreach (restapi_schema_net_call('interfaces_lagg_protos') ?: array_keys($protos) as $p) {
		$proto_options[] = array('value' => $p, 'label' => $protos[$p][0] ?? strtoupper($p), 'detail' => $protos[$p][1] ?? '');
	}
	return array(
		'title' => gettext('LAGG'),
		'summary' => gettext('{proto} LAGG of {members}'),
		'summaryIcon' => 'link',
		'sections' => array(
			array('id' => 'lagg', 'title' => gettext('LAGG configuration'), 'fields' => array(
				array('name' => 'members', 'type' => 'checklist', 'label' => gettext('Member interfaces'), 'required' => true,
				    'options' => $ports, 'optionsFrom' => 'member_choices',
				    'help' => gettext('The ports aggregated by the LAGG. Assigned ports and members of other LAGGs are not offered.'),
				    'errorMatch' => array('supplied as member')),
				array('name' => 'proto', 'type' => 'select', 'label' => gettext('LAGG protocol'), 'required' => true, 'default' => 'none',
				    'width' => 'half', 'options' => $proto_options, 'errorMatch' => array('protocol supplied')),
				array('name' => 'failovermaster', 'type' => 'select', 'label' => gettext('Failover master interface'), 'default' => 'auto',
				    'width' => 'half', 'options' => array_merge(array(array('value' => 'auto', 'label' => 'auto')), $ports),
				    'optionsFrom' => 'failovermaster_choices', 'visibleWhen' => array('field' => 'proto', 'equals' => 'failover'),
				    'help' => gettext('With auto the first member is the master; the others are failover ports.')),
				array('name' => 'lacptimeout', 'type' => 'segmented', 'label' => gettext('LACP timeout'), 'default' => 'slow',
				    'options' => restapi_schema_choices(array('slow' => gettext('Slow (default)'), 'fast' => gettext('Fast'))),
				    'visibleWhen' => array('field' => 'proto', 'equals' => 'lacp'),
				    'help' => gettext('PDUs are sent every 30 seconds (slow) or every second (fast); the timeout is three missed PDUs.')),
				array('name' => 'lagghash', 'type' => 'select', 'label' => gettext('Hash algorithm'), 'default' => 'l2,l3,l4', 'width' => 'half',
				    'options' => restapi_schema_choices((array)($lagg_hash_list ?? array())),
				    'visibleWhen' => array('field' => 'proto', 'in' => array('lacp', 'loadbalance')),
				    'help' => gettext('Packet layers hashed to choose a port: 2 MAC address and VLAN, 3 IP address, 4 port.')),
				restapi_schema_net_descr(),
			)),
		),
	);
}

/* The parent select of the GIF and GRE editors. Legacy "parent|address" values are kept (strict: false). */
function restapi_schema_net_tunnel_parent($type) {
	return array('name' => 'if', 'type' => 'select', 'label' => gettext('Parent interface'), 'required' => true, 'width' => 'half', 'strict' => false,
	    'options' => restapi_schema_choices(restapi_schema_net_call('interfaces_tunnel_parent_list', $type)),
	    'help' => gettext('This interface serves as the local address of the tunnel.'));
}

/* "128".."1" as select choices, like the edit pages. */
function restapi_schema_net_bits($max) {
	$out = array();
	for ($i = $max; $i >= 1; $i--) {
		$out[] = array('value' => (string)$i, 'label' => (string)$i);
	}
	return $out;
}

function restapi_schema_network_gifs() {
	return array(
		'title' => gettext('GIF tunnel'),
		'summary' => gettext('GIF tunnel to {remote-addr}'),
		'summaryIcon' => 'route',
		'sections' => array(
			array('id' => 'gif', 'title' => gettext('GIF configuration'), 'fields' => array(
				restapi_schema_net_tunnel_parent('gif'),
				array('name' => 'remote-addr', 'type' => 'text', 'label' => gettext('GIF remote address'), 'required' => true, 'mono' => true,
				    'width' => 'half', 'help' => gettext('Peer address where encapsulated GIF packets are sent.'),
				    'errorMatch' => array('remote peer address')),
				array('name' => 'tunnel-local-addr', 'type' => 'text', 'label' => gettext('GIF tunnel local address'), 'required' => true,
				    'mono' => true, 'width' => 'third', 'help' => gettext('Local GIF tunnel endpoint.'),
				    'errorMatch' => array('tunnel local and tunnel remote')),
				array('name' => 'tunnel-remote-addr', 'type' => 'text', 'label' => gettext('GIF tunnel remote address'), 'required' => true,
				    'mono' => true, 'width' => 'third', 'help' => gettext('Remote GIF tunnel endpoint.'),
				    'errorMatch' => array('a gif with the network')),
				array('name' => 'tunnel-remote-net', 'type' => 'select', 'label' => gettext('GIF tunnel subnet'), 'required' => true,
				    'width' => 'third', 'default' => '128', 'options' => restapi_schema_net_bits(128),
				    'help' => gettext('Prefix length of the tunnelled network (1-32 for IPv4).'),
				    'errorMatch' => array('gif tunnel remote netmask')),
				array('name' => 'link1', 'type' => 'switch', 'label' => gettext('ECN friendly behavior'),
				    'text' => gettext('ECN friendly behavior (violates RFC 2893; agree with the peer)')),
				array('name' => 'link2', 'type' => 'switch', 'label' => gettext('Outer source filtering'),
				    'text' => gettext('Disable automatic filtering of the outer GIF source'),
				    'help' => gettext('When disabled, martian and inbound filtering is not performed, which allows asymmetric routing of the outer traffic.')),
				restapi_schema_net_descr(),
			)),
		),
	);
}

function restapi_schema_network_gres() {
	$tunnel = function ($v) {
		$six = ($v === 6);
		$sfx = $six ? '6' : '';
		$fam = $six ? 'IPv6' : 'IPv4';
		return array('id' => "ipv{$v}", 'title' => $fam, 'description' => $six ? gettext('Leave empty for an IPv4-only tunnel.') :
		    gettext('Leave empty for an IPv6-only tunnel.'), 'fields' => array(
			array('name' => "tunnel-local-addr{$sfx}", 'type' => 'text', 'label' => sprintf(gettext('Local %s tunnel address'), $fam),
			    'mono' => true, 'width' => 'third', 'errorMatch' => array(mb_strtolower("{$fam} local tunnel address"))
			    + ($six ? array() : array(1 => 'needs either a valid ipv4 or ipv6'))),
			array('name' => "tunnel-remote-addr{$sfx}", 'type' => 'text', 'label' => sprintf(gettext('Remote %s tunnel address'), $fam),
			    'mono' => true, 'width' => 'third', 'errorMatch' => array(mb_strtolower("{$fam} remote tunnel address"),
			    mb_strtolower("same {$fam} tunnel network"))),
			array('name' => "tunnel-remote-net{$sfx}", 'type' => 'select', 'label' => sprintf(gettext('%s tunnel subnet'), $fam),
			    'width' => 'third', 'default' => $six ? '128' : '32', 'options' => restapi_schema_net_bits($six ? 128 : 32),
			    'help' => sprintf(gettext('Prefix length of the tunnelled %s network.'), $fam)),
		));
	};
	return array(
		'title' => gettext('GRE tunnel'),
		'summary' => gettext('GRE tunnel to {remote-addr}'),
		'summaryIcon' => 'route',
		'sections' => array(
			array('id' => 'gre', 'title' => gettext('GRE configuration'), 'fields' => array(
				restapi_schema_net_tunnel_parent('gre'),
				array('name' => 'remote-addr', 'type' => 'text', 'label' => gettext('Remote address'), 'required' => true, 'mono' => true,
				    'width' => 'half', 'help' => gettext('Peer address where encapsulated GRE packets are sent.')),
			)),
			$tunnel(4),
			$tunnel(6),
			array('id' => 'options', 'title' => gettext('Options'), 'fields' => array(
				array('name' => 'link1', 'type' => 'switch', 'label' => gettext('Add static route'),
				    'text' => gettext('Add an explicit static route for the remote inner tunnel address/subnet via the local tunnel address')),
				restapi_schema_net_descr(),
			)),
		),
	);
}

function restapi_schema_network_groups() {
	return array(
		'title' => gettext('Interface group'),
		'summary' => gettext('Group {ifname} of {members}'),
		'summaryIcon' => 'object-group',
		'sections' => array(
			array('id' => 'group', 'title' => gettext('Interface group configuration'), 'fields' => array(
				array('name' => 'ifname', 'type' => 'text', 'label' => gettext('Group name'), 'required' => true, 'maxLength' => 15, 'mono' => true,
				    'width' => 'half', 'pattern' => '^[A-Za-z_]([A-Za-z0-9_]*[A-Za-z_])?$',
				    'patternMessage' => gettext("Only letters (A-Z), digits (0-9) and '_' are allowed; the name cannot start or end with a digit."),
				    'help' => gettext("Only letters (A-Z), digits (0-9) and '_' are allowed. The group name cannot start or end with a digit. " .
				    'Renaming a group also renames it in the firewall rules and port forwards that use it.'),
				    'errorMatch' => array('reserved keyword', 'only letters', 'an alias with this name')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Group description'), 'width' => 'half',
				    'help' => gettext('A description for administrative reference (not parsed).')),
				array('name' => 'members', 'type' => 'checklist', 'label' => gettext('Group members'),
				    'options' => restapi_schema_choices(restapi_schema_net_call('interfaces_group_member_list')),
				    'help' => gettext('Rules for WAN type interfaces in groups do not contain the reply-to mechanism upon which Multi-WAN typically relies.'),
				    'errorMatch' => array('invalid interface')),
			)),
		),
	);
}

function restapi_schema_network_assignments() {
	$options = array();
	if (function_exists('interfaces_assign_port_list')) {
		$portlist = interfaces_assign_port_list();
		$options = restapi_schema_choices(interfaces_assign_port_descrs($portlist));
	}
	return array(
		'title' => gettext('Interface assignment'),
		'summary' => gettext('Port {port}'),
		'summaryIcon' => 'ethernet',
		'sections' => array(
			array('id' => 'assignment', 'title' => gettext('Network port'), 'fields' => array(
				array('name' => 'port', 'type' => 'select', 'label' => gettext('Network port'), 'required' => true, 'options' => $options,
				    'help' => gettext('A new interface is named OPTn and starts disabled. Ports that are members of a LAGG are not listed.'),
				    'errorMatch' => array('cannot add port', 'is already assigned', 'cannot set port', 'was assigned to', 'vlan parent interface')),
			)),
		),
	);
}
