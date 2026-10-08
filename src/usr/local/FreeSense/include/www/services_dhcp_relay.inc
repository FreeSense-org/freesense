<?php
/*
 * services_dhcp_relay.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos
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
 * DHCP and DHCPv6 relay logic shared by services_dhcp_relay.php,
 * services_dhcpv6_relay.php and the REST API, moved out of the pages with
 * the form fields as parameters. $v6 selects the DHCPv6 relay (config
 * "dhcrelay6") instead of the DHCP relay ("dhcrelay"). Like the pages, a
 * save reconfigures the relay at once; there is nothing to apply.
 */

require_once("config.gui.inc");
require_once("interfaces.inc");
require_once("services.inc");
require_once("filter.inc");
require_once("util.inc");

/* The settings form's fields, as the page preselects them. */
function dhcp_relay_settings($v6) {
	$pconfig = array();
	if (!$v6) {
		$pconfig['enable'] = config_path_enabled('dhcrelay');

		if (empty(config_get_path('dhcrelay/interface'))) {
			$pconfig['interface'] = array();
		} else {
			$pconfig['interface'] = explode(",", config_get_path('dhcrelay/interface'));
		}

		$pconfig['agentoption'] = config_path_enabled('dhcrelay', 'agentoption');
		$pconfig['server'] = config_path_enabled('dhcrelay', 'server') ? config_get_path('dhcrelay/server') : null;
		$pconfig['carpstatusvip'] = config_path_enabled('dhcrelay', 'carpstatusvip') ? config_get_path('dhcrelay/carpstatusvip') : 'none';
	} else {
		$pconfig['enable'] = config_path_enabled('dhcrelay6');

		$pconfig['interface'] = array_filter(explode(",", config_get_path('dhcrelay6/interface', "")));

		$pconfig['agentoption'] = config_path_enabled('dhcrelay6', 'agentoption');
		$pconfig['server'] = config_get_path('dhcrelay6/server');
		$pconfig['carpstatusvip'] = config_get_path('dhcrelay6/carpstatusvip', 'none');
	}
	return $pconfig;
}

/* The downstream interfaces the page offers: interfaces with an address of the family, no pseudo interfaces. */
function dhcp_relay_interface_list($v6) {
	return array_intersect_key(
		get_configured_interface_with_descr(),
		array_flip(
			array_filter(
				array_keys(get_configured_interface_with_descr()),
				function($if) use ($v6) {
					return (($v6 ? get_interface_ipv6($if) : get_interface_ip($if)) &&
					    !is_pseudo_interface(convert_friendly_interface_to_real_interface_name($if)));
				}
			)
		)
	);
}

/* The CARP status VIPs the page offers ("none" first). */
function dhcp_relay_carp_list($v6) {
	$family = $v6 ? 'inet6' : 'inet';
	return array_merge(array('none' => 'none'), array_intersect_key(
		get_configured_vip_list_with_descr($family, VIP_CARP),
		array_flip(
			array_filter(
				array_keys(get_configured_vip_list_with_descr($family, VIP_CARP)),
				function($if) {
					return (get_interface_ip($if) &&
					    !is_pseudo_interface(convert_friendly_interface_to_real_interface_name($if)));
				}
			)
		)
	));
}

/*
 * True when the DHCP (DHCPv6) server is enabled on any enabled interface.
 * The relay cannot run at the same time.
 */
function dhcp_relay_dhcpd_enabled($v6) {
	foreach (config_get_path($v6 ? 'dhcpdv6' : 'dhcpd', []) as $dhcpif => $dhcp) {
		if (empty($dhcp)) {
			continue;
		}
		if (isset($dhcp['enable']) && config_path_enabled("interfaces/{$dhcpif}")) {
			return true;
		}
	}
	return false;
}

/*
 * Save the settings form ($post: enable, interface[], agentoption,
 * carpstatusvip, server0, server1, ...) and reconfigure the relay at once.
 * Returns input_errors, "server" (the comma separated server list to show
 * again) and "retval" (null when nothing was saved, 0 when the relay was
 * reconfigured without errors).
 */
function dhcp_relay_save($post, $v6) {
	$input_errors = array();
	$path = $v6 ? 'dhcrelay6' : 'dhcrelay';

	/* input validation */
	if ($post['enable']) {
		$reqdfields = explode(' ', 'interface');
		$reqdfieldsn = array(gettext('Interface'));

		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
	}

	$svrlist = '';
	for ($idx = 0; $idx < count($post); $idx++) {
		if ($post['server' . $idx]) {
			if (!empty($post['server' . $idx])) { // Filter out any empties
				if (!$v6 && !is_ipaddrv4($post['server' . $idx])) {
					$input_errors[] = sprintf(gettext('Upstream Server address %s is not a valid IPv4 address.'), $post['server' . $idx]);
				} elseif ($v6 && !is_ipaddrv6($post['server' . $idx])) {
					$input_errors[] = sprintf(gettext('Upstream Server IP address %s is not a valid IPv6 address.'), $post['server' . $idx]);
				}

				if (!empty($svrlist)) {
					$svrlist .= ',';
				}

				$svrlist .= $post['server' . $idx];
			}
		}
	}

	// Check that the user input something in one of the Destination Server fields
	if (empty($svrlist) && $post['enable']) {
		$input_errors[] = gettext('At least one Upstream Server must be specified.');
	}

	/* The page disables the checkbox while a DHCP server runs; refuse a forged enable. */
	if ($post['enable'] && dhcp_relay_dhcpd_enabled($v6)) {
		$input_errors[] = $v6 ?
		    gettext('DHCPv6 Relay cannot be enabled while the DHCPv6 Server is enabled on any interface.') :
		    gettext('DHCP Relay cannot be enabled while DHCP Server is enabled on any interface.');
	}

	/* Only the interfaces and CARP VIPs the page offers. */
	if (isset($post['interface']) && is_array($post['interface'])) {
		$iflist = dhcp_relay_interface_list($v6);
		foreach ($post['interface'] as $if) {
			if (!array_key_exists($if, $iflist)) {
				$input_errors[] = sprintf(gettext('%s is not an interface the relay can use.'), $if);
			}
		}
	}
	if (isset($post['carpstatusvip']) && !array_key_exists($post['carpstatusvip'], dhcp_relay_carp_list($v6))) {
		$input_errors[] = gettext('A valid CARP Status VIP must be selected.');
	}

	$retval = null;
	if (!$input_errors) {
		config_set_path("{$path}/enable", $post['enable'] ? true : false);
		if (isset($post['interface']) &&
		    is_array($post['interface'])) {
			config_set_path("{$path}/interface", implode(",", $post['interface']));
		} else {
			config_del_path("{$path}/interface");
		}
		config_set_path("{$path}/agentoption", $post['agentoption'] ? true : false);
		config_set_path("{$path}/server", $svrlist);
		config_set_path("{$path}/carpstatusvip", $post['carpstatusvip']);

		write_config($v6 ? "DHCPv6 Relay settings saved" : "DHCP Relay settings saved");

		$retval = 0;
		if ($v6) {
			$retval |= services_dhcrelay6_configure();
		} else {
			$retval |= services_dhcrelay_configure();
			$retval |= filter_configure();
		}
	}

	return array('input_errors' => $input_errors, 'server' => $svrlist, 'retval' => $retval);
}
