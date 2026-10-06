<?php
/*
 * services_captiveportal_hostname.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-services-captiveportal-allowedhostnames
##|*NAME=Services: Captive Portal: Allowed Hostnames
##|*DESCR=Allow access to the 'Services: Captive Portal: Allowed Hostnames' page.
##|*MATCH=services_captiveportal_hostname.php*
##|-PRIV

$directionicons = array('to' => '&#x2192;', 'from' => '&#x2190;', 'both' => '&#x21c4;');

$notestr =
	sprintf(gettext('Adding new hostnames will allow a DNS hostname access to/from the captive portal without being taken to the portal page. ' .
	'This can be used for a web server serving images for the portal page, or a DNS server on another network, for example. ' .
	'By specifying %1$sfrom%2$s addresses, it may be used to always allow pass-through access from a client behind the captive portal.'),
	'<em>', '</em>');

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("captiveportal.inc");

$cpzone = $_REQUEST['zone'];

$cpzone = strtolower(htmlspecialchars($cpzone));

if (empty($cpzone) || empty(config_get_path("captiveportal/{$cpzone}"))) {
	header("Location: services_captiveportal_zones.php");
	exit;
}

if (isset($cpzone) && !empty($cpzone) && (config_get_path("captiveportal/{$cpzone}/zoneid") !== null)) {
	$cpzoneid = config_get_path("captiveportal/{$cpzone}/zoneid");
}

$pgtitle = array(gettext("Services"), gettext("Captive Portal"), htmlspecialchars($cpzone), gettext("Allowed Hostnames"));
$pglinks = array("", "services_captiveportal_zones.php", "services_captiveportal.php?zone=" . $cpzone, "@self");
$shortcut_section = "captiveportal";

if ($_POST['act'] == "del" && !empty($cpzone)) {
	if (config_get_path("captiveportal/{$cpzone}/allowedhostname/{$_POST['id']}")) {
		captiveportal_allowedhostname_cleanup();
		config_del_path("captiveportal/{$cpzone}/allowedhostname/{$_POST['id']}");
		write_config("Captive portal allowed hostnames saved");
		captiveportal_allowedhostname_configure();
		header("Location: services_captiveportal_hostname.php?zone={$cpzone}");
		exit;
	}
}

fs_page_action(gettext('Add hostname'), 'services_captiveportal_hostname_edit.php?zone=' . urlencode($cpzone) . '&act=add', 'fa-plus');
include("head.inc");

fs_tabs('services-captiveportal', 'services_captiveportal_hostname.php', ['zone' => $cpzone]);
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Allowed Hostnames'),
	'search' => gettext('Search hostnames…'),
	'noun' => gettext('hostnames'),
	'noun_one' => gettext('hostname'),
]); ?>
<div class="panel-body table-responsive">
	<table class="table table-hover table-rowdblclickedit" data-sortable>
		<thead>
			<tr>
				<th data-fs-search><?=gettext("Hostname"); ?></th>
				<th data-fs-search><?=gettext("Description"); ?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
			</tr>
		</thead>
		<tbody>
<?php
$i = 0;
foreach (config_get_path("captiveportal/{$cpzone}/allowedhostname", []) as $ip): ?>
			<tr>
				<td>
					<?=$directionicons[$ip['dir']]?>&nbsp;<?=htmlspecialchars(strtolower((is_string($ip['hostname']) && (strlen($ip['hostname']) > 0)) ? idn_to_utf8($ip['hostname']) : ''))?>
				</td>
				<td >
					<?=htmlspecialchars($ip['descr'])?>
				</td>
				<td class="fs-col-actions">
<?=fs_row_actions([
					['edit', "services_captiveportal_hostname_edit.php?zone=" . urlencode($cpzone) . "&id={$i}", $ip['hostname']],
					['delete', "services_captiveportal_hostname.php?zone=" . urlencode($cpzone) . "&act=del&id={$i}", $ip['hostname'], ['thing' => gettext('allowed hostname')]],
				])?>
				</td>
			</tr>
<?php
$i++;
endforeach; ?>
<?php if ($i == 0) {
	fs_empty_row(3, gettext('No allowed hostnames yet.'), 'services_captiveportal_hostname_edit.php?zone=' . urlencode($cpzone) . '&act=add', gettext('Add hostname'));
} ?>
		</tbody>
	</table>
</div>
<div class="panel-footer small">
	<?=$directionicons['to'] . ' = ' . sprintf(gettext('All connections %1$sto%2$s the hostname are allowed'), '<u>', '</u>') . ', '?>
	<?=$directionicons['from'] . ' = ' . sprintf(gettext('All connections %1$sfrom%2$s the hostname are allowed'), '<u>', '</u>') . ', '?>
	<?=$directionicons['both'] . ' = ' . sprintf(gettext('All connections %1$sto or from%2$s are allowed'), '<u>', '</u>')?>
</div>
</div>


<div class="infoblock">
	<?php print_info_box($notestr, 'info', false); ?>
</div>

<?php

include("foot.inc");
