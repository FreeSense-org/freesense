<?php
/*
 * status_ipsec.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-status-ipsec
##|*NAME=Status: IPsec
##|*DESCR=Allow access to the 'Status: IPsec' page.
##|*MATCH=status_ipsec.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("service-utils.inc");

if ($_POST['act'] == 'connect') {
	/* Assume type is IKE */
	$type = empty($_POST['type']) ? 'ike' : $_POST['type'];
	ipsec_initiate_by_conid($type, $_POST['conid']);
} elseif ($_POST['act'] == 'disconnect') {
	/* Assume type is IKE */
	$type = empty($_POST['type']) ? 'ike' : $_POST['type'];
	ipsec_terminate_by_conid($type, $_POST['conid'], $_POST['uniqueid']);
}

// If this is just an AJAX call to update the table body, just generate the body and quit
if ($_REQUEST['ajax']) {
	print_ipsec_body();
	exit;
}

/*
 * Connect / disconnect control. The id is read by the shared IPsec handler in
 * FreeSenseHelpers.js ("ipsecstatus-<act>-<type>-<conid>[-<uniqueid>]"), which
 * posts it back to this page. Disconnects confirm first (data-fs-confirm).
 */
function ipsec_ui_button($act, $type, $conid, $uniqueid, $label, $confirm = null, $detail = null) {
	$id = "ipsecstatus-{$act}-{$type}-{$conid}" . (empty($uniqueid) ? '' : "-{$uniqueid}");
	$attrs = [
		'type' => 'button',
		'class' => 'fs-action' . (($act == 'disconnect') ? ' fs-action--delete' : ''),
		'id' => $id,
		'title' => $label,
		'aria-label' => $label,
		'data-fs-confirm' => $confirm,
		'data-fs-confirm-detail' => $detail,
		'data-fs-confirm-action' => ($confirm !== null) ? gettext('Disconnect') : null,
	];
	$icon = ($act == 'disconnect') ? 'fa-plug-circle-xmark' : (($type == 'ike') ? 'fa-right-to-bracket' : 'fa-plug');
	return '<button' . fs_attrs($attrs) . '><i class="fa-solid ' . $icon . '" aria-hidden="true"></i></button>';
}

function ipsec_ui_edit($href, $label) {
	return '<a class="fs-action" href="' . fs_h($href) . '" title="' . fs_h($label) . '" aria-label="' . fs_h($label) . '">'
	    . '<i class="fa-solid fa-pencil" aria-hidden="true"></i></a>';
}

// Table body is composed here so that it can be more easily updated via AJAX
function print_ipsec_body() {
	if (!ipsec_enabled()) {
		echo '<tr class="fs-empty" data-ipsec-msg><td colspan="8"><span class="fs-empty-message">' .
		    fs_h(gettext("IPsec is disabled.")) . '</span></td></tr>';
		return;
	}
	if (!get_service_status(array('name' => 'ipsec'))) {
		echo '<tr class="fs-empty" data-ipsec-msg><td colspan="8"><span class="fs-empty-message">' .
		    fs_h(gettext("IPsec daemon is stopped.")) . '</span></td></tr>';
		return;
	}

	$cmap = ipsec_map_config_by_id();
	$status = ipsec_list_sa();
	if (!is_array($status)) {
		$status = array();
	}

	$p1conids = array_column($status, 'con-id');
	$p1uniqueids = array_column($status, 'uniqueid');
	array_multisort($p1conids, SORT_NATURAL,
			$p1uniqueids, SORT_NUMERIC,
			$status);

	$p1connected = array();
	$p2connected = array();
	$rows = 0;
	foreach ($status as $ikesa) {
		list($ikeid, $reqid) = ipsec_id_by_conid($ikesa['con-id']);
		if (!array_key_exists($ikeid, $cmap)) {
			// Doesn't match known tunnel
			$p1connected[$ikesa['con-id']] = $ikesa['con-id'];
		} else {
			$p1connected[$ikeid] = $ph1idx = $ikeid;
		}
		if (!array_key_exists('child-sas', $ikesa) || !is_array($ikesa['child-sas'])) {
			$ikesa['child-sas'] = array();
		}
		if (count($ikesa['child-sas'])) {
			$p2conids = array_column($ikesa['child-sas'], 'name');
			$p2uniqueids = array_column($ikesa['child-sas'], 'uniqueid');
			array_multisort($p2conids, SORT_NATURAL,
					$p2uniqueids, SORT_NUMERIC,
					$ikesa['child-sas']);

			foreach ($ikesa['child-sas'] as $childsa) {
				list($childikeid, $childreqid) = ipsec_id_by_conid($childsa['name']);
				if ($childreqid != null) {
					$p2connected[$childreqid] = $childsa['name'];
				} else {
					/* If this is IKEv2 w/o Split, mark all reqids for the P1 as connected */
					if (($cmap[$childikeid]['p1']['iketype'] == 'ikev2') &&
					    !isset($cmap[$childikeid]['p1']['splitconn']) &&
					    isset($cmap[$ikeid]['p2']) && is_array($cmap[$ikeid]['p2'])) {
						foreach ($cmap[$ikeid]['p2'] as $p2) {
							$p2connected[$p2['reqid']] = $childsa['name'];
						}
					}
				}
			}
		}
		$p2disconnected = array();
		if (!isset($cmap[$ikeid]['p1']['mobile']) &&
		    isset($cmap[$ikeid]) &&
		    is_array($cmap[$ikeid]) &&
		    is_array($cmap[$ikeid]['p2'])) {
			foreach ($cmap[$ikeid]['p2'] as $p2) {
				if (!array_key_exists($p2['reqid'], $p2connected)) {
					/* This P2 is not connected */
					$p2conid = ipsec_conid($cmap[$ikeid]['p1'], $p2);
					$p2disconnected[$p2conid] = $p2;
				}
			}
		}

		/* identities and endpoints */
		$localid = gettext("Unknown");
		if (!empty($ikesa['local-id'])) {
			$localid = ($ikesa['local-id'] == '%any') ? gettext('Any identifier') : $ikesa['local-id'];
		}
		$lhost = gettext("Unknown");
		if (!empty($ikesa['local-host'])) {
			$lhost = $ikesa['local-host'];
			if (!empty($ikesa['local-port'])) {
				if (is_ipaddrv6($ikesa['local-host'])) {
					$lhost = "[{$lhost}]";
				}
				$lhost .= ":{$ikesa['local-port']}";
			}
		}
		$identity = "";
		if (!empty($ikesa['remote-id'])) {
			$identity = ($ikesa['remote-id'] == '%any') ? gettext('Any identifier') : $ikesa['remote-id'];
		}
		$remoteid = "";
		if (!empty($ikesa['remote-xauth-id'])) {
			$remoteid = $ikesa['remote-xauth-id'];
		} elseif (!empty($ikesa['remote-eap-id'])) {
			$remoteid = $ikesa['remote-eap-id'];
		} elseif (empty($identity)) {
			$identity = gettext("Unknown");
		}
		$rhost = gettext("Unknown");
		if (!empty($ikesa['remote-host'])) {
			$rhost = $ikesa['remote-host'];
			if (!empty($ikesa['remote-port'])) {
				if (is_ipaddrv6($ikesa['remote-host'])) {
					$rhost = "[{$rhost}]";
				}
				$rhost .= ":{$ikesa['remote-port']}";
			}
		}
		$lspi = ($ikesa['initiator'] == 'yes') ? $ikesa['initiator-spi'] : $ikesa['responder-spi'];
		$rspi = ($ikesa['initiator'] == 'yes') ? $ikesa['responder-spi'] : $ikesa['initiator-spi'];

		/* state */
		if ($ikesa['state'] == 'ESTABLISHED') {
			$state = 'connected';
			$badge = fs_badge('up', gettext('Connected'),
			    sprintf(gettext('%1$s seconds (%2$s) ago'), $ikesa['established'], convert_seconds_to_dhms($ikesa['established'])));
		} elseif ($ikesa['state'] == 'CONNECTING') {
			$state = 'connecting';
			$badge = fs_badge('pending', gettext('Connecting'));
		} else {
			$state = 'other';
			$badge = fs_badge('warn', ucfirst(strtolower($ikesa['state'])));
		}

		/* actions */
		$name = $cmap[$ikeid]['p1']['descr'] ?? '';
		$label = ($name !== '') ? $name : $ikesa['con-id'];
		$actions = '';
		if (array_key_exists($ikeid, $cmap)) {
			$actions .= ipsec_ui_edit("vpn_ipsec_phase1.php?ikeid={$ikeid}", sprintf(gettext('Edit phase 1 of %s'), $label));
		}
		if (!in_array($ikesa['state'], array('ESTABLISHED', 'CONNECTING'))) {
			$actions .= ipsec_ui_button('connect', 'all', $ikesa['con-id'], null, sprintf(gettext('Connect %s (phase 1 and 2)'), $label));
		} else {
			if (empty($ikesa['child-sas']) && ($ikesa['state'] != 'CONNECTING')) {
				$actions .= ipsec_ui_button('connect', 'all', $ikesa['con-id'], null, sprintf(gettext('Connect child SAs of %s'), $label));
			}
			$actions .= ipsec_ui_button('disconnect', 'ike', $ikesa['con-id'], $ikesa['uniqueid'], sprintf(gettext('Disconnect %s'), $label),
			    sprintf(gettext('Disconnect “%s”?'), $label), gettext('The tunnel and all of its child SAs go down until they are connected again.'));
		}

		$child_key = "{$ikesa['con-id']}_{$ikesa['uniqueid']}";
		$nchild = count($ikesa['child-sas']);
		$ndisc = count($p2disconnected);
		$rows++;
?>
<tr data-fs-filter-state="<?=$state?>" data-ipsec-children="<?=$nchild?>">
	<td><?=$badge?></td>
	<td>
		<strong><?=htmlspecialchars($name)?></strong>
		<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($ikesa['con-id'])?> #<?=htmlspecialchars($ikesa['uniqueid'])?></span>
<?php		if (($nchild + $ndisc) > 0): ?>
		<button type="button" class="btn btn-link btn-sm fs-ipsec-toggle" data-ipsec-toggle="<?=htmlspecialchars($child_key)?>" aria-expanded="false">
			<i class="fa-solid fa-chevron-right" aria-hidden="true"></i><?=htmlspecialchars(sprintf(ngettext('%d child SA', '%d child SAs', $nchild), $nchild))?><?php if ($ndisc): ?>, <?=htmlspecialchars(sprintf(gettext('%d down'), $ndisc))?><?php endif; ?>
		</button>
<?php		endif; ?>
	</td>
	<td>
		<?=htmlspecialchars($localid)?>
		<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($lhost)?><?=isset($ikesa['nat-local']) ? ' · ' . htmlspecialchars(gettext("NAT-T")) : ''?></span>
<?php		if (!empty($lspi)): ?>
		<span class="fs-ipsec-sub fs-mono">SPI <?=htmlspecialchars($lspi)?></span>
<?php		endif; ?>
	</td>
	<td>
<?php		if (!empty($remoteid)): ?>
		<?=htmlspecialchars($remoteid)?><br>
<?php		endif; ?>
		<?=htmlspecialchars($identity)?>
		<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($rhost)?><?=isset($ikesa['nat-remote']) ? ' · ' . htmlspecialchars(gettext("NAT-T")) : ''?></span>
<?php		if (!empty($rspi)): ?>
		<span class="fs-ipsec-sub fs-mono">SPI <?=htmlspecialchars($rspi)?></span>
<?php		endif; ?>
	</td>
	<td>
		IKEv<?=htmlspecialchars($ikesa['version'])?>
		<span class="fs-ipsec-sub"><?=($ikesa['initiator'] == 'yes') ? htmlspecialchars(gettext("Initiator")) : htmlspecialchars(gettext("Responder"))?></span>
	</td>
	<td class="fs-ipsec-small">
<?php		if ($ikesa['version'] == 2): ?>
		<?=htmlspecialchars(gettext("Rekey:"))?>
		<?=!empty($ikesa['rekey-time']) ? htmlspecialchars(convert_seconds_to_dhms($ikesa['rekey-time'])) : htmlspecialchars(gettext("Disabled"))?><br>
<?php		endif; ?>
		<?=htmlspecialchars(gettext("Reauth:"))?>
		<?=!empty($ikesa['reauth-time']) ? htmlspecialchars(convert_seconds_to_dhms($ikesa['reauth-time'])) : htmlspecialchars(gettext("Disabled"))?>
<?php		if ($ikesa['state'] == 'ESTABLISHED'): ?>
		<span class="fs-ipsec-sub"><?=htmlspecialchars(sprintf(gettext('Up %s'), convert_seconds_to_dhms($ikesa['established'])))?></span>
<?php		endif; ?>
	</td>
	<td class="fs-ipsec-small fs-mono">
		<?=implode('<br>', array_map('htmlspecialchars', array_filter([
		    ($ikesa['encr-alg'] ?? '') . (!empty($ikesa['encr-keysize']) ? " ({$ikesa['encr-keysize']})" : ''),
		    $ikesa['integ-alg'] ?? '', $ikesa['prf-alg'] ?? '', $ikesa['dh-group'] ?? ''], 'strlen')))?>
	</td>
	<td class="fs-col-actions"><div class="fs-actions"><?=$actions?></div></td>
</tr>
<?php		if (($nchild + $ndisc) > 0): ?>
<tr class="fs-ipsec-children" data-fs-static data-ipsec-child="<?=htmlspecialchars($child_key)?>" hidden>
	<td colspan="8">
	<div class="table-responsive">
	<table class="table table-sm">
	<thead>
	<tr>
		<th><?=htmlspecialchars(gettext("Status"))?></th>
		<th><?=htmlspecialchars(gettext("Child SA"))?></th>
		<th><?=htmlspecialchars(gettext("Local"))?></th>
		<th><?=htmlspecialchars(gettext("Remote"))?></th>
		<th><?=htmlspecialchars(gettext("SPIs"))?></th>
		<th><?=htmlspecialchars(gettext("Times"))?></th>
		<th><?=htmlspecialchars(gettext("Algorithms"))?></th>
		<th><?=htmlspecialchars(gettext("Traffic"))?></th>
		<th><span class="visually-hidden"><?=htmlspecialchars(gettext("Actions"))?></span></th>
	</tr>
	</thead>
	<tbody>
<?php
			foreach ($ikesa['child-sas'] as $childsa) {
				list($childikeid, $childreqid) = ipsec_id_by_conid($childsa['name']);
				$p2descr = "";
				$p2uid = "";
				if (!empty($childreqid)) {
					/* IKEv1 or IKEv2+Split */
					$p2descr = $cmap[$childikeid]['p2'][$childreqid]['descr'];
					$p2uid = $cmap[$childikeid]['p2'][$childreqid]['uniqid'];
				} else {
					$childreqid = array_key_first(array_get_path($cmap, "{$childikeid}/p2", []));
					$p2uid = array_get_path($cmap, "{$childikeid}/p2/{$childreqid}/uniqid");
					if (count(array_get_path($cmap, "{$childikeid}/p2", [])) > 1) {
						$p2descr = gettext("Multiple");
					} else {
						$p2descr = array_get_path($cmap, "{$childikeid}/p2/{$childreqid}/descr");
					}
				}
				$lnetlist = array();
				if (is_array($childsa['local-ts'])) {
					foreach ($childsa['local-ts'] as $lnets) {
						$lnetlist[] = htmlspecialchars(ipsec_fixup_network($lnets));
					}
				} else {
					$lnetlist[] = htmlspecialchars(gettext("Unknown"));
				}
				$rnetlist = array();
				if (is_array($childsa['remote-ts'])) {
					foreach ($childsa['remote-ts'] as $rnets) {
						$rnetlist[] = htmlspecialchars(ipsec_fixup_network($rnets));
					}
				} else {
					$rnetlist[] = htmlspecialchars(gettext("Unknown"));
				}
				$algos = array_filter([
				    $childsa['encr-alg'] . (!empty($childsa['encr-keysize']) ? " ({$childsa['encr-keysize']})" : ''),
				    $childsa['integ-alg'] ?? '', $childsa['prf-alg'] ?? '', $childsa['dh-group'] ?? '', $childsa['esn'] ?? ''], 'strlen');
				$ipcomp = gettext('None');
				if (!empty($childsa['cpi-in']) || !empty($childsa['cpi-out'])) {
					$ipcomp = "{$childsa['cpi-in']} {$childsa['cpi-out']}";
				}
				$clabel = ($p2descr !== '' && $p2descr !== null) ? $p2descr : $childsa['name'];
				$cstate = ucfirst(strtolower($childsa['state']));
?>
	<tr>
		<td><?=($childsa['state'] == 'INSTALLED') ? fs_badge('up', $cstate) : fs_badge('warn', $cstate)?></td>
		<td>
			<?=htmlspecialchars($p2descr)?>
			<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($childsa['name'])?> #<?=htmlspecialchars($childsa['uniqueid'])?></span>
		</td>
		<td class="fs-mono"><?=implode('<br>', $lnetlist)?></td>
		<td class="fs-mono"><?=implode('<br>', $rnetlist)?></td>
		<td class="fs-ipsec-small fs-mono">
<?php				if (isset($childsa['spi-in'])): ?>
			<?=htmlspecialchars(gettext("Local:"))?> <?=htmlspecialchars($childsa['spi-in'])?><br>
<?php				endif; ?>
<?php				if (isset($childsa['spi-out'])): ?>
			<?=htmlspecialchars(gettext("Remote:"))?> <?=htmlspecialchars($childsa['spi-out'])?>
<?php				endif; ?>
		</td>
		<td class="fs-ipsec-small">
			<?=htmlspecialchars(gettext("Rekey:"))?> <?=htmlspecialchars(convert_seconds_to_dhms($childsa['rekey-time']))?><br>
			<?=htmlspecialchars(gettext("Life:"))?> <?=htmlspecialchars(convert_seconds_to_dhms($childsa['life-time']))?><br>
			<?=htmlspecialchars(gettext("Install:"))?> <?=htmlspecialchars(convert_seconds_to_dhms($childsa['install-time']))?>
		</td>
		<td class="fs-ipsec-small fs-mono">
			<?=implode('<br>', array_map('htmlspecialchars', $algos))?><br>
			<?=htmlspecialchars(gettext("IPComp: "))?><?=htmlspecialchars($ipcomp)?>
		</td>
		<td class="fs-ipsec-small">
			<span class="fs-ipsec-dir"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i><span class="visually-hidden"><?=htmlspecialchars(gettext("In:"))?></span> <?=htmlspecialchars(format_bytes($childsa['bytes-in']))?> · <?=htmlspecialchars(number_format($childsa['packets-in']))?> <?=htmlspecialchars(gettext("pkts"))?></span><br>
			<span class="fs-ipsec-dir"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i><span class="visually-hidden"><?=htmlspecialchars(gettext("Out:"))?></span> <?=htmlspecialchars(format_bytes($childsa['bytes-out']))?> · <?=htmlspecialchars(number_format($childsa['packets-out']))?> <?=htmlspecialchars(gettext("pkts"))?></span>
		</td>
		<td class="fs-col-actions"><div class="fs-actions">
<?php				if (!empty($p2uid) && ($p2descr != gettext("Multiple"))): ?>
			<?=ipsec_ui_edit("vpn_ipsec_phase2.php?uniqid={$p2uid}", sprintf(gettext('Edit phase 2 of %s'), $clabel))?>
<?php				endif; ?>
			<?=ipsec_ui_button('disconnect', 'child', $childsa['name'], $childsa['uniqueid'], sprintf(gettext('Disconnect child SA %s'), $clabel),
			    sprintf(gettext('Disconnect child SA “%s”?'), $clabel), gettext('Traffic for these networks stops until the child SA is connected again.'))?>
		</div></td>
	</tr>
<?php
			}
			foreach ($p2disconnected as $p2conid => $p2) {
				$clabel = !empty($p2['descr']) ? $p2['descr'] : $p2conid;
?>
	<tr>
		<td><?=fs_badge('down', gettext('Disconnected'))?></td>
		<td>
			<?=htmlspecialchars($p2['descr'])?>
			<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($p2conid)?></span>
		</td>
		<td class="fs-mono"><?=htmlspecialchars(ipsec_idinfo_to_cidr($p2['localid'], false, $p2['mode']))?></td>
		<td class="fs-mono"><?=htmlspecialchars(ipsec_idinfo_to_cidr($p2['remoteid'], false, $p2['mode']))?></td>
		<td></td>
		<td></td>
		<td></td>
		<td></td>
		<td class="fs-col-actions"><div class="fs-actions">
			<?=ipsec_ui_edit("vpn_ipsec_phase2.php?uniqid={$p2['uniqid']}", sprintf(gettext('Edit phase 2 of %s'), $clabel))?>
			<?=ipsec_ui_button('connect', 'child', $p2conid, null, sprintf(gettext('Connect child SA %s'), $clabel))?>
		</div></td>
	</tr>
<?php
			}
?>
	</tbody>
	</table>
	</div>
	</td>
</tr>
<?php
		endif;
	}

	$rgmap = array();

	foreach ($cmap as $p1) {
		if (!array_key_exists('p1', $p1) ||
		    isset($p1['p1']['disabled'])) {
			continue;
		}
		$ph1ent = &$p1['p1'];
		$rgmap[$ph1ent['remote-gateway']] = $ph1ent['remote-gateway'];
		if ($p1connected[$ph1ent['ikeid']]) {
			continue;
		}
		list ($myid_type, $myid_data) = ipsec_find_id($ph1ent, "local", array());
		if (empty($myid_data)) {
			$myid_data = gettext("Unknown");
		}
		$ph1src = ipsec_get_phase1_src($ph1ent);
		$ph1src = empty($ph1src) ? gettext("Unknown") : str_replace(',', ', ', $ph1src);
		$mobile = isset($ph1ent['mobile']);
		$conid = ipsec_conid($ph1ent);
		$label = !empty($ph1ent['descr']) ? $ph1ent['descr'] : $conid;
		$rows++;
?>
<tr data-fs-filter-state="<?=$mobile ? 'waiting' : 'down'?>" data-ipsec-children="0">
	<td><?=$mobile ? fs_badge('idle', gettext('Waiting')) : fs_badge('down', gettext('Disconnected'))?></td>
	<td>
		<strong><?=htmlspecialchars($ph1ent['descr'])?></strong>
		<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($conid)?></span>
	</td>
	<td>
		<?=htmlspecialchars($myid_data)?>
		<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($ph1src)?></span>
	</td>
	<td>
<?php		if (!$mobile):
			list ($peerid_type, $peerid_data) = ipsec_find_id($ph1ent, "peer", $rgmap);
			if (empty($peerid_data)) {
				$peerid_data = gettext("Unknown");
			}
			$ph1dst = ipsec_get_phase1_dst($ph1ent);
			if (empty($ph1dst)) {
				$ph1dst = gettext("Unknown");
			}
?>
		<?=htmlspecialchars($peerid_data)?>
		<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($ph1dst)?></span>
<?php		else: ?>
		<?=htmlspecialchars(gettext("Mobile Clients"))?>
		<span class="fs-ipsec-sub"><?=htmlspecialchars(gettext("Awaiting connections"))?></span>
<?php		endif; ?>
	</td>
	<td><?=htmlspecialchars(['ikev1' => 'IKEv1', 'ikev2' => 'IKEv2', 'auto' => gettext('Auto')][$ph1ent['iketype'] ?? ''] ?? '')?></td>
	<td></td>
	<td></td>
	<td class="fs-col-actions"><div class="fs-actions">
		<?=ipsec_ui_edit("vpn_ipsec_phase1.php?ikeid={$ph1ent['ikeid']}", sprintf(gettext('Edit phase 1 of %s'), $label))?>
<?php		if (!$mobile): ?>
		<?=ipsec_ui_button('connect', 'ike', $conid, null, sprintf(gettext('Connect phase 1 of %s only'), $label))?>
		<?=ipsec_ui_button('connect', 'all', $conid, null, sprintf(gettext('Connect %s (phase 1 and 2)'), $label))?>
<?php		endif; ?>
	</div></td>
</tr>
<?php
	}
	unset($p1connected, $p2connected, $p2disconnected, $rgmap);

	if ($rows == 0) {
		echo '<tr class="fs-empty" data-ipsec-msg><td colspan="8"><span class="fs-empty-message">' .
		    fs_h(gettext("No IPsec tunnels are configured.")) . '</span></td></tr>';
	}
}

$pgtitle = array(gettext("Status"), gettext("IPsec"), gettext("Overview"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "ipsec";

if (isAllowedPage('vpn_ipsec.php')) {
	fs_page_action(gettext('Configure IPsec'), 'vpn_ipsec.php', 'fa-gear', 'secondary');
}

include("head.inc");

fs_tabs('status-ipsec', 'status_ipsec.php');
?>

<style>
.fs-ipsec-sub { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-ipsec-small { font-size: var(--fs-fs-sm); white-space: nowrap; }
.fs-ipsec-toggle { padding: 0; margin-top: .2rem; font-size: var(--fs-fs-xs); text-decoration: none; }
.fs-ipsec-toggle i { margin-right: .3rem; transition: transform var(--fs-t-fast) var(--fs-ease); }
.fs-ipsec-toggle[aria-expanded="true"] i { transform: rotate(90deg); }
tr.fs-ipsec-children > td { padding: 0 0 .75rem 2rem; background: var(--fs-surface-raised); }
tr.fs-ipsec-children table { margin: 0; background: transparent; }
.fs-ipsec-dir { white-space: nowrap; }
.fs-ipsec-dir i { color: var(--fs-text-muted); width: 1em; }
</style>

<div class="fs-tiles">
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Connected')?></div><div class="fs-tile-value" data-ipsec-count="connected">–</div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Connecting')?></div><div class="fs-tile-value" data-ipsec-count="connecting">–</div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Disconnected')?></div><div class="fs-tile-value" data-ipsec-count="down">–</div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Child SAs')?></div><div class="fs-tile-value" data-ipsec-count="children">–</div></div>
</div>

<div class="panel panel-default fs-table" id="ipsec-status">
<?php fs_table_toolbar([
	'title' => gettext('Tunnels'),
	'search' => gettext('Search tunnels, IDs, hosts…'),
	'noun' => gettext('tunnels'),
	'noun_one' => gettext('tunnel'),
	'filters' => ['state' => [gettext('All states'), 'connected' => gettext('Connected'), 'connecting' => gettext('Connecting'),
	    'down' => gettext('Disconnected'), 'waiting' => gettext('Waiting'), 'other' => gettext('Other')]],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th class="fs-col-status" data-fs-search><?=htmlspecialchars(gettext("Status"))?></th>
					<th data-fs-search><?=htmlspecialchars(gettext("Tunnel"))?></th>
					<th data-fs-search><?=htmlspecialchars(gettext("Local"))?></th>
					<th data-fs-search><?=htmlspecialchars(gettext("Remote"))?></th>
					<th><?=htmlspecialchars(gettext("Role"))?></th>
					<th><?=htmlspecialchars(gettext("Timers"))?></th>
					<th data-fs-search><?=htmlspecialchars(gettext("Algorithms"))?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=htmlspecialchars(gettext("Actions"))?></span></th>
				</tr>
			</thead>
			<tbody id="ipsec-body">
<?php print_ipsec_body(); ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> <?=gettext('Refreshes every 5 seconds.')?>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
/* after js/freesense-ui.js has set up the table (root._fsTable) */
events.push(function() { setTimeout(function() {
	var root = document.getElementById('ipsec-status');
	var body = document.getElementById('ipsec-body');
	var open = {};		// child SA lists the user expanded, kept across refreshes
	var busy = false;

	/* child SA rows follow their tunnel: shown only while expanded and the tunnel row is visible */
	function syncChildren() {
		body.querySelectorAll('tr[data-ipsec-child]').forEach(function (tr) {
			var key = tr.getAttribute('data-ipsec-child');
			var parent = tr.previousElementSibling;
			tr.hidden = !open[key] || !parent || parent.hidden;
			var btn = parent ? parent.querySelector('[data-ipsec-toggle]') : null;
			if (btn) {
				btn.setAttribute('aria-expanded', open[key] ? 'true' : 'false');
			}
		});
	}

	function updateTiles() {
		var counts = {connected: 0, connecting: 0, down: 0, children: 0};
		body.querySelectorAll('tr[data-fs-filter-state]').forEach(function (tr) {
			var s = tr.getAttribute('data-fs-filter-state');
			if (counts.hasOwnProperty(s)) {
				counts[s]++;
			}
			counts.children += parseInt(tr.getAttribute('data-ipsec-children') || '0', 10);
		});
		root.parentNode.querySelectorAll('[data-ipsec-count]').forEach(function (el) {
			el.textContent = String(counts[el.getAttribute('data-ipsec-count')]);
		});
	}

	function refreshed() {
		if (root._fsTable) {
			root._fsTable.apply(false);
		}
		syncChildren();
		updateTiles();
	}

	/* keep the child rows in step with search and filters */
	if (root._fsTable) {
		var apply = root._fsTable.apply;
		root._fsTable.apply = function () {
			apply.apply(this, arguments);
			syncChildren();
		};
	}

	body.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-ipsec-toggle]');
		if (!btn) {
			return;
		}
		var key = btn.getAttribute('data-ipsec-toggle');
		open[key] = !open[key];
		syncChildren();
	});

	function update() {
		/* never swap rows under an open dialog (e.g. the disconnect confirmation) */
		if (busy || $('.modal:visible').length) {
			setTimeout(update, 1000);
			return;
		}
		busy = true;
		$.ajax({url: '/status_ipsec.php', type: 'post', data: {ajax: 'ajax'}})
		.done(function (response) {
			if (!$('.modal:visible').length) {
				$(body).html(response);
				refreshed();
			}
		})
		.always(function () {
			busy = false;
			setTimeout(update, 5000);
		});
	}

	refreshed();
	setTimeout(update, 5000);
}, 0); });
//]]>
</script>

<?php
include("foot.inc"); ?>
