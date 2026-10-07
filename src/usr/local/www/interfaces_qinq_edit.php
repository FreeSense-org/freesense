<?php
/*
 * interfaces_qinq_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-interfaces-qinq-edit
##|*NAME=Interfaces: QinQ: Edit
##|*DESCR=Allow access to 'Interfaces: QinQ: Edit' page
##|*MATCH=interfaces_qinq_edit.php*
##|-PRIV

$pgtitle = array(gettext("Interfaces"), gettext("QinQs"), gettext("Edit"));
$pglinks = array("", "interfaces_qinq.php", "@self");
$shortcut_section = "interfaces";

require_once("guiconfig.inc");
require_once("interfaces_l2.inc");

$portlist = interfaces_vlan_parent_list();

if (count($portlist) < 1) {
	header("Location: interfaces_qinq.php");
	exit;
}

if (isset($_REQUEST['id']) && is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

$this_qinq_config = isset($id) ? config_get_path("qinqs/qinqentry/{$id}") : null;
if ($this_qinq_config) {
	$pconfig['if'] = $this_qinq_config['if'];
	$pconfig['tag_type'] = $this_qinq_config['tag_type'];
	$pconfig['tag'] = $this_qinq_config['tag'];
	$pconfig['members'] = $this_qinq_config['members'];
	$pconfig['descr'] = $this_qinq_config['descr'];
	$pconfig['autogroup'] = isset($this_qinq_config['autogroup']);
	$pconfig['autoadjustmtu'] = isset($this_qinq_config['autoadjustmtu']);
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	/*
	 * Check user privileges to test if the user is allowed to make changes.
	 * Otherwise users can end up in an inconsistent state where some changes are
	 * performed and others denied. See upstream issue 15318
	 */
	$input_errors = interfaces_qinq_save($_POST, $id ?? null, interfaces_gui_read_only());
	if (!$input_errors) {
		header("Location: interfaces_qinq.php");
		exit;
	} else {
		$pconfig['descr'] = $_POST['descr'];
		$pconfig['tag'] = $_POST['tag'];
		$pconfig['members'] = interfaces_qinq_posted_members($_POST);
	}
}

$parentlist = array();
foreach ($portlist as $ifn => $ifinfo) {
	$parentlist[$ifn] = $ifn . ' (' . $ifinfo['mac'] . ')';
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('QinQ Configuration');

$section->addInput(new Form_Select(
	'if',
	'*Parent interface',
	$pconfig['if'],
	$parentlist
))->setHelp('Only QinQ capable interfaces will be shown.');

$section->addInput(new Form_Select(
	'tag_type',
	'*VLAN Tag Type',
	$pconfig['tag_type'] ?? 'stag',
	interfaces_vlan_tag_types()
))->setHelp('The type of VLAN tag to use for the first level tag (defaults to S-Tag).');

$section->addInput(new Form_Input(
	'tag',
	'*First level tag',
	'number',
	$pconfig['tag'],
	['max' => '4094', 'min' => '1']
))->setHelp('This is the first level VLAN tag. On top of this are stacked the member VLANs defined below.');

$section->addInput(new Form_Checkbox(
	'autogroup',
	'Option(s)',
	'Adds interface to QinQ interface groups',
	$pconfig['autogroup']
))->setHelp('Allows rules to be written more easily.');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

$section->addInput(new Form_StaticText(
	'Member(s)',
	'Ranges can be specified in the inputs below. Enter a range (2-3) or individual numbers.' . '<br />' .
	'Click "Add Tag" as many times as needed to add new inputs.'
));

if ($this_qinq_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$counter = 0;
$members = $pconfig['members'];

// List each of the member tags from the space-separated list
if ($members != "") {
	$item = explode(" ", $members);
} else {
	$item = array('');
}

foreach ($item as $ww) {

	$group = new Form_Group($counter == 0 ? '*Tag(s)':'');
	$group->addClass('repeatable');

	$group->add(new Form_Input(
		'member' . $counter,
		null,
		'text',
		$ww
	))->setWidth(6) // Width must be <= 8 to make room for the duplication buttons
	  ->setHelp(($counter == count($item) - 1) ? 'Tag or range' : null);

	$group->add(new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-outline-secondary');

	$counter++;

	$section->add($group);
}

/* below the tag rows (entry grid), not in the Save bar */
$section->addInput(new Form_Button(
	'addrow',
	'Add Tag',
	null,
	'fa-solid fa-plus'
))->addClass('btn-outline-secondary addbtn');

$form->add($section);

fs_form_cancel($form, 'interfaces_qinq.php');
print($form);

?>

<script type="text/javascript">
//<![CDATA[

events.push(function() {

	// Suppress "Delete row" button if there are fewer than two rows
	checkLastRow();

});
//]]>
</script>

<?php
include("foot.inc");
