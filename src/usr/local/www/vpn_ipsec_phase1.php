<?php

/*
 * vpn_ipsec_phase1.php
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
##|*IDENT=page-vpn-ipsec-editphase1
##|*NAME=VPN: IPsec: Edit Phase 1
##|*DESCR=Allow access to the 'VPN: IPsec: Edit Phase 1' page.
##|*MATCH=vpn_ipsec_phase1.php*
##|-PRIV

require_once("functions.inc");
require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("filter.inc");
require_once("vpn_ipsec.inc");

if ($_POST['generatekey']) {
	$keyoutput = "";
	$keystatus = "";
	exec("/bin/dd status=none if=/dev/random bs=4096 count=1 | /usr/bin/openssl sha224 | /usr/bin/cut -f2 -d' '", $keyoutput, $keystatus);
	print json_encode(['pskey' => $keyoutput[0]]);
	exit;
}

if (is_numericint($_REQUEST['p1index'])) {
	$p1index = $_REQUEST['p1index'];
}

if (is_numericint($_REQUEST['dup'])) {
	$p1index = $_REQUEST['dup'];
}

$p1 = null;
if (!empty($_REQUEST['ikeid'])) {
	$p1index = 0;
	foreach(config_get_path('ipsec/phase1', []) as $phase1) {
		if ($phase1['ikeid'] == $_REQUEST['ikeid']) {
			$p1 = $phase1;
			break;
		}
		$p1index++;
	}
} elseif (isset($p1index) && config_get_path('ipsec/phase1/' . $p1index)) {
	$p1 = config_get_path('ipsec/phase1/' . $p1index);
}

if ($p1) {
	$old_ph1ent = $p1;
}

$pconfig = ipsec_p1_form($p1, (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])), !empty($_REQUEST['mobile']));

if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	unset($p1index);
}

// the header summary shows the saved entry (or the defaults of a new one), not posted values
$p1_summary = $pconfig;
$p1_is_new = (!$p1 || isset($_REQUEST['dup']));

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = ipsec_p1_save($pconfig, ($p1 && !isset($_REQUEST['dup'])) ? $p1index : null, $old_ph1ent ?? null);
	if (!$input_errors) {
		header("Location: vpn_ipsec.php");
		exit;
	}
}

$p1_heading = $p1_is_new ? gettext("Add phase 1") : gettext("Edit phase 1");
if (isset($pconfig['mobile'])) {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Mobile Clients"), $p1_heading);
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec_mobile.php", "@self");
} elseif (!$p1_is_new && (trim((string)$p1_summary['descr']) !== '')) {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Tunnels"), htmlspecialchars($p1_summary['descr']), $p1_heading);
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec.php", "", "@self");
} else {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Tunnels"), $p1_heading);
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec.php", "@self");
}

$shortcut_section = "ipsec";

include("head.inc");

// lifetimes and advanced options start closed; open after a failed save
$fs_section_state = COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED);

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('vpn-ipsec', 'vpn_ipsec.php');

/* ------------------------------------------------------------ header summary */

$fs_ipsec_pick = function ($value, array $options) {
	$picked = null;
	foreach (array_keys($options) as $key) {
		$cmp = ((gettype($key) == "integer") && (gettype($value) == "string")) ? strval($key) : $key;
		if ($value == $cmp) {
			$picked = $key;
		}
	}
	return ($picked === null) ? array_key_first($options) : $picked;
};

/* every select-backed fact uses the option list of the field below it */
$p1_iflist = ipsec_p1_interface_list();
$p1_protocols = array("inet" => gettext("IPv4"), "inet6" => gettext("IPv6"), "both" => gettext("Dual stack"));
$p1_sum_facts = array();

$p1_sum_ike = array("ikev1" => "IKEv1", "ikev2" => "IKEv2", "auto" => gettext("Auto (IKEv1 or IKEv2)"))[$fs_ipsec_pick($p1_summary['iketype'], array("ikev1" => 1, "ikev2" => 1, "auto" => 1))];
if ($fs_ipsec_pick($p1_summary['iketype'], array("ikev1" => 1, "ikev2" => 1, "auto" => 1)) != 'ikev2') {
	$p1_sum_ike .= ' · ' . (($fs_ipsec_pick($p1_summary['mode'], array("main" => 1, "aggressive" => 1)) == 'aggressive') ? gettext("Aggressive mode") : gettext("Main mode"));
}
$p1_sum_facts[] = array(gettext("Key exchange"), fs_h($p1_sum_ike));

$p1_sum_if = (empty($p1_iflist) ? '' : $p1_iflist[$fs_ipsec_pick($p1_summary['interface'], $p1_iflist)] . ' · ') .
    $p1_protocols[$fs_ipsec_pick($p1_summary['protocol'], $p1_protocols)];
if (isset($p1_summary['mobile'])) {
	$p1_sum_facts[] = array(gettext("Remote gateway"), fs_h(gettext("Mobile clients")) .
	    '<span class="fs-ipsec-sum-note">' . fs_h($p1_sum_if) . '</span>');
} else {
	$p1_sum_gw = trim((string)$p1_summary['remotegw']);
	if (!empty($p1_summary['ikeport'])) {
		$p1_sum_if .= ' · ' . sprintf(gettext("port %s"), $p1_summary['ikeport']);
	}
	$p1_sum_facts[] = array(gettext("Remote gateway"),
	    (($p1_sum_gw === '') ? '<span class="fs-muted">' . fs_h(gettext("Not set")) . '</span>' : '<span class="fs-mono">' . fs_h($p1_sum_gw) . '</span>') .
	    '<span class="fs-ipsec-sum-note">' . fs_h($p1_sum_if) . '</span>');
}

$p1_sum_authlist = ipsec_p1_auth_method_list(isset($p1_summary['mobile']));
$p1_sum_auth = empty($p1_sum_authlist) ? '' : $p1_authentication_methods[$fs_ipsec_pick($p1_summary['authentication_method'], $p1_sum_authlist)]['name'];
$p1_sum_facts[] = array(gettext("Authentication"), ($p1_sum_auth === '') ? '<span class="fs-muted">' . fs_h(gettext("Not set")) . '</span>' : fs_h($p1_sum_auth));

$p1_sum_props = array();
foreach (array_get_path($p1_summary, 'encryption/item', []) as $p1_sum_item) {
	$p1_sum_alg = $fs_ipsec_pick(array_get_path($p1_sum_item, 'encryption-algorithm/name', []), $p1_ealgos);
	$p1_sum_txt = $p1_ealgos[$p1_sum_alg]['name'];
	/* the page script selects the saved key length when it is one of the algorithm's lengths */
	$p1_sum_keylen = array_get_path($p1_sum_item, 'encryption-algorithm/keylen');
	$p1_sum_keysel = $p1_ealgos[$p1_sum_alg]['keysel'] ?? null;
	if (is_array($p1_sum_keysel) && is_numericint($p1_sum_keylen) && ($p1_sum_keylen >= $p1_sum_keysel['lo']) &&
	    ($p1_sum_keylen <= $p1_sum_keysel['hi']) && ((($p1_sum_keysel['hi'] - $p1_sum_keylen) % $p1_sum_keysel['step']) == 0)) {
		$p1_sum_txt .= ' ' . $p1_sum_keylen;
	}
	$p1_sum_txt .= ' · ' . $p1_halgos[$fs_ipsec_pick(array_get_path($p1_sum_item, 'hash-algorithm'), $p1_halgos)];
	$p1_sum_txt .= ' · ' . sprintf(gettext("DH %s"), $fs_ipsec_pick(array_get_path($p1_sum_item, 'dhgroup'), $p1_dhgroups));
	$p1_sum_props[] = $p1_sum_txt;
}
$p1_sum_prop = empty($p1_sum_props) ? '<span class="fs-muted">' . fs_h(gettext("Not set")) . '</span>' : fs_h($p1_sum_props[0]);
if (count($p1_sum_props) > 1) {
	$p1_sum_prop .= '<span class="fs-ipsec-sum-note" title="' . fs_h(implode("\n", array_slice($p1_sum_props, 1))) . '">' .
	    fs_h(sprintf(gettext("+%d more"), count($p1_sum_props) - 1)) . '</span>';
}
$p1_sum_facts[] = array(gettext("Proposal"), $p1_sum_prop);

$p1_sum_sub = array(gettext("Phase 1"));
if (!$p1_is_new) {
	$p1_sum_sub[] = sprintf(gettext("IKE ID %s"), $p1_summary['ikeid']);
	$p1_sum_p2 = 0;
	foreach (config_get_path('ipsec/phase2', []) as $p1_sum_ph2) {
		if ($p1_sum_ph2['ikeid'] == $p1_summary['ikeid']) {
			$p1_sum_p2++;
		}
	}
	$p1_sum_sub[] = sprintf(ngettext("%d phase 2 entry", "%d phase 2 entries", $p1_sum_p2), $p1_sum_p2);
} elseif (isset($_REQUEST['dup'])) {
	$p1_sum_sub[] = gettext("Copy, not saved yet");
} else {
	$p1_sum_sub[] = gettext("Defaults, not saved yet");
}
if (isset($p1_summary['mobile'])) {
	$p1_sum_sub[] = gettext("Mobile clients");
}

$p1_sum_title = trim((string)$p1_summary['descr']);
if ($p1_is_new) {
	$p1_sum_badge = fs_badge('info', isset($_REQUEST['dup']) ? gettext("Copy") : gettext("New"));
} else {
	$p1_sum_badge = $p1_summary['disabled'] ? fs_badge('disabled') : fs_badge('enabled');
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
@media (max-width: 575.98px) { .fs-ipsec-sum-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<div class="panel panel-default fs-ipsec-sum" aria-label="<?=fs_h(gettext("Phase 1 summary"))?>" role="region">
	<div class="fs-ipsec-sum-head">
		<span class="fs-ipsec-sum-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
		<div class="fs-ipsec-sum-name">
			<div class="fs-ipsec-sum-title"><?php if ($p1_sum_title === ''): ?><span class="fs-muted"><?=htmlspecialchars($p1_is_new ? gettext("New phase 1") : gettext("No description"))?></span><?php else: ?><?=htmlspecialchars($p1_sum_title)?><?php endif; ?></div>
			<div class="fs-ipsec-sum-sub"><?=fs_h(implode(' · ', $p1_sum_sub))?></div>
		</div>
		<?=$p1_sum_badge?>
	</div>
	<dl class="fs-ipsec-sum-facts">
<?php foreach ($p1_sum_facts as $p1_sum_fact): ?>
		<div><dt><?=fs_h($p1_sum_fact[0])?></dt><dd><?=$p1_sum_fact[1]?></dd></div>
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
	'Disable this phase 1 without removing it from the list',
	$pconfig['disabled']
));

if (!empty($pconfig['ikeid'])) {
	$section->addInput(new Form_StaticText(
		'IKE ID',
		$pconfig['ikeid']
	));
}

$form->add($section);

$section = new Form_Section('Connection');

$section->addInput(new Form_Select(
	'iketype',
	'*Key exchange version',
	$pconfig['iketype'],
	array("ikev1" => "IKEv1", "ikev2" => "IKEv2", "auto" => gettext("Auto"))
))->setHelp('Auto uses IKEv2 when initiating and accepts IKEv1 or IKEv2 as responder.');

$section->addInput(new Form_Select(
	'mode',
	'*Negotiation mode',
	$pconfig['mode'],
	array("main" => gettext("Main"), "aggressive" => gettext("Aggressive"))
))->setHelp('IKEv1 only. Aggressive is more flexible, but less secure.');

$section->addInput(new Form_Select(
	'protocol',
	'*Internet protocol',
	$pconfig['protocol'],
	array("inet" => "IPv4", "inet6" => "IPv6", "both" => "Both (Dual Stack)")
))->setHelp('Address family of the tunnel endpoints.');

$section->addInput(new Form_Select(
	'interface',
	'*Interface',
	$pconfig['interface'],
	$p1_iflist
))->setHelp('Interface for the local endpoint of this phase 1.');

if (!isset($pconfig['mobile'])) {
	$group = new Form_Group('*Remote gateway');

	$group->add(new Form_Input(
		'remotegw',
		'Remote gateway',
		'text',
		$pconfig['remotegw']
	))->setHelp('Public IP address or host name of the remote gateway.%1$s%2$s%3$s',
	    '<div class="infoblock">',
	    sprint_info_box(gettext('Use \'0.0.0.0\' to allow connections from any IPv4 address or \'::\' ' .
	    'to allow connections from any IPv6 address. For dual stack tunnels, either form will allow connections from ' .
	    'both address families.' .
	    '<br/><br/>' .
	    'Child SA Start Action must be set to None and Peer IP Address cannot be used for Remote Identifier. ' .
	    '<br/><br/>' .
	    'A remote gateway address of \'0.0.0.0\' or \'::\' is not compatible with VTI, use an FQDN instead.'),
	    'info', false),
	    '</div>');

	$section->add($group);
}

$form->add($section);

$section = new Form_Section('Authentication');

$section->addInput(new Form_Select(
	'authentication_method',
	'*Authentication method',
	$pconfig['authentication_method'],
	ipsec_p1_auth_method_list(isset($pconfig['mobile']))
))->setHelp('Must match the setting chosen on the remote side.');

$section->addInput(new Form_Input(
	'pskey',
	'*Pre-shared key',
	'text',
	$pconfig['pskey']
))->setHelp('Must match on both peers. Use a long, random key: a weak key can lead to a tunnel compromise.%1$s', '<br/>');

$section->addInput(new Form_Select(
	'certref',
	'*My certificate',
	$pconfig['certref'],
	cert_build_list('cert', 'IPsec')
))->setHelp('Certificate which identifies this firewall. It must have at least one non-wildcard SAN.');

$section->addInput(new Form_Select(
	'pkcs11certref',
	'*PKCS#11 certificate',
	$pconfig['pkcs11certref'],
	ipsec_p1_pkcs11cert_list()
))->setHelp('Certificate from an attached PKCS#11 token device.');

$section->addInput(new Form_Input(
	'pkcs11pin',
	'*PKCS#11 PIN',
	'text',
	$pconfig['pkcs11pin']
))->setHelp('PIN of the PKCS#11 token.');

$section->addInput(new Form_Select(
	'caref',
	'*Peer certificate authority',
	$pconfig['caref'],
	cert_build_list('ca', 'IPsec')
))->setHelp('Certificate authority that validates the peer certificate.');

$group = new Form_Group('*My identifier');

$group->add(new Form_Select(
	'myid_type',
	null,
	$pconfig['myid_type'],
	ipsec_p1_myid_list()
));

$group->add(new Form_Input(
	'myid_data',
	null,
	'text',
	$pconfig['myid_data']
));

$section->add($group);

$group = new Form_Group('*Peer identifier');
$group->addClass('peeridgroup');

$group->add(new Form_Select(
	'peerid_type',
	null,
	$pconfig['peerid_type'],
	ipsec_p1_peerid_list()
));

$group->add(new Form_Input(
	'peerid_data',
	null,
	'text',
	$pconfig['peerid_data']
));

if (isset($pconfig['mobile'])) {
	$group->setHelp('This is known as the "group" setting on some VPN client implementations');
}

$section->add($group);

$form->add($section);

$eitems = array_get_path($pconfig, 'encryption/item', []);
$rowcount = count($eitems);
$section = new Form_Section('Proposal');
foreach($eitems as $key => $p1enc) {
	$lastrow = ($counter == $rowcount - 1);
	$group = new Form_Group($counter == 0 ? '*Encryption Algorithm' : '');
	$group->addClass("repeatable");

	$group->add(new Form_Select(
		'ealgo_algo'.$key,
		null,
		array_get_path($p1enc, 'encryption-algorithm/name', []),
		ipsec_p1_eal_list()
	))->setHelp($lastrow ? 'Algorithm' : '')->setWidth(2);

	$group->add(new Form_Select(
		'ealgo_keylen'.$key,
		null,
		array_get_path($p1enc, 'encryption-algorithm/keylen', []),
		array()
	))->setHelp($lastrow ? 'Key length' : '')->setWidth(2);

	$group->add(new Form_Select(
		'halgo'.$key,
		'*Hash algorithm',
		array_get_path($p1enc, 'hash-algorithm'),
		$p1_halgos
	))->setHelp($lastrow ? 'Hash' : '')->setWidth(2);

	$group->add(new Form_Select(
		'dhgroup'.$key,
		'*DH group',
		array_get_path($p1enc, 'dhgroup'),
		$p1_dhgroups
	))->setHelp($lastrow ? 'DH Group' : '')->setWidth(2);

	$group->add(new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning')->setWidth(2);

	$group->add(new Form_StaticText(
		null,
		null
	))->setWidth(6);

	$group->add(new Form_Select(
		'prfalgo'.$key,
		'*PRF algorithm',
		array_get_path($p1enc, 'prf-algorithm'),
		$p1_halgos
	))->setHelp($lastrow ? 'PRF' : '')->setWidth(2);

	$section->add($group);
	$counter += 1;
}
$section->addInput(new Form_StaticText('', ''))->setHelp('Note: SHA1 and DH groups 1, 2, 5, 22, 23, and 24 provide weak security and should be avoided.');

$btnaddopt = new Form_Button(
	'algoaddrow',
	'Add algorithm',
	null,
	'fa-solid fa-plus'
);
$btnaddopt->removeClass('btn-primary')->addClass('btn-success btn-sm');
$section->addInput($btnaddopt);

$section->addInput(new Form_Checkbox(
	'prfselect_enable',
	'PRF selection',
	'Enable manual Pseudo-Random Function (PRF) selection',
	$pconfig['prfselect_enable']
))->setHelp('IKEv2 only. Rarely needed, but useful with AEAD algorithms such as AES-GCM.');

$form->add($section);

$section = new Form_Section('Expiration and replacement', 'ph1-lifetimes', $fs_section_state);

$section->addInput(new Form_Input(
	'lifetime',
	'Life time',
	'number',
	$pconfig['lifetime'],
	["placeholder" => ipsec_get_life_time(ipsec_timer_entry($pconfig))]
))->setHelp('Hard IKE SA life time, in seconds, after which the IKE SA will be expired. ' .
		'Must be larger than Rekey Time and Reauth Time. ' .
		'Cannot be set to the same value as Rekey Time or Reauth Time. ' .
		'If left empty, defaults to 110% of whichever timer is higher (reauth or rekey)');

$section->addInput(new Form_Input(
	'rekey_time',
	'Rekey time',
	'number',
	$pconfig['rekey_time'],
	['min' => 0, "placeholder" => ipsec_get_rekey_time(ipsec_timer_entry($pconfig))]
))->setHelp('Time, in seconds, before an IKE SA establishes new keys. This works without interruption. ' .
		'Cannot be set to the same value as Life Time. ' .
		'Only supported by IKEv2, and is recommended for use with IKEv2. ' .
		'Leave blank to use a default value of 90% Life Time when using IKEv2. ' .
		'Enter a value of 0 to disable.');

$section->addInput(new Form_Input(
	'reauth_time',
	'Reauth time',
	'number',
	$pconfig['reauth_time'],
	['min' => 0, "placeholder" => ipsec_get_reauth_time(ipsec_timer_entry($pconfig))]
))->setHelp('Time, in seconds, before an IKE SA is torn down and recreated from scratch, including authentication. ' .
		'This can be disruptive unless both sides support make-before-break and overlapping IKE SA entries. ' .
		'Cannot be set to the same value as Life Time. ' .
		'Supported by IKEv1 and IKEv2. ' .
		'Leave blank to use a default value of 90% Life Time when using IKEv1. ' .
		'Enter a value of 0 to disable.');

$section->addInput(new Form_Input(
	'rand_time',
	'Rand time',
	'number',
	$pconfig['rand_time'],
	['min' => 0, "placeholder" => ipsec_get_rand_time(ipsec_timer_entry($pconfig))]
))->setHelp('A random value up to this amount will be subtracted from Rekey Time/Reauth Time to avoid simultaneous renegotiation. ' .
		'If left empty, defaults to 10% of Life Time. ' .
		'Enter 0 to disable randomness, but be aware that simultaneous renegotiation can lead to duplicate security associations.');

$form->add($section);

$section = new Form_Section('Advanced options', 'ph1-advanced', $fs_section_state);

if (!isset($pconfig['mobile'])) {
	$section->addInput(new Form_Select(
		'startaction',
		'Child SA start action',
		$pconfig['startaction'],
		$ipsec_startactions
	))->setHelp('Force specific initiator/responder behavior for child SA (phase 2) entries.');
}

$section->addInput(new Form_Select(
	'closeaction',
	'Child SA close action',
	$pconfig['closeaction'],
	$ipsec_closeactions
))->setHelp('What to do when the remote peer unexpectedly closes a child SA (phase 2).');

$section->addInput(new Form_Checkbox(
	'dpd_enable',
	'Dead peer detection',
	'Enable DPD',
	$pconfig['dpd_enable']
))->setHelp('Check the liveness of a peer by using IKEv2 INFORMATIONAL exchanges or IKEv1 R_U_THERE messages. ' .
	    'Active DPD checking is only enforced if no IKE or ESP/AH packet has been received for the configured DPD delay.');

$section->addInput(new Form_Input(
	'dpd_delay',
	'Delay',
	'number',
	$pconfig['dpd_delay']
))->setHelp('Delay between sending peer acknowledgement messages. In IKEv2, a value of 0 sends no additional ' .
	    'messages and only standard messages (such as those to rekey) are used to detect dead peers.');

$section->addInput(new Form_Input(
	'dpd_maxfail',
	'Max failures',
	'number',
	$pconfig['dpd_maxfail']
))->setHelp('Number of consecutive failures allowed before disconnecting. This only applies to IKEv1; in IKEv2 ' .
	    'the %1$sretransmission timeout%2$s is used instead.', '<a href="/vpn_ipsec_settings.php">', '</a>');

$section->addInput(new Form_Select(
	'nat_traversal',
	'NAT traversal',
	$pconfig['nat_traversal'],
	array('on' => gettext('Auto'), 'force' => gettext('Force'))
))->setHelp('Encapsulate ESP in UDP packets (NAT-T) when needed; can help clients behind restrictive firewalls.');

$section->addInput(new Form_Select(
	'mobike',
	'MOBIKE',
	$pconfig['mobike'],
	array('on' => gettext('Enable'), 'off' => gettext('Disable'))
))->setHelp('IKEv2 mobility and multihoming (MOBIKE).');

$group = new Form_Group('Custom IKE/NAT-T ports');

$group->add(new Form_Input(
    'ikeport',
    'Remote IKE port',
    'number',
    $pconfig['ikeport'],
    ['min' => 1, 'max' => 65535]
))->setHelp('UDP port for IKE on the remote gateway. Leave empty for default automatic behavior (500/4500).');
$group->add(new Form_Input(
    'nattport',
    'Remote NAT-T port',
    'number',
    $pconfig['nattport'],
    ['min' => 1, 'max' => 65535]
))->setHelp('UDP port for NAT-T on the remote gateway.%1$s%2$s%3$s',
    '<div class="infoblock">',
    sprint_info_box(gettext('If the IKE port is empty and NAT-T contains a value, the tunnel will use only NAT-T.'),
    'info', false),
    '</div>');

$section->add($group);

if (!isset($pconfig['mobile'])) {
	$section->addInput(new Form_Checkbox(
		'gw_duplicates',
		'Gateway duplicates',
		sprintf(gettext('Enable this to allow multiple phase 1 configurations with the same endpoint. ' .
		    'When enabled, %s does not manage routing to the remote gateway and traffic will follow the default route ' .
		    'without regard for the chosen interface. Static routes can override this behavior.'), g_get('product_label')),
		$pconfig['gw_duplicates']
	));
}

$section->addInput(new Form_Checkbox(
	'splitconn',
	'Split connections',
	'Enable this to split connection entries with multiple phase 2 configurations. Required for remote endpoints that support only a single traffic selector per child SA.',
	$pconfig['splitconn']
));

/* FreeBSD doesn't yet have TFC support. this is ready to go once it does
upstream issue 4688

$section->addInput(new Form_Checkbox(
	'tfc_enable',
	'Traffic Flow Confidentiality',
	'Enable TFC',
	$pconfig['tfc_enable']
))->setHelp('Enable Traffic Flow Confidentiality');

$section->addInput(new Form_Input(
	'tfc_bytes',
	'TFC Bytes',
	'Bytes TFC',
	$pconfig['tfc_bytes']
))->setHelp('Enter the number of bytes to pad ESP data to, or leave blank to fill to MTU size');

*/

if ((!empty($_REQUEST['ikeid']) &&
    $p1['ikeid']) &&
    (!isset($_REQUEST['dup']))) {
	$form->addGlobal(new Form_Input(
		'ikeid',
		null,
		'hidden',
		$p1['ikeid']
	));
} elseif (isset($p1index) && config_get_path('ipsec/phase1/' . $p1index)) {
	$form->addGlobal(new Form_Input(
		'p1index',
		null,
		'hidden',
		$p1index
	));
}

if (isset($pconfig['mobile'])) {
	$form->addGlobal(new Form_Input(
		'mobile',
		null,
		'hidden',
		'true'
	));
}

$form->addGlobal(new Form_Input(
	'ikeid',
	null,
	'hidden',
	$pconfig['ikeid']
));

$form->add($section);

fs_form_cancel($form, 'vpn_ipsec.php');

print($form);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	$('[id^=algoaddrow]').prop('type','button');

	$('[id^=algoaddrow]').click(function() {
		add_row();

		var lastRepeatableGroup = $('.repeatable:last');
		$(lastRepeatableGroup).find('[id^=ealgo_algo]select').change(function () {
			id = getStringInt(this.id);
			ealgosel_change(id, '');
		});
		$(lastRepeatableGroup).find('[id^=ealgo_algo]select').change();
	});

	function myidsel_change() {
		hideGroupInput('myid_data', ($('#myid_type').val() == 'myaddress'));
	}

	function iketype_change() {

		if ($('#iketype').val() == 'ikev2') {
			hideInput('mode', true);
			hideInput('mobike', false);
			//hideCheckbox('tfc_enable', false);
			hideInput('rekey_time', false);
			hideCheckbox('splitconn', false);
			hideCheckbox('prfselect_enable', false);
		} else {
			hideInput('mode', false);
			hideInput('mobike', true);
			//hideCheckbox('tfc_enable', true);
			//hideInput('tfc_bytes', true);
			hideInput('rekey_time', !($('#iketype').val() == 'auto'));
			hideCheckbox('splitconn', true);
			hideCheckbox('prfselect_enable', true);
		}
	}

	function peeridsel_change() {
		hideGroupInput('peerid_data', ($('#peerid_type').val() == 'peeraddress') || ($('#peerid_type').val() == 'any'));
	}

	function methodsel_change() {

		switch ($('#authentication_method').val()) {
			case 'eap-mschapv2':
			case 'eap-radius':
			case 'hybrid_cert_server':
				hideInput('pskey', true);
				hideClass('peeridgroup', false);
				hideInput('certref', false);
				hideInput('caref', true);
				hideInput('pkcs11certref', true);
				hideInput('pkcs11pin', true);
				disableInput('certref', false);
				disableInput('caref', true);
				disableInput('pkcs11certref', true);
				disableInput('pkcs11pin', true);
				break;
			case 'eap-tls':
			case 'xauth_cert_server':
			case 'cert':
				hideInput('pskey', true);
				hideClass('peeridgroup', false);
				hideInput('certref', false);
				hideInput('caref', false);
				hideInput('pkcs11certref', true);
				hideInput('pkcs11pin', true);
				disableInput('certref', false);
				disableInput('caref', false);
				disableInput('pkcs11certref', true);
				disableInput('pkcs11pin', true);
				break;
			case 'pkcs11':
				hideInput('pskey', true);
				hideClass('peeridgroup', false);
				hideInput('certref', true);
				hideInput('caref', false);
				hideInput('pkcs11certref', false);
				hideInput('pkcs11pin', false);
				disableInput('certref', true);
				disableInput('caref', false);
				disableInput('pkcs11certref', false);
				disableInput('pkcs11pin', false);
				break;

<?php if (isset($pconfig['mobile'])) { ?>
				case 'pre_shared_key':
					hideInput('pskey', true);
					hideClass('peeridgroup', true);
					hideInput('certref', true);
					hideInput('caref', true);
					hideInput('pkcs11certref', true);
					hideInput('pkcs11pin', true);
					disableInput('certref', true);
					disableInput('caref', true);
					disableInput('pkcs11certref', true);
					disableInput('pkcs11pin', true);
					break;
<?php } ?>
			default: /* psk modes*/
				hideInput('pskey', false);
				hideClass('peeridgroup', false);
				hideInput('certref', true);
				hideInput('caref', true);
				hideInput('pkcs11certref', true);
				hideInput('pkcs11pin', true);
				disableInput('certref', true);
				disableInput('caref', true);
				disableInput('pkcs11certref', true);
				disableInput('pkcs11pin', true);
				break;
		}
	}

	/* PHP generates javascript case statements for variable length keys */
	function ealgosel_change(id, bits) {

		$("select[name='ealgo_keylen"+id+"']").find('option').remove().end();

		switch ($('#ealgo_algo'+id).find(":selected").index().toString()) {
<?php
	$i = 0;
	foreach ($p1_ealgos as $algodata) {
		if (is_array($algodata['keysel'])) {
?>
			case '<?=$i?>':
				invisibleGroupInput('ealgo_keylen'+id, false);
<?php
			$key_hi = $algodata['keysel']['hi'];
			$key_lo = $algodata['keysel']['lo'];
			$key_step = $algodata['keysel']['step'];

			for ($keylen = $key_hi; $keylen >= $key_lo; $keylen -= $key_step) {
?>
				$("select[name='ealgo_keylen"+id+"']").append($('<option value="<?=$keylen?>"><?=$keylen?> bits</option>'));
<?php
			}
?>
			break;
<?php
		} else {
?>
			case '<?=$i?>':
				invisibleGroupInput('ealgo_keylen'+id, true);
			break;
<?php
		}
		$i++;
	}
?>
		}

		if (bits) {
			$('#ealgo_keylen'+id).val(bits);
		}
	}

	function prfselectchkbox_change() {
		hide = !$('#prfselect_enable').prop('checked');
		var i;
		for (i = 0; i < 50; i++) {
			hideGroupInput('prfalgo' + i , hide);
		}
	}

	function dpdchkbox_change() {
		hide = !$('#dpd_enable').prop('checked');

		hideInput('dpd_delay', hide);
		hideInput('dpd_maxfail', hide);

		if (!$('#dpd_delay').val()) {
			$('#dpd_delay').val('10')
		}

		if (!$('#dpd_maxfail').val()) {
			$('#dpd_maxfail').val('5')
		}
	}

	//function tfcchkbox_change() {
	//	hide = !$('#tfc_enable').prop('checked');
	//
	//	hideInput('tfc_bytes', hide);
	//}

	// ---------- Monitor elements for change and call the appropriate display functions ----------

	 // Enable PRF
	$('#prfselect_enable').click(function () {
		prfselectchkbox_change();
	});

	 // Enable DPD
	$('#dpd_enable').click(function () {
		dpdchkbox_change();
	});

	 // TFC
	//$('#tfc_enable').click(function () {
	//	tfcchkbox_change();
	//});

	 // Peer identifier
	$('#peerid_type').change(function () {
		peeridsel_change();
	});

	 // My identifier
	$('#myid_type').change(function () {
		myidsel_change();
	});

	 // ike type
	$('#iketype').change(function () {
		iketype_change();
	});

	 // authentication method
	$('#authentication_method').change(function () {
		methodsel_change();
	});

	 // algorithm
	$('[id^=ealgo_algo]select').change(function () {
		id = getStringInt(this.id);
		ealgosel_change(id, 0);
	});

	// On initial page load
	myidsel_change();
	peeridsel_change();
	iketype_change();
	methodsel_change();
	dpdchkbox_change();
	prfselectchkbox_change();
<?php
foreach($pconfig['encryption']['item'] as $key => $p1enc) {
	$keylen = $p1enc['encryption-algorithm']['keylen'];
	if (!is_numericint($keylen)) {
		$keylen = "''";
	}
	echo "ealgosel_change({$key}, {$keylen});";
}
?>

	// ---------- On initial page load ------------------------------------------------------------

	var generateButton = $('<button type="button" class="btn btn-sm btn-outline-secondary fs-ipsec-genkey"><i class="fa-solid fa-arrows-rotate icon-embed-btn" aria-hidden="true"></i><?=gettext("Generate new Pre-Shared Key");?></button>');
	generateButton.on('click', function() {
		$.ajax({
			type: 'post',
			url: 'vpn_ipsec_phase1.php',
			data: {
				generatekey:           true,
			},
			dataType: 'json',
			success: function(data) {
				$('#pskey').val(data.pskey.replace(/\\n/g, '\n'));
			}
		});
	});
	generateButton.appendTo($('#pskey + .help-block')[0]);
});
//]]>
</script>
<?php

include("foot.inc");
