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
?>

	<script type="text/javascript">
		//<![CDATA[

		function pool_change() {

			if (document.iform.pool_enable.checked) {
				document.iform.pool_address.disabled = 0;
				document.iform.pool_netbits.disabled = 0;
			} else {
				document.iform.pool_address.disabled = 1;
				document.iform.pool_netbits.disabled = 1;
			}
		}

		function pool_change_v6() {

			if (document.iform.pool_enable_v6.checked) {
				document.iform.pool_address_v6.disabled = 0;
				document.iform.pool_netbits_v6.disabled = 0;
			} else {
				document.iform.pool_address_v6.disabled = 1;
				document.iform.pool_netbits_v6.disabled = 1;
			}
		}

		function radius_advanced_change() {

			if (document.iform.radius_advanced_enable.checked) {
				document.iform.radius_retransmit_base.disabled = 0;
				document.iform.radius_retransmit_timeout.disabled = 0;
				document.iform.radius_retransmit_tries.disabled = 0;
				document.iform.radius_sockets.disabled = 0;
			} else {
				document.iform.radius_retransmit_base.disabled = 1;
				document.iform.radius_retransmit_timeout.disabled = 1;
				document.iform.radius_retransmit_tries.disabled = 1;
				document.iform.radius_sockets.disabled = 1;
			}
		}

		function dns_domain_change() {

			if (document.iform.dns_domain_enable.checked) {
				document.iform.dns_domain.disabled = 0;
			} else {
				document.iform.dns_domain.disabled = 1;
			}
		}

		function dns_split_change() {

			if (document.iform.dns_split_enable.checked) {
				document.iform.dns_split.disabled = 0;
			} else {
				document.iform.dns_split.disabled = 1;
			}
		}

		function dns_server_change() {

			if (document.iform.dns_server_enable.checked) {
				document.iform.dns_server1.disabled = 0;
				document.iform.dns_server2.disabled = 0;
				document.iform.dns_server3.disabled = 0;
				document.iform.dns_server4.disabled = 0;
			} else {
				document.iform.dns_server1.disabled = 1;
				document.iform.dns_server2.disabled = 1;
				document.iform.dns_server3.disabled = 1;
				document.iform.dns_server4.disabled = 1;
			}
		}

		function wins_server_change() {

			if (document.iform.wins_server_enable.checked) {
				document.iform.wins_server1.disabled = 0;
				document.iform.wins_server2.disabled = 0;
			} else {
				document.iform.wins_server1.disabled = 1;
				document.iform.wins_server2.disabled = 1;
			}
		}

		function pfs_group_change() {

			if (document.iform.pfs_group_enable.checked) {
				document.iform.pfs_group.disabled = 0;
			} else {
				document.iform.pfs_group.disabled = 1;
			}
		}

		function login_banner_change() {

			if (document.iform.login_banner_enable.checked) {
				document.iform.login_banner.disabled = 0;
			} else {
				document.iform.login_banner.disabled = 1;
			}
		}

		//]]>
	</script>

<?php
if ($_POST['apply']) {
	print_apply_result_box($retval);
}
if (is_subsystem_dirty('ipsec')) {
	print_apply_box(gettext("The IPsec tunnel configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}
foreach (config_get_path('ipsec/phase1', []) as $ph1ent) {
	if (isset($ph1ent['mobile'])) {
		$ph1found = true;
	}
}
if ($pconfig['enable'] && !$ph1found) {
	print_info_box(gettext("Support for IPsec Mobile Clients is enabled but a Phase 1 definition was not found") . ".<br />" . gettext("Please click Create to define one."), "warning", "create", gettext("Create Phase 1"), 'fa-solid fa-plus', 'success');
}

if ($input_errors) {
	print_input_errors($input_errors);
}

$tab_array = array();
$tab_array[] = array(gettext("Tunnels"), false, "vpn_ipsec.php");
$tab_array[] = array(gettext("Mobile Clients"), true, "vpn_ipsec_mobile.php");
$tab_array[] = array(gettext("Pre-Shared Keys"), false, "vpn_ipsec_keys.php");
$tab_array[] = array(gettext("Advanced Settings"), false, "vpn_ipsec_settings.php");
display_top_tabs($tab_array);

$form = new Form;

$section = new Form_Section('Enable IPsec Mobile Client Support');
$section->addInput(new Form_Checkbox(
	'enable',
	'IKE Extensions',
	'Enable IPsec Mobile Client Support',
	$pconfig['enable']
));

$form->add($section);

$section = new Form_Section('Extended Authentication (Xauth)');

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
))->setHelp('When enabled, the IPsec daemon will attempt to send RADIUS accounting ' .
		'data for mobile IPsec connections with Virtual IP addresses. ' .
		'Do not enable this option unless the selected RADIUS servers are online and ' .
		'capable of receiving RADIUS accounting data. If RADIUS accounting data is ' .
		'enabled and fails to send, tunnels will be disconnected.');

$form->add($section);

$section = new Form_Section('Client Configuration (mode-cfg)');

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

$section->addInput(new Form_Checkbox(
	'net_list_enable',
	'Network List',
	'Provide a list of accessible networks to clients',
	$pconfig['net_list_enable']
));

$section->addInput(new Form_Checkbox(
	'save_passwd_enable',
	'Save Xauth Password',
	'Allow clients to save Xauth passwords (Cisco VPN client only).',
	$pconfig['save_passwd_enable']
))->setHelp('NOTE: With iPhone clients, this does not work when deployed via the iPhone configuration utility, only by manual entry.');

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
))->setHelp('NOTE: If left blank, and a default domain is set, it will be used for this value.');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'dns_server_enable',
	'DNS Servers',
	'Provide a DNS server list to clients',
	$pconfig['dns_server_enable']
))->setHelp('NOTE: IPv4-mapped IPv6 addresses (ex: fd00::1.2.3.4) are not supported.')->toggles('.toggle-dns_server_enable');

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
))->setHelp('Note: Groups 1, 2, 5, 22, 23, and 24 provide weak security and should be avoided.');

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

print $form;

include("foot.inc");
