<?php
/*
 * services_dyndns.php
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
##|*IDENT=page-services-dynamicdnsclients
##|*NAME=Services: Dynamic DNS clients
##|*DESCR=Allow access to the 'Services: Dynamic DNS clients' page.
##|*MATCH=services_dyndns.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_dyndns.inc");

global $dyndns_split_domain_types;

if ($_POST['act'] == "del") {
	dyndns_delete_client($_POST['id']);

	header("Location: services_dyndns.php");
	exit;
} else if ($_POST['act'] == "toggle") {
	if (dyndns_toggle_client($_POST['id'])) {
		header("Location: services_dyndns.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("Dynamic DNS"), gettext("Dynamic DNS Clients"));
$pglinks = array("", "@self", "@self");
fs_page_action(gettext('Add client'), 'services_dyndns_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('services-dyndns', 'services_dyndns.php');
?>
<form action="services_dyndns.php" method="post" name="iform" id="iform">
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Dynamic DNS Clients'),
	'search' => gettext('Search Dynamic DNS clients…'),
	'noun' => gettext('Dynamic DNS clients'),
	'noun_one' => gettext('Dynamic DNS client'),
]); ?>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-hover table-rowdblclickedit">
					<thead>
						<tr>
							<th data-fs-search><?=gettext("Status")?></th>
							<th data-fs-search><?=gettext("Interface")?></th>
							<th data-fs-search><?=gettext("Service")?></th>
							<th data-fs-search><?=gettext("Hostname")?></th>
							<th data-fs-search><?=gettext("Cached IP")?></th>
							<th data-fs-search><?=gettext("Description")?></th>
							<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
						</tr>
					</thead>
					<tbody>
<?php

$iflist = get_configured_interface_with_descr();
$groupslist = return_gateway_groups_array();

$i = 0;
foreach (config_get_path("dyndnses/dyndns", []) as $dyndns):
	if (!is_array($dyndns) || empty($dyndns)) {
		continue;
	}
	if (in_array($dyndns['type'], $dyndns_split_domain_types)) {
		$hostname = $dyndns['host'] . "." . $dyndns['domainname'];
	} else {
		$hostname = $dyndns['host'];
	}
	$filename = "{$g['conf_path']}/dyndns_{$dyndns['interface']}{$dyndns['type']}" . escapeshellarg($hostname) . "{$dyndns['id']}.cache";
	$filename_v6 = "{$g['conf_path']}/dyndns_{$dyndns['interface']}{$dyndns['type']}" . escapeshellarg($hostname) . "{$dyndns['id']}_v6.cache";
	if (file_exists($filename)) {
		$ipaddr = dyndnsCheckIP($dyndns['interface'], array_get_path($dyndns, 'check_ip_mode'), AF_INET);
		$cached_ip_s = explode("|", file_get_contents($filename));
		$cached_ip = $cached_ip_s[0];

		if ($ipaddr == $cached_ip) {
			$icon_class = "fa-solid fa-circle-check";
			$text_class = "text-success";
			$icon_title = "Updated";
		} else {
			$icon_class = "fa-solid fa-circle-xmark";
			$text_class = "text-danger";
			$icon_title = "Failed";
		}
	} else if (file_exists($filename_v6)) {
		$ipv6addr = dyndnsCheckIP($dyndns['interface'], array_get_path($dyndns, 'check_ip_mode'), AF_INET6);
		$cached_ipv6_s = explode("|", file_get_contents($filename_v6));
		$cached_ipv6 = $cached_ipv6_s[0];

		if ($ipv6addr == $cached_ipv6) {
			$icon_class = "fa-solid fa-circle-check";
			$text_class = "text-success";
			$icon_title = "Updated";
		} else {
			$icon_class = "fa-solid fa-circle-xmark";
			$text_class = "text-danger";
			$icon_title = "Failed";
		}
	}
?>
						<tr<?=!isset($dyndns['enable'])?' class="disabled"':''?>>
							<td>
							<?=(file_exists($filename) || file_exists($filename_v6))
							    ? (($icon_title == 'Updated') ? fs_badge('pass', gettext('Updated')) : fs_badge('block', gettext('Failed')))
							    : fs_badge('neutral', gettext('Not updated yet'))?>
							</td>
							<td>
<?php
	foreach ($iflist as $if => $ifdesc) {
		if (str_replace('_stf', '', $dyndns['interface']) == $if) {
			print($ifdesc);

			break;
		}
	}

	foreach ($groupslist as $if => $group) {
		if ($dyndns['interface'] == $if) {
			print($if);
			break;
		}
	}
?>
							</td>
							<td>
<?php
	$types = explode(",", DYNDNS_PROVIDER_DESCRIPTIONS);
	$vals = explode(" ", DYNDNS_PROVIDER_VALUES);

	for ($j = 0; $j < count($vals); $j++) {
		if ($vals[$j] == $dyndns['type']) {
			print(htmlspecialchars($types[$j]));

			break;
		}
	}
?>
							</td>
							<td>
<?php
	print(insert_word_breaks_in_domain_name(htmlspecialchars($hostname)));
?>
							</td>
							<td>
<?php
	if (file_exists($filename)) {
		print("<span class='{$text_class}'>");
		print(htmlspecialchars($cached_ip));
		print('</span>');
	} elseif (file_exists($filename_v6)) {
		print("<span class='{$text_class}'>");
		print(htmlspecialchars($cached_ipv6));
		print('</span>');
	} else {
		print('N/A');
	}
?>
							</td>
							<td>
<?php
	print(htmlspecialchars($dyndns['descr']));
?>
							</td>
							<td class="fs-col-actions">
<?=fs_row_actions([
								['edit', "services_dyndns_edit.php?id={$i}", $hostname ?: $dyndns['descr']],
								['copy', "services_dyndns_edit.php?dup={$i}", $hostname ?: $dyndns['descr']],
								['toggle', "?act=toggle&id={$i}", $hostname ?: $dyndns['descr'], ['enabled' => isset($dyndns['enable'])]],
								['delete', "services_dyndns.php?act=del&id={$i}", $hostname ?: $dyndns['descr'], ['thing' => gettext('client')]],
							])?>
							</td>
						</tr>
<?php
	$i++;
	endforeach;
?>
<?php if ($i == 0) {
	fs_empty_row(7, gettext('No Dynamic DNS clients yet.'), 'services_dyndns_edit.php', gettext('Add client'));
} ?>
					</tbody>
			  </table>
			</div>
		</div>
	</div>
</form>


<p class="help-block"><?=gettext('An update can be forced on the edit page for an entry.')?></p>

<?php
include("foot.inc");
