<?php
/*
 * system_restapi.php
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

##|+PRIV
##|*IDENT=page-system-restapi
##|*NAME=System: REST API
##|*DESCR=Allow access to the 'System: REST API' page (API settings and the API keys of every user).
##|*WARN=standard-warning-root
##|*MATCH=system_restapi.php*
##|*MATCH=system_restapi_explorer.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("restapi_keys.inc");

$new_token = null;
$create = array();
/* Two views: the settings (default) and every user's keys. Key actions always return to the keys. */
$view = ((($_POST['view'] ?? $_GET['view'] ?? '') === 'keys') || isset($_POST['create']) || isset($_POST['act'])) ? 'keys' : '';

if ($_POST['save']) {
	$result = restapi_save_settings($_POST);
	$input_errors = $result['input_errors'];
	if (empty($input_errors)) {
		$savemsg = gettext('REST API settings saved.');
	}
	$pconfig = $_POST;
} elseif ($_POST['create']) {
	$result = restapi_create_token($_POST);
	$input_errors = $result['input_errors'];
	if (empty($input_errors)) {
		$new_token = $result['token'];
	} else {
		$create = $_POST;
	}
} elseif (($_POST['act'] ?? '') === 'revoke') {
	if (restapi_revoke_token((string)($_POST['id'] ?? ''))) {
		$savemsg = gettext('The API key was revoked.');
	} else {
		$input_errors[] = gettext('The API key no longer exists.');
	}
} elseif (($_POST['act'] ?? '') === 'revoke_all') {
	$revoke_user = (string)($_POST['username'] ?? '');
	$savemsg = sprintf(gettext('%1$d API key(s) of user %2$s revoked.'), restapi_revoke_user_tokens($revoke_user),
	    htmlspecialchars($revoke_user));
}

if (!isset($pconfig)) {
	$pconfig = restapi_settings();
}
$users = restapi_users_with_access();
$tokens = restapi_tokens();

$pgtitle = array(gettext('System'), gettext('REST API'), ($view === 'keys') ? gettext('All Keys') : gettext('Settings'));
$pglinks = array('', 'system_restapi.php', '@self');
include("head.inc");

restapi_print_tabs('system_restapi.php', true, $view);

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

if ($view === 'keys'):
	if (!restapi_enabled()) {
		print_info_box(sprintf(gettext('The REST API is disabled. Keys can be created, but they only work once it is enabled in %1$sSettings%2$s.'),
		    '<a href="system_restapi.php">', '</a>'), 'warning', false);
	}
	if ($new_token !== null) {
		restapi_print_new_token($new_token);
	}

	/* "Revoke all keys" per user, for an account that may be compromised (a password change keeps keys). */
	$key_owners = array_unique(array_map(function ($t) {
		return (string)($t['username'] ?? '');
	}, $tokens));
	sort($key_owners);
	$footer = '';
	if (!empty($key_owners)) {
		$footer = '<div class="d-flex flex-wrap align-items-center gap-2"><span class="text-muted small me-1">' .
		    gettext('Revoke all keys of a user (e.g. if the account may be compromised; a password change keeps keys):') . '</span>';
		foreach ($key_owners as $owner) {
			$footer .= '<a href="system_restapi.php?act=revoke_all&amp;username=' . urlencode($owner) . '" class="btn btn-sm btn-outline-danger do-confirm" usepost ' .
			    'title="' . htmlspecialchars(sprintf(gettext('Revoke every API key of %s'), $owner)) . '">' .
			    '<i class="fa-solid fa-ban icon-embed-btn"></i>' . htmlspecialchars($owner) . '</a>';
		}
		$footer .= '</div>';
	}
	restapi_print_key_table($tokens, true, 'system_restapi.php', $footer);

	if (empty($users)) {
		print_info_box(sprintf(gettext('No user has REST API access yet. Edit a user or group in %1$sSystem > User Manager%2$s and ' .
		    'add the "WebCfg - System: REST API access" privilege, then create a key for it here.'),
		    '<a href="system_usermanager.php">', '</a>'), 'warning', false);
	} else {
		restapi_print_create_form($users, $create);
	}
else:
	$form = new Form(false);
	$section = new Form_Section(gettext('REST API Settings'));
	$section->addInput(new Form_Checkbox(
		'enable',
		gettext('Enable'),
		gettext('Enable the REST API at /api/v1/'),
		!empty($pconfig['enable'])
	))->setHelp(sprintf(gettext('A key acts as its local user. The user needs the %1$s"WebCfg - System: REST API access"%2$s ' .
	    'privilege, and each endpoint is allowed only when the user may open the matching GUI page. ' .
	    'Grant access per user or per group in %3$sSystem > User Manager%4$s.'),
	    '<strong>', '</strong>', '<a href="system_usermanager.php">', '</a>'));
	$section->addInput(new Form_Input(
		'allowednetworks',
		gettext('Allowed networks'),
		'text',
		$pconfig['allowednetworks'] ?? '',
		array('placeholder' => '192.168.1.0/24 2001:db8::/48')
	))->setHelp(gettext('Addresses or networks allowed to use the API, separated by spaces. Empty allows any address ' .
	    'that can reach the WebGUI. The firewall rules for the WebGUI still apply.'));
	$section->addInput(new Form_Checkbox(
		'allowhttp',
		gettext('Allow HTTP'),
		gettext('Accept API requests over plain HTTP'),
		!empty($pconfig['allowhttp'])
	))->setHelp(gettext('API keys are sent with every request. Leave this off unless the WebGUI only runs over HTTP on a trusted network.'));
	$section->addInput(new Form_Button(
		'save',
		gettext('Save'),
		null,
		'fa-solid fa-save'
	))->addClass('btn-primary');
	$form->add($section);
	print($form);

	/* At a glance: what the saved settings mean right now. */
	$active_keys = count(array_filter($tokens, function ($t) {
		return empty($t['expires']) || ((int)$t['expires'] >= time());
	}));
	$saved = restapi_settings();
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Status')?></h2></div>
	<div class="panel-body p-3">
		<dl class="row mb-0">
			<dt class="col-sm-3"><?=gettext('API')?></dt>
			<dd class="col-sm-9"><?=restapi_enabled() ?
			    '<span class="badge text-bg-success"><i class="fa-solid fa-circle-check"></i> ' . gettext('Enabled') . '</span>' :
			    '<span class="badge text-bg-secondary"><i class="fa-solid fa-circle-xmark"></i> ' . gettext('Disabled') . '</span>'?>
				<code class="ms-2"><?=empty($saved['allowhttp']) ? 'https' : 'http(s)'?>://<?=htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'firewall')?>/api/v1/</code></dd>
			<dt class="col-sm-3"><?=gettext('Users with API access')?></dt>
			<dd class="col-sm-9"><?=empty($users) ? '<span class="text-muted">' . gettext('None') . '</span>' :
			    htmlspecialchars(implode(', ', array_keys($users)))?>
				<a class="ms-2 small" href="system_usermanager.php"><?=gettext('User Manager')?></a></dd>
			<dt class="col-sm-3"><?=gettext('API keys')?></dt>
			<dd class="col-sm-9"><?=sprintf(gettext('%1$d active, %2$d in total'), $active_keys, count($tokens))?>
				<a class="ms-2 small" href="system_restapi.php?view=keys"><?=gettext('Manage keys')?></a></dd>
			<dt class="col-sm-3"><?=gettext('Documentation')?></dt>
			<dd class="col-sm-9 mb-0"><?=sprintf(gettext('The %1$sGuide%2$s explains keys, scopes and errors, and the %3$sAPI Explorer%4$s lists every endpoint ' .
			    'and lets you try them. The OpenAPI document is at %5$s (it needs an API key). Every change made through the API appears in ' .
			    'Diagnostics > Backup & Restore > Configuration History with the key ID.'),
			    '<a href="system_restapi_explorer.php?view=guide">', '</a>', '<a href="system_restapi_explorer.php">', '</a>',
			    '<code>/api/v1/openapi.json</code>')?></dd>
		</dl>
	</div>
</div>
<?php
endif;

include("foot.inc");
