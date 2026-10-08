<?php
/*
 * firewall_shaper_vinterface.php
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
##|*IDENT=page-firewall-trafficshaper-limiter
##|*NAME=Firewall: Traffic Shaper: Limiters
##|*DESCR=Allow access to the 'Firewall: Traffic Shaper: Limiters' page.
##|*MATCH=firewall_shaper_vinterface.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");

$pgtitle = array(gettext("Firewall"), gettext("Traffic Shaper"), gettext("Limiters"));
$pglinks = array("", "firewall_shaper.php", "@self");
$shortcut_section = "trafficshaper-limiters";
$dfltmsg = false;

read_dummynet_config();
/*
 * The whole logic in these code maybe can be specified.
 * If you find a better way contact me :).
 */

if ($_GET) {
	if ($_GET['queue']) {
		$qname = htmlspecialchars(trim($_GET['queue']));
	}
	if ($_GET['pipe']) {
		$pipe = htmlspecialchars(trim($_GET['pipe']));
	}
	if ($_GET['action']) {
		$action = htmlspecialchars($_GET['action']);
		$addnewpipe = ($action == 'add');
	}
}

if ($_POST) {
	if ($_POST['name']) {
		$qname = htmlspecialchars(trim($_POST['name']));
	}
	if ($_POST['newname']) {
		$newname = 	htmlspecialchars(trim($_POST['newname']));
		if (!$_POST['name']) {
			$qname = $newname;
			$addnewpipe = (!isset($_POST['apply']) && !$_POST['parentqueue']);
		}
	}
	if ($_POST['pipe']) {
		$pipe = htmlspecialchars(trim($_POST['pipe']));
	} else {
		$pipe = htmlspecialchars(trim($qname));
	}
	if ($_POST['parentqueue']) {
		$parentqueue = htmlspecialchars(trim($_POST['parentqueue']));
	}
}

if ($pipe) {
	$dnpipe = $dummynet_pipe_list[$pipe];
	if ($dnpipe) {
		$queue =& $dnpipe->find_queue($pipe, $qname);
	} else {
		$addnewpipe = true;
	}
}

$dontshow = false;
$newqueue = false;
$output_form = "";

if ($_GET) {
	switch ($action) {
		case "delete":
			if ($queue) {
				$queue->delete_queue();
				if (write_config("Traffic Shaper: Queue deleted")) {
					mark_subsystem_dirty('shaper');
				}
				header("Location: firewall_shaper_vinterface.php");
				exit;
			} else {
				$input_errors[] = sprintf(gettext("No queue with name %s was found!"), $qname);
				$output_form .= $dn_default_shaper_msg;
				$dontshow = true;
			}
			break;
		case "resetall":
			foreach ($dummynet_pipe_list as $dn) {
				$dn->delete_queue();
			}
			unset($dummynet_pipe_list);
			$dummynet_pipe_list = array();
			config_del_path('dnshaper/queue');
			unset($queue);
			unset($pipe);
			$can_add = false;
			$can_enable = false;
			$dontshow = true;
			foreach (get_filter_rules_list() as $key => $rule) {
				if (isset($rule['dnpipe'])) {
					config_del_path("filter/rule/{$key}/dnpipe");
				}
				if (isset($rule['pdnpipe'])) {
					config_del_path("filter/rule/{$key}/pdnpipe");
				}
			}
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
		if ($dnpipe) {
			$q = new dnqueue_class();
			$q->SetPipe($pipe);
		} else if ($addnewpipe) {
			$q = new dnpipe_class();
			$q->SetQname($pipe);
		} else {
			$input_errors[] = gettext("Could not create new queue/discipline!");
		}

		if ($q) {
			$sform = $q->build_form();
			if ($dnpipe) {
				$sform->addGlobal(new Form_Input(
					'parentqueue',
					null,
					'hidden',
					$pipe
				));
			}
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
			$queue->wconfig();
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
			$queue->wconfig();
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

	if ($addnewpipe) {
		foreach ($dummynet_pipe_list as $dn) {
			if ($dn->GetQname() == $newname) {
				$input_errors[] = gettext("Cannot have duplicate limiter names.");
				break;
			}
			if (!is_array($dn->subqueues) || empty($dn->subqueues)) {
				continue;
			}
			foreach ($dn->subqueues as $subqueue) {
				if ($subqueue->GetQname() == $newname) {
					$input_errors[] = gettext("Limiters and child queues cannot have the same name.");
					break 2;
				}
			}
		}
		if (empty($input_errors)) {
			$__tmp_dnpipe = new dnpipe_class(); $dnpipe =& $__tmp_dnpipe;

			$dnpipe->ReadConfig($_POST);
			$dnpipe->validate_input($_POST, $input_errors);
			if (!$input_errors) {
				$number = dnpipe_find_nextnumber();
				$dnpipe->SetNumber($number);
				unset($tmppath);
				$tmppath = array();
				array_push($tmppath, shaper_dn_config_get_next_queue_index($tmppath));
				$dnpipe->SetLink($tmppath);
				$dnpipe->wconfig();
				if (write_config("Traffic Shaper: New pipe added")) {
					mark_subsystem_dirty('shaper');
					header("Location: firewall_shaper_vinterface.php");
					exit;
				}
				$can_enable = true;
				$can_add = true;
			}

			read_dummynet_config();
			$sform = $dnpipe->build_form();
			$newjavascript = $dnpipe->build_javascript();
		}
	} else if ($parentqueue) { /* Add a new queue */
		foreach ($dummynet_pipe_list as $dn) {
			if ($dn->GetQname() == $newname) {
				$input_errors[] = gettext("Limiters and child queues cannot have the same name.");
				break;
			}
		}
		if (empty($input_errors) && $dnpipe) {
			$tmppath =& $dnpipe->GetLink();
			array_push($tmppath, shaper_dn_config_get_next_queue_index($tmppath));
			$tmp =& $dnpipe->add_queue($pipe, $_POST, $tmppath, $input_errors);
			if (!$input_errors) {
				array_pop($tmppath);
				$tmp->wconfig();
				if (write_config("Traffic Shaper: New queue added")) {
					$can_enable = true;
					$can_add = false;
					mark_subsystem_dirty('shaper');
					header("Location: firewall_shaper_vinterface.php");
					exit;
				}
			}
			read_dummynet_config();
			$sform = $tmp->build_form();
		} else {
			$input_errors[] = gettext("Could not add new queue.");
		}
	} else if ($_POST['apply']) {
		write_config("Traffic Shaper: Changes applied");

		$changes_applied = true;
		$retval = 0;
		$retval |= filter_configure();

		/* XXX: TODO Make dummynet pretty graphs */
		//	enable_rrd_graphing();

		clear_subsystem_dirty('shaper');

		if ($queue) {
			$sform = $queue->build_form();
			$dontshow = false;
		} else {
			$output_form .= $dn_default_shaper_message;
			$dontshow = true;
		}

	} else if ($queue) {
		if ($queue->GetQname() != $newname) {
			foreach ($dummynet_pipe_list as $dn) {
				if ($dn->GetQname() == $newname) {
					$input_errors[] = gettext("Limiters and child queues cannot have the same name.");
					break;
				}
			}
		}

		if (!$input_errors) {
			$queue->validate_input($_POST, $input_errors);
		}
		if (!$input_errors) {
			$queue->update_dn_data($_POST);
			$queue->wconfig();
			if (write_config("Traffic Shaper: Queue changed")) {
				mark_subsystem_dirty('shaper');
				header("Location: firewall_shaper_vinterface.php");
				exit;
			}
			$dontshow = false;
		}
		read_dummynet_config();
		$sform = $queue->build_form();
	} else	{
		$dfltmsg = true;
		$dontshow = true;
	}
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
	if ($queue->CanHaveChildren()) {
		$can_add = true;
	} else {
		$can_add = false;
	}
}

$limiters = is_array($dummynet_pipe_list) ? array_filter($dummynet_pipe_list) : [];

$dn_units = ['b' => gettext('bit/s'), 'Kb' => gettext('Kbit/s'), 'Mb' => gettext('Mbit/s'), 'Gb' => gettext('Gbit/s')];
$dn_bw = function ($pipe) use ($dn_units) {
	$out = [];
	foreach ((is_array($pipe->GetBandwidth()) ? $pipe->GetBandwidth() : []) as $bw) {
		if (!is_array($bw) || trim((string)($bw['bw'] ?? '')) === '') {
			continue;
		}
		$text = trim($bw['bw']) . ' ' . ($dn_units[$bw['bwscale'] ?? ''] ?? ($bw['bwscale'] ?? ''));
		if (!empty($bw['bwsched']) && $bw['bwsched'] !== 'none') {
			$text .= ' (' . $bw['bwsched'] . ')';
		}
		$out[] = $text;
	}
	return implode(', ', $out);
};
$dn_mask = function ($q) {
	$mask = $q->GetMask();
	switch ($mask['type'] ?? 'none') {
		case 'srcaddress':
			$text = gettext('Source addresses');
			break;
		case 'dstaddress':
			$text = gettext('Destination addresses');
			break;
		default:
			return '';
	}
	$bits = [];
	if (($mask['bits'] ?? '') !== '') {
		$bits[] = '/' . $mask['bits'];
	}
	if (($mask['bitsv6'] ?? '') !== '') {
		$bits[] = '/' . $mask['bitsv6'];
	}
	return $bits ? $text . ' ' . implode(' ', $bits) : $text;
};
$dn_sched = function ($pipe) {
	$key = (string)$pipe->GetScheduler();
	$all = function_exists('getSchedulers') ? getSchedulers() : [];
	return ($key === '') ? '' : (string)($all[$key]['name'] ?? strtoupper($key));
};
$dn_aqm = function ($q) {
	$key = method_exists($q, 'GetAQM') ? (string)$q->GetAQM() : '';
	$all = function_exists('getAQMs') ? getAQMs() : [];
	return ($key === '') ? '' : (string)($all[$key]['name'] ?? $key);
};
$dn_children = function ($pipe) {
	return (isset($pipe->subqueues) && is_array($pipe->subqueues)) ? $pipe->subqueues : [];
};

fs_page_action(gettext('New limiter'), 'firewall_shaper_vinterface.php?action=add', 'fa-plus');

include("head.inc");
?>

<script type="text/javascript">
//<![CDATA[
function show_source_port_range() {
	document.getElementById("sprtable").style.display = '';
	document.getElementById("sprtable1").style.display = '';
	document.getElementById("sprtable2").style.display = '';
	document.getElementById("sprtable5").style.display = '';
	document.getElementById("sprtable4").style.display = 'none';
	document.getElementById("showadvancedboxspr").innerHTML='';
}
//]]>
</script>

<?php
if ($queue) {
	echo $queue->build_javascript();
} else {
	echo $newjavascript;
}

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

fs_tabs('firewall-shaper', 'firewall_shaper_vinterface.php');

$show_form = (!$dfltmsg && $sform);
?>

<style>
.fs-shaper-nav .panel-body { padding: var(--fs-sp-3); }
.fs-shaper-nav .panel-footer { padding: var(--fs-sp-3); }
.fs-shaper-none { margin: 0; padding: var(--fs-sp-1) var(--fs-sp-2); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
/* bandwidth schedule table built by shaper.inc: room for the row button */
.fs-shaper #maintable td.col-4 { width: 30%; }
.fs-shaper #maintable td:last-child { width: 1%; white-space: nowrap; }
.fs-shaper-empty .fs-tool-empty { text-align: center; }
.fs-shaper-empty .fs-tool-empty p { max-width: 32rem; margin: 0; }
.fs-shaper-empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--fs-sp-2); margin-top: var(--fs-sp-2); }
</style>

<div class="fs-tool fs-shaper">
	<div class="panel panel-default fs-shaper-nav">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Limiters')?></h2></div>
		<div class="panel-body">
<?php if ($limiters): ?>
			<ul class="tree">
<?php
	foreach ($limiters as $tmpdn) {
		/* limiters without queues come with an empty child list; drop it so they get no caret */
		print($tmpdn->build_tree());
	}
?>
			</ul>
<?php else: ?>
			<p class="fs-shaper-none"><?=gettext('No limiters yet.')?></p>
<?php endif; ?>
		</div>
		<div class="panel-footer">
			<a href="firewall_shaper_vinterface.php?action=add" class="btn btn-sm btn-outline-secondary">
				<i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=gettext('New limiter')?>
			</a>
		</div>
	</div>

	<div class="fs-tool-stack">
<?php
if ($show_form) {
	/* What is being edited: a saved limiter / limiter queue, or a new one */
	$is_new = ($newqueue || !$queue || ($_POST && ($addnewpipe || $parentqueue) && $input_errors));
	if (!$is_new && ($queue instanceof dnpipe_class)) {
		$queues = [];
		foreach ($dn_children($queue) as $child) {
			$queues[] = $child->GetQname();
		}
		fs_summary_card([
			'icon' => 'fa-gauge-high',
			'title' => $queue->GetQname(),
			'subtitle' => (string)$queue->GetDescription(),
			'badges' => [fs_badge($queue->GetEnabled() ? 'enabled' : 'disabled')],
			'meta' => sprintf(gettext('Pipe %s'), $queue->GetNumber()),
			'label' => gettext('Limiter summary'),
			'facts' => [
				[gettext('Bandwidth'), $dn_bw($queue), 'mono' => true, 'empty' => gettext('Not set')],
				[gettext('Mask'), $dn_mask($queue), 'empty' => gettext('None')],
				[gettext('Scheduler'), $dn_sched($queue), 'empty' => gettext('Default')],
				[gettext('Queues'), '', 'chips' => $queues, 'empty' => gettext('None yet')],
			],
		]);
	} elseif (!$is_new) {
		$parent = $queue->GetParent();
		$pname = is_object($parent) ? $parent->GetQname() : $pipe;
		fs_summary_card([
			'icon' => 'fa-layer-group',
			'title' => $queue->GetQname(),
			'subtitle' => (string)$queue->GetDescription(),
			'badges' => [fs_badge($queue->GetEnabled() ? 'enabled' : 'disabled')],
			'meta' => sprintf(gettext('Queue %s'), $queue->GetNumber()),
			'label' => gettext('Queue summary'),
			'facts' => [
				[gettext('Limiter'), $pname, 'href' => 'firewall_shaper_vinterface.php?pipe=' . $pname . '&queue=' . $pname . '&action=show',
				    'note' => is_object($parent) ? $dn_bw($parent) : ''],
				[gettext('Mask'), $dn_mask($queue), 'empty' => gettext('None')],
				[gettext('Queue management'), $dn_aqm($queue), 'empty' => gettext('Default')],
				[gettext('Weight'), (string)($queue->weight ?? ''), 'mono' => true, 'empty' => gettext('Default')],
			],
		]);
	} else {
		$adding_queue = ($dnpipe && !$addnewpipe);
		fs_summary_card([
			'icon' => $adding_queue ? 'fa-layer-group' : 'fa-gauge-high',
			'title' => '',
			'placeholder' => $adding_queue ? gettext('New queue') : gettext('New limiter'),
			'subtitle' => $adding_queue ? sprintf(gettext('Queue under limiter %s'), $pipe)
			    : gettext('A limiter caps the bandwidth of the traffic that firewall rules send into it.'),
			'badges' => [fs_badge('info', gettext('New'))],
			'label' => $adding_queue ? gettext('Queue summary') : gettext('Limiter summary'),
		]);
	}

	// Add global buttons
	if (!$dontshow || $newqueue) {
		if ($can_add && ($action != "add")) {
			if ($queue) {
				$url = 'firewall_shaper_vinterface.php?pipe=' . $pipe . '&queue=' . $queue->GetQname() . '&action=add';
			} else {
				$url = 'firewall_shaper_vinterface.php?pipe='. $pipe . '&action=add';
			}

			$sform->addGlobal(new Form_Button(
				'add',
				gettext('Add queue'),
				$url,
				'fa-solid fa-plus'
			))->removeClass('btn-secondary')->addClass('btn-outline-secondary');
		}

		if ($action != "add") {
			if ($queue) {
				$url = 'firewall_shaper_vinterface.php?pipe='. $pipe . '&queue=' . $queue->GetQname() . '&action=delete';
			} else {
				$url = 'firewall_shaper_vinterface.php?pipe='. $pipe . '&action=delete';
			}

			$is_queue = ($queue && ($qname != $pipe));
			$delete = new Form_Button(
				'delete',
				$is_queue ? gettext('Delete queue') : gettext('Delete limiter'),
				$url,
				'fa-solid fa-trash-can'
			);
			$delete->removeClass('btn-secondary')->addClass('btn-outline-danger', 'nowarn');
			$delete->setAttribute('data-fs-confirm', $is_queue
			    ? gettext('Delete this queue?')
			    : gettext('Delete this limiter?'));
			$delete->setAttribute('data-fs-confirm-detail', $is_queue
			    ? gettext('Firewall rules that use it lose their limiter assignment.')
			    : gettext('Its queues are deleted too. Firewall rules that use them lose their limiter assignment.'));
			$delete->setAttribute('data-fs-confirm-action', gettext('Delete'));
			$sform->addGlobal($delete);
		}
	}

	// Print the form
	$sform->setAction("firewall_shaper_vinterface.php");
	fs_form_cancel($sform, 'firewall_shaper_vinterface.php');
	/* The queue form is built by shaper.inc from the saved or posted queue; the Form classes escape every value. */
	print($sform); // nosemgrep: php.lang.security.injection.printed-request.printed-request
} elseif ($limiters) {
	/* Overview of the limiters */
?>
		<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Limiters'),
	'search' => (count($limiters) > 5) ? gettext('Search limiters…') : false,
	'noun' => gettext('limiters'),
	'noun_one' => gettext('limiter'),
]); ?>
			<div class="panel-body table-responsive">
				<table class="table table-hover">
					<thead>
						<tr>
							<th data-fs-search><?=gettext('Limiter')?></th>
							<th><?=gettext('Bandwidth')?></th>
							<th><?=gettext('Mask')?></th>
							<th data-fs-search><?=gettext('Queues')?></th>
							<th><?=gettext('Status')?></th>
							<th class="text-end"><?=gettext('Actions')?></th>
						</tr>
					</thead>
					<tbody>
<?php foreach ($limiters as $dnname => $tmpdn):
	$show = 'firewall_shaper_vinterface.php?pipe=' . $dnname . '&queue=' . $dnname . '&action=show';
	$actions = [
		['edit', $show, $dnname],
		['custom', 'firewall_shaper_vinterface.php?pipe=' . $dnname . '&action=add', $dnname,
		    ['icon' => 'fa-plus', 'label' => sprintf(gettext('Add a queue to %s'), $dnname)]],
	];
?>
						<tr>
							<td>
								<a href="<?=fs_h($show)?>"><strong><?=fs_h($dnname)?></strong></a>
<?php	if ((string)$tmpdn->GetDescription() !== ''): ?>
								<div class="fs-muted small"><?=fs_h($tmpdn->GetDescription())?></div>
<?php	endif; ?>
							</td>
							<td class="fs-mono"><?=fs_h($dn_bw($tmpdn)) ?: '<span class="fs-muted">' . gettext('Not set') . '</span>'?></td>
							<td><?=fs_h($dn_mask($tmpdn)) ?: '<span class="fs-muted">' . gettext('None') . '</span>'?></td>
							<td>
<?php	if ($dn_children($tmpdn)): ?>
								<span class="fs-chips">
<?php		foreach ($dn_children($tmpdn) as $child): ?>
									<a class="fs-chip fs-chip--mono<?=$child->GetEnabled() ? '' : ' is-off'?>" href="<?=fs_h('firewall_shaper_vinterface.php?pipe=' . $dnname . '&queue=' . $child->GetQname() . '&action=show')?>"><?=fs_h($child->GetQname())?></a>
<?php		endforeach; ?>
								</span>
<?php	else: ?>
								<span class="fs-muted"><?=gettext('None')?></span>
<?php	endif; ?>
							</td>
							<td><?=fs_badge($tmpdn->GetEnabled() ? 'enabled' : 'disabled')?></td>
							<td class="text-end"><?=fs_row_actions($actions)?></td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="panel-footer small fs-muted">
				<?=gettext('Assign a limiter or one of its queues to traffic with the In / Out pipe option of a firewall rule.')?>
			</div>
		</div>
<?php
} else {
?>
		<div class="panel panel-default fs-shaper-empty">
			<div class="fs-tool-empty">
				<i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
				<strong><?=gettext('No limiters yet.')?></strong>
				<p><?=gettext('A limiter caps the bandwidth of the traffic that firewall rules send into it, for example per user with a mask. Limiters work on every interface.')?></p>
				<div class="fs-shaper-empty-actions">
					<a class="btn btn-primary" href="firewall_shaper_vinterface.php?action=add"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=gettext('New limiter')?></a>
				</div>
			</div>
		</div>
<?php
}
?>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

    // Disables the specified input element
    function disableInput(id, disable) {
        $('#' + id).prop("disabled", disable);
    }

	function change_masks() {
		disableInput('maskbits', ($('#mask').val() == 'none'));
		disableInput('maskbitsv6', ($('#mask').val() == 'none'));
	}

	// ---------- On initial page load ------------------------------------------------------------

	change_masks();

	// ---------- Click checkbox handlers ---------------------------------------------------------

    $('#mask').on('change', function() {
        change_masks();
    });
});
//]]>
</script>


<?php
include("foot.inc");
