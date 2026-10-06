<?php
/*
 * vpn_ipsec_mobile.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-vpn-ipsec-mobile
##|*NAME=VPN: IPsec: Mobile
##|*DESCR=Allow access to the 'VPN: IPsec: Mobile' page.
##|*MATCH=vpn_ipsec_mobile.php*
##|-PRIV

require_once("functions.inc");
require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("filter.inc");
require_once("vpn_ipsec.inc");

$auth_groups = ipsec_mobile_auth_groups();

$pconfig = ipsec_mobile_form();

if ($_REQUEST['create']) {
	header("Location: vpn_ipsec_phase1.php?mobile=true");
}

if ($_POST['apply']) {
	$retval = ipsec_mobile_apply();
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = ipsec_mobile_save($pconfig);
	if (!$input_errors) {
		header("Location: vpn_ipsec_mobile.php");
		exit;
	}
}

$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Mobile Clients"));
$pglinks = array("", "vpn_ipsec.php", "@self");
$shortcut_section = "ipsec";

include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}
if (is_subsystem_dirty('ipsec')) {
	print_apply_box(gettext("The IPsec tunnel configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}
$mobile_p1 = null;
$ph1found = false;
foreach (config_get_path('ipsec/phase1', []) as $ph1ent) {
	if (isset($ph1ent['mobile'])) {
		$ph1found = true;
		$mobile_p1 = $ph1ent;
	}
}
if ($pconfig['enable'] && !$ph1found) {
	print_info_box(gettext("Support for IPsec Mobile Clients is enabled but a Phase 1 definition was not found") . ".<br />" . gettext("Please click Create to define one."), "warning", "create", gettext("Create Phase 1"), 'fa-solid fa-plus', 'success');
}

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('vpn-ipsec', 'vpn_ipsec_mobile.php');

/* summary of the saved settings (purely informative) */
$saved = config_get_path('ipsec/client', []);
$saved_sources = array_filter(explode(",", (string)($saved['user_source'] ?? '')));
$saved_pools = array();
if (!empty($saved['pool_address'])) {
	$saved_pools[] = $saved['pool_address'] . '/' . $saved['pool_netbits'];
}
if (!empty($saved['pool_address_v6'])) {
	$saved_pools[] = $saved['pool_address_v6'] . '/' . $saved['pool_netbits_v6'];
}
$saved_dns = array_filter(array($saved['dns_server1'] ?? '', $saved['dns_server2'] ?? '', $saved['dns_server3'] ?? '', $saved['dns_server4'] ?? ''));

fs_summary_card([
	'icon' => 'fa-mobile-screen',
	'title' => gettext('Mobile clients'),
	'subtitle' => gettext('Remote access for road warriors (IKE mode-cfg)'),
	'badges' => [isset($saved['enable']) ? fs_badge('enabled') : fs_badge('disabled')],
	'facts' => [
		$mobile_p1
		    ? [gettext('Phase 1'), (($mobile_p1['descr'] ?? '') !== '') ? $mobile_p1['descr'] : sprintf(gettext('Tunnel %s'), $mobile_p1['ikeid']), 'href' => 'vpn_ipsec_phase1.php?ikeid=' . rawurlencode($mobile_p1['ikeid'])]
		    : [gettext('Phase 1'), '', 'empty' => gettext('Not defined')],
		[gettext('User authentication'), implode(', ', $saved_sources)],
		[gettext('Address pool'), implode(', ', $saved_pools), 'mono' => true, 'empty' => gettext('none')],
		[gettext('DNS servers'), implode(', ', $saved_dns), 'mono' => true, 'empty' => gettext('none')],
	],
	'label' => gettext('Mobile clients summary'),
]);

$form = new Form;

$section = new Form_Section('Mobile client support');
$section->addInput(new Form_Checkbox(
	'enable',
	'IKE Extensions',
	'Enable IPsec Mobile Client Support',
	$pconfig['enable']
))->setHelp('Mobile clients also need a mobile phase 1 entry on the Tunnels tab.');

$form->add($section);

$section = new Form_Section('User authentication (Xauth)');

$authServers = ipsec_mobile_user_sources();

$section->addInput(new Form_Select(
	'user_source',
	'*User Authentication',
	is_array($pconfig['user_source']) ? $pconfig['user_source'] : explode(",", $pconfig['user_source']),
	$authServers,
	true
))->setHelp('Source');

$section->addInput(new Form_Checkbox(
	'group_source',
	'Group Authentication',
	'Group Authentication',
	$pconfig['group_source'],
))->setHelp('Authenticate members of groups which have either "User - VPN: IPsec with Dialin" or "WebCfg - All pages" privileges.')
  ->toggles('.toggle-group_source');

$group = new Form_Group('Authentication Groups');
$group->addClass('toggle-group_source collapse');

if (!empty($pconfig['group_source'])) {
	$group->addClass('show');
}

$group->add(new Form_Select(
	'auth_groups',
	'Groups',
	is_array($pconfig['auth_groups']) ? $pconfig['auth_groups'] : explode(",", $pconfig['auth_groups']),
	$auth_groups,
	true
))->setHelp('Multiple group selection is allowed.');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'radiusaccounting',
	'RADIUS Accounting',
	'Enable RADIUS Accounting',
	$pconfig['radiusaccounting']
))->setHelp('Sends RADIUS accounting data for mobile connections with virtual IP addresses. ' .
		'Only enable it when the selected RADIUS servers accept accounting data: if sending fails, tunnels are disconnected.');

$form->add($section);

$section = new Form_Section('Client addresses (mode-cfg)');

$section->addInput(new Form_Checkbox(
	'pool_enable',
	'Virtual Address Pool',
	'Provide a virtual IP address to clients',
	$pconfig['pool_enable']
))->toggles('.toggle-pool_enable');

// TODO: Refactor this manual setup
$group = new Form_Group('');
$group->addClass('toggle-pool_enable collapse');

if (!empty($pconfig['pool_enable'])) {
	$group->addClass('show');
}

$group->add(new Form_Input(
	'pool_address',
	'Network',
	'text',
	$pconfig['pool_address']
))->setWidth(4)->setHelp('Network configuration for Virtual Address Pool');

$netBits = array();

for ($i = 32; $i >= 0; $i--) {
	$netBits[$i] = $i;
}

$group->add(new Form_Select(
	'pool_netbits',
	'',
	$pconfig['pool_netbits'],
	$netBits
))->setWidth(2);

$section->add($group);

$section->addInput(new Form_Checkbox(
	'pool_enable_v6',
	'Virtual IPv6 Address Pool',
	'Provide a virtual IPv6 address to clients',
	$pconfig['pool_enable_v6']
))->toggles('.toggle-pool_enable_v6');

// TODO: Refactor this manual setup
$group = new Form_Group('');
$group->addClass('toggle-pool_enable_v6 collapse');

if (!empty($pconfig['pool_enable_v6'])) {
	$group->addClass('show');
}

$group->add(new Form_Input(
	'pool_address_v6',
	'IPv6 Network',
	'text',
	$pconfig['pool_address_v6']
))->setWidth(4)->setHelp('Network configuration for Virtual IPv6 Address Pool');

$netBits = array();

for ($i = 128; $i >= 0; $i--) {
	$netBitsv6[$i] = $i;
}

$group->add(new Form_Select(
	'pool_netbits_v6',
	'',
	$pconfig['pool_netbits_v6'],
	$netBitsv6
))->setWidth(2);

$section->add($group);

$section->addInput(new Form_Checkbox(
	'radius_ip_priority_enable',
	'RADIUS IP address priority',
	'IPv4/IPv6 address pool is used if address is not supplied by RADIUS server',
	$pconfig['radius_ip_priority_enable']
));

$section->addInput(new Form_Checkbox(
	'net_list_enable',
	'Network List',
	'Provide a list of accessible networks to clients',
	$pconfig['net_list_enable']
));

$form->add($section);

$section = new Form_Section('DNS');

$section->addInput(new Form_Checkbox(
	'dns_domain_enable',
	'DNS Default Domain',
	'Provide a default domain name to clients',
	$pconfig['dns_domain_enable']
))->toggles('.toggle-dns_domain');

$group = new Form_Group('');
$group->addClass('toggle-dns_domain collapse');

if (!empty($pconfig['dns_domain_enable'])) {
	$group->addClass('show');
}

$group->add(new Form_Input(
	'dns_domain',
	'',
	'text',
	$pconfig['dns_domain']
))->setHelp('Specify domain as DNS Default Domain');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'dns_split_enable',
	'Split DNS',
	'Provide a list of split DNS domain names to clients. Enter a space separated list.',
	$pconfig['dns_split_enable']
))->toggles('.toggle-dns_split');

$group = new Form_Group('');
$group->addClass('toggle-dns_split collapse');

if (!empty($pconfig['dns_split_enable'])) {
	$group->addClass('show');
}

$group->add(new Form_Input(
	'dns_split',
	'',
	'text',
	$pconfig['dns_split']
))->setHelp('If left blank and a default domain is set, the default domain is used.');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'dns_server_enable',
	'DNS Servers',
	'Provide a DNS server list to clients',
	$pconfig['dns_server_enable']
))->setHelp('IPv4-mapped IPv6 addresses (ex: fd00::1.2.3.4) are not supported.')->toggles('.toggle-dns_server_enable');

for ($i = 1; $i <= 4; $i++) {
	$group = new Form_Group('Server #' . $i);
	$group->addClass('toggle-dns_server_enable collapse');

	if (!empty($pconfig['dns_server_enable'])) {
		$group->addClass('show');
	}

	$group->add(new Form_Input(
		'dns_server' . $i,
		'Server #' . $i,
		'text',
		$pconfig['dns_server' . $i]
	));

	$section->add($group);
}

$form->add($section);

/* rarely changed client options: closed unless one is in use or a save failed */
$other_open = !empty($input_errors) || !empty($pconfig['wins_server_enable']) || !empty($pconfig['pfs_group_enable']) ||
    !empty($pconfig['login_banner_enable']) || !empty($pconfig['save_passwd_enable']);
$section = new Form_Section('Other client options', 'ipsec-mobile-other', COLLAPSIBLE | ($other_open ? SEC_OPEN : SEC_CLOSED));

$section->addInput(new Form_Checkbox(
	'save_passwd_enable',
	'Save Xauth Password',
	'Allow clients to save Xauth passwords (Cisco VPN client only).',
	$pconfig['save_passwd_enable']
))->setHelp('With iPhone clients, this only works for manual entry, not when deployed with the iPhone configuration utility.');

$section->addInput(new Form_Checkbox(
	'wins_server_enable',
	'WINS Servers',
	'Provide a WINS server list to clients',
	$pconfig['wins_server_enable']
))->toggles('.toggle-wins_server_enable');

for ($i = 1; $i <= 2; $i++) {
	$group = new Form_Group('Server #' . $i);
	$group->addClass('toggle-wins_server_enable collapse');

	if (!empty($pconfig['wins_server_enable'])) {
		$group->addClass('show');
	}

	$group->add(new Form_Input(
		'wins_server' . $i,
		'Server #' . $i,
		'text',
		$pconfig['wins_server' . $i],
		array('size' => 20)
	));

	$section->add($group);
}

$section->addInput(new Form_Checkbox(
	'pfs_group_enable',
	'Phase2 PFS Group',
	'Provide the Phase2 PFS group to clients ( overrides all mobile phase2 settings )',
	$pconfig['pfs_group_enable']
))->toggles('.toggle-pfs_group');

$group = new Form_Group('Group');
$group->addClass('toggle-pfs_group collapse');

if (!empty($pconfig['pfs_group_enable'])) {
	$group->addClass('show');
}

$group->add(new Form_Select(
	'pfs_group',
	'Group',
	$pconfig['pfs_group'],
	$p2_pfskeygroups
))->setHelp('Groups 1, 2, 5, 22, 23 and 24 provide weak security and should be avoided.');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'login_banner_enable',
	'Login Banner',
	'Provide a login banner to clients',
	$pconfig['login_banner_enable']
))->toggles('.toggle-login_banner');

$group = new Form_Group('');
$group->addClass('toggle-login_banner collapse');

if (!empty($pconfig['login_banner_enable'])) {
	$group->addClass('show');
}

// TODO: should be a textarea
$group->add(new Form_Input(
	'login_banner',
	'',
	'text',
	$pconfig['login_banner']
));

$section->add($group);

$form->add($section);

/* RADIUS tuning: closed unless in use or a save failed */
$section = new Form_Section('RADIUS advanced parameters', 'ipsec-mobile-radius',
    COLLAPSIBLE | ((!empty($input_errors) || !empty($pconfig['radius_advanced'])) ? SEC_OPEN : SEC_CLOSED));

$section->addInput(new Form_Checkbox(
	'radius_advanced',
	'RADIUS Advanced Parameters',
	'Set Advanced RADIUS parameters',
	$pconfig['radius_advanced']
))->toggles('.toggle-radius_advanced')->setHelp('May only be required when using 2FA/MFA with RADIUS or under high load.');

$group = new Form_Group('');
$group->addClass('toggle-radius_advanced collapse');

if (!empty($pconfig['radius_advanced'])) {
	$group->addClass('show');
}

$group->add(new Form_Input(
	'radius_retransmit_base',
	'Retransmit Base',
	'text',
	$pconfig['radius_retransmit_base'],
	['placeholder' => 1.4]
))->setHelp('%1$sRetransmit Base%2$s -%3$sBase to use for calculating exponential back off.',
	'<b>', '</b>', '<br/>');

$group->add(new Form_Input(
	'radius_retransmit_timeout',
	'Retransmit Timeout',
	'text',
	$pconfig['radius_retransmit_timeout'],
	['placeholder' => 2.0]
))->setHelp('%1$sRetransmit Timeout%2$s -%3$sTimeout in seconds before sending first retransmit.',
	'<b>', '</b>', '<br/>');

$group->add(new Form_Input(
	'radius_retransmit_tries',
	'Retransmit Tries',
	'text',
	$pconfig['radius_retransmit_tries'],
	['placeholder' => 4]
))->setHelp('%1$sRetransmit Tries%2$s -%3$sNumber of times to retransmit a packet before giving up.',
	'<b>', '</b>', '<br/>');

$group->add(new Form_Input(
	'radius_sockets',
	'Sockets',
	'text',
	$pconfig['radius_sockets'],
	['placeholder' => 1]
))->setHelp('%1$sSockets%2$s -%3$sNumber of sockets (ports) to use, increase for high load.',
	'<b>', '</b>', '<br/>');

$section->add($group);

$form->add($section);

print $form;

include("foot.inc");
