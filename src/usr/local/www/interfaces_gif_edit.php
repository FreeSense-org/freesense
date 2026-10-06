<?php
/*
 * interfaces_gif_edit.php
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
##|*IDENT=page-interfaces-gif-edit
##|*NAME=Interfaces: GIF: Edit
##|*DESCR=Allow access to the 'Interfaces: GIF: Edit' page.
##|*MATCH=interfaces_gif_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_tunnels.inc");

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$this_gif_config = isset($id) ? config_get_path("gifs/gif/{$id}") : null;
if ($this_gif_config) {
	$pconfig['if'] = $this_gif_config['if'];
	if (!empty($this_gif_config['ipaddr'])) {
		$pconfig['if'] = $pconfig['if'] . '|' . $this_gif_config['ipaddr'];
	}
	$pconfig['gifif'] = $this_gif_config['gifif'];
	$pconfig['remote-addr'] = $this_gif_config['remote-addr'];
	$pconfig['tunnel-remote-net'] = $this_gif_config['tunnel-remote-net'];
	$pconfig['tunnel-local-addr'] = $this_gif_config['tunnel-local-addr'];
	$pconfig['tunnel-remote-addr'] = $this_gif_config['tunnel-remote-addr'];
	$pconfig['link1'] = isset($this_gif_config['link1']);
	$pconfig['link2'] = isset($this_gif_config['link2']);
	$pconfig['descr'] = $this_gif_config['descr'];
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = interfaces_gif_save($_POST, $id);
	if (!$input_errors) {
		header("Location: interfaces_gif.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("GIFs"), gettext("Edit"));
$pglinks = array("", "interfaces_gif.php", "@self");
$shortcut_section = "interfaces";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('GIF Configuration');

$section->addInput(new Form_Select(
	'if',
	'*Parent Interface',
	$pconfig['if'],
	interfaces_tunnel_parent_list('gif')
))->setHelp('This interface serves as the local address to be used for the GIF tunnel.');

$section->addInput(new Form_IpAddress(
	'remote-addr',
	'*GIF Remote Address',
	$pconfig['remote-addr']
))->setHelp('Peer address where encapsulated gif packets will be sent.');

$section->addInput(new Form_IpAddress(
	'tunnel-local-addr',
	'*GIF tunnel local address',
	$pconfig['tunnel-local-addr']
))->setHelp('Local gif tunnel endpoint.');

$section->addInput(new Form_IpAddress(
	'tunnel-remote-addr',
	'*GIF tunnel remote address',
	$pconfig['tunnel-remote-addr']
))->setHelp('Remote GIF address endpoint.');

$section->addInput(new Form_Select(
	'tunnel-remote-net',
	'*GIF tunnel subnet',
	$pconfig['tunnel-remote-net'],
	array_combine(range(128, 1, -1), range(128, 1, -1))
))->setHelp('The subnet is used for determining the network that is tunnelled.');

$section->addInput(new Form_Checkbox(
	'link1',
	'ECN friendly behavior',
	'ECN friendly behavior violates RFC2893. This should be used in mutual agreement with the peer. ',
	$pconfig['link1']
));

$section->addInput(new Form_Checkbox(
	'link2',
	'Outer Source Filtering',
	'Disable automatic filtering of the outer GIF source which ensures a match with the configured remote peer. When disabled, martian and inbound filtering is not performed which allows asymmetric routing of the outer traffic.',
	$pconfig['link2']
));

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

$form->addGlobal(new Form_Input(
	'gifif',
	null,
	'hidden',
	$pconfig['gifif']
));

if ($this_gif_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$form->add($section);
print($form);

include("foot.inc");
