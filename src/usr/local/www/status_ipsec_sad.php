<?php
/*
 * status_ipsec_sad.php
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
##|*IDENT=page-status-ipsec-sad
##|*NAME=Status: IPsec: SADs
##|*DESCR=Allow access to the 'Status: IPsec: SADs' page.
##|*MATCH=status_ipsec_sad.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("ipsec.inc");

$pgtitle = array(gettext("Status"), gettext("IPsec"), gettext("SADs"));
$pglinks = array("", "status_ipsec.php", "@self");
$shortcut_section = "ipsec";

/* delete any SA? */
/* The values are written into setkey's command input, so accept only an
 * address pair, a known protocol and a hex SPI; anything else could add
 * further setkey commands. */
function sad_endpoint_valid($endpoint) {
	/* setkey prints NAT-T endpoints as address[port]. */
	if (!preg_match('/^([^\[\]]+)(\[[0-9]{1,5}\])?$/', (string)$endpoint, $m)) {
		return false;
	}
	return is_ipaddr($m[1]);
}

if (($_POST['act'] == "del") &&
    sad_endpoint_valid($_POST['src']) && sad_endpoint_valid($_POST['dst']) &&
    in_array(strtolower((string)$_POST['proto']), ['esp', 'ah', 'ipcomp'], true) &&
    preg_match('/^0x[0-9a-f]{1,8}$/i', (string)$_POST['spi'])) {
	$fd = @popen("/sbin/setkey -c > /dev/null 2>&1", "w");
	if ($fd) {
		fwrite($fd, "delete {$_POST['src']} {$_POST['dst']} {$_POST['proto']} {$_POST['spi']} ;\n");
		pclose($fd);
		sleep(1);
	}
}

$sad = ipsec_dump_sad();
if (!is_array($sad)) {
	$sad = [];
}

$protos = [];
foreach ($sad as $sa) {
	$protos[strtolower($sa['proto'])] = strtoupper($sa['proto']);
}
ksort($protos);

include("head.inc");

fs_tabs('status-ipsec', 'status_ipsec_sad.php');

if (!ipsec_enabled()) {
	print_info_box(sprintf(gettext('IPsec is disabled. %1$sConfigure IPsec%2$s.'), '<a href="vpn_ipsec.php">', '</a>'), 'info', false);
}

$filters = [];
if (count($protos) > 1) {
	$filters['proto'] = [gettext('All protocols')] + $protos;
}
?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Security associations'),
	'search' => gettext('Search addresses, SPIs…'),
	'noun' => gettext('associations'),
	'noun_one' => gettext('association'),
	'filters' => $filters,
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Source")?></th>
					<th data-fs-search><?=gettext("Destination")?></th>
					<th data-fs-search><?=gettext("Protocol")?></th>
					<th data-fs-search><?=gettext("SPI")?></th>
					<th data-fs-search><?=gettext("Encryption")?></th>
					<th data-fs-search><?=gettext("Authentication")?></th>
					<th><?=gettext("Data")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($sad as $sa):
	$args = "src=" . rawurlencode($sa['src']);
	$args .= "&dst=" . rawurlencode($sa['dst']);
	$args .= "&proto=" . rawurlencode($sa['proto']);
	$args .= "&spi=" . rawurlencode("0x" . $sa['spi']);
	$label = sprintf('%s %s → %s', strtoupper($sa['proto']), $sa['src'], $sa['dst']);
?>
				<tr data-fs-filter-proto="<?=htmlspecialchars(strtolower($sa['proto']))?>">
					<td class="fs-mono"><?=htmlspecialchars($sa['src'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($sa['dst'])?></td>
					<td><?=htmlspecialchars(strtoupper($sa['proto']))?></td>
					<td class="fs-mono"><?=htmlspecialchars($sa['spi'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($sa['ealgo'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($sa['aalgo'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($sa['data'])?></td>
					<td class="fs-col-actions"><?=fs_row_actions([
						['delete', "status_ipsec_sad.php?act=del&{$args}", "0x{$sa['spi']}", [
						    'thing' => gettext('security association'),
						    'detail' => sprintf(gettext('%s. Traffic using it stops until the tunnel rekeys.'), $label)]],
					])?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($sad)) {
	fs_empty_row(8, gettext('No IPsec security associations.'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php
include("foot.inc");
