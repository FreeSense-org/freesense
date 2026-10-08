<?php
/*
 * services_rfc2136.php
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
##|*IDENT=page-services-rfc2136clients
##|*NAME=Services: RFC 2136 Clients
##|*DESCR=Allow access to the 'Services: RFC 2136 Clients' page.
##|*MATCH=services_rfc2136.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_dyndns.inc");

if ($_POST['act'] == "del") {
	rfc2136_delete_client($_POST['id']);
	header("Location: services_rfc2136.php");
	exit;
} else if ($_POST['act'] == "toggle") {
	if (rfc2136_toggle_client($_POST['id'])) {
		header("Location: services_rfc2136.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("Dynamic DNS"), gettext("RFC 2136 Clients"));
$pglinks = array("", "services_dyndns.php", "@self");
fs_page_action(gettext('Add client'), 'services_rfc2136_edit.php', 'fa-plus');
include("head.inc");

fs_tabs('services-dyndns', 'services_rfc2136.php');

if ($input_errors) {
	print_input_errors($input_errors);
}
?>

<form action="services_rfc2136.php" method="post" name="iform" id="iform">
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('RFC2136 Clients'),
	'search' => gettext('Search RFC 2136 clients…'),
	'noun' => gettext('RFC 2136 clients'),
	'noun_one' => gettext('RFC 2136 client'),
]); ?>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-hover table-rowdblclickedit">
					<thead>
						<tr>
							<th data-fs-search><?=gettext("Status")?></th>
							<th data-fs-search><?=gettext("Interface")?></th>
							<th data-fs-search><?=gettext("Server")?></th>
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
foreach (config_get_path('dnsupdates/dnsupdate', []) as $rfc2136):
	if (!is_array($rfc2136) || empty($rfc2136)) {
		continue;
	}
	$filename = "{$g['conf_path']}/dyndns_{$rfc2136['interface']}_rfc2136_" . escapeshellarg($rfc2136['host']) . "_{$rfc2136['server']}.cache";
	$filename_v6 = "{$g['conf_path']}/dyndns_{$rfc2136['interface']}_rfc2136_" . escapeshellarg($rfc2136['host']) . "_{$rfc2136['server']}_v6.cache";
	$if = get_failover_interface($rfc2136['interface']);

	if (file_exists($filename)) {
		if (isset($rfc2136['usepublicip'])) {
			$ipaddr = dyndnsCheckIP($if, null, AF_INET);
		} else {
			$ipaddr = get_interface_ip($if);
		}

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
	} elseif (file_exists($filename_v6)) {
		$ipv6addr = get_interface_ipv6($if);
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
						<tr<?=(isset($rfc2136['enable']) ? '' : ' class="disabled"')?>>
							<td>
							<?=(file_exists($filename) || file_exists($filename_v6))
							    ? (($icon_title == 'Updated') ? fs_badge('pass', gettext('Updated')) : fs_badge('block', gettext('Failed')))
							    : fs_badge('neutral', gettext('Not updated yet'))?>
							</td>
							<td>
<?php
	foreach ($iflist as $ifname => $ifdesc) {
		if ($rfc2136['interface'] == $ifname) {
			print($ifdesc);
			break;
		}
	}
	foreach ($groupslist as $ifname => $group) {
		if ($rfc2136['interface'] == $ifname) {
			print($ifname);
			break;
		}
	}
?>
							</td>
							<td>
								<?=htmlspecialchars($rfc2136['server'])?>
							</td>
							<td>
								<?=htmlspecialchars($rfc2136['host'])?>
							</td>
							<td>
<?php
	if (file_exists($filename)) {
		print('IPv4: ');
		print("<span class='{$text_class}'>");
		print(htmlspecialchars($cached_ip));
		print('</span>');
	} else {
		print('IPv4: N/A');
	}

	print('<br />');

	if (file_exists($filename_v6)) {
		print('IPv6: ');
		$ipaddr = get_interface_ipv6($if);
		$cached_ip_s = explode("|", file_get_contents($filename_v6));
		$cached_ip = $cached_ip_s[0];

		if ($ipaddr != $cached_ip) {
			print('<span class="text-danger">');
		} else {
			print('<span class="text-success">');
		}

		print(htmlspecialchars($cached_ip));
		print('</span>');
	} else {
		print('IPv6: N/A');
	}

?>
					</td>
					<td>
						<?=htmlspecialchars($rfc2136['descr'])?>
					</td>
					<td class="fs-col-actions">
<?=fs_row_actions([
							['edit', "services_rfc2136_edit.php?id={$i}", $rfc2136['host'] ?: $rfc2136['descr']],
							['copy', "services_rfc2136_edit.php?dup={$i}", $rfc2136['host'] ?: $rfc2136['descr']],
							['toggle', "?act=toggle&id={$i}", $rfc2136['host'] ?: $rfc2136['descr'], ['enabled' => isset($rfc2136['enable'])]],
							['delete', "services_rfc2136.php?act=del&id={$i}", $rfc2136['host'] ?: $rfc2136['descr'], ['thing' => gettext('client')]],
						])?>
					</td>
					</tr>
<?php
	$i++;
endforeach; ?>

<?php if ($i == 0) {
	fs_empty_row(7, gettext('No RFC 2136 clients yet.'), 'services_rfc2136_edit.php', gettext('Add client'));
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
