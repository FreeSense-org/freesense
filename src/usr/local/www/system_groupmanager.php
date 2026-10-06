<?php
/*
 * system_groupmanager.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2005 Paul Taylor <paultaylor@winn-dixie.com>
 * Copyright (c) 2008 Shrew Soft Inc
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
##|*IDENT=page-system-groupmanager
##|*NAME=System: Group Manager
##|*DESCR=Allow access to the 'System: Group Manager' page.
##|*WARN=standard-warning-root
##|*MATCH=system_groupmanager.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("system_usermanager.inc");

$id = is_numericint($_REQUEST['groupid']) ? $_REQUEST['groupid'] : null;
$act = (isset($_REQUEST['act']) ? $_REQUEST['act'] : '');

$dup = null;

if ($act == 'dup') {
	$dup = $id;
	$act = 'edit';
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

if (($_POST['act'] == "delgroup") && !$read_only) {

	$rv = usermgr_group_delete($id, $_REQUEST['groupname'] ?? null, $guiuser);
	if ($rv === null) {
		FreeSenseHeader("system_groupmanager.php");
		exit;
	}
	if ($rv['deleted']) {
		$savemsg = $rv['savemsg'];
	} else {
		$input_errors = $rv['errors'];
	}
}

if (($_POST['act'] == "delpriv") && !$read_only && ($dup === null)) {

	$rv = usermgr_group_priv_remove($id, $_REQUEST['privid'], $guiuser);
	if ($rv === null) {
		FreeSenseHeader("system_groupmanager.php");
		exit;
	}
	if (!empty($rv['errors'])) {
		$input_errors = $rv['errors'];
	} else {
		$savemsg = $rv['savemsg'];
	}

	$act = "edit";
}

if ($act == "edit") {
	if (isset($id)) {
		$pconfig = usermgr_group_form($id, $dup);
	}
}

if (isset($_POST['dellall_x']) && !$read_only) {

	$rv = usermgr_groups_delete($_POST['delete_check'], $guiuser);
	if (!empty($rv['errors'])) {
		$input_errors = $rv['errors'];
	}
	if ($rv['savemsg'] !== null) {
		$savemsg = $rv['savemsg'];
	}
}

if (isset($_POST['save']) && !$read_only) {
	unset($input_errors);
	$input_errors = usermgr_group_save($_POST, $id, $guiuser, $pconfig, $savemsg);

	if (!$input_errors) {
		header("Location: system_groupmanager.php");
		exit;
	}

	$pconfig['name'] = $_POST['groupname'];
}

$pgtitle = array(gettext("System"), gettext("User Manager"), gettext("Groups"));
$pglinks = array("", "system_usermanager.php", "system_groupmanager.php");

if ($act == "new" || $act == "edit") {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

$tab_array = array();
$tab_array[] = array(gettext("Users"), false, "system_usermanager.php");
$tab_array[] = array(gettext("Groups"), true, "system_groupmanager.php");
$tab_array[] = array(gettext("Settings"), false, "system_usermanager_settings.php");
$tab_array[] = array(gettext("Change Password"), false, "system_usermanager_passwordmg.php");
$tab_array[] = array(gettext("Authentication Servers"), false, "system_authservers.php");
display_top_tabs($tab_array);

if (!($act == "new" || $act == "edit")) {
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Groups')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm sortable-theme-bootstrap table-rowdblclickedit" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Group name")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Member Count")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach (config_get_path('system/group', []) as $i => $group):
		if ($group["name"] == "all") {
			$groupcount = count(config_get_path('system/user', []));
		} elseif (is_array($group['member'])) {
			$groupcount = count($group['member']);
		} else {
			$groupcount = 0;
		}
?>
					<tr>
						<td>
							<?=htmlspecialchars($group['name'])?>
						</td>
						<td>
							<?=htmlspecialchars($group['description'])?>
						</td>
						<td>
							<?=$groupcount?>
						</td>
						<td>
							<a class="fa-solid fa-pencil" title="<?=gettext("Edit group"); ?>" href="?act=edit&amp;groupid=<?=$i?>"></a>
							<a class="fa-regular fa-clone" title="<?=gettext("Copy group"); ?>" href="?act=dup&amp;groupid=<?=$i?>"></a>
							<?php if (($group['scope'] != "system") && !$read_only): ?>
								<a class="fa-solid fa-trash-can"	title="<?=gettext("Delete group")?>" href="?act=delgroup&amp;groupid=<?=$i?>&amp;groupname=<?=$group['name']?>" usepost></a>
							<?php endif;?>
						</td>
					</tr>
<?php
	endforeach;
?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<?php if (!$read_only): ?>
	<a href="?act=new" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>
	<?php endif; ?>
</nav>
<?php
	include('foot.inc');
	exit;
}

$form = new Form;
$form->setAction('system_groupmanager.php?act=edit');
if ($dup === null) {
	$form->addGlobal(new Form_Input(
		'groupid',
		null,
		'hidden',
		$id
	));
} else {
	$form->addGlobal(new Form_Input(
		'dup',
		null,
		'hidden',
		$dup
	));
}

if (isset($id) && config_get_path("system/group/{$id}")) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));

	$form->addGlobal(new Form_Input(
		'gid',
		null,
		'hidden',
		$pconfig['gid']
	));
}

$section = new Form_Section('Group Properties');

$section->addInput($input = new Form_Input(
	'groupname',
	'*Group name',
	'text',
	$pconfig['name']
));

if ($pconfig['gtype'] == "system") {
	$input->setReadonly();

	$section->addInput(new Form_Input(
		'gtype',
		'*Scope',
		'text',
		$pconfig['gtype']
	))->setReadonly();
} else {
	$section->addInput(new Form_Select(
		'gtype',
		'*Scope',
		$pconfig['gtype'],
		["local" => gettext("Local"), "remote" => gettext("Remote")]
	))->setHelp("<span class=\"text-danger\">Warning: Changing this " .
	    "setting may affect the local groups file, in which case a " .
	    "reboot may be required for the changes to take effect.</span>");
}

$section->addInput(new Form_Input(
	'description',
	'Description',
	'text',
	$pconfig['description']
))->setHelp('Group description, for administrative information only');

$form->add($section);

/* all users group */
if ($pconfig['gid'] != 1998) {
	/* Group membership */
	$group = new Form_Group('Group membership');

	/*
	 * Make a list of all the groups configured on the system, and a list of
	 * those which this user is a member of
	 */
	$systemGroups = array();
	$usersGroups = array();

	foreach (config_get_path('system/user', []) as $user) {
		if (is_array($pconfig['members']) && in_array($user['uid'],
		    $pconfig['members'])) {
			/* Add it to the user's list */
			$usersGroups[ $user['uid'] ] = $user['name'];
		} else {
			/* Add it to the 'not a member of' list */
			$systemGroups[ $user['uid'] ] = $user['name'];
		}
	}

	$group->add(new Form_Select(
		'notmembers',
		null,
		array_combine((array)$pconfig['groups'],
		    (array)$pconfig['groups']),
		$systemGroups,
		true
	))->setHelp('Not members');

	$group->add(new Form_Select(
		'members',
		null,
		array_combine((array)$pconfig['groups'],
		    (array)$pconfig['groups']),
		$usersGroups,
		true
	))->setHelp('Members');

	$section->add($group);

	$group = new Form_Group('');

	$group->add(new Form_Button(
		'movetoenabled',
		'Move to "Members"',
		null,
		'fa-solid fa-angles-right'
	))->setAttribute('type','button')->removeClass('btn-primary')->addClass(
	    'btn-info btn-sm');

	$group->add(new Form_Button(
		'movetodisabled',
		'Move to "Not members',
		null,
		'fa-solid fa-angles-left'
	))->setAttribute('type','button')->removeClass('btn-primary')->addClass(
	    'btn-info btn-sm');

	$group->setHelp(
	    'Hold down CTRL (PC)/COMMAND (Mac) key to select multiple items.');
	$section->add($group);

}

if (isset($pconfig['gid']) || ($dup !== null)) {
	$section = new Form_Section('Assigned Privileges');

	$section->addInput(new Form_StaticText(
		null,
		usermgr_group_priv_table($id, $read_only, $dup)
	));


	$form->add($section);
}

print $form;
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// On click . .
	$("#movetodisabled").click(function() {
		moveOptions($('[name="members[]"] option'),
		    $('[name="notmembers[]"]'));
	});

	$("#movetoenabled").click(function() {
		moveOptions($('[name="notmembers[]"] option'),
		    $('[name="members[]"]'));
	});

	// On submit mark all the user's groups as "selected"
	$('form').submit(function() {
		AllServers($('[name="members[]"] option'), true);
	});
});
//]]>
</script>
<?php
include('foot.inc');
