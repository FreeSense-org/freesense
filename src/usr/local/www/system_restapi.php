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
##|-PRIV

require_once("guiconfig.inc");
require_once("restapi_keys.inc");

$new_token = null;
$create = array();

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
}

if (!isset($pconfig)) {
	$pconfig = restapi_settings();
}
$users = restapi_users_with_access();

$pgtitle = array(gettext('System'), gettext('REST API'));
$pglinks = array('', '@self');
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext('Settings & All Keys'), true, 'system_restapi.php');
$tab_array[] = array(gettext('My API Keys'), false, 'system_restapi_keys.php');
display_top_tabs($tab_array);

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}
if ($new_token !== null) {
	restapi_print_new_token($new_token);
}

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

restapi_print_key_table(restapi_tokens(), true, 'system_restapi.php');

if (empty($users)) {
	print_info_box(sprintf(gettext('No user has REST API access yet. Edit a user or group in %1$sSystem > User Manager%2$s and ' .
	    'add the "WebCfg - System: REST API access" privilege, then create a key for it here.'),
	    '<a href="system_usermanager.php">', '</a>'), 'warning', false);
} else {
	restapi_print_create_form($users, $create);
}

print_info_box(sprintf(gettext('The API is described by an OpenAPI document at %s (it needs an API key). ' .
    'Every change made through the API appears in Diagnostics > Backup & Restore > Configuration History with the key ID.'),
    '<code>/api/v1/openapi.json</code>'), 'info', false);

include("foot.inc");
