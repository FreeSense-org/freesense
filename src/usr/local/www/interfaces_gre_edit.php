<?php
/*
 * interfaces_gre_edit.php
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
##|*IDENT=page-interfaces-gre-edit
##|*NAME=Interfaces: GRE: Edit
##|*DESCR=Allow access to the 'Interfaces: GRE: Edit' page.
##|*MATCH=interfaces_gre_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("interfaces_tunnels.inc");

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$this_gre_config = isset($id) ? config_get_path("gres/gre/{$id}") : null;
if ($this_gre_config) {
	$pconfig['if'] = $this_gre_config['if'];
	$pconfig['greif'] = $this_gre_config['greif'];
	$pconfig['remote-addr'] = $this_gre_config['remote-addr'];
	$pconfig['tunnel-remote-net'] = $this_gre_config['tunnel-remote-net'];
	$pconfig['tunnel-local-addr'] = $this_gre_config['tunnel-local-addr'];
	$pconfig['tunnel-remote-addr'] = $this_gre_config['tunnel-remote-addr'];
	$pconfig['tunnel-remote-net6'] = $this_gre_config['tunnel-remote-net6'];
	$pconfig['tunnel-local-addr6'] = $this_gre_config['tunnel-local-addr6'];
	$pconfig['tunnel-remote-addr6'] = $this_gre_config['tunnel-remote-addr6'];
	$pconfig['link1'] = isset($this_gre_config['link1']);
	$pconfig['link2'] = isset($this_gre_config['link2']);
	$pconfig['link0'] = isset($this_gre_config['link0']);
	$pconfig['descr'] = $this_gre_config['descr'];
}

if ($_POST['save']) {

	unset($input_errors);
	$pconfig = interfaces_gre_form_values($_POST);

	$input_errors = interfaces_gre_save($_POST, $id);
	if (!$input_errors) {
		header("Location: interfaces_gre.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("GREs"), gettext("Edit"));
$pglinks = array("", "interfaces_gre.php", "@self");
$shortcut_section = "interfaces";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('GRE Configuration');

$section->addInput(new Form_Select(
	'if',
	'*Parent Interface',
	$pconfig['if'],
	interfaces_tunnel_parent_list('gre')
))->setHelp('This interface serves as the local address to be used for the GRE tunnel.');

$section->addInput(new Form_IpAddress(
	'remote-addr',
	'*Remote Address',
	$pconfig['remote-addr']
))->setHelp('Peer address where encapsulated GRE packets will be sent.');

$group = new Form_Group(gettext('IPv4'));

$group->add(new Form_IpAddress(
	'tunnel-local-addr',
	'*Local Tunnel Address',
	$pconfig['tunnel-local-addr'],
	'V4'
))->setHelp('Local IPv4 tunnel address.');

$group->add(new Form_IpAddress(
	'tunnel-remote-addr',
	'*Remote Tunnel Address',
	$pconfig['tunnel-remote-addr'],
	'V4'
))->setHelp('Remote IPv4 tunnel address.');

$group->add(new Form_Select(
	'tunnel-remote-net',
	'*Tunnel subnet',
	$pconfig['tunnel-remote-net'],
	array_combine(range(32, 1, -1), range(32, 1, -1))
))->setHelp('The subnet is used for determining the IPv4 network that is tunnelled.');

$section->add($group);

$group = new Form_Group(gettext('IPv6'));

$group->add(new Form_IpAddress(
	'tunnel-local-addr6',
	'*Local Tunnel Address',
	$pconfig['tunnel-local-addr6'],
	'V6'
))->setHelp('Local IPv6 tunnel address.');

$group->add(new Form_IpAddress(
	'tunnel-remote-addr6',
	'*Remote Tunnel address',
	$pconfig['tunnel-remote-addr6'],
	'V6'
))->setHelp('Remote IPv6 tunnel address.');

$group->add(new Form_Select(
	'tunnel-remote-net6',
	'*Tunnel subnet',
	$pconfig['tunnel-remote-net6'],
	array_combine(range(128, 1, -1), range(128, 1, -1))
))->setHelp('The subnet is used for determining the IPv6 network that is tunnelled.');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'link1',
	'Add Static Route',
	'Add an explicit static route for the remote inner tunnel address/subnet via the local tunnel address',
	$pconfig['link1']
));

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

$form->addGlobal(new Form_Input(
	'greif',
	null,
	'hidden',
	$pconfig['greif']
));

if ($this_gre_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$form->add($section);
fs_form_cancel($form, 'interfaces_gre.php');
print($form);

include("foot.inc");
