<?php
/*
 * account.php
 *
 * part of FreeSense (https://www.freesense.org)
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

/*
 * The signed-in user's account in one place: profile, appearance, preferences
 * and security (sessions, API keys). Profile and the per-user options of
 * earlier versions live in config.xml; appearance, start page, language and the
 * dashboard's live default are small per-user files (webui_prefs.inc) that the
 * WebUI applies at once, and that the REST API serves under /api/v1/me.
 */

##|+PRIV
##|*IDENT=page-system-user-settings
##|*NAME=System: Account
##|*DESCR=Allow access to the 'Account' page: your profile, appearance, preferences and sessions.
##|*MATCH=account.php*
##|-PRIV

require_once("auth.inc");
require_once("guiconfig.inc");
require_once("webui_prefs.inc");
require_once("restapi.inc");

$pgtitle = array(gettext("Account"));
$username = (string)$_SESSION['Username'];

$tabs = array(
	'profile' => gettext('Profile'),
	'appearance' => gettext('Appearance'),
	'preferences' => gettext('Preferences'),
	'security' => gettext('Security'),
);
$tab = (isset($_REQUEST['tab']) && isset($tabs[$_REQUEST['tab']])) ? $_REQUEST['tab'] : 'profile';

$entry = getUserEntry($username);
$local = isset($entry['idx']) && is_numericint($entry['idx']);
$id = $local ? $entry['idx'] : null;
$this_user = $local ? config_get_path("system/user/{$id}") : null;

$prefs = webui_prefs_get($username);
$input_errors = array();
$savemsg = '';

/* The per-user options kept in config.xml since earlier versions. */
$legacy_flags = array('interfacessort', 'systemlogsfilterpanel', 'systemlogsmanagelogpanel', 'statusmonitoringsettingspanel',
    'webguileftcolumnhyper', 'disablealiaspopupdetail', 'pagenamefirst');

$start_pages = array(
	'/' => gettext('Dashboard'),
	'/status_interfaces.php' => gettext('Status > Interfaces'),
	'/firewall_rules.php' => gettext('Firewall > Rules'),
	'/status_logs_filter.php' => gettext('Status > System Logs > Firewall'),
	'/status_gateways.php' => gettext('Status > Gateways'),
	'/status_services.php' => gettext('Status > Services'),
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');

	if ($action === 'profile') {
		if (!$local) {
			$input_errors[] = gettext('The profile of a user from an authentication server is managed on that server.');
		} else {
			list($values, $errors) = webui_profile_validate(array(
				'name' => (string)($_POST['name'] ?? ''), 'email' => (string)($_POST['email'] ?? '')));
			foreach ($errors as $e) {
				$input_errors[] = gettext($e);
			}
			if (!$input_errors) {
				webui_profile_save($username, $values);
				$savemsg = gettext('Profile saved.');
				$this_user = config_get_path("system/user/{$id}");
			}
		}
	} elseif ($action === 'appearance') {
		$in = array();
		foreach (array('mode', 'accent', 'density', 'nav', 'language') as $k) {
			$in[$k] = (string)($_POST[$k] ?? '');
		}
		list($values, $errors) = webui_pref_validate($in);
		foreach ($errors as $e) {
			$input_errors[] = gettext($e);
		}
		if (($in['language'] !== '') && !isset(get_locale_list()[$in['language']])) {
			$input_errors[] = gettext('This language is not available.');
		}
		if (!$input_errors && (webui_prefs_put($username, $values) !== null)) {
			header('Location: /account.php?tab=appearance&saved=1');	/* reload so the new mode, accent and language apply */
			exit;
		}
	} elseif ($action === 'preferences') {
		$start = (string)($_POST['start_page'] ?? '/');
		list($values, $errors) = webui_pref_validate(array('start_page' => $start, 'live' => (string)($_POST['live'] ?? 'on')));
		foreach ($errors as $e) {
			$input_errors[] = gettext($e);
		}
		if ($local) {
			validate_webguifixedmenu_field($input_errors, $_POST['webguifixedmenu'] ?? '');
			validate_webguihostnamemenu_field($input_errors, $_POST['webguihostnamemenu'] ?? '');
		}
		if (!$input_errors) {
			webui_prefs_put($username, $values);
			if ($local) {
				$ent = config_get_path("system/user/{$id}");
				foreach (array('webguifixedmenu', 'webguihostnamemenu') as $k) {
					if (!empty($_POST[$k])) {
						$ent[$k] = $_POST[$k];
					} else {
						unset($ent[$k]);
					}
				}
				foreach ($legacy_flags as $k) {
					if (!empty($_POST[$k])) {
						$ent[$k] = true;
					} else {
						unset($ent[$k]);
					}
				}
				unset($ent['dashboardcolumns'], $ent['dashboardavailablewidgetspanel'], $ent['widgets']);
				config_set_path("system/user/{$id}", $ent);
				write_config(sprintf(gettext('Preferences of %s changed in the WebUI.'), $username));
				$this_user = $ent;
			}
			$prefs = webui_prefs_get($username);
			$savemsg = gettext('Preferences saved.');
		}
	} elseif ($action === 'reset_dashboard') {
		webui_layout_delete($username);
		$savemsg = gettext('Your dashboard is back to the default layout.');
	} elseif ($action === 'revoke_session') {
		$r = webui_session_revoke($username, (string)($_POST['session'] ?? ''), session_id());
		if ($r === 'ok') {
			$savemsg = gettext('The session was signed out.');
		} elseif ($r === 'current') {
			$input_errors[] = gettext('This is the session you are using; sign out from the account menu instead.');
		} else {
			$input_errors[] = gettext('No such session.');
		}
	} elseif ($action === 'revoke_keys') {
		$n = restapi_revoke_user_tokens($username);
		$savemsg = sprintf(gettext('%d API key(s) revoked.'), (int)$n);
	}
}
if (!empty($_GET['saved'])) {
	$savemsg = gettext('Appearance saved.');
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

$display = $local ? trim((string)($this_user['descr'] ?? '')) : '';
$shown_name = ($display !== '') ? $display : $username;
?>
<div class="fs-account">
	<div class="fs-account-card">
		<span class="fs-avatar" aria-hidden="true"><?=htmlspecialchars(webui_initials($shown_name))?></span>
		<div>
			<h2><?=htmlspecialchars($shown_name)?></h2>
			<p>@<?=htmlspecialchars($username)?> &middot; <?=htmlspecialchars($system_url)?><?php
			if ($local) {
				$groups = array_values(local_user_get_groups($this_user));
				if ($groups) {
					echo ' &middot; ' . htmlspecialchars(implode(', ', $groups));
				}
			} else {
				echo ' &middot; ' . htmlspecialchars(gettext('signed in through an authentication server'));
			}
			?></p>
		</div>
	</div>
<?php
$tab_array = array();
foreach ($tabs as $k => $label) {
	$tab_array[] = array($label, $tab === $k, "/account.php?tab={$k}");
}
display_top_tabs($tab_array);

/* One small form per tab; the action field says which one was sent. */
function account_form($tab, $action) {
	$form = new Form(false);
	$form->addGlobal(new Form_Input('tab', null, 'hidden', $tab));
	$form->addGlobal(new Form_Input('action', null, 'hidden', $action));
	return $form;
}

if ($tab === 'profile') {
	$form = account_form('profile', 'profile');
	$section = new Form_Section(gettext('Profile'));
	$section->addInput(new Form_StaticText(gettext('User name'), htmlspecialchars($username)));
	if ($local) {
		$section->addInput(new Form_Input('name', 'Display name', 'text', $_POST['name'] ?? ($this_user['descr'] ?? '')))
		    ->setHelp('Shown next to your avatar. Up to 64 characters.');
		$section->addInput(new Form_Input('email', 'Email', 'email', $_POST['email'] ?? ($this_user['email'] ?? '')))
		    ->setHelp('Optional. Used for notifications addressed to you.');
		$form->addGlobal(new Form_Button('save', gettext('Save'), null, 'fa-solid fa-save'))->addClass('btn-primary');
	} else {
		$section->addInput(new Form_StaticText(gettext('Profile'), gettext('Managed on the authentication server.')));
	}
	$form->add($section);
	print($form);
} elseif ($tab === 'appearance') {
	$form = account_form('appearance', 'appearance');
	$section = new Form_Section(gettext('Appearance'));
	$section->addInput(new Form_Select('mode', 'Mode', $fs_mode, array(
		'light' => gettext('Light'), 'dark' => gettext('Dark'), 'auto' => gettext('Automatic (follow the browser)'))));
	$acc = array();
	foreach ($fs_accents as $k => $l) {
		$acc[$k] = gettext($l);
	}
	$section->addInput(new Form_Select('accent', 'Accent colour', $fs_accent, $acc));
	$section->addInput(new Form_Select('density', 'Density', $prefs['density'], array(
		'comfortable' => gettext('Comfortable'), 'compact' => gettext('Compact'))))
	    ->setHelp('Compact tightens tables, cards and the dashboard.');
	$section->addInput(new Form_Select('nav', 'Menu', $prefs['nav'], array(
		'top' => gettext('Top menu bar'), 'side' => gettext('Sidebar'))))
	    ->setHelp('Both layouts have the same menus. On a phone both become one collapsed menu.');
	$langs = array('' => gettext('System default'));
	foreach (get_locale_list() as $k => $l) {
		$langs[$k] = $l;
	}
	$section->addInput(new Form_Select('language', 'Language', $prefs['language'], $langs))
	    ->setHelp('The language of the WebUI for you only. The system default is set in System > General Setup.');
	$form->addGlobal(new Form_Button('save', gettext('Save'), null, 'fa-solid fa-save'))->addClass('btn-primary');
	$form->add($section);
	print($form);
} elseif ($tab === 'preferences') {
	$form = account_form('preferences', 'preferences');
	$section = new Form_Section(gettext('When you sign in'));
	$section->addInput(new Form_Select('start_page', 'Start page', $prefs['start_page'], $start_pages))
	    ->setHelp('The page that opens after you sign in (unless you were sent to another page).');
	$section->addInput(new Form_Select('live', 'Dashboard updates', $prefs['live'], array(
		'on' => gettext('Live: widgets keep updating'),
		'off' => gettext('Paused: widgets load once; press Live to resume'))));
	$form->add($section);
	if ($local) {
		$pconfig = array(
			'webguifixedmenu' => $this_user['webguifixedmenu'] ?? '',
			'webguihostnamemenu' => $this_user['webguihostnamemenu'] ?? '',
		);
		foreach ($legacy_flags as $k) {
			$pconfig[$k] = isset($this_user[$k]);
		}
		$section2 = new Form_Section(gettext('Pages'));
		gen_webguifixedmenu_field($section2, $pconfig['webguifixedmenu']);
		gen_webguihostnamemenu_field($section2, $pconfig['webguihostnamemenu']);
		gen_interfacessort_field($section2, $pconfig['interfacessort']);
		gen_associatedpanels_fields($section2, $pconfig['systemlogsfilterpanel'], $pconfig['systemlogsmanagelogpanel'],
		    $pconfig['statusmonitoringsettingspanel']);
		gen_webguileftcolumnhyper_field($section2, $pconfig['webguileftcolumnhyper']);
		gen_disablealiaspopupdetail_field($section2, $pconfig['disablealiaspopupdetail']);
		gen_pagenamefirst_field($section2, $pconfig['pagenamefirst']);
		$form->add($section2);
	}
	$form->addGlobal(new Form_Button('save', gettext('Save'), null, 'fa-solid fa-save'))->addClass('btn-primary');
	print($form);

	$reset = account_form('preferences', 'reset_dashboard');
	$rs = new Form_Section(gettext('Dashboard'));
	$rs->addInput(new Form_StaticText(gettext('Layout'), gettext('Put the widgets and their settings back to the default layout.')));
	$reset->addGlobal(new Form_Button('reset', gettext('Reset my dashboard'), null, 'fa-solid fa-arrow-rotate-left'))->addClass('btn-outline-danger');
	$reset->add($rs);
	print($reset);
} else {
	?>
	<div class="card mb-3"><div class="card-header"><h2 class="card-title"><?=gettext('Password')?></h2></div>
	<div class="card-body">
		<?php if ($local && isAllowedPage('system_usermanager_passwordmg.php')): ?>
		<a class="btn btn-primary" href="/system_usermanager_passwordmg.php"><i class="fa-solid fa-key icon-embed-btn" aria-hidden="true"></i><?=gettext('Change password')?></a>
		<?php else: ?>
		<p class="mb-0"><?=gettext('Your password is managed on the authentication server.')?></p>
		<?php endif; ?>
	</div></div>

	<div class="card mb-3"><div class="card-header"><h2 class="card-title"><?=gettext('Signed-in sessions')?></h2></div>
	<div class="card-body">
	<?php foreach (webui_sessions_list($username, session_id()) as $s): ?>
		<form method="post" class="fs-session">
			<input type="hidden" name="tab" value="security"><input type="hidden" name="action" value="revoke_session">
			<input type="hidden" name="session" value="<?=htmlspecialchars($s['id'])?>">
			<i class="fa-solid fa-display" aria-hidden="true"></i>
			<div>
				<strong><?=htmlspecialchars($s['address'])?></strong>
				<?=$s['current'] ? '<span class="badge bg-success ms-1">' . gettext('This session') . '</span>' : ''?>
				<small><?=htmlspecialchars(mb_substr($s['agent'], 0, 120))?></small>
				<small><?=sprintf(gettext('Signed in %s, last seen %s'),
				    htmlspecialchars($s['signed_in'] ? str_replace('T', ' ', substr($s['signed_in'], 0, 16)) . ' UTC' : '-'),
				    htmlspecialchars($s['last_seen'] ? str_replace('T', ' ', substr($s['last_seen'], 0, 16)) . ' UTC' : '-'))?></small>
			</div>
			<?php if (!$s['current']): ?>
			<button type="submit" class="btn btn-sm btn-outline-danger"><?=gettext('Sign out')?></button>
			<?php endif; ?>
		</form>
	<?php endforeach; ?>
	</div></div>

	<div class="card mb-3"><div class="card-header"><h2 class="card-title"><?=gettext('API keys')?></h2></div>
	<div class="card-body">
		<p><?=gettext('Keys you created for the REST API act with your permissions. Revoke them all if one may have leaked; create new ones in System > REST API.')?></p>
		<form method="post">
			<input type="hidden" name="tab" value="security"><input type="hidden" name="action" value="revoke_keys">
			<button type="submit" class="btn btn-outline-danger"><i class="fa-solid fa-ban icon-embed-btn" aria-hidden="true"></i><?=gettext('Revoke all my API keys')?></button>
		</form>
	</div></div>
	<?php
}
?>
</div>
<?php
include("foot.inc");
