<?php
/*
 * interfaces_vlan_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-interfaces-vlan-edit
##|*NAME=Interfaces: VLAN: Edit
##|*DESCR=Allow access to the 'Interfaces: VLAN: Edit' page.
##|*MATCH=interfaces_vlan_edit.php*
##|-PRIV

require_once("config.lib.inc");
require_once("guiconfig.inc");
require_once("interfaces_tunnels.inc");

$portlist = interfaces_vlan_parent_list();

if (is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

$this_vlan_config = isset($id) ? config_get_path("vlans/vlan/{$id}") : null;
if ($this_vlan_config) {
	$pconfig['if'] = $this_vlan_config['if'];
	$pconfig['vlanif'] = $this_vlan_config['vlanif'];
	$pconfig['tag_type'] = $this_vlan_config['tag_type'];
	$pconfig['tag'] = $this_vlan_config['tag'];
	$pconfig['pcp'] = $this_vlan_config['pcp'];
	$pconfig['descr'] = $this_vlan_config['descr'];
}

if ($_POST['save']) {

	unset($input_errors);
	$pconfig = $_POST;

	/*
	 * Check user privileges to test if the user is allowed to make changes.
	 * Otherwise users can end up in an inconsistent state where some changes are
	 * performed and others denied. See upstream issue 15282
	 */
	$input_errors = interfaces_vlan_save($_POST, $id ?? null, interfaces_gui_read_only());
	if (!$input_errors) {
		header("Location: interfaces_vlan.php");
		exit;
	}
}

function build_interfaces_list() {
	global $portlist;

	$list = array();

	foreach ($portlist as $ifn => $ifinfo) {
		$list[$ifn] = $ifn . " (" . $ifinfo['mac'] . ")";
		$iface = convert_real_interface_to_friendly_interface_name($ifn);
		if (isset($iface) && strlen($iface) > 0) {
			$list[$ifn] .= " - $iface";
		}
	}

	return($list);
}

$pgtitle = array(gettext("Interfaces"), gettext("VLANs"), gettext("Edit"));
$pglinks = array("", "interfaces_vlan.php", "@self");
$shortcut_section = "interfaces";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;
$section = new Form_Section('VLAN Configuration');

$section->addInput(new Form_Select(
	'if',
	'*Parent Interface',
	$pconfig['if'],
	build_interfaces_list()
))->setWidth(6)->setHelp('Only VLAN capable interfaces will be shown.');

$section->addInput(new Form_Select(
	'tag_type',
	'*VLAN Tag Type',
	$pconfig['tag_type'] ?? 'ctag',
	interfaces_vlan_tag_types()
))->setHelp('The type of VLAN tag to use (defaults to C-Tag).');

$section->addInput(new Form_Input(
	'tag',
	'*VLAN Tag',
	'text',
	$pconfig['tag'],
	['placeholder' => '1']
))->setWidth(6)->setHelp('802.1Q VLAN tag (between 1 and 4094).');

$section->addInput(new Form_Input(
	'pcp',
	'VLAN Priority',
	'text',
	$pconfig['pcp'],
	['placeholder' => '0']
))->setWidth(6)->setHelp('802.1Q VLAN Priority (between 0 and 7).');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr'],
	['placeholder' => 'Description']
))->setWidth(6)->setHelp('A group description may be entered here for administrative reference (not parsed).');

$form->addGlobal(new Form_Input(
	'vlanif',
	'vlanif',
	'hidden',
	$pconfig['vlanif']
));

if ($this_vlan_config) {
	$form->addGlobal(new Form_Input(
		'id',
		'id',
		'hidden',
		$id
	));
}

$form->add($section);
print $form;

include("foot.inc");
