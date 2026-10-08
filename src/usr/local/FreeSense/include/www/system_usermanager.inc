<?php
/*
 * system_usermanager.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc.
 * Copyright (c) 2005 Paul Taylor <paultaylor@winn-dixie.com>
 * Copyright (c) 2006 Daniel S. Haischt.
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
 * User and group manager logic shared by system_usermanager.php,
 * system_groupmanager.php, system_usermanager_addprivs.php,
 * system_groupmanager_addprivs.php and the REST API, moved out of the pages
 * with the form fields as parameters. Functions are prefixed usermgr_.
 *
 * Users are stored in system/user, groups in system/group (both addressed
 * by position like the pages); every change is applied at once (the
 * operating system accounts and groups are updated and the configuration
 * written), like the pages.
 *
 * Every change takes the user making it ($caller: a user entry, as
 * getUserEntry() returns it) and refuses (usermgr_*_refusal()) what would
 * let it exceed its own privileges. A caller that is not an administrator
 * (uid 0 or "WebCfg - All pages") may only
 *   - grant privileges it holds itself: directly, or through a group a user
 *     is added to or a group's privileges;
 *   - change users and groups whose privileges it holds itself (so it cannot
 *     take over a more privileged account by setting its password or SSH
 *     keys, or remove privileges from it).
 * Nobody may delete or disable the user it is logged in as, delete, disable
 * or rename the system users and groups (admin, admins, all), or remove the
 * last member of the admins group.
 */

require_once("auth.inc");
require_once("certs.inc");
require_once("freesense-utils.inc");
require_once("priv.inc");
require_once("util.inc");

/* ------------------------------------------------------------------ */
/* Privilege checks                                                    */
/* ------------------------------------------------------------------ */

/* The privileges $caller holds (its own and its groups', like the GUI's privilege checks). */
function usermgr_caller_privs($caller) {
	if (!is_array($caller)) {
		return array();
	}
	$privs = get_user_privileges($caller);
	return is_array($privs) ? array_values(array_unique(array_map('strval', $privs))) : array();
}

/* Whether $caller is an administrator (uid 0 or "WebCfg - All pages"): it may grant and change anything. */
function usermgr_is_superuser($caller) {
	if (!is_array($caller)) {
		return false;
	}
	if (isset($caller['uid']) && ((string)$caller['uid'] === '0')) {
		return true;
	}
	return in_array('page-all', usermgr_caller_privs($caller), true);
}

/* The privileges of $privs that $caller does not hold (none for an administrator). */
function usermgr_missing_privs($caller, array $privs) {
	if (usermgr_is_superuser($caller)) {
		return array();
	}
	$held = usermgr_caller_privs($caller);
	$missing = array();
	foreach ($privs as $priv) {
		$priv = (string)$priv;
		if (($priv !== '') && !in_array($priv, $held, true) && !in_array($priv, $missing, true)) {
			$missing[] = $priv;
		}
	}
	return $missing;
}

/* Privilege ids as their names (HTML escaped), for messages. */
function usermgr_priv_names(array $privs) {
	global $priv_list;
	return implode(', ', array_map(function ($priv) use ($priv_list) {
		return htmlspecialchars(isset($priv_list[$priv]['name']) ? $priv_list[$priv]['name'] : $priv);
	}, $privs));
}

/* The privileges of the groups named $names and of the "all" group every user is a member of. */
function usermgr_group_privs(array $names) {
	$names[] = 'all';
	$privs = array();
	foreach (config_get_path('system/group', []) as $group) {
		if (is_array($group) && in_array((string)($group['name'] ?? ''), $names, true) && is_array($group['priv'] ?? null)) {
			$privs = array_merge($privs, $group['priv']);
		}
	}
	return array_values(array_unique(array_map('strval', $privs)));
}

/* The privileges of the local user $user: its own and those of its groups ($groups: group names, default its current groups). */
function usermgr_user_privs($user, $groups = null) {
	if (!is_array($user)) {
		return array();
	}
	if ($groups === null) {
		$groups = local_user_get_groups($user, true);
	}
	$own = is_array($user['priv'] ?? null) ? array_map('strval', $user['priv']) : array();
	return array_values(array_unique(array_merge($own, usermgr_group_privs(array_map('strval', (array)$groups)))));
}

/* A refusal: array('status' => 403 (privileges) or 409 (protected), 'errors' => messages). */
function usermgr_refusal($status, $message) {
	return array('status' => $status, 'errors' => array($message));
}

/* Refused unless $caller holds every privilege in $privs. */
function usermgr_grant_refusal($caller, array $privs) {
	$missing = usermgr_missing_privs($caller, $privs);
	if (empty($missing)) {
		return null;
	}
	return usermgr_refusal(403, sprintf(gettext('Only privileges the current user holds can be granted. Not held: %s.'),
	    usermgr_priv_names($missing)));
}

/* Refused unless $caller holds every privilege of the user $user. */
function usermgr_manage_user_refusal($caller, $user) {
	$missing = usermgr_missing_privs($caller, usermgr_user_privs($user));
	if (empty($missing)) {
		return null;
	}
	return usermgr_refusal(403, sprintf(gettext('User %1$s holds privileges the current user does not hold (%2$s), so the current user cannot change it.'),
	    htmlspecialchars($user['name']), usermgr_priv_names($missing)));
}

/* Refused unless $caller holds every privilege of the group $group. */
function usermgr_manage_group_refusal($caller, $group) {
	$missing = usermgr_missing_privs($caller, is_array($group['priv'] ?? null) ? $group['priv'] : array());
	if (empty($missing)) {
		return null;
	}
	return usermgr_refusal(403, sprintf(gettext('Group %1$s grants privileges the current user does not hold (%2$s), so the current user cannot change it.'),
	    htmlspecialchars($group['name']), usermgr_priv_names($missing)));
}

/* The uids of the members of the admins group. */
function usermgr_admin_members() {
	$admin_group = getGroupEntry(g_get('admin_group'));
	return is_array($admin_group['item']['member'] ?? null) ? array_map('strval', $admin_group['item']['member']) : array();
}

/* Refused when removing the user with uid $uid from the admins group would leave the group without members. */
function usermgr_last_admin_refusal($uid) {
	$members = usermgr_admin_members();
	if (in_array((string)$uid, $members, true) && (count($members) <= 1)) {
		return usermgr_refusal(409, sprintf(gettext('The last member of the %s group cannot be removed from it.'), g_get('admin_group')));
	}
	return null;
}

/* The user name of $caller ('' when unknown). */
function usermgr_caller_name($caller) {
	return is_array($caller) ? (string)($caller['name'] ?? '') : '';
}

/* ------------------------------------------------------------------ */
/* Users (system_usermanager.php)                                      */
/* ------------------------------------------------------------------ */

/* The user edit form of the user $this_user, as the page fills it. */
function usermgr_user_form($this_user) {
	$pconfig = array();
	$pconfig['usernamefld'] = $this_user['name'];
	$pconfig['descr'] = $this_user['descr'];
	$pconfig['expires'] = $this_user['expires'];
	$pconfig['customsettings'] = isset($this_user['customsettings']);
	$pconfig['webguicss'] = $this_user['webguicss'];
	$pconfig['webguifixedmenu'] = $this_user['webguifixedmenu'];
	$pconfig['webguihostnamemenu'] = $this_user['webguihostnamemenu'];
	$pconfig['dashboardcolumns'] = $this_user['dashboardcolumns'];
	$pconfig['interfacessort'] = isset($this_user['interfacessort']);
	$pconfig['dashboardavailablewidgetspanel'] = isset($this_user['dashboardavailablewidgetspanel']);
	$pconfig['systemlogsfilterpanel'] = isset($this_user['systemlogsfilterpanel']);
	$pconfig['systemlogsmanagelogpanel'] = isset($this_user['systemlogsmanagelogpanel']);
	$pconfig['statusmonitoringsettingspanel'] = isset($this_user['statusmonitoringsettingspanel']);
	$pconfig['webguileftcolumnhyper'] = isset($this_user['webguileftcolumnhyper']);
	$pconfig['disablealiaspopupdetail'] = isset($this_user['disablealiaspopupdetail']);
	$pconfig['pagenamefirst'] = isset($this_user['pagenamefirst']);
	$pconfig['groups'] = local_user_get_groups($this_user);
	$pconfig['utype'] = $this_user['scope'];
	$pconfig['uid'] = $this_user['uid'];
	$pconfig['authorizedkeys'] = base64_decode($this_user['authorizedkeys']);
	$pconfig['priv'] = $this_user['priv'];
	$pconfig['ipsecpsk'] = $this_user['ipsecpsk'];
	$pconfig['disabled'] = isset($this_user['disabled']);
	$pconfig['keephistory'] = isset($this_user['keephistory']);
	return $pconfig;
}

/* The internal CAs (with a private key) a new user's certificate can be signed by: refid => name. */
function usermgr_user_cert_cas() {
	$nonPrvCas = array();
	foreach (config_get_path('ca', []) as $ca) {
		if (!$ca['prv']) {
			continue;
		}

		$nonPrvCas[ $ca['refid'] ] = $ca['descr'];
	}
	return $nonPrvCas;
}

/* The security refusal of deleting the user $user by $caller (null when allowed; the self check is the caller's). */
function usermgr_user_delete_refusal($user, $caller) {
	if ($user['scope'] == "system") {
		return usermgr_refusal(409, sprintf(gettext("Cannot delete user %s because it is a system user."), $user['name']));
	}
	if ($user['name'] == usermgr_caller_name($caller)) {
		return usermgr_refusal(409, sprintf(gettext("Cannot delete user %s because you are currently logged in as that user."), $user['name']));
	}
	$refusal = usermgr_manage_user_refusal($caller, $user);
	if ($refusal !== null) {
		return $refusal;
	}
	return usermgr_last_admin_refusal($user['uid']);
}

/* Remove the user at position $userid (OS account, API keys) from the configuration; the caller reindexes and writes. */
function usermgr_user_remove($userid) {
	$user = config_get_path("system/user/{$userid}");
	local_user_del($user);
	/* Its REST API keys go with it (they would work again for a new user with the same name). */
	local_user_revoke_api_keys($user['name']);
	config_del_path("system/user/{$userid}");
}

/*
 * Delete the user at position $id (the page's act=deluser; $username must be
 * its name). Returns null for an unknown user or name (the page goes back to
 * the list), otherwise array('deleted' => bool, 'errors' => messages,
 * 'status' => 403/409 for a refusal, 'savemsg' => message).
 */
function usermgr_user_delete($id, $username, $caller) {
	if (!isset($username) || !isset($id) || (config_get_path("system/user/{$id}") === null) || ($username != config_get_path("system/user/{$id}/name"))) {
		return null;
	}

	$delete_errors = array();
	$status = null;
	$savemsg = null;
	$deleted = false;
	if ($username == usermgr_caller_name($caller)) {
		$refusal = usermgr_refusal(409, sprintf(gettext("Cannot delete user %s because you are currently logged in as that user."), $username));
	} else {
		/* Like the bulk delete: system users are kept (single deletes removed them), and the caller's privileges apply. */
		$refusal = usermgr_user_delete_refusal(config_get_path("system/user/{$id}"), $caller);
	}
	if ($refusal !== null) {
		$delete_errors = $refusal['errors'];
		$status = $refusal['status'];
	} else {
		$userdeleted = config_get_path("system/user/{$id}/name");
		usermgr_user_remove($id);
		/* Reindex the array to avoid operating on an incorrect index upstream issue 7733 */
		config_set_path('system/user', array_values(config_get_path('system/user', [])));
		$savemsg = localize_text("Successfully deleted user: %s", $userdeleted);
		write_config($savemsg);
		logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
		$deleted = true;
	}
	return array('deleted' => $deleted, 'errors' => $delete_errors, 'status' => $status, 'savemsg' => $savemsg);
}

/*
 * Delete the users at the positions $del_users (the page's Delete button).
 * Returns array('deleted' => names, 'errors' => messages, 'savemsg' => message or null).
 */
function usermgr_users_delete($del_users, $caller) {
	$deleted_users = array();
	$delete_errors = array();
	$savemsg = null;

	if (!empty($del_users)) {
		foreach ($del_users as $userid) {
			$tmp_user = config_get_path("system/user/{$userid}", []);
			if (!is_numericint($userid) || empty($tmp_user)) {
				continue;
			}
			$refusal = usermgr_user_delete_refusal($tmp_user, $caller);
			if ($refusal !== null) {
				$delete_errors = array_merge($delete_errors, $refusal['errors']);
			} else {
				$deleted_users[] = $tmp_user['name'];
				usermgr_user_remove($userid);
			}
		}

		if (count($deleted_users) > 0) {
			$savemsg = localize_text("Successfully deleted %s: %s", (count($deleted_users) == 1) ? gettext("user") : gettext("users"), implode(', ', $deleted_users));
			/* Reindex the array to avoid operating on an incorrect index upstream issue 7733 */
			config_set_path('system/user', array_values(config_get_path('system/user', [])));
			write_config($savemsg);
			logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
		}
	}
	return array('deleted' => $deleted_users, 'errors' => $delete_errors, 'savemsg' => $savemsg);
}

/*
 * Remove the certificate association $certid (position in the user's list)
 * from the user at position $id (the page's act=delcert; the certificate is
 * kept). Returns null for an unknown user, otherwise
 * array('errors' => messages, 'status' => 403 for a refusal, 'savemsg' => message).
 */
function usermgr_user_cert_remove($id, $certid, $caller) {
	if (!isset($id) || !config_get_path("system/user/{$id}")) {
		return null;
	}

	$refusal = usermgr_manage_user_refusal($caller, config_get_path("system/user/{$id}"));
	if ($refusal !== null) {
		return array('errors' => $refusal['errors'], 'status' => $refusal['status'], 'savemsg' => null);
	}
	$certdeleted = lookup_cert(config_get_path("system/user/{$id}/cert/{$certid}"));
	$certdeleted = $certdeleted['item']['descr'];
	$savemsg = localize_text("Removed certificate association \"%s\" from user %s", $certdeleted, config_get_path("system/user/{$id}/name"));
	config_del_path("system/user/{$id}/cert/{$certid}");
	write_config($savemsg);
	logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	return array('errors' => array(), 'status' => null, 'savemsg' => $savemsg);
}

/*
 * Remove the privilege $privid (position in the user's own list) from the
 * user at position $id (the page's act=delprivid). Returns
 * array('errors' => messages, 'status' => 403 for a refusal, 'savemsg' => message).
 */
function usermgr_user_priv_remove($id, $privid, $caller) {
	global $priv_list;

	$user = config_get_path("system/user/{$id}");
	if (is_array($user)) {
		$refusal = usermgr_manage_user_refusal($caller, $user);
		if ($refusal !== null) {
			return array('errors' => $refusal['errors'], 'status' => $refusal['status'], 'savemsg' => null);
		}
	}
	$privdeleted = array_get_path($priv_list, (config_get_path("system/user/{$id}/priv/{$privid}") . '/name'));
	config_del_path("system/user/{$id}/priv/{$privid}");
	local_user_set(config_get_path("system/user/{$id}"));
	$savemsg = localize_text("Removed Privilege \"%s\" from user %s", $privdeleted, config_get_path("system/user/{$id}/name"));
	write_config($savemsg);
	logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	return array('errors' => array(), 'status' => null, 'savemsg' => $savemsg);
}

/*
 * The security refusal of the user form $post (a save of the user at
 * position $id, null for a new user) by $caller, or null when allowed: the
 * caller must hold the privileges of the user (before and after the save,
 * through its groups); system users cannot be renamed or disabled, nor can
 * the caller's own user be disabled; the last member of the admins group
 * stays in it.
 */
function usermgr_user_save_refusal(array $post, $id, $caller) {
	$user = (isset($id) && is_array(config_get_path("system/user/{$id}"))) ? config_get_path("system/user/{$id}") : null;
	$groups = array();
	foreach ((array)($post['groups'] ?? array()) as $group) {
		if (is_string($group) && !empty(getGroupEntry($group))) {
			$groups[] = $group;
		}
	}

	if ($user !== null) {
		$refusal = usermgr_manage_user_refusal($caller, $user);
		if ($refusal !== null) {
			return $refusal;
		}
		if (($user['scope'] == 'system') && isset($post['usernamefld']) && ($post['usernamefld'] !== $user['name'])) {
			return usermgr_refusal(409, sprintf(gettext('System user %s cannot be renamed.'), $user['name']));
		}
		if (!empty($post['disabled'])) {
			if ($user['scope'] == 'system') {
				return usermgr_refusal(409, sprintf(gettext('System user %s cannot be disabled.'), $user['name']));
			}
			if ($user['name'] === usermgr_caller_name($caller)) {
				return usermgr_refusal(409, sprintf(gettext('Cannot disable user %s because you are currently logged in as that user.'), $user['name']));
			}
		}
		if (!in_array(g_get('admin_group'), $groups, true)) {
			$refusal = usermgr_last_admin_refusal($user['uid']);
			if ($refusal !== null) {
				return $refusal;
			}
		}
	}

	return usermgr_grant_refusal($caller, usermgr_user_privs(($user !== null) ? $user : array(), $groups));
}

/*
 * Validate and save the user form $post (the page's Save) for the user at
 * position $id (null: a new user) like the page: password (hashed; also
 * revokes the user's REST API keys, see local_user_set_password()), group
 * memberships, an optional user certificate for a new user (createcert),
 * GUI settings, SSH keys and IPsec key. The OS account is updated and the
 * configuration written at once. $pconfig gets the form to re-render on
 * errors, $savemsg the message. Returns the input errors (empty on success).
 */
function usermgr_user_save(array $post, $id, $caller, &$pconfig = null, &$savemsg = null) {
	global $userindex;

	$input_errors = [];
	$pconfig = $post;

	/* input validation */
	if (isset($id) && config_get_path("system/user/{$id}")) {
		$reqdfields = explode(" ", "usernamefld");
		$reqdfieldsn = array(gettext("Username"));
	} else {
		if ($post['createcert'] != "yes") {
			$reqdfields = explode(" ", "usernamefld passwordfld1");
			$reqdfieldsn = array(
				gettext("Username"),
				gettext("Password"));
		} else {
			$reqdfields = explode(" ", "usernamefld passwordfld1 name caref keylen lifetime");
			$reqdfieldsn = array(
				gettext("Username"),
				gettext("Password"),
				gettext("Descriptive name"),
				gettext("Certificate authority"),
				gettext("Key length"),
				gettext("Lifetime"));
		}
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (preg_match("/[^a-zA-Z0-9\.\-_]/", $post['usernamefld'])) {
		$input_errors[] = gettext("The username contains invalid characters.");
	}

	if (strlen($post['usernamefld']) > 32) {
		$input_errors[] = gettext("The username is longer than 32 characters.");
	}

	if (($post['passwordfld1']) && ($post['passwordfld1'] != $post['passwordfld2'])) {
		$input_errors[] = gettext("The passwords do not match.");
	}

	if (isset($post['ipsecpsk']) && !preg_match('/^[[:ascii:]]*$/', $post['ipsecpsk'])) {
		$input_errors[] = gettext("IPsec Pre-Shared Key contains invalid characters.");
	}

	$input_errors = array_merge($input_errors, validate_password($post['usernamefld'], $post['passwordfld1']));

	/* Check the POSTed groups to ensure they are valid and exist */
	if (is_array($post['groups'])) {
		foreach ($post['groups'] as $newgroup) {
			if (empty(getGroupEntry($newgroup))) {
				$input_errors[] = gettext("One or more invalid groups was submitted.");
			}
		}
	}

	$oldusername = (isset($id)) ? config_get_path("system/user/{$id}/name", '') : '';
	/* make sure this user name is unique */
	if (!$input_errors) {
		foreach (config_get_path('system/user', []) as $userent) {
			if ($userent['name'] == $post['usernamefld'] && $oldusername != $post['usernamefld']) {
				$input_errors[] = gettext("Another entry with the same username already exists.");
				break;
			}
		}
	}
	/* also make sure it is not reserved */
	if (!$input_errors) {
		$system_users = explode("\n", file_get_contents("/etc/passwd"));
		foreach ($system_users as $s_user) {
			$ent = explode(":", $s_user);
			if ($ent[0] == $post['usernamefld'] && $oldusername != $post['usernamefld']) {
				$input_errors[] = gettext("That username is reserved by the system.");
				break;
			}
		}
	}

	/*
	 * Check for a valid expiration date if one is set at all (valid means,
	 * DateTime puts out a time stamp so any DateTime compatible time
	 * format may be used. to keep it simple for the enduser, we only
	 * claim to accept MM/DD/YYYY as inputs. Advanced users may use inputs
	 * like "+1 day", which will be converted to MM/DD/YYYY based on "now".
	 * Otherwise such an entry would lead to an invalid expiration data.
	 */
	if ($post['expires']) {
		try {
			$expdate = new DateTime($post['expires']);
			//convert from any DateTime compatible date to MM/DD/YYYY
			$post['expires'] = $expdate->format("m/d/Y");
		} catch (Exception $ex) {
			$input_errors[] = gettext("Invalid expiration date format; use MM/DD/YYYY instead.");
		}
	}

	if ($post['createcert'] == "yes") {
		$ca = lookup_ca($post['caref']);
		$ca = $ca['item'];
		if (!$ca) {
			$input_errors[] = gettext("Invalid internal Certificate Authority") . "\n";
		}
	}
	validate_webguicss_field($input_errors, $post['webguicss']);
	validate_webguifixedmenu_field($input_errors, $post['webguifixedmenu']);
	validate_webguihostnamemenu_field($input_errors, $post['webguihostnamemenu']);
	validate_dashboardcolumns_field($input_errors, $post['dashboardcolumns']);

	/* No privilege escalation; system users and the admins group are protected. */
	$refusal = usermgr_user_save_refusal($post, $id, $caller);
	if ($refusal !== null) {
		$input_errors = array_merge($input_errors, $refusal['errors']);
	}

	if (!$input_errors) {
		if (isset($id) && config_get_path("system/user/{$id}")) {
			$user_item_config = [
				'idx' => $id,
				'item' => config_get_path("system/user/{$id}")
			];
		} else {
			$user_item_config = ['idx' => null, 'item' => null];
		}
		$userent = &$user_item_config['item'];

		/* The scope of an existing user is kept (the form's hidden field could change it); a new user is a "user". */
		if (!isset($userent['scope'])) {
			$userent['scope'] = "user";
		}
		$stored_name = isset($userent['name']) ? (string)$userent['name'] : '';

		/* the user name was modified */
		if (!empty($post['oldusername']) && ($post['usernamefld'] <> $post['oldusername'])) {
			$_SERVER['REMOTE_USER'] = $post['usernamefld'];
			local_user_del($userent);
		}

		/* the user password was modified */
		if ($post['passwordfld1']) {
			local_user_set_password($user_item_config, $post['passwordfld1']);
			phpsession_begin();
			/* invalidate cache, see #16720/16728 */
			if ($post['usernamefld'] === $_SESSION['Username']) {
				unset($_SESSION['insecure_user']);
			} else if ($post['usernamefld'] === 'admin') {
				unset($_SESSION['insecure_admin']);
			}
			phpsession_end(true);
		}

		/* only change description if sent */
		if (isset($post['descr'])) {
			$userent['descr'] = $post['descr'];
		}

		$userent['name'] = $post['usernamefld'];
		$userent['expires'] = $post['expires'];
		$userent['dashboardcolumns'] = $post['dashboardcolumns'];
		$userent['authorizedkeys'] = base64_encode($post['authorizedkeys']);
		$userent['ipsecpsk'] = $post['ipsecpsk'];

		if ($post['disabled']) {
			$userent['disabled'] = true;
		} else {
			unset($userent['disabled']);
		}

		if ($post['customsettings']) {
			$userent['customsettings'] = true;
		} else {
			unset($userent['customsettings']);
		}

		if ($post['webguicss']) {
			$userent['webguicss'] = $post['webguicss'];
		} else {
			unset($userent['webguicss']);
		}

		if ($post['webguifixedmenu']) {
			$userent['webguifixedmenu'] = $post['webguifixedmenu'];
		} else {
			unset($userent['webguifixedmenu']);
		}

		if ($post['webguihostnamemenu']) {
			$userent['webguihostnamemenu'] = $post['webguihostnamemenu'];
		} else {
			unset($userent['webguihostnamemenu']);
		}

		if ($post['interfacessort']) {
			$userent['interfacessort'] = true;
		} else {
			unset($userent['interfacessort']);
		}

		if ($post['dashboardavailablewidgetspanel']) {
			$userent['dashboardavailablewidgetspanel'] = true;
		} else {
			unset($userent['dashboardavailablewidgetspanel']);
		}

		if ($post['systemlogsfilterpanel']) {
			$userent['systemlogsfilterpanel'] = true;
		} else {
			unset($userent['systemlogsfilterpanel']);
		}

		if ($post['systemlogsmanagelogpanel']) {
			$userent['systemlogsmanagelogpanel'] = true;
		} else {
			unset($userent['systemlogsmanagelogpanel']);
		}

		if ($post['statusmonitoringsettingspanel']) {
			$userent['statusmonitoringsettingspanel'] = true;
		} else {
			unset($userent['statusmonitoringsettingspanel']);
		}

		if ($post['webguileftcolumnhyper']) {
			$userent['webguileftcolumnhyper'] = true;
		} else {
			unset($userent['webguileftcolumnhyper']);
		}

		if ($post['disablealiaspopupdetail']) {
			$userent['disablealiaspopupdetail'] = true;
		} else {
			unset($userent['disablealiaspopupdetail']);
		}

		if ($post['pagenamefirst']) {
			$userent['pagenamefirst'] = true;
		} else {
			unset($userent['pagenamefirst']);
		}

		if ($post['keephistory']) {
			$userent['keephistory'] = true;
		} else {
			unset($userent['keephistory']);
		}

		if (isset($id) && config_get_path("system/user/{$id}")) {
			/* A renamed user keeps its REST API keys. */
			if (($stored_name !== '') && ($stored_name !== $userent['name'])) {
				local_user_rename_api_keys($stored_name, $userent['name']);
			}
			config_set_path("system/user/{$id}", $userent);
		} else {
			/* Keys left behind by a deleted user of the same name never work for a new user. */
			local_user_revoke_api_keys($userent['name']);
			if ($post['createcert'] == "yes") {
				$cert = array();
				$cert['refid'] = uniqid();
				$userent['cert'] = array();

				$cert['descr'] = $post['name'];

				$subject = cert_get_subject_hash($ca['crt']);

				$dn = array();
				if (!empty($subject['C'])) {
					$dn['countryName'] = $subject['C'];
				}
				if (!empty($subject['ST'])) {
					$dn['stateOrProvinceName'] = $subject['ST'];
				}
				if (!empty($subject['L'])) {
					$dn['localityName'] = $subject['L'];
				}
				if (!empty($subject['O'])) {
					$dn['organizationName'] = $subject['O'];
				}
				if (!empty($subject['OU'])) {
					$dn['organizationalUnitName'] = $subject['OU'];
				}
				$dn['commonName'] = $userent['name'];
				$cn_altname = cert_add_altname_type($userent['name']);
				if (!empty($cn_altname)) {
					$dn['subjectAltName'] = $cn_altname;
				}

				cert_create($cert, $post['caref'], $post['keylen'],
					(int)$post['lifetime'], $dn, 'user',
					$post['digest_alg'], $post['keytype'],
					$post['ecname']);

				config_set_path('cert/', $cert);
				$userent['cert'][] = $cert['refid'];
			}
			$nextuid_config = config_get_path('system/nextuid');
			$userent['uid'] = $nextuid_config++;
			config_set_path('system/nextuid', $nextuid_config);
			/* Add the user to All Users group. */
			$group_config = config_get_path('system/group', []);
			foreach ($group_config as $gidx => &$group) {
				if ($group['name'] == "all") {
					if (!is_array($group['member'])) {
						$group['member'] = [];
					}
					$group['member'][] = $userent['uid'];
					break;
				}
			}
			unset($group);
			config_set_path('system/group', $group_config);

			config_set_path('system/user/', $userent);
		}

		/* Sort it alphabetically */
		$user_config = config_get_path('system/user', []);
		usort($user_config, function($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		config_set_path('system/user', $user_config);

		local_user_set_groups($userent, $post['groups']);
		local_user_set($userent);

		/* Update user index to account for new changes */
		$userindex = index_users();

		$savemsg = localize_text("Successfully %s user %s", (isset($id)) ? gettext("edited") : gettext("created"), $userent['name']);
		write_config($savemsg);
		logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
		if (is_dir("/etc/inc/privhooks")) {
			run_plugins("/etc/inc/privhooks");
		}

		if ($userent['uid'] == 0) {
			logger(LOG_NOTICE, localize_text("Restarting sshd due to admin account change."), LOG_PREFIX_AUTHPROVIDER_LOCAL);
			send_event("service restart sshd");
		}
	}
	return $input_errors;
}

/* The user page's Effective Privileges table of the user at position $id. */
function usermgr_user_priv_table($id, $read_only) {
	$privhtml = '<div class="table-responsive">';
	$privhtml .=	'<table class="table table-striped table-hover table-sm">';
	$privhtml .=		'<thead>';
	$privhtml .=			'<tr>';
	$privhtml .=				'<th>' . gettext('Inherited from') . '</th>';
	$privhtml .=				'<th>' . gettext('Name') . '</th>';
	$privhtml .=				'<th>' . gettext('Description') . '</th>';
	$privhtml .=				'<th>' . gettext('Action') . '</th>';
	$privhtml .=			'</tr>';
	$privhtml .=		'</thead>';
	$privhtml .=		'<tbody>';

	$i = 0;
	$user_has_root_priv = false;

	$user_privs = (is_numericint($id)) ? get_user_privdesc(config_get_path("system/user/{$id}", [])) : [];
	foreach ($user_privs as $priv) {
		$group = false;
		if ($priv['group']) {
			$group = $priv['group'];
		}

		$privhtml .=		'<tr>';
		$privhtml .=			'<td>' . htmlspecialchars($priv['group']) . '</td>';
		$privhtml .=			'<td>' . htmlspecialchars($priv['name']) . '</td>';
		$privhtml .=			'<td>' . htmlspecialchars($priv['descr']);
		if (isset($priv['warn']) && ($priv['warn'] == 'standard-warning-root')) {
			$privhtml .=			' ' . gettext('(admin privilege)');
			$user_has_root_priv = true;
		}
		$privhtml .=			'</td>';
		$privhtml .=			'<td>';
		if (!$group && !$read_only) {
			$privhtml .=			'<a class="fa-solid fa-trash-can no-confirm icon-pointer" title="' . gettext('Delete Privilege') . '" id="delprivid' . $i . '"></a>';
		}

		$privhtml .=			'</td>';
		$privhtml .=		'</tr>';

		if (!$group) {
			$i++;
		}
	}

	if ($user_has_root_priv) {
		$privhtml .=		'<tr>';
		$privhtml .=			'<td colspan="3">';
		$privhtml .=				'<b>' . gettext('Security notice: This user effectively has administrator-level access') . '</b>';
		$privhtml .=			'</td>';
		$privhtml .=			'<td>';
		$privhtml .=			'</td>';
		$privhtml .=		'</tr>';

	}

	$privhtml .=		'</tbody>';
	$privhtml .=	'</table>';
	$privhtml .= '</div>';

	$privhtml .= '<nav class="action-buttons">';
	if (!$read_only) {
		$privhtml .=	'<a href="system_usermanager_addprivs.php?userid=' . $id . '" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i>' . gettext("Add privilege") . '</a>';
	}
	$privhtml .= '</nav>';

	return($privhtml);
}

/* The user page's User Certificates table of the user at position $id. */
function usermgr_user_cert_table($id, $read_only) {
	$certhtml = '<div class="table-responsive">';
	$certhtml .=	'<table class="table table-striped table-hover table-sm">';
	$certhtml .=		'<thead>';
	$certhtml .=			'<tr>';
	$certhtml .=				'<th>' . gettext('Name') . '</th>';
	$certhtml .=				'<th>' . gettext('CA') . '</th>';
	$certhtml .=				'<th></th>';
	$certhtml .=			'</tr>';
	$certhtml .=		'</thead>';
	$certhtml .=		'<tbody>';

	$i = 0;
	$user_certs = (is_numericint($id)) ? config_get_path("system/user/{$id}/cert", []) : [];
	foreach ($user_certs as $certref) {
		$cert = lookup_cert($certref);
		$cert = $cert['item'];
		$ca = lookup_ca($cert['caref']);
		$ca = $ca['item'];
		$revokedstr =	is_cert_revoked($cert) ? '<b> Revoked</b>':'';

		$certhtml .=	'<tr>';
		$certhtml .=		'<td>' . htmlspecialchars($cert['descr']) . $revokedstr . '</td>';
		$certhtml .=		'<td>' . htmlspecialchars($ca['descr']) . '</td>';
		$certhtml .=		'<td>';
		if (!$read_only) {
			$certhtml .=			'<a id="delcert' . $i .'" class="fa-solid fa-trash-can no-confirm icon-pointer" title="';
			$certhtml .=			gettext('Remove this certificate association? (Certificate will not be deleted)') . '"></a>';
		}
		$certhtml .=		'</td>';
		$certhtml .=	'</tr>';
		$i++;
	}

	$certhtml .=		'</tbody>';
	$certhtml .=	'</table>';
	$certhtml .= '</div>';

	$certhtml .= '<nav class="action-buttons">';
	if (!$read_only && is_numericint($id)) {
		$certhtml .=	'<a href="system_certmanager.php?act=new&amp;userid=' . $id . '" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i>' . gettext("Add certificate") . '</a>';
	}
	$certhtml .= '</nav>';

	return($certhtml);
}

/* ------------------------------------------------------------------ */
/* Groups (system_groupmanager.php)                                    */
/* ------------------------------------------------------------------ */

/* The page's group name order (case-insensitive). */
function usermgr_group_cmp($a, $b) {
	return strcasecmp($a['name'], $b['name']);
}

function usermgr_groups_sort() {
	$group_config = config_get_path('system/group');

	if (!is_array($group_config)) {
		return;
	}

	usort($group_config, "usermgr_group_cmp");
	config_set_path("system/group", $group_config);
}

/*
 * The members of the group with gid 1998 are never changed by the group
 * form (the page shows no membership for it: pfSense's "all" group; in
 * FreeSense's default configuration it is the admins group).
 */
function usermgr_group_members_fixed($group) {
	return isset($group['gid']) && ($group['gid'] == 1998);
}

/* The group edit form of the group at position $id ($dup: copying it), as the page fills it. */
function usermgr_group_form($id, $dup) {
	$pconfig = array();
	$this_group = config_get_path("system/group/{$id}");
	if ($dup === null) {
		$pconfig['name'] = $this_group['name'];
		$pconfig['gid'] = $this_group['gid'];
		$pconfig['gtype'] = empty($this_group['scope'])
		    ? "local" : $this_group['scope'];
	} else {
		$pconfig['gtype'] = ($this_group['scope'] == 'system')
		    ? "local" : $this_group['scope'];
	}
	$pconfig['priv'] = $this_group['priv'];
	$pconfig['description'] = $this_group['description'];
	$pconfig['members'] = $this_group['member'];
	return $pconfig;
}

/* What uses the group named $name (it cannot be deleted while in use). */
function usermgr_group_used_by($name) {
	$used = array();
	if (in_array((string)$name, explode(',', (string)config_get_path('ipsec/client/auth_groups', '')), true)) {
		$used[] = gettext('IPsec mobile client group authentication');
	}
	return $used;
}

/* The security refusal of deleting the group $group by $caller (null when allowed). */
function usermgr_group_delete_refusal($group, $caller) {
	if ($group['scope'] == "system") {
		return usermgr_refusal(409, sprintf(gettext('Cannot delete group %s because it is a system group.'), htmlspecialchars($group['name'])));
	}
	$used = usermgr_group_used_by($group['name']);
	if (!empty($used)) {
		return usermgr_refusal(409, sprintf(gettext('Group %1$s is in use (%2$s) and cannot be deleted.'), htmlspecialchars($group['name']),
		    implode(', ', $used)));
	}
	return usermgr_manage_group_refusal($caller, $group);
}

/* Remove the group at position $groupid from the configuration and the OS, and refresh its members' accounts (privileges such as shell access). */
function usermgr_group_remove($groupid) {
	$group = config_get_path("system/group/{$groupid}");
	local_group_del($group);
	config_del_path("system/group/{$groupid}");
	foreach ((array)($group['member'] ?? array()) as $uid) {
		$user = getUserEntryByUID($uid);
		if (!empty($user['item'])) {
			local_user_set($user['item']);
		}
	}
}

/*
 * Delete the group at position $id (the page's act=delgroup; $groupname must
 * be its name). Returns null for an unknown group or name (the page goes back
 * to the list), otherwise array('deleted' => bool, 'errors' => messages,
 * 'status' => 403/409 for a refusal, 'savemsg' => message).
 */
function usermgr_group_delete($id, $groupname, $caller) {
	if (!isset($id) || !isset($groupname) ||
	    (config_get_path("system/group/{$id}") === null) ||
	    ($groupname != config_get_path("system/group/{$id}/name"))) {
		return null;
	}

	$refusal = usermgr_group_delete_refusal(config_get_path("system/group/{$id}"), $caller);
	if ($refusal !== null) {
		return array('deleted' => false, 'errors' => $refusal['errors'], 'status' => $refusal['status'], 'savemsg' => null);
	}
	$groupdeleted = config_get_path("system/group/{$id}/name");
	usermgr_group_remove($id);
	/*
	 * Reindex the array to avoid operating on an incorrect index
	 * upstream issue 7733
	 */
	config_set_path("system/group", array_values(config_get_path('system/group', [])));

	$savemsg = localize_text("Successfully deleted group: %s",
	    $groupdeleted);
	write_config($savemsg);
	logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	return array('deleted' => true, 'errors' => array(), 'status' => null, 'savemsg' => $savemsg);
}

/*
 * Delete the groups at the positions $del_groups (the page's bulk delete;
 * system groups are skipped like before). Returns
 * array('deleted' => names, 'errors' => messages, 'savemsg' => message or null).
 */
function usermgr_groups_delete($del_groups, $caller) {
	$deleted_groups = array();
	$delete_errors = array();
	$savemsg = null;

	if (!empty($del_groups)) {
		foreach ($del_groups as $groupid) {
			$this_group = config_get_path("system/group/{$groupid}");
			if (isset($this_group) &&
			    $this_group['scope'] != "system") {
				$refusal = usermgr_group_delete_refusal($this_group, $caller);
				if ($refusal !== null) {
					$delete_errors = array_merge($delete_errors, $refusal['errors']);
					continue;
				}
				$deleted_groups[] = $this_group['name'];
				usermgr_group_remove($groupid);
			}
		}

		$savemsg = localize_text("Successfully deleted %s: %s",
		    (count($deleted_groups) == 1)
		    ? gettext("group") : gettext("groups"),
		    implode(', ', $deleted_groups));
		/*
		 * Reindex the array to avoid operating on an incorrect index
		 * upstream issue 7733
		 */
		config_set_path("system/group", array_values(config_get_path('system/group', [])));
		write_config($savemsg);
		logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	}
	return array('deleted' => $deleted_groups, 'errors' => $delete_errors, 'savemsg' => $savemsg);
}

/*
 * Remove the privilege $privid (position in the group's list) from the group
 * at position $id (the page's act=delpriv) and refresh its members. Returns
 * null for an unknown group, otherwise array('errors' => messages,
 * 'status' => 403 for a refusal, 'savemsg' => message).
 */
function usermgr_group_priv_remove($id, $privid, $caller) {
	global $priv_list;

	if (!isset($id) || (config_get_path("system/group/{$id}") === null)) {
		return null;
	}

	$refusal = usermgr_manage_group_refusal($caller, config_get_path("system/group/{$id}"));
	if ($refusal !== null) {
		return array('errors' => $refusal['errors'], 'status' => $refusal['status'], 'savemsg' => null);
	}
	$privdeleted = array_get_path($priv_list, (config_get_path("system/group/{$id}/priv/{$privid}") . "/name"));
	config_del_path("system/group/{$id}/priv/{$privid}");

	foreach (config_get_path("system/group/{$id}/member", []) as $uid) {
		$user = getUserEntryByUID($uid);
		$user = $user['item'];
		if ($user) {
			local_user_set($user);
		}
	}

	$savemsg = localize_text("Removed Privilege \"%s\" from group %s",
	    $privdeleted, config_get_path("system/group/{$id}/name"));
	write_config($savemsg);
	logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	return array('errors' => array(), 'status' => null, 'savemsg' => $savemsg);
}

/*
 * The security refusal of the group form $post (a save of the group at
 * position $id, null for a new group, $post['dup']: the position of a group
 * whose privileges a new group copies) by $caller, or null when allowed: the
 * caller must hold the group's privileges; system groups keep their name and
 * scope; the admins group keeps at least one member.
 */
function usermgr_group_save_refusal(array $post, $id, $caller) {
	$group = (isset($id) && is_array(config_get_path("system/group/{$id}"))) ? config_get_path("system/group/{$id}") : null;
	if ($group !== null) {
		$refusal = usermgr_manage_group_refusal($caller, $group);
		if ($refusal !== null) {
			return $refusal;
		}
		if ($group['scope'] == 'system') {
			if ($post['groupname'] !== $group['name']) {
				return usermgr_refusal(409, sprintf(gettext('System group %s cannot be renamed.'), htmlspecialchars($group['name'])));
			}
		}
		if (($group['name'] == g_get('admin_group')) && !usermgr_group_members_fixed($group) && empty($post['members'])) {
			return usermgr_refusal(409, sprintf(gettext('The last member of the %s group cannot be removed from it.'), g_get('admin_group')));
		}
		return null;
	}
	if (isset($post['dup']) && is_numericint($post['dup'])) {
		$privs = config_get_path("system/group/{$post['dup']}/priv", []);
		return usermgr_grant_refusal($caller, is_array($privs) ? $privs : array());
	}
	return null;
}

/*
 * Validate and save the group form $post (the page's Save) for the group at
 * position $id (null: a new group, optionally a copy of the group at position
 * $post['dup']) like the page: name, description, scope, members (OS group
 * and the members' accounts updated at once). $pconfig gets the form to
 * re-render on errors, $savemsg the message. Returns the input errors
 * (empty on success).
 */
function usermgr_group_save(array $post, $id, $caller, &$pconfig = null, &$savemsg = null) {
	$input_errors = array();
	$pconfig = $post;

	/* input validation */
	$reqdfields = explode(" ", "groupname");
	$reqdfieldsn = array(gettext("Group Name"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	$existing = (isset($id) && config_get_path("system/group/{$id}")) ? config_get_path("system/group/{$id}") : null;
	/* The scope must be the form's choice (local or remote); a system group keeps its scope. */
	if (($existing !== null) && ($existing['scope'] == 'system')) {
		$post['gtype'] = 'system';
	} elseif (!in_array($post['gtype'], array('local', 'remote'), true)) {
		$input_errors[] = gettext("The group scope must be local or remote.");
	}

	if ($post['gtype'] != "remote") {
		if (preg_match("/[^a-zA-Z0-9\.\-_]/", $post['groupname'])) {
			$input_errors[] = sprintf(gettext(
			    "The (%s) group name contains invalid characters."),
			    $post['gtype']);
		}
		if (strlen($post['groupname']) > 16) {
			$input_errors[] = gettext(
			    "The group name is longer than 16 characters.");
		}
	} else {
		if (preg_match("/[^a-zA-Z0-9\.\- _]/", $post['groupname'])) {
			$input_errors[] = sprintf(gettext(
			    "The (%s) group name contains invalid characters."),
			    $post['gtype']);
		}
	}

	/* Check the POSTed members to ensure they are valid and exist */
	if (is_array($post['members'])) {
		foreach ($post['members'] as $newmember) {
			if (!is_numeric($newmember) ||
			    empty(getUserEntryByUID($newmember))) {
				$input_errors[] = gettext("One or more " .
				    "invalid group members was submitted.");
			}
		}
	}

	if (!$input_errors && !(isset($id) && config_get_path("system/group/{$id}"))) {
		/* make sure there are no dupes */
		foreach (config_get_path('system/group', []) as $group) {
			if ($group['name'] == $post['groupname']) {
				$input_errors[] = gettext("Another entry " .
				    "with the same group name already exists.");
				break;
			}
		}
	}

	/* No privilege escalation; system groups and the admins group are protected. */
	$refusal = usermgr_group_save_refusal($post, $id, $caller);
	if ($refusal !== null) {
		$input_errors = array_merge($input_errors, $refusal['errors']);
	}

	if (!$input_errors) {
		$group = array();
		if (isset($id) && config_get_path("system/group/{$id}")) {
			$group = config_get_path("system/group/{$id}");
		}

		$group['name'] = $post['groupname'];
		$group['description'] = $post['description'];
		$group['scope'] = $post['gtype'];

		/* The form shows no membership for the group with gid 1998: keep its members (they were all removed). */
		if (usermgr_group_members_fixed($group)) {
			/* unchanged */
		} else if (empty($post['members'])) {
			unset($group['member']);
		} else {
			$group['member'] = $post['members'];
		}

		if (isset($id) && config_get_path("system/group/{$id}")) {
			config_set_path("system/group/{$id}", $group);
		} else {
			$nextgid = config_get_path('system/nextgid');
			$group['gid'] = $nextgid++;
			config_set_path('system/nextgid', $nextgid);
			/* A copy gets the privileges of the copied group (the first group, position 0, was copied without them). */
			if (isset($post['dup']) && is_numericint($post['dup'])) {
				$group['priv'] = config_get_path("system/group/{$post['dup']}/priv");
			}
			config_set_path('system/group/', $group);
		}

		usermgr_groups_sort();

		local_group_set($group);

		/*
		 * Refresh users in this group since their privileges may have
		 * changed.
		 */
		if (is_array($group['member'])) {
			foreach (config_get_path('system/user', []) as $idx => $user) {
				if (in_array($user['uid'], $group['member'])) {
					local_user_set($user);
					config_set_path("system/user/{$idx}", $user);
				}
			}
		}

		/* Sort it alphabetically */
		$group_config = config_get_path('system/group', []);
		usort($group_config, function($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		config_set_path('system/group', $group_config);

		$savemsg = localize_text("Successfully %s group %s",
		    (strlen($id) > 0) ? gettext("edited") : gettext("created"),
		    $group['name']);
		write_config($savemsg);
		logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	}
	return $input_errors;
}

/* The group page's Assigned Privileges table of the group at position $id ($dup: the page copies a group). */
function usermgr_group_priv_table($id, $read_only, $dup) {
	$privhtml = '<div class="table-responsive">';
	$privhtml .=	'<table class="table table-striped table-hover table-sm">';
	$privhtml .=		'<thead>';
	$privhtml .=			'<tr>';
	$privhtml .=				'<th>' . gettext('Name') . '</th>';
	$privhtml .=				'<th>' . gettext('Description') . '</th>';
	$privhtml .=				'<th>' . gettext('Action') . '</th>';
	$privhtml .=			'</tr>';
	$privhtml .=		'</thead>';
	$privhtml .=		'<tbody>';

	$user_has_root_priv = false;

	if (isset($id)) {
		foreach (get_user_privdesc(config_get_path("system/group/{$id}")) as $i => $priv) {
			$privhtml .=		'<tr>';
			$privhtml .=			'<td>' . htmlspecialchars($priv['name']) . '</td>';
			$privhtml .=			'<td>' . htmlspecialchars($priv['descr']);
			if (isset($priv['warn']) && ($priv['warn'] == 'standard-warning-root')) {
				$privhtml .=			' ' . gettext('(admin privilege)');
				$user_has_root_priv = true;
			}
			$privhtml .=			'</td>';
			if (!$read_only && ($dup === null)) {
				$privhtml .=			'<td><a class="fa-solid fa-trash-can" title="' . gettext('Delete Privilege') . '"	href="system_groupmanager.php?act=delpriv&amp;groupid=' . $id . '&amp;privid=' . $i . '" usepost></a></td>';
			}
			$privhtml .=		'</tr>';
		}
	}

	if ($user_has_root_priv) {
		$privhtml .=		'<tr>';
		$privhtml .=			'<td colspan="2">';
		$privhtml .=				'<b>' . gettext('Security notice: Users in this group effectively have administrator-level access') . '</b>';
		$privhtml .=			'</td>';
		$privhtml .=			'<td>';
		$privhtml .=			'</td>';
		$privhtml .=		'</tr>';

	}

	$privhtml .=		'</tbody>';
	$privhtml .=	'</table>';
	$privhtml .= '</div>';

	$privhtml .= '<nav class="action-buttons">';
	if (!$read_only && ($dup === null)) {
		$privhtml .=	'<a href="system_groupmanager_addprivs.php?groupid=' . $id . '" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i>' . gettext("Add privilege") . '</a>';
	}
	$privhtml .= '</nav>';

	return($privhtml);
}

/* ------------------------------------------------------------------ */
/* Privileges (system_usermanager_addprivs.php, system_groupmanager_addprivs.php) */
/* ------------------------------------------------------------------ */

/* Every privilege, sorted by name (the add privilege pages' list). */
function usermgr_priv_list_sorted() {
	global $priv_list;
	$spriv_list = $priv_list;
	uasort($spriv_list, "compare_by_name");
	return $spriv_list;
}

/* The privileges that can still be added to an entry holding $assigned: id => name. */
function usermgr_priv_choices(array $spriv_list, $assigned) {
	$list = array();

	foreach ($spriv_list as $pname => $pdata) {
		if (in_array($pname, (array)$assigned)) {
			continue;
		}

		$list[$pname] = $pdata['name'];
	}

	return($list);
}

/* The names of the privileges that give administrator-level access, each after a line break. */
function usermgr_root_priv_text() {
	global $priv_list;

	$priv_text = "";

	foreach ($priv_list as $pdata) {
		if (isset($pdata['warn']) && ($pdata['warn'] == 'standard-warning-root')) {
			$priv_text .= '<br/>' . $pdata['name'];
		}
	}

	return($priv_text);
}

/* Unknown privilege ids of $privs (they cannot be added). */
function usermgr_unknown_privs(array $privs) {
	global $priv_list;
	$unknown = array();
	foreach ($privs as $priv) {
		if (!is_string($priv) || !isset($priv_list[$priv])) {
			$unknown[] = is_scalar($priv) ? (string)$priv : '?';
		}
	}
	return $unknown;
}

/* The security refusal of adding the privileges $privs to the user at position $userid by $caller (null when allowed). */
function usermgr_user_privs_add_refusal($userid, array $privs, $caller) {
	$user = config_get_path("system/user/{$userid}");
	if (is_array($user)) {
		$refusal = usermgr_manage_user_refusal($caller, $user);
		if ($refusal !== null) {
			return $refusal;
		}
	}
	return usermgr_grant_refusal($caller, $privs);
}

/*
 * Add the privileges $post['sysprivs'] to the user at position $userid (the
 * add privileges page's Save; the user must exist). Returns the input errors
 * (empty on success).
 */
function usermgr_user_privs_add($userid, array $post, $caller) {
	$input_errors = array();
	$a_user = config_get_path("system/user/{$userid}");
	if (!is_array($a_user['priv'])) {
		$a_user['priv'] = array();
	}
	$pconfig = $post;

	/* input validation */
	$reqdfields = explode(" ", "sysprivs");
	$reqdfieldsn = array(gettext("Selected privileges"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!$input_errors) {
		if (!is_array($pconfig['sysprivs'])) {
			$pconfig['sysprivs'] = array();
		}
		/* Only known privileges can be added. */
		$unknown = usermgr_unknown_privs($pconfig['sysprivs']);
		if (!empty($unknown)) {
			$input_errors[] = sprintf(gettext('Unknown privileges: %s.'), htmlspecialchars(implode(', ', $unknown)));
		}
		$refusal = usermgr_user_privs_add_refusal($userid, $pconfig['sysprivs'], $caller);
		if ($refusal !== null) {
			$input_errors = array_merge($input_errors, $refusal['errors']);
		}
	}

	if (!$input_errors) {

		if (!count($a_user['priv'])) {
			$a_user['priv'] = $pconfig['sysprivs'];
		} else {
			$a_user['priv'] = array_merge($a_user['priv'], $pconfig['sysprivs']);
		}

		$a_user['priv'] = sort_user_privs($a_user['priv']);
		config_set_path("system/user/{$userid}", $a_user);
		local_user_set($a_user);

		$savemsg = localize_text("Privileges changed for user: %s", $a_user['name']);
		write_config($savemsg);
		logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	}
	return $input_errors;
}

/* The security refusal of adding the privileges $privs to the group at position $groupid by $caller (null when allowed). */
function usermgr_group_privs_add_refusal($groupid, array $privs, $caller) {
	$group = config_get_path("system/group/{$groupid}");
	if (is_array($group)) {
		$refusal = usermgr_manage_group_refusal($caller, $group);
		if ($refusal !== null) {
			return $refusal;
		}
	}
	return usermgr_grant_refusal($caller, $privs);
}

/*
 * Add the privileges $post['sysprivs'] to the group at position $groupid (the
 * add privileges page's Save; the group must exist) and refresh its members.
 * Returns the input errors (empty on success).
 */
function usermgr_group_privs_add($groupid, array $post, $caller) {
	$input_errors = array();
	$pconfig = $post;

	/* input validation */
	$reqdfields = explode(" ", "sysprivs");
	$reqdfieldsn = array(gettext("Selected privileges"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!$input_errors) {
		if (!is_array($pconfig['sysprivs'])) {
			$pconfig['sysprivs'] = array();
		}
		/* Only known privileges can be added. */
		$unknown = usermgr_unknown_privs($pconfig['sysprivs']);
		if (!empty($unknown)) {
			$input_errors[] = sprintf(gettext('Unknown privileges: %s.'), htmlspecialchars(implode(', ', $unknown)));
		}
		$refusal = usermgr_group_privs_add_refusal($groupid, $pconfig['sysprivs'], $caller);
		if ($refusal !== null) {
			$input_errors = array_merge($input_errors, $refusal['errors']);
		}
	}

	if (!$input_errors) {

		if (!count(config_get_path("system/group/{$groupid}/priv", []))) {
			config_set_path("system/group/{$groupid}/priv", $pconfig['sysprivs']);
		} else {
			config_set_path("system/group/{$groupid}/priv", array_merge(config_get_path("system/group/{$groupid}/priv", []), $pconfig['sysprivs']));
		}

		foreach (config_get_path("system/group/{$groupid}/member", []) as $uid) {
			$user = getUserEntryByUID($uid);
			$user = $user['item'];
			if ($user) {
				local_user_set($user);
			}
		}

		$savemsg = localize_text("Privileges changed for group: %s", config_get_path("system/group/{$groupid}/name"));
		write_config($savemsg);
		logger(LOG_WARNING, $savemsg, LOG_PREFIX_AUTHPROVIDER_LOCAL);
	}
	return $input_errors;
}
