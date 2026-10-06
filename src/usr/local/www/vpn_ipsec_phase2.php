<?php
/*
 * vpn_ipsec_phase2.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc
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
##|*IDENT=page-vpn-ipsec-editphase2
##|*NAME=VPN: IPsec: Edit Phase 2
##|*DESCR=Allow access to the 'VPN: IPsec: Edit Phase 2' page.
##|*MATCH=vpn_ipsec_phase2.php*
##|-PRIV

require_once("functions.inc");
require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("vpn_ipsec.inc");

global $p2_pfskeygroups;
$ipsec_lidtype_flags = [SPECIALNET_ADDR, SPECIALNET_NET, SPECIALNET_IFSUB];
$ipsec_nlitype_flags = [SPECIALNET_NONE, SPECIALNET_ADDR, SPECIALNET_NET];

if (!empty($_REQUEST['p2index'])) {
	$uindex = $_REQUEST['p2index'];
}

if (!empty($_REQUEST['uniqid'])) {
	$uindex = $_REQUEST['uniqid'];
}

if (!empty($_REQUEST['dup'])) {
	$uindex = $_REQUEST['dup'];
}

$p2index = isset($uindex) ? ipsec_p2_index($uindex) : null;

$pconfig = ipsec_p2_form(($p2index !== null) ? config_get_path('ipsec/phase2/' . $p2index) : null, $_REQUEST['ikeid'],
    isset($_REQUEST['mobile']));

if (!empty($_REQUEST['dup'])) {
	unset($uindex);
	$p2index = null;
	$pconfig['uniqid'] = uniqid();
	$pconfig['reqid'] = ipsec_new_reqid();
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = ipsec_p2_save($pconfig, $p2index);
	if (!$input_errors) {
		header("Location: vpn_ipsec.php");
		exit;
	}
}

$localid_help_tunnel  = "Local network component of this IPsec security association.";
$localid_help_vti     = "Local point-to-point IPsec interface tunnel network address.";
$localid_help_mobile  = "Network reachable by mobile IPsec clients.";
$remoteid_help_tunnel = "Remote network component of this IPsec security association.";
$remoteid_help_vti    = "Remote point-to-point IPsec interface tunnel network address.";

if (isset($pconfig['mobile'])) {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Mobile Clients"), gettext("Edit Phase 2"));
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec_mobile.php", "@self");
	$editing_mobile = true;
} else {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Tunnels"), gettext("Edit Phase 2"));
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec.php", "@self");
	$editing_mobile = false;
}
$shortcut_section = "ipsec";

include("head.inc");

// lifetimes and advanced options start closed; open after a failed save
$fs_section_state = COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED);

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('vpn-ipsec', 'vpn_ipsec.php');

$form = new Form();

$section = new Form_Section('General Information');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

$section->addInput(new Form_Checkbox(
	'disabled',
	'Disabled',
	'Disable this phase 2 entry without removing it from the list. ',
	$pconfig['disabled']
));

$section->addInput(new Form_Select(
	'mode',
	'*Mode',
	$pconfig['mode'],
	$p2_modes
));

if (!empty($pconfig['ikeid'])) {
	$p1 = ipsec_get_phase1($pconfig['ikeid']);
	if (!empty($p1['descr'])) {
		$p1name = htmlspecialchars($p1['descr']);
	} else {
		$p1name = '<i>' . gettext('No description') . '</i> ';
	}
	$p1name .= ' (IKE ID ' . htmlspecialchars($pconfig['ikeid']);
	if (isset($pconfig['mobile'])) {
		$p1name .= ', ' . gettext('Mobile');
	}
	$p1name .= ')';
	$section->addInput(new Form_StaticText(
		'Phase 1',
		$p1name .
		' <a class="fa-solid fa-pencil" href="vpn_ipsec_phase1.php?ikeid=' . urlencode((string)$p1['ikeid']) . '" title="' . gettext("Edit Phase 1 Entry") . '"></a>'
	));
}
if (!empty($pconfig['reqid'])) {
	$section->addInput(new Form_StaticText(
		'P2 reqid',
		$pconfig['reqid']
	));
}

$form->add($section);

$section = new Form_Section('Networks');

$group = new Form_Group('*Local Network');
$group->addClass('opt_localid');

$group->add(new Form_Select(
	'localid_type',
	null,
	$pconfig['localid_type'],
	get_specialnet('', $ipsec_lidtype_flags)
))->setHelp('Type');

$group->add(new Form_IpAddress(
	'localid_address',
	null,
	$pconfig['localid_address']
))->setHelp('Address')->addMask('localid_netbits', $pconfig['localid_netbits'], 128, 0);

$group->setHelp('%s', '<span id="opt_localid_help"></span>');
$section->add($group);

$group = new Form_Group('NAT/BINAT translation');
$group->addClass('opt_natid');

$group->add(new Form_Select(
	'natlocalid_type',
	null,
	$pconfig['natlocalid_type'],
	get_specialnet('', $ipsec_nlitype_flags)
))->setHelp('Type');

$group->add(new Form_IpAddress(
	'natlocalid_address',
	null,
	$pconfig['natlocalid_address']
))->setHelp('Address')->addMask('natlocalid_netbits', $pconfig['natlocalid_netbits'], 128, 0);

$group->setHelp('If NAT/BINAT is required on this network specify the address to be translated');
$section->add($group);

if (!isset($pconfig['mobile'])) {
	$group = new Form_Group('*Remote Network');
	$group->addClass('opt_remoteid');

	$group->add(new Form_Select(
		'remoteid_type',
		null,
		$pconfig['remoteid_type'],
		array('address' => gettext('Address'), 'network' => gettext('Network'))
	))->setHelp('Type');

	$group->add(new Form_IpAddress(
		'remoteid_address',
		null,
		$pconfig['remoteid_address']
	))->setHelp('Address')->addMask('remoteid_netbits', $pconfig['remoteid_netbits'], 128, 0);

	$group->setHelp('%s', '<span id="opt_remoteid_help"></span>');
	$section->add($group);
}

$form->add($section);

$section = new Form_Section('Phase 2 Proposal (SA/Key Exchange)');

$section->addInput(new Form_Select(
	'proto',
	'*Protocol',
	$pconfig['proto'],
	$p2_protos
))->setHelp('Encapsulating Security Payload (ESP) performs encryption and authentication, Authentication Header (AH) is authentication only.');

$i = 0;
$rows = count($p2_ealgos) - 1;

foreach ($p2_ealgos as $algo => $algodata) {
	$group = new Form_Group($i == 0 ? '*Encryption Algorithms':'');
	$group->addClass('encalg');

	// Note: ID attribute of each element created is to be unique.  Not being used, suppressing it.
	$group->add(new Form_Checkbox(
		'ealgos[]',
		null,
		$algodata['name'],
		(is_array($pconfig['ealgos']) && in_array($algo, $pconfig['ealgos'])),
		$algo
	))->addClass('multi ealgoschk')->setAttribute('id', $algodata['name']);

	if (is_array($algodata['keysel'])) {
		$list = array();
		$key_hi = $algodata['keysel']['hi'];
		$key_lo = $algodata['keysel']['lo'];
		$key_step = $algodata['keysel']['step'];
		for ($keylen = $key_hi; $keylen >= $key_lo; $keylen -= $key_step) {
			$list[$keylen] = $keylen . ' bits';
		}

		$group->add(new Form_Select(
			'keylen_' . $algo,
			null,
			$pconfig["keylen_".$algo],
			['auto' => gettext('Auto')] + $list
		));
	}

	$i++;
	$section->add($group);
}

$group = new Form_Group('*Hash Algorithms');

foreach ($p2_halgos as $algo => $algoname) {
	// Note: ID attribute of each element created is to be unique.  Not being used, suppressing it.
	$group->add(new Form_Checkbox(
		'halgos[]',
		null,
		$algoname,
		(empty($pconfig['halgos']) ? '' : in_array($algo, $pconfig['halgos'])),
		$algo
	))->addClass('multi')->setAttribute('id');

	$group->setHelp('Note: Hash is ignored with GCM algorithms. SHA1 provides weak security and should be avoided.');
}

$section->add($group);

$sm = (!isset($pconfig['mobile']) || empty(config_get_path('ipsec/client/pfs_group')));
$helpstr = $sm ? '':'Set globally in mobile client options. ';
$helpstr .= 'Note: Groups 1, 2, 5, 22, 23, and 24 provide weak security and should be avoided.';

$section->addInput(new Form_Select(
	'pfsgroup',
	'PFS key group',
	$pconfig['pfsgroup'],
	$sm ? $p2_pfskeygroups:array()
))->setHelp($helpstr);

$form->add($section);

$section = new Form_Section('Expiration and Replacement', 'ph2-lifetimes', $fs_section_state);

$section->addInput(new Form_Input(
	'lifetime',
	'Life Time',
	'number',
	$pconfig['lifetime'],
	["placeholder" => ipsec_get_life_time(ipsec_timer_entry($pconfig))]
))->setHelp('Hard Child SA life time, in seconds, after which the Child SA will be expired. ' .
		'Must be larger than Rekey Time. ' .
		'Cannot be set to the same value as Rekey Time. ' .
		'If left empty, defaults to 110% of Rekey Time. ' .
		'If both Life Time and Rekey Time are empty, defaults to 3960.');

$section->addInput(new Form_Input(
	'rekey_time',
	'Rekey Time',
	'number',
	$pconfig['rekey_time'],
	['min' => 0, "placeholder" => ipsec_get_rekey_time(ipsec_timer_entry($pconfig))]
))->setHelp('Time, in seconds, before a Child SA establishes new keys. This works without interruption. ' .
		'Cannot be set to the same value as Life Time. ' .
		'Leave blank to use a default value of 90% Life Time. ' .
		'If both Life Time and Rekey Time are empty, defaults to 3600. ' .
		'Enter a value of 0 to disable, but be aware that when rekey is disabled, ' .
		'connections can be interrupted while new Child SA entries are negotiated.');

$section->addInput(new Form_Input(
	'rand_time',
	'Rand Time',
	'number',
	$pconfig['rand_time'],
	['min' => 0, "placeholder" => ipsec_get_rand_time(ipsec_timer_entry($pconfig))]
))->setHelp('A random value up to this amount will be subtracted from Rekey Time to avoid simultaneous renegotiation. ' .
		'If left empty, defaults to 10% of Life Time. ' .
		'Enter 0 to disable randomness, but be aware that simultaneous renegotiation can lead to duplicate security associations.');

$form->add($section);

// Hidden inputs
if (isset($pconfig['mobile'])) {
	$form->addGlobal(new Form_Input(
		'mobile',
		null,
		'hidden',
		'true'
	));
} else {
	$section = new Form_Section('Keep Alive');

	$section->addInput(new Form_IpAddress(
		'pinghost',
		'Automatically ping host',
		$pconfig['pinghost']
	))->setHelp('Sends an ICMP echo request inside the tunnel to the specified IP Address. ' .
			'Can trigger initiation of a tunnel mode P2, but does not trigger initiation of a VTI mode P2. ');

	$section->addInput(new Form_Checkbox(
		'keepalive',
		'Keep Alive',
		'Enable periodic keep alive check',
		$pconfig['keepalive']
	))->setHelp('Periodically check this P2 and initiate it if disconnected; does not send traffic' .
	            ' inside the tunnel. This check ignores the P1 option "Child SA Start Action" and' .
				' works for both VTI and tunnel mode P2s. For IKEv2 without split connections, this' .
				' only needs to be enabled on one P2.');
	$form->add($section);
}

$form->addGlobal(new Form_Input(
	'ikeid',
	null,
	'hidden',
	$pconfig['ikeid']
));

if (!empty($pconfig['reqid'])) {
	$form->addGlobal(new Form_Input(
		'reqid',
		null,
		'hidden',
		$pconfig['reqid']
	));
}

$form->addGlobal(new Form_Input(
	'uniqid',
	null,
	'hidden',
	$pconfig['uniqid']
));

print($form);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$("form").submit(function() {
		disableInput('localid_type', false);
		disableInput('remoteid_type', false);
	});

	// ---------- On changing "Mode" ----------------------------------------------------------------------------------
	function change_mode() {

		value = $('#mode').val();

		disableInput('localid_type', false);
		disableInput('remoteid_type', false);
		if ((value == 'tunnel') || (value == 'tunnel6')) {
			hideClass('opt_localid', false);
			hideClass('opt_natid', false);
			$('#opt_localid_help').html("<?=$localid_help_mobile?>");

<?php	if (!isset($pconfig['mobile'])): ?>
			hideClass('opt_remoteid', false);
			hideClass('opt_natid', false);
			$('#opt_localid_help').html("<?=$localid_help_tunnel?>");
			$('#opt_remoteid_help').html("<?=$remoteid_help_tunnel?>");
<?php	endif; ?>
		} else if (value == 'vti') {
			hideClass('opt_localid', false);
			hideClass('opt_natid', true);
			hideClass('opt_remoteid', false);
			address_is_blank = !/\S/.test($('#localid_type').val());
			if (address_is_blank) {
				$('#localid_type').val('address');
				disableInput('localid_type', false);
				typesel_change_remote(0);
			}
			address_is_blank = !/\S/.test($('#remoteid_address').val());
			if (address_is_blank) {
				$('#remoteid_type').val('address');
				disableInput('remoteid_type', false);
				typesel_change_remote(0);
			}
			$('#opt_localid_help').html("<?=$localid_help_vti?>");
			$('#opt_remoteid_help').html("<?=$remoteid_help_vti?>");
		} else {
			hideClass('opt_localid', true);
			hideClass('opt_natid', true);
<?php	if (!isset($pconfig['mobile'])): ?>
			hideClass('opt_remoteid', true);
<?php	endif; ?>
		}
	}

	// ---------- On changing "NAT/BINAT" -----------------------------------------------------------------------------
	function typesel_change_natlocal(bits) {
		var value = $('#mode').val();

		if (typeof(bits) === "undefined") {
			if (value === "tunnel") {
				bits = 24;
			} else if (value === "tunnel6") {
				bits = 64;
			}
		}

		var address_is_blank = !/\S/.test($('#natlocalid_address').val());

		switch ($("#natlocalid_type option:selected").index()) {
			case 0: /* none */
				disableInput('natlocalid_address', true);

				if (address_is_blank) {
					$('#natlocalid_netbits').val(0);
				}

				disableInput('natlocalid_netbits', true);
				break;
			case 1: /* address */
				disableInput('natlocalid_address', false);

				if (address_is_blank) {
					$('#natlocalid_netbits').val(bits);
				}

				disableInput('natlocalid_netbits', true);
				break;
			case 2: /* network */
				disableInput('natlocalid_address', false);
				disableInput('natlocalid_netbits', false);
				break;
			default:
				$('#natlocalid_address').val("");
				disableInput('natlocalid_address', true);

				if (address_is_blank) {
					$('#natlocalid_netbits').val(0);
				}

				disableInput('natlocalid_netbits', true);
				break;
		}
	}

	// ---------- On changing "Local Network" -------------------------------------------------------------------------
	function typesel_change_local(bits) {
		var value = $('#mode').val();

		if (typeof(bits) === "undefined") {
			if (value === "tunnel") {
				bits = 24;
			} else if (value === "tunnel6") {
				bits = 64;
			}
		}

		var address_is_blank = !/\S/.test($('#localid_address').val());

		switch ($("#localid_type option:selected").index()) {
			case 0: /* single */
				disableInput('localid_address', false);

				if (address_is_blank) {
					$('#localid_netbits').val(0);
				}

				disableInput('localid_netbits', true);
				break;
			case 1: /* network */
				disableInput('localid_address', false);

				if (address_is_blank) {
					$('#localid_netbits').val(bits);
				}

				disableInput('localid_netbits', false);
				break;
			case 3: /* none */
				disableInput('localid_address', true);
				disableInput('localid_netbits', true);
				break;
			default:
				$('#localid_address').val("");
				disableInput('localid_address', true);

				if (address_is_blank) {
					$('#localid_netbits').val(0);
				}

				disableInput('localid_netbits', true);
				break;
		}
	}

<?php

	// ---------- On changing "Remote Network" ------------------------------------------------------------------------
	if (!isset($pconfig['mobile'])): ?>

		function typesel_change_remote(bits) {

			var value = $('#mode').val();

			if (typeof(bits) === "undefined") {
				if (value === "tunnel") {
					bits = 24;
				} else if (value === "tunnel6") {
					bits = 64;
				}
			}

			var address_is_blank = !/\S/.test($('#remoteid_address').val());

			switch ($("#remoteid_type option:selected").index()) {
				case 0: /* single */
					disableInput('remoteid_address', false);

					if (address_is_blank) {
						$('#remoteid_netbits').val(0);
					}

					disableInput('remoteid_netbits', true);
					break;
				case 1: /* network */
					disableInput('remoteid_address', false);

					if (address_is_blank) {
						$('#remoteid_netbits').val(bits);
					}

					disableInput('remoteid_netbits', false);
					break;
				case 3: /* none */
					disableInput('remoteid_address', true);
					disableInput('remoteid_netbits', true);
					break;
				default:
					$('#remoteid_address').val("");
					disableInput('remoteid_address', true);

					if (address_is_blank) {
						$('#remoteid_netbits').val(0);
					}

					disableInput('remoteid_netbits', true);
					break;
			}
		}

	<?php endif; ?>

	function change_protocol() {
		var hide = ($('#proto').val() != 'esp');
		hideClass('encalg', hide);
		$("input[name='halgos[]']").prop("disabled", !hide);
	}

	function change_aead() {
		var notaead = ['AES'];
		var arrayLength = notaead.length;
		for (var i = 0; i < arrayLength; i++) {
			if ($('#' + notaead[i]).prop('checked') || ($('#proto').val() != 'esp')) {
				$("input[name='halgos[]']").prop("disabled", false);
				return;
			} 
		}
		$("input[name='halgos[]']").prop("disabled", true);
	}

	// ---------- Monitor elements for change and call the appropriate display functions ----------

	 // Protocol
	$('#proto').change(function () {
		change_protocol();
	});

	// AEAD
	$(".ealgoschk").click(function () {
		change_aead();
	});

	 // Localid
	$('#localid_type').change(function () {
		typesel_change_local(<?=htmlspecialchars($pconfig['localid_netbits'])?>);
	});

	 // Remoteid
	$('#remoteid_type').change(function () {
		typesel_change_remote(<?=htmlspecialchars($pconfig['remoteid_netbits'])?>);
	});

	 // NATLocalid
	$('#natlocalid_type').change(function () {
		typesel_change_natlocal(<?=htmlspecialchars($pconfig['natlocalid_netbits'])?>);
	});

	 // Mode
	$('#mode').change(function () {
		change_mode();
	});

	// ---------- On initial page load ------------------------------------------------------------

	change_mode();
	change_protocol();
	change_aead();
	typesel_change_local(<?=htmlspecialchars($pconfig['localid_netbits'])?>);
	typesel_change_natlocal(<?=htmlspecialchars($pconfig['natlocalid_netbits'])?>);
<?php
	if (!isset($pconfig['mobile'])):
?>
		typesel_change_remote(<?=htmlspecialchars($pconfig['remoteid_netbits'])?>);
<?php
endif;
?>
});
//]]>
</script>
<?php
include("foot.inc");
