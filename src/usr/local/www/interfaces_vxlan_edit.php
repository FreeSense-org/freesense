<?php
/*
 * interfaces_vxlan_edit.php
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
##|*IDENT=page-interfaces-vxlan-edit
##|*NAME=Interfaces: VXLAN: Edit
##|*DESCR=Allow access to the 'Interfaces: VXLAN: Edit' page.
##|*MATCH=interfaces_vxlan_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_tunnels.inc");

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$this_vxlan_config = isset($id) ? config_get_path("vxlans/vxlan/{$id}") : null;
if ($this_vxlan_config) {
	$pconfig = $this_vxlan_config;
	$pconfig['learn'] = !isset($this_vxlan_config['nolearn']);
	$pconfig['allowrule'] = isset($this_vxlan_config['allowrule']);
} else {
	$pconfig = array('ipproto' => 'inet', 'mode' => 'unicast', 'learn' => true);
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;
	$pconfig['learn'] = isset($_POST['learn']);
	$pconfig['allowrule'] = isset($_POST['allowrule']);

	$input_errors = interfaces_vxlan_save($_POST, $id);
	if (!$input_errors) {
		header("Location: interfaces_vxlan.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("VXLANs"), gettext("Edit"));
$pglinks = array("", "interfaces_vxlan.php", "@self");
$shortcut_section = "interfaces";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('VXLAN Configuration');

$section->addInput(new Form_Select(
	'if',
	'*Parent Interface',
	$pconfig['if'],
	interfaces_tunnel_parent_list('vxlan')
))->setHelp('The tunnel is sent from this interface. Its address of the selected family is used as the local VTEP address.');

$section->addInput(new Form_Select(
	'ipproto',
	'*Address Family',
	$pconfig['ipproto'],
	array('inet' => gettext('IPv4'), 'inet6' => gettext('IPv6'))
))->setHelp('Address family of the outer (encapsulating) traffic.');

$section->addInput(new Form_Select(
	'mode',
	'*Mode',
	$pconfig['mode'],
	array('unicast' => gettext('Unicast (one remote VTEP)'), 'multicast' => gettext('Multicast group'))
));

$section->addInput(new Form_Input(
	'vni',
	'*VXLAN Network Identifier',
	'number',
	$pconfig['vni'],
	['min' => 0, 'max' => VXLAN_VNI_MAX]
))->setHelp('The VNI (0-%d) must match on every VTEP of this segment.', VXLAN_VNI_MAX);

$section->addInput(new Form_IpAddress(
	'remote-addr',
	'*Remote Address',
	$pconfig['remote-addr']
))->setHelp('Address of the remote VTEP.');

$section->addInput(new Form_IpAddress(
	'mcastgroup',
	'*Multicast Group',
	$pconfig['mcastgroup']
))->setHelp('Group joined on the parent interface, e.g. 239.1.1.1. IPv4 groups must be 224.0.1.0 or above; IPv6 groups must have site scope or wider (ff05::, ff08::, ff0e::).');

$section->addInput(new Form_Input(
	'localport',
	'Local Port',
	'number',
	$pconfig['localport'],
	['min' => 1, 'max' => 65535, 'placeholder' => VXLAN_DEFAULT_PORT]
))->setHelp('UDP port to listen on. Default %1$d. Linux peers use 8472 unless configured with "dstport 4789".', VXLAN_DEFAULT_PORT);

$section->addInput(new Form_Input(
	'remoteport',
	'Remote Port',
	'number',
	$pconfig['remoteport'],
	['min' => 1, 'max' => 65535, 'placeholder' => VXLAN_DEFAULT_PORT]
))->setHelp('UDP port of the remote VTEP. Default %1$d; use 8472 for a default Linux peer.', VXLAN_DEFAULT_PORT);

$section->addInput(new Form_Input(
	'ttl',
	'TTL',
	'number',
	$pconfig['ttl'],
	['min' => 1, 'max' => 255, 'placeholder' => VXLAN_DEFAULT_TTL]
))->setHelp('TTL of the outer packets. Default %1$d.', VXLAN_DEFAULT_TTL);

$section->addInput(new Form_Checkbox(
	'learn',
	'MAC Learning',
	'Learn which remote VTEP each MAC address is behind',
	$pconfig['learn']
))->setHelp('With learning, frames for a known MAC address are sent to the VTEP it was learned from instead of the remote address or group.');

$section->addInput(new Form_Checkbox(
	'allowrule',
	'Firewall Rule',
	'Allow the VXLAN traffic in on the parent interface',
	$pconfig['allowrule']
))->setHelp('Adds a pass rule for the encapsulated UDP traffic from the remote VTEP to this firewall. ' .
    'It is placed before the "Block private networks" and "Block bogon networks" rules of the parent, so it also admits a peer in those ranges. ' .
    'In multicast mode the rule accepts encapsulated frames from any sender and also passes IGMP or MLD. ' .
    'Traffic inside the tunnel is filtered by the rules of the assigned VXLAN interface, which are still required.');

if (!empty($pconfig['mac'])) {
	$section->addInput(new Form_StaticText(
		'MAC Address',
		htmlspecialchars($pconfig['mac'])
	))->setHelp('Generated when the tunnel was created and kept when it is recreated.');
}

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

if ($this_vxlan_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$form->add($section);
fs_form_cancel($form, 'interfaces_vxlan.php');
print($form);
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function change_mode() {
		var multicast = ($('#mode').val() == 'multicast');
		hideInput('remote-addr', multicast);
		hideInput('mcastgroup', !multicast);
	}

	$('#mode').change(function () {
		change_mode();
	});

	// ---------- On initial page load ------------------------------------------------------------

	change_mode();
});
//]]>
</script>

<?php
include("foot.inc");
