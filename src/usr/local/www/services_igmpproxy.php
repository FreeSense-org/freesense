<?php
/*
 * services_igmpproxy.php
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
##|*IDENT=page-services-igmpproxy
##|*NAME=Services: IGMP Proxy
##|*DESCR=Allow access to the 'Services: IGMP Proxy' page.
##|*MATCH=services_igmpproxy.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_igmpproxy.inc");

//igmpproxy_sort();

if ($_POST['apply']) {
	$pconfig = $_POST;

	$changes_applied = true;
	$retval = igmpproxy_apply();
}

$pconfig = array_merge((array)$pconfig, igmpproxy_settings());

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = igmpproxy_save_settings($_POST);
	if (!$input_errors) {
		header("Location: services_igmpproxy.php");
		exit;
	}
}

if ($_POST['act'] == "del") {
	if (igmpproxy_delete_entry($_POST['id'])) {
		header("Location: services_igmpproxy.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("IGMP Proxy"));
fs_page_action(gettext('Add IGMP entry'), 'services_igmpproxy_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('igmpproxy')) {
	print_apply_box(gettext('The IGMP entry list has been changed.') . '<br />' . gettext('The changes must be applied for them to take effect.'));
}
?>

<?php

$form = new Form();

$section = new Form_Section('General IGMP Options');

$section->addInput(new Form_Checkbox(
	'enable',
	'Enable',
	'Enable IGMP',
	$pconfig['enable']
));

$section->addInput(new Form_Checkbox(
	'igmpxverbose',
	'Verbose Logging',
	'Enable verbose logging',
	$pconfig['igmpxverbose']
))->setHelp('Change the IGMP Proxy logging from terse to verbose. Note: Make sure to set an appropriate default log level (%s) to see informational messages.',
	'<a href="/status_logs_settings.php">Status > System Logs > Settings</a>');

$form->add($section);

print($form);

?>
<form action="services_igmpproxy.php" method="post">
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Upstream and downstream interfaces'),
	'search' => gettext('Search IGMP entries…'),
	'noun' => gettext('IGMP entries'),
	'noun_one' => gettext('IGMP entry'),
]); ?>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-hover table-rowdblclickedit">
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
$i = 0;
foreach (config_get_path('igmpproxy/igmpentry', []) as $igmpentry):
?>
						<tr>
							<td>
								<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($igmpentry['ifname']))?>
							</td>
							<td>
								<?=htmlspecialchars($igmpentry['type'])?>
							</td>
							<td>
<?php
	$addresses = implode(", ", array_slice(explode(" ", $igmpentry['address']), 0, 10));
	print(htmlspecialchars($addresses));

	if (!is_array($igmpentry['address']) || count($igmpentry['address']) < 10) {
		print(' ');
	} else {
		print('...');
	}
?>
							</td>
							<td>
								<?=htmlspecialchars($igmpentry['descr'])?>&nbsp;
							</td>
							<td class="fs-col-actions">
<?=fs_row_actions([
									['edit', "services_igmpproxy_edit.php?id={$i}", convert_friendly_interface_to_friendly_descr($igmpentry['ifname'])],
									['delete', "services_igmpproxy.php?act=del&id={$i}", convert_friendly_interface_to_friendly_descr($igmpentry['ifname']), ['thing' => gettext('IGMP entry')]],
								])?>
							</td>
						</tr>
<?php
	$i++;
endforeach;
?>
<?php if ($i == 0) {
	fs_empty_row(5, gettext('No IGMP entries yet.'), 'services_igmpproxy_edit.php', gettext('Add IGMP entry'));
} ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</form>


<div class="infoblock">
<?php print_info_box(gettext('Please add the interface for upstream, the allowed subnets, and the downstream interfaces for the proxy to allow. ' .
					   'Only one "upstream" interface can be configured.'), 'info', false); ?>
</div>
<?php
include("foot.inc");
