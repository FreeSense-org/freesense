<?php
/*
 * interfaces_groups_edit.php
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
##|*IDENT=page-interfaces-groups-edit
##|*NAME=Interfaces: Groups: Edit
##|*DESCR=Allow access to the 'Interfaces: Groups: Edit' page.
##|*MATCH=interfaces_groups_edit.php*
##|-PRIV


require_once("guiconfig.inc");
require_once("functions.inc");
require_once("interfaces_l2.inc");

$pgtitle = array(gettext("Interfaces"), gettext("Interface Groups"), gettext("Edit"));
$pglinks = array("", "interfaces_groups.php", "@self");
$shortcut_section = "interfaces";

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

/* hide VTI interfaces, see upstream issue 11134 */
$interface_list = interfaces_group_member_list();

$this_ifgroup_config = isset($id) ? config_get_path("ifgroups/ifgroupentry/{$id}") : null;
if ($this_ifgroup_config) {
	/* Cleanup invalid group members (Deleted interfaces, etc.)
	 * upstream issue 15778 */
	$pconfig['ifname'] = $this_ifgroup_config['ifname'];
	$pconfig['members'] = implode(" ", interfaces_group_valid_members(explode(" ", array_get_path($this_ifgroup_config, 'members', ""))));
	$pconfig['descr'] = $this_ifgroup_config['descr'];
}

$ifname_allowed_chars_text = gettext("Only letters (A-Z), digits (0-9) and '_' are allowed.");
$ifname_no_digit_text = gettext("The group name cannot start or end with a digit.");

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = interfaces_group_save($_POST, $id);
	if (!$input_errors) {
		header("Location: interfaces_groups.php");
		exit;
	} else {
		$pconfig['descr'] = $_POST['descr'];
		$pconfig['members'] = implode(" ", interfaces_group_valid_members((array)($_POST['members'] ?? [])));
	}
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

?>
<div id="inputerrors"></div>
<?php
$form = new Form;
$section = new Form_Section('Interface Group Configuration');

$section->addInput(new Form_Input(
	'ifname',
	'*Group Name',
	'text',
	$pconfig['ifname'],
	['placeholder' => 'Group Name', 'maxlength' => "15"]
))->setWidth(6)->setHelp($ifname_allowed_chars_text . " " . $ifname_no_digit_text);

$section->addInput(new Form_Input(
	'descr',
	'Group Description',
	'text',
	$pconfig['descr'],
	['placeholder' => 'Group Description']
))->setWidth(6)->setHelp('A group description may be entered '.
	'here for administrative reference (not parsed).');

$section->addInput(new Form_Select(
	'members',
	'Group Members',
	explode(' ', $pconfig['members']),
	$interface_list,
	true
))->setWidth(6)->setHelp('NOTE: Rules for WAN type '.
	'interfaces in groups do not contain the reply-to mechanism upon which '.
	'Multi-WAN typically relies. %1$sMore Information%2$s',
	'<a href="https://docs.freesense.org/en/latest/interfaces/groups.html">', '</a>');

if ($this_ifgroup_config) {
	$form->addGlobal(new Form_Input(
		'id',
		'id',
		'hidden',
		$id
	));
}

$form->add($section);
fs_form_cancel($form, 'interfaces_groups.php');
print $form;

unset($interface_list);
include("foot.inc");
?>
