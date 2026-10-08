<?php
/*
 * services_checkip.php
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
##|*IDENT=page-services-checkipservices
##|*NAME=Services: Check IP Service
##|*DESCR=Allow access to the 'Services: Check IP Service' page.
##|*MATCH=services_checkip.php*
##|-PRIV

require_once("guiconfig.inc");

$dirty = false;
if ($_POST['act'] == "del") {
	config_del_path("checkipservices/checkipservice/{$_POST['id']}");
	$wc_msg = gettext('Deleted a check IP service.');
	$dirty = true;
} else if ($_POST['act'] == "toggle") {
	if (config_get_path("checkipservices/checkipservice/{$_POST['id']}")) {
		if (config_path_enabled("checkipservices/checkipservice/{$_POST['id']}")) {
			config_del_path("checkipservices/checkipservice/{$_POST['id']}/enable");
			$wc_msg = gettext('Disabled a check IP service.');
		} else {
			config_set_path("checkipservices/checkipservice/{$_POST['id']}/enable", true);
			$wc_msg = gettext('Enabled a check IP service.');
		}
		$dirty = true;
	} else if ($_POST['id'] == count(config_get_path('checkipservices/checkipservice', []))) {
		if (config_path_enabled('checkipservices', 'disable_factory_default')) {
			config_del_path('checkipservices/disable_factory_default');
			$wc_msg = gettext('Enabled the default check IP service.');
		} else {
			config_set_path('checkipservices/disable_factory_default', true);
			$wc_msg = gettext('Disabled the default check IP service.');
		}
		$dirty = true;
	}
}
if ($dirty) {
	write_config($wc_msg);

	header("Location: services_checkip.php");
	exit;
}

$pgtitle = array(gettext("Services"), gettext("Dynamic DNS"), gettext("Check IP Services"));
$pglinks = array("", "services_dyndns.php", "@self");
fs_page_action(gettext('Add service'), 'services_checkip_edit.php', 'fa-plus');
include("head.inc");

fs_tabs('services-dyndns', 'services_checkip.php');

if ($input_errors) {
	print_input_errors($input_errors);
}
?>

<form action="services_checkip.php" method="post" name="iform" id="iform">
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Check IP Services'),
	'search' => gettext('Search check IP services…'),
	'noun' => gettext('check IP services'),
	'noun_one' => gettext('check IP service'),
]); ?>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-hover table-rowdblclickedit">
					<thead>
						<tr>
							<th data-fs-search><?=gettext("Name")?></th>
							<th data-fs-search><?=gettext("URL")?></th>
							<th data-fs-search><?=gettext("Verify SSL/TLS Peer")?></th>
							<th data-fs-search><?=gettext("Description")?></th>
							<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
						</tr>
					</thead>
					<tbody>
<?php
// Is the factory default check IP service disabled?
if (config_path_enabled('checkipservices', 'disable_factory_default')) {
	unset($factory_default_checkipservice['enable']);
}

// Append the factory default check IP service to the list.
$a_checkipservice = config_get_path('checkipservices/checkipservice', []);
$a_checkipservice[] = $factory_default_checkipservice;
$factory_default = count($a_checkipservice) - 1;

$i = 0;
foreach ($a_checkipservice as $checkipservice):

	// Hide edit and delete controls on the factory default check IP service entry (last one; id = count-1), and retain layout positioning.
	if ($i == $factory_default) {
		$visibility = 'invisible';
	} else {
		$visibility = 'visible';
	}
?>
						<tr<?=(isset($checkipservice['enable']) ? '' : ' class="disabled"')?>>
						<td>
							<?=htmlspecialchars($checkipservice['name'])?>
						</td>
						<td>
							<?=htmlspecialchars($checkipservice['url'])?>
						</td>
						<td class="text-center">
							<?=isset($checkipservice['verifysslpeer']) ? fs_badge('pass', gettext('Yes')) : fs_badge('neutral', gettext('No'))?>
						</td>
						<td>
							<?=htmlspecialchars($checkipservice['descr'])?>
						</td>
						<td class="fs-col-actions">
<?php
	/* the factory default service (last entry) can only be toggled */
	$cip_actions = [];
	if ($i != $factory_default) {
		$cip_actions[] = ['edit', "services_checkip_edit.php?id={$i}", $checkipservice['name']];
	}
	$cip_actions[] = ['toggle', "?act=toggle&id={$i}", $checkipservice['name'], ['enabled' => isset($checkipservice['enable'])]];
	if ($i != $factory_default) {
		$cip_actions[] = ['delete', "services_checkip.php?act=del&id={$i}", $checkipservice['name'], ['thing' => gettext('check IP service')]];
	}
?>
							<?=fs_row_actions($cip_actions)?>
						</td>
					</tr>
<?php
	$i++;
endforeach; ?>

					</tbody>
				</table>
			</div>
		</div>
	</div>
</form>


<div class="infoblock">
	<?php print_info_box(gettext('The server must return the client IP address ' .
	'as a string in the following format: ') .
	'<pre>Current IP Address: x.x.x.x</pre>' .
	gettext(
	'The first (highest in list) enabled check ip service will be used to ' .
	'check IP addresses for Dynamic DNS services, and ' .
	'RFC 2136 entries that have the "Use public IP" option enabled.') .
	'<br/><br/>'
	, 'info', false);

	print_info_box(gettext('Sample Server Configurations') .
	'<br/>' .
	gettext('nginx with LUA') . ':' .
	'<pre> location = /ip {
	default_type text/html;
	content_by_lua \'
		ngx.say("' . htmlspecialchars('<html><head><title>Current IP Check</title></head><body>') . 'Current IP Address: ")
		ngx.say(ngx.var.remote_addr)
		ngx.say("' . htmlspecialchars('</body></html>') . '")
	\';
	}</pre>' .
	gettext('PHP') .
	'<pre>' .
	htmlspecialchars('<html><head><title>Current IP Check</title></head><body>Current IP Address: <?=$_SERVER[\'REMOTE_ADDR\']?></body></html>') .
	'</pre>'
	, 'info', false); ?>
</div>

<?php include("foot.inc");
