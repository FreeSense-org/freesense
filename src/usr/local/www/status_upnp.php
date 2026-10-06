<?php
/*
 * status_upnp.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
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
##|*IDENT=page-status-upnpstatus
##|*NAME=Status: UPnP IGD & PCP
##|*DESCR=Allow access to the 'Status: UPnP IGD & PCP' page.
##|*MATCH=status_upnp.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_upnp.inc");

$pgtitle = array(gettext("Status"), gettext("UPnP IGD &amp; PCP"));
$shortcut_section = "upnp";

$enabled = (config_get_path('installedpackages/miniupnpd/config/0/enable') == 'on');

if (!$enabled) {
	include("head.inc");
	print_info_box(sprintf(gettext('Service is currently disabled. It can be enabled here: %1$s%2$s%3$s.'), '<a href="pkg_edit.php?xml=miniupnpd.xml">', gettext('Services &gt; UPnP IGD &amp; PCP'), '</a>'), 'danger');
	include("foot.inc");
	exit;
}

if ($_POST) {
	if ($_POST['delete-all']) {
		upnp_action('restart');
		$savemsg = gettext("Port maps have been deleted and the service restarted.");
	}
}

$port_maps = upnp_port_maps();

$protos = [];
$clients = [];
foreach ($port_maps as $map) {
	$protos[strtolower($map['proto'])] = strtoupper($map['proto']);
	$clients[$map['intaddr']] = true;
}
ksort($protos);

fs_page_action(gettext('Delete all port maps'), 'status_upnp.php?delete-all=delete-all', 'fa-trash-can', 'danger', [
	'usepost' => true,
	'data-fs-confirm' => gettext('Delete all UPnP and PCP port maps?'),
	'data-fs-confirm-detail' => gettext('The service restarts. Clients add their port maps again when they need them.'),
	'data-fs-confirm-action' => gettext('Delete all'),
]);

include("head.inc");

if ($savemsg) {
	print_info_box($savemsg, 'success');
}
?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Port maps'), count($port_maps));
fs_tile(gettext('Clients'), count($clients));
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Active port maps'),
	'search' => gettext('Search ports, addresses, descriptions…'),
	'noun' => gettext('port maps'),
	'noun_one' => gettext('port map'),
	'filters' => (count($protos) > 1) ? ['proto' => [gettext('All protocols')] + $protos] : [],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("External interface")?></th>
					<th data-fs-search><?=gettext("External port")?></th>
					<th data-fs-search><?=gettext("Internal IP")?></th>
					<th data-fs-search><?=gettext("Internal port")?></th>
					<th data-fs-search><?=gettext("Protocol")?></th>
					<th data-fs-search><?=gettext("Source IP")?></th>
					<th data-fs-search><?=gettext("Source port")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($port_maps as $map): ?>
				<tr data-fs-filter-proto="<?=htmlspecialchars(strtolower($map['proto']))?>">
					<td><?=htmlspecialchars(convert_real_interface_to_friendly_descr($map['iface']))?></td>
					<td class="fs-mono"><?=htmlspecialchars($map['extport'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($map['intaddr'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($map['intport'])?></td>
					<td><?=htmlspecialchars(strtoupper($map['proto']))?></td>
					<td class="fs-mono"><?=htmlspecialchars($map['srcaddr'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($map['srcport'] ?: gettext("any"))?></td>
					<td><?=htmlspecialchars($map['descr'])?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($port_maps)) {
	fs_empty_row(8, gettext('No active port maps.'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php
include("foot.inc");
