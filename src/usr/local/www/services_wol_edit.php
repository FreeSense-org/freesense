<?php
/*
 * services_wol_edit.php
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
##|*IDENT=page-services-wakeonlan-edit
##|*NAME=Services: Wake-on-LAN: Edit
##|*DESCR=Allow access to the 'Services: Wake-on-LAN: Edit' page.
##|*MATCH=services_wol_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_wol.inc");

if (is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

$this_wol_config = isset($id) ? config_get_path("wol/wolentry/{$id}") : null;
if ($this_wol_config) {
	$pconfig['interface'] = $this_wol_config['interface'];
	$pconfig['mac'] = $this_wol_config['mac'];
	$pconfig['descr'] = $this_wol_config['descr'];
} else {
	$pconfig['interface'] = $_REQUEST['if'];
	$pconfig['mac'] = $_REQUEST['mac'];
	$pconfig['descr'] = $_REQUEST['descr'];
}

if ($_POST['save']) {

	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = wol_save_entry($_POST, $id);
	if (!$input_errors) {
		header("Location: services_wol.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("Wake-on-LAN"), gettext("Edit"));
$pglinks = array("", "services_wol.php", "@self");
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;

if ($this_wol_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$section = new Form_Section('Edit WOL Entry');

$section->addInput(new Form_Select(
	'interface',
	'*Interface',
	(link_interface_to_bridge($pconfig['interface']) ? null : $pconfig['interface']),
	get_configured_interface_with_descr()
))->setHelp('Choose which interface this host is connected to.');

$section->addInput(new Form_Input(
	'mac',
	'*MAC address',
	'text',
	$pconfig['mac']
))->setHelp('Enter a MAC address in the following format: xx:xx:xx:xx:xx:xx');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

$form->add($section);
fs_form_cancel($form, 'services_wol.php');
print $form;

include("foot.inc");
