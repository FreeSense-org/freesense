<?php
/*
 * status_logs_common.inc
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

require_once("guiconfig.inc");
require_once("syslog.inc");


// Status Logs Common - Code
function status_logs_common_code() {
	global $g, $user_settings, $specific_log, $nentries, $rawfilter, $filterlogentries_qty, $logfile_path, $shortcut_section, $allowed_logs, $logfile;
	global $system_logs_filter_form_hidden, $system_logs_manage_log_form_hidden, $view, $input_errors;

	$logfile_path = "{$g['varlog_path']}/" . basename($logfile);
	/* Only add .log suffix if necessary */
	if (!in_array($logfile, array('userlog', 'dmesg.boot'))) {
		$logfile_path .= ".log";
	}

	$specific_log = basename($logfile) . '_settings';

	$nentries = config_get_path('syslog/nentries');
	if (config_get_path("syslog/{$specific_log}/nentries") !== null) {
		$nentries = config_get_path("syslog/{$specific_log}/nentries");
	}

	// Override Display Quantity
	if ($filterlogentries_qty) {
		$nentries = $filterlogentries_qty;
	}

	if (!$nentries || !is_numeric($nentries)) {
		$nentries = g_get('default_log_entries');
	}

	phpsession_begin();
	$guiuser = getUserEntry($_SESSION['Username']);
	$read_only = (is_array($guiuser) && userHasPrivilege($guiuser['item'], "user-config-readonly"));
	phpsession_end();

	if ($_POST['clear']) {
		if (!$read_only) {
			clear_log_file($logfile_path);
		} else{
			$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
		}
	}

	/* Setup shortcuts if they exist */

	if (!empty($allowed_logs[$logfile]["shortcut"])) {
		$shortcut_section = $allowed_logs[$logfile]["shortcut"];
	}

	/* The filter is an always visible toolbar and "Manage log" a header action
	 * (status_logs_page_actions()), so head.inc draws no legacy toggle icons. The
	 * user setting "systemlogsfilterpanel" now opens the advanced filter. */
	$system_logs_filter_form_hidden = false;
	$system_logs_manage_log_form_hidden = false;

	// Formatted/Raw Display
	if (config_get_path("syslog/{$specific_log}/format") == 'formatted') {
		$rawfilter = false;
	} elseif (config_get_path("syslog/{$specific_log}/format") == 'raw') {
		$rawfilter = true;
	} else {	//  Use the general logging options setting (global).
		$rawfilter = config_path_enabled('syslog', 'rawfilter');
	}
}

// Tab Array: the log tabs (registry group 'status-logs') plus the second level as a view switch
function tab_array_logs_common() {
	global $tab_array, $logfile, $vpntype, $view;

	$script = basename($_SERVER['SCRIPT_FILENAME']);
	$is_system_log = in_array($logfile, array('system', 'gateways', 'routing', 'resolver', 'wireless', 'nginx', 'dmesg.boot'));
	$is_auth_log = in_array($logfile, array('auth', 'portalauth', 'utx', 'userlog'));
	$is_filter_log = in_array($logfile, array('filter'));
	$is_pppoe_l2tp_vpn_log = in_array($logfile, array('poes', 'l2tps', 'vpn'));

	/* registry URL of the active top-level tab */
	if ($script == 'status_logs_packages.php') {
		$active = 'status_logs_packages.php';
	} elseif ($script == 'status_logs_settings.php') {
		$active = 'status_logs_settings.php';
	} elseif ($is_system_log) {
		$active = 'status_logs.php';
	} elseif ($is_filter_log) {
		$active = 'status_logs_filter.php';
	} elseif ($is_auth_log || (($logfile == 'vpn') && ($script == 'status_logs.php'))) {
		$active = 'status_logs.php?logfile=auth';
	} elseif ($is_pppoe_l2tp_vpn_log) {
		$active = 'status_logs_vpn.php';
	} else {
		$active = 'status_logs.php?logfile=' . $logfile;
	}
	fs_tabs('status-logs', $active);

	$tab_array = array();
	if ($is_system_log) {
		$tab_array[] = array(gettext("General"), ($logfile == 'system'), "/status_logs.php");
		$tab_array[] = array(gettext("Gateways"), ($logfile == 'gateways'), "/status_logs.php?logfile=gateways");
		$tab_array[] = array(gettext("Routing"), ($logfile == 'routing'), "/status_logs.php?logfile=routing");
		$tab_array[] = array(gettext("DNS Resolver"), ($logfile == 'resolver'), "/status_logs.php?logfile=resolver");
		$tab_array[] = array(gettext("Wireless"), ($logfile == 'wireless'), "/status_logs.php?logfile=wireless");
		$tab_array[] = array(gettext("GUI Service"), ($logfile == 'nginx'), "/status_logs.php?logfile=nginx");
		$tab_array[] = array(gettext("OS Boot"), ($logfile == 'dmesg.boot'), "/status_logs.php?logfile=dmesg.boot");
	} else if ($is_auth_log) {
		$tab_array[] = array(gettext("General"), ($logfile == 'auth'), "/status_logs.php?logfile=auth");
		$tab_array[] = array(gettext("Captive Portal Auth"), ($logfile == 'portalauth'), "/status_logs.php?logfile=portalauth");
		$tab_array[] = array(gettext("PPPoE Logins"),
					(($logfile == 'vpn') && ($vpntype == "poes")),
					"/status_logs_vpn.php?logfile=vpn&vpntype=poes");
		$tab_array[] = array(gettext("L2TP Logins"),
					(($logfile == 'vpn') && ($vpntype == "l2tp")),
					"/status_logs_vpn.php?logfile=vpn&vpntype=l2tp");
		$tab_array[] = array(gettext("OS User Events"), ($logfile == 'utx'), "/status_logs.php?logfile=utx");
		$tab_array[] = array(gettext("OS Account Changes"), ($logfile == 'userlog'), "/status_logs.php?logfile=userlog");
	} else if ($is_filter_log) {
		$tab_array[] = array(gettext("Normal"), ($view == 'normal'), "/status_logs_filter.php");
		$tab_array[] = array(gettext("Dynamic"), ($view == 'dynamic'), "/status_logs_filter_dynamic.php?logfile=filter&view=dynamic");
		$tab_array[] = array(gettext("Summary"), ($view == 'summary'), "/status_logs_filter_summary.php?logfile=filter&view=summary");
	} else if ($is_pppoe_l2tp_vpn_log) {
		$tab_array[] = array(gettext("PPPoE Logins"),
					(($logfile == 'vpn') && ($vpntype == "poes")),
					"/status_logs_vpn.php?logfile=vpn&vpntype=poes");
		$tab_array[] = array(gettext("PPPoE Service"),
					(($logfile == 'poes') && ($vpntype == "poes")),
					"/status_logs_vpn.php?logfile=poes&vpntype=poes");
		$tab_array[] = array(gettext("L2TP Logins"),
					(($logfile == 'vpn') && ($vpntype == "l2tp")),
					"/status_logs_vpn.php?logfile=vpn&vpntype=l2tp");
		$tab_array[] = array(gettext("L2TP Service"),
					(($logfile == 'l2tps') && ($vpntype == "l2tp")),
					"/status_logs_vpn.php?logfile=l2tps&vpntype=l2tp");
	}
	if ($tab_array) {
		status_logs_subnav($tab_array, ($is_filter_log ? gettext('Firewall log view') : gettext('Log')));
	}
}

/*
 * Second level below the log tabs as a segmented control (docs/webui 02: a second
 * level uses a view switch, never a second tab bar). $items are [label, active, url];
 * links to pages the user may not open are left out, like display_top_tabs() does.
 */
function status_logs_subnav(array $items, $label = null) {
	echo '<nav class="fs-viewswitch fs-logs-subnav" aria-label="' . fs_h($label ?? gettext('Log')) . '">';
	foreach ($items as $item) {
		if (!isAllowedPage($item[2])) {
			continue;
		}
		$attrs = ['href' => $item[2]];
		if ($item[1]) {
			$attrs['aria-current'] = 'page';
		}
		echo '<a' . fs_attrs($attrs) . '>' . fs_h($item[0]) . '</a>';
	}
	echo '</nav>';
}

/*
 * Header actions for a log page (call before head.inc): "Log settings" opens the
 * per-log settings modal (manage_log_section()) and "Clear log" posts `clear`
 * after a confirmation. Both need access to the log settings page, as before.
 */
function status_logs_page_actions($settings = true, $clear = true) {
	global $allowed_logs, $logfile;

	if (!isAllowedPage("status_logs_settings.php")) {
		return;
	}

	$name = gettext($allowed_logs[$logfile]["name"] ?? $logfile);
	if ($settings) {
		fs_page_action(gettext('Log settings'), '#', 'fa-sliders', 'secondary', ['data-fs-modal' => '#manage-log']);
	}
	if ($clear) {
		/* post back to this page, keeping the parameters that select the log */
		$keep = array_intersect_key($_GET, array_flip(['logfile', 'vpntype', 'view', 'pkg']));
		$keep = array_filter($keep, 'is_string');
		fs_page_action(gettext('Clear log'), '/' . basename($_SERVER['SCRIPT_NAME']) . '?clear=1', 'fa-trash-can', 'danger', [
			'usepost' => empty($keep) ? true : '?' . http_build_query($keep),
			'data-fs-confirm' => sprintf(gettext('Clear the %s log?'), $name),
			'data-fs-confirm-detail' => gettext('Every entry in this log file is removed. This cannot be undone.'),
			'data-fs-confirm-action' => gettext('Clear log'),
		]);
	}
}

/* Errors and save results for the log pages (after head.inc). */
function status_logs_notices() {
	global $input_errors, $changes_applied, $retval, $extra_save_msg;

	if (!empty($input_errors)) {
		print_input_errors($input_errors);
	}
	if ($changes_applied) {
		print_apply_result_box($retval, $extra_save_msg);
	}
}

// Log Table summary line (card footer)
function system_log_table_panel_title() {
	global $rawfilter, $filtersubmit, $filterlogentries_submit, $filterlog, $nentries, $rows;

	$count = $rawfilter ? (int)$rows : count((array)$filterlog);
	if (($filtersubmit) || ($filterlogentries_submit)) {
		$text = sprintf(($count == 1) ? gettext('%d matching entry') : gettext('%d matching entries'), $count);
	} else {
		$text = sprintf(($count == 1) ? gettext('Last %d entry') : gettext('Last %d entries'), $count);
	}

	return fs_h($text . ' ' . sprintf(gettext('(maximum %d)'), $nentries));
}


// Log Filter: formatted entries go to $filterlog, raw lines to $rawlines (count in $rows)
function system_log_filter() {
	global $rawfilter, $filtersubmit, $filterlogentries_submit, $filterlog, $nentries;
	global $logfile_path, $filtertext, $filterfieldsarray, $interfacefilter, $inverse, $rows, $rawlines;

	if ($rawfilter && (basename($logfile_path) != 'utx.log')) {
		$filtertext = (($filtersubmit) || ($filterlogentries_submit)) ? $filtertext : null;
		$rawlines = dump_log($logfile_path, $nentries, true, array($filtertext), $inverse, 'none');
		if (!is_array($rawlines)) {
			$rawlines = array();
		}
		$rows = count($rawlines);
	} else {
		if (($filtersubmit) || ($filterlogentries_submit)) {
			$filterlog = conv_log_filter($logfile_path, $nentries, $nentries + 100, $filterfieldsarray);
		} else {
			$filterlog = conv_log_filter($logfile_path, $nentries, $nentries + 100, $filtertext, $interfacefilter);
		}
		if (!is_array($filterlog)) {
			$filterlog = array();
		}
		$rows = count($filterlog);
	}
}

/* ------------------------------------------------------------- log rendering */

/* Severity guessed from the message text ('error', 'warn' or ''); colours the row edge. */
function status_logs_severity($text) {
	if (preg_match('/\b(errors?|fail(s|ed|ure)?|fatal|panic|crit(ical)?|denied|refused|unable|cannot|crash(ed)?|emerg(ency)?|alert)\b/i', $text)) {
		return 'error';
	}
	if (preg_match('/\b(warn(ing)?s?|deprecated|time[sd]? ?out|retry(ing)?|degraded|lost|down)\b/i', $text)) {
		return 'warn';
	}
	return '';
}

/* <tr> attributes for a log row: severity class and the level filter key */
function status_logs_row_attrs($text) {
	$sev = status_logs_severity($text);
	return ' data-fs-filter-level="' . ($sev ?: 'info') . '"' . ($sev ? ' class="fs-log--' . $sev . '"' : '');
}

/* Time cell: mono, date muted, sub-second digits and zone only in the tooltip. */
function status_logs_time_cell($time) {
	$time = trim((string)$time);
	if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $time, $m)) {
		$html = '<span class="fs-log-date">' . fs_h($m[1]) . '</span> ' . fs_h($m[2]);
	} else {
		$html = fs_h($time);
	}
	return '<td class="fs-log-time" title="' . fs_h($time) . '">' . $html . '</td>';
}

/* Process chip with the PID as a muted suffix. */
function status_logs_process_chip($process, $pid = '') {
	$process = trim((string)$process);
	$pid = trim((string)$pid);
	if ($process === '' && $pid === '') {
		return '';
	}
	return '<span class="fs-log-proc">' . fs_h($process)
	    . (($pid !== '' && $pid !== '-') ? '<span class="fs-log-pid">[' . fs_h($pid) . ']</span>' : '')
	    . '</span>';
}

/* Raw log lines as one mono message cell per row. */
function status_logs_raw_rows($lines, $colspan = 1) {
	foreach ((array)$lines as $line) {
		echo '<tr' . status_logs_row_attrs($line) . '><td class="fs-log-raw"' . (($colspan > 1) ? ' colspan="' . (int)$colspan . '"' : '') . '>'
		    . fs_h($line) . '</td></tr>';
	}
}

/* Card footer: entry summary and the filter syntax hint. */
function status_logs_card_footer($hint = true) {
	echo '<div class="panel-footer fs-logcard-foot"><span>' . system_log_table_panel_title() . '</span>';
	if ($hint) {
		echo '<span>' . fs_h(gettext('Filters take regular expressions; start with ! to exclude matches.')) . ' '
		    . '<a href="https://www.php.net/manual/en/book.pcre.php" target="_blank" rel="noopener">' . fs_h(gettext('Syntax')) . '</a></span>';
	}
	echo '</div>';
}

/* Shared page styles for the log pages. */
function status_logs_styles() {
?>
<style>
.fs-logs-subnav { margin-top: calc(-1 * var(--fs-sp-2)); }
.fs-logtable > tbody > tr > td { height: 2rem; padding-top: .3rem; padding-bottom: .3rem; vertical-align: top; }
.fs-log-time { white-space: nowrap; font-family: var(--fs-font-mono); font-size: var(--fs-fs-sm); }
.fs-log-date { color: var(--fs-text-muted); }
.fs-log-proc {
	display: inline-block; max-width: 14rem; padding: 0 .45rem; overflow: hidden; text-overflow: ellipsis; vertical-align: top;
	border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); background: var(--fs-surface-raised);
	color: var(--fs-text-muted); font-family: var(--fs-font-mono); font-size: var(--fs-fs-xs); line-height: 1.3rem; white-space: nowrap;
}
.fs-log-pid { margin-left: .15rem; opacity: .7; }
.fs-log-msg { min-width: 16rem; white-space: normal; overflow-wrap: anywhere; }
.fs-log-raw { font-family: var(--fs-font-mono); font-size: var(--fs-fs-sm); white-space: pre-wrap; overflow-wrap: anywhere; }
tr.fs-log--error > td:first-child { box-shadow: inset 3px 0 0 var(--fs-block); }
tr.fs-log--warn > td:first-child { box-shadow: inset 3px 0 0 var(--fs-warn); }
tr.fs-log--error .fs-log-msg, tr.fs-log--error .fs-log-raw { color: var(--fs-block); }
.fs-logfilter-field { width: 11rem; height: 2rem; font-size: var(--fs-fs-sm); }
.fs-logfilter-field--sm { width: 6.5rem; }
.fs-toolbar .fs-logfilter-chip { height: 2rem; padding: 0 .7rem; font-size: var(--fs-fs-sm); line-height: 1.9rem; }
.fs-logfilter-toggle[aria-expanded="true"] { color: var(--fs-text-strong); border-color: var(--fs-coral); }
.fs-logfilter-adv { padding: var(--fs-sp-3) var(--fs-sp-4); background: var(--fs-surface-raised); border-bottom: 1px solid var(--fs-border); }
.fs-logfilter-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: var(--fs-sp-2) var(--fs-sp-3); }
.fs-logfilter-grid .form-label { margin-bottom: .2rem; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 500; }
.fs-logfilter-grid .form-control { height: 2rem; font-size: var(--fs-fs-sm); }
.fs-logcard-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .25rem 1rem; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-logset-group { display: grid; grid-template-columns: minmax(0, 11rem) minmax(0, 1fr); gap: .6rem 1rem; align-items: center; margin-bottom: 1rem; }
.fs-logset-group > .form-label { margin: 0; }
.fs-logset-group .form-text { grid-column: 2; margin-top: -.35rem; }
.fs-logset-title { margin: 1.25rem 0 .6rem; padding-top: .9rem; border-top: 1px solid var(--fs-border); font-size: var(--fs-fs-base); font-weight: 600; color: var(--fs-text-strong); }
.fs-logset-intro { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-logset-checks .form-check { margin-bottom: .35rem; }
@media (max-width: 575.98px) {
	.fs-logfilter-field, .fs-logfilter-field--sm { flex: 1 1 8rem; width: auto; min-width: 0; }
	.fs-logset-group { grid-template-columns: 1fr; gap: .3rem; }
	.fs-logset-group .form-text { grid-column: 1; margin-top: 0; }
	.fs-logtable > thead { display: none; }
	.panel .fs-logtable.table > tbody > tr:not(.fs-empty) {
		display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: .2rem .6rem;
		padding: .45rem var(--fs-sp-3); border-bottom: 1px solid var(--fs-border);
	}
	.panel .fs-logtable.table > tbody > tr:not(.fs-empty) > td { height: auto; padding: 0; border: 0; box-shadow: none; }
	.panel .fs-logtable.table > tbody > tr > td.fs-log-msg, .panel .fs-logtable.table > tbody > tr > td.fs-log-raw { grid-column: 1 / -1; min-width: 0; }
	.panel .fs-logtable.table > tbody > tr.fs-log--error:not(.fs-empty) { box-shadow: inset 3px 0 0 var(--fs-block); }
	.panel .fs-logtable.table > tbody > tr.fs-log--warn:not(.fs-empty) { box-shadow: inset 3px 0 0 var(--fs-warn); }
}
</style>
<?php
}

/* ------------------------------------------------------------ filter toolbar */

/*
 * One filter field. $f: name, label, value, type (text|number|select|check),
 * options (select), placeholder, small (narrow), list (datalist values).
 * Toolbar fields join the filter form through the form="" attribute, so the
 * quick search beside them never submits.
 */
function status_logs_filter_field(array $f, $toolbar) {
	$type = $f['type'] ?? 'text';
	$name = $f['name'];
	$value = (string)($f['value'] ?? '');
	$form = $toolbar ? ' form="fs-logfilter"' : '';

	if ($type == 'check') {
		/* toggle chip; posts the same name/value as the old checkbox */
		return '<input type="checkbox" class="btn-check" id="' . fs_h($name) . '" name="' . fs_h($name) . '" value="' . fs_h($f['check_value']) . '"'
		    . $form . ($f['checked'] ? ' checked' : '') . ' autocomplete="off">'
		    . '<label class="btn btn-sm btn-outline-secondary fs-logfilter-chip" for="' . fs_h($name) . '">' . fs_h($f['label']) . '</label>';
	}

	$cls = $toolbar ? ' fs-logfilter-field' . (!empty($f['small']) ? ' fs-logfilter-field--sm' : '') : '';
	if ($type == 'select') {
		$html = '<select class="form-select form-select-sm' . $cls . '" id="' . fs_h($name) . '" name="' . fs_h($name) . '"' . $form
		    . ($toolbar ? ' aria-label="' . fs_h($f['label']) . '"' : '') . '>';
		foreach ($f['options'] as $ov => $ol) {
			$html .= '<option value="' . fs_h($ov) . '"' . (((string)$ov === $value) ? ' selected' : '') . '>' . fs_h($ol) . '</option>';
		}
		$html .= '</select>';
	} else {
		$list = '';
		$listattr = '';
		if (!empty($f['list'])) {
			$listattr = ' list="' . fs_h($name) . '-list"';
			$list = '<datalist id="' . fs_h($name) . '-list">';
			foreach ($f['list'] as $lv) {
				$list .= '<option value="' . fs_h($lv) . '"></option>';
			}
			$list .= '</datalist>';
		}
		$html = '<input type="' . (($type == 'number') ? 'number" min="1' : 'text') . '" class="form-control' . ($toolbar ? ' form-control-sm' : '') . $cls . '"'
		    . ' id="' . fs_h($name) . '" name="' . fs_h($name) . '" value="' . fs_h($value) . '"' . $form . $listattr
		    . ' placeholder="' . fs_h($f['placeholder'] ?? $f['label']) . '"'
		    . ($toolbar ? ' aria-label="' . fs_h($f['label']) . '"' : '') . ' autocomplete="off">' . $list;
	}

	if ($toolbar) {
		return $html;
	}
	return '<div><label class="form-label" for="' . fs_h($name) . '">' . fs_h($f['label']) . '</label>' . $html . '</div>';
}

/*
 * Log card toolbar: quick search over the shown entries (client side), the main
 * server filter fields, Apply, "More filters" (the advanced fields, collapsible)
 * and the entry count. Field names and the submit name are the existing ones.
 */
function status_logs_filter_toolbar(array $quick, array $advanced, $submit, $levels = true) {
	global $filter_active, $user_settings;

	$adv_open = !empty($user_settings['webgui']['systemlogsfilterpanel']);
	foreach ($advanced as $f) {
		if ((($f['type'] ?? 'text') == 'check') ? !empty($f['checked']) : (trim((string)($f['value'] ?? '')) !== '')) {
			$adv_open = true;
		}
	}

	$custom = '';
	foreach ($quick as $f) {
		$custom .= status_logs_filter_field($f, true);
	}
	$custom .= '<button type="submit" class="btn btn-sm btn-primary" name="' . fs_h($submit) . '" value="apply" form="fs-logfilter">'
	    . '<i class="fa-solid fa-filter icon-embed-btn" aria-hidden="true"></i>' . fs_h(gettext('Apply')) . '</button>';
	if (!empty($advanced)) {
		$custom .= '<button type="button" class="btn btn-sm btn-outline-secondary fs-logfilter-toggle" data-bs-toggle="collapse" data-bs-target="#fs-logfilter"'
		    . ' aria-controls="fs-logfilter" aria-expanded="' . ($adv_open ? 'true' : 'false') . '">'
		    . '<i class="fa-solid fa-sliders icon-embed-btn" aria-hidden="true"></i>' . fs_h(gettext('More filters')) . '</button>';
	}
	if ($filter_active) {
		$keep = array_filter(array_intersect_key($_GET, array_flip(['logfile', 'vpntype', 'view', 'pkg'])), 'is_string');
		$custom .= '<a class="btn btn-sm btn-link" href="' . fs_h(basename($_SERVER['SCRIPT_NAME']) . ($keep ? '?' . http_build_query($keep) : '')) . '">'
		    . fs_h(gettext('Reset')) . '</a>';
	}

	$opts = [
		'search' => gettext('Search shown entries…'),
		'noun' => gettext('entries'),
		'noun_one' => gettext('entry'),
		'custom' => $custom,
	];
	if ($levels) {
		$opts['filters'] = ['level' => [gettext('All levels'), 'error' => gettext('Errors'), 'warn' => gettext('Warnings')]];
	}
	fs_table_toolbar($opts);

	if (empty($advanced)) {
		echo '<form id="fs-logfilter" method="post" hidden></form>';
		return;
	}
	echo '<form id="fs-logfilter" method="post" class="collapse fs-logfilter-adv' . ($adv_open ? ' show' : '') . '">';
	echo '<div class="fs-logfilter-grid">';
	foreach ($advanced as $f) {
		echo status_logs_filter_field($f, false);
	}
	echo '</div></form>';
}

// Filter Section/Form - System
function filter_form_system() {

	global $logfile, $rawfilter, $filterfieldsarray, $filtertext, $filterlogentries_qty, $nentries;

	if (!$rawfilter || ($logfile == 'utx')) { // Advanced (field) log filter
		if ($logfile == 'utx') {
			$labels = array(
				'time'    => gettext('Login time'),
				'process' => gettext('Duration'),
				'pid'     => gettext('TTY'),
				'message' => gettext('User'),
			);
		} else {
			$labels = array(
				'time'    => gettext('Time'),
				'process' => gettext('Process'),
				'pid'     => gettext('PID'),
				'message' => gettext('Message'),
			);
		}
		$quick = [
			['name' => 'filterlogentries_message', 'label' => $labels['message'], 'value' => $filterfieldsarray['message'] ?? '',
			    'placeholder' => sprintf(gettext('%s filter'), $labels['message'])],
			['name' => 'filterlogentries_process', 'label' => $labels['process'], 'value' => $filterfieldsarray['process'] ?? '', 'small' => true],
		];
		$advanced = [
			['name' => 'filterlogentries_time', 'label' => $labels['time'], 'value' => $filterfieldsarray['time'] ?? ''],
			['name' => 'filterlogentries_pid', 'label' => $labels['pid'], 'value' => $filterfieldsarray['pid'] ?? ''],
			['name' => 'filterlogentries_qty', 'label' => gettext('Entries'), 'type' => 'number', 'value' => $filterlogentries_qty, 'placeholder' => $nentries],
		];
		status_logs_filter_toolbar($quick, $advanced, 'filterlogentries_submit', ($logfile != 'utx'));
	} else { // Simple (expression) log filter
		$quick = [
			['name' => 'filtertext', 'label' => gettext('Filter expression'), 'value' => $filtertext],
			['name' => 'filterlogentries_qty', 'label' => gettext('Entries'), 'type' => 'number', 'value' => $filterlogentries_qty,
			    'placeholder' => $nentries, 'small' => true],
		];
		status_logs_filter_toolbar($quick, [], 'filtersubmit');
	}
}


// Log Filter Submit - System
function log_filter_form_system_submit() {

	global $filtersubmit, $interfacefilter, $filtertext;
	global $filterlogentries_submit, $filterfieldsarray, $actpass, $actblock;
	global $filter_active, $filterlogentries_qty;

	$filtersubmit = getGETPOSTsettingvalue('filtersubmit', null);

	if ($filtersubmit) {
		$filter_active = true;
		$filtertext = getGETPOSTsettingvalue('filtertext', "");
		$filterlogentries_qty = getGETPOSTsettingvalue('filterlogentries_qty', null);
	}

	$filterlogentries_submit = getGETPOSTsettingvalue('filterlogentries_submit', null);

	if ($filterlogentries_submit) {
		$filter_active = true;
		$filterfieldsarray = array();

		$filterfieldsarray['time'] = getGETPOSTsettingvalue('filterlogentries_time', null);
		$filterfieldsarray['process'] = getGETPOSTsettingvalue('filterlogentries_process', null);
		$filterfieldsarray['pid'] = getGETPOSTsettingvalue('filterlogentries_pid', null);
		$filterfieldsarray['message'] = getGETPOSTsettingvalue('filterlogentries_message', null);
		$filterlogentries_qty = getGETPOSTsettingvalue('filterlogentries_qty', null);
	}
}


// Log Filter Submit - Firewall
function log_filter_form_firewall_submit() {

	global $filtersubmit, $interfacefilter, $filtertext;
	global $filterlogentries_submit, $filterfieldsarray, $actpass, $actblock;
	global $filter_active, $filterlogentries_qty;

	$filtersubmit = getGETPOSTsettingvalue('filtersubmit', null);

	if ($filtersubmit) {
		$filter_active = true;
		$interfacefilter = getGETPOSTsettingvalue('interface', null);
		$filtertext = getGETPOSTsettingvalue('filtertext', "");
		$filterlogentries_qty = getGETPOSTsettingvalue('filterlogentries_qty', null);
	}

	$filterlogentries_submit = getGETPOSTsettingvalue('filterlogentries_submit', null);

	if ($filterlogentries_submit) {
		$filter_active = true;
		$filterfieldsarray = array();

		$actpass = getGETPOSTsettingvalue('actpass', null);
		$actblock = getGETPOSTsettingvalue('actblock', null);
		$filterfieldsarray['act'] = str_replace("  ", " ", trim($actpass . " " . $actblock));
		$filterfieldsarray['act'] = $filterfieldsarray['act'] != "" ? $filterfieldsarray['act'] : 'All';
		$filterfieldsarray['time'] = getGETPOSTsettingvalue('filterlogentries_time', null);
		$filterfieldsarray['interface'] = getGETPOSTsettingvalue('filterlogentries_interfaces', null);
		$filterfieldsarray['srcip'] = getGETPOSTsettingvalue('filterlogentries_sourceipaddress', null);
		$filterfieldsarray['srcport'] = getGETPOSTsettingvalue('filterlogentries_sourceport', null);
		$filterfieldsarray['dstip'] = getGETPOSTsettingvalue('filterlogentries_destinationipaddress', null);
		$filterfieldsarray['dstport'] = getGETPOSTsettingvalue('filterlogentries_destinationport', null);
		$filterfieldsarray['proto'] = getGETPOSTsettingvalue('filterlogentries_protocol', null);
		$filterfieldsarray['tcpflags'] = getGETPOSTsettingvalue('filterlogentries_protocolflags', null);
		$filterfieldsarray['tracker'] = getGETPOSTsettingvalue('filterlogentries_tracker', null);
		$filterlogentries_qty = getGETPOSTsettingvalue('filterlogentries_qty', null);
	}
}

// Filter Section/Form - Firewall
function filter_form_firewall() {

	global $rawfilter, $filterfieldsarray, $filtertext, $filterlogentries_qty, $nentries, $interfacefilter;

	$Include_Act = explode(",", str_replace(" ", ",", $filterfieldsarray['act'] ?? ''));

	if (!$rawfilter) { // Advanced (field) log filter
		$ifdescrs = array_values(get_configured_interface_with_descr(true));
		$quick = [
			['name' => 'actpass', 'label' => gettext('Pass'), 'type' => 'check', 'check_value' => 'Pass', 'checked' => in_arrayi('Pass', $Include_Act)],
			['name' => 'actblock', 'label' => gettext('Block'), 'type' => 'check', 'check_value' => 'Block', 'checked' => in_arrayi('Block', $Include_Act)],
			['name' => 'filterlogentries_interfaces', 'label' => gettext('Interface'), 'value' => $filterfieldsarray['interface'] ?? '',
			    'list' => $ifdescrs, 'small' => true],
			['name' => 'filterlogentries_sourceipaddress', 'label' => gettext('Source address'), 'value' => $filterfieldsarray['srcip'] ?? ''],
			['name' => 'filterlogentries_destinationipaddress', 'label' => gettext('Destination address'), 'value' => $filterfieldsarray['dstip'] ?? ''],
		];
		$advanced = [
			['name' => 'filterlogentries_sourceport', 'label' => gettext('Source port'), 'value' => $filterfieldsarray['srcport'] ?? ''],
			['name' => 'filterlogentries_destinationport', 'label' => gettext('Destination port'), 'value' => $filterfieldsarray['dstport'] ?? ''],
			['name' => 'filterlogentries_protocol', 'label' => gettext('Protocol'), 'value' => $filterfieldsarray['proto'] ?? ''],
			['name' => 'filterlogentries_protocolflags', 'label' => gettext('Protocol flags'), 'value' => $filterfieldsarray['tcpflags'] ?? ''],
			['name' => 'filterlogentries_tracker', 'label' => gettext('Rule tracker ID'), 'value' => $filterfieldsarray['tracker'] ?? ''],
			['name' => 'filterlogentries_time', 'label' => gettext('Time'), 'value' => $filterfieldsarray['time'] ?? ''],
			['name' => 'filterlogentries_qty', 'label' => gettext('Entries'), 'type' => 'number', 'value' => $filterlogentries_qty, 'placeholder' => $nentries],
		];
		status_logs_filter_toolbar($quick, $advanced, 'filterlogentries_submit', false);
	} else { // Simple (expression) log filter
		$quick = [
			['name' => 'interface', 'label' => gettext('Interface'), 'type' => 'select', 'value' => $interfacefilter,
			    'options' => array('' => gettext('All interfaces')) + (array)status_logs_build_if_list()],
			['name' => 'filtertext', 'label' => gettext('Filter expression'), 'value' => $filtertext],
			['name' => 'filterlogentries_qty', 'label' => gettext('Entries'), 'type' => 'number', 'value' => $filterlogentries_qty,
			    'placeholder' => $nentries, 'small' => true],
		];
		status_logs_filter_toolbar($quick, [], 'filtersubmit', false);
	}
}

function status_logs_build_if_list() {
	$iflist = get_configured_interface_with_descr(true);
	//$iflist = get_interface_list();
	// Allow extending of the firewall edit interfaces
	FreeSense_handle_custom_code("/usr/local/pkg/firewall_nat/pre_interfaces_edit");
	foreach ($iflist as $if => $ifdesc) {
		$interfaces[$if] = $ifdesc;
	}

	if (config_get_path('l2tp/mode') == "server") {
		$interfaces['l2tp'] = "L2TP VPN";
	}

	if (is_pppoe_server_enabled() && have_ruleint_access("pppoe")) {
		$interfaces['pppoe'] = "PPPoE Server";
	}

	/* add ipsec interfaces */
	if (ipsec_enabled()) {
		$interfaces["enc0"] = "IPsec";
	}

	/* add openvpn/tun interfaces */
	if	(config_get_path('openvpn/openvpn-server') || config_get_path('openvpn/openvpn-client')) {
		$interfaces["openvpn"] = "OpenVPN";
	}

	return($interfaces);
}


// Manage Log Section - Code
function manage_log_code() {

	/* If the user does not have access to status logs settings page, then don't accept a manage log form submission. */
	if (!isAllowedPage("status_logs_settings.php")) {
		return;
	}

	global $logfile, $specific_log, $pconfig, $save_settings, $input_errors, $extra_save_msg, $retval, $changes_applied, $allowed_logs;

	$changes_applied = false;
	$filterlog_changed = false;
	$extra_save_msg = "";
	$specific_log = basename($logfile) . '_settings';
	$syslog_config = config_get_path('syslog', []);

	// Common to All Logs
	$pconfig['cronorder'] = isset($syslog_config[$specific_log]['cronorder']) ? $syslog_config[$specific_log]['cronorder'] : null;
	$pconfig['nentries'] = isset($syslog_config[$specific_log]['nentries']) ? $syslog_config[$specific_log]['nentries'] : null;
	$pconfig['logfilesize'] = isset($syslog_config[$specific_log]['logfilesize']) ? $syslog_config[$specific_log]['logfilesize'] : null;
	$pconfig['rotatecount'] = isset($syslog_config[$specific_log]['rotatecount']) ? $syslog_config[$specific_log]['rotatecount'] : null;
	$pconfig['format'] = isset($syslog_config[$specific_log]['format']) ? $syslog_config[$specific_log]['format'] : null;

	// Specific to System General (main) Log
	if ($logfile == 'system') {
		$pconfig['lognginx'] = !isset($syslog_config['nolognginx']);
	}

	// Specific to Firewall Log
	if ($logfile == 'filter') {
		$pconfig['logipoptions'] = !isset($syslog_config['nologipoptions']);
		$pconfig['logdefaultblock'] = !isset($syslog_config['nologdefaultblock']);
		$pconfig['logdefaultpass'] = isset($syslog_config['nologdefaultpass']);
		$pconfig['logbogons'] = !isset($syslog_config['nologbogons']);
		$pconfig['logprivatenets'] = !isset($syslog_config['nologprivatenets']);
		$pconfig['loglinklocal4'] = !isset($syslog_config['nologlinklocal4']);
		$pconfig['logsnort2c'] = !isset($syslog_config['nologsnort2c']);
		$pconfig['filterdescriptions'] = isset($syslog_config['filterdescriptions']) ? $syslog_config['filterdescriptions'] : null;
	}

	$save_settings = getGETPOSTsettingvalue('save_settings', null);

	phpsession_begin();
	$guiuser = getUserEntry($_SESSION['Username']);
	$read_only = (is_array($guiuser) && userHasPrivilege($guiuser['item'], "user-config-readonly"));
	phpsession_end();

	if ($save_settings && !$read_only) {
		// Common to All Logs
		$cronorder = getGETPOSTsettingvalue('cronorder',  null);
		$nentries = getGETPOSTsettingvalue('nentries', null);
		$logfilesize = getGETPOSTsettingvalue('logfilesize', null);
		$rotatecount = getGETPOSTsettingvalue('rotatecount', null);
		$format  = getGETPOSTsettingvalue('format',  null);

		// Specific to System General (main) Log
		if ($logfile == 'system') {
			$lognginx  = getGETPOSTsettingvalue('lognginx',  null);
		}

		// Specific to Firewall Log
		if ($logfile == 'filter') {
			$logipoptions = getGETPOSTsettingvalue('logipoptions',  null);
			$logdefaultblock = getGETPOSTsettingvalue('logdefaultblock',  null);
			$logdefaultpass = getGETPOSTsettingvalue('logdefaultpass', null);
			$logbogons = getGETPOSTsettingvalue('logbogons', null);
			$logprivatenets  = getGETPOSTsettingvalue('logprivatenets',  null);
			$loglinklocal4 = getGETPOSTsettingvalue('loglinklocal4',  null);
			$filterdescriptions  = getGETPOSTsettingvalue('filterdescriptions',  null);
			$logsnort2c = getGETPOSTsettingvalue('logsnort2c',  null);
		}

		unset($input_errors);
		global $input_errors;
		$pconfig = $_POST;

		/* input validation */
		// Common to All Logs
		if (isset($nentries) && (strlen($nentries) > 0)) {
			if (!is_numeric($nentries) || ($nentries < 5) || ($nentries > 2000)) {
				$input_errors[] = gettext("Number of log entries to show must be between 5 and 2000.");
			}
		}

		if (isset($logfilesize) && (strlen($logfilesize) > 0)) {
			if (!is_numeric($logfilesize) || ($logfilesize < 100000)) {
				$input_errors[] = gettext("Log file size must be numeric and greater than or equal to 100000.");
			} elseif ($logfilesize >= (2**32)/2) {
				$input_errors[] = gettext("Log file size is too large. Set a smaller value.");
			}
		}
		if (isset($rotatecount) && (strlen($rotatecount) > 0)) {
			if (!is_numericint($rotatecount) ||
			    ($rotatecount < 0) ||
			    ($rotatecount > 99)) {
				$input_errors[] = gettext("Log Retention Count must be an integer from 0 to 99.");
			}
		}

		if (!$input_errors) {
			if (($rotatecount != array_get_path($syslog_config, "{$specific_log}/rotatecount")) ||
			    ($logfilesize != array_get_path($syslog_config, "{$specific_log}/logfilesize"))) {
				$logging_changed = true;
			}

			# Clear out the specific log settings and leave only the applied settings to override the general logging options (global) settings.
			array_del_path($syslog_config, "{$specific_log}");
			array_init_path($syslog_config, "{$specific_log}");

		// Common to All Logs
			if ($cronorder != '') { # if not using the general logging options setting (global)
				$syslog_config[$specific_log]['cronorder'] = $cronorder;
			}

			if (isset($nentries) && (strlen($nentries) > 0)) {
				$syslog_config[$specific_log]['nentries'] = (int)$nentries;
			}

			if (isset($logfilesize) && (strlen($logfilesize) > 0)) {
				$syslog_config[$specific_log]['logfilesize'] = (int)$logfilesize;
			}

			if (isset($rotatecount) && (strlen($rotatecount) > 0)) {
				$syslog_config[$specific_log]['rotatecount'] = (int)$rotatecount;
			}

			if ($format != '') { # if not using the general logging options setting (global)
				$syslog_config[$specific_log]['format'] = $format;
			}

		// Specific to System General (main) Log
			if ($logfile == 'system') {
				$oldnolognginx = isset($syslog_config['nolognginx']);
				$syslog_config['nolognginx'] = $lognginx ? false : true;

				if ($oldnolognginx !== $syslog_config['nolognginx']) {
					$logging_changed = $nginx_logging_changed = true;
				}
			}

		// Specific to  Firewall Log
			if ($logfile == 'filter') {
				$oldnologipoptions = isset($syslog_config['nologipoptions']);
				$oldnologdefaultblock = isset($syslog_config['nologdefaultblock']);
				$oldnologdefaultpass = isset($syslog_config['nologdefaultpass']);
				$oldnologbogons = isset($syslog_config['nologbogons']);
				$oldnologprivatenets = isset($syslog_config['nologprivatenets']);
				$oldnologlinklocal4 = isset($syslog_config['nologlinklocal4']);
				$oldnologsnort2c = isset($syslog_config['nologsnort2c']);

				$syslog_config['nologipoptions'] = $logipoptions ? false : true;
				$syslog_config['nologdefaultblock'] = $logdefaultblock ? false : true;
				$syslog_config['nologdefaultpass'] = $logdefaultpass ? true : false;
				$syslog_config['nologbogons'] = $logbogons ? false : true;
				$syslog_config['nologprivatenets'] = $logprivatenets ? false : true;
				$syslog_config['nologlinklocal4'] = $loglinklocal4 ? false : true;
				$syslog_config['nologsnort2c'] = $logsnort2c ? false : true;

				if (is_numeric($filterdescriptions) && $filterdescriptions > 0) {
					$syslog_config['filterdescriptions'] = $filterdescriptions;
				} else {
					array_del_path($syslog_config, 'filterdescriptions');
				}

				if (
				    ($oldnologipoptions !== $syslog_config['nologipoptions']) ||
				    ($oldnologdefaultblock !== $syslog_config['nologdefaultblock']) ||
				    ($oldnologdefaultpass !== $syslog_config['nologdefaultpass']) ||
				    ($oldnologbogons !== $syslog_config['nologbogons']) ||
				    ($oldnologprivatenets !== $syslog_config['nologprivatenets']) ||
				    ($oldnologlinklocal4 !== $syslog_config['nologlinklocal4']) ||
				    ($oldnologsnort2c !== $syslog_config['nologsnort2c'])) {
					$logging_changed = $firewall_logging_changed = true;
				}

				if ($oldnologipoptions !== $syslog_config['nologipoptions']) {
					$filterlog_changed = true;
				}
			}

			config_set_path('syslog', $syslog_config);

			$retval = 0;
			$changes_applied = true;

		// If any of the logging settings were changed then backup and sync (standard write_config).  Otherwise only write config (don't backup, don't sync).
			$log_friendly_name = empty($allowed_logs[$logfile]["name"]) ? $logfile : $allowed_logs[$logfile]["name"];
			if ($logging_changed) {
				write_config($desc = gettext("Log Display Settings Saved: ") . gettext($log_friendly_name), $backup = true, $write_config_only = false);
				system_syslogd_start();
			} else {
				write_config($desc = gettext("Log Display Settings Saved (no backup, no sync): ") . gettext($log_friendly_name), $backup = false, $write_config_only = true);
			}

		// Specific to System General (main) Log
			if ($logfile == 'system') {
				if ($nginx_logging_changed) {
					ob_flush();
					flush();
					logger(LOG_NOTICE, localize_text('webConfigurator configuration has changed. Restarting webConfigurator.'));
					send_event("service restart webgui");
					$extra_save_msg = gettext("WebGUI process is restarting.");
				}
			}

		// Specific to Firewall Log
			if ($logfile == 'filter') {
				if ($firewall_logging_changed) {
					require_once("filter.inc");
					$retval |= filter_configure();
					filter_pflog_start($filterlog_changed);
				}
			}
		}
	} elseif ($save_settings) {
		/* Save Settings code path, but read only user. */
		$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
	}
}

# Manage Log Section/Form: the per-log settings modal opened by the "Log settings" page action
function manage_log_section() {

	/* If the user does not have access to status logs settings page, then exclude the manage log modal from the page. */
	if (!isAllowedPage("status_logs_settings.php")) {
		return;
	}

	global $input_errors, $allowed_logs, $logfile, $pconfig, $save_settings;

	$name = gettext($allowed_logs[$logfile]["name"] ?? $logfile);
	$radio = function ($field, $choices, $current) {
		$html = '<div class="btn-group flex-wrap" role="radiogroup">';
		foreach ($choices as $value => $label) {
			$id = $field . '-' . (($value === '') ? 'default' : $value);
			$html .= '<input type="radio" class="btn-check" name="' . fs_h($field) . '" id="' . fs_h($id) . '" value="' . fs_h($value) . '"'
			    . (((string)$current === (string)$value) ? ' checked' : '') . ' autocomplete="off">'
			    . '<label class="btn btn-sm btn-outline-secondary" for="' . fs_h($id) . '">' . fs_h($label) . '</label>';
		}
		return $html . '</div>';
	};
	$number = function ($field, $value, $placeholder, $attrs) {
		return '<input type="number" class="form-control fs-mono" id="ml-' . fs_h($field) . '" name="' . fs_h($field) . '" value="' . fs_h($value) . '"'
		    . ' placeholder="' . fs_h($placeholder) . '"' . fs_attrs($attrs) . '>';
	};
	$check = function ($field, $label, $checked, $help = null) {
		return '<div class="form-check"><input class="form-check-input" type="checkbox" id="ml-' . fs_h($field) . '" name="' . fs_h($field) . '" value="yes"'
		    . ($checked ? ' checked' : '') . '><label class="form-check-label" for="ml-' . fs_h($field) . '">' . fs_h($label) . '</label>'
		    . (($help !== null) ? '<div class="form-text">' . fs_h($help) . '</div>' : '') . '</div>';
	};

	/* reopen with the posted values after a failed save */
	$reopen = ($save_settings && !empty($input_errors)) ? [] : null;
	fs_modal_form_begin('manage-log', sprintf(gettext('%s log settings'), $name), '', [], $reopen);
?>
	<p class="fs-logset-intro"><?=gettext('These settings apply to this log only and override the')?> <a href="status_logs_settings.php"><?=gettext('general log settings')?></a>.</p>
	<div class="fs-logset-group">
		<span class="form-label"><?=gettext('Order')?></span>
		<?=$radio('cronorder', ['' => gettext('Default'), 'reverse' => gettext('Newest first'), 'forward' => gettext('Oldest first')], $pconfig['cronorder'] ?? '')?>
		<span class="form-label"><?=gettext('Display')?></span>
		<?=$radio('format', ['' => gettext('Default'), 'formatted' => gettext('Formatted'), 'raw' => gettext('Raw')], $pconfig['format'] ?? '')?>
		<div class="form-text"><?=gettext('Raw shows each line as the service wrote it.')?></div>
		<label class="form-label" for="ml-nentries"><?=gettext('Entries shown')?></label>
		<?=$number('nentries', $pconfig['nentries'] ?? '', config_get_path('syslog/nentries', g_get('default_log_entries')), ['min' => 5, 'max' => 200000])?>
	</div>
	<h3 class="fs-logset-title"><?=gettext('Rotation')?></h3>
	<div class="fs-logset-group">
		<label class="form-label" for="ml-logfilesize"><?=gettext('Rotation size (bytes)')?></label>
		<?=$number('logfilesize', $pconfig['logfilesize'] ?? '', config_get_path('syslog/logfilesize', g_get('default_log_size')), ['min' => 100000])?>
		<div class="form-text"><?=fs_h(sprintf(gettext('Rotate the log at this size (default %s). Sizes are checked once a minute.'), format_bytes(g_get('default_log_size'))))?></div>
		<label class="form-label" for="ml-rotatecount"><?=gettext('Files kept')?></label>
		<?=$number('rotatecount', $pconfig['rotatecount'] ?? '', config_get_path('syslog/rotatecount', 7), ['min' => 0, 'max' => 99])?>
		<div class="form-text"><?=gettext('Rotated files to keep before the oldest is removed.')?></div>
	</div>
<?php	if ($logfile == 'system'): ?>
	<h3 class="fs-logset-title"><?=gettext('Sources')?></h3>
	<div class="fs-logset-checks">
		<?=$check('lognginx', gettext('Log web server errors'), !empty($pconfig['lognginx']), gettext('Errors from the nginx process of the GUI and Captive Portal appear in this log.'))?>
	</div>
<?php	endif; ?>
<?php	if ($logfile == 'filter'): ?>
	<h3 class="fs-logset-title"><?=gettext('Log these packets')?></h3>
	<div class="fs-logset-checks">
		<?=$check('logdefaultblock', gettext('Blocked by the default block rule'), !empty($pconfig['logdefaultblock']))?>
		<?=$check('logdefaultpass', gettext('Passed by the default pass rule'), !empty($pconfig['logdefaultpass']))?>
		<?=$check('logbogons', gettext('Blocked by "Block bogon networks"'), !empty($pconfig['logbogons']))?>
		<?=$check('logprivatenets', gettext('Blocked by "Block private networks"'), !empty($pconfig['logprivatenets']))?>
		<?=$check('loglinklocal4', gettext('Blocked by the IPv4 link-local rules'), !empty($pconfig['loglinklocal4']))?>
		<?=$check('logipoptions', gettext('Blocked for unmatched IP options in pass rules'), !empty($pconfig['logipoptions']))?>
		<?=$check('logsnort2c', gettext('Hosts blocked by IDS packages'), !empty($pconfig['logsnort2c']))?>
	</div>
	<div class="fs-logset-group mt-3">
		<label class="form-label" for="ml-filterdescriptions"><?=gettext('Rule descriptions')?></label>
		<select class="form-select" id="ml-filterdescriptions" name="filterdescriptions">
<?php		foreach (['0' => gettext('Do not show'), '1' => gettext('In their own column'), '2' => gettext('Under the action')] as $v => $l): ?>
			<option value="<?=$v?>"<?=((string)($pconfig['filterdescriptions'] ?? '1') === (string)$v) ? ' selected' : ''?>><?=fs_h($l)?></option>
<?php		endforeach; ?>
		</select>
		<div class="form-text"><?=gettext('Looking up descriptions for every row can be slow with large rule sets.')?></div>
	</div>
<?php	endif;
	fs_modal_form_end(gettext('Save'), 'save_settings', 'save', 'fa-floppy-disk');
}
?>
