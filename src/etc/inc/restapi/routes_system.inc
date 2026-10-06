<?php
/*
 * restapi/routes_system.inc
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
 * REST API v1 routes for System > Advanced (area system.settings): the
 * Firewall & NAT, Networking, Miscellaneous and Notifications tabs and the
 * System Tunables. Admin Access is not part of this area.
 *
 * Writes go through the functions the pages call
 * (system_advanced_{firewall,network,misc,notifications,sysctl}.inc), so the
 * validation is the GUI's. Like the pages, the four settings tabs apply at
 * once (filter reload, sysctls, services). Settings are flat objects of the
 * form fields with checkboxes as booleans; a PUT changes only the fields it
 * sends. Fields the page hides on this system (NAT reflection with a single
 * interface, Speed Shift, PTI and MDS without hardware support, if_pppoe
 * without the driver) are not offered either. Secrets (proxy password, SMTP
 * password, Telegram, Pushover and Slack keys) read as "(set)"; sending
 * "(set)" or leaving them out keeps them. The notification test buttons send
 * real messages and are not part of the API. Selects the save functions do
 * not check (optimization, bogon update frequency, NAT reflection mode, TFTP
 * proxy interfaces, state killing on gateway recovery, disk standby time)
 * must hold one of the page's options (422 otherwise).
 *
 * Tunables are addressed by name. Like the page, creating, changing and
 * deleting a tunable is pending until applied (POST
 * /v1/system/tunables/apply or ?apply=true); deleting a tunable does not reset
 * its running value until the next boot unless it is one of the system's
 * default tunables (then applying sets the default again).
 */

require_once('restapi/framework.inc');

function restapi_routes_system() {
	$area = 'system.settings';
	$w = function ($page, $summary, $body = null) use ($area) {
		return array('page' => $page, 'area' => $area, 'write' => true, 'summary' => $summary, 'body' => $body);
	};
	$fw_body = array('type' => 'object', 'description' => 'The Firewall & NAT form: scrubnodf, scrubrnid, disablescrub, ' .
	    'vpn_scrubnodf, vpn_fragment_reassemble, maxmss_enable, disablefilter, disable_auto_floating_states, bypassstaticroutes, ' .
	    'disablevpnrules, disablereplyto, disablenegate, no_apipa_block, checkaliasesurlcert, allow_nat64_prefix_override, ' .
	    'enablebinatreflection, enablenatreflectionhelper (booleans); optimization, statepolicy, bogonsinterval, natreflection ' .
	    '(from the *_choices), tftpinterface (list of interfaces), adaptivestart, adaptiveend, maximumstates, maximumtableentries, ' .
	    'maximumfrags, maxmss, aliasesresolveinterval, reflectiontimeout and the state timeouts (e.g. tcpfirsttimeout; empty: ' .
	    'default). NAT reflection fields exist only with more than one interface. Omitted fields keep their value. Saving reloads ' .
	    'the filter at once.');
	$net_body = array('type' => 'object', 'description' => 'The Networking form: radvddebug, dhcp6debug, dhcp6norelease, ipv6allow, ' .
	    'ipv6nat_enable, prefer_ipv4, ipv6dontcreatelocaldns, sharednet, ip_change_kill_states, use_if_pppoe (only with the if_pppoe ' .
	    'driver) (booleans); ipv6nat_ipaddr, global-v6duid (raw DUID, empty: automatic). Omitted fields keep their value. Applied at ' .
	    'once; reboot_required tells when a reboot is needed.');
	$misc_body = array('type' => 'object', 'description' => 'The Miscellaneous form: proxyurl, proxyport, proxyuser, proxypass ' .
	    '("(set)" keeps it), lb_use_sticky (boolean), srctrack, hwpstate (boolean), hwpstate_control_level, hwpstate_epp (Speed Shift, ' .
	    'only on supported hardware), powerd_enable (boolean), powerd_ac_mode, powerd_battery_mode, powerd_normal_mode, ' .
	    'crypto_hardware, thermal_hardware, pti_disabled (boolean, when the kernel has PTI), mds_disable (when the kernel has MDS ' .
	    'mitigation), schedule_states, keep_failover_states, skip_rules_gw_down, dpinger_dont_add_static_routes, use_mfs_tmpvar, ' .
	    'do_not_send_uniqueid (booleans), remove_failover_states_default, gw_down_kill_states, php_memory_limit, use_mfs_tmp_size, ' .
	    'use_mfs_var_size, rrdbackup, dhcpbackup, logsbackup, captiveportalbackup, harddiskstandby. Omitted fields keep their value. ' .
	    'Applied at once; reboot_required lists the changes that need a reboot.');
	$notif_body = array('type' => 'object', 'description' => 'The Notifications form: cert_enable_notify, revoked_cert_ignore_notify, ' .
	    'disable_smtp, smtpssl, sslvalidate, consolebell, disablebeep, enable_telegram, enable_pushover, enable_slack (booleans); ' .
	    'certexpiredays, smtpipaddress, smtpport, smtptimeout, smtpfromaddress, smtpnotifyemailaddress, smtpusername, smtppassword, ' .
	    'smtpauthmech, api (Telegram bot API key), chatid, pushoverapikey, pushoveruserkey, pushoversound, pushoverpriority, ' .
	    'pushoverretry, pushoverexpire, slack_api, slack_channel. Secrets read as "(set)"; "(set)" or leaving them out keeps them. ' .
	    'Omitted fields keep their value. Saved at once. Test messages cannot be sent through the API.');
	$tunable_body = array('type' => 'object', 'properties' => array(
	    'tunable' => array('type' => 'string', 'description' => 'sysctl name, e.g. net.inet.tcp.log_debug'),
	    'value' => array('type' => 'string', 'description' => 'Value (letters, digits, ".", "-", "_", "%", "/"; "default" for the system default)'),
	    'descr' => array('type' => 'string')));
	$apply_q = array('apply' => 'true: apply the tunables at once (otherwise the change is pending, like in the GUI)');
	$t_page = 'system_advanced_sysctl.php';

	return array(
		restapi_route('GET', '/v1/system/advanced/firewall', 'restapi_h_sysadv_fw_get', array(
			'page' => 'system_advanced_firewall.php', 'area' => $area,
			'summary' => 'Advanced Firewall & NAT settings')),
		restapi_route('PUT', '/v1/system/advanced/firewall', 'restapi_h_sysadv_fw_set',
			$w('system_advanced_firewall.php', 'Change advanced Firewall & NAT settings (applied at once; omitted fields keep their value)', $fw_body)),
		restapi_route('GET', '/v1/system/advanced/networking', 'restapi_h_sysadv_net_get', array(
			'page' => 'system_advanced_network.php', 'area' => $area,
			'summary' => 'Advanced Networking settings (DHCPv6 client, IPv6, ARP, PPPoE driver)')),
		restapi_route('PUT', '/v1/system/advanced/networking', 'restapi_h_sysadv_net_set',
			$w('system_advanced_network.php', 'Change advanced Networking settings (applied at once; omitted fields keep their value)', $net_body)),
		restapi_route('GET', '/v1/system/advanced/misc', 'restapi_h_sysadv_misc_get', array(
			'page' => 'system_advanced_misc.php', 'area' => $area,
			'summary' => 'Miscellaneous advanced settings (proxy password as "(set)")')),
		restapi_route('PUT', '/v1/system/advanced/misc', 'restapi_h_sysadv_misc_set',
			$w('system_advanced_misc.php', 'Change miscellaneous advanced settings (applied at once; omitted fields keep their value)', $misc_body)),
		restapi_route('GET', '/v1/system/advanced/notifications', 'restapi_h_sysadv_notif_get', array(
			'page' => 'system_advanced_notifications.php', 'area' => $area,
			'summary' => 'Notification settings (passwords and API keys as "(set)")')),
		restapi_route('PUT', '/v1/system/advanced/notifications', 'restapi_h_sysadv_notif_set',
			$w('system_advanced_notifications.php', 'Change notification settings (saved at once; omitted fields keep their value)', $notif_body)),

		restapi_route('GET', '/v1/system/tunables', 'restapi_h_tunables_list', array(
			'page' => $t_page, 'area' => $area,
			'summary' => 'System tunables: custom entries and the system defaults, with their running values')),
		restapi_route('POST', '/v1/system/tunables/apply', 'restapi_h_tunables_apply',
			$w($t_page, 'Apply the pending tunable changes')),
		restapi_route('GET', '/v1/system/tunables/{name}', 'restapi_h_tunables_get', array(
			'page' => $t_page, 'area' => $area, 'summary' => 'One tunable')),
		restapi_route('POST', '/v1/system/tunables', 'restapi_h_tunables_create',
			$w($t_page, 'Add a custom tunable (pending until applied)', $tunable_body) + array('query' => $apply_q)),
		restapi_route('PUT', '/v1/system/tunables/{name}', 'restapi_h_tunables_update',
			$w($t_page, 'Change a tunable; changing a system default adds a custom entry, like the GUI (pending until applied)', $tunable_body) +
			array('query' => $apply_q)),
		restapi_route('DELETE', '/v1/system/tunables/{name}', 'restapi_h_tunables_delete',
			$w($t_page, 'Delete a custom tunable (pending until applied)') + array('query' => $apply_q)),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* The NAT reflection mode the Firewall & NAT page preselects. */
function restapi_sysadv_natreflection(array $system) {
	if (isset($system['disablenatreflection'])) {
		return 'disable';
	}
	return isset($system['enablenatreflectionpurenat']) ? 'purenat' : 'proxy';
}

/*
 * The state timeout fields of the Firewall & NAT page: every timeout pfctl
 * reports, group by group, up to and including the "Other" group (the page
 * stops there; fragments, interval and adaptive are not state timeouts).
 */
function restapi_sysadv_timeout_keys(array $pftimeouts) {
	$keys = array();
	foreach ($pftimeouts as $tm) {
		$item = null;
		foreach ($tm as $item) {
			$keys[] = $item['keyname'];
		}
		if (is_array($item) && ($item['keyname'] == 'othermultipletimeout')) {
			break;
		}
	}
	return $keys;
}

/* Replace secret values with "(set)" (or "" when empty). */
function restapi_sysadv_mask(array $values, array $secrets) {
	foreach ($secrets as $secret) {
		if (array_key_exists($secret, $values)) {
			$values[$secret] = ((string)$values[$secret] !== '') ? '(set)' : '';
		}
	}
	return $values;
}

/* A body without the secrets sent as "(set)" (they keep their value). */
function restapi_sysadv_keep_secrets(array $body, array $secrets) {
	foreach ($secrets as $secret) {
		if (($body[$secret] ?? null) === '(set)') {
			unset($body[$secret]);
		}
	}
	return $body;
}

/*
 * A password field of the GUI's addPassword() pair: an unchanged stored
 * password posts the page's placeholder, a new one is posted twice.
 */
function restapi_sysadv_password_post($field, $current, array $body) {
	if (array_key_exists($field, $body)) {
		$value = (string)$body[$field];
	} else {
		$value = ((string)$current !== '') ? DMYPWD : '';
	}
	return array($field => $value, "{$field}_confirm" => $value);
}

/*
 * Select fields must hold one of the page's options. The save functions do not
 * check every select (a browser cannot post anything else), so the API does.
 */
function restapi_sysadv_check_choices(array $values, array $choices, array $fields) {
	$errors = array();
	foreach ($fields as $field) {
		if (!array_key_exists($field, $values) || !isset($choices[$field])) {
			continue;
		}
		$keys = array_map('strval', array_keys($choices[$field]));
		foreach ((array)$values[$field] as $value) {
			if (!in_array((string)$value, $keys, true)) {
				$errors[] = sprintf('"%s" is not a valid choice for %s.', $value, $field);
			}
		}
	}
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
}

/* A stored tunable as the API shows it (names and values are stored HTML encoded). */
function restapi_tunable_out(array $t, $running = null) {
	$out = array(
		'tunable' => htmlspecialchars_decode((string)($t['tunable'] ?? '')),
		'value' => htmlspecialchars_decode((string)($t['value'] ?? '')),
		'descr' => (string)($t['descr'] ?? ''),
		'custom' => !empty($t['modified']),
	);
	if ($running !== null) {
		$out['running_value'] = $running;
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* Firewall & NAT                                                      */
/* ------------------------------------------------------------------ */

function restapi_sysadv_fw_choices() {
	global $state_policy_options;

	$choices = array(
		'optimization' => array('normal' => 'Normal', 'high-latency' => gettext('High-latency'),
		    'aggressive' => gettext('Aggressive'), 'conservative' => gettext('Conservative')),
		'statepolicy' => $state_policy_options,
		'bogonsinterval' => array('monthly' => gettext('Monthly'), 'weekly' => gettext('Weekly'), 'daily' => gettext('Daily')),
	);
	if (count(config_get_path('interfaces', [])) > 1) {
		$choices['natreflection'] = array('disable' => gettext('disabled'), 'proxy' => gettext('NAT + Proxy'),
		    'purenat' => gettext('Pure NAT'));
		$choices['tftpinterface'] = get_configured_interface_with_descr();
	}
	return $choices;
}

/* Field types of the form on this system. */
function restapi_sysadv_fw_types() {
	$types = array();
	foreach (array('scrubnodf', 'scrubrnid', 'disablescrub', 'vpn_scrubnodf', 'vpn_fragment_reassemble', 'maxmss_enable',
	    'disablefilter', 'disable_auto_floating_states', 'bypassstaticroutes', 'disablevpnrules', 'disablereplyto', 'disablenegate',
	    'no_apipa_block', 'checkaliasesurlcert', 'allow_nat64_prefix_override') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('optimization', 'adaptivestart', 'adaptiveend', 'maximumstates', 'maximumtableentries', 'maximumfrags',
	    'maxmss', 'statepolicy', 'aliasesresolveinterval', 'bogonsinterval') as $field) {
		$types[$field] = 'string';
	}
	if (count(config_get_path('interfaces', [])) > 1) {
		$types += array('natreflection' => 'string', 'reflectiontimeout' => 'string', 'enablebinatreflection' => 'bool',
		    'enablenatreflectionhelper' => 'bool', 'tftpinterface' => 'list');
	}
	foreach (restapi_sysadv_timeout_keys(get_pf_timeouts()) as $key) {
		$types[$key] = 'string';
	}
	$types['choices'] = 'ro';
	$types['timeout_defaults'] = 'ro';
	return $types;
}

/* The settings as the page's form shows them. */
function restapi_sysadv_fw_settings() {
	$sys = config_get_path('system', []);
	$p = getSystemAdvancedFirewall();
	$choices = restapi_sysadv_fw_choices();
	$out = array(
		'scrubnodf' => isset($sys['scrubnodf']),
		'scrubrnid' => isset($sys['scrubrnid']),
		'optimization' => restapi_select_choice($sys['optimization'] ?? '', $choices['optimization']),
		'disablescrub' => isset($sys['disablescrub']),
		'adaptivestart' => (string)$p['adaptivestart'],
		'adaptiveend' => (string)$p['adaptiveend'],
		'maximumstates' => (string)$p['maximumstates'],
		'maximumtableentries' => (string)$p['maximumtableentries'],
		'maximumfrags' => (string)$p['maximumfrags'],
		'vpn_scrubnodf' => (bool)$p['vpn_scrubnodf'],
		'vpn_fragment_reassemble' => (bool)$p['vpn_fragment_reassemble'],
		'maxmss_enable' => (bool)$p['maxmss_enable'],
		'maxmss' => (string)$p['maxmss'],
		'disablefilter' => isset($sys['disablefilter']),
		'statepolicy' => restapi_select_choice($p['statepolicy'] ?? '', $choices['statepolicy']),
		'disable_auto_floating_states' => isset($p['disable_auto_floating_states']),
		'bypassstaticroutes' => (bool)$p['bypassstaticroutes'],
		'disablevpnrules' => isset($sys['disablevpnrules']),
		'disablereplyto' => (bool)$p['disablereplyto'],
		'disablenegate' => (bool)$p['disablenegate'],
		'no_apipa_block' => (bool)$p['no_apipa_block'],
		'aliasesresolveinterval' => (string)$p['aliasesresolveinterval'],
		'checkaliasesurlcert' => (bool)$p['checkaliasesurlcert'],
		'bogonsinterval' => restapi_select_choice(empty($p['bogonsinterval']) ? 'monthly' : $p['bogonsinterval'], $choices['bogonsinterval']),
		'allow_nat64_prefix_override' => (bool)$p['allow_nat64_prefix_override'],
	);
	if (isset($choices['natreflection'])) {
		$out['natreflection'] = restapi_sysadv_natreflection($sys);
		$out['reflectiontimeout'] = (string)($sys['reflectiontimeout'] ?? '');
		$out['enablebinatreflection'] = isset($sys['enablebinatreflection']);
		$out['enablenatreflectionhelper'] = isset($sys['enablenatreflectionhelper']);
		/* Only interfaces the select offers are posted by a browser. */
		$out['tftpinterface'] = array_values(array_filter(array_map('strval', (array)$p['tftpinterface']), function ($ifn) use ($choices) {
			return ($ifn !== '') && array_key_exists($ifn, $choices['tftpinterface']);
		}));
	}
	foreach (restapi_sysadv_timeout_keys(get_pf_timeouts()) as $key) {
		$out[$key] = (string)($sys[$key] ?? '');
	}
	return $out;
}

function restapi_sysadv_fw_out(array $settings) {
	$defaults = array();
	foreach (get_pf_timeouts() as $tm) {
		foreach ($tm as $item) {
			$defaults[$item['keyname']] = $item['value'];
		}
	}
	$settings['timeout_defaults'] = array_intersect_key($defaults, array_flip(restapi_sysadv_timeout_keys(get_pf_timeouts())));
	$settings['choices'] = array_map('restapi_svc_choices', restapi_sysadv_fw_choices());
	return $settings;
}

function restapi_h_sysadv_fw_get($req) {
	return array('data' => restapi_sysadv_fw_out(restapi_sysadv_fw_settings()));
}

function restapi_h_sysadv_fw_set($req) {
	$types = restapi_sysadv_fw_types();
	$values = restapi_svc_merge($req['body'], restapi_sysadv_fw_settings(), $types);
	restapi_sysadv_check_choices($values, restapi_sysadv_fw_choices(), array('optimization', 'bogonsinterval', 'natreflection',
	    'tftpinterface'));
	$rv = restapi_json_result(saveSystemAdvancedFirewall(restapi_svc_post($values, $types), true));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_sysadv_fw_out(restapi_sysadv_fw_settings()) + array('applied' => ($rv['retval'] == 0)));
}

/* ------------------------------------------------------------------ */
/* Networking                                                          */
/* ------------------------------------------------------------------ */

function restapi_sysadv_net_types() {
	$types = array('ipv6nat_ipaddr' => 'string', 'global-v6duid' => 'string', 'duid' => 'ro');
	foreach (array('radvddebug', 'dhcp6debug', 'dhcp6norelease', 'ipv6allow', 'ipv6nat_enable', 'prefer_ipv4',
	    'ipv6dontcreatelocaldns', 'sharednet', 'ip_change_kill_states') as $flag) {
		$types[$flag] = 'bool';
	}
	if (if_pppoe_available()) {
		$types['use_if_pppoe'] = 'bool';
	}
	return $types;
}

function restapi_sysadv_net_settings() {
	$p = getAdvancedNetwork();
	$out = array(
		'radvddebug' => (bool)$p['radvddebug'],
		'dhcp6debug' => (bool)$p['dhcp6debug'],
		'dhcp6norelease' => (bool)$p['dhcp6norelease'],
		'global-v6duid' => (string)$p['global-v6duid'],
		'ipv6allow' => (bool)$p['ipv6allow'],
		'ipv6nat_enable' => (bool)$p['ipv6nat_enable'],
		'ipv6nat_ipaddr' => (string)$p['ipv6nat_ipaddr'],
		'prefer_ipv4' => (bool)$p['prefer_ipv4'],
		'ipv6dontcreatelocaldns' => (bool)$p['ipv6dontcreatelocaldns'],
		'sharednet' => isset($p['sharednet']),
		'ip_change_kill_states' => isset($p['ip_change_kill_states']),
	);
	if (if_pppoe_available()) {
		$out['use_if_pppoe'] = (bool)$p['use_if_pppoe'];
	}
	return $out;
}

function restapi_h_sysadv_net_get($req) {
	return array('data' => restapi_sysadv_net_settings() + array('duid' => (string)get_duid_from_file()));
}

function restapi_h_sysadv_net_set($req) {
	$types = restapi_sysadv_net_types();
	$values = restapi_svc_merge($req['body'], restapi_sysadv_net_settings(), $types);
	/* The raw DUID type: the page's other DUID fields only build global-v6duid. */
	$rv = restapi_json_result(saveAdvancedNetworking(restapi_svc_post($values, $types) + array('ipv6duidtype' => '0'), true));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_sysadv_net_settings() + array('duid' => (string)get_duid_from_file(),
	    'applied' => ($rv['retval'] == 0), 'reboot_required' => !empty($rv['show_reboot_msg'])));
}

/* ------------------------------------------------------------------ */
/* Miscellaneous                                                       */
/* ------------------------------------------------------------------ */

function restapi_sysadv_misc_choices() {
	global $hwpstate_control_levels, $global_gateway_state_kill_modes;

	$powerd_modes = array('hadp' => gettext('Hiadaptive'), 'adp' => gettext('Adaptive'), 'min' => gettext('Minimum'),
	    'max' => gettext('Maximum'));
	$choices = array(
		'powerd_ac_mode' => $powerd_modes,
		'powerd_battery_mode' => $powerd_modes,
		'powerd_normal_mode' => $powerd_modes,
		'crypto_hardware' => array('' => gettext('None')) + getSystemAdvancedMiscCryptoModules(),
		'thermal_hardware' => array('' => 'None/ACPI', 'coretemp' => gettext("Intel Core* CPU on-die thermal sensor"),
		    'amdtemp' => gettext("AMD K8, K10 and K11 CPU on-die thermal sensor")),
		'remove_failover_states_default' => array('' => 'Don\'t kill states from the firewall itself',
		    'all' => 'Kill all states for lower-priority gateways',
		    'policy' => 'Only kill policy routing states for lower-priority gateways',
		    'addrfamily' => 'Only kill states with the same address family as the gateway group'),
		'gw_down_kill_states' => $global_gateway_state_kill_modes,
		/* The page's options as it renders them (values in the page's units, labels as shown). */
		'harddiskstandby' => array('' => gettext('Always on'), '0.5' => '6', '1' => '12', '2' => '24', '3' => '36', '4' => '48',
		    '5' => '60', '7.5' => '90', '10' => '120', '15' => '180', '20' => '240', '30' => '241', '60' => '242'),
	);
	if (system_has_hwpstate() !== false) {
		$choices['hwpstate_control_level'] = $hwpstate_control_levels;
	}
	if (strlen((string)get_single_sysctl('hw.mds_disable_state')) > 0) {
		$choices['mds_disable'] = array('' => gettext('Default'), 0 => gettext('Mitigation disabled'),
		    1 => gettext('VERW instruction (microcode) mitigation enabled'),
		    2 => gettext('Software sequence mitigation enabled (not recommended)'),
		    3 => gettext('Automatic VERW or Software selection'));
	}
	return $choices;
}

function restapi_sysadv_misc_types() {
	$choices = restapi_sysadv_misc_choices();
	$types = array('choices' => 'ro', 'status' => 'ro');
	foreach (array('proxyurl', 'proxyport', 'proxyuser', 'proxypass', 'srctrack', 'powerd_ac_mode', 'powerd_battery_mode',
	    'powerd_normal_mode', 'crypto_hardware', 'thermal_hardware', 'remove_failover_states_default', 'gw_down_kill_states',
	    'php_memory_limit', 'use_mfs_tmp_size', 'use_mfs_var_size', 'rrdbackup', 'dhcpbackup', 'logsbackup',
	    'captiveportalbackup', 'harddiskstandby') as $field) {
		$types[$field] = 'string';
	}
	foreach (array('lb_use_sticky', 'powerd_enable', 'schedule_states', 'keep_failover_states', 'skip_rules_gw_down',
	    'dpinger_dont_add_static_routes', 'use_mfs_tmpvar', 'do_not_send_uniqueid') as $flag) {
		$types[$flag] = 'bool';
	}
	if (isset($choices['hwpstate_control_level'])) {
		$types += array('hwpstate' => 'bool', 'hwpstate_control_level' => 'string', 'hwpstate_epp' => 'string');
	}
	if (strlen((string)get_single_sysctl('vm.pmap.pti')) > 0) {
		$types['pti_disabled'] = 'bool';
	}
	if (isset($choices['mds_disable'])) {
		$types['mds_disable'] = 'string';
	}
	return $types;
}

/* The settings as the page's form shows them, with the proxy password in clear (never returned). */
function restapi_sysadv_misc_settings() {
	$p = getSystemAdvancedMisc();
	$choices = restapi_sysadv_misc_choices();
	$types = restapi_sysadv_misc_types();
	$out = array(
		'proxyurl' => (string)$p['proxyurl'],
		'proxyport' => (string)$p['proxyport'],
		'proxyuser' => (string)$p['proxyuser'],
		'proxypass' => (string)$p['proxypass'],
		'lb_use_sticky' => (bool)$p['lb_use_sticky'],
		'srctrack' => (string)$p['srctrack'],
		'powerd_enable' => (bool)$p['powerd_enable'],
		'powerd_ac_mode' => restapi_select_choice($p['powerd_ac_mode'], $choices['powerd_ac_mode']),
		'powerd_battery_mode' => restapi_select_choice($p['powerd_battery_mode'], $choices['powerd_battery_mode']),
		'powerd_normal_mode' => restapi_select_choice($p['powerd_normal_mode'], $choices['powerd_normal_mode']),
		'crypto_hardware' => restapi_select_choice($p['crypto_hardware'] ?? '', $choices['crypto_hardware']),
		'thermal_hardware' => restapi_select_choice($p['thermal_hardware'] ?? '', $choices['thermal_hardware']),
		'schedule_states' => (bool)$p['schedule_states'],
		'remove_failover_states_default' => restapi_select_choice($p['remove_failover_states_default'] ?? '',
		    $choices['remove_failover_states_default']),
		'keep_failover_states' => (bool)$p['keep_failover_states'],
		'gw_down_kill_states' => restapi_select_choice($p['gw_down_kill_states'] ?? '', $choices['gw_down_kill_states']),
		'skip_rules_gw_down' => (bool)$p['skip_rules_gw_down'],
		'dpinger_dont_add_static_routes' => (bool)$p['dpinger_dont_add_static_routes'],
		'php_memory_limit' => str_replace('M', '', (string)$p['php_memory_limit']),
		'use_mfs_tmpvar' => (bool)$p['use_mfs_tmpvar'],
		'use_mfs_tmp_size' => (string)$p['use_mfs_tmp_size'],
		'use_mfs_var_size' => (string)$p['use_mfs_var_size'],
		'rrdbackup' => (string)config_get_path('system/rrdbackup', ''),
		'dhcpbackup' => (string)config_get_path('system/dhcpbackup', ''),
		'logsbackup' => (string)config_get_path('system/logsbackup', ''),
		'captiveportalbackup' => (string)config_get_path('system/captiveportalbackup', ''),
		'harddiskstandby' => restapi_select_choice($p['harddiskstandby'] ?? '', $choices['harddiskstandby']),
		'do_not_send_uniqueid' => (bool)$p['do_not_send_uniqueid'],
	);
	if (isset($types['hwpstate'])) {
		$out['hwpstate'] = (bool)$p['hwpstate'];
		$out['hwpstate_control_level'] = restapi_select_choice($p['hwpstate_control_level'], $choices['hwpstate_control_level']);
		$out['hwpstate_epp'] = (string)$p['hwpstate_epp'];
	}
	if (isset($types['pti_disabled'])) {
		$out['pti_disabled'] = (bool)$p['pti_disabled'];
	}
	if (isset($types['mds_disable'])) {
		$out['mds_disable'] = restapi_select_choice($p['mds_disable'] ?? '', $choices['mds_disable']);
	}
	return $out;
}

function restapi_sysadv_misc_out(array $settings) {
	global $hwpstate_status_descr;

	$settings = restapi_sysadv_mask($settings, array('proxypass'));
	$hwpstate = system_has_hwpstate();
	$settings['status'] = array(
		'pti' => (string)get_single_sysctl('vm.pmap.pti'),
		'mds' => (string)get_single_sysctl('hw.mds_disable_state'),
		'speed_shift' => ($hwpstate === false) ? null : ($hwpstate_status_descr[$hwpstate] ?? null),
	);
	$settings['choices'] = array_map('restapi_svc_choices', restapi_sysadv_misc_choices());
	return $settings;
}

function restapi_h_sysadv_misc_get($req) {
	return array('data' => restapi_sysadv_misc_out(restapi_sysadv_misc_settings()));
}

function restapi_h_sysadv_misc_set($req) {
	$types = restapi_sysadv_misc_types();
	$current = restapi_sysadv_misc_settings();
	$body = restapi_sysadv_keep_secrets($req['body'], array('proxypass'));
	$values = restapi_svc_merge($body, $current, $types);
	restapi_sysadv_check_choices($values, restapi_sysadv_misc_choices(), array('remove_failover_states_default', 'harddiskstandby'));
	unset($values['proxypass']);
	$post = restapi_svc_post($values, $types) + restapi_sysadv_password_post('proxypass', $current['proxypass'], $body);
	$rv = saveSystemAdvancedMisc($post);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$reboot = array();
	if (!empty($rv['reboot-ramdisks'])) {
		$reboot[] = 'ramdisks';
	}
	if (!empty($rv['reboot-hwpstate'])) {
		$reboot[] = 'speed_shift';
	}
	return array('data' => restapi_sysadv_misc_out(restapi_sysadv_misc_settings()) +
	    array('applied' => ($rv['retval'] == 0), 'reboot_required' => $reboot));
}

/* ------------------------------------------------------------------ */
/* Notifications                                                       */
/* ------------------------------------------------------------------ */

function restapi_sysadv_notif_secrets() {
	return array('smtppassword', 'api', 'pushoverapikey', 'pushoveruserkey', 'slack_api');
}

function restapi_sysadv_notif_choices() {
	global $smtp_authentication_mechanisms, $pushover_sounds;

	return array(
		'smtpauthmech' => $smtp_authentication_mechanisms,
		'pushoversound' => $pushover_sounds,
		'pushoverpriority' => array("0" => "Normal Priority", "-2" => "Lowest Priority - No sound or notification",
		    "-1" => "Low Priority - No sound or vibration", "1" => "High Priority - Always play sound and vibrate",
		    "2" => "Emergency Priority - Repeats notification until acknowledged"),
	);
}

function restapi_sysadv_notif_types() {
	$types = array('choices' => 'ro');
	foreach (array('cert_enable_notify', 'revoked_cert_ignore_notify', 'disable_smtp', 'smtpssl', 'sslvalidate', 'consolebell',
	    'disablebeep', 'enable_telegram', 'enable_pushover', 'enable_slack') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('certexpiredays', 'smtpipaddress', 'smtpport', 'smtptimeout', 'smtpfromaddress', 'smtpnotifyemailaddress',
	    'smtpusername', 'smtppassword', 'smtpauthmech', 'api', 'chatid', 'pushoverapikey', 'pushoveruserkey', 'pushoversound',
	    'pushoverpriority', 'pushoverretry', 'pushoverexpire', 'slack_api', 'slack_channel') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/* The settings as the page's form shows them, with the secrets in clear (never returned). */
function restapi_sysadv_notif_settings() {
	$p = getAdvancedNotifications();
	$choices = restapi_sysadv_notif_choices();
	$s = function ($key) use ($p) {
		return (string)($p[$key] ?? '');
	};
	return array(
		'cert_enable_notify' => (bool)$p['cert_enable_notify'],
		'revoked_cert_ignore_notify' => (bool)$p['revoked_cert_ignore_notify'],
		'certexpiredays' => $s('certexpiredays'),
		'disable_smtp' => (bool)$p['disable_smtp'],
		'smtpipaddress' => $s('smtpipaddress'),
		'smtpport' => $s('smtpport'),
		'smtptimeout' => $s('smtptimeout'),
		'smtpssl' => (bool)$p['smtpssl'],
		'sslvalidate' => (bool)$p['sslvalidate'],
		'smtpfromaddress' => $s('smtpfromaddress'),
		'smtpnotifyemailaddress' => $s('smtpnotifyemailaddress'),
		'smtpusername' => $s('smtpusername'),
		'smtppassword' => $s('smtppassword'),
		'smtpauthmech' => restapi_select_choice($s('smtpauthmech'), $choices['smtpauthmech']),
		'consolebell' => ($p['consolebell'] == 'enabled'),
		'disablebeep' => (bool)$p['disablebeep'],
		'enable_telegram' => (bool)$p['enable_telegram'],
		'api' => $s('api'),
		'chatid' => $s('chatid'),
		'enable_pushover' => (bool)$p['enable_pushover'],
		'pushoverapikey' => $s('pushoverapikey'),
		'pushoveruserkey' => $s('pushoveruserkey'),
		'pushoversound' => restapi_select_choice($s('pushoversound'), $choices['pushoversound']),
		'pushoverpriority' => restapi_select_choice($s('pushoverpriority'), $choices['pushoverpriority']),
		'pushoverretry' => $s('pushoverretry'),
		'pushoverexpire' => $s('pushoverexpire'),
		'enable_slack' => (bool)$p['enable_slack'],
		'slack_api' => $s('slack_api'),
		'slack_channel' => $s('slack_channel'),
	);
}

function restapi_sysadv_notif_out(array $settings) {
	$settings = restapi_sysadv_mask($settings, restapi_sysadv_notif_secrets());
	$settings['choices'] = array_map('restapi_svc_choices', restapi_sysadv_notif_choices());
	return $settings;
}

function restapi_h_sysadv_notif_get($req) {
	return array('data' => restapi_sysadv_notif_out(restapi_sysadv_notif_settings()));
}

function restapi_h_sysadv_notif_set($req) {
	$types = restapi_sysadv_notif_types();
	$current = restapi_sysadv_notif_settings();
	$body = restapi_sysadv_keep_secrets($req['body'], restapi_sysadv_notif_secrets());
	$values = restapi_svc_merge($body, $current, $types);
	unset($values['smtppassword']);
	/*
	 * Only the fields of the form and its Save button: the test buttons
	 * (test-smtp, ...) send messages and are never posted.
	 */
	$post = restapi_svc_post($values, $types) + restapi_sysadv_password_post('smtppassword', $current['smtppassword'], $body) +
	    array('save' => 'Save');
	$rv = restapi_json_result(saveAdvancedNotifications($post, true));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_sysadv_notif_out(restapi_sysadv_notif_settings()));
}

/* ------------------------------------------------------------------ */
/* System tunables                                                     */
/* ------------------------------------------------------------------ */

/* Running values of the given sysctls (one sysctl call). */
function restapi_tunables_running(array $names) {
	$names = array_values(array_unique(array_filter($names, function ($n) {
		return preg_match('/^[A-Za-z0-9._%-]+$/D', $n) && ($n[0] !== '-');
	})));
	return empty($names) ? array() : (array)get_sysctl($names);
}

/* The page's list (custom entries and the remaining system defaults) as API entries, keyed by name. */
function restapi_tunables_all() {
	$list = getTunables();
	$entries = array();
	foreach ($list as $t) {
		$entry = restapi_tunable_out($t);
		$entries[$entry['tunable']] = $entry;
	}
	$running = restapi_tunables_running(array_keys($entries));
	foreach ($entries as $name => &$entry) {
		$entry['running_value'] = $running[$name] ?? null;
		if ($entry['value'] === 'default') {
			$entry['default_value'] = get_default_sysctl_value($name);
		}
	}
	unset($entry);
	return $entries;
}

function restapi_tunables_get($name) {
	$all = restapi_tunables_all();
	if (!isset($all[$name])) {
		throw new RestApiError(404, 'not_found', 'No tunable with that name.');
	}
	return $all[$name];
}

/* Apply the tunables like the page's Apply button. */
function restapi_tunables_do_apply() {
	system_setup_sysctl();
	clear_subsystem_dirty('sysctl');
}

function restapi_tunables_result($entry, $req) {
	if (restapi_want_apply($req)) {
		restapi_tunables_do_apply();
	}
	return array('tunable' => $entry, 'pending' => is_subsystem_dirty('sysctl'));
}

function restapi_tunables_body(array $body, array $current) {
	foreach ($body as $key => $value) {
		if (!in_array($key, array('tunable', 'value', 'descr'), true)) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
		if (!is_scalar($value)) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a string.");
		}
		$current[$key] = (string)$value;
	}
	return $current;
}

function restapi_tunables_save(array $post, $id) {
	$rv = saveTunable($post, $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
}

function restapi_h_tunables_list($req) {
	return array('data' => array('tunables' => array_values(restapi_tunables_all()), 'pending' => is_subsystem_dirty('sysctl')));
}

function restapi_h_tunables_get($req) {
	return array('data' => restapi_tunables_get($req['params']['name']));
}

function restapi_h_tunables_create($req) {
	$post = restapi_tunables_body($req['body'], array('tunable' => '', 'value' => '', 'descr' => ''));
	if (($post['tunable'] !== '') && (findTunable($post['tunable']) !== null)) {
		throw new RestApiError(409, 'exists', 'A custom tunable with this name already exists; change it with PUT.');
	}
	restapi_tunables_save($post, null);
	return array('status' => 201, 'data' => restapi_tunables_result(restapi_tunables_get($post['tunable']), $req));
}

function restapi_h_tunables_update($req) {
	$name = $req['params']['name'];
	$current = restapi_tunables_get($name);
	sortTunables();
	$idx = findTunable($name);
	/* Like the page: a system default is edited by its name, which adds a custom entry. */
	$id = ($idx === null) ? $name : $idx;
	$post = restapi_tunables_body($req['body'], array('tunable' => $current['tunable'], 'value' => $current['value'],
	    'descr' => $current['descr']));
	restapi_tunables_save($post, $id);
	return array('data' => restapi_tunables_result(restapi_tunables_get($post['tunable']), $req));
}

function restapi_h_tunables_delete($req) {
	$name = $req['params']['name'];
	sortTunables();
	$idx = findTunable($name);
	if ($idx === null) {
		throw new RestApiError(404, 'not_found', 'No custom tunable with that name (system defaults cannot be deleted).');
	}
	$apply = restapi_want_apply($req);
	if (!deleteTunable($idx, $apply, false)) {
		throw new RestApiError(404, 'not_found', 'No custom tunable with that name.');
	}
	if ($apply) {
		clear_subsystem_dirty('sysctl');
	}
	return array('data' => array('deleted' => $name, 'pending' => is_subsystem_dirty('sysctl')));
}

function restapi_h_tunables_apply($req) {
	restapi_tunables_do_apply();
	return array('data' => array('applied' => true, 'pending' => is_subsystem_dirty('sysctl')));
}
