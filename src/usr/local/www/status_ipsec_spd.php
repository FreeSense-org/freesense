<?php
/*
 * status_ipsec_spd.php
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
##|*IDENT=page-status-ipsec-spd
##|*NAME=Status: IPsec: SPD
##|*DESCR=Allow access to the 'Status: IPsec: SPD' page.
##|*MATCH=status_ipsec_spd.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("ipsec.inc");

$pgtitle = array(gettext("Status"), gettext("IPsec"), gettext("SPDs"));
$pglinks = array("", "status_ipsec.php", "@self");
$shortcut_section = "ipsec";

$spd = ipsec_dump_spd();
if (!is_array($spd)) {
	$spd = [];
}

$counts = ['in' => 0, 'out' => 0];
foreach ($spd as $sp) {
	$counts[($sp['dir'] == 'in') ? 'in' : 'out']++;
}

include("head.inc");

fs_tabs('status-ipsec', 'status_ipsec_spd.php');

if (!ipsec_enabled()) {
	print_info_box(sprintf(gettext('IPsec is disabled. %1$sConfigure IPsec%2$s.'), '<a href="vpn_ipsec.php">', '</a>'), 'info', false);
}
?>

<style>
.fs-ipsec-dir { white-space: nowrap; }
.fs-ipsec-dir i { color: var(--fs-text-muted); margin-right: .3rem; }
.fs-ipsec-ends { white-space: nowrap; }
.fs-ipsec-ends i { color: var(--fs-text-muted); margin: 0 .35rem; }
</style>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Security policies'),
	'search' => gettext('Search networks, endpoints…'),
	'noun' => gettext('policies'),
	'noun_one' => gettext('policy'),
	'filters' => ['dir' => [gettext('Both directions'),
	    'in' => sprintf(gettext('Inbound (%d)'), $counts['in']), 'out' => sprintf(gettext('Outbound (%d)'), $counts['out'])]],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Mode")?></th>
					<th data-fs-search><?=gettext("Source")?></th>
					<th data-fs-search><?=gettext("Destination")?></th>
					<th data-fs-search><?=gettext("Direction")?></th>
					<th data-fs-search><?=gettext("Protocol")?></th>
					<th data-fs-search><?=gettext("Tunnel endpoints")?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($spd as $sp):
	$in = ($sp['dir'] == 'in');
?>
				<tr data-fs-filter-dir="<?=$in ? 'in' : 'out'?>">
					<td>
<?php if ($sp['scope'] == 'ifnet'): ?>
						<?=htmlspecialchars(gettext("VTI"))?>
<?php if (!empty($sp['ifname'])): ?>
						<span class="fs-mono"><?=htmlspecialchars($sp['ifname'])?></span>
<?php endif; ?>
<?php else: ?>
						<?=htmlspecialchars(gettext("Tunnel"))?>
<?php endif; ?>
					</td>
					<td class="fs-mono"><?=htmlspecialchars($sp['srcid'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($sp['dstid'])?></td>
					<td class="fs-ipsec-dir">
						<i class="fa-solid <?=$in ? 'fa-arrow-left' : 'fa-arrow-right'?>" aria-hidden="true"></i><?=$in ? gettext('Inbound') : gettext('Outbound')?>
					</td>
					<td><?=htmlspecialchars(strtoupper($sp['proto']))?></td>
					<td class="fs-mono fs-ipsec-ends">
<?php if ($in): ?>
						<?=htmlspecialchars($sp['dst'])?><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><?=htmlspecialchars($sp['src'])?>
<?php else: ?>
						<?=htmlspecialchars($sp['src'])?><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><?=htmlspecialchars($sp['dst'])?>
<?php endif; ?>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($spd)) {
	fs_empty_row(6, gettext('No IPsec security policies configured.'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php
include("foot.inc");
