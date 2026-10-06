<?php
/*
 * system_usermanager.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc.
 * Copyright (c) 2005 Paul Taylor <paultaylor@winn-dixie.com>
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

##|+PRIV
##|*IDENT=page-system-usermanager
##|*NAME=System: User Manager
##|*DESCR=Allow access to the 'System: User Manager' page.
##|*WARN=standard-warning-root
##|*MATCH=system_usermanager.php*
##|-PRIV

require_once("certs.inc");
require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("system_usermanager.inc");

$cert_keylens = array("1024", "2048", "3072", "4096", "6144", "7680", "8192", "15360", "16384");
$cert_keytypes = array("RSA", "ECDSA");
$openssl_ecnames = cert_build_curve_list();

global $openssl_digest_algs;

$password_extra_help = get_validate_password_hints();

// start admin user code
if (isset($_REQUEST['userid']) && is_numericint($_REQUEST['userid'])) {
	$id = $_REQUEST['userid'];
}

$act = $_REQUEST['act'];

if (isset($_SERVER['HTTP_REFERER'])) {
	$referer = $_SERVER['HTTP_REFERER'];
} else {
	$referer = '/system_usermanager.php';
}

if (isset($id)) {
	$this_user = config_get_path("system/user/{$id}");
}
if ($this_user) {
	$pconfig = usermgr_user_form($this_user);
}

/*
 * Check user privileges to test if the user is allowed to make changes.
 * Otherwise users can end up in an inconsistent state where some changes are
 * performed and others denied. See upstream issue 9259
 */
phpsession_begin();
$guiuser = getUserEntry($_SESSION['Username']);
$guiuser = $guiuser['item'];
$read_only = (is_array($guiuser) && userHasPrivilege($guiuser, "user-config-readonly"));
phpsession_end();

if (!empty($_POST) && $read_only) {
	$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
}

if (($_POST['act'] == "deluser") && !$read_only) {

	$rv = usermgr_user_delete($id ?? null, $_POST['username'] ?? null, $guiuser);
	if ($rv === null) {
		FreeSenseHeader("system_usermanager.php");
		exit;
	}
	if (!empty($rv['errors'])) {
		$delete_errors = $rv['errors'];
	}
	if ($rv['deleted']) {
		$savemsg = $rv['savemsg'];
	}

} else if ($act == "new") {
	/*
	 * set this value cause the text field is read only
	 * and the user should not be able to mess with this
	 * setting.
	 */
	$pconfig['utype'] = "user";
	$pconfig['lifetime'] = 3650;

	$nonPrvCas = usermgr_user_cert_cas();

}

if (isset($_POST['dellall']) && !$read_only) {

	$rv = usermgr_users_delete($_POST['delete_check'], $guiuser);
	if (!empty($rv['errors'])) {
		$delete_errors = $rv['errors'];
	}
	if ($rv['savemsg'] !== null) {
		$savemsg = $rv['savemsg'];
	}
}

if (($_POST['act'] == "delcert") && !$read_only) {

	$rv = usermgr_user_cert_remove($id ?? null, $_POST['certid'], $guiuser);
	if ($rv === null) {
		FreeSenseHeader("system_usermanager.php");
		exit;
	}
	if (!empty($rv['errors'])) {
		$delete_errors = $rv['errors'];
	} else {
		$savemsg = $rv['savemsg'];
	}
	$_POST['act'] = "edit";
}

if (($_POST['act'] == "delprivid") && !$read_only && isset($id)) {
	$rv = usermgr_user_priv_remove($id, $_POST['privid'], $guiuser);
	if (!empty($rv['errors'])) {
		$delete_errors = $rv['errors'];
	} else {
		$savemsg = $rv['savemsg'];
	}
	$_POST['act'] = "edit";
}

if ($_POST['save'] && !$read_only) {
	unset($input_errors);
	$input_errors = usermgr_user_save($_POST, $id ?? null, $guiuser, $pconfig, $savemsg);

	if (!$input_errors) {
		FreeSenseHeader("system_usermanager.php");
	}
}

$pgtitle = array(gettext("System"), gettext("User Manager"), gettext("Users"));
$pglinks = array("", "system_usermanager.php", "system_usermanager.php");

if ($act == "new" || $act == "edit" || $input_errors) {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}

include("head.inc");

if ($delete_errors) {
	print_input_errors($delete_errors);
}

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

fs_tabs('system-usermanager', 'system_usermanager.php');

if (!($act == "new" || $act == "edit" || $input_errors)) {
?>
<form method="post">
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Users')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm sortable-theme-bootstrap table-rowdblclickedit" data-sortable>
				<thead>
					<tr>
						<th>&nbsp;</th>
						<th><?=gettext("Username")?></th>
						<th><?=gettext("Full name")?></th>
						<th><?=gettext("Status")?></th>
						<th><?=gettext("Groups")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php
foreach (config_get_path('system/user', []) as $i => $userent):
	?>
					<tr>
						<td>
							<input type="checkbox" id="frc<?=$i?>" name="delete_check[]" value="<?=$i?>" <?=((($userent['scope'] == "system") || ($userent['name'] == $_SESSION['Username'])) ? 'disabled' : '')?>/>
						</td>
						<td>
<?php
	if ($userent['scope'] != "user") {
		$usrimg = 'fa-regular fa-eye';
	} else {
		$usrimg = 'fa-solid fa-user';
	}
?>
							<i class="<?=$usrimg?>" title="<?= gettext("Scope") . ": {$userent['scope']}" ?>"></i>
							<?=htmlspecialchars($userent['name'])?>
						</td>
						<td><?=htmlspecialchars($userent['descr'])?></td>
						<td><i class="<?= (isset($userent['disabled'])) ? 'fa-solid fa-ban" title="' . gettext("Disabled") . '"' : 'fa-solid fa-check" title="' . gettext("Enabled") . '"' ; ?>"><span style='display: none'><?= (isset($userent['disabled'])) ? gettext("Disabled") : gettext("Enabled") ; ?></span></i></td>
						<td><?=implode(",", local_user_get_groups($userent))?></td>
						<td>
							<a class="fa-solid fa-pencil" title="<?=gettext("Edit user"); ?>" href="?act=edit&amp;userid=<?=$i?>"></a>
<?php if (($userent['scope'] != "system") && ($userent['name'] != $_SESSION['Username']) && !$read_only): ?>
							<a class="fa-solid fa-trash-can"	title="<?=gettext("Delete user")?>" href="?act=deluser&amp;userid=<?=$i?>&amp;username=<?=$userent['name']?>" usepost></a>
<?php endif; ?>
						</td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<nav class="action-buttons">
	<?php if (!$read_only): ?>

	<a href="?act=new" class="btn btn-sm btn-success">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>

	<button type="submit" class="btn btn-sm btn-danger" name="dellall" value="dellall" title="<?=gettext('Delete selected users')?>">
		<i class="fa-solid fa-trash-can icon-embed-btn"></i>
		<?=gettext("Delete")?>
	</button>
	<?php endif; ?>

</nav>
</form>
<div class="infoblock">
<?php
	print_callout('<p>' . gettext("Additional users can be added here. User permissions for accessing " .
		"the webConfigurator can be assigned directly or inherited from group memberships. " .
		"Some system object properties can be modified but they cannot be deleted.") . '</p>' .
		'<p>' . gettext("Accounts added here are also used for other parts of the system " .
		"such as OpenVPN, IPsec, and Captive Portal.") . '</p>'
	);

?></div>

<?php
	include("foot.inc");
	exit;
}

$form = new Form;

if ($act == "new" || $act == "edit" || $input_errors):

	$form->addGlobal(new Form_Input(
		'act',
		null,
		'hidden',
		$act
	));

	$form->addGlobal(new Form_Input(
		'userid',
		null,
		'hidden',
		isset($id) ? $id:''
	));

	$form->addGlobal(new Form_Input(
		'privid',
		null,
		'hidden',
		''
	));

	$form->addGlobal(new Form_Input(
		'certid',
		null,
		'hidden',
		''
	));

	$ro = "";
	if ($pconfig['utype'] == "system") {
		$ro = "readonly";
	}

	$section = new Form_Section('User Properties');

	$section->addInput(new Form_StaticText(
		'Defined by',
		strtoupper($pconfig['utype'])
	));

	$form->addGlobal(new Form_Input(
		'utype',
		null,
		'hidden',
		$pconfig['utype']
	));

	$section->addInput(new Form_Checkbox(
		'disabled',
		'Disabled',
		'This user cannot login',
		$pconfig['disabled']
	));

	$section->addInput($input = new Form_Input(
		'usernamefld',
		'*Username',
		'text',
		$pconfig['usernamefld'],
		['autocomplete' => 'new-password']
	));

	if ($ro) {
		$input->setReadonly();
	}

	$form->addGlobal(new Form_Input(
		'oldusername',
		null,
		'hidden',
		$pconfig['usernamefld']
	));

	if ($act == "edit") {
		$pwd_required = "";
	} else {
		$pwd_required = "*";
	}

	$group = new Form_Group($pwd_required . 'Password');
	$group->add(new Form_Input(
		'passwordfld1',
		'Password',
		'password',
		null,
		['autocomplete' => 'new-password']
	))->setHelp('Enter a new password.' .
			'%1$s%1$s' .
			'Hints:%1$s' .
			' %2$s', '<br/>', $password_extra_help);
	$group->add(new Form_Input(
		'passwordfld2',
		'Confirm Password',
		'password',
		null,
		['autocomplete' => 'new-password']
	))->setHelp('Type the new password again for confirmation.');

	$section->add($group);

	$section->addInput($input = new Form_Input(
		'descr',
		'Full name',
		'text',
		$pconfig['descr']
	))->setHelp('User\'s full name, for administrative information only');

	if ($ro) {
		$input->setDisabled();
	}

	$section->addInput(new Form_Input(
		'expires',
		'Expiration date',
		'text',
		$pconfig['expires']
	))->setHelp('Leave blank if the account shouldn\'t expire, otherwise enter '.
		'the expiration date as MM/DD/YYYY');

	$section->addInput(new Form_Checkbox(
		'customsettings',
		'Custom Settings',
		'Use individual customized GUI options and dashboard layout for this user.',
		$pconfig['customsettings']
	));

	gen_user_settings_fields($section, $pconfig);

	// ==== Group membership ==================================================
	$group = new Form_Group('Group membership');

	// Make a list of all the groups configured on the system, and a list of
	// those which this user is a member of
	$systemGroups = array();
	$usersGroups = array();

	$usergid = [$pconfig['usernamefld']];

	foreach (config_get_path('system/group', []) as $Ggroup) {
		if ($Ggroup['name'] != "all") {
			if (($act == 'edit' || $input_errors) && $Ggroup['member'] && is_numericint($id) && in_array(config_get_path("system/user/{$id}/uid", []), $Ggroup['member'])) {
				$usersGroups[ $Ggroup['name'] ] = $Ggroup['name'];	// Add it to the user's list
			} else {
				$systemGroups[ $Ggroup['name'] ] = $Ggroup['name']; // Add it to the 'not a member of' list
			}
		}
	}

	$group->add(new Form_Select(
		'sysgroups',
		null,
		array_combine((array)$pconfig['groups'], (array)$pconfig['groups']),
		$systemGroups,
		true
	))->setHelp('Not member of');

	$group->add(new Form_Select(
		'groups',
		null,
		array_combine((array)$pconfig['groups'], (array)$pconfig['groups']),
		$usersGroups,
		true
	))->setHelp('Member of');

	$section->add($group);

	$group = new Form_Group('');

	$group->add(new Form_Button(
		'movetoenabled',
		'Move to "Member of" list',
		null,
		'fa-solid fa-angles-right'
	))->setAttribute('type','button')->removeClass('btn-primary')->addClass('btn-info btn-sm');

	$group->add(new Form_Button(
		'movetodisabled',
		'Move to "Not member of" list',
		null,
		'fa-solid fa-angles-left'
	))->setAttribute('type','button')->removeClass('btn-primary')->addClass('btn-info btn-sm');

	$group->setHelp('Hold down CTRL (PC)/COMMAND (Mac) key to select multiple items.');
	$section->add($group);

	// ==== Button for adding user certificate ================================
	if ($act == 'new') {
		if (count($nonPrvCas) > 0) {
			$section->addInput(new Form_Checkbox(
				'createcert',
				'Certificate',
				'Create a user certificate',
				false
			));
		} else {
			$section->addInput(new Form_StaticText(
				'Certificate',
				gettext('No private CAs found. A private CA is required to create a new user certificate. ' .
					'Save the user first to import an external certificate.')
			));
		}
	}

	$form->add($section);

	// ==== Effective privileges section ======================================
	if (isset($pconfig['uid'])) {
		// We are going to build an HTML table and add it to an Input_StaticText. It may be ugly, but it
		// is the best way to make the display we need.

		$section = new Form_Section('Effective Privileges');

		$section->addInput(new Form_StaticText(
			null,
			usermgr_user_priv_table($id, $read_only)
		));

		/* REST API access is the "WebCfg - System: REST API access" privilege above. */
		require_once('restapi.inc');
		$api_keys = count(array_filter(restapi_tokens(), function ($t) use ($this_user) {
			return ($t['username'] ?? '') === ($this_user['name'] ?? '');
		}));
		$api_text = restapi_user_has_access($this_user) ?
		    '<i class="fa-solid fa-check text-success"></i> ' . gettext('Allowed') :
		    '<i class="fa-solid fa-xmark text-muted"></i> ' . gettext('Not allowed (add the "WebCfg - System: REST API access" privilege)');
		$api_text .= ' &middot; ' . sprintf(ngettext('%d API key', '%d API keys', $api_keys), $api_keys);
		if (isAllowedPage('system_restapi.php')) {
			$api_text .= ' &middot; <a href="system_restapi.php">' . gettext('Manage API keys') . '</a>';
		}
		$section->addInput(new Form_StaticText(
			gettext('REST API'),
			$api_text
		));

		$form->add($section);

		// ==== Certificate table section =====================================
		$section = new Form_Section('User Certificates');

		$section->addInput(new Form_StaticText(
			null,
			usermgr_user_cert_table($id, $read_only)
		));

		$form->add($section);
	}

	// ==== Add user certificate for a new user
	if (count(config_get_path('ca', [])) > 0) {
		$section = new Form_Section('Create Certificate for User');
		$section->addClass('cert-options');

		if (!empty($nonPrvCas)) {
			$section->addInput(new Form_Input(
				'name',
				'Descriptive name',
				'text',
				$pconfig['name']
			));

			$section->addInput(new Form_Select(
				'caref',
				'Certificate authority',
				null,
				$nonPrvCas
			));

			$section->addInput(new Form_Select(
				'keytype',
				'*Key type',
				$pconfig['keytype'],
				array_combine($cert_keytypes, $cert_keytypes)
			));

			$group = new Form_Group($i == 0 ? '*Key length':'');
			$group->addClass('rsakeys');
			$group->add(new Form_Select(
				'keylen',
				null,
				$pconfig['keylen'] ? $pconfig['keylen'] : '2048',
				array_combine($cert_keylens, $cert_keylens)
			))->setHelp('The length to use when generating a new RSA key, in bits. %1$s' .
				'The Key Length should not be lower than 2048 or some platforms ' .
				'may consider the certificate invalid.', '<br/>');
			$section->add($group);

			$group = new Form_Group($i == 0 ? '*Elliptic Curve Name':'');
			$group->addClass('ecnames');
			$group->add(new Form_Select(
				'ecname',
				null,
				$pconfig['ecname'] ? $pconfig['ecname'] : 'prime256v1',
				$openssl_ecnames
			))->setHelp('Curves may not be compatible with all uses. Known compatible curve uses are denoted in brackets.');
			$section->add($group);

			$section->addInput(new Form_Select(
				'digest_alg',
				'*Digest Algorithm',
				$pconfig['digest_alg'] ? $pconfig['digest_alg'] : 'sha256',
				array_combine($openssl_digest_algs, $openssl_digest_algs)
			))->setHelp('The digest method used when the certificate is signed. %1$s' .
				'The best practice is to use an algorithm stronger than SHA1. '.
				'Some platforms may consider weaker digest algorithms invalid', '<br/>');

			$section->addInput(new Form_Input(
				'lifetime',
				'Lifetime',
				'number',
				$pconfig['lifetime']
			));
		}

		$form->add($section);
	}

endif;
// ==== Paste a key for the new user
$section = new Form_Section('Keys');

$section->addInput(new Form_Checkbox(
	'showkey',
	'Authorized keys',
	'Click to paste an authorized key',
	false
));

$section->addInput(new Form_Textarea(
	'authorizedkeys',
	'Authorized SSH Keys',
	$pconfig['authorizedkeys']
))->setHelp('Enter authorized SSH keys for this user');

$section->addInput(new Form_Input(
	'ipsecpsk',
	'IPsec Pre-Shared Key',
	'text',
	$pconfig['ipsecpsk']
));

$form->add($section);

$section = new Form_Section('Shell Behavior');

$section->addInput(new Form_Checkbox(
	'keephistory',
	'Keep Command History',
	'Keep shell command history between login sessions',
	$pconfig['keephistory']
))->setHelp('If this user has shell access, this option preserves the last 1000 unique commands entered at a shell prompt between login sessions. ' .
		'The user can access history using the up and down arrows at an SSH or console shell prompt ' .
		'and search the history by typing a partial command and then using the up or down arrows.');

$form->add($section);

print $form;

$csswarning = sprintf(gettext("%sUser-created themes are unsupported, use at your own risk."), "<br />");
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	function setcustomoptions() {
		var adv = $('#customsettings').prop('checked');

		hideInput('webguicss', !adv);
		hideInput('webguifixedmenu', !adv);
		hideInput('webguihostnamemenu', !adv);
		hideInput('dashboardcolumns', !adv);
		hideCheckbox('interfacessort', !adv);
		hideCheckbox('dashboardavailablewidgetspanel', !adv);
		hideCheckbox('systemlogsfilterpanel', !adv);
		hideCheckbox('systemlogsmanagelogpanel', !adv);
		hideCheckbox('statusmonitoringsettingspanel', !adv);
		hideCheckbox('webguileftcolumnhyper', !adv);
		hideCheckbox('disablealiaspopupdetail', !adv);
		hideCheckbox('pagenamefirst', !adv);
	}

	// Handle displaying a warning message if a user-created theme is selected.
	function setThemeWarning() {
		if ($('#webguicss').val().startsWith("FreeSense")) {
			$('#csstxt').html("").addClass("text-default");
		} else {
			$('#csstxt').html("<?=$csswarning?>").addClass("text-danger");
		}
	}

	function change_keytype() {
		hideClass('rsakeys', ($('#keytype').val() != 'RSA'));
		hideClass('ecnames', ($('#keytype').val() != 'ECDSA'));
	}

	$('#webguicss').change(function() {
		setThemeWarning();
	});

	setThemeWarning();

	// On click . .
	$('#customsettings').click(function () {
		setcustomoptions();
	});

	$("#movetodisabled").click(function() {
		moveOptions($('[name="groups[]"] option'), $('[name="sysgroups[]"]'));
	});

	$("#movetoenabled").click(function() {
		moveOptions($('[name="sysgroups[]"] option'), $('[name="groups[]"]'));
	});

	$("#createcert").click(function() {
		hideClass('cert-options', !this.checked);
	});

	$("#showkey").click(function() {
		hideInput('authorizedkeys', false);
		hideCheckbox('showkey', true);
	});

	$('[id^=delcert]').click(function(event) {
		if (confirm(event.target.title)) {
			$('#certid').val(event.target.id.match(/\d+$/)[0]);
			$('#userid').val('<?=$id;?>');
			$('#act').val('delcert');
			$('form').submit();
		}
	});

	$('[id^=delprivid]').click(function(event) {
		if (confirm(event.target.title)) {
			$('#privid').val(event.target.id.match(/\d+$/)[0]);
			$('#userid').val('<?=$id;?>');
			$('#act').val('delprivid');
			$('form').submit();
		}
	});

	$('#expires').datepicker();

	$('#keytype').change(function () {
		change_keytype();
	});

	// ---------- On initial page load ------------------------------------------------------------

	hideClass('cert-options', true);
	//hideInput('authorizedkeys', true);
	hideCheckbox('showkey', true);
	setcustomoptions();
	change_keytype();

	// On submit mark all the user's groups as "selected"
	$('form').submit(function() {
		AllServers($('[name="groups[]"] option'), true);
	});

});
//]]>
</script>
<?php
include('foot.inc');
?>
