<?php
/*
 * services_wol.inc
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
 * Wake-on-LAN logic shared by services_wol.php, services_wol_edit.php and
 * the REST API, moved out of the pages with the form fields as parameters.
 * Devices are stored in wol/wolentry, sorted by description after every
 * save. There is nothing to apply.
 */

require_once("config.gui.inc");
require_once("interfaces.inc");
require_once("util.inc");

/*
 * Send a magic packet to $mac on the broadcast address of interface $if.
 * Appends the result to $savemsg and sets $class to "success" or
 * "warning"; leaves both alone when the interface has no IPv4 address or
 * the MAC address is invalid.
 */
function send_wol($if, $mac, $description, & $savemsg, & $class) {
	$ipaddr = get_interface_ip($if);
	if (!is_ipaddr($ipaddr) || !is_macaddr($mac)) {
		return array();
	}
	if (!empty($description)) {
		$description = ' (' . htmlspecialchars($description) . ')';
	}
	/* determine broadcast address */
	$bcip = gen_subnet_max($ipaddr, get_interface_subnet($if));
	/* Execute wol command and check return code. */
	if (!mwexec("/usr/local/bin/wol -i {$bcip} {$mac}")) {
		$savemsg .= sprintf(gettext('Sent magic packet to %1$s%2$s.'), $mac, $description) . "<br />";
		$class = 'success';
	} else {
		$savemsg .= sprintf(gettext('Please check the %1$ssystem log%2$s, the wol command for %3$s (%4$s) did not complete successfully.'), '<a href="/status_logs.php">', '</a>', $description, htmlspecialchars($mac)) . "<br />";
		$class = 'warning';
	}
}

/* Lowercase a MAC address and convert Windows-ized hyphenated MACs to colon delimited. */
function wol_normalize_mac($mac) {
	return strtolower(str_replace("-", ":", $mac));
}

/* Wake every stored device ("Wake All Devices"). */
function wol_wake_all(& $savemsg, & $class) {
	foreach (config_get_path('wol/wolentry', []) as $wolent) {
		send_wol($wolent['interface'], $wolent['mac'], $wolent['descr'], $savemsg, $class);
	}
	$savemsg .= gettext('Sent magic packet to all devices.') . "<br />";
}

/*
 * Validate and wake one device (the page's "Send" and its per-device wake
 * links). $mac must already be normalized. Returns the input errors.
 */
function wol_wake_device($mac, $if, & $savemsg, & $class) {
	$input_errors = array();

	/* input validation */
	if (!$mac || !is_macaddr($mac)) {
		$input_errors[] = gettext("A valid MAC address must be specified.");
	}

	if (!$if || !array_key_exists($if, get_configured_interface_with_descr())) {
		$input_errors[] = gettext("A valid interface must be specified.");
	}

	if (!$input_errors) {
		send_wol($if, $mac, '', $savemsg, $class);
	}

	return $input_errors;
}

/* Delete the device at position $id. Returns true when it existed. */
function wol_delete_entry($id) {
	if (is_numericint($id) && config_get_path("wol/wolentry/{$id}")) {
		config_del_path("wol/wolentry/{$id}");
		write_config(gettext("Deleted a device from WOL configuration."));
		return true;
	}
	return false;
}

function wolcmp($a, $b) {
	return strcmp($a['descr'], $b['descr']);
}

function wol_sort() {
	$wol_config = config_get_path('wol/wolentry', []);
	usort($wol_config, "wolcmp");
	config_set_path('wol/wolentry', $wol_config);
}

/*
 * Save the edit form ($post: interface, mac, descr) as the device at
 * position $id, or as a new device when $id is not set or does not exist.
 * The list is sorted by description afterwards, so positions can change.
 * Returns the input errors (empty when saved).
 */
function wol_save_entry($post, $id) {
	$input_errors = array();
	$this_wol_config = isset($id) ? config_get_path("wol/wolentry/{$id}") : null;

	/* input validation */
	$reqdfields = explode(" ", "interface mac");
	$reqdfieldsn = array(gettext("Interface"), gettext("MAC address"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!$post['interface'] || !array_key_exists($post['interface'], get_configured_interface_with_descr())) {
		$input_errors[] = gettext("A valid interface must be specified.");
	}

	/* normalize MAC addresses - lowercase and convert Windows-ized hyphenated MACs to colon delimited */
	$post['mac'] = trim(wol_normalize_mac($post['mac']));

	if (($post['mac'] && !is_macaddr($post['mac']))) {
		$input_errors[] = gettext("A valid MAC address must be specified.");
	}

	foreach (config_get_path('wol/wolentry', []) as $wolidx => $wolentry) {
		if ((!isset($id) || ($wolidx != $id)) && ($wolentry['interface'] == $post['interface']) && ($wolentry['mac'] == $post['mac'])) {
			$input_errors[] = gettext("This interface and MAC address wake-on-LAN entry already exists.");
			break;
		}
	}

	if (!$input_errors) {
		$wolent = array();
		$wolent['interface'] = $post['interface'];
		$wolent['mac'] = $post['mac'];
		$wolent['descr'] = $post['descr'];

		if ($this_wol_config) {
			config_set_path("wol/wolentry/{$id}", $wolent);
		} else {
			config_set_path('wol/wolentry/', $wolent);
		}
		wol_sort();

		write_config(gettext("Configured a wake-on-LAN entry."));
	}

	return $input_errors;
}
