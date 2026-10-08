<?php
/*
 * firewall_aliases.php
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
##|*IDENT=page-firewall-aliases
##|*NAME=Firewall: Aliases
##|*DESCR=Allow access to the 'Firewall: Aliases' page.
##|*MATCH=firewall_aliases.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("alias-utils.inc");

$tab = ($_REQUEST['tab'] == "" ? "ip" : preg_replace("/\W/", "", $_REQUEST['tab']));

if ($_POST['apply']) {
	$retval = 0;

	/* reload all components that use aliases */
	$retval |= filter_configure();

	if ($retval == 0) {
		clear_subsystem_dirty('aliases');
	}
}


if ($_POST['act'] == "del") {
	$delete_error = deleteAlias($_POST['id']);

	if (strlen($delete_error) == 0) {
		header("Location: firewall_aliases.php?tab=" . $tab);
		exit;
	}
}

/* host/network are shown on the IP tab */
$tab_key = in_array($tab, ['host', 'network'], true) ? 'ip' : $tab;
$tab_titles = ['ip' => gettext("IP"), 'port' => gettext("Ports"), 'url' => gettext("URLs"), 'all' => gettext("All")];
$bctab = $tab_titles[$tab_key] ?? '';

$pgtitle = array(gettext("Firewall"), gettext("Aliases"), $bctab);
$pglinks = array("", "firewall_aliases.php", "@self");
$shortcut_section = "aliases";

fs_page_action(gettext('Add alias'), 'firewall_aliases_edit.php?tab=' . urlencode($tab), 'fa-plus');
if (in_array($tab_key, ['ip', 'port', 'all'], true)) {
	fs_page_action(gettext('Import'), 'firewall_aliases_import.php?tab=' . urlencode($tab), 'fa-upload', 'secondary');
}

/* first value(s) of an alias for the list: up to 10, then an ellipsis */
function alias_list_values($alias) {
	if ($alias["url"]) {
		return htmlspecialchars($alias["url"]);
	}
	$out = [];
	if (is_array($alias["aliasurl"])) {
		$out[] = htmlspecialchars(implode(", ", array_slice($alias["aliasurl"], 0, 10))) .
		    ((count($alias["aliasurl"]) > 10) ? '&hellip;' : '');
	}
	if (!empty($alias['address'])) {
		$tmpaddr = explode(" ", $alias['address']);
		if ($alias['type'] == 'host') {
			$tmpaddr = array_map('alias_idn_to_utf8', $tmpaddr);
		}
		$out[] = htmlspecialchars(implode(", ", array_slice($tmpaddr, 0, 10))) . ((count($tmpaddr) > 10) ? '&hellip;' : '');
	}
	return implode('<br />', $out);
}

include("head.inc");

if ($delete_error) {
	print_info_box($delete_error, 'danger');
}
if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('aliases')) {
	print_apply_box(gettext("The alias list has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('firewall-aliases', 'firewall_aliases.php?tab=' . $tab_key);

/* type filter on the All tab */
$type_filter = [];
if ($tab_key == 'all') {
	$type_filter = ['type' => array_merge([gettext('All types')], $alias_types)];
}
?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => sprintf(gettext('Firewall Aliases %s'), $bctab),
	'search' => gettext('Search aliases…'),
	'noun' => gettext('aliases'),
	'noun_one' => gettext('alias'),
	'filters' => $type_filter,
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Name")?></th>
					<th data-fs-search><?=gettext("Type")?></th>
					<th data-fs-search><?=gettext("Values")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
	/* Ensure aliases are presented in natural sort order so they are easier to locate.
	 * and preserve keys so that the IDs match in the config and the list.
	 * upstream issue 14015 */
	$aliases = get_sorted_aliases();
	$shown = 0;

	foreach ($aliases as $i => $alias):
		switch ($tab_key) {
		case "all":
			$show_alias = true;
			break;
		case "ip":
			$show_alias = (bool)preg_match("/(host|network)/", $alias["type"]);
			break;
		case "url":
			$show_alias = (bool)preg_match("/(url)/i", $alias["type"]);
			break;
		case "port":
			$show_alias = ($alias["type"] == "port");
			break;
		default:
			$show_alias = false;
		}
		if (!$show_alias) {
			continue;
		}
		$shown++;
?>
				<tr data-fs-filter-type="<?=htmlspecialchars($alias['type'])?>">
					<td><a href="firewall_aliases_edit.php?id=<?=$i?>"><?=htmlspecialchars($alias['name'])?></a></td>
					<td class="text-nowrap"><?=htmlspecialchars($alias_types[$alias['type']])?></td>
					<td class="fs-mono"><?=alias_list_values($alias)?></td>
					<td><?=htmlspecialchars($alias['descr'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', "firewall_aliases_edit.php?id={$i}", $alias['name']],
							['copy', "firewall_aliases_edit.php?dup={$i}", $alias['name']],
							['delete', "?act=del&tab=" . htmlspecialchars($tab) . "&id={$i}", $alias['name'], ['thing' => gettext('alias'),
							    'detail' => gettext('An alias that is still used by rules or other aliases cannot be deleted.')]],
						])?>
					</td>
				</tr>
<?php
	endforeach;

	if ($shown == 0) {
		fs_empty_row(5, gettext('No aliases yet.'), 'firewall_aliases_edit.php?tab=' . $tab, gettext('Add alias'));
	}
?>
			</tbody>
		</table>
	</div>
</div>

<?php
/* Show system aliases. */
if ($tab_key == 'all'):
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('System Aliases'),
	'search' => gettext('Search system aliases…'),
	'noun' => gettext('system aliases'),
	'noun_one' => gettext('system alias'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Name")?></th>
					<th data-fs-search><?=gettext("Type")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th data-fs-search><?=gettext("Values")?></th>
				</tr>
			</thead>
			<tbody>
<?php
	foreach (get_reserved_table_names() as $alias):
		$values = alias_list_values($alias);
?>
				<tr>
					<td><?=htmlspecialchars($alias['name'])?></td>
					<td class="text-nowrap"><?=htmlspecialchars($alias_types[$alias['type']])?></td>
					<td><?=htmlspecialchars($alias['descr'])?></td>
					<td class="fs-mono"><?=($values !== '') ? $values : '<span class="fs-muted">' . gettext('Values set dynamically.') . '</span>'?></td>
				</tr>
<?php
	endforeach;
?>
			</tbody>
		</table>
	</div>
</div>
<?php
endif;
?>

<div class="infoblock">
	<?php print_info_box(gettext('Aliases act as placeholders for real hosts, networks or ports. They can be used to minimize the number ' .
		'of changes that have to be made if a host, network or port changes.') . '<br />' .
		gettext('The name of an alias can be entered instead of the host, network or port where indicated. The alias will be resolved according to the list above.') . '<br />' .
		gettext('If an alias cannot be resolved (e.g. because it was deleted), the corresponding element (e.g. filter/NAT/shaper rule) will be considered invalid and skipped.'), 'info', false); ?>
</div>

<?php
include("foot.inc");
