<?php
/*
 * firewall_rules_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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

##|+PRIV
##|*IDENT=page-firewall-rules-edit
##|*NAME=Firewall: Rules: Edit
##|*DESCR=Allow access to the 'Firewall: Rules: Edit' page.
##|*MATCH=firewall_rules_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("filter.inc");
require_once("shaper.inc");

require_once("firewall_rules.inc");

if (isset($_POST['referer'])) {
	$referer = $_POST['referer'];
} else {
	$referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/firewall_rules.php');
}

$ostypes = firewall_rule_ostypes();

$ifdisp = get_configured_interface_with_descr();

$a_filter = get_filter_rules_list();

if (isset($_REQUEST['id']) && is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

if (isset($_REQUEST['after']) && (is_numericint($_REQUEST['after']) || $_REQUEST['after'] == "-1")) {
	$after = $_REQUEST['after'];
}

if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	$id = $_REQUEST['dup'];
	$after = $_REQUEST['dup'];
}

if (isset($id) && $a_filter[$id]) {
	$pconfig = getFilterRule($id, isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup']));
} else {
	/* defaults */
	if ($_REQUEST['if']) {
		$pconfig['interface'] = $_REQUEST['if'];
	}
	$pconfig['ipprotocol'] = "inet"; // other things depend on this, set a sensible default
	$pconfig['type'] = "pass";
	$pconfig['proto'] = "tcp"; // for new blank rules, default=tcp, also ensures ports fields are visible
	$pconfig['src'] = "any";
	$pconfig['dst'] = "any";
}

/* Allow the FloatingRules to work */
$if = $pconfig['interface'];

if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	unset($id);
}

$list = firewall_rule_queue_list();
$dnqlist = firewall_rule_dnqueue_list();
$a_gatewaygroups = return_gateway_groups_array();

if ($_POST['save']) {
	$result = saveFilterRule($_POST, $id ?? null, $after ?? null, $if);
	$input_errors = $result['input_errors'];
	$pconfig = $result['pconfig'];

	if (empty($input_errors)) {
		if ($result['interface'] === 'FloatingRules') {
			header('Location: firewall_rules.php?if=FloatingRules');
		} else {
			header('Location: firewall_rules.php?if=' . htmlspecialchars($result['interface']));
		}
		exit;
	}
}

function build_flag_table() {
	global $pconfig, $tcpflags;

	$flagtable = '<table class="table table-sm table-flags" style="width: auto;">';

	$setflags = explode(",", $pconfig['tcpflags1']);
	$outofflags = explode(",", $pconfig['tcpflags2']);
	$header = "<td></td>";
	$tcpflags1 = "<td>" . gettext("set") . "</td>";
	$tcpflags2 = "<td>" . gettext("out of") . "</td>";

	foreach ($tcpflags as $tcpflag) {
		$header .= "<td><strong>" . strtoupper($tcpflag) . "</strong></td>\n";
		$tcpflags1 .= "<td> <input type='checkbox' name='tcpflags1_{$tcpflag}' value='on' ";

		if (array_search($tcpflag, $setflags) !== false) {
			$tcpflags1 .= "checked";
		}

		$tcpflags1 .= " /></td>\n";
		$tcpflags2 .= "<td> <input type='checkbox' name='tcpflags2_{$tcpflag}' value='on' ";

		if (array_search($tcpflag, $outofflags) !== false) {
			$tcpflags2 .= "checked";
		}

		$tcpflags2 .= " /></td>\n";
	}

	$flagtable .= "<tr id='tcpheader'>{$header}</tr>\n";
	$flagtable .=  "<tr id='tcpflags1'>{$tcpflags1}</tr>\n";
	$flagtable .=  "<tr id='tcpflags2'>{$tcpflags2}</tr>\n";
	$flagtable .=  "</table>";

	$flagtable .= '<input type="checkbox" name="tcpflags_any" id="tcpflags_any" value="on"';
	$flagtable .= ($pconfig['tcpflags_any'] ? 'checked':'') . '/>';
	$flagtable .= '<strong>' . gettext(" Any flags.") . '</strong>';

	return($flagtable);
}

$pgtitle = array(gettext("Firewall"), gettext("Rules"));
$pglinks = array("");

$is_floating_rule = (($if === 'FloatingRules') || isset($pconfig['floating']));

if ($is_floating_rule) {
	$pglinks[] = "firewall_rules.php?if=FloatingRules";
	$pgtitle[] = gettext('Floating');
	$pglinks[] = "firewall_rules.php?if=FloatingRules";
} elseif (!empty($if)) {
	$pglinks = array("", "firewall_rules.php?if=" . $if);
} else {
	$pglinks = array("", "firewall_rules.php");
}

$pgtitle[] = gettext("Edit");
$pglinks[] = "@self";
$shortcut_section = "firewall";

$page_filename = "firewall_rules_edit.php";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;
$section = new Form_Section('Edit Firewall Rule');

if (isset($id)) {
	$form->addGlobal(new Form_Input(
		'id',
		'ID',
		'hidden',
		$id
	));
}

if (isset($a_filter[$id])) {
	$form->addGlobal(new Form_Input(
		'tracker',
		'Tracker',
		'hidden',
		$pconfig['tracker']
	));
}

$form->addGlobal(new Form_Input(
	'after',
	'After',
	'hidden',
	$after
));

$form->addGlobal(new Form_Input(
	'ruleid',
	'Ruleid',
	'hidden',
	$pconfig['ruleid']
));

// Allow extending of the firewall edit page and include custom input validation
FreeSense_handle_custom_code("/usr/local/pkg/firewall_rules/htmlphpearly");

$values = array(
	'pass' => gettext('Pass'),
	'block' => gettext('Block'),
	'reject' => gettext('Reject'),
);

if ($is_floating_rule) {
	$values['match'] = gettext('Match');
}

$section->addInput(new Form_Select(
	'type',
	'*Action',
	$pconfig['type'],
	$values
))->setHelp('Choose what to do with packets that match the criteria specified '.
	'below.%sHint: the difference between block and reject is that with '.
	'reject, a packet (TCP RST or ICMP port unreachable for UDP) is returned '.
	'to the sender, whereas with block the packet is dropped silently. In '.
	'either case, the original packet is discarded.', '<br/>');

$section->addInput(new Form_Checkbox(
	'disabled',
	'Disabled',
	'Disable this rule',
	$pconfig['disabled']
))->setHelp('Set this option to disable this rule without removing it from the '.
	'list.');

if ($is_floating_rule) {
	$section->addInput(new Form_Checkbox(
		'quick',
		'Quick',
		'Apply the action immediately on match.',
		$pconfig['quick']
	))->setHelp('Set this option to apply this action to traffic that '.
		'matches this rule immediately.');
}

$edit_disabled = isset($pconfig['associated-rule-id']);

if ($edit_disabled) {
	$extra = '';
	foreach (get_anynat_rules_list('rdr') as $index => $nat_rule) {
		if ($nat_rule['associated-rule-id'] === $pconfig['associated-rule-id']) {
			$extra = '<br/><a href="firewall_nat_edit.php?id='. $index .'">'. gettext('View the NAT rule') .'</a>';
		}
	}

	$section->addInput(new Form_StaticText(
		'Associated filter rule',
		'<span class="help-block">' .
		'This is associated with a NAT rule.<br/>' .
		'Editing the interface, protocol, source, or destination of associated filter rules is not permitted.'.
		$extra .
		'</span>'
		));

	$form->addGlobal(new Form_Input(
		'associated-rule-id',
		null,
		'hidden',
		$pconfig['associated-rule-id']
	));

	if (!empty($pconfig['interface'])) {
		$form->addGlobal(new Form_Input(
			'interface',
			null,
			'hidden',
			$pconfig['interface']
		));
	}
}

if ($is_floating_rule) {
	$section->addInput($input = new Form_Select(
		'interface',
		'*Interface',
		$pconfig['interface'],
		array_merge(array('any' => 'Any'), filter_get_interface_list()),
		true
	))->setHelp('Choose the interface(s) for this rule.');
} else {
	$section->addInput($input = new Form_Select(
		'interface',
		'*Interface',
		$pconfig['interface'],
		filter_get_interface_list()
	))->setHelp('Choose the interface from which packets must come to match this rule.');
}

if ($is_floating_rule) {
	$section->addInput(new Form_Select(
		'direction',
		'*Direction',
		$pconfig['direction'],
		array(
			'any' => gettext('any'),
			'in' => gettext('in'),
			'out' => gettext('out'),
		)
	));

	$form->addGlobal(new Form_Input(
		'floating',
		'Floating',
		'hidden',
		'floating'
	));
}

$group = new Form_Group('*Address Family');
$group->add(new Form_Select(
	'ipprotocol',
	'*Address Family',
	$pconfig['ipprotocol'],
	array(
		'inet' => 'IPv4',
		'inet6' => 'IPv6',
		'inet46' => 'IPv4+IPv6',
	)
))->setHelp('Select the Internet Protocol version this rule applies to.');
$group->add(new Form_Checkbox(
	'nat',
	null,
	'Enable NAT',
	$pconfig['nat']
));
$section->add($group);

$section->addInput(new Form_Select(
	'proto',
	'*Protocol',
	$pconfig['proto'],
	get_ipprotocols()
))->setHelp('Choose which IP protocol this rule should match.');

$group = new Form_Group("ICMP Subtypes");
$group->add(new Form_Select(
	'icmptype',
	'ICMP subtypes',
	((isset($pconfig['icmptype']) && strlen($pconfig['icmptype']) > 0) ? explode(',', $pconfig['icmptype']) : 'any'),
	isset($icmplookup[$pconfig['ipprotocol']]) ? $icmplookup[$pconfig['ipprotocol']]['icmptypes'] : array('any' => gettext('any')),
	true
))->setHelp('%s', '<div id="icmptype_help">' . (isset($icmplookup[$pconfig['ipprotocol']]) ? $icmplookup[$pconfig['ipprotocol']]['helpmsg'] : '') . '</div>');
$group->addClass('icmptype_section');

$section->add($group);

$form->add($section);

$section = new Form_Section('Address Family Translation');
$section->addClass('nat');
$section->addInput(new Form_StaticText(
	null,
	'<span class="help-block">' .
	gettext('Matching traffic will have its address family ' .
		'translated and use the specified address as its source.') .
		'</span>'
));
$form_nat_fields = [
	[
		'Source', // group title
		'nat64', // field name prefix
		'source', // target
		'ALIASV4', // Form_IpAddress type
		array_merge(['default' => 'Automatic (default)'],
			get_specialnet('', [SPECIALNET_NETAL, SPECIALNET_IFADDR, SPECIALNET_VIPALIAS])
		), // The drop-down selection values
		32 // mask bit
	]
];
foreach ($form_nat_fields as $form_field) {
	$group = new Form_Group($form_field[0]);
	$group->addClass($form_field[1]);
	$group->add(new Form_Select(
		"{$form_field[1]}_{$form_field[2]}",
		null,
		$pconfig["{$form_field[1]}_{$form_field[2]}"] ?? 'default',
		$form_field[4]
	))->setWidth('3');
	$group->add(new Form_IpAddress(
		"{$form_field[1]}_{$form_field[2]}_value",
		null,
		$pconfig["{$form_field[1]}_{$form_field[2]}_value"],
		$form_field[3]
	))->addMask("{$form_field[1]}_{$form_field[2]}_value_subnet", ($pconfig["{$form_field[1]}_{$form_field[2]}_value_subnet"] ?? $form_field[5]), $form_field[5])->setWidth('4');
	$section->add($group);
}

$form->add($section);

// Source and destination share a lot of logic. Loop over the two
// ToDo: Unfortunately they seem to differ more than they share. This needs to be unrolled
foreach (['src' => gettext('Source'), 'dst' => gettext('Destination')] as $type => $name) {
	$section = new Form_Section($name);

	$group = new Form_Group('*' . $name);
	$group->add(new Form_Checkbox(
		$type .'not',
		$name .' not',
		'Invert match',
		$pconfig[$type.'not']
	))->setWidth(2);

	// The rule type dropdown on the GUI can be one of the special names like
	// "any" "LANnet" "LAN address"... or "Single host or alias" or "Network"
	if ($pconfig[$type.'type']) {
		// The rule type came from the $_POST array, after input errors, so keep it.
		$ruleType = $pconfig[$type.'type'];
	} elseif (get_specialnet($pconfig[$type], $filter_srcdsttype_flags)) {
		// It is one of the special names, let it through as-is.
		$ruleType = $pconfig[$type];
	} elseif ((is_ipaddrv6($pconfig[$type]) && $pconfig[$type.'mask'] == 128) ||
	    (is_ipaddrv4($pconfig[$type]) && $pconfig[$type.'mask'] == 32) ||
	    (is_alias($pconfig[$type]))) {
		// It is a single-host IP address or an alias
		$ruleType = 'single';
	} else {
		// Everything else must be a network
		$ruleType = 'network';
	}

	$ruleValues_flags = array_merge([SPECIALNET_CHECKPERM], $filter_srcdsttype_flags);
	if ($type != 'dst' && !$is_floating_rule) {
		$ruleValues_flags = array_diff($ruleValues_flags, [SPECIALNET_SELF]);
	}

	$group->add(new Form_Select(
		$type . 'type',
		$name .' Type',
		$ruleType,
		get_specialnet('', $ruleValues_flags)
	));

	$group->add(new Form_IpAddress(
		$type,
		$name .' Address',
		$pconfig[$type],
		'ALIASV4V6'
	))->addMask($type .'mask', $pconfig[$type.'mask']);

	$section->add($group);

	if ($type == 'src') {
		$section->addInput(new Form_Button(
			'btnsrctoggle',
			'',
			null,
			'fa-solid fa-cog'
		))->setAttribute('type','button')->addClass('btn-info btn-sm')->setHelp(
			'The %1$sSource Port Range%2$s for a connection is typically random '.
			'and almost never equal to the destination port. '.
			'In most cases this setting must remain at its default value, %1$sany%2$s.', '<b>', '</b>');
	}

	$portValues = ['' => gettext('(other)'), 'any' => gettext('any')];
	foreach ($wkports as $port => $portName) {
		$portValues[$port] = $portName.' ('. $port .')';
	}

	$group = new Form_Group($type == 'src' ? gettext('Source Port Range') : gettext('Destination Port Range'));

	$group->addClass($type . 'portrange');

	$group->add(new Form_Select(
		$type .'beginport',
		$name .' port begin',
		$pconfig[$type .'beginport'],
		$portValues
	))->setHelp('From');

	$group->add(new Form_Input(
		$type .'beginport_cust',
		null,//$name .' port begin custom',
		'text',
		(isset($portValues[ $pconfig[$type .'beginport'] ]) ? null : $pconfig[$type .'beginport'])
	))->setHelp('Custom');

	$group->add(new Form_Select(
		$type .'endport',
		$name .' port end',
		$pconfig[$type .'endport'],
		$portValues
	))->setHelp('To');

	$group->add(new Form_Input(
		$type .'endport_cust',
		null,//$name .' port end custom',
		'text',
		(isset($portValues[ $pconfig[$type .'endport'] ]) ? null : $pconfig[$type .'endport'])
	))->setHelp('Custom');

	$group->setHelp('Specify the %s port or port range for this rule. The "To" field may be left empty if only filtering a single port.', strtolower($name));

	$group->addClass(($type == 'src') ? 'srcprtr':'dstprtr');
	$section->add($group);

	$form->add($section);
}

$section = new Form_Section('Extra Options');
$section->addInput(new Form_Checkbox(
	'log',
	'Log',
	'Log packets that are handled by this rule',
	$pconfig['log']
))->setHelp('Hint: the firewall has limited local log space. Don\'t turn on logging '.
	'for everything. If doing a lot of logging, consider using a remote '.
	'syslog server (see the %1$sStatus: System Logs: Settings%2$s page).', '<a href="status_logs_settings.php">', '</a>');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference. ' .
	'A maximum of %s characters will be used in the ruleset label and displayed in the firewall log.',
	user_rule_descr_maxlen());

$btnadv = new Form_Button(
	'btnadvopts',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-cog'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	'Advanced Options',
	$btnadv
));

$form->add($section);

$section = new Form_Section('Advanced Options');
$section->addClass('advanced-options');

$section->addInput(new Form_Select(
	'os',
	'Source OS',
	(empty($pconfig['os']) ? '':$pconfig['os']),
	['' => gettext('Any')] + array_combine($ostypes, $ostypes)
))->setHelp('Note: this only works for TCP rules. General OS choice matches all subtypes.');

$section->addInput(new Form_Select(
	'dscp',
	'Diffserv Code Point',
	$pconfig['dscp'],
	["" => ''] + array_combine($firewall_rules_dscp_types, $firewall_rules_dscp_types)
));

$section->addInput(new Form_Checkbox(
	'allowopts',
	'Allow IP options',
	'Allow packets with IP options to pass. Otherwise they are blocked by '.
	'default. This is usually only seen with multicast traffic.',
	$pconfig['allowopts']
));

$section->addInput(new Form_Checkbox(
	'disablereplyto',
	'Disable reply-to',
	'Disable auto generated reply-to for this rule.',
	$pconfig['disablereplyto']
));

$section->addInput(new Form_Input(
	'tag',
	'Tag',
	'text',
	$pconfig['tag']
))->setHelp('A packet matching this rule can be marked and this mark used to match '.
	'on other NAT/filter rules. It is called %1$sPolicy filtering%2$s.', '<b>', '</b>');

$group = new Form_Group('Tagged');

$group->add(new Form_Checkbox(
	'nottagged',
	'nottagged',
	'Invert',
	$pconfig['nottagged']
))->setWidth(1);

$group->add(new Form_Input(
'tagged',
'Tagged',
'text',
$pconfig['tagged']
))->setWidth(4);

$group->setHelp('Match a mark placed on a packet by a different rule with the Tag option. Check Invert to match packets which do not contain this tag.');

$section->add($group);

$section->addInput(new Form_Input(
	'max',
	'Max. states',
	'number',
	$pconfig['max']
))->setHelp('Maximum state entries this rule can create.');

$section->addInput(new Form_Input(
	'max-src-nodes',
	'Max. src nodes',
	'number',
	$pconfig['max-src-nodes']
))->setHelp('Maximum number of unique source hosts.');

$section->addInput(new Form_Input(
	'max-src-conn',
	'Max. connections',
	'number',
	$pconfig['max-src-conn']
))->setHelp('Maximum number of established connections per host (TCP only).');

$section->addInput(new Form_Input(
	'max-src-states',
	'Max. src. states',
	'number',
	$pconfig['max-src-states']
))->setHelp('Maximum state entries per host.');

$section->addInput(new Form_Input(
	'max-src-conn-rate',
	'Max. src. conn. Rate',
	'number',
	$pconfig['max-src-conn-rate']
))->setHelp('Maximum new connections per host (TCP only).');

$section->addInput(new Form_Input(
	'max-src-conn-rates',
	'Max. src. conn. Rates',
	'number',
	$pconfig['max-src-conn-rates'],
	['min' => 1, 'max' => 255]
))->setHelp('/ per how many second(s) (TCP only)');

$section->addInput(new Form_Input(
	'statetimeout',
	'State timeout',
	'number',
	$pconfig['statetimeout'],
	['min' => 1]
))->setHelp('State Timeout in seconds');

$section->addInput(new Form_StaticText(
	'TCP Flags',
	build_flag_table()
))->setHelp('Use this to choose TCP flags that must be set or cleared for this rule to match.');

$section->addInput(new Form_Checkbox(
	'nopfsync',
	'No pfSync',
	'Prevent states created by this rule to be sync\'ed over pfsync.',
	$pconfig['nopfsync']
));

$section->addInput(new Form_Select(
	'statepolicy',
	'State Policy',
	(isset($pconfig['statepolicy'])) ? $pconfig['statepolicy'] : "",
	$statepolicy_values
))->setHelp('Optionally overrides the default state policy behavior to force a specific policy ' .
		'for connections matching this rule. Only effective when rules keep state.%1$s' .
		'The global default policy option is located at System > Advanced, Firewall &amp; NAT tab.',
		'<br />');

$section->addInput(new Form_Select(
	'statetype',
	'State type',
	(isset($pconfig['statetype'])) ? $pconfig['statetype'] : "keep state",
	$statetype_values
))->setHelp('Select which type of state tracking mechanism to use.  If in doubt, use keep state.%1$s',
			'<br /><span></span>');

$section->addInput(new Form_Checkbox(
	'nosync',
	'No XMLRPC Sync',
	'Prevent the rule on Master from automatically syncing to other CARP members',
	$pconfig['nosync']
))->setHelp('This does NOT prevent the rule from being overwritten on Slave.');

$section->addInput(new Form_Select(
	'vlanprio',
	'VLAN Prio',
	$pconfig['vlanprio'],
	$vlanprio
))->setHelp('Choose 802.1p priority to match on.');

$section->addInput(new Form_Select(
	'vlanprioset',
	'VLAN Prio Set',
	$pconfig['vlanprioset'],
	$vlanprio
))->setHelp('Choose 802.1p priority to apply.');

$schedules = array();
foreach (config_get_path('schedules/schedule', []) as $schedule) {
	if ($schedule['name'] != "") {
		$schedules[] = $schedule['name'];
	}
}

$section->addInput(new Form_Select(
	'sched',
	'Schedule',
	$pconfig['sched'],
	['' => gettext('none')] + array_combine($schedules, $schedules)
))->setHelp('Leave as \'none\' to leave the rule enabled all the time.');

// Build the gateway lists in JSON so the selector can be populated in JS
$gwjson = '[{"name":"", "gateway":"Default", "family":"inet46"}';

foreach (get_gateways() as $gwname => $gw) {
	$gwjson = $gwjson . "," .'{"name":' . json_encode($gwname) . ', "gateway":' .
	json_encode($gw['name'] . (empty($gw['gateway'])? '' : ' - '. $gw['gateway']) . (empty($gw['descr'])? '' : ' - '. $gw['descr'])) . ',"family":' .
	json_encode($gw['ipprotocol']) . '}';
}

foreach ((array)$a_gatewaygroups as $gwg_name => $gwg_data) {
	$gwjson = $gwjson . "," .'{"name":' . json_encode($gwg_name) . ', "gateway":' .
	json_encode($gwg_data['name'] . $gwg_name . (empty($gwg_data['descr'])? '' : ' - '. $gwg_data['descr'])) . ',"family":' . json_encode($gwg_data['ipprotocol']) . '}';
	$firstgw = false;
}

$gwjson .= ']';
$gwselected = $pconfig['gateway'];

// print($gwjson);

// Gateway selector is populated by JavaScript updateGWselect() function
$section->addInput(new Form_Select(
	'gateway',
	'Gateway',
	'',
	[]
))->setHelp('Leave as \'default\' to use the system routing table. Or choose a '.
	'gateway to utilize policy based routing. %sGateway selection is not valid for "IPV4+IPV6" address family.', '<br />');

$group = new Form_Group('In / Out pipe');

$group->add(new Form_Select(
	'dnpipe',
	'DNpipe',
	(isset($pconfig['dnpipe'])) ? $pconfig['dnpipe']:"",
	array('' => gettext('none')) + array_combine(array_keys($dnqlist), array_keys($dnqlist))
));

$group->add(new Form_Select(
	'pdnpipe',
	'PDNpipe',
	(isset($pconfig['pdnpipe'])) ? $pconfig['pdnpipe']:"",
	array('' => gettext('none')) + array_combine(array_keys($dnqlist), array_keys($dnqlist))
));

$section->add($group)->setHelp('Choose the Out queue/Virtual interface only if '.
	'In is also selected. The Out selection is applied to traffic leaving '.
	'the interface where the rule is created, the In selection is applied to traffic coming '.
	'into the chosen interface.%1$sIf creating a floating rule, if the '.
	'direction is In then the same rules apply, if the direction is Out the '.
	'selections are reversed, Out is for incoming and In is for outgoing.',
	'<br />'
);

$group = new Form_Group('Ackqueue / Queue');

$group->add(new Form_Select(
	'ackqueue',
	'Ackqueue',
	$pconfig['ackqueue'],
	$list
));

$group->add(new Form_Select(
	'defaultqueue',
	'Default Queue',
	$pconfig['defaultqueue'],
	$list
));

$section->add($group)->setHelp('Choose the Acknowledge Queue only if there is a '.
	'selected Queue.'
);

$form->add($section);

if (is_numericint($id)) {
	gen_created_updated_fields($form, $a_filter[$id]['created'], $a_filter[$id]['updated'], $a_filter[$id]['tracker']);
}

echo $form;
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	var portsenabled = 1;
	var editenabled = 1;
	var srcportsvisible = false;

	var allowopts_state_without_igmp = false;
	if ($('#proto option:selected').val() != "igmp") {
		var allowopts_state_without_igmp = $('#allowopts').prop('checked');
	}

	// Show advanced additional opts options ======================================================
	var showadvopts = false;

	// Remove focus on page load
	document.activeElement.blur()

	function show_advopts(ispageload) {
		var text;
		if ($('#allowopts').prop('checked')) {
			// Always show advanced options when Allow IP options is checked.
			showadvopts = true;
		} else if (ispageload) {
			// On page load decide the initial state based on the data.
			showadvopts = <?php if (is_aoadv_used($pconfig)) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvopts = !showadvopts;
		}

		hideClass('advanced-options', !showadvopts);
		if ($('#tcpflags_any').prop('checked')) {
			$('.table-flags').addClass('hidden');
		}

		if (showadvopts) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvopts').children();
		$('#btnadvopts').text(text).prepend(children);
	}

	$('#btnadvopts').click(function(event) {
		show_advopts();
	});

	function ext_change() {

		if (($('#srcbeginport').find(":selected").index() == 0) && portsenabled && editenabled) {
			disableInput('srcbeginport_cust', false);
		} else {
			if (editenabled) {
				$('#srcbeginport_cust').val("");
			}

			disableInput('srcbeginport_cust', true);
		}

		if (($('#srcendport').find(":selected").index() == 0) && portsenabled && editenabled) {
			disableInput('srcendport_cust', false);
		} else {
			if (editenabled) {
				$('#srcendport_cust').val("");
			}

			disableInput('srcendport_cust', true);
		}

		if (($('#dstbeginport').find(":selected").index() == 0) && portsenabled && editenabled) {
			disableInput('dstbeginport_cust', false);
		} else {
			if (editenabled) {
				$('#dstbeginport_cust').val("");
			}

			disableInput('dstbeginport_cust', true);
		}

		if (($('#dstendport').find(":selected").index() == 0) && portsenabled && editenabled) {
			disableInput('dstendport_cust', false);
		} else {
			if (editenabled) {
				$('#dstendport_cust').val("");
			}

			disableInput('dstendport_cust', true);
		}

		if (!portsenabled) {
			disableInput('srcbeginport', true);
			disableInput('srcendport', true);
			disableInput('dstbeginport', true);
			disableInput('dstendport', true);
		} else {
			if (editenabled) {
				disableInput('srcbeginport', false);
				disableInput('srcendport', false);
				disableInput('dstbeginport', false);
				disableInput('dstendport', false);
			}
		}
	}

	function show_source_port_range() {
		hideClass('srcprtr', !srcportsvisible);

		if (srcportsvisible) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnsrctoggle').children();
		$('#btnsrctoggle').text(text).prepend(children);
	}

	function typesel_change() {
		src_typesel_change();
		dst_typesel_change();
	}

	function src_typesel_change() {
		if (editenabled) {
			switch ($('#srctype').find(":selected").index()) {
				case 1: // single
					disableInput('src', false);
					$('#srcmask').val("");
					disableInput('srcmask', true);
					break;
				case 2: // network
					disableInput('src', false);
					disableInput('srcmask', false);
					break;
				default:
					$('#src').val("");
					disableInput('src', true);
					$('#srcmask').val("");
					disableInput('srcmask', true);
					break;
			}
		}
	}

	function dst_typesel_change() {
		if (editenabled) {
			switch ($('#dsttype').find(":selected").index()) {
				case 1: // single
					disableInput('dst', false);
					$('#dstmask').val("");
					disableInput('dstmask', true);
					break;
				case 2: // network
					disableInput('dst', false);
					disableInput('dstmask', false);
					break;
				default:
					$('#dst').val("");
					disableInput('dst', true);
					$('#dstmask').val("");
					disableInput('dstmask', true);
					break;
			}
		}
	}

	// Populate the "gateway" selector from a JSON array composed in the PHP
	function updateGWselect() {
		var selected = "<?=$gwselected?>";
		var protocol = $('#ipprotocol').val();
		var json = JSON.parse(<?=json_encode($gwjson)?>);

		// Remove all of the existing optns
		$('#gateway').find('option').remove();

		// Add new ones as appropriate for the address family
		json.forEach(function(gwobj) {
			if ((protocol != "inet46") &&
			    ((!$('#nat').prop('checked') && (gwobj.family == protocol) || (gwobj.family == "inet46")) ||
			    ($('#nat').prop('checked') && (protocol == "inet6") && (gwobj.family == "inet")))) {
				$('#gateway').append($('<option>', {
				    text: gwobj.gateway,
				    value: gwobj.name
				}));
			}
		});

		// Add "selected" attribute as needed
		$('#gateway').val(selected);

		// Gateway selection is not permitted for "IPV4+IPV6"
		$('#gateway').prop("disabled", protocol == "inet46");

	}

	function proto_change() {
		portsenabled = (jQuery.inArray($('#proto :selected').val(), Object.keys(<?=json_encode(get_ipprotocols('portsonly'))?>)) != -1) ? true : false;
		hideClass('tcpflags', !portsenabled);

		// Disable OS if the proto is not TCP.
		disableInput('os', ($('#proto :selected').val() != 'tcp'));

		// Hide ICMP types if not icmp rule
		hideClass('icmptype_section', $('#proto').val() != 'icmp');
		// Update ICMP help msg to match current IP protocol
		$('#icmptype_help').html(icmphelp[$('#ipprotocol').val()]);
		// Update ICMP types available for current IP protocol, copying over any still-valid selections
		var listid = "#icmptype\\[\\]"; // for ease of use
		var current_sel = ($(listid).val() || ['any']); // Ensures we get correct array when none selected
		var new_options = icmptypes[$('#ipprotocol').val()];
		var new_html = '';
		//remove and re-create the select element (otherwise the options can disappear in Safari)
		$(listid).remove();
		var select = $("<select></select>").attr("id", "icmptype[]").attr("name", "icmptype[]").addClass("form-control").attr("multiple", "multiple");
		$('div.icmptype_section > div.col-sm-10').prepend(select);

		for (var key in new_options) {
			new_html += '<option value="' + key + (jQuery.inArray(key, current_sel) != -1 ? '" selected="selected">' : '">') + new_options[key] + '</option>\n';
		}

		$(listid).html(new_html);

		ext_change();

		if (portsenabled) {
			hideClass('dstprtr', false);
			hideInput('btnsrctoggle', false);
			if ((($('#srcbeginport').val() == "any") || ($('#srcbeginport').val() == "")) &&
			    (($('#srcendport').val() == "any") || ($('#srcendport').val() == ""))) {
				srcportsvisible = false;
			} else {
				srcportsvisible = true;
			}
		} else {
			hideClass('dstprtr', true);
			hideInput('btnsrctoggle', true);
			srcportsvisible = false;
		}

		show_source_port_range();

		updateGWselect();
	}

	function icmptype_change() {
		var listid = "#icmptype\\[\\]"; // for ease of use
		var current_sel = ($(listid).val() || ['any']); // Ensures we get correct array when none selected
		if (jQuery.inArray('any', current_sel) != -1) {
			// "any" negates all selections
			$(listid).find('option').not('[value="any"]').removeAttr('selected');
		}
		if ($(listid + ' option:selected').length == 0) {
			// no selection = select "any"
			$(listid + ' option[value="any"]').prop('selected', true);
		}
	}

	function src_rep_change() {
		$('#srcendport').prop("selectedIndex", $('#srcbeginport').find(":selected").index());
	}

	function dst_rep_change() {
		$('#dstendport').prop("selectedIndex", $('#dstbeginport').find(":selected").index());
	}

	// On initial page load

<?php
	// Generate icmptype data used in form JS
	$out1 = "var icmptypes = [];\n";
	$out2 = "var icmphelp = [];\n";
	foreach ($icmplookup as $k => $v) {
		$a = array();
		foreach ($v['icmptypes'] as $icmp_k => $icmp_v) {
			$a[] = sprintf("'%s':'%s'", $icmp_k, $icmp_v);
		}
		$out1 .= "icmptypes['{$k}'] = {\n\t" . implode(",\n\t", $a) . "\n};\n";
		$out2 .= "icmphelp['{$k}'] = '" . str_replace("'", '&apos;', $v['helpmsg']) . "';\n";
	}
	echo $out1;
	echo $out2;
?>

	proto_change();

	ext_change();

	typesel_change();

	show_advopts(true);
	hideClass('srcportrange', true);

	<?php if ((!empty($pconfig['srcbeginport']) && $pconfig['srcbeginport'] != "any") || (!empty($pconfig['srcendport']) && $pconfig['srcendport'] != "any")): ?>
		srcportsvisible = true;
		show_source_port_range();
	<?php endif; ?>

	// on click . .
	$('#srcbeginport').on('change', function() {
		src_rep_change();
		ext_change();
	});

	$('#btnsrctoggle').click(function() {
		srcportsvisible = !srcportsvisible;
		show_source_port_range();
	});

	$('#srcendport').on('change', function() {
		ext_change();
	});

	$('#save').on('click', function() {
		disableInput('save');
	});

	$('#dstbeginport').on('change', function() {
		dst_rep_change();
		ext_change();
	});

	$('#dstendport').on('change', function() {
		ext_change();
	});

	$('#srctype').on('change', function() {
		src_typesel_change();
	});

	$('#dsttype').on('change', function() {
		dst_typesel_change();
	});

	$('#ipprotocol').on('change', function() {
		proto_change();
		nat_change('address_family');
	});

	$('#proto').on('change', function() {
		proto_change();
		if ($('#proto option:selected').val() == "igmp") {
			$('#allowopts').prop('checked', true);
		} else {
			$('#allowopts').prop('checked', allowopts_state_without_igmp);
		}
		show_advopts(true);
	});

	$('#allowopts').on('change', function() {
		if ($('#proto option:selected').val() != "igmp") {
			allowopts_state_without_igmp = $('#allowopts').prop('checked');
		}
	});

	$('#icmptype\\[\\]').on('change', function() {
		icmptype_change();
	});

	$('#tcpflags_any').click(function () {
		if (this.checked) {
			$('.table-flags').addClass('hidden');
		} else {
			$('.table-flags').removeClass('hidden');
		}
	});

	// Change help text based on the selector value
	function setOptText(target, val) {
		var dispstr = '<span class="text-success">';

		if (val == 'keep state') {
			dispstr += 'Keep: works with all IP protocols';
		} else if (val == 'sloppy state') {
			dispstr += 'Sloppy: works with all IP protocols';
		} else if (val == 'synproxy state') {
			dispstr += 'Synproxy: proxies incoming TCP connections to help protect servers from spoofed TCP SYN floods, at the cost of performance (no SACK or window scaling)';
		} else if (val == 'none') {
			dispstr += 'None: Do not use state mechanisms to keep track';
		}

		dispstr += '</span>';
		setHelpText(target, dispstr);
	}

	// When editing "associated" rules, everything except the enable, action, address family and description
	// fields are disabled
	function disable_most(disable) {
		var elementsToDisable = [
			'interface', 'proto', 'icmptype\\[\\]', 'srcnot', 'srctype', 'src', 'srcmask', 'srcbeginport', 'srcbeginport_cust', 'srcendport',
			'srcendport_cust', 'dstnot', 'dsttype', 'dst', 'dstmask', 'dstbeginport', 'dstbeginport_cust', 'dstendport', 'dstendport_cust'];

		for (var idx=0, len = elementsToDisable.length; idx<len; idx++) {
			disableInput(elementsToDisable[idx], disable);
		}
	}

	function nat_change(action) {
		can_override_nat64prefix = <?=(config_path_enabled('system', 'allow_nat64_prefix_override') ? "true" : "false")?>;
		updateGWselect();
		if ((action == 'nat_toggle') && (($('#ipprotocol option:selected').val() != "inet6") || !$('#nat').prop('checked'))) {
			disableInput('dstnot', false);
			disableInput('dsttype', false);
			disableInput('dst', false);
			disableInput('dstmask', false);
		}
		if ($('#ipprotocol option:selected').val() == "inet6") {
			if (action == 'address_family') {
				$('#nat').parent()[0].childNodes[1].nodeValue = 'Enable NAT64';
			}

			hideInput('nat', false);
			if (!$('#nat').prop('checked')) {
				hideClass('nat', true);
				return;
			}
			hideClass('nat64', false);
			hideInput('nat64_source_value', ($('#nat64_source option:selected').val() != "network"));

			if (action == 'nat_toggle') {
				$('#dstnot').prop('checked', false);
				$('#dsttype').val('network');
				$('#dsttype').change();
				if (!can_override_nat64prefix || !$('#dst').val()) {
					$('#dst').val("<?=NAT64_WELLKNOWN_PREFIX?>");
					$('#dst').change();
					$('#dstmask').val('96');
				}
			}
			disableInput('dstnot', true);
			if (!can_override_nat64prefix) {
				disableInput('dsttype', true);
				disableInput('dst', true);
				disableInput('dstmask', true);
			}
		} else {
			$('#nat').prop('checked', false);
			hideInput('nat', true);
			hideClass('nat', true);
			return;
		}

		hideClass('nat', false);
	}
	$('#nat').on('change', function() {
		nat_change('nat_toggle');
	});
	$('#nat64_source').on('change', function() {
		nat_change();
	});

	// ---------- Click checkbox handlers ---------------------------------------------------------

	$('#statetype').on('change', function() {
		setOptText('statetype', this.value);
	});

	// ---------- On initial page load ------------------------------------------------------------

	nat_change('address_family');

	setOptText('statetype', $('#statetype').val())
<?php if ($edit_disabled) {
?>
	disable_most(true);
<?php
}
?>

	// ---------- Autocomplete --------------------------------------------------------------------

	var addressarray = <?= json_encode(get_alias_list('host,network,url,urltable')) ?>;
	var customarray = <?= json_encode(get_alias_list('port,url_ports,urltable_ports')) ?>;

	$('#nat64_source_value, #src, #dst').autocomplete({
		source: addressarray
	});

	$('#dstbeginport_cust, #dstendport_cust, #srcbeginport_cust, #srcendport_cust').autocomplete({
		source: customarray
	});
});
//]]>
</script>

<?php
include("foot.inc");
