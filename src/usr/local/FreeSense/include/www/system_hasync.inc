<?php
/*
 * system_hasync.inc
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

require_once("config.gui.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("interfaces.inc");
require_once("util.inc");

// Functions included by system_hasync.php (System > High Availability) ======

/* The page's checkboxes (stored as the posted value, "on", or false). */
function system_hasync_checkbox_names() {
	return array(
		'pfsyncenabled',
		'synctlsinsecure',
		'adminsync',
		'synchronizeusers',
		'synchronizeauthservers',
		'synchronizecerts',
		'synchronizerules',
		'synchronizeschedules',
		'synchronizealiases',
		'synchronizenat',
		'synchronizeipsec',
		'synchronizeopenvpn',
		'synchronizedhcpd',
		'synchronizedhcrelay',
		'synchronizekea6',
		'synchronizedhcrelay6',
		'synchronizewol',
		'synchronizestaticroutes',
		'synchronizevirtualip',
		'synchronizetrafficshaper',
		'synchronizetrafficshaperlimiter',
		'synchronizednsforwarder',
		'synchronizecaptiveportal');
}

/*
 * Validate and save the High Availability form ($post: the page's fields).
 * On success the section is stored and applied (pfsync setup; the filter is
 * reloaded when the host ID changed). Returns the input errors and the hasync
 * section with the posted values (the page shows it again after an error).
 */
function system_hasync_save(array $post) {
	$a_hasync = config_get_path('hasync', []);
	$input_errors = array();

	$pconfig = $post;
	foreach (system_hasync_checkbox_names() as $name) {
		$a_hasync[$name] = $pconfig[$name] ? $pconfig[$name] : false;
	}
	$old_pfhostid = isset($a_hasync['pfhostid']) ? $a_hasync['pfhostid'] : '';
	$a_hasync['pfhostid'] = strtolower(trim($pconfig['pfhostid']));
	$a_hasync['pfsyncpeerip'] = $pconfig['pfsyncpeerip'];
	$a_hasync['pfsyncinterface'] = $pconfig['pfsyncinterface'];
	$a_hasync['synchronizetoip'] = $pconfig['synchronizetoip'];
	$a_hasync['username'] = $pconfig['username'];

	if ($pconfig['passwordfld'] == $pconfig['passwordfld_confirm']) {
		if ($pconfig['passwordfld'] != DMYPWD) {
				$a_hasync['password'] = $pconfig['passwordfld'];
		}
	} else {
		$input_errors[] = gettext("Password and confirmation must match.");
	}

	if ((!empty($pconfig['pfhostid']) &&
	    !(ctype_xdigit($pconfig['pfhostid']) &&
	    (strlen($pconfig['pfhostid']) <= 8))) ||
	    ($pconfig['pfhostid'] === "0")) {
		$input_errors[] = gettext("Invalid Host ID. Must be a non-zero hexadecimal string 8 characters or less.");
	}

	if (!empty($pconfig['pfsyncpeerip']) && !is_ipaddrv4($pconfig['pfsyncpeerip'])) {
		$input_errors[] = gettext("pfsync Synchronize Peer IP must be an IPv4 IP.");
	}

	if (!empty($pconfig['synchronizetoip']) && !is_ipaddr($pconfig['synchronizetoip'])) {
		$input_errors[] = gettext("Synchronize Config to IP must be a valid IP address.");
	}

	if (!$input_errors) {
		config_set_path('hasync', $a_hasync);
		write_config("Updated High Availability Sync configuration");
		interfaces_sync_setup();
		if ($old_pfhostid != $a_hasync['pfhostid']) {
			filter_configure();
		}
	}

	return array('input_errors' => $input_errors, 'hasync' => $a_hasync);
}
