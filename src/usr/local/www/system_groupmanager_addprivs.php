<?php
/*
 * system_groupmanager_addprivs.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

##|+PRIV
##|*IDENT=page-system-groupmanager-addprivs
##|*NAME=System: Group Manager: Add Privileges
##|*DESCR=Allow access to the 'System: Group Manager: Add Privileges' page.
##|*WARN=standard-warning-root
##|*MATCH=system_groupmanager_addprivs.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("system_usermanager.inc");

$groupid = $_REQUEST['groupid'];

$pgtitle = array(gettext("System"), gettext("User Manager"), gettext("Groups"), gettext("Edit"), gettext("Add Privileges"));
$pglinks = array("", "system_usermanager.php", "system_groupmanager.php", "system_groupmanager.php?act=edit&groupid=" . $groupid, "@self");

/* The group must exist (an unknown position created a group entry holding only the privileges). */
if (!is_numericint($groupid) || !is_array(config_get_path("system/group/{$groupid}"))) {
	FreeSenseHeader("system_groupmanager.php");
	exit;
}

// Make a local copy and sort it
$spriv_list = usermgr_priv_list_sorted();

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

if ($_POST['save'] && !$read_only) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = usermgr_group_privs_add($groupid, $_POST, $guiuser);
	if (!$input_errors) {
		FreeSenseHeader("system_groupmanager.php?act=edit&groupid={$groupid}");
		exit;
	}
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('system-usermanager', 'system_groupmanager.php');

/* checklist descriptions and administrator-level flags of the privileges that can still be added */
$priv_descs = [];
$priv_warn = [];
foreach (usermgr_priv_choices($spriv_list, config_get_path("system/group/{$groupid}/priv", [])) as $pname => $plabel) {
	$priv_descs[$pname] = preg_replace("/FreeSense/i", g_get('product_label'), (string)$spriv_list[$pname]['descr']);
	if (($spriv_list[$pname]['warn'] ?? '') == 'standard-warning-root') {
		$priv_warn[] = $pname;
	}
}

$form = new Form;
if (isset($groupid)) {
	$form->addGlobal(new Form_Input(
		'groupid',
		null,
		'hidden',
		$groupid
	));
}

$section = new Form_Section('Group Privileges');

$name_string = config_get_path("system/group/{$groupid}/name");
if (!empty(config_get_path("system/group/{$groupid}/descr"))) {
	$name_string .= ' (' . config_get_path("system/group/{$groupid}/descr") . ')';
}

$section->addInput(new Form_StaticText(
	'Group',
	$name_string
));

$section->addInput(new Form_Select(
	'sysprivs',
	'*Assigned privileges',
	config_get_path("system/group/{$groupid}/priv", []),
	usermgr_priv_choices($spriv_list, config_get_path("system/group/{$groupid}/priv", [])),
	true
))->setAttribute('data-fs-checklist', '')
  ->setAttribute('data-fs-descs', json_encode($priv_descs))
  ->setAttribute('data-fs-warn', json_encode($priv_warn))
  ->setAttribute('data-fs-text-search', gettext('Search privileges…'))
  ->setAttribute('data-fs-text-only', gettext('Selected only'))
  ->setAttribute('data-fs-text-count', gettext('%d selected'))
  ->setAttribute('data-fs-text-empty', gettext('No privilege matches the search.'))
  ->setAttribute('data-fs-text-warn', gettext('Admin-level'))
  ->setHelp('Tick the privileges to add. Search matches names and descriptions.');

$section->addInput(new Form_StaticText(
	gettext('Privilege information'),
	'<span class="help-block">'.
	gettext('The following privileges effectively give administrator-level access to users in the group' .
		' because the user gains access to execute general commands, edit system files, ' .
		' modify users, change passwords or similar:') .
	'<br/>' .
	usermgr_root_priv_text() .
	'<br/><br/>' .
	gettext('Please take care when granting these privileges.') .
	'</span>'
));

$form->add($section);

fs_form_cancel($form, 'system_groupmanager.php?act=edit&groupid=' . urlencode($groupid));
print $form;

?>
<?php
include('foot.inc');
