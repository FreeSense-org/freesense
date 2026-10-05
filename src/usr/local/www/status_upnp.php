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

include("head.inc");

if (config_get_path('installedpackages/miniupnpd/config/0/enable') != 'on') {
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

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlentities(gettext("Active UPnP IGD & PCP/NAT-PMP Port Maps"))?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Ext Interface")?></th>
						<th><?=gettext("Ext Port")?></th>
						<th><?=gettext("Int IP")?></th>
						<th><?=gettext("Int Port")?></th>
						<th><?=gettext("Protocol")?></th>
						<th><?=gettext("Source IP")?></th>
						<th><?=gettext("Source Port")?></th>
						<th><?=gettext("Description")?></th>
					</tr>
				</thead>
				<tbody>
<?php
foreach ($port_maps as $map) {
?>
					<tr>
						<td>
							<?= htmlspecialchars(convert_real_interface_to_friendly_descr($map['iface'])) ?>
						</td>
						<td>
							<?= htmlspecialchars($map['extport']) ?>
						</td>
						<td>
							<?= htmlspecialchars($map['intaddr']) ?>
						</td>
						<td>
							<?= htmlspecialchars($map['intport']) ?>
						</td>
						<td>
							<?= htmlspecialchars(strtoupper($map['proto'])) ?>
						</td>
						<td>
							<?= htmlspecialchars($map['srcaddr']) ?>
						</td>
						<td>
							<?= htmlspecialchars($map['srcport'] ?: "any") ?>
						</td>
						<td>
							<?= htmlspecialchars($map['descr']) ?>
						</td>
					</tr>
<?php
}
?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<div>
	<form action="status_upnp.php" method="post">
		<nav class="action-buttons">
			<button class="btn btn-danger btn-sm" type="submit" name="delete-all" value="delete-all">
				<i class="fa-solid fa-trash-can icon-embed-btn"></i>
				<?=gettext("Delete all port maps")?>
			</button>
		</nav>
	</form>
</div>

<?php
include("foot.inc");
