<?php
/*
 * firewall_shaper_queues.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-firewall-trafficshaper-queues
##|*NAME=Firewall: Traffic Shaper: Queues
##|*DESCR=Allow access to the 'Firewall: Traffic Shaper: Queues' page.
##|*MATCH=firewall_shaper_queues.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("rrd.inc");

$qname = gettext("No Queue Configured/Selected");

$shaperIFlist = get_configured_interface_with_descr();
read_altq_config();
$qlist =& get_unique_queue_list();

if (!is_array($qlist)) {
	$qlist = array();
}

/* Rows of the selected queue, one per interface (action=show) */
$rows = [];
$selected = '';

if ($_GET) {
	if ($_GET['queue']) {
		$qname = htmlspecialchars(trim($_GET['queue']));
	}

	if ($_GET['interface']) {
		$interface = htmlspecialchars(trim($_GET['interface']));
	}

	if ($_GET['action']) {
		$action = htmlspecialchars($_GET['action']);
	}

	switch ($action) {
		case "delete":
			$altq =& $altq_list_queues[$interface];
			$qtmp =& $altq->find_queue("", $qname);
			if ($qtmp) {
				$qtmp->delete_queue();
				if (write_config("Traffic Shaper: Queue deleted")) {
					mark_subsystem_dirty('shaper');
				}
			}
			header("Location: firewall_shaper_queues.php");
			exit;
		case "add":
			/*
			 * XXX: WARNING: This returns the first it finds.
			 * Maybe the user expects something else?!
			 */
			foreach ($altq_list_queues as $altq) {
				$qtmp =& $altq->find_queue("", $qname);

				if ($qtmp) {
					$copycfg = array();
					$qtmp->copy_queue($interface, $copycfg);
					$aq =& $altq_list_queues[$interface];

					if ($qname == $qtmp->GetInterface()) {
						config_set_path('shaper/queue/', $copycfg);
					} else if ($aq) {
						$tmp1 =& $qtmp->find_parentqueue($interface, $qname);
						if ($tmp1) {
							$tmp =& $aq->find_queue($interface, $tmp1->GetQname());
						}

						if ($tmp) {
							$link = shaper_config_get_path($tmp->GetLink());
						} else {
							$link = shaper_config_get_path($aq->GetLink());
						}

						config_set_path("{$link}/queue/", $copycfg);
					} else {
						$newroot = array();
						$newroot['name'] = $interface;
						$newroot['interface'] = $interface;
						$newroot['scheduler'] = $altq->GetScheduler();
						$newroot['queue'] = array();
						$newroot['queue'][] = $copycfg;
						config_set_path('shaper/queue/', $newroot);
					}

					if (write_config("Traffic Shaper: Added new queue")) {
						mark_subsystem_dirty('shaper');
					}

					break;
					}
				}

			header("Location: firewall_shaper_queues.php?queue=".$qname."&action=show");
			exit;
		case "show":
			$selected = $qname;
			foreach (config_get_path('interfaces', []) as $if => $ifdesc) {
				$altq = $altq_list_queues[$if];

				if ($altq) {
					$qtmp =& $altq->find_queue("", $qname);

					if ($qtmp) {
						$rows[] = ['if' => $if, 'altq' => $altq, 'queue' => $qtmp];
					} else {
						$rows[] = ['if' => $if, 'altq' => $altq, 'queue' => null];
					}
					unset($qtmp);
				} else {
					if (!is_altq_capable($ifdesc['if'])) {
						continue;
					}

					if (!isset($ifdesc['enable']) && $if != "lan" && $if != "wan") {
						continue;
					}

					$rows[] = ['if' => $if, 'altq' => null, 'queue' => null];
				}
			}
		break;
	}
}

if ($_POST['apply']) {
	write_config("Traffic Shaper: Changes applied");

	$retval = 0;
	/* Setup pf rules since the user may have changed the optimization value */
	$retval |= filter_configure();

	/* reset rrd queues */
	system("rm -f /var/db/rrd/*queuedrops.rrd");
	system("rm -f /var/db/rrd/*queues.rrd");
	enable_rrd_graphing();

	clear_subsystem_dirty('shaper');
}

$pgtitle = array(gettext("Firewall"), gettext("Traffic Shaper"), gettext("By Queue"));
$pglinks = array("", "firewall_shaper.php", "@self");
$shortcut_section = "trafficshaper";

$shaper_units = ['b' => gettext('bit/s'), 'Kb' => gettext('Kbit/s'), 'Mb' => gettext('Mbit/s'), 'Gb' => gettext('Gbit/s'), '%' => '%'];
$shaper_bw = function ($q) use ($shaper_units) {
	if ($q instanceof altq_root_queue) {
		$bw = $q->GetBandwidth();
		$type = $q->bandwidthtype;
	} else {
		$bw = $q->GetBandwidth();
		$type = $q->qbandwidthtype;
	}
	$bw = trim((string)$bw);
	if ($bw === '') {
		return '';
	}
	return ($type === '%') ? $bw . '%' : $bw . ' ' . ($shaper_units[$type] ?? $type);
};

$nshaped = is_array($altq_list_queues) ? count(array_filter($altq_list_queues)) : 0;
$present = count(array_filter($rows, function ($r) { return $r['queue'] !== null; }));

if (isAllowedPage('status_queues.php')) {
	fs_page_action(gettext('Queue status'), 'status_queues.php', 'fa-chart-line', 'secondary');
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('shaper')) {
	print_apply_box(gettext("The traffic shaper configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('firewall-shaper', 'firewall_shaper_queues.php');

?>

<style>
.fs-shaper-nav .panel-body { padding: var(--fs-sp-3); }
.fs-shaper-none { margin: 0; padding: var(--fs-sp-1) var(--fs-sp-2); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-shaper-empty .fs-tool-empty { text-align: center; }
.fs-shaper-empty .fs-tool-empty p { max-width: 32rem; margin: 0; }
.fs-shaper-empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--fs-sp-2); margin-top: var(--fs-sp-2); }
tr.fs-shaper-missing > td:not(:last-child) { color: var(--fs-text-muted); }
</style>

<form action="firewall_shaper_queues.php" method="post" name="iform" id="iform">
<div class="fs-tool fs-shaper">
	<div class="panel panel-default fs-shaper-nav">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Queues')?></h2></div>
		<div class="panel-body">
<?php if ($qlist): ?>
			<ul class="tree">
<?php	foreach ($qlist as $q => $qkey): ?>
				<li><a href="firewall_shaper_queues.php?queue=<?=fs_h($q)?>&amp;action=show"><?=fs_h($shaperIFlist[$q] ?? $q)?></a></li>
<?php	endforeach; ?>
			</ul>
<?php else: ?>
			<p class="fs-shaper-none"><?=gettext('No queues yet.')?></p>
<?php endif; ?>
		</div>
	</div>

	<div class="fs-tool-stack">
<?php
if ($selected !== '') {
	$sel_name = $shaperIFlist[$selected] ?? $selected;
	fs_summary_card([
		'icon' => 'fa-layer-group',
		'title' => $sel_name,
		'subtitle' => sprintf(gettext('On %1$d of %2$d interfaces'), $present, count($rows)),
		'badges' => [$present ? fs_badge('active', gettext('In use')) : fs_badge('neutral', gettext('Not in use'))],
		'label' => gettext('Queue summary'),
	]);
?>
		<div class="panel panel-default fs-table">
<?php	fs_table_toolbar([
		'title' => gettext('Interfaces'),
		'search' => false,
		'noun' => gettext('interfaces'),
		'noun_one' => gettext('interface'),
	]); ?>
			<div class="panel-body table-responsive">
				<table class="table table-hover">
					<thead>
						<tr>
							<th><?=gettext('Interface')?></th>
							<th><?=gettext('Scheduler')?></th>
							<th><?=gettext('Bandwidth')?></th>
							<th><?=gettext('Priority')?></th>
							<th><?=gettext('Status')?></th>
							<th class="text-end"><?=gettext('Actions')?></th>
						</tr>
					</thead>
					<tbody>
<?php	foreach ($rows as $r):
		$if = $r['if'];
		$ifname = $shaperIFlist[$if] ?? $if;
		$q = $r['queue'];
		$sched = $r['altq'] ? $r['altq']->GetScheduler() : '';
		if ($q) {
			$qif = $q->GetInterface();
			$edit = 'firewall_shaper.php?interface=' . $qif . '&queue=' . $q->GetQname() . '&action=show';
			$is_root = ($q instanceof altq_root_queue);
			$actions = [
				['edit', $edit, $q->GetQname()],
				['custom', 'firewall_shaper_queues.php?interface=' . $qif . '&queue=' . $q->GetQname() . '&action=delete', $q->GetQname(), [
					'icon' => 'fa-trash-can',
					'label' => $is_root ? sprintf(gettext('Remove the shaper from %s'), $ifname) : sprintf(gettext('Delete the queue from %s'), $ifname),
					'confirm' => $is_root ? sprintf(gettext('Remove the shaper from %s?'), $ifname) : sprintf(gettext('Delete queue “%1$s” from %2$s?'), $q->GetQname(), $ifname),
					'detail' => $is_root ? gettext('All queues on this interface are deleted.') : gettext('Its child queues on this interface are deleted too.'),
					'confirm_action' => $is_root ? gettext('Remove') : gettext('Delete'),
					'attrs' => ['class' => 'fs-action fs-action--delete'],
				]],
			];
		} else {
			$edit = $r['altq'] ? 'firewall_shaper.php?interface=' . $if . '&queue=' . $if . '&action=show' : '';
			$actions = [
				['custom', 'firewall_shaper_queues.php?interface=' . $if . '&queue=' . $selected . '&action=add', $selected, [
					'icon' => 'fa-clone',
					'label' => sprintf(gettext('Clone the queue to %s'), $ifname),
				]],
			];
		}
?>
						<tr<?=$q ? '' : ' class="fs-shaper-missing"'?>>
							<td><?=($edit !== '') ? '<a href="' . fs_h($edit) . '"><strong>' . fs_h($ifname) . '</strong></a>' : '<strong>' . fs_h($ifname) . '</strong>'?></td>
							<td><?=($sched !== '') ? '<span class="fs-chip fs-chip--strong">' . fs_h($sched) . '</span>' : '<span class="fs-muted">' . gettext('No shaper') . '</span>'?></td>
							<td class="fs-mono"><?=$q ? (fs_h($shaper_bw($q)) ?: '<span class="fs-muted">' . gettext('Not set') . '</span>') : ''?></td>
							<td>
<?php		if ($q && !($q instanceof altq_root_queue)): ?>
								<span class="fs-mono"><?=fs_h($q->GetQpriority())?></span>
<?php			if ($q->GetDefault() != ''): ?>
								<?=fs_badge('info', gettext('Default'))?>
<?php			endif; ?>
<?php		endif; ?>
							</td>
							<td><?=$q ? fs_badge($q->GetEnabled() ? 'enabled' : 'disabled') : fs_badge('neutral', gettext('Not on this interface'))?></td>
							<td class="text-end"><?=fs_row_actions($actions)?></td>
						</tr>
<?php	endforeach; ?>
<?php	if (!$rows) {
		fs_empty_row(6, gettext('No interface can use ALTQ traffic shaping.'));
	} ?>
					</tbody>
				</table>
			</div>
			<div class="panel-footer small fs-muted">
				<?=gettext('Clone copies the queue and its settings to an interface that does not have it yet.')?>
			</div>
		</div>
<?php
} elseif ($qlist) {
?>
		<div class="panel panel-default">
			<div class="fs-tool-empty">
				<i class="fa-solid fa-layer-group" aria-hidden="true"></i>
				<span><?=gettext('Select a queue to see it on every interface and clone it where it is missing.')?></span>
			</div>
		</div>
<?php
} elseif (empty(get_interface_list_to_show()) && !$nshaped) {
?>
		<div class="panel panel-default fs-shaper-empty">
			<div class="fs-tool-empty">
				<i class="fa-solid fa-sliders" aria-hidden="true"></i>
				<strong><?=gettext('No interface can use ALTQ traffic shaping.')?></strong>
				<p><?=gettext('None of the assigned interfaces has a driver that supports ALTQ queues. Limiters work on every interface.')?></p>
				<div class="fs-shaper-empty-actions">
					<a class="btn btn-primary" href="firewall_shaper_vinterface.php"><i class="fa-solid fa-gauge-high icon-embed-btn" aria-hidden="true"></i><?=gettext('Use limiters')?></a>
				</div>
			</div>
		</div>
<?php
} else {
?>
		<div class="panel panel-default fs-shaper-empty">
			<div class="fs-tool-empty">
				<i class="fa-solid fa-layer-group" aria-hidden="true"></i>
				<strong><?=gettext('No queues yet.')?></strong>
				<p><?=gettext('Queues are created on the By Interface tab or by a wizard. This view then lists each queue name across all interfaces.')?></p>
				<div class="fs-shaper-empty-actions">
					<a class="btn btn-primary" href="firewall_shaper_wizards.php"><i class="fa-solid fa-wand-magic-sparkles icon-embed-btn" aria-hidden="true"></i><?=gettext('Run a wizard')?></a>
					<a class="btn btn-outline-secondary" href="firewall_shaper.php"><i class="fa-solid fa-sliders icon-embed-btn" aria-hidden="true"></i><?=gettext('Shape by interface')?></a>
				</div>
			</div>
		</div>
<?php
}
?>
	</div>
</div>
</form>

<?php
include("foot.inc");
