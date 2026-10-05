<?php
/*
 * interfaces_tunnels.inc
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

/*
 * VLAN, VXLAN, GIF and GRE logic shared by the Interfaces pages and the
 * REST API. Moved out of interfaces_{vlan,vxlan,gif,gre}(_edit).php with the
 * form fields and ids as parameters instead of the request arrays. The
 * functions never redirect; the pages do that.
 *
 * Like the pages, a save configures the live interface first and writes the
 * configuration only when that worked; nothing is configured while there
 * are validation errors. Changes take effect at once (no apply step).
 */

require_once("config.gui.inc");
require_once("functions.inc");
require_once("interfaces.inc");
require_once("filter.inc");

/*
 * Whether the signed-in GUI user holds "user-config-readonly". Checked by the
 * VLAN pages before a change so a read-only user cannot leave a half-made
 * change behind (upstream issue 15282). GUI only: the REST API refuses
 * writes of read-only users before a handler runs.
 */
function interfaces_gui_read_only() {
	phpsession_begin();
	$guiuser = getUserEntry($_SESSION['Username']);
	$read_only = (is_array($guiuser) && userHasPrivilege($guiuser['item'], "user-config-readonly"));
	phpsession_end();
	return $read_only;
}

/*
 * Interfaces and VIPs that can carry a GIF, GRE or VXLAN tunnel (the parent
 * select of the edit pages). A VXLAN cannot run over lo0 or another VXLAN.
 */
function interfaces_tunnel_parent_list($type) {
	$parentlist = array();
	foreach (get_possible_listen_ips() as $ifn => $ifinfo) {
		if (($type == 'vxlan') &&
		    (($ifn == 'lo0') || (substr(get_real_interface($ifn), 0, 5) == 'vxlan'))) {
			continue;
		}
		$parentlist[$ifn] = $ifinfo;
	}

	return($parentlist);
}

/* ------------------------------------------------------------------ */
/* VLAN                                                                */
/* ------------------------------------------------------------------ */

function interfaces_vlan_tag_types() {
	return [
		'ctag' => 'C-Tag (0x8100)',
		'stag' => 'S-Tag (0x88A8)'
	];
}

/* The VLAN capable parent interfaces the VLAN edit page offers. */
function interfaces_vlan_parent_list() {
	$portlist = get_interface_list();
	$lagglist = get_lagg_interface_list();
	$portlist = array_merge($portlist, $lagglist);
	foreach ($lagglist as $lagg) {
		/* LAGG members cannot be assigned */
		$laggmembers = explode(',', $lagg['members']);
		foreach ($laggmembers as $lagm) {
			if (isset($portlist[$lagm])) {
				unset($portlist[$lagm]);
			}
		}
	}

	/* Do not allow OpenVPN TUN interfaces to be used for QinQ
	 * upstream issue 11675 */
	foreach ($portlist as $portname => $port) {
		if (strstr($portname, "ovpn")) {
			preg_match('/ovpn([cs])([1-9]+)/', $portname, $m);
			$type = ($m[1] == 'c') ? 'client' : 'server';
			foreach (config_get_path("openvpn/openvpn-{$type}", []) as $ovpn) {
				if (($ovpn['vpnid'] == $m[2]) && ($ovpn['dev_mode'] == 'tun')) {
					unset($portlist[$portname]);
				}
			}
		}
	}
	return $portlist;
}

/*
 * Save the VLAN edit form. $id is the position of the VLAN being edited,
 * or null for a new one. Returns the input errors; empty on success.
 */
function interfaces_vlan_save(array $post, $id, $read_only = false) {
	$input_errors = array();
	$this_vlan_config = isset($id) ? config_get_path("vlans/vlan/{$id}") : null;

	if ($read_only) {
		$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
	}

	/* input validation */
	$reqdfields = explode(" ", "if tag");
	$reqdfieldsn = array(gettext("Parent interface"), gettext("VLAN tag"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (isset($post['tag']) && !vlan_valid_tag($post['tag'])) {
		$input_errors[] = gettext("The VLAN tag must be an integer between 1 and 4094.");
	}
	if (isset($post['pcp']) && !empty($post['pcp']) && (!is_numericint($post['pcp']) || ($post['pcp'] < '0') || ($post['pcp'] > '7'))) {
		$input_errors[] = gettext("The VLAN Priority must be an integer between 0 and 7.");
	}

	if (!does_interface_exist($post['if'])) {
		$input_errors[] = gettext("Interface supplied as parent is invalid");
	}

	if (!array_key_exists($post['tag_type'], interfaces_vlan_tag_types())) {
		$input_errors[] = gettext("The selected VLAN Tag Type is invalid.");
	}

	if (isset($id)) {
		if ($post['tag'] && $post['tag'] != $this_vlan_config['tag']) {
			if (!empty($this_vlan_config['vlanif']) && convert_real_interface_to_friendly_interface_name($this_vlan_config['vlanif']) != NULL) {
				$input_errors[] = gettext("The VLAN tag cannot be changed while the interface is assigned.");
			}
		}
	}
	foreach (config_get_path('vlans/vlan', []) as $vlan) {
		if ($this_vlan_config && ($this_vlan_config === $vlan)) {
			continue;
		}

		if (($vlan['if'] == $post['if']) && ($vlan['tag'] == $post['tag'])) {
			$input_errors[] = sprintf(gettext("A VLAN with the tag %s is already defined on this interface."), $vlan['tag']);
			break;
		}
	}

	foreach (config_get_path('qinqs/qinqentry', []) as $qinq) {
		if ($qinq['tag'] == $post['tag'] && $qinq['if'] == $post['if']) {
			$input_errors[] = sprintf(gettext('A QinQ VLAN exists on %s with this tag. Please remove it to use this tag for a normal VLAN.'), $post['if']);
		}
	}

	if ($input_errors) {
		return $input_errors;
	}

	$confif = "";
	if ($this_vlan_config) {
		if (($this_vlan_config['if'] != $post['if']) || ($this_vlan_config['tag'] != $post['tag'])) {
			if (!empty($this_vlan_config['vlanif'])) {
				$confif = convert_real_interface_to_friendly_interface_name($this_vlan_config['vlanif']);
				// Destroy previous vlan
				FreeSense_interface_destroy($this_vlan_config['vlanif']);
			} else {
				FreeSense_interface_destroy(vlan_interface($this_vlan_config));
				$confif = convert_real_interface_to_friendly_interface_name(vlan_interface($this_vlan_config));
			}
			if ($confif != "") {
				config_set_path("interfaces/{$confif}/if", vlan_interface($post));
			}
		}
	}
	$vlan = array();
	$vlan['if'] = $post['if'];
	$vlan['tag_type'] = $post['tag_type'];
	$vlan['tag'] = $post['tag'];
	$vlan['pcp'] = $post['pcp'];
	$vlan['descr'] = $post['descr'];
	$vlan['vlanif'] = vlan_interface($post);
	$vlanif = interface_vlan_configure($vlan);
	if ($vlanif == NULL || $vlanif != $vlan['vlanif']) {
		FreeSense_interface_destroy($vlan['vlanif']);
		$input_errors[] = gettext("Error occurred creating interface, please retry.");
		return $input_errors;
	}

	if ($this_vlan_config) {
		config_set_path("vlans/vlan/{$id}", $vlan);
	} else {
		config_set_path('vlans/vlan/', $vlan);
	}

	write_config("VLAN interface added");

	if ($confif != "") {
		interface_configure($confif);
	}
	return array();
}

/*
 * Delete the VLAN at position $id. Returns true when it was deleted;
 * otherwise $input_errors says why (for example, it is assigned).
 */
function interfaces_vlan_delete($id, $read_only, &$input_errors) {
	$input_errors = array();
	if ($read_only) {
		$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
	}

	$this_vlan_config = config_get_path("vlans/vlan/{$id}");
	if (!isset($id)) {
		$input_errors[] = gettext("Wrong parameters supplied");
	} else if (empty($this_vlan_config)) {
		$input_errors[] = gettext("Wrong index supplied");
	/* check if still in use */
	} else if (vlan_inuse($this_vlan_config)) {
		$input_errors[] = gettext("This VLAN cannot be deleted because it is still being used as an interface.");
	}

	if ($input_errors) {
		return false;
	}

	if (does_interface_exist($this_vlan_config['vlanif'])) {
		FreeSense_interface_destroy($this_vlan_config['vlanif']);
	}
	config_del_path("vlans/vlan/{$id}");

	write_config("VLAN interface deleted");
	return true;
}

/* ------------------------------------------------------------------ */
/* VXLAN                                                               */
/* ------------------------------------------------------------------ */

/* Returns why the VXLAN cannot be deleted, or false when it is unused. */
function vxlan_inuse($num) {
	$vxlanif = config_get_path("vxlans/vxlan/{$num}/vxlanif");
	if (empty($vxlanif)) {
		return false;
	}

	$friendly = convert_real_interface_to_friendly_interface_name($vxlanif);
	if (!empty($friendly)) {
		if (!empty(link_interface_to_bridge($friendly))) {
			return gettext("This VXLAN cannot be deleted because it is a bridge member.");
		}
		if (!empty(link_interface_to_group($friendly))) {
			return gettext("This VXLAN cannot be deleted because it is a member of an interface group.");
		}
		return gettext("This VXLAN cannot be deleted because it is still being used as an interface.");
	}
	foreach (config_get_path('vlans/vlan', []) as $vlan) {
		if ($vlan['if'] == $vxlanif) {
			return gettext("This VXLAN cannot be deleted because it is the parent of a VLAN.");
		}
	}
	foreach (config_get_path('qinqs/qinqentry', []) as $qinq) {
		if ($qinq['if'] == $vxlanif) {
			return gettext("This VXLAN cannot be deleted because it is the parent of a QinQ.");
		}
	}
	foreach (config_get_path('laggs/lagg', []) as $lagg) {
		if (in_array($vxlanif, explode(',', $lagg['members']))) {
			return gettext("This VXLAN cannot be deleted because it is a LAGG member.");
		}
	}

	return false;
}

/*
 * Save the VXLAN edit form. $id is the position of the tunnel being
 * edited, or null for a new one. Returns the input errors; empty on success.
 */
function interfaces_vxlan_save(array $post, $id) {
	$input_errors = array();
	$this_vxlan_config = isset($id) ? config_get_path("vxlans/vxlan/{$id}") : null;

	$vxlan = array();
	$vxlan['if'] = $post['if'];
	$vxlan['ipproto'] = $post['ipproto'];
	$vxlan['mode'] = $post['mode'];
	$vxlan['vni'] = trim($post['vni']);
	if ($post['mode'] == 'multicast') {
		$vxlan['mcastgroup'] = trim($post['mcastgroup']);
	} else {
		$vxlan['remote-addr'] = trim($post['remote-addr']);
	}
	foreach (array('localport', 'remoteport', 'ttl') as $field) {
		if (trim($post[$field]) !== '') {
			$vxlan[$field] = trim($post[$field]);
		}
	}
	if (!isset($post['learn'])) {
		$vxlan['nolearn'] = '';
	}
	if (isset($post['allowrule'])) {
		$vxlan['allowrule'] = '';
	}
	$vxlan['descr'] = $post['descr'];
	/* Keep the interface name and MAC of an existing tunnel; never take them from the form. */
	$vxlan['vxlanif'] = $this_vxlan_config['vxlanif'] ?? '';
	$vxlan['mac'] = vxlan_is_valid_mac($this_vxlan_config['mac'] ?? '') ?
	    $this_vxlan_config['mac'] : vxlan_generate_mac();

	if (!array_key_exists($vxlan['if'], interfaces_tunnel_parent_list('vxlan'))) {
		$input_errors[] = gettext("A valid parent interface must be selected.");
	}

	$others = array();
	foreach (config_get_path('vxlans/vxlan', []) as $idx => $other) {
		if (isset($id) && ($idx == $id)) {
			continue;
		}
		$others[] = array(
			'vxlanif' => $other['vxlanif'],
			'ipproto' => $other['ipproto'],
			'mode' => $other['mode'],
			'localaddr' => interface_vxlan_local_address($other),
			'localport' => vxlan_localport($other),
			'vni' => $other['vni'],
		);
	}
	$input_errors = array_merge($input_errors,
	    vxlan_validate($vxlan, interface_vxlan_local_address($vxlan), $others));

	/* a tunnel sent from a bridge it is a member of would loop */
	$parentbridge = get_real_interface($vxlan['if']);
	$assignedas = empty($vxlan['vxlanif']) ? '' : convert_real_interface_to_friendly_interface_name($vxlan['vxlanif']);
	if (!empty($assignedas) && (substr($parentbridge, 0, 6) == 'bridge') &&
	    (link_interface_to_bridge($assignedas) == $parentbridge)) {
		$input_errors[] = gettext("The parent interface is a bridge that this VXLAN is a member of.");
	}

	if (!empty($vxlan['vxlanif']) && !preg_match("/^vxlan[0-9]+$/", $vxlan['vxlanif'])) {
		$input_errors[] = gettext("Invalid VXLAN interface.");
	}

	if ($input_errors) {
		return $input_errors;
	}

	$vxlanif = interface_vxlan_configure($vxlan);
	if (!is_string($vxlanif) || !preg_match("/^vxlan[0-9]+$/", $vxlanif)) {
		$input_errors[] = gettext("The VXLAN interface could not be created with these settings. The system log has the details.");
		/* the old tunnel was destroyed before the new one failed; bring it back */
		if ($this_vxlan_config) {
			$restore = $this_vxlan_config;
			if (is_string(interface_vxlan_configure($restore))) {
				$confif = convert_real_interface_to_friendly_interface_name($restore['vxlanif']);
				if ($confif != "") {
					interface_configure($confif);
					system_routing_configure($confif);
				}
			}
		}
		return $input_errors;
	}

	$vxlan['vxlanif'] = $vxlanif;
	if ($this_vxlan_config) {
		config_set_path("vxlans/vxlan/{$id}", $vxlan);
	} else {
		config_set_path('vxlans/vxlan/', $vxlan);
	}

	write_config("VXLAN interface saved");

	/* reapply the address, MTU and bridge membership of the recreated interface */
	$confif = convert_real_interface_to_friendly_interface_name($vxlanif);
	if ($confif != "") {
		interface_configure($confif);
		system_routing_configure($confif);
	}

	/* the pass rule follows the peer, port and parent */
	filter_configure();
	return array();
}

/* Delete the VXLAN at position $id; see interfaces_vlan_delete(). */
function interfaces_vxlan_delete($id, &$input_errors) {
	$input_errors = array();
	if (!isset($id)) {
		$input_errors[] = gettext("Wrong parameters supplied");
	} else if (empty(config_get_path("vxlans/vxlan/{$id}"))) {
		$input_errors[] = gettext("Wrong index supplied");
	/* check if still in use */
	} else if (($inuse = vxlan_inuse($id)) !== false) {
		$input_errors[] = $inuse;
	}
	if ($input_errors) {
		return false;
	}

	FreeSense_interface_destroy(config_get_path("vxlans/vxlan/{$id}/vxlanif"));
	config_del_path("vxlans/vxlan/{$id}");

	write_config("VXLAN interface deleted");

	/* drop the tunnel's pass rule */
	filter_configure();
	return true;
}

/* ------------------------------------------------------------------ */
/* GIF                                                                 */
/* ------------------------------------------------------------------ */

function gif_inuse($num) {
	$a_gifs = config_get_path('gifs/gif', []);
	$iflist = get_configured_interface_list(true);
	$if_config = config_get_path('interfaces', []);
	foreach ($iflist as $if) {
		if ($if_config[$if]['if'] == $a_gifs[$num]['gifif']) {
			return true;
		}
	}

	return false;
}

/*
 * Save the GIF edit form. $id is the position of the tunnel being edited,
 * or null for a new one. Returns the input errors; empty on success.
 */
function interfaces_gif_save(array $post, $id) {
	$input_errors = array();
	$this_gif_config = isset($id) ? config_get_path("gifs/gif/{$id}") : null;

	/* input validation */
	$reqdfields = explode(" ", "if remote-addr tunnel-local-addr tunnel-remote-addr tunnel-remote-net");
	$reqdfieldsn = array(gettext("Parent interface"), gettext("gif remote address"), gettext("gif tunnel local address"), gettext("gif tunnel remote address"), gettext("gif tunnel remote netmask"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ((!is_ipaddr($post['tunnel-local-addr']) || is_subnet($post['tunnel-local-addr'])) ||
	    (!is_ipaddr($post['tunnel-remote-addr']) || is_subnet($post['tunnel-remote-addr'])) ||
	    (!is_ipaddr($post['remote-addr']) || is_subnet($post['remote-addr']))) {
		$input_errors[] = gettext("The tunnel local and tunnel remote fields must have valid IP addresses and must not contain CIDR masks or prefixes.");
	}

	if (!is_numericint($post['tunnel-remote-net'])) {
		$input_errors[] = gettext("The gif tunnel subnet must be an integer.");
	}

	if (is_ipaddrv4($post['tunnel-local-addr'])) {
		if (!is_ipaddrv4($post['tunnel-remote-addr'])) {
			$input_errors[] = gettext("The gif tunnel remote address must be IPv4 where tunnel local address is IPv4.");
		}
		if ($post['tunnel-remote-net'] > 32 || $post['tunnel-remote-net'] < 1) {
			$input_errors[] = gettext("The gif tunnel subnet must be an integer between 1 and 32.");
		}
	}

	if (is_ipaddrv6($post['tunnel-local-addr'])) {
		if (!is_ipaddrv6($post['tunnel-remote-addr'])) {
			$input_errors[] = gettext("The gif tunnel remote address must be IPv6 where tunnel local address is IPv6.");
		}
		if ($post['tunnel-remote-net'] > 128 || $post['tunnel-remote-net'] < 1) {
			$input_errors[] = gettext("The gif tunnel subnet must be an integer between 1 and 128.");
		}
	}

	$alias = strstr($post['if'], '|');
	if ((is_ipaddrv4($alias) && !is_ipaddrv4($post['remote-addr'])) ||
	    (is_ipaddrv6($alias) && !is_ipaddrv6($post['remote-addr']))) {
		$input_errors[] = gettext("The alias IP address family has to match the family of the remote peer address.");
	}

	/* The parent without an "|address" suffix. */
	$parent = explode('|', (string)$post['if'])[0];
	foreach (config_get_path('gifs/gif', []) as $gif) {
		if ($this_gif_config && ($this_gif_config === $gif)) {
			continue;
		}

		/* FIXME: needs to perform proper subnet checks in the future */
		if (($gif['if'] == $parent) && ($gif['tunnel-remote-addr'] == $post['tunnel-remote-addr'])) {
			$input_errors[] = sprintf(gettext("A gif with the network %s is already defined."), $gif['tunnel-remote-addr']);
			break;
		}
	}

	if ($input_errors) {
		return $input_errors;
	}

	$gif = array();
	list($gif['if'], $gif['ipaddr']) = array_pad(explode("|", $post['if']), 2, null);
	$gif['tunnel-local-addr'] = $post['tunnel-local-addr'];
	$gif['tunnel-remote-addr'] = $post['tunnel-remote-addr'];
	$gif['tunnel-remote-net'] = $post['tunnel-remote-net'];
	$gif['remote-addr'] = $post['remote-addr'];
	$gif['descr'] = $post['descr'];
	if (isset($post['link1'])) {
		$gif['link1'] = '';
	}
	if (isset($post['link2'])) {
		$gif['link2'] = '';
	}

	/* Keep the interface name of an existing tunnel; never take it from the form. */
	$gifif = $this_gif_config['gifif'] ?? '';
	if (empty($gifif) ||
	    preg_match("/^gif[0-9]+$/", $gifif)) {
		/* Attempt initial configuration of the GIF if the
		 * interface is empty or looks like a GIF interface. */
		$gif['gifif'] = $gifif;
		$gif['gifif'] = interface_gif_configure($gif);
	} else {
		$input_errors[] = gettext("Invalid GIF interface.");
	}

	if (empty($gif['gifif']) ||
	    !preg_match("/^gif[0-9]+$/", $gif['gifif'])) {
		$input_errors[] = gettext("Error occurred creating interface, please retry.");
		return $input_errors;
	}

	if ($this_gif_config) {
		config_set_path("gifs/gif/{$id}", $gif);
	} else {
		config_set_path('gifs/gif/', $gif);
	}

	write_config("GIF interface added");

	$confif = convert_real_interface_to_friendly_interface_name($gif['gifif']);

	if ($confif != "") {
		interface_configure($confif);
	}
	return array();
}

/* Delete the GIF at position $id; see interfaces_vlan_delete(). */
function interfaces_gif_delete($id, &$input_errors) {
	$input_errors = array();
	if (!isset($id)) {
		$input_errors[] = gettext("Wrong parameters supplied");
	} else if (empty(config_get_path("gifs/gif/{$id}"))) {
		$input_errors[] = gettext("Wrong index supplied");
	/* check if still in use */
	} else if (gif_inuse($id)) {
		$input_errors[] = gettext("This gif TUNNEL cannot be deleted because it is still being used as an interface.");
	}
	if ($input_errors) {
		return false;
	}

	FreeSense_interface_destroy(config_get_path("gifs/gif/{$id}/gifif"));
	config_del_path("gifs/gif/{$id}");

	write_config("GIF interface deleted");
	return true;
}

/* ------------------------------------------------------------------ */
/* GRE                                                                 */
/* ------------------------------------------------------------------ */

function gre_inuse($num) {
	$a_gres = config_get_path('gres/gre', []);
	$iflist = get_configured_interface_list(true);
	$if_config = config_get_path('interfaces', []);
	foreach ($iflist as $if) {
		if ($if_config[$if]['if'] == $a_gres[$num]['greif']) {
			return true;
		}
	}

	return false;
}

/* The GRE form fields with the addresses in lower case (the page refills the form with these). */
function interfaces_gre_form_values(array $post) {
	$pconfig = $post;
	$pconfig['tunnel-local-addr'] = addrtolower($post['tunnel-local-addr']);
	$pconfig['tunnel-remote-addr'] = addrtolower($post['tunnel-remote-addr']);
	$pconfig['tunnel-local-addr6'] = addrtolower($post['tunnel-local-addr6']);
	$pconfig['tunnel-remote-addr6'] = addrtolower($post['tunnel-remote-addr6']);
	$pconfig['remote-addr'] = addrtolower($post['remote-addr']);
	return $pconfig;
}

/*
 * Save the GRE edit form. $id is the position of the tunnel being edited,
 * or null for a new one. Returns the input errors; empty on success.
 */
function interfaces_gre_save(array $post, $id) {
	$input_errors = array();
	$this_gre_config = isset($id) ? config_get_path("gres/gre/{$id}") : null;

	$pconfig = interfaces_gre_form_values($post);

	$tunnel_type = '';
	if ((!empty($pconfig['tunnel-local-addr'])) || (!empty($pconfig['tunnel-remote-addr']))) {
		$tunnel_type = 'v4';
	}
	if ((!empty($pconfig['tunnel-local-addr6'])) || (!empty($pconfig['tunnel-remote-addr6']))) {
		$tunnel_type .= 'v6';
	}

	/* input validation */
	if ($tunnel_type === 'v4v6') {
		$reqdfields = explode(" ", "if remote-addr tunnel-local-addr tunnel-remote-addr tunnel-remote-net tunnel-local-addr6 tunnel-remote-addr6 tunnel-remote-net6");
		$reqdfieldsn = array(
			gettext("Parent interface"),
			gettext("Remote Address"),
			gettext("Local IPv4 tunnel address"),
			gettext("Remote IPv4 tunnel address"),
			gettext("IPv4 tunnel subnet"),
			gettext("Local IPv6 tunnel address"),
			gettext("Remote IPv6 tunnel address"),
			gettext("IPv6 tunnel subnet"));
	} else if ($tunnel_type === 'v6') {
		$reqdfields = explode(" ", "if remote-addr tunnel-local-addr6 tunnel-remote-addr6 tunnel-remote-net6");
		$reqdfieldsn = array(
			gettext("Parent interface"),
			gettext("Remote Address"),
			gettext("Local IPv6 tunnel address"),
			gettext("Remote IPv6 tunnel address"),
			gettext("IPv6 tunnel subnet"));
	} else {
		$reqdfields = explode(" ", "if remote-addr tunnel-local-addr tunnel-remote-addr tunnel-remote-net");
		$reqdfieldsn = array(
			gettext("Parent interface"),
			gettext("Remote Address"),
			gettext("Local IPv4 tunnel address"),
			gettext("Remote IPv4 tunnel address"),
			gettext("IPv4 tunnel subnet"));
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($tunnel_type === '') {
		$input_errors[] = gettext("The tunnel needs either a valid IPv4 or IPv6 tunnel configuration.");
	}

	if (!is_ipaddr($post['remote-addr'])) {
		$input_errors[] = gettext("The remote address must be a valid IP address.");
	}

	if ((!is_ipaddrv4($post['tunnel-local-addr'])) &&
	    in_array($tunnel_type, array('v4', 'v4v6'))) {
		$input_errors[] = gettext("The IPv4 local tunnel address must be a valid IPv4 address.");
	}

	if ((!is_ipaddrv4($post['tunnel-remote-addr'])) &&
	    in_array($tunnel_type, array('v4', 'v4v6'))) {
		$input_errors[] = gettext("The IPv4 remote tunnel address must be a valid IPv4 address.");
	}

	if ((!is_ipaddrv6($post['tunnel-local-addr6'])) &&
	     in_array($tunnel_type, array('v6', 'v4v6'))) {
		$input_errors[] = gettext("The IPv6 local tunnel address must be a valid IPv6 address.");
	}

	if ((!is_ipaddrv6($post['tunnel-remote-addr6'])) &&
	     in_array($tunnel_type, array('v6', 'v4v6'))) {
		$input_errors[] = gettext("The IPv6 remote tunnel address must be a valid IPv6 address.");
	}

	if ((!is_numericint($post['tunnel-remote-net'])) || ($post['tunnel-remote-net'] > 32) || ($post['tunnel-remote-net'] < 1)) {
		$input_errors[] = gettext("The IPv4 tunnel subnet must be an integer between 1 and 32.");
	}

	if ((!is_numericint($post['tunnel-remote-net6'])) || ($post['tunnel-remote-net6'] > 128) || ($post['tunnel-remote-net6'] < 1)) {
		$input_errors[] = gettext("The IPv6 tunnel subnet must be an integer between 1 and 128.");
	}

	foreach (config_get_path('gres/gre', []) as $gre) {
		if ($this_gre_config && ($this_gre_config === $gre)) {
			continue;
		}

		if (($gre['if'] == $post['if']) && !empty($gre['tunnel-remote-addr']) &&
		    ($gre['tunnel-remote-addr'] == $post['tunnel-remote-addr'])) {
			$input_errors[] = sprintf(gettext("A GRE tunnel with the same IPv4 tunnel network is already defined."));
			break;
		}
		if (($gre['if'] == $post['if']) && !empty($gre['tunnel-remote-addr6']) &&
		    ($gre['tunnel-remote-addr6'] == $post['tunnel-remote-addr6'])) {
			$input_errors[] = sprintf(gettext("A GRE tunnel with the same IPv6 tunnel network is already defined."));
			break;
		}
	}

	if ($input_errors) {
		return $input_errors;
	}

	$gre = array();
	$gre['if'] = $post['if'];
	$gre['tunnel-local-addr'] = $post['tunnel-local-addr'];
	$gre['tunnel-remote-addr'] = $post['tunnel-remote-addr'];
	$gre['tunnel-local-addr6'] = $post['tunnel-local-addr6'];
	$gre['tunnel-remote-addr6'] = $post['tunnel-remote-addr6'];
	$gre['tunnel-remote-net'] = $post['tunnel-remote-net'];
	$gre['tunnel-remote-net6'] = $post['tunnel-remote-net6'];
	$gre['remote-addr'] = $post['remote-addr'];
	$gre['descr'] = $post['descr'];
	if (isset($post['link1']) && $post['link1']) {
		$gre['link1'] = '';
	}

	/* Keep the interface name of an existing tunnel; never take it from the form. */
	$greif = $this_gre_config['greif'] ?? '';
	if (empty($greif) ||
	    preg_match("/^gre[0-9]+$/", $greif)) {
		/* Attempt initial configuration of the GRE if the
		 * interface is empty or looks like a GRE interface. */
		$gre['greif'] = $greif;
		$gre['greif'] = interface_gre_configure($gre);
	} else {
		$input_errors[] = gettext("Invalid GRE interface.");
	}

	if (empty($gre['greif']) ||
	    !preg_match("/^gre[0-9]+$/", $gre['greif'])) {
		$input_errors[] = gettext("Error occurred creating interface, please retry.");
		return $input_errors;
	}

	if ($this_gre_config) {
		config_set_path("gres/gre/{$id}", $gre);
	} else {
		config_set_path('gres/gre/', $gre);
	}

	write_config("GRE interface added");

	$confif = convert_real_interface_to_friendly_interface_name($gre['greif']);

	if ($confif != "") {
		interface_configure($confif);
	}
	return array();
}

/* Delete the GRE at position $id; see interfaces_vlan_delete(). */
function interfaces_gre_delete($id, &$input_errors) {
	$input_errors = array();
	if (!isset($id)) {
		$input_errors[] = gettext("Wrong parameters supplied");
	} else if (empty(config_get_path("gres/gre/{$id}"))) {
		$input_errors[] = gettext("Wrong index supplied");
	/* check if still in use */
	} else if (gre_inuse($id)) {
		$input_errors[] = gettext("This GRE tunnel cannot be deleted because it is still being used as an interface.");
	}
	if ($input_errors) {
		return false;
	}

	/* the explicit route "Add Static Route" (link1) added does not go with the interface */
	$gre = config_get_path("gres/gre/{$id}");
	if (isset($gre['link1'])) {
		if (is_ipaddrv4($gre['tunnel-remote-addr']) && is_ipaddrv4($gre['tunnel-local-addr'])) {
			mwexec("/sbin/route -q delete -inet " . escapeshellarg($gre['tunnel-remote-addr'] . "/" . $gre['tunnel-remote-net']) .
			    " " . escapeshellarg($gre['tunnel-local-addr']));
		}
		if (is_ipaddrv6($gre['tunnel-remote-addr6']) && is_ipaddrv6($gre['tunnel-local-addr6'])) {
			mwexec("/sbin/route -q delete -inet6 " . escapeshellarg($gre['tunnel-remote-addr6'] . "/" . $gre['tunnel-remote-net6']) .
			    " " . escapeshellarg($gre['tunnel-local-addr6']));
		}
	}
	FreeSense_interface_destroy($gre['greif']);
	config_del_path("gres/gre/{$id}");

	write_config("GRE interface deleted");
	return true;
}
