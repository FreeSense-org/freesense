<?php
/*
 * vpn_l2tp.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
 * L2TP VPN server logic shared by vpn_l2tp.php, vpn_l2tp_users.php,
 * vpn_l2tp_users_edit.php and the REST API, moved out of the pages with the
 * form fields as parameters. The settings live in l2tp, the users in
 * l2tp/user (kept sorted by name). Like the pages, every change is applied at
 * once: a settings save restarts the L2TP server (vpn_l2tp_configure()), a
 * user change rewrites its secrets file (vpn_l2tp_updatesecret()).
 */

require_once("config.gui.inc");
require_once("interfaces.inc");
require_once("util.inc");
require_once("vpn.inc");

/* The authentication types the settings page offers. */
function l2tp_paporchap_values() {
	return array(
		'chap' => 'CHAP',
		'chap-msv2' => 'MS-CHAPv2',
		'pap' => 'PAP'
	);
}

/* True when $text contains a control character (it would end the line of an mpd configuration file). */
function l2tp_has_control_chars($text) {
	return (preg_match('/[\x00-\x1f\x7f]/', (string)$text) === 1);
}

/* The settings page's form, read from the configuration. */
function l2tp_settings_form() {
	$pconfig = array();
	$pconfig['remoteip'] = config_get_path('l2tp/remoteip');
	$pconfig['localip'] = config_get_path('l2tp/localip');
	$pconfig['l2tp_subnet'] = config_get_path('l2tp/l2tp_subnet');
	$pconfig['mode'] = config_get_path('l2tp/mode');
	$pconfig['interface'] = config_get_path('l2tp/interface');
	$pconfig['l2tp_dns1'] = config_get_path('l2tp/dns1');
	$pconfig['l2tp_dns2'] = config_get_path('l2tp/dns2');
	$pconfig['mtu'] = config_get_path('l2tp/mtu');
	$pconfig['radiusenable'] = config_path_enabled('l2tp/radius');
	$pconfig['radacct_enable'] = config_path_enabled('l2tp/radius', 'accounting');
	$pconfig['radiusserver'] = config_get_path('l2tp/radius/server');
	$pconfig['radiussecret'] = config_get_path('l2tp/radius/secret');
	$pconfig['radiusissueips'] = config_path_enabled('l2tp/radius', 'radiusissueips');
	$pconfig['n_l2tp_units'] = config_get_path('l2tp/n_l2tp_units');
	$pconfig['paporchap'] = config_get_path('l2tp/paporchap');
	$pconfig['secret'] = config_get_path('l2tp/secret');
	return $pconfig;
}

/*
 * Validate and save the settings page's form ($post, as the page posts it) and
 * restart the L2TP server, like the page's Save. The secrets keep their value
 * when DMYPWD is posted. Returns array('input_errors' => list, 'pconfig' =>
 * the form to show again, 'changes_applied' => bool, 'retval' => the result
 * of vpn_l2tp_configure()).
 */
function l2tp_settings_save(array $post) {
	$input_errors = array();
	$pconfig = $post;
	$changes_applied = false;
	$retval = 0;

	/* input validation */
	if ($post['mode'] == "server") {
		$reqdfields = explode(" ", "localip");
		$reqdfieldsn = array(gettext("Server address"));
		if (!$post['radiusenable'] || !$post['radiusissueips']) {
			$reqdfields = array_merge($reqdfields, explode(" ", "remoteip"));
			$reqdfieldsn = array_merge($reqdfieldsn, array(gettext("Remote start address")));
		} elseif ($post['radiusenable']) {
			$reqdfields = array_merge($reqdfields, explode(" ", "radiusserver radiussecret"));
			$reqdfieldsn = array_merge($reqdfieldsn,
				array(gettext("RADIUS server address"), gettext("RADIUS shared secret")));
		}

		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

		if (!array_key_exists((string)$post['interface'], get_configured_interface_with_descr())) {
			$input_errors[] = gettext("A valid interface must be selected.");
		}
		if (($post['localip'] && !is_ipaddrv4($post['localip']))) {
			$input_errors[] = gettext("A valid server address must be specified.");
		}
		if (is_ipaddr_configured($post['localip'])) {
			$input_errors[] = gettext("'Server address' parameter should NOT be set to any IP address currently in use on this firewall.");
		}
		if ($post['l2tp_subnet'] && !is_ipaddrv4($post['remoteip']) &&
		    (!$post['radiusenable'] || !$post['radiusissueips'])) {
			$input_errors[] = gettext("A valid remote start address must be specified.");
		}
		if (($post['radiusserver'] && !is_ipaddrv4($post['radiusserver']))) {
			$input_errors[] = gettext("A valid RADIUS server address must be specified.");
		}

		if ($post['secret'] != $post['secret_confirm']) {
			$input_errors[] = gettext("Secret and confirmation must match");
		}

		if ($post['radiussecret'] != $post['radiussecret_confirm']) {
			$input_errors[] = gettext("RADIUS secret and confirmation must match");
		}

		if (!is_numericint($post['n_l2tp_units']) || ($post['n_l2tp_units'] < 1) || ($post['n_l2tp_units'] > 255)) {
			$input_errors[] = gettext("Number of L2TP users must be between 1 and 255");
		}

		if (!array_key_exists((string)$post['paporchap'], l2tp_paporchap_values())) {
			$input_errors[] = gettext("A valid authentication type must be selected.");
		}

		if (!$input_errors) {
			$post['remoteip'] = $pconfig['remoteip'] = gen_subnet($post['remoteip'], $post['l2tp_subnet']);
			if (is_inrange_v4($post['localip'], $post['remoteip'], ip_after($post['remoteip'], $post['n_l2tp_units'] - 1))) {
				$input_errors[] = gettext("The specified server address lies in the remote subnet.");
			}
			if ($post['localip'] == get_interface_ip("lan")) {
				$input_errors[] = gettext("The specified server address is equal to the LAN interface address.");
			}
		}

		if (!empty($post['l2tp_dns1']) && !is_ipaddrv4(trim($post['l2tp_dns1']))) {
			$input_errors[] = gettext("The field 'Primary L2TP DNS Server' must contain a valid IPv4 address.");
		}
		if (!empty($post['l2tp_dns2']) && !is_ipaddrv4(trim($post['l2tp_dns2']))) {
			$input_errors[] = gettext("The field 'Secondary L2TP DNS Server' must contain a valid IPv4 address.");
		}
		if (!empty($post['l2tp_dns2']) && empty($post['l2tp_dns1'])) {
			$input_errors[] = gettext("The Secondary L2TP DNS Server cannot be set when the Primary L2TP DNS Server is empty.");
		}
		if ($post['mtu']) {
			if (!is_numericint($post['mtu'])) {
				$input_errors[] = "MTU must be an integer.";
			}
			$min_mtu = 576;
			$max_mtu = 9000;
			if (($post['mtu'] < $min_mtu) || ($post['mtu'] > $max_mtu)) {
				$input_errors[] = sprintf(gettext("The MTU must be between %d and %d bytes."), $min_mtu, $max_mtu);
			}
		}
	}

	/* The secrets are written into the mpd configuration: one line each. */
	if (($post['secret'] != DMYPWD) && l2tp_has_control_chars($post['secret'])) {
		$input_errors[] = gettext("The secret cannot contain control characters.");
	}
	if (($post['radiussecret'] != DMYPWD) && l2tp_has_control_chars($post['radiussecret'])) {
		$input_errors[] = gettext("The RADIUS secret cannot contain control characters.");
	}

	if (!$input_errors) {
		$l2tpcfg = config_get_path('l2tp', []);
		$l2tpcfg['remoteip'] = $post['remoteip'];
		$l2tpcfg['localip'] = $post['localip'];
		$l2tpcfg['l2tp_subnet'] = $post['l2tp_subnet'];
		$l2tpcfg['mode'] = $post['mode'];
		$l2tpcfg['interface'] = $post['interface'];
		$l2tpcfg['n_l2tp_units'] = $post['n_l2tp_units'];
		$l2tpcfg['radius']['server'] = $post['radiusserver'];
		if ($post['radiussecret'] != DMYPWD) {
			$l2tpcfg['radius']['secret'] = $post['radiussecret'];
		}

		if ($post['secret'] != DMYPWD) {
			$l2tpcfg['secret'] = $post['secret'];
		}

		$l2tpcfg['paporchap'] = $post['paporchap'];


		if ($post['l2tp_dns1'] == "") {
			if (isset($l2tpcfg['dns1'])) {
				unset($l2tpcfg['dns1']);
			}
		} else {
			$l2tpcfg['dns1'] = $post['l2tp_dns1'];
		}

		if ($post['l2tp_dns2'] == "") {
			if (isset($l2tpcfg['dns2'])) {
				unset($l2tpcfg['dns2']);
			}
		} else {
			$l2tpcfg['dns2'] = $post['l2tp_dns2'];
		}

		if ($post['mtu'] == "") {
			if (isset($l2tpcfg['mtu'])) {
				unset($l2tpcfg['mtu']);
			}
		} else {
			$l2tpcfg['mtu'] = $post['mtu'];
		}

		if ($post['radiusenable'] == "yes") {
			$l2tpcfg['radius']['enable'] = true;
		} else {
			unset($l2tpcfg['radius']['enable']);
		}

		if ($post['radacct_enable'] == "yes") {
			$l2tpcfg['radius']['accounting'] = true;
		} else {
			unset($l2tpcfg['radius']['accounting']);
		}

		if ($post['radiusissueips'] == "yes") {
			$l2tpcfg['radius']['radiusissueips'] = true;
		} else {
			unset($l2tpcfg['radius']['radiusissueips']);
		}

		config_set_path('l2tp', $l2tpcfg);
		write_config(gettext("L2TP VPN configuration changed."));

		$changes_applied = true;
		$retval = 0;
		$retval |= vpn_l2tp_configure();
	}

	return array('input_errors' => $input_errors, 'pconfig' => $pconfig, 'changes_applied' => $changes_applied,
	    'retval' => $retval);
}

/* The stored user at position $id, or null. */
function l2tp_user_conf($id) {
	if (($id === null) || !is_numericint((string)$id)) {
		return null;
	}
	$user = config_get_path("l2tp/user/{$id}");
	return $user ? $user : null;
}

/* The user edit page's form for the user at position $id (empty for a new user). */
function l2tp_user_form($id) {
	$pconfig = array();
	$this_secret_config = l2tp_user_conf($id);
	if ($this_secret_config) {
		$pconfig['usernamefld'] = $this_secret_config['name'];
		$pconfig['ip'] = $this_secret_config['ip'];
		$pconfig['passwordfld'] = $this_secret_config['passwordfld'];
	}
	return $pconfig;
}

/*
 * Validate and save the user edit form ($post) for the user at position $id
 * (null or a missing position: a new user), keep the list sorted and rewrite
 * the secrets file, like the page's Save. An empty password keeps the
 * current one. Returns the input errors.
 */
function l2tp_user_save(array $post, $id) {
	$input_errors = array();
	$this_secret_config = l2tp_user_conf($id);

	/* input validation */
	if ($this_secret_config) {
		$reqdfields = explode(" ", "usernamefld");
		$reqdfieldsn = array(gettext("Username"));
	} else {
		$reqdfields = explode(" ", "usernamefld passwordfld");
		$reqdfieldsn = array(gettext("Username"), gettext("Password"));
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (preg_match("/[^a-zA-Z0-9\.\@\-_]/", $post['usernamefld'])) {
		$input_errors[] = gettext("The username contains invalid characters.");
	}
	if (preg_match("/^!/", trim($post['passwordfld']))) {
		$input_errors[] = gettext("The password cannot start with '!'.");
	}
	/* The password is written into the mpd secrets file: one line per user. */
	if (l2tp_has_control_chars($post['passwordfld'])) {
		$input_errors[] = gettext("The password cannot contain control characters.");
	}
	if (($post['passwordfld']) && ($post['passwordfld'] != $post['passwordfld_confirm'])) {
		$input_errors[] = gettext("The passwords do not match.");
	}
	if (($post['ip'] && !is_ipaddr($post['ip']))) {
		$input_errors[] = gettext("The IP address entered is not valid.");
	}

	if (!$input_errors) {
		/* make sure there are no dupes (other than the user being edited) */
		foreach (config_get_path('l2tp/user', []) as $idx => $secretent) {
			if ($this_secret_config && ((string)$idx === (string)$id)) {
				continue;
			}
			if ($secretent['name'] == $post['usernamefld']) {
				$input_errors[] = gettext("Another entry with the same username already exists.");
				break;
			}
		}
	}

	if (!$input_errors) {

		if ($this_secret_config) {
			$secretent = $this_secret_config;
		}

		$secretent['name'] = $post['usernamefld'];
		$secretent['ip'] = $post['ip'];

		if ($post['passwordfld'] && ($post['passwordfld'] != DMYPWD)) {
			$secretent['password'] = $post['passwordfld'];
		}

		if ($this_secret_config) {
			config_set_path("l2tp/user/{$id}", $secretent);
		} else {
			config_set_path('l2tp/user/', $secretent);
		}
		l2tp_users_sort();

		write_config(gettext("Configured a L2TP VPN user."));

		vpn_l2tp_updatesecret();
	}

	return $input_errors;
}

/* Delete the user at position $id and rewrite the secrets file; false when there is no such user. */
function l2tp_user_delete($id) {
	if (!l2tp_user_conf($id)) {
		return false;
	}
	config_del_path("l2tp/user/{$id}");
	l2tp_users_sort();
	write_config(gettext("Deleted a L2TP VPN user."));
	vpn_l2tp_updatesecret();
	return true;
}
