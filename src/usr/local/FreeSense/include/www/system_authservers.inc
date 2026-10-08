<?php
/*
 * system_authservers.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc
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
 * Authentication server logic shared by system_authservers.php and the
 * REST API, moved out of the page with the form fields as parameters.
 * Functions are prefixed authsrv_. Servers are stored in system/authserver;
 * the page addresses them by their position in auth_get_authserver_list()
 * (the configured servers, then the built-in "Local Database"). Changes are
 * written at once, like the page.
 *
 * A server that is in use (the webConfigurator's authentication source,
 * OpenVPN servers, IPsec mobile clients, captive portal zones) cannot be
 * deleted, and only an administrator (uid 0 or "WebCfg - All pages") may
 * change the server the webConfigurator authenticates against: it decides
 * who gets the privileges of the remote groups.
 */

require_once("auth.inc");
require_once("interfaces.inc");
require_once("system_usermanager.inc");
require_once("util.inc");

/* The page's choices (the same lists as guiconfig.inc's $auth_server_types, $ldap_urltypes, $ldap_scopes, $ldap_protvers, $radius_srvcs, $radius_protocol). */
function authsrv_types() {
	return array('ldap' => "LDAP", 'radius' => "RADIUS");
}

function authsrv_ldap_urltypes() {
	return array('Standard TCP' => 389, 'STARTTLS Encrypted' => 389, 'SSL/TLS Encrypted' => 636);
}

function authsrv_ldap_scopes() {
	return array('one' => gettext("One Level"), 'subtree' => gettext("Entire Subtree"));
}

function authsrv_ldap_protvers() {
	return array(2, 3);
}

function authsrv_radius_srvcs() {
	return array('both' => gettext("Authentication and Accounting"), 'auth' => gettext("Authentication"), 'acct' => gettext("Accounting"));
}

function authsrv_radius_protocols() {
	return array('PAP' => "PAP", 'CHAP_MD5' => "MD5-CHAP", 'MSCHAPv1' => "MS-CHAPv1", 'MSCHAPv2' => "MS-CHAPv2");
}

/* The servers as the page lists them: the configured ones, then "Local Database". */
function authsrv_list() {
	return array_values(auth_get_authserver_list());
}

/*
 * The LDAP container picker of the page (its AJAX request): the containers of
 * the server described by $request (port, basedn, host, scope, binddn,
 * bindpw, urltype, proto, authcn, cert) as the modal's HTML.
 */
function authsrv_ldap_containers_html(array $request) {
	$ous = array();
	$authcfg = array();

	$authcfg['ldap_port'] = $request['port'];
	$authcfg['ldap_basedn'] = $request['basedn'];
	$authcfg['host'] = $request['host'];
	$authcfg['ldap_scope'] = $request['scope'];
	$authcfg['ldap_binddn'] = $request['binddn'];
	$authcfg['ldap_bindpw'] = $request['bindpw'];
	$authcfg['ldap_urltype'] = $request['urltype'];
	$authcfg['ldap_protver'] = $request['proto'];
	$authcfg['ldap_authcn'] = explode(";", $request['authcn']);
	$authcfg['ldap_caref'] = $request['cert'];

	$ous = ldap_get_user_ous(true, $authcfg);

	if (empty($ous)) {
		return '<span class="text-danger">Could not connect to the LDAP server. Please check the LDAP configuration.</span>';
	}

	$modal = new Modal("Select LDAP containers for authentication", "containers", true);
	$group = new Form_MultiCheckboxGroup('Containers');

	if (is_array($ous)) {
		$idx = 0;

		foreach ($ous as $ou) {
			$group->add(new Form_MultiCheckbox(
				'ou' . $idx,
				'',
				$ou,
				in_array($ou, $authcfg['ldap_authcn']),
				$ou
			));

			$idx++;
		}
	}

	$modal->add($group);

	// Create a "Save button"

	$btnsv = new Form_Button(
		'svcontbtn',
		'Save',
		null,
		'fa-solid fa-save'
	);

	$btnsv->removeClass("btn-secondary)")->addClass("btn-primary");

	$modal->addInput(new Form_StaticText(
		'',
		$btnsv
	));

	return (string)$modal;
}

/* The edit form of the server $server (a list entry; $dup: copying it), as the page fills it. */
function authsrv_form(array $server, $dup) {
	$pconfig = array();
	$pconfig['type'] = $server['type'];
	if (!$dup) {
		$pconfig['name'] = $server['name'];
	}

	if ($pconfig['type'] == "ldap") {
		$pconfig['ldap_caref'] = $server['ldap_caref'];
		$pconfig['ldap_host'] = $server['host'];
		$pconfig['ldap_port'] = $server['ldap_port'];
		$pconfig['ldap_timeout'] = $server['ldap_timeout'];
		$pconfig['ldap_urltype'] = $server['ldap_urltype'];
		$pconfig['ldap_protver'] = $server['ldap_protver'];
		$pconfig['ldap_scope'] = $server['ldap_scope'];
		$pconfig['ldap_basedn'] = $server['ldap_basedn'];
		$pconfig['ldap_authcn'] = $server['ldap_authcn'];
		$pconfig['ldap_extended_enabled'] = $server['ldap_extended_enabled'];
		$pconfig['ldap_extended_query'] = $server['ldap_extended_query'];
		$pconfig['ldap_binddn'] = $server['ldap_binddn'];
		$pconfig['ldap_bindpw'] = $server['ldap_bindpw'];
		$pconfig['ldap_attr_user'] = $server['ldap_attr_user'];
		$pconfig['ldap_attr_group'] = $server['ldap_attr_group'];
		$pconfig['ldap_attr_member'] = $server['ldap_attr_member'];
		$pconfig['ldap_attr_groupobj'] = $server['ldap_attr_groupobj'];
		$pconfig['ldap_pam_groupdn'] = $server['ldap_pam_groupdn'];
		$pconfig['ldap_utf8'] = isset($server['ldap_utf8']);
		$pconfig['ldap_nostrip_at'] = isset($server['ldap_nostrip_at']);
		$pconfig['ldap_allow_unauthenticated'] = isset($server['ldap_allow_unauthenticated']);
		$pconfig['ldap_rfc2307'] = isset($server['ldap_rfc2307']);
		$pconfig['ldap_rfc2307_userdn'] = isset($server['ldap_rfc2307_userdn']);
		$pconfig['ldap_rfc2307_basedn_groups'] = isset($server['ldap_rfc2307_basedn_groups']);

		if (!$pconfig['ldap_binddn'] || !$pconfig['ldap_bindpw']) {
			$pconfig['ldap_anon'] = true;
		}
	}

	if ($pconfig['type'] == "radius") {
		$pconfig['radius_protocol'] = $server['radius_protocol'];
		$pconfig['radius_host'] = $server['host'];
		$pconfig['radius_nasip_attribute'] = $server['radius_nasip_attribute'];
		$pconfig['radius_auth_port'] = $server['radius_auth_port'];
		$pconfig['radius_acct_port'] = $server['radius_acct_port'];
		$pconfig['radius_secret'] = $server['radius_secret'];
		$pconfig['radius_timeout'] = $server['radius_timeout'];
		$pconfig['disable_radius_msg_auth'] = isset($server['disable_radius_msg_auth']);

		if ($pconfig['radius_auth_port'] &&
			$pconfig['radius_acct_port']) {
			$pconfig['radius_srvcs'] = "both";
		}

		if ($pconfig['radius_auth_port'] &&
			!$pconfig['radius_acct_port']) {
			$pconfig['radius_srvcs'] = "auth";
			$pconfig['radius_acct_port'] = 1813;
		}

		if (!$pconfig['radius_auth_port'] &&
			$pconfig['radius_acct_port']) {
			$pconfig['radius_srvcs'] = "acct";
			$pconfig['radius_auth_port'] = 1812;
		}

	}
	return $pconfig;
}

/* The page's form for a new server. */
function authsrv_new_form() {
	$pconfig = array();
	$pconfig['ldap_protver'] = 3;
	$pconfig['ldap_anon'] = true;
	$pconfig['radius_protocol'] = "MSCHAPv2";
	$pconfig['radius_srvcs'] = "both";
	$pconfig['radius_auth_port'] = "1812";
	$pconfig['radius_acct_port'] = "1813";
	return $pconfig;
}

/* The names in a comma separated list of authentication servers. */
function authsrv_name_list($text) {
	return array_values(array_filter(explode(',', (string)$text), 'strlen'));
}

/*
 * What uses the authentication server named $name: the webConfigurator
 * (User Manager settings), OpenVPN servers, IPsec mobile clients, captive
 * portal zones (authentication and accounting). Empty when unused.
 */
function authsrv_used_by($name) {
	$name = (string)$name;
	$used = array();
	if ((string)config_get_path('system/webgui/authmode', '') === $name) {
		$used[] = gettext('webConfigurator authentication (User Manager settings)');
	}
	foreach ((array)config_get_path('openvpn/openvpn-server', []) as $server) {
		if (is_array($server) && in_array($name, authsrv_name_list($server['authmode'] ?? ''), true)) {
			$used[] = sprintf(gettext('OpenVPN server %s'), htmlspecialchars(!empty($server['description']) ? $server['description'] : ($server['vpnid'] ?? '')));
		}
	}
	if (in_array($name, authsrv_name_list(config_get_path('ipsec/client/user_source', '')), true)) {
		$used[] = gettext('IPsec mobile clients');
	}
	foreach ((array)config_get_path('captiveportal', []) as $zone => $cp) {
		if (!is_array($cp)) {
			continue;
		}
		$servers = array();
		foreach (array('auth_server', 'auth_server2') as $field) {
			foreach (authsrv_name_list($cp[$field] ?? '') as $entry) {
				/* stored as "<type> - <name>" */
				if (strpos($entry, ' - ') !== false) {
					$parts = explode(' - ', $entry);
					array_shift($parts);
					$servers[] = implode(' - ', $parts);
				}
			}
		}
		if (in_array($name, $servers, true) || ((string)($cp['radacct_server'] ?? '') === $name)) {
			$used[] = sprintf(gettext('Captive portal zone %s'), htmlspecialchars(!empty($cp['zone']) ? $cp['zone'] : $zone));
		}
	}
	return $used;
}

/* Only an administrator may change or delete the server the webConfigurator authenticates against. */
function authsrv_webgui_refusal($name, $caller) {
	if (((string)config_get_path('system/webgui/authmode', '') === (string)$name) && !usermgr_is_superuser($caller)) {
		return usermgr_refusal(403, sprintf(gettext('Authentication server %s is the webConfigurator\'s authentication source; only an administrator may change it.'),
		    htmlspecialchars($name)));
	}
	return null;
}

/*
 * Delete the server at position $id of authsrv_list() (the page's act=del).
 * Returns null for an unknown position (the page goes back to the list),
 * otherwise array('deleted' => bool, 'errors' => messages, 'status' => 403/409
 * for a refusal, 'savemsg' => message). The built-in Local Database and
 * servers in use are kept.
 */
function authsrv_delete($id, $caller) {
	$a_server = authsrv_list();
	if (!is_numericint($id) || !$a_server[$id]) {
		return null;
	}

	/* Remove server from main list. */
	$serverdeleted = $a_server[$id]['name'];
	$configured = false;
	foreach (config_get_path('system/authserver', []) as $as) {
		if ($as['name'] == $serverdeleted) {
			$configured = true;
		}
	}
	if (!$configured) {
		return array('deleted' => false, 'status' => 409, 'savemsg' => null,
		    'errors' => array(sprintf(gettext('Authentication server %s is built in and cannot be deleted.'), htmlspecialchars($serverdeleted))));
	}
	$used = authsrv_used_by($serverdeleted);
	if (!empty($used)) {
		return array('deleted' => false, 'status' => 409, 'savemsg' => null,
		    'errors' => array(sprintf(gettext('Authentication server %1$s is in use (%2$s) and cannot be deleted.'), htmlspecialchars($serverdeleted),
		    implode(', ', $used))));
	}
	foreach (config_get_path('system/authserver', []) as $k => $as) {
		if ($as['name'] == $serverdeleted) {
			config_del_path("system/authserver/{$k}");
		}
	}
	config_set_path('system/authserver', array_values(config_get_path('system/authserver', [])));
	if (empty(config_get_path('system/authserver'))) {
		config_del_path('system/authserver');
	}

	$savemsg = sprintf(gettext("Authentication Server %s deleted."), htmlspecialchars($serverdeleted));
	write_config($savemsg);
	return array('deleted' => true, 'errors' => array(), 'status' => null, 'savemsg' => $savemsg);
}

/*
 * Validate and save the server form $post (the page's Save) for the server at
 * position $id (null: a new server) like the page. On success the
 * configuration is written (and the shell authentication updated when the
 * server is the webConfigurator's). Returns the input errors (empty on success).
 */
function authsrv_save(array $post, $id, $caller) {
	$a_server = authsrv_list();
	$input_errors = array();
	$pconfig = $post;
	$reqdfields = array();
	$reqdfieldsn = array();

	/* input validation */

	if ($pconfig['type'] == "ldap") {
		$reqdfields = explode(" ",
			"name type ldap_host ldap_port " .
			"ldap_urltype ldap_protver ldap_scope " .
			"ldap_attr_user ldap_attr_group ldap_attr_member ldapauthcontainers");

		$reqdfieldsn = array(
			gettext("Descriptive name"),
			gettext("Type"),
			gettext("Hostname or IP"),
			gettext("Port value"),
			gettext("Transport"),
			gettext("Protocol version"),
			gettext("Search level"),
			gettext("User naming Attribute"),
			gettext("Group naming Attribute"),
			gettext("Group member attribute"),
			gettext("Authentication container"));

		if (!$pconfig['ldap_anon']) {
			$reqdfields[] = "ldap_binddn";
			$reqdfields[] = "ldap_bindpw";
			$reqdfieldsn[] = gettext("Bind user DN");
			$reqdfieldsn[] = gettext("Bind Password");
		}
	}

	if ($pconfig['type'] == "radius") {
		$reqdfields = explode(" ", "name type radius_protocol radius_host radius_srvcs");
		$reqdfieldsn = array(
			gettext("Descriptive name"),
			gettext("Type"),
			gettext("Radius Protocol"),
			gettext("Hostname or IP"),
			gettext("Services"));

		if ($pconfig['radius_srvcs'] == "both" ||
			$pconfig['radius_srvcs'] == "auth") {
			$reqdfields[] = "radius_auth_port";
			$reqdfieldsn[] = gettext("Authentication port");
		}

		if ($pconfig['radius_srvcs'] == "both" ||
			$pconfig['radius_srvcs'] == "acct") {
			$reqdfields[] = "radius_acct_port";
			$reqdfieldsn[] = gettext("Accounting port");
		}

		if (!isset($id)) {
			$reqdfields[] = "radius_secret";
			$reqdfieldsn[] = gettext("Shared Secret");
		}
	}

	/* The type must be one of the page's choices (another one stored a server with only a name). */
	if (!is_string($pconfig['type']) || !array_key_exists($pconfig['type'], authsrv_types())) {
		$input_errors[] = gettext("A valid authentication server type must be selected.");
	}
	/* Only a configured server can be edited (an unknown position added a server, the built-in Local Database a copy of it). */
	if (isset($id) && (!$a_server[$id] || !config_get_path("system/authserver/{$id}"))) {
		$input_errors[] = gettext("The authentication server to edit does not exist or cannot be changed.");
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (preg_match("/[^a-zA-Z0-9\.\-_]/", $post['host'])) {
		$input_errors[] = gettext("The host name contains invalid characters.");
	}

	if (auth_get_authserver($pconfig['name']) && !isset($id)) {
		$input_errors[] = gettext("An authentication server with the same name already exists.");
	}

	if (isset($id) && config_get_path("system/authserver/{$id}") &&
	   (config_get_path("system/authserver/{$id}/name") != $pconfig['name'])) {
		$input_errors[] = gettext("The name of an authentication server cannot be changed.");
	}

	if (($pconfig['type'] == "ldap") || ($pconfig['type'] == "radius")) {
		$to_field = "{$pconfig['type']}_timeout";
		if (isset($post[$to_field]) && !empty($post[$to_field]) && (!is_numeric($post[$to_field]) || (is_numeric($post[$to_field]) && ($post[$to_field] <= 0)))) {
			$input_errors[] = sprintf(gettext("%s Timeout value must be numeric and positive."), strtoupper($pconfig['type']));
		}
	}

	if (($pconfig['type'] == 'ldap') && config_path_enabled('system/webgui', 'shellauth') &&
	    (config_get_path('system/webgui/authmode') == $pconfig['name']) && empty($pconfig['ldap_pam_groupdn'])) {
		$input_errors[] = gettext("Shell Authentication Group DN must be specified if " .
			"Shell Authentication is enabled for appliance.");
	}

	/* Only an administrator may change the webConfigurator's authentication source. */
	if (isset($id) && $a_server[$id]) {
		$refusal = authsrv_webgui_refusal($a_server[$id]['name'], $caller);
		if ($refusal !== null) {
			$input_errors = array_merge($input_errors, $refusal['errors']);
		}
	}

	if (!$input_errors) {
		$server = array();
		$server['refid'] = uniqid();
		if (isset($id) && $a_server[$id]) {
			$server = $a_server[$id];
		}

		$server['type'] = $pconfig['type'];
		$server['name'] = $pconfig['name'];

		if ($server['type'] == "ldap") {

			if (!empty($pconfig['ldap_caref'])) {
				$server['ldap_caref'] = $pconfig['ldap_caref'];
			}
			$server['host'] = $pconfig['ldap_host'];
			$server['ldap_port'] = $pconfig['ldap_port'];
			$server['ldap_urltype'] = $pconfig['ldap_urltype'];
			$server['ldap_protver'] = $pconfig['ldap_protver'];
			$server['ldap_scope'] = $pconfig['ldap_scope'];
			$server['ldap_basedn'] = $pconfig['ldap_basedn'];
			$server['ldap_authcn'] = $pconfig['ldapauthcontainers'];
			$server['ldap_extended_enabled'] = $pconfig['ldap_extended_enabled'];
			$server['ldap_extended_query'] = $pconfig['ldap_extended_query'];
			$server['ldap_attr_user'] = $pconfig['ldap_attr_user'];
			$server['ldap_attr_group'] = $pconfig['ldap_attr_group'];
			$server['ldap_attr_member'] = $pconfig['ldap_attr_member'];

			$server['ldap_attr_groupobj'] = empty($pconfig['ldap_attr_groupobj']) ? "posixGroup" : $pconfig['ldap_attr_groupobj'];
			$server['ldap_pam_groupdn'] = $pconfig['ldap_pam_groupdn'];

			if ($pconfig['ldap_utf8'] == "yes") {
				$server['ldap_utf8'] = true;
			} else {
				unset($server['ldap_utf8']);
			}
			if ($pconfig['ldap_nostrip_at'] == "yes") {
				$server['ldap_nostrip_at'] = true;
			} else {
				unset($server['ldap_nostrip_at']);
			}
			if ($pconfig['ldap_allow_unauthenticated'] == "yes") {
				$server['ldap_allow_unauthenticated'] = true;
			} else {
				unset($server['ldap_allow_unauthenticated']);
			}
			if ($pconfig['ldap_rfc2307'] == "yes") {
				$server['ldap_rfc2307'] = true;
			} else {
				unset($server['ldap_rfc2307']);
			}
			if ($pconfig['ldap_rfc2307_userdn'] == "yes") {
				$server['ldap_rfc2307_userdn'] = true;
			} else {
				unset($server['ldap_rfc2307_userdn']);
			}
			if ($pconfig['ldap_rfc2307_basedn_groups'] == "yes") {
				$server['ldap_rfc2307_basedn_groups'] = true;
			} else {
				unset($server['ldap_rfc2307_basedn_groups']);
			}


			if (!$pconfig['ldap_anon']) {
				$server['ldap_binddn'] = $pconfig['ldap_binddn'];
				$server['ldap_bindpw'] = $pconfig['ldap_bindpw'];
			} else {
				unset($server['ldap_binddn']);
				unset($server['ldap_bindpw']);
			}

			if ($pconfig['ldap_timeout']) {
				$server['ldap_timeout'] = $pconfig['ldap_timeout'];
			} else {
				$server['ldap_timeout'] = 25;
			}
		}

		if ($server['type'] == "radius") {

			$server['radius_protocol'] = $pconfig['radius_protocol'];
			$server['host'] = $pconfig['radius_host'];
			$server['radius_nasip_attribute'] = $pconfig['radius_nasip_attribute'];

			if ($pconfig['radius_secret']) {
				$server['radius_secret'] = $pconfig['radius_secret'];
			}

			if ($pconfig['disable_radius_msg_auth'] == "yes") {
				$server['disable_radius_msg_auth'] = true;
			} else {
				unset($server['disable_radius_msg_auth']);
			}

			if ($pconfig['radius_timeout']) {
				$server['radius_timeout'] = $pconfig['radius_timeout'];
			} else {
				$server['radius_timeout'] = 5;
			}

			if ($pconfig['radius_srvcs'] == "both") {
				$server['radius_auth_port'] = $pconfig['radius_auth_port'];
				$server['radius_acct_port'] = $pconfig['radius_acct_port'];
			}

			if ($pconfig['radius_srvcs'] == "auth") {
				$server['radius_auth_port'] = $pconfig['radius_auth_port'];
				unset($server['radius_acct_port']);
			}

			if ($pconfig['radius_srvcs'] == "acct") {
				$server['radius_acct_port'] = $pconfig['radius_acct_port'];
				unset($server['radius_auth_port']);
			}
		}

		if (isset($id) && config_get_path("system/authserver/{$id}")) {
			config_set_path("system/authserver/{$id}", $server);
		} else {
			config_set_path('system/authserver/', $server);
		}

		if (config_path_enabled('system/webgui', 'shellauth') &&
		    (config_get_path('system/webgui/authmode') == $pconfig['name'])) {
			set_pam_auth();
		}

		write_config("Authentication Servers settings saved");
	}
	return $input_errors;
}

/* The NAS IP address choices of a RADIUS server: interface or virtual IP address => description. */
function authsrv_radiusnas_list() {
	$list = array();

	$iflist = get_configured_interface_with_descr();
	foreach ($iflist as $ifdesc => $ifdescr) {
		$ipaddr = get_interface_ip($ifdesc);
		if (is_ipaddr($ipaddr)) {
			$list[$ifdesc] = $ifdescr . ' - ' . $ipaddr;
		}
	}

	foreach (config_get_path('virtualip/vip', []) as $sn) {
		if ($sn['mode'] == "proxyarp" && $sn['type'] == "network") {
			$start = ip2long32(gen_subnet($sn['subnet'], $sn['subnet_bits']));
			$end = ip2long32(gen_subnet_max($sn['subnet'], $sn['subnet_bits']));
			$len = $end - $start;

			for ($i = 0; $i <= $len; $i++) {
				$snip = long2ip32($start+$i);
				$list[$snip] = $sn['descr'] . ' - ' . $snip;
			}
		} else {
			$list[$sn['subnet']] = $sn['descr'] . ' - ' . $sn['subnet'];
		}
	}


	return($list);
}

/*
 * Test a user name and password against the server named $name like
 * Diagnostics > Authentication ("Local Database" is the local users).
 * Returns array('authenticated' => bool, 'groups' => the user's groups when
 * authenticated, 'errors' => input errors). The password is never logged.
 */
function authsrv_test($name, $username, $password) {
	$errors = array();
	$authcfg = auth_get_authserver($name);
	if (!$authcfg) {
		$errors[] = sprintf(gettext('%s is not a valid authentication server'), $name);
	}

	if (empty($username)) {
		$errors[] = gettext("A username and password must be specified.");
	}

	if (!empty($errors)) {
		return array('authenticated' => false, 'groups' => array(), 'errors' => $errors);
	}
	$attributes = array();
	if (authenticate_user($username, $password, $authcfg, $attributes)) {
		return array('authenticated' => true, 'groups' => array_values(getUserGroups($username, $authcfg, $attributes)), 'errors' => array());
	}
	return array('authenticated' => false, 'groups' => array(), 'errors' => array());
}
