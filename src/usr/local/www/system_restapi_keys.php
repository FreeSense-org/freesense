<?php
/*
 * system_restapi_keys.php
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
 * This page's privilege is also the per-user REST API access switch: an
 * API key only works while its owner may open this page (see
 * RESTAPI_ACCESS_PAGE in restapi.inc).
 */

##|+PRIV
##|*IDENT=page-system-restapi-keys
##|*NAME=System: REST API access
##|*DESCR=Allow the user to use the REST API, to create and revoke their own API keys and to open the API Explorer. The API still only allows what the user's other privileges allow.
##|*MATCH=system_restapi_keys.php*
##|*MATCH=system_restapi_explorer.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("restapi_keys.inc");

$me = (string)($_SESSION['Username'] ?? '');
$me_user = restapi_local_user($me);
$new_token = null;
$create = array();
$create_errors = array();

if ($me_user === null) {
	$input_errors[] = gettext('API keys are available to local users only.');
} elseif ($_POST['create']) {
	$post = $_POST;
	$post['username'] = $me;
	$result = restapi_create_token($post);
	if (empty($result['input_errors'])) {
		$new_token = $result['token'];
	} else {
		/* Shown in the create modal, which opens again with the entered values. */
		$create_errors = $result['input_errors'];
		$create = $_POST;
	}
} elseif (($action = restapi_key_action($_POST, $me)) !== null) {
	/* Only the signed-in user's own keys, whatever IDs were posted. */
	list($savemsg, $input_errors) = $action;
}

$pgtitle = array(gettext('System'), gettext('REST API'), gettext('My API Keys'));
$pglinks = array('', isAllowedPage('system_restapi.php') ? 'system_restapi.php' : '', '@self');
include("head.inc");

restapi_print_tabs('system_restapi_keys.php');

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}
if (!restapi_enabled()) {
	print_info_box(gettext('The REST API is currently disabled by the administrator. Keys can be created but will not work until it is enabled.'),
	    'warning', false);
}
if ($new_token !== null) {
	restapi_print_new_token($new_token);
}

if ($me_user !== null) {
	$mine = array_values(array_filter(restapi_tokens(), function ($t) use ($me) {
		return ($t['username'] ?? '') === $me;
	}));
	restapi_print_key_table($mine, false, 'system_restapi_keys.php');
	restapi_print_create_form(array(), $create, $me, $create_errors);

	print_info_box(gettext('A key can do exactly what your account can do in the GUI, and nothing more. ' .
	    'Use a read-only key and an expiry date where you can, and revoke keys you no longer use.'), 'info', false);
}

include("foot.inc");
