<?php
/*
 * diag_packet_capture.php
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
##|*IDENT=page-diagnostics-packetcapture
##|*NAME=Diagnostics: Packet Capture
##|*DESCR=Allow access to the 'Diagnostics: Packet Capture' page.
##|*MATCH=diag_packet_capture.php*
##|-PRIV

require_once('util.inc');
require_once('interfaces_fast.inc');
require_once('guiconfig.inc');
require_once('freesense-utils.inc');
require_once('live_logs.inc');
require_once('diag_packet_capture.inc');

// Directory path where .pcap and .plog files will be stored.
$pcap_files_root = g_get('tmp_path');

/* Include relevant files in the list, keyed by the date in the file name. Sort
 * the file lists from oldest to newest, and store the newest file name. These
 * lists are used when clearing the .pcap and .plog files. File name example:
 * packetcapture-igb0.20-20220701000101.pcap
 * packetcapture-normal-lookup-20220701000101.plog
*/
$pcap_files_list = [];
foreach (array_filter(glob("{$pcap_files_root}/packetcapture-*.pcap"), 'is_file') as $file) {
	if (preg_match('/^.*-\d{14}\.pcap/i', $file)) {
		$pcap_files_list[strtotime(substr($file, -19, 14))] = $file;
	}
}
ksort($pcap_files_list, SORT_NUMERIC);
$pcap_file_last = empty($pcap_files_list) ? null : $pcap_files_list[array_key_last($pcap_files_list)];

$plog_files_list = [];
foreach (array_filter(glob("{$pcap_files_root}/packetcapture-*.plog"), 'is_file') as $file) {
	if (preg_match('/^.*-\d{14}\.plog/i', $file)) {
		$plog_files_list[strtotime(substr($file, -19, 14))] = $file;
	}
}
ksort($plog_files_list, SORT_NUMERIC);
$plog_file_current = empty($plog_files_list) ? null : $plog_files_list[array_key_last($plog_files_list)];

/* The file name in the AJAX POST call must match the file name from
 * $plog_file_current to avoid spoofed requests providing unintended access to
 * system files. */
if (isset($_REQUEST['ajaxLog']) && $_REQUEST['file'] == $plog_file_current) {
	checkForAjaxLog($plog_file_current);
	exit;
}

/* Handle AJAX POST call for the tcpdump process check. */
if ($_REQUEST['isCaptureRunning']) {
	/* Check for any matching tcpdump processes currently running.
 	 * Handle multiple matches; assume the first match is correct. */
	$processes_check = get_pgrep_output('^\/usr\/sbin\/tcpdump.*-w -');
	$process_running = empty($processes_check) ? false : true;

	echo ($process_running ? "true" : "false");
	exit;
}

// Page properties
$allowautocomplete = true;
if ($_POST['download_button'] != '') {
	$nocsrf = true;
}
$pgtitle = array(gettext('Diagnostics'), gettext('Packet Capture'));

$available_interfaces = get_interfaces_sorted();
$max_view_size = 50 * 1024 * 1024; // Avoid timeout by only requesting 50MB at a time.

$run_capture = false;
$expression_string = '';
$input_error = [];

/* Actions taken on form button click */
if ($_POST) {
	if (isset($_POST['start_button'])) {
		$action = 'start';
		$run_capture = true;
	} elseif (isset($_POST['stop_button'])) {
		$action = 'stop';
	} elseif (isset($_POST['view_button'])) {
		$action = 'view';
	} elseif (isset($_POST['download_button'])) {
		$action = 'download';
	} elseif (isset($_POST['clear_button'])) {
		$action = 'clear';
	}

	/* Save previous input to use on page load after submission */
	// capture options
	$input_interface = $_POST['interface'];
	if (!array_key_exists($input_interface, $available_interfaces)) {
		$input_error[] = 'No valid interface selected.';
	}
	$input_filter = ($_POST['filter'] !== null) ? intval($_POST['filter']) : null;
	if ($_POST['count'] == '0') {
		$input_count = 0;
	} elseif (empty($_POST['count'])) {
		$input_count = 1000;
	} elseif (!is_numericint($_POST['count'])) {
		$input_error[] = 'Invalid Packet Count.';
	} else {
		$input_count = intval($_POST['count']);
	}
	if (empty($_POST['length'])) {
		$input_length = 0;
	} elseif (!is_numericint($_POST['length'])) {
		$input_error[] = 'Invalid Packet Length.';
	} else {
		$input_length = intval($_POST['length']);
	}
	$input_promiscuous = empty($_POST['promiscuous']) ? false : $_POST['promiscuous'];
	// view options
	$input_viewdetail = empty($_POST['viewdetail']) ? 'normal' : $_POST['viewdetail'];
	$input_viewtype = empty($_POST['viewtype']) ? 'default' : $_POST['viewtype'];
	$input_lookup = empty($_POST['lookup']) ? false : $_POST['lookup'];

	// filter options
	$filterattributes = [];
	if ($input_filter == PCAP_FPRESET_CUSTOM) {
		// Variables to pre-fill form input based on POST data
		if (isset($_POST['tagged_taglevel'])) {
			$input_tagged_taglevel = $_POST['tagged_taglevel'];
		}

		foreach ($_POST as $key => $value) {
			/* Only the "match" select element values need to be checked. Determine
			 * the corresponding Section and Type from the element ID. */
			if (preg_match('/^untagged_[a-z]+_match$/i', $key)) {
				$fa_section = PCAP_SECTION_UNTAGGED;
				$fa_type_name = substr_replace(substr($key, 9), '', -6);
			} elseif (preg_match('/^tagged_[a-z]+_match$/i', $key)) {
				// The Section is determined by the tag level
				$fa_section = empty($_POST['tagged_taglevel']) ? PCAP_SECTION_TAGGED_MIN : $_POST['tagged_taglevel'];
				$fa_type_name = substr_replace(substr($key, 7), '', -6);
			} else {
				continue;
			}

			switch ($fa_type_name) {
				case 'ethertype':
					$fa_type = PCAP_TYPE_ETHERTYPE;
					break;
				case 'protocol':
					$fa_type = PCAP_TYPE_PROTOCOL;
					break;
				case 'ipaddress':
					$fa_type = PCAP_TYPE_IPADDRESS;
					break;
				case 'macaddress':
					$fa_type = PCAP_TYPE_MACADDRESS;
					break;
				case 'port':
					$fa_type = PCAP_TYPE_PORT;
					break;
				case 'tag':
					$fa_type = PCAP_TYPE_VLAN;
					break;
				case 'section':
					$fa_type = PCAP_TYPE_SMATCH;
					break;
				default:
					// Other Types don't need to be checked. ("continue" alone
					// only left the switch and reused the previous $fa_type.)
					continue 2;
			}

			// Get this match's corresponding input element ID to retrieve its value
			$fa_id = substr_replace($key, '', -6);

			/* A Section's "match" select element is used for both the input value
			 * and match operator. */
			if ($fa_type == PCAP_TYPE_SMATCH) {
				$fa_input = $fa_match = $value;
			} else {
				/* Check whether this match's selected value is the match operator or
				 * the input value. */
				if (in_array($value, PCAP_LIST_MATCH)) {
					/* Get the match operator from the match, and the input
					 * value from the corresponding input field. */
					$fa_input = isset($_POST[$fa_id]) ? $_POST[$fa_id] : '';
					$fa_match = $value;
				} else {
					/* Get the input value from the match and explicitly set
					 * the match operator. */
					$fa_input = $value;
					$fa_match = PCAP_MATCH_ATTR_ANYOF;
				}
			}

			// Generate variable variables to pre-fill form input based on POST data
			${'input_' . $key} = $value;
			${'input_' . $fa_id} = $_POST[$fa_id];

			try {
				// Create a FilterAttribute object for a Section or Type
				$fa = new FilterAttribute($fa_section, $fa_match, $fa_type);
				$fa->setInputString($fa_input);
			} catch (Exception $e) {
				$input_error[] = $e->getMessage();
				break;
			}
			$filterattributes[] = $fa;
		}
	} else {
		try {
			// Create a FilterAttribute object for the Filter Preset
			if (is_int($input_filter)) {
				$filterattributes[] = new FilterAttribute(PCAP_SECTION_FPRESET, $input_filter, PCAP_TYPE_APRESET);
			} else {
				throw new Exception("Invalid filter option given.");
			}
		} catch (Exception $e) {
			$input_error[] = $e->getMessage();
		}
	}

	$vlan_supported = !preg_match('/^(lo\d+|gif\d+|gre\d+|ppp\d+|pppoe\d+|pptp\d+|l2tp\d+|enc\d+|ipsec\d+|ovpn[sc]\d+|tun_wg\d+|tailscale\d+)/i', $input_interface);
	try {
		$expression_string = get_expression_string($filterattributes, $vlan_supported);
	} catch (Exception $e) {
		$input_error[] = $e->getMessage();
	}

	if (!empty($input_error)) {
		$run_capture = false;
	}
}

// Header page HTML
include('head.inc');

// Show input validation errors only when trying to start the packet capture
if (!empty($input_error) && $action == 'start') {
	print_input_errors($input_error);
}

// Which buttons show: Start or Stop in the action bar, View / Download / Clear on the last capture card
$show_buttons = ['stop_button' => false, 'start_button' => true, 'view_button' => false, 'download_button' => false, 'clear_button' => false];

// Handle button actions before displaying the form
if ($action == 'stop') {
	/* Kill relevant running tcpdump processes (don't defer to background),
 	 * then verify if it's running. */
	mwexec("/bin/pkill -f '^\/usr\/sbin\/tcpdump.*-w -'");
}
/* Check for any matching tcpdump processes currently running.
 * Handle multiple matches; assume the first match is correct. */
$processes_check = get_pgrep_output('^\/usr\/sbin\/tcpdump.*-w -');
$process_running = empty($processes_check) ? false : true;

$show_last_capture_details = false;
if ($process_running || $run_capture) {
	// Only show the Stop button
	$show_buttons['stop_button'] = true;
	$show_buttons['start_button'] = false;
} else {
	// Show the Start button
	if (file_exists($pcap_file_last)) {
		if ($action == 'clear') {
			// Clear related files (the file buttons stay hidden)
			foreach ($pcap_files_list as $pcap_file) {
				unlink_if_exists($pcap_file);
			}
			foreach ($plog_files_list as $plog_file) {
				unlink_if_exists($plog_file);
			}
		} else {
			$show_last_capture_details = true;
			$show_buttons['view_button'] = $show_buttons['download_button'] = $show_buttons['clear_button'] = true;
			if ($action == 'download') {
				send_user_download('file', $pcap_file_last);
			}
		}
	}
}

// Prepare the form variables
// Packet capture filter options
$form_filters = array(
	PCAP_FPRESET_CUSTOM => gettext('Custom Filter'),
	PCAP_FPRESET_ANY => gettext('Everything'),
	PCAP_FPRESET_UNTAGGED => gettext('Only Untagged'),
	PCAP_FPRESET_TAGGED => gettext('Only Tagged')
);
// View detail options
$form_viewdetail = array(
	'normal' => gettext('Normal'),
	'medium' => gettext('Medium'),
	'high' => gettext('High'),
	'full' => gettext('Full')
);
// View type options
$form_viewtype = array(
	'default' => gettext('Default Type'),
	'aodv' => 'AODV',
	'carp' => 'CARP',
	'cnfp' => 'CNFP',
	'lmp' => 'LMP',
	'pgm' => 'PGM',
	'pgm_zmtp1' => 'PGM_ZMTP1',
	'resp' => 'RESP',
	'radius' => 'RADIUS',
	'rpc' => 'RPC',
	'rtp' => 'RTP',
	'rtcp' => 'RTCP',
	'snmp' => 'SNMP',
	'tftp' => 'TFTP',
	'vat' => 'VAT',
	'wb' => 'WB',
	'zmtp1' => 'ZMTP1',
	'vxlan' => 'VXLAN'
);
// Section match selection fields
$form_fsmatch = array(
	PCAP_MATCH_SECT_NONE => gettext('exclude all'),
	PCAP_MATCH_SECT_ANYOF => gettext('include any of')
);
$form_fsmatch_tagged = array(
	PCAP_MATCH_SECT_NONE => gettext('exclude all'),
	PCAP_MATCH_SECT_ANYOF => gettext('include any of')
);
// Type match selection fields
$form_match_ipaddress = $form_match_macaddress = $form_match_port = array(
	PCAP_MATCH_ATTR_ANYOF => gettext('any of'),
	PCAP_MATCH_ATTR_ALLOF => gettext('all of'),
	PCAP_MATCH_ATTR_NONEOF => gettext('none of'),
	PCAP_MATCH_TYPE_ALLOF => gettext('OR all of'),
	PCAP_MATCH_TYPE_ANYOF => gettext('OR any of')
);
$form_match_tag = $form_match_protocol = $form_match_ethertype = array(
	PCAP_MATCH_ATTR_ANYOF => gettext('any of'),
	PCAP_MATCH_ATTR_NONEOF => gettext('none of'),
	PCAP_MATCH_TYPE_ANYOF => gettext('OR any of')
);
$form_match_ethertype +=  array(
	'ipv4' => '[IPv4]',
	'ipv6' => '[IPv6]',
	'arp' => '[ARP]'
);
$form_match_protocol += array(
	'ping' => '[Ping]',
	'ipsec' => '[IPsec]',
	'tcp' => '[TCP]',
	'udp' => '[UDP]',
	'carp' => '[CARP]',
	'pfsync' => '[pfsync]',
	'ospf' => '[OSPF]'
);
// Variables for each Section
$form_filter_section_begin_row = array('ipaddress', 'protocol');
$form_filter_section_end_row = array('taglevel', 'macaddress', 'ethertype');
$form_filter_sections = array(
	0 => array(
		'name' => 'untagged',
		'sectionlabel' => gettext('Untagged Filter'),
		'sectiondescription' => gettext('Filter options for packets without any VLAN tags.'),
		'matchdescription' => gettext('Untagged packets')
	),
	1 => array(
		'name' => 'tagged',
		'sectionlabel' => gettext('Tagged Filter'),
		'sectiondescription' => gettext('Filter options for packets that have a VLAN tag set. ' .
		    'Specify a tag level to match stacked VLAN packets (such as QinQ).'),
		'matchdescription' => gettext('Tagged packets')
	)
);
$form_filter_section_attributes_properties = array(
	'tag' => array(
		'placeholder' => gettext('e.g. 100 200'),
		'description' => gettext('VLAN tag'),
		'width' => 3
	),
	'taglevel' => array(
		'placeholder' => 1,
		'description' => gettext('Level'),
		'width' => 1
	),
	'ipaddress' => array(
		'placeholder' => gettext('e.g. 10.1.1.0/24 192.168.1.1'),
		'description' => gettext('Host IP address or subnet'),
		'width' => 6
	),
	'macaddress' => array(
		'placeholder' => gettext('e.g. 00:02 11:22:33:44:55:66'),
		'description' => gettext('Host MAC address'),
		'width' => 4
	),
	'protocol' => array(
		'placeholder' => gettext('e.g. 17 tcp'),
		'description' => gettext('Protocol'),
		'width' => 3
	),
	'port' => array(
		'placeholder' => gettext('e.g. 80 443'),
		'description' => gettext('Port number'),
		'width' => 3
	),
	'ethertype' => array(
		'placeholder' => gettext('e.g. arp 8100 0x8200'),
		'description' => gettext('Ethertype'),
		'width' => 4
	)
);

// Default form input values
if (!isset($input_filter)) {
	$input_filter = PCAP_FPRESET_CUSTOM;
}
if (!isset($input_promiscuous)) {
	$input_promiscuous = true;
}
if (!isset($input_viewdetail)) {
	$input_viewdetail = 'normal';
}
if (!isset($input_viewtype)) {
	$input_viewtype = 'default';
}
if (!isset($input_lookup)) {
	$input_lookup = false;
}
if (!isset($input_untagged_section_match)) {
	$input_untagged_section_match = PCAP_MATCH_SECT_ANYOF;
}
if (!isset($input_untagged_ipaddress_match)) {
	$input_untagged_ipaddress_match = PCAP_MATCH_ATTR_ALLOF;
}
if (!isset($input_tagged_ipaddress_match)) {
	$input_tagged_ipaddress_match = PCAP_MATCH_ATTR_ALLOF;
}

// Create the form
$form = new Form(false);
$form->setAttribute('id', 'pcap-form');
// Main panel
$section = new Form_Section('Capture options');
$group = new Form_Group('Capture');
$group->add(new Form_Select(
	'interface',
	null,
	$input_interface,
	$available_interfaces
))->setHelp('Interface to capture packets on.')->setWidth(4);
$group->add(new Form_Select(
	'filter',
	null,
	$input_filter,
	$form_filters
))->setHelp('Filter preset.')->addClass('match-selection')->setWidth(3);
$section->add($group);
$group = new Form_Group('Limits');
$group->add(new Form_Input(
	'count',
	'Packet Count',
	null,
	$input_count,
	array('type' => 'number', 'min' => 0, 'step' => 1, 'placeholder' => 1000)
))->setHelp('Packets to capture (default 1000, 0 for no limit).')->setWidth(3);
$group->add(new Form_Input(
	'length',
	'Packet Length',
	null,
	$input_length,
	array('type' => 'number', 'min' => 0, 'step' => 1, 'placeholder' => 0)
))->setHelp('Bytes per packet (default 0 for no limit).')->setWidth(3);
$section->add($group);
$section->addInput(new Form_Checkbox(
	'promiscuous',
	'Promiscuous mode',
	'Capture all traffic seen by the interface',
	$input_promiscuous
))->setHelp('Turn this off to capture only traffic to and from the interface, including broadcast and multicast traffic.');

$form->add($section);

// Hidden panel
$section = new Form_Section('Custom filter options');
$section->addClass('custom-options');
$section->addInput(new Form_StaticText(
	'Hint',
	sprintf('Separate several values with %1$sspaces%2$s. With "%1$sinclude any of%2$s", fill in ' .
	        'at least two types (for example ethertype and port): packets that match either type ' .
	        'are captured, not only those that match both.',
			'<b>', '</b>')
));
// Add each Section
foreach ($form_filter_sections as $fs_key => $fs_var) {
	$fs_name = $fs_var['name'];
	$fs_match_id           =       "{$fs_name}_section_match";
	$fs_match_input_varvar = "input_{$fs_name}_section_match";

	// Section header
	$section->addInput(new Form_StaticText(
		$fs_var['sectionlabel'],
		$fs_var['sectiondescription']
	));

	// Section match field
	$group = new Form_Group('');
	$group->addClass('no-separator');
	$group->add(new Form_Select(
		$fs_match_id,
		null,
		${$fs_match_input_varvar},
		($fs_key == array_key_first($form_filter_sections) ? $form_fsmatch : $form_fsmatch_tagged)
	))->setHelp($fs_var['matchdescription'])->addClass('match-selection inputselectcombo')->setWidth(3);
	$group->add(new Form_StaticText(
		null,
		null
	))->setWidth(3);

	// Type fields
	foreach ($form_filter_section_attributes_properties as $attribute_name => $attribute_strings) {
		// Don't add VLAN Types in an untagged Section
		if ($fs_key == array_key_first($form_filter_sections)) {
			if ($attribute_name == 'tag') {
				continue;
			}
			if ($attribute_name == 'taglevel') {
				$section->add($group);
				continue;
			}
		}
		// Variable variables for the Type fields
		$attribute_input_id     =       "{$fs_name}_{$attribute_name}";       // input element ID
		$attribute_match_id     =       "{$fs_name}_{$attribute_name}_match"; // select element ID
		$attribute_input_varvar = "input_{$fs_name}_{$attribute_name}";       // input element value
		$attribute_match_varvar = "input_{$fs_name}_{$attribute_name}_match"; // select element selected value
		$form_match_varvar = "form_match_{$attribute_name}";                  // select element options

		// Start a new row within this Section
		if (in_array($attribute_name, $form_filter_section_begin_row)) {
			$group = new Form_Group('');
			// Hide the row seperator for all but the last row.
			if ($attribute_name != $form_filter_section_begin_row[array_key_last($form_filter_section_begin_row)]) {
				$group->addClass('no-separator');
			}
		}

		// Add the Type field to the group
		switch ($attribute_name) {
			case 'taglevel':
				$attribute_field = new Form_Input(
					$attribute_input_id,
					$attribute_strings['placeholder'],
					null,
					${$attribute_input_varvar},
					array('type' => 'number', 'min' => 1, 'max' => 9, 'step' => 1)
				);
				break;
			default:
				$attribute_field = new Form_SelectInputCombo(
					$attribute_input_id,
					$attribute_strings['placeholder'],
					${$attribute_input_varvar}
				);
				$attribute_field->addSelect($attribute_match_id, ${$attribute_match_varvar}, ${$form_match_varvar});
				break;
		}
		$attribute_field->setHelp($attribute_strings['description'])->setWidth($attribute_strings['width']);
		$group->add($attribute_field);

		// Add the fields group to the form section when on the last Type of the row
		if (in_array($attribute_name, $form_filter_section_end_row)) {
			$section->add($group);
		}
	}
}
$form->add($section);

// View options (how a capture is decoded when viewing it)
$view_default = ($input_viewdetail == 'normal') && ($input_viewtype == 'default') && !$input_lookup;
$section = new Form_Section('View options', 'pcap-view-options', COLLAPSIBLE | ($view_default ? SEC_CLOSED : SEC_OPEN));
$section->addInput(new Form_Select(
	'viewdetail',
	'View detail',
	$input_viewdetail,
	$form_viewdetail
))->setHelp('The level of detail shown when viewing the packet capture.');
$section->addInput(new Form_Select(
	'viewtype',
	'View type',
	$input_viewtype,
	$form_viewtype
))->setHelp('Force the captured traffic to be interpreted as a specified type.');
$section->addInput(new Form_Checkbox(
	'lookup',
	'Name lookup',
	'Resolve port, host and MAC address names',
	$input_lookup
))->setHelp('Reverse DNS lookups can make viewing the capture much slower.');
$form->add($section);

// Start / Stop in the action bar
$btn = new Form_Button('start_button', 'Start', null, 'fa-solid fa-play');
$btn->addClass('btn-primary')->setAttribute('data-fs-busy', 'true');
if (!$show_buttons['start_button']) {
	$btn->setAttribute('hidden', true);
}
$form->addGlobal($btn);
$btn = new Form_Button('stop_button', 'Stop', null, 'fa-solid fa-stop');
$btn->removeClass('btn-primary')->addClass('btn-danger');
if (!$show_buttons['stop_button']) {
	$btn->setAttribute('hidden', true);
}
$form->addGlobal($btn);

/* Show the form */
echo $form;

/* View / Download / Clear post the options form from outside it (form="pcap-form") */
$file_buttons = function ($with_clear) {
	$html = '<button type="submit" form="pcap-form" class="btn btn-sm btn-outline-secondary" name="view_button" id="view_button" value="View">'
	    . '<i class="fa-regular fa-file-lines icon-embed-btn" aria-hidden="true"></i>' . gettext('View') . '</button>'
	    . '<button type="submit" form="pcap-form" class="btn btn-sm btn-outline-secondary" name="download_button" id="download_button" value="Download">'
	    . '<i class="fa-solid fa-download icon-embed-btn" aria-hidden="true"></i>' . gettext('Download') . '</button>';
	if ($with_clear) {
		$html .= '<button type="submit" form="pcap-form" class="btn btn-sm btn-outline-danger" name="clear_button" id="clear_button" value="Clear Captures"'
		    . ' data-fs-confirm="' . fs_h(gettext('Delete the stored packet captures?')) . '"'
		    . ' data-fs-confirm-detail="' . fs_h(gettext('The capture files and their decoded output are removed.')) . '"'
		    . ' data-fs-confirm-action="' . fs_h(gettext('Clear captures')) . '">'
		    . '<i class="fa-solid fa-trash-can icon-embed-btn" aria-hidden="true"></i>' . gettext('Clear captures') . '</button>';
	}
	return $html;
};

if ($show_last_capture_details):
?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Last capture')?></h2>
		<div class="fs-pcap-actions"><?=$file_buttons(true)?></div>
	</div>
	<div class="panel-body">
		<dl class="fs-pcap-facts">
			<dt><?=gettext('File')?></dt><dd class="fs-mono"><?=htmlspecialchars(basename($pcap_file_last))?></dd>
			<dt><?=gettext('Size')?></dt><dd class="fs-mono"><?=htmlspecialchars(format_bytes(filesize($pcap_file_last)))?></dd>
			<dt><?=gettext('Started')?></dt><dd><?=htmlspecialchars(date('Y-m-d H:i:s', strtotime(substr($pcap_file_last, -19, 14))))?></dd>
			<dt><?=gettext('Stopped')?></dt><dd><?=htmlspecialchars(date('Y-m-d H:i:s', filemtime($pcap_file_last)))?></dd>
		</dl>
	</div>
</div>
<?php
endif;

/* Show the capture */
if ($action == 'stop' || $action == 'view' || $process_running || $run_capture) :
	$cmd_part_lookup = $input_lookup ? '' : ' -n';
	switch ($input_viewdetail) {
		case 'full':
			$cmd_part_viewdetail = ' -vv -e';
			break;
		case 'high':
			$cmd_part_viewdetail = ' -vv';
			break;
		case 'medium':
			$cmd_part_viewdetail = ' -v';
			break;
		default:
			$input_viewdetail = 'normal';
			$cmd_part_viewdetail = ' -q';
			break;
	}
	switch ($input_viewtype) {
		case 'aodv': // Ad-hoc On-demand Distance Vector protocol
			$cmd_part_viewtype = ' -T aodv';
			break;
		case 'carp': // Common Address Redundancy Protocol
			$cmd_part_viewtype = ' -T carp';
			break;
		case 'cnfp': // Cisco NetFlow Protocol
			$cmd_part_viewtype = ' -T cnfp';
			break;
		case 'lmp': // Link Management Protocol
			$cmd_part_viewtype = ' -T lmp';
			break;
		case 'pgm': // Pragmatic General Multicast
			$cmd_part_viewtype = ' -T pgm';
			break;
		case 'pgm_zmtp1': // ZMTP/1.0 inside PGM/EPGM
			$cmd_part_viewtype = ' -T pgm_zmtp1';
			break;
		case 'resp': // REdis Serialization Protocol
			$cmd_part_viewtype = ' -T resp';
			break;
		case 'radius': // RADIUS
			$cmd_part_viewtype = ' -T radius';
			break;
		case 'rpc': // Remote Procedure Call
			$cmd_part_viewtype = ' -T rpc';
			break;
		case 'rtp': // Real-Time Applications Protocol
			$cmd_part_viewtype = ' -T rtp';
			break;
		case 'rtcp': // Real-Time Applications control Protocol
			$cmd_part_viewtype = ' -T rtcp';
			break;
		case 'snmp': // Simple Network Management Protocol
			$cmd_part_viewtype = ' -T snmp';
			break;
		case 'tftp': // Trivial File Transfer Protocol
			$cmd_part_viewtype = ' -T tftp';
			break;
		case 'vat': // Visual Audio Tool
			$cmd_part_viewtype = ' -T vat';
			break;
		case 'wb': // distributed White Board
			$cmd_part_viewtype = ' -T wb';
			break;
		case 'zmtp1': // ZeroMQ Message Transport Protocol 1.0
			$cmd_part_viewtype = ' -T zmtp1';
			break;
		case 'vxlan': // Virtual eXtensible Local Area Network
			$cmd_part_viewtype = ' -T vxlan';
			break;
		default: // Do not force a view type
			$input_viewtype = 'default';
			$cmd_part_viewtype = '';
			break;
	}

	/* Run tcpdump */
	$pcap_file_suffix = '-' . date('YmdHis');
	$plog_file_current = $pcap_files_root . '/packetcapture-'. $input_viewdetail . (empty($cmd_part_lookup) ? '' : '-lookup') . (empty($cmd_part_viewtype) ? '' : '-' . $input_viewtype) . $pcap_file_suffix . '.plog';
	if ($run_capture) {
		// Generate the file name to write to
		$pcap_file_current = $pcap_files_root . '/packetcapture-' . $input_interface . $pcap_file_suffix . '.pcap';
		unlink_if_exists($pcap_file_current);
		unlink_if_exists($plog_file_current);

		// Handle capture options
		$cmd_part_promiscuous = $input_promiscuous ? '' : ' -p';
		$cmd_part_count = empty($input_count) ? '' : " -c " . escapeshellarg($input_count);
		$cmd_part_length = empty($input_length) ? '' : " -s " . escapeshellarg($input_length);
		$cmd_expression_string = $expression_string ? escapeshellarg($expression_string) : '';

		/* Output in binary format (use packet-buffered to avoid missing packets) to stdout,
		* use tee to write the binary file and pipe the output,
		* use a second tcpdump process to parse the binary output,
		* lastly save the parsed output to a text file to read later. */
		$cmd_run = sprintf('/usr/sbin/tcpdump -ni %1$s%2$s%3$s%4$s -U -w - %6$s | /usr/bin/tee %5$s | /usr/sbin/tcpdump -l%7$s%8$s%9$s -r - | /usr/bin/tee %10$s',
		                $input_interface, $cmd_part_promiscuous, $cmd_part_count,
		                $cmd_part_length, $pcap_file_current, $cmd_expression_string,
		                $cmd_part_lookup, $cmd_part_viewdetail, $cmd_part_viewtype,
		                $plog_file_current);
		$process_running_cmd = strstr($cmd_run, ' |', TRUE);
		mwexec_bg($cmd_run);
	} else {
		$pcap_file_current = $pcap_file_last;
		if (!$process_running && !file_exists($plog_file_current)) {
			// Make sure the pcap log file is generated
			$cmd_run = sprintf('/usr/sbin/tcpdump%1$s%2$s%3$s -r %4$s | /usr/bin/tee %5$s', $cmd_part_lookup, $cmd_part_viewdetail, $cmd_part_viewtype, $pcap_file_current, $plog_file_current);
			mwexec_bg($cmd_run);
		}
	}

	$capture_live = ($process_running || $run_capture);
	if ($capture_live && !isset($process_running_cmd) && !empty($processes_check)) {
		$process_running_cmd = $processes_check[array_key_first($processes_check)];
	}
?>

<!-- Packet Capture View -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Capture output')?></h2>
		<span id="pcap-state-running"<?=$capture_live ? '' : ' hidden'?>><?=fs_badge('pending', gettext('Capturing'))?></span>
		<span id="pcap-state-done"<?=$capture_live ? ' hidden' : ''?>><?=fs_badge('neutral', gettext('Stopped'))?></span>
<?php if ($capture_live): ?>
		<div class="form-check form-switch fs-pcap-autoscroll">
			<input class="form-check-input" type="checkbox" role="switch" id="autoscroll" checked>
			<label class="form-check-label" for="autoscroll"><?=gettext('Auto-scroll')?></label>
		</div>
		<div class="fs-pcap-actions" id="pcap-file-buttons" hidden><?=$file_buttons(false)?></div>
<?php endif; ?>
		<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#pcap_output">
			<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
		</button>
	</div>
<?php if ($capture_live && !empty($process_running_cmd)): ?>
	<div class="fs-pcap-cmd"><span class="fs-muted"><?=gettext('Running')?></span> <code class="fs-mono"><?=htmlspecialchars($process_running_cmd)?></code></div>
<?php endif; ?>
	<pre class="fs-console fs-pcap-output" id="pcap_output" aria-live="off"><?=gettext('Waiting for output…')?></pre>
	<div class="panel-footer small fs-muted"><span class="fs-mono"><?=htmlspecialchars(basename((string)$pcap_file_current))?></span></div>
</div>

<script>
events.push(function() {
	var overrideScroll = false;
	var isLive = <?=$capture_live ? 'true' : 'false'?>;
	var output = document.querySelector('#pcap_output');
	var autoscroll = document.querySelector('#autoscroll');
	var bytesRead = 0;
	var started = false;
	var emptyTries = 0;

	function checkProcess() {
		$.ajax({
			url: "diag_packet_capture.php",
			type: "post",
			data: {
				isCaptureRunning: "ajax"
			},
			success: function(result) {
				if (result === "false") {
					isLive = false;
					var stop = document.getElementById('stop_button');
					var start = document.getElementById('start_button');
					if (stop) {
						stop.hidden = true;
					}
					if (start) {
						start.hidden = false;
					}
					document.getElementById('pcap-state-running').hidden = true;
					document.getElementById('pcap-state-done').hidden = false;
					var files = document.getElementById('pcap-file-buttons');
					if (files) {
						files.hidden = false;
					}
				}
			}
		});
	}

	function refreshOutput(bytes) {
		bytes = bytes || 0;
		$.ajax({
			url: "diag_packet_capture.php",
			type: "post",
			data: {
				ajaxLog: "ajax",
				byte: bytes,
				maxRead: <?=(int)$max_view_size?>,
				file: <?=json_encode((string)$plog_file_current)?>
			},
			success: function(result) {
				var response = JSON.parse(result);
				if (!isLive && !(response.bytesRead > 0)) {
					// the decoded output of a stopped capture may still be written in the background
					if (!started && ++emptyTries < 6) {
						setTimeout(function() { refreshOutput(bytes); }, 1000);
						return;
					}
					if (!started) {
						output.textContent = <?=json_encode(gettext('No packets in this capture.'))?>;
					}
					return;
				}
				if (!started && response.bytesRead > 0) {
					output.textContent = "";
					started = true;
				}

				// If read returns 0 bytes check if tcpdump is still running
				if (response.bytesRead == 0) {
					checkProcess();
				}

				output.textContent += response.output;
				bytesRead = bytes + response.bytesRead;

				if (autoscroll && autoscroll.checked) {
					overrideScroll = true;
					output.scrollTop = output.scrollHeight;
					overrideScroll = false;
				}

				setTimeout(function() { refreshOutput(bytesRead); }, 2500);
			}
		});
	}

	if (autoscroll) {
		output.addEventListener("scroll", function () {
			if (!overrideScroll) {
				autoscroll.checked = (output.scrollHeight <= (output.scrollTop + output.clientHeight + 2));
			}
		});
	}
	setTimeout(refreshOutput, 500);
});
</script>
<?php
endif;
?>
<style>
.fs-pcap-actions { display: inline-flex; flex-wrap: wrap; gap: var(--fs-sp-2); }
.fs-pcap-autoscroll { margin: 0; font-size: var(--fs-fs-sm); white-space: nowrap; }
.fs-pcap-cmd { padding: var(--fs-sp-2) var(--fs-sp-4); border-bottom: 1px solid var(--fs-border); font-size: var(--fs-fs-sm); overflow-wrap: anywhere; }
.fs-pcap-cmd code { color: var(--fs-text); background: none; }
.fs-pcap-output { min-height: 12rem; border-radius: 0; }
.custom-options div.inputselectcombo select { max-width: none; min-width: 8.5rem; white-space: nowrap; }
.fs-pcap-facts { display: grid; grid-template-columns: 7rem minmax(0, 1fr); gap: .4rem 1rem; margin: 0; }
.fs-pcap-facts dt { color: var(--fs-text-muted); font-weight: 500; }
.fs-pcap-facts dd { margin: 0; overflow-wrap: anywhere; }
@media (max-width: 575.98px) {
	.panel-heading:has(.fs-pcap-actions) { flex-wrap: wrap; }
}
</style>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	const PCAP_FPRESET_CUSTOM = <?=PCAP_FPRESET_CUSTOM?>;
	const PCAP_MATCH_SECT_NONE = <?=PCAP_MATCH_SECT_NONE?>;
	const PCAP_MATCH_SECT_ALLOF = <?=PCAP_MATCH_SECT_ALLOF?>;
	const PCAP_MATCH_SECT_ANYOF = <?=PCAP_MATCH_SECT_ANYOF?>;
	const PCAP_MATCH_TYPE_ALLOF = <?=PCAP_MATCH_TYPE_ALLOF?>;
	const PCAP_MATCH_TYPE_ANYOF = <?=PCAP_MATCH_TYPE_ANYOF?>;
	const PCAP_MATCH_ATTR_ANYOF = <?=PCAP_MATCH_ATTR_ANYOF?>;
	const PCAP_MATCH_ATTR_ALLOF = <?=PCAP_MATCH_ATTR_ALLOF?>;
	const PCAP_MATCH_ATTR_NONEOF = <?=PCAP_MATCH_ATTR_NONEOF?>;

	const idUntaggedList = ['untagged_section_match', 'untagged_ethertype_match', 'untagged_ethertype',
	    'untagged_protocol_match', 'untagged_protocol', 'untagged_ipaddress_match', 'untagged_ipaddress',
	    'untagged_macaddress_match', 'untagged_macaddress', 'untagged_port_match', 'untagged_port'];

	const idTaggedList = ['tagged_section_match', 'tagged_taglevel', 'tagged_tag_match', 'tagged_tag',
	    'tagged_ethertype_match', 'tagged_ethertype', 'tagged_protocol_match',
	    'tagged_protocol', 'tagged_ipaddress_match', 'tagged_ipaddress',
	    'tagged_macaddress_match', 'tagged_macaddress', 'tagged_port_match', 'tagged_port'];

	const idAllList = idUntaggedList.concat(idTaggedList);

	// Disables the given input element
	function disableElement(id, isDisabled) {
		$('#' + id).prop('disabled', isDisabled);
	}

	// Disable input elements depending on filter and match selections
	function disableInput(idList, isElementDisabled, isSectionDisabled) {
		for (let element of idList) {
			if (element == 'untagged_section_match' || element == 'tagged_section_match') {
				// Handle the Section match
				disableElement(element, isSectionDisabled);
			} else if (element.indexOf('_match') == -1 && $('#' + element + '_match').length > 0) {
				// Element ID does not contain "_match" - it must be an input field.
				var elementMatch = '#' + element + '_match';
				// Disable the input field depending on the respective section and input match
				if (!isElementDisabled && ($(elementMatch).val() == PCAP_MATCH_ATTR_NONEOF ||
				    $(elementMatch).val() == PCAP_MATCH_ATTR_ANYOF ||
				    $(elementMatch).val() == PCAP_MATCH_ATTR_ALLOF ||
				    $(elementMatch).val() == PCAP_MATCH_TYPE_ALLOF ||
				    $(elementMatch).val() == PCAP_MATCH_TYPE_ANYOF)) {
					disableElement(element, false);
				} else {
					disableElement(element, true);
				}
			} else {
				// Element is an input match field
				disableElement(element, isElementDisabled);
			}
		}
	}

	// Remove focus on page load
	document.activeElement.blur()

	// Match selection handlers
	$('.match-selection').on('change', function() {
		// Validate that the element can be handled
		if ((this.id).indexOf('_match') != -1 || (this.id) == 'filter') {
			switch (this.id) {
				case 'filter':
					// On selecting a filter preset
					hideClass('custom-options', (this.value != PCAP_FPRESET_CUSTOM));
					var isDisableAll = (this.value != PCAP_FPRESET_CUSTOM);
					var isDisableTagged = ($('#tagged_section_match').val() == PCAP_MATCH_SECT_NONE);
					disableInput(idAllList, isDisableAll, isDisableAll);
					disableInput(idTaggedList, isDisableTagged, false);
					break;
				case 'untagged_section_match':
					// On selecting an untagged Section match
					disableInput(idUntaggedList, (this.value == PCAP_MATCH_SECT_NONE), false);
					break;
				case 'tagged_section_match':
					// On selecting a tagged Section match
					disableInput(idTaggedList, (this.value == PCAP_MATCH_SECT_NONE), false);
					break
				default:
					// On selecting a Type Match, handle the respective input field
					disableElement((this.id).replace('_match', ''), !(this.value == PCAP_MATCH_ATTR_NONEOF ||
					    this.value == PCAP_MATCH_ATTR_ANYOF || this.value == PCAP_MATCH_ATTR_ALLOF ||
					    this.value == PCAP_MATCH_TYPE_ALLOF || this.value == PCAP_MATCH_TYPE_ANYOF));
					break;
			}
		}
	});

	// On initial page load
	if ($('#filter').val() != PCAP_FPRESET_CUSTOM) {
		hideClass('custom-options', true);
		disableInput(idAllList, true, true);
	} else {
		hideClass('custom-options', false);
		disableInput(idUntaggedList, ($('#untagged_section_match').val() == PCAP_MATCH_SECT_NONE), false);
		disableInput(idTaggedList, ($('#tagged_section_match').val() == PCAP_MATCH_SECT_NONE), false);
	}
});
//]]>
</script>

<?php

// page footer
include('foot.inc');
