<?php
/*
 * status_logs_filter.php
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
##|*IDENT=page-diagnostics-logs-firewall
##|*NAME=Status: Logs: Firewall
##|*DESCR=Allow access to the 'Status: Logs: Firewall' page.
##|*MATCH=status_logs_filter.php*
##|-PRIV

require_once("status_logs_common.inc");
require_once("ipsec.inc");


# --- AJAX RESOLVE ---
if (isset($_POST['resolve'])) {
	$ip = strtolower($_POST['resolve']);
	$res = (is_ipaddr($ip) ? gethostbyaddr($ip) : '');

	if ($res && $res != $ip) {
		$response = array('resolve_ip' => $ip, 'resolve_text' => $res);
	} else {
		$response = array('resolve_ip' => $ip, 'resolve_text' => gettext("Cannot resolve"));
	}

	echo json_encode(str_replace("\\", "\\\\", $response)); // single escape chars can break JSON decode
	exit;
}


/*
Build a list of allowed log files so we can reject others to prevent the page
from acting on unauthorized files.
*/
$allowed_logs = array(
	"filter" => array("name" => "Firewall",
		    "shortcut" => "filter"),
);

// The logs to display are specified in a REQUEST argument. Default to 'system' logs
if (!$_REQUEST['logfile']) {
	$logfile = 'filter';
	$view = 'normal';
} else {
	$logfile = $_REQUEST['logfile'];
	$view = $_REQUEST['view'];
	if (!array_key_exists($logfile, $allowed_logs)) {
		/* Do not let someone attempt to load an unauthorized log. */
		$logfile = 'filter';
		$view = 'normal';
	}
}

if ($view == 'normal')  { $view_title = gettext("Normal View"); }
if ($view == 'dynamic') { $view_title = gettext("Dynamic View"); }
if ($view == 'summary') { $view_title = gettext("Summary View"); }

// Used for the firewall log widget and the firewall logs dynamic view.
$rulenum = getGETPOSTsettingvalue('getrulenum', null);

if ($rulenum) {
	list($rulenum, $subrulenum, $tracker, $type) = explode(',', $rulenum);
	$rule = find_rule_by_number($rulenum, $subrulenum, $tracker, $type);
	$rule = $rule['match'] ?? 'unavailable';
	echo gettext("The rule that triggered this action is") . ":\n\n{$rule}";
	exit;
}


// Log Filter Submit - Firewall
log_filter_form_firewall_submit();


// Manage Log Section - Code
manage_log_code();


// Status Logs Common - Code
status_logs_common_code();


$pgtitle = array(gettext("Status"), gettext("System Logs"), gettext($allowed_logs[$logfile]["name"]), $view_title);
$pglinks = array("", "status_logs.php", "status_logs_filter.php", "@self");

$filterdescriptions = config_get_path('syslog/filterdescriptions');

// Read the log
if (!$rawfilter) {
	$iflist = get_configured_interface_with_descr(true);

	if ($iflist[$interfacefilter]) {
		$interfacefilter = $iflist[$interfacefilter];
	}
}
system_log_filter();

// Header actions: Log settings (modal) and Clear log
status_logs_page_actions();

include("head.inc");

status_logs_notices();

// Tab Array
tab_array_logs_common();

status_logs_styles();

/* Action badge with the matched rule as a popover ("match" and "reject" are not
 * logged as such by filterlog: they are derived like print_syslog_rule_action()). */
$fw_action = function ($rule) {
	switch ($rule['act']) {
		case 'pass':
		case 'block':
		case 'rdr':
			$action = $rule['act'];
			break;
		case 'unkn(%u)':
			$action = 'match';
			break;
		default:
			$action = 'unknown';
			break;
	}
	$rules = find_rule_by_number($rule['rulenum'], $rule['subrulenum'], $rule['tracker'], $action);
	if (isset($rules['match']) && ($action == 'block') && preg_match('/^@\d+ block return /', $rules['match'])) {
		$action = 'reject';
	}

	$details = gettext('Action') . ': ' . htmlspecialchars($action) . '<br />' .
	    gettext('Reason') . ': ' . htmlspecialchars($rule['reason']) . '<br />' .
	    gettext('Tracker ID') . ': ' . htmlspecialchars($rule['tracker']) . '<br />' .
	    gettext('Matched rule') . ':' . (isset($rules['match']) ? '<br />' . htmlspecialchars($rules['match']) : ' ' . gettext('unavailable'));
	if (isset($rules['associated'])) {
		$details .= '<br />' . gettext('Associated rules') . ':<br />' . implode('<br />', array_map('htmlspecialchars', $rules['associated']));
	}

	$badge = match ($action) {
		'pass' => fs_badge('pass'),
		'block' => fs_badge('block'),
		'reject' => fs_badge('reject'),
		'match' => fs_badge('info', gettext('Match')),
		'rdr' => fs_badge('info', gettext('Redirect')),
		default => fs_badge('neutral'),
	};

	return '<a class="fs-fw-act" tabindex="0" role="button" data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-html="true"'
	    . ' title="' . fs_h(gettext('Rule details')) . '" data-bs-content="' . fs_h($details) . '"'
	    . ' aria-label="' . fs_h(sprintf(gettext('Rule details (%s)'), $action)) . '">' . $badge . '</a>';
};

/* "addr:port" with the service name as a tooltip */
$fw_endpoint = function ($ip, $port, $proto) {
	$html = htmlspecialchars($ip);
	if ($port && is_port($port)) {
		$service = getservbyport($port, $proto);
		$html .= ':' . ($service ? '<span title="' . fs_h(sprintf(gettext('Service %1$s/%2$s: %3$s'), $port, $proto, $service)) . '">' . htmlspecialchars($port) . '</span>' : htmlspecialchars($port));
	}
	return $html;
};
?>

<div class="panel panel-default fs-table" data-fs-table="firewall-log">
<?php
// Filter toolbar - Firewall
filter_form_firewall();

if (!$rawfilter):
	$colspan = ($filterdescriptions === "1") ? 8 : 7;
?>
	<div class="panel-body table-responsive">
		<table class="table table-hover fs-fwlog" data-sortable>
			<thead>
				<tr>
					<th><?=gettext("Action")?></th>
					<th><?=gettext("Time")?></th>
					<th data-fs-search><?=gettext("Interface")?></th>
<?php	if ($filterdescriptions === "1"): ?>
					<th data-fs-search><?=gettext("Rule")?></th>
<?php	endif; ?>
					<th data-fs-search><?=gettext("Source")?></th>
					<th data-fs-search><?=gettext("Destination")?></th>
					<th data-fs-search><?=gettext("Protocol")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
	if ($filterdescriptions) {
		buffer_rules_load();
	}

	foreach ($filterlog as $filterent):
		$int = strtolower($filterent['interface']);
		$proto = strtolower($filterent['proto']);
		$rawsrcip = $filterent['srcip'];
		$rawdstip = $filterent['dstip'];

		if ($filterent['version'] == '6') {
			$ipproto = "inet6";
			$filterent['srcip'] = "[{$filterent['srcip']}]";
			$filterent['dstip'] = "[{$filterent['dstip']}]";
		} else {
			$ipproto = "inet";
		}

		$protostr = $filterent['proto'];
		if ($filterent['proto'] == "TCP") {
			$protostr .= ":{$filterent['tcpflags']}";
		} elseif ($filterent['protoid'] == '112') {
			$carp_details = array();
			foreach (array('vhid', 'advskew', 'advbase') as $carp_field) {
				if (strlen($filterent[$carp_field])) {
					$carp_details[] = $filterent[$carp_field];
				}
			}
			if (!empty($carp_details)) {
				$protostr .= " " . implode("/", $carp_details);
			}
		}

		$rule_descr = $filterdescriptions ? find_rule_by_number_buffer($filterent['rulenum'], $filterent['subrulenum'], $filterent['tracker'], $filterent['act']) : '';
		$srcname = $filterent['srcip'];
		$actions = [
			['custom', '#', $srcname, ['icon' => 'fa-solid fa-globe', 'label' => gettext('Resolve source and destination'),
			    'attrs' => ['data-fs-resolve' => json_encode([$rawsrcip, $rawdstip])]]],
			['custom', 'easyrule.php?' . http_build_query(['action' => 'block', 'int' => $int, 'src' => $filterent['srcip'], 'ipproto' => $ipproto]),
			    $srcname, ['icon' => 'fa-regular fa-square-minus', 'label' => sprintf(gettext('EasyRule: block %s'), $srcname)]],
			['custom', 'easyrule.php?' . http_build_query(['action' => 'pass', 'int' => $int, 'proto' => $proto, 'src' => $filterent['srcip'],
			    'dst' => $filterent['dstip'], 'dstport' => $filterent['dstport'], 'ipproto' => $ipproto]),
			    $srcname, ['icon' => 'fa-regular fa-square-plus', 'label' => gettext('EasyRule: pass this traffic')]],
		];
?>
				<tr>
					<td class="fs-fw-actcell">
						<?=$fw_action($filterent)?>
<?php		if ($filterent['count']): ?>
						<span class="fs-muted small" title="<?=gettext('Repeated entries')?>">&times;<?=htmlspecialchars($filterent['count'])?></span>
<?php		endif; ?>
<?php		if ($filterdescriptions === "2"): ?>
						<div class="fs-fw-rule"><?=$rule_descr?></div>
<?php		endif; ?>
					</td>
					<?=status_logs_time_cell($filterent['time'])?>
					<td class="text-nowrap">
<?php		if ($filterent['direction'] == "out"): ?>
						<i class="fa-solid fa-arrow-right-from-bracket fs-muted" title="<?=gettext("Outbound")?>" aria-label="<?=gettext("Outbound")?>"></i>
<?php		endif; ?>
						<?=htmlspecialchars($filterent['interface'])?>
					</td>
<?php		if ($filterdescriptions === "1"): ?>
					<td class="fs-fw-rule"><?=$rule_descr?></td>
<?php		endif; ?>
					<td class="fs-mono fs-fw-addr"><?=$fw_endpoint($filterent['srcip'], $filterent['srcport'], $proto)?><span class="fs-fw-resolved" data-fs-ip="<?=fs_h($rawsrcip)?>"></span></td>
					<td class="fs-mono fs-fw-addr"><?=$fw_endpoint($filterent['dstip'], $filterent['dstport'], $proto)?><span class="fs-fw-resolved" data-fs-ip="<?=fs_h($rawdstip)?>"></span></td>
					<td class="fs-mono text-nowrap"><?=htmlspecialchars($protostr)?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php
	endforeach;
	buffer_rules_clear();

	if (count($filterlog) == 0) {
		fs_empty_row($colspan, gettext('No log entries to display.'));
	}
?>
			</tbody>
		</table>
	</div>
<?php else: ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover fs-logtable" data-sortable>
			<thead>
				<tr>
					<th><?=gettext("Message")?></th>
				</tr>
			</thead>
			<tbody>
<?php
	status_logs_raw_rows($rawlines);
	if ($rows == 0) {
		fs_empty_row(1, gettext('No log entries to display.'));
	}
?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
<?php status_logs_card_footer(); ?>
	<div class="panel-footer fs-logcard-foot">
		<span><a href="https://docs.freesense.org/en/latest/firewall/configure.html#tcp-flags" target="_blank" rel="noopener"><?=gettext("TCP flags")?></a>:
			F FIN, S SYN, A or . ACK, R RST, P PSH, U URG, E ECE, C CWR</span>
<?php if (!$rawfilter): ?>
		<span class="fs-fw-legend">
			<span><i class="fa-solid fa-globe" aria-hidden="true"></i> <?=gettext('Resolve')?></span>
			<span><i class="fa-regular fa-square-minus" aria-hidden="true"></i> <?=gettext('Block source')?></span>
			<span><i class="fa-regular fa-square-plus" aria-hidden="true"></i> <?=gettext('Pass traffic')?></span>
		</span>
<?php endif; ?>
	</div>
</div>

<style>
.fs-fwlog > tbody > tr > td { height: 2rem; padding-top: .3rem; padding-bottom: .3rem; }
.fs-fw-act { display: inline-block; cursor: help; text-decoration: none; }
.fs-fw-act:focus-visible { outline: 2px solid var(--fs-coral); outline-offset: 2px; border-radius: 999px; }
.fs-fw-actcell { white-space: nowrap; }
.fs-fw-rule { min-width: 12rem; max-width: 22rem; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); white-space: normal; overflow-wrap: anywhere; }
td.fs-fw-actcell .fs-fw-rule { margin-top: .2rem; }
.fs-fw-addr { white-space: nowrap; font-size: var(--fs-fs-sm); }
.fs-fw-resolved:not(:empty) { display: block; color: var(--fs-text-muted); font-family: var(--fs-font-ui); font-size: var(--fs-fs-xs); }
.fs-fw-legend { display: inline-flex; flex-wrap: wrap; gap: .25rem 1rem; }
</style>

<?php
// Log settings modal
manage_log_section();
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	/* Resolve the source and destination of a row; results go under the addresses */
	function showResolved(ip, text) {
		document.querySelectorAll('.fs-fw-resolved[data-fs-ip="' + CSS.escape(ip) + '"]').forEach(function (el) {
			el.textContent = text;
		});
	}

	document.addEventListener('click', function (e) {
		var link = e.target.closest ? e.target.closest('[data-fs-resolve]') : null;
		if (!link) {
			return;
		}
		e.preventDefault();
		var ips = [];
		try {
			ips = JSON.parse(link.getAttribute('data-fs-resolve')) || [];
		} catch (err) {
			ips = [];
		}
		ips.forEach(function (ip) {
			showResolved(ip, <?=json_encode(gettext("Resolving…"))?>);
			$.ajax('/status_logs_filter.php', {
				method: 'post',
				dataType: 'json',
				data: { resolve: ip },
				success: function (response) {
					showResolved(ip, (response && response.resolve_text) ? String(response.resolve_text) : <?=json_encode(gettext("Cannot resolve"))?>);
				},
				error: function () {
					showResolved(ip, <?=json_encode(gettext("Cannot resolve"))?>);
				}
			});
		});
	});
});
//]]>
</script>

<?php include("foot.inc");
