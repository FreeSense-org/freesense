<?php
/*
 * vpn_ipsec.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-vpn-ipsec
##|*NAME=VPN: IPsec
##|*DESCR=Allow access to the 'VPN: IPsec' page.
##|*MATCH=vpn_ipsec.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("vpn_ipsec.inc");

global $p1_authentication_methods;

if ($_POST['apply']) {
	$retval = ipsec_apply_changes();
} else {
	/*
	 * delete, move or toggle entries: the row actions post toggle_N / del_N /
	 * togglep2_N / delp2_N, the move buttons post move_N / movep2_N with the
	 * checked p1entry[] / p2entry[], the bulk buttons post del / delp2
	 */
	$input_errors = ipsec_tunnels_action($_POST);
}

$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Tunnels"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "ipsec";

if (isAllowedPage('vpn_ipsec_phase1.php')) {
	fs_page_action(gettext('Add tunnel'), 'vpn_ipsec_phase1.php', 'fa-plus');
}
if (isAllowedPage('status_ipsec.php')) {
	fs_page_action(gettext('Status'), 'status_ipsec.php', 'fa-chart-line', 'secondary');
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('vpn-ipsec', 'vpn_ipsec.php');

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('ipsec')) {
	print_apply_box(gettext("The IPsec tunnel configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}
global $user_settings;
$show_alias_popup = (array_key_exists('webgui', $user_settings) && !$user_settings['webgui']['disablealiaspopupdetail']);
$ipsec_specialnet = get_specialnet('', [SPECIALNET_IFSUB]);

/* interface, VIP and gateway group labels for the phase 1 interface */
$iflabels = get_configured_interface_with_descr(true);
foreach (get_configured_vip_list() as $vip => $address) {
	$iflabels[$vip] = $address;
	if (get_vip_descr($address)) {
		$iflabels[$vip] .= " (" . get_vip_descr($address) . ")";
	}
}
foreach (return_gateway_groups_array() as $name => $group) {
	$iflabels[$name] = "GW Group {$name}";
}

/* DH groups, hashes and ciphers that strongSwan and RFC 8247 consider weak */
$weak_dh = array('1', '2', '5', '22', '23', '24');
$weak_hash = array('sha1', 'hmac_sha1', 'md5', 'hmac_md5');
$ike_labels = array('ikev1' => 'IKEv1', 'ikev2' => 'IKEv2', 'auto' => gettext('Auto'));

/* the text of a phase 2 local or remote network */
$p2_net_html = function ($idinfo, $mode, $popup) use ($ipsec_specialnet) {
	if (empty($idinfo) || !is_array($idinfo)) {
		return '<span class="fs-muted">' . gettext('none') . '</span>';
	}
	if ($popup && array_key_exists($idinfo['type'], $ipsec_specialnet)) {
		return '<a class="fs-ipsec-net" data-bs-toggle="popover" data-bs-trigger="hover focus" tabindex="0" title="' . htmlspecialchars(gettext('Subnet details')) . '"'
		    . ' data-bs-content="' . htmlspecialchars(ipsec_idinfo_to_cidr($idinfo, false, $mode)) . '">'
		    . str_replace('_', '_<wbr>', htmlspecialchars($ipsec_specialnet[$idinfo['type']])) . '</a>';
	}
	return htmlspecialchars(ipsec_idinfo_to_text($idinfo));
};

/* a proposal chip; weak algorithms get a warning mark */
$chip_html = function (array $chip) {
	$title = $chip['title'] . ($chip['weak'] ? ' (' . gettext('weak algorithm') . ')' : '');
	return '<span class="fs-chip fs-chip--mono' . ($chip['weak'] ? ' is-warn' : '') . '" title="' . htmlspecialchars($title) . '">' . htmlspecialchars($chip['text'])
	    . ($chip['weak'] ? '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span class="visually-hidden"> ' . htmlspecialchars(gettext('weak algorithm')) . '</span>' : '')
	    . '</span>';
};

/* collect first so the summary tiles can sit above the list */
$tunnels = array();
$counts = array('enabled' => 0, 'disabled' => 0, 'mobile' => 0, 'p2' => 0, 'p2_disabled' => 0);
$p2_by_ikeid = array();
foreach (config_get_path('ipsec/phase2', []) as $ph2index => $ph2ent) {
	if (!is_array($ph2ent)) {
		continue;
	}
	$p2_by_ikeid[(string)$ph2ent['ikeid']][$ph2index] = $ph2ent;
}

foreach (config_get_path('ipsec/phase1', []) as $i => $ph1ent) {
	if (!is_array($ph1ent)) {
		continue;
	}
	$disabled = isset($ph1ent['disabled']);
	$mobile = isset($ph1ent['mobile']);
	$counts[$disabled ? 'disabled' : 'enabled']++;
	$counts['mobile'] += $mobile ? 1 : 0;

	$iketype = empty($ph1ent['iketype']) ? 'ikev1' : $ph1ent['iketype'];
	if ($ph1ent['interface']) {
		$if = isset($iflabels[$ph1ent['interface']]) ? $iflabels[$ph1ent['interface']] : sprintf(gettext("Interface not found: '%s'"), $ph1ent['interface']);
	} else {
		$if = "WAN";
	}

	/* one chip per phase 1 proposal: cipher (key length) · hash [/ PRF] · DH group */
	$proposals = array();
	foreach (array_get_path($ph1ent, 'encryption/item', []) as $p1algo) {
		$cipher = array_get_path($p1_ealgos, array_get_path($p1algo, 'encryption-algorithm/name', '') . '/name', '');
		$keylen = array_get_path($p1algo, 'encryption-algorithm/keylen');
		if ($keylen && ($cipher === 'AES')) {
			$cipher .= '-' . $keylen;
		} elseif ($keylen) {
			$cipher .= ' (' . $keylen . ')';
		}
		$hash = $p1_halgos[$p1algo['hash-algorithm']] ?? $p1algo['hash-algorithm'];
		if (isset($ph1ent['prfselect_enable'])) {
			$hash .= ' / PRF ' . ($p1_halgos[$p1algo['prf-algorithm']] ?? $p1algo['prf-algorithm']);
		}
		$dh = (string)$p1algo['dhgroup'];
		$proposals[] = array(
			'text' => implode(' · ', array_filter(array($cipher, $hash, 'DH ' . $dh))),
			'title' => sprintf(gettext('DH group %s'), $p1_dhgroups[$dh] ?? $dh),
			'weak' => in_array($dh, $weak_dh, true) || in_array($p1algo['hash-algorithm'], $weak_hash, true),
		);
	}

	$children = array();
	foreach ($p2_by_ikeid[(string)$ph1ent['ikeid']] ?? array() as $ph2index => $ph2ent) {
		$counts['p2']++;
		$own_disabled = isset($ph2ent['disabled']);
		$counts['p2_disabled'] += ($own_disabled || $disabled) ? 1 : 0;

		$chips = array();
		$chips[] = array('text' => $p2_protos[$ph2ent['protocol']] ?? strtoupper((string)$ph2ent['protocol']), 'weak' => false, 'title' => gettext('Protocol'));
		foreach (($ph2ent['encryption-algorithm-option'] ?? array()) as $ph2ea) {
			$text = $p2_ealgos[$ph2ea['name']]['name'] ?? $ph2ea['name'];
			if ($ph2ea['keylen'] && ($ph2ea['keylen'] != 'auto')) {
				$text .= ($text === 'AES') ? '-' . $ph2ea['keylen'] : ' (' . $ph2ea['keylen'] . ')';
			}
			$chips[] = array('text' => $text, 'weak' => false, 'title' => gettext('Encryption'));
		}
		if (!empty($ph2ent['hash-algorithm-option']) && is_array($ph2ent['hash-algorithm-option'])) {
			foreach ($ph2ent['hash-algorithm-option'] as $ph2ha) {
				$chips[] = array('text' => $p2_halgos[$ph2ha] ?? $ph2ha, 'weak' => in_array($ph2ha, $weak_hash, true), 'title' => gettext('Hash'));
			}
		}
		if (!empty($ph2ent['pfsgroup'])) {
			$chips[] = array('text' => 'PFS ' . $ph2ent['pfsgroup'], 'weak' => in_array((string)$ph2ent['pfsgroup'], $weak_dh, true),
			    'title' => sprintf(gettext('PFS key group %s'), $p2_pfskeygroups[$ph2ent['pfsgroup']] ?? $ph2ent['pfsgroup']));
		}

		$has_nets = in_array($ph2ent['mode'], array('tunnel', 'tunnel6', 'vti'));
		$children[$ph2index] = array(
			'ent' => $ph2ent,
			'own_disabled' => $own_disabled,
			'disabled' => $own_disabled || $disabled,
			'mode' => $p2_modes[$ph2ent['mode']] ?? $ph2ent['mode'],
			'local' => $has_nets ? $p2_net_html($ph2ent['localid'] ?? null, $ph2ent['mode'], $show_alias_popup) : null,
			'remote' => $has_nets ? $p2_net_html($ph2ent['remoteid'] ?? null, $ph2ent['mode'], false) : null,
			'nat' => ($has_nets && !empty($ph2ent['natlocalid']) && ($ph2ent['natlocalid']['type'] ?? '') !== '') ? ipsec_idinfo_to_text($ph2ent['natlocalid']) : null,
			'search' => implode(' ', array($ph2ent['descr'] ?? '', $has_nets ? ipsec_idinfo_to_text($ph2ent['localid']) : '',
			    $has_nets ? ipsec_idinfo_to_text($ph2ent['remoteid']) : '')),
			'chips' => $chips,
		);
	}

	$tunnels[$i] = array(
		'ent' => $ph1ent,
		'disabled' => $disabled,
		'mobile' => $mobile,
		'iketype' => $iketype,
		'ike' => $ike_labels[$iketype] ?? strtoupper($iketype),
		'if' => $if,
		'auth' => array_get_path($p1_authentication_methods, "{$ph1ent['authentication_method']}/name", ''),
		'mode' => (($iketype == 'ikev1') || ($iketype == 'auto')) ? ucfirst((string)$ph1ent['mode']) : '',
		'name' => ($ph1ent['descr'] !== '' && $ph1ent['descr'] !== null) ? $ph1ent['descr'] : sprintf(gettext('Tunnel %s'), $ph1ent['ikeid']),
		'proposals' => $proposals,
		'children' => $children,
	);
}
$can_add = isAllowedPage('vpn_ipsec_phase1.php');
?>

<style>
.fs-ipsec-name { font-weight: 600; color: var(--fs-text-strong); }
.fs-ipsec-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .15rem .5rem; margin-top: .15rem; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-ipsec-ike { display: inline-block; padding: 0 .4rem; border-radius: var(--fs-r-sm); background: var(--fs-accent-tint); color: var(--fs-coral-text); font-weight: 600; line-height: 1.3rem; }
.fs-ipsec-mode { display: inline-flex; align-items: center; gap: .3rem; padding: 0 .45rem; border: 1px solid var(--fs-border); border-radius: 999px; color: var(--fs-text); font-size: var(--fs-fs-xs); font-weight: 600; line-height: 1.35rem; white-space: nowrap; }
.fs-ipsec-mode > i { color: var(--fs-text-muted); }
.fs-ipsec-gw { font-family: var(--fs-font-mono, monospace); color: var(--fs-text-strong); word-break: break-all; }
.fs-ipsec-nets { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .1rem .4rem; font-family: var(--fs-font-mono, monospace); font-size: var(--fs-fs-sm); }
.fs-ipsec-nets > i { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-ipsec-net { color: inherit; text-decoration: underline dotted; cursor: help; }
.fs-ipsec-actions { display: flex; align-items: center; justify-content: flex-end; }
.fs-ipsec-move { display: none; border: 0; background: transparent; }
.fs-ipsec.fs-has-selection .fs-ipsec-move-p1, .fs-ipsec.fs-ipsec-has-p2sel .fs-ipsec-move-p2 { display: inline-flex; color: var(--fs-coral-text); opacity: 1; }
tr.fs-ipsec-p1 > td { border-bottom: 0; padding-top: .7rem; }
tr.fs-ipsec-children > td { padding-top: 0; }
.fs-ipsec-table > tbody > tr.fs-ipsec-children:hover > * { background-color: transparent; }
.fs-ipsec-p2 { margin: 0 0 .35rem .1rem; padding-left: .85rem; border-left: 2px solid var(--fs-border); }
.fs-ipsec-p2head { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .75rem; min-height: 2rem; }
.fs-ipsec-p2toggle { display: inline-flex; align-items: center; gap: .4rem; padding: .15rem .25rem; border: 0; border-radius: var(--fs-r-sm); background: transparent; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 600; }
.fs-ipsec-p2toggle:hover { color: var(--fs-text-strong); }
.fs-ipsec-p2toggle > i { transition: transform var(--fs-t-fast) var(--fs-ease); }
.fs-ipsec-p2toggle[aria-expanded="false"] > i { transform: rotate(-90deg); }
.fs-ipsec-p2add { font-size: var(--fs-fs-sm); }
.fs-ipsec-p2 .table { margin: 0; }
.fs-ipsec-p2 .table > thead > tr > th { font-size: var(--fs-fs-xs); }
.fs-ipsec-p2 .table > tbody > tr:last-child > td { border-bottom: 0; }
.fs-ipsec-p2empty { padding: .25rem .25rem .5rem; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-ipsec-p2bar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; padding: .6rem 1rem; border-top: 1px solid var(--fs-border); background: var(--fs-accent-tint); }
.fs-ipsec-p2bar[hidden] { display: none; }
.fs-ipsec-p2bar-count { font-weight: 600; color: var(--fs-text-strong); font-size: var(--fs-fs-sm); }
.fs-ipsec-p2bar-hint { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
@media (min-width: 992px) {
	/* the same columns under every tunnel */
	.fs-ipsec-p2 .table { table-layout: fixed; }
	.fs-ipsec-p2 th.fs-col-select { width: 2.25rem; }
	.fs-ipsec-p2 th.fs-col-status { width: 7.5rem; }
	.fs-ipsec-p2 th.fs-ipsec-col-name { width: 24%; }
	.fs-ipsec-p2 th.fs-ipsec-col-nets { width: 27%; }
	.fs-ipsec-p2 th.fs-col-actions { width: 11rem; }
}
@media (max-width: 575.98px) {
	tr.fs-ipsec-children > td.fs-col-select { display: none; }
	.fs-ipsec-p2 { margin-left: 0; padding-left: .5rem; }
	.fs-ipsec .fs-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; width: calc(3 * var(--fs-hit) + 4px); }
	.fs-ipsec td.fs-col-actions { white-space: normal; }
}
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Tunnels'), count($tunnels), null, $counts['mobile'] ? sprintf(gettext('%d for mobile clients'), $counts['mobile']) : null);
fs_tile(gettext('Enabled'), $counts['enabled']);
fs_tile(gettext('Disabled'), $counts['disabled']);
fs_tile(gettext('Phase 2 entries'), $counts['p2'], null, $counts['p2_disabled'] ? sprintf(gettext('%d inactive'), $counts['p2_disabled']) : null);
?>
</div>

<form name="mainform" method="post" id="fs-ipsec-form">
<div class="panel panel-default fs-table fs-ipsec">
<?php fs_table_toolbar([
	'title' => gettext('IPsec tunnels'),
	'search' => gettext('Search tunnels, gateways, networks…'),
	'noun' => gettext('tunnels'),
	'noun_one' => gettext('tunnel'),
	'filters' => [
		'status' => [gettext('All states'), 'enabled' => gettext('Enabled'), 'disabled' => gettext('Disabled')],
		'ike' => [gettext('All IKE versions'), 'ikev1' => 'IKEv1', 'ikev2' => 'IKEv2', 'auto' => gettext('Auto')],
	],
	'bulk' => [
		['name' => 'del', 'value' => gettext('Delete selected P1s'), 'label' => gettext('Delete'), 'icon' => 'fa-trash-can', 'variant' => 'danger',
		 'confirm' => gettext('Delete the selected tunnels and their phase 2 entries?')],
	],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover fs-ipsec-table">
			<thead>
				<tr>
					<th class="fs-col-select"><input type="checkbox" data-fs-select-all aria-label="<?=gettext('Select all tunnels')?>"></th>
					<th class="fs-col-status d-none d-sm-table-cell"><?=gettext('Status')?></th>
					<th data-fs-search><?=gettext('Tunnel')?></th>
					<th data-fs-search class="d-none d-md-table-cell"><?=gettext('Remote gateway')?></th>
					<th data-fs-search class="d-none d-xl-table-cell"><?=gettext('Authentication')?></th>
					<th class="d-none d-lg-table-cell"><?=gettext('Phase 1 proposal')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody class="p1-entries">
<?php foreach ($tunnels as $i => $t):
	$ph1ent = $t['ent'];
	$name = $t['name'];
	$badge = $t['disabled'] ? fs_badge('disabled') : fs_badge('enabled');
	$add_p2 = 'vpn_ipsec_phase2.php?ikeid=' . rawurlencode($ph1ent['ikeid']) . ($t['mobile'] ? '&mobile=true' : '');
	$actions = array(['edit', 'vpn_ipsec_phase1.php?ikeid=' . rawurlencode($ph1ent['ikeid']), $name]);
	if (!$t['mobile']) {
		$actions[] = ['copy', "vpn_ipsec_phase1.php?dup={$i}", $name];
	}
	$actions[] = ['toggle', "vpn_ipsec.php?toggle_{$i}=toggle_{$i}", $name, ['enabled' => !$t['disabled']]];
	$actions[] = ['delete', "vpn_ipsec.php?del_{$i}=del_{$i}", $name, ['thing' => gettext('tunnel'),
	    'detail' => gettext('Its phase 2 entries are deleted too.')]];
	$move = '<button type="submit" class="fs-action fs-ipsec-move fs-ipsec-move-p1" name="move_' . $i . '" value="move_' . $i . '"'
	    . ' title="' . htmlspecialchars(sprintf(gettext('Move the selected tunnels before %s'), $name)) . '"'
	    . ' aria-label="' . htmlspecialchars(sprintf(gettext('Move the selected tunnels before %s'), $name)) . '">'
	    . '<i class="fa-solid fa-arrow-turn-up" aria-hidden="true"></i></button>';
	$search = implode(' ', array_column($t['children'], 'search'));
	$nchild = count($t['children']);
?>
				<tr id="fr<?=$i?>" class="fs-ipsec-p1<?=$t['disabled'] ? ' fs-row-disabled' : ''?>" data-fs-filter-status="<?=$t['disabled'] ? 'disabled' : 'enabled'?>" data-fs-filter-ike="<?=htmlspecialchars($t['iketype'])?>">
					<td class="fs-col-select"><input type="checkbox" id="frc<?=$i?>" name="p1entry[]" value="<?=$i?>" data-fs-select aria-label="<?=htmlspecialchars(sprintf(gettext('Select %s'), $name))?>"></td>
					<td class="d-none d-sm-table-cell"><?=$badge?></td>
					<td>
						<span class="fs-ipsec-name"><?=htmlspecialchars($name)?></span>
						<span class="d-sm-none"><?=$badge?></span>
						<div class="fs-ipsec-meta">
							<span class="fs-ipsec-ike"><?=htmlspecialchars($t['ike'])?></span>
							<span><?=htmlspecialchars(sprintf(gettext('ID %s'), $ph1ent['ikeid']))?></span>
							<span class="d-md-none"><?=htmlspecialchars($t['if'])?></span>
						</div>
						<div class="d-md-none small"><?=$t['mobile'] ? htmlspecialchars(gettext('Mobile clients')) : '<span class="fs-ipsec-gw">' . htmlspecialchars($ph1ent['remote-gateway']) . '</span>'?></div>
						<span hidden><?=htmlspecialchars($search)?></span>
					</td>
					<td class="d-none d-md-table-cell">
<?php if ($t['mobile']): ?>
						<?=fs_badge('info', gettext('Mobile clients'))?>
<?php else: ?>
						<span class="fs-ipsec-gw"><?=htmlspecialchars($ph1ent['remote-gateway'])?></span>
<?php endif; ?>
						<div class="fs-ipsec-meta"><span><?=htmlspecialchars(sprintf(gettext('via %s'), $t['if']))?></span></div>
					</td>
					<td class="d-none d-xl-table-cell">
						<?=htmlspecialchars($t['auth'] !== '' ? $t['auth'] : $ph1ent['authentication_method'])?>
<?php if ($t['mode'] !== ''): ?>
						<div class="fs-ipsec-meta"><span><?=htmlspecialchars(sprintf(gettext('%s mode'), $t['mode']))?></span></div>
<?php endif; ?>
					</td>
					<td class="d-none d-lg-table-cell">
						<div class="fs-chips">
							<?=implode('', array_map($chip_html, $t['proposals']))?>
						</div>
					</td>
					<td class="fs-col-actions"><div class="fs-ipsec-actions"><?=$move?><?=fs_row_actions($actions)?></div></td>
				</tr>
				<tr class="fs-ipsec-children<?=$t['disabled'] ? ' fs-row-disabled' : ''?>" data-fs-static data-fs-parent="fr<?=$i?>">
					<td class="fs-col-select"></td>
					<td colspan="6" class="contains-table">
						<div class="fs-ipsec-p2">
							<div class="fs-ipsec-p2head">
<?php if ($nchild): ?>
								<button type="button" class="fs-ipsec-p2toggle" aria-expanded="true" aria-controls="tdph2-<?=$i?>">
									<i class="fa-solid fa-chevron-down" aria-hidden="true"></i><?=gettext('Phase 2')?> <span class="fs-count"><?=$nchild?></span>
								</button>
<?php else: ?>
								<span class="fs-ipsec-p2empty"><?=gettext('No phase 2 entries yet.')?></span>
<?php endif; ?>
								<a class="fs-ipsec-p2add" href="<?=htmlspecialchars($add_p2)?>"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars(gettext('Add phase 2'))?><span class="visually-hidden"> <?=htmlspecialchars(sprintf(gettext('to %s'), $name))?></span></a>
							</div>
<?php if ($nchild): ?>
							<div id="tdph2-<?=$i?>" class="table-responsive">
								<table class="table table-hover table-rowdblclickedit">
									<thead>
										<tr>
											<th class="fs-col-select"><span class="visually-hidden"><?=gettext('Select')?></span></th>
											<th class="fs-col-status d-none d-sm-table-cell"><?=gettext('Status')?></th>
											<th class="fs-ipsec-col-name"><?=gettext('Phase 2')?></th>
											<th class="fs-ipsec-col-nets d-none d-md-table-cell"><?=gettext('Local → remote network')?></th>
											<th class="d-none d-lg-table-cell"><?=gettext('Proposal')?></th>
											<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
										</tr>
									</thead>
									<tbody class="p2-entries">
<?php foreach ($t['children'] as $ph2index => $c):
	$ph2ent = $c['ent'];
	$p2name = ($ph2ent['descr'] ?? '') !== '' ? $ph2ent['descr'] : sprintf(gettext('Phase 2 #%s'), $ph2ent['reqid']);
	if ($c['disabled'] && !$c['own_disabled']) {
		$p2badge = fs_badge('disabled', gettext('Inactive'), gettext('The phase 1 of this entry is disabled'));
	} else {
		$p2badge = $c['disabled'] ? fs_badge('disabled') : fs_badge('enabled');
	}
	$nets = '';
	if ($c['local'] !== null) {
		$nets = '<span class="fs-ipsec-nets"><span>' . $c['local'] . '</span><i class="fa-solid fa-arrow-right" aria-label="' . htmlspecialchars(gettext('to')) . '"></i><span>' . $c['remote'] . '</span></span>';
		if ($c['nat'] !== null) {
			$nets .= '<div class="fs-ipsec-meta"><span>' . htmlspecialchars(sprintf(gettext('NAT as %s'), $c['nat'])) . '</span></div>';
		}
	} else {
		$nets = '<span class="fs-muted small">' . gettext('Between the tunnel endpoints') . '</span>';
	}
	$p2actions = array(
		['edit', 'vpn_ipsec_phase2.php?p2index=' . rawurlencode($ph2ent['uniqid']), $p2name],
		['copy', 'vpn_ipsec_phase2.php?dup=' . rawurlencode($ph2ent['uniqid']), $p2name],
		['toggle', "vpn_ipsec.php?togglep2_{$ph2index}=togglep2_{$ph2index}", $p2name, ['enabled' => !$c['own_disabled']]],
		['delete', "vpn_ipsec.php?delp2_{$ph2index}=delp2_{$ph2index}", $p2name, ['thing' => gettext('phase 2 entry')]],
	);
	$p2label = htmlspecialchars(sprintf(gettext('Move the selected phase 2 entries before %s'), $p2name));
?>
										<tr id="frp2<?=$i?>_<?=$ph2index?>"<?=$c['disabled'] ? ' class="fs-row-disabled"' : ''?>>
											<td class="fs-col-select"><input type="checkbox" name="p2entry[]" value="<?=$ph2index?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Select %s'), $p2name))?>"></td>
											<td class="d-none d-sm-table-cell"><?=$p2badge?></td>
											<td>
												<span class="fs-ipsec-name"><?=htmlspecialchars($p2name)?></span>
												<span class="d-sm-none"><?=$p2badge?></span>
												<div class="fs-ipsec-meta">
													<span class="fs-ipsec-mode"><?=htmlspecialchars($c['mode'])?></span>
													<span><?=htmlspecialchars(sprintf(gettext('Req ID %s'), $ph2ent['reqid']))?></span>
												</div>
												<div class="d-md-none small mt-1"><?=$nets?></div>
												<div class="d-lg-none fs-chips mt-1">
													<?=implode('', array_map($chip_html, $c['chips']))?>
												</div>
											</td>
											<td class="d-none d-md-table-cell"><?=$nets?></td>
											<td class="d-none d-lg-table-cell">
												<div class="fs-chips">
													<?=implode('', array_map($chip_html, $c['chips']))?>
												</div>
											</td>
											<td class="fs-col-actions"><div class="fs-ipsec-actions"><button type="submit" class="fs-action fs-ipsec-move fs-ipsec-move-p2" name="movep2_<?=$ph2index?>" value="movep2_<?=$ph2index?>" title="<?=$p2label?>" aria-label="<?=$p2label?>"><i class="fa-solid fa-arrow-turn-up" aria-hidden="true"></i></button><?=fs_row_actions($p2actions)?></div></td>
										</tr>
<?php endforeach; ?>
									</tbody>
								</table>
							</div>
<?php endif; ?>
						</div>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($tunnels)) {
	fs_empty_row(7, gettext('No IPsec tunnels yet.'), $can_add ? 'vpn_ipsec_phase1.php' : null, $can_add ? gettext('Add tunnel') : null);
} ?>
			</tbody>
		</table>
	</div>
	<div class="fs-ipsec-p2bar" hidden>
		<span class="fs-ipsec-p2bar-count" aria-live="polite" data-fs-format="<?=htmlspecialchars(gettext('%s phase 2 entries selected'))?>"></span>
		<button type="submit" name="delp2" value="<?=htmlspecialchars(gettext('Delete selected P2s'))?>" class="btn btn-sm btn-danger" data-fs-confirm="<?=htmlspecialchars(gettext('Delete the selected phase 2 entries?'))?>" data-fs-confirm-action="<?=htmlspecialchars(gettext('Delete'))?>">
			<i class="fa-solid fa-trash-can icon-embed-btn" aria-hidden="true"></i><?=gettext('Delete')?>
		</button>
		<button type="button" class="btn btn-sm btn-link" data-fs-ipsec-clear><?=gettext('Clear selection')?></button>
		<span class="fs-ipsec-p2bar-hint"><i class="fa-solid fa-arrow-turn-up" aria-hidden="true"></i> <?=gettext('Use the move button on a row to move them before it.')?></span>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('To reorder, select tunnels or phase 2 entries and use the move button on the row they should go before.')?>
		<?=sprintf(gettext('Connection state is on %1$sStatus: IPsec%2$s; debug logging and other options are under %3$sAdvanced settings%4$s.'),
		    '<a href="status_ipsec.php">', '</a>', '<a href="vpn_ipsec_settings.php">', '</a>')?>
	</div>
</div>
</form>

<script>
//<![CDATA[
(function () {
	var form = document.getElementById('fs-ipsec-form');
	if (!form) {
		return;
	}
	var card = form.querySelector('.fs-ipsec');

	/* a tunnel's phase 2 block follows its row when search or a filter hides it */
	form.querySelectorAll('tr.fs-ipsec-children').forEach(function (child) {
		var parent = document.getElementById(child.getAttribute('data-fs-parent'));
		if (!parent) {
			return;
		}
		var sync = function () {
			child.hidden = parent.hidden;
		};
		new MutationObserver(sync).observe(parent, {attributes: true, attributeFilter: ['hidden']});
		sync();

		/* double-click a tunnel row to edit it (phase 2 rows use table-rowdblclickedit) */
		parent.addEventListener('dblclick', function (e) {
			if (e.target.closest('a, button, input')) {
				return;
			}
			var edit = parent.querySelector('.fs-actions a.fs-action');
			if (edit) {
				edit.click();
			}
		});
	});

	/* collapse / expand the phase 2 entries of a tunnel */
	form.querySelectorAll('.fs-ipsec-p2toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var open = btn.getAttribute('aria-expanded') !== 'true';
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			document.getElementById(btn.getAttribute('aria-controls')).hidden = !open;
		});
	});

	/* phase 2 selection: bulk delete bar and the phase 2 move buttons */
	var bar = form.querySelector('.fs-ipsec-p2bar');
	var count = bar.querySelector('.fs-ipsec-p2bar-count');
	var boxes = form.querySelectorAll('input[name="p2entry[]"]');
	var update = function () {
		var n = 0;
		boxes.forEach(function (cb) {
			cb.closest('tr').classList.toggle('fs-selected', cb.checked);
			n += cb.checked ? 1 : 0;
		});
		bar.hidden = (n === 0);
		card.classList.toggle('fs-ipsec-has-p2sel', n > 0);
		count.textContent = count.getAttribute('data-fs-format').replace('%s', n);
	};
	boxes.forEach(function (cb) {
		cb.addEventListener('change', update);
	});
	bar.querySelector('[data-fs-ipsec-clear]').addEventListener('click', function () {
		boxes.forEach(function (cb) {
			cb.checked = false;
		});
		update();
	});
	update();
})();
//]]>
</script>

<?php
include("foot.inc");
