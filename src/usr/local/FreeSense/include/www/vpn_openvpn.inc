<?php
/*
 * vpn_openvpn.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc.
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
 * OpenVPN logic shared by vpn_openvpn_server.php, vpn_openvpn_client.php,
 * vpn_openvpn_csc.php and the REST API, moved out of the pages with the form
 * fields as parameters. Servers are stored in openvpn/openvpn-server, clients
 * in openvpn/openvpn-client (both identified by vpnid, edited by position) and
 * client specific overrides in openvpn/openvpn-csc (by position). Like the
 * pages, every save and delete is applied at once: the instance is restarted
 * (or stopped), the override files are rewritten and the DNS Resolver is
 * reconfigured.
 *
 * Advanced options (custom options) need the page's "Edit Advanced" privilege;
 * openvpn_user_can_edit_advanced() checks it for the signed-in user (the API
 * signs in as the key's user).
 */

require_once("auth.inc");
require_once("certs.inc");
require_once("interfaces.inc");
require_once("openvpn.inc");
require_once("priv.inc");
require_once("services.inc");
require_once("util.inc");

/*
 * Whether the signed-in user ($_SESSION['Username']; the API sets it to the
 * key's user) may edit the advanced options guarded by $privilege
 * ("page-openvpn-server-advanced", "page-openvpn-client-advanced" or
 * "page-openvpn-csc-advanced").
 */
function openvpn_user_can_edit_advanced($privilege) {
	$user_entry = getUserEntry($_SESSION['Username']);
	$user_entry = $user_entry['item'];
	return (isAdminUID($_SESSION['Username']) || userHasPrivilege($user_entry, $privilege) || userHasPrivilege($user_entry, "page-all"));
}

/* The NetBIOS node types of the pages (guiconfig.inc's list; the API does not load guiconfig.inc). */
function openvpn_netbios_nodetypes() {
	global $netbios_nodetypes;
	if (is_array($netbios_nodetypes)) {
		return $netbios_nodetypes;
	}
	return array(
		'0' => "none",
		'1' => "b-node",
		'2' => "p-node",
		'4' => "m-node",
		'8' => "h-node");
}

/* The gateway creation choices of the server and client pages (radio buttons). */
function openvpn_create_gw_choices() {
	return array('both' => gettext('Both'), 'v4only' => gettext('IPv4 only'), 'v6only' => gettext('IPv6 only'));
}

/* The stored entry of kind $mode ("server", "client" or "csc") at position $id, or null. */
function openvpn_entry_conf($mode, $id) {
	if (($id === null) || !is_numericint((string)$id)) {
		return null;
	}
	return config_get_path("openvpn/openvpn-{$mode}/{$id}");
}

/*
 * Delete the server or client ($mode) at position $id, like the list page:
 * refused while its interface is assigned or when it has advanced options the
 * user may not edit. Returns null when there is no such entry (the page
 * redirects), else array('input_errors' => [...], 'deleted' => bool).
 */
function openvpn_instance_delete($mode, $id, $user_can_edit_advanced) {
	$this_config = (($id !== null) && is_numericint((string)$id)) ? config_get_path("openvpn/openvpn-{$mode}/{$id}") : null;
	if (!isset($this_config)) {
		return null;
	}
	$input_errors = array();
	$wc_msg = '';

	if (empty($this_config)) {
		$wc_msg = ($mode == 'server') ? gettext('Deleted empty OpenVPN server') : gettext('Deleted empty OpenVPN client');
	} elseif (openvpn_inuse($this_config['vpnid'], $mode)) {
		$input_errors[] = gettext("Cannot delete an OpenVPN instance while the interface is assigned. Remove the interface assignment first.");
	} elseif (!$user_can_edit_advanced && !empty($this_config['custom_options'])) {
		$input_errors[] = gettext("This user does not have sufficient privileges to delete an instance with Advanced options set.");
	} else {
		openvpn_delete($mode, $this_config);
		if ($mode == 'server') {
			$wc_msg = sprintf(gettext('Deleted OpenVPN server from %1$s:%2$s %3$s'), convert_friendly_interface_to_friendly_descr($this_config['interface']), $this_config['local_port'], $this_config['description']);
		} else {
			$wc_msg = sprintf(gettext('Deleted OpenVPN client to server %1$s:%2$s %3$s'), $this_config['server_addr'], $this_config['server_port'], $this_config['description']);
		}
	}
	if (!empty($wc_msg)) {
		config_del_path("openvpn/openvpn-{$mode}/{$id}");
		write_config($wc_msg);
		services_unbound_configure(false);
	}
	return array('input_errors' => $input_errors, 'deleted' => !empty($wc_msg));
}

/* ------------------------------------------------------------------ */
/* Servers                                                             */
/* ------------------------------------------------------------------ */

/*
 * The server edit page's form for $act "new" (the page's defaults), "edit" or
 * "dup" of the stored server $this_server_config (a duplicate gets the next
 * free UDP port). Null when the page shows no form values (the list, or an
 * edit of a missing entry).
 */
function openvpn_server_form($act, $this_server_config) {
	$pconfig = null;

	if ($act == "new") {
		$pconfig['data_ciphers'] = 'AES-256-GCM,AES-128-GCM,CHACHA20-POLY1305';
		$pconfig['data_ciphers_fallback'] = 'AES-256-CBC';
		$pconfig['autokey_enable'] = "yes";
		$pconfig['tlsauth_enable'] = "yes";
		$pconfig['tlsauth_keydir'] = "default";
		$pconfig['autotls_enable'] = "yes";
		$pconfig['dh_length'] = 2048;
		$pconfig['dev_mode'] = "tun";
		$pconfig['interface'] = "wan";
		$pconfig['local_port'] = openvpn_port_next('UDP');
		$pconfig['cert_depth'] = 1;
		$pconfig['create_gw'] = "both"; // v4only, v6only, or both (default: both)
		$pconfig['verbosity_level'] = 1; // Default verbosity is 1
		$pconfig['digest'] = "SHA256";
		$pconfig['allow_compression'] = "no";
		$pconfig['compression'] = "";
		$pconfig['inactive_seconds'] = 300;
		$pconfig['exit_notify'] = 1;
		$pconfig['remote_cert_tls'] = "yes";
	}

	if (($act == "edit") || ($act == "dup")) {

		if ($this_server_config) {
			$pconfig['disable'] = isset($this_server_config['disable']);
			$pconfig['mode'] = $this_server_config['mode'];
			$pconfig['protocol'] = $this_server_config['protocol'];
			$pconfig['authmode'] = $this_server_config['authmode'];
			if (isset($this_server_config['data_ciphers'])) {
				$pconfig['data_ciphers'] = $this_server_config['data_ciphers'];
			} else {
				$pconfig['data_ciphers'] = 'AES-256-GCM,AES-128-GCM,CHACHA20-POLY1305';
			}
			$pconfig['dev_mode'] = $this_server_config['dev_mode'];
			$pconfig['interface'] = $this_server_config['interface'];

			if (!empty($this_server_config['ipaddr'])) {
				$pconfig['interface'] = $pconfig['interface'] . '|' . $this_server_config['ipaddr'];
			}

			$pconfig['local_port'] = $this_server_config['local_port'];
			$pconfig['description'] = $this_server_config['description'];
			$pconfig['custom_options'] = $this_server_config['custom_options'];

			if ($pconfig['mode'] != "p2p_shared_key") {
				if ($this_server_config['tls']) {
					$pconfig['tlsauth_enable'] = "yes";
					$pconfig['tls'] = base64_decode($this_server_config['tls']);
					$pconfig['tls_type'] = $this_server_config['tls_type'];
					$pconfig['tlsauth_keydir'] = $this_server_config['tlsauth_keydir'];
				}

				$pconfig['caref'] = $this_server_config['caref'];
				$pconfig['crlref'] = $this_server_config['crlref'];
				if (isset($this_server_config['ocspcheck'])) {
					$pconfig['ocspcheck'] = "yes";
				}
				$pconfig['ocspurl'] = $this_server_config['ocspurl'];
				$pconfig['certref'] = $this_server_config['certref'];
				$pconfig['dh_length'] = $this_server_config['dh_length'];
				$pconfig['ecdh_curve'] = $this_server_config['ecdh_curve'];
				if (isset($this_server_config['cert_depth'])) {
					$pconfig['cert_depth'] = $this_server_config['cert_depth'];
				} else {
					$pconfig['cert_depth'] = 1;
				}
				if ($pconfig['mode'] == "server_tls_user") {
					$pconfig['strictusercn'] = $this_server_config['strictusercn'];
				}
				$pconfig['remote_cert_tls'] = isset($this_server_config['remote_cert_tls']);
			} else {
				$pconfig['shared_key'] = base64_decode($this_server_config['shared_key']);
			}
			$pconfig['data_ciphers_fallback'] = $this_server_config['data_ciphers_fallback'];
			$pconfig['digest'] = !empty($this_server_config['digest']) ? $this_server_config['digest'] : "SHA256";

			$pconfig['tunnel_network'] = $this_server_config['tunnel_network'];
			$pconfig['tunnel_networkv6'] = $this_server_config['tunnel_networkv6'];
			$pconfig['tunnel_networkv6_type'] = $this_server_config['tunnel_networkv6_type'];
			$pconfig['tunnel_track6_interface'] = $this_server_config['tunnel_track6_interface'];
			$pconfig['tunnel_track6_prefix_id'] = $this_server_config['tunnel_track6_prefix_id'];

			$pconfig['remote_network'] = $this_server_config['remote_network'];
			$pconfig['remote_networkv6'] = $this_server_config['remote_networkv6'];
			$pconfig['gwredir'] = $this_server_config['gwredir'];
			$pconfig['gwredir6'] = $this_server_config['gwredir6'];
			$pconfig['local_network'] = $this_server_config['local_network'];
			$pconfig['local_networkv6'] = $this_server_config['local_networkv6'];
			$pconfig['maxclients'] = $this_server_config['maxclients'];
			$pconfig['connlimit'] = $this_server_config['connlimit'];
			$pconfig['allow_compression'] = $this_server_config['allow_compression'];
			$pconfig['compression'] = $this_server_config['compression'];
			$pconfig['compression_push'] = $this_server_config['compression_push'];
			$pconfig['passtos'] = $this_server_config['passtos'];
			$pconfig['client2client'] = $this_server_config['client2client'];

			$pconfig['dynamic_ip'] = $this_server_config['dynamic_ip'];
			$pconfig['topology'] = $this_server_config['topology'];

			$pconfig['serverbridge_dhcp'] = $this_server_config['serverbridge_dhcp'];
			$pconfig['serverbridge_interface'] = $this_server_config['serverbridge_interface'];
			$pconfig['serverbridge_routegateway'] = $this_server_config['serverbridge_routegateway'];
			$pconfig['serverbridge_dhcp_start'] = $this_server_config['serverbridge_dhcp_start'];
			$pconfig['serverbridge_dhcp_end'] = $this_server_config['serverbridge_dhcp_end'];

			$pconfig['dns_domain'] = $this_server_config['dns_domain'];
			if ($pconfig['dns_domain']) {
				$pconfig['dns_domain_enable'] = true;
			}

			$pconfig['dns_server1'] = $this_server_config['dns_server1'];
			$pconfig['dns_server2'] = $this_server_config['dns_server2'];
			$pconfig['dns_server3'] = $this_server_config['dns_server3'];
			$pconfig['dns_server4'] = $this_server_config['dns_server4'];

			if ($pconfig['dns_server1'] ||
			    $pconfig['dns_server2'] ||
			    $pconfig['dns_server3'] ||
			    $pconfig['dns_server4']) {
				$pconfig['dns_server_enable'] = true;
			}

			$pconfig['ntp_server1'] = $this_server_config['ntp_server1'];
			$pconfig['ntp_server2'] = $this_server_config['ntp_server2'];

			if ($pconfig['ntp_server1'] ||
			    $pconfig['ntp_server2']) {
				$pconfig['ntp_server_enable'] = true;
			}

			$pconfig['netbios_enable'] = $this_server_config['netbios_enable'];
			$pconfig['netbios_ntype'] = $this_server_config['netbios_ntype'];
			$pconfig['netbios_scope'] = $this_server_config['netbios_scope'];

			$pconfig['wins_server1'] = $this_server_config['wins_server1'];
			$pconfig['wins_server2'] = $this_server_config['wins_server2'];

			if ($pconfig['wins_server1'] ||
			    $pconfig['wins_server2']) {
				$pconfig['wins_server_enable'] = true;
			}

			$pconfig['nbdd_server1'] = $this_server_config['nbdd_server1'];
			$pconfig['nbdd_server2'] = $this_server_config['nbdd_server2'];

			if ($pconfig['nbdd_server1'] || $pconfig['nbdd_server2']) {
				$pconfig['nbdd_server_enable'] = true;
			}

			// just in case the modes switch
			$pconfig['autokey_enable'] = "yes";
			$pconfig['autotls_enable'] = "yes";

			$pconfig['duplicate_cn'] = isset($this_server_config['duplicate_cn']);

			if (isset($this_server_config['create_gw'])) {
				$pconfig['create_gw'] = $this_server_config['create_gw'];
			} else {
				$pconfig['create_gw'] = "both"; // v4only, v6only, or both (default: both)
			}

			if (isset($this_server_config['verbosity_level'])) {
				$pconfig['verbosity_level'] = $this_server_config['verbosity_level'];
			} else {
				$pconfig['verbosity_level'] = 1; // Default verbosity is 1
			}

			$pconfig['push_blockoutsidedns'] = $this_server_config['push_blockoutsidedns'];
			$pconfig['username_as_common_name']  = ($this_server_config['username_as_common_name'] != 'disabled');
			$pconfig['udp_fast_io'] = $this_server_config['udp_fast_io'];
			$pconfig['exit_notify'] = $this_server_config['exit_notify'];
			$pconfig['sndrcvbuf'] = $this_server_config['sndrcvbuf'];
			$pconfig['push_register_dns'] = $this_server_config['push_register_dns'];

			$pconfig['ping_method'] = $this_server_config['ping_method'];
			$pconfig['keepalive_interval'] = $this_server_config['keepalive_interval'];
			$pconfig['keepalive_timeout'] = $this_server_config['keepalive_timeout'];
			$pconfig['ping_seconds'] = $this_server_config['ping_seconds'];
			$pconfig['ping_push'] = empty($this_server_config['ping_push']) ? '' : 'yes';
			$pconfig['ping_action'] = $this_server_config['ping_action'];
			$pconfig['ping_action_seconds'] = $this_server_config['ping_action_seconds'];
			$pconfig['ping_action_push'] = empty($this_server_config['ping_action_push']) ? '' : 'yes';
			$pconfig['inactive_seconds'] = $this_server_config['inactive_seconds'] ?: 0;
		}
	}

	if ($act == "dup") {
		$pconfig['local_port'] = openvpn_port_next('UDP');
	}

	return $pconfig;
}

/*
 * Validate and save the server edit form $pconfig (the posted form) for the
 * server at position $id (null or a missing position: a new server); $act is
 * the form's "act" ("new" or "edit"). Like the page, a saved server is applied
 * at once (the instance restarts, the override files are rewritten, the DNS
 * Resolver is reconfigured). $pconfig is changed like the page changed it, so
 * a refused form is shown again as before. Returns the input errors (empty when
 * saved).
 */
function openvpn_server_save(array &$pconfig, $id, $act, $user_can_edit_advanced) {
	global $openvpn_topologies, $openvpn_tls_modes, $openvpn_exit_notify_server;
	global $openvpn_prots, $openvpn_dev_mode, $openvpn_cert_depths, $openvpn_dh_lengths;
	global $openvpn_allow_compression, $openvpn_compression_modes, $openvpn_ping_method;
	global $openvpn_ping_action, $openvpn_verbosity_level;

	$post = $pconfig;
	$input_errors = array();
	$netbios_nodetypes = openvpn_netbios_nodetypes();
	$openvpn_all_data_ciphers = openvpn_get_cipherlist();
	$certlist = openvpn_build_cert_list(false, true);
	if (($id !== null) && !is_numericint((string)$id)) {
		$id = null;
	}
	$this_server_config = isset($id) ? config_get_path("openvpn/openvpn-server/{$id}") : null;

	if ($this_server_config) {
		$vpnid = $this_server_config['vpnid'];
	} else {
		$vpnid = 0;
	}

	if ($pconfig['disable'] && openvpn_inuse($vpnid, 'server')) {
		$input_errors[] = gettext("Cannot disable an OpenVPN instance while the interface is assigned. Remove the interface assignment first.");
	}

	if (isset($pconfig['custom_options']) &&
	    ($pconfig['custom_options'] != $this_server_config['custom_options']) &&
	    !$user_can_edit_advanced) {
		$input_errors[] = gettext("This user does not have sufficient privileges to edit Advanced options on this instance.");
	}
	if (!$user_can_edit_advanced && !empty($this_server_config['custom_options'])) {
		$pconfig['custom_options'] = $this_server_config['custom_options'];
	}

	if (!empty($pconfig['mode']) &&
	    !array_key_exists($pconfig['mode'], openvpn_build_mode_list())) {
		$input_errors[] = gettext("The selected Server Mode is not valid.");
	}

	if (!empty($pconfig['dev_mode']) &&
	    !array_key_exists($pconfig['dev_mode'], $openvpn_dev_mode)) {
		$input_errors[] = gettext("The selected Device Mode is not valid.");
	}

	if (!empty($pconfig['protocol']) &&
	    !array_key_exists($pconfig['protocol'], $openvpn_prots)) {
		$input_errors[] = gettext("The selected Protocol is not valid.");
	}

	if (!empty($pconfig['interface']) &&
	    !array_key_exists($pconfig['interface'], openvpn_build_if_list())) {
		$input_errors[] = gettext("The selected Interface is not valid.");
	}

	if (!empty($pconfig['ecdh_curve']) &&
	    !array_key_exists($pconfig['ecdh_curve'], openvpn_get_curvelist())) {
		$input_errors[] = gettext("The selected ECDH Curve is not valid.");
	}

	$cipher_validation_list = array_keys($openvpn_all_data_ciphers);
	if (!in_array($pconfig['data_ciphers_fallback'], $cipher_validation_list)) {
		$input_errors[] = gettext("The selected Fallback Data Encryption Algorithm is not valid.");
	}

	/* Maximum option line length = 256, see upstream issue 11559 */
	if (!empty($pconfig['data_ciphers']) && is_array($pconfig['data_ciphers']) &&
	    (strlen("data-ciphers " . implode(",", $pconfig['data_ciphers'])) > 254)) {
		$input_errors[] = gettext("Too many Data Encryption Algorithms have been selected.");
	}

	if (!empty($pconfig['data_ciphers']) && is_array($pconfig['data_ciphers'])) {
		foreach ($pconfig['data_ciphers'] as $dc) {
			if (!in_array(trim($dc), $cipher_validation_list)) {
				$input_errors[] = gettext("One or more of the selected Data Encryption Algorithms is not valid.");
			}
		}
	} else {
		/* If data_ciphers is not empty and also not an array, it can't be valid. */
		$input_errors[] = gettext("The Data Encryption Algorithm list is not valid.");
	}

	if (!empty($pconfig['digest']) &&
	    !array_key_exists($pconfig['digest'], openvpn_get_digestlist())) {
		$input_errors[] = gettext("The selected Auth Digest Algorithm is not valid.");
	}

	if (!empty($pconfig['cert_depth']) &&
	    !array_key_exists($pconfig['cert_depth'], $openvpn_cert_depths)) {
		$input_errors[] = gettext("The selected Certificate Depth is not valid.");
	}

	list($iv_iface, $iv_ip) = explode ("|", $pconfig['interface']);
	if (is_ipaddrv4($iv_ip) && (stristr($pconfig['protocol'], "6") !== false)) {
		$input_errors[] = gettext("Protocol and IP address families do not match. An IPv6 protocol and an IPv4 IP address cannot be selected.");
	} elseif (is_ipaddrv6($iv_ip) && (stristr($pconfig['protocol'], "6") === false)) {
		$input_errors[] = gettext("Protocol and IP address families do not match. An IPv4 protocol and an IPv6 IP address cannot be selected.");
	} elseif ((stristr($pconfig['protocol'], "6") === false) && !get_interface_ip($iv_iface) && ($pconfig['interface'] != "any")) {
		// If an underlying interface to be used by this server uses DHCP, then it may not have received an IP address yet.
		// So in that case we do not report a problem.
		if (!interface_has_dhcp($iv_iface, 4)) {
			$input_errors[] = gettext("An IPv4 protocol was selected, but the selected interface has no IPv4 address.");
		}
	} elseif ((stristr($pconfig['protocol'], "6") !== false) && !get_interface_ipv6($iv_iface) && ($pconfig['interface'] != "any")) {
		// If an underlying interface to be used by this server uses DHCP6, then it may not have received an IP address yet.
		// So in that case we do not report a problem.
		if (!interface_has_dhcp($iv_iface, 6)) {
			$input_errors[] = gettext("An IPv6 protocol was selected, but the selected interface has no IPv6 address.");
		}
	}

	if ($pconfig['mode'] != "p2p_shared_key") {
		$tls_mode = true;

		if (!empty($pconfig['caref']) &&
		    !array_key_exists($pconfig['caref'], cert_build_list('ca', 'OpenVPN'))) {
			$input_errors[] = gettext("The selected Peer Certificate Authority is not valid.");
		}

		if (!empty($pconfig['crlref']) &&
		    !array_key_exists($pconfig['crlref'], openvpn_build_crl_list())) {
			$input_errors[] = gettext("The selected Peer Certificate Revocation List is not valid.");
		}

		if (!empty($pconfig['certref']) &&
		    !array_key_exists($pconfig['certref'], $certlist['server'] + $certlist['non-server'])) {
			$input_errors[] = gettext("The selected Server Certificate is not valid.");
		}

	} else {
		$tls_mode = false;
	}

	if (empty($pconfig['authmode']) && (($pconfig['mode'] == "server_user") || ($pconfig['mode'] == "server_tls_user"))) {
		$input_errors[] = gettext("A Backend for Authentication must be selected if the server mode requires User Auth.");
	}

	if (!empty($pconfig['authmode'])) {
		if (!is_array($pconfig['authmode'])) {
			$input_errors[] = gettext("The Backend for Authentication list is not valid.");
		} else {
			foreach ($pconfig['authmode'] as $authmode) {
				if (!array_key_exists($authmode, auth_get_authserver_list())) {
					$input_errors[] = gettext("A valid Backend for Authentication must be selected.");
					break;
				}
			}
		}
	}

	/* input validation */
	if ($result = openvpn_validate_port($pconfig['local_port'], 'Local port', 1)) {
		$input_errors[] = $result;
	}

	/* Maximum option line length = 256, see upstream issue 11104 */
	if (!empty($pconfig['authmode']) && is_array($pconfig['authmode']) && is_port($pconfig['local_port'])) {
		$strictusercn = "false";
		if ($pconfig['strictusercn']) {
			$strictusercn = "true";
		}
		$authstring = openvpn_authscript_string(implode(',', $pconfig['authmode']),
			    $strictusercn, $vpnid, $pconfig['local_port']);
		if (strlen($authstring) > 254) {
			$input_errors[] = gettext("Too many Authentication Backends have been selected or their names are too long.");
		}
	}

	if (!empty($pconfig['tunnel_network']) && !openvpn_validate_tunnel_network($pconfig['tunnel_network'], 'ipv4')) {
		$input_errors[] = gettext("The field 'IPv4 Tunnel Network' must contain a valid IPv4 subnet with CIDR mask or an alias with a single IPv4 subnet with CIDR mask.");
	}

	if (!empty($pconfig['tunnel_network']) &&
	    (!isset($this_server_config) ||
	    ($this_server_config['tunnel_network'] != $pconfig['tunnel_network'])) &&
	    openvpn_is_tunnel_network_in_use($pconfig['tunnel_network'])) {
		$input_errors[] = gettext("The submitted IPv4 Tunnel Network is already in use.");
	}

	if (!empty($pconfig['tunnel_networkv6']) && !openvpn_validate_tunnel_network($pconfig['tunnel_networkv6'], 'ipv6')) {
		$input_errors[] = gettext("The field 'IPv6 Tunnel Network' must contain a valid IPv6 prefix or an alias with a single IPv6 prefix.");
	}

	if (!empty($pconfig['tunnel_networkv6']) &&
	    (!isset($this_server_config) ||
	    ($this_server_config['tunnel_networkv6'] != $pconfig['tunnel_networkv6'])) &&
	    openvpn_is_tunnel_network_in_use($pconfig['tunnel_networkv6'])) {
		$input_errors[] = gettext("The submitted IPv6 Tunnel Network is already in use.");
	}

	if (!empty($pconfig['tunnel_track6_interface']) && !empty($pconfig['tunnel_track6_prefix_id'])) {
		if (!ctype_xdigit($pconfig['tunnel_track6_prefix_id'])) {
			$input_errors[] = gettext("A valid hexadecimal number must be entered for the IPv6 prefix ID.");
		}
	}

	if ($result = openvpn_validate_cidr($pconfig['remote_network'], 'IPv4 Remote Network', true, "ipv4", true)) {
		$input_errors[] = $result;
	}

	if ($result = openvpn_validate_cidr($pconfig['remote_networkv6'], 'IPv6 Remote Network', true, "ipv6", true)) {
		$input_errors[] = $result;
	}

	if ($result = openvpn_validate_cidr($pconfig['local_network'], 'IPv4 Local Network', true, "ipv4", true)) {
		$input_errors[] = $result;
	}

	if ($result = openvpn_validate_cidr($pconfig['local_networkv6'], 'IPv6 Local Network', true, "ipv6", true)) {
		$input_errors[] = $result;
	}

	if (!empty($pconfig['allow_compression']) &&
	    !array_key_exists($pconfig['allow_compression'], $openvpn_allow_compression)) {
		$input_errors[] = gettext("The selected Allow Compression value is not valid.");
	}

	if (!empty($pconfig['compression']) &&
	    !array_key_exists($pconfig['compression'], $openvpn_compression_modes)) {
		$input_errors[] = gettext("The selected Compression is not valid.");
	}

	$portused = openvpn_port_used($pconfig['protocol'], $pconfig['interface'], $pconfig['local_port'], $vpnid);
	if (($portused != $vpnid) && ($portused != 0)) {
		$input_errors[] = gettext("The specified 'Local port' is in use. Please select another value");
	}

	if ($pconfig['autokey_enable']) {
		$pconfig['shared_key'] = openvpn_create_key();
	}

	if (!$tls_mode && !$pconfig['autokey_enable']) {
		if (!strstr($pconfig['shared_key'], "-----BEGIN OpenVPN Static key V1-----") ||
		    !strstr($pconfig['shared_key'], "-----END OpenVPN Static key V1-----")) {
			$input_errors[] = gettext("The field 'Shared Key' does not appear to be valid");
		}
	}

	if ($tls_mode && $pconfig['tlsauth_enable'] && !$pconfig['autotls_enable']) {
		if (!strstr($pconfig['tls'], "-----BEGIN OpenVPN Static key V1-----") ||
		    !strstr($pconfig['tls'], "-----END OpenVPN Static key V1-----")) {
			$input_errors[] = gettext("The field 'TLS Key' does not appear to be valid");
		}
		if (!in_array($pconfig['tls_type'], array_keys($openvpn_tls_modes))) {
			$input_errors[] = gettext("The field 'TLS Key Usage Mode' is not valid");
		}
		if (!empty($pconfig['tlsauth_keydir']) &&
		    !array_key_exists($pconfig['tlsauth_keydir'], openvpn_get_keydirlist())) {
			$input_errors[] = gettext("The selected TLS Key Direction is not valid.");
		}
	}

	if ($pconfig['dns_domain_enable'] && !is_domain($pconfig['dns_domain'])) {
		$input_errors[] = gettext("The field 'DNS Default Domain' must contain a valid domain name");
	}

	if ($pconfig['netbios_enable'] && !empty($pconfig['netbios_scope']) &&
	    !openvpn_is_valid_netbios_scope($pconfig['netbios_scope'])) {
		$input_errors[] = gettext("The field 'NetBIOS Scope ID' may only contain letters, digits, hyphens, underscores and dots");
	}

	if ($pconfig['dns_server_enable']) {
		if (!empty($pconfig['dns_server1']) && !is_ipaddr(trim($pconfig['dns_server1']))) {
			$input_errors[] = gettext("The field 'DNS Server #1' must contain a valid IPv4 or IPv6 address");
		}
		if (!empty($pconfig['dns_server2']) && !is_ipaddr(trim($pconfig['dns_server2']))) {
			$input_errors[] = gettext("The field 'DNS Server #2' must contain a valid IPv4 or IPv6 address");
		}
		if (!empty($pconfig['dns_server3']) && !is_ipaddr(trim($pconfig['dns_server3']))) {
			$input_errors[] = gettext("The field 'DNS Server #3' must contain a valid IPv4 or IPv6 address");
		}
		if (!empty($pconfig['dns_server4']) && !is_ipaddr(trim($pconfig['dns_server4']))) {
			$input_errors[] = gettext("The field 'DNS Server #4' must contain a valid IPv4 or IPv6 address");
		}
	}

	if ($pconfig['ntp_server_enable']) {
		if (!empty($pconfig['ntp_server1']) && !is_ipaddr(trim($pconfig['ntp_server1']))) {
			$input_errors[] = gettext("The field 'NTP Server #1' must contain a valid IP address");
		}
		if (!empty($pconfig['ntp_server2']) && !is_ipaddr(trim($pconfig['ntp_server2']))) {
			$input_errors[] = gettext("The field 'NTP Server #2' must contain a valid IP address");
		}
		if (!empty($pconfig['ntp_server3']) && !is_ipaddr(trim($pconfig['ntp_server3']))) {
			$input_errors[] = gettext("The field 'NTP Server #3' must contain a valid IP address");
		}
		if (!empty($pconfig['ntp_server4']) && !is_ipaddr(trim($pconfig['ntp_server4']))) {
			$input_errors[] = gettext("The field 'NTP Server #4' must contain a valid IP address");
		}
	}

	if ($pconfig['netbios_enable']) {
		if ($pconfig['wins_server_enable']) {
			if (!empty($pconfig['wins_server1']) && !is_ipaddr(trim($pconfig['wins_server1']))) {
				$input_errors[] = gettext("The field 'WINS Server #1' must contain a valid IP address");
			}
			if (!empty($pconfig['wins_server2']) && !is_ipaddr(trim($pconfig['wins_server2']))) {
				$input_errors[] = gettext("The field 'WINS Server #2' must contain a valid IP address");
			}
		}
		if ($pconfig['nbdd_server_enable']) {
			if (!empty($pconfig['nbdd_server1']) && !is_ipaddr(trim($pconfig['nbdd_server1']))) {
				$input_errors[] = gettext("The field 'NetBIOS Data Distribution Server #1' must contain a valid IP address");
			}
			if (!empty($pconfig['nbdd_server2']) && !is_ipaddr(trim($pconfig['nbdd_server2']))) {
				$input_errors[] = gettext("The field 'NetBIOS Data Distribution Server #2' must contain a valid IP address");
			}
		}

		if (!empty($pconfig['netbios_ntype']) &&
		    !array_key_exists($pconfig['netbios_ntype'], $netbios_nodetypes)) {
			$input_errors[] = gettext("The selected NetBIOS Node Type is not valid.");
		}
	}

	if ($pconfig['maxclients'] && !is_numericint($pconfig['maxclients'])) {
		$input_errors[] = gettext("The field 'Concurrent connections' must be numeric.");
	}

	if ($pconfig['connlimit'] && !is_numericint($pconfig['connlimit'])) {
		$input_errors[] = gettext("The field 'Duplicate Connection Limit' must be numeric.");
	}

	if (!array_key_exists($pconfig['topology'], $openvpn_topologies)) {
		$input_errors[] = gettext("The field 'Topology' contains an invalid selection");
	}

	/* If we are not in shared key mode, then we need the CA/Cert. */
	$reqdfields = $reqdfieldsn = array();
	if ($pconfig['mode'] != "p2p_shared_key") {
		if (empty(trim($pconfig['certref']))) {
			$input_errors[] = gettext("The selected certificate is not valid");
		}

		if (!empty($pconfig['dh_length']) && !in_array($pconfig['dh_length'], array_keys($openvpn_dh_lengths))) {
			$input_errors[] = gettext("The specified DH Parameter length is invalid or " .
				"the DH file does not exist.");
		}

		if (!empty($pconfig['ecdh_curve']) && !openvpn_validate_curve($pconfig['ecdh_curve'])) {
			$input_errors[] = gettext("The specified ECDH Curve is invalid.");
		}
		$reqdfields = explode(" ", "caref certref");
		$reqdfieldsn = array(gettext("Certificate Authority"), gettext("Certificate"));
	} elseif (!$pconfig['autokey_enable']) {
		/* We only need the shared key filled in if we are in shared key mode and autokey is not selected. */
		$reqdfields = array('shared_key');
		$reqdfieldsn = array(gettext('Shared key'));
	}

	if (($pconfig['mode'] == "p2p_shared_key") &&
	    (strstr($pconfig['data_ciphers_fallback'], "GCM") ||
	    strstr($pconfig['data_ciphers_fallback'], "CHACHA"))) {
		$input_errors[] = gettext("AEAD Encryption Algorithms (GCM, CHACHA) cannot be used with Shared Key mode.");
	}

	if ($pconfig['dev_mode'] == "tap") {
		if (!empty($pconfig['serverbridge_interface']) &&
		    !array_key_exists($pconfig['serverbridge_interface'], openvpn_build_bridge_list())) {
			$input_errors[] = gettext("The selected Server Bridge Interface is not valid.");
		} elseif (empty($pconfig['serverbridge_interface']) && empty($pconfig["tunnel_network"]) &&
		    empty($pconfig["tunnel_networkv6"])) {
			$input_errors[] = gettext("TAP server mode requires an IPv4/IPv6 Tunnel Network or Bridge Interface to work.");
		}

		if ($pconfig['serverbridge_dhcp'] && $pconfig['tunnel_network']) {
			$input_errors[] = gettext("Using a tunnel network and server bridge settings together is not allowed.");
		}
		if (($pconfig['serverbridge_dhcp'] && $pconfig['serverbridge_routegateway']) &&
		    ((empty($pconfig['serverbridge_interface'])) || (strcmp($pconfig['serverbridge_interface'], "none") == 0))) {
			$input_errors[] = gettext("Bridge Route Gateway requires a valid Bridge Interface.");
		}
		if (($pconfig['serverbridge_dhcp_start'] && !$pconfig['serverbridge_dhcp_end']) ||
		    (!$pconfig['serverbridge_dhcp_start'] && $pconfig['serverbridge_dhcp_end'])) {
			$input_errors[] = gettext("Server Bridge DHCP Start and End must both be empty, or defined.");
		}
		if (($pconfig['serverbridge_dhcp_start'] && !is_ipaddrv4($pconfig['serverbridge_dhcp_start']))) {
			$input_errors[] = gettext("Server Bridge DHCP Start must be an IPv4 address.");
		}
		if (($pconfig['serverbridge_dhcp_end'] && !is_ipaddrv4($pconfig['serverbridge_dhcp_end']))) {
			$input_errors[] = gettext("Server Bridge DHCP End must be an IPv4 address.");
		}
		if (ip_greater_than($pconfig['serverbridge_dhcp_start'], $pconfig['serverbridge_dhcp_end'])) {
			$input_errors[] = gettext("The Server Bridge DHCP range is invalid (start higher than end).");
		}
	}

	/* UDP Fast I/O and Exit Notify are not compatible with TCP, so toss the
	 * option out when submitted since it can't be set this way
	 * legitimately. This also avoids having to perform any more trickery on
	 * the stored option to not preserve the value when changing modes. */
	if (strtolower(substr($pconfig['protocol'], 0, 3)) != "udp") {
		if ($pconfig['udp_fast_io']) {
			unset($pconfig['udp_fast_io']);
		}
		if ($pconfig['exit_notify']) {
			unset($pconfig['exit_notify']);
		}
	} else {
		if (!array_key_exists($pconfig['exit_notify'], $openvpn_exit_notify_server)) {
			$input_errors[] = gettext("The Exit Notify value is invalid.");
		}
	}

	if (!empty($pconfig['sndrcvbuf']) && !array_key_exists($pconfig['sndrcvbuf'], openvpn_get_buffer_values())) {
		$input_errors[] = gettext("The supplied Send/Receive Buffer size is invalid.");
	}

	if (!empty($pconfig['ping_method']) && !array_key_exists($pconfig['ping_method'], $openvpn_ping_method)) {
		$input_errors[] = gettext("The supplied Ping Method is invalid.");
	}
	if (!empty($pconfig['ping_action']) && !array_key_exists($pconfig['ping_action'], $openvpn_ping_action)) {
		$input_errors[] = gettext("The supplied Ping Action is invalid.");
	}
	if (!empty($pconfig['keepalive_interval']) && !is_numericint($pconfig['keepalive_interval'])) {
		$input_errors[] = gettext("The supplied Keepalive Interval value is invalid.");
	}
	if (!empty($pconfig['keepalive_timeout']) && !is_numericint($pconfig['keepalive_timeout'])) {
		$input_errors[] = gettext("The supplied Keepalive Timeout value is invalid.");
	}
	if (!empty($pconfig['ping_seconds']) && !is_numericint($pconfig['ping_seconds'])) {
		$input_errors[] = gettext("The supplied Ping Seconds value is invalid.");
	}
	if (!empty($pconfig['ping_action_seconds']) && !is_numericint($pconfig['ping_action_seconds'])) {
		$input_errors[] = gettext("The supplied Ping Restart or Exit Seconds value is invalid.");
	}
	if (!empty($pconfig['inactive_seconds']) && !is_numericint($pconfig['inactive_seconds'])) {
		$input_errors[] = gettext("The supplied Inactive Seconds value is invalid.");
	}
	if (!empty($pconfig['ocspurl']) && !is_URL($pconfig['ocspurl'])) {
		$input_errors[] = gettext("OCSP URL must be a valid URL address.");
	}
	if (!empty($pconfig['verbosity_level']) &&
	    !array_key_exists($pconfig['verbosity_level'], $openvpn_verbosity_level)) {
		$input_errors[] = gettext("The selected Verbosity Level is not valid.");
	}
	if (!empty($pconfig['create_gw']) && !array_key_exists($pconfig['create_gw'], openvpn_create_gw_choices())) {
		$input_errors[] = gettext("The selected Gateway creation option is not valid.");
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!$input_errors) {

		$server = array();

		if ($this_server_config &&
		    ($pconfig['dev_mode'] != $this_server_config['dev_mode'])) {
			/*
			 * delete old interface so a new TUN or TAP interface
			 * can be created.
			 */
			openvpn_delete('server', $this_server_config);
		}

		if ($vpnid) {
			$server['vpnid'] = $vpnid;
		} else {
			$server['vpnid'] = openvpn_vpnid_next();
		}

		if ($post['disable'] == "yes") {
			$server['disable'] = true;
		}
		$server['mode'] = $pconfig['mode'];
		if (!empty($pconfig['authmode']) && (($pconfig['mode'] == "server_user") || ($pconfig['mode'] == "server_tls_user"))) {
			$server['authmode'] = implode(",", $pconfig['authmode']);
		}
		$server['protocol'] = $pconfig['protocol'];
		$server['dev_mode'] = $pconfig['dev_mode'];
		list($server['interface'], $server['ipaddr']) = explode ("|", $pconfig['interface']);
		$server['local_port'] = $pconfig['local_port'];
		$server['description'] = $pconfig['description'];
		$server['custom_options'] = str_replace("\r\n", "\n", $pconfig['custom_options']);

		if ($tls_mode) {
			if ($pconfig['tlsauth_enable']) {
				if ($pconfig['autotls_enable']) {
					$pconfig['tls'] = openvpn_create_key();
				}
				$server['tls'] = base64_encode($pconfig['tls']);
				$server['tls_type'] = $pconfig['tls_type'];
				$server['tlsauth_keydir'] = $pconfig['tlsauth_keydir'];
			}
			$server['caref'] = $pconfig['caref'];
			$server['crlref'] = $pconfig['crlref'];
			if ($pconfig['ocspcheck']) {
				$server['ocspcheck'] = "yes";
			}
			$server['ocspurl'] = $pconfig['ocspurl'];
			$server['certref'] = $pconfig['certref'];
			$server['dh_length'] = $pconfig['dh_length'];
			$server['ecdh_curve'] = $pconfig['ecdh_curve'];
			$server['cert_depth'] = $pconfig['cert_depth'];
			if ($pconfig['mode'] == "server_tls_user") {
				$server['strictusercn'] = $pconfig['strictusercn'];
			}
			if (isset($pconfig['remote_cert_tls'])) {
				$server['remote_cert_tls'] = true;
			}
		} else {
			$server['shared_key'] = base64_encode($pconfig['shared_key']);
		}

		$server['digest'] = $pconfig['digest'];

		foreach (array('', 'v6') as $ntype) {
			$server["tunnel_network{$ntype}"] = openvpn_tunnel_network_fix($pconfig["tunnel_network{$ntype}"]);
		}
		$server['remote_network'] = $pconfig['remote_network'];
		$server['remote_networkv6'] = $pconfig['remote_networkv6'];
		$server['gwredir'] = $pconfig['gwredir'];
		$server['gwredir6'] = $pconfig['gwredir6'];
		$server['local_network'] = $pconfig['local_network'];
		$server['local_networkv6'] = $pconfig['local_networkv6'];
		if ($pconfig['tunnel_networkv6_type'] == 'track6') {
			$server['tunnel_networkv6_type'] = $pconfig['tunnel_networkv6_type'];
			$server['tunnel_track6_interface'] = $pconfig['tunnel_track6_interface'];
			$server['tunnel_track6_prefix_id'] = $pconfig['tunnel_track6_prefix_id'];
		}
		$server['maxclients'] = $pconfig['maxclients'];
		$server['connlimit'] = $pconfig['connlimit'];
		$server['allow_compression'] = $pconfig['allow_compression'];
		$server['compression'] = $pconfig['compression'];
		$server['compression_push'] = $pconfig['compression_push'];
		$server['passtos'] = $pconfig['passtos'];
		$server['client2client'] = $pconfig['client2client'];

		$server['dynamic_ip'] = $pconfig['dynamic_ip'];
		$server['topology'] = $pconfig['topology'];

		$server['serverbridge_dhcp'] = $pconfig['serverbridge_dhcp'];
		$server['serverbridge_interface'] = $pconfig['serverbridge_interface'];
		$server['serverbridge_routegateway'] = $pconfig['serverbridge_routegateway'];
		$server['serverbridge_dhcp_start'] = $pconfig['serverbridge_dhcp_start'];
		$server['serverbridge_dhcp_end'] = $pconfig['serverbridge_dhcp_end'];

		if ($pconfig['dns_domain_enable']) {
			$server['dns_domain'] = $pconfig['dns_domain'];
		}

		if ($pconfig['dns_server_enable']) {
			$server['dns_server1'] = $pconfig['dns_server1'];
			$server['dns_server2'] = $pconfig['dns_server2'];
			$server['dns_server3'] = $pconfig['dns_server3'];
			$server['dns_server4'] = $pconfig['dns_server4'];
		}

		if ($pconfig['push_blockoutsidedns']) {
			$server['push_blockoutsidedns'] = $pconfig['push_blockoutsidedns'];
		}

		$server['username_as_common_name'] = ($pconfig['username_as_common_name'] == 'yes') ? "enabled" : "disabled";

		if ($pconfig['udp_fast_io']) {
			$server['udp_fast_io'] = $pconfig['udp_fast_io'];
		}
		if ($pconfig['exit_notify']) {
			$server['exit_notify'] = $pconfig['exit_notify'];
		}
		$server['sndrcvbuf'] = $pconfig['sndrcvbuf'];
		if ($pconfig['push_register_dns']) {
			$server['push_register_dns'] = $pconfig['push_register_dns'];
		}

		if ($pconfig['ntp_server_enable']) {
			$server['ntp_server1'] = $pconfig['ntp_server1'];
			$server['ntp_server2'] = $pconfig['ntp_server2'];
		}

		$server['netbios_enable'] = $pconfig['netbios_enable'];
		if ($pconfig['netbios_enable']) {
			$server['netbios_ntype'] = $pconfig['netbios_ntype'];
			$server['netbios_scope'] = $pconfig['netbios_scope'];

			if ($pconfig['wins_server_enable']) {
				$server['wins_server1'] = $pconfig['wins_server1'];
				$server['wins_server2'] = $pconfig['wins_server2'];
			}

			if ($pconfig['nbdd_server_enable']) {
				$server['nbdd_server1'] = $pconfig['nbdd_server1'];
				$server['nbdd_server2'] = $pconfig['nbdd_server2'];
			}
		}

		$server['create_gw'] = $pconfig['create_gw'];
		$server['verbosity_level'] = $pconfig['verbosity_level'];

		if ($post['duplicate_cn'] == "yes") {
			$server['duplicate_cn'] = true;
		}

		if (!empty($pconfig['data_ciphers'])) {
			$server['data_ciphers'] = implode(",", $pconfig['data_ciphers']);
		}
		$server['data_ciphers_fallback'] = $pconfig['data_ciphers_fallback'];

		$server['ping_method'] = $pconfig['ping_method'];
		$server['keepalive_interval'] = $pconfig['keepalive_interval'];
		$server['keepalive_timeout'] = $pconfig['keepalive_timeout'];
		$server['ping_seconds'] = $pconfig['ping_seconds'];
		$server['ping_push'] = $pconfig['ping_push'];
		$server['ping_action'] = $pconfig['ping_action'];
		$server['ping_action_seconds'] = $pconfig['ping_action_seconds'];
		$server['ping_action_push'] = $pconfig['ping_action_push'];
		$server['inactive_seconds'] = $pconfig['inactive_seconds'];

		if (($act == 'new') || (!empty($server['disable']) ^ !empty($this_server_config['disable'])) ||
		    ($server['tunnel_network'] != $this_server_config['tunnel_network']) ||
		    ($server['tunnel_networkv6'] != $this_server_config['tunnel_networkv6'])) {
			$server['unbound_restart'] = true;
		}

		if ($this_server_config) {
			config_set_path("openvpn/openvpn-server/{$id}", $server);
			$wc_msg = sprintf(gettext('Updated OpenVPN server on %1$s:%2$s %3$s'), convert_friendly_interface_to_friendly_descr($server['interface']), $server['local_port'], $server['description']);
		} else {
			config_set_path('openvpn/openvpn-server/', $server);
			$wc_msg = sprintf(gettext('Added OpenVPN server on %1$s:%2$s %3$s'), convert_friendly_interface_to_friendly_descr($server['interface']), $server['local_port'], $server['description']);
		}

		write_config($wc_msg);
		openvpn_resync('server', $server);
		openvpn_resync_csc_all();
		services_unbound_configure(false);
	}

	return $input_errors;
}

/* ------------------------------------------------------------------ */
/* Clients                                                             */
/* ------------------------------------------------------------------ */

/* The proxy authentication types of the client page. */
function openvpn_client_proxy_auth_types() {
	return array('none' => gettext('none'), 'basic' => gettext('basic'), 'ntlm' => gettext('ntlm'));
}

/* The client fields kept when the page posts the password placeholder. */
function openvpn_client_simplefields() {
	return array('auth_user', 'auth_pass', 'proxy_user', 'proxy_passwd');
}

/*
 * The client edit page's form for $act "new" (the page's defaults), "edit" or
 * "dup" of the stored client $this_client_config. Null when the page shows no
 * form values.
 */
function openvpn_client_form($act, $this_client_config) {
	$pconfig = null;

	if ($act == "new") {
		$pconfig['data_ciphers'] = 'AES-256-GCM,AES-128-GCM,CHACHA20-POLY1305';
		$pconfig['data_ciphers_fallback'] = 'AES-256-CBC';
		$pconfig['autokey_enable'] = "yes";
		$pconfig['tlsauth_enable'] = "yes";
		$pconfig['tlsauth_keydir'] = "default";
		$pconfig['autotls_enable'] = "yes";
		$pconfig['interface'] = "wan";
		$pconfig['server_port'] = 1194;
		$pconfig['verbosity_level'] = 1; // Default verbosity is 1
		$pconfig['digest'] = "SHA256";
		$pconfig['allow_compression'] = "no";
		$pconfig['compression'] = "";
		$pconfig['exit_notify'] = 1;
		$pconfig['remote_cert_tls'] = "yes";
		$pconfig['create_gw'] = "both";
	}

	if (($act == "edit") || ($act == "dup")) {
		if ($this_client_config) {
			foreach (openvpn_client_simplefields() as $stat) {
				$pconfig[$stat] = $this_client_config[$stat];
			}

			$pconfig['disable'] = isset($this_client_config['disable']);
			$pconfig['mode'] = $this_client_config['mode'];
			$pconfig['protocol'] = $this_client_config['protocol'];
			$pconfig['interface'] = $this_client_config['interface'];
			if (!empty($this_client_config['ipaddr'])) {
				$pconfig['interface'] = $pconfig['interface'] . '|' . $this_client_config['ipaddr'];
			}
			$pconfig['local_port'] = $this_client_config['local_port'];
			$pconfig['server_addr'] = $this_client_config['server_addr'];
			$pconfig['server_port'] = $this_client_config['server_port'];
			$pconfig['proxy_addr'] = $this_client_config['proxy_addr'];
			$pconfig['proxy_port'] = $this_client_config['proxy_port'];
			$pconfig['proxy_authtype'] = $this_client_config['proxy_authtype'];
			$pconfig['description'] = $this_client_config['description'];
			$pconfig['custom_options'] = $this_client_config['custom_options'];
			$pconfig['ns_cert_type'] = $this_client_config['ns_cert_type'];
			if (isset($this_client_config['data_ciphers'])) {
				$pconfig['data_ciphers'] = $this_client_config['data_ciphers'];
			} else {
				$pconfig['data_ciphers'] = 'AES-256-GCM,AES-128-GCM,CHACHA20-POLY1305';
			}
			$pconfig['dev_mode'] = $this_client_config['dev_mode'];

			if ($pconfig['mode'] != "p2p_shared_key") {
				$pconfig['caref'] = $this_client_config['caref'];
				$pconfig['certref'] = $this_client_config['certref'];
				$pconfig['crlref'] = $this_client_config['crlref'];
				if ($this_client_config['tls']) {
					$pconfig['tlsauth_enable'] = "yes";
					$pconfig['tls'] = base64_decode($this_client_config['tls']);
					$pconfig['tls_type'] = $this_client_config['tls_type'];
				}
				$pconfig['remote_cert_tls'] = isset($this_client_config['remote_cert_tls']);
			} else {
				$pconfig['shared_key'] = base64_decode($this_client_config['shared_key']);
			}
			$pconfig['tlsauth_keydir'] = $this_client_config['tlsauth_keydir'];
			$pconfig['data_ciphers_fallback'] = $this_client_config['data_ciphers_fallback'];
			$pconfig['digest'] = !empty($this_client_config['digest']) ? $this_client_config['digest'] : "SHA256";

			$pconfig['tunnel_network'] = $this_client_config['tunnel_network'];
			$pconfig['tunnel_networkv6'] = $this_client_config['tunnel_networkv6'];
			$pconfig['remote_network'] = $this_client_config['remote_network'];
			$pconfig['remote_networkv6'] = $this_client_config['remote_networkv6'];
			$pconfig['use_shaper'] = $this_client_config['use_shaper'];
			$pconfig['allow_compression'] = $this_client_config['allow_compression'];
			$pconfig['compression'] = $this_client_config['compression'];
			$pconfig['auth-retry-none'] = $this_client_config['auth-retry-none'];
			$pconfig['passtos'] = $this_client_config['passtos'];
			$pconfig['udp_fast_io'] = $this_client_config['udp_fast_io'];
			$pconfig['exit_notify'] = $this_client_config['exit_notify'];
			$pconfig['sndrcvbuf'] = $this_client_config['sndrcvbuf'];
			$pconfig['topology'] = $this_client_config['topology'];

			// just in case the modes switch
			$pconfig['autokey_enable'] = "yes";
			$pconfig['autotls_enable'] = "yes";

			$pconfig['route_no_pull'] = $this_client_config['route_no_pull'];
			$pconfig['route_no_exec'] = $this_client_config['route_no_exec'];
			$pconfig['dns_add'] = $this_client_config['dns_add'];
			if (isset($this_client_config['create_gw'])) {
				$pconfig['create_gw'] = $this_client_config['create_gw'];
			} else {
				$pconfig['create_gw'] = "both"; // v4only, v6only, or both (default: both)
			}
			if (isset($this_client_config['verbosity_level'])) {
				$pconfig['verbosity_level'] = $this_client_config['verbosity_level'];
			} else {
				$pconfig['verbosity_level'] = 1; // Default verbosity is 1
			}

			$pconfig['ping_method'] = $this_client_config['ping_method'];
			$pconfig['keepalive_interval'] = $this_client_config['keepalive_interval'];
			$pconfig['keepalive_timeout'] = $this_client_config['keepalive_timeout'];
			$pconfig['ping_seconds'] = $this_client_config['ping_seconds'];
			$pconfig['ping_action'] = $this_client_config['ping_action'];
			$pconfig['ping_action_seconds'] = $this_client_config['ping_action_seconds'];
			$pconfig['inactive_seconds'] = $this_client_config['inactive_seconds'] ?: 0;
		}
	}

	return $pconfig;
}

/*
 * Validate and save the client edit form $pconfig for the client at position
 * $id (null or missing: a new client); $act is the form's "act". A password
 * posted as the page's placeholder keeps the stored one (of the duplicated
 * client "parentid" for a copy). Applied at once like the page (the instance
 * restarts, the DNS Resolver is reconfigured). $pconfig is changed like the
 * page changed it. Returns the input errors (empty when saved).
 */
function openvpn_client_save(array &$pconfig, $id, $act, $user_can_edit_advanced) {
	global $openvpn_topologies, $openvpn_tls_modes, $openvpn_exit_notify_client;
	global $openvpn_prots, $openvpn_dev_mode, $openvpn_client_modes;
	global $openvpn_allow_compression, $openvpn_compression_modes, $openvpn_ping_method;
	global $openvpn_ping_action, $openvpn_verbosity_level;

	$post = $pconfig;
	$input_errors = array();
	$openvpn_all_data_ciphers = openvpn_get_cipherlist();
	$proxy_auth_types = openvpn_client_proxy_auth_types();
	$certlist = openvpn_build_cert_list(true);
	if (($id !== null) && !is_numericint((string)$id)) {
		$id = null;
	}
	$this_client_config = isset($id) ? config_get_path("openvpn/openvpn-client/{$id}") : null;

	if ($this_client_config) {
		$vpnid = $this_client_config['vpnid'];
	} else {
		$vpnid = 0;
	}

	if ($pconfig['disable'] && openvpn_inuse($vpnid, 'client')) {
		$input_errors[] = gettext("Cannot disable an OpenVPN instance while the interface is assigned. Remove the interface assignment first.");
	}

	if (isset($pconfig['custom_options']) &&
	    ($pconfig['custom_options'] != $this_client_config['custom_options']) &&
	    !$user_can_edit_advanced) {
		$input_errors[] = gettext("This user does not have sufficient privileges to edit Advanced options on this instance.");
	}
	if (!$user_can_edit_advanced && !empty($this_client_config['custom_options'])) {
		$pconfig['custom_options'] = $this_client_config['custom_options'];
	}

	if (!empty($pconfig['mode']) &&
	    !array_key_exists($pconfig['mode'], $openvpn_client_modes)) {
		$input_errors[] = gettext("The selected mode is not valid.");
	}

	if (!empty($pconfig['dev_mode']) &&
	    !array_key_exists($pconfig['dev_mode'], $openvpn_dev_mode)) {
		$input_errors[] = gettext("The selected Device Mode is not valid.");
	}

	if (!empty($pconfig['protocol']) &&
	    !array_key_exists($pconfig['protocol'], $openvpn_prots)) {
		$input_errors[] = gettext("The selected Protocol is not valid.");
	}

	if (!empty($pconfig['interface']) &&
	    !array_key_exists($pconfig['interface'], openvpn_build_if_list())) {
		$input_errors[] = gettext("The selected Interface is not valid.");
	}

	$cipher_validation_list = array_keys($openvpn_all_data_ciphers);
	if (!in_array($pconfig['data_ciphers_fallback'], $cipher_validation_list)) {
		$input_errors[] = gettext("The selected Fallback Data Encryption Algorithm is not valid.");
	}

	/* Maximum option line length = 256, see upstream issue 11559 */
	if (!empty($pconfig['data_ciphers']) && is_array($pconfig['data_ciphers']) &&
	    (strlen("data-ciphers " . implode(",", $pconfig['data_ciphers'])) > 254)) {
		$input_errors[] = gettext("Too many Data Encryption Algorithms have been selected.");
	}

	if (!empty($pconfig['data_ciphers']) && is_array($pconfig['data_ciphers'])) {
		foreach ($pconfig['data_ciphers'] as $dc) {
			if (!in_array(trim($dc), $cipher_validation_list)) {
				$input_errors[] = gettext("One or more of the selected Data Encryption Algorithms is not valid.");
			}
		}
	} else {
		/* If data_ciphers is not empty and also not an array, it can't be valid. */
		$input_errors[] = gettext("The Data Encryption Algorithm list is not valid.");
	}

	list($iv_iface, $iv_ip) = explode ("|", $pconfig['interface']);
	if (is_ipaddrv4($iv_ip) && (stristr($pconfig['protocol'], "6") !== false)) {
		$input_errors[] = gettext("Protocol and IP address families do not match. An IPv6 protocol and an IPv4 IP address cannot be selected.");
	} elseif (is_ipaddrv6($iv_ip) && (stristr($pconfig['protocol'], "6") === false)) {
		$input_errors[] = gettext("Protocol and IP address families do not match. An IPv4 protocol and an IPv6 IP address cannot be selected.");
	} elseif ((stristr($pconfig['protocol'], "6") === false) && !get_interface_ip($iv_iface) && ($pconfig['interface'] != "any")) {
		// If an underlying interface to be used by this client uses DHCP, then it may not have received an IP address yet.
		// So in that case we do not report a problem.
		if (!interface_has_dhcp($iv_iface, 4)) {
			$input_errors[] = gettext("An IPv4 protocol was selected, but the selected interface has no IPv4 address.");
		}
	} elseif ((stristr($pconfig['protocol'], "6") !== false) && !get_interface_ipv6($iv_iface) && ($pconfig['interface'] != "any")) {
		// If an underlying interface to be used by this client uses DHCP6, then it may not have received an IP address yet.
		// So in that case we do not report a problem.
		if (!interface_has_dhcp($iv_iface, 6)) {
			$input_errors[] = gettext("An IPv6 protocol was selected, but the selected interface has no IPv6 address.");
		}
	}

	if (!empty($pconfig['digest']) &&
	    !array_key_exists($pconfig['digest'], openvpn_get_digestlist())) {
		$input_errors[] = gettext("The selected Auth Digest Algorithm is not valid.");
	}

	if (!empty($pconfig['allow_compression']) &&
	    !array_key_exists($pconfig['allow_compression'], $openvpn_allow_compression)) {
		$input_errors[] = gettext("The selected Allow Compression value is not valid.");
	}

	if (!empty($pconfig['compression']) &&
	    !array_key_exists($pconfig['compression'], $openvpn_compression_modes)) {
		$input_errors[] = gettext("The selected Compression is not valid.");
	}

	if ($pconfig['mode'] != "p2p_shared_key") {
		$tls_mode = true;
		if (!empty($pconfig['caref']) &&
		    !array_key_exists($pconfig['caref'], cert_build_list('ca', 'OpenVPN'))) {
			$input_errors[] = gettext("The selected Peer Certificate Authority is not valid.");
		}

		if (!empty($pconfig['crlref']) &&
		    !array_key_exists($pconfig['crlref'], openvpn_build_crl_list())) {
			$input_errors[] = gettext("The selected Peer Certificate Revocation List is not valid.");
		}

		if (!empty($pconfig['certref']) &&
		    !array_key_exists($pconfig['certref'], $certlist['server'])) {
			$input_errors[] = gettext("The selected Client Certificate is not valid.");
		}
	} else {
		$tls_mode = false;
	}

	/* input validation */
	if ($pconfig['local_port']) {

		if ($result = openvpn_validate_port($pconfig['local_port'], 'Local port')) {
			$input_errors[] = $result;
		}

		$portused = openvpn_port_used($pconfig['protocol'], $pconfig['interface'], $pconfig['local_port'], $vpnid);
		if (($portused != $vpnid) && ($portused != 0)) {
			$input_errors[] = gettext("The specified 'Local port' is in use. Please select another value");
		}
	}

	if ($result = openvpn_validate_host($pconfig['server_addr'], 'Server host or address')) {
		$input_errors[] = $result;
	}

	if ($result = openvpn_validate_port($pconfig['server_port'], 'Server port')) {
		$input_errors[] = $result;
	}

	if (!array_key_exists($pconfig['topology'], $openvpn_topologies)) {
		$input_errors[] = gettext("The field 'Topology' contains an invalid selection");
	}

	if ($pconfig['proxy_addr']) {
		if (!empty($pconfig['proxy_authtype']) &&
		    !array_key_exists($pconfig['proxy_authtype'], $proxy_auth_types)) {
			$input_errors[] = gettext("The selected Proxy Authentication Type is not valid.");
		}

		if ($result = openvpn_validate_host($pconfig['proxy_addr'], 'Proxy host or address')) {
			$input_errors[] = $result;
		}

		if ($result = openvpn_validate_port($pconfig['proxy_port'], 'Proxy port')) {
			$input_errors[] = $result;
		}

		if ($pconfig['proxy_authtype'] != "none") {
			if (empty($pconfig['proxy_user']) || empty($pconfig['proxy_passwd'])) {
				$input_errors[] = gettext("User name and password are required for proxy with authentication.");
			}
		}
	}

	if ($pconfig['tunnel_network']) {
		if (!openvpn_validate_tunnel_network($pconfig['tunnel_network'], 'ipv4')) {
			$input_errors[] = gettext("The field 'IPv4 Tunnel Network' must contain a valid IPv4 subnet with CIDR mask or an alias with a single IPv4 subnet with CIDR mask.");
		}
		if ((!isset($this_client_config) ||
		    ($this_client_config['tunnel_network'] != $pconfig['tunnel_network'])) &&
		    openvpn_is_tunnel_network_in_use($pconfig['tunnel_network'])) {
			$input_errors[] = gettext("The submitted IPv4 Tunnel Network is already in use.");
		}
	}

	if ($pconfig['tunnel_networkv6']) {
		if (!openvpn_validate_tunnel_network($pconfig['tunnel_networkv6'], 'ipv6')) {
			$input_errors[] = gettext("The field 'IPv6 Tunnel Network' must contain a valid IPv6 prefix or an alias with a single IPv6 prefix.");
		}
		if ((!isset($this_client_config) ||
		    ($this_client_config['tunnel_networkv6'] != $pconfig['tunnel_networkv6'])) &&
		    openvpn_is_tunnel_network_in_use($pconfig['tunnel_networkv6'])) {
			$input_errors[] = gettext("The submitted IPv6 Tunnel Network is already in use.");
		}
	}

	if ($result = openvpn_validate_cidr($pconfig['remote_network'], 'IPv4 Remote Network', true, "ipv4", true)) {
		$input_errors[] = $result;
	}

	if ($result = openvpn_validate_cidr($pconfig['remote_networkv6'], 'IPv6 Remote Network', true, "ipv6", true)) {
		$input_errors[] = $result;
	}

	if (!empty($pconfig['use_shaper']) && (!is_numeric($pconfig['use_shaper']) || ($pconfig['use_shaper'] <= 0))) {
		$input_errors[] = gettext("The bandwidth limit must be a positive numeric value.");
	}

	if ($pconfig['autokey_enable']) {
		$pconfig['shared_key'] = openvpn_create_key();
	}

	if (!$tls_mode && !$pconfig['autokey_enable']) {
		if (!strstr($pconfig['shared_key'], "-----BEGIN OpenVPN Static key V1-----") ||
		    !strstr($pconfig['shared_key'], "-----END OpenVPN Static key V1-----")) {
			$input_errors[] = gettext("The field 'Shared Key' does not appear to be valid");
		}
	}

	if ($tls_mode && $pconfig['tlsauth_enable'] && !$pconfig['autotls_enable']) {
		if (!strstr($pconfig['tls'], "-----BEGIN OpenVPN Static key V1-----") ||
		    !strstr($pconfig['tls'], "-----END OpenVPN Static key V1-----")) {
			$input_errors[] = gettext("The field 'TLS Key' does not appear to be valid");
		}
		if (!in_array($pconfig['tls_type'], array_keys($openvpn_tls_modes))) {
			$input_errors[] = gettext("The field 'TLS Key Usage Mode' is not valid");
		}

		if (!empty($pconfig['tlsauth_keydir']) &&
		    !array_key_exists($pconfig['tlsauth_keydir'], openvpn_get_keydirlist())) {
			$input_errors[] = gettext("The selected TLS Key Direction is not valid.");
		}
	}

	if (($pconfig['mode'] == "p2p_shared_key") &&
	    (strstr($pconfig['data_ciphers_fallback'], "GCM") ||
	    strstr($pconfig['data_ciphers_fallback'], "CHACHA"))) {
		$input_errors[] = gettext("AEAD Encryption Algorithms (GCM, CHACHA) cannot be used with Shared Key mode.");
	}

	/* If we are not in shared key mode, then we need the CA/Cert. */
	$reqdfields = $reqdfieldsn = array();
	if ($pconfig['mode'] != "p2p_shared_key") {
		$reqdfields = explode(" ", "caref");
		$reqdfieldsn = array(gettext("Certificate Authority"));
	} elseif (!$pconfig['autokey_enable']) {
		/* We only need the shared key filled in if we are in shared key mode and autokey is not selected. */
		$reqdfields = array('shared_key');
		$reqdfieldsn = array(gettext('Shared key'));
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($pconfig['mode'] != "p2p_shared_key") {
		if (empty($pconfig['certref']) && empty($pconfig['auth_user']) && empty($pconfig['auth_pass'])) {
			$input_errors[] = gettext("If no Client Certificate is selected, a username and/or password must be entered.");
		}
	}

	/* UDP Fast I/O and Exit Notify are not compatible with TCP, so toss the
	 * option out when submitted since it can't be set this way
	 * legitimately. This also avoids having to perform any more trickery on
	 * the stored option to not preserve the value when changing modes. */
	if (strtolower(substr($pconfig['protocol'], 0, 3)) != "udp") {
		if ($pconfig['udp_fast_io']) {
			unset($pconfig['udp_fast_io']);
		}
		if ($pconfig['exit_notify']) {
			unset($pconfig['exit_notify']);
		}
	} else {
		if (!array_key_exists($pconfig['exit_notify'], $openvpn_exit_notify_client)) {
			$input_errors[] = gettext("The Exit Notify value is invalid.");
		}
	}

	if ($pconfig['udp_fast_io'] && (!empty($pconfig['use_shaper']))) {
		/* Only warn if the user is set to UDP, otherwise it isn't relevant (See above) */
		if (strtolower(substr($pconfig['protocol'], 0, 3)) == "udp") {
			$input_errors[] = gettext("Limit Outgoing Bandwidth is not compatible with UDP Fast I/O.");
		} else {
			unset($pconfig['udp_fast_io']);
		}
	}

	if (!empty($pconfig['sndrcvbuf']) && !array_key_exists($pconfig['sndrcvbuf'], openvpn_get_buffer_values())) {
		$input_errors[] = gettext("The supplied Send/Receive Buffer size is invalid.");
	}

	if (!empty($pconfig['ping_method']) && !array_key_exists($pconfig['ping_method'], $openvpn_ping_method)) {
		$input_errors[] = gettext("The supplied Ping Method is invalid.");
	}
	if (!empty($pconfig['ping_action']) && !array_key_exists($pconfig['ping_action'], $openvpn_ping_action)) {
		$input_errors[] = gettext("The supplied Ping Action is invalid.");
	}
	if (!empty($pconfig['keepalive_interval']) && !is_numericint($pconfig['keepalive_interval'])) {
		$input_errors[] = gettext("The supplied Keepalive Interval value is invalid.");
	}
	if (!empty($pconfig['keepalive_timeout']) && !is_numericint($pconfig['keepalive_timeout'])) {
		$input_errors[] = gettext("The supplied Keepalive Timeout value is invalid.");
	}
	if (!empty($pconfig['ping_seconds']) && !is_numericint($pconfig['ping_seconds'])) {
		$input_errors[] = gettext("The supplied Ping Seconds value is invalid.");
	}
	if (!empty($pconfig['ping_action_seconds']) && !is_numericint($pconfig['ping_action_seconds'])) {
		$input_errors[] = gettext("The supplied Ping Restart or Exit Seconds value is invalid.");
	}
	if (!empty($pconfig['inactive_seconds']) && !is_numericint($pconfig['inactive_seconds'])) {
		$input_errors[] = gettext("The supplied Inactive Seconds value is invalid.");
	}

	if (!empty($pconfig['verbosity_level']) &&
	    !array_key_exists($pconfig['verbosity_level'], $openvpn_verbosity_level)) {
		$input_errors[] = gettext("The selected Verbosity Level is not valid.");
	}
	if (!empty($pconfig['create_gw']) && !array_key_exists($pconfig['create_gw'], openvpn_create_gw_choices())) {
		$input_errors[] = gettext("The selected Gateway creation option is not valid.");
	}

	if (!$input_errors) {

		$client = array();

		if ($this_client_config &&
		    ($pconfig['dev_mode'] != $this_client_config['dev_mode'])) {
			/*
			 * delete old interface so a new TUN or TAP interface
			 * can be created.
			 */
			openvpn_delete('client', $this_client_config);
		}

		foreach (openvpn_client_simplefields() as $stat) {
			if ($post[$stat] == DMYPWD) {
				if (is_numeric($post['parentid'])) {
					$orig_id = $post['parentid'];
				} else {
					$orig_id = $id;
				}
				$client[$stat] = config_get_path("openvpn/openvpn-client/{$orig_id}/{$stat}");
			} elseif ($post[$stat] != null) {
				/* the page's update_if_changed() on the new entry (its change log is not used) */
				$client[$stat] = $post[$stat];
			}
		}

		if ($vpnid) {
			$client['vpnid'] = $vpnid;
		} else {
			$client['vpnid'] = openvpn_vpnid_next();
		}

		if ($post['disable'] == "yes") {
			$client['disable'] = true;
		}
		$client['protocol'] = $pconfig['protocol'];
		$client['dev_mode'] = $pconfig['dev_mode'];
		list($client['interface'], $client['ipaddr']) = explode ("|", $pconfig['interface']);
		$client['local_port'] = $pconfig['local_port'];
		$client['server_addr'] = $pconfig['server_addr'];
		$client['server_port'] = $pconfig['server_port'];
		$client['proxy_addr'] = $pconfig['proxy_addr'];
		$client['proxy_port'] = $pconfig['proxy_port'];
		$client['proxy_authtype'] = $pconfig['proxy_authtype'];
		$client['description'] = $pconfig['description'];
		$client['mode'] = $pconfig['mode'];
		$client['topology'] = $pconfig['topology'];
		$client['custom_options'] = str_replace("\r\n", "\n", $pconfig['custom_options']);

		if ($tls_mode) {
			$client['caref'] = $pconfig['caref'];
			$client['certref'] = $pconfig['certref'];
			$client['crlref'] = $pconfig['crlref'];
			if ($pconfig['tlsauth_enable']) {
				if ($pconfig['autotls_enable']) {
					$pconfig['tls'] = openvpn_create_key();
				}
				$client['tls'] = base64_encode($pconfig['tls']);
				$client['tls_type'] = $pconfig['tls_type'];
				$client['tlsauth_keydir'] = $pconfig['tlsauth_keydir'];
			}
			if (isset($pconfig['remote_cert_tls'])) {
				$client['remote_cert_tls'] = true;
			}
		} else {
			$client['shared_key'] = base64_encode($pconfig['shared_key']);
		}
		$client['digest'] = $pconfig['digest'];

		foreach (array('', 'v6') as $ntype) {
			$client["tunnel_network{$ntype}"] = openvpn_tunnel_network_fix($pconfig["tunnel_network{$ntype}"]);
		}
		$client['remote_network'] = $pconfig['remote_network'];
		$client['remote_networkv6'] = $pconfig['remote_networkv6'];
		$client['use_shaper'] = $pconfig['use_shaper'];
		$client['allow_compression'] = $pconfig['allow_compression'];
		$client['compression'] = $pconfig['compression'];
		$client['auth-retry-none'] = $pconfig['auth-retry-none'];
		$client['passtos'] = $pconfig['passtos'];
		$client['udp_fast_io'] = $pconfig['udp_fast_io'];
		$client['exit_notify'] = $pconfig['exit_notify'];
		$client['sndrcvbuf'] = $pconfig['sndrcvbuf'];

		$client['route_no_pull'] = $pconfig['route_no_pull'];
		$client['route_no_exec'] = $pconfig['route_no_exec'];
		$client['dns_add'] = $pconfig['dns_add'];
		$client['verbosity_level'] = $pconfig['verbosity_level'];
		$client['create_gw'] = $pconfig['create_gw'];

		if (!empty($pconfig['data_ciphers'])) {
			$client['data_ciphers'] = implode(",", $pconfig['data_ciphers']);
		}
		$client['data_ciphers_fallback'] = $pconfig['data_ciphers_fallback'];

		$client['ping_method'] = $pconfig['ping_method'];
		$client['keepalive_interval'] = $pconfig['keepalive_interval'];
		$client['keepalive_timeout'] = $pconfig['keepalive_timeout'];
		$client['ping_seconds'] = $pconfig['ping_seconds'];
		$client['ping_action'] = $pconfig['ping_action'];
		$client['ping_action_seconds'] = $pconfig['ping_action_seconds'];
		$client['inactive_seconds'] = $pconfig['inactive_seconds'];

		if (($act == 'new') || (!empty($client['disable']) ^ !empty($this_client_config['disable'])) ||
		    ($client['tunnel_network'] != $this_client_config['tunnel_network']) ||
		    ($client['tunnel_networkv6'] != $this_client_config['tunnel_networkv6'])) {
			$client['unbound_restart'] = true;
		}

		if ($this_client_config) {
			config_set_path("openvpn/openvpn-client/{$id}", $client);
			$wc_msg = sprintf(gettext('Updated OpenVPN client to server %1$s:%2$s %3$s'), $client['server_addr'], $client['server_port'], $client['description']);
		} else {
			config_set_path('openvpn/openvpn-client/', $client);
			$wc_msg = sprintf(gettext('Added OpenVPN client to server %1$s:%2$s %3$s'), $client['server_addr'], $client['server_port'], $client['description']);
		}

		write_config($wc_msg);
		openvpn_resync('client', $client);
		services_unbound_configure(false);
	}

	return $input_errors;
}

/* ------------------------------------------------------------------ */
/* Client specific overrides                                           */
/* ------------------------------------------------------------------ */

/* The servers an override can be limited to (the SSL/TLS servers): vpnid => label. */
function openvpn_csc_server_list() {
	global $openvpn_tls_server_modes;

	$serveroptionlist = array();
	foreach (config_get_path('openvpn/openvpn-server', []) as $serversettings) {
		if (in_array($serversettings['mode'], $openvpn_tls_server_modes)) {
			$serveroptionlist[$serversettings['vpnid']] = sprintf(gettext("OpenVPN Server %d: %s"), $serversettings['vpnid'], $serversettings['description']);
		}
	}
	return $serveroptionlist;
}

/* The "Reset Server Options" choices of the override page. */
function openvpn_csc_override_options() {
	return array(
		'default' => 'Keep all server options (default)',
		'push_reset' => 'Reset all options',
		'remove_specified' => 'Remove specified options'
	);
}

/* The "Remove Options" choices of the override page. */
function openvpn_csc_remove_options() {
	return array(
		'remove_route' => 'Local Routes & Gateways',
		'remove_iroute' => 'Remote Routes',
		'remove_redirect_gateway' => 'Redirect Gateways',
		'remove_inactive' => 'Inactivity Timeout',
		'remove_ping' => 'Client Ping',
		'remove_ping_action' => 'Ping Action',
		'remove_dnsdomain' => 'DNS Domains',
		'remove_dnsservers' => 'DNS Servers',
		'remove_blockoutsidedns' => 'Block Outside DNS',
		'remove_ntpservers' => 'NTP Options',
		'remove_netbios_ntype' => 'NetBIOS Type',
		'remove_netbios_scope' => 'NetBIOS Scope',
		'remove_wins' => 'WINS Options'
	);
}

/* The override edit page's form for $act "edit" or "dup" of the stored override $this_csc_config (null otherwise). */
function openvpn_csc_form($act, $this_csc_config) {
	$pconfig = null;

	if (($act == "edit") || ($act == "dup")) {
		if ($this_csc_config) {
			$pconfig['keep_minimal'] = isset($this_csc_config['keep_minimal']);
			// Handle the "Reset Options" list
			if (!empty($this_csc_config['remove_options'])) {
				$pconfig['override_options'] = 'remove_specified';
				$pconfig['remove_options'] = explode(',', $this_csc_config['remove_options']);
			} elseif (isset($this_csc_config['push_reset'])) {
				$pconfig['override_options'] = 'push_reset';
			}

			$pconfig['server_list'] = array_filter(explode(",", $this_csc_config['server_list']));
			$pconfig['custom_options'] = $this_csc_config['custom_options'];
			$pconfig['disable'] = isset($this_csc_config['disable']);
			$pconfig['common_name'] = $this_csc_config['common_name'];
			$pconfig['block'] = $this_csc_config['block'];
			$pconfig['description'] = $this_csc_config['description'];

			$pconfig['tunnel_network'] = $this_csc_config['tunnel_network'];
			$pconfig['tunnel_networkv6'] = $this_csc_config['tunnel_networkv6'];
			$pconfig['local_network'] = $this_csc_config['local_network'];
			$pconfig['local_networkv6'] = $this_csc_config['local_networkv6'];
			$pconfig['gateway'] = $this_csc_config['gateway'];
			$pconfig['gateway6'] = $this_csc_config['gateway6'];
			$pconfig['remote_network'] = $this_csc_config['remote_network'];
			$pconfig['remote_networkv6'] = $this_csc_config['remote_networkv6'];
			$pconfig['gwredir'] = $this_csc_config['gwredir'];
			$pconfig['gwredir6'] = $this_csc_config['gwredir6'];

			$pconfig['inactive_seconds'] = $this_csc_config['inactive_seconds'];
			$pconfig['ping_seconds'] = $this_csc_config['ping_seconds'];
			$pconfig['ping_action'] = $this_csc_config['ping_action'];
			$pconfig['ping_action_seconds'] = $this_csc_config['ping_action_seconds'];

			$pconfig['dns_domain'] = $this_csc_config['dns_domain'];
			if ($pconfig['dns_domain']) {
				$pconfig['dns_domain_enable'] = true;
			}

			$pconfig['dns_server1'] = $this_csc_config['dns_server1'];
			$pconfig['dns_server2'] = $this_csc_config['dns_server2'];
			$pconfig['dns_server3'] = $this_csc_config['dns_server3'];
			$pconfig['dns_server4'] = $this_csc_config['dns_server4'];

			if ($pconfig['dns_server1'] ||
			    $pconfig['dns_server2'] ||
			    $pconfig['dns_server3'] ||
			    $pconfig['dns_server4']) {
				$pconfig['dns_server_enable'] = true;
			}

			$pconfig['push_blockoutsidedns'] = $this_csc_config['push_blockoutsidedns'];
			$pconfig['push_register_dns'] = $this_csc_config['push_register_dns'];

			$pconfig['ntp_server1'] = $this_csc_config['ntp_server1'];
			$pconfig['ntp_server2'] = $this_csc_config['ntp_server2'];

			if ($pconfig['ntp_server1'] ||
			    $pconfig['ntp_server2']) {
				$pconfig['ntp_server_enable'] = true;
			}

			$pconfig['netbios_enable'] = $this_csc_config['netbios_enable'];
			$pconfig['netbios_ntype'] = $this_csc_config['netbios_ntype'];
			$pconfig['netbios_scope'] = $this_csc_config['netbios_scope'];

			$pconfig['wins_server1'] = $this_csc_config['wins_server1'];
			$pconfig['wins_server2'] = $this_csc_config['wins_server2'];

			if ($pconfig['wins_server1'] ||
			    $pconfig['wins_server2']) {
				$pconfig['wins_server_enable'] = true;
			}

			$pconfig['nbdd_server1'] = $this_csc_config['nbdd_server1'];
			$pconfig['nbdd_server2'] = $this_csc_config['nbdd_server2'];

			if ($pconfig['nbdd_server1'] || $pconfig['nbdd_server2']) {
				$pconfig['nbdd_server_enable'] = true;
			}
		}
	}

	return $pconfig;
}

/*
 * Validate and save the override edit form $pconfig for the override at
 * position $id (null or missing: a new override); $act is the form's "act".
 * Applied at once like the page (the override files are rewritten, the DNS
 * Resolver is reconfigured). $pconfig is changed like the page changed it.
 * Returns the input errors (empty when saved).
 */
function openvpn_csc_save(array &$pconfig, $id, $act, $user_can_edit_advanced) {
	global $openvpn_ping_action;

	$post = $pconfig;
	$input_errors = array();
	$netbios_nodetypes = openvpn_netbios_nodetypes();
	$serveroptionlist = openvpn_csc_server_list();
	if (($id !== null) && !is_numericint((string)$id)) {
		$id = null;
	}
	$this_csc_config = isset($id) ? config_get_path("openvpn/openvpn-csc/{$id}") : null;

	/* input validation */
	if (isset($pconfig['custom_options']) &&
	    ($pconfig['custom_options'] != $this_csc_config['custom_options']) &&
	    !$user_can_edit_advanced) {
		$input_errors[] = gettext("This user does not have sufficient privileges to edit Advanced options on this instance.");
	}
	if (!$user_can_edit_advanced && !empty($this_csc_config['custom_options'])) {
		// Restore the "custom options" field
		$pconfig['custom_options'] = $this_csc_config['custom_options'];
	}

	if (!empty($pconfig['server_list'])) {
		if (is_array($pconfig['server_list'])) {
			foreach ($pconfig['server_list'] as $server) {
				if (!array_key_exists(trim($server), $serveroptionlist)) {
					$input_errors[] = gettext("The server list contains an invalid entry.");
				}
			}
		} else {
			$input_errors[] = gettext("The server list is invalid");
		}
	}

	if (!empty($pconfig['override_options']) && !array_key_exists($pconfig['override_options'], openvpn_csc_override_options())) {
		$input_errors[] = gettext("The selected Reset Server Options value is not valid.");
	}
	if (($pconfig['override_options'] == 'remove_specified') && !empty($pconfig['remove_options'])) {
		if (!is_array($pconfig['remove_options']) ||
		    !empty(array_diff($pconfig['remove_options'], array_keys(openvpn_csc_remove_options())))) {
			$input_errors[] = gettext("The Remove Options list contains an invalid entry.");
		}
	}

	if (!empty($pconfig['tunnel_network']) && !openvpn_validate_tunnel_network($pconfig['tunnel_network'], 'ipv4')) {
		$input_errors[] = gettext("The field 'IPv4 Tunnel Network' must contain a valid IPv4 subnet with CIDR mask or an alias with a single IPv4 subnet with CIDR mask.");
	}

	if (!empty($pconfig['tunnel_networkv6']) && !openvpn_validate_tunnel_network($pconfig['tunnel_networkv6'], 'ipv6')) {
		$input_errors[] = gettext("The field 'IPv6 Tunnel Network' must contain a valid IPv6 prefix or an alias with a single IPv6 prefix.");
	}

	if (empty($pconfig['gwredir']) && ($result = openvpn_validate_cidr($pconfig['local_network'], 'IPv4 Local Network', true, "ipv4", true))) {
		$input_errors[] = $result;
	}

	if (empty($pconfig['gwredir6']) && ($result = openvpn_validate_cidr($pconfig['local_networkv6'], 'IPv6 Local Network', true, "ipv6", true))) {
		$input_errors[] = $result;
	}

	if (!empty($pconfig['gateway']) && !is_ipaddrv4($pconfig['gateway'])) {
		$input_errors[] = gettext("The specified IPv4 gateway address is invalid.");
	}

	if (!empty($pconfig['gateway6']) && !is_ipaddrv6($pconfig['gateway6'])) {
		$input_errors[] = gettext("The specified IPv6 gateway address is invalid.");
	}

	if ($result = openvpn_validate_cidr($pconfig['remote_network'], 'IPv4 Remote Network', true, "ipv4", true)) {
		$input_errors[] = $result;
	}

	if ($result = openvpn_validate_cidr($pconfig['remote_networkv6'], 'IPv6 Remote Network', true, "ipv6", true)) {
		$input_errors[] = $result;
	}

	if (!empty($pconfig['inactive_seconds']) && !is_numericint($pconfig['inactive_seconds'])) {
		$input_errors[] = gettext('The supplied "Inactivity Timeout" value is invalid.');
	}

	if (!empty($pconfig['ping_seconds']) && !is_numericint($pconfig['ping_seconds'])) {
		$input_errors[] = gettext('The supplied "Ping Interval" value is invalid.');
	}
	if (!empty($pconfig['ping_action']) && ($pconfig['ping_action'] != 'default')) {
		if (!isset($openvpn_ping_action[$pconfig['ping_action']])) {
			$input_errors[] = gettext('The field "Ping Action" contains an invalid selection.');
		}
		if (!is_numericint($pconfig['ping_action_seconds'])) {
			$input_errors[] = gettext('The supplied "Ping Action" timeout value is invalid.');
		}
	}

	if ($pconfig['dns_domain_enable'] && !is_domain($pconfig['dns_domain'])) {
		$input_errors[] = gettext("The field 'DNS Default Domain' must contain a valid domain name");
	}

	if ($pconfig['netbios_enable'] && !empty($pconfig['netbios_scope']) &&
	    !openvpn_is_valid_netbios_scope($pconfig['netbios_scope'])) {
		$input_errors[] = gettext("The field 'NetBIOS Scope ID' may only contain letters, digits, hyphens, underscores and dots");
	}

	if ($pconfig['dns_server_enable']) {
		if (!empty($pconfig['dns_server1']) && !is_ipaddr(trim($pconfig['dns_server1']))) {
			$input_errors[] = gettext("The field 'DNS Server #1' must contain a valid IP address");
		}
		if (!empty($pconfig['dns_server2']) && !is_ipaddr(trim($pconfig['dns_server2']))) {
			$input_errors[] = gettext("The field 'DNS Server #2' must contain a valid IP address");
		}
		if (!empty($pconfig['dns_server3']) && !is_ipaddr(trim($pconfig['dns_server3']))) {
			$input_errors[] = gettext("The field 'DNS Server #3' must contain a valid IP address");
		}
		if (!empty($pconfig['dns_server4']) && !is_ipaddr(trim($pconfig['dns_server4']))) {
			$input_errors[] = gettext("The field 'DNS Server #4' must contain a valid IP address");
		}
	}

	if ($pconfig['ntp_server_enable']) {
		if (!empty($pconfig['ntp_server1']) && !is_ipaddr(trim($pconfig['ntp_server1']))) {
			$input_errors[] = gettext("The field 'NTP Server #1' must contain a valid IP address");
		}
		if (!empty($pconfig['ntp_server2']) && !is_ipaddr(trim($pconfig['ntp_server2']))) {
			$input_errors[] = gettext("The field 'NTP Server #2' must contain a valid IP address");
		}
		if (!empty($pconfig['ntp_server3']) && !is_ipaddr(trim($pconfig['ntp_server3']))) {
			$input_errors[] = gettext("The field 'NTP Server #3' must contain a valid IP address");
		}
		if (!empty($pconfig['ntp_server4']) && !is_ipaddr(trim($pconfig['ntp_server4']))) {
			$input_errors[] = gettext("The field 'NTP Server #4' must contain a valid IP address");
		}
	}

	if ($pconfig['netbios_enable']) {
		if ($pconfig['wins_server_enable']) {
			if (!empty($pconfig['wins_server1']) && !is_ipaddr(trim($pconfig['wins_server1']))) {
				$input_errors[] = gettext("The field 'WINS Server #1' must contain a valid IP address");
			}
			if (!empty($pconfig['wins_server2']) && !is_ipaddr(trim($pconfig['wins_server2']))) {
				$input_errors[] = gettext("The field 'WINS Server #2' must contain a valid IP address");
			}
		}
		if ($pconfig['nbdd_server_enable']) {
			if (!empty($pconfig['nbdd_server1']) && !is_ipaddr(trim($pconfig['nbdd_server1']))) {
				$input_errors[] = gettext("The field 'NetBIOS Data Distribution Server #1' must contain a valid IP address");
			}
			if (!empty($pconfig['nbdd_server2']) && !is_ipaddr(trim($pconfig['nbdd_server2']))) {
				$input_errors[] = gettext("The field 'NetBIOS Data Distribution Server #2' must contain a valid IP address");
			}
		}

		if (!empty($pconfig['netbios_ntype']) &&
		    !array_key_exists($pconfig['netbios_ntype'], $netbios_nodetypes)) {
			$input_errors[] = gettext("The selected NetBIOS Node Type is not valid.");
		}
	}

	$reqdfields[] = 'common_name';
	$reqdfieldsn[] = 'Common name';

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!$input_errors) {
		$csc = array();

		// Handle "Reset Server Options" and "Reset Options"
		if ($pconfig['override_options'] == 'push_reset') {
			$csc['push_reset'] = true;
		} elseif (($pconfig['override_options'] == 'remove_specified') && !empty($pconfig['remove_options'])) {
			$csc['remove_options'] = implode(',', $pconfig['remove_options']);
		}
		if (isset($pconfig['keep_minimal']) && (isset($csc['push_reset']) || isset($csc['remove_options']))) {
			$csc['keep_minimal'] = true;
		}

		if (is_array($pconfig['server_list'])) {
			$csc['server_list'] = implode(",", $pconfig['server_list']);
		} else {
			$csc['server_list'] = "";
		}
		$csc['custom_options'] = $pconfig['custom_options'];
		if ($post['disable'] == "yes") {
			$csc['disable'] = true;
		}
		$csc['common_name'] = $pconfig['common_name'];
		$csc['block'] = $pconfig['block'];
		$csc['description'] = $pconfig['description'];
		$csc['tunnel_network'] = $pconfig['tunnel_network'];
		$csc['tunnel_networkv6'] = $pconfig['tunnel_networkv6'];

		$csc['gateway'] = $pconfig['gateway'];
		$csc['gateway6'] = $pconfig['gateway6'];
		// Don't push routes if redirecting all traffic.
		if (!empty($pconfig['gwredir'])) {
			$csc['gwredir'] = $pconfig['gwredir'];
		} else {
			$csc['local_network'] = $pconfig['local_network'];
		}
		if (!empty($pconfig['gwredir6'])) {
			$csc['gwredir6'] = $pconfig['gwredir6'];
		} else {
			$csc['local_networkv6'] = $pconfig['local_networkv6'];
		}

		$csc['remote_network'] = $pconfig['remote_network'];
		$csc['remote_networkv6'] = $pconfig['remote_networkv6'];

		if (is_numericint($pconfig['inactive_seconds'])) {
			$csc['inactive_seconds'] = $pconfig['inactive_seconds'];
		}
		if (is_numericint($pconfig['ping_seconds'])) {
			$csc['ping_seconds'] = $pconfig['ping_seconds'];
		}
		if (!empty($pconfig['ping_action']) && ($pconfig['ping_action'] != 'default')) {
			$csc['ping_action'] = $pconfig['ping_action'];
			$csc['ping_action_seconds'] = $pconfig['ping_action_seconds'];
		}

		if ($pconfig['dns_domain_enable']) {
			$csc['dns_domain'] = $pconfig['dns_domain'];
		}

		if ($pconfig['dns_server_enable']) {
			$csc['dns_server1'] = $pconfig['dns_server1'];
			$csc['dns_server2'] = $pconfig['dns_server2'];
			$csc['dns_server3'] = $pconfig['dns_server3'];
			$csc['dns_server4'] = $pconfig['dns_server4'];
		}

		$csc['push_blockoutsidedns'] = $pconfig['push_blockoutsidedns'];
		$csc['push_register_dns'] = $pconfig['push_register_dns'];

		if ($pconfig['ntp_server_enable']) {
			$csc['ntp_server1'] = $pconfig['ntp_server1'];
			$csc['ntp_server2'] = $pconfig['ntp_server2'];
		}

		$csc['netbios_enable'] = $pconfig['netbios_enable'];

		if ($pconfig['netbios_enable']) {
			$csc['netbios_ntype'] = $pconfig['netbios_ntype'];
			$csc['netbios_scope'] = $pconfig['netbios_scope'];

			if ($pconfig['wins_server_enable']) {
				$csc['wins_server1'] = $pconfig['wins_server1'];
				$csc['wins_server2'] = $pconfig['wins_server2'];
			}

			if ($pconfig['nbdd_server_enable']) {
				$csc['nbdd_server1'] = $pconfig['nbdd_server1'];
				$csc['nbdd_server2'] = $pconfig['nbdd_server2'];
			}
		}

		if (($act == 'new') || (!empty($csc['disable']) ^ !empty($this_csc_config['disable'])) ||
		    ($csc['tunnel_network'] != $this_csc_config['tunnel_network']) ||
		    ($csc['tunnel_networkv6'] != $this_csc_config['tunnel_networkv6'])) {
			$csc['unbound_restart'] = true;
		}

		if ($this_csc_config) {
			$old_csc = $this_csc_config;
			config_set_path("openvpn/openvpn-csc/{$id}", $csc);
			$wc_msg = sprintf(gettext('Updated OpenVPN client specific override %1$s %2$s'), $csc['common_name'], $csc['description']);
		} else {
			config_set_path('openvpn/openvpn-csc/', $csc);
			$wc_msg = sprintf(gettext('Added OpenVPN client specific override %1$s %2$s'), $csc['common_name'], $csc['description']);
		}

		if (!empty($old_csc['common_name'])) {
			openvpn_delete_csc($old_csc);
		}
		openvpn_resync_csc($csc);
		write_config($wc_msg);
		services_unbound_configure(false);
	}

	return $input_errors;
}

/*
 * Delete the override at position $id like the list page (refused when it has
 * advanced options the user may not edit). Returns null when there is no such
 * entry (the page redirects), else array('input_errors' => [...], 'deleted' => bool).
 */
function openvpn_csc_delete($id, $user_can_edit_advanced) {
	$this_csc_config = (($id !== null) && is_numericint((string)$id)) ? config_get_path("openvpn/openvpn-csc/{$id}") : null;
	if (!$this_csc_config) {
		return null;
	}
	$input_errors = array();

	if (!$user_can_edit_advanced && !empty($this_csc_config['custom_options'])) {
		$input_errors[] = gettext("This user does not have sufficient privileges to delete an instance with Advanced options set.");
	} else {
		$wc_msg = sprintf(gettext('Deleted OpenVPN client specific override %1$s %2$s'), $this_csc_config['common_name'], $this_csc_config['description']);
		openvpn_delete_csc($this_csc_config);
		config_del_path("openvpn/openvpn-csc/{$id}");
		write_config($wc_msg);
		services_unbound_configure(false);
	}
	return array('input_errors' => $input_errors, 'deleted' => empty($input_errors));
}
