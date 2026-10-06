<?php
/*
 * vpn_ipsec_settings.php
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
##|*IDENT=page-vpn-ipsec-settings
##|*NAME=VPN: IPsec: Settings
##|*DESCR=Allow access to the 'VPN: IPsec: Settings' page.
##|*MATCH=vpn_ipsec_settings.php*
##|-PRIV

require_once("functions.inc");
require_once("guiconfig.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("vpn_ipsec.inc");

global $ipsec_filtermodes;

$pconfig = ipsec_settings_form();

if ($_POST['save']) {
	unset($input_errors);

	$rv = ipsec_settings_save($_POST);
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];
	if ($rv['changes_applied']) {
		$changes_applied = true;
		$retval = $rv['retval'];
	}
}

$pconfig['async_crypto'] = config_get_path('ipsec/async_crypto', 'disabled');

$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Advanced Settings"));
$pglinks = array("", "vpn_ipsec.php", "@self");
$shortcut_section = "ipsec";

include("head.inc");

if ($changes_applied) {
	print_apply_result_box($retval);
}

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('vpn-ipsec', 'vpn_ipsec_settings.php');

$form = new Form;

$section = new Form_Section('IPsec Logging Controls');

foreach ($ipsec_log_cats as $cat => $desc) {
	$section->addInput(new Form_Select(
		'logging_' . $cat,
		$desc,
		$pconfig['logging'][$cat],
		$ipsec_log_sevs
	))->setWidth(2);
}

$section->addInput(new Form_StaticText('', ''))->setHelp(
	'Changes the log verbosity for the IPsec daemon, so that more detail will be generated to aid in troubleshooting.'
);

$form->add($section);

$section = new Form_Section('Advanced IPsec Settings');

$section->addInput(new Form_Select(
	'uniqueids',
	'Configure Unique IDs as',
	$pconfig['uniqueids'],
	$ipsec_idhandling
))->setHelp(
	'Whether a particular participant ID should be kept unique, with any new IKE_SA using an ID ' .
	'deemed to replace all old ones using that ID. Participant IDs normally are unique, so a new ' .
	'IKE_SA using the same ID is almost invariably intended to replace an old one. ' .
	'The difference between %1$sno%2$s and %1$snever%2$s is that the old IKE_SAs will be replaced when receiving an ' .
	'INITIAL_CONTACT notify if the option is no but will ignore these notifies if %1$snever%2$s is configured. ' .
	'The daemon also accepts the value %1$skeep%2$s to reject ' .
	'new IKE_SA setups and keep the duplicate established earlier. Defaults to Yes.',
	'<b>', '</b>'
);

$section->addInput(new Form_Select(
	'filtermode',
	'IPsec Filter Mode',
	$pconfig['filtermode'],
	$ipsec_filtermodes
))->setHelp(
	'Experimental. Controls how the firewall will filter IPsec traffic. By default, rules on ' .
	'the IPsec tab filter all IPsec traffic, including tunnel mode, transport mode, and VTI mode. %3$s' .
	'This is limited in that it does not allow for filtering on assigned VTI or transport mode interfaces (e.g. GRE), and it does not ' .
	'support features such as NAT rules and reply-to for return routing. ' .
	'When set to filter on assigned VTI and transport interfaces, %1$sall tunnel mode traffic is blocked%2$s. ' .
	'Do not set this option unless %1$sall%2$s IPsec tunnels are using VTI or transport mode.',
	'<b>', '</b>', '<br />'
);

$section->addInput(new Form_Checkbox(
	'ikev2_retransmit_enable',
	'IKEv2 Retransmission Parameters',
	'Set IKEv2 Retransmission parameters',
	$pconfig['ikev2_retransmit_enable']
))->toggles('.toggle-ikev2_retransmit_enable')->setHelp('Retransmission timeout parameters for IKEv2.');

$group = new Form_Group('');
$group->addClass('toggle-ikev2_retransmit_enable collapse');

if (!empty($pconfig['ikev2_retransmit_enable'])) {
	$group->addClass('show');
}

$group->add(new Form_Input(
	'ikev2_retransmit_tries',
	'Retransmit Tries',
	'number',
	$pconfig['ikev2_retransmit_tries'],
	['placeholder' => 5]
))->setHelp('%1$sRetransmit Tries%2$s -%3$sNumber of retransmissions to send before giving up.',
	'<b>', '</b>', '<br/>');

$group->add(new Form_Input(
	'ikev2_retransmit_timeout',
	'Retransmit Timeout',
	'text',
	$pconfig['ikev2_retransmit_timeout'],
	['placeholder' => 4.0]
))->setHelp('%1$sRetransmit Timeout%2$s -%3$sTimeout in seconds.',
	'<b>', '</b>', '<br/>');

$group->add(new Form_Input(
	'ikev2_retransmit_base',
	'Retransmit Base',
	'text',
	$pconfig['ikev2_retransmit_base'],
	['placeholder' => 1.8]
))->setHelp('%1$sRetransmit Base%2$s -%3$sBase of exponential backoff.',
	'<b>', '</b>', '<br/>');

$group->add(new Form_Input(
	'ikev2_retransmit_jitter',
	'Retransmit Jitter',
	'number',
	$pconfig['ikev2_retransmit_jitter'],
	['placeholder' => 0]
))->setHelp('%1$sRetransmit Jitter%2$s -%3$sMaximum jitter in percent to apply randomly to calculated ' .
	    'retransmission timeout (0 to disable).', '<b>', '</b>', '<br/>');

$group->add(new Form_Input(
	'ikev2_retransmit_limit',
	'Retransmit Limit',
	'number',
	$pconfig['ikev2_retransmit_limit'],
	['placeholder' => 0]
))->setHelp('%1$sRetransmit Limit%2$s -%3$sUpper limit in seconds for calculated retransmission timeout ' .
	    '(0 to disable).', '<b>', '</b>', '<br/>');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'compression',
	'IP Compression',
	'Enable IPCompression',
	$pconfig['compression']
))->setHelp('IPComp compression of content is proposed on the connection.');

$section->addInput(new Form_Checkbox(
	'pkcs11support',
	'PKCS#11 Support',
	'Enable PKCS#11',
	$pconfig['pkcs11support']
))->setHelp('Allow use of PKCS#11 tokens for Phase 1 authentication. Note that restarting the PS/SC Smart Card service will restart IPsec and vice versa.');

$section->addInput(new Form_Checkbox(
	'enableinterfacesuse',
	'Strict interface binding',
	'Enable strict interface binding',
	$pconfig['enableinterfacesuse']
))->setHelp('Enable strongSwan\'s interfaces_use option to bind specific interfaces only. This option is known to break IPsec with dynamic IP interfaces. This is not recommended at this time.');

$section->addInput(new Form_Checkbox(
	'acceptunencryptedmainmode',
	'Unencrypted payloads in IKEv1 Main Mode',
	'Accept unencrypted ID and HASH payloads in IKEv1 Main Mode',
	$pconfig['acceptunencryptedmainmode']
))->setHelp(
	'Some implementations send the third Main Mode message unencrypted, probably to find the PSKs for the specified ID for authentication. ' .
	'This is very similar to Aggressive Mode, and has the same security implications: ' .
	'A passive attacker can sniff the negotiated Identity, and start brute forcing the PSK using the HASH payload. ' .
	'It is recommended to keep this option to no, unless the exact implications are known and compatibility is required for such devices (for example, some SonicWall boxes).'
);

$section->addInput(new Form_Input(
	'maxexchange',
	'Maximum IKEv1 Phase 2 Exchanges',
	'number',
	$pconfig['maxexchange'],
	['placeholder' => '3']
))->setHelp(
	'IKEv1 phase 2 rekeying for one VPN gateway can be initiated in parallel. By default only 3 parallel rekeys are allowed. ' .
	'Undersized values can break VPN connections with many phase 2 definitions. ' .
	'If unsure, set this value to match the largest number of phase 2 entries on any phase 1.'
);

$section->addInput(new Form_Checkbox(
	'unityplugin',
	'Enable Cisco Extensions',
	'Enable Unity Plugin',
	$pconfig['unityplugin']
))->setHelp('Enable Unity Plugin which provides Cisco Extension support such as Split-Include, Split-Exclude and Split-Dns.');

$section->addInput(new Form_Checkbox(
	'strictcrlpolicy',
	'Strict CRL Checking',
	'Enable strict Certificate Revocation List checking',
	$pconfig['strictcrlpolicy']
))->setHelp('Check this to require availability of a fresh CRL for peer authentication based on certificate signatures to succeed.');

$section->addInput(new Form_Input(
	'dns-interval',
	'FQDN Endpoints Resolve Interval',
	'number',
	$pconfig['dns-interval'],
	['placeholder' => '60']
))->setHelp('Interval, in seconds, that will be used to resolve FQDN remote gateways.');

$section->addInput(new Form_Checkbox(
	'makebeforebreak',
	'Make before Break',
	'Initiate IKEv2 reauthentication with a make-before-break',
	$pconfig['makebeforebreak']
))->setHelp('Instead of a break-before-make scheme. Make-before-break uses overlapping IKE and CHILD_SA during reauthentication ' .
			'by first recreating all new SAs before deleting the old ones. This behavior can be beneficial to avoid connectivity gaps ' .
			'during reauthentication, but requires support for overlapping SAs by the peer.');

$section->addInput(new Form_Checkbox(
	'async_crypto',
	'Asynchronous Cryptography',
	'Use asynchronous mode to parallelize multiple cryptography jobs',
	($pconfig['async_crypto'] == "enabled")
))->setHelp('Allow crypto(9) jobs to be dispatched multi-threaded to increase performance. ' .
		'Jobs are handled in the order they are received so that packets will be reinjected in the correct order.');

$group = new Form_Group('Custom ports');
$group->add(new Form_Input(
  'port',
	'IKE port',
	'number',
	$pconfig['port'],
	['min' => 1, 'max' => 65535]
))->setHelp('Local UDP port for IKE (Default: 500)');

$group->add(new Form_Input(
  'port_nat_t',
	'NAT-T port',
	'number',
	$pconfig['port_nat_t'],
	['min' => 1, 'max' => 65535]
))->setHelp('Local UDP port for NAT-T (Default: 4500)');
$section->add($group);

$section->addInput(new Form_Checkbox(
	'autoexcludelanaddress',
	'Auto-exclude LAN address',
	'Enable bypass for LAN interface IP',
	!$pconfig['noshuntlaninterfaces']
))->setHelp('Exclude traffic from LAN subnet to LAN IP address from IPsec.');

$section->addInput(new Form_Checkbox(
	'ipsecbypass',
	'Additional IPsec bypass',
	'Enable extra IPsec bypass rules',
	$pconfig['ipsecbypass']
))->setHelp('Create extra rules to exclude specific traffic from IPsec.');

$form->add($section);

$section = new Form_Section('IPsec bypass rules');
$section->addClass('ipsecbypass');

if (!$pconfig['bypassrules']) {
	$pconfig['bypassrules'] = array();
	$pconfig['bypassrules']['rule']  = array(array('source' => '', 'srcmask' => '32',
	       					'destination' => '', 'dstmask' => '32'));
}

$numrows = count($item) -1;
$counter = 0;

$numrows = count($pconfig['bypassrules']['rule']) -1;

foreach ($pconfig['bypassrules']['rule'] as $rule) {
	$group = new Form_Group(($counter == 0) ? 'Rule':null);
	$group->addClass('repeatable');

	$group->add(new Form_IpAddress(
		'source' . $counter,
		null,
		$rule['source']
	))->setWidth(4)->setHelp($numrows == $counter ? 'Source address' : null)->addMask('srcmask' . $counter, $rule['srcmask'], 128, 0);

	$group->add(new Form_IpAddress(
		'destination' . $counter,
		null,
		$rule['destination']
	))->setWidth(4)->setHelp($numrows == $counter ? 'Destination address' : null)->addMask('dstmask' . $counter, $rule['dstmask'], 128, 0);

	$group->add(new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning');

	$section->add($group);

	$counter++;
}

$section->addInput(new Form_Button(
	'addrow',
	'Add',
	null,
	'fa-solid fa-plus'
))->addClass('btn-success');

$form->add($section);

print $form;

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var ipsecbypass = false;

	function show_ipsecbypass() {
		hide = !$('#ipsecbypass').prop('checked');
		hideClass('ipsecbypass', hide);
	}

	$('#ipsecbypass').click(function () {
		show_ipsecbypass();
	});

	show_ipsecbypass();
	checkLastRow();
});
//]]>
</script>

<?php include("foot.inc"); ?>
