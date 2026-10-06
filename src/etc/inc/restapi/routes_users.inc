<?php
/*
 * restapi/routes_users.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
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
 * REST API v1 routes for the user manager (area users): local users
 * (/v1/users), groups (/v1/groups), their privileges, group members, user
 * certificates, authentication servers (/v1/auth-servers) and the privilege
 * catalogue (/v1/privileges). Users, groups and servers are addressed by
 * name.
 *
 * Writes go through the functions the pages call (system_usermanager.inc,
 * system_authservers.inc) and are applied at once like the pages (accounts,
 * groups and the configuration); there is nothing to stage.
 *
 * Security (the same checks as the pages; see system_usermanager.inc): the
 * key's user may only grant privileges it holds itself - directly, through a
 * group a user joins or a group's privileges - and only change users and
 * groups whose privileges it holds, unless it is an administrator (uid 0 or
 * "WebCfg - All pages"); refusals are 403 privilege_escalation. Deleting or
 * disabling the key's own user, deleting, disabling or renaming system users
 * and groups and removing the last member of the admins group are 409
 * protected; deleting a group or authentication server in use is 409
 * in_use.
 *
 * Secrets are write-only: password hashes and passwords are never returned
 * (a user's "password" reads as "(set)"), nor are SSH authorized keys (their
 * count and SHA256 fingerprints only), the IPsec pre-shared key, the LDAP
 * bind password or the RADIUS shared secret ("(set)"). Sending "(set)" or
 * leaving a secret out keeps it. A changed password, a deleted or disabled
 * user end the user's REST API keys (revoked, or refused at authentication).
 *
 * The front controller loads system_usermanager.inc and
 * system_authservers.inc at file scope.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_firewall.inc');
require_once('restapi/routes_services.inc');
require_once('restapi/routes_vpn.inc');
require_once('restapi/routes_openvpn.inc');
require_once('restapi/routes_pki.inc');

function restapi_routes_users() {
	$route = function ($method, $path, $handler, $page, $summary, $write = false, $body = null, $area = 'users') {
		$opts = array('page' => $page, 'area' => $area, 'summary' => $summary);
		if ($write) {
			$opts['write'] = true;
			$opts['body'] = $body;
		}
		return restapi_route($method, $path, $handler, $opts);
	};

	$user_body = array('type' => 'object', 'description' => 'A user as its page\'s form: name, password (write-only; required for a ' .
	    'new user, "(set)" or leaving it out keeps it; the user\'s REST API keys keep working after a password change), descr (full name), expires ' .
	    '(MM/DD/YYYY), disabled, groups (list of group names), authorizedkeys (SSH keys, write-only; "(set)" or leaving it out keeps ' .
	    'them), ipsecpsk (write-only), keephistory, customsettings, webguicss, webguifixedmenu, webguihostnamemenu, dashboardcolumns, ' .
	    'interfacessort, dashboardavailablewidgetspanel, systemlogsfilterpanel, systemlogsmanagelogpanel, statusmonitoringsettingspanel, ' .
	    'webguileftcolumnhyper, disablealiaspopupdetail, pagenamefirst (booleans). Omitted fields keep their value (a new user: the ' .
	    'page\'s defaults). Only privileges the key\'s user holds can be granted through groups. Applied at once.');
	$privs_body = array('type' => 'object', 'description' => 'privileges: the complete list of assigned privilege ids (see ' .
	    'GET /api/v1/privileges); privileges not listed are removed. Only privileges the key\'s user holds can be added. Applied at once.');
	$cert_body = array('type' => 'object', 'description' => 'method existing: certref (refid of a certificate to associate); or a new ' .
	    'certificate for the user as POST /api/v1/pki/certificates takes it (method internal, import, external, sign, with their ' .
	    'fields). Applied at once.');
	$group_body = array('type' => 'object', 'description' => 'A group as its page\'s form: name, description, scope (local or remote; ' .
	    'a system group keeps its scope and name), members (list of user names; the group with gid 1998 shows no membership on its page ' .
	    'and keeps its members). Omitted fields keep their value. Applied at once.');
	$members_body = array('type' => 'object', 'description' => 'members: the complete list of member user names. Applied at once.');
	$server_body = array('type' => 'object', 'description' => 'An authentication server as its page\'s form: name, type (ldap or ' .
	    'radius; fixed once created). LDAP: ldap_host, ldap_port, ldap_urltype, ldap_caref, ldap_protver, ldap_timeout, ldap_scope, ' .
	    'ldap_basedn, ldap_authcn (containers separated by ";"), ldap_extended_enabled, ldap_extended_query, ldap_anon, ldap_binddn, ' .
	    'ldap_bindpw (write-only), ldap_attr_user, ldap_attr_group, ldap_attr_member, ldap_attr_groupobj, ldap_pam_groupdn, ldap_utf8, ' .
	    'ldap_nostrip_at, ldap_allow_unauthenticated, ldap_rfc2307, ldap_rfc2307_userdn, ldap_rfc2307_basedn_groups. RADIUS: ' .
	    'radius_protocol, radius_host, radius_secret (write-only), disable_radius_msg_auth, radius_srvcs (both, auth, acct), ' .
	    'radius_auth_port, radius_acct_port, radius_timeout, radius_nasip_attribute. Omitted fields keep their value (a new server: ' .
	    'the page\'s defaults with the OpenLDAP template). Applied at once.');
	$test_body = array('type' => 'object', 'description' => 'username and password to authenticate against the server, like ' .
	    'Diagnostics > Authentication (nothing is stored or logged).');

	$u = '/v1/users';
	$g = '/v1/groups';
	$s = '/v1/auth-servers';
	$um = 'system_usermanager.php';
	$gm = 'system_groupmanager.php';
	$as = 'system_authservers.php';
	return array(
		$route('GET', $u, 'restapi_h_users_list', $um, 'List the local users (groups, assigned privileges, certificates; never a password)'),
		$route('GET', "{$u}/{name}", 'restapi_h_users_get', $um,
		    'Get a local user with its form fields (secrets masked, SSH keys as fingerprints)'),
		$route('POST', $u, 'restapi_h_users_create', $um, 'Create a local user (applied at once)', true, $user_body),
		$route('PUT', "{$u}/{name}", 'restapi_h_users_update', $um,
		    'Update a local user (applied at once; omitted fields keep their value)', true, $user_body),
		$route('DELETE', "{$u}/{name}", 'restapi_h_users_delete', $um,
		    'Delete a local user and its REST API keys (409 for system users and the key\'s own user)', true),
		$route('GET', "{$u}/{name}/privileges", 'restapi_h_users_privs_get', $um,
		    'The privileges of a user: assigned, and effective (with the groups they come from)'),
		$route('PUT', "{$u}/{name}/privileges", 'restapi_h_users_privs_set', $um,
		    'Set the assigned privileges of a user (applied at once)', true, $privs_body),
		$route('DELETE', "{$u}/{name}/api-keys", 'restapi_h_users_keys_revoke', $um,
		    'Revoke all REST API keys of a user (e.g. when the account may be compromised)', true),
		$route('GET', "{$u}/{name}/certificates", 'restapi_h_users_certs_list', $um, 'The certificates associated with a user'),
		$route('POST', "{$u}/{name}/certificates", 'restapi_h_users_certs_add', 'system_certmanager.php',
		    'Create a certificate for a user, or associate an existing one (applied at once)', true, $cert_body, 'pki'),
		$route('DELETE', "{$u}/{name}/certificates/{refid}", 'restapi_h_users_certs_remove', $um,
		    'Remove a certificate association from a user (the certificate is kept)', true),
		$route('GET', $g, 'restapi_h_groups_list', $gm, 'List the groups (members, privileges)'),
		$route('GET', "{$g}/{name}", 'restapi_h_groups_get', $gm, 'Get a group with its form fields'),
		$route('POST', $g, 'restapi_h_groups_create', $gm, 'Create a group (applied at once)', true, $group_body),
		$route('PUT', "{$g}/{name}", 'restapi_h_groups_update', $gm,
		    'Update a group (applied at once; omitted fields keep their value)', true, $group_body),
		$route('DELETE', "{$g}/{name}", 'restapi_h_groups_delete', $gm,
		    'Delete a group (409 for system groups and groups in use)', true),
		$route('GET', "{$g}/{name}/privileges", 'restapi_h_groups_privs_get', $gm, 'The privileges assigned to a group'),
		$route('PUT', "{$g}/{name}/privileges", 'restapi_h_groups_privs_set', $gm,
		    'Set the privileges of a group (applied at once; members are updated)', true, $privs_body),
		$route('GET', "{$g}/{name}/members", 'restapi_h_groups_members_get', $gm, 'The members of a group'),
		$route('PUT', "{$g}/{name}/members", 'restapi_h_groups_members_set', $gm,
		    'Set the members of a group (applied at once)', true, $members_body),
		$route('GET', $s, 'restapi_h_authsrv_list', $as, 'List the authentication servers (and the built-in Local Database)'),
		$route('GET', "{$s}/{name}", 'restapi_h_authsrv_get', $as,
		    'Get an authentication server with its form fields (bind password and shared secret masked)'),
		$route('POST', $s, 'restapi_h_authsrv_create', $as, 'Create an LDAP or RADIUS authentication server (applied at once)', true,
		    $server_body),
		$route('PUT', "{$s}/{name}", 'restapi_h_authsrv_update', $as,
		    'Update an authentication server (applied at once; omitted fields keep their value)', true, $server_body),
		$route('DELETE', "{$s}/{name}", 'restapi_h_authsrv_delete', $as,
		    'Delete an authentication server (409 while in use or for the Local Database)', true),
		$route('POST', "{$s}/{name}/test", 'restapi_h_authsrv_test', 'diag_authentication.php',
		    'Test a user name and password against an authentication server (nothing is stored)', true, $test_body),
		$route('GET', '/v1/privileges', 'restapi_h_privileges', $um,
		    'The privilege catalogue (id, name, description, warning) for assigning privileges'),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* Whether a stored user has a password (the only place the API reads a password hash). */
function restapi_users_has_password(array $user) {
	return !empty($user['bcrypt-hash']) || !empty($user['sha512-hash']) || !empty($user['password']);
}

/* The SSH authorized keys text as a list of {type, fingerprint} (SHA256, like ssh-keygen -l); comments are left out. */
function restapi_users_key_fingerprints($text) {
	$out = array();
	foreach (preg_split('/\r?\n/', (string)$text) as $line) {
		$line = trim($line);
		if (($line === '') || ($line[0] === '#')) {
			continue;
		}
		$fields = preg_split('/\s+/', $line);
		/* options may precede the key type */
		foreach ($fields as $i => $field) {
			if (preg_match('/^(ssh-|ecdsa-|sk-)/', $field) && isset($fields[$i + 1])) {
				$blob = base64_decode($fields[$i + 1], true);
				$out[] = array('type' => $field, 'fingerprint' => ($blob === false) ? '(invalid)' :
				    'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='));
				continue 2;
			}
		}
		$out[] = array('type' => '(unknown)', 'fingerprint' => '(invalid)');
	}
	return $out;
}

/* The field types of a user (API names; see restapi_users_post()). */
function restapi_users_types() {
	return restapi_ovpn_typemap(array('uid', 'scope', 'privileges', 'certificates', 'api_keys', 'authorizedkeys_count',
	    'authorizedkeys_fingerprints', 'effective_admin'),
	    array('disabled', 'customsettings', 'interfacessort', 'dashboardavailablewidgetspanel', 'systemlogsfilterpanel',
	    'systemlogsmanagelogpanel', 'statusmonitoringsettingspanel', 'webguileftcolumnhyper', 'disablealiaspopupdetail', 'pagenamefirst',
	    'keephistory'),
	    array('groups'),
	    array('name', 'password', 'descr', 'expires', 'webguicss', 'webguifixedmenu', 'webguihostnamemenu', 'dashboardcolumns',
	    'authorizedkeys', 'ipsecpsk'));
}

/*
 * A user's form values in API shape: $p is the page's form
 * (usermgr_user_form(), or array() for a new user), the selects as the page
 * preselects them ($css: the theme choices). Secrets in clear: the output
 * builder masks them.
 */
function restapi_users_values(array $p, array $css) {
	$out = array('name' => (string)($p['usernamefld'] ?? ''), 'password' => '', 'descr' => (string)($p['descr'] ?? ''),
	    'expires' => (string)($p['expires'] ?? ''));
	foreach (array('disabled', 'customsettings', 'interfacessort', 'dashboardavailablewidgetspanel', 'systemlogsfilterpanel',
	    'systemlogsmanagelogpanel', 'statusmonitoringsettingspanel', 'webguileftcolumnhyper', 'disablealiaspopupdetail', 'pagenamefirst',
	    'keephistory') as $flag) {
		$out[$flag] = !empty($p[$flag]);
	}
	/* gen_webguicss_field(), gen_dashboardcolumns_field() and the two menu selects */
	$out['webguicss'] = (is_scalar($p['webguicss'] ?? null) && isset($css[$p['webguicss']])) ? (string)$p['webguicss'] : 'FreeSense.css';
	$out['webguifixedmenu'] = restapi_ovpn_select($p['webguifixedmenu'] ?? null, array('' => '', 'fixed' => ''));
	$out['webguihostnamemenu'] = restapi_ovpn_select($p['webguihostnamemenu'] ?? null, array('' => '', 'hostonly' => '', 'fqdn' => ''));
	$cols = (int)($p['dashboardcolumns'] ?? 0);
	$out['dashboardcolumns'] = (($cols < 1) || ($cols > 6)) ? '2' : (string)$p['dashboardcolumns'];
	$out['groups'] = array_values(array_map('strval', (array)($p['groups'] ?? array())));
	$out['authorizedkeys'] = is_string($p['authorizedkeys'] ?? null) ? $p['authorizedkeys'] : '';
	$out['ipsecpsk'] = is_scalar($p['ipsecpsk'] ?? null) ? (string)$p['ipsecpsk'] : '';
	return $out;
}

/*
 * A user body merged over the current values as the page's post: name and
 * password become usernamefld and passwordfld1/2 (the password only when
 * sent), checkboxes "yes". "(set)" keeps a secret.
 */
function restapi_users_post(array $body, array $current) {
	foreach (array('password', 'authorizedkeys', 'ipsecpsk') as $secret) {
		$body = restapi_vpn_secret_body($body, $secret);
	}
	$types = restapi_users_types();
	$values = restapi_svc_merge($body, $current, $types);
	$post = restapi_svc_post($values, $types);
	$post['usernamefld'] = $post['name'];
	$password = array_key_exists('password', $body) ? (string)$values['password'] : '';
	$post['passwordfld1'] = $password;
	$post['passwordfld2'] = $password;
	unset($post['name'], $post['password']);
	$post['save'] = 'Save';
	return $post;
}

/* The field types of a group. */
function restapi_groups_types() {
	return restapi_ovpn_typemap(array('gid', 'privileges', 'member_count'), array(), array('members'), array('name', 'description', 'scope'));
}

/* A list of privilege ids from a body field; 400 when malformed. */
function restapi_users_priv_list($value) {
	if (!is_array($value) || !array_is_list($value)) {
		throw new RestApiError(400, 'invalid_field', 'Field "privileges" must be a list of privilege ids.');
	}
	foreach ($value as $priv) {
		if (!is_string($priv) || ($priv === '')) {
			throw new RestApiError(400, 'invalid_field', 'Field "privileges" must be a list of privilege ids.');
		}
	}
	return array_values(array_unique($value));
}

/* Only the listed fields in $body; 400 otherwise. */
function restapi_users_only(array $body, array $fields) {
	foreach (array_keys($body) as $key) {
		if (!in_array($key, $fields, true)) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
	}
}

/* The field types of an authentication server (ldap_authcn is the form's ldapauthcontainers). */
function restapi_authsrv_types($new) {
	$ro = array('refid', 'in_use', 'used_by', 'builtin');
	$strings = array('ldap_host', 'ldap_port', 'ldap_urltype', 'ldap_caref', 'ldap_protver', 'ldap_timeout', 'ldap_scope', 'ldap_basedn',
	    'ldap_authcn', 'ldap_extended_query', 'ldap_binddn', 'ldap_bindpw', 'ldap_attr_user', 'ldap_attr_group', 'ldap_attr_member',
	    'ldap_attr_groupobj', 'ldap_pam_groupdn', 'radius_protocol', 'radius_host', 'radius_secret', 'radius_srvcs', 'radius_auth_port',
	    'radius_acct_port', 'radius_timeout', 'radius_nasip_attribute', 'name');
	if ($new) {
		$strings[] = 'type';
	} else {
		$ro[] = 'type';
	}
	return restapi_ovpn_typemap($ro, array('ldap_extended_enabled', 'ldap_anon', 'ldap_utf8', 'ldap_nostrip_at', 'ldap_allow_unauthenticated',
	    'ldap_rfc2307', 'ldap_rfc2307_userdn', 'ldap_rfc2307_basedn_groups', 'disable_radius_msg_auth'), array(), $strings);
}

/*
 * A server's form (authsrv_form() or authsrv_new_form()) in API shape for the
 * choices $c (field => options, preselected like the page). For a new server
 * the page's script fills the port (389) and the attributes of the first
 * template (OpenLDAP); $template is it. Secrets in clear: mask before returning.
 */
function restapi_authsrv_values(array $p, array $c, $new, array $template = array()) {
	$types = restapi_authsrv_types(true);
	$out = array();
	foreach ($types as $field => $type) {
		$value = $p[$field] ?? null;
		if ($type === 'bool') {
			$out[$field] = !empty($value);
		} elseif (array_key_exists($field, $c)) {
			$out[$field] = restapi_ovpn_select($value, $c[$field]);
		} else {
			$out[$field] = is_scalar($value) ? (string)$value : '';
		}
	}
	if ($new) {
		if ($out['ldap_port'] === '') {
			$out['ldap_port'] = ($out['ldap_urltype'] === 'SSL/TLS Encrypted') ? '636' : '389';
		}
		$out['ldap_attr_user'] = (string)($template['attr_user'] ?? $out['ldap_attr_user']);
		$out['ldap_attr_group'] = (string)($template['attr_group'] ?? $out['ldap_attr_group']);
		$out['ldap_attr_member'] = (string)($template['attr_member'] ?? $out['ldap_attr_member']);
		$out['ldap_allow_unauthenticated'] = (($template['allow_unauthenticated'] ?? '') === 'true');
	}
	return $out;
}

/* $fields with the LDAP bind password and RADIUS secret read as "(set)". */
function restapi_authsrv_mask(array $fields) {
	foreach (array('ldap_bindpw', 'radius_secret') as $secret) {
		if (array_key_exists($secret, $fields)) {
			$fields[$secret] = restapi_svc_mask(is_scalar($fields[$secret]) ? $fields[$secret] : '');
		}
	}
	return $fields;
}

/* Server values as the page's post (ldap_authcn is posted as ldapauthcontainers). */
function restapi_authsrv_post(array $values, array $types) {
	$post = restapi_svc_post($values, $types);
	$post['ldapauthcontainers'] = $post['ldap_authcn'] ?? '';
	unset($post['ldap_authcn']);
	$post['save'] = 'Save';
	return $post;
}

/* A refusal of the shared functions as an API error. */
function restapi_users_refusal_error(array $refusal) {
	$e = restapi_validation_error($refusal['errors']);
	$status = (int)$refusal['status'];
	return new RestApiError($status, ($status === 403) ? 'privilege_escalation' : 'protected', $e->details['messages'][0],
	    array('messages' => $e->details['messages']));
}

/* ------------------------------------------------------------------ */
/* Users                                                               */
/* ------------------------------------------------------------------ */

/* The position of the local user named $name, or a 404. */
function restapi_users_id($name) {
	foreach (config_get_path('system/user', []) as $idx => $user) {
		if (is_array($user) && (($user['name'] ?? null) === (string)$name)) {
			return (int)$idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No local user with that name.');
}

/* The number of REST API keys of the user named $name. */
function restapi_users_key_count($name) {
	$n = 0;
	foreach ((array)config_get_path('restapi/apitoken', []) as $token) {
		if (is_array($token) && (($token['username'] ?? null) === (string)$name)) {
			$n++;
		}
	}
	return $n;
}

/* Whether privileges $privs include one that gives administrator-level access. */
function restapi_users_admin_equivalent(array $privs) {
	global $priv_list;
	foreach ($privs as $priv) {
		if (($priv_list[$priv]['warn'] ?? '') === 'standard-warning-root') {
			return true;
		}
	}
	return false;
}

/* A user in API shape: summary, or (full) also its form fields. Fields are copied one by one; secrets never leave. */
function restapi_users_out(array $user, $full) {
	$form = usermgr_user_form($user);
	$values = restapi_users_values($form, get_css_files());
	$out = array(
		'name' => (string)$user['name'],
		'descr' => $values['descr'],
		'uid' => (int)($user['uid'] ?? 0),
		'scope' => (string)($user['scope'] ?? ''),
		'disabled' => $values['disabled'],
		'expires' => $values['expires'],
		'groups' => $values['groups'],
		'privileges' => array_values(array_map('strval', is_array($user['priv'] ?? null) ? $user['priv'] : array())),
		'certificates' => array_values(array_map('strval', is_array($user['cert'] ?? null) ? $user['cert'] : array())),
		'password' => restapi_users_has_password($user) ? '(set)' : '',
		'ipsecpsk' => restapi_svc_mask($values['ipsecpsk']),
		'authorizedkeys_count' => count(restapi_users_key_fingerprints($values['authorizedkeys'])),
		'api_keys' => restapi_users_key_count($user['name']),
		'effective_admin' => restapi_users_admin_equivalent(usermgr_user_privs($user)),
	);
	if (!$full) {
		return $out;
	}
	foreach (array('customsettings', 'webguicss', 'webguifixedmenu', 'webguihostnamemenu', 'dashboardcolumns', 'interfacessort',
	    'dashboardavailablewidgetspanel', 'systemlogsfilterpanel', 'systemlogsmanagelogpanel', 'statusmonitoringsettingspanel',
	    'webguileftcolumnhyper', 'disablealiaspopupdetail', 'pagenamefirst', 'keephistory') as $field) {
		$out[$field] = $values[$field];
	}
	$out['authorizedkeys_fingerprints'] = restapi_users_key_fingerprints($values['authorizedkeys']);
	return $out;
}

function restapi_h_users_list($req) {
	$out = array();
	foreach (config_get_path('system/user', []) as $user) {
		if (is_array($user) && isset($user['name'])) {
			$out[] = restapi_users_out($user, false);
		}
	}
	return array('data' => $out);
}

function restapi_h_users_get($req) {
	$id = restapi_users_id($req['params']['name']);
	return array('data' => restapi_users_out(config_get_path("system/user/{$id}"), true));
}

/* Save a user through the page's function ($id null: create) and return it by its (new) name. */
function restapi_users_save(array $body, $id, array $caller, $status) {
	if ($id === null) {
		$current = restapi_users_values(array(), get_css_files());
		$post = restapi_users_post($body, $current);
		$post['act'] = 'new';
		$post['utype'] = 'user';
		$post['oldusername'] = '';
	} else {
		$user = config_get_path("system/user/{$id}");
		$current = restapi_users_values(usermgr_user_form($user), get_css_files());
		$post = restapi_users_post($body, $current);
		/* A system user's full name is read-only on the page (the field is not posted). */
		if ($user['scope'] == 'system') {
			if (array_key_exists('descr', $body) && ((string)$body['descr'] !== $current['descr'])) {
				throw new RestApiError(409, 'protected', 'The full name of a system user cannot be changed.');
			}
			unset($post['descr']);
		}
		$post['act'] = 'edit';
		$post['userid'] = (string)$id;
		$post['utype'] = (string)$user['scope'];
		$post['oldusername'] = (string)$user['name'];
	}
	$refusal = usermgr_user_save_refusal($post, $id, $caller);
	if ($refusal !== null) {
		throw restapi_users_refusal_error($refusal);
	}
	$errors = usermgr_user_save($post, $id, $caller);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('status' => $status, 'data' => restapi_users_out(config_get_path('system/user/' . restapi_users_id($post['usernamefld'])), true));
}

function restapi_h_users_create($req) {
	return restapi_users_save($req['body'], null, $req['user'], 201);
}

function restapi_h_users_update($req) {
	return restapi_users_save($req['body'], restapi_users_id($req['params']['name']), $req['user'], 200);
}

function restapi_h_users_delete($req) {
	$id = restapi_users_id($req['params']['name']);
	$name = (string)config_get_path("system/user/{$id}/name");
	$rv = usermgr_user_delete($id, $name, $req['user']);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No local user with that name.');
	}
	if (!$rv['deleted']) {
		throw restapi_users_refusal_error(array('status' => $rv['status'] ?? 409, 'errors' => $rv['errors']));
	}
	return array('data' => array('deleted' => $name));
}

/* The assigned and effective privileges of the user at position $id. */
function restapi_users_privs_out($id) {
	global $priv_list;
	$user = config_get_path("system/user/{$id}");
	$assigned = array_values(array_map('strval', is_array($user['priv'] ?? null) ? $user['priv'] : array()));
	$effective = array();
	$add = function ($priv, $group) use (&$effective, $priv_list) {
		foreach ($effective as $e) {
			if ($e['id'] === $priv) {
				return;
			}
		}
		$effective[] = array('id' => $priv, 'name' => (string)($priv_list[$priv]['name'] ?? $priv),
		    'inherited_from' => $group, 'admin' => (($priv_list[$priv]['warn'] ?? '') === 'standard-warning-root'));
	};
	foreach ($assigned as $priv) {
		$add($priv, null);
	}
	foreach (local_user_get_groups($user, true) as $name) {
		$group = getGroupEntry($name);
		foreach ((array)($group['item']['priv'] ?? array()) as $priv) {
			$add((string)$priv, (string)$name);
		}
	}
	return array('name' => (string)$user['name'], 'assigned' => $assigned, 'effective' => $effective,
	    'effective_admin' => restapi_users_admin_equivalent(array_column($effective, 'id')));
}

function restapi_h_users_privs_get($req) {
	return array('data' => restapi_users_privs_out(restapi_users_id($req['params']['name'])));
}

/*
 * Set the assigned privileges of the user named in $req (PUT): removals go
 * through the page's Delete Privilege (one by one), additions through the add
 * privileges page's Save.
 */
function restapi_h_users_privs_set($req) {
	restapi_users_only($req['body'], array('privileges'));
	$id = restapi_users_id($req['params']['name']);
	$want = restapi_users_priv_list($req['body']['privileges'] ?? null);
	$user = config_get_path("system/user/{$id}");
	$current = array_values(array_map('strval', is_array($user['priv'] ?? null) ? $user['priv'] : array()));
	$add = array_values(array_diff($want, $current));
	$remove = array_values(array_diff($current, $want));
	$unknown = usermgr_unknown_privs($add);
	if (!empty($unknown)) {
		throw restapi_validation_error(array(sprintf('Unknown privileges: %s.', implode(', ', $unknown))));
	}
	$refusal = usermgr_user_privs_add_refusal($id, $add, $req['user']);
	if ($refusal !== null) {
		throw restapi_users_refusal_error($refusal);
	}
	foreach ($remove as $priv) {
		$pos = array_search($priv, (array)config_get_path("system/user/{$id}/priv", []), true);
		if ($pos === false) {
			continue;
		}
		$rv = usermgr_user_priv_remove($id, $pos, $req['user']);
		if (!empty($rv['errors'])) {
			throw restapi_users_refusal_error(array('status' => $rv['status'] ?? 403, 'errors' => $rv['errors']));
		}
	}
	if (!empty($add)) {
		$errors = usermgr_user_privs_add($id, array('sysprivs' => $add, 'userid' => (string)$id, 'save' => 'Save'), $req['user']);
		if (!empty($errors)) {
			throw restapi_validation_error($errors);
		}
	}
	return array('data' => restapi_users_privs_out($id));
}

/* The certificates of the user at position $id: refid, descr, CA name, revoked. */
function restapi_users_certs_out($id) {
	$out = array();
	foreach ((array)config_get_path("system/user/{$id}/cert", []) as $refid) {
		$cert = lookup_cert($refid);
		$cert = $cert['item'] ?? null;
		$ca = is_array($cert) ? lookup_ca($cert['caref'] ?? '') : array();
		$out[] = array('refid' => (string)$refid, 'descr' => is_array($cert) ? (string)($cert['descr'] ?? '') : '',
		    'ca' => (string)($ca['item']['descr'] ?? ''), 'revoked' => is_array($cert) ? (bool)is_cert_revoked($cert) : false,
		    'exists' => is_array($cert));
	}
	return $out;
}

/* Revoke every REST API key of a user the caller may manage (like the user manager's other changes). */
function restapi_h_users_keys_revoke($req) {
	$id = restapi_users_id($req['params']['name']);
	$user = config_get_path("system/user/{$id}");
	$refusal = usermgr_manage_user_refusal($req['user'], $user);
	if ($refusal !== null) {
		throw restapi_users_refusal_error($refusal);
	}
	return array('data' => array('user' => $user['name'], 'revoked' => restapi_revoke_user_tokens($user['name'])));
}

function restapi_h_users_certs_list($req) {
	return array('data' => restapi_users_certs_out(restapi_users_id($req['params']['name'])));
}

/* Create a certificate for a user, or associate an existing one, through the certificate manager's save (its userid). */
function restapi_h_users_certs_add($req) {
	$id = restapi_users_id($req['params']['name']);
	$refusal = usermgr_manage_user_refusal($req['user'], config_get_path("system/user/{$id}"));
	if ($refusal !== null) {
		throw restapi_users_refusal_error($refusal);
	}
	$body = $req['body'];
	$pkcs12 = null;
	if (($body['method'] ?? null) === 'existing') {
		restapi_users_only($body, array('method', 'certref'));
		$certref = $body['certref'] ?? '';
		if (!is_string($certref)) {
			throw new RestApiError(400, 'invalid_field', 'Field "certref" must be a string.');
		}
		if (($certref !== '') && !array_key_exists($certref, pki_cert_existing_list($id))) {
			throw restapi_validation_error(array('The certificate does not exist or is already associated with the user.'));
		}
		$post = array('method' => 'existing', 'certref' => $certref, 'descr' => '', 'act' => 'new', 'save' => 'Save', 'userid' => (string)$id);
	} else {
		$c = restapi_pki_cert_choices();
		unset($c['altname_type']);
		$form = restapi_pki_cert_new_form(pki_cert_new_form(null), pki_cert_default_lifetime());
		$current = restapi_pki_form_fields($form, restapi_pki_cert_create_types(), $c);
		list($post, $pkcs12) = restapi_pki_cert_create_post($body, $current);
		$post['userid'] = (string)$id;
	}
	$before = (array)config_get_path("system/user/{$id}/cert", []);
	$errors = pki_cert_save($post, null, 'new', $id, $pkcs12);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$added = array_values(array_diff((array)config_get_path("system/user/{$id}/cert", []), $before));
	return array('status' => 201, 'data' => array('added' => $added, 'certificates' => restapi_users_certs_out($id)));
}

function restapi_h_users_certs_remove($req) {
	$id = restapi_users_id($req['params']['name']);
	$pos = array_search((string)$req['params']['refid'], array_map('strval', (array)config_get_path("system/user/{$id}/cert", [])), true);
	if ($pos === false) {
		throw new RestApiError(404, 'not_found', 'The user has no certificate with that refid.');
	}
	$rv = usermgr_user_cert_remove($id, $pos, $req['user']);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No local user with that name.');
	}
	if (!empty($rv['errors'])) {
		throw restapi_users_refusal_error(array('status' => $rv['status'] ?? 403, 'errors' => $rv['errors']));
	}
	return array('data' => restapi_users_certs_out($id));
}

/* ------------------------------------------------------------------ */
/* Groups                                                              */
/* ------------------------------------------------------------------ */

/* The position of the group named $name, or a 404. */
function restapi_groups_id($name) {
	foreach (config_get_path('system/group', []) as $idx => $group) {
		if (is_array($group) && (($group['name'] ?? null) === (string)$name)) {
			return (int)$idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No group with that name.');
}

/* User names for uids (unknown uids as they are). */
function restapi_groups_member_names(array $uids) {
	$names = array();
	foreach ($uids as $uid) {
		$user = getUserEntryByUID($uid);
		$names[] = isset($user['item']['name']) ? (string)$user['item']['name'] : (string)$uid;
	}
	return $names;
}

/* uids for user names; 422 for an unknown user. */
function restapi_groups_member_uids(array $names) {
	$uids = array();
	foreach ($names as $name) {
		$found = null;
		foreach (config_get_path('system/user', []) as $user) {
			if (is_array($user) && (($user['name'] ?? null) === (string)$name)) {
				$found = (string)$user['uid'];
			}
		}
		if ($found === null) {
			throw restapi_validation_error(array(sprintf('Unknown user: %s.', $name)));
		}
		$uids[] = $found;
	}
	return $uids;
}

/* A group in API shape (fields copied one by one). */
function restapi_groups_out(array $group) {
	$members = is_array($group['member'] ?? null) ? $group['member'] : array();
	return array(
		'name' => (string)$group['name'],
		'description' => (string)($group['description'] ?? ''),
		'scope' => (string)($group['scope'] ?? ''),
		'gid' => (int)($group['gid'] ?? 0),
		'members' => restapi_groups_member_names($members),
		'member_count' => count($members),
		'privileges' => array_values(array_map('strval', is_array($group['priv'] ?? null) ? $group['priv'] : array())),
		'members_fixed' => usermgr_group_members_fixed($group),
		'used_by' => usermgr_group_used_by($group['name']),
	);
}

function restapi_h_groups_list($req) {
	$out = array();
	foreach (config_get_path('system/group', []) as $group) {
		if (is_array($group) && isset($group['name'])) {
			$out[] = restapi_groups_out($group);
		}
	}
	return array('data' => $out);
}

function restapi_h_groups_get($req) {
	return array('data' => restapi_groups_out(config_get_path('system/group/' . restapi_groups_id($req['params']['name']))));
}

/* Save a group through the page's function ($id null: create). */
function restapi_groups_save(array $body, $id, array $caller, $status) {
	$types = restapi_groups_types();
	if ($id === null) {
		$current = array('name' => '', 'description' => '', 'scope' => 'local', 'members' => array());
	} else {
		$p = usermgr_group_form($id, null);
		$current = array('name' => (string)$p['name'], 'description' => (string)($p['description'] ?? ''), 'scope' => (string)$p['gtype'],
		    'members' => restapi_groups_member_names(is_array($p['members'] ?? null) ? $p['members'] : array()));
	}
	$values = restapi_svc_merge($body, $current, $types);
	$group = ($id === null) ? null : config_get_path("system/group/{$id}");
	if (($group !== null) && ($group['scope'] == 'system') && ($values['scope'] !== 'system')) {
		throw new RestApiError(409, 'protected', 'The scope of a system group cannot be changed.');
	}
	if (($group !== null) && usermgr_group_members_fixed($group) && ($values['members'] != $current['members'])) {
		throw new RestApiError(409, 'members_fixed', 'The members of this group (gid 1998) cannot be changed on its page; ' .
		    'change the groups of the users instead (PUT /api/v1/users/{name}).');
	}
	$post = array('groupname' => $values['name'], 'description' => $values['description'], 'gtype' => $values['scope'], 'save' => 'Save');
	$uids = restapi_groups_member_uids($values['members']);
	if (!empty($uids)) {
		$post['members'] = $uids;
	}
	if ($id !== null) {
		$post['groupid'] = (string)$id;
		$post['id'] = (string)$id;
		$post['gid'] = (string)$group['gid'];
	}
	$refusal = usermgr_group_save_refusal($post, $id, $caller);
	if ($refusal !== null) {
		throw restapi_users_refusal_error($refusal);
	}
	$errors = usermgr_group_save($post, $id, $caller);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('status' => $status, 'data' => restapi_groups_out(config_get_path('system/group/' . restapi_groups_id($values['name']))));
}

function restapi_h_groups_create($req) {
	return restapi_groups_save($req['body'], null, $req['user'], 201);
}

function restapi_h_groups_update($req) {
	return restapi_groups_save($req['body'], restapi_groups_id($req['params']['name']), $req['user'], 200);
}

function restapi_h_groups_delete($req) {
	$id = restapi_groups_id($req['params']['name']);
	$name = (string)config_get_path("system/group/{$id}/name");
	$rv = usermgr_group_delete($id, $name, $req['user']);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No group with that name.');
	}
	if (!$rv['deleted']) {
		$e = restapi_users_refusal_error(array('status' => $rv['status'] ?? 409, 'errors' => $rv['errors']));
		if (($e->status === 409) && !empty(usermgr_group_used_by($name))) {
			throw new RestApiError(409, 'in_use', $e->getMessage(), array('used_by' => usermgr_group_used_by($name)));
		}
		throw $e;
	}
	return array('data' => array('deleted' => $name));
}

function restapi_h_groups_privs_get($req) {
	$group = config_get_path('system/group/' . restapi_groups_id($req['params']['name']));
	return array('data' => array('name' => (string)$group['name'],
	    'privileges' => array_values(array_map('strval', is_array($group['priv'] ?? null) ? $group['priv'] : array()))));
}

/* Set a group's privileges (PUT): removals through the page's Delete Privilege, additions through the add privileges page's Save. */
function restapi_h_groups_privs_set($req) {
	restapi_users_only($req['body'], array('privileges'));
	$id = restapi_groups_id($req['params']['name']);
	$want = restapi_users_priv_list($req['body']['privileges'] ?? null);
	$current = array_values(array_map('strval', (array)config_get_path("system/group/{$id}/priv", [])));
	$add = array_values(array_diff($want, $current));
	$remove = array_values(array_diff($current, $want));
	$unknown = usermgr_unknown_privs($add);
	if (!empty($unknown)) {
		throw restapi_validation_error(array(sprintf('Unknown privileges: %s.', implode(', ', $unknown))));
	}
	$refusal = usermgr_group_privs_add_refusal($id, $add, $req['user']);
	if ($refusal !== null) {
		throw restapi_users_refusal_error($refusal);
	}
	foreach ($remove as $priv) {
		$pos = array_search($priv, (array)config_get_path("system/group/{$id}/priv", []), true);
		if ($pos === false) {
			continue;
		}
		$rv = usermgr_group_priv_remove($id, $pos, $req['user']);
		if (!empty($rv['errors'])) {
			throw restapi_users_refusal_error(array('status' => $rv['status'] ?? 403, 'errors' => $rv['errors']));
		}
	}
	if (!empty($add)) {
		$errors = usermgr_group_privs_add($id, array('sysprivs' => $add, 'groupid' => (string)$id, 'save' => 'Save'), $req['user']);
		if (!empty($errors)) {
			throw restapi_validation_error($errors);
		}
	}
	return restapi_h_groups_privs_get($req);
}

function restapi_h_groups_members_get($req) {
	$group = config_get_path('system/group/' . restapi_groups_id($req['params']['name']));
	return array('data' => array('name' => (string)$group['name'],
	    'members' => restapi_groups_member_names(is_array($group['member'] ?? null) ? $group['member'] : array()),
	    'members_fixed' => usermgr_group_members_fixed($group)));
}

function restapi_h_groups_members_set($req) {
	restapi_users_only($req['body'], array('members'));
	if (!array_key_exists('members', $req['body'])) {
		throw new RestApiError(400, 'invalid_field', 'Field "members" (a list of user names) is required.');
	}
	restapi_groups_save(array('members' => $req['body']['members']), restapi_groups_id($req['params']['name']), $req['user'], 200);
	return restapi_h_groups_members_get($req);
}

/* ------------------------------------------------------------------ */
/* Authentication servers                                              */
/* ------------------------------------------------------------------ */

/* The position of the server named $name in authsrv_list(), or a 404. */
function restapi_authsrv_id($name) {
	foreach (authsrv_list() as $idx => $server) {
		if (($server['name'] ?? null) === (string)$name) {
			return (int)$idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No authentication server with that name.');
}

/* Whether the list entry $server is a configured server (not the built-in Local Database). */
function restapi_authsrv_configured(array $server) {
	foreach ((array)config_get_path('system/authserver', []) as $as) {
		if (is_array($as) && (($as['name'] ?? null) === ($server['name'] ?? ''))) {
			return true;
		}
	}
	return false;
}

/* The choices of the server form. */
function restapi_authsrv_choices() {
	$cas = array('global' => 'Global Root CA List');
	foreach (config_get_path('ca', []) as $ca) {
		$cas[$ca['refid']] = $ca['descr'];
	}
	return array(
		'type' => authsrv_types(),
		'ldap_urltype' => array_combine(array_keys(authsrv_ldap_urltypes()), array_keys(authsrv_ldap_urltypes())),
		'ldap_caref' => $cas,
		'ldap_protver' => array_combine(authsrv_ldap_protvers(), authsrv_ldap_protvers()),
		'ldap_scope' => authsrv_ldap_scopes(),
		'radius_protocol' => authsrv_radius_protocols(),
		'radius_srvcs' => authsrv_radius_srvcs(),
		'radius_nasip_attribute' => authsrv_radiusnas_list(),
	);
}

/* The first LDAP template of the page (OpenLDAP; guiconfig.inc's $ldap_templates['open']). */
function restapi_authsrv_template() {
	return array('attr_user' => 'cn', 'attr_group' => 'cn', 'attr_member' => 'member', 'allow_unauthenticated' => 'true');
}

/* A server in API shape: summary, or (full) also its form fields (secrets masked) and choices. */
function restapi_authsrv_out(array $server, $full) {
	$configured = restapi_authsrv_configured($server);
	$out = array(
		'name' => (string)$server['name'],
		'type' => (string)($server['type'] ?? ''),
		'host' => (string)($server['host'] ?? ''),
		'refid' => (string)($server['refid'] ?? ''),
		'builtin' => !$configured,
		'in_use' => !empty(authsrv_used_by($server['name'])),
		'used_by' => authsrv_used_by($server['name']),
	);
	if (!$full || !$configured) {
		return $out;
	}
	$p = authsrv_form($server, false);
	$p['ldap_authcn'] = $p['ldap_authcn'] ?? '';
	$c = restapi_authsrv_choices();
	$fields = restapi_authsrv_values($p, $c, false);
	return $out + array('fields' => restapi_authsrv_mask($fields), 'choices' => array_map('restapi_svc_choices', $c));
}

function restapi_h_authsrv_list($req) {
	return array('data' => array_map(function ($server) {
		return restapi_authsrv_out($server, false);
	}, authsrv_list()));
}

function restapi_h_authsrv_get($req) {
	return array('data' => restapi_authsrv_out(authsrv_list()[restapi_authsrv_id($req['params']['name'])], true));
}

/* Save a server through the page's function ($id null: create). */
function restapi_authsrv_save(array $body, $id, array $caller, $status) {
	$body = restapi_vpn_secret_body($body, 'ldap_bindpw');
	$body = restapi_vpn_secret_body($body, 'radius_secret');
	$c = restapi_authsrv_choices();
	if ($id === null) {
		$types = restapi_authsrv_types(true);
		$current = restapi_authsrv_values(authsrv_new_form(), $c, true, restapi_authsrv_template());
	} else {
		$server = authsrv_list()[$id];
		if (!restapi_authsrv_configured($server)) {
			throw new RestApiError(409, 'protected', 'The built-in Local Database cannot be changed.');
		}
		$types = restapi_authsrv_types(false);
		$current = restapi_authsrv_values(authsrv_form($server, false), $c, false);
		/* a RADIUS secret is only stored when one is sent (the page keeps it when the field is empty) */
		$current['radius_secret'] = '';
	}
	$values = restapi_svc_merge($body, $current, $types);
	$post = restapi_authsrv_post($values, restapi_authsrv_types(true));
	if ($id !== null) {
		$post['id'] = (string)$id;
		$post['type'] = (string)$current['type'];
		$refusal = authsrv_webgui_refusal($server['name'], $caller);
		if ($refusal !== null) {
			throw restapi_users_refusal_error($refusal);
		}
	}
	$errors = authsrv_save($post, $id, $caller);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('status' => $status, 'data' => restapi_authsrv_out(authsrv_list()[restapi_authsrv_id($values['name'])], true));
}

function restapi_h_authsrv_create($req) {
	return restapi_authsrv_save($req['body'], null, $req['user'], 201);
}

function restapi_h_authsrv_update($req) {
	return restapi_authsrv_save($req['body'], restapi_authsrv_id($req['params']['name']), $req['user'], 200);
}

function restapi_h_authsrv_delete($req) {
	$id = restapi_authsrv_id($req['params']['name']);
	$name = (string)authsrv_list()[$id]['name'];
	$rv = authsrv_delete($id, $req['user']);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No authentication server with that name.');
	}
	if (!$rv['deleted']) {
		$e = restapi_validation_error($rv['errors']);
		$used = authsrv_used_by($name);
		throw new RestApiError(409, empty($used) ? 'protected' : 'in_use', $e->details['messages'][0], empty($used) ? array() : array('used_by' => $used));
	}
	return array('data' => array('deleted' => $name));
}

/* Authenticate a user name and password against a server (like Diagnostics > Authentication; nothing is stored or logged here). */
function restapi_h_authsrv_test($req) {
	$body = $req['body'];
	restapi_users_only($body, array('username', 'password'));
	foreach (array('username', 'password') as $field) {
		if (array_key_exists($field, $body) && !is_string($body[$field])) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be a string.");
		}
	}
	$name = (string)authsrv_list()[restapi_authsrv_id($req['params']['name'])]['name'];
	$rv = authsrv_test($name, (string)($body['username'] ?? ''), (string)($body['password'] ?? ''));
	if (!empty($rv['errors'])) {
		throw restapi_validation_error($rv['errors']);
	}
	return array('data' => array('server' => $name, 'username' => (string)$body['username'], 'authenticated' => $rv['authenticated'],
	    'groups' => $rv['groups']));
}

/* ------------------------------------------------------------------ */
/* Privilege catalogue                                                 */
/* ------------------------------------------------------------------ */

function restapi_h_privileges($req) {
	$out = array();
	foreach (usermgr_priv_list_sorted() as $id => $priv) {
		$out[] = array('id' => (string)$id, 'name' => (string)($priv['name'] ?? $id), 'description' => (string)($priv['descr'] ?? ''),
		    'warning' => (string)($priv['warn'] ?? ''), 'admin' => (($priv['warn'] ?? '') === 'standard-warning-root'));
	}
	return array('data' => $out);
}
