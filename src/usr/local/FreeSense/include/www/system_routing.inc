<?php
/*
 * system_routing.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
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
 * Routing logic shared by the System > Routing pages (gateways, gateway
 * groups, static routes) and the REST API. Moved out of the pages with the
 * form fields and ids as parameters instead of the request arrays and page
 * globals. The functions never redirect; the pages do that.
 *
 * Gateway validation and saving stay in gwlb.inc (validate_gateway(),
 * save_gateway()).
 */

require_once("config.gui.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("gwlb.inc");
require_once("openvpn.inc");

/* ------------------------------------------------------------------ */
/* Apply                                                               */
/* ------------------------------------------------------------------ */

/*
 * Apply pending routing changes (gateways, gateway groups and static
 * routes share the "staticroutes" dirty flag). Returns 0 on success.
 * Was inline, in slightly different forms, in system_gateways.php,
 * system_gateway_groups.php and system_routes.php; this runs the union of
 * the three.
 */
function routing_apply_changes() {
	/* Route deletions queued by static route edits/deletes. */
	$routes_apply_file = g_get('tmp_path') . '/.system_routes.apply';
	if (file_exists($routes_apply_file)) {
		foreach (unserialize_data(file_get_contents($routes_apply_file), []) as $toapply) {
			mwexec("{$toapply}");
		}
		@unlink($routes_apply_file);
	}

	$retval = 0;

	/* reconfigure our gateway monitor */
	setup_gateways_monitor();
	$retval |= system_routing_configure();
	$retval |= system_resolvconf_generate();
	$retval |= filter_configure();
	/* Dynamic DNS and IPsec DNS endpoints on gateway groups may have changed */
	send_multiple_events(array("service reload dyndnsall", "service reload ipsecdns"));

	if ($retval == 0) {
		clear_subsystem_dirty('staticroutes');
	}

	foreach (config_get_path('gateways/gateway_group', []) as $gateway_group) {
		$gw_subsystem = 'gwgroup.' . $gateway_group['name'];
		if (is_subsystem_dirty($gw_subsystem)) {
			openvpn_resync_gwgroup($gateway_group['name']);
			clear_subsystem_dirty($gw_subsystem);
		}
	}

	return $retval;
}

/* ------------------------------------------------------------------ */
/* Gateways                                                            */
/* ------------------------------------------------------------------ */

/* Save the default gateway selection (defaultgw4, defaultgw6). */
function routing_save_default_gateways($post) {
	foreach (array('defaultgw4', 'defaultgw6') as $field) {
		config_set_path("gateways/{$field}", $post[$field]);
	}
	mark_subsystem_dirty('staticroutes');
	write_config("System - Gateways: save default gateway");
}

/*
 * Validate and save a gateway from the edit form's fields. $id is the
 * gateway's index in get_gateways(GW_CACHE_INDEXED), null for a new one.
 * Returns the input errors; empty when the gateway was saved.
 */
function routing_save_gateway($post, $id = null) {
	refresh_gateways(); // make sure we're working on a current gateway list
	$a_gateways = get_gateways(GW_CACHE_INDEXED);
	$realid = "";
	if (isset($id) && $a_gateways[$id]) {
		$realid = $a_gateways[$id]['attribute'];
	}

	$input_errors = validate_gateway($post, $id);

	if (count($input_errors) == 0) {
		save_gateway($post, $realid);
	}
	return $input_errors;
}

/*
 * Whether the gateway at $id of $a_gateways (get_gateways(GW_CACHE_INDEXED))
 * may be deleted, or disabled when $disable is set. Reasons it may not are
 * appended to $input_errors.
 */
function routing_gateway_can_delete_disable($a_gateways, $id, $disable, &$input_errors) {
	if (!isset($a_gateways[$id])) {
		return false;
	}

	foreach (config_get_path('gateways/gateway_group', []) as $group) {
		foreach ($group['item'] as $item) {
			$items = explode("|", $item);
			if ($items[0] == $a_gateways[$id]['name']) {
				if (!$disable) {
					$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be deleted because it is in use on Gateway Group "%2$s"'), $a_gateways[$id]['name'], $group['name']);
				} else {
					$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be disabled because it is in use on Gateway Group "%2$s"'), $a_gateways[$id]['name'], $group['name']);
				}
			}
		}
	}

	foreach (config_get_path('staticroutes/route', []) as $route) {
		if ($route['gateway'] == $a_gateways[$id]['name']) {
			if (!$disable) {
				// The user wants to delete this gateway, but there is a static route (enabled or disabled) that refers to the gateway.
				$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be deleted because it is in use on Static Route "%2$s"'), $a_gateways[$id]['name'], $route['network']);
			} else if (!isset($route['disabled'])) {
				// The user wants to disable this gateway.
				// But there is a static route that uses this gateway and is enabled (not disabled).
				$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be disabled because it is in use on Static Route "%2$s"'), $a_gateways[$id]['name'], $route['network']);
			}
		}
	}

	/* prevent removing a gateway if it's still in use by DNS servers
	 * see upstream issue 8390 */
	$dnsgw_counter = 1;
	foreach (config_get_path('system/dnsserver', []) as $dnsserver) {
		if (config_path_enabled("system", "dns{$dnsgw_counter}gw") &&
		    ($a_gateways[$id]['name'] == config_get_path("system/dns{$dnsgw_counter}gw"))) {
				if (!$disable) {
					// The user wants to delete this gateway, but there is a static route to the DNS server that refers to the gateway.
					$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be deleted because it is in use by DNS Server "%2$s"'), $a_gateways[$id]['name'], $dnsserver);
				} else {
					// The user wants to disable this gateway, but there is a static route to the DNS server that refers to the gateway.
					$input_errors[] = sprintf(gettext('Gateway "%1$s" cannot be disabled because it is in use by DNS Server "%2$s"'), $a_gateways[$id]['name'], $dnsserver);
				}
		}
		$dnsgw_counter++;
	}

	if (!empty($input_errors)) {
		return false;
	}

	return true;
}

/* Remove the gateway at $id of $a_gateways from the configuration (not written). */
function routing_gateway_remove($a_gateways, $id) {
	if (!isset($a_gateways[$id])) {
		return;
	}

	/* If the removed gateway was the default route, remove the default route */
	if (!empty($a_gateways[$id]) && is_ipaddr($a_gateways[$id]['gateway']) &&
	    !isset($a_gateways[$id]['disabled']) &&
	    isset($a_gateways[$id]['isdefaultgw'])) {
		$inet = (!is_ipaddrv4($a_gateways[$id]['gateway'])
		    ? 'inet6' : 'inet');
		route_del('default', $inet);
	}

	/* NOTE: Cleanup static routes for the interface route if any */
	if (!empty($a_gateways[$id]) && is_ipaddr($a_gateways[$id]['gateway']) &&
	    isset($a_gateways[$id]["nonlocalgateway"])) {
		route_del($a_gateways[$id]['gateway']);
	}
	/* NOTE: Cleanup static routes for the monitor ip if any */
	if (!empty($a_gateways[$id]['monitor']) &&
	    $a_gateways[$id]['monitor'] != "dynamic" &&
	    is_ipaddr($a_gateways[$id]['monitor']) &&
	    $a_gateways[$id]['gateway'] != $a_gateways[$id]['monitor']) {
		route_del($a_gateways[$id]['monitor']);
	}

	if (config_get_path("interfaces/{$a_gateways[$id]['friendlyiface']}/gateway") == $a_gateways[$id]['name']) {
		config_del_path("interfaces/{$a_gateways[$id]['friendlyiface']}/gateway");
	}
	config_del_path("gateways/gateway_item/{$a_gateways[$id]['attribute']}");
}

/*
 * Delete the gateway at $id of $a_gateways when nothing uses it. Returns
 * true when it was deleted; otherwise the reasons are in $input_errors.
 */
function routing_delete_gateway($a_gateways, $id, &$input_errors) {
	if (!routing_gateway_can_delete_disable($a_gateways, $id, false, $input_errors)) {
		return false;
	}
	$realid = $a_gateways[$id]['attribute'];
	routing_gateway_remove($a_gateways, $id);
	write_config("Gateways: removed gateway {$realid}");
	mark_subsystem_dirty('staticroutes');
	return true;
}

/* Delete several gateways (all or none). Returns true unless one is in use. */
function routing_delete_gateways($a_gateways, $ids, &$input_errors) {
	foreach ($ids as $rulei) {
		if (!routing_gateway_can_delete_disable($a_gateways, $rulei, false, $input_errors)) {
			break;
		}
	}

	if (!empty($input_errors)) {
		return false;
	}
	$items_deleted = "";
	foreach ($ids as $rulei) {
		routing_gateway_remove($a_gateways, $rulei);
		$items_deleted .= "{$rulei} ";
	}
	if (!empty($items_deleted)) {
		write_config(sprintf(gettext("Gateways: removed gateways %s"), $items_deleted));
		mark_subsystem_dirty('staticroutes');
	}
	return true;
}

/*
 * Enable a disabled gateway or disable an enabled one. Disabling is
 * refused while the gateway is in use. Returns true when it was toggled.
 */
function routing_toggle_gateway($a_gateways, $id, &$input_errors) {
	if (!$a_gateways[$id]) {
		return false;
	}
	$realid = $a_gateways[$id]['attribute'];
	$disable_gw = config_get_path("gateways/gateway_item/{$realid}/disabled") === null;
	if ($disable_gw) {
		// The user wants to disable the gateway, so check if that is OK.
		$ok_to_toggle = routing_gateway_can_delete_disable($a_gateways, $id, $disable_gw, $input_errors);
	} else {
		// The user wants to enable the gateway. That is always OK.
		$ok_to_toggle = true;
	}
	if (!$ok_to_toggle) {
		return false;
	}
	gateway_set_enabled(config_get_path("gateways/gateway_item/{$realid}/name"), !$disable_gw);

	if (write_config("Gateways: enable/disable")) {
		mark_subsystem_dirty('staticroutes');
	}
	return true;
}

/* ------------------------------------------------------------------ */
/* Gateway groups                                                      */
/* ------------------------------------------------------------------ */

/* The edit page's "Trigger Level" choices. */
function routing_gateway_group_triggers() {
	return array(
		'down' => gettext("Member Down"),
		'downloss' => gettext("Packet Loss"),
		'downlatency' => gettext("High Latency"),
		'downlosslatency' => gettext("Packet Loss or High Latency"));
}

/* The edit page's "Keep Failover States" choices. */
function routing_gateway_group_keep_states() {
	return array(
		'' => 'Use global behavior (default)',
		'keep' => 'Keep states on gateway recovery',
		'kill' => 'Kill states on gateway recovery',
	);
}

/*
 * Validate and save a gateway group from the edit form's fields: name,
 * descr, trigger, keep_failover_states, and per gateway "<gateway>" (tier
 * 0-5, 0 = not a member) and "<gateway>_vip". $id is the group's position,
 * null for a new group. Returns array('input_errors' => [...], 'item' =>
 * the member list built from the form, for refilling it).
 */
function routing_save_gateway_group($post, $id = null) {
	$a_gateways = get_gateways();
	$this_gateway_group_config = isset($id) ? config_get_path("gateways/gateway_group/{$id}") : null;
	$input_errors = array();
	$pconfig = $post;

	/* input validation */
	$reqdfields = explode(" ", "name");
	$reqdfieldsn = explode(",", "Name");

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!isset($post['name'])) {
		$input_errors[] = gettext("A valid gateway group name must be specified.");
	}
	if (!is_validaliasname($post['name'])) {
		$input_errors[] = invalidaliasnamemsg($post['name'], gettext("gateway group"));
	}

	if (isset($post['name'])) {
		/* check for overlaps */
		foreach (config_get_path('gateways/gateway_group', []) as $gateway_group) {
			if (isset($id) && ($this_gateway_group_config && ($this_gateway_group_config === $gateway_group))) {
				if ($gateway_group['name'] != $post['name']) {
					$input_errors[] = gettext("Changing name on a gateway group is not allowed.");
				}
				continue;
			}

			if ($gateway_group['name'] == $post['name']) {
				$input_errors[] = sprintf(gettext('A gateway group with this name "%s" already exists.'), $post['name']);
				break;
			}
		}
	}

	/* Build list of items in group with priority */
	$pconfig['item'] = array();
	foreach ($a_gateways as $gwname => $gateway) {
		if ($post[$gwname] > 0) {
			$vipname = "{$gwname}_vip";
			/* we have a priority above 0 (disabled), add item to list */
			$pconfig['item'][] = "{$gwname}|{$post[$gwname]}|{$post[$vipname]}";
		}
		/* check for overlaps */
		if ($post['name'] == $gwname) {
			$input_errors[] = sprintf(gettext('A gateway group cannot have the same name as a gateway "%s" please choose another name.'), $post['name']);
		}

	}
	if (count($pconfig['item']) == 0) {
		$input_errors[] = gettext("No gateway(s) have been selected to be used in this group");
	}

	if (!$input_errors) {
		$gateway_group = array();
		$gateway_group['name'] = $post['name'];
		$gateway_group['item'] = $pconfig['item'];
		$gateway_group['trigger'] = $post['trigger'];
		if (!empty($pconfig['keep_failover_states'])) {
			$gateway_group['keep_failover_states'] = $pconfig['keep_failover_states'];
		}
		$gateway_group['descr'] = $post['descr'];

		if ($this_gateway_group_config) {
			config_set_path("gateways/gateway_group/{$id}", $gateway_group);
		} else {
			config_set_path('gateways/gateway_group/', $gateway_group);
		}

		mark_subsystem_dirty('staticroutes');
		mark_subsystem_dirty('gwgroup.' . $gateway_group['name']);

		write_config("Gateway Groups settings saved");
	}

	return array('input_errors' => $input_errors, 'item' => $pconfig['item']);
}

/*
 * Where a gateway group is referenced by name, as a list of descriptions:
 * firewall rules (policy routing) and the interface of Dynamic DNS clients,
 * OpenVPN servers/clients and IPsec phase 1 entries.
 */
function routing_gateway_group_users($name) {
	$users = array();
	foreach (get_filter_rules_list() as $idx => $rule) {
		if (($rule['gateway'] ?? null) == $name) {
			$users[] = sprintf(gettext('firewall rule "%s"'), ($rule['descr'] ?? '') !== '' ? $rule['descr'] : $idx);
		}
	}
	foreach (config_get_path('dyndnses/dyndns', []) as $dyndns) {
		if (is_array($dyndns) && (($dyndns['interface'] ?? null) == $name)) {
			$users[] = sprintf(gettext('Dynamic DNS client "%s"'), $dyndns['host'] ?? '');
		}
	}
	foreach (array('openvpn-server' => gettext('OpenVPN server'), 'openvpn-client' => gettext('OpenVPN client')) as $mode => $label) {
		foreach (config_get_path("openvpn/{$mode}", []) as $vpn) {
			if (is_array($vpn) && (($vpn['interface'] ?? null) == $name)) {
				$users[] = sprintf('%s "%s"', $label, ($vpn['description'] ?? '') !== '' ? $vpn['description'] : ($vpn['vpnid'] ?? ''));
			}
		}
	}
	foreach (config_get_path('ipsec/phase1', []) as $ph1) {
		if (is_array($ph1) && (($ph1['interface'] ?? null) == $name)) {
			$users[] = sprintf(gettext('IPsec phase 1 "%s"'), ($ph1['descr'] ?? '') !== '' ? $ph1['descr'] : ($ph1['ikeid'] ?? ''));
		}
	}
	return $users;
}

/*
 * Delete the gateway group at position $id. Refused while it is the
 * default gateway or referenced elsewhere (it used to be stripped from
 * firewall rules silently). Returns true when deleted.
 */
function routing_delete_gateway_group($id, &$input_errors) {
	$a_gateway_groups = config_get_path('gateways/gateway_group', []);
	if (!$a_gateway_groups[$id]) {
		return false;
	}
	$name = $a_gateway_groups[$id]['name'];
	if ((config_get_path('gateways/defaultgw4', '') == $name) ||
	    (config_get_path('gateways/defaultgw6', '') == $name)) {
		$input_errors[] = gettext('Cannot remove a gateway group that is being used as the default gateway.');
		return false;
	}
	$users = routing_gateway_group_users($name);
	if (!empty($users)) {
		$input_errors[] = sprintf(gettext('Gateway group "%1$s" cannot be deleted because it is in use by %2$s.'),
		    $name, implode(', ', $users));
		return false;
	}

	$changedesc = gettext("Gateway Groups") . ": " . sprintf(gettext("removed gateway group %s"), $id);
	config_del_path("gateways/gateway_group/{$id}");
	write_config($changedesc);
	mark_subsystem_dirty('staticroutes');
	$gw_subsystem = 'gwgroup.' . $name;
	if (is_subsystem_dirty($gw_subsystem)) {
		clear_subsystem_dirty($gw_subsystem);
	}
	return true;
}

/* ------------------------------------------------------------------ */
/* Static routes                                                       */
/* ------------------------------------------------------------------ */

/*
 * Validate and save a static route from the edit form's fields (network,
 * network_subnet, gateway, disabled, descr). $id is the route's position,
 * null for a new route. Returns the input errors; empty when saved.
 */
function routing_save_static_route($post, $id = null) {
	global $aliastable;

	$a_gateways = get_gateways(GW_CACHE_DISABLED | GW_CACHE_LOCALHOST);
	$this_routes_config = isset($id) ? config_get_path("staticroutes/route/{$id}") : null;
	$input_errors = array();

	/* input validation */
	$reqdfields = explode(" ", "network network_subnet gateway");
	$reqdfieldsn = explode(",",
		gettext("Destination network") . "," .
		gettext("Destination network bit count") . "," .
		gettext("Gateway"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($post['network'] && !is_ipaddr($post['network']) &&
	    (!is_alias($post['network']) || !in_array(alias_get_type($post['network']), array('host', 'network', 'url')))) {
		$input_errors[] = gettext("A valid IPv4 or IPv6 destination network or an alias must be specified.");
	}
	if (($post['network_subnet'] && !is_numeric($post['network_subnet']))) {
		$input_errors[] = gettext("A valid destination network bit count must be specified.");
	}
	if (($post['gateway']) && is_ipaddr($post['network'])) {
		if (!isset($a_gateways[$post['gateway']])) {
			$input_errors[] = gettext("A valid gateway must be specified.");
		} else if (isset($a_gateways[$post['gateway']]['disabled']) && !$post['disabled']) {
			$input_errors[] = gettext("The gateway is disabled but the route is not. The route must be disabled in order to choose a disabled gateway.");
		} else {
			// Note that the 3rd parameter "disabled" must be passed as explicitly true or false.
			if (!validate_address_family($post['network'], $post['gateway'], $post['disabled'] ? true : false) &&
				!validate_address_family($post['network'], array_get_path($a_gateways, "{$post['gateway']}/gateway"), $post['disabled'] ? true : false)) {
				$input_errors[] = sprintf(gettext('The gateway "%1$s" is a different Address Family than network "%2$s".'), array_get_path($a_gateways, "{$post['gateway']}/gateway"), $post['network']);
			}
		}
	}

	/* check for overlaps */
	$current_targets = get_staticroutes(true);
	$new_targets = array();
	if (is_ipaddrv6($post['network'])) {
		$osn = gen_subnetv6($post['network'], $post['network_subnet']) . "/" . $post['network_subnet'];
		$new_targets[] = $osn;
	}
	if (is_ipaddrv4($post['network'])) {
		if ($post['network_subnet'] > 32) {
			$input_errors[] = gettext("A IPv4 subnet can not be over 32 bits.");
		} else {
			$osn = gen_subnet($post['network'], $post['network_subnet']) . "/" . $post['network_subnet'];
			$new_targets[] = $osn;
		}
	} elseif (is_alias($post['network'])) {
		$osn = $post['network'];
		foreach (preg_split('/\s+/', $aliastable[$osn]) as $tgt) {
			if (is_ipaddrv4($tgt)) {
				$tgt .= "/32";
			}
			if (is_ipaddrv6($tgt)) {
				$tgt .= "/128";
			}
			if (!is_subnet($tgt)) {
				continue;
			}
			$new_targets[] = $tgt;
		}
	}
	if (!isset($id)) {
		$id = count(config_get_path('staticroutes/route', []));
	}
	$oroute = $this_routes_config;
	$old_targets = array();
	if (!empty($oroute)) {
		$staticroute_file = g_get('tmp_path') . '/staticroute_' . $id;
		if (file_exists($staticroute_file)) {
			$old_targets = unserialize_data(file_get_contents($staticroute_file), []);
		}
		$staticroute_gw_file = $staticroute_file . '_gw';
		if (file_exists($staticroute_gw_file)) {
			$old_gateway = unserialize_data(file_get_contents($staticroute_gw_file), []);
		}
	}

	$overlaps = array_intersect($new_targets, array_diff($current_targets, $old_targets));
	if (!$post['disabled'] && count($overlaps)) {
		$input_errors[] = gettext("A route to these destination networks already exists") . ": " . implode(", ", $overlaps);
	}

	foreach (config_get_path('interfaces', []) as $if) {
		if (is_ipaddrv4($post['network']) &&
			isset($if['ipaddr']) && isset($if['subnet']) &&
			is_ipaddrv4($if['ipaddr']) && is_numeric($if['subnet']) &&
			($post['network_subnet'] == $if['subnet']) &&
			(gen_subnet($post['network'], $post['network_subnet']) == gen_subnet($if['ipaddr'], $if['subnet']))) {
			$input_errors[] = sprintf(gettext("This network conflicts with address configured on interface %s."), $if['descr']);
		} else if (is_ipaddrv6($post['network']) &&
			isset($if['ipaddrv6']) && isset($if['subnetv6']) &&
			is_ipaddrv6($if['ipaddrv6']) && is_numeric($if['subnetv6']) &&
			($post['network_subnet'] == $if['subnetv6']) &&
			(gen_subnetv6($post['network'], $post['network_subnet']) == gen_subnetv6($if['ipaddrv6'], $if['subnetv6']))) {
			$input_errors[] = sprintf(gettext("This network conflicts with address configured on interface %s."), $if['descr']);
		}
	}

	if ($input_errors) {
		return $input_errors;
	}

	$route = array();
	$route['network'] = $osn;
	$route['gateway'] = $post['gateway'];
	$route['descr'] = $post['descr'];
	if ($post['disabled']) {
		$route['disabled'] = true;
	} else {
		unset($route['disabled']);
	}

	$routes_apply_file = g_get('tmp_path') . '/.system_routes.apply';
	if (file_exists($routes_apply_file)) {
		$toapplylist = unserialize_data(file_get_contents($routes_apply_file), []);
	} else {
		$toapplylist = array();
	}
	config_set_path("staticroutes/route/{$id}", $route);

	if (!empty($oroute)) {
		/* This used to read a nested key that never exists (always null), so
		 * every edit queued the removal of all old targets, unchanged ones too. */
		$rgateway = $route['gateway'];
		if (!empty($old_gateway) && ($rgateway != $old_gateway)) {
			$delete_targets = $old_targets;
			$delgw = lookup_gateway_ip_by_name($old_gateway);
		} elseif (isset($route['disabled'])) {
			/* system_routing_configure() leaves disabled routes alone, so
			 * remove all of them now, as disabling from the list does. */
			$delete_targets = $old_targets;
			$delgw = lookup_gateway_ip_by_name($rgateway);
		} else {
			$delete_targets = array_diff($old_targets, $new_targets);
			$delgw = lookup_gateway_ip_by_name($rgateway);
		}
		if (count($delete_targets)) {
			foreach ($delete_targets as $dts) {
				$family = is_ipaddrv6($dts) || is_subnetv6($dts) ? "-inet6" : "-inet";
				if (!count(route_get($dts, '', true))) {
					continue;
				}
				$toapplylist[] = "/sbin/route delete " .
				    $family . " " . $dts . " " . $delgw;
			}
		}
	}

	file_put_contents($routes_apply_file, serialize($toapplylist));

	mark_subsystem_dirty('staticroutes');

	write_config(gettext("Saved static route configuration."));

	return array();
}

/* Delete the static route at $id; the kernel route goes on apply. */
function routing_delete_static_route($id) {
	if (!config_get_path("staticroutes/route/{$id}")) {
		return false;
	}
	$changedesc = gettext('Static Routes') . ": " . sprintf(gettext('removed route to %s'), config_get_path("staticroutes/route/{$id}/network"));
	delete_static_route($id, true);
	config_del_path("staticroutes/route/{$id}");
	write_config($changedesc);
	mark_subsystem_dirty('staticroutes');
	return true;
}

/* Delete several static routes (positions before any is removed). */
function routing_delete_static_routes($ids) {
	$deleted_routes = '';
	foreach ($ids as $routei) {
		$deleted_routes .= ' ' . config_get_path("staticroutes/route/{$routei}/network");
		delete_static_route($routei, true);
		config_del_path("staticroutes/route/{$routei}");
	}
	$changedesc = gettext('Static Routes') . ": " . sprintf(gettext('removed route to %s'), $deleted_routes);
	write_config($changedesc);
	mark_subsystem_dirty('staticroutes');
}

/*
 * Enable a disabled static route or disable an enabled one. A route whose
 * gateway is disabled cannot be enabled. Returns true when it was toggled.
 */
function routing_toggle_static_route($id, &$input_errors) {
	$a_gateways = get_gateways(GW_CACHE_ALL);
	$changedesc_prefix = gettext('Static Routes') . ": ";
	$this_route_config = config_get_path("staticroutes/route/{$id}");
	if (!$this_route_config) {
		return false;
	}
	if (isset($this_route_config['disabled'])) {
		// Do not enable a route whose gateway is disabled
		if (isset($a_gateways[$this_route_config['gateway']]['disabled'])) {
			$input_errors[] = $changedesc_prefix . sprintf(gettext('gateway is disabled, cannot enable route to %s'), $this_route_config['network']);
			return false;
		}
		config_del_path("staticroutes/route/{$id}/disabled");
		$changedesc = $changedesc_prefix . sprintf(gettext('enabled route to %s'), $this_route_config['network']);
	} else {
		delete_static_route($id);
		config_set_path("staticroutes/route/{$id}/disabled", true);
		$changedesc = $changedesc_prefix . sprintf(gettext('disabled route to %s'), $this_route_config['network']);
	}

	if (write_config($changedesc)) {
		mark_subsystem_dirty('staticroutes');
	}
	return true;
}

/* Move the routes at positions $selected before the route at $movebtn. */
function routing_move_static_routes($movebtn, $selected) {
	$a_routes = config_get_path('staticroutes/route', []);
	$a_routes_new = array();

	/* copy all routes < $movebtn and not selected */
	for ($i = 0; $i < $movebtn; $i++) {
		if (!in_array($i, $selected)) {
			$a_routes_new[] = $a_routes[$i];
		}
	}

	/* copy all selected routes */
	for ($i = 0; $i < count($a_routes); $i++) {
		if ($i == $movebtn) {
			continue;
		}
		if (in_array($i, $selected)) {
			$a_routes_new[] = $a_routes[$i];
		}
	}

	/* copy $movebtn route */
	if ($movebtn < count($a_routes)) {
		$a_routes_new[] = $a_routes[$movebtn];
	}

	/* copy all routes > $movebtn and not selected */
	for ($i = $movebtn+1; $i < count($a_routes); $i++) {
		if (!in_array($i, $selected)) {
			$a_routes_new[] = $a_routes[$i];
		}
	}
	if (count($a_routes_new) > 0) {
		config_set_path('staticroutes/route', $a_routes_new);
	}

	if (write_config(gettext('Saved static routes configuration.'))) {
		mark_subsystem_dirty('staticroutes');
	}
}
