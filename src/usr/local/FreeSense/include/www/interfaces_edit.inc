<?php
/*
 * interfaces_edit.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2006 Daniel S. Haischt
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

/*
 * Interface edit logic (Interfaces > WAN, LAN, OPTn) shared by
 * interfaces.php and the REST API, moved out of the page with the form
 * fields as parameters.
 *
 * Like the page, a save writes the configuration, records the old settings
 * in {tmp_path}/.interfaces.apply and marks the "interfaces" subsystem
 * dirty; nothing is reconfigured until interfaces_edit_apply() runs (the
 * page's "Apply Changes").
 *
 * What the page kept in globals is a context array built by
 * interfaces_edit_context(): the interface's configuration, the PPP and
 * gateway lists, the PPP entry it uses and, for wireless interfaces, what
 * the hardware supports.
 */

require_once("config.gui.inc");
require_once("functions.inc");
require_once("interfaces.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("captiveportal.inc");
require_once("rrd.inc");
require_once("gwlb.inc");
require_once("services.inc");
require_once("system.inc");
require_once("xmlparse_attr.inc");
require_once("util.inc");

if (!defined('CRON_MONTHLY_PATTERN')) {
	define("CRON_MONTHLY_PATTERN", "0 0 1 * *");
}
if (!defined('CRON_WEEKLY_PATTERN')) {
	define("CRON_WEEKLY_PATTERN", "0 0 * * 0");
}
if (!defined('CRON_DAILY_PATTERN')) {
	define("CRON_DAILY_PATTERN", "0 0 * * *");
}
if (!defined('CRON_HOURLY_PATTERN')) {
	define("CRON_HOURLY_PATTERN", "0 * * * *");
}

function remove_bad_chars($string) {
	return preg_replace('/[^a-z_0-9]/i', '', $string);
}

/* The file where saves record the settings that "Apply Changes" replaces. */
function interfaces_edit_apply_file() {
	return g_get('tmp_path') . '/.interfaces.apply';
}

/*
 * What the page loads before it builds the form: the interface's
 * configuration ($wancfg, with the WAN/LAN default description), the
 * settings before any change ($old_wancfg, $old_ppps), the PPP and gateway
 * lists, the PPP entry the interface uses ($pppid; the next free index
 * when it uses none, see "ppp_found") and the wireless details. With
 * $wireless_info false the wireless hardware is not queried (and a missing
 * wireless clone is not created), which is enough to read the settings.
 */
function interfaces_edit_context($if, $wireless_info = true) {
	// Get configured interface list
	$ifdescrs = get_configured_interface_with_descr(true);
	$bridged = link_interface_to_bridge($if);

	$a_ppps = config_get_path('ppps/ppp', []);
	$old_ppps = $a_ppps;

	$a_gateways = config_get_path('gateways/gateway_item', []);

	$wancfg = config_get_path("interfaces/{$if}", []);
	$realifname = get_real_interface($if);
	$old_wancfg = $wancfg;
	array_set_path($old_wancfg, 'realif', $realifname);

	$interfaces = get_configured_interface_with_descr();

	/* Interfaces which have addresses configured elsewhere and should not be
	 * configured here. See upstream issue 8687 */
	$no_address_interfaces = ["ovpn", "ipsec", "gif", "gre", "l2tps"];
	$show_address_controls = true;
	foreach ($no_address_interfaces as $ifbl) {
		if (substr($realifname, 0, strlen($ifbl)) == $ifbl) {
			$show_address_controls = false;
		}
	}

	// Populate page descr if it does not exist.
	if ((strtolower($if) == "wan") &&
	    empty(array_get_path($wancfg, 'descr'))) {
		array_set_path($wancfg, 'descr', "WAN");
	} elseif ((strtolower($if) == "lan") &&
	    empty(array_get_path($wancfg, 'descr'))) {
		array_set_path($wancfg, 'descr', "LAN");
	}

	/* NOTE: The code here is used to set the $pppid for the curious */
	$pppid = null;
	foreach ($a_ppps as $pid => $ppp) {
		if (array_get_path($wancfg, 'if') == array_get_path($ppp, 'if')) {
			$pppid = $pid;
			break;
		}
	}

	$type_disabled = (substr(array_get_path($wancfg, 'if', ''), 0, 3) == 'gre') ? 'disabled' : '';

	$ppp_found = (isset($pppid) && array_get_path($wancfg, 'if') == array_get_path($a_ppps, "{$pppid}/if"));
	if (!$ppp_found) {
		$pppid = count($a_ppps);
	}

	$wl = [
		'wlanif' => null,
		'wlanbaseif' => null,
		'wl_modes' => null,
		'wl_ht_modes' => null,
		'wl_ht_list' => [],
		'wl_chaninfo' => null,
		'wl_sysctl_prefix' => null,
		'wl_sysctl' => null,
		'wl_regdomains' => null,
		'wl_regdomains_attr' => null,
		'wl_countries' => null,
		'wl_countries_attr' => null,
	];

	/* Wireless interface? */
	if (is_array(array_get_path($wancfg, 'wireless'))) {
		/* Sync first to be sure it displays the actual settings that will be used */
		interface_sync_wireless_clones($wancfg, false);
		/* Get wireless modes */
		$wlanif = get_real_interface($if);
		$wl['wlanif'] = $wlanif;
		if ($wireless_info && !does_interface_exist($wlanif)) {
			interface_wireless_clone($wlanif, $wancfg);
		}
		$wlanbaseif = interface_get_wireless_base(array_get_path($wancfg, 'if'));
		$wl['wlanbaseif'] = $wlanbaseif;
		if ($wireless_info) {
			preg_match("/^(.*?)([0-9]*)$/", $wlanbaseif, $wlanbaseif_split);
			$wl['wl_modes'] = get_wireless_modes($if);
			$wl['wl_ht_modes'] = get_wireless_ht_modes($if);
			$wl['wl_ht_list'] = get_wireless_ht_list($if);
			$wl['wl_chaninfo'] = get_wireless_channel_info($if);
			$wl_sysctl_prefix = 'dev.' . $wlanbaseif_split[1] . '.' . $wlanbaseif_split[2];
			$wl['wl_sysctl_prefix'] = $wl_sysctl_prefix;
			$wl['wl_sysctl'] = get_sysctl(
				[
					"{$wl_sysctl_prefix}.diversity",
					"{$wl_sysctl_prefix}.txantenna",
					"{$wl_sysctl_prefix}.rxantenna",
					"{$wl_sysctl_prefix}.slottime",
					"{$wl_sysctl_prefix}.acktimeout",
					"{$wl_sysctl_prefix}.ctstimeout"
				]);
			$wl_regdomain_xml_attr = [];
			$wl_regdomain_xml = parse_xml_regdomain($wl_regdomain_xml_attr);
			$wl['wl_regdomains'] = array_get_path($wl_regdomain_xml, 'regulatory-domains/rd');
			$wl['wl_regdomains_attr'] = array_get_path($wl_regdomain_xml_attr, 'regulatory-domains/rd');
			$wl['wl_countries'] = array_get_path($wl_regdomain_xml, 'country-codes/country');
			$wl['wl_countries_attr'] = array_get_path($wl_regdomain_xml_attr, 'country-codes/country');
		}
	}

	return array_merge([
		'if' => $if,
		'ifdescrs' => $ifdescrs,
		'bridged' => $bridged,
		'a_ppps' => $a_ppps,
		'old_ppps' => $old_ppps,
		'a_gateways' => $a_gateways,
		'wancfg' => $wancfg,
		'old_wancfg' => $old_wancfg,
		'realifname' => $realifname,
		'interfaces' => $interfaces,
		'show_address_controls' => $show_address_controls,
		'type_disabled' => $type_disabled,
		'pppid' => $pppid,
		'ppp_found' => $ppp_found,
	], $wl);
}

/*
 * The form's values as the page shows them ($pconfig) for an interface,
 * built from the configuration only. $ctx is the interface's context
 * (built when not given); like the page this normalizes an unset DHCPv6
 * prefix delegation size to "none" in $ctx['wancfg'].
 */
function interfaces_edit_config($if, ?array &$ctx = null) {
	if ($ctx === null) {
		$ctx = interfaces_edit_context($if, false);
	}
	$pconfig = [];
	$a_ppps = $ctx['a_ppps'];
	$pppid = $ctx['pppid'];
	$wancfg = $ctx['wancfg'];
	$wlanbaseif = $ctx['wlanbaseif'];

	if ($ctx['ppp_found']) {
		array_set_path($pconfig, 'pppid', $pppid);
		array_set_path($pconfig, 'ptpid', array_get_path($a_ppps, "{$pppid}/ptpid"));
		array_set_path($pconfig, 'port', array_get_path($a_ppps, "{$pppid}/ports"));
		if (array_get_path($a_ppps, "{$pppid}/type") == "ppp") {
			array_set_path($pconfig, 'ppp_username', array_get_path($a_ppps, "{$pppid}/username", ''));
			array_set_path($pconfig, 'ppp_password', base64_decode(array_get_path($a_ppps, "{$pppid}/password", '')));
			array_set_path($pconfig, 'phone', array_get_path($a_ppps, "{$pppid}/phone", ''));
			array_set_path($pconfig, 'apn', array_get_path($a_ppps, "{$pppid}/apn", ''));
		} elseif (array_get_path($a_ppps, "{$pppid}/type") == "pppoe") {
			array_set_path($pconfig, 'pppoe_username', array_get_path($a_ppps, "{$pppid}/username", ''));
			array_set_path($pconfig, 'pppoe_password', base64_decode(array_get_path($a_ppps, "{$pppid}/password", '')));

			array_set_path($pconfig, 'provider', array_get_path($a_ppps, "{$pppid}/provider"));
			array_set_path($pconfig, 'hostuniq', array_get_path($a_ppps, "{$pppid}/hostuniq"));
			array_set_path($pconfig, 'pppoe_dialondemand', array_path_enabled($a_ppps, $pppid, 'ondemand'));
			array_set_path($pconfig, 'pppoe_idletimeout', array_get_path($a_ppps, "{$pppid}/idletimeout"));

			/* ================================================ */
			/* = force a connection reset at a specific time? = */
			/* ================================================ */

			if (array_path_enabled($a_ppps, $pppid, 'pppoe-reset-type')) {
				array_set_path($pconfig, 'pppoe-reset-type', array_get_path($a_ppps, "{$pppid}/pppoe-reset-type"));
				$itemhash = getMPDCRONSettings(array_get_path($a_ppps, "{$pppid}/if"));
				if (is_array($itemhash) &&
				    !empty($itemhash)) {
					$cronitem = array_get_path($itemhash, 'ITEM');
				}
				if (isset($cronitem)) {
					$resetTime  = array_get_path($cronitem, 'minute') . ' ';
					$resetTime .= array_get_path($cronitem, 'hour') . ' ';
					$resetTime .= array_get_path($cronitem, 'mday') . ' ';
					$resetTime .= array_get_path($cronitem, 'month') . ' ';
					$resetTime .= array_get_path($cronitem, 'wday');
				} else {
					$resetTime = NULL;
				}

				if (array_get_path($a_ppps, "{$pppid}/pppoe-reset-type") == 'custom') {
					if ($cronitem) {
						array_set_path($pconfig, 'pppoe_pr_custom', true);
						array_set_path($pconfig, 'pppoe_resetminute', array_get_path($cronitem, 'minute'));
						array_set_path($pconfig, 'pppoe_resethour', array_get_path($cronitem, 'hour'));
						if ((array_get_path($cronitem, 'mday') != "*") &&
						    (array_get_path($cronitem, 'month') != "*")) {
							$resetDate  = array_get_path($cronitem, 'month') . '/';
							$resetDate .= array_get_path($cronitem, 'mday') . '/';
							$resetDate .= date("Y");
							array_set_path($pconfig, 'pppoe_resetdate', $resetDate);
						}
					}
				} elseif (array_get_path($a_ppps, "{$pppid}/pppoe-reset-type") == 'preset') {
					array_set_path($pconfig, 'pppoe_pr_preset', true);
					switch ($resetTime) {
						case CRON_MONTHLY_PATTERN:
							array_set_path($pconfig, 'pppoe_monthly', true);
							break;
						case CRON_WEEKLY_PATTERN:
							array_set_path($pconfig, 'pppoe_weekly', true);
							break;
						case CRON_DAILY_PATTERN:
							array_set_path($pconfig, 'pppoe_daily', true);
							break;
						case CRON_HOURLY_PATTERN:
							array_set_path($pconfig, 'pppoe_hourly', true);
							break;
					}
				}
			} // End force pppoe reset at specific time
			// End if type == pppoe
		} elseif ((array_get_path($a_ppps, "{$pppid}/type") == "pptp") ||
			  array_get_path($a_ppps, "{$pppid}/type") == "l2tp") {
			array_set_path($pconfig, 'pptp_username', array_get_path($a_ppps, "{$pppid}/username", ''));
			array_set_path($pconfig, 'pptp_password', base64_decode(array_get_path($a_ppps, "{$pppid}/password", '')));
			if ((array_get_path($a_ppps, "{$pppid}/type") == 'l2tp') &&
			    !empty(array_get_path($a_ppps, "{$pppid}/secret"))) {
				array_set_path($pconfig, 'l2tp_secret', base64_decode(array_get_path($a_ppps, "{$pppid}/secret")));
			}
			array_set_path($pconfig, 'pptp_localip', explode(",", array_get_path($a_ppps, "{$pppid}/localip")));
			array_set_path($pconfig, 'pptp_subnet', explode(",", array_get_path($a_ppps, "{$pppid}/subnet")));
			array_set_path($pconfig, 'pptp_remote', explode(",", array_get_path($a_ppps, "{$pppid}/gateway")));
			array_set_path($pconfig, 'pptp_dialondemand', array_path_enabled($a_ppps, $pppid, 'ondemand'));
			array_set_path($pconfig, 'pptp_idletimeout', array_get_path($a_ppps, "{$pppid}/timeout"));
		}
	} else {
		array_set_path($pconfig, 'ptpid', interfaces_ptpid_next());
	}

	/* Settings to copy from $wancfg to $pconfig */
	$copy_list = [
		'dhcphostname',
		'alias-address',
		'alias-subnet',
		'dhcprejectfrom',
		'adv_dhcp_pt_timeout',
		'adv_dhcp_pt_retry',
		'adv_dhcp_pt_select_timeout',
		'adv_dhcp_pt_reboot',
		'adv_dhcp_pt_backoff_cutoff',
		'adv_dhcp_pt_initial_interval',
		'adv_dhcp_pt_values',
		'adv_dhcp_send_options',
		'adv_dhcp_request_options',
		'adv_dhcp_required_options',
		'adv_dhcp_option_modifiers',
		'adv_dhcp_config_advanced',
		'adv_dhcp_config_file_override',
		'adv_dhcp_config_file_override_path',
		'adv_dhcp6_interface_statement_send_options',
		'adv_dhcp6_interface_statement_request_options',
		'adv_dhcp6_interface_statement_information_only_enable',
		'adv_dhcp6_interface_statement_script',
		'adv_dhcp6_id_assoc_statement_address_enable',
		'adv_dhcp6_id_assoc_statement_address',
		'adv_dhcp6_id_assoc_statement_address_id',
		'adv_dhcp6_id_assoc_statement_address_pltime',
		'adv_dhcp6_id_assoc_statement_address_vltime',
		'adv_dhcp6_id_assoc_statement_prefix_enable',
		'adv_dhcp6_id_assoc_statement_prefix',
		'adv_dhcp6_id_assoc_statement_prefix_id',
		'adv_dhcp6_id_assoc_statement_prefix_pltime',
		'adv_dhcp6_id_assoc_statement_prefix_vltime',
		'adv_dhcp6_prefix_interface_statement_sla_id',
		'adv_dhcp6_prefix_interface_statement_sla_len',
		'adv_dhcp6_prefix_selected_interface',
		'adv_dhcp6_authentication_statement_authname',
		'adv_dhcp6_authentication_statement_protocol',
		'adv_dhcp6_authentication_statement_algorithm',
		'adv_dhcp6_authentication_statement_rdm',
		'adv_dhcp6_key_info_statement_keyname',
		'adv_dhcp6_key_info_statement_realm',
		'adv_dhcp6_key_info_statement_keyid',
		'adv_dhcp6_key_info_statement_secret',
		'adv_dhcp6_key_info_statement_expire',
		'adv_dhcp6_config_advanced',
		'adv_dhcp6_config_file_override',
		'adv_dhcp6_config_file_override_path',
	];
	foreach ($copy_list as $sn) {
		array_set_path($pconfig, $sn, array_get_path($wancfg, $sn));
	}

	array_set_path($pconfig, 'dhcp_plus', array_path_enabled($wancfg, '', 'dhcp_plus'));
	array_set_path($pconfig, 'descr', remove_bad_chars(array_get_path($wancfg, 'descr')));
	array_set_path($pconfig, 'enable', array_path_enabled($wancfg, ''));

	switch (array_get_path($wancfg, 'ipaddr')) {
		case "dhcp":
			array_set_path($pconfig, 'type', 'dhcp');
			array_set_path($pconfig, 'dhcpvlanenable', array_path_enabled($wancfg, '', 'dhcpvlanenable'));
			array_set_path($pconfig, 'dhcpcvpt', array_get_path($wancfg, 'dhcpcvpt'));
			break;
		case "pppoe":
		case "pptp":
		case "l2tp":
		case "ppp":
			array_set_path($pconfig, 'type', array_get_path($wancfg, 'ipaddr'));
			break;
		default:
			if (is_ipaddrv4(array_get_path($wancfg, 'ipaddr'))) {
				array_set_path($pconfig, 'type', 'staticv4');
				array_set_path($pconfig, 'ipaddr', array_get_path($wancfg, 'ipaddr'));
				array_set_path($pconfig, 'subnet', array_get_path($wancfg, 'subnet'));
				array_set_path($pconfig, 'gateway', array_get_path($wancfg, 'gateway'));
			} elseif (in_array(remove_ifindex(array_get_path($wancfg, 'if')), ["ppp", "pppoe", "pptp", "l2tp"])) {
				array_set_path($pconfig, 'type', remove_ifindex(array_get_path($wancfg, 'if')));
			} else {
				array_set_path($pconfig, 'type', 'none');
			}
			break;
	}

	switch (array_get_path($wancfg, 'ipaddrv6')) {
		case "slaac":
			array_set_path($pconfig, 'type6', 'slaac');
			array_set_path($pconfig, 'slaacusev4iface', array_path_enabled($wancfg, '', 'slaacusev4iface'));
			break;
		case "dhcp6":
			array_set_path($pconfig, 'type6', 'dhcp6');
			array_set_path($pconfig, 'dhcp6-duid', array_get_path($wancfg, 'dhcp6-duid'));
			if (is_null(array_get_path($wancfg, 'dhcp6-ia-pd-len'))) {
				array_set_path($wancfg, 'dhcp6-ia-pd-len', 'none');
			}
			array_set_path($pconfig, 'dhcp6-ia-pd-len', array_get_path($wancfg, 'dhcp6-ia-pd-len'));
			array_set_path($pconfig, 'dhcp6-ia-pd-send-hint', array_path_enabled($wancfg, '', 'dhcp6-ia-pd-send-hint'));
			array_set_path($pconfig, 'dhcp6prefixonly', array_path_enabled($wancfg, '', 'dhcp6prefixonly'));
			array_set_path($pconfig, 'dhcp6usev4iface', array_path_enabled($wancfg, '', 'dhcp6usev4iface'));
			array_set_path($pconfig, 'dhcp6withoutra', array_path_enabled($wancfg, '', 'dhcp6withoutra'));
			array_set_path($pconfig, 'dhcp6vlanenable', array_path_enabled($wancfg, '', 'dhcp6vlanenable'));
			array_set_path($pconfig, 'dhcp6cvpt', array_get_path($wancfg, 'dhcp6cvpt'));
			break;
		case "6to4":
			array_set_path($pconfig, 'type6', '6to4');
			break;
		case "track6":
			array_set_path($pconfig, 'type6', 'track6');
			array_set_path($pconfig, 'track6-interface', array_get_path($wancfg, 'track6-interface'));
			array_set_path($pconfig, 'track6-prefix-id', array_get_path($wancfg, 'track6-prefix-id', '0'));
			array_set_path($pconfig, 'track6-prefix-id--hex', sprintf("%x", array_get_path($pconfig, 'track6-prefix-id')));
			break;
		case "6rd":
			array_set_path($pconfig, 'type6', '6rd');
			array_set_path($pconfig, 'prefix-6rd', array_get_path($wancfg, 'prefix-6rd'));
			array_set_path($pconfig, 'prefix-6rd-v4plen', array_get_path($wancfg, 'prefix-6rd-v4plen', '0'));
			array_set_path($pconfig, 'gateway-6rd', array_get_path($wancfg, 'gateway-6rd'));
			break;
		default:
			if (is_ipaddrv6(array_get_path($wancfg, 'ipaddrv6'))) {
				array_set_path($pconfig, 'type6', 'staticv6');
				array_set_path($pconfig, 'ipv6usev4iface', array_path_enabled($wancfg, '', 'ipv6usev4iface'));
				array_set_path($pconfig, 'ipaddrv6', array_get_path($wancfg, 'ipaddrv6'));
				array_set_path($pconfig, 'subnetv6', array_get_path($wancfg, 'subnetv6'));
				array_set_path($pconfig, 'gatewayv6', array_get_path($wancfg, 'gatewayv6'));
			} else {
				array_set_path($pconfig, 'type6', 'none');
			}
			break;
	}

	array_set_path($pconfig, 'blockpriv', array_path_enabled($wancfg, '', 'blockpriv'));
	array_set_path($pconfig, 'blockbogons', array_path_enabled($wancfg, '', 'blockbogons'));
	array_set_path($pconfig, 'spoofmac', array_get_path($wancfg, 'spoofmac'));
	array_set_path($pconfig, 'mtu', array_get_path($wancfg, 'mtu'));
	array_set_path($pconfig, 'mss', array_get_path($wancfg, 'mss'));

	/* Wireless interface? */
	if (is_array(array_get_path($wancfg, 'wireless'))) {
		array_set_path($pconfig, 'persistcommonwireless', config_path_enabled('wireless/interfaces', $wlanbaseif));
		array_set_path($pconfig, 'standard', array_get_path($wancfg, 'wireless/standard'));
		array_set_path($pconfig, 'mode', array_get_path($wancfg, 'wireless/mode'));
		array_set_path($pconfig, 'protmode', array_get_path($wancfg, 'wireless/protmode'));
		array_set_path($pconfig, 'ssid', array_get_path($wancfg, 'wireless/ssid'));
		array_set_path($pconfig, 'channel', array_get_path($wancfg, 'wireless/channel'));
		array_set_path($pconfig, 'channel_width', array_get_path($wancfg, 'wireless/channel_width'));
		array_set_path($pconfig, 'txpower', array_get_path($wancfg, 'wireless/txpower'));
		array_set_path($pconfig, 'diversity', array_get_path($wancfg, 'wireless/diversity'));
		array_set_path($pconfig, 'txantenna', array_get_path($wancfg, 'wireless/txantenna'));
		array_set_path($pconfig, 'rxantenna', array_get_path($wancfg, 'wireless/rxantenna'));
		array_set_path($pconfig, 'distance', array_get_path($wancfg, 'wireless/distance'));
		array_set_path($pconfig, 'regdomain', array_get_path($wancfg, 'wireless/regdomain'));
		array_set_path($pconfig, 'regcountry', array_get_path($wancfg, 'wireless/regcountry'));
		array_set_path($pconfig, 'reglocation', array_get_path($wancfg, 'wireless/reglocation'));
		array_set_path($pconfig, 'wme_enable', array_path_enabled($wancfg, 'wireless/wme'));

		if (array_path_enabled($wancfg, 'wireless/puren')) {
			array_set_path($pconfig, 'puremode', '11n');
		} elseif (array_path_enabled($wancfg, 'wireless/pureg')) {
			array_set_path($pconfig, 'puremode', '11g');
		} else {
			array_set_path($pconfig, 'puremode', 'any');
		}
		array_set_path($pconfig, 'apbridge_enable', array_path_enabled($wancfg, 'wireless/apbridge'));
		array_set_path($pconfig, 'authmode', array_get_path($wancfg, 'wireless/authmode'));
		array_set_path($pconfig, 'hidessid_enable', array_path_enabled($wancfg, 'wireless/hidessid'));
		array_set_path($pconfig, 'auth_server_addr', array_get_path($wancfg, 'wireless/auth_server_addr'));
		array_set_path($pconfig, 'auth_server_port', array_get_path($wancfg, 'wireless/auth_server_port'));
		array_set_path($pconfig, 'auth_server_shared_secret', array_get_path($wancfg, 'wireless/auth_server_shared_secret'));
		array_set_path($pconfig, 'auth_server_addr2', array_get_path($wancfg, 'wireless/auth_server_addr2'));
		array_set_path($pconfig, 'auth_server_port2', array_get_path($wancfg, 'wireless/auth_server_port2'));
		array_set_path($pconfig, 'auth_server_shared_secret2', array_get_path($wancfg, 'wireless/auth_server_shared_secret2'));
		if (is_array(array_get_path($wancfg, 'wireless/wpa'))) {
			array_set_path($pconfig, 'wpa_enable', array_path_enabled($wancfg, 'wireless/wpa'));
			array_set_path($pconfig, 'debug_mode', array_get_path($wancfg, 'wireless/wpa/debug_mode'));
			array_set_path($pconfig, 'macaddr_acl', array_get_path($wancfg, 'wireless/wpa/macaddr_acl'));
			array_set_path($pconfig, 'mac_acl_enable', array_path_enabled($wancfg, 'wireless/wpa', 'mac_acl_enable'));
			array_set_path($pconfig, 'wpa_mode', array_get_path($wancfg, 'wireless/wpa/wpa_mode'));
			array_set_path($pconfig, 'wpa_key_mgmt', array_get_path($wancfg, 'wireless/wpa/wpa_key_mgmt'));
			array_set_path($pconfig, 'wpa_pairwise', array_get_path($wancfg, 'wireless/wpa/wpa_pairwise'));
			array_set_path($pconfig, 'wpa_group_rekey', array_get_path($wancfg, 'wireless/wpa/wpa_group_rekey'));
			array_set_path($pconfig, 'wpa_gmk_rekey', array_get_path($wancfg, 'wireless/wpa/wpa_gmk_rekey'));
			array_set_path($pconfig, 'wpa_strict_rekey', array_path_enabled($wancfg, 'wireless/wpa', 'wpa_strict_rekey'));
			array_set_path($pconfig, 'passphrase', array_get_path($wancfg, 'wireless/wpa/passphrase'));
			array_set_path($pconfig, 'ieee8021x', array_path_enabled($wancfg, 'wireless/wpa/ieee8021x'));
			array_set_path($pconfig, 'rsn_preauth', array_path_enabled($wancfg, 'wireless/wpa', 'rsn_preauth'));
			array_set_path($pconfig, 'ext_wpa_sw', array_get_path($wancfg, 'wireless/wpa/ext_wpa_sw'));
			array_set_path($pconfig, 'wpa_eap_client_mode', array_get_path($wancfg, 'wireless/wpa/wpa_eap_client_mode'));
			array_set_path($pconfig, 'wpa_eap_inner_auth', array_get_path($wancfg, 'wireless/wpa/wpa_eap_inner_auth'));
			array_set_path($pconfig, 'wpa_eap_inner_id', array_get_path($wancfg, 'wireless/wpa/wpa_eap_inner_id'));
			array_set_path($pconfig, 'wpa_eap_inner_password', base64_decode(array_get_path($wancfg, 'wireless/wpa/wpa_eap_inner_password')));
			array_set_path($pconfig, 'wpa_eap_cert', array_get_path($wancfg, 'wireless/wpa/wpa_eap_cert'));
			array_set_path($pconfig, 'wpa_eap_ca', array_get_path($wancfg, 'wireless/wpa/wpa_eap_ca'));
		}
		array_set_path($pconfig, 'mac_acl', array_get_path($wancfg, 'wireless/mac_acl'));
	}

	$ctx['wancfg'] = $wancfg;
	return $pconfig;
}

/*
 * "Apply Changes": reconfigure every interface saved since the last apply
 * (from {tmp_path}/.interfaces.apply), then gateways monitoring, the
 * filter, RRD graphs and pending static routes. Returns the filter reload
 * result, or null with an input error when there was nothing to apply.
 */
function interfaces_edit_apply(&$input_errors = null) {
	$retval = null;
	if (!is_subsystem_dirty('interfaces')) {
		$input_errors[] = gettext("The settings have already been applied!");
	} else {
		$retval = 0;
		clear_subsystem_dirty('interfaces');

		$vlan_redo = [];
		if (file_exists(interfaces_edit_apply_file())) {
			$toapplylist = unserialize_data(file_get_contents(interfaces_edit_apply_file()), []);
			foreach ($toapplylist as $ifapply => $ifcfgo) {
				$realif = get_real_interface($ifapply);
				$ifmtu = get_interface_mtu($realif);
				if (config_path_enabled("interfaces/{$ifapply}")) {
					interface_bring_down($ifapply, false, $ifcfgo);
					interface_configure($ifapply, true);
					if (config_get_path("interfaces/{$ifapply}/ipaddrv6") == "track6") {
						/* call interface_track6_configure with linkup true so
						   IPv6 IPs are added back. dhcp6c needs a HUP. Can't
						   just call interface_configure with linkup true as
						   that skips bridge membership addition.
						*/
						$wancfg = config_get_path("interfaces/{$ifapply}");
						interface_track6_configure($ifapply, $wancfg, true);
					}
				} else {
					interface_bring_down($ifapply, true, $ifcfgo);
				}
				restart_interface_services($ifapply, array_get_path($ifcfgo, 'ifcfg/ipaddrv6'), true);
				$mtu = config_get_path("interfaces/{$ifapply}/mtu");
				if (interface_has_clones($realif) &&
				    ($mtu && ($mtu != $ifmtu)) ||
				    (!$mtu && (get_interface_default_mtu() != $ifmtu))) {
					$vlan_redo[] = $realif;
				}
			}
		}

		/*
		 * If the parent interface has changed MTU above, the VLANs needs to be
		 * redone.
		 */
		if (!empty($vlan_redo)) {
			foreach ($vlan_redo as $vlredo) {
				interfaces_vlan_configure_mtu($vlredo);
			}
		}

		/* sync filter configuration */
		setup_gateways_monitor();

		clear_subsystem_dirty('interfaces');

		$retval |= filter_configure();

		enable_rrd_graphing();

		if (is_subsystem_dirty('staticroutes')) {
			$routes_apply_file = g_get('tmp_path') . '/.system_routes.apply';
			if (file_exists($routes_apply_file)) {
				foreach (unserialize_data(file_get_contents($routes_apply_file), []) as $toapply) {
					mwexec("{$toapply}");
				}
				@unlink($routes_apply_file);
			}
		    if (system_routing_configure() == 0) {
				clear_subsystem_dirty('staticroutes');
			}
		}

		send_event("service reload packages");
	}
	@unlink(interfaces_edit_apply_file());
	return $retval;
}

/* Interfaces saved but not applied yet: array(pending => bool, interfaces => [names]). */
function interfaces_edit_pending() {
	$names = [];
	if (file_exists(interfaces_edit_apply_file())) {
		$names = array_keys((array)unserialize_data(file_get_contents(interfaces_edit_apply_file()), []));
	}
	return ['pending' => is_subsystem_dirty('interfaces'), 'interfaces' => array_values(array_map('strval', $names))];
}

/*
 * Validate and save the interface form ($post: the page's POST fields).
 * Returns true when the configuration was written: the "interfaces"
 * subsystem is then dirty and the change waits for interfaces_edit_apply().
 * Otherwise $input_errors holds the messages and $ctx['pconfig'] the form
 * values to show again (with $ctx['gateway_settings4'/'6'] for the "Add a
 * new gateway" dialogs). $ctx is the interface's context (built with the
 * wireless details when not given).
 */
function interfaces_edit_save($if, array $post, &$input_errors, ?array &$ctx = null) {
	if ($ctx === null) {
		$ctx = interfaces_edit_context($if, true);
	}
	$ifdescrs = $ctx['ifdescrs'];
	$bridged = $ctx['bridged'];
	$a_ppps = $ctx['a_ppps'];
	$old_ppps = $ctx['old_ppps'];
	$a_gateways = $ctx['a_gateways'];
	$wancfg = $ctx['wancfg'];
	$old_wancfg = $ctx['old_wancfg'];
	$realifname = $ctx['realifname'];
	$pppid = $ctx['pppid'];
	$wlanif = $ctx['wlanif'];
	$wlanbaseif = $ctx['wlanbaseif'];
	$wl_ht_list = is_array($ctx['wl_ht_list']) ? $ctx['wl_ht_list'] : [];
	$wl_countries_attr = $ctx['wl_countries_attr'];

	$gateway_settings4 = [];
	$gateway_settings6 = [];
	/* Never set: the page adds no gateway for dynamic types (save_gateway() below does). */
	$gateway_item = null;

	$pconfig = $post;

	if (ctype_xdigit($post['track6-prefix-id--hex'])) {
		array_set_path($pconfig, 'track6-prefix-id', intval($post['track6-prefix-id--hex'], 16));
	} else {
		array_set_path($pconfig, 'track6-prefix-id', 0);
	}

	/* filter out spaces from descriptions */
	$post['descr'] = remove_bad_chars($post['descr']);

	/* okay first of all, cause we are just hiding the PPPoE HTML
	 * fields related to PPPoE resets, we are going to unset $post
	 * vars, if the reset feature should not be used. Otherwise the
	 * data validation procedure below, may trigger a false error
	 * message.
	 */
	if (empty($post['pppoe-reset-type'])) {
		unset($post['pppoe_pr_type']);
		unset($post['pppoe_resethour']);
		unset($post['pppoe_resetminute']);
		unset($post['pppoe_resetdate']);
		unset($post['pppoe_pr_preset_val']);
	}

	/* input validation */
	$reqdfields = explode(" ", "descr");
	$reqdfieldsn = [gettext("Description")];
	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!$input_errors) {
		/* Reserved name? */
		if (get_pf_reserved($post['descr'], false)) {
			$input_errors[] = sprintf(gettext("Cannot use a reserved keyword as an interface name: %s"), $post['descr']);
		}

		/* description unique? */
		foreach ($ifdescrs as $ifent => $ifdescr) {
			if (($if != $ifent) &&
			    (strcasecmp($ifdescr, $post['descr']) == 0)) {
				$input_errors[] = gettext("An interface with the specified description already exists.");
				break;
			}
		}

		/* Is the description already used as an alias name? */
		foreach (config_get_path('aliases/alias', []) as $alias) {
			if (strcasecmp(array_get_path($alias, 'name'), $post['descr']) == 0) {
				$input_errors[] = sprintf(gettext("Sorry, an alias with the name %s already exists."), $post['descr']);
			}
		}

		/* Is the description already used as an interface group name? */
		foreach (config_get_path('ifgroups/ifgroupentry', []) as $ifgroupentry) {
			if (strcasecmp(array_get_path($ifgroupentry, 'ifname'), $post['descr']) == 0) {
				$input_errors[] = sprintf(gettext("Sorry, an interface group with the name %s already exists."), $post['descr']);
			}
		}

		if (is_numeric($post['descr'])) {
			$input_errors[] = gettext("The interface description cannot contain only numbers.");
		}

		if ((strlen(trim($post['descr'])) > 25) &&
		    ((substr($realifname, 0, 4) == 'ovpn') ||
		    (substr($realifname, 0, 5) == 'ipsec'))) {
			$input_errors[] = gettext("OpenVPN and VTI interface descriptions must be less than 26 characters long.");
		}

		if ((strlen(trim($post['descr'])) > 22) &&
		    ((substr($realifname, 0, 3) == 'gif') ||
		    (substr($realifname, 0, 3) == 'gre'))) {
			$input_errors[] = gettext("The GIF and GRE interface description must be less than 23 characters long.");
		}

		/*
		 * Packages (e.g. tinc) create interface groups, reserve this
		 * namespace pkg_ for them.
		 * One namespace is shared by Interfaces, Interface Groups and Aliases.
		 */
		if (substr($post['descr'], 0, 4) == 'pkg_') {
			$input_errors[] = gettext("The interface description cannot start with pkg_");
		}
	}

	if (($post['blockbogons'] == "yes") &&
	    config_path_enabled('system','ipv6allow') &&
	    (config_get_path('system/maximumtableentries', 0) <
	    g_get('minimumtableentries_bogonsv6'))) {
		$input_errors[] = sprintf(gettext(
		    "In order to block bogon networks the Firewall Maximum Table Entries value in System / Advanced / Firewall must be increased at least to %s."),
		    g_get('minimumtableentries_bogonsv6'));
	}

	if (config_path_enabled("dhcpd/{$if}")) {
		if (!preg_match("/^staticv4/", $post['type'])) {
			$input_errors[] = gettext("The DHCP Server is active " .
			    "on this interface and it can be used only with " .
			    "a static IP configuration. Please disable the " .
			    "DHCP Server service on this interface first, " .
			    "then change the interface configuration.");
		} elseif (!empty($post['subnet']) &&
			  ($post['subnet'] >= 31)) {
			$input_errors[] = gettext("The DHCP Server is active " .
			    "on this interface and it can be used only with " .
			    "IPv4 subnet < 31. Please disable the " .
			    "DHCP Server service on this interface first, " .
			    "then change the interface configuration.");
		}
	}
	if (!empty(config_get_path('dhcpdv6', [])) &&
	    (($post['type6'] != "staticv6") &&
	    ($post['type6'] != "track6"))) {
		if (config_path_enabled("dhcpdv6/{$if}")) {
			$input_errors[] = gettext("The DHCP6 Server is active on this interface and it can be used only " .
			    "with a static IPv6 configuration. Please disable the DHCPv6 Server service on this " .
			    "interface first, then change the interface configuration.");
		}
		if (config_get_path("dhcpdv6/{$if}/ramode", "disabled") != "disabled") {
			$input_errors[] = gettext("The Router Advertisements Server is active on this interface and it can " .
			    "be used only with a static IPv6 configuration. Please disable the Router Advertisements " .
			    "Server service on this interface first, then change the interface configuration.");
		}
	}

	switch (strtolower($post['type'])) {
		case "staticv4":
			$reqdfields = explode(" ", "ipaddr subnet gateway");
			$reqdfieldsn = [gettext("IPv4 address"), gettext("Subnet bit count"), gettext("Gateway")];
			do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
			break;
		case "none":
			foreach (config_get_path('virtualip/vip', []) as $vip) {
				if (is_ipaddrv4(array_get_path($vip, 'subnet')) &&
				    (array_get_path($vip, 'interface') == $if)) {
					$input_errors[] = gettext("This interface is referenced by IPv4 VIPs. Please delete these VIPs before setting the interface configuration type to 'none'.");
				}
			}
			break;
		case "ppp":
			$reqdfields = explode(" ", "port phone");
			$reqdfieldsn = [gettext("Modem Port"), gettext("Phone Number")];
			do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
			break;
		case "pppoe":
			if ($post['pppoe_dialondemand']) {
				$reqdfields = explode(" ", "pppoe_username pppoe_password pppoe_dialondemand pppoe_idletimeout");
				$reqdfieldsn = [gettext("PPPoE username"), gettext("PPPoE password"), gettext("Dial on demand"), gettext("Idle timeout value")];
			} else {
				$reqdfields = explode(" ", "pppoe_username pppoe_password");
				$reqdfieldsn = [gettext("PPPoE username"), gettext("PPPoE password")];
			}
			do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
			break;
		case "pptp":
			if ($post['pptp_dialondemand']) {
				$reqdfields = explode(" ", "pptp_username pptp_password pptp_local0 pptp_subnet0 pptp_remote0 pptp_dialondemand pptp_idletimeout");
				$reqdfieldsn = [gettext("PPTP username"), gettext("PPTP password"), gettext("PPTP local IP address"), gettext("PPTP subnet"), gettext("PPTP remote IP address"), gettext("Dial on demand"), gettext("Idle timeout value")];
			} else {
				$reqdfields = explode(" ", "pptp_username pptp_password pptp_local0 pptp_subnet0 pptp_remote0");
				$reqdfieldsn = [gettext("PPTP username"), gettext("PPTP password"), gettext("PPTP local IP address"), gettext("PPTP subnet"), gettext("PPTP remote IP address")];
			}
			do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
			break;
		case "l2tp":
			if ($post['pptp_dialondemand']) {
				$reqdfields = explode(" ", "pptp_username pptp_password pptp_remote0 pptp_dialondemand pptp_idletimeout");
				$reqdfieldsn = [gettext("L2TP username"), gettext("L2TP password"), gettext("L2TP remote IP address"), gettext("Dial on demand"), gettext("Idle timeout value")];
			} else {
				$reqdfields = explode(" ", "pptp_username pptp_password pptp_remote0");
				$reqdfieldsn = [gettext("L2TP username"), gettext("L2TP password"), gettext("L2TP remote IP address")];
			}
			do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
			break;
	}
	switch (strtolower($post['type6'])) {
		case "staticv6":
			$reqdfields = explode(" ", "ipaddrv6 subnetv6 gatewayv6");
			$reqdfieldsn = [gettext("IPv6 address"), gettext("Subnet bit count"), gettext("Gateway")];
			do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
			break;
		case "none":
			foreach (config_get_path('virtualip/vip', []) as $vip) {
				if (is_ipaddrv6(array_get_path($vip, 'subnet')) &&
				    (array_get_path($vip, 'interface') == $if)) {
					$input_errors[] = gettext("This interface is referenced by IPv6 VIPs. Please delete these VIPs before setting the interface configuration type to 'none'.");
				}
			}
			break;
		case "dhcp6":
			if (in_array(array_get_path($wancfg, 'ipaddrv6'), [])) {
				$input_errors[] = sprintf(gettext("The interface must be reassigned to configure as %s."), $post['type6']);
			}
			if ($post['dhcp6-ia-pd-send-hint'] &&
			    (strtolower($post['dhcp6-ia-pd-len']) == 'none')) {
				$input_errors[] = gettext('DHCPv6 Prefix Delegation size must be provided when Send IPv6 prefix hint flag is checked');
			}
			if (!empty($post['adv_dhcp6_id_assoc_statement_address_id']) &&
			    !is_numericint($post['adv_dhcp6_id_assoc_statement_address_id'])) {
				$input_errors[] = gettext('DHCPv6 advanced id-assoc na ID value must be empty or an integer.');
			}
			if (!empty($post['adv_dhcp6_id_assoc_statement_prefix_id']) &&
			    !is_numericint($post['adv_dhcp6_id_assoc_statement_prefix_id'])) {
				$input_errors[] = gettext('DHCPv6 advanced id-assoc pd ID value must be empty or an integer.');
			}
			if (!empty($post['adv_dhcp6_prefix_interface_statement_sla_id']) &&
			    !is_numericint($post['adv_dhcp6_prefix_interface_statement_sla_id'])) {
				$input_errors[] = gettext('DHCPv6 advanced Prefix Interface sla-id value must be empty or an integer.');
			}
			if (!empty($post['adv_dhcp6_prefix_interface_statement_sla_len']) &&
			    !is_numericint($post['adv_dhcp6_prefix_interface_statement_sla_len'])) {
				$input_errors[] = gettext('DHCPv6 advanced Prefix Interface sla-len value must be empty or an integer.');
			}
			break;
		case "6rd":
			foreach ($ifdescrs as $ifent => $ifdescr) {
				if (($if != $ifent) &&
				    (config_get_path("interfaces/{$ifent}/ipaddrv6") == $post['type6'])) {
					if (config_get_path("interfaces/{$ifent}/prefix-6rd") == $post['prefix-6rd']) {
						$input_errors[] = gettext("Only one interface can be configured within a single 6rd prefix.");
						break;
					}
				}
			}
			if (!is_subnetv6($post['prefix-6rd'])) {
				$input_errors[] = gettext("6RD Prefix must be a valid IPv6 prefix.");
			}
			if (!is_ipaddrv4($post['gateway-6rd'])) {
				$input_errors[] = gettext("6RD Border Relay must be an IPv4 address.");
			}
			if (in_array(array_get_path($wancfg, 'ipaddrv6'), [])) {
				$input_errors[] = sprintf(gettext("The interface must be reassigned to configure as %s."), $post['type6']);
			}
			break;
		case "6to4":
			foreach ($ifdescrs as $ifent => $ifdescr) {
				if (($if != $ifent) &&
				    (config_get_path("interfaces/{$ifent}/ipaddrv6") == $post['type6'])) {
					$input_errors[] = sprintf(gettext("Only one interface can be configured as 6to4."), $post['type6']);
					break;
				}
			}
			if (in_array(array_get_path($wancfg, 'ipaddrv6'), [])) {
				$input_errors[] = sprintf(gettext("The interface must be reassigned to configure as %s."), $post['type6']);
			}
			break;
		case "track6":
			/* needs to check if $track6-prefix-id is used on another interface */
			if (in_array(array_get_path($wancfg, 'ipaddrv6'), [])) {
				$input_errors[] = sprintf(gettext("The interface must be reassigned to configure as %s."), $post['type6']);
			}

			if (empty($post['track6-interface'])) {
				$input_errors[] = gettext("A valid interface to track must be selected.");
			}

			if (($post['track6-prefix-id--hex'] != "") &&
			    !ctype_xdigit($post['track6-prefix-id--hex'])) {
				$input_errors[] = gettext("A valid hexadecimal number must be entered for the IPv6 prefix ID.");
			} else {
				$track6_prefix_id = intval($post['track6-prefix-id--hex'], 16);
				if (($track6_prefix_id < 0) ||
				    ($track6_prefix_id > $post['ipv6-num-prefix-ids-' . $post['track6-interface']])) {
					$input_errors[] = gettext("The specified IPv6 Prefix ID is out of range.") .
						" ({$post['track6-interface']}) - (0) - (" . sprintf('%x', $post['ipv6-num-prefix-ids-' . $post['track6-interface']]) . ")";
				} else {
					foreach ($ifdescrs as $ifent => $ifdescr) {
						if ($if == $ifent) {
							continue;
						}
						if (config_get_path("interfaces/{$ifent}/ipaddrv6") == 'track6' &&
						    config_get_path("interfaces/{$ifent}/track6-interface") == $post['track6-interface'] &&
						    config_get_path("interfaces/{$ifent}/track6-prefix-id") == $track6_prefix_id) {
							$input_errors[] = sprintf(gettext("This track6 prefix ID is already being used in %s."), $ifdescr);
						}
					}
				}
			}
			break;
	}

	/* normalize MAC addresses - lowercase and convert Windows-ized hyphenated MACs to colon delimited */
	$staticroutes = get_staticroutes(true);
	$post['spoofmac'] = strtolower(str_replace("-", ":", $post['spoofmac']));
	if (($post['type'] == 'staticv4') &&
	    $post['ipaddr']) {
		$post['ipaddr'] = trim($post['ipaddr']);
		if (!is_ipaddrv4($post['ipaddr'])) {
			$input_errors[] = gettext("A valid IPv4 address must be specified.");
		} else {
			$where_ipaddr_configured = where_is_ipaddr_configured($post['ipaddr'], $if, true, true, $post['subnet']);
			if (count($where_ipaddr_configured)) {
				$subnet_conflict_text = sprintf(gettext("IPv4 address %s is being used by or overlaps with:"), $post['ipaddr'] . "/" . $post['subnet']);
				foreach ($where_ipaddr_configured as $subnet_conflict) {
					$subnet_conflict_text .= " " . convert_friendly_interface_to_friendly_descr($subnet_conflict['if']) . " (" . $subnet_conflict['ip_or_subnet'] . ")";
				}
				$input_errors[] = $subnet_conflict_text;
			}

			/* Do not accept network or broadcast address, except if subnet is 31 or 32 */
			if ($post['subnet'] < 31) {
				if ($post['ipaddr'] == gen_subnet($post['ipaddr'], $post['subnet'])) {
					$input_errors[] = gettext("This IPv4 address is the network address and cannot be used");
				} elseif ($post['ipaddr'] == gen_subnet_max($post['ipaddr'], $post['subnet'])) {
					$input_errors[] = gettext("This IPv4 address is the broadcast address and cannot be used");
				}
			}

			foreach ($staticroutes as $route_subnet) {
				list($network, $subnet) = explode("/", $route_subnet);
				if (($post['subnet'] == $subnet) &&
				    ($network == gen_subnet($post['ipaddr'], $post['subnet']))) {
					$input_errors[] = gettext("This IPv4 address conflicts with a Static Route.");
					break;
				}
				unset($network, $subnet);
			}
		}
	}
	if (($post['type6'] == 'staticv6') &&
	    $post['ipaddrv6']) {
		$post['ipaddrv6'] = trim(addrtolower($post['ipaddrv6']));

		if (!is_ipaddrv6($post['ipaddrv6'])) {
			$input_errors[] = gettext("A valid IPv6 address must be specified.");
		} else {
			if (ip_in_subnet($post['ipaddrv6'], "fe80::/10")) {
				$input_errors[] = gettext("IPv6 link local addresses cannot be configured as an interface IP address.");
			}
			$where_ipaddr_configured = where_is_ipaddr_configured($post['ipaddrv6'], $if, true, true, $post['subnetv6']);
			if (count($where_ipaddr_configured)) {
				$subnet_conflict_text = sprintf(gettext("IPv6 address %s is being used by or overlaps with:"), $post['ipaddrv6'] . "/" . $post['subnetv6']);
				foreach ($where_ipaddr_configured as $subnet_conflict) {
					$subnet_conflict_text .= " " . convert_friendly_interface_to_friendly_descr($subnet_conflict['if']) . " (" . array_get_path($subnet_conflict, 'ip_or_subnet') . ")";
				}
				$input_errors[] = $subnet_conflict_text;
			}

			foreach ($staticroutes as $route_subnet) {
				list($network, $subnet) = explode("/", $route_subnet);
				if (($post['subnetv6'] == $subnet) &&
				    ($network == gen_subnetv6($post['ipaddrv6'], $post['subnetv6']))) {
					$input_errors[] = gettext("This IPv6 address conflicts with a Static Route.");
					break;
				}
				unset($network, $subnet);
			}
		}
	}
	if (($post['subnet'] &&
	    !is_numeric($post['subnet']))) {
		$input_errors[] = gettext("A valid subnet bit count must be specified.");
	}
	if (($post['subnetv6'] &&
	    !is_numeric($post['subnetv6']))) {
		$input_errors[] = gettext("A valid subnet bit count must be specified.");
	}
	if (($post['alias-address'] &&
	    !is_ipaddrv4($post['alias-address']))) {
		$input_errors[] = gettext("A valid alias IP address must be specified.");
	}
	if (($post['alias-subnet'] &&
	    !is_numeric($post['alias-subnet']))) {
		$input_errors[] = gettext("A valid alias subnet bit count must be specified.");
	}
	if ($post['dhcprejectfrom'] &&
	    !validate_ipv4_list($post['dhcprejectfrom'])) {
		$input_errors[] = gettext("An invalid IP address was detected in the 'Reject leases from' field.");
	}

	// Only check the IPv4 gateway already exists if it is not "none" and it is not a gateway that the user is adding
	if (($post['gateway'] != "none") &&
	    (!$post['gatewayip4'] ||
	    ($post['gateway'] != $post['gatewayname4']))) {
		$match = false;
		foreach ($a_gateways as $gateway) {
			if (in_array($post['gateway'], $gateway)) {
				$match = true;
			}
		}
		if (!$match) {
			$input_errors[] = gettext("A valid IPv4 gateway must be specified.");
		}
	}
	// Only check the IPv6 gateway already exists if it is not "none" and it is not a gateway that the user is adding
	if (($post['gatewayv6'] != "none") &&
	    (!$post['gatewayip6'] ||
	    ($post['gatewayv6'] != $post['gatewayname6']))) {
		$match = false;
		foreach ($a_gateways as $gateway) {
			if (in_array($post['gatewayv6'], $gateway)) {
				$match = true;
			}
		}
		if (!$match) {
			$input_errors[] = gettext("A valid IPv6 gateway must be specified.");
		}
	}

	if ($post['provider'] &&
	    strpos($post['provider'], "\"")) {
		$input_errors[] = gettext("The service name may not contain quote characters.");
	}
	if ($post['hostuniq'] &&
	    !preg_match('/^[a-zA-Z0-9]+$/i', $post['hostuniq'])) {
		$input_errors[] = gettext("The Host-Uniq value can only be hexadecimal or letters and numbers.");
	}
	if (($post['pppoe_idletimeout'] != "") &&
	   !is_numericint($post['pppoe_idletimeout'])) {
		$input_errors[] = gettext("The idle timeout value must be an integer.");
	}
	if (($post['pppoe_resethour'] != "") &&
	    !is_numericint($post['pppoe_resethour']) &&
	    ($post['pppoe_resethour'] >= 0) &&
	    ($post['pppoe_resethour'] <= 23)) {
		$input_errors[] = gettext("A valid PPPoE reset hour must be specified (0-23).");
	}
	if (($post['pppoe_resetminute'] != "") &&
	    !is_numericint($post['pppoe_resetminute']) &&
	    ($post['pppoe_resetminute'] >= 0) &&
	    ($post['pppoe_resetminute'] <= 59)) {
		$input_errors[] = gettext("A valid PPPoE reset minute must be specified (0-59).");
	}
	if (($post['pppoe_resetdate'] != "") &&
	    !is_numeric(str_replace("/", "", $post['pppoe_resetdate']))) {
		$input_errors[] = gettext("A valid PPPoE reset date must be specified (mm/dd/yyyy).");
	}
	if (($post['pptp_local0'] &&
	    !is_ipaddrv4($post['pptp_local0']))) {
		$input_errors[] = gettext("A valid PPTP local IP address must be specified.");
	}
	if (($post['pptp_subnet0'] &&
	    !is_numeric($post['pptp_subnet0']))) {
		$input_errors[] = gettext("A valid PPTP subnet bit count must be specified.");
	}
	if (($post['pptp_remote0'] &&
	    !is_ipaddrv4($post['pptp_remote0']) &&
	    !is_hostname($post['pptp_remote0']))) {
		$input_errors[] = gettext("A valid PPTP remote IP address must be specified.");
	}
	if (($post['pptp_idletimeout'] != "") &&
	    !is_numericint($post['pptp_idletimeout'])) {
		$input_errors[] = gettext("The idle timeout value must be an integer.");
	}
	if (($post['spoofmac'] &&
	    !is_macaddr($post['spoofmac']))) {
		$input_errors[] = gettext("A valid MAC address must be specified.");
	}
	if ($post['mtu']) {
		if (!is_numericint($post['mtu'])) {
			$input_errors[] = "MTU must be an integer.";
		}
		if (substr(array_get_path($wancfg, 'if'), 0, 3) == 'gif') {
			$min_mtu = 1280;
			$max_mtu = 8192;
		} elseif (($post['ipaddrv6'] == '6rd') ||
			  ($post['ipaddrv6'] == '6to4')) {
			$min_mtu = 1300;
			$max_mtu = 9000;
		} else {
			$min_mtu = 576;
			$max_mtu = 9000;
		}

		if (($post['mtu'] < $min_mtu) ||
		    ($post['mtu'] > $max_mtu)) {
			$input_errors[] = sprintf(gettext("The MTU must be between %d and %d bytes."), $min_mtu, $max_mtu);
		}

		unset($min_mtu, $max_mtu);

		if (interface_is_vlan(array_get_path($wancfg, 'if')) != NULL) {
			$realhwif_array = get_parent_interface(array_get_path($wancfg, 'if'));
			// Need code to handle MLPPP if we ever use $realhwif for MLPPP handling
			$parent_realhwif = $realhwif_array[0];
			$parent_if = convert_real_interface_to_friendly_interface_name($parent_realhwif);
			$mtu = 0;
			if (!empty($parent_if) &&
			    !(config_get_path("interfaces/{$parent_if}/mtu"))) {
				$mtu = intval(config_get_path("interfaces/{$parent_if}/mtu"));
			}
			if ($mtu == 0) {
				$mtu = get_interface_mtu($parent_realhwif);
			}
			if ($post['mtu'] > $mtu) {
				$input_errors[] = gettext("The MTU of a VLAN cannot be greater than that of its parent interface.");
			}
		} else {
			foreach (config_get_path('interfaces', []) as $idx => $ifdata) {
				if (($idx == $if) ||
				    (interface_is_vlan(array_get_path($ifdata, 'if')) == NULL)) {
					continue;
				}

				$realhwif_array = get_parent_interface(array_get_path($ifdata, 'if'));
				// Need code to handle MLPPP if we ever use $realhwif for MLPPP handling
				$parent_realhwif = $realhwif_array[0];

				if ($parent_realhwif != array_get_path($wancfg, 'if')) {
					continue;
				}

				if (array_path_enabled($ifdata, '', 'mtu') &&
				    (array_get_path($ifdata, 'mtu') > $post['mtu'])) {
					$input_errors[] = sprintf(gettext("Interface %s (VLAN) has MTU set to a larger value."), array_get_path($ifdata, 'descr'));
				}
			}
		}
	}
	if ($post['mss'] != '') {
		if (!is_numericint($post['mss']) ||
		    (($post['mss'] < 576) ||
		    ($post['mss'] > 65535))) {
			$input_errors[] = gettext("The MSS must be an integer between 576 and 65535 bytes.");
		}
	}
	/* Wireless interface? */
	if (is_array(array_get_path($wancfg, 'wireless'))) {
		$reqdfields = ["mode"];
		$reqdfieldsn = [gettext("Mode")];
		if ($post['mode'] == 'hostap') {
			$reqdfields[] = "ssid";
			$reqdfieldsn[] = gettext("SSID");
			if (isset($post['channel']) &&
			    ($post['channel'] == "0")) {
				// auto channel with hostap is broken, prevent this for now.
				$input_errors[] = gettext("A specific channel, not auto, must be selected for Access Point mode.");
			}
		}
		if (!stristr($post['standard'], '11n') &&
		    ($post['channel_width'] != "0")) {
			$input_errors[] = gettext("Channel width selection is only supported by 802.11n standards.");
		}
		if (stristr($post['standard'], '11n')) {
			if (!($post['wme_enable'])) {
				$input_errors[] = gettext("802.11n standards require enabling WME.");
			}
			$whl = array_get_path($wl_ht_list, array_get_path($post, 'standard') . '/' . array_get_path($post, 'channel'), []);
			if (($post['channel_width'] != "0") &&
			    ($post['channel'] != "0") &&
			    !empty($whl) &&
			    !in_array($post['channel_width'], $whl)) {
				$input_errors[] = sprintf(gettext("Unable to use %s channel width with channel %s."), strtoupper($post['channel_width']), $post['channel']);
			}
		}
		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
		interfaces_edit_check_wireless_mode($post, $wancfg, $wlanif, $wlanbaseif, $input_errors);
		if (isset($post['wpa_group_rekey']) &&
		    (!is_numericint($post['wpa_group_rekey']) ||
		    ($post['wpa_group_rekey'] < 1) ||
		    ($post['wpa_group_rekey'] > 9999))) {
			$input_errors[] = gettext("Key Rotation must be an integer between 1 and 9999.");
		}
		if (isset($post['wpa_gmk_rekey']) &&
		    (!is_numericint($post['wpa_gmk_rekey']) ||
		    ($post['wpa_gmk_rekey'] < 1) ||
		    ($post['wpa_gmk_rekey'] > 9999))) {
			$input_errors[] = gettext("Master Key Regeneration must be an integer between 1 and 9999.");
		}
		if (isset($post['wpa_group_rekey']) &&
		    isset($post['wpa_gmk_rekey'])) {
			if ($post['wpa_group_rekey'] > $post['wpa_gmk_rekey']) {
				$input_errors[] = gettext("Master Key Regeneration must be greater than Key Rotation.");
			}
		}
		if (!empty($post['auth_server_addr'])) {
			if (!is_domain($post['auth_server_addr']) &&
			    !is_ipaddr($post['auth_server_addr'])) {
				$input_errors[] = gettext("802.1X Authentication Server must be an IP or hostname.");
			}
		}
		if (!empty($post['auth_server_addr2'])) {
			if (!is_domain($post['auth_server_addr2']) &&
			    !is_ipaddr($post['auth_server_addr2'])) {
				$input_errors[] = gettext("Secondary 802.1X Authentication Server must be an IP or hostname.");
			}
		}
		if (!empty($post['auth_server_port'])) {
			if (!is_port($post['auth_server_port'])) {
				$input_errors[] = gettext("802.1X Authentication Server Port must be a valid port number (1-65535).");
			}
		}
		if (!empty($post['auth_server_port2'])) {
			if (!is_port($post['auth_server_port2'])) {
				$input_errors[] = gettext("Secondary 802.1X Authentication Server Port must be a valid port number (1-65535).");
			}
		}
		if (isset($post['channel']) &&
		    !is_numericint($post['channel'])) {
			if (!is_numericint($post['channel'])) {
				$input_errors[] = gettext("Invalid channel specified.");
			} else {
				if (($post['channel'] > 255) ||
				    ($post['channel'] < 0)) {
					$input_errors[] = gettext("Channel must be between 0-255.");
				}
			}
		}
		if (!empty($post['distance']) &&
		    !is_numericint($post['distance'])) {
			$input_errors[] = gettext("Distance must be an integer.");
		}
		if (isset($post['standard']) &&
		    (stristr($post['standard'], '11na') ||
		    stristr($post['standard'], '11a'))) {
			if (($post['channel'] != 0) &&
			    ($post['channel'] < 15)) {
				$input_errors[] = gettext("Channel selected is not valid for 802.11a or 802.11na.");
			}
		}
		if (isset($post['standard']) &&
		    (($post['standard'] == "11b") ||
		    ($post['standard'] == "11g"))) {
			if ($post['channel'] > 14) {
				$input_errors[] = gettext("Channel selected is not valid for 802.11b or 802.11g.");
			}
		}
		if (!empty($post['protmode']) &&
		    !in_array($post['protmode'], ["off", "cts", "rtscts"])) {
			$input_errors[] = gettext("Invalid option chosen for OFDM Protection Mode");
		}

		if ($post['passphrase']) {
			$passlen = strlen($post['passphrase']);
			if (($passlen < 8) ||
			    ($passlen > 63)) {
				$input_errors[] = gettext("The WPA passphrase must be between 8 and 63 characters long.");
			}
		}

		if ($post['wpa_enable'] == "yes") {
			if (empty($post['passphrase']) &&
			    stristr($post['wpa_key_mgmt'], "WPA-PSK")) {
				$input_errors[] = gettext("A WPA Passphrase must be specified when WPA PSK is enabled.");
			}
			if (($post['mode'] == 'bss') &&
			    ($post['wpa_key_mgmt'] == "WPA-EAP") &&
			    ($post['wpa_eap_client_mode'] != 'tls')) {
				if (empty($post['wpa_eap_inner_id'])) {
					$input_errors[] = gettext("An Inner Authentication Identity must be specified " .
					    "when PEAP/TTLS authentication method is selected.");
				}
				if (empty($post['wpa_eap_inner_password'])) {
					$input_errors[] = gettext("An Inner Authentication Passphrase must be specified " .
					    "when PEAP/TTLS authentication method is selected.");
				}
			}
		}
	}

	if ($post['ppp_password'] != $post['ppp_password_confirm']) {
		$input_errors[] = gettext("PPP Password and confirmed password must match!");
	}

	if ($post['pppoe_password'] != $post['pppoe_password_confirm']) {
		$input_errors[] = gettext("PPPoE Password and confirmed password must match!");
	}

	if ($post['pptp_password'] != $post['pptp_password_confirm']) {
		$input_errors[] = gettext("PTPP Password and confirmed password must match!");
	}

	if ($post['gatewayip4']) {
		// The user wants to add an IPv4 gateway - validate the settings
		$gateway_settings4 = [
			'name' => $post['gatewayname4'],
			'interface' => $post['if'],
			'gateway' => $post['gatewayip4'],
			'descr' => $post['gatewaydescr4'],
			'defaultgw' => $post['defaultgw4'],
			'ipprotocol' => 'inet',
		];
		$gw_input_errors = validate_gateway($gateway_settings4, '', $post['ipaddr'], $post['subnet']);
		foreach ($gw_input_errors as $input_error_text) {
			$input_errors[] = $input_error_text;
		}
	}

	if ($post['gatewayip6']) {
		// The user wants to add an IPv6 gateway - validate the settings
		$gateway_settings6 = [
			'name' => $post['gatewayname6'],
			'interface' => $post['if'],
			'gateway' => $post['gatewayip6'],
			'descr' => $post['gatewaydescr6'],
			'defaultgw' => $post['defaultgw6'],
			'ipprotocol' => 'inet6',
		];
		$gw_input_errors = validate_gateway($gateway_settings6, '', $post['ipaddrv6'], $post['subnetv6']);
		foreach ($gw_input_errors as $input_error_text) {
			$input_errors[] = $input_error_text;
		}
	}

	if (!$input_errors) {
		$poriginal = [];
		// These 3 fields can be a list of multiple data items when used for MLPPP.
		// The UI in this code only processes the first of the list, so save the data here then we can preserve any other entries.
		array_set_path($poriginal, 'pptp_localip', explode(",", array_get_path($a_ppps, "{$pppid}/localip")));
		array_set_path($poriginal, 'pptp_subnet',  explode(",", array_get_path($a_ppps, "{$pppid}/subnet")));
		array_set_path($poriginal, 'pptp_remote',  explode(",", array_get_path($a_ppps, "{$pppid}/gateway")));

		if (array_get_path($wancfg, 'ipaddr') != $post['type']) {
			if (in_array(array_get_path($wancfg, 'ipaddr'), ["ppp", "pppoe", "pptp", "l2tp"])) {
				array_set_path($wancfg, 'if', array_get_path($a_ppps, "{$pppid}/ports"));
				array_del_path($a_ppps, $pppid);
			} elseif (array_get_path($wancfg, 'ipaddr') == "dhcp") {
				kill_dhclient_process(array_get_path($wancfg, 'if'));
			}
			if ((array_get_path($wancfg, 'ipaddrv6') == "dhcp6") &&
			    ($post['type6'] != "dhcp6")) {
				interface_dhcpv6_configure($if, $wancfg, true);
			}
		}
		$ppp = [];
		if (array_get_path($wancfg, 'ipaddr') != "ppp") {
			array_del_path($wancfg, 'ipaddr');
		}
		if (array_get_path($wancfg, 'ipaddrv6') != "ppp") {
			array_del_path($wancfg, 'ipaddrv6');
		}

		$wancfg_to_delelete = [
			'subnet',
			'gateway',
			'subnetv6',
			'gatewayv6',
			'dhcphostname',
			'dhcprejectfrom',
			'dhcp6-duid',
			'dhcp6-ia-pd-len',
			'dhcp6-ia-pd-send-hint',
			'dhcp6prefixonly',
			'dhcp6usev4iface',
			'slaacusev4iface',
			'ipv6usev4iface',
			'track6-interface',
			'track6-prefix-id',
			'dhcp6withoutra',
			'dhcp6vlanenable',
			'dhcp6cvpt',
			'prefix-6rd',
			'prefix-6rd-v4plen',
			'gateway-6rd',
			'dhcpvlanenable',
			'dhcpcvpt',
			'adv_dhcp_pt_timeout',
			'adv_dhcp_pt_retry',
			'adv_dhcp_pt_select_timeout',
			'adv_dhcp_pt_reboot',
			'adv_dhcp_pt_backoff_cutoff',
			'adv_dhcp_pt_initial_interval',
			'adv_dhcp_pt_values',
			'adv_dhcp_send_options',
			'adv_dhcp_request_options',
			'adv_dhcp_required_options',
			'adv_dhcp_option_modifiers',
			'adv_dhcp_config_advanced',
			'adv_dhcp_config_file_override',
			'adv_dhcp_config_file_override_path',
			'adv_dhcp6_interface_statement_send_options',
			'adv_dhcp6_interface_statement_request_options',
			'adv_dhcp6_interface_statement_information_only_enable',
			'adv_dhcp6_interface_statement_script',
			'adv_dhcp6_id_assoc_statement_address_enable',
			'adv_dhcp6_id_assoc_statement_address',
			'adv_dhcp6_id_assoc_statement_address_id',
			'adv_dhcp6_id_assoc_statement_address_pltime',
			'adv_dhcp6_id_assoc_statement_address_vltime',
			'adv_dhcp6_id_assoc_statement_prefix_enable',
			'adv_dhcp6_id_assoc_statement_prefix',
			'adv_dhcp6_id_assoc_statement_prefix_id',
			'adv_dhcp6_id_assoc_statement_prefix_pltime',
			'adv_dhcp6_id_assoc_statement_prefix_vltime',
			'adv_dhcp6_prefix_interface_statement_sla_id',
			'adv_dhcp6_prefix_interface_statement_sla_len',
			'adv_dhcp6_prefix_selected_interface',
			'adv_dhcp6_authentication_statement_authname',
			'adv_dhcp6_authentication_statement_protocol',
			'adv_dhcp6_authentication_statement_algorithm',
			'adv_dhcp6_authentication_statement_rdm',
			'adv_dhcp6_key_info_statement_keyname',
			'adv_dhcp6_key_info_statement_realm',
			'adv_dhcp6_key_info_statement_keyid',
			'adv_dhcp6_key_info_statement_secret',
			'adv_dhcp6_key_info_statement_expire',
			'adv_dhcp6_config_advanced',
			'adv_dhcp6_config_file_override',
			'adv_dhcp6_config_file_override_path',
			'pppoe_password',
			'pptp_username',
			'pptp_password',
			'l2tp_secret',
			'provider',
			'hostuniq',
			'ondemand',
			'timeout',
			'local',
			'remote',
		];
		foreach ($wancfg_to_delelete as $todel) {
			array_del_path($wancfg, $todel);
		}

		if (empty(array_get_path($wancfg, 'pppoe/pppoe-reset-type'))) {
			array_del_path($wancfg, 'pppoe/pppoe-reset-type');
		}
		if (isset($pppid) && is_array(array_get_path($a_ppps, $pppid)) &&
		    in_array(array_get_path($wancfg, 'ipaddr'), ["ppp", "pppoe", "pptp", "l2tp"])) {
			if (array_get_path($wancfg, 'ipaddr') != 'ppp') {
				array_del_path($a_ppps, "{$pppid}/apn");
				array_del_path($a_ppps, "{$pppid}/phone");
				array_del_path($a_ppps, "{$pppid}/provider");
				array_del_path($a_ppps, "{$pppid}/ondemand");
			}
			if (in_array(array_get_path($wancfg, 'ipaddr'), ["pppoe", "pptp", "l2tp"])) {
				array_del_path($a_ppps, "{$pppid}/localip");
				array_del_path($a_ppps, "{$pppid}/subnet");
				array_del_path($a_ppps, "{$pppid}/gateway");
			}
			if (array_get_path($wancfg, 'ipaddr') != 'pppoe') {
				array_del_path($a_ppps, "{$pppid}/pppoe-reset-type");
				array_del_path($a_ppps, "{$pppid}/hostuniq");
			}
			if (array_get_path($wancfg, 'type') != $post['type']) {
				array_del_path($a_ppps, "{$pppid}/idletimeout");
			}
		}

		array_set_path($wancfg, 'descr', remove_bad_chars($post['descr']));
		array_set_path($wancfg, 'enable', (($post['enable'] == "yes") ? true : false));

		/* let return_gateways_array() do the magic on dynamic interfaces for us */
		switch ($post['type']) {
			case "staticv4":
				array_set_path($wancfg, 'ipaddr', $post['ipaddr']);
				array_set_path($wancfg, 'subnet', $post['subnet']);
				if ($post['gateway'] != "none") {
					array_set_path($wancfg, 'gateway', $post['gateway']);
				}
				break;
			case "dhcp":
				array_set_path($wancfg, 'ipaddr', 'dhcp');

				array_set_path($wancfg, 'dhcphostname', $post['dhcphostname']);
				array_set_path($wancfg, 'alias-address', $post['alias-address']);
				array_set_path($wancfg, 'alias-subnet', $post['alias-subnet']);
				array_set_path($wancfg, 'dhcprejectfrom', $post['dhcprejectfrom']);

				array_set_path($wancfg, 'adv_dhcp_pt_timeout', $post['adv_dhcp_pt_timeout']);
				array_set_path($wancfg, 'adv_dhcp_pt_retry', $post['adv_dhcp_pt_retry']);
				array_set_path($wancfg, 'adv_dhcp_pt_select_timeout', $post['adv_dhcp_pt_select_timeout']);
				array_set_path($wancfg, 'adv_dhcp_pt_reboot', $post['adv_dhcp_pt_reboot']);
				array_set_path($wancfg, 'adv_dhcp_pt_backoff_cutoff', $post['adv_dhcp_pt_backoff_cutoff']);
				array_set_path($wancfg, 'adv_dhcp_pt_initial_interval', $post['adv_dhcp_pt_initial_interval']);
				array_set_path($wancfg, 'adv_dhcp_pt_values', $post['adv_dhcp_pt_values']);

				array_set_path($wancfg, 'adv_dhcp_send_options', $post['adv_dhcp_send_options']);
				array_set_path($wancfg, 'adv_dhcp_request_options', $post['adv_dhcp_request_options']);
				array_set_path($wancfg, 'adv_dhcp_required_options', $post['adv_dhcp_required_options']);
				array_set_path($wancfg, 'adv_dhcp_option_modifiers', $post['adv_dhcp_option_modifiers']);

				array_set_path($wancfg, 'adv_dhcp_config_advanced', $post['adv_dhcp_config_advanced']);
				array_set_path($wancfg, 'adv_dhcp_config_file_override', $post['adv_dhcp_config_file_override']);
				array_set_path($wancfg, 'adv_dhcp_config_file_override_path', $post['adv_dhcp_config_file_override_path']);

				array_set_path($wancfg, 'dhcp_plus', (($post['dhcp_plus'] == "yes") ? true : false));

				if ($gateway_item) {
					$a_gateways[] = $gateway_item;
				}
				if ($post['dhcpvlanenable'] == "yes") {
					array_set_path($wancfg, 'dhcpvlanenable', true);
				}
				if (!empty($post['dhcpcvpt'])) {
					array_set_path($wancfg, 'dhcpcvpt', $post['dhcpcvpt']);
				} else {
					array_del_path($wancfg, 'dhcpcvpt');
				}
				break;
			case "ppp":
				if (!isset($pppid)) {
					break;
				}
				array_set_path($a_ppps, "{$pppid}/ptpid", $post['ptpid']);
				array_set_path($a_ppps, "{$pppid}/type", $post['type']);
				array_set_path($a_ppps, "{$pppid}/if", $post['type'] . $post['ptpid']);
				array_set_path($a_ppps, "{$pppid}/ports", $post['port']);
				array_set_path($a_ppps, "{$pppid}/username", $post['ppp_username']);
				if ($post['ppp_password'] != DMYPWD) {
					array_set_path($a_ppps, "{$pppid}/password", base64_encode($post['ppp_password']));
				}
				array_set_path($a_ppps, "{$pppid}/phone", $post['phone']);
				array_set_path($a_ppps, "{$pppid}/apn", $post['apn']);

				array_set_path($wancfg, 'if', $post['type'] . $post['ptpid']);
				array_set_path($wancfg, 'ipaddr', $post['type']);
				break;
			case "pppoe":
				if (!isset($pppid)) {
					break;
				}
				array_set_path($a_ppps, "{$pppid}/ptpid", $post['ptpid']);
				array_set_path($a_ppps, "{$pppid}/type", $post['type']);
				array_set_path($a_ppps, "{$pppid}/if", $post['type'] . $post['ptpid']);
				if (isset($post['ppp_port'])) {
					array_set_path($a_ppps, "{$pppid}/ports", $post['ppp_port']);
				} else {
					array_set_path($a_ppps, "{$pppid}/ports", array_get_path($wancfg, 'if'));
				}
				array_set_path($a_ppps, "{$pppid}/username", $post['pppoe_username']);
				if ($post['pppoe_password'] != DMYPWD) {
					array_set_path($a_ppps, "{$pppid}/password", base64_encode($post['pppoe_password']));
				}
				if (!empty($post['provider'])) {
					array_set_path($a_ppps, "{$pppid}/provider", $post['provider']);
				}
				if (!empty($post['hostuniq'])) {
					array_set_path($a_ppps, "{$pppid}/hostuniq", strtolower($post['hostuniq']));
				}
				array_set_path($a_ppps, "{$pppid}/ondemand", ($post['pppoe_dialondemand'] ? true : false));

				if (!empty($post['pppoe_idletimeout'])) {
					array_set_path($a_ppps, "{$pppid}/idletimeout", $post['pppoe_idletimeout']);
				} else {
					array_del_path($a_ppps, "{$pppid}/idletimeout");
				}

				if (!empty($post['pppoe-reset-type'])) {
					array_set_path($a_ppps, "{$pppid}/pppoe-reset-type", $post['pppoe-reset-type']);
				} else {
					array_del_path($a_ppps, "{$pppid}/pppoe-reset-type");
				}

				array_set_path($wancfg, 'if', $post['type'] . $post['ptpid']);
				array_set_path($wancfg, 'ipaddr', $post['type']);
				if ($gateway_item) {
					$a_gateways[] = $gateway_item;
				}

				break;
			case "pptp":
			case "l2tp":
				if (!isset($pppid)) {
					break;
				}
				array_set_path($a_ppps, "{$pppid}/ptpid", $post['ptpid']);
				array_set_path($a_ppps, "{$pppid}/type", $post['type']);
				array_set_path($a_ppps, "{$pppid}/if", $post['type'] . $post['ptpid']);
				if (isset($post['ppp_port'])) {
					array_set_path($a_ppps, "{$pppid}/ports", $post['ppp_port']);
				} else {
					array_set_path($a_ppps, "{$pppid}/ports", array_get_path($wancfg, 'if'));
				}
				array_set_path($a_ppps, "{$pppid}/username", $post['pptp_username']);
				if ($post['pptp_password'] != DMYPWD) {
					array_set_path($a_ppps, "{$pppid}/password", base64_encode($post['pptp_password']));
				}
				if (($post['type'] == 'l2tp') &&
				    !empty($post['l2tp_secret'])) {
					array_set_path($a_ppps, "{$pppid}/secret", base64_encode($post['l2tp_secret']));
				} else {
					array_del_path($a_ppps, "{$pppid}/secret");
				}
				// Replace the first (0) entry with the posted data. Preserve any other entries that might be there.
				array_set_path($poriginal, 'pptp_localip/0', $post['pptp_local0']);
				array_set_path($a_ppps, "{$pppid}/localip", implode(',', array_get_path($poriginal, 'pptp_localip')));
				array_set_path($poriginal, 'pptp_subnet/0', $post['pptp_subnet0']);
				array_set_path($a_ppps, "{$pppid}/subnet", implode(',', array_get_path($poriginal, 'pptp_subnet')));
				array_set_path($poriginal, 'pptp_remote/0', $post['pptp_remote0']);
				array_set_path($a_ppps, "{$pppid}/gateway", implode(',', array_get_path($poriginal, 'pptp_remote')));
				array_set_path($a_ppps, "{$pppid}/ondemand", ($post['pptp_dialondemand'] ? true : false));
				if (!empty($post['pptp_idletimeout'])) {
					array_set_path($a_ppps, "{$pppid}/idletimeout", $post['pptp_idletimeout']);
				} else {
					array_del_path($a_ppps, "{$pppid}/idletimeout");
				}
				array_set_path($wancfg, 'if', $post['type'] . $post['ptpid']);
				array_set_path($wancfg, 'ipaddr', $post['type']);
				if ($gateway_item) {
					$a_gateways[] = $gateway_item;
				}
				break;
			case "none":
				break;
		}
		switch ($post['type6']) {
			case "staticv6":
				array_set_path($wancfg, 'ipaddrv6', $post['ipaddrv6']);
				array_set_path($wancfg, 'subnetv6', $post['subnetv6']);
				if ($post['ipv6usev4iface'] == "yes") {
					array_set_path($wancfg, 'ipv6usev4iface', true);
				}
				if ($post['gatewayv6'] != "none") {
					array_set_path($wancfg, 'gatewayv6', $post['gatewayv6']);
				}
				break;
			case "slaac":
				array_set_path($wancfg, 'ipaddrv6', 'slaac');
				if ($post['slaacusev4iface'] == "yes") {
					array_set_path($wancfg, 'slaacusev4iface', true);
				}
				break;
			case "dhcp6":
				array_set_path($wancfg, 'ipaddrv6', 'dhcp6');
				array_set_path($wancfg, 'dhcp6-duid', $post['dhcp6-duid']);
				array_set_path($wancfg, 'dhcp6-ia-pd-len', $post['dhcp6-ia-pd-len']);
				if ($post['dhcp6-ia-pd-send-hint'] == "yes") {
					array_set_path($wancfg, 'dhcp6-ia-pd-send-hint', true);
				}
				if ($post['dhcp6prefixonly'] == "yes") {
					array_set_path($wancfg, 'dhcp6prefixonly', true);
				}
				if ($post['dhcp6usev4iface'] == "yes") {
					array_set_path($wancfg, 'dhcp6usev4iface', true);
				}
				if ($post['dhcp6withoutra'] == "yes") {
					array_set_path($wancfg, 'dhcp6withoutra', true);
				}
				if ($post['dhcp6vlanenable'] == "yes") {
					array_set_path($wancfg, 'dhcp6vlanenable', true);
				}
				if (!empty($post['dhcp6cvpt'])) {
					array_set_path($wancfg, 'dhcp6cvpt', $post['dhcp6cvpt']);
				} else {
					array_del_path($wancfg, 'dhcp6cvpt');
				}

				if (!empty($post['adv_dhcp6_interface_statement_send_options'])) {
					array_set_path($wancfg, 'adv_dhcp6_interface_statement_send_options', $post['adv_dhcp6_interface_statement_send_options']);
				}
				if (!empty($post['adv_dhcp6_interface_statement_request_options'])) {
					array_set_path($wancfg, 'adv_dhcp6_interface_statement_request_options', $post['adv_dhcp6_interface_statement_request_options']);
				}
				if (isset($post['adv_dhcp6_interface_statement_information_only_enable'])) {
					array_set_path($wancfg, 'adv_dhcp6_interface_statement_information_only_enable', $post['adv_dhcp6_interface_statement_information_only_enable']);
				}
				if (!empty($post['adv_dhcp6_interface_statement_script'])) {
					array_set_path($wancfg, 'adv_dhcp6_interface_statement_script', $post['adv_dhcp6_interface_statement_script']);
				}

				if (isset($post['adv_dhcp6_id_assoc_statement_address_enable'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_address_enable', $post['adv_dhcp6_id_assoc_statement_address_enable']);
				}
				if (!empty($post['adv_dhcp6_id_assoc_statement_address'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_address', $post['adv_dhcp6_id_assoc_statement_address']);
				}
				if (is_numericint($post['adv_dhcp6_id_assoc_statement_address_id'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_address_id', $post['adv_dhcp6_id_assoc_statement_address_id']);
				}
				if (!empty($post['adv_dhcp6_id_assoc_statement_address_pltime'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_address_pltime', $post['adv_dhcp6_id_assoc_statement_address_pltime']);
				}
				if (!empty($post['adv_dhcp6_id_assoc_statement_address_vltime'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_address_vltime', $post['adv_dhcp6_id_assoc_statement_address_vltime']);
				}

				if (isset($post['adv_dhcp6_id_assoc_statement_prefix_enable'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_prefix_enable', $post['adv_dhcp6_id_assoc_statement_prefix_enable']);
				}
				if (!empty($post['adv_dhcp6_id_assoc_statement_prefix'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_prefix', $post['adv_dhcp6_id_assoc_statement_prefix']);
				}
				if (is_numericint($post['adv_dhcp6_id_assoc_statement_prefix_id'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_prefix_id', $post['adv_dhcp6_id_assoc_statement_prefix_id']);
				}
				if (!empty($post['adv_dhcp6_id_assoc_statement_prefix_pltime'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_prefix_pltime', $post['adv_dhcp6_id_assoc_statement_prefix_pltime']);
				}
				if (!empty($post['adv_dhcp6_id_assoc_statement_prefix_vltime'])) {
					array_set_path($wancfg, 'adv_dhcp6_id_assoc_statement_prefix_vltime', $post['adv_dhcp6_id_assoc_statement_prefix_vltime']);
				}

				if (is_numericint($post['adv_dhcp6_prefix_interface_statement_sla_id'])) {
					array_set_path($wancfg, 'adv_dhcp6_prefix_interface_statement_sla_id', $post['adv_dhcp6_prefix_interface_statement_sla_id']);
				}
				if (is_numericint($post['adv_dhcp6_prefix_interface_statement_sla_len'])) {
					array_set_path($wancfg, 'adv_dhcp6_prefix_interface_statement_sla_len', $post['adv_dhcp6_prefix_interface_statement_sla_len']);
				}
				if (!empty($post['adv_dhcp6_prefix_selected_interface'])) {
					array_set_path($wancfg, 'adv_dhcp6_prefix_selected_interface', $post['adv_dhcp6_prefix_selected_interface']);
				}
				if (!empty($post['adv_dhcp6_authentication_statement_authname'])) {
					array_set_path($wancfg, 'adv_dhcp6_authentication_statement_authname', $post['adv_dhcp6_authentication_statement_authname']);
				}
				if (!empty($post['adv_dhcp6_authentication_statement_protocol'])) {
					array_set_path($wancfg, 'adv_dhcp6_authentication_statement_protocol', $post['adv_dhcp6_authentication_statement_protocol']);
				}
				if (!empty($post['adv_dhcp6_authentication_statement_algorithm'])) {
					array_set_path($wancfg, 'adv_dhcp6_authentication_statement_algorithm', $post['adv_dhcp6_authentication_statement_algorithm']);
				}
				if (!empty($post['adv_dhcp6_authentication_statement_rdm'])) {
					array_set_path($wancfg, 'adv_dhcp6_authentication_statement_rdm', $post['adv_dhcp6_authentication_statement_rdm']);
				}

				if (!empty($post['adv_dhcp6_key_info_statement_keyname'])) {
					array_set_path($wancfg, 'adv_dhcp6_key_info_statement_keyname', $post['adv_dhcp6_key_info_statement_keyname']);
				}
				if (!empty($post['adv_dhcp6_key_info_statement_realm'])) {
					array_set_path($wancfg, 'adv_dhcp6_key_info_statement_realm', $post['adv_dhcp6_key_info_statement_realm']);
				}
				if (!empty($post['adv_dhcp6_key_info_statement_keyid'])) {
					array_set_path($wancfg, 'adv_dhcp6_key_info_statement_keyid', $post['adv_dhcp6_key_info_statement_keyid']);
				}
				if (!empty($post['adv_dhcp6_key_info_statement_secret'])) {
					array_set_path($wancfg, 'adv_dhcp6_key_info_statement_secret', $post['adv_dhcp6_key_info_statement_secret']);
				}
				if (!empty($post['adv_dhcp6_key_info_statement_expire'])) {
					array_set_path($wancfg, 'adv_dhcp6_key_info_statement_expire', $post['adv_dhcp6_key_info_statement_expire']);
				}

				if (!empty($post['adv_dhcp6_config_advanced'])) {
					array_set_path($wancfg, 'adv_dhcp6_config_advanced', $post['adv_dhcp6_config_advanced']);
				}
				if (!empty($post['adv_dhcp6_config_file_override'])) {
					array_set_path($wancfg, 'adv_dhcp6_config_file_override', $post['adv_dhcp6_config_file_override']);
				}
				if (!empty($post['adv_dhcp6_config_file_override_path'])) {
					array_set_path($wancfg, 'adv_dhcp6_config_file_override_path', $post['adv_dhcp6_config_file_override_path']);
				}

				if ($gateway_item) {
					$a_gateways[] = $gateway_item;
				}
				break;
			case "6rd":
				array_set_path($wancfg, 'ipaddrv6', '6rd');
				array_set_path($wancfg, 'prefix-6rd', $post['prefix-6rd']);
				array_set_path($wancfg, 'prefix-6rd-v4plen', $post['prefix-6rd-v4plen']);
				array_set_path($wancfg, 'gateway-6rd', $post['gateway-6rd']);
				if ($gateway_item) {
					$a_gateways[] = $gateway_item;
				}
				break;
			case "6to4":
				array_set_path($wancfg, 'ipaddrv6', '6to4');
				break;
			case "track6":
				array_set_path($wancfg, 'ipaddrv6', 'track6');
				array_set_path($wancfg, 'track6-interface', $post['track6-interface']);
				if ($post['track6-prefix-id--hex'] === "") {
					array_set_path($wancfg, 'track6-prefix-id', 0);
				} elseif (ctype_xdigit($post['track6-prefix-id--hex'])) {
					array_set_path($wancfg, 'track6-prefix-id', intval($post['track6-prefix-id--hex'], 16));
				} else {
					array_set_path($wancfg, 'track6-prefix-id', 0);
				}
				break;
			case "none":
				break;
		}
		handle_pppoe_reset($post);

		if ($post['blockpriv'] == "yes") {
			array_set_path($wancfg, 'blockpriv', true);
		} else {
			array_del_path($wancfg, 'blockpriv');
		}
		if ($post['blockbogons'] == "yes") {
			array_set_path($wancfg, 'blockbogons', true);
		} else {
			array_del_path($wancfg, 'blockbogons');
		}
		array_set_path($wancfg, 'spoofmac', $post['spoofmac']);
		/* Only update MTU in the config if the if is not a member of a
		 * bridge. The display will show the bridge MTU in a disabled input
		 * field, and we will maintain the user configured MTU for initial
		 * configuration and in the event that the interface is removed from the
		 * bridge it will return to its original MTU. */
		if (!$bridged) {
			if (empty($post['mtu'])) {
				array_del_path($wancfg, 'mtu');
			} else {
				array_set_path($wancfg, 'mtu', $post['mtu']);
			}
		}
		if (empty($post['mss'])) {
			array_del_path($wancfg, 'mss');
		} else {
			array_set_path($wancfg, 'mss', $post['mss']);
		}
		if (empty($post['mediaopt'])) {
			array_del_path($wancfg, 'media');
			array_del_path($wancfg, 'mediaopt');
		} else {
			$mediaopts = explode(' ', $post['mediaopt']);
			if ($mediaopts[0] != '') {
				array_set_path($wancfg, 'media', $mediaopts[0]);
			}
			if ($mediaopts[1] != '') {
				array_set_path($wancfg, 'mediaopt', $mediaopts[1]);
			} else {
				array_del_path($wancfg, 'mediaopt');
			}
		}
		if (!empty(array_get_path($wancfg, 'wireless'))) {
			interfaces_edit_handle_wireless_post($post, $wancfg, $wlanbaseif, $wl_countries_attr);
		}

		config_set_path('ppps/ppp', $a_ppps);
		config_set_path('gateways/gateway_item', $a_gateways);
		config_set_path("interfaces/{$if}", $wancfg);
		write_config("Interfaces settings changed");

		if ($post['gatewayip4']) {
			save_gateway($gateway_settings4);
		}

		if ($post['gatewayip6']) {
			save_gateway($gateway_settings6);
		}

		if (file_exists(interfaces_edit_apply_file())) {
			$toapplylist = unserialize_data(file_get_contents(interfaces_edit_apply_file()), []);
		} else {
			$toapplylist = [];
		}
		array_set_path($toapplylist, "{$if}/ifcfg", $old_wancfg);
		array_set_path($toapplylist, "{$if}/ppps", $old_ppps);
		file_put_contents(interfaces_edit_apply_file(), serialize($toapplylist));

		mark_subsystem_dirty('interfaces');

		/* regenerate cron settings/crontab file */
		configure_cron();
		return true;
	}

	/* keep port value, see upstream issue 12498 */
	if (!empty($post['ppp_port'])) {
		array_set_path($pconfig, 'port', $post['ppp_port']);
	}

	$ctx['pconfig'] = $pconfig;
	$ctx['gateway_settings4'] = $gateway_settings4;
	$ctx['gateway_settings6'] = $gateway_settings6;
	return false;
}

/* The wireless part of a save: the posted wireless fields into $wancfg (then shared with the other clones of the radio). */
function interfaces_edit_handle_wireless_post(array &$post, array &$wancfg, $wlanbaseif, $wl_countries_attr) {
	array_set_path($wancfg, 'wireless/standard', $post['standard']);
	array_set_path($wancfg, 'wireless/mode', $post['mode']);
	array_set_path($wancfg, 'wireless/protmode', $post['protmode']);
	array_set_path($wancfg, 'wireless/ssid', $post['ssid']);
	array_set_path($wancfg, 'wireless/channel', $post['channel']);
	array_set_path($wancfg, 'wireless/channel_width', $post['channel_width']);
	array_set_path($wancfg, 'wireless/authmode', $post['authmode']);
	array_set_path($wancfg, 'wireless/txpower', $post['txpower']);
	array_set_path($wancfg, 'wireless/distance', $post['distance']);
	array_set_path($wancfg, 'wireless/regdomain', $post['regdomain']);
	array_set_path($wancfg, 'wireless/regcountry', $post['regcountry']);
	array_set_path($wancfg, 'wireless/reglocation', $post['reglocation']);

	if (!empty(array_get_path($wancfg, 'wireless/regdomain')) &&
	    !empty(array_get_path($wancfg, 'wireless/regcountry'))) {
		foreach ($wl_countries_attr as $wl_country) {
			if (array_get_path($wancfg, 'wireless/regcountry') == array_get_path($wl_country, 'ID')) {
				array_set_path($wancfg, 'wireless/regdomain', array_get_path($wl_country, 'rd/0/REF'));
				break;
			}
		}
	}

	array_set_path($wancfg, 'wireless/wpa/macaddr_acl', $post['macaddr_acl']);
	array_set_path($wancfg, 'wireless/wpa/wpa_mode', $post['wpa_mode']);
	array_set_path($wancfg, 'wireless/wpa/wpa_key_mgmt', $post['wpa_key_mgmt']);
	array_set_path($wancfg, 'wireless/wpa/wpa_pairwise', $post['wpa_pairwise']);
	array_set_path($wancfg, 'wireless/wpa/wpa_group_rekey', $post['wpa_group_rekey']);
	array_set_path($wancfg, 'wireless/wpa/wpa_gmk_rekey', $post['wpa_gmk_rekey']);
	array_set_path($wancfg, 'wireless/wpa/passphrase', $post['passphrase']);
	array_set_path($wancfg, 'wireless/wpa/ext_wpa_sw', $post['ext_wpa_sw']);
	array_set_path($wancfg, 'wireless/wpa/wpa_eap_client_mode', $post['wpa_eap_client_mode']);
	array_set_path($wancfg, 'wireless/wpa/wpa_eap_inner_auth', $post['wpa_eap_inner_auth']);
	array_set_path($wancfg, 'wireless/wpa/wpa_eap_inner_id', $post['wpa_eap_inner_id']);
	array_set_path($wancfg, 'wireless/wpa/wpa_eap_inner_password', base64_encode($post['wpa_eap_inner_password']));
	array_set_path($wancfg, 'wireless/wpa/wpa_eap_cert', $post['wpa_eap_cert']);
	array_set_path($wancfg, 'wireless/wpa/wpa_eap_ca', $post['wpa_eap_ca']);
	array_set_path($wancfg, 'wireless/wpa/auth_server_addr', $post['auth_server_addr']);
	array_set_path($wancfg, 'wireless/wpa/auth_server_port', $post['auth_server_port']);
	array_set_path($wancfg, 'wireless/wpa/auth_server_shared_secret', $post['auth_server_shared_secret']);
	array_set_path($wancfg, 'wireless/wpa/auth_server_addr2', $post['auth_server_addr2']);
	array_set_path($wancfg, 'wireless/wpa/auth_server_port2', $post['auth_server_port2']);
	array_set_path($wancfg, 'wireless/wpa/auth_server_shared_secret2', $post['auth_server_shared_secret2']);

	if ($post['persistcommonwireless'] == "yes") {
		config_set_path("wireless/interfaces/{$wlanbaseif}", []);
	} elseif (config_path_enabled('wireless/interfaces', $wlanbaseif)) {
		config_del_path("wireless/interfaces/{$wlanbaseif}");
	}
	if (isset($post['diversity']) &&
	    is_numeric($post['diversity'])) {
		array_set_path($wancfg, 'wireless/diversity', $post['diversity']);
	} elseif (array_path_enabled($wancfg, 'wireless', 'diversity')) {
		array_del_path($wancfg, 'wireless/diversity');
	}
	if (isset($post['txantenna']) &&
	    is_numeric($post['txantenna'])) {
		array_set_path($wancfg, 'wireless/txantenna', $post['txantenna']);
	} elseif (array_path_enabled($wancfg, 'wireless', 'txantenna')) {
		array_del_path($wancfg, 'wireless/txantenna');
	}
	if (isset($post['rxantenna']) &&
	    is_numeric($post['rxantenna'])) {
		array_set_path($wancfg, 'wireless/rxantenna', $post['rxantenna']);
	} elseif (array_path_enabled($wancfg, 'wireless', 'rxantenna')) {
		array_del_path($wancfg, 'wireless/rxantenna');
	}
	if ($post['hidessid_enable'] == "yes") {
		array_set_path($wancfg, 'wireless/hidessid/enable', true);
	} elseif (array_path_enabled($wancfg, 'wireless/hidessid')) {
		array_del_path($wancfg, 'wireless/hidessid/enable');
	}
	if ($post['mac_acl_enable'] == "yes") {
		array_set_path($wancfg, 'wireless/wpa/mac_acl_enable', true);
	} elseif (array_path_enabled($wancfg, 'wireless/wpa', 'mac_acl_enable')) {
		array_del_path($wancfg, 'wireless/wpa/mac_acl_enable');
	}
	if ($post['rsn_preauth'] == "yes") {
		array_set_path($wancfg, 'wireless/wpa/rsn_preauth', true);
	} else {
		array_del_path($wancfg, 'wireless/wpa/rsn_preauth');
	}
	if ($post['ieee8021x'] == "yes") {
		array_set_path($wancfg, 'wireless/wpa/ieee8021x/enable', true);
	} elseif (array_path_enabled($wancfg, 'wireless/wpa/ieee8021x')) {
		array_del_path($wancfg, 'wireless/wpa/ieee8021x/enable');
	}
	if ($post['wpa_strict_rekey'] == "yes") {
		array_set_path($wancfg, 'wireless/wpa/wpa_strict_rekey', true);
	} elseif (array_path_enabled($wancfg, 'wireless/wpa', 'wpa_strict_rekey')) {
		array_del_path($wancfg, 'wireless/wpa/wpa_strict_rekey');
	}
	if ($post['debug_mode'] == "yes") {
		array_set_path($wancfg, 'wireless/wpa/debug_mode', true);
	} elseif (array_path_enabled($wancfg, 'wireless/wpa', 'debug_mode')) {
		array_del_path($wancfg, 'wireless/wpa/debug_mode');
	}
	if ($post['wpa_enable'] == "yes") {
		$post['wpa_enable'] = true;
		array_set_path($wancfg, 'wireless/wpa/enable', true);
	} elseif (array_path_enabled($wancfg, 'wireless/wpa')) {
		array_del_path($wancfg, 'wireless/wpa/enable');
	}

	if ($post['wme_enable'] == "yes") {
		$post['wme_enable'] = true;
		array_set_path($wancfg, 'wireless/wme/enable', true);
	} elseif (array_path_enabled($wancfg, 'wireless/wme')) {
		array_del_path($wancfg, 'wireless/wme/enable');
	}
	if ($post['puremode'] == "11g") {
		array_set_path($wancfg, 'wireless/pureg/enable', true);
	} elseif ($post['puremode'] == "11n") {
		array_set_path($wancfg, 'wireless/puren/enable', true);
	} else {
		array_del_path($wancfg, 'wireless/pureg');
		array_del_path($wancfg, 'wireless/puren');
	}
	if ($post['apbridge_enable'] == "yes") {
		$post['apbridge_enable'] = true;
		array_set_path($wancfg, 'wireless/apbridge/enable', true);
	} elseif (array_path_enabled($wancfg, 'wireless/apbridge')) {
		array_del_path($wancfg, 'wireless/apbridge/enable');
	}
	if (($post['standard'] == "11g Turbo") ||
	    ($post['standard'] == "11a Turbo")) {
		array_set_path($wancfg, 'wireless/turbo/enable', true);
	} elseif (array_path_enabled($wancfg, 'wireless/turbo')) {
		array_del_path($wancfg, 'wireless/turbo/enable');
	}

	interface_sync_wireless_clones($wancfg, true);
}

/* A changed wireless mode must be one the radio can still clone (the check creates and destroys a test clone). */
function interfaces_edit_check_wireless_mode(array $post, array &$wancfg, $wlanif, $wlanbaseif, &$input_errors) {
	/* The mode names of guiconfig.inc (not loaded by the API). */
	$wlan_modes = (isset($GLOBALS['wlan_modes']) && is_array($GLOBALS['wlan_modes'])) ? $GLOBALS['wlan_modes'] : [];

	if (array_get_path($wancfg, 'wireless/mode') == $post['mode']) {
		return;
	}

	if (does_interface_exist(interface_get_wireless_clone($wlanbaseif))) {
		$clone_count = 1;
	} else {
		$clone_count = 0;
	}

	foreach (config_get_path('wireless/clone', []) as $clone) {
		if (array_get_path($clone, 'if') == $wlanbaseif) {
				$clone_count++;
		}
	}

	if ($clone_count > 1) {
		$old_wireless_mode = array_get_path($wancfg, 'wireless/mode');
		array_set_path($wancfg, 'wireless/mode', $post['mode']);
		if (!interface_wireless_clone("{$wlanif}_", $wancfg)) {
			$input_errors[] = sprintf(gettext("Unable to change mode to %s. The maximum number of wireless clones supported in this mode may have been reached."),
					array_get_path($wlan_modes, array_get_path($wancfg, 'wireless/mode')));
		} else {
			FreeSense_interface_destroy("{$wlanif}_");
		}
		array_set_path($wancfg, 'wireless/mode', $old_wireless_mode);
	}
}

/* Media options the port supports ("1000baseT full-duplex", ...), from ifconfig -m. */
function interfaces_edit_mediaopts($if) {
	// Find all possible media options for the interface
	$mediaopts_list = [];
	$intrealname = config_get_path("interfaces/{$if}/if");
	exec("/sbin/ifconfig -m " . escapeshellarg($intrealname) . " | /usr/bin/grep \"media \"", $mediaopts);
	foreach ($mediaopts as $mediaopt) {
		preg_match("/media (.*)/", $mediaopt, $matches);
		if (preg_match("/(.*) mediaopt (.*)/", $matches[1], $matches1)) {
			// there is media + mediaopt like "media 1000baseT mediaopt full-duplex"
			array_push($mediaopts_list, $matches1[1] . " " . $matches1[2]);
		} else {
			// there is only media like "media 1000baseT"
			array_push($mediaopts_list, $matches[1]);
		}
	}
	return $mediaopts_list;
}

/* The "Speed and Duplex" choices. */
function interfaces_edit_media_choices(array $mediaopts_list) {
	$list = [
		"" => gettext("Default (no preference, typically autoselect)"),
		" " => gettext("------- Media Supported by this interface -------")
		];

	foreach ($mediaopts_list as $mediaopt) {
		/* Output may include a '/' so we can't use array_set_path() */
		$list[$mediaopt] = $mediaopt;
	}

	return($list);
}

/* The upstream gateway choices: "none" and the gateways of this interface ($family 4 or 6). */
function interfaces_edit_gateway_choices($if, array $a_gateways, $family = 4) {
	$list = ["none" => gettext("None")];
	foreach ($a_gateways as $gateway) {
		if ((array_get_path($gateway, 'interface') == $if) &&
		    (($family == 6) ? is_ipaddrv6(array_get_path($gateway, 'gateway')) : is_ipaddrv4(array_get_path($gateway, 'gateway')))) {
			array_set_path($list,
				array_get_path($gateway, 'name'),
				array_get_path($gateway, 'name') . " - " . array_get_path($gateway, 'gateway'));
		}
	}

	return($list);
}

/* The IPv4 configuration types: an interface on a PPP link whose ports are assigned elsewhere can only stay a PPP type. */
function interfaces_edit_ipv4_types(array $pconfig, array $ctx) {
	$a_ppps = $ctx['a_ppps'];
	$pppid = $ctx['pppid'];

	$types4 = ["ppp" => gettext("PPP"), "pppoe" => gettext("PPPoE"), "pptp" => gettext("PPTP"), "l2tp" => gettext("L2TP")];

	if (!isset($pppid) ||!in_array(array_get_path($pconfig, 'type'), ["ppp", "pppoe", "pptp", "l2tp"]) ||
	    !array_intersect_key(explode(",", array_get_path($a_ppps, "{$pppid}/ports", "")), get_configured_interface_list_by_realif())) {
		$types4 = array_merge(["none" => gettext("None"), "staticv4" => gettext("Static IPv4"), "dhcp" => gettext("DHCP")], $types4);
	}
	return $types4;
}

function interfaces_edit_ipv6_types() {
	return ["none" => gettext("None"), "staticv6" => gettext("Static IPv6"), "dhcp6" => gettext("DHCP6"), "slaac" => gettext("SLAAC"), "6rd" => gettext("6rd Tunnel"), "6to4" => gettext("6to4 Tunnel"), "track6" => gettext("Track Interface")];
}

/* The DHCPv6 prefix delegation sizes: form value => prefix length. */
function interfaces_edit_pd_len_choices() {
	return ["none" => "None", 16 => "48", 15 => "49", 14 => "50", 13 => "51", 12 => "52", 11 => "53", 10 => "54", 9 => "55", 8 => "56", 7 => "57", 6 => "58", 5 => "59", 4 => "60", 3 => "61", 2 => "62", 1 => "63", 0 => "64"];
}

/*
 * The interfaces "Track Interface" can follow (dynamic IPv6: DHCP6, 6rd,
 * 6to4) with the highest prefix ID each delegates, the data of
 * build_ipv6interface_list() without the form.
 */
function interfaces_edit_track6_interfaces() {
	$out = [];
	foreach (get_configured_interface_with_descr(true) as $iface => $ifacename) {
		switch (config_get_path("interfaces/{$iface}/ipaddrv6")) {
			case "6to4":
			case "6rd":
			case "dhcp6":
				$out[$iface] = [
					'name' => $ifacename,
					'ipv6_num_prefix_ids' => pow(2, (int) calculate_ipv6_delegation_length($iface)) - 1
				];
				break;
			default:
				continue 2;
		}
	}
	return $out;
}

/* The option a select posts: the last one matching the value as the page renders it (Form_Select), else the first. */
function interfaces_edit_select_value($value, array $options) {
	$selected = null;
	foreach ($options as $key => $label) {
		if ((gettype($key) == "integer") && (gettype($value) == "string")) {
			$key = strval($key);
		}
		if ($value == $key) {
			$selected = $key;
		}
	}
	if ($selected === null) {
		$keys = array_keys($options);
		$selected = $keys[0] ?? '';
	}
	return (string)$selected;
}

/*
 * The POST the page's form sends when it is saved without changes: every
 * field the page renders for this interface, with the value it shows
 * (checkboxes only when ticked, selects as the option they show, passwords
 * as the placeholder that keeps them). Callers change fields of it and
 * pass it to interfaces_edit_save(). Wireless selects whose options come
 * from the radio post the stored value.
 */
function interfaces_edit_form_post($if, array $pconfig, array $ctx) {
	global $vlanprio;

	$wancfg = $ctx['wancfg'];
	$a_gateways = $ctx['a_gateways'];
	$realifname = $ctx['realifname'];
	$intrealname = config_get_path("interfaces/{$if}/if");
	$is_vlan = (interface_is_vlan(array_get_path($wancfg, 'if')) != NULL);
	$vlanprio_list = is_array($vlanprio) ? $vlanprio : [];
	/* A ticked checkbox posts its value, an unticked one nothing (false here). */
	$on = function ($key, $value = 'yes') use ($pconfig) {
		return array_get_path($pconfig, $key) ? $value : false;
	};
	$sel = function ($key, array $options) use ($pconfig) {
		return interfaces_edit_select_value(array_get_path($pconfig, $key), $options);
	};
	$mask = function ($key, $max, $min = 1) use ($pconfig) {
		return interfaces_edit_select_value(array_get_path($pconfig, $key), array_combine(range($max, $min), range($max, $min)));
	};
	$pwd = function ($key) use ($pconfig) {
		return ((string)array_get_path($pconfig, $key, '') != "") ? DMYPWD : '';
	};

	$post = [];
	/* General */
	$post['enable'] = $on('enable');
	$post['descr'] = array_get_path($pconfig, 'descr');
	if ($ctx['show_address_controls']) {
		$post['type'] = $sel('type', interfaces_edit_ipv4_types($pconfig, $ctx));
		$post['type6'] = $sel('type6', interfaces_edit_ipv6_types());
	} else {
		$post['type'] = 'none';
		$post['type6'] = 'none';
	}
	/* Link settings (a VLAN's MAC address and a bridge member's MTU are disabled fields) */
	if (!is_pseudo_interface($intrealname, true) && !interface_is_vlan($realifname)) {
		$post['spoofmac'] = array_get_path($pconfig, 'spoofmac');
	}
	if (!$ctx['bridged']) {
		$post['mtu'] = array_get_path($pconfig, 'mtu');
	}
	$post['mss'] = array_get_path($pconfig, 'mss');
	$mediaopts_list = interfaces_edit_mediaopts($if);
	if (count($mediaopts_list) > 0) {
		$post['mediaopt'] = interfaces_edit_select_value(rtrim(config_get_path("interfaces/{$if}/media", "") . ' ' . config_get_path("interfaces/{$if}/mediaopt")),
		    interfaces_edit_media_choices($mediaopts_list));
	}
	/* Static IPv4 */
	$post['ipaddr'] = array_get_path($pconfig, 'ipaddr');
	$post['subnet'] = $mask('subnet', 32);
	$post['gateway'] = $sel('gateway', interfaces_edit_gateway_choices($if, $a_gateways, 4));
	/* DHCP client */
	$post['adv_dhcp_config_advanced'] = $on('adv_dhcp_config_advanced');
	$post['adv_dhcp_config_file_override'] = $on('adv_dhcp_config_file_override');
	$post['dhcphostname'] = array_get_path($pconfig, 'dhcphostname');
	$post['alias-address'] = array_get_path($pconfig, 'alias-address');
	$post['alias-subnet'] = $mask('alias-subnet', 32);
	$post['dhcprejectfrom'] = array_get_path($pconfig, 'dhcprejectfrom');
	if ($is_vlan) {
		$post['dhcpvlanenable'] = $on('dhcpvlanenable');
		$post['dhcpcvpt'] = $sel('dhcpcvpt', $vlanprio_list);
	}
	/* The page ticks the stored timing preset ("Saved Cfg" when none) and fills the timing fields from it. */
	$pt_values = (string)array_get_path($pconfig, 'adv_dhcp_pt_values', '');
	$post['adv_dhcp_pt_values'] = ($pt_values == "") ? 'SavedCfg' : $pt_values;
	$presets = [
		'DHCP' => ["60", "300", "0", "10", "120", "10"],
		'FreeSense' => ["60", "15", "0", "", "", "1"],
		'Clear' => ["", "", "", "", "", ""],
	];
	$timing = ['adv_dhcp_pt_timeout', 'adv_dhcp_pt_retry', 'adv_dhcp_pt_select_timeout', 'adv_dhcp_pt_reboot',
	    'adv_dhcp_pt_backoff_cutoff', 'adv_dhcp_pt_initial_interval'];
	foreach ($timing as $i => $field) {
		$post[$field] = isset($presets[$pt_values]) ? $presets[$pt_values][$i] : array_get_path($pconfig, $field);
	}
	foreach (['adv_dhcp_config_file_override_path', 'adv_dhcp_send_options', 'adv_dhcp_request_options',
	    'adv_dhcp_required_options', 'adv_dhcp_option_modifiers'] as $field) {
		$post[$field] = array_get_path($pconfig, $field);
	}
	/* PPP */
	$post['ppp_username'] = array_get_path($pconfig, 'ppp_username');
	$post['ppp_password'] = $post['ppp_password_confirm'] = $pwd('ppp_password');
	$post['phone'] = array_get_path($pconfig, 'phone');
	$post['apn'] = array_get_path($pconfig, 'apn');
	$ports = ["" => "None"];
	foreach (array_merge(glob("/dev/cua*") ?: [], glob("/dev/modem*") ?: []) as $port) {
		if (!preg_match("/\.(lock|init)$/", $port)) {
			$ports[trim($port)] = trim($port);
		}
	}
	$post['port'] = $sel('port', $ports);
	/* PPPoE */
	$post['pppoe_username'] = array_get_path($pconfig, 'pppoe_username');
	$post['pppoe_password'] = $post['pppoe_password_confirm'] = $pwd('pppoe_password');
	$post['provider'] = array_get_path($pconfig, 'provider');
	$post['hostuniq'] = array_get_path($pconfig, 'hostuniq');
	$post['pppoe_dialondemand'] = $on('pppoe_dialondemand', 'enable');
	$post['pppoe_idletimeout'] = array_get_path($pconfig, 'pppoe_idletimeout');
	$post['pppoe-reset-type'] = $sel('pppoe-reset-type', ['' => 'Disabled', 'custom' => 'Custom', 'preset' => 'Pre-set']);
	$post['pppoe_resethour'] = (strlen((string)array_get_path($pconfig, 'pppoe_resethour')) > 0) ? array_get_path($pconfig, 'pppoe_resethour') : "0";
	$post['pppoe_resetminute'] = (strlen((string)array_get_path($pconfig, 'pppoe_resetminute')) > 0) ? array_get_path($pconfig, 'pppoe_resetminute') : "0";
	$post['pppoe_resetdate'] = array_get_path($pconfig, 'pppoe_resetdate');
	foreach (['monthly', 'weekly', 'daily', 'hourly'] as $preset) {
		if (array_get_path($pconfig, "pppoe_{$preset}")) {
			$post['pppoe_pr_preset_val'] = $preset;
		}
	}
	/* PPTP/L2TP */
	$post['pptp_username'] = array_get_path($pconfig, 'pptp_username');
	$post['pptp_password'] = $post['pptp_password_confirm'] = $pwd('pptp_password');
	$post['l2tp_secret'] = array_get_path($pconfig, 'l2tp_secret');
	$post['pptp_local0'] = array_get_path($pconfig, 'pptp_localip/0');
	$post['pptp_subnet0'] = $mask('pptp_subnet/0', 128);
	$post['pptp_remote0'] = array_get_path($pconfig, 'pptp_remote/0');
	$post['pptp_dialondemand'] = $on('pptp_dialondemand', 'enable');
	$post['pptp_idletimeout'] = array_get_path($pconfig, 'pptp_idletimeout');
	/* Static IPv6 */
	$post['ipaddrv6'] = array_get_path($pconfig, 'ipaddrv6');
	$post['subnetv6'] = $mask('subnetv6', 128);
	$post['ipv6usev4iface'] = $on('ipv6usev4iface');
	$post['gatewayv6'] = $sel('gatewayv6', interfaces_edit_gateway_choices($if, $a_gateways, 6));
	/* DHCP6 client */
	$post['adv_dhcp6_config_advanced'] = $on('adv_dhcp6_config_advanced');
	$post['adv_dhcp6_config_file_override'] = $on('adv_dhcp6_config_file_override');
	$post['dhcp6usev4iface'] = $on('dhcp6usev4iface');
	$post['dhcp6prefixonly'] = $on('dhcp6prefixonly');
	$post['dhcp6-ia-pd-len'] = $sel('dhcp6-ia-pd-len', interfaces_edit_pd_len_choices());
	$post['dhcp6-ia-pd-send-hint'] = $on('dhcp6-ia-pd-send-hint');
	$post['dhcp6withoutra'] = $on('dhcp6withoutra');
	if ($is_vlan) {
		$post['dhcp6vlanenable'] = $on('dhcp6vlanenable');
		$post['dhcp6cvpt'] = $sel('dhcp6cvpt', $vlanprio_list);
	}
	$post['adv_dhcp6_interface_statement_information_only_enable'] = $on('adv_dhcp6_interface_statement_information_only_enable');
	$post['adv_dhcp6_id_assoc_statement_address_enable'] = $on('adv_dhcp6_id_assoc_statement_address_enable');
	$post['adv_dhcp6_id_assoc_statement_prefix_enable'] = $on('adv_dhcp6_id_assoc_statement_prefix_enable');
	foreach (['adv_dhcp6_config_file_override_path', 'adv_dhcp6_interface_statement_send_options',
	    'adv_dhcp6_interface_statement_request_options', 'adv_dhcp6_interface_statement_script',
	    'adv_dhcp6_id_assoc_statement_address_id', 'adv_dhcp6_id_assoc_statement_address',
	    'adv_dhcp6_id_assoc_statement_address_pltime', 'adv_dhcp6_id_assoc_statement_address_vltime',
	    'adv_dhcp6_id_assoc_statement_prefix_id', 'adv_dhcp6_id_assoc_statement_prefix',
	    'adv_dhcp6_id_assoc_statement_prefix_pltime', 'adv_dhcp6_id_assoc_statement_prefix_vltime',
	    'adv_dhcp6_prefix_interface_statement_sla_id', 'adv_dhcp6_prefix_interface_statement_sla_len',
	    'adv_dhcp6_authentication_statement_authname', 'adv_dhcp6_authentication_statement_protocol',
	    'adv_dhcp6_authentication_statement_algorithm', 'adv_dhcp6_authentication_statement_rdm',
	    'adv_dhcp6_key_info_statement_keyname', 'adv_dhcp6_key_info_statement_realm',
	    'adv_dhcp6_key_info_statement_keyid', 'adv_dhcp6_key_info_statement_secret',
	    'adv_dhcp6_key_info_statement_expire'] as $field) {
		$post[$field] = array_get_path($pconfig, $field);
	}
	$post['adv_dhcp6_prefix_selected_interface'] = $sel('adv_dhcp6_prefix_selected_interface', $ctx['interfaces']);
	/* SLAAC, 6rd */
	$post['slaacusev4iface'] = $on('slaacusev4iface');
	$post['prefix-6rd'] = array_get_path($pconfig, 'prefix-6rd');
	$post['gateway-6rd'] = array_get_path($pconfig, 'gateway-6rd');
	$post['prefix-6rd-v4plen'] = $sel('prefix-6rd-v4plen', array_combine(range(0, 32), range(0, 32)));
	/* Track interface (and the hidden prefix ID ranges the page adds for each trackable interface) */
	$track6 = interfaces_edit_track6_interfaces();
	$track6_list = ['' => ''];
	foreach ($track6 as $iface => $data) {
		$track6_list[$iface] = $data['name'];
		$post["ipv6-num-prefix-ids-{$iface}"] = $data['ipv6_num_prefix_ids'];
	}
	$post['track6-interface'] = $sel('track6-interface', $track6_list);
	$post['track6-prefix-id--hex'] = sprintf("%x", (array_get_path($pconfig, 'track6-prefix-id') == "") ? 0 : array_get_path($pconfig, 'track6-prefix-id'));
	$post['track6-prefix-id-max'] = 0;
	/* Wireless */
	if (is_array(array_get_path($wancfg, 'wireless'))) {
		$post['persistcommonwireless'] = $on('persistcommonwireless');
		$post['standard'] = (array_get_path($pconfig, 'standard') == "") ? "11ng" : array_get_path($pconfig, 'standard');
		$post['protmode'] = isset($ctx['wl_modes']['11g']) ? $sel('protmode', ['off' => 'Off', 'cts' => 'CTS to self', 'rtscts' => 'RTS and CTS']) : 'off';
		foreach (['channel', 'channel_width', 'regdomain', 'regcountry', 'ssid', 'passphrase', 'wpa_key_mgmt', 'wpa_eap_client_mode',
		    'wpa_eap_ca', 'wpa_eap_inner_auth', 'wpa_eap_inner_id', 'wpa_eap_inner_password', 'wpa_eap_cert', 'auth_server_addr',
		    'auth_server_port', 'auth_server_shared_secret', 'auth_server_addr2', 'auth_server_port2', 'auth_server_shared_secret2'] as $field) {
			$post[$field] = array_get_path($pconfig, $field);
		}
		$sysctl = is_array($ctx['wl_sysctl']) ? $ctx['wl_sysctl'] : [];
		$prefix = $ctx['wl_sysctl_prefix'];
		if (isset($sysctl["{$prefix}.slottime"]) && isset($sysctl["{$prefix}.acktimeout"]) && isset($sysctl["{$prefix}.ctstimeout"])) {
			$post['distance'] = array_get_path($pconfig, 'distance');
		}
		$post['reglocation'] = $sel('reglocation', ['' => 'Default', 'indoor' => 'Indoor', 'outdoor' => 'Outdoor', 'anywhere' => 'Anywhere']);
		$post['mode'] = $sel('mode', ['bss' => 'BSS', 'adhoc' => 'IBSS', 'hostap' => 'Access Point']);
		if (isset($ctx['wl_modes']['11ng']) || isset($ctx['wl_modes']['11na'])) {
			$post['puremode'] = $sel('puremode', ['any' => 'Any', '11g' => '802.11g', '11n' => '802.11n']);
		} elseif (isset($ctx['wl_modes']['11g'])) {
			$post['puremode'] = $on('puremode', '11g');
		}
		foreach (['apbridge_enable', 'wme_enable', 'hidessid_enable', 'wpa_enable', 'wpa_strict_rekey', 'ieee8021x', 'rsn_preauth'] as $field) {
			$post[$field] = $on($field);
		}
		$post['wpa_mode'] = array_get_path($pconfig, 'wpa_mode', 2);
		$post['wpa_pairwise'] = array_get_path($pconfig, 'wpa_pairwise', 'CCMP');
		$post['wpa_group_rekey'] = array_get_path($pconfig, 'wpa_group_rekey', 60);
		$post['wpa_gmk_rekey'] = array_get_path($pconfig, 'wpa_gmk_rekey', 3600);
	}
	/* Reserved networks */
	$post['blockpriv'] = $on('blockpriv');
	$post['blockbogons'] = $on('blockbogons');
	/* Hidden fields */
	$post['if'] = $if;
	if ($ctx['ppp_found']) {
		$post['ppp_port'] = array_get_path($pconfig, 'port');
	}
	$post['ptpid'] = array_get_path($pconfig, 'ptpid');
	/* The "Add a new gateway" dialogs, left empty */
	$post['defaultgw4'] = (strtolower($if) == "wan") ? 'yes' : false;
	$post['gatewayname4'] = array_get_path($wancfg, 'descr') . "GW";
	$post['gatewayip4'] = '';
	$post['gatewaydescr4'] = '';
	$post['defaultgw6'] = (strtolower($if) == "wan") ? 'yes' : false;
	$post['gatewayname6'] = array_get_path($wancfg, 'descr') . "GWv6";
	$post['gatewayip6'] = '';
	$post['gatewaydescr6'] = '';
	$post['save'] = 'Save';

	/* Unticked checkboxes are not posted; an empty field posts "". */
	return array_map(function ($v) {
		return is_array($v) ? $v : (string)$v;
	}, array_filter($post, function ($v) {
		return $v !== false;
	}));
}
