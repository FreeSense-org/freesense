<?php
/*
 * system_advanced_misc.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc
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
##|*IDENT=page-system-advanced-misc
##|*NAME=System: Advanced: Miscellaneous
##|*DESCR=Allow access to the 'System: Advanced: Miscellaneous' page.
##|*MATCH=system_advanced_misc.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('functions.inc');
require_once('filter.inc');
require_once("system.inc");
require_once('shaper.inc');
require_once('util.inc');
require_once('vpn.inc');
require_once('system_advanced_misc.inc');

$pconfig = getSystemAdvancedMisc();

global $global_gateway_state_kill_modes;

$rebootneeded_ramdisks = false;

if ($_POST) {
	ob_flush();
	flush();

	/* CPU, power, crypto and mitigation settings live on System > Advanced > Performance */
	$rv = saveSystemAdvancedMisc(systemAdvancedMiscKeepFields($_POST, getSystemAdvancedHardwareFields()));

	$pconfig = $rv['post'];
	$input_errors = $rv['input_errors'];
	$retval = $rv['retval'];
	$changes_applied = $rv['changes_applied'];
	$rebootneeded_ramdisks = $rv['reboot-ramdisks'];
} else {
	$pconfig = getSystemAdvancedMisc();
}

$pgtitle = array(gettext("System"), gettext("Advanced"), gettext("Miscellaneous"));
$pglinks = array("", "system_advanced_admin.php", "@self");
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

fs_tabs('system-advanced', 'system_advanced_misc.php');

$form = new Form;
$section = new Form_Section('Proxy Support');

$section->addInput(new Form_Input(
	'proxyurl',
	'Proxy URL',
	'text',
	$pconfig['proxyurl']
))->setHelp('Hostname or IP address of proxy server this system will '.
	'use for its outbound Internet access.');

$section->addInput(new Form_Input(
	'proxyport',
	'Proxy Port',
	'text',
	$pconfig['proxyport']
))->setHelp('Port where proxy server is listening.');

$section->addInput(new Form_Input(
	'proxyuser',
	'Proxy Username',
	'text',
	$pconfig['proxyuser'],
	['autocomplete' => 'new-password']
))->setHelp('Username for authentication to proxy server. Optional, '.
	'leave blank to not use authentication.');

$section->addPassword(new Form_Input(
	'proxypass',
	'Proxy Password',
	'password',
	$pconfig['proxypass']
))->setHelp('Password for authentication to proxy server.');

$form->add($section);
$section = new Form_Section('Load Balancing');

$group = new Form_Group('Load Balancing');

$group->add(new Form_Checkbox(
	'lb_use_sticky',
	'Use sticky connections',
	'Use sticky connections',
	$pconfig['lb_use_sticky']
))->setHelp('Successive connections will be redirected via gateways in a '.
	'round-robin manner with connections from the same source being sent via the '.
	'same gateway. This "sticky connection" will exist as long as there are '.
	'states that refer to this connection. Once the states expire, so will the '.
	'sticky connection. Further connections from that host will be redirected '.
	'via the next gateway in the round robin.');

$group->add(new Form_Input(
	'srctrack',
	'Source tracking timeout',
	'number',
	$pconfig['srctrack'],
	["placeholder" => "0"]
))->setHelp('Set the source tracking timeout for sticky connections in seconds. By default '.
	'this is 0, so source tracking is removed as soon as the state expires. '.
	'Setting this timeout higher will cause the source/destination relationship '.
	'to persist for longer periods of time.');

$section->add($group);

$form->add($section);

$section = new Form_Section('Schedules');

$section->addInput(new Form_Checkbox(
	'schedule_states',
	'Schedule States',
	'Do not kill connections when schedule expires',
	$pconfig['schedule_states']
))->setHelp('By default, when a schedule expires, connections permitted by that '.
	'schedule are killed. This option overrides that behavior by not clearing states '.
	'for existing connections.');

$form->add($section);
$section = new Form_Section('Gateway Monitoring');

$section->addInput(new Form_Select(
	'remove_failover_states_default',
	'State Killing on Gateway Recovery',
	$pconfig['remove_failover_states_default'],
	[
		'' => 'Don\'t kill states from the firewall itself',
		'all' => 'Kill all states for lower-priority gateways',
		'policy' => 'Only kill policy routing states for lower-priority gateways',
		'addrfamily' => 'Only kill states with the same address family as the gateway group',
	]
))->setHelp('Controls the state killing behavior for the %1$ssystem\'s ' .
	'default gateway%2$s when set to a failover gateway group.',
	'<strong>', '</strong>');
$section->addInput(new Form_Checkbox(
	'keep_failover_states',
	null,
	'Don\'t kill policy routing states for lower-priority gateways',
	$pconfig['keep_failover_states']
))->setHelp('Controls the default state killing behavior for %1$sall failover gateway' .
	'groups%2$s on gateway recovery. This only affects states created by ' .
	'firewall rules with policy routing.', '<strong>', '</strong>');

$section->addInput(new Form_Select(
	'gw_down_kill_states',
	'State Killing on Gateway Failure',
	$pconfig['gw_down_kill_states'],
	$global_gateway_state_kill_modes
))->setHelp('Controls the state killing behavior when a gateway is down during a filter reload. '.
	'Killing states for specific down gateways only affects states created by policy routing rules and reply-to. ' .
	'Behaviors except "Flush all" can be overridden on a per-gateway basis. Not triggered by '.
	'gateways with monitoring disabled, monitoring action disabled, or which have been forced down. ' .
	'May not have any effect on dynamic gateways during a link loss event.');

$section->addInput(new Form_Checkbox(
	'skip_rules_gw_down',
	'Skip rules when gateway is down',
	'Do not create rules when gateway is down',
	$pconfig['skip_rules_gw_down']
))->setHelp('By default, when a rule has a gateway specified and this gateway is '.
	'down, the rule is created omitting the gateway. This option overrides that '.
	'behavior by omitting the entire rule instead.');

$section->addInput(new Form_Checkbox(
	'dpinger_dont_add_static_routes',
	'Static routes',
	'Do not add static routes for gateway monitor IP addresses',
	$pconfig['dpinger_dont_add_static_routes']
))->setHelp('By default the firewall adds static routes for gateway monitor IP addresses '.
	'to ensure traffic to the monitor IP address leaves via the correct interface. '.
	'Enabling this checkbox overrides that behavior.');

$form->add($section);

// Retrieve limits
$php_default = get_php_default_memory($ARCH);
$php_max_ram = get_php_max_memory();

$section = new Form_Section('PHP Settings');

$group = new Form_Group('Memory Limit');

$group->add(new Form_Input(
	'php_memory_limit',
	'Memory Limit',
	'number',
	str_replace("M", "", $pconfig['php_memory_limit']),
	['min' => 128, 'max' => $php_max_ram, 'placeholder' => '128 to ' . $php_max_ram]
))->setHelp('PHP memory limit in MiB.%1$sNote: Leave this blank for the default. On this system the default size is: %2$d '
	, '<br/>', $php_default);

$section->add($group);

$form->add($section);

$section = new Form_Section('RAM Disk Settings (Reboot to Apply Changes)');

$section->addInput(new Form_Checkbox(
	'use_mfs_tmpvar',
	'Use RAM Disks',
	'Use memory file system for /tmp and /var',
	$pconfig['use_mfs_tmpvar']
))->setHelp('Check this option to use /tmp and /var as RAM disks (in-memory '.
	'file system disks) rather than the hard disk. %s' .
	'NOTE: Setting this will erase the current data in /tmp and /var. RRD, '.
	'DHCP leases, log directory and Captive Portal data will be retained. ' .
	'Changing this setting will cause the firewall to reboot after saving.',
	'<br/><br/>');

$group = new Form_Group('RAM Disk Size');

$group->add(new Form_Input(
	'use_mfs_tmp_size',
	'/tmp RAM Disk Size',
	'number',
	$pconfig['use_mfs_tmp_size'],
	['placeholder' => 40]
))->setHelp('/tmp RAM Disk%1$sDo not set lower than 40.%1$s' .
	'Current usage: %2$s',
	'<br/>',
	format_bytes(ramdisk_current_usage('/tmp', false)));

$group->add(new Form_Input(
	'use_mfs_var_size',
	'/var RAM Disk Size',
	'number',
	$pconfig['use_mfs_var_size'],
	['placeholder' => 60]
))->setHelp('/var RAM Disk%1$sDo not set lower than 60.%1$s' .
	'Current usage: %2$s',
	'<br/>',
	format_bytes(ramdisk_current_usage('/var', false)));

$group->setHelp('Sets the size, in MiB, for the RAM disks. ' .
	'Ensure each RAM disk is large enough to contain the current contents of the directories in question. %1$s' .
	'Maximum total size of all RAM disks cannot exceed available memory and swap space: %2$s' .
	'%1$s%1$sNOTE: If /tmp and /var RAM disk usage exceeds the available free RAM (%3$s), the RAM disks will use ' .
	'swap memory which leads to disk activity.',
	'<br/>', format_bytes( ramdisk_available_memory() ), format_bytes(ramdisk_available_memory(false)));

$section->add($group);

$group = new Form_Group('Periodic RAM Disk Data Backups');

$group->add(new Form_Input(
	'rrdbackup',
	'Periodic RRD Backup',
	'number',
	config_get_path('system/rrdbackup'),
	['min' => 0, 'max' => 24, 'placeholder' => '1 to 24 hours']
))->setHelp('RRD Data');

$group->add(new Form_Input(
	'dhcpbackup',
	'Periodic DHCP Leases Backup',
	'number',
	config_get_path('system/dhcpbackup'),
	['min' => 0, 'max' => 24, 'placeholder' => '1 to 24 hours']
))->setHelp('DHCP Leases');

$group->add(new Form_Input(
	'logsbackup',
	'Periodic Logs Backup',
	'number',
	config_get_path('system/logsbackup'),
	['min' => 0, 'max' => 24, 'placeholder' => '1 to 24 hours']
))->setHelp('Log Directory');

$group->add(new Form_Input(
	'captiveportalbackup',
	'Periodic Captive Portal DB and Vouchers Backup',
	'number',
	config_get_path('system/captiveportalbackup'),
	['min' => 0, 'max' => 24, 'placeholder' => '1 to 24 hours']
))->setHelp('Captive Portal Data');

$group->setHelp('Sets the interval, in hours, to periodically backup these portions of RAM disk data so '.
	'they can be restored automatically on the next boot. Keep in mind that the more '.
	'frequent the backup, the more writes will happen to the media.');

$section->add($group);

$form->add($section);

$section = new Form_Section('Hardware Settings');

$opts = array(0.5,  1, 2,  3,  4,  5,  7.5,  10,  15,  20,  30,  60);
$vals = array(  6, 12, 24, 36, 48, 60,  90, 120, 180, 240, 241, 242);

$section->addInput(new Form_Select(
	'harddiskstandby',
	'Hard disk standby time',
	$pconfig['harddiskstandby'],
	['' => gettext("Always on")] + array_combine($opts, $vals)
))->setHelp('Puts the hard disk into standby mode when the selected number of minutes has elapsed since the last access.%1$s' .
			'%2$sDo not set this for CF cards.%3$s', '<br />', '<strong>', '</strong>');

$form->add($section);

print $form;

$reboot_confirm_prompt = "";
$reboot_msg = [];
if ($rebootneeded_ramdisks) {
	$reboot_msg[] = gettext('The \"Use RAM Disks\" setting has been changed.\nThis requires the firewall to reboot.');
}
if (!empty($reboot_msg)) {
	$reboot_msg[] = gettext('Reboot now?');
	$reboot_confirm_prompt = implode('\n\n', $reboot_msg);
}

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Has the Use ramdisk checkbox changed state?
	if (<?= (int) !empty($reboot_confirm_prompt) ?> && confirm("<?=$reboot_confirm_prompt?>")) {
		postSubmit({rebootmode : 'Reboot', Submit: 'Yes'}, 'diag_reboot.php')
	}

	// source track timeout field is disabled if sticky connections not enabled
	$('#lb_use_sticky').click(function () {
		disableInput('srctrack', !$(this).prop("checked"));
	});

	disableInput('srctrack', !$('#lb_use_sticky').prop("checked"));

});
//]]>
</script>

<?php
include("foot.inc");
