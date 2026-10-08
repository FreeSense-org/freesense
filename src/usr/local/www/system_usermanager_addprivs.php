<?php
/*
 * system_usermanager_addprivs.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2006 Daniel S. Haischt.
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
##|*IDENT=page-system-usermanager-addprivs
##|*NAME=System: User Manager: Add Privileges
##|*DESCR=Allow access to the 'System: User Manager: Add Privileges' page.
##|*WARN=standard-warning-root
##|*MATCH=system_usermanager_addprivs.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("system_usermanager.inc");

if (isset($_REQUEST['userid']) && is_numericint($_REQUEST['userid'])) {
	$userid = $_REQUEST['userid'];
}

$pgtitle = array(gettext("System"), gettext("User Manager"), gettext("Users"), gettext("Edit"), gettext("Add Privileges"));
$pglinks = array("", "system_usermanager.php", "system_usermanager.php", "system_usermanager.php?act=edit&userid=" . $userid, "@self");

/* No or invalid user id: back to the list without touching "system/user/" (logs a config warning). */
$a_user = isset($userid) ? config_get_path("system/user/{$userid}") : null;

if (empty($a_user) || !is_array($a_user)) {
	FreeSenseHeader("system_usermanager.php");
	exit;
}

if (!is_array($a_user['priv'])) {
	$a_user['priv'] = array();
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

	$input_errors = usermgr_user_privs_add($userid, $_POST, $guiuser);
	if (!$input_errors) {
		post_redirect("system_usermanager.php", array('act' => 'edit', 'userid' => $userid));

		exit;
	}

}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('system-usermanager', 'system_usermanager.php');

/* checklist descriptions and administrator-level flags of the privileges that can still be added */
$priv_descs = [];
$priv_warn = [];
foreach (usermgr_priv_choices($spriv_list, $a_user['priv']) as $pname => $plabel) {
	$priv_descs[$pname] = preg_replace("/FreeSense/i", g_get('product_label'), (string)$spriv_list[$pname]['descr']);
	if (($spriv_list[$pname]['warn'] ?? '') == 'standard-warning-root') {
		$priv_warn[] = $pname;
	}
}

$form = new Form();

$section = new Form_Section('User Privileges');

$name_string = $a_user['name'];
if (!empty($a_user['descr'])) {
	$name_string .= " (" . htmlspecialchars($a_user['descr']) . ")";
}

$section->addInput(new Form_StaticText(
	'User',
	$name_string
));

$section->addInput(new Form_Select(
	'sysprivs',
	'*Assigned privileges',
	null,
	usermgr_priv_choices($spriv_list, $a_user['priv']),
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
	gettext('The following privileges effectively give the user administrator-level access ' .
		' because the user gains access to execute general commands, edit system files, ' .
		' modify users, change passwords or similar:') .
	'<br/>' .
	usermgr_root_priv_text() .
	'<br/><br/>' .
	gettext('Please take care when granting these privileges.') .
	'</span>'
));

if (isset($userid)) {
	$form->addGlobal(new Form_Input(
	'userid',
	null,
	'hidden',
	$userid
	));
}

$form->add($section);

fs_form_cancel($form, 'system_usermanager.php?act=edit&userid=' . urlencode($userid));
print($form);
?>
<?php include("foot.inc");
