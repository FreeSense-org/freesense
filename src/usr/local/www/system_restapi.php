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
require_once("restapi_listeners.inc");

$new_token = null;
$create = array();
$create_errors = array();
$listener_errors = array();
$listener_post = array();
/*
 * Three views: the settings (default), the listeners and every user's keys.
 * Listener actions return to the listeners, key actions to the keys.
 */
$act = (string)($_POST['act'] ?? '');
$asked = (string)($_POST['view'] ?? $_GET['view'] ?? '');
if (strpos($act, 'listener_') === 0) {
	$view = 'listeners';
} elseif (isset($_POST['create']) || ($act !== '')) {
	$view = 'keys';
} else {
	$view = in_array($asked, array('keys', 'listeners'), true) ? $asked : '';
}

if ($_POST['save']) {
	$result = restapi_save_settings($_POST);
	$input_errors = $result['input_errors'];
	if (empty($input_errors)) {
		$savemsg = gettext('REST API settings saved.');
	}
	$pconfig = $_POST;
} elseif ($_POST['create']) {
	$result = restapi_create_token($_POST);
	if (empty($result['input_errors'])) {
		$new_token = $result['token'];
	} else {
		/* Shown in the create modal, which opens again with the entered values. */
		$create_errors = $result['input_errors'];
		$create = $_POST;
	}
} elseif (($action = restapi_listener_action($_POST)) !== null) {
	list($savemsg, $input_errors, $listener_errors, $listener_post) = $action;
} elseif (($action = restapi_key_action($_POST)) !== null) {
	list($savemsg, $input_errors) = $action;
}

if (!isset($pconfig)) {
	$pconfig = restapi_settings();
}
$users = restapi_users_with_access();
$tokens = restapi_tokens();
$listeners = restapi_listeners();

/* A new key's secret is in this response only: never cache it. */
if ($new_token !== null) {
	header('Cache-Control: no-store');
}

$titles = array('' => gettext('Settings'), 'listeners' => gettext('Listeners'), 'keys' => gettext('All Keys'));
$pgtitle = array(gettext('System'), gettext('REST API'), $titles[$view]);
$pglinks = array('', 'system_restapi.php', '@self');
if ($view === 'keys') {
	if (!empty($users)) {
		fs_page_action(gettext('Create key'), '#', 'fa-plus', 'primary', array('data-bs-toggle' => 'modal', 'data-bs-target' => '#restapi-create-modal',
		    'role' => 'button'));
	}
	fs_page_action(gettext('User Manager'), 'system_usermanager.php', 'fa-users', 'secondary');
} elseif ($view === 'listeners') {
	fs_page_action(gettext('Add listener'), '#', 'fa-plus', 'primary', array('id' => 'restapi-listener-add', 'role' => 'button'));
	if (!empty($listeners)) {
		fs_page_action(gettext('Restart'), 'system_restapi.php?act=listener_restart', 'fa-rotate', 'secondary', array('usepost' => true,
		    'title' => gettext('Write the configuration again and restart every listener'),
		    'data-fs-confirm' => gettext('Restart the API listeners?'),
		    'data-fs-confirm-detail' => gettext('Requests in progress on the listeners are cut off. The WebGUI port is not affected.'),
		    'data-fs-confirm-action' => gettext('Restart')));
	}
} else {
	fs_page_action(gettext('Request log'), 'status_restapi.php', 'fa-list', 'secondary');
}
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

	/* Select keys (e.g. filter by a user whose account may be compromised; a password change keeps keys) and revoke them together. */
	restapi_print_key_table($tokens, true, 'system_restapi.php', !empty($users));

	if (empty($users)) {
		print_info_box(sprintf(gettext('No user has REST API access yet. Edit a user or group in %1$sSystem > User Manager%2$s and ' .
		    'add the "WebCfg - System: REST API access" privilege, then create a key for it here.'),
		    '<a href="system_usermanager.php">', '</a>'), 'warning', false);
	} else {
		restapi_print_create_form($users, $create, null, $create_errors);
	}
elseif ($view === 'listeners'):
	if (!restapi_enabled()) {
		print_info_box(sprintf(gettext('The REST API is disabled, so no listener runs. Enable it in %1$sSettings%2$s.'),
		    '<a href="system_restapi.php">', '</a>'), 'warning', false);
	}
	restapi_print_listeners($listener_errors, $listener_post);
else:
	/* At a glance: what the saved settings mean right now. */
	$saved = restapi_settings();
	$active_keys = count(array_filter($tokens, function ($t) {
		return empty($t['revoked']) && (empty($t['expires']) || ((int)$t['expires'] >= time()));
	}));
	$listener_states = restapi_listener_status();
	$running = count(array_filter($listeners, function ($l) use ($listener_states) {
		return $l['enable'] && (($listener_states[$l['id']]['state'] ?? '') === 'running');
	}));
	$log_size = is_file(RESTAPI_REQLOG_FILE) ? (int)@filesize(RESTAPI_REQLOG_FILE) : 0;
	$log_labels = array('off' => gettext('Off'), 'failures' => gettext('Failed requests'), 'changes' => gettext('Changes and failures'),
	    'all' => gettext('Every request'));
	$api_url = (empty($saved['allowhttp']) ? 'https' : 'http(s)') . '://' . ($_SERVER['HTTP_HOST'] ?? 'firewall') . '/api/v1/';
?>
<div class="fs-tiles restapi-tiles">
<?php
	fs_tile(gettext('REST API'), $saved['guiapi'] ? gettext('WebGUI port') : gettext('Listeners only'),
	    restapi_enabled() ? 'enabled' : 'disabled', $saved['guiapi'] ? $api_url : gettext('Not served on the WebGUI port'));
	fs_tile(gettext('Listeners'), empty($listeners) ? gettext('None') : sprintf(gettext('%1$d of %2$d'), $running, count($listeners)), null,
	    empty($listeners) ? gettext('Only the WebGUI port') : gettext('running'));
	fs_tile(gettext('Users with access'), count($users), null, empty($users) ? gettext('Grant it in User Manager') :
	    implode(', ', array_slice(array_keys($users), 0, 4)) . ((count($users) > 4) ? ' …' : ''));
	fs_tile(gettext('Active keys'), $active_keys, null, sprintf(gettext('%d in total'), count($tokens)));
	fs_tile(gettext('Request log'), $log_labels[$saved['log_level']] ?? $saved['log_level'], null,
	    sprintf(gettext('%1$s of %2$d MB, kept %3$d days'), format_bytes($log_size), $saved['log_mb'], $saved['log_days']));
?>
</div>
<?php
	$form = new Form();
	$section = new Form_Section(gettext('General'));
	$section->addInput(new Form_Checkbox(
		'enable',
		gettext('Enable'),
		gettext('Enable the REST API at /api/v1/'),
		!empty($pconfig['enable'])
	))->setHelp(sprintf(gettext('A key acts as its local user. The user needs the %1$s"WebCfg - System: REST API access"%2$s ' .
	    'privilege, and each endpoint is allowed only when the user may open the matching GUI page. ' .
	    'Grant access per user or per group in %3$sSystem > User Manager%4$s.'),
	    '<strong>', '</strong>', '<a href="system_usermanager.php">', '</a>'));
	$section->addInput(new Form_Checkbox(
		'guiapi',
		gettext('WebGUI port'),
		gettext('Serve the API on the WebGUI port too'),
		!empty($pconfig['guiapi'])
	))->setHelp(sprintf(gettext('Turn this off to serve the API only on its %1$sListeners%2$s. The API Explorer\'s "Try it" uses the WebGUI port.'),
	    '<a href="system_restapi.php?view=listeners">', '</a>'));
	$form->add($section);

	$section = new Form_Section(gettext('Access'));
	$section->addInput(new Form_Input(
		'allowednetworks',
		gettext('Allowed networks'),
		'text',
		$pconfig['allowednetworks'] ?? '',
		array('placeholder' => '192.168.1.0/24 2001:db8::/48')
	))->setHelp(gettext('Addresses or networks allowed to use the API, separated by spaces. Empty allows any address ' .
	    'that can reach the WebGUI or a listener. A listener can have its own list. The firewall rules still apply.'));
	$section->addInput(new Form_Checkbox(
		'allowhttp',
		gettext('Allow HTTP'),
		gettext('Accept API requests over plain HTTP'),
		!empty($pconfig['allowhttp'])
	))->setHelp(gettext('API keys are sent with every request. Leave this off unless the WebGUI only runs over HTTP on a trusted network. ' .
	    'Listeners always use HTTPS.'));
	$form->add($section);

	$section = new Form_Section(gettext('Request Log'));
	$section->addInput(new Form_Select(
		'log_level',
		gettext('Log'),
		$pconfig['log_level'] ?? RESTAPI_REQLOG_DEFAULT_LEVEL,
		array(
			'off' => gettext('Nothing'),
			'failures' => gettext('Failed requests (4xx and 5xx)'),
			'changes' => gettext('Changes and failed requests'),
			'all' => gettext('Every request'),
		)
	))->setHelp(sprintf(gettext('Each entry has the time, client address, method, path, status, duration, key ID, user and listener; never a ' .
	    'request body, a query value or a key secret. See it in %1$sStatus > REST API%2$s. Failed authentications are also always ' .
	    'written to the authentication log for login protection.'), '<a href="status_restapi.php">', '</a>'));
	$group = new Form_Group(gettext('Clean up'));
	$group->add(new Form_Input(
		'log_days',
		gettext('Keep for (days)'),
		'number',
		$pconfig['log_days'] ?? RESTAPI_REQLOG_DEFAULT_DAYS,
		array('min' => 1, 'max' => 365, 'inputmode' => 'numeric')
	))->setHelp(gettext('Days to keep entries'));
	$group->add(new Form_Input(
		'log_mb',
		gettext('Size limit (MB)'),
		'number',
		$pconfig['log_mb'] ?? RESTAPI_REQLOG_DEFAULT_MB,
		array('min' => 1, 'max' => 500, 'inputmode' => 'numeric')
	))->setHelp(gettext('Maximum size in MB'));
	$group->setHelp(sprintf(gettext('Older entries are removed every night; when the log reaches the size limit, the oldest entries are removed at once. ' .
	    'Current size: %s.'), format_bytes($log_size)));
	$section->add($group);
	$form->add($section);
	print($form);
?>
<p class="restapi-note"><?=sprintf(gettext('The %1$sGuide%2$s explains keys, scopes and errors; the %3$sAPI Explorer%4$s lists every endpoint. ' .
    'Changes made through the API appear in Configuration History with the key ID.'),
    '<a href="system_restapi_explorer.php?view=guide">', '</a>', '<a href="system_restapi_explorer.php">', '</a>')?></p>
<?php
endif;

include("foot.inc");
