<?php
/*
 * diag_authentication.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-diagnostics-authentication
##|*NAME=Diagnostics: Authentication
##|*DESCR=Allow access to the 'Diagnostics: Authentication' page.
##|*MATCH=diag_authentication.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("auth.inc");

$auth_ok = null;
$groups = array();

if ($_POST) {
	$pconfig = $_POST;
	unset($input_errors);

	if ($_POST['debug'] == 'yes') {
		g_set('debug', true);
	}

	$authcfg = auth_get_authserver($_POST['authmode']);
	if (!$authcfg) {
		$input_errors[] =  sprintf(gettext('%s is not a valid authentication server'), $_POST['authmode']);
	}

	if (empty($_POST['username'])) {
		$input_errors[] = gettext("A username and password must be specified.");
	}

	if (!$input_errors) {
		$attributes = array();
		if (authenticate_user($_POST['username'], $_POST['password'], $authcfg, $attributes)) {
			$auth_ok = true;
			$groups = getUserGroups($_POST['username'], $authcfg, $attributes);
		} else {
			$auth_ok = false;
			$input_errors[] = gettext("Authentication failed.");
		}
	}
} else {
	if (config_path_enabled('system/webgui', 'authmode')) {
		$pconfig['authmode'] = config_get_path('system/webgui/authmode');
	} else {
		$pconfig['authmode'] = "Local Database";
	}
}

$pgtitle = array(gettext("Diagnostics"), gettext("Authentication"));
$shortcut_section = "authentication";
include("head.inc");

if ($input_errors && $auth_ok !== false) {
	print_input_errors($input_errors);
}

$serverlist = array();
foreach (auth_get_authserver_list() as $key => $auth_server) {
	$serverlist[$key] = $auth_server['name'];
}
?>

<style>
.fs-auth-groups { padding: 0 var(--fs-sp-4) var(--fs-sp-4); }
.fs-auth-groups h3 { margin: 0 0 var(--fs-sp-2); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 600; }
</style>

<div class="fs-tool">
	<form method="post" action="diag_authentication.php" class="fs-tool-form" autocomplete="off">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Authentication test')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="authmode"><?=gettext('Authentication server')?></label>
					<select class="form-select" id="authmode" name="authmode">
<?php foreach ($serverlist as $k => $v): ?>
						<option value="<?=htmlspecialchars($k)?>"<?=($pconfig['authmode'] == $k) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="form-label" for="username"><?=gettext('Username')?></label>
					<input class="form-control" type="text" id="username" name="username" value="<?=htmlspecialchars($pconfig['username'])?>" placeholder="<?=gettext('Username')?>" autocomplete="new-password" required>
				</div>
				<div>
					<label class="form-label" for="password"><?=gettext('Password')?></label>
					<input class="form-control" type="password" id="password" name="password" value="" placeholder="<?=gettext('Password')?>" autocomplete="new-password">
				</div>
				<div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="debug" name="debug" value="yes"<?=($_POST['debug'] == 'yes') ? ' checked' : ''?>>
						<label class="form-check-label" for="debug"><?=gettext('Set debug flag')?></label>
					</div>
					<div class="form-text"><?=gettext('May add diagnostic entries to the system log, for example for LDAP.')?></div>
				</div>
			</div>
			<div class="panel-footer">
				<button type="submit" class="btn btn-primary" name="Submit" value="Test" data-fs-busy="true">
					<i class="fa-solid fa-user-check icon-embed-btn" aria-hidden="true"></i><?=gettext('Test')?>
				</button>
			</div>
		</div>
	</form>

	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Result')?></h2></div>
<?php if ($auth_ok === true): ?>
		<div class="fs-tool-verdict">
			<?=fs_badge('pass', gettext('Authenticated'))?>
			<span><?=htmlspecialchars(sprintf(gettext('User %s authenticated successfully.'), $_POST['username']))?></span>
		</div>
		<div class="fs-auth-groups">
			<h3><?=gettext('Group membership')?></h3>
<?php if (!empty($groups)): ?>
			<ul class="fs-chips">
<?php foreach ($groups as $group): ?>
				<li class="fs-chip"><?=htmlspecialchars($group)?></li>
<?php endforeach; ?>
			</ul>
<?php else: ?>
			<p class="fs-muted mb-0"><?=gettext('The user is not a member of any group.')?></p>
<?php endif; ?>
		</div>
<?php elseif ($auth_ok === false): ?>
		<div class="fs-tool-verdict">
			<?=fs_badge('block', gettext('Failed'))?>
			<span><?=gettext("Authentication failed.")?></span>
		</div>
<?php else: ?>
		<div class="fs-tool-empty">
			<i class="fa-solid fa-user-check" aria-hidden="true"></i>
			<span><?=gettext('Checks a username and password against an authentication server and lists the groups the user belongs to.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>

<?php
include("foot.inc");
