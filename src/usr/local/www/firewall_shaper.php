<?php
/*
 * firewall_shaper.php
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
##|*IDENT=page-firewall-trafficshaper
##|*NAME=Firewall: Traffic Shaper
##|*DESCR=Allow access to the 'Firewall: Traffic Shaper' page.
##|*MATCH=firewall_shaper.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("rrd.inc");

$pgtitle = array(gettext("Firewall"), gettext("Traffic Shaper"), gettext("By Interface"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "trafficshaper";

$shaperIFlist = get_configured_interface_with_descr(true);
read_altq_config();
/*
 * The whole logic in these code maybe can be specified.
 * If you find a better way contact me :).
 */

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
}

if ($_POST) {
	if ($_POST['name']) {
		$qname = htmlspecialchars(trim($_POST['name']));
	}
	if ($_POST['interface']) {
		$interface = htmlspecialchars(trim($_POST['interface']));
	}
	if ($_POST['parentqueue']) {
		$parentqueue = htmlspecialchars(trim($_POST['parentqueue']));
	}
}

if ($interface) {
	$altq = $altq_list_queues[$interface];

	if ($altq) {
		$queue =& $altq->find_queue($interface, $qname);
	} else {
		$addnewaltq = true;
	}
}

$dontshow = false;
$newqueue = false;
$dfltmsg = false;

if ($_GET) {
	switch ($action) {
	case "delete":
		if ($queue) {
			$queue->delete_queue();
			if (write_config("Traffic Shaper: Item deleted")) {
				mark_subsystem_dirty('shaper');
			}
		}

		header("Location: firewall_shaper.php");
		exit;
	case "resetall":
		foreach ($altq_list_queues as $altq) {
			$altq->delete_all();
		}
		unset($altq_list_queues);
		$altq_list_queues = array();
		$tree = "<ul class=\"tree\" >";
		$tree .= get_interface_list_to_show();
		$tree .= "</ul>";
		config_del_path('shaper/queue');
		unset($queue);
		unset($altq);
		$can_add = false;
		$can_enable = false;
		$dontshow = true;
		// remove_filter_rules
		$remove_list = [];
		foreach (get_filter_rules_list() as $key => $rule) {
			if (isset($rule['wizard']) && $rule['wizard'] == "yes") {
				$remove_list[] = $key;
			}
		}
		remove_filter_rules($remove_list);

		if (write_config("Traffic Shaper: Reset all")) {
			$changes_applied = true;
			$retval = 0;
			$retval |= filter_configure();
		} else {
			$no_write_config_msg = gettext("Unable to write config.xml (Access Denied?).");
		}

		$dfltmsg = true;


		break;

	case "add":
		/* XXX: Find better way because we shouldn't know about this */
		if ($altq) {

			switch ($altq->GetScheduler()) {
			case "PRIQ":
				$q = new priq_queue();
				break;
			case "FAIRQ":
				$q = new fairq_queue();
				break;
			case "HFSC":
				$q = new hfsc_queue();
				break;
			case "CBQ":
				$q = new cbq_queue();
				break;
			default:
				/* XXX: Happens when sched==NONE?! */
				$q = new altq_root_queue();
				break;
			}
		} else if ($addnewaltq) {
			$q = new altq_root_queue();
		} else {
			$input_errors[] = gettext("Could not create new queue/discipline! Any recent changes may need to be applied first.");
		}

		if ($q) {
			$q->SetInterface($interface);
			$sform = $q->build_form();
			$sform->addGlobal(new Form_Input(
			    'parentqueue',
			    null,
			    'hidden',
			    $qname
			    ));

			$newjavascript = $q->build_javascript();
			unset($q);
			$newqueue = true;
		}
		break;
	case "show":
		if ($queue) {
			$sform = $queue->build_form();
		} else {
			$input_errors[] = gettext("Queue not found!");
		}
		break;
	case "enable":
		if ($queue) {
			$queue->SetEnabled("on");
			$sform = $queue->build_form();
			if (write_config("Traffic Shaper: Queue enabled")) {
				mark_subsystem_dirty('shaper');
			}
		} else {
			$input_errors[] = gettext("Queue not found!");
		}
		break;
	case "disable":
		if ($queue) {
			$queue->SetEnabled("");
			$sform = $queue->build_form();
			if (write_config("Traffic Shaper: Queue disabled")) {
				mark_subsystem_dirty('shaper');
			}
		} else {
			$input_errors[] = gettext("Queue not found!");
		}
		break;
	default:
		$dfltmsg = true;
		$dontshow = true;
		break;
	}
}

if ($_POST) {
	unset($input_errors);

	if ($addnewaltq) {
		$__tmp_altq = new altq_root_queue(); $altq =& $__tmp_altq;
		$altq->SetInterface($interface);
		$altq->ReadConfig($_POST);
		$altq->validate_input($_POST, $input_errors);
		if (!$input_errors) {
			unset($tmppath);
			$tmppath = array();
			array_push($tmppath, shaper_config_get_next_queue_index($tmppath));
			$altq->SetLink($tmppath);
			$altq->wconfig();
			if (write_config("Traffic Shaper: Added root queue")) {
				mark_subsystem_dirty('shaper');
			}
			$can_enable = true;
			$can_add = true;
		}

		read_altq_config();
		$sform = $altq->build_form();
	} else if ($parentqueue) { /* Add a new queue */
		$qtmp =& $altq->find_queue($interface, $parentqueue);
		if ($qtmp) {
			$tmppath =& $qtmp->GetLink();
			array_push($tmppath, shaper_config_get_next_queue_index($tmppath));
			$tmp =& $qtmp->add_queue($interface, $_POST, $tmppath, $input_errors);
			if (!$input_errors) {
				array_pop($tmppath);
				$tmp->wconfig();
				$can_enable = true;
				if ($tmp->CanHaveChildren() && $can_enable) {
					if ($tmp->GetDefault() <> "") {
						$can_add = false;
					} else {
						$can_add = true;
					}
				} else {
					$can_add = false;
				}
				if (write_config("Traffic Shaper: Added new queue")) {
					mark_subsystem_dirty('shaper');
				}
				$can_enable = true;
				if ($altq->GetScheduler() != "PRIQ") { /* XXX */
					if ($tmp->GetDefault() <> "") {
						$can_add = false;
					} else {
						$can_add = true;
					}
				}
			}
			read_altq_config();
			$sform = $tmp->build_form();
		} else {
			$input_errors[] = gettext("Could not add new queue.");
		}
	} else if ($_POST['apply']) {
		write_config("Traffic Shaper: Apply changes");
		$changes_applied = true;
		$retval = 0;
		$retval |= filter_configure();

		/* reset rrd queues */
		system("rm -f /var/db/rrd/*queuedrops.rrd");
		system("rm -f /var/db/rrd/*queues.rrd");
		enable_rrd_graphing();

		clear_subsystem_dirty('shaper');

		if ($queue) {
			$sform = $queue->build_form();
			$dontshow = false;
		} else {
			$sform = $default_shaper_message;
			$dontshow = true;
		}
	} else if ($queue) {
		$queue->validate_input($_POST, $input_errors);
		if (!$input_errors) {
			$queue->update_altq_queue_data($_POST);
			$queue->wconfig();
			if (write_config("Traffic Shaper: Changed queue")) {
				mark_subsystem_dirty('shaper');
			}
			$dontshow = false;
		}
		read_altq_config();
		$sform = $queue->build_form();
	} else	{
		$dfltmsg = true;
		$dontshow = true;
	}
	mwexec("/usr/bin/killall -q qstats");
}

if (!$_POST && !$_GET) {
	$dfltmsg = true;
	$dontshow = true;
}

if ($queue) {
	if ($queue->GetEnabled()) {
		$can_enable = true;
	} else {
		$can_enable = false;
	}
	if ($queue->CanHaveChildren() && $can_enable) {
		if ($altq->GetQname() <> $queue->GetQname() && $queue->GetDefault() <> "") {
			$can_add = false;
		} else {
			$can_add = true;
		}
	} else {
		$can_add = false;
	}
}

/* Shaped interfaces (left tree) and interfaces that can still get a shaper */
$shaped = is_array($altq_list_queues) ? array_filter($altq_list_queues) : [];
$available = [];
foreach ($shaperIFlist as $shif => $shdescr) {
	if (!empty($shaped[$shif]) || !is_altq_capable(get_real_interface($shif))) {
		continue;
	}
	$available[$shif] = $shdescr;
}

$shaper_units = ['b' => gettext('bit/s'), 'Kb' => gettext('Kbit/s'), 'Mb' => gettext('Mbit/s'), 'Gb' => gettext('Gbit/s'), '%' => '%'];
$shaper_bw = function ($bw, $type) use ($shaper_units) {
	$bw = trim((string)$bw);
	if ($bw === '') {
		return '';
	}
	return ($type === '%') ? $bw . '%' : $bw . ' ' . ($shaper_units[$type] ?? $type);
};
$shaper_children = function ($q) {
	if ($q instanceof altq_root_queue) {
		return is_array($q->queues) ? $q->queues : [];
	}
	return (isset($q->subqueues) && is_array($q->subqueues)) ? $q->subqueues : [];
};
$shaper_count = function ($q) use (&$shaper_count, $shaper_children) {
	$n = 0;
	foreach ($shaper_children($q) as $child) {
		$n += 1 + $shaper_count($child);
	}
	return $n;
};

if (isAllowedPage('status_queues.php')) {
	fs_page_action(gettext('Queue status'), 'status_queues.php', 'fa-chart-line', 'secondary');
}

include("head.inc");

if ($queue) {
	print($queue->build_javascript());
}

print($newjavascript);

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($no_write_config_msg) {
	print_info_box($no_write_config_msg, 'danger');
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('shaper')) {
	print_apply_box(gettext("The traffic shaper configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('firewall-shaper', 'firewall_shaper.php');

$show_form = (!$dfltmsg && $sform);
$ifdescr = $shaperIFlist[$interface] ?? $interface;
?>

<style>
.fs-shaper-nav .panel-body { padding: var(--fs-sp-3); }
.fs-shaper-nav .panel-footer { padding: var(--fs-sp-3); }
.fs-shaper-subhead { margin: var(--fs-sp-3) 0 var(--fs-sp-1); padding: 0 var(--fs-sp-2); color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.fs-shaper-subhead:first-child { margin-top: 0; }
.fs-shaper-none { margin: 0; padding: var(--fs-sp-1) var(--fs-sp-2); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-shaper-add { margin: 0; padding: 0; list-style: none; }
.fs-shaper-add a { display: flex; align-items: center; gap: var(--fs-sp-2); padding: .3rem .5rem; border-radius: var(--fs-r-sm); color: var(--fs-text); font-size: .9rem; text-decoration: none; }
.fs-shaper-add a:hover, .fs-shaper-add a.is-active { background: var(--fs-accent-tint); color: var(--fs-text-strong); }
.fs-shaper-add i { width: 1rem; color: var(--fs-coral-text); font-size: var(--fs-fs-xs); text-align: center; }
.fs-shaper-empty .fs-tool-empty { text-align: center; }
.fs-shaper-empty .fs-tool-empty p { max-width: 32rem; margin: 0; }
.fs-shaper-empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--fs-sp-2); margin-top: var(--fs-sp-2); }
</style>

<div class="fs-tool fs-shaper">
	<div class="panel panel-default fs-shaper-nav">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Interfaces')?></h2></div>
		<div class="panel-body">
			<h3 class="fs-shaper-subhead"><?=gettext('Shaped')?></h3>
<?php if ($shaped): ?>
			<ul class="tree">
<?php
	foreach ($shaped as $tmpaltq) {
		/* leaf queues come with an empty child list; drop it so they get no caret */
		print($tmpaltq->build_tree());
	}
?>
			</ul>
<?php else: ?>
			<p class="fs-shaper-none"><?=gettext('No interface is shaped yet.')?></p>
<?php endif; ?>
<?php if ($available): ?>
			<h3 class="fs-shaper-subhead"><?=gettext('Add a shaper')?></h3>
			<ul class="fs-shaper-add">
<?php foreach ($available as $shif => $shdescr): ?>
				<li><a href="firewall_shaper.php?interface=<?=fs_h($shif)?>&amp;action=add"<?=($newqueue && $addnewaltq && $interface === $shif) ? ' class="is-active" aria-current="page"' : ''?>><i class="fa-solid fa-plus" aria-hidden="true"></i><?=fs_h($shdescr)?></a></li>
<?php endforeach; ?>
			</ul>
<?php endif; ?>
		</div>
<?php if (count($altq_list_queues) > 0): ?>
		<div class="panel-footer">
			<a href="firewall_shaper.php?action=resetall" class="btn btn-sm btn-outline-danger"
			   data-fs-confirm="<?=fs_h(gettext('Remove the traffic shaper?'))?>"
			   data-fs-confirm-detail="<?=fs_h(gettext('All queues on every interface are deleted, together with the firewall rules a shaper wizard created. The firewall rules are reloaded right away.'))?>"
			   data-fs-confirm-action="<?=fs_h(gettext('Remove shaper'))?>">
				<i class="fa-solid fa-trash-can icon-embed-btn" aria-hidden="true"></i><?=gettext('Remove shaper')?>
			</a>
		</div>
<?php endif; ?>
	</div>

	<div class="fs-tool-stack">
<?php
if ($show_form) {
	/* What is being edited: a saved root/child queue, or a new one */
	$subject = null;
	if (!$newqueue && !($_POST && ($addnewaltq || $parentqueue) && $input_errors)) {
		if ($queue) {
			$subject = $queue;
		} elseif ($_POST && $parentqueue && isset($tmp) && is_object($tmp)) {
			$subject = $tmp;
		} elseif ($_POST && $addnewaltq && is_object($altq)) {
			$subject = $altq;
		}
	}
	$root = ($altq && is_object($altq)) ? $altq : null;
	$scheduler = $root ? $root->GetScheduler() : '';

	if ($subject instanceof altq_root_queue) {
		$queues = [];
		foreach ($shaper_children($subject) as $child) {
			$queues[] = $child->GetQname();
		}
		fs_summary_card([
			'icon' => 'fa-sliders',
			'title' => $shaperIFlist[$subject->GetInterface()] ?? $subject->GetInterface(),
			'subtitle' => gettext('Interface shaper'),
			'badges' => [fs_badge($subject->GetEnabled() ? 'enabled' : 'disabled')],
			'meta' => $subject->GetScheduler(),
			'label' => gettext('Shaper summary'),
			'facts' => [
				[gettext('Scheduler'), $subject->GetScheduler()],
				[gettext('Bandwidth'), $shaper_bw($subject->GetBandwidth(), $subject->bandwidthtype), 'mono' => true, 'empty' => gettext('Interface speed')],
				[gettext('Queues'), '', 'chips' => $queues, 'empty' => gettext('None yet')],
				[gettext('Queue limit'), (string)$subject->GetQlimit(), 'mono' => true, 'empty' => gettext('Default')],
			],
		]);
	} elseif ($subject) {
		$parent = $subject->GetParent();
		$badges = [fs_badge($subject->GetEnabled() ? 'enabled' : 'disabled')];
		if ($subject->GetDefault() != '') {
			$badges[] = fs_badge('info', gettext('Default queue'));
		}
		$facts = [
			[gettext('Interface'), $shaperIFlist[$subject->GetInterface()] ?? $subject->GetInterface(),
			    'href' => 'firewall_shaper.php?interface=' . $subject->GetInterface() . '&queue=' . $subject->GetInterface() . '&action=show', 'note' => $scheduler],
			[gettext('Bandwidth'), $shaper_bw($subject->GetBandwidth(), $subject->qbandwidthtype), 'mono' => true, 'empty' => gettext('Not set')],
			[gettext('Priority'), (string)$subject->GetQpriority(), 'mono' => true],
		];
		if (is_object($parent) && !($parent instanceof altq_root_queue)) {
			array_splice($facts, 1, 0, [[gettext('Parent queue'), $parent->GetQname(),
			    'href' => 'firewall_shaper.php?interface=' . $subject->GetInterface() . '&queue=' . $parent->GetQname() . '&action=show']]);
		}
		if ($subject->CanHaveChildren()) {
			$facts[] = [gettext('Child queues'), (string)$shaper_count($subject)];
		}
		fs_summary_card([
			'icon' => 'fa-layer-group',
			'title' => $subject->GetQname(),
			'subtitle' => (string)$subject->GetDescription(),
			'badges' => $badges,
			'label' => gettext('Queue summary'),
			'facts' => $facts,
		]);
	} else {
		$adding_root = ($addnewaltq || !$root);
		fs_summary_card([
			'icon' => $adding_root ? 'fa-sliders' : 'fa-layer-group',
			'title' => $adding_root ? $ifdescr : '',
			'placeholder' => gettext('New queue'),
			'subtitle' => $adding_root ? gettext('New interface shaper: choose the scheduler and the bandwidth of the link.')
			    : sprintf(gettext('New queue under %1$s on %2$s'), (($qname != '' && $qname !== $interface) ? $qname : gettext('the root queue')), $ifdescr),
			'badges' => [fs_badge('info', gettext('New'))],
			'meta' => $adding_root ? '' : $scheduler,
			'label' => gettext('Queue summary'),
		]);
	}

	// Add global buttons
	if (!$dontshow || $newqueue) {
		if ($can_add || $addnewaltq) {
			if ($queue) {
				$url = 'firewall_shaper.php?interface='. $interface . '&queue=' . $queue->GetQname() . '&action=add';
			} else {
				$url = 'firewall_shaper.php?interface='. $interface . '&action=add';
			}

			$sform->addGlobal(new Form_Button(
				'add',
				gettext('Add child queue'),
				$url,
				'fa-solid fa-plus'
			))->removeClass('btn-secondary')->addClass('btn-outline-secondary');

		}

		if ($queue) {
			$url = 'firewall_shaper.php?interface='. $interface . '&queue=' . $queue->GetQname() . '&action=delete';
		} else {
			$url = 'firewall_shaper.php?interface='. $interface . '&action=delete';
		}

		$is_root_delete = (!$queue || ($queue instanceof altq_root_queue));
		$delete = new Form_Button(
			'delete',
			$is_root_delete ? gettext('Remove shaper from interface') : gettext('Delete queue'),
			$url,
			'fa-solid fa-trash-can'
		);
		$delete->removeClass('btn-secondary')->addClass('btn-outline-danger', 'nowarn');
		$delete->setAttribute('data-fs-confirm', $is_root_delete
		    ? gettext('Remove the shaper from this interface?')
		    : gettext('Delete this queue?'));
		$delete->setAttribute('data-fs-confirm-detail', $is_root_delete
		    ? gettext('All queues on this interface are deleted.')
		    : gettext('Its child queues are deleted too. Firewall rules that use it lose their queue assignment.'));
		$delete->setAttribute('data-fs-confirm-action', $is_root_delete ? gettext('Remove') : gettext('Delete'));
		$sform->addGlobal($delete);
	}

	fs_form_cancel($sform, 'firewall_shaper.php');
	/* The queue form is built by shaper.inc from the saved or posted queue; the Form classes escape every value. */
	print($sform); // nosemgrep: php.lang.security.injection.printed-request.printed-request
} elseif ($shaped) {
	/* Overview of the shaped interfaces */
?>
		<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Shaped interfaces'),
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
							<th><?=gettext('Queues')?></th>
							<th><?=gettext('Status')?></th>
							<th class="text-end"><?=gettext('Actions')?></th>
						</tr>
					</thead>
					<tbody>
<?php foreach ($shaped as $shif => $tmpaltq):
	$name = $shaperIFlist[$shif] ?? $shif;
	$show = 'firewall_shaper.php?interface=' . $shif . '&queue=' . $shif . '&action=show';
	$actions = [['edit', $show, $name]];
	if ($tmpaltq->GetEnabled() && $tmpaltq->CanHaveChildren()) {
		$actions[] = ['custom', 'firewall_shaper.php?interface=' . $shif . '&queue=' . $shif . '&action=add', $name,
		    ['icon' => 'fa-plus', 'label' => sprintf(gettext('Add a queue on %s'), $name)]];
	}
?>
						<tr>
							<td><a href="<?=fs_h($show)?>"><strong><?=fs_h($name)?></strong></a></td>
							<td><span class="fs-chip fs-chip--strong"><?=fs_h($tmpaltq->GetScheduler())?></span></td>
							<td class="fs-mono"><?=fs_h($shaper_bw($tmpaltq->GetBandwidth(), $tmpaltq->bandwidthtype)) ?: '<span class="fs-muted">' . gettext('Interface speed') . '</span>'?></td>
							<td>
								<span class="fs-chips">
<?php	foreach ($shaper_children($tmpaltq) as $child): ?>
									<a class="fs-chip fs-chip--mono<?=$child->GetEnabled() ? '' : ' is-off'?>" href="<?=fs_h('firewall_shaper.php?interface=' . $shif . '&queue=' . $child->GetQname() . '&action=show')?>"<?=$child->GetDefault() != '' ? ' title="' . fs_h(gettext('Default queue')) . '"' : ''?>><?=fs_h($child->GetQname())?><?=$child->GetDefault() != '' ? ' <i class="fa-solid fa-star" aria-hidden="true"></i>' : ''?></a>
<?php	endforeach; ?>
								</span>
<?php	if (!$shaper_children($tmpaltq)): ?>
								<span class="fs-muted"><?=gettext('None yet')?></span>
<?php	else: ?>
								<span class="fs-muted small"><?=sprintf(gettext('%d in total'), $shaper_count($tmpaltq))?></span>
<?php	endif; ?>
							</td>
							<td><?=fs_badge($tmpaltq->GetEnabled() ? 'enabled' : 'disabled')?></td>
							<td class="text-end"><?=fs_row_actions($actions)?></td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="panel-footer small fs-muted">
				<?=gettext('Pick an interface or a queue in the tree to edit it. A star marks the default queue of an interface.')?>
			</div>
		</div>
<?php
} elseif ($available) {
	$first = array_key_first($available);
?>
		<div class="panel panel-default fs-shaper-empty">
			<div class="fs-tool-empty">
				<i class="fa-solid fa-sliders" aria-hidden="true"></i>
				<strong><?=gettext('No traffic shaper is configured.')?></strong>
				<p><?=gettext('Run a wizard to create a ready-made set of queues, or add a shaper to an interface and build the queues by hand.')?></p>
				<div class="fs-shaper-empty-actions">
					<a class="btn btn-primary" href="firewall_shaper_wizards.php"><i class="fa-solid fa-wand-magic-sparkles icon-embed-btn" aria-hidden="true"></i><?=gettext('Run a wizard')?></a>
					<a class="btn btn-outline-secondary" href="firewall_shaper.php?interface=<?=fs_h($first)?>&amp;action=add"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=fs_h(sprintf(gettext('Add a shaper on %s'), $available[$first]))?></a>
				</div>
			</div>
		</div>
<?php
} else {
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
}
?>
	</div>
</div>

<?php
include("foot.inc");
