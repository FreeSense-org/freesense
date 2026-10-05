<?php
/*
 * interfaces_bridge_edit.php
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
##|*IDENT=page-interfaces-bridge-edit
##|*NAME=Interfaces: Bridge edit
##|*DESCR=Allow access to the 'Interfaces: Bridge : Edit' page.
##|*MATCH=interfaces_bridge_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_l2.inc");

$ifacelist = interfaces_bridge_iface_list();

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$this_bridge_config = isset($id) ? config_get_path("bridges/bridged/{$id}") : null;
if ($this_bridge_config) {
	$pconfig['enablestp'] = isset($this_bridge_config['enablestp']);
	$pconfig['ip6linklocal'] = isset($this_bridge_config['ip6linklocal']);
	$pconfig['descr'] = $this_bridge_config['descr'];
	$pconfig['bridgeif'] = $this_bridge_config['bridgeif'];
	$pconfig['members'] = $this_bridge_config['members'];
	$pconfig['maxaddr'] = $this_bridge_config['maxaddr'];
	$pconfig['timeout'] = $this_bridge_config['timeout'];
	$pconfig['maxage'] = $this_bridge_config['maxage'];
	$pconfig['fwdelay'] = $this_bridge_config['fwdelay'];
	$pconfig['hellotime'] = $this_bridge_config['hellotime'];
	$pconfig['priority'] = $this_bridge_config['priority'];
	$pconfig['proto'] = $this_bridge_config['proto'];
	$pconfig['holdcnt'] = $this_bridge_config['holdcnt'];

	if (!empty($this_bridge_config['ifpriority'])) {
		$pconfig['ifpriority'] = explode(",", $this_bridge_config['ifpriority']);
		$ifpriority = array();
		foreach ($pconfig['ifpriority'] as $cfg) {
			list ($key, $value) = explode(":", $cfg);
			$embprioritycfg[$key] = $value;
			foreach ($embprioritycfg as $key => $value) {
				$ifpriority[$key] = $value;
			}
		}
		$pconfig['ifpriority'] = $ifpriority;
	}

	if (!empty($this_bridge_config['ifpathcost'])) {
		$pconfig['ifpathcost'] = explode(",", $this_bridge_config['ifpathcost']);
		$ifpathcost = array();
		foreach ($pconfig['ifpathcost'] as $cfg) {
			list ($key, $value) = explode(":", $cfg);
			$embpathcfg[$key] = $value;
			foreach ($embpathcfg as $key => $value) {
				$ifpathcost[$key] = $value;
			}
		}
		$pconfig['ifpathcost'] = $ifpathcost;
	}

	if (isset($this_bridge_config['static'])) {
		$pconfig['static'] = $this_bridge_config['static'];
	}
	if (isset($this_bridge_config['private'])) {
		$pconfig['private'] = $this_bridge_config['private'];
	}
	if (isset($this_bridge_config['stp'])) {
		$pconfig['stp'] = $this_bridge_config['stp'];
	}
	if (isset($this_bridge_config['span'])) {
		$pconfig['span'] = $this_bridge_config['span'];
	}
	if (isset($this_bridge_config['edge'])) {
		$pconfig['edge'] = $this_bridge_config['edge'];
	}
	if (isset($this_bridge_config['autoedge'])) {
		$pconfig['autoedge'] = $this_bridge_config['autoedge'];
	}
	if (isset($this_bridge_config['ptp'])) {
		$pconfig['ptp'] = $this_bridge_config['ptp'];
	}
	if (isset($this_bridge_config['autoptp'])) {
		$pconfig['autoptp'] = $this_bridge_config['autoptp'];
	}
}

if ($_POST['save']) {
	unset($input_errors);

	$input_errors = interfaces_bridge_save($_POST, $id);
	if (!$input_errors) {
		header("Location: interfaces_bridge.php");
		exit;
	}
	$pconfig = interfaces_bridge_form_values($_POST);
}

$pgtitle = array(gettext("Interfaces"), gettext("Bridges"), gettext("Edit"));
$pglinks = array("", "interfaces_bridge.php", "@self");
$shortcut_section = "interfaces";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('Bridge Configuration');

$memberslist = interfaces_bridge_port_list($pconfig['members']);

$section->addInput(new Form_Select(
	'members',
	'*Member Interfaces',
	$memberslist['selected'],
	$memberslist['list'],
	true // Allow multiples
))->setHelp('Interfaces participating in the bridge.');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
));

// Advanced Additional options
$btnadv = new Form_Button(
	'btnadvopts',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-cog'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	'Advanced Options',
	$btnadv
));

$form->add($section);

$section = new Form_Section('Advanced Configuration');

$section->addClass('adnlopts');

$section->addInput(new Form_Input(
	'maxaddr',
	'Cache Size',
	'text',
	$pconfig['maxaddr']
))->setHelp('Set the size of the bridge address cache. The default is 2000 entries.');

$section->addInput(new Form_Input(
	'timeout',
	'Cache expire time',
	'text',
	$pconfig['timeout']
))->setHelp('Set the timeout of address cache entries to this number of seconds. If seconds is zero, then address cache entries will not be expired. The default is 1200 seconds.');

$spanlist = interfaces_bridge_port_list($pconfig['span']);

$section->addInput(new Form_Select(
	'span',
	'Span Port',
	$spanlist['selected'],
	$spanlist['list'],
	true
))->setHelp('Add the interface named by interface as a span port on the bridge. Span ports transmit a copy of every frame received by the bridge. ' .
			'This is most useful for snooping a bridged network passively on another host connected to one of the span ports of the bridge. %1$s' .
			'%2$sThe span interface cannot be part of the bridge member interfaces.%3$s', '<br />', '<strong>', '</strong>');

$edgelist = interfaces_bridge_port_list($pconfig['edge']);

$section->addInput(new Form_Select(
	'edge',
	'Edge Ports',
	$edgelist['selected'],
	$edgelist['list'],
	true
))->setHelp('Set interface as an edge port. An edge port connects directly to end stations and cannot create bridging loops in the network; this allows it to transition straight to forwarding.');

$edgelist = interfaces_bridge_port_list($pconfig['autoedge']);

$section->addInput(new Form_Select(
	'autoedge',
	'Auto Edge Ports',
	$edgelist['selected'],
	$edgelist['list'],
	true
))->setHelp('Allow interface to automatically detect edge status. This is the default for all interfaces added to a bridge.' .
			'%1$sThis will disable the autoedge status of interfaces. %2$s', '<strong>', '</strong>');

$edgelist = interfaces_bridge_port_list($pconfig['ptp']);

$section->addInput(new Form_Select(
	'ptp',
	'PTP Ports',
	$edgelist['selected'],
	$edgelist['list'],
	true
))->setHelp('Set the interface as a point-to-point link. This is required for straight transitions to forwarding and should be enabled on a direct link to another RSTP-capable switch.');

$edgelist = interfaces_bridge_port_list($pconfig['autoptp']);

$section->addInput(new Form_Select(
	'autoptp',
	'Auto PTP Ports',
	$edgelist['selected'],
	$edgelist['list'],
	true
))->setHelp('Automatically detect the point-to-point status on interface by checking the full duplex link status. This is the default for interfaces added to the bridge.' .
			'%1$sThe interfaces selected here will be removed from default autoedge status. %2$s', '<strong>', '</strong>');

$edgelist = interfaces_bridge_port_list($pconfig['static']);

$section->addInput(new Form_Select(
	'static',
	'Sticky Ports',
	$edgelist['selected'],
	$edgelist['list'],
	true
))->setHelp('Mark an interface as a "sticky" interface. Dynamically learned address entries are treated as static once entered into the cache. ' .
			'Sticky entries are never aged out of the cache or replaced, even if the address is seen on a different interface.');

$edgelist = interfaces_bridge_port_list($pconfig['private']);

$section->addInput(new Form_Select(
	'private',
	'Private Ports',
	$edgelist['selected'],
	$edgelist['list'],
	true
))->setHelp('Mark an interface as a "private" interface. A private interface does not forward any traffic to any other port that is also a private interface. ');

$section->addInput(new Form_Checkbox(
	'ip6linklocal',
	'Enable IPv6 auto linklocal',
	null,
	$pconfig['ip6linklocal']
))->setHelp('When enabled, the AUTO_LINKLOCAL flag is set on the bridge interface and cleared on every member interface. This is required when the bridge interface is used for stateless autoconfiguration. ');

//	STP section
// ToDo: - Should disable spanning tree section when not checked
$section->addInput(new Form_Checkbox(
	'enablestp',
	'Enable RSTP/STP',
	null,
	$pconfig['enablestp']
));

// Show the spanning tree section
$form->add($section);
$section = new Form_Section('RSTP/STP');
$section->addClass('adnlopts');

$section->addInput(new Form_Select(
	'proto',
	'Protocol',
	$pconfig['proto'],
	array('rstp' => 'RSTP',
		  'stp' => 'STP')
))->setHelp('Protocol used for spanning tree.');

$edgelist = interfaces_bridge_port_list($pconfig['stp']);

$section->addInput(new Form_Select(
	'stp',
	'STP Interfaces',
	$edgelist['selected'],
	$edgelist['list'],
	true
))->setHelp('Enable Spanning Tree Protocol on interface. The if_bridge(4) driver has support for the IEEE 802.1D Spanning Tree Protocol (STP). ' .
			'STP is used to detect and remove loops in a network topology.');

$section->addInput(new Form_Input(
	'maxage',
	'Valid time',
	'number',
	$pconfig['maxage'],
	['placeholder' => 20, 'min' => 6, 'max' => 40]
))->setHelp('Set the time that a Spanning Tree Protocol configuration is valid. The default is 20 seconds. The minimum is 6 seconds and the maximum is 40 seconds.');

$section->addInput(new Form_Input(
	'fwdelay',
	'Forward time',
	'number',
	$pconfig['fwdelay'],
	['placeholder' => 15, 'min' => 4, 'max' => 30]
))->setHelp('Set the time that must pass before an interface begins forwarding packets when Spanning Tree is enabled. The default is 15 seconds. The minimum is 4 seconds and the maximum is 30 seconds. ');

$section->addInput(new Form_Input(
	'hellotime',
	'Hello time',
	'number',
	$pconfig['hellotime'],
	['placeholder' => 2, 'min' => 1, 'max' => 2, 'step' => '0.1']
))->setHelp('Set the time in seconds between broadcasting of Spanning Tree Protocol configuration messages. The hello time may only be changed when operating in legacy STP mode. ' .
			'The default is 2 seconds. The minimum is 1 second and the maximum is 2 seconds.');

$section->addInput(new Form_Input(
	'priority',
	'Priority',
	'number',
	$pconfig['priority'],
	['placeholder' => 32768, 'min' => 0, 'max' => 61440]
))->setHelp('Set the bridge priority for Spanning Tree. The default is 32768. The minimum is 0 and the maximum is 61440. ');

$section->addInput(new Form_Input(
	'holdcnt',
	'Hold Count',
	'number',
	$pconfig['holdcnt'],
	['placeholder' => 6, 'min' => 1, 'max' => 10]
))->setHelp('Set the transmit hold count for Spanning Tree. This is the number of packets transmitted before being rate limited. The default is 6. The minimum is 1 and the maximum is 10.');

foreach ($ifacelist as $ifn => $ifdescr) {
	$section->addInput(new Form_Input(
		$ifn,
		$ifdescr . ' Priority',
		'number',
		$pconfig['ifpriority'][$ifn],
		['placeholder' => 128, 'min' => 0, 'max' => 240, 'step' => 16]
	))->setHelp('Set the Spanning Tree priority of interface to value. The default is 128. The minimum is 0 and the maximum is 240. Increments of 16.');
}

$i = 0;
foreach ($ifacelist as $ifn => $ifdescr) {
	$section->addInput(new Form_Input(
		$ifn . 0,
		$ifdescr . ' Path cost',
		'number',
		$pconfig['ifpathcost'][$ifn],
		[ 'placeholder' => 0, 'min' => 1, 'max' => 200000000]
	))->setHelp('Set the Spanning Tree path cost of interface to value. The default is calculated from the link speed. '.
		'To change a previously selected path cost back to automatic, set the cost to 0. The minimum is 1 and the maximum is 200000000.');
	$i++;
}

$form->addGlobal(new Form_Input(
	'bridgeif',
	null,
	'hidden',
	$pconfig['bridgeif']
));

if ($this_bridge_config) {
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

	// Show advanced additional opts options ======================================================
	var showadvopts = false;

	function show_advopts(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
			showadvopts = <?php if (bridge_advanced_used($pconfig)) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvopts = !showadvopts;
		}

		hideClass('adnlopts', !showadvopts);

		if (showadvopts) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvopts').children();
		$('#btnadvopts').text(text).prepend(children);
	}

	$('#btnadvopts').click(function(event) {
		show_advopts();
	});

	// ---------- On initial page load ------------------------------------------------------------

	show_advopts(true);
});
//]]>
</script>

<?php include("foot.inc");
