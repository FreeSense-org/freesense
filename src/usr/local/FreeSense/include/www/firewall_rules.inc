<?php
/*
 * firewall_rules.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
 * Firewall filter rule logic shared by firewall_rules.php,
 * firewall_rules_edit.php and the REST API: reading a rule into form
 * fields, validating and saving one, and delete/toggle/reorder/apply.
 * Moved out of the pages unchanged apart from taking the form fields and
 * ids as parameters instead of reading $_POST and page variables.
 */

require_once("config.gui.inc");
require_once("ipsec.inc");
require_once("filter.inc");
require_once("shaper.inc");

/* Lookup tables used by the edit form and its validation. Declared global
 * so they exist even when this file is included from inside a function. */
global $filter_srcdsttype_flags, $filter_srcdsttype_flags_validation, $icmptypes,
    $icmptypes4, $icmptypes6, $icmptypes46, $icmplookup, $statepolicy_values,
    $statetype_values, $vlanprio, $firewall_rules_dscp_types;

$filter_srcdsttype_flags = [
	SPECIALNET_ANY, SPECIALNET_COMPAT_ADDRAL, SPECIALNET_NET, SPECIALNET_SELF,
	SPECIALNET_CLIENTS, SPECIALNET_IFADDR, SPECIALNET_IFNET, SPECIALNET_GROUP
];
$filter_srcdsttype_flags_validation = array_diff($filter_srcdsttype_flags, [SPECIALNET_COMPAT_ADDRAL, SPECIALNET_NET]);

/* build icmptypes valid for IPv4, IPv6 and IPv<any> */
$icmptypes4 = array('any' => gettext('any'));
$icmptypes6 = array('any' => gettext('any'));
$icmptypes46 = array('any' => gettext('any'));

// ICMP descriptions may be translated, so require escaping to handle single quotes (in particular)
foreach ($icmptypes as $k => $v) {
	$description = addslashes($v['descrip']);

	if ($v['valid4']) {
		$icmptypes4[$k] = $description;
		if ($v['valid6']) {
			$icmptypes6[$k] = $description;
			$icmptypes46[$k] = $description;
		}
	} else {
		$icmptypes6[$k] = $description;
	}
}

$icmplookup = array(
	'inet' => array('name' => 'IPv4', 'icmptypes' => $icmptypes4, 'helpmsg' => gettext('For ICMP rules on IPv4, one or more of these ICMP subtypes may be specified.')),
	'inet6' => array('name' => 'IPv6', 'icmptypes' => $icmptypes6, 'helpmsg' => gettext('For ICMP rules on IPv6, one or more of these ICMP subtypes may be specified.')),
	'inet46' => array('name' => 'IPv4+6', 'icmptypes' => $icmptypes46, 'helpmsg' => sprintf(gettext('For ICMP rules on IPv4+IPv6, one or more of these ICMP subtypes may be specified. (Other ICMP subtypes are only valid under IPv4 %1$sor%2$s IPv6, not both)'), '<i>', '</i>'))
);

$statepolicy_values = [
	''  => gettext('Use global default'),
	'if-bound' => gettext('Interface Bound States'),
	'floating' => gettext('Floating States'),
];

$statetype_values = array(
	'keep state' => gettext('Keep'),
	'sloppy state' => gettext('Sloppy'),
	'synproxy state' => gettext('Synproxy'),
	'none' => gettext('None'),
);

$vlanprio = array(
	"" => "none",
	"bk" => "Background (BK, 0)",
	"be" => "Best Effort (BE, 1)",
	"ee" => "Excellent Effort (EE, 2)",
	"ca" => "Critical Applications (CA, 3)",
	"vi" => "Video (VI, 4)",
	"vo" => "Voice (VO, 5)",
	"ic" => "Internetwork Control (IC, 6)",
	"nc" => "Network Control (NC, 7)");

$firewall_rules_dscp_types = array(
	"af11",
	"af12",
	"af13",
	"af21",
	"af22",
	"af23",
	"af31",
	"af32",
	"af33",
	"af41",
	"af42",
	"af43",
	"VA",
	"EF",
	"cs1",
	"cs2",
	"cs3",
	"cs4",
	"cs5",
	"cs6",
	"cs7",
	"0x01",
	"0x02",
	"0x04");

function is_posnumericint($arg) {
	// Integer > 0? (Note that to be safe we do not allow any leading zero - "01", "007")
	return (is_numericint($arg) && $arg[0] != '0');
}

function is_aoadv_used($rule_config) {
	// Note that the user could set "tag" or "tagged" to the string "0", which is valid but empty().
	// And if the user enters "0" in other fields, we want to present an error message, and keep the Advanced Options section open.
	if (($rule_config['os'] != "") ||
	    ($rule_config['dscp'] != "") ||
	    (isset($rule_config['allowopts'])) ||
	    (isset($rule_config['disablereplyto'])) ||
	    ($rule_config['tag'] != "") ||
	    ($rule_config['tagged'] != "") ||
	    (isset($rule_config['nottagged'])) ||
	    ($rule_config['max'] != "") ||
	    ($rule_config['max-src-nodes'] != "") ||
	    ($rule_config['max-src-conn'] != "") ||
	    ($rule_config['max-src-states'] != "") ||
	    ($rule_config['max-src-conn-rate'] != "") ||
	    ($rule_config['max-src-conn-rates'] != "") ||
	    ($rule_config['statetimeout'] != "") ||
	    ($rule_config['tcpflags1'] != "") ||
	    ($rule_config['tcpflags2'] != "") ||
	    ($rule_config['tcpflags_any']) ||
	    ($rule_config['nopfsync']) ||
	    ($rule_config['statepolicy'] != "") ||
	    (($rule_config['statetype'] != "") && ($rule_config['statetype'] != "keep state")) ||
	    ($rule_config['nosync']) ||
	    ($rule_config['vlanprio'] != "") ||
	    ($rule_config['vlanprioset'] != "") ||
	    ($rule_config['sched'] != "") ||
	    ($rule_config['gateway'] != "") ||
	    ($rule_config['dnpipe'] != "") ||
	    ($rule_config['pdnpipe'] != "") ||
	    ($rule_config['ackqueue'] != "") ||
	    ($rule_config['defaultqueue'] != "")) {
		return true;
	}

	return false;
}

/**
 * Parse a firwall rule's NAT form data into the respective configuration.
 *
 * Validates values. Supports PLAT/NAT64 with a source. Does not support
 * CLAT/NAT46 nor specifying the destination since it's currently untested.
 *
 * @param array $post The form submission request.
 * @return array [<error>, [<config values>]]
 */
function parse_firewall_rule_nat_from_post(array $post): array {
	// Validate general rule requirements
	if (!$post['nat']) {
		return [null, null];
	}

	if (!isset($post['ipprotocol'])) {
		return [gettext('NAT rules must specify an address family')];
	}
	$nat_type = match ($post['ipprotocol']) {
		'inet6' => '64',
		default => null
	};
	if (!isset($nat_type)) {
		return [gettext('The specified address family is not supported with NAT')];
	}

	if ($post['type'] != 'pass') {
		return [gettext('NAT may only be set on "pass" rules')];
	}

	$multiple_interfaces = false;
	if (isset($post['floating'])) {
		if (!isset($post['direction']) || ($post['direction'] != 'in')) {
			return [gettext('NAT may only be set on inbound rules')];
		}
		if (empty($post['interface']) || (count($post['interface']) > 1) || in_array('any', $post['interface'])) {
			$multiple_interfaces = true;
		}
	}

	$config_values = [
		'nat' => [
			'enable' => true
		]
	];

	// Validate NAT target
	foreach (['source'] as $target) {
		if (!isset($post["nat{$nat_type}_{$target}"])) {
			return [gettext("The NAT {$target} must be selected")];
		}

		$address = [
			null, // IP address
			null, // mask bit
		];
		$validated = false;

		// Validate the drop-down selection and respective input
		if (empty($post["nat{$nat_type}_{$target}"]) || ($post["nat{$nat_type}_{$target}"] == 'default')) {
			// The target should be determined automatically during filter reloads
			if ($multiple_interfaces) {
				return [gettext(
					"The NAT {$target} can be automatically determined only " .
					"when a single interface (that is not \"Any\") is selected."
				)];
			}
			$address[0] = '';
			$validated = true;
		} elseif ($post["nat{$nat_type}_{$target}"] == 'network') {
			if (empty($post["nat{$nat_type}_{$target}_value"])) {
				return [gettext("A valid NAT {$target} must be specified")];
			}
			if (is_alias($post["nat{$nat_type}_{$target}_value"])) {
				// Validate aliases
				$address[0] = $post["nat{$nat_type}_{$target}_value"];
				$validated = true;
			} else {
				// Extract the address input
				$address[0] = $post["nat{$nat_type}_{$target}_value"];
				$address[1] = $post["nat{$nat_type}_{$target}_value_subnet"];
			}
		} elseif (get_specialnet($post["nat{$nat_type}_{$target}"], [SPECIALNET_VIPALIAS])) {
			// Extract the VIP address from the selection
			if (str_contains($post["nat{$nat_type}_{$target}"], '/')) {
				$address = explode('/', $post["nat{$nat_type}_{$target}"], 2);
				$validated = true;
			} else {
				$address[0] = $post["nat{$nat_type}_{$target}"];
			}
		} elseif (get_specialnet($post["nat{$nat_type}_{$target}"], [SPECIALNET_IFADDR])) {
			// Interface address selected, e.g. wanip
			$address[0] = $post["nat{$nat_type}_{$target}"];
			$validated = true;
		} else {
			// Unknown selection
			return [gettext("The specified NAT {$target} type is invalid")];
		}

		// Validate VIPs and IP address input
		if (!$validated) {
			if (($nat_type == '64') && !is_ipaddrv4($address[0])) {
				return [gettext("The specified NAT {$target} is invalid")];
			}
			if (isset($address[1])) {
				$filter_validate_int_options = [
					'options' => [
						'min_range' => 1,
						'max_range' => 32
					]
				];
				if (empty(filter_var($address[1], FILTER_VALIDATE_INT, $filter_validate_int_options))) {
					return [gettext("The specified NAT {$target} mask bit is invalid")];
				}
			}
			$validated = true;
		}

		if ($validated) {
			$config_values['nat'][$target] = $address[0] . (isset($address[1]) ? "/{$address[1]}" : '');
			continue;
		}

		return [gettext("Could not validate NAT {$target}")];
	}

	return [null, $config_values];
}

/**
 * Parse the firewall rule's NAT configuration into form fields.
 * 
 * Does not validate values.
 * @param array $rule The rule configuration.
 * @return array [<error>, [<form values>]]
 */
function parse_firewall_rule_nat_from_config(array $rule): array {
	$nat_type = match ($rule['ipprotocol']) {
		'inet6' => '64',
		default => null
	};
	if (!isset($nat_type)) {
		return [gettext('Unknown NAT configuration')];
	}

	$specialnet_flags = [SPECIALNET_NETAL, SPECIALNET_IFADDR, SPECIALNET_VIPALIAS];
	$form_values = [];

	// Check if NAT is enabled
	if (array_path_enabled($rule, 'nat')) {
		$form_values['nat'] = true;
	}

	// Get the NAT values
	foreach (['source'] as $target) {
		$config_value = array_get_path($rule, "nat/{$target}");
		if (!isset($config_value)) {
			continue;
		}

		if (get_specialnet($config_value, $specialnet_flags)) {
			// The value is a VIP or interface selection
			$form_values["nat{$nat_type}_{$target}"] = $config_value;
		} else {
			// The value is an alias or IP address
			$address = explode("/", $config_value, 2);
			$form_values["nat{$nat_type}_{$target}"] = 'network';
			$form_values["nat{$nat_type}_{$target}_value"] = $address[0];
			if (isset($address[1])) {
				$form_values["nat{$nat_type}_{$target}_value_subnet"] = $address[1];
			}
		}
	}

	return [
		null,
		$form_values
	];
}

/*
 * The edit form's fields for filter rule $id (an index into
 * get_filter_rules_list()), as firewall_rules_edit.php shows them.
 * $dup leaves out the associated NAT rule id, as for a copy.
 * Returns null when there is no such rule.
 */
function getFilterRule($id, $dup = false) {
	$a_filter = get_filter_rules_list();
	if (!isset($id) || !$a_filter[$id]) {
		return null;
	}
	$pconfig = array();
	$pconfig['interface'] = $a_filter[$id]['interface'];

	if (isset($a_filter[$id]['id'])) {
		$pconfig['ruleid'] = $a_filter[$id]['id'];
	}

	if (isset($a_filter[$id]['created']) && is_array($a_filter[$id]['created'])) {
		$pconfig['created'] = $a_filter[$id]['created'];
	}

	if (isset($a_filter[$id]['updated']) && is_array($a_filter[$id]['updated'])) {
		$pconfig['updated'] = $a_filter[$id]['updated'];
	}

	if (!isset($a_filter[$id]['type'])) {
		$pconfig['type'] = "pass";
	} else {
		$pconfig['type'] = $a_filter[$id]['type'];
	}

	if (isset($a_filter[$id]['floating'])) {
		$pconfig['floating'] = $a_filter[$id]['floating'];
		if (isset($a_filter[$id]['interface']) && $a_filter[$id]['interface'] <> "") {
			$pconfig['interface'] = explode(",", $a_filter[$id]['interface']);
		}
	}

	if (isset($a_filter[$id]['direction'])) {
		$pconfig['direction'] = $a_filter[$id]['direction'];
	}

	if (isset($a_filter[$id]['ipprotocol'])) {
		$pconfig['ipprotocol'] = $a_filter[$id]['ipprotocol'];
	} else {
		$pconfig['ipprotocol'] = 'inet';
	}

	if (isset($a_filter[$id]['protocol'])) {
		$pconfig['proto'] = $a_filter[$id]['protocol'];
	} else {
		$pconfig['proto'] = "any";
	}

	if ($a_filter[$id]['protocol'] == "icmp") {
		$pconfig['icmptype'] = $a_filter[$id]['icmptype'];
	}

	list($error, $pconfig_nat) = parse_firewall_rule_nat_from_config($a_filter[$id]);
	if (!isset($error)) {
		$pconfig = array_merge($pconfig, $pconfig_nat);
	}

	address_to_pconfig($a_filter[$id]['source'], $pconfig['src'],
		$pconfig['srcmask'], $pconfig['srcnot'],
		$pconfig['srcbeginport'], $pconfig['srcendport']);

	if ($a_filter[$id]['os'] != "") {
		$pconfig['os'] = $a_filter[$id]['os'];
	}

	address_to_pconfig($a_filter[$id]['destination'], $pconfig['dst'],
		$pconfig['dstmask'], $pconfig['dstnot'],
		$pconfig['dstbeginport'], $pconfig['dstendport']);

	if ($a_filter[$id]['dscp'] <> "") {
		$pconfig['dscp'] = $a_filter[$id]['dscp'];
	}

	$pconfig['disabled'] = isset($a_filter[$id]['disabled']);
	$pconfig['log'] = isset($a_filter[$id]['log']);
	$pconfig['descr'] = $a_filter[$id]['descr'];

	if (isset($a_filter[$id]['tcpflags_any'])) {
		$pconfig['tcpflags_any'] = true;
	} else {
		if (isset($a_filter[$id]['tcpflags1']) && $a_filter[$id]['tcpflags1'] <> "") {
			$pconfig['tcpflags1'] = $a_filter[$id]['tcpflags1'];
		}
		if (isset($a_filter[$id]['tcpflags2']) && $a_filter[$id]['tcpflags2'] <> "") {
			$pconfig['tcpflags2'] = $a_filter[$id]['tcpflags2'];
		}
	}

	if (isset($a_filter[$id]['tag']) && $a_filter[$id]['tag'] <> "") {
		$pconfig['tag'] = $a_filter[$id]['tag'];
	}
	if (isset($a_filter[$id]['tagged']) && $a_filter[$id]['tagged'] <> "") {
		$pconfig['tagged'] = $a_filter[$id]['tagged'];
	}
	if (isset($a_filter[$id]['quick']) && $a_filter[$id]['quick']) {
		$pconfig['quick'] = $a_filter[$id]['quick'];
	}
	if (isset($a_filter[$id]['allowopts'])) {
		$pconfig['allowopts'] = true;
	}
	if (isset($a_filter[$id]['disablereplyto'])) {
		$pconfig['disablereplyto'] = true;
	}
	if (isset($a_filter[$id]['nottagged'])) {
		$pconfig['nottagged'] = true;
	}

	/* advanced */
	$pconfig['max'] = $a_filter[$id]['max'];
	$pconfig['max-src-nodes'] = $a_filter[$id]['max-src-nodes'];
	$pconfig['max-src-conn'] = $a_filter[$id]['max-src-conn'];
	$pconfig['max-src-states'] = $a_filter[$id]['max-src-states'];
	$pconfig['statepolicy'] = $a_filter[$id]['statepolicy'];
	$pconfig['statetype'] = $a_filter[$id]['statetype'];
	$pconfig['statetimeout'] = $a_filter[$id]['statetimeout'];
	$pconfig['nopfsync'] = isset($a_filter[$id]['nopfsync']);

	/* advanced - nosync */
	$pconfig['nosync'] = isset($a_filter[$id]['nosync']);

	/* advanced - new connection per second banning*/
	$pconfig['max-src-conn-rate'] = $a_filter[$id]['max-src-conn-rate'];
	$pconfig['max-src-conn-rates'] = $a_filter[$id]['max-src-conn-rates'];

	/* Multi-WAN next-hop support */
	$pconfig['gateway'] = $a_filter[$id]['gateway'];

	/* Shaper support */
	$pconfig['defaultqueue'] = (($a_filter[$id]['ackqueue'] == "none") ? '' : $a_filter[$id]['defaultqueue']);
	$pconfig['ackqueue'] = (($a_filter[$id]['ackqueue'] == "none") ? '' : $a_filter[$id]['ackqueue']);
	$pconfig['dnpipe'] = (($a_filter[$id]['dnpipe'] == "none") ? '' : $a_filter[$id]['dnpipe']);
	$pconfig['pdnpipe'] = (($a_filter[$id]['pdnpipe'] == "none") ? '' : $a_filter[$id]['pdnpipe']);

	//schedule support
	$pconfig['sched'] = (($a_filter[$id]['sched'] == "none") ? '' : $a_filter[$id]['sched']);
	$pconfig['vlanprio'] = (($a_filter[$id]['vlanprio'] == "none") ? '' : $a_filter[$id]['vlanprio']);
	$pconfig['vlanprioset'] = (($a_filter[$id]['vlanprioset'] == "none") ? '' : $a_filter[$id]['vlanprioset']);
	if (!$dup) {
		$pconfig['associated-rule-id'] = $a_filter[$id]['associated-rule-id'];
	}

	$pconfig['tracker'] = $a_filter[$id]['tracker'];

	return $pconfig;
}

/* OS fingerprint names pf knows (for the OS detection option). */
function firewall_rule_ostypes() {
	static $ostypes = null;
	if ($ostypes !== null) {
		return $ostypes;
	}
	$ostypes = array();
	exec('/sbin/pfctl -s osfp | /usr/bin/tr \'\t\' \' \'', $ostypes);

	if (count($ostypes) > 2) {
		// Remove header rows from pfctl output
		array_shift($ostypes);
		array_shift($ostypes);
	} else {
		// Fall back to a default list
		$ostypes = array(
			"AIX",
			"Linux",
			"FreeBSD",
			"NetBSD",
			"OpenBSD",
			"Solaris",
			"MacOS",
			"Windows",
			"Novell",
			"NMAP"
		);
	}
	return $ostypes;
}

/* ALTQ queues a rule may use, as name => label. */
function firewall_rule_queue_list() {
	$ifdisp = get_configured_interface_with_descr();
	read_altq_config(); /* XXX: */
	$qlist =& get_unique_queue_list();

	$list = array('' => 'none');
	if (!is_array($qlist)) {
		$qlist = array();
	}

	foreach ($qlist as $q => $qkey) {
		if (isset($ifdisp[$q])) {
			$list[$q] = $ifdisp[$q];
		} else {
			$list[$q] = $q;
		}
	}
	return $list;
}

/* Limiters (dummynet queues and pipes) a rule may use. */
function firewall_rule_dnqueue_list() {
	read_dummynet_config(); /* XXX: */
	$dnqlist =& get_unique_dnqueue_list();
	return is_array($dnqlist) ? $dnqlist : array();
}

/*
 * Run a firewall_rules custom code hook directory. The hooks used to run
 * from the page's global scope, so they read $_POST and may use
 * "global $input_errors, $filterent, $pconfig"; mirror those around the call.
 */
function firewall_rule_run_hook($dir, &$post, &$input_errors, &$filterent = null, &$pconfig = null) {
	$saved_post = $_POST;
	$_POST = $post;
	$GLOBALS['input_errors'] = $input_errors;
	$GLOBALS['filterent'] = $filterent;
	$GLOBALS['pconfig'] = $pconfig;
	FreeSense_handle_custom_code($dir);
	$post = $_POST;
	$input_errors = $GLOBALS['input_errors'];
	$filterent = $GLOBALS['filterent'];
	$pconfig = $GLOBALS['pconfig'];
	$_POST = $saved_post;
}

/* The rules tab a rule belongs to: its interface, or "FloatingRules". */
function filter_rule_tab($rule) {
	return isset($rule['floating']) ? 'FloatingRules' : (string)($rule['interface'] ?? '');
}

/*
 * Validate and save a filter rule from firewall_rules_edit.php's form fields.
 *   $id     index of the rule to replace, or null to add a rule
 *   $after  for a new rule: index to insert after, -1 for the top, null for the end
 *   $if     for a new rule: the rules tab it is added on (interface or "FloatingRules")
 * On success the filter is marked dirty (apply with applyFilterRules()).
 * Returns array('input_errors' => [...], 'pconfig' => fields to show again
 * after errors, 'id' => index of the saved rule, 'interface' => its tab).
 */
function saveFilterRule($post, $id = null, $after = null, $if = null) {
	global $icmplookup, $icmptypes4, $icmptypes6, $icmptypes46, $statepolicy_values,
	    $statetype_values, $vlanprio, $filter_srcdsttype_flags,
	    $filter_srcdsttype_flags_validation, $tcpflags, $firewall_rules_dscp_types;

	$a_filter = get_filter_rules_list();
	if (isset($id) && !$a_filter[$id]) {
		$id = null;
	}
	if (isset($id)) {
		$pconfig = getFilterRule($id);
		/* Allow the FloatingRules to work */
		$if = $pconfig['interface'];
	} else {
		$pconfig = array('interface' => $if);
	}
	$ostypes = firewall_rule_ostypes();
	$list = firewall_rule_queue_list();
	$dnqlist = firewall_rule_dnqueue_list();
	$a_gatewaygroups = return_gateway_groups_array();
	$input_errors = array();
	$filterent = null;
	$config_nat = null;

	if (!array_key_exists($post['ipprotocol'], $icmplookup)) {
		$input_errors[] = gettext("The IP protocol is not recognized.");
		unset($post['ipprotocol']);
	}

	// add validation + input error for $post['interface']

	if ($post['interface'] == 'FloatingRules' || isset($post['floating'])) {
		$valid = ['pass', 'block', 'reject', 'match'];
	} else {
		$valid = ['pass', 'block', 'reject'];
	}

	if (!(is_string($post['type'])  && in_array($post['type'], $valid))) {
		$input_errors[] = gettext("A valid rule type is not selected.");
		unset($post['type']);
	}

	if (isset($post['tracker']) && !is_numericint($post['tracker'])) {
		unset($post['tracker']);	// silently unset hidden input if invalid
	}

	if (isset($a_filter[$id]['associated-rule-id'])) {
		$post['proto'] = $pconfig['proto'];
		if ($pconfig['proto'] == "icmp") {
			$post['icmptype'] = $pconfig['icmptype'];
		}
	}

	if (isset($post['ipprotocol']) && $post['gateway'] <> '') {
		foreach (config_get_path('gateways/gateway_group',[]) as $gw_group) {
			if ($gw_group['name'] != $post['gateway']) {
				continue;
			}

			if ($post['ipprotocol'] == "inet46") {
				$input_errors[] = gettext("Gateways can not be assigned in a rule that applies to both IPv4 and IPv6.");
			} elseif ($post['nat']) {
				if (($post['ipprotocol'] == "inet6") && ($a_gatewaygroups[$post['gateway']]['ipprotocol'] != 'inet')) {
					$input_errors[] = gettext("An IPv6 gateway group can not be assigned in NAT64 rules.");
				}
			} elseif ($post['ipprotocol'] != $a_gatewaygroups[$post['gateway']]['ipprotocol']) {
				if ($post['ipprotocol'] == "inet6") {
					$input_errors[] = gettext("An IPv4 gateway group can not be assigned in IPv6 rules.");
				} elseif ($post['ipprotocol'] == "inet") {
					$input_errors[] = gettext("An IPv6 gateway group can not be assigned in IPv4 rules.");
				}
			}
		}
		if ($iptype = is_ipaddr(lookup_gateway_ip_by_name($post['gateway']))) {
			// this also implies that  $post['gateway'] was set and not empty
			if ($post['ipprotocol'] == "inet46") {
				$input_errors[] = gettext("Gateways can not be assigned in a rule that applies to both IPv4 and IPv6.");
			} elseif ($post['nat']) {
				if (($post['ipprotocol'] == "inet6") && ($iptype != 4)) {
					$input_errors[] = gettext("An IPv6 gateway can not be assigned in NAT64 rules.");
				}
			} else {
				if (($post['ipprotocol'] == "inet6") && ($iptype != 6)) {
					$input_errors[] = gettext("An IPv4 gateway can not be assigned in IPv6 rules.");
				}
				if (($post['ipprotocol'] == "inet") && ($iptype != 4)) {
					$input_errors[] = gettext("An IPv6 gateway can not be assigned in IPv4 rules.");
				}
			}
		}
	}

	if (strpos($post['descr'], "\\") !== false) {
		$input_errors[] = gettext("The '\' character is not allowed in the Description field.");
	}

	if (!array_key_exists(strtolower($post['proto']), get_ipprotocols('portsonly'))) {
		$post['srcbeginport'] = 0;
		$post['srcendport'] = 0;
		$post['dstbeginport'] = 0;
		$post['dstendport'] = 0;
	} else {
		if ($post['srcbeginport_cust'] && !$post['srcbeginport']) {
			$post['srcbeginport'] = trim($post['srcbeginport_cust']);
		}
		if ($post['srcendport_cust'] && !$post['srcendport']) {
			$post['srcendport'] = trim($post['srcendport_cust']);
		}
		if ($post['srcbeginport'] == "any") {
			$post['srcbeginport'] = 0;
			$post['srcendport'] = 0;
		} else {
			if (!$post['srcendport']) {
				$post['srcendport'] = $post['srcbeginport'];
			}
		}
		if ($post['srcendport'] == "any") {
			$post['srcendport'] = $post['srcbeginport'];
		}

		if ($post['dstbeginport_cust'] && !$post['dstbeginport']) {
			$post['dstbeginport'] = trim($post['dstbeginport_cust']);
		}
		if ($post['dstendport_cust'] && !$post['dstendport']) {
			$post['dstendport'] = trim($post['dstendport_cust']);
		}

		if ($post['dstbeginport'] == "any") {
			$post['dstbeginport'] = 0;
			$post['dstendport'] = 0;
		} else {
			if (!$post['dstendport']) {
				$post['dstendport'] = $post['dstbeginport'];
			}
		}
		if ($post['dstendport'] == "any") {
			$post['dstendport'] = $post['dstbeginport'];
		}
	}

	if (get_specialnet($post['srctype'], $filter_srcdsttype_flags_validation)) {
		$post['src'] = $post['srctype'];
		$post['srcmask'] = 0;
	} else if ($post['srctype'] == "single") {
		if (is_ipaddrv6($post['src'])) {
			$post['srcmask'] = 128;
		} else {
			$post['srcmask'] = 32;
		}
	}
	if (!config_path_enabled('system', 'allow_nat64_prefix_override') &&
	    $post['nat'] && ($post['ipprotocol'] == 'inet6')) {
		// Force the well-known NAT64 prefix.
		unset($post['dstnot']);
		$post['dsttype'] = 'network';
		$post['dst'] = NAT64_WELLKNOWN_PREFIX;
		$post['dstmask'] = NAT64_WELLKNOWN_PREFIX_LENGTH;
	} elseif (get_specialnet($post['dsttype'], $filter_srcdsttype_flags_validation)) {
		$post['dst'] = $post['dsttype'];
		$post['dstmask'] = 0;
	} else if ($post['dsttype'] == "single") {
		if (is_ipaddrv6($post['dst'])) {
			$post['dstmask'] = 128;
		} else {
			$post['dstmask'] = 32;
		}
	}

	$pconfig = $post;

	if (!isset($pconfig['ipprotocol'])) {
		// other things depend on this, so ensure a valid value if none provided
		$pconfig['ipprotocol'] = "inet";
	}

	if (($post['proto'] == "icmp") &&
	    is_array($post['icmptype']) &&
	    count($post['icmptype'])) {
		$pconfig['icmptype'] = implode(',', $post['icmptype']);
	} else {
		unset($pconfig['icmptype']);
	}

	/* input validation */
	$reqdfields = explode(" ", "type proto");
	if (isset($a_filter[$id]['associated-rule-id']) === false) {
		$reqdfields[] = "src";
		$reqdfields[] = "dst";
	}
	$reqdfieldsn = array(gettext("Type"), gettext("Protocol"));
	if (isset($a_filter[$id]['associated-rule-id']) === false) {
		$reqdfieldsn[] = gettext("Source");
		$reqdfieldsn[] = gettext("Destination");
	}

	if ($post['statetype'] == "synproxy state") {
		if ($post['proto'] != "tcp") {
			$input_errors[] = sprintf(gettext("%s is only valid with protocol TCP."), $post['statetype']);
		}
		if ($post['gateway'] != "") {
			$input_errors[] = sprintf(gettext("%s is only valid if the gateway is set to 'default'."), $post['statetype']);
		}
	}

	list($error, $config_nat) = parse_firewall_rule_nat_from_post($post);
	if (isset($error)) {
		$input_errors[] = $error;
	}

	if (isset($a_filter[$id]['associated-rule-id']) === false &&
	    (!(get_specialnet($post['srctype'], $filter_srcdsttype_flags_validation) || ($post['srctype'] == "single")))) {
		$reqdfields[] = "srcmask";
		$reqdfieldsn[] = gettext("Source bit count");
	}
	if (isset($a_filter[$id]['associated-rule-id']) === false &&
	    (!(get_specialnet($post['dsttype'], $filter_srcdsttype_flags_validation) || ($post['dsttype'] == "single")))) {
		$reqdfields[] = "dstmask";
		$reqdfieldsn[] = gettext("Destination bit count");
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (config_path_enabled('system', 'allow_nat64_prefix_override') && 
	    ($post['nat'] && ($post['ipprotocol'] == 'inet6'))) {
		if (isset($post['dstnot'])) {
			$input_errors[] = gettext("Invert match cannot be selected with NAT64.");
		}
		if ($post['dsttype'] != "network") {
			$input_errors[] = gettext("The NAT64 destination type must be a network.");
		}
		if (!validate_nat64_prefix($post['dst'] . '/' . $post['dstmask'])) {
			$input_errors[] = gettext("The NAT64 prefix is invalid.");
		}
	}

	if ((isset($post['srcnot']) && ($post['srctype'] == 'any')) ||
	    (isset($post['dstnot']) && ($post['dsttype'] == 'any'))) {
		$input_errors[] = gettext("Invert match cannot be selected with 'any'.");
	}

	if (isset($post['nottagged']) && empty($post['tagged'])) {
		$input_errors[] = gettext("Invert tagged match cannot be selected without any tags.");
	}

	if (!$post['srcbeginport']) {
		$post['srcbeginport'] = 0;
		$post['srcendport'] = 0;
	}
	if (!$post['dstbeginport']) {
		$post['dstbeginport'] = 0;
		$post['dstendport'] = 0;
	}

	if ($post['srcbeginport'] && !is_port_or_alias($post['srcbeginport'])) {
		$input_errors[] = sprintf(gettext("%s is not a valid start source port. It must be a port alias or integer between 1 and 65535."), $post['srcbeginport']);
	}
	if ($post['srcendport'] && !is_port_or_alias($post['srcendport'])) {
			$input_errors[] = sprintf(gettext("%s is not a valid end source port. It must be a port alias or integer between 1 and 65535."), $post['srcendport']);
	}
	if ($post['dstbeginport'] && !is_port_or_alias($post['dstbeginport'])) {
			$input_errors[] = sprintf(gettext("%s is not a valid start destination port. It must be a port alias or integer between 1 and 65535."), $post['dstbeginport']);
	}
	if ($post['dstendport'] && !is_port_or_alias($post['dstendport'])) {
			$input_errors[] = sprintf(gettext("%s is not a valid end destination port. It must be a port alias or integer between 1 and 65535."), $post['dstendport']);
	}
	if (!$post['srcbeginport_cust'] && $post['srcendport_cust']) {
		if (is_alias($post['srcendport_cust'])) {
			$input_errors[] = 'If a port alias is put in the Source port range to: field the same port alias must be put in the from: field';
		}
	}
	if ($post['srcbeginport_cust'] && $post['srcendport_cust']) {
		if (is_alias($post['srcbeginport_cust']) && is_alias($post['srcendport_cust']) && $post['srcbeginport_cust'] != $post['srcendport_cust']) {
			$input_errors[] = 'The same port alias must be used in Source port range from: and to: fields';
		}
		if ((is_alias($post['srcbeginport_cust']) && (!is_alias($post['srcendport_cust']) && $post['srcendport_cust'] != '')) ||
		    ((!is_alias($post['srcbeginport_cust']) && $post['srcbeginport_cust'] != '') && is_alias($post['srcendport_cust']))) {
			$input_errors[] = 'Numbers and port aliases cannot be specified at the same time in Source port range from: and to: field';
		}
	}
	if (!$post['dstbeginport_cust'] && $post['dstendport_cust']) {
		if (is_alias($post['dstendport_cust'])) {
			$input_errors[] = 'If a port alias is put in the Destination port range to: field the same port alias must be put in the from: field';
		}
	}
	if ($post['dstbeginport_cust'] && $post['dstendport_cust']) {
		if (is_alias($post['dstbeginport_cust']) && is_alias($post['dstendport_cust']) && $post['dstbeginport_cust'] != $post['dstendport_cust']) {
			$input_errors[] = 'The same port alias must be used in Destination port range from: and to: fields';
		}
		if ((is_alias($post['dstbeginport_cust']) && (!is_alias($post['dstendport_cust']) && $post['dstendport_cust'] != '')) ||
		    ((!is_alias($post['dstbeginport_cust']) && $post['dstbeginport_cust'] != '') && is_alias($post['dstendport_cust']))) {
			$input_errors[] = 'Numbers and port aliases cannot be specified at the same time in Destination port range from: and to: field';
		}
	}

	if ($post['src']) {
		$post['src'] = addrtolower(trim($post['src']));
	}
	if ($post['dst']) {
		$post['dst'] = addrtolower(trim($post['dst']));
	}

	/* if user enters an alias and selects "network" then disallow. */
	if ($post['srctype'] == "network") {
		if (is_alias($post['src'])) {
			$input_errors[] = gettext("Alias entries must be a single host or alias.");
		}
	}
	if ($post['dsttype'] == "network") {
		if (is_alias($post['dst'])) {
			$input_errors[] = gettext("Alias entries must be a single host or alias.");
		}
	}

	if (!get_specialnet($post['srctype'], $filter_srcdsttype_flags_validation)) {
		if (($post['src'] && !is_ipaddroralias($post['src']))) {
			$input_errors[] = sprintf(gettext("%s is not a valid source IP address or alias."), $post['src']);
		}
		if (($post['srcmask'] && !is_numericint($post['srcmask']))) {
			$input_errors[] = gettext("A valid source bit count must be specified.");
		}
	}
	if (!get_specialnet($post['dsttype'], $filter_srcdsttype_flags_validation)) {
		if (($post['dst'] && !is_ipaddroralias($post['dst']))) {
			$input_errors[] = sprintf(gettext("%s is not a valid destination IP address or alias."), $post['dst']);
		}
		if (($post['dstmask'] && !is_numericint($post['dstmask']))) {
			$input_errors[] = gettext("A valid destination bit count must be specified.");
		}
	}
	if ((is_ipaddr($post['src']) && is_ipaddr($post['dst']))) {
		if (!validate_address_family($post['src'], $post['dst'])) {
			$input_errors[] = gettext("The source and destination IP addresses must have the same family (IPv4 / IPv6).");
		}
	}
	if ((is_ipaddrv6($post['src']) || is_ipaddrv6($post['dst'])) && ($post['ipprotocol'] == "inet")) {
		$input_errors[] = gettext("IPv6 addresses cannot be used in IPv4 rules (except within an alias).");
	}
	if ((is_ipaddrv4($post['src']) || is_ipaddrv4($post['dst'])) && ($post['ipprotocol'] == "inet6")) {
		$input_errors[] = gettext("IPv4 addresses can not be used in IPv6 rules (except within an alias).");
	}

	if ((is_ipaddr($post['src']) || is_ipaddr($post['dst'])) && ($post['ipprotocol'] == "inet46")) {
		$input_errors[] = gettext("IPv4 and IPv6 addresses can not be used in rules that apply to both IPv4 and IPv6 (except within an alias).");
	}

	if ($post['srcbeginport'] > $post['srcendport']) {
		/* swap */
		$tmp = $post['srcendport'];
		$post['srcendport'] = $post['srcbeginport'];
		$post['srcbeginport'] = $tmp;
	}
	if ($post['dstbeginport'] > $post['dstendport']) {
		/* swap */
		$tmp = $post['dstendport'];
		$post['dstendport'] = $post['dstbeginport'];
		$post['dstbeginport'] = $tmp;
	}
	if ($post['os']) {
		if ($post['proto'] != "tcp") {
			$input_errors[] = gettext("OS detection is only valid with protocol TCP.");
		}
		if (!in_array($post['os'], $ostypes)) {
			$input_errors[] = gettext("Invalid OS detection selection. Please select a valid OS.");
		}
	}

	if ($post['proto'] == "icmp") {
		$t = $post['icmptype'];
		if (isset($t) && !is_array($t)) {
			// shouldn't happen but avoids making assumptions for data-sanitising
			$input_errors[] = gettext("ICMP types expected to be a list if present, but is not.");
		} elseif (!isset($t) || count($t) == 0) {
			// not specified or none selected
			unset($post['icmptype']);
		} elseif (isset($post['ipprotocol'])) {
			// check data; if ipprotocol invalid then safe to skip this (we can't determine valid icmptypes, but input error already raised for ipprotocol)
			$bad_types = array();
			if ((count($t) == 1 && !isset($t['any'])) || count($t) > 1) {
				// Only need to check valid if just one selected != "any", or >1 selected
				$p = $post['ipprotocol'];
				foreach ($t as $type) {
					if (($p == 'inet' && !array_key_exists($type, $icmptypes4)) ||
					    ($p == 'inet6' && !array_key_exists($type, $icmptypes6)) ||
					    ($p == 'inet46' && !array_key_exists($type, $icmptypes46))) {
							$bad_types[] = $type;
					}
				}
			}
			if (count($bad_types) > 0) {
				$input_errors[] = sprintf(gettext("Invalid ICMP subtype: %s can not be used with %s."), implode(';', $bad_types),  $t['name']);
			}
		}
	} else {
		unset($post['icmptype']); // field not applicable, might hold junk from old hidden selections. Unset it.
	}

	if ($post['ackqueue'] != "") {
		if ($post['defaultqueue'] == "") {
			$input_errors[] = gettext("A queue must be selected when an acknowledge queue is also selected.");
		} else if ($post['ackqueue'] == $post['defaultqueue']) {
			$input_errors[] = gettext("Acknowledge queue and Queue cannot be the same.");
		}
	}
	if (isset($post['floating']) && $post['pdnpipe'] != "" && (empty($post['direction']) || $post['direction'] == "any")) {
		$input_errors[] = gettext("Limiters can not be used in Floating rules without choosing a direction.");
	}
	if (isset($post['floating']) && $post['gateway'] != "" && (empty($post['direction']) || $post['direction'] == "any")) {
		$input_errors[] = gettext("Gateways can not be used in Floating rules without choosing a direction.");
	}
	if ($post['pdnpipe'] && $post['pdnpipe'] != "") {
		if ($post['dnpipe'] == "") {
			$input_errors[] = gettext("A queue must be selected for the In direction before selecting one for Out too.");
		} else if ($post['pdnpipe'] == $post['dnpipe']) {
			$input_errors[] = gettext("In and Out Queue cannot be the same.");
		} else if ($dnqlist[$post['pdnpipe']][0] == "?" && $dnqlist[$post['dnpipe']][0] <> "?") {
			$input_errors[] = gettext("A queue and a virtual interface cannot be selected for IN and Out. Both must be from the same type.");
		} else if ($dnqlist[$post['dnpipe']][0] == "?" && $dnqlist[$post['pdnpipe']][0] <> "?") {
			$input_errors[] = gettext("A queue and a virtual interface cannot be selected for IN and Out. Both must be from the same type.");
		}
		if ($post['direction'] == "out" && empty($post['gateway']) && $post['type'] == "pass") {
			$input_errors[] = gettext("Please select a gateway, normally the interface selected gateway, so the limiters work correctly");
		}
	}
	if (!empty($post['ruleid']) && !is_numericint($post['ruleid'])) {
		$input_errors[] = gettext('ID must be an integer');
	}

	if (!in_array($post['proto'], array("tcp", "tcp/udp"))) {
		if (!empty($post['max-src-conn'])) {
			$input_errors[] = gettext("The maximum number of established connections per host (advanced option) can only be specified for TCP protocol.");
		}
		if (!empty($post['max-src-conn-rate']) || !empty($post['max-src-conn-rates'])) {
			$input_errors[] = gettext("The maximum new connections per host / per second(s) (advanced option) can only be specified for TCP protocol.");
		}
	}

	if ($post['type'] <> "pass") {
		if (!empty($post['max'])) {
			$input_errors[] = gettext("The maximum state entries (advanced option) can only be specified for Pass type rules.");
		}
		if (!empty($post['max-src-nodes'])) {
			$input_errors[] = gettext("The maximum number of unique source hosts (advanced option) can only be specified for Pass type rules.");
		}
		if (!empty($post['max-src-conn'])) {
			$input_errors[] = gettext("The maximum number of established connections per host (advanced option) can only be specified for Pass type rules.");
		}
		if (!empty($post['max-src-states'])) {
			$input_errors[] = gettext("The maximum state entries per host (advanced option) can only be specified for Pass type rules.");
		}
		if (!empty($post['max-src-conn-rate']) || !empty($post['max-src-conn-rates'])) {
			$input_errors[] = gettext("The maximum new connections per host / per second(s) (advanced option) can only be specified for Pass type rules.");
		}
		if (!empty($post['statetimeout'])) {
			$input_errors[] = gettext("The state timeout (advanced option) can only be specified for Pass type rules.");
		}
	}

	if ($post['statetype'] == "none") {
		if (!empty($post['max'])) {
			$input_errors[] = gettext("The maximum state entries (advanced option) cannot be specified if statetype is none.");
		}
		if (!empty($post['max-src-nodes'])) {
			$input_errors[] = gettext("The maximum number of unique source hosts (advanced option) cannot be specified if statetype is none.");
		}
		if (!empty($post['max-src-conn'])) {
			$input_errors[] = gettext("The maximum number of established connections per host (advanced option) cannot be specified if statetype is none.");
		}
		if (!empty($post['max-src-states'])) {
			$input_errors[] = gettext("The maximum state entries per host (advanced option) cannot be specified if statetype is none.");
		}
		if (!empty($post['max-src-conn-rate']) || !empty($post['max-src-conn-rates'])) {
			$input_errors[] = gettext("The maximum new connections per host / per second(s) (advanced option) cannot be specified if statetype is none.");
		}
		if (!empty($post['statetimeout'])) {
			$input_errors[] = gettext("The state timeout (advanced option) cannot be specified if statetype is none.");
		}
	}

	if (($post['max'] != "") && !is_posnumericint($post['max'])) {
		$input_errors[] = gettext("Maximum state entries (advanced option) must be a positive integer");
	}

	if (($post['max-src-nodes'] != "") && !is_posnumericint($post['max-src-nodes'])) {
		$input_errors[] = gettext("Maximum number of unique source hosts (advanced option) must be a positive integer");
	}

	if (($post['max-src-conn'] != "") && !is_posnumericint($post['max-src-conn'])) {
		$input_errors[] = gettext("Maximum number of established connections per host (advanced option) must be a positive integer");
	}

	if (($post['max-src-states'] != "") && !is_posnumericint($post['max-src-states'])) {
		$input_errors[] = gettext("Maximum state entries per host (advanced option) must be a positive integer");
	}

	if (($post['max-src-conn-rate'] != "") && !is_posnumericint($post['max-src-conn-rate'])) {
		$input_errors[] = gettext("Maximum new connections per host / per second(s) (advanced option) must be a positive integer");
	}

	if (($post['statetimeout'] != "") && !is_posnumericint($post['statetimeout'])) {
		$input_errors[] = gettext("State timeout (advanced option) must be a positive integer");
	}

	if ((($post['max-src-conn-rate'] <> "" and $post['max-src-conn-rates'] == "")) ||
	    (($post['max-src-conn-rate'] == "" and $post['max-src-conn-rates'] <> ""))) {
		$input_errors[] = gettext("Both maximum new connections per host and the interval (per second(s)) must be specified");
	}

	if (!$post['tcpflags_any']) {
		$settcpflags = array();
		$outoftcpflags = array();
		foreach ($tcpflags as $tcpflag) {
			if ($post['tcpflags1_' . $tcpflag] == "on") {
				$settcpflags[] = $tcpflag;
			}
			if ($post['tcpflags2_' . $tcpflag] == "on") {
				$outoftcpflags[] = $tcpflag;
			}
		}
		if (empty($outoftcpflags) && !empty($settcpflags)) {
			$input_errors[] = gettext("If TCP flags that should be set is specified, then out of which flags should be specified as well.");
		} else {
			/* upstream issue 11762 */
			foreach ($settcpflags as $tcpflag) {
				if (!in_array($tcpflag, $outoftcpflags)) {
					$input_errors[] = sprintf(gettext("The TCP flag %s must be checked in 'out of' list."), strtoupper($tcpflag));
				}
			}
		}
	}

	if ($post['dscp'] && !in_array($post['dscp'], $firewall_rules_dscp_types)) {
		$input_errors[] = gettext("Invalid DSCP value.");
	}
	if ($post['tag'] && !is_validaliasname($post['tag'])) {
		$input_errors[] = gettext("Invalid tag value.");
	}
	if ($post['tagged'] && !is_validaliasname($post['tagged'])) {
		$input_errors[] = gettext("Invalid tagged value.");
	}
	if ($post['statepolicy'] && !array_key_exists($post['statepolicy'], $statepolicy_values)) {
		$input_errors[] = gettext("Invalid State Policy.");
	}
	if ($post['statetype'] && !array_key_exists($post['statetype'], $statetype_values)) {
		$input_errors[] = gettext("Invalid State Type.");
	}
	if ($post['vlanprio'] && !array_key_exists($post['vlanprio'], $vlanprio)) {
		$input_errors[] = gettext("Invalid VLAN Prio.");
	}
	if ($post['vlanprioset'] && !array_key_exists($post['vlanprioset'], $vlanprio)) {
		$input_errors[] = gettext("Invalid VLAN Prio Set.");
	}

	if ($post['ackqueue'] && !array_key_exists($post['ackqueue'], $list)) {
		$input_errors[] = gettext("Invalid ACK Queue.");
	}
	if ($post['defaultqueue'] && !array_key_exists($post['defaultqueue'], $list)) {
		$input_errors[] = gettext("Invalid Default Queue.");
	}

	if ($post['dnpipe'] && !array_key_exists($post['dnpipe'], $dnqlist)) {
		$input_errors[] = gettext("Invalid In Pipe.");
	}
	if ($post['pdnpipe'] && !array_key_exists($post['pdnpipe'], $dnqlist)) {
		$input_errors[] = gettext("Invalid Out Pipe.");
	}

	// Allow extending of the firewall edit page and include custom input validation
	firewall_rule_run_hook("/usr/local/pkg/firewall_rules/input_validation", $post, $input_errors, $filterent, $pconfig);

	if (!$input_errors) {
		$filterent = array();
		$filterent['id'] = $post['ruleid']>0?$post['ruleid']:'';

		$filterent['tracker'] = empty($post['tracker']) ? (int)microtime(true) : $post['tracker'];

		$filterent['type'] = $post['type'];

		if (isset($post['floating']) &&
		    (!isset($post['interface']) || in_array('any', $post['interface']))) {
			$post['interface'] = array('any');
		} else {
			$filterent['interface'] = $post['interface'];
		}

		$filterent['ipprotocol'] = $post['ipprotocol'];

		if (isset($config_nat)) {
			$filterent = array_merge($filterent, $config_nat);
		}

		if ($post['tcpflags_any']) {
			$filterent['tcpflags_any'] = true;
		} else {
			$settcpflags = array();
			$outoftcpflags = array();
			foreach ($tcpflags as $tcpflag) {
				if ($post['tcpflags1_' . $tcpflag] == "on") {
					$settcpflags[] = $tcpflag;
				}
				if ($post['tcpflags2_' . $tcpflag] == "on") {
					$outoftcpflags[] = $tcpflag;
				}
			}
			if (!empty($outoftcpflags)) {
				$filterent['tcpflags2'] = join(",", $outoftcpflags);
				if (!empty($settcpflags)) {
					$filterent['tcpflags1'] = join(",", $settcpflags);
				}
			}
		}

		if (isset($post['tag'])) {
			$filterent['tag'] = $post['tag'];
		}
		if (isset($post['tagged'])) {
			$filterent['tagged'] = $post['tagged'];
		}
		if ($if == "FloatingRules" || isset($post['floating'])) {
			$filterent['direction'] = $post['direction'];
			if (isset($post['quick']) && $post['quick'] <> "") {
				$filterent['quick'] = $post['quick'];
			}
			$filterent['floating'] = "yes";
			$filterent['interface'] = implode(",", $post['interface']);
		}

		/* Advanced options */
		if ($post['allowopts'] == "yes") {
			$filterent['allowopts'] = true;
		} else {
			unset($filterent['allowopts']);
		}
		if ($post['disablereplyto'] == "yes") {
			$filterent['disablereplyto'] = true;
		} else {
			unset($filterent['disablereplyto']);
		}
		if ($post['nottagged'] == "yes") {
			$filterent['nottagged'] = true;
		} else {
			unset($filterent['nottagged']);
		}
		$filterent['max'] = $post['max'];
		$filterent['max-src-nodes'] = $post['max-src-nodes'];
		$filterent['max-src-conn'] = $post['max-src-conn'];
		$filterent['max-src-states'] = $post['max-src-states'];
		$filterent['statetimeout'] = $post['statetimeout'];
		$filterent['statepolicy'] = $post['statepolicy'];
		$filterent['statetype'] = $post['statetype'];
		$filterent['os'] = $post['os'];
		if ($post['nopfsync'] <> "") {
			$filterent['nopfsync'] = true;
		} else {
			unset($filterent['nopfsync']);
		}

		/* Nosync directive - do not xmlrpc sync this item */
		if ($post['nosync'] <> "") {
			$filterent['nosync'] = true;
		} else {
			unset($filterent['nosync']);
		}

		/* unless both values are provided, unset the values - ticket #650 */
		if ($post['max-src-conn-rate'] <> "" and $post['max-src-conn-rates'] <> "") {
			$filterent['max-src-conn-rate'] = $post['max-src-conn-rate'];
			$filterent['max-src-conn-rates'] = $post['max-src-conn-rates'];
		} else {
			unset($filterent['max-src-conn-rate']);
			unset($filterent['max-src-conn-rates']);
		}

		if ($post['proto'] != "any") {
			$filterent['protocol'] = $post['proto'];
		} else {
			unset($filterent['protocol']);
		}

		// Convert array of selected ICMP types to comma-separated string, for backwards compatibility (previously only allowed one type per rule)
		if ($post['proto'] == "icmp" && is_array($post['icmptype']) && !isset($post['icmptype']['any']) && count($post['icmptype']) > 0) {
			//if any of these conditions not met, rule would apply to all icmptypes, so we would unset
			$filterent['icmptype'] = implode(',', $post['icmptype']);
		} else {
			unset($filterent['icmptype']);
		}

		pconfig_to_address($filterent['source'], $post['src'],
			$post['srcmask'], $post['srcnot'],
			$post['srcbeginport'], $post['srcendport'], false, $filter_srcdsttype_flags);

		pconfig_to_address($filterent['destination'], $post['dst'],
			$post['dstmask'], $post['dstnot'],
			$post['dstbeginport'], $post['dstendport'], false, $filter_srcdsttype_flags);

		if ($post['disabled']) {
			$filterent['disabled'] = true;
		} else {
			unset($filterent['disabled']);
		}

		if ($post['dscp']) {
			$filterent['dscp'] = $post['dscp'];
		}

		if ($post['log']) {
			$filterent['log'] = true;
		} else {
			unset($filterent['log']);
		}

		$filterent['descr'] = trim($post['descr']);

		if ($post['gateway'] != "") {
			$filterent['gateway'] = $post['gateway'];
		}

		if ($post['defaultqueue'] != "") {
			$filterent['defaultqueue'] = $post['defaultqueue'];
			if ($post['ackqueue'] != "") {
				$filterent['ackqueue'] = $post['ackqueue'];
			}
		}

		if ($post['dnpipe'] != "") {
			$filterent['dnpipe'] = $post['dnpipe'];
			if ($post['pdnpipe'] != "") {
				$filterent['pdnpipe'] = $post['pdnpipe'];
			}
		}

		if ($post['sched'] != "") {
			$filterent['sched'] = $post['sched'];
		}

		if ($post['vlanprio'] != "") {
			$filterent['vlanprio'] = $post['vlanprio'];
		}
		if ($post['vlanprioset'] != "") {
			$filterent['vlanprioset'] = $post['vlanprioset'];
		}

		// If we have an associated nat rule, make sure the source and destination doesn't change
		if (isset($a_filter[$id]['associated-rule-id'])) {
			$filterent['interface'] = $a_filter[$id]['interface'];
			if (isset($a_filter[$id]['protocol'])) {
				$filterent['protocol'] = $a_filter[$id]['protocol'];
			} else if (isset($filterent['protocol'])) {
				unset($filterent['protocol']);
			}
			if ($a_filter[$id]['protocol'] == "icmp" && $a_filter[$id]['icmptype']) {
				$filterent['icmptype'] = $a_filter[$id]['icmptype'];
			} else if (isset($filterent['icmptype'])) {
				unset($filterent['icmptype']);
			}

			$filterent['source'] = $a_filter[$id]['source'];
			$filterent['destination'] = $a_filter[$id]['destination'];
			$filterent['associated-rule-id'] = $a_filter[$id]['associated-rule-id'];
		}

		if (isset($a_filter[$id]['created']) && is_array($a_filter[$id]['created'])) {
			$filterent['created'] = $a_filter[$id]['created'];
		}

		$filterent['updated'] = make_config_revision_entry();

		// Allow extending of the firewall edit page and include custom input validation
		firewall_rule_run_hook("/usr/local/pkg/firewall_rules/pre_write_config", $post, $input_errors, $filterent, $pconfig);

		if (isset($id) && $a_filter[$id]) {
			if (isset($pconfig['floating'])) {
				$tmpif = 'FloatingRules';
			} else {
				$tmpif = $filterent['interface'];
			}
			// Keep rule labels.
			set_labels_for_rule_item($filterent, get_label_items_from_rule_item($a_filter[$id]));
			if (isset($a_filter[$id]['force_category'])) {
				// Keep filter category override.
				$filterent['force_category'] = $a_filter[$id]['force_category'];
			}
			if (($tmpif == $if) || isset($pconfig['floating'])) {
				add_filter_rules([$filterent], $id, true);
			} else {							// rule moved to different interface
				// remove the rule from the previous interface
				remove_filter_rules($id, $if);

				// add the new rule at the end of the new interface
				add_filter_rules([$filterent]);
			}

		} else {
			$filterent['created'] = make_config_revision_entry();
			// For copy/dup the $if var is taken from the rule submission.
			// In the case of floating rules that could be anything.  But never "FloatingRules" that is needed.
			if (isset($pconfig['floating'])) {
				$tmpif = 'FloatingRules';
			} else if (isset($filterent['interface'])) {
				$tmpif = $filterent['interface'];
			} else {
				$tmpif = $if;
			}

			if (is_numeric($after) && ($tmpif == $if || (isset($pconfig['floating'])))) {
				// shift the separators and insert the rule
				if ($after == -1) {
					// rule is being placed on top
					add_filter_rules([$filterent], -1);
				} else {
					// rule is being placed after another rule
					add_filter_rules([$filterent], $after);
				}
			} else {
				// rule is being added at the end or copied to a different interface; place it at the bottom
				add_filter_rules([$filterent]);
			}
		}

		if (write_config(gettext("Firewall: Rules - saved/edited a firewall rule."))) {
			mark_subsystem_dirty('filter');
		}
	}

	$saved_id = null;
	if (empty($input_errors) && is_array($filterent)) {
		foreach (get_filter_rules_list() as $idx => $rule) {
			if ((string)($rule['tracker'] ?? '') === (string)$filterent['tracker']) {
				$saved_id = $idx;
				break;
			}
		}
	}

	return array(
		'input_errors' => $input_errors,
		'pconfig' => $pconfig,
		'id' => $saved_id,
		'interface' => isset($post['floating']) ? 'FloatingRules' : $post['interface'],
	);
}

/* Load pending filter changes. Returns filter_configure()'s result. */
function applyFilterRules() {
	$retval = 0;
	$retval |= filter_configure();

	clear_subsystem_dirty('filter');
	return $retval;
}

/* Delete one rule ($id) from rules tab $if. Returns false when there is no such rule. */
function deleteFilterRule($id, $if) {
	$rule = is_numericint($id) ? config_get_path("filter/rule/{$id}") : null;
	if (!isset($rule)) {
		return false;
	}
	remove_filter_rules($id, $if);

	if (write_config(gettext("Firewall: Rules - deleted a firewall rule."))) {
		mark_subsystem_dirty('filter');
	}
	return true;
}

/* Delete several rules (indexes) from rules tab $if. */
function deleteFilterRules(array $ids, $if) {
	$changes = [];
	remove_filter_rules($ids, $if, true, $changes);
	if (isset($changes['filter_rules']) && write_config(gettext("Firewall: Rules - deleted selected firewall rules."))) {
		mark_subsystem_dirty('filter');
	}
}

/* Enable or disable one rule. Returns "enabled", "disabled", or false when there is no such rule. */
function toggleFilterRule($id) {
	if (!config_get_path("filter/rule/{$id}")) {
		return false;
	}
	if (config_path_enabled("filter/rule/{$id}", 'disabled')) {
		config_del_path("filter/rule/{$id}/disabled");
		$wc_msg = gettext('Firewall: Rules - enabled a firewall rule.');
		$state = 'enabled';
	} else {
		config_set_path("filter/rule/{$id}/disabled", true);
		$wc_msg = gettext('Firewall: Rules - disabled a firewall rule.');
		$state = 'disabled';
	}
	if (write_config($wc_msg)) {
		mark_subsystem_dirty('filter');
	}
	return $state;
}

/* Enable or disable several rules (each one flips). */
function toggleFilterRules(array $ids) {
	foreach ($ids as $rulei) {
		if (config_path_enabled("filter/rule/{$rulei}", 'disabled')) {
			config_del_path("filter/rule/{$rulei}/disabled");
		} else {
			config_set_path("filter/rule/{$rulei}/disabled", true);
		}
	}
	if (write_config(gettext("Firewall: Rules - toggle selected firewall rules."))) {
		mark_subsystem_dirty('filter');
	}
}

/*
 * Store a new rule order. $rule_ids lists rule indexes in their new order;
 * $separators (or null) is the rules tab's separator list. Returns true
 * when the rule order changed.
 */
function reorderFilterRules($rule_ids, $separators, $if) {
	$updated = false;
	$dirty = false;
	/* update rule order, $rule_ids is an array of ordered IDs */
	if (is_array($rule_ids) && !empty($rule_ids)) {
		$dirty = set_filter_rules_order($rule_ids);
	}

	$a_separators = config_get_path('filter/separator/' . strtolower($if), []);

	/* update separator order, $separators is an array of ordered IDs */
	if (is_array($separators) && !empty($separators)) {
		$new_separator = array();
		$idx = 0;

		foreach ($separators as $separator) {
			$new_separator['sep' . $idx++] = $separator;
		}

		if ($a_separators !== $new_separator) {
			$a_separators = $new_separator;
			$updated = true;
		}
	} else if (!empty($a_separators)) {
		$a_separators = "";
		$updated = true;
	}

	if ($updated || $dirty) {
		config_set_path('filter/separator/' . strtolower($if), $a_separators);
		if (write_config(gettext("Firewall: Rules - reordered firewall rules."))) {
			if ($dirty) {
				mark_subsystem_dirty('filter');
			}
		}
	}
	return $dirty;
}

/*
 * Turn getFilterRule()'s fields into what the edit form would submit for
 * that rule unchanged: the source/destination type selectors chosen the
 * way the page preselects them, ticked checkboxes as "yes", TCP flags as
 * tcpflags1_<flag>/tcpflags2_<flag> = "on" and ICMP types as a list.
 * Used to edit a rule by changing only some fields (REST API).
 */
function filter_rule_form_fields($pconfig) {
	global $filter_srcdsttype_flags, $tcpflags;

	$checkboxes = array('disabled', 'log', 'srcnot', 'dstnot', 'allowopts', 'disablereplyto',
	    'nottagged', 'nopfsync', 'nosync', 'tcpflags_any', 'nat');
	$post = array();
	foreach ((array)$pconfig as $key => $value) {
		if (in_array($key, $checkboxes, true) || is_bool($value)) {
			if ($value) {
				$post[$key] = 'yes';
			}
		} elseif (($key === 'interface') && is_array($value)) {
			$post[$key] = array_values($value);
		} elseif (is_scalar($value)) {
			$post[$key] = (string)$value;
		}
	}

	foreach (array('src', 'dst') as $type) {
		$addr = (string)($pconfig[$type] ?? '');
		$mask = $pconfig[$type . 'mask'] ?? '';
		if (get_specialnet($addr, $filter_srcdsttype_flags)) {
			$post[$type . 'type'] = $addr;
		} elseif ((is_ipaddrv6($addr) && $mask == 128) || (is_ipaddrv4($addr) && $mask == 32) || is_alias($addr)) {
			$post[$type . 'type'] = 'single';
		} else {
			$post[$type . 'type'] = 'network';
		}
	}

	foreach (array('tcpflags1', 'tcpflags2') as $field) {
		if (!empty($pconfig[$field])) {
			foreach (explode(',', $pconfig[$field]) as $flag) {
				if (in_array($flag, $tcpflags, true)) {
					$post["{$field}_{$flag}"] = 'on';
				}
			}
		}
		unset($post[$field]);
	}

	if (!empty($pconfig['icmptype'])) {
		$post['icmptype'] = explode(',', $pconfig['icmptype']);
	}

	if (!isset($pconfig['floating'])) {
		unset($post['floating']);
	}
	unset($post['associated-rule-id']);
	return $post;
}
