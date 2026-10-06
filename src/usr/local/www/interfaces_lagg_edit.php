<?php
/*
 * interfaces_lagg_edit.php
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
##|*IDENT=page-interfaces-lagg-edit
##|*NAME=Interfaces: LAGG: Edit
##|*DESCR=Allow access to the 'Interfaces: LAGG: Edit' page.
##|*MATCH=interfaces_lagg_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_l2.inc");

$laggprotos	  = interfaces_lagg_protos();
$laggprotosuc = array(gettext("NONE"), gettext("LACP"), gettext("FAILOVER"), gettext("LOADBALANCE"), gettext("ROUNDROBIN"));

$protohelp =
'<ul>' .
	'<li>' .
		'<strong>' . $laggprotosuc[0] . '</strong><br />' .
		gettext('This protocol is intended to do nothing: it disables any ' .
				'traffic without disabling the lagg interface itself.') .
	'</li>' .
	'<li>' .
		'<strong>' . $laggprotosuc[1] . '</strong><br />' .
		gettext('Supports the IEEE 802.3ad Link Aggregation Control Protocol ' .
				'(LACP) and the Marker Protocol.	LACP will negotiate a set ' .
				'of aggregable links with the peer in to one or more Link ' .
				'Aggregated Groups.  Each LAG is composed of ports of the ' .
				'same speed, set to full-duplex operation.  The traffic will ' .
				'be balanced across the ports in the LAG with the greatest ' .
				'total speed, in most cases there will only be one LAG which ' .
				'contains all ports.	In the event of changes in physical ' .
				'connectivity, Link Aggregation will quickly converge to a ' .
				'new configuration.') .
	'</li>' .
	'<li>' .
		'<strong>' . $laggprotosuc[2] . '</strong><br />' .
		gettext('Sends and receives traffic only through the master port.  If ' .
				'the master port becomes unavailable, the next active port is ' .
				'used.') .
	'</li>' .
	'<li>' .
		'<strong>' . $laggprotosuc[3] . '</strong><br />' .
		gettext('Balances outgoing traffic across the active ports based on ' .
				'hashed protocol header information and accepts incoming ' .
				'traffic from any active port.	 This is a static setup and ' .
				'does not negotiate aggregation with the peer or exchange ' .
				'frames to monitor the link.  The hash includes the Ethernet ' .
				'source and destination address, and, if available, the VLAN ' .
				'tag, and the IP source and destination address.') .
	'</li>' .
	'<li>' .
		'<strong>' . $laggprotosuc[4] . '</strong><br />' .
		gettext('Distributes outgoing traffic using a round-robin scheduler ' .
				'through all active ports and accepts incoming traffic from ' .
				'any active port.') .
	'</li>' .
'</ul>';

$lagghashhelp =
'Hash algorithms for the packet layers: ' .
'<ul>' .
	'<li>' .
		'<strong>Layer 2</strong><br />' . gettext('Source/Destination MAC Address and optional VLAN number.') .
	'</li>' .
	'<li>' .
		'<strong>Layer 3</strong><br />' .  gettext('Source/Destination IPv4/IPv6 Address.') .
	'</li>' .
	'<li>' .
		'<strong>Layer 4</strong><br />' . gettext('Source/Destination port.') .
	'</li>' .
'</ul>';

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$this_lagg_config = isset($id) ? config_get_path("laggs/lagg/{$id}") : null;
if ($this_lagg_config) {
	$pconfig['laggif'] = $this_lagg_config['laggif'];
	$pconfig['members'] = $this_lagg_config['members'];
	$pconfig['proto'] = $this_lagg_config['proto'];
	if (isset($this_lagg_config['failovermaster'])) {
		$pconfig['failovermaster'] = $this_lagg_config['failovermaster'];
	}
	if (isset($this_lagg_config['lacptimeout'])) {
		$pconfig['lacptimeout'] = $this_lagg_config['lacptimeout'];
	}
	if (isset($this_lagg_config['lagghash'])) {
		$pconfig['lagghash'] = $this_lagg_config['lagghash'];
	}
	$pconfig['descr'] = $this_lagg_config['descr'];
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	if (is_array($_POST['members'])) {
		$pconfig['members'] = implode(',', $_POST['members']);
	}

	$input_errors = interfaces_lagg_save($_POST, $id);
	if (!$input_errors) {
		header("Location: interfaces_lagg.php");
		exit;
	}
}

function build_member_list() {
	global $pconfig, $id;

	$memberlist = array('list' => array(), 'selected' => array());

	foreach (interfaces_lagg_port_list($id) as $ifn => $ifinfo) {
		$hwaddr = get_interface_vendor_mac($ifn);

		$memberlist['list'][$ifn] = $ifn . ' (' . $ifinfo['mac'] .
		    ($hwaddr != $ifinfo['mac'] ? " | hw: {$hwaddr}" : '') . ')';

		if (in_array($ifn, explode(",", $pconfig['members']))) {
			array_push($memberlist['selected'], $ifn);
		}
	}

	return($memberlist);
}

$pgtitle = array(gettext("Interfaces"), gettext("LAGGs"), gettext("Edit"));
$pglinks = array("", "interfaces_lagg.php", "@self");
$shortcut_section = "interfaces";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('LAGG Configuration');

$memberslist = build_member_list();
$failoverlist = array_merge(array('auto' => 'auto'), $memberslist['list']);

$section->addInput(new Form_Select(
	'members',
	'*Parent Interfaces',
	$memberslist['selected'],
	$memberslist['list'],
	true // Allow multiples
))->setHelp('Choose the members that will be used for the link aggregation.');

$section->addInput(new Form_Select(
	'proto',
	'*LAGG Protocol',
	$pconfig['proto'],
	array_combine($laggprotos, $laggprotosuc)
))->setHelp($protohelp);

$group = new Form_Group('Failover Master Interface');
$group->addClass('fomaster');
$group->add(new Form_Select(
	'failovermaster',
	'Failover Master Interface',
	$pconfig['failovermaster'],
	$failoverlist
))->setHelp('Master interface for the <b>FAILOVER</b> mode. If auto is selected, then the first interface added is the master port; any interfaces added after that are used as failover devices.');
$section->add($group);

$group = new Form_Group('LACP Timeout Mode');
$group->addClass('lacptimeout');
$group->add(new Form_Select(
	'lacptimeout',
	'LACP Timeout',
	$pconfig['lacptimeout'],
	array('slow' => 'Slow (default)', 'fast' => 'Fast')
))->setHelp('In a <b>Slow</b> timeout, PDUs are sent every 30 seconds and in a <b>Fast</b> timeout, ' .
	    'PDUs are sent every second. LACP timeout occurs when 3 consecutive PDUs are missed. ' .
	    'If LACP timeout is a slow timeout, the time taken when 3 consecutive PDUs are missed ' .
	    'is 90 seconds (3x30 seconds). If LACP timeout is a fast timeout, the time taken is 3 ' .
	    'seconds (3x1 second).');
$section->add($group);

$group = new Form_Group('Hash Algorithm');
$group->addClass('lagghash');
$group->add(new Form_Select(
	'lagghash',
	'Hash',
	$pconfig['lagghash'],
	$lagg_hash_list
))->setHelp($lagghashhelp);
$section->add($group);

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp("Enter a description here for reference only (Not parsed).");

$form->addGlobal(new Form_Input(
	'laggif',
	null,
	'hidden',
	$pconfig['laggif']
));

if ($this_lagg_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$form->add($section);
print($form);
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function change_proto() {
		hideClass('fomaster', ($('#proto').val() != 'failover'));
		hideClass('lacptimeout', ($('#proto').val() != 'lacp'));
		hideClass('lagghash', (($('#proto').val() != 'lacp') && ($('#proto').val() != 'loadbalance')));
	}

	$('#proto').change(function () {
		change_proto();
	});

	// ---------- On initial page load ------------------------------------------------------------

	change_proto();

});
//]]>
</script>

<?php include("foot.inc");
