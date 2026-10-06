<?php
/*
 * interfaces_assign.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
 * Written by Jim McBeath based on existing m0n0wall files
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
 * Interface assignment logic shared by interfaces_assign.php and the REST
 * API, moved out of the page with the form fields as parameters.
 *
 * "Port" is the network port (em0, em1.100, lagg0, ...), "interface" the
 * assignment (wan, lan, optN). There is no pending state: like the page,
 * an add, remap or delete reconfigures the interfaces and the filter at
 * once. Only a pending interface mismatch (after a configuration restore)
 * needs the page's "Apply Changes", which reboots the firewall.
 */

require_once("config.gui.inc");
require_once("functions.inc");
require_once("interfaces.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("openvpn.inc");
require_once("captiveportal.inc");
require_once("rrd.inc");
require_once("gwlb.inc");
require_once("interfaces_fast.inc");
require_once("firewall_nat.inc");

/* Set after a configuration restore whose ports do not match this hardware; "Apply Changes" then reboots. */
define('INTERFACES_ASSIGN_REBOOT_FILE', '/var/run/interface_mismatch_reboot_needed');

function interfaces_assign_reboot_needed() {
	return file_exists(INTERFACES_ASSIGN_REBOOT_FILE);
}

/*
 * Every port that can be assigned, keyed by port name: physical ports
 * (without LAGG members), wireless clones, VLANs, bridges, GIF, GRE, VXLAN,
 * LAGG, QinQ, PPP, OpenVPN and IPsec VTI interfaces. Also sets the globals
 * $ovpn_descrs and $ipsec_descrs that interface_assign_description_fast()
 * reads.
 */
function interfaces_assign_port_list() {
	global $ovpn_descrs, $ipsec_descrs;

	/* get list without VLAN interfaces */
	$portlist = get_interface_list();

	/* add wireless clone interfaces */
	foreach (config_get_path('wireless/clone', []) as $clone) {
		$portlist[$clone['cloneif']] = $clone;
		$portlist[$clone['cloneif']]['iswlclone'] = true;
	}

	/* add VLAN interfaces */
	foreach (config_get_path('vlans/vlan', []) as $vlan) {
		$portlist[$vlan['vlanif']] = $vlan;
		$portlist[$vlan['vlanif']]['isvlan'] = true;
	}

	/* add Bridge interfaces */
	foreach (config_get_path('bridges/bridged', []) as $bridge) {
		$portlist[$bridge['bridgeif']] = $bridge;
		$portlist[$bridge['bridgeif']]['isbridge'] = true;
	}

	/* add GIF interfaces */
	foreach (config_get_path('gifs/gif', []) as $gif) {
		$portlist[$gif['gifif']] = $gif;
		$portlist[$gif['gifif']]['isgif'] = true;
	}

	/* add GRE interfaces */
	foreach (config_get_path('gres/gre', []) as $gre) {
		$portlist[$gre['greif']] = $gre;
		$portlist[$gre['greif']]['isgre'] = true;
	}

	/* add VXLAN interfaces */
	foreach (config_get_path('vxlans/vxlan', []) as $vxlan) {
		if (empty($vxlan['vxlanif'])) {
			continue;
		}
		$portlist[$vxlan['vxlanif']] = $vxlan;
		$portlist[$vxlan['vxlanif']]['isvxlan'] = true;
	}

	/* add LAGG interfaces */
	foreach (config_get_path('laggs/lagg', []) as $lagg) {
		$portlist[$lagg['laggif']] = $lagg;
		$portlist[$lagg['laggif']]['islagg'] = true;
		/* LAGG members cannot be assigned */
		$lagifs = explode(',', $lagg['members']);
		foreach ($lagifs as $lagif) {
			if (isset($portlist[$lagif])) {
				unset($portlist[$lagif]);
			}
		}
	}

	/* add QinQ interfaces */
	foreach (config_get_path('qinqs/qinqentry', []) as $qinq) {
		$portlist["{$qinq['vlanif']}"]['descr'] = "VLAN {$qinq['tag']} on {$qinq['if']}";
		$portlist["{$qinq['vlanif']}"]['isqinq'] = true;
		/* QinQ members */
		$qinqifs = explode(' ', $qinq['members']);
		foreach ($qinqifs as $qinqif) {
			$portlist["{$qinq['vlanif']}.{$qinqif}"]['descr'] = "QinQ {$qinqif} on VLAN {$qinq['tag']} on {$qinq['if']}";
			$portlist["{$qinq['vlanif']}.{$qinqif}"]['isqinq'] = true;
		}
	}

	/* add PPP interfaces */
	foreach (config_get_path('ppps/ppp', []) as $ppp) {
		$portname = $ppp['if'];
		$portlist[$portname] = $ppp;
		$portlist[$portname]['isppp'] = true;
		$ports_base = basename($ppp['ports']);
		if (isset($ppp['descr'])) {
			$portlist[$portname]['descr'] = strtoupper($ppp['if']). "({$ports_base}) - {$ppp['descr']}";
		} else if (isset($ppp['username'])) {
			$portlist[$portname]['descr'] = strtoupper($ppp['if']). "({$ports_base}) - {$ppp['username']}";
		} else {
			$portlist[$portname]['descr'] = strtoupper($ppp['if']). "({$ports_base})";
		}
	}

	$ovpn_descrs = array();
	foreach (['server', 'client'] as $openvpn_mode) {
		foreach (config_get_path("openvpn/openvpn-{$openvpn_mode}", []) as $openvpn_settings) {
			$portname = openvpn_name($openvpn_mode, $openvpn_settings);
			$portlist[$portname] = $openvpn_settings;
			$ovpn_descrs[$openvpn_settings['vpnid']] = $openvpn_settings['description'];
		}
	}

	$ipsec_descrs = interface_ipsec_vti_list_all();
	foreach ($ipsec_descrs as $ifname => $ifdescr) {
		$portlist[$ifname] = array('descr' => $ifdescr);
	}

	return $portlist;
}

/* The port select's text of each port (call after interfaces_assign_port_list()). */
function interfaces_assign_port_descrs(array $portlist) {
	return interface_assign_description_fast($portlist, convert_real_interface_to_friendly_interface_name_fast());
}

/* The ports of $portlist that no interface uses (the page's "Available network ports"). */
function interfaces_assign_unused_ports(array $portlist) {
	$ifaceArray = array_column(config_get_path('interfaces', []), 'if');
	$unused = array_flip(array_diff(array_keys($portlist), $ifaceArray));
	return array_intersect_key($portlist, $unused);
}

/*
 * Assign $port as the next free interface: "lan" when there is no LAN,
 * otherwise the first free optN. Like the page, the new interface is not
 * enabled. Returns the new interface name, or null with $input_errors set.
 */
function interfaces_assign_add($port, array $portlist, &$input_errors) {
	$input_errors = array();
	$port = is_scalar($port) ? (string)$port : '';
	/* The page only offers ports of the list (a forged post could assign any name). */
	if (($port === '') || !array_key_exists($port, $portlist)) {
		$input_errors[] = sprintf(gettext('Cannot add port %1$s because it does not exist.'), $port);
		return null;
	}

	/* Be sure this port is not being used */
	foreach (config_get_path('interfaces', []) as $ifname => $ifdata) {
		if ($ifdata['if'] == $port) {
			$input_errors[] = sprintf(gettext('Port %1$s is already assigned to %2$s.'), $port,
			    convert_friendly_interface_to_friendly_descr($ifname));
			return null;
		}
	}

	/* find next free optional interface number */
	if (!config_get_path('interfaces/lan')) {
		$newifname = "lan";
		$descr = "LAN";
	} else {
		$interface_count = count(config_get_path('interfaces', []));
		for ($i = 1; $i <= $interface_count; $i++) {
			if (!config_get_path("interfaces/opt{$i}")) {
				break;
			}
		}
		$newifname = 'opt' . $i;
		$descr = "OPT" . $i;
	}

	config_set_path("interfaces/{$newifname}", [
		'descr' => $descr,
		'if' => $port
	]);
	if (preg_match(g_get('wireless_regex'), $port)) {
		config_set_path("interfaces/{$newifname}/wireless", []);
		$new_if_config = config_get_path("interfaces/{$newifname}");
		interface_sync_wireless_clones($new_if_config, false);
		config_set_path("interfaces/{$newifname}", $new_if_config);
	}

	$if_config = config_get_path('interfaces', []);
	uksort($if_config, "compare_interface_friendly_names");
	config_set_path('interfaces', $if_config);

	write_config("New interface assigned");

	filter_configure();

	return $newifname;
}

/*
 * The page's "Apply Changes": reboot when an interface mismatch is pending,
 * otherwise write the configuration and reload the filter. Returns
 * array('rebooting' => bool, 'retval' => filter_configure() result).
 */
function interfaces_assign_apply() {
	if (interfaces_assign_reboot_needed()) {
		system_reboot();
		return array('rebooting' => true, 'retval' => 0);
	}
	write_config("Interfaces assignment settings changed");

	$retval = 0;
	$retval |= filter_configure();
	return array('rebooting' => false, 'retval' => $retval);
}

/*
 * The page's "Save": map interfaces to ports. $post holds interface name =>
 * port for every interface (the page posts all of them; other fields are
 * ignored). An interface whose port changes is brought down and
 * reconfigured on the new port at once, then the filter is reloaded.
 * Returns true, or false with $input_errors set (nothing changed).
 */
function interfaces_assign_save(array $post, array $portlist, &$input_errors) {
	$input_errors = array();

	/* input validation */

	/* Build a list of the port names so we can see how the interfaces map */
	$portifmap = array();
	foreach ($portlist as $portname => $portinfo) {
		$portifmap[$portname] = array();
	}

	/* Go through the list of ports selected by the user,
	build a list of port-to-interface mappings in portifmap */
	foreach ($post as $ifname => $ifport) {
		if (($ifname == 'lan') || ($ifname == 'wan') || (substr($ifname, 0, 3) == 'opt')) {
			/* The page posts the assigned interfaces only (a forged one would have been created). */
			if (empty(config_get_path("interfaces/{$ifname}"))) {
				$input_errors[] = sprintf(gettext('Cannot set port for %1$s because that interface is not assigned.'), $ifname);
			} else if (is_scalar($ifport) && array_key_exists($ifport, $portlist)) {
				$portifmap[$ifport][] = strtoupper($ifname);
			} else {
				$input_errors[] = sprintf(gettext('Cannot set port %1$s because the submitted interface does not exist.'), $ifname);
			}
		}
	}

	/* Deliver error message for any port with more than one assignment */
	foreach ($portifmap as $portname => $ifnames) {
		if (count($ifnames) > 1) {
			$errstr = sprintf(gettext('Port %1$s '.
				' was assigned to %2$s' .
				' interfaces:'), $portname, count($ifnames));

			foreach ($portifmap[$portname] as $ifn) {
				$errstr .= " " . convert_friendly_interface_to_friendly_descr(strtolower($ifn)) . " (" . $ifn . ")";
			}

			$input_errors[] = $errstr;
		} else if (count($ifnames) == 1 && preg_match('/^bridge[0-9]/', $portname)) {
			foreach (config_get_path('bridges/bridged', []) as $bridge) {
				if ($bridge['bridgeif'] != $portname) {
					continue;
				}

				$members = explode(",", strtoupper($bridge['members']));
				foreach ($members as $member) {
					if ($member == $ifnames[0]) {
						$input_errors[] = sprintf(gettext('Cannot set port %1$s to interface %2$s because this interface is a member of %3$s.'), $portname, $member, $portname);
						break;
					}
				}
			}
		}
	}

	foreach (config_get_path('vlans/vlan', []) as $vlan) {
		if (does_interface_exist($vlan['if']) == false) {
			$input_errors[] = sprintf(gettext('Vlan parent interface %1$s does not exist anymore so vlan id %2$s cannot be created please fix the issue before continuing.'), $vlan['if'], $vlan['tag']);
		}
	}

	if ($input_errors) {
		return false;
	}

	/* No errors detected, so update the config */
	$filter_reload = false;
	$gateway_monitor_reload = false;
	foreach ($post as $ifname => $ifport) {

		if (($ifname == 'lan') || ($ifname == 'wan') || (substr($ifname, 0, 3) == 'opt')) {

			if (!is_array($ifport)) {
				$reloadif = false;
				if (!empty(config_get_path("interfaces/{$ifname}/if")) && config_get_path("interfaces/{$ifname}/if") <> $ifport) {
					interface_bring_down($ifname);
					/* Mark this to be reconfigured in any case. */
					$reloadif = true;
					$filter_reload = true;
					$gateway_monitor_reload = true;
				}
				$this_if_config = config_get_path("interfaces/{$ifname}");
				$this_if_config['if'] = $ifport;
				if (isset($portlist[$ifport]['isppp'])) {
					$this_if_config['ipaddr'] = $portlist[$ifport]['type'];
				}

				if ((substr($ifport, 0, 3) == 'gre') ||
				    (substr($ifport, 0, 3) == 'gif')) {
					unset($this_if_config['ipaddr']);
					unset($this_if_config['subnet']);
					unset($this_if_config['ipaddrv6']);
					unset($this_if_config['subnetv6']);
				}

				/* check for wireless interfaces, set or clear ['wireless'] */
				if (preg_match(g_get('wireless_regex'), $ifport)) {
					if (!is_array($this_if_config['wireless'])) {
						$this_if_config['wireless'] = array();
					}
				} else {
					unset($this_if_config['wireless']);
				}

				/* make sure there is a descr for all interfaces */
				if (!isset($this_if_config['descr'])) {
					$this_if_config['descr'] = strtoupper($ifname);
				}
				config_set_path("interfaces/{$ifname}", $this_if_config);

				if ($reloadif == true) {
					if (preg_match(g_get('wireless_regex'), $ifport)) {
						interface_sync_wireless_clones($this_if_config, false);
						config_set_path("interfaces/{$ifname}", $this_if_config);
					}
					/* Reload all for the interface. */
					interface_configure($ifname, true);
					$filter_reload = true;
				}
			}
		}
	}
	/* regenerated ruleset after re-assigning the interface,
	 * see upstream issue 12949 */
	if ($filter_reload) {
		filter_configure();
	}
	if ($gateway_monitor_reload) {
		setup_gateways_monitor();
	}
	write_config("Interfaces assignment settings changed");

	enable_rrd_graphing();
	return true;
}

/*
 * Names of the gateways on an interface: configured ones and the dynamic
 * gateway the interface provides (e.g. OPT1_DHCP).
 */
function interfaces_assign_gateway_names($id) {
	$names = array();
	foreach (return_gateways_array(true, false, true) as $name => $gateway) {
		if (($gateway['friendlyiface'] ?? '') === $id) {
			$names[] = (string)$name;
		}
	}
	return $names;
}

/*
 * Why an interface cannot be deleted, or '' when it can. The page checked
 * groups, bridges, tunnels and shaper queues; gateways, gateway groups,
 * static routes, the default gateway and virtual IPs on the interface
 * were not checked and were left pointing at a missing interface.
 */
function interfaces_assign_in_use($id) {
	if (link_interface_to_group($id)) {
		return gettext("The interface is part of a group. Please remove it from the group to continue");
	} else if (link_interface_to_bridge($id)) {
		return gettext("The interface is part of a bridge. Please remove it from the bridge to continue");
	} else if (!empty(link_interface_to_tunnelif($id, 'gre'))) {
		return gettext("The interface is part of a gre tunnel. Please delete the tunnel to continue");
	} else if (!empty(link_interface_to_tunnelif($id, 'gif'))) {
		return gettext("The interface is part of a gif tunnel. Please delete the tunnel to continue");
	} else if (!empty(link_interface_to_tunnelif($id, 'vxlan'))) {
		return gettext("The interface is the parent of a VXLAN tunnel. Please delete the tunnel to continue");
	} else if (interface_has_queue($id)) {
		return gettext("The interface has a traffic shaper queue configured.\nPlease remove all queues on the interface to continue.");
	}

	$configured = array();
	foreach (config_get_path('gateways/gateway_item', []) as $gateway) {
		if (is_array($gateway) && (($gateway['interface'] ?? '') === $id)) {
			$configured[] = $gateway['name'];
		}
	}
	if (!empty($configured)) {
		return sprintf(gettext("The interface has gateways (%s). Please delete them to continue"), implode(', ', $configured));
	}

	$gwnames = interfaces_assign_gateway_names($id);
	if (!empty($gwnames)) {
		$routes = array();
		foreach (config_get_path('staticroutes/route', []) as $route) {
			if (is_array($route) && in_array($route['gateway'] ?? '', $gwnames, true)) {
				$routes[] = $route['network'];
			}
		}
		if (!empty($routes)) {
			return sprintf(gettext("Static routes (%s) use a gateway of the interface. Please delete them to continue"), implode(', ', $routes));
		}
		$groups = array();
		foreach (config_get_path('gateways/gateway_group', []) as $group) {
			foreach ((array)($group['item'] ?? []) as $item) {
				if (in_array(explode('|', (string)$item)[0], $gwnames, true)) {
					$groups[] = $group['name'];
					break;
				}
			}
		}
		if (!empty($groups)) {
			return sprintf(gettext("Gateway groups (%s) use a gateway of the interface. Please remove it from them to continue"), implode(', ', $groups));
		}
		foreach (array('defaultgw4', 'defaultgw6') as $default) {
			if (in_array(config_get_path("gateways/{$default}", ''), $gwnames, true)) {
				return gettext("The default gateway is a gateway of the interface. Please choose another default gateway to continue");
			}
		}
	}

	$vips = array();
	foreach (config_get_path('virtualip/vip', []) as $vip) {
		if (is_array($vip) && (($vip['interface'] ?? '') === $id)) {
			$vips[] = $vip['subnet'];
		}
	}
	if (!empty($vips)) {
		return sprintf(gettext("The interface has virtual IP addresses (%s). Please delete them to continue"), implode(', ', $vips));
	}
	return '';
}

/*
 * The page's delete button: unassign an interface that is not in use,
 * bring it down and remove its DHCP server settings, firewall rules and
 * port forwards. WAN is never deleted (the page offers no button for it).
 * Returns true, or false with $input_errors set (nothing changed).
 */
function interfaces_assign_delete($id, &$input_errors) {
	$input_errors = array();
	$id = is_scalar($id) ? (string)$id : '';

	if (($id === '') || empty(config_get_path("interfaces/{$id}"))) {
		$input_errors[] = gettext("Wrong interface supplied");
	} else if ($id === 'wan') {
		$input_errors[] = gettext("The WAN interface cannot be deleted.");
	} else if (($inuse = interfaces_assign_in_use($id)) !== '') {
		$input_errors[] = $inuse;
	}
	if ($input_errors) {
		return false;
	}

	config_del_path("interfaces/{$id}/enable");
	$realid = get_real_interface($id);
	interface_bring_down($id);
	config_del_path("interfaces/{$id}");	/* delete the specified OPTn or LAN*/

	if (is_array(config_get_path("dhcpd/{$id}"))) {
		config_del_path("dhcpd/{$id}");
		services_dhcpd_configure('inet');
	}

	if (is_array(config_get_path("dhcpdv6/{$id}"))) {
		config_del_path("dhcpdv6/{$id}");
		services_dhcpd_configure('inet6');
	}
	remove_filter_rules([], $id);
	$rdr_rules_list = [];
	foreach (get_anynat_rules_list('rdr') as $x => $rule) {
		if ($rule['interface'] == $id) {
			$rdr_rules_list[] = $x;
		}
	}
	remove_rdr_rules($rdr_rules_list);

	write_config(gettext('Interface assignment deleted'));

	/* If we are in firewall/routing mode (not single interface)
	 * then ensure that we are not running DHCP on the wan which
	 * will make a lot of ISPs unhappy.
	 */
	if (config_path_enabled('interfaces', 'lan')
		&& config_path_enabled('dhcpd', 'wan')) {
			config_del_path('dhcpd/wan');
	}

	link_interface_to_vlans($realid, "update");

	filter_configure();
	return true;
}
