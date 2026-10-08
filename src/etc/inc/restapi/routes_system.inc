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
 * REST API v1 routes for the system settings (area system.settings): System >
 * General Setup, High Availability, Update Settings and System > Advanced
 * (Admin Access, Firewall & NAT, Networking, Miscellaneous, Notifications
 * and the System Tunables).
 *
 * General Setup, High Availability, Update Settings and Admin Access save
 * through the pages' functions (system_general.inc, system_hasync.inc,
 * system_update_settings.inc, system_advanced_admin.inc) and apply at once
 * like the pages. The High Availability sync password reads as "(set)".
 * Update Settings only selects the firmware branch (from the page's list) and
 * the dashboard check: the GitSync fields (repository URL and branch, synced
 * on the next upgrade) are neither returned nor accepted. Admin Access
 * changes that can lock users out (webConfigurator protocol or port, the
 * anti-lockout rule, the DNS rebind and HTTP_REFERER checks, disabling SSH,
 * the SSH port or key mode) need {"confirm": true}; the error and the answer
 * name the address the webConfigurator and the API will be at. A port another
 * service listens on is refused. When the save needs a webConfigurator or
 * sshd restart, it runs a few seconds after the answer has been sent
 * ("restart" in the answer); the page restarts them after rendering.
 *
 * The other System > Advanced tabs write through the functions their pages
 * call (system_advanced_{firewall,network,misc,notifications,sysctl}.inc), so the
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
	    'mitigation), schedule_states, keep_failover_states, skip_rules_gw_down, dpinger_dont_add_static_routes, use_mfs_tmpvar ' .
	    '(booleans), remove_failover_states_default, gw_down_kill_states, php_memory_limit, use_mfs_tmp_size, ' .
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

	$gen_body = array('type' => 'object', 'description' => 'The General Setup form: hostname, domain, dnsservers (list of {address, ' .
	    'hostname (for TLS verification), gateway (only with more than one gateway of an address family; "none")}), dnslocalhost, ' .
	    'timezone, language, webguicss, webguifixedmenu, webguihostnamemenu, logincss (from the choices), timeservers (space ' .
	    'separated), dashboardcolumns (1-6), login_message, dnsallowoverride, interfacessort, dashboardavailablewidgetspanel, ' .
	    'systemlogsfilterpanel, systemlogsmanagelogpanel, statusmonitoringsettingspanel, requirestatefilter, ' .
	    'requirefirewallinterface, webguileftcolumnhyper, disablealiaspopupdetail, roworderdragging, loginshowhost (booleans). ' .
	    'Omitted fields keep their value. Applied at once (hostname, DNS, timezone, NTP, filter).');
	$hasync_body = array('type' => 'object', 'description' => 'The High Availability form: pfsyncenabled, synctlsinsecure, adminsync ' .
	    'and the synchronize* options (booleans; synchronizekea6 only with the Kea DHCP backend), pfsyncinterface (from the ' .
	    'choices), pfhostid (hex, up to 8 digits), pfsyncpeerip, synchronizetoip, username, password ("(set)" or leaving it ' .
	    'out keeps it). Omitted fields keep their value. Applied at once.');
	$update_body = array('type' => 'object', 'properties' => array(
	    'fwbranch' => array('type' => 'string', 'description' => 'Firmware branch, one of choices.fwbranch (switches the package repository)'),
	    'disablecheck' => array('type' => 'boolean', 'description' => 'Disable the dashboard update check')),
	    'description' => 'GitSync settings are not available through the API.');
	$admin_body = array('type' => 'object', 'description' => 'The Admin Access form: webguiproto (http|https), ssl-certref, ' .
	    'webguiport, max_procs, althostnames (space separated), sshdkeyonly (disabled|enabled|both), sshport, sshguard_threshold, ' .
	    'sshguard_blocktime, sshguard_detection_time, sshguard_whitelist (list of subnets), serialspeed, primaryconsole ' .
	    '(serial|video); webgui-redirect, webgui-hsts, ocsp-staple, loginautocomplete, webgui-login-messages, roaming, ' .
	    'noantilockout, nodnsrebindcheck, nohttpreferercheck, pagenamefirst, enablesshd, sshdagentforwarding, enableserial, ' .
	    'disableconsolemenu (booleans); confirm (boolean). Changing webguiproto, webguiport, nodnsrebindcheck, ' .
	    'nohttpreferercheck, sshport or sshdkeyonly, setting noantilockout or clearing enablesshd needs "confirm": true. ' .
	    'Omitted fields keep their value. Applied at once; a needed webConfigurator or sshd restart runs a few seconds ' .
	    'after the answer ("restart").');

	return array(
		restapi_route('GET', '/v1/system/general', 'restapi_h_sysgen_get', array(
			'page' => 'system.php', 'area' => $area,
			'summary' => 'General Setup (hostname, domain, DNS servers, timezone, NTP servers, language, webConfigurator preferences)')),
		restapi_route('PUT', '/v1/system/general', 'restapi_h_sysgen_set',
			$w('system.php', 'Change General Setup (applied at once; omitted fields keep their value)', $gen_body)),
		restapi_route('GET', '/v1/system/hasync', 'restapi_h_hasync_get', array(
			'page' => 'system_hasync.php', 'area' => $area,
			'summary' => 'High Availability sync settings (password as "(set)")')),
		restapi_route('PUT', '/v1/system/hasync', 'restapi_h_hasync_set',
			$w('system_hasync.php', 'Change High Availability sync settings (applied at once; omitted fields keep their value)', $hasync_body)),
		restapi_route('GET', '/v1/system/update-settings', 'restapi_h_update_settings_get', array(
			'page' => 'system_update_settings.php', 'area' => $area,
			'summary' => 'Firmware branch and dashboard update check (GitSync is not available through the API)')),
		restapi_route('PUT', '/v1/system/update-settings', 'restapi_h_update_settings_set',
			$w('system_update_settings.php', 'Select the firmware branch or change the dashboard update check', $update_body)),
		restapi_route('GET', '/v1/system/advanced/admin', 'restapi_h_admin_get', array(
			'page' => 'system_advanced_admin.php', 'area' => $area,
			'summary' => 'Admin Access settings (webConfigurator, SSH, login protection, console)')),
		restapi_route('PUT', '/v1/system/advanced/admin', 'restapi_h_admin_set',
			$w('system_advanced_admin.php', 'Change Admin Access settings (lock-out risks need "confirm": true; restarts run after the answer)',
			    $admin_body)),
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
	    'dpinger_dont_add_static_routes', 'use_mfs_tmpvar') as $flag) {
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

/* ------------------------------------------------------------------ */
/* General Setup (system.php)                                          */
/* ------------------------------------------------------------------ */

/* The checkboxes of the General Setup form. */
function restapi_sysgen_flags() {
	return array('dnsallowoverride', 'interfacessort', 'dashboardavailablewidgetspanel', 'systemlogsfilterpanel',
	    'systemlogsmanagelogpanel', 'statusmonitoringsettingspanel', 'requirestatefilter', 'requirefirewallinterface',
	    'webguileftcolumnhyper', 'disablealiaspopupdetail', 'roworderdragging', 'loginshowhost');
}

function restapi_sysgen_types() {
	$types = array('dnsservers' => 'objects', 'choices' => 'ro', 'applied' => 'ro');
	foreach (array('hostname', 'domain', 'dnslocalhost', 'timezone', 'timeservers', 'language', 'webguicss', 'webguifixedmenu',
	    'webguihostnamemenu', 'dashboardcolumns', 'logincss', 'login_message') as $field) {
		$types[$field] = 'string';
	}
	foreach (restapi_sysgen_flags() as $flag) {
		$types[$flag] = 'bool';
	}
	return $types;
}

/* Whether the page offers a gateway per DNS server (more than one gateway of an address family). */
function restapi_sysgen_multiwan(array $gateways) {
	$multiwan = 0;
	$multiwan6 = 0;
	foreach ($gateways as $gw) {
		if ($gw['ipprotocol'] == 'inet') {
			$multiwan++;
		} else {
			$multiwan6++;
		}
	}
	return ($multiwan > 1) || ($multiwan6 > 1);
}

function restapi_sysgen_choices() {
	$timezones = system_get_timezone_list();
	$gateways = get_gateways();
	$choices = array(
		'dnslocalhost' => array('' => 'Use local DNS (127.0.0.1), fall back to remote DNS Servers (Default)',
		    'local' => 'Use local DNS (127.0.0.1), ignore remote DNS Servers', 'remote' => 'Use remote DNS Servers, ignore local DNS'),
		'timezone' => array_combine($timezones, system_general_timezone_descr($timezones)),
		'language' => get_locale_list(),
		'webguicss' => get_css_files(),
		'webguifixedmenu' => array('' => gettext("Scrolls with page"), 'fixed' => gettext("Fixed (Remains visible at top of page)")),
		'webguihostnamemenu' => array('' => gettext("Default (No hostname)"), 'hostonly' => gettext("Hostname only"),
		    'fqdn' => gettext("Fully Qualified Domain Name")),
		'logincss' => array("1e3f75;" => gettext("Dark Blue"), "003300" => gettext("Dark green"), "770101" => gettext("Crimson red"),
		    "4b1263" => gettext("Purple"), "424142" => gettext("Gray"), "333333" => gettext("Dark gray"), "000000" => gettext("Black"),
		    "633215" => gettext("Dark brown"), "bf7703" => gettext("Brown"), "008000" => gettext("Green"),
		    "007faa" => gettext("Light Blue"), "dc2a2a" => gettext("Red"), "9b59b6" => gettext("Violet")),
	);
	if (restapi_sysgen_multiwan($gateways)) {
		$options = array('none' => 'none');
		foreach ($gateways as $gwname => $gwitem) {
			$options[$gwname] = $gwname . ' - ' . $gwitem['friendlyiface'] . ' - ' . $gwitem['gateway'];
		}
		$choices['dnsgw'] = $options;
	}
	return $choices;
}

/* The page's form (pconfig) as API values; DNS servers as {address, hostname[, gateway]}. */
function restapi_sysgen_values(array $p, $with_gateways, array $csslist) {
	$servers = array();
	foreach (array_values((array)($p['dnsserver'] ?? array())) as $i => $address) {
		$server = array('address' => (string)$address, 'hostname' => (string)($p["dnshost{$i}"] ?? ''));
		if ($with_gateways) {
			$gw = (string)($p["dnsgw{$i}"] ?? '');
			$server['gateway'] = ($gw === '') ? 'none' : $gw;
		}
		$servers[] = $server;
	}
	$out = array(
		'hostname' => (string)$p['hostname'],
		'domain' => (string)$p['domain'],
		'dnsservers' => $servers,
		'dnsallowoverride' => (bool)$p['dnsallowoverride'],
		'dnslocalhost' => (string)$p['dnslocalhost'],
		'timezone' => (string)$p['timezone'],
		'timeservers' => (string)$p['timeservers'],
		'language' => (string)$p['language'],
		/* The theme select shows FreeSense.css when the stored theme is not installed. */
		'webguicss' => isset($csslist[(string)$p['webguicss']]) ? (string)$p['webguicss'] : 'FreeSense.css',
		'webguifixedmenu' => (string)$p['webguifixedmenu'],
		'webguihostnamemenu' => (string)$p['webguihostnamemenu'],
		'dashboardcolumns' => (string)$p['dashboardcolumns'],
		'logincss' => (string)$p['logincss'],
		/* The page shows the stored message HTML encoded; the API returns the text. */
		'login_message' => html_entity_decode((string)$p['login_message'], ENT_QUOTES | ENT_HTML401),
	);
	foreach (restapi_sysgen_flags() as $flag) {
		$out[$flag] = (bool)$p[$flag];
	}
	return $out;
}

function restapi_sysgen_settings() {
	return restapi_sysgen_values(system_general_settings(), isset(restapi_sysgen_choices()['dnsgw']), get_css_files());
}

/* The dnsservers list as the page's rows (dnsN, dnshostN and dnsgwN when the gateway select is shown). */
function restapi_sysgen_dns_rows($servers, $with_gateways) {
	$shape = '"dnsservers" must be a list of {address, hostname, gateway}.';
	if (!is_array($servers) || !array_is_list($servers)) {
		throw new RestApiError(400, 'invalid_field', $shape);
	}
	$post = array();
	foreach ($servers as $i => $server) {
		if (!is_array($server) || (array_is_list($server) && !empty($server))) {
			throw new RestApiError(400, 'invalid_field', $shape);
		}
		foreach ($server as $key => $value) {
			if (!in_array($key, array('address', 'hostname', 'gateway'), true)) {
				throw new RestApiError(400, 'unknown_field', "Unknown DNS server field \"{$key}\".");
			}
			if (!is_string($value) && !is_null($value)) {
				throw new RestApiError(400, 'invalid_field', "DNS server field \"{$key}\" must be a string.");
			}
		}
		$gw = (string)($server['gateway'] ?? '');
		if (!$with_gateways && ($gw !== '') && ($gw !== 'none')) {
			throw restapi_validation_error(array('A gateway per DNS server can only be selected with more than one gateway of an address family.'));
		}
		$post["dns{$i}"] = (string)($server['address'] ?? '');
		$post["dnshost{$i}"] = (string)($server['hostname'] ?? '');
		if ($with_gateways) {
			$post["dnsgw{$i}"] = ($gw === '') ? 'none' : $gw;
		}
	}
	if (empty($post)) {
		/* The page always posts at least one (empty) row. */
		$post = array('dns0' => '', 'dnshost0' => '');
		if ($with_gateways) {
			$post['dnsgw0'] = 'none';
		}
	}
	return $post;
}

/*
 * Selects must hold one of the page's options. A stored value the page does
 * not offer (e.g. an unset login page color) may be kept as it is.
 */
function restapi_settings_check_changed_choices(array $values, array $current, array $choices, array $fields) {
	$changed = array();
	foreach ($fields as $field) {
		if (array_key_exists($field, $values) && ((string)$values[$field] !== (string)($current[$field] ?? ''))) {
			$changed[$field] = $values[$field];
		}
	}
	restapi_sysadv_check_choices($changed, $choices, array_keys($changed));
}

function restapi_sysgen_out(array $settings, array $choices) {
	$settings['choices'] = array_map('restapi_svc_choices', $choices);
	return $settings;
}

function restapi_h_sysgen_get($req) {
	return array('data' => restapi_sysgen_out(restapi_sysgen_settings(), restapi_sysgen_choices()));
}

function restapi_h_sysgen_set($req) {
	$types = restapi_sysgen_types();
	$choices = restapi_sysgen_choices();
	$with_gateways = isset($choices['dnsgw']);
	$current = restapi_sysgen_settings();
	$values = restapi_svc_merge($req['body'], $current, $types);
	restapi_settings_check_changed_choices($values, $current, $choices, array('dnslocalhost', 'timezone', 'language', 'webguicss',
	    'webguifixedmenu', 'webguihostnamemenu', 'logincss'));
	$rows = restapi_sysgen_dns_rows($values['dnsservers'], $with_gateways);
	if ($with_gateways) {
		foreach ($rows as $key => $gw) {
			if ((strpos($key, 'dnsgw') === 0) && !array_key_exists($gw, $choices['dnsgw'])) {
				throw restapi_validation_error(array(sprintf('"%s" is not a valid choice for the DNS server gateway.', $gw)));
			}
		}
	}
	unset($values['dnsservers']);
	$post = restapi_svc_post($values, $types) + $rows;
	/* Like the page: the firewall log daemon is (re)started when the timezone changes. */
	if ($post['timezone'] !== $current['timezone']) {
		filter_pflog_start();
	}
	$rv = system_general_save($post);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_sysgen_out(restapi_sysgen_settings(), restapi_sysgen_choices()) +
	    array('applied' => ($rv['retval'] == 0)));
}

/* ------------------------------------------------------------------ */
/* High Availability (system_hasync.php)                               */
/* ------------------------------------------------------------------ */

/* The checkboxes the page shows (Kea DHCPv6 sync only with the Kea backend). */
function restapi_hasync_flags($kea) {
	return array_values(array_filter(system_hasync_checkbox_names(), function ($name) use ($kea) {
		return $kea || ($name !== 'synchronizekea6');
	}));
}

function restapi_hasync_types($kea) {
	$types = array('pfhostid' => 'string', 'pfsyncpeerip' => 'string', 'pfsyncinterface' => 'string', 'synchronizetoip' => 'string',
	    'username' => 'string', 'password' => 'string', 'choices' => 'ro');
	foreach (restapi_hasync_flags($kea) as $flag) {
		$types[$flag] = 'bool';
	}
	return $types;
}

function restapi_hasync_choices() {
	$ifaces = get_configured_interface_with_descr();
	$ifaces['lo0'] = 'loopback';
	return array('pfsyncinterface' => $ifaces);
}

/* A stored hasync section as the form shows it, with the password in clear (never returned). */
function restapi_hasync_values(array $h, $kea) {
	$out = array();
	foreach (restapi_hasync_flags($kea) as $flag) {
		/* The page ticks "TLS verification" when it is set at all, the others when they are "on". */
		$out[$flag] = ($flag === 'synctlsinsecure') ? !empty($h[$flag]) : (($h[$flag] ?? null) === 'on');
	}
	foreach (array('pfhostid', 'pfsyncpeerip', 'pfsyncinterface', 'synchronizetoip', 'username', 'password') as $field) {
		$out[$field] = (string)($h[$field] ?? '');
	}
	return $out;
}

/*
 * API values to the page's post: the checkboxes post "on" ("yes" for TLS
 * verification); the password pair posts the placeholder to keep the stored
 * password unless a new one is sent.
 */
function restapi_hasync_post(array $values, $kea, $current_password, array $body) {
	$post = array();
	foreach (restapi_hasync_flags($kea) as $flag) {
		if (!empty($values[$flag])) {
			$post[$flag] = ($flag === 'synctlsinsecure') ? 'yes' : 'on';
		}
	}
	foreach (array('pfhostid', 'pfsyncpeerip', 'pfsyncinterface', 'synchronizetoip', 'username') as $field) {
		$post[$field] = (string)$values[$field];
	}
	if (array_key_exists('password', $body)) {
		$password = (string)$body['password'];
	} else {
		$password = ((string)$current_password !== '') ? DMYPWD : '';
	}
	$post['passwordfld'] = $password;
	$post['passwordfld_confirm'] = $password;
	return $post;
}

function restapi_hasync_out(array $values) {
	$values = restapi_sysadv_mask($values, array('password'));
	$values['choices'] = array_map('restapi_svc_choices', restapi_hasync_choices());
	return $values;
}

function restapi_h_hasync_get($req) {
	return array('data' => restapi_hasync_out(restapi_hasync_values(config_get_path('hasync', []), dhcp_is_backend('kea'))));
}

function restapi_h_hasync_set($req) {
	$kea = dhcp_is_backend('kea');
	$types = restapi_hasync_types($kea);
	$current = restapi_hasync_values(config_get_path('hasync', []), $kea);
	$body = restapi_sysadv_keep_secrets($req['body'], array('password'));
	$values = restapi_svc_merge($body, $current, $types);
	restapi_settings_check_changed_choices($values, $current, restapi_hasync_choices(), array('pfsyncinterface'));
	$rv = system_hasync_save(restapi_hasync_post($values, $kea, $current['password'], $body));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_hasync_out(restapi_hasync_values(config_get_path('hasync', []), $kea)));
}

/* ------------------------------------------------------------------ */
/* Update settings (system_update_settings.php)                        */
/* ------------------------------------------------------------------ */

/* The page's GitSync fields: they are never read or written through the API. */
function restapi_update_gitsync_fields() {
	return array('synconupgrade', 'repositoryurl', 'branch', 'minimal', 'diff', 'show_files', 'show_command', 'dryrun');
}

function restapi_update_settings() {
	return array(
		'fwbranch' => (string)pkg_get_repo_name(config_get_path('system/pkg_repo_conf_path')),
		'disablecheck' => config_path_enabled('system/firmware', 'disablecheck'),
	);
}

function restapi_update_out(array $settings) {
	$settings['gitsync_configured'] = !empty(config_get_path('system/gitsync'));
	$settings['choices'] = array('fwbranch' => array_map('trim', restapi_svc_choices(pkg_build_repo_list())));
	return $settings;
}

function restapi_h_update_settings_get($req) {
	return array('data' => restapi_update_out(restapi_update_settings()));
}

function restapi_h_update_settings_set($req) {
	foreach (array_keys($req['body']) as $key) {
		if (in_array($key, restapi_update_gitsync_fields(), true)) {
			throw new RestApiError(400, 'not_available', 'The GitSync settings cannot be read or changed through the API.');
		}
	}
	restapi_pkg_not_busy();
	$types = array('fwbranch' => 'string', 'disablecheck' => 'bool', 'gitsync_configured' => 'ro', 'choices' => 'ro');
	$values = restapi_svc_merge($req['body'], restapi_update_settings(), $types);
	/* The branch must be one the page offers (the save function also takes an empty one). */
	restapi_sysadv_check_choices(array('fwbranch' => $values['fwbranch']), array('fwbranch' => pkg_build_repo_list()), array('fwbranch'));
	$rv = system_update_settings_save(restapi_svc_post($values, $types), pkg_list_repos(), false);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_update_out(restapi_update_settings()));
}

/* ------------------------------------------------------------------ */
/* Admin Access (system_advanced_admin.php)                            */
/* ------------------------------------------------------------------ */

/* Seconds between the API's response and a webConfigurator or sshd restart. */
define('RESTAPI_ADMIN_RESTART_DELAY', 2);

/* The checkboxes of the Admin Access form (form name => getAdvancedAdminConfig() key). */
function restapi_admin_flags() {
	return array('webgui-redirect' => 'disablehttpredirect', 'webgui-hsts' => 'disablehsts', 'ocsp-staple' => 'ocsp-staple',
	    'loginautocomplete' => 'loginautocomplete', 'webgui-login-messages' => 'quietlogin', 'roaming' => 'roaming',
	    'noantilockout' => 'noantilockout', 'nodnsrebindcheck' => 'nodnsrebindcheck', 'nohttpreferercheck' => 'nohttpreferercheck',
	    'pagenamefirst' => 'pagenamefirst', 'enablesshd' => 'enablesshd', 'sshdagentforwarding' => 'sshdagentforwarding',
	    'enableserial' => 'enableserial', 'disableconsolemenu' => 'disableconsolemenu');
}

/*
 * Changes that can lock users out of the webConfigurator, the API or SSH need
 * {"confirm": true}. field => the change that needs it ("any" change, or the
 * new value that needs it).
 */
function restapi_admin_lockout_fields() {
	return array('webguiproto' => 'any', 'webguiport' => 'any', 'noantilockout' => true, 'nodnsrebindcheck' => 'any',
	    'nohttpreferercheck' => 'any', 'enablesshd' => false, 'sshport' => 'any', 'sshdkeyonly' => 'any');
}

/* The guarded fields a change from $current to $values touches. */
function restapi_admin_lockout_changes(array $current, array $values) {
	$changes = array();
	foreach (restapi_admin_lockout_fields() as $field => $when) {
		if (!array_key_exists($field, $values) || ($values[$field] === ($current[$field] ?? null))) {
			continue;
		}
		if (($when === 'any') || ($values[$field] === $when)) {
			$changes[] = $field;
		}
	}
	return $changes;
}

function restapi_admin_types() {
	$types = array('webguiproto' => 'string', 'ssl-certref' => 'string', 'webguiport' => 'string', 'max_procs' => 'string',
	    'althostnames' => 'string', 'sshdkeyonly' => 'string', 'sshport' => 'string', 'sshguard_threshold' => 'string',
	    'sshguard_blocktime' => 'string', 'sshguard_detection_time' => 'string', 'sshguard_whitelist' => 'list',
	    'serialspeed' => 'string', 'choices' => 'ro', 'webgui_url' => 'ro');
	foreach (array_keys(restapi_admin_flags()) as $flag) {
		$types[$flag] = 'bool';
	}
	/* Like the page: no serial terminal checkbox when it is forced on, no console select when either is forced. */
	if (g_get('enableserial_force')) {
		unset($types['enableserial']);
	}
	if (!g_get('enableserial_force') && !g_get('primaryconsole_force')) {
		$types['primaryconsole'] = 'string';
	}
	return $types;
}

function restapi_admin_choices() {
	return array(
		'webguiproto' => array('http' => 'HTTP', 'https' => 'HTTPS (SSL/TLS)'),
		'ssl-certref' => cert_build_list('cert', 'HTTPS'),
		'sshdkeyonly' => array('disabled' => 'Password or Public Key', 'enabled' => 'Public Key Only',
		    'both' => 'Require Both Password and Public Key'),
		'serialspeed' => array_combine(array(115200, 57600, 38400, 19200, 14400, 9600), array(115200, 57600, 38400, 19200, 14400, 9600)),
		'primaryconsole' => array('serial' => gettext('Serial Console'), 'video' => gettext('Video Console')),
	);
}

/*
 * The settings as the API shows them: the page's form, except that selects
 * keep their stored value (an unset serial speed or primary console, or a
 * certificate the list does not offer) instead of the page's preselection.
 */
function restapi_admin_values(array $p, array $types, array $choices) {
	$out = array(
		'webguiproto' => (string)$p['webguiproto'],
		'ssl-certref' => (string)$p['ssl-certref'],
		'webguiport' => (string)$p['webguiport'],
		'max_procs' => (string)$p['max_procs'],
		'althostnames' => (string)$p['althostnames'],
		'sshdkeyonly' => restapi_select_choice((string)$p['sshdkeyonly'], $choices['sshdkeyonly']),
		'sshport' => (string)$p['sshport'],
		'sshguard_threshold' => (string)$p['sshguard_threshold'],
		'sshguard_blocktime' => (string)$p['sshguard_blocktime'],
		'sshguard_detection_time' => (string)$p['sshguard_detection_time'],
		'sshguard_whitelist' => array_values(array_filter(explode(' ', (string)$p['sshguard_whitelist']), 'strlen')),
		'serialspeed' => (string)$p['serialspeed'],
	);
	foreach (restapi_admin_flags() as $flag => $key) {
		/* The page ticks the serial terminal when the setting exists at all. */
		$out[$flag] = ($flag === 'enableserial') ? isset($p['enableserial']) : (bool)$p[$key];
	}
	if (!isset($types['enableserial'])) {
		unset($out['enableserial']);
	}
	if (isset($types['primaryconsole'])) {
		$out['primaryconsole'] = (string)$p['primaryconsole'];
	}
	return $out;
}

/* API values to the page's post (checkboxes "yes", the pass list as one space separated value). */
function restapi_admin_post(array $values, array $types) {
	$post = restapi_svc_post($values, $types);
	$post['sshguard_whitelist'] = implode(' ', (array)$values['sshguard_whitelist']);
	return $post;
}

/* The port the webConfigurator listens on with these settings. */
function restapi_admin_gui_port(array $values) {
	if ((string)$values['webguiport'] !== '') {
		return (int)$values['webguiport'];
	}
	return ($values['webguiproto'] === 'https') ? 443 : 80;
}

/* The SSH port with these settings (null: SSH disabled). */
function restapi_admin_ssh_port(array $values) {
	if (empty($values['enablesshd'])) {
		return null;
	}
	return ((string)$values['sshport'] !== '') ? (int)$values['sshport'] : 22;
}

/*
 * Ports the new settings would make the webConfigurator or sshd share with
 * something else. $listeners: TCP port => commands listening on it now. Only
 * a changed port is checked against the listeners (the running nginx or sshd
 * on it is expected).
 */
function restapi_admin_port_conflicts(array $current, array $values, array $listeners) {
	$errors = array();
	$gui = restapi_admin_gui_port($values);
	$redirect = ($values['webguiproto'] === 'https') && empty($values['webgui-redirect']);
	$ssh = restapi_admin_ssh_port($values);
	if ($redirect && ($gui === 80)) {
		$errors[] = 'The webConfigurator cannot use port 80 for HTTPS while its HTTP redirect (port 80) is enabled.';
	}
	if (($ssh !== null) && ($ssh === $gui)) {
		$errors[] = sprintf('The webConfigurator and SSH cannot both use port %d.', $ssh);
	}
	if (($ssh !== null) && $redirect && ($ssh === 80)) {
		$errors[] = 'SSH cannot use port 80 while the webConfigurator redirects HTTP (port 80) to HTTPS.';
	}
	if ($gui !== restapi_admin_gui_port($current)) {
		/* The webConfigurator's nginx (and sshd when SSH is being disabled) give their ports up. */
		$others = array_diff($listeners[$gui] ?? array(), ($ssh === null) ? array('nginx', 'sshd') : array('nginx'));
		if (!empty($others)) {
			$errors[] = sprintf('Port %d is already used by %s.', $gui, implode(', ', $others));
		}
	}
	if (($ssh !== null) && ($ssh !== restapi_admin_ssh_port($current))) {
		$others = array_diff($listeners[$ssh] ?? array(), array('sshd'));
		if (!empty($others)) {
			$errors[] = sprintf('Port %d is already used by %s.', $ssh, implode(', ', $others));
		}
	}
	return $errors;
}

/* sockstat lines (USER COMMAND PID FD PROTO LOCAL FOREIGN) as TCP port => listening commands. */
function restapi_admin_parse_sockstat(array $lines) {
	$ports = array();
	foreach ($lines as $line) {
		$f = preg_split('/\s+/', trim($line));
		if ((count($f) < 6) || !preg_match('/[:.](\d+)$/D', $f[5], $m)) {
			continue;
		}
		$ports[(int)$m[1]][$f[1]] = $f[1];
	}
	return array_map('array_values', $ports);
}

/* TCP ports with listening sockets and the commands that hold them. */
function restapi_admin_listeners() {
	$lines = array();
	exec('/usr/bin/sockstat -46lq -P tcp 2>/dev/null', $lines);
	return restapi_admin_parse_sockstat($lines);
}

/* The webConfigurator's address with the given settings, built from the request's host like the page's redirect. */
function restapi_admin_gui_url($http_host, $proto, $port) {
	$http_host = (string)$http_host;
	if (strpos($http_host, ']') !== false) {
		$host = substr($http_host, 0, strrpos($http_host, ']') + 1);
	} else {
		list($host) = explode(':', $http_host);
	}
	if (!preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)$/D', $host)) {
		$host = '<firewall address>';
	}
	$default = ($proto === 'https') ? '443' : '80';
	$port = (string)$port;
	return "{$proto}://{$host}" . ((($port !== '') && ($port !== $default)) ? ":{$port}" : '') . '/';
}

function restapi_admin_settings() {
	return restapi_admin_values(getAdvancedAdminConfig(), restapi_admin_types(), restapi_admin_choices());
}

function restapi_admin_out(array $settings) {
	$settings['webgui_url'] = restapi_admin_gui_url($_SERVER['HTTP_HOST'] ?? '', $settings['webguiproto'], $settings['webguiport']);
	$choices = restapi_admin_choices();
	if (!isset($settings['primaryconsole'])) {
		unset($choices['primaryconsole']);
	}
	$settings['choices'] = array_map('restapi_svc_choices', $choices);
	return $settings;
}

/*
 * Restart the webConfigurator and/or sshd once the response has been sent:
 * the webConfigurator restart stops the web server that answers this request.
 */
function restapi_admin_restart_after_response($webgui, $sshd) {
	register_shutdown_function(function () use ($webgui, $sshd) {
		/* Output after the response would otherwise end the script once the client has gone (restart_GUI() flushes). */
		ignore_user_abort(true);
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
		sleep(RESTAPI_ADMIN_RESTART_DELAY);
		if ($sshd) {
			restart_SSHD();
		}
		if ($webgui) {
			/* restart_GUI() flushes an output buffer. */
			ob_start();
			restart_GUI();
			ob_end_clean();
		}
	});
}

function restapi_h_admin_get($req) {
	return array('data' => restapi_admin_out(restapi_admin_settings()));
}

function restapi_h_admin_set($req) {
	$body = $req['body'];
	$confirm = $body['confirm'] ?? null;
	unset($body['confirm']);
	if (($confirm !== null) && !is_bool($confirm)) {
		throw new RestApiError(400, 'invalid_field', 'Field "confirm" must be a boolean.');
	}
	$types = restapi_admin_types();
	$choices = restapi_admin_choices();
	$current = restapi_admin_settings();
	$values = restapi_svc_merge($body, $current, $types);
	restapi_sysadv_check_choices($values, $choices, array('webguiproto', 'sshdkeyonly'));
	restapi_settings_check_changed_choices($values, $current, $choices, array('ssl-certref', 'serialspeed', 'primaryconsole'));
	$lockout = restapi_admin_lockout_changes($current, $values);
	$after = restapi_admin_gui_url($_SERVER['HTTP_HOST'] ?? '', $values['webguiproto'], $values['webguiport']);
	if (!empty($lockout) && ($confirm !== true)) {
		throw new RestApiError(400, 'confirm_required', 'These changes can lock you out of the webConfigurator, the API or SSH (' .
		    implode(', ', $lockout) . '); send them again with "confirm": true. After the restart the webConfigurator will be at ' .
		    $after . ' and the API at ' . $after . 'api/v1.', array('fields' => $lockout, 'webgui_url' => $after,
		    'api_url' => "{$after}api/v1"));
	}
	$conflicts = restapi_admin_port_conflicts($current, $values, restapi_admin_listeners());
	if (!empty($conflicts)) {
		throw restapi_validation_error($conflicts);
	}
	/* The page's function saves and applies; the restarts are left to restapi_admin_restart_after_response(). */
	$rv = restapi_json_result(doAdvancedAdminPOST(restapi_admin_post($values, $types), true, false));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$webgui = !empty($rv['restartui']);
	$sshd = !empty($rv['restartsshd']);
	if ($webgui || $sshd) {
		restapi_admin_restart_after_response($webgui, $sshd);
	}
	$settings = restapi_admin_out(restapi_admin_settings());
	return array('data' => $settings + array(
		'applied' => ($rv['retval'] == 0),
		'restart' => array('webgui' => $webgui, 'sshd' => $sshd,
		    'after_seconds' => ($webgui || $sshd) ? RESTAPI_ADMIN_RESTART_DELAY : 0,
		    'webgui_url' => $settings['webgui_url'], 'api_url' => "{$settings['webgui_url']}api/v1"),
		'lockout_fields_changed' => $lockout));
}
