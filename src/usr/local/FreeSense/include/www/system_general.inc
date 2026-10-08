<?php
/*
 * system_general.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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

require_once("config.gui.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("system.inc");
require_once("util.inc");
require_once("freesense-utils.inc");

// Functions included by system.php (System > General Setup) =================

/*
 * The General Setup form as the page shows it. Like the page, an unset
 * dashboard column count and language are set to their defaults in the
 * running configuration (stored with the next save).
 */
function system_general_settings() {
	global $g;

	$pconfig = array();
	$pconfig['hostname'] = config_get_path('system/hostname');
	$pconfig['domain'] = config_get_path('system/domain');
	$pconfig['dnsserver'] = config_get_path('system/dnsserver');

	// set default columns to two if unset
	if (!is_numericint(config_get_path('system/webgui/dashboardcolumns'))) {
		config_set_path('system/webgui/dashboardcolumns', 2);
	}

	// set default language if unset
	if (!config_path_enabled('system', 'language')) {
		config_set_path('system/language', g_get('language'));
	}

	$dnshost_counter = 1;

	while (config_path_enabled("system", "dns{$dnshost_counter}host")) {
		$pconfig_dnshost_counter = $dnshost_counter - 1;
		$pconfig["dnshost{$pconfig_dnshost_counter}"] = config_get_path("system/dns{$dnshost_counter}host");
		$dnshost_counter++;
	}

	$dnsgw_counter = 1;

	while (config_get_path("system/dns{$dnsgw_counter}gw") !== null) {
		$pconfig_dnsgw_counter = $dnsgw_counter - 1;
		$pconfig["dnsgw{$pconfig_dnsgw_counter}"] = config_get_path("system/dns{$dnsgw_counter}gw");
		$dnsgw_counter++;
	}

	$pconfig['dnsallowoverride'] = config_path_enabled('system', 'dnsallowoverride');
	$pconfig['timezone'] = config_get_path('system/timezone');
	$pconfig['timeservers'] = config_get_path('system/timeservers');
	$pconfig['language'] = config_get_path('system/language');
	$pconfig['webguicss'] = config_get_path('system/webgui/webguicss');
	$pconfig['logincss'] = config_get_path('system/webgui/logincss');
	$pconfig['webguifixedmenu'] = config_get_path('system/webgui/webguifixedmenu');
	$pconfig['dashboardcolumns'] = config_get_path('system/webgui/dashboardcolumns');
	$pconfig['interfacessort'] = config_path_enabled('system/webgui', 'interfacessort');
	$pconfig['webguileftcolumnhyper'] = config_path_enabled('system/webgui', 'webguileftcolumnhyper');
	$pconfig['disablealiaspopupdetail'] = config_path_enabled('system/webgui', 'disablealiaspopupdetail');
	$pconfig['dashboardavailablewidgetspanel'] = config_path_enabled('system/webgui', 'dashboardavailablewidgetspanel');
	$pconfig['systemlogsfilterpanel'] = config_path_enabled('system/webgui', 'systemlogsfilterpanel');
	$pconfig['systemlogsmanagelogpanel'] = config_path_enabled('system/webgui', 'systemlogsmanagelogpanel');
	$pconfig['statusmonitoringsettingspanel'] = config_path_enabled('system/webgui', 'statusmonitoringsettingspanel');
	$pconfig['webguihostnamemenu'] = config_get_path('system/webgui/webguihostnamemenu');
	$pconfig['dnslocalhost'] = config_get_path('system/dnslocalhost');
	//$pconfig['dashboardperiod'] = isset($config['widgets']['period']) ? $config['widgets']['period']:"10";
	$pconfig['roworderdragging'] = config_path_enabled('system/webgui', 'roworderdragging');
	$pconfig['loginshowhost'] = config_path_enabled('system/webgui', 'loginshowhost');
	$pconfig['login_message'] = htmlentities(base64_decode(config_get_path('system/login_message', '')));
	$pconfig['requirestatefilter'] = config_path_enabled('system/webgui', 'requirestatefilter');
	$pconfig['requirefirewallinterface'] = config_path_enabled('system/webgui', 'requirefirewallinterface');

	if (!$pconfig['timezone']) {
		if (isset($g['default_timezone']) && !empty(g_get('default_timezone'))) {
			$pconfig['timezone'] = g_get('default_timezone');
		} else {
			$pconfig['timezone'] = "Etc/UTC";
		}
	}

	if (!$pconfig['timeservers']) {
		$pconfig['timeservers'] = "pool.ntp.org";
	}

	return $pconfig;
}

/* The timezone choices: Etc/GMT+N zones get an explanation of their (inverted) offset. */
function system_general_timezone_descr(array $timezonelist) {
	$timezonedesc = $timezonelist;

	/*
	 * Etc/GMT entries work the opposite way to what people expect.
	 * Ref: https://github.com/eggert/tz/blob/master/etcetera and Redmine issue 7089
	 * Add explanatory text to entries like:
	 * Etc/GMT+1 and Etc/GMT-1
	 * but not:
	 * Etc/GMT or Etc/GMT+0
	 */
	foreach ($timezonedesc as $idx => $desc) {
		if (substr($desc, 0, 7) != "Etc/GMT" || substr($desc, 8, 1) == "0") {
			continue;
		}

		$direction = substr($desc, 7, 1);

		switch ($direction) {
		case '-':
			$direction_str = gettext('AHEAD of');
			break;
		case '+':
			$direction_str = gettext('BEHIND');
			break;
		default:
			continue 2;
		}

		$hr_offset = substr($desc, 8);
		$timezonedesc[$idx] = $desc . " " .
		    sprintf(ngettext('(%1$s hour %2$s GMT)', '(%1$s hours %2$s GMT)', intval($hr_offset)), $hr_offset, $direction_str);
	}

	return $timezonedesc;
}

/*
 * Validate and save the General Setup form ($post: the page's fields) and
 * apply it at once (hostname, hosts, resolv.conf, DNS Forwarder/Resolver,
 * timezone, NTP, filter). Returns the form to show again (pconfig), the input
 * errors, whether the changes were applied and the apply result (retval).
 */
function system_general_save(array $post) {
	global $changedesc, $changecount;

	$changedesc = gettext("System") . ": ";
	$changecount = 0;
	$changes_applied = false;
	$retval = 0;

	$changecount++;

	unset($input_errors);
	$pconfig = $post;

	/* input validation */
	$reqdfields = explode(" ", "hostname domain");
	$reqdfieldsn = array(gettext("Hostname"), gettext("Domain"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($post['hostname']) {
		if (!is_hostname($post['hostname'])) {
			$input_errors[] = gettext("The hostname can only contain the characters A-Z, 0-9 and '-'. It may not start or end with '-'.");
		} else {
			if (!is_unqualified_hostname($post['hostname'])) {
				$input_errors[] = gettext("A valid hostname is specified, but the domain name part should be omitted");
			}
		}
	}
	if ($post['domain'] && (!is_domain($post['domain'], false, false))) {
		$input_errors[] = gettext("The domain may only contain the characters a-z, 0-9, '-' and '.', and it cannot start with '.' or '-'.");
	}
	validate_webguicss_field($input_errors, $post['webguicss']);
	validate_webguifixedmenu_field($input_errors, $post['webguifixedmenu']);
	validate_webguihostnamemenu_field($input_errors, $post['webguihostnamemenu']);
	validate_dashboardcolumns_field($input_errors, $post['dashboardcolumns']);

	$dnslist = $ignore_posted_dnsgw = array();

	$dnscounter = 0;
	$dnsname = "dns{$dnscounter}";

	while (isset($post[$dnsname])) {
		$dnsgwname = "dnsgw{$dnscounter}";
		$dnshostname = "dnshost{$dnscounter}";
		$dnslist[] = $post[$dnsname];

		if (($post[$dnsname] && !is_ipaddr($post[$dnsname]))) {
			$input_errors[] = sprintf(gettext("A valid IP address must be specified for DNS server %s."), $dnscounter+1);
		} else {
			if (!empty($post[$dnshostname]) && !is_hostname($post[$dnshostname])) {
				$input_errors[] = sprintf(gettext('The hostname provided for DNS server "%1$s" is not valid.'), $post[$dnsname]);
			}
			if (($post[$dnsgwname] <> "") && ($post[$dnsgwname] <> "none")) {
				// A real gateway has been selected.
				if (is_ipaddr($post[$dnsname])) {
					if ((is_ipaddrv4($post[$dnsname])) && (validate_address_family($post[$dnsname], $post[$dnsgwname]) === false)) {
						$input_errors[] = sprintf(gettext('The IPv6 gateway "%1$s" can not be specified for IPv4 DNS server "%2$s".'), $post[$dnsgwname], $post[$dnsname]);
					}
					if ((is_ipaddrv6($post[$dnsname])) && (validate_address_family($post[$dnsname], $post[$dnsgwname]) === false)) {
						$input_errors[] = sprintf(gettext('The IPv4 gateway "%1$s" can not be specified for IPv6 DNS server "%2$s".'), $post[$dnsgwname], $post[$dnsname]);
					}
				} else {
					// The user selected a gateway but did not provide a DNS address. Be nice and set the gateway back to "none".
					$ignore_posted_dnsgw[$dnsgwname] = true;
				}
			}
		}
		$dnscounter++;
		$dnsname = "dns{$dnscounter}";
	}

	if (count(array_filter($dnslist)) != count(array_unique(array_filter($dnslist)))) {
		$input_errors[] = gettext('Each configured DNS server must have a unique IP address. Remove the duplicated IP.');
	}

	$dnscounter = 0;
	$dnsname = "dns{$dnscounter}";

	$direct_networks_list = explode(" ", filter_get_direct_networks_list());
	while (isset($post[$dnsname])) {
		$dnsgwname = "dnsgw{$dnscounter}";
		if ($post[$dnsgwname] && ($post[$dnsgwname] <> "none")) {
			foreach ($direct_networks_list as $direct_network) {
				if (ip_in_subnet($post[$dnsname], $direct_network)) {
					$input_errors[] = sprintf(gettext("A gateway cannot be specified for %s because that IP address is part of a directly connected subnet %s. To use that nameserver, change its Gateway to `none`."), $post[$dnsname], $direct_network);
				}
			}
		}
		$dnscounter++;
		$dnsname = "dns{$dnscounter}";
	}

	# it's easy to have a little too much whitespace in the field, clean it up for the user before processing.
	$post['timeservers'] = preg_replace('/[[:blank:]]+/', ' ', $post['timeservers']);
	$post['timeservers'] = trim($post['timeservers']);
	foreach (explode(' ', $post['timeservers']) as $ts) {
		if (!is_domain($ts) && (!is_ipaddr($ts))) {
			$input_errors[] = gettext("NTP Time Server names must be valid domain names, IPv4 addresses, or IPv6 addresses");
		}
	}

	/* The timezone must be one of the page's choices (it is written to /var/db/zoneinfo). */
	if (!in_array($post['timezone'], system_get_timezone_list(), true)) {
		$input_errors[] = gettext("A valid timezone must be selected.");
	}

	if ($input_errors) {
		// Put the user-entered list back into place so it will be redisplayed for correction.
		$pconfig['dnsserver'] = $dnslist;
	} else {
		// input validation passed, so we can proceed with removing static routes for dead DNS gateways
		if (is_array(config_get_path('system/dnsserver'))) {
		  	$dns_servers_arr = config_get_path('system/dnsserver');
	 		foreach ($dns_servers_arr as $arr_index => $this_dnsserver) {
				$i = (int)$arr_index + 1;
				$this_dnsgw = config_get_path("system/dns{$i}gw");
				unset($gatewayip);
				unset($inet6);
				if ((!empty($this_dnsgw)) && ($this_dnsgw != 'none') && (!empty($this_dnsserver))) {
					$gatewayip = lookup_gateway_ip_by_name($this_dnsgw);
					$inet6 = is_ipaddrv6($gatewayip) ? '-inet6 ' : '';
					mwexec("/sbin/route -q delete -host {$inet6}{$this_dnsserver} " . escapeshellarg($gatewayip));
				}
			}
		}

		$system_config = config_get_path('system');
		update_if_changed("hostname", $system_config['hostname'], $post['hostname']);
		update_if_changed("domain", $system_config['domain'], $post['domain']);
		update_if_changed("timezone", $system_config['timezone'], $post['timezone']);
		update_if_changed("NTP servers", $system_config['timeservers'], strtolower($post['timeservers']));
		config_set_path('system', $system_config);

		if ($post['language'] && $post['language'] != config_get_path('system/language')) {
			config_set_path('system/language', $post['language']);
			set_language();
		}

		config_del_path('system/webgui/interfacessort');
		config_set_path('system/webgui/interfacessort', $post['interfacessort'] ? true : false);

		config_del_path('system/webgui/webguileftcolumnhyper');
		config_set_path('system/webgui/webguileftcolumnhyper', $post['webguileftcolumnhyper'] ? true : false);

		config_del_path('system/webgui/disablealiaspopupdetail');
		config_set_path('system/webgui/disablealiaspopupdetail', $post['disablealiaspopupdetail'] ? true : false);

		config_del_path('system/webgui/dashboardavailablewidgetspanel');
		config_set_path('system/webgui/dashboardavailablewidgetspanel', $post['dashboardavailablewidgetspanel'] ? true : false);

		config_del_path('system/webgui/systemlogsfilterpanel');
		config_set_path('system/webgui/systemlogsfilterpanel', $post['systemlogsfilterpanel'] ? true : false);

		config_del_path('system/webgui/systemlogsmanagelogpanel');
		config_set_path('system/webgui/systemlogsmanagelogpanel', $post['systemlogsmanagelogpanel'] ? true : false);

		config_del_path('system/webgui/statusmonitoringsettingspanel');
		config_set_path('system/webgui/statusmonitoringsettingspanel', $post['statusmonitoringsettingspanel'] ? true : false);

//		if ($post['dashboardperiod']) {
//			$config['widgets']['period'] = $post['dashboardperiod'];
//		}

		if ($post['webguicss']) {
			config_set_path('system/webgui/webguicss', $post['webguicss']);
		} else {
			config_del_path('system/webgui/webguicss');
		}

		config_set_path('system/webgui/roworderdragging', $post['roworderdragging'] ? true:false);

		if ($post['logincss']) {
			config_set_path('system/webgui/logincss', $post['logincss']);
		} else {
			config_del_path('system/webgui/logincss');
		}

		config_set_path('system/webgui/loginshowhost', $post['loginshowhost'] ? true:false);

		if (isset($post['login_message']) && (strlen(strval($post['login_message'])) > 0)) {
			$login_message = strip_tags(strval($pconfig['login_message']));
			$pconfig['login_message'] = $login_message;
			config_set_path('system/login_message', base64_encode($login_message));
		} else {
			config_del_path('system/login_message');
		}

		if ($post['webguifixedmenu']) {
			config_set_path('system/webgui/webguifixedmenu', $post['webguifixedmenu']);
		} else {
			config_del_path('system/webgui/webguifixedmenu');
		}

		if ($post['webguihostnamemenu']) {
			config_set_path('system/webgui/webguihostnamemenu', $post['webguihostnamemenu']);
		} else {
			config_del_path('system/webgui/webguihostnamemenu');
		}

		if ($post['dashboardcolumns']) {
			config_set_path('system/webgui/dashboardcolumns', $post['dashboardcolumns']);
		} else {
			config_del_path('system/webgui/dashboardcolumns');
		}

		config_set_path('system/webgui/requirestatefilter', $post['requirestatefilter'] ? true : false);
		config_set_path('system/webgui/requirefirewallinterface', $post['requirefirewallinterface'] ? true : false);

		/* XXX - billm: these still need updating after figuring out how to check if they actually changed */
		$olddnsservers = config_get_path('system/dnsserver');
		config_del_path('system/dnsserver');

		$dnscounter = 0;
		$dnsname = "dns{$dnscounter}";

		while (isset($post[$dnsname])) {
			if ($post[$dnsname]) {
				config_set_path('system/dnsserver/', $post[$dnsname]);
			}
			$dnscounter++;
			$dnsname = "dns{$dnscounter}";
		}

		// Remember the new list for display also.
		$pconfig['dnsserver'] = config_get_path('system/dnsserver');

		$olddnsallowoverride = config_get_path('system/dnsallowoverride');

		config_del_path('system/dnsallowoverride');
		config_set_path('system/dnsallowoverride', $post['dnsallowoverride'] ? true : false);

		if ($post['dnslocalhost']) {
			config_set_path('system/dnslocalhost', $post['dnslocalhost']);
		} else {
			config_del_path('system/dnslocalhost');
		}

		/* which interface should the dns servers resolve through? */
		$dnscounter = 0;
		// The $post array key of the DNS IP (starts from 0)
		$dnsname = "dns{$dnscounter}";
		$outdnscounter = 0;
		while (isset($post[$dnsname])) {
			// The $post array key of the corresponding gateway (starts from 0)
			$dnsgwname = "dnsgw{$dnscounter}";
			$dnshostname = "dnshost{$dnscounter}";
			// The numbering of DNS GW/host entries in the config starts from 1
			$dnsgwconfigcounter = $dnscounter + 1;
			$dnshostconfigcounter = $dnscounter + 1;
			// So this is the array key of the DNS GW entry in $config['system']
			$dnsgwconfigname = "dns{$dnsgwconfigcounter}gw";
			$dnshostconfigname = "dns{$dnshostconfigcounter}host";

			$olddnsgwname = config_get_path("system/{$dnsgwconfigname}");
			$olddnshostname = config_get_path("system/{$dnshostconfigname}");

			if ($ignore_posted_dnsgw[$dnsgwname]) {
				$thisdnsgwname = "none";
			} else {
				$thisdnsgwname = $pconfig[$dnsgwname];
			}
			$thisdnshostname = $pconfig[$dnshostname];

			// "Blank" out the settings for this index, then we set them below using the "outdnscounter" index.
			config_set_path("system/{$dnsgwconfigname}", 'none');
			$pconfig[$dnsgwname] = "none";
			config_set_path("system/{$dnshostconfigname}", '');
			$pconfig[$dnshostname] = "";
			$pconfig[$dnsname] = "";

			if ($post[$dnsname]) {
				// Only the non-blank DNS servers were put into the config above.
				// So we similarly only add the corresponding gateways sequentially to the config (and to pconfig), as we find non-blank DNS servers.
				// This keeps the DNS server IP and corresponding gateway "lined up" when the user blanks out a DNS server IP in the middle of the list.

				// The $pconfig array key of the DNS IP (starts from 0)
				$outdnsname = "dns{$outdnscounter}";
				// The $pconfig array key of the corresponding gateway (starts from 0)
				$outdnsgwname = "dnsgw{$outdnscounter}";
				// The $pconfig array key of the corresponding hostname (starts from 0)
				$outdnshostname = "dnshost{$outdnscounter}";

				// The numbering of DNS GW/host entries in the config starts from 1
				$outdnsgwconfigcounter = $outdnscounter + 1;
				$outdnshostconfigcounter = $outdnscounter + 1;
				// So this is the array key of the output DNS GW entry in $config['system']
				$outdnsgwconfigname = "dns{$outdnsgwconfigcounter}gw";
				$outdnshostconfigname = "dns{$outdnshostconfigcounter}host";

				$pconfig[$outdnsname] = $post[$dnsname];
				if ($post[$dnsgwname]) {
					config_set_path("system/{$outdnsgwconfigname}", $thisdnsgwname);
					$pconfig[$outdnsgwname] = $thisdnsgwname;
				} else {
					// Note: when no DNS GW name is chosen, the entry is set to "none", so actually this case never happens.
					config_del_path("system/{$outdnsgwconfigname}");
					$pconfig[$outdnsgwname] = "";
				}
				if ($post[$dnshostname]) {
					config_set_path("system/{$outdnshostconfigname}", $thisdnshostname);
					$pconfig[$outdnshostname] = $thisdnshostname;
				} else {
					// Note: when no DNS hostname is chosen, unset the value.
					config_del_path("system/{$outdnshostconfigname}");
					$pconfig[$outdnshostname] = "";
				}
				$outdnscounter++;
			}

			$dnscounter++;
			// The $post array key of the DNS IP (starts from 0)
			$dnsname = "dns{$dnscounter}";
		}

		// clean up dnsgw orphans
		$oldgwcounter = 1;
		$olddnsgwconfigname = "dns{$oldgwcounter}gw";
		while (config_get_path("system/{$olddnsgwconfigname}") !== null) {
			if (empty(config_get_path('system/dnsserver/' . ($oldgwcounter - 1)))) {
				config_del_path("system/{$olddnsgwconfigname}");
			}
			$oldgwcounter++;
			$olddnsgwconfigname = "dns{$oldgwcounter}gw";
		}
		unset($oldgwcounter);
		unset($olddnsgwconfigname);

		if ($changecount > 0) {
			write_config($changedesc);
		}

		$changes_applied = true;
		$retval = 0;
		$retval |= system_hostname_configure();
		$retval |= system_hosts_generate();
		$retval |= system_resolvconf_generate();
		if (config_path_enabled('dnsmasq')) {
			$retval |= services_dnsmasq_configure();
		} elseif (config_path_enabled('unbound')) {
			$retval |= services_unbound_configure();
		}
		$retval |= system_timezone_configure();
		$retval |= system_ntp_configure();

		if ($olddnsallowoverride != config_get_path('system/dnsallowoverride')) {
			$retval |= send_event("service reload dns");
		}

		// Reload the filter - plugins might need to be run.
		$retval |= filter_configure();
	}

	unset($ignore_posted_dnsgw);

	return array('pconfig' => $pconfig, 'input_errors' => (array)$input_errors, 'changes_applied' => $changes_applied,
	    'retval' => $retval);
}
