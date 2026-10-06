<?php
/*
 * services_wol.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-services-wakeonlan
##|*NAME=Services: Wake-on-LAN
##|*DESCR=Allow access to the 'Services: Wake-on-LAN' page.
##|*MATCH=services_wol.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_wol.inc");

$savemsg = "";
$class = "";

/* Waking every device changes state, so only accept it via POST. */
if ($_POST['wakeall'] != "") {
	wol_wake_all($savemsg, $class);
}

if ($_POST['Submit'] || $_POST['mac']) {
	unset($input_errors);

	if ($_POST['mac']) {
		/* normalize MAC addresses - lowercase and convert Windows-ized hyphenated MACs to colon delimited */
		$mac = wol_normalize_mac($_POST['mac']);
		$if = $_POST['if'];
	}

	$input_errors = wol_wake_device($mac, $if, $savemsg, $class);
}

if (is_numericint($_POST['id']) && $_POST['act'] == "del") {
	if (wol_delete_entry($_POST['id'])) {
		header("Location: services_wol.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("Wake-on-LAN"));
include("head.inc");
?>
<div class="infoblock blockopen">
<?php
print_info_box(gettext('This service can be used to wake up (power on) computers by sending special "Magic Packets".') . '<br />' .
			   gettext('The NIC in the computer that is to be woken up must support Wake-on-LAN and must be properly configured (WOL cable, BIOS settings).'),
			   'info', false);

?>
</div>
<?php

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, $class);
}

$selected_if = (empty($if) ? 'lan' : $if);
if (!isset(get_configured_interface_list(false)[$selected_if])) {
	$selected_if = null;
}

$form = new Form(false);

$section = new Form_Section('Wake-on-LAN');

$section->addInput(new Form_Select(
	'if',
	'*Interface',
	$selected_if,
	get_configured_interface_with_descr()
))->setHelp('Choose which interface the host to be woken up is connected to.');

$section->addInput(new Form_Input(
	'mac',
	'*MAC address',
	'text',
	$mac
))->setHelp('Enter a MAC address in the following format: xx:xx:xx:xx:xx:xx');

$form->add($section);

$form->addGlobal(new Form_Button(
	'Submit',
	'Send',
	null,
	'fa-solid fa-power-off'
))->addClass('btn-primary');

print $form;
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Wake-on-LAN Devices");?></h2>
	</div>

<?php
	// Add top buttons if more than 24 entries in the table
	if (count(config_get_path('wol/wolentry', [])) > 24) {
?>
	<div class="panel-footer">
		<a class="btn btn-success" href="services_wol_edit.php">
			<i class="fa-solid fa-plus icon-embed-btn"></i>
			<?=gettext("Add");?>
		</a>

		<button type="button" class="btn btn-primary wakeall">
			<i class="fa-solid fa-power-off icon-embed-btn"></i>
			<?=gettext("Wake All Devices")?>
		</button>
	</div>
<?php } ?>

	<div class="panel-body">
		<p class="text-danger" style="margin-left: 8px;margin-bottom:0px;"><?=gettext("Click the MAC address to wake up an individual device.")?></p>
		<div class="table-responsive">
			<table class="table table-striped table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th><?=gettext("Interface")?></th>
						<th><?=gettext("MAC address")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach (config_get_path('wol/wolentry', []) as $i => $wolent): ?>
						<tr>
							<td>
								<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($wolent['interface']));?>
							</td>
							<td>
								<a href="?mac=<?=urlencode($wolent['mac']);?>&amp;if=<?=urlencode($wolent['interface']);?>" usepost><?=htmlspecialchars(strtolower($wolent['mac']));?></a>
							</td>
							<td>
								<?=htmlspecialchars($wolent['descr']);?>
							</td>
							<td>
								<a class="fa-solid fa-pencil"	title="<?=gettext('Edit Device')?>"	href="services_wol_edit.php?id=<?=$i?>"></a>
								<a class="fa-solid fa-trash-can"	title="<?=gettext('Delete Device')?>" href="services_wol.php?act=del&amp;id=<?=$i?>" usepost></a>
								<a class="fa-solid fa-power-off" title="<?=gettext('Wake Device')?>" href="?mac=<?=urlencode($wolent['mac']);?>&amp;if=<?=urlencode($wolent['interface']);?>" usepost></a>
							</td>
						</tr>
					<?php endforeach?>
				</tbody>
			</table>
		</div>
	</div>
	<div class="panel-footer">
		<a class="btn btn-success" href="services_wol_edit.php">
			<i class="fa-solid fa-plus icon-embed-btn"></i>
			<?=gettext("Add");?>
		</a>

		<button type="button" class="btn btn-primary wakeall">
			<i class="fa-solid fa-power-off icon-embed-btn"></i>
			<?=gettext("Wake All Devices")?>
		</button>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	$('.wakeall').click(function() {
		if (confirm("Are you sure you wish to Wake All Devices?")) {
			postSubmit({wakeall: 'true'}, 'services_wol.php');
		}
	});

});
//]]>
</script>

<?php

include("foot.inc");
