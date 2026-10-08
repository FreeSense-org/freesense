<?php
/*
 * vpn_l2tp.php
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
##|*IDENT=page-vpn-vpnl2tp
##|*NAME=VPN: L2TP
##|*DESCR=Allow access to the 'VPN: L2TP' page.
##|*MATCH=vpn_l2tp.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("vpn.inc");
require_once("vpn_l2tp.inc");

$pconfig = l2tp_settings_form();

if ($_POST['save']) {

	unset($input_errors);
	$rv = l2tp_settings_save($_POST);
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];
	if ($rv['changes_applied']) {
		$changes_applied = true;
		$retval = $rv['retval'];
	}
}

/* the summary card always shows the saved configuration, not the posted form */
$saved = l2tp_settings_form();
$interfaces = get_configured_interface_with_descr();
$server_on = ($saved['mode'] == 'server');
$auth_types = l2tp_paporchap_values();
$user_count = count(config_get_path('l2tp/user', []));
$radius_ips = $saved['radiusenable'] && $saved['radiusissueips'];
$remote_range = '';
if ($radius_ips) {
	$remote_range = gettext('Assigned by RADIUS');
} elseif (is_ipaddrv4($saved['remoteip'])) {
	$units = max(1, (int)$saved['n_l2tp_units']);
	$remote_range = ($units > 1) ? $saved['remoteip'] . ' – ' . ip_after($saved['remoteip'], $units - 1) : $saved['remoteip'];
}

$pgtitle = array(gettext("VPN"), gettext("L2TP"), gettext("Configuration"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "l2tps";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

fs_tabs('vpn-l2tp', 'vpn_l2tp.php');

?>
<style>
.fs-l2tp-note { display: flex; gap: .6rem; margin-top: var(--fs-sp-4); padding: .6rem .8rem; border-radius: var(--fs-r-sm); background: var(--fs-surface-raised); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-l2tp-note > i { margin-top: .2rem; color: var(--fs-info); }
</style>
<?php
fs_summary_card([
	'icon' => 'fa-network-wired',
	'title' => gettext('L2TP server'),
	'subtitle' => ($server_on && isset($interfaces[$saved['interface']])) ? sprintf(gettext('Listening on %s'), $interfaces[$saved['interface']]) : gettext('Remote access VPN for L2TP clients'),
	'badges' => [fs_badge($server_on ? 'enabled' : 'disabled')],
	'facts' => [
		[gettext('Server address'), (string)$saved['localip'], 'mono' => true],
		[gettext('Remote range'), $remote_range, 'mono' => !$radius_ips],
		[gettext('Max users'), (string)$saved['n_l2tp_units']],
		[gettext('Authentication'), ($auth_types[$saved['paporchap']] ?? 'CHAP') . ($saved['radiusenable'] ? ' · RADIUS' : '')],
		[gettext('Users'), sprintf(ngettext('%d local user', '%d local users', $user_count), $user_count), 'href' => 'vpn_l2tp_users.php'],
	],
	'label' => gettext('L2TP server summary'),
]);
$form = new Form();

/* 1. turn the server on and pick where it listens */
$section = new Form_Section("Server", 'l2tp-server');

$section->addInput(new Form_Checkbox(
	'mode',
	'Enable',
	'Enable L2TP server',
	($pconfig['mode'] == "server"),
	'server'
));

$iflist = array();
foreach ($interfaces as $iface => $ifacename) {
	$iflist[$iface] = $ifacename;
}

$section->addInput(new Form_Select(
	'interface',
	'*Interface',
	$pconfig['interface'],
	$iflist
))->setHelp('The interface L2TP clients connect to.');

$form->add($section);

/* 2. the addresses the tunnel uses */
$section = new Form_Section("Client addresses", 'l2tp-addresses');
$section->addClass('toggle-l2tp-enable');

$section->addInput(new Form_Input(
	'localip',
	'*Server address',
	'text',
	$pconfig['localip']
))->setHelp('The gateway address clients use. Pick an unused address just outside the client range; ' .
			'it must not be in use on this firewall.');

$section->addInput(new Form_IpAddress(
        'remoteip',
        '*Remote address range',
        $pconfig['remoteip']
))->addMask('l2tp_subnet', $pconfig['l2tp_subnet'], 32)->setWidth(5)
  ->setHelp('The first address handed out to clients.');

$section->addInput(new Form_Select(
	'n_l2tp_units',
	'*Number of L2TP users',
	$pconfig['n_l2tp_units'],
	array_combine(range(1, 255, 1), range(1, 255, 1))
))->setHelp('How many clients can be connected at the same time.');

$form->add($section);

/* 3. how clients authenticate */
$section = new Form_Section("Authentication", 'l2tp-auth');
$section->addClass('toggle-l2tp-enable');

$section->addInput(new Form_Select(
	'paporchap',
	'*Authentication type',
	$pconfig['paporchap'],
	l2tp_paporchap_values()
))->setHelp('The protocol used to authenticate users.');

$section->addPassword(new Form_Input(
	'secret',
	'Secret',
	'password',
	$pconfig['secret']
))->setHelp('Optional secret shared between peers. Some devices require it.');

$form->add($section);

/* 4. what clients get */
$section = new Form_Section("Client DNS", 'l2tp-dns');
$section->addClass('toggle-l2tp-enable');

$section->addInput(new Form_Input(
	'l2tp_dns1',
	'Primary L2TP DNS server',
	'text',
	$pconfig['l2tp_dns1']
));

$section->addInput(new Form_Input(
	'l2tp_dns2',
	'Secondary L2TP DNS server',
	'text',
	$pconfig['l2tp_dns2']
))->setHelp('DNS servers handed to clients. Leave empty to not push any.');

$form->add($section);

/* 5. rarely changed: RADIUS and link settings */
$section = new Form_Section("RADIUS", 'l2tp-radius',
	COLLAPSIBLE | ((!empty($input_errors) || $pconfig['radiusenable']) ? SEC_OPEN : SEC_CLOSED));
$section->addClass('toggle-l2tp-enable');

$section->addInput(new Form_Checkbox(
	'radiusenable',
	'Enable',
	'Use a RADIUS server for authentication',
	$pconfig['radiusenable']
))->setHelp('All users are authenticated by the RADIUS server below; the local user database is not used.');

$section->addInput(new Form_Checkbox(
	'radacct_enable',
	'Accounting',
	'Enable RADIUS accounting',
	$pconfig['radacct_enable']
))->setHelp('Sends accounting packets to the RADIUS server.');

$section->addInput(new Form_IpAddress(
	'radiusserver',
	'*Server',
	$pconfig['radiusserver']
))->setHelp('The IP address of the RADIUS server.');

$section->addPassword(new Form_Input(
	'radiussecret',
	'*Secret',
	'password',
	$pconfig['radiussecret']
))->setHelp('The shared secret used to authenticate to the RADIUS server.');

$section->addInput(new Form_Checkbox(
	'radiusissueips',
	'RADIUS issued IPs',
	'Issue IP Addresses via RADIUS server.',
	$pconfig['radiusissueips']
));

$form->add($section);

$section = new Form_Section("Advanced", 'l2tp-advanced',
	COLLAPSIBLE | ((!empty($input_errors) || $pconfig['mtu'] != '') ? SEC_OPEN : SEC_CLOSED));
$section->addClass('toggle-l2tp-enable');

$section->addInput(new Form_Input(
	'mtu',
	'VPN MTU',
	'number',
	$pconfig['mtu']
))->setHelp('Leave empty to use the adapter\'s default MTU, typically 1500 bytes.');

$form->add($section);

print($form);
?>
<div class="fs-l2tp-note">
	<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
	<span><?php if ($server_on): ?><?=sprintf(gettext('Clients can only reach your network once a %1$sfirewall rule on the L2TP VPN tab%2$s permits their traffic.'), '<a href="firewall_rules.php?if=l2tp">', '</a>')?><?php else: ?><?=gettext("Don't forget to add a firewall rule to permit traffic from L2TP clients.")?><?php endif; ?></span>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	function setL2TP () {
		hide = ! $('#mode').prop('checked');

		hideInput('interface', hide);
		hideClass('toggle-l2tp-enable', hide);
	}

	function setRADIUS () {
		hide = ! $('#radiusenable').prop('checked');

		hideCheckbox('radacct_enable', hide);
		hideInput('radiusserver', hide);
		hideInput('radiussecret', hide);
		hideCheckbox('radiusissueips', hide);
	}

	// on-click
	$('#mode').click(function () {
		setL2TP();
	});

	$('#radiusenable').click(function () {
		setRADIUS();
	});

	// on-page-load
	setRADIUS();
	setL2TP();

});
//]]>
</script>

<?php include("foot.inc")?>
