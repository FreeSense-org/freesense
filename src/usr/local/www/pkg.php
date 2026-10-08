<?php
/*
 * pkg.php
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
##|*IDENT=page-package-settings
##|*NAME=Package: Settings
##|*DESCR=Allow access to the 'Package: Settings' page.
##|*MATCH=pkg.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("pkg-utils.inc");

function domTT_title($title_msg) {
	print "onmouseout=\"this.style.color = ''; domTT_mouseout(this, event);\" onmouseover=\"domTT_activate(this, event, 'content', '".gettext($title_msg)."', 'trail', true, 'delay', 0, 'fade', 'both', 'fadeMax', 93, 'styleClass', 'niceTitle');\"";
}

$xml = $_REQUEST['xml'];

if ($xml == "") {
	$pgtitle = array(gettext("Package"), gettext("Editor"));
	$pglinks = array("", "@self");
	include("head.inc");
	print_info_box(gettext("No valid package defined."), 'danger', false);
	include("foot.inc");
	exit;
} else {
	$pkg_xml_prefix = "/usr/local/pkg/";
	$pkg_full_path = "{$pkg_xml_prefix}/{$xml}";
	$pkg_realpath = realpath($pkg_full_path);
	if (empty($pkg_realpath)) {
		$path_error = sprintf(gettext("Package path %s not found."), htmlspecialchars($pkg_full_path));
	} else if (substr_compare($pkg_realpath, $pkg_xml_prefix, 0, strlen($pkg_xml_prefix))) {
		$path_error = sprintf(gettext("Invalid path %s specified."), htmlspecialchars($pkg_full_path));
	}

	if (!empty($path_error)) {
		include("head.inc");
		print_info_box($path_error . "<br />" . gettext("Try reinstalling the package."), 'danger', false);
		include("foot.inc");
		die;
	}

	if (file_exists($pkg_full_path)) {
		$pkg = parse_xml_config_pkg($pkg_full_path, "packagegui");
	} else {
		include("head.inc");
		print_info_box(sprintf(gettext("File not found %s."), htmlspecialchars($xml)), 'danger', false);
		include("foot.inc");
		exit;
	}
}

if ($pkg['donotsave'] != "") {
	header("Location: pkg_edit.php?xml=" . $xml);
	exit;
}

if ($pkg['include_file'] != "") {
	require_once($pkg['include_file']);
}

if ($_REQUEST['startdisplayingat']) {
	$startdisplayingat = $_REQUEST['startdisplayingat'];
}

if ($_REQUEST['display_maximum_rows']) {
	if ($_REQUEST['display_maximum_rows']) {
		$display_maximum_rows = $_REQUEST['display_maximum_rows'];
	}
}

$pkg_config_path = sprintf('installedpackages/%s', xml_safe_fieldname($pkg['name']));

$evaledvar = config_get_path("{$pkg_config_path}/config", []);

if ($_POST['act'] == "update") {

	if (is_array(config_get_path($pkg_config_path)) && $pkg['name'] != "" && $_POST['ids'] !="") {
		// get current values
		$current_values = config_get_path("{$pkg_config_path}/config", []);
		// get updated ids
		parse_str($_POST['ids'], $update_list);
		// sort ids to know what to change
		// useful to do not lose data when using sorting and paging
		$sort_list=$update_list['ids'];
		sort($sort_list);
		// apply updates
		foreach ($update_list['ids'] as $key=> $value) {
			config_set_path("{$pkg_config_path}/config/{$sort_list[$key]}", $current_values[$update_list['ids'][$key]]);
		}
		// save current config
		write_config(gettext("Package configuration changes saved from package settings page."));
		// sync package
		eval ("{$pkg['custom_php_resync_config_command']}");
	}
	// function called via jquery, no need to continue after save changes.
	exit;
}
if ($_REQUEST['act'] == "del") {
	// loop through our fieldnames and automatically setup the fieldnames
	// in the environment.	ie: a fieldname of username with a value of
	// testuser would automatically eval $username = "testuser";
	foreach ($evaledvar as $ip) {
		if ($pkg['adddeleteeditpagefields']['columnitem']) {
			foreach ($pkg['adddeleteeditpagefields']['columnitem'] as $column) {
				${xml_safe_fieldname($column['fielddescr'])} = $ip[xml_safe_fieldname($column['fieldname'])];
			}
		}
	}

	if (config_get_path("{$pkg_config_path}/config/{$_REQUEST['id']}")) {
		config_del_path("{$pkg_config_path}/config/{$_REQUEST['id']}");
		write_config(gettext("Package configuration item deleted from package settings page."));
		if ($pkg['custom_delete_php_command'] != "") {
			if ($pkg['custom_php_command_before_form'] != "") {
				eval($pkg['custom_php_command_before_form']);
			}
			eval($pkg['custom_delete_php_command']);
		}
		header("Location:  pkg.php?xml=" . $xml);
		exit;
	}
}

ob_start();

$iflist = get_configured_interface_with_descr(true);
$evaledvar = config_get_path("{$pkg_config_path}/config", []);

if ($pkg['custom_php_global_functions'] != "") {
	eval($pkg['custom_php_global_functions']);
}

if ($pkg['custom_php_command_before_form'] != "") {
	eval($pkg['custom_php_command_before_form']);
}

// Breadcrumb
if ($pkg['title'] != "") {
	/*if (!$only_edit) {						// Is any package still making use of this?? Is this something that is still wanted, considering the breadcrumb policy upstream issue 5527
 		$pkg['title'] = $pkg['title'] . '/Edit';		// If this needs to live on, then it has to be moved to run AFTER "foreach ($pkg['tabs']['tab'] as $tab)"-loop. This due to $pgtitle[] = $tab['text'];
	}*/
	if (strpos($pkg['title'], '/')) {
		$title = explode('/', $pkg['title']);

		foreach ($title as $subtitle) {
			$pgtitle[] = gettext($subtitle);
			$pglinks[] = "@self";
		}
	} else {
		$pgtitle = array(gettext("Package"), gettext($pkg['title']));
		$pglinks = array("", "@self");
	}
} else {
	$pgtitle = array(gettext("Package"), gettext("Editor"));
	$pglinks = array("", "@self");
}

if ($pkg['tabs'] != "") {
	$tab_array = array();
	foreach ($pkg['tabs']['tab'] as $tab) {
		if ($tab['tab_level']) {
			$tab_level = $tab['tab_level'];
		} else {
			$tab_level = 1;
		}
		if (isset($tab['active'])) {
			$active = true;
			$pgtitle[] = $tab['text'];
			$pglinks[] = "@self";
		} else {
			$active = false;
		}
		$urltmp = "";
		if ($tab['url'] != "") {
			$urltmp = $tab['url'];
		}
		if ($tab['xml'] != "") {
			$urltmp = "pkg_edit.php?xml=" . $tab['xml'];
		}

		$addresswithport = getenv("HTTP_HOST");
		$colonpos = strpos($addresswithport, ":");
		if ($colonpos !== False) {
			//my url is actually just the IP address of the freesense box
			$myurl = substr($addresswithport, 0, $colonpos);
		} else {
			$myurl = $addresswithport;
		}
		// eval url so that above $myurl item can be processed if need be.
		$url = str_replace('$myurl', $myurl, $urltmp);

		$tab_array[$tab_level][] = array(
			$tab['text'],
			$active,
			$url
		);
	}

	ksort($tab_array);
}

if (!empty($pkg['tabs'])) {
	$shortcut_section = $pkg['shortcut_section'];
}

/* ------------------------------------------------------------------ list model */

$pagefields = is_array($pkg['adddeleteeditpagefields']) ? $pkg['adddeleteeditpagefields'] : [];
$columns = is_array($pagefields['columnitem']) ? $pagefields['columnitem'] : [];
$movable = !empty($pagefields['movable']);
if (!is_array($evaledvar)) {
	$evaledvar = [];
}
$xml_url = str_replace('%2F', '/', rawurlencode($xml));
$add_href = 'pkg_edit.php?xml=' . $xml_url . '&id=' . count($evaledvar);
$add_msg = $pagefields['addtext'] ? $pagefields['addtext'] : gettext("Add a new item");
$edit_msg = $pagefields['edittext'] ? $pagefields['edittext'] : '';
$delete_msg = $pagefields['deletetext'] ? $pagefields['deletetext'] : '';

/*
 * One list cell, formatted as before (checkbox Yes/No, interface description,
 * base64, listmodeon/off, prefix/suffix). Returns [text, is_html]; HTML only
 * when the package XML sets allow_html on the column.
 */
$pkg_cell = function ($column, $ip) use ($iflist) {
	$value = $ip[xml_safe_fieldname($column['fieldname'])];
	if (is_array($value)) {
		$value = implode(', ', $value);
	}
	if ($column['type'] == "checkbox") {
		return [($value == "") ? gettext("No") : gettext("Yes"), false];
	}
	if ($column['type'] == "interface") {
		return [$column['prefix'] . $iflist[$value] . $column['suffix'], false];
	}
	if ($column['encoding'] == "base64") {
		$text = $column['prefix'] . base64_decode($value) . $column['suffix'];
	} else if ($column['listmodeon'] && $value != "") {
		$text = $column['prefix'] . gettext($column['listmodeon']) . $column['suffix'];
	} else if ($column['listmodeoff'] && $value == "") {
		$text = $column['prefix'] . gettext($column['listmodeoff']) . $column['suffix'];
	} else {
		$text = $column['prefix'] . $value . " " . $column['suffix'];
	}
	return [trim($text), isset($column['allow_html'])];
};

/* Package "sorting" field: server-side filter (field + text, A-Z) and paging */
$sorting = null;
foreach ((is_array($pkg['fields']['field']) ? $pkg['fields']['field'] : []) as $field) {
	if ($field['type'] == "sorting") {
		$sorting = $field;
		if ($display_maximum_rows < 1 && $field['display_maximum_rows']) {
			$display_maximum_rows = $field['display_maximum_rows'];
		}
	}
}

/* Rows to show: the same paging and filter rules as before */
$rows = [];
$i = 0;
$filter_regex = null;
$filter_fieldname = null;
if ($_REQUEST['pkg_filter'] && $sorting && is_array($sorting['sortablefields']['item'])) {
	foreach ($sorting['sortablefields']['item'] as $sf) {
		if ($sf['name'] == $_REQUEST['pkg_filter_type']) {
			$filter_fieldname = $sf['fieldname'];
			# Use a default regex on sortable fields when none is declared
			$pkg_filter = cleanup_regex_pattern(htmlspecialchars(strip_tags($_REQUEST['pkg_filter'])));
			if ($sf['regex']) {
				$filter_regex = str_replace("%FILTERTEXT%", $pkg_filter, trim($sf['regex']));
			} else {
				$filter_regex = "/{$pkg_filter}/i";
			}
		}
	}
}
foreach ($evaledvar as $idx => $ip) {
	if ($startdisplayingat && ($i < $startdisplayingat)) {
		$i++;
		continue;
	}
	if ($_REQUEST['pkg_filter']) {
		$filter_matches = null;
		foreach ($columns as $column) {
			if ($filter_regex && ($column['fieldname'] == $filter_fieldname)) {
				preg_match($filter_regex, $ip[xml_safe_fieldname($column['fieldname'])], $filter_matches);
				break;
			}
		}
		if (!$filter_matches) {
			$i++;
			continue;
		}
	}
	$rows[$i] = $ip;
	$i++;
	if ($display_maximum_rows && (count($rows) >= $display_maximum_rows)) {
		break;
	}
}
$last_shown = $i;

fs_page_action(gettext('Add'), $add_href, 'fa-plus', 'primary', ['title' => $add_msg]);

include("head.inc");
if (isset($tab_array)) {
	foreach ($tab_array as $tab) {
		display_top_tabs($tab);
	}
}

if ($_REQUEST['savemsg'] != "") {
	$savemsg = htmlspecialchars($_REQUEST['savemsg']);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

/* toolbar title: the active tab, else the last part of the package title */
$list_title = '';
foreach ((is_array($pkg['tabs']['tab']) ? $pkg['tabs']['tab'] : []) as $tab) {
	if (isset($tab['active'])) {
		$list_title = $tab['text'];
	}
}
if ($list_title === '' && $pkg['title'] != '') {
	$parts = explode('/', $pkg['title']);
	$list_title = gettext(trim(end($parts)));
}

$req_filter_type = (string)$_REQUEST['pkg_filter_type'];
$req_filter_html = htmlspecialchars((string)$_REQUEST['pkg_filter'], ENT_QUOTES);

ob_start();
if ($sorting) {
	if ($sorting['sortablefields']) {
		echo '<select name="pkg_filter_type" class="form-select form-select-sm" aria-label="' . fs_h(gettext('Filter field')) . '">';
		foreach ($sorting['sortablefields']['item'] as $si) {
			$opt_selected = ($si['name'] == $req_filter_type);
			echo '<option value="' . htmlspecialchars($si['name']) . '"';
			if ($opt_selected) {
				echo ' selected';
			}
			echo '>' . htmlspecialchars($si['name']) . '</option>';
		}
		echo '</select>';
	}
	if (isset($sorting['include_filtering_inputbox'])) {
		echo '<input id="pkg_filter" name="pkg_filter" class="form-control form-control-sm fs-pkg-filter" value="' . $req_filter_html . '" placeholder="' . fs_h(gettext('Filter text')) . '" aria-label="' . fs_h(gettext('Filter text')) . '">';
		echo '<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-filter icon-embed-btn" aria-hidden="true"></i>' . gettext("Filter") . '</button>';
	} else {
		echo '<input type="hidden" id="pkg_filter" name="pkg_filter" value="' . $req_filter_html . '">';
	}
}
if ($display_maximum_rows) {
	echo '<select name="display_maximum_rows" class="form-select form-select-sm" data-pkg-autosubmit aria-label="' . fs_h(gettext('Rows per page')) . '">';
	for ($x = 0; $x < 250; $x += 5) {
		echo '<option value="' . $x . '"' . (($x == $display_maximum_rows) ? ' selected' : '') . '>' . sprintf(gettext('%s per page'), $x) . '</option>';
	}
	echo '</select>';
}
$toolbar_custom = ob_get_clean();

ob_start();
if ($movable): ?>
	<span id="savemsg" class="fs-muted small" aria-live="polite"></span>
	<button type="button" id="pkg-save-order" class="btn btn-sm btn-outline-secondary" disabled
		data-fs-confirm="<?=gettext('Save the new order?')?>" data-fs-confirm-action="<?=gettext('Save order')?>">
		<i class="fa-solid fa-floppy-disk icon-embed-btn" aria-hidden="true"></i><?=gettext('Save order')?>
	</button>
<?php endif;
$toolbar_actions = ob_get_clean();
?>

<form action="pkg.php" name="pkgform" method="get">
	<input type="hidden" name="xml" value="<?=fs_h($_REQUEST['xml'])?>" />
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => $list_title,
	'noun' => gettext('entries'),
	'noun_one' => gettext('entry'),
	'custom' => $toolbar_custom,
	'actions' => $toolbar_actions,
]); ?>
<?php if ($sorting): ?>
		<nav class="fs-pkg-letters" aria-label="<?=gettext('Filter by first letter')?>">
<?php for ($char = 65; $char < 91; $char++): ?>
			<a href="#" data-pkg-filter="<?=chr($char)?>"<?=($_REQUEST['pkg_filter'] === chr($char)) ? ' aria-current="true"' : ''?>><?=chr($char)?></a>
<?php endfor; ?>
		</nav>
<?php endif; ?>
		<div id="mainarea" class="panel-body table-responsive">
			<table class="table table-hover table-rowdblclickedit"<?=$movable ? '' : ' data-sortable'?>>
				<thead>
					<tr>
<?php if ($movable): ?>
						<th class="fs-col-icon"><span class="visually-hidden"><?=gettext('Order')?></span></th>
<?php endif; ?>
<?php foreach ($columns as $column): ?>
						<th data-fs-search><?=fs_h($column['fielddescr'])?></th>
<?php endforeach; ?>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
					</tr>
				</thead>
				<tbody>
<?php
foreach ($rows as $i => $ip):
	$cells = [];
	$name = '';
	foreach ($columns as $column) {
		$cell = $pkg_cell($column, $ip);
		$cells[] = [$column, $cell[0], $cell[1]];
		if ($name === '' && $column['type'] != 'checkbox') {
			$name = trim(strip_tags($cell[0]));
		}
	}
	if ($name === '') {
		$name = sprintf(gettext('entry %s'), $i + 1);
	}
	$actions = [
		['edit', 'pkg_edit.php?xml=' . $xml_url . '&act=edit&id=' . $i, $name,
		    $edit_msg ? ['attrs' => ['title' => $edit_msg]] : []],
		['delete', 'pkg.php?xml=' . $xml_url . '&act=del&id=' . $i, $name,
		    $delete_msg ? ['attrs' => ['title' => $delete_msg]] : []],
	];
?>
					<tr<?=$movable ? ' class="sortable" id="id_' . (int)$i . '"' : ''?>>
<?php if ($movable): ?>
						<td class="fs-col-icon fs-pkg-grip" title="<?=gettext('Drag to reorder')?>"><i class="fa-solid fa-grip-vertical" aria-hidden="true"></i></td>
<?php endif; ?>
<?php foreach ($cells as $n => list($column, $text, $is_html)):
	$class = [];
	if ($column['type'] == 'checkbox' && $text === gettext('No')) {
		$class[] = 'fs-muted';
	} elseif (!$is_html && preg_match('/^[0-9a-f.:\/]+$/i', $text) && preg_match('/[0-9]/', $text) && preg_match('/[.:\/]/', $text)) {
		$class[] = 'fs-mono';
	}
	if ($n === 0) {
		$class[] = 'fs-pkg-first';
	}
?>
						<td<?=$class ? ' class="' . implode(' ', $class) . '"' : ''?>><?=$is_html ? $text : fs_h($text)?></td>
<?php endforeach; ?>
						<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
					</tr>
<?php
endforeach;

if (empty($rows)) {
	if (!empty($evaledvar) && $_REQUEST['pkg_filter']) {
		fs_empty_row(count($columns) + ($movable ? 2 : 1), gettext('No entries match the filter.'));
	} else {
		fs_empty_row(count($columns) + ($movable ? 2 : 1), gettext('No entries yet.'),
		    $add_href, gettext('Add'));
	}
}
?>
				</tbody>
			</table>
		</div>
<?php
if ($display_maximum_rows && count($evaledvar) > 0):
	$first_shown = empty($rows) ? 0 : array_key_first($rows) + 1;
	$prev = $startdisplayingat - $display_maximum_rows;
?>
		<div class="panel-footer fs-pkg-pager small">
<?php if ($startdisplayingat > 0): ?>
			<a href="pkg.php?xml=<?=fs_h($xml_url)?>&amp;startdisplayingat=<?=max(0, (int)$prev)?>&amp;display_maximum_rows=<?=(int)$display_maximum_rows?>"><i class="fa-solid fa-chevron-left icon-embed-btn" aria-hidden="true"></i><?=gettext("Previous page")?></a>
<?php else: ?>
			<span></span>
<?php endif; ?>
			<span class="fs-muted"><?=sprintf(gettext('Showing %1$s–%2$s of %3$s'), $first_shown, empty($rows) ? 0 : array_key_last($rows) + 1, count($evaledvar))?></span>
<?php if ($last_shown < count($evaledvar)): ?>
			<a href="pkg.php?xml=<?=fs_h($xml_url)?>&amp;startdisplayingat=<?=(int)($startdisplayingat + $display_maximum_rows)?>&amp;display_maximum_rows=<?=(int)$display_maximum_rows?>"><?=gettext("Next page")?><i class="fa-solid fa-chevron-right icon-embed-btn fs-pkg-next" aria-hidden="true"></i></a>
<?php else: ?>
			<span></span>
<?php endif; ?>
		</div>
<?php endif; ?>
<?php if ($pagefields['description']): ?>
		<div class="panel-footer small fs-muted">
			<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
			<?=$pagefields['description']?>
		</div>
<?php endif; ?>
<?php if ($movable): ?>
		<div class="panel-footer small fs-muted">
			<i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>
			<?=gettext('Drag rows to change their order, then save the order.')?>
		</div>
<?php endif; ?>
	</div>
</form>

<style>
.fs-pkg-letters { display: flex; flex-wrap: wrap; gap: 2px 6px; padding: .4rem 1rem; border-bottom: 1px solid var(--fs-border-color); font-size: var(--fs-fs-sm); }
.fs-pkg-letters a { min-width: 1.1rem; text-align: center; text-decoration: none; }
.fs-pkg-letters a[aria-current] { font-weight: 600; color: var(--fs-text-strong); }
.fs-pkg-filter { width: auto; max-width: 12rem; }
.fs-pkg-pager { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.fs-pkg-next { margin: 0 0 0 .35rem; }
.fs-pkg-grip { cursor: grab; }
td.fs-pkg-first { font-weight: 600; color: var(--fs-text-strong); }
tr.ui-sortable-helper { background: var(--fs-surface-raised); }
</style>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// A-Z filter of the package "sorting" field
	$('[data-pkg-filter]').on('click', function(e) {
		e.preventDefault();
		$('#pkg_filter').val($(this).attr('data-pkg-filter'));
		document.pkgform.submit();
	});
	$('[data-pkg-autosubmit]').on('change', function() {
		document.pkgform.submit();
	});

<?php if ($movable): ?>
	$('#mainarea table tbody').sortable({
		items: 'tr.sortable',
		cursor: 'grabbing',
		distance: 10,
		opacity: 0.8,
		helper: function(e, ui) {
			ui.children().each(function() {
				$(this).width($(this).width());
			});
			return ui;
		},
		update: function() {
			$('#pkg-save-order').prop('disabled', false);
			$('#savemsg').text('');
		}
	});

	// runs after the data-fs-confirm question (js/freesense-ui.js)
	$('#pkg-save-order').on('click', function() {
		save_changes_to_xml();
	});
<?php endif; ?>
});

function save_changes_to_xml(xml) {
	var ids = $('#mainarea table tbody').sortable('serialize', {key:"ids[]"});
	$.ajax({
		type: 'post',
		cache: false,
		url: <?=json_encode($_SERVER['SCRIPT_NAME'])?>,
		data: {xml: <?=json_encode((string)$xml)?>, act: 'update', ids: ids},
		beforeSend: function() {
			$('#savemsg').text(<?=json_encode(gettext('Saving changes...'))?>);
		},
		error: function() {
			$('#savemsg').text(<?=json_encode(gettext('The order could not be saved.'))?>);
		},
		success: function() {
			$('#savemsg').text(<?=json_encode(gettext('Order saved.'))?>);
			$('#pkg-save-order').prop('disabled', true);
		}
	});
}
//]]>
</script>

<?php
include("foot.inc");
