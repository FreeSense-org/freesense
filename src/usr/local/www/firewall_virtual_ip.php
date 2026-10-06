<?php
/*
 * firewall_virtual_ip.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2005 Bill Marquette <bill.marquette@gmail.com>
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
##|*IDENT=page-firewall-virtualipaddresses
##|*NAME=Firewall: Virtual IP Addresses
##|*DESCR=Allow access to the 'Firewall: Virtual IP Addresses' page.
##|*MATCH=firewall_virtual_ip.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("firewall_virtual_ip.inc");

$input_errors = array();
$retval = 0;

if ($_POST['apply']) {
	$rv = applyVIP();
	$retval = $rv['retval'];
}

if ($_POST['act'] == "del") {
	$rv = deleteVIP($_POST['id']);
	$input_errors = $rv['input_errors'];
}

$types = array('proxyarp' => gettext('Proxy ARP'),
			   'carp' => gettext('CARP'),
			   'other' => gettext('Other'),
			   'ipalias' => gettext('IP Alias')
			   );

$pgtitle = array(gettext("Firewall"), gettext("Virtual IPs"));
fs_page_action(gettext('Add virtual IP'), 'firewall_virtual_ip_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
} else if ($_POST['apply']) {
	print_apply_result_box($retval);
} else if (is_subsystem_dirty('vip')) {
	print_apply_box(gettext("The VIP configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

/* active tabs
$tab_array = array();
$tab_array[] = array(gettext("Virtual IPs"), true, "firewall_virtual_ip.php");
 $tab_array[] = array(gettext("CARP Settings"), false, "system_hasync.php");
display_top_tabs($tab_array);
*/
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Virtual IP Address'),
	'search' => gettext('Search virtual IPs…'),
	'noun' => gettext('virtual IPs'),
	'noun_one' => gettext('virtual IP'),
	'filters' => ['type' => array_merge([gettext('All types')], $types)],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Virtual IP address")?></th>
					<th data-fs-search><?=gettext("Interface")?></th>
					<th data-fs-search><?=gettext("Type")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
$interfaces = get_configured_interface_with_descr(true);
$viplist = get_configured_vip_list();

foreach ($viplist as $vipname => $address) {
	$interfaces[$vipname] = $address;
	$interfaces[$vipname] .= " (";
	if (get_vip_descr($address)) {
		$interfaces[$vipname] .= get_vip_descr($address);
	} else {
		$vip = get_configured_vip($vipname);
		$interfaces[$vipname] .= "vhid: {$vip['vhid']}";
	}
	$interfaces[$vipname] .= ")";
}

$interfaces['lo0'] = "Localhost";

$i = 0;
$shown = 0;
foreach (config_get_path('virtualip/vip', []) as $vipent):
	if ($vipent['subnet'] != ""):
		$shown++;
		$address = '';
		if ((($vipent['type'] == "single") || ($vipent['type'] == "network")) && $vipent['subnet_bits']) {
			$address = "{$vipent['subnet']}/{$vipent['subnet_bits']}";
		}
		$label = $address ?: ($vipent['descr'] ?: $types[$vipent['mode']]);
?>
				<tr data-fs-filter-type="<?=htmlspecialchars($vipent['mode'])?>">
					<td class="fs-mono">
						<a href="firewall_virtual_ip_edit.php?id=<?=$i?>"><?=htmlspecialchars($address)?></a>
<?php	if ($vipent['mode'] == "carp"): ?>
						<span class="fs-muted">(vhid: <?=htmlspecialchars($vipent['vhid'])?>)</span>
<?php	endif; ?>
					</td>
					<td><?=htmlspecialchars($interfaces[$vipent['interface']])?></td>
					<td><?=htmlspecialchars($types[$vipent['mode']])?></td>
					<td><?=htmlspecialchars($vipent['descr'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', "firewall_virtual_ip_edit.php?id={$i}", $label],
							['delete', "firewall_virtual_ip.php?act=del&id={$i}", $label, ['thing' => gettext('virtual IP')]],
						])?>
					</td>
				</tr>
<?php
	endif;
	$i++;
endforeach;

if ($shown == 0) {
	fs_empty_row(5, gettext('No virtual IPs yet.'), 'firewall_virtual_ip_edit.php', gettext('Add virtual IP'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<div class="infoblock">
	<?php print_info_box(sprintf(gettext('The virtual IP addresses defined on this page may be used in %1$sNAT%2$s mappings.'), '<a href="firewall_nat.php">', '</a>') . '<br />' .
		sprintf(gettext('Check the status of CARP Virtual IPs and interfaces %1$shere%2$s.'), '<a href="status_carp.php">', '</a>'), 'info', false); ?>
</div>

<?php
include("foot.inc");
