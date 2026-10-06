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

// the header summary shows the saved entry (or the defaults of a new one), not posted values
$p2_summary = $pconfig;
$p2_is_new = ($p2index === null);

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

$p2_heading = $p2_is_new ? gettext("Add phase 2") : gettext("Edit phase 2");
if (isset($pconfig['mobile'])) {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Mobile Clients"), $p2_heading);
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec_mobile.php", "@self");
	$editing_mobile = true;
} else {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Tunnels"), $p2_heading);
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec.php", "@self");
	$editing_mobile = false;
}
if (!$p2_is_new && (trim((string)$p2_summary['descr']) !== '')) {
	array_splice($pgtitle, 3, 0, array(htmlspecialchars($p2_summary['descr'])));
	array_splice($pglinks, 3, 0, array(""));
}
$shortcut_section = "ipsec";

include("head.inc");

// lifetimes and keep alive start closed; open after a failed save
$fs_section_state = COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED);

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('vpn-ipsec', 'vpn_ipsec.php');

/* ------------------------------------------------------------ header summary */

$p2_sum_facts = array();
$p2_sum_mode = (string)$p2_summary['mode'];
if ($p2_sum_mode === '') {
	$p2_sum_mode = 'tunnel';
}
$p2_sum_facts[] = array(gettext("Mode"), fs_h($p2_modes[$p2_sum_mode] ?? strtoupper($p2_sum_mode)));

if ($p2_sum_mode == 'transport') {
	$p2_sum_net = '<span class="fs-muted">' . fs_h(gettext("Host to host, no networks")) . '</span>';
} else {
	$p2_sum_ids = array();
	foreach (array('local', 'remote') as $p2_sum_side) {
		$p2_sum_id = array(
			'type' => (string)$p2_summary[$p2_sum_side . 'id_type'],
			'address' => (string)$p2_summary[$p2_sum_side . 'id_address'],
			'netbits' => (string)$p2_summary[$p2_sum_side . 'id_netbits'],
		);
		if ($p2_sum_id['type'] == 'mobile') {
			$p2_sum_ids[] = fs_h(gettext("Mobile clients"));
		} elseif (in_array($p2_sum_id['type'], array('address', 'network')) && ($p2_sum_id['address'] === '')) {
			$p2_sum_ids[] = '<span class="fs-muted">' . fs_h(gettext("Not set")) . '</span>';
		} elseif (in_array($p2_sum_id['type'], array('address', 'network'))) {
			$p2_sum_ids[] = '<span class="fs-mono">' . fs_h(ipsec_idinfo_to_text($p2_sum_id)) . '</span>';
		} else {
			$p2_sum_ids[] = fs_h(ipsec_idinfo_to_text($p2_sum_id));
		}
	}
	$p2_sum_net = $p2_sum_ids[0] . ' <i class="fa-solid fa-arrow-right-arrow-left fs-ipsec-sum-arrow" aria-hidden="true"></i><span class="visually-hidden">' .
	    fs_h(gettext("to")) . '</span> ' . $p2_sum_ids[1];
	if (!empty($p2_summary['natlocalid_type']) && ($p2_summary['natlocalid_type'] != 'none')) {
		$p2_sum_nat = array(
			'type' => (string)$p2_summary['natlocalid_type'],
			'address' => (string)$p2_summary['natlocalid_address'],
			'netbits' => (string)$p2_summary['natlocalid_netbits'],
		);
		$p2_sum_net .= '<span class="fs-ipsec-sum-note">' . fs_h(sprintf(gettext("Local translated to %s"), ipsec_idinfo_to_text($p2_sum_nat))) . '</span>';
	}
}
$p2_sum_facts[] = array(($p2_sum_mode == 'vti') ? gettext("Tunnel addresses") : gettext("Local and remote networks"), $p2_sum_net);

$p2_sum_proto = (string)$p2_summary['proto'];
$p2_sum_prop = array($p2_protos[$p2_sum_proto] ?? strtoupper($p2_sum_proto));
if ($p2_sum_proto == 'esp') {
	$p2_sum_algs = array();
	foreach ((is_array($p2_summary['ealgos']) ? $p2_summary['ealgos'] : array()) as $p2_sum_alg) {
		$p2_sum_txt = $p2_ealgos[$p2_sum_alg]['name'] ?? strtoupper($p2_sum_alg);
		$p2_sum_keylen = $p2_summary['keylen_' . $p2_sum_alg] ?? '';
		if (is_numericint($p2_sum_keylen)) {
			$p2_sum_txt .= ' ' . $p2_sum_keylen;
		}
		$p2_sum_algs[] = $p2_sum_txt;
	}
	$p2_sum_prop[] = empty($p2_sum_algs) ? gettext("no encryption selected") : implode(', ', $p2_sum_algs);
}
$p2_sum_hashes = array();
foreach ((is_array($p2_summary['halgos']) ? $p2_summary['halgos'] : array()) as $p2_sum_hash) {
	$p2_sum_hashes[] = $p2_halgos[$p2_sum_hash] ?? strtoupper($p2_sum_hash);
}
if (!empty($p2_sum_hashes)) {
	$p2_sum_prop[] = implode(', ', $p2_sum_hashes);
}
if (isset($p2_summary['mobile']) && !empty(config_get_path('ipsec/client/pfs_group'))) {
	$p2_sum_prop[] = gettext("PFS from mobile client settings");
} elseif (empty($p2_summary['pfsgroup'])) {
	$p2_sum_prop[] = gettext("PFS off");
} else {
	$p2_sum_prop[] = sprintf(gettext("PFS %s"), $p2_summary['pfsgroup']);
}
$p2_sum_facts[] = array(gettext("Proposal"), fs_h(implode(' · ', $p2_sum_prop)));

$p2_sum_p1 = !empty($p2_summary['ikeid']) ? ipsec_get_phase1($p2_summary['ikeid']) : null;
if ($p2_sum_p1) {
	$p2_sum_p1name = trim((string)$p2_sum_p1['descr']);
	$p2_sum_facts[] = array(gettext("Phase 1"),
	    '<a href="vpn_ipsec_phase1.php?ikeid=' . fs_h(urlencode((string)$p2_sum_p1['ikeid'])) . '" title="' . fs_h(gettext("Edit Phase 1 Entry")) . '">' .
	    (($p2_sum_p1name === '') ? '<i>' . fs_h(gettext("No description")) . '</i>' : fs_h($p2_sum_p1name)) . '</a>' .
	    '<span class="fs-ipsec-sum-note">' . fs_h(sprintf(gettext("IKE ID %s"), $p2_summary['ikeid']) .
	    (isset($p2_summary['mobile']) ? ' · ' . gettext("Mobile clients") : '') .
	    (isset($p2_sum_p1['remote-gateway']) ? ' · ' . $p2_sum_p1['remote-gateway'] : '')) . '</span>');
}

$p2_sum_sub = array(gettext("Phase 2"));
if (!empty($p2_summary['reqid'])) {
	$p2_sum_sub[] = sprintf(gettext("reqid %s"), $p2_summary['reqid']);
}
if ($p2_is_new) {
	$p2_sum_sub[] = !empty($_REQUEST['dup']) ? gettext("Copy, not saved yet") : gettext("Defaults, not saved yet");
}

$p2_sum_title = trim((string)$p2_summary['descr']);
if ($p2_is_new) {
	$p2_sum_badge = fs_badge('info', !empty($_REQUEST['dup']) ? gettext("Copy") : gettext("New"));
} else {
	$p2_sum_badge = $p2_summary['disabled'] ? fs_badge('disabled') : fs_badge('enabled');
}
?>
<style>
.fs-ipsec-sum { padding: var(--fs-sp-4); }
.fs-ipsec-sum-head { display: flex; align-items: center; gap: var(--fs-sp-3); min-width: 0; }
.fs-ipsec-sum-icon { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: var(--fs-r-md); background: var(--fs-accent-tint); color: var(--fs-coral-text); font-size: 1.1rem; }
.fs-ipsec-sum-name { flex: 1 1 auto; min-width: 0; }
.fs-ipsec-sum-title { overflow-wrap: anywhere; color: var(--fs-text-strong); font-size: var(--fs-fs-lg); font-weight: 600; line-height: 1.3; }
.fs-ipsec-sum-sub { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-ipsec-sum-head > .fs-badge { flex: 0 0 auto; }
.fs-ipsec-sum-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: var(--fs-sp-3) var(--fs-sp-4); margin: var(--fs-sp-4) 0 0; padding-top: var(--fs-sp-3); border-top: 1px solid var(--fs-border); }
.fs-ipsec-sum-facts > div { min-width: 0; }
.fs-ipsec-sum-facts dt { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 500; text-transform: uppercase; letter-spacing: .03em; }
.fs-ipsec-sum-facts dd { margin: .1rem 0 0; overflow-wrap: anywhere; color: var(--fs-text); font-weight: 500; }
.fs-ipsec-sum-note { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 400; }
.fs-ipsec-sum-arrow { margin: 0 .3rem; color: var(--fs-text-muted); font-size: .85em; }
@media (max-width: 575.98px) { .fs-ipsec-sum-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<div class="panel panel-default fs-ipsec-sum" aria-label="<?=fs_h(gettext("Phase 2 summary"))?>" role="region">
	<div class="fs-ipsec-sum-head">
		<span class="fs-ipsec-sum-icon"><i class="fa-solid fa-route" aria-hidden="true"></i></span>
		<div class="fs-ipsec-sum-name">
			<div class="fs-ipsec-sum-title"><?php if ($p2_sum_title === ''): ?><span class="fs-muted"><?=htmlspecialchars($p2_is_new ? gettext("New phase 2") : gettext("No description"))?></span><?php else: ?><?=htmlspecialchars($p2_sum_title)?><?php endif; ?></div>
			<div class="fs-ipsec-sum-sub"><?=fs_h(implode(' · ', $p2_sum_sub))?></div>
		</div>
		<?=$p2_sum_badge?>
	</div>
	<dl class="fs-ipsec-sum-facts">
<?php foreach ($p2_sum_facts as $p2_sum_fact): ?>
		<div><dt><?=fs_h($p2_sum_fact[0])?></dt><dd><?=$p2_sum_fact[1]?></dd></div>
<?php endforeach; ?>
	</dl>
</div>
<?php

/* ------------------------------------------------------------------- form */

$form = new Form();

$section = new Form_Section('General');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A name for administrative reference (not parsed).');

$section->addInput(new Form_Checkbox(
	'disabled',
	'Disabled',
	'Disable this phase 2 entry without removing it from the list',
	$pconfig['disabled']
));

$section->addInput(new Form_Select(
	'mode',
	'*Mode',
	$pconfig['mode'],
	$p2_modes
))->setHelp('Tunnel modes carry traffic between networks, Routed (VTI) creates an interface for routing, Transport protects traffic between the two endpoints only.');

$form->add($section);

$section = new Form_Section('Networks');

$group = new Form_Group('*Local network');
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
	$group = new Form_Group('*Remote network');
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

$section = new Form_Section('Proposal');

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

$group = new Form_Group('*Hash algorithms');

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

// Hidden inputs
if (isset($pconfig['mobile'])) {
	$form->addGlobal(new Form_Input(
		'mobile',
		null,
		'hidden',
		'true'
	));
} else {
	$section = new Form_Section('Keep alive', 'ph2-keepalive',
	    COLLAPSIBLE | ((!empty($input_errors) || !empty($pconfig['pinghost']) || !empty($pconfig['keepalive'])) ? SEC_OPEN : SEC_CLOSED));

	$section->addInput(new Form_IpAddress(
		'pinghost',
		'Automatically ping host',
		$pconfig['pinghost']
	))->setHelp('Sends an ICMP echo request inside the tunnel to the specified IP Address. ' .
			'Can trigger initiation of a tunnel mode P2, but does not trigger initiation of a VTI mode P2. ');

	$section->addInput(new Form_Checkbox(
		'keepalive',
		'Keep alive',
		'Enable periodic keep alive check',
		$pconfig['keepalive']
	))->setHelp('Periodically check this P2 and initiate it if disconnected; does not send traffic' .
	            ' inside the tunnel. This check ignores the P1 option "Child SA Start Action" and' .
				' works for both VTI and tunnel mode P2s. For IKEv2 without split connections, this' .
				' only needs to be enabled on one P2.');
	$form->add($section);
}

$section = new Form_Section('Expiration and replacement', 'ph2-lifetimes', $fs_section_state);

$section->addInput(new Form_Input(
	'lifetime',
	'Life time',
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
	'Rekey time',
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
	'Rand time',
	'number',
	$pconfig['rand_time'],
	['min' => 0, "placeholder" => ipsec_get_rand_time(ipsec_timer_entry($pconfig))]
))->setHelp('A random value up to this amount will be subtracted from Rekey Time to avoid simultaneous renegotiation. ' .
		'If left empty, defaults to 10% of Life Time. ' .
		'Enter 0 to disable randomness, but be aware that simultaneous renegotiation can lead to duplicate security associations.');

$form->add($section);

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

fs_form_cancel($form, 'vpn_ipsec.php');

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
