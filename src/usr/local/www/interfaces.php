<?php
/*
 * interfaces.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2006 Daniel S. Haischt
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
##|*IDENT=page-interfaces
##|*NAME=Interfaces: WAN
##|*DESCR=Allow access to the 'Interfaces' page.
##|*MATCH=interfaces.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("functions.inc");
require_once("captiveportal.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("rrd.inc");
require_once("vpn.inc");
require_once("xmlparse_attr.inc");
require_once("util.inc");
require_once("interfaces_edit.inc");

define("ANTENNAS", false);

if (isset($_POST['referer'])) {
	$referer = $_POST['referer'];
} else {
	$referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/interfaces.php');
}

// Get configured interface list
$ifdescrs = get_configured_interface_with_descr(true);
$if = $_REQUEST['if'] ?? 'wan';

if (empty($ifdescrs[$if])) {
	header("Location: interfaces.php");
	exit;
}

/* The interface's settings and what the form needs (interfaces_edit.inc). */
$ctx = interfaces_edit_context($if);
$pconfig = interfaces_edit_config($if, $ctx);

$bridged = $ctx['bridged'];
$a_ppps = $ctx['a_ppps'];
$a_gateways = $ctx['a_gateways'];
$wancfg = $ctx['wancfg'];
$realifname = $ctx['realifname'];
$interfaces = $ctx['interfaces'];
$show_address_controls = $ctx['show_address_controls'];
$pppid = $ctx['pppid'];
$wlanbaseif = $ctx['wlanbaseif'];
$wl_modes = $ctx['wl_modes'];
$wl_ht_modes = $ctx['wl_ht_modes'];
$wl_chaninfo = $ctx['wl_chaninfo'];
$wl_sysctl_prefix = $ctx['wl_sysctl_prefix'];
$wl_sysctl = $ctx['wl_sysctl'];
$wl_regdomains = $ctx['wl_regdomains'];
$wl_regdomains_attr = $ctx['wl_regdomains_attr'];
$wl_countries = $ctx['wl_countries'];
$wl_countries_attr = $ctx['wl_countries_attr'];

$gateway_settings4 = [];
$gateway_settings6 = [];

$changes_applied = false;

if ($_POST['apply']) {
	unset($input_errors);
	$retval = interfaces_edit_apply($input_errors);
	$changes_applied = ($retval !== null);
} elseif ($_POST['save']) {
	unset($input_errors);
	if (interfaces_edit_save($if, $_POST, $input_errors, $ctx)) {
		header("Location: interfaces.php?if={$if}");
		exit;
	}
	$pconfig = $ctx['pconfig'];
	$gateway_settings4 = $ctx['gateway_settings4'];
	$gateway_settings6 = $ctx['gateway_settings6'];
}

// Find all possible media options for the interface
$mediaopts_list = interfaces_edit_mediaopts($if);
$intrealname = config_get_path("interfaces/{$if}/if");

$pgtitle = [gettext("Interfaces"), array_get_path($wancfg, 'descr') . " ({$realifname})"];
$shortcut_section = "interfaces";

$types4 = interfaces_edit_ipv4_types($pconfig, $ctx);

$types6 = interfaces_edit_ipv6_types();

// Get the MAC address
$defgatewayname4 = array_get_path($wancfg, 'descr') . "GW";
$defgatewayname6 = array_get_path($wancfg, 'descr') . "GWv6";

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if (is_subsystem_dirty('interfaces')) {
	print_apply_box(sprintf(gettext("The %s configuration has been changed."), array_get_path($wancfg, 'descr')) . "<br />" .
					gettext("The changes must be applied to take effect.") . "<br />" .
					gettext("Don't forget to adjust the DHCP Server range if needed after applying."));
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

$form = new Form();

$section = new Form_Section('General Configuration');

$section->addInput(new Form_Checkbox(
	'enable',
	'Enable',
	'Enable interface',
	array_get_path($pconfig, 'enable'),
	'yes'
));

$section->addInput(new Form_Input(
	'descr',
	'*Description',
	'text',
	array_get_path($pconfig, 'descr'),
))->setHelp('Enter a description (name) for the interface here.');

if ($show_address_controls) {
	$section->addInput(new Form_Select(
		'type',
		'IPv4 Configuration Type',
		array_get_path($pconfig, 'type'),
		$types4
	));
	$section->addInput(new Form_Select(
		'type6',
		'IPv6 Configuration Type',
		array_get_path($pconfig, 'type6'),
		$types6
	));
} else {
	$section->addInput(new Form_StaticText(
		'IPv4/IPv6 Configuration',
		"This interface type does not support manual address configuration on this page. "
	));
	$form->addGlobal(new Form_Input(
		'type',
		null,
		'hidden',
		'none'
	));
	$form->addGlobal(new Form_Input(
		'type6',
		null,
		'hidden',
		'none'
	));
}

$form->add($section);

// rarely changed link settings: closed unless one is set or a save failed
$link_set = !empty($input_errors) || array_get_path($pconfig, 'spoofmac') || array_get_path($pconfig, 'mtu') ||
    array_get_path($pconfig, 'mss') || config_get_path("interfaces/{$if}/media");
$section = new Form_Section('Link Settings', 'link-settings', COLLAPSIBLE | ($link_set ? SEC_OPEN : SEC_CLOSED));

if (!is_pseudo_interface($intrealname, true)) {
	$macaddress = new Form_Input(
		'spoofmac',
		'MAC Address',
		'text',
		array_get_path($pconfig, 'spoofmac'),
		['placeholder' => 'xx:xx:xx:xx:xx:xx']
	);

	if (interface_is_vlan($realifname)) {
		$macaddress->setDisabled();
		$macaddress->setHelp('The MAC address of a VLAN interface must be ' .
		    'set on its parent interface');
	} else {
		$macaddress->setHelp('This field can be used to modify ("spoof") the ' .
		    'MAC address of this interface.%sEnter a MAC address in the ' .
		    'following format: xx:xx:xx:xx:xx:xx or leave blank.', '<br />');
	}

	$section->addInput($macaddress);
}

$mtu_help_text = 'If this field is blank, the adapter\'s default MTU will be used. ' .
	'This is typically 1500 bytes but can vary in some circumstances.';
if (str_starts_with($realifname, 'ovpn')) {
	$mtu_help_text = sprintf(
		$mtu_help_text . '%s%sNote%s: MSS must also be set when using OpenVPN DCO and a non-default MTU.',
		'<br/>', '<b>', '</b>'
	);
}
$mtuInput = $section->addInput(new Form_Input(
	'mtu',
	'MTU',
	'number',
	array_get_path($pconfig, 'mtu'),
))->setHelp($mtu_help_text);
/* Do not allow MTU changes for interfaces in a bridge */
if ($bridged) {
	$mtuInput->setDisabled();
	$mtuInput->setHelp('This interface is a bridge member, its MTU is ' .
					   'controlled by its parent bridge interface');
	$mtuInput->setPlaceholder(get_interface_mtu($bridged));
	$mtuInput->setValue(null);
}

$section->addInput(new Form_Input(
	'mss',
	'MSS',
	'number',
	array_get_path($pconfig, 'mss'),
))->setHelp('If a value is entered in this field, then MSS clamping for TCP connections to the value entered above ' .
	    'minus 40 for IPv4 (TCP/IPv4 header size) and minus 60 for IPv6 (TCP/IPv6 header size) will be in effect.');

if (count($mediaopts_list) > 0) {
	$section->addInput(new Form_Select(
		'mediaopt',
		'Speed and Duplex',
		rtrim(config_get_path("interfaces/{$if}/media", "") . ' ' . config_get_path("interfaces/{$if}/mediaopt")),
		interfaces_edit_media_choices($mediaopts_list)
	))->setHelp('Explicitly set speed and duplex mode for this interface.%s' .
				'WARNING: MUST be set to autoselect (automatically negotiate speed) unless the port this interface connects to has its speed and duplex forced.', '<br />');
}

$form->add($section);

// Static IPv4 Configuration
$section = new Form_Section('Static IPv4 Configuration');
$section->addClass('staticv4');

$section->addInput(new Form_IpAddress(
	'ipaddr',
	'*IPv4 Address',
	array_get_path($pconfig, 'ipaddr'),
	'V4'
))->addMask('subnet', array_get_path($pconfig, 'subnet'), 32);

$group = new Form_Group('IPv4 Upstream gateway');

$group->add(new Form_Select(
	'gateway',
	'IPv4 Upstream Gateway',
	array_get_path($pconfig, 'gateway'),
	interfaces_edit_gateway_choices($if, $a_gateways, 4)
));

$group->add(new Form_Button(
	'addgw4',
	'Add a new gateway',
	null,
	'fa-solid fa-plus'
))->setAttribute('type','button')->addClass('btn-outline-secondary')->setAttribute('data-bs-target', '#newgateway4')->setAttribute('data-bs-toggle', 'modal');

$group->setHelp('If this interface is an Internet connection, select an existing Gateway from the list or add a new one using the "Add" button.%1$s' .
				'On local area network interfaces the upstream gateway should be "none".%1$s' .
				'Selecting an upstream gateway causes the firewall to treat this interface as a %2$sWAN type interface%4$s.%1$s' .
				'Gateways can be managed by %3$sclicking here%4$s.', '<br />', '<a target="_blank" href="https://docs.freesense.org/en/latest/interfaces/wanvslan.html">', '<a target="_blank" href="system_gateways.php">', '</a>');

$section->add($group);

$form->add($section);

// DHCP Client Configuration
$section = new Form_Section('DHCP Client Configuration');
$section->addClass('dhcp');

$group = new Form_Group('Options');

$group->add(new Form_Checkbox(
	'adv_dhcp_config_advanced',
	null,
	'Advanced Configuration',
	array_get_path($pconfig, 'adv_dhcp_config_advanced'),
))->setHelp('Use advanced DHCP configuration options.');

$group->add(new Form_Checkbox(
	'adv_dhcp_config_file_override',
	null,
	'Configuration Override',
	array_get_path($pconfig, 'adv_dhcp_config_file_override'),
))->setHelp('Override the configuration from this file.');

$section->add($group);

$section->addInput(new Form_Input(
	'dhcphostname',
	'Hostname',
	'text',
	array_get_path($pconfig, 'dhcphostname'),
))->setHelp('The value in this field is sent as the DHCP client identifier and hostname when requesting a DHCP lease. Some ISPs may require this (for client identification).');

$section->addInput(new Form_IpAddress(
	'alias-address',
	'Alias IPv4 address',
	array_get_path($pconfig, 'alias-address'),
	'V4'
))->addMask('alias-subnet', array_get_path($pconfig, 'alias-subnet'), 32)->setHelp('The value in this field is used as a fixed alias IPv4 address by the DHCP client.');

$section->addInput(new Form_Input(
	'dhcprejectfrom',
	'Reject leases from',
	'text',
	array_get_path($pconfig, 'dhcprejectfrom'),
))->setHelp('To have the DHCP client reject offers from specific DHCP servers, enter their IP addresses here ' .
			'(separate multiple entries with a comma). ' .
			'This is useful for rejecting leases from cable modems that offer private IP addresses when they lose upstream sync.');

if (interface_is_vlan(array_get_path($wancfg, 'if')) != NULL) {
	$group = new Form_Group('DHCP VLAN Priority');
	$group->add(new Form_Checkbox(
		'dhcpvlanenable',
		null,
		'Enable dhcpclient VLAN Priority tagging',
		array_get_path($pconfig, 'dhcpvlanenable'),
	))->setHelp('Normally off unless specifically required by the ISP.');

	$group->add(new Form_Select(
		'dhcpcvpt',
		'VLAN Prio',
		array_get_path($pconfig, 'dhcpcvpt'),
		$vlanprio
	))->setHelp('Choose 802.1p priority to set.');

	$section->add($group);
}

$group = new Form_Group('Protocol timing');
$group->addClass('dhcpadvanced');
$group->setHelp('The values in these fields are DHCP protocol timings used when requesting a lease.%1$s' .
				'See %2$shere%3$s for more information.', '<br />', '<a target="_blank" href="https://www.freebsd.org/cgi/man.cgi?query=dhclient.conf&sektion=5#PROTOCOL_TIMING">', '</a>');

$group->add(new Form_Input(
	'adv_dhcp_pt_timeout',
	null,
	'number',
	array_get_path($pconfig, 'adv_dhcp_pt_timeout'),
))->setHelp('Timeout');

$group->add(new Form_Input(
	'adv_dhcp_pt_retry',
	null,
	'number',
	array_get_path($pconfig, 'adv_dhcp_pt_retry'),
))->setHelp('Retry');

$group->add(new Form_Input(
	'adv_dhcp_pt_select_timeout',
	null,
	'number',
	array_get_path($pconfig, 'adv_dhcp_pt_select_timeout'),
	['min' => 0]
))->setHelp('Select timeout');

$group->add(new Form_Input(
	'adv_dhcp_pt_reboot',
	null,
	'number',
	array_get_path($pconfig, 'adv_dhcp_pt_reboot'),
))->setHelp('Reboot');

$group->add(new Form_Input(
	'adv_dhcp_pt_backoff_cutoff',
	null,
	'number',
	array_get_path($pconfig, 'adv_dhcp_pt_backoff_cutoff'),
))->setHelp('Backoff cutoff');

$group->add(new Form_Input(
	'adv_dhcp_pt_initial_interval',
	null,
	'number',
	array_get_path($pconfig, 'adv_dhcp_pt_initial_interval'),
))->setHelp('Initial interval');

$section->add($group);

$group = new Form_Group('Presets');
$group->addClass('dhcpadvanced');

$group->add(new Form_Checkbox(
	'adv_dhcp_pt_values',
	null,
	'FreeBSD default',
	null,
	'DHCP'
))->displayAsRadio();

$group->add(new Form_Checkbox(
	'adv_dhcp_pt_values',
	null,
	'Clear',
	null,
	'Clear'
))->displayAsRadio();

$group->add(new Form_Checkbox(
	'adv_dhcp_pt_values',
	null,
	'FreeSense Default',
	null,
	'FreeSense'
))->displayAsRadio();

$group->add(new Form_Checkbox(
	'adv_dhcp_pt_values',
	null,
	'Saved Cfg',
	null,
	'SavedCfg'
))->displayAsRadio();

$section->add($group);

$section->addInput(new Form_Input(
	'adv_dhcp_config_file_override_path',
	'Configuration File Override',
	'text',
	array_get_path($pconfig, 'adv_dhcp_config_file_override_path'),
))->setWidth(9)->sethelp('The value in this field is the full absolute path to a DHCP client configuration file.	 [/[dirname/[.../]]filename[.ext]] %1$s' .
			'Value Substitutions in Config File: {interface}, {hostname}, {mac_addr_asciiCD}, {mac_addr_hexCD} %1$s'.
			'Where C is U(pper) or L(ower) Case, and D is ":-." Delimiter (space, colon, hyphen, or period) (omitted for none).%1$s' .
			'Some ISPs may require certain options be or not be sent.', '<br />');

$form->add($section);

$section = new Form_Section('Lease Requirements and Requests');
$section->addClass('dhcpadvanced');

$section->addInput(new Form_Input(
	'adv_dhcp_send_options',
	'Send options',
	'text',
	array_get_path($pconfig, 'adv_dhcp_send_options'),
))->setWidth(9)->sethelp('The values in this field are DHCP options to be sent when requesting a DHCP lease.	 [option declaration [, ...]] %1$s' .
			'Value Substitutions: {interface}, {hostname}, {mac_addr_asciiCD}, {mac_addr_hexCD} %1$s' .
			'Where C is U(pper) or L(ower) Case, and D is " :-." Delimiter (space, colon, hyphen, or period) (omitted for none).%1$s' .
			'Some ISPs may require certain options be or not be sent.', '<br />');

$section->addInput(new Form_Input(
	'adv_dhcp_request_options',
	'Request options',
	'text',
	array_get_path($pconfig, 'adv_dhcp_request_options'),
))->setWidth(9)->sethelp('The values in this field are DHCP option 55 to be sent when requesting a DHCP lease.  [option [, ...]] %1$s' .
			'Some ISPs may require certain options be or not be requested.', '<br />');

$section->addInput(new Form_Input(
	'adv_dhcp_required_options',
	'Require options',
	'text',
	array_get_path($pconfig, 'adv_dhcp_required_options'),
))->setWidth(9)->sethelp('The values in this field are DHCP options required by the client when requesting a DHCP lease.	 [option [, ...]]');

$section->addInput(new Form_Input(
	'adv_dhcp_option_modifiers',
	'Option modifiers',
	'text',
	array_get_path($pconfig, 'adv_dhcp_option_modifiers'),
))->setWidth(9)->sethelp('The values in this field are DHCP option modifiers applied to the obtained DHCP lease.	 [modifier option declaration [, ...]] %1$s' .
			'modifiers: (default, supersede, prepend, append) %1$s' .
			'See %2$shere%3$s more information', '<br />', '<a target="_blank" href="https://www.freebsd.org/cgi/man.cgi?query=dhclient.conf&sektion=5#LEASE_REQUIREMENTS_AND_REQUESTS">', '</a>');

$form->add($section);

// PPP Configuration
$section = new Form_Section('PPP Configuration');
$section->addClass('ppp');

$section->addInput(new Form_Select(
	'country',
	'Country',
	array_get_path($pconfig, 'country'),
	[]
));

$section->addInput(new Form_Select(
	'provider_list',
	'Provider',
	array_get_path($pconfig, 'provider_list'),
	[]
));

$section->addInput(new Form_Select(
	'providerplan',
	'Plan',
	array_get_path($pconfig, 'providerplan'),
	[]
))->setHelp('Select to fill in service provider data.');

$section->addInput(new Form_Input(
	'ppp_username',
	'Username',
	'text',
	array_get_path($pconfig, 'ppp_username'),
	['autocomplete' => 'new-password']
));

$section->addPassword(new Form_Input(
	'ppp_password',
	'Password',
	'password',
	array_get_path($pconfig, 'ppp_password'),
));

$section->addInput(new Form_Input(
	'phone',
	'*Phone number',
	'text',
	array_get_path($pconfig, 'phone'),
))->setHelp('Typically *99# for GSM networks and #777 for CDMA networks.');

$section->addInput(new Form_Input(
	'apn',
	'Access Point Name',
	'text',
	array_get_path($pconfig, 'apn'),
));


function build_port_list() {
	$list = ["" => "None"];

	$portlist = glob("/dev/cua*");
	$modems	  = glob("/dev/modem*");
	$portlist = array_merge($portlist, $modems);

	foreach ($portlist as $port) {
		if (preg_match("/\.(lock|init)$/", $port)) {
			continue;
		}

		$port = trim($port);
		$list[$port] = $port;
	}

	return($list);
}

$section->addInput(new Form_Select(
	'port',
	"*Modem port",
	array_get_path($pconfig, 'port'),
	build_port_list()
));

$section->addInput(new Form_Button(
	'btnadvppp',
	'Advanced PPP',
	array_path_enabled($pconfig, '', 'pppid') ? 'interfaces_ppps_edit.php?id=' . htmlspecialchars(array_get_path($pconfig, 'pppid')) : 'interfaces_ppps_edit.php',
	'fa-solid fa-gear'
))->setAttribute('type','button')->removeClass('btn-secondary')->addClass('btn-outline-secondary')->setAttribute('id')->setHelp('Create a new PPP configuration.');

$form->add($section);

// PPPoE Configuration
$section = new Form_Section('PPPoE Configuration');
$section->addClass('pppoe');

$section->addInput(new Form_Input(
	'pppoe_username',
	'*Username',
	'text',
	array_get_path($pconfig, 'pppoe_username'),
	['autocomplete' => 'new-password']
));

$section->addPassword(new Form_Input(
	'pppoe_password',
	'*Password',
	'password',
	array_get_path($pconfig, 'pppoe_password'),
));

$section->addInput(new Form_Input(
	'provider',
	'Service name',
	'text',
	array_get_path($pconfig, 'provider'),
))->setHelp('This field can usually be left empty.');

$section->addInput(new Form_Input(
	'hostuniq',
	'Host-Uniq',
	'text',
	array_get_path($pconfig, 'hostuniq'),
))->setHelp('A unique host tag value for this PPPoE client. Leave blank unless a value is required by the service provider.');

$section->addInput(new Form_Checkbox(
	'pppoe_dialondemand',
	'Dial on demand',
	'Enable Dial-On-Demand mode ',
	array_get_path($pconfig, 'pppoe_dialondemand'),
	'enable'
));

$section->addInput(new Form_Input(
	'pppoe_idletimeout',
	'Idle timeout',
	'number',
	array_get_path($pconfig, 'pppoe_idletimeout'),
	['min' => 0]
))->setHelp('If no qualifying outgoing packets are transmitted for the specified number of seconds, the connection is brought down. ' .
			'An idle timeout of zero disables this feature.');

$section->addInput(new Form_Select(
	'pppoe-reset-type',
	'Periodic reset',
	array_get_path($pconfig, 'pppoe-reset-type'),
	['' => gettext('Disabled'), 'custom' => gettext('Custom'), 'preset' => gettext('Pre-set')]
))->setHelp('Select a reset timing type.');

$group = new Form_Group('Custom reset');
$group->addClass('pppoecustom');

$group->add(new Form_Input(
	'pppoe_resethour',
	null,
	'number',
	(strlen(array_get_path($pconfig, 'pppoe_resethour')) > 0) ? array_get_path($pconfig, 'pppoe_resethour'): "0",
	['min' => 0, 'max' => 23]
))->setHelp('Hour (0-23), blank for * (every)');

$group->add(new Form_Input(
	'pppoe_resetminute',
	null,
	'number',
	(strlen(array_get_path($pconfig, 'pppoe_resetminute')) > 0) ? array_get_path($pconfig, 'pppoe_resetminute') : "0",
	['min' => 0, 'max' => 59]
))->setHelp('Minute (0-59), blank for * (every)');

$group->add(new Form_Input(
	'pppoe_resetdate',
	null,
	'text',
	array_get_path($pconfig, 'pppoe_resetdate'),
))->setHelp('Specific date (mm/dd/yyyy)');

$group->setHelp('Leave the date field empty, for the reset to be executed each day at the time specified by the minutes and hour fields');

$section->add($group);

$group = new Form_MultiCheckboxGroup('cron based reset');
$group->addClass('pppoepreset');

$group->add(new Form_MultiCheckbox(
	'pppoe_pr_preset_val',
	null,
	'Reset at each month ("0 0 1 * *")',
	array_get_path($pconfig, 'pppoe_monthly'),
	'monthly'
))->displayAsRadio();

$group->add(new Form_MultiCheckbox(
	'pppoe_pr_preset_val',
	null,
	'Reset at each week ("0 0 * * 0")',
	array_get_path($pconfig, 'pppoe_weekly'),
	'weekly'
))->displayAsRadio();

$group->add(new Form_MultiCheckbox(
	'pppoe_pr_preset_val',
	null,
	'Reset at each day ("0 0 * * *")',
	array_get_path($pconfig, 'pppoe_daily'),
	'daily'
))->displayAsRadio();

$group->add(new Form_MultiCheckbox(
	'pppoe_pr_preset_val',
	null,
	'Reset at each hour ("0 * * * *")',
	array_get_path($pconfig, 'pppoe_hourly'),
	'hourly'
))->displayAsRadio();

$section->add($group);

$section->addInput(new Form_Button(
	'btnadvppp',
	'Advanced and MLPPP',
	array_path_enabled($pconfig, '', 'pppid') ? 'interfaces_ppps_edit.php?id=' . htmlspecialchars(array_get_path($pconfig, 'pppid')) : 'interfaces_ppps_edit.php',
	'fa-solid fa-gear'
))->setAttribute('type','button')->removeClass('btn-secondary')->addClass('btn-outline-secondary')->setAttribute('id')->setHelp('Click for additional PPPoE configuration options. Save first if changes have been made.');

$form->add($section);

// PPTP & L2TP Configuration
$section = new Form_Section('PPTP/L2TP Configuration');
$section->addClass('pptp');

$section->addInput(new Form_Input(
	'pptp_username',
	'*Username',
	'text',
	array_get_path($pconfig, 'pptp_username'),
	['autocomplete' => 'new-password']
));

$section->addPassword(new Form_Input(
	'pptp_password',
	'*Password',
	'password',
	array_get_path($pconfig, 'pptp_password'),
));

$group = new Form_Group('Shared Secret');

$group->add(new Form_Input(
	'l2tp_secret',
	'*Secret',
	'password',
	array_get_path($pconfig, 'l2tp_secret'),
))->setHelp('L2TP tunnel Shared Secret. Used to authenticate tunnel connection and encrypt ' .
	    'important control packet contents. (Optional)');

$group->addClass('l2tp_secret');
$section->add($group);

$section->addInput(new Form_IpAddress(
	'pptp_local0',
	'*Local IP address',
	$_POST['pptp_local0'] ? $_POST['pptp_local0'] : array_get_path($pconfig, 'pptp_localip/0', []),
	'V4'
))->addMask('pptp_subnet0', $_POST['pptp_subnet0'] ? $_POST['pptp_subnet0'] : array_get_path($pconfig, 'pptp_subnet/0', []));

$section->addInput(new Form_IpAddress(
	'pptp_remote0',
	'*Remote IP address',
	$_POST['pptp_remote0'] ? $_POST['pptp_remote0'] : array_get_path($pconfig, 'pptp_remote/0', []),
	'HOSTV4'
));

$section->addInput(new Form_Checkbox(
	'pptp_dialondemand',
	'Dial on demand',
	'Enable Dial-On-Demand mode ',
	array_get_path($pconfig, 'pptp_dialondemand'),
	'enable'
))->setHelp('This option causes the interface to operate in dial-on-demand mode, allowing it to be a virtual full time connection. ' .
			'The interface is configured, but the actual connection of the link is delayed until qualifying outgoing traffic is detected.');

$section->addInput(new Form_Input(
	'pptp_idletimeout',
	'Idle timeout (seconds)',
	'number',
	array_get_path($pconfig, 'pptp_idletimeout'),
	['min' => 0]
))->setHelp('If no qualifying outgoing packets are transmitted for the specified number of seconds, the connection is brought down. ' .
			'An idle timeout of zero disables this feature.');

if (array_path_enabled($pconfig, 'pptp_localip', '1') ||
    array_path_enabled($pconfig, 'pptp_subnet', '1') |
    array_path_enabled($pconfig, 'pptp_remote', '1')) {
	$mlppp_text = gettext("There are additional Local and Remote IP addresses defined for MLPPP.") . "<br />";
} else {
	$mlppp_text = "";
}

$section->addInput(new Form_Button(
	'btnadvppp',
	'Advanced and MLPPP',
	array_path_enabled($pconfig, '', 'pppid') ? 'interfaces_ppps_edit.php?id=' . htmlspecialchars(array_get_path($pconfig, 'pppid')) : 'interfaces_ppps_edit.php',
	'fa-solid fa-gear'
))->setAttribute('type','button')->removeClass('btn-secondary')->addClass('btn-outline-secondary')->setAttribute('id')->setHelp('%sClick for additional PPTP and L2TP configuration options. Save first if changes have been made.', $mlppp_text);

$form->add($section);

$section = new Form_Section('Static IPv6 Configuration');
$section->addClass('staticv6');

$section->addInput(new Form_IpAddress(
	'ipaddrv6',
	'*IPv6 address',
	array_get_path($pconfig, 'ipaddrv6'),
	'V6'
))->addMask('subnetv6', array_get_path($pconfig, 'subnetv6'), 128);

$section->addInput(new Form_Checkbox(
	'ipv6usev4iface',
	'Use IPv4 connectivity as parent interface',
	'IPv6 will use the IPv4 connectivity link (PPPoE)',
	array_get_path($pconfig, 'ipv6usev4iface'),
));

$group = new Form_Group('IPv6 Upstream gateway');

$group->add(new Form_Select(
	'gatewayv6',
	'IPv6 Upstream Gateway',
	array_get_path($pconfig, 'gatewayv6'),
	interfaces_edit_gateway_choices($if, $a_gateways, 6)
));

$group->add(new Form_Button(
	'addgw6',
	'Add a new gateway',
	null,
	'fa-solid fa-plus'
))->setAttribute('type','button')->addClass('btn-outline-secondary')->setAttribute('data-bs-target', '#newgateway6')->setAttribute('data-bs-toggle', 'modal');

$group->setHelp('If this interface is an Internet connection, select an existing Gateway from the list or add a new one using the "Add" button.%s' .
				'On local LANs the upstream gateway should be "none". ', '<br />');

$section->add($group);
$form->add($section);

// Add new gateway modal pop-up for IPv6
$modal = new Modal('New IPv6 Gateway', 'newgateway6', 'large');

$modal->addInput(new Form_Checkbox(
	'defaultgw6',
	'Default',
	'Default gateway',
	array_set_path($gateway_settings6, 'defaultgw', (strtolower($if) == "wan")),
));

$modal->addInput(new Form_Input(
	'gatewayname6',
	'Gateway name',
	'text',
	array_set_path($gateway_settings6, 'name', $defgatewayname6),
));

$modal->addInput(new Form_IpAddress(
	'gatewayip6',
	'Gateway IPv6',
	array_get_path($gateway_settings6, 'gateway'),
	'V6'
));

$modal->addInput(new Form_Input(
	'gatewaydescr6',
	'Description',
	'text',
	array_get_path($gateway_settings6, 'descr')
));

$btnaddgw6 = new Form_Button(
	'add6',
	'Add',
	null,
	'fa-solid fa-plus'
);

$btnaddgw6->setAttribute('type','button')->addClass('btn-primary');

$btncnxgw6 = new Form_Button(
	'cnx6',
	'Cancel',
	null,
	'fa-solid fa-arrow-rotate-left'
);

$btncnxgw6->setAttribute('type','button')->addClass('btn-outline-secondary');

$modal->addInput(new Form_StaticText(
	null,
	$btnaddgw6 . $btncnxgw6
));

$form->add($modal);

// DHCP6 Client Configuration
$section = new Form_Section('DHCP6 Client Configuration');
$section->addClass('dhcp6');

$group = new Form_Group('Options');

$group->add(new Form_Checkbox(
	'adv_dhcp6_config_advanced',
	null,
	'Advanced Configuration',
	array_get_path($pconfig, 'adv_dhcp6_config_advanced'),
))->setHelp('Use advanced DHCPv6 configuration options.');

$group->add(new Form_Checkbox(
	'adv_dhcp6_config_file_override',
	null,
	'Configuration Override',
	array_get_path($pconfig, 'adv_dhcp6_config_file_override'),
))->setHelp('Override the configuration from this file.');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'dhcp6usev4iface',
	'Use IPv4 connectivity as parent interface',
	'Request a IPv6 prefix/information through the IPv4 connectivity link',
	array_get_path($pconfig, 'dhcp6usev4iface'),
));

$section->addInput(new Form_Checkbox(
	'dhcp6prefixonly',
	'Request only an IPv6 prefix',
	'Only request an IPv6 prefix, do not request an IPv6 address',
	array_get_path($pconfig, 'dhcp6prefixonly'),
));

$section->addInput(new Form_Select(
	'dhcp6-ia-pd-len',
	'DHCPv6 Prefix Delegation size',
	array_get_path($pconfig, 'dhcp6-ia-pd-len'),
	["none" => "None", 16 => "48", 15 => "49", 14 => "50", 13 => "51", 12 => "52", 11 => "53", 10 => "54", 9 => "55", 8 => "56", 7 => "57", 6 => "58", 5 => "59", 4 => "60", 3 => "61", 2 => "62", 1 => "63", 0 => "64"]
))->setHelp('The value in this field is the delegated prefix length provided by the DHCPv6 server. Normally specified by the ISP.');

$section->addInput(new Form_Checkbox(
	'dhcp6-ia-pd-send-hint',
	'Send IPv6 prefix hint',
	'Send an IPv6 prefix hint to indicate the desired prefix size for delegation',
	array_get_path($pconfig, 'dhcp6-ia-pd-send-hint'),
));

$section->addInput(new Form_Checkbox(
	'dhcp6withoutra',
	'Do not wait for a RA',
	'Required by some ISPs, especially those not using PPPoE',
	array_get_path($pconfig, 'dhcp6withoutra'),
));

if (interface_is_vlan(array_get_path($wancfg, 'if')) != NULL) {
	$group = new Form_Group('DHCP6 VLAN Priority');

	$group->add(new Form_Checkbox(
		'dhcp6vlanenable',
		null,
		'Enable dhcp6c VLAN Priority tagging',
		array_get_path($pconfig, 'dhcp6vlanenable'),
	))->setHelp('Normally off unless specifically required by the ISP.');

	$group->add(new Form_Select(
		'dhcp6cvpt',
		'VLAN Prio',
		array_get_path($pconfig, 'dhcp6cvpt'),
		$vlanprio
	))->setHelp('Choose 802.1p priority to set.');

	$section->add($group);
}

$section->addInput(new Form_Input(
	'adv_dhcp6_config_file_override_path',
	'Configuration File Override',
	'text',
	array_get_path($pconfig, 'adv_dhcp6_config_file_override_path'),
))->setWidth(9)->setHelp('The value in this field is the full absolute path to a DHCP client configuration file.	 [/[dirname/[.../]]filename[.ext]] %1$s' .
			'Value Substitutions in Config File: {interface}, {hostname}, {mac_addr_asciiCD}, {mac_addr_hexCD} %1$s' .
			'Where C is U(pper) or L(ower) Case, and D is " :-." Delimiter (space, colon, hyphen, or period) (omitted for none).%1$s' .
			'Some ISPs may require certain options be or not be sent.', '<br />');

$form->add($section);

// DHCP6 Client Advanced Configuration
$section = new Form_Section('Advanced DHCP6 Client Configuration');
$section->addClass('dhcp6advanced');

$section->addInput(new Form_Checkbox(
	'adv_dhcp6_interface_statement_information_only_enable',
	'Information only',
	'Exchange Information Only',
	array_get_path($pconfig, 'adv_dhcp6_interface_statement_information_only_enable'),
	'Selected'
))->setHelp('Only exchange informational configuration parameters with servers.');

$section->addInput(new Form_Input(
	'adv_dhcp6_interface_statement_send_options',
	'Send options',
	'text',
	array_get_path($pconfig, 'adv_dhcp6_interface_statement_send_options'),
))->setWidth(9)->sethelp('DHCP send options to be sent when requesting a DHCP lease.	 [option declaration [, ...]] %1$s' .
			'Value Substitutions: {interface}, {hostname}, {mac_addr_asciiCD}, {mac_addr_hexCD} %1$s' .
			'Where C is U(pper) or L(ower) Case, and D is " :-." Delimiter (space, colon, hyphen, or period) (omitted for none).%1$s' .
			'Some DHCP services may require certain options be or not be sent.', '<br />');

$section->addInput(new Form_Input(
	'adv_dhcp6_interface_statement_request_options',
	'Request Options',
	'text',
	array_get_path($pconfig, 'adv_dhcp6_interface_statement_request_options'),
))->setWidth(9)->sethelp('DHCP request options to be sent when requesting a DHCP lease.	[option [, ...]] %1$s' .
			'Some DHCP services may require certain options be or not be requested.', '<br />');

$section->addInput(new Form_Input(
	'adv_dhcp6_interface_statement_script',
	'Scripts',
	'text',
	array_get_path($pconfig, 'adv_dhcp6_interface_statement_script'),
))->setWidth(9)->sethelp('Absolute path to a script invoked on certain conditions including when a reply message is received.%1$s' .
			'[/[dirname/[.../]]filename[.ext]].', '<br />');

$group = new Form_Group('Identity Association Statement');

$group->add(new Form_Checkbox(
	'adv_dhcp6_id_assoc_statement_address_enable',
	null,
	'Non-Temporary Address Allocation',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_address_enable'),
	'Selected'
));

$group->add(new Form_Input(
	'adv_dhcp6_id_assoc_statement_address_id',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_address_id'),
))->sethelp('id-assoc na ID');

$group->add(new Form_IpAddress(
	'adv_dhcp6_id_assoc_statement_address',
	null,
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_address'),
	'V6'
))->sethelp('IPv6 address');

$group->add(new Form_Input(
	'adv_dhcp6_id_assoc_statement_address_pltime',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_address_pltime'),
))->sethelp('pltime');

$group->add(new Form_Input(
	'adv_dhcp6_id_assoc_statement_address_vltime',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_address_vltime'),
))->sethelp('vltime');

$section->add($group);

// Prefix delegation
$group = new Form_Group('');

$group->add(new Form_Checkbox(
	'adv_dhcp6_id_assoc_statement_prefix_enable',
	null,
	'Prefix Delegation ',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_prefix_enable'),
	'Selected'
));

$group->add(new Form_Input(
	'adv_dhcp6_id_assoc_statement_prefix_id',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_prefix_id'),
))->sethelp('id-assoc pd ID');

$group->add(new Form_IpAddress(
	'adv_dhcp6_id_assoc_statement_prefix',
	null,
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_prefix'),
	'V6'
))->sethelp('IPv6 prefix');

$group->add(new Form_Input(
	'adv_dhcp6_id_assoc_statement_prefix_pltime',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_prefix_pltime'),
))->sethelp('pltime');

$group->add(new Form_Input(
	'adv_dhcp6_id_assoc_statement_prefix_vltime',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_id_assoc_statement_prefix_vltime'),
))->sethelp('vltime');

$section->add($group);

$group = new Form_Group('Prefix interface statement');

$group->add(new Form_Input(
	'adv_dhcp6_prefix_interface_statement_sla_id',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_prefix_interface_statement_sla_id'),
))->sethelp('Prefix Interface sla-id');

$group->add(new Form_Input(
	'adv_dhcp6_prefix_interface_statement_sla_len',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_prefix_interface_statement_sla_len'),
))->sethelp('sla-len');

$section->add($group);

$group = new Form_Group('Select prefix interface');
$section->addInput(new Form_Select(
	'adv_dhcp6_prefix_selected_interface',
	'Prefix Interface',
	array_get_path($pconfig, 'adv_dhcp6_prefix_selected_interface'),
	$interfaces
))->setHelp('Select the interface on which to apply the prefix delegation.');

$group = new Form_Group('Authentication statement');

$group->add(new Form_Input(
	'adv_dhcp6_authentication_statement_authname',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_authentication_statement_authname'),
))->sethelp('Authname');

$group->add(new Form_Input(
	'adv_dhcp6_authentication_statement_protocol',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_authentication_statement_protocol'),
))->sethelp('Protocol');

$group->add(new Form_Input(
	'adv_dhcp6_authentication_statement_algorithm',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_authentication_statement_algorithm'),
))->sethelp('Algorithm');

$group->add(new Form_Input(
	'adv_dhcp6_authentication_statement_rdm',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_authentication_statement_rdm'),
))->sethelp('RDM');

$section->add($group);

$group = new Form_Group('Keyinfo statement');

$group->add(new Form_Input(
	'adv_dhcp6_key_info_statement_keyname',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_key_info_statement_keyname'),
))->sethelp('Keyname');

$group->add(new Form_Input(
	'adv_dhcp6_key_info_statement_realm',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_key_info_statement_realm'),
))->sethelp('Realm');

$section->add($group);

$group = new Form_Group('');

$group->add(new Form_Input(
	'adv_dhcp6_key_info_statement_keyid',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_key_info_statement_keyid'),
))->sethelp('KeyID');

$group->add(new Form_Input(
	'adv_dhcp6_key_info_statement_secret',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_key_info_statement_secret'),
))->sethelp('Secret');

$group->add(new Form_Input(
	'adv_dhcp6_key_info_statement_expire',
	null,
	'text',
	array_get_path($pconfig, 'adv_dhcp6_key_info_statement_expire'),
))->sethelp('Expire');

$group->setHelp('See %1$shere%2$s more information', '<a target="_blank" href="https://www.freebsd.org/cgi/man.cgi?query=dhcp6c.conf&sektion=5&apropos=0&manpath=FreeBSD+11.0-RELEASE+and+Ports#Interface_statement">', '</a>');

$section->add($group);

$form->add($section);

// SLAAC IPv6 Configuration
$section = new Form_Section('SLAAC IPv6 Configuration');
$section->addClass('slaac');

$section->addInput(new Form_Checkbox(
	'slaacusev4iface',
	'Use IPv4 connectivity as parent interface',
	'IPv6 will use the IPv4 connectivity link (PPPoE)',
	array_get_path($pconfig, 'slaacusev4iface'),
));

$form->add($section);

// 6RD Configuration
$section = new Form_Section('6RD Configuration');
$section->addClass('_6rd');

$section->addInput(new Form_Input(
	'prefix-6rd',
	'6RD Prefix',
	'text',
	array_get_path($pconfig, 'prefix-6rd'),
))->sethelp('6RD IPv6 prefix assigned by the ISP. e.g. "2001:db8::/32"');

$section->addInput(new Form_Input(
	'gateway-6rd',
	'*6RD Border relay',
	'text',
	array_get_path($pconfig, 'gateway-6rd'),
))->sethelp('6RD IPv4 gateway address assigned by the ISP');

$section->addInput(new Form_Select(
	'prefix-6rd-v4plen',
	'6RD IPv4 Prefix length',
	array_get_path($pconfig, 'prefix-6rd-v4plen'),
	array_combine(range(0, 32), range(0, 32))
))->setHelp('6RD IPv4 prefix length. Normally specified by the ISP. A value of 0 means embed the entire IPv4 address in the 6RD prefix.');

$form->add($section);

// Track IPv6 Interface
$section = new Form_Section('Track IPv6 Interface');
$section->addClass('track6');

$section->addInput(new Form_Select(
	'track6-interface',
	'*IPv6 Interface',
	array_get_path($pconfig, 'track6-interface'),
	build_ipv6interface_list()
))->setHelp('Selects the dynamic IPv6 WAN interface to track for configuration.');

if (array_get_path($pconfig, 'track6-prefix-id') == "") {
	array_set_path($pconfig, 'track6-prefix-id', 0);
}

$section->addInput(new Form_Input(
	'track6-prefix-id--hex',
	'IPv6 Prefix ID',
	'text',
	sprintf("%x", array_get_path($pconfig, 'track6-prefix-id'))
))->setHelp('(%1$shexadecimal%2$s from 0 to %3$s) The value in this field is the (Delegated) IPv6 prefix ID. This determines the configurable network ID based on the dynamic IPv6 connection. The default value is 0.', '<b>', '</b>', '<span id="track6-prefix-id-range"></span>');

$form->addGlobal(new Form_Input(
	'track6-prefix-id-max',
	null,
	'hidden',
	0
));

$form->add($section);

// Wireless interface
if (is_array(array_get_path($wancfg, 'wireless'))) {
	$section = new Form_Section('Common Wireless Configuration - Settings apply to all wireless networks on ' . $wlanbaseif . '.');

	$section->addInput(new Form_Checkbox(
		'persistcommonwireless',
		'Persist common settings',
		'Preserve common wireless configuration through interface deletions and reassignments.',
		array_get_path($pconfig, 'persistcommonwireless'),
		'yes'
	));

	$mode_list = ['auto' => 'Auto'];

	if (is_array($wl_modes)) {
		foreach ($wl_modes as $wl_standard => $wl_channels) {
			array_set_path($mode_list, $wl_standard, '802.' . $wl_standard);
		}
	}

	if (count($mode_list) == 1) {
		$mode_list[''] = '';
	}

	$section->addInput(new Form_Select(
		'standard',
		'Standard',
		(array_get_path($pconfig, 'standard') == "") ? "11ng" : array_get_path($pconfig, 'standard'),
		$mode_list
	));

	if (isset($wl_modes['11g'])) {
		$section->addInput(new Form_Select(
			'protmode',
			'802.11g OFDM Protection Mode',
			array_get_path($pconfig, 'protmode'),
			['off' => gettext('Off'), 'cts' => gettext('CTS to self'), 'rtscts' => gettext('RTS and CTS')]
		))->setHelp('For IEEE 802.11g, use the specified technique for protecting OFDM frames in a mixed 11b/11g network.');
	} else {
		$form->addGlobal(new Form_Input(
			'protmode',
			null,
			'hidden',
			'off'
		));
	}

	$mode_list = ['0' => gettext('Auto')];

	if (is_array($wl_modes)) {
		foreach ($wl_modes as $wl_standard => $wl_channels) {
			if ($wl_standard == "11g") {
				$wl_standard = "11b/g";
			} elseif ($wl_standard == "11ng") {
				$wl_standard = "11b/g/n";
			} elseif ($wl_standard == "11na") {
				$wl_standard = "11a/n";
			}

			foreach ($wl_channels as $wl_channel) {
				if (isset($wl_chaninfo[$wl_channel])) {
					array_set_path($mode_list, $wl_channel, $wl_standard . ' - ' . $wl_channel);
				} else {
					$tcinfo = array_get_path($wl_chaninfo, $wl_channel, []);
					array_set_path($mode_list, $wl_channel, $wl_standard . ' - ' . $wl_channel . ' (' . $tcinfo[1] . ' @ ' . $tcinfo[2] . ' / ' . $tcinfo[3] . ')');
				}
			}
		}
	}

	$section->addInput(new Form_Select(
		'channel',
		'Channel',
		array_get_path($pconfig, 'channel'),
		$mode_list
	))->setHelp('Legend: wireless standards - channel # (frequency @ max TX power / TX power allowed in reg. domain) %1$s' .
				'Not all channels may be supported by some cards.  Auto may override the wireless standard selected above.', '<br />');

	$section->addInput(new Form_Select(
		'channel_width',
		'Channel width',
		array_get_path($pconfig, 'channel_width'),
		$wl_ht_modes
	))->setHelp('Channel width for 802.11n mode. Not all cards may support channel width changing.');

	if (ANTENNAS) {
		if (isset($wl_sysctl["{$wl_sysctl_prefix}.diversity"]) ||
		    isset($wl_sysctl["{$wl_sysctl_prefix}.txantenna"]) ||
		    isset($wl_sysctl["{$wl_sysctl_prefix}.rxantenna"])) {
			$group = new Form_Group('Antenna Settings');

			if (isset($wl_sysctl["{$wl_sysctl_prefix}.diversity"])) {
				$group->add(new Form_Select(
					'diversity',
					null,
					array_get_path($pconfig, 'diversity', ''),
					['' => gettext('Default'), '0' => gettext('Off'), '1' => gettext('On')]
				))->setHelp('Diversity');
			}

			if (isset($wl_sysctl["{$wl_sysctl_prefix}.txantenna"])) {
				$group->add(new Form_Select(
					'txantenna',
					null,
					array_get_path($pconfig, 'txantenna', ''),
					['' => gettext('Default'), '0' => gettext('Auto'), '1' => gettext('#1'), '2' => gettext('#2')]
				))->setHelp('Transmit antenna');
			}

			if (isset($wl_sysctl["{$wl_sysctl_prefix}.rxantenna"])) {
				$group->add(new Form_Select(
					'rxantenna',
					null,
					array_get_path($pconfig, 'rxantenna', ''),
					['' => gettext('Default'), '0' => gettext('Auto'), '1' => gettext('#1'), '2' => gettext('#2')]
				))->setHelp('Receive antenna');
			}

			$group->setHelp('Note: The antenna numbers do not always match up with the labels on the card.');

			$section->add($group);
		}
	}

	if (isset($wl_sysctl["{$wl_sysctl_prefix}.slottime"]) &&
	    isset($wl_sysctl["{$wl_sysctl_prefix}.acktimeout"]) &&
	    isset($wl_sysctl["{$wl_sysctl_prefix}.ctstimeout"])) {
			$section->addInput(new Form_Input(
				'distance',
				'Distance setting (meters)',
				'test',
				array_get_path($pconfig, 'distance'),
			))->setHelp('This field can be used to tune ACK/CTS timers to fit the distance between AP and Client');
	}

	$form->add($section);

	// Regulatory settings
	$section = new Form_Section('Regulatory Settings');

	$domain_list = ["" => 'Default'];

	if (is_array($wl_regdomains)) {
		foreach ($wl_regdomains as $wl_regdomain_key => $wl_regdomain) {
			array_set_path($domain_list, array_get_path($wl_regdomains_attr, "{$wl_regdomain_key}/ID"), array_get_path($wl_regdomain, 'name'));
		}
	}

	$section->addInput(new Form_Select(
		'regdomain',
		'Regulatory domain',
		array_get_path($pconfig, 'regdomain'),
		$domain_list
	))->setHelp('Some cards have a default that is not recognized and require changing the regulatory domain to one in this list for the changes to other regulatory settings to work');

	$country_list = ['' => 'Default'];

	if (is_array($wl_countries)) {
		foreach ($wl_countries as $wl_country_key => $wl_country) {
			array_set_path($country_list, array_get_path($wl_countries_attr, "{$wl_country_key}/ID"), array_get_path($wl_country, 'name'));
		}
	}

	$section->addInput(new Form_Select(
		'regcountry',
		'Country',
		array_get_path($pconfig, 'regcountry'),
		$country_list
	))->setHelp('Any country setting other than "Default" will override the regulatory domain setting');

	$section->addInput(new Form_Select(
		'reglocation',
		'Location',
		array_get_path($pconfig, 'reglocation'),
		['' => gettext('Default'), 'indoor' => gettext('Indoor'), 'outdoor' => gettext('Outdoor'), 'anywhere' => gettext('Anywhere')]
	))->setHelp('These settings may affect which channels are available and the maximum transmit power allowed on those channels. ' .
				'Using the correct settings to comply with local regulatory requirements is recommended.%1$s' .
				'All wireless networks on this interface will be temporarily brought down when changing regulatory settings.  ' .
				'Some of the regulatory domains or country codes may not be allowed by some cards.	' .
				'These settings may not be able to add additional channels that are not already supported.', '<br />');

	$form->add($section);

	$section = new Form_Section('Network-Specific Wireless Configuration');

	$section->addInput(new Form_Select(
		'mode',
		'Mode',
		array_get_path($pconfig, 'mode'),
		['bss' => gettext('Infrastructure (BSS)'), 'adhoc' => gettext('Ad-hoc (IBSS)'), 'hostap' => gettext('Access Point')]
	));

	$section->addInput(new Form_Input(
		'ssid',
		'SSID',
		'text',
		array_get_path($pconfig, 'ssid'),
	));

	if (isset($wl_modes['11ng']) ||
	    isset($wl_modes['11na'])) {
		$section->addInput(new Form_Select(
			'puremode',
			'Minimum wireless standard',
			array_get_path($pconfig, 'puremode'),
			['any' => gettext('Any'), '11g' => gettext('802.11g'), '11n' => gettext('802.11n')]
		))->setHelp('When operating as an access point, allow only stations capable of the selected wireless standard to associate (stations not capable are not permitted to associate)');
	} elseif (isset($wl_modes['11g'])) {
		$section->addInput(new Form_Checkbox(
			'puremode',
			'802.11g only',
			null,
			array_get_path($pconfig, 'puremode'),
			'11g'
		))->setHelp('When operating as an access point in 802.11g mode, allow only 11g-capable stations to associate (11b-only stations are not permitted to associate)');
	}

	$section->addInput(new Form_Checkbox(
		'apbridge_enable',
		'Allow intra-BSS communication',
		'Allow packets to pass between wireless clients directly when operating as an access point',
		array_get_path($pconfig, 'apbridge_enable'),
		'yes'
	))->setHelp('Provides extra security by isolating clients so they cannot directly communicate with one another');

	$section->addInput(new Form_Checkbox(
		'wme_enable',
		'Enable WME',
		'Force the card to use WME (wireless QoS)',
		array_get_path($pconfig, 'wme_enable'),
		'yes'
	));

	$section->addInput(new Form_Checkbox(
		'hidessid_enable',
		'Hide SSID',
		'Disable broadcasting of the SSID for this network (This may cause problems for some clients, and the SSID may still be discovered by other means.)',
		array_get_path($pconfig, 'hidessid_enable'),
		'yes'
	));

	$form->add($section);

	// WPA Section
	$section = new Form_Section('WPA');

	$section->addInput(new Form_Checkbox(
		'wpa_enable',
		'Enable',
		'Enable WPA',
		array_get_path($pconfig, 'wpa_enable'),
		'yes'
	));

	$section->addInput(new Form_Select(
		'wpa_mode',
		'WPA mode',
		array_get_path($pconfig, 'wpa_mode', 2),
		['1' => gettext('WPA'), '2' => gettext('WPA2'), '3' => gettext('Both')]
	));

	$section->addInput(new Form_Select(
		'wpa_pairwise',
		'WPA Pairwise',
		array_get_path($pconfig, 'wpa_pairwise', 'CCMP'),
		['CCMP TKIP' => gettext('Both'), 'CCMP' => gettext('AES (recommended)'), 'TKIP' => gettext('TKIP')]
	));

	$section->addInput(new Form_Select(
		'wpa_key_mgmt',
		'WPA Key Management Mode',
		array_get_path($pconfig, 'wpa_key_mgmt'),
		['WPA-PSK' => gettext('Pre-Shared Key'), 'WPA-EAP' => gettext('Extensible Authentication Protocol'), 'WPA-PSK WPA-EAP' => gettext('Both')]
	));

	$section->addInput(new Form_Input(
		'passphrase',
		'WPA Pre-Shared Key',
		'text',
		array_get_path($pconfig, 'passphrase'),
	))->setHelp('WPA Passphrase must be between 8 and 63 characters long');

	$section->addInput(new Form_Select(
		'wpa_eap_client_mode',
		'EAP Client Mode',
		array_get_path($pconfig, 'wpa_eap_client_mode'),
		['PEAP' => 'PEAP', 'TLS' => 'TLS', 'TTLS' => 'TTLS']
	));

	$section->addInput(new Form_Select(
		'wpa_eap_ca',
		'Certificate Authority',
		array_get_path($pconfig, 'wpa_eap_ca'),
		cert_build_list('ca', 'HTTPS')
	));

	$section->addInput(new Form_Select(
		'wpa_eap_inner_auth',
		'Inner Authentication Method',
		array_get_path($pconfig, 'wpa_eap_inner_auth'),
		['MSCHAPV2' => gettext('MSCHAPv2'), 'MD5' => gettext('MD5'), 'PAP' => gettext('PAP')]
	));

	$section->addInput(new Form_Input(
		'wpa_eap_inner_id',
		'*Inner Authentication Identity',
		'text',
		array_get_path($pconfig, 'wpa_eap_inner_id'),
	));

	$section->addInput(new Form_Input(
		'wpa_eap_inner_password',
		'*Inner Authentication Passphrase',
		'text',
		array_get_path($pconfig, 'wpa_eap_inner_password'),
	));

	$section->addInput(new Form_Select(
		'wpa_eap_cert',
		'TLS/TTLS Client Certificate',
		array_get_path($pconfig, 'wpa_eap_cert'),
		cert_build_list('cert', 'HTTPS')
	));

	$section->addInput(new Form_Input(
		'wpa_group_rekey',
		'Group Key Rotation',
		'number',
		array_get_path($pconfig, 'wpa_group_rekey', 60),
		['min' => '1', 'max' => 9999]
	))->setHelp('Time between group rekey events, specified in seconds. Allowed values are 1-9999. Must be shorter than Master Key Regeneration time');

	$section->addInput(new Form_Input(
		'wpa_gmk_rekey',
		'Group Master Key Regeneration',
		'number',
		array_get_path($pconfig, 'wpa_gmk_rekey', 3600),
		['min' => '1', 'max' => 9999]
	))->setHelp('Time between GMK rekey events, specified in seconds. Allowed values are 1-9999. Must be longer than Group Key Rotation time');

	$section->addInput(new Form_Checkbox(
		'wpa_strict_rekey',
		'Strict Key Regeneration',
		'Force the AP to rekey whenever a client disassociates',
		array_get_path($pconfig, 'wpa_strict_rekey'),
		'yes'
	));

	$form->add($section);

	$section = new Form_Section('802.1x RADIUS Options');
	$section->addClass('ieee8021x_group');

	$section->addInput(new Form_Checkbox(
		'ieee8021x',
		'IEEE802.1X',
		'Enable 802.1X authentication',
		array_get_path($pconfig, 'ieee8021x'),
		'yes'
	));

	$group = new Form_Group('Primary 802.1X server');

	$group->add(new Form_IpAddress(
		'auth_server_addr',
		'IP Address',
		array_get_path($pconfig, 'auth_server_addr'),
	))->setHelp('IP address of the RADIUS server');

	$group->add(new Form_Input(
		'auth_server_port',
		'Port',
		'number',
		array_get_path($pconfig, 'auth_server_port'),
	))->setHelp('Server auth port. Default is 1812');

	$group->add(new Form_Input(
		'auth_server_shared_secret',
		'Shared Secret',
		'text',
		array_get_path($pconfig, 'auth_server_shared_secret'),
	))->setHelp('RADIUS Shared secret for this firewall');

	$section->add($group);

	$group = new Form_Group('Secondary 802.1X server');

	$group->add(new Form_IpAddress(
		'auth_server_addr2',
		'IP Address',
		array_get_path($pconfig, 'auth_server_addr2'),
	))->setHelp('IP address of the RADIUS server');

	$group->add(new Form_Input(
		'auth_server_port2',
		'Port',
		'number',
		array_get_path($pconfig, 'auth_server_port2'),
	))->setHelp('Server auth port. Default is 1812');

	$group->add(new Form_Input(
		'auth_server_shared_secret2',
		'Shared Secret',
		'text',
		array_get_path($pconfig, 'auth_server_shared_secret2'),
	))->setHelp('RADIUS Shared secret for this firewall');

	$section->add($group);

	$section->addInput(new Form_Checkbox(
		'rsn_preauth',
		'Authentication Roaming Preauth',
		null,
		array_get_path($pconfig, 'rsn_preauth'),
		'yes'
	))->setHelp('Pre-authentication to speed up roaming between access points.');

	$form->add($section);
}

$section = new Form_Section('Reserved Networks');

$section->addInput(new Form_Checkbox(
	'blockpriv',
	'Block private networks and loopback addresses',
	'',
	array_get_path($pconfig, 'blockpriv'),
	'yes'
))->setHelp('Blocks traffic from IP addresses that are reserved for private networks per RFC 1918 (10/8, 172.16/12, 192.168/16) ' .
			'and unique local addresses per RFC 4193 (fc00::/7) as well as loopback addresses (127/8). This option should ' .
			'generally be turned on, unless this network interface resides in such a private address space, too.');

$section->addInput(new Form_Checkbox(
	'blockbogons',
	'Block bogon networks',
	'',
	array_get_path($pconfig, 'blockbogons'),
	'yes'
))->setHelp('Blocks traffic from reserved IP addresses (but not RFC 1918) or not yet assigned by IANA. Bogons are prefixes that should ' .
			'never appear in the Internet routing table, and so should not appear as the source address in any packets received.%1$s' .
			'This option should only be used on external interfaces (WANs), it is not necessary on local interfaces and it can potentially block required local traffic.%1$s' .
			'Note: The update frequency can be changed under System > Advanced, Firewall & NAT settings.', '<br />');

$form->add($section);

$form->addGlobal(new Form_Input(
	'if',
	null,
	'hidden',
	$if
));

if (isset($pppid) && array_get_path($wancfg, 'if') == array_get_path($a_ppps, "{$pppid}/if")) {
	$form->addGlobal(new Form_Input(
		'ppp_port',
		null,
		'hidden',
		array_get_path($pconfig, 'port'),
	));
}

$form->addGlobal(new Form_Input(
	'ptpid',
	null,
	'hidden',
	array_get_path($pconfig, 'ptpid'),
));


// Add new gateway modal pop-up
$modal = new Modal('New IPv4 Gateway', 'newgateway4', 'large');

$modal->addInput(new Form_Checkbox(
	'defaultgw4',
	'Default',
	'Default gateway',
	array_get_path($gateway_settings4, 'defaultgw', (strtolower($if) == "wan")),
));

$modal->addInput(new Form_Input(
	'gatewayname4',
	'Gateway name',
	'text',
	array_get_path($gateway_settings4, 'name', $defgatewayname4),
));

$modal->addInput(new Form_IpAddress(
	'gatewayip4',
	'Gateway IPv4',
	array_get_path($gateway_settings4, 'gateway'),
	'V4'
));

$modal->addInput(new Form_Input(
	'gatewaydescr4',
	'Description',
	'text',
	array_get_path($gateway_settings4, 'descr'),
));

$btnaddgw4 = new Form_Button(
	'add4',
	'Add',
	null,
	'fa-solid fa-plus'
);

$btnaddgw4->setAttribute('type','button')->addClass('btn-primary');

$btncnxgw4 = new Form_Button(
	'cnx4',
	'Cancel',
	null,
	'fa-solid fa-arrow-rotate-left'
);

$btncnxgw4->setAttribute('type','button')->addClass('btn-outline-secondary');

$modal->addInput(new Form_StaticText(
	null,
	$btnaddgw4 . $btncnxgw4
));

$form->add($modal);

print($form);
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// if_pppoe options.
	var if_pppoetype = <?php if (use_if_pppoe()) { echo 'true'; } else { echo 'false'; } ?>;

	function updateType(t) {

		switch (t) {
			case "none": {
				$('.dhcpadvanced, .staticv4, .dhcp, .pppoe, .pptp, .ppp').hide();
				break;
			}
			case "staticv4": {
				$('.dhcpadvanced, .none, .dhcp').hide();
				$('.pppoe, .pptp, .ppp').hide();
				break;
			}
			case "dhcp": {
				$('.dhcpadvanced, .none').hide();
				$('.staticv4').hide();	// MYSTERY: This line makes the page very slow to load, but why? There is nothing special
										//			about the staticv4 class
				$('.pppoe, .pptp, .ppp').hide();
				break;
			}
			case "ppp": {
				$('.dhcpadvanced, .none, .staticv4, .dhcp, .pptp, .pppoe').hide();
				country_list();
				break;
			}
			case "pppoe": {
				$('.dhcpadvanced, .none, .staticv4, .dhcp, .pptp, .ppp').hide();
				if (if_pppoetype) {
					hideInput('hostuniq', true);
					hideCheckbox('pppoe_dialondemand', true);
					setRequired('pppoe_idletimeout', false);
					hideSelect('pppoe_idletimeout', true);
					show_reset_settings();
					hideSelect('pppoe-reset-type', true);
				} else {
					show_reset_settings($('#pppoe-reset-type').val());
					setPPPoEDialOnDemandItems();
				}
				break;
			}
			case "l2tp": {
				$('.dhcpadvanced, .none, .staticv4, .dhcp, .pppoe, .ppp').hide();
				$('.pptp, .l2tp_secret').show();
				break;
			}
			case "pptp": {
				$('.dhcpadvanced, .none, .staticv4, .dhcp, .pppoe, .ppp, .l2tp_secret').hide();
				$('.pptp').show();
				break;
			}
		}

		if ((t != "l2tp") && (t != "pptp")) {
			$('.'+t).show();
		}
	}

	function updateTypeSix(t) {
		if (!isNaN(t[0])) {
			t = '_' + t;
		}

		switch (t) {
			case "none": {
				$('.dhcp6advanced, .staticv6, .dhcp6, ._6rd, ._6to4, .track6, .slaac').hide();
				break;
			}
			case "staticv6": {
				$('.dhcp6advanced, .none, .dhcp6, ._6rd, ._6to4, .track6, .slaac').hide();
				break;
			}
			case "slaac": {
				$('.dhcp6advanced, .none, .staticv6, ._6rd, ._6to4, .track6, .dhcp6').hide();
				break;
			}
			case "dhcp6": {
				$('.dhcp6advanced, .none, .staticv6, ._6rd, ._6to4, .track6, .slaac').hide();
				break;
			}
			case "_6rd": {
				$('.dhcp6advanced, .none, .dhcp6, .staticv6, ._6to4, .track6, .slaac').hide();
				break;
			}
			case "_6to4": {
				$('.dhcp6advanced, .none, .dhcp6, .staticv6, ._6rd, .track6, .slaac').hide();
				break;
			}
			case "track6": {
				$('.dhcp6advanced, .none, .dhcp6, .staticv6, ._6rd, ._6to4, .slaac').hide();
				update_track6_prefix();
				break;
			}
		}

		if ((t != "l2tp") && (t != "pptp")) {
			$('.'+t).show();
		}
	}

	function show_reset_settings(reset_type) {
		if (reset_type == 'preset') {
			$('.pppoepreset').show();
			$('.pppoecustom').hide();
		} else if (reset_type == 'custom') {
			$('.pppoecustom').show();
			$('.pppoepreset').hide();
		} else {
			$('.pppoecustom').hide();
			$('.pppoepreset').hide();
		}
	}

	function update_track6_prefix() {
		var iface = $("#track6-interface").val();
		if (iface == null) {
			return;
		}

		var track6_prefix_ids = $('#ipv6-num-prefix-ids-' + iface).val();
		if (track6_prefix_ids == null) {
			return;
		}

		track6_prefix_ids = parseInt(track6_prefix_ids).toString(16);
		$('#track6-prefix-id-range').html(track6_prefix_ids);
	}

	function addOption_v4() {
		var gwtext_v4 = escape($("#gatewayname4").val()) + " - " + $("#gatewayip4").val();
		addSelectboxOption($('#gateway'), gwtext_v4, $("#gatewayname4").val());
	}

	function addOption_v6() {
		var gwtext_v6 = escape($("#gatewayname6").val()) + " - " + $("#gatewayip6").val();
		addSelectboxOption($('#gatewayv6'), gwtext_v6, $("#gatewayname6").val());
	}

	function addSelectboxOption(selectbox, text, value) {
		var optn = document.createElement("OPTION");
		optn.text = text;
		optn.value = value;
		selectbox.append(optn);
		selectbox.prop('selectedIndex', selectbox.children().length - 1);
	}

	function country_list() {
		$('#country').children().remove();
		$('#provider_list').children().remove();
		$('#providerplan').children().remove();
		$.ajax({
			type: 'post',
			url: 'getserviceproviders.php',
			data: { get_country_list: true },
			success: function(response) {

				var responseTextArr = response.split("\n");
				responseTextArr.sort();

				responseTextArr.forEach( function(value) {
					country = value.split(":");
					$('#country').append($('<option>', {
						value: country[1],
						text : country[0]
					}));
				});
			}
		});
	}

	function providers_list() {
		$('#provider_list').children().remove();
		$('#providerplan').children().remove();
		$.ajax("getserviceproviders.php",{
			type: 'post',
			data: {country : $('#country').val()},
			success: function(response) {
				var responseTextArr = response.split("\n");
				responseTextArr.sort();
				responseTextArr.forEach( function(value) {
					$('#provider_list').append($('<option>', {
							value: value,
							text : value
					}));
				});
			}
		});
	}

	function providerplan_list() {
		$('#providerplan').children().remove();
		$.ajax("getserviceproviders.php",{
			type: 'post',
			data: {country : $('#country').val(), provider : $('#provider_list').val()},
			success: function(response) {
				var responseTextArr = response.split("\n");
				responseTextArr.sort();

				$('#providerplan').append($('<option>', {
					value: '',
					text : ''
				}));

				responseTextArr.forEach( function(value) {
					if (value != "") {
						providerplan = value.split(":");

						$('#providerplan').append($('<option>', {
							value: providerplan[1],
							text : providerplan[0] + " - " + providerplan[1]
						}));
					}
				});
			}
		});
	}

	function prefill_provider() {
		$.ajax("getserviceproviders.php",{
			type: 'POST',
			data: {country : $('#country').val(), provider : $('#provider_list').val(), plan : $('#providerplan').val()},
			success: function(data, textStatus, response) {
				var xmldoc = response.responseXML;
				var provider = xmldoc.getElementsByTagName('connection')[0];
				$('#ppp_username').val('');
				$('#ppp_password').val('');
				$('#ppp_password_confirm').val('');
				if (provider.getElementsByTagName('apn')[0].firstChild.data == "CDMA") {
					$('#phone').val('#777');
					$('#apn').val('');
				} else {
					$('#phone').val('*99#');
					$('#apn').val(provider.getElementsByTagName('apn')[0].firstChild.data);
				}
				ppp_username = provider.getElementsByTagName('username')[0].firstChild.data;
				ppp_password = provider.getElementsByTagName('password')[0].firstChild.data;
				$('#ppp_username').val(ppp_username);
				$('#ppp_password').val(ppp_password);
				$('#ppp_password_confirm').val(ppp_password);
			}
		});
	}

	function show_dhcp6adv() {
		var ovr = $('#adv_dhcp6_config_file_override').prop('checked');
		var adv = $('#adv_dhcp6_config_advanced').prop('checked');

		hideCheckbox('dhcp6usev4iface', ovr);
		hideCheckbox('dhcp6prefixonly', ovr);
		hideInput('dhcp6-ia-pd-len', ovr);
		hideCheckbox('dhcp6-ia-pd-send-hint', ovr);
		hideInput('adv_dhcp6_config_file_override_path', !ovr);

		hideClass('dhcp6advanced', !adv || ovr);
	}

	function setDHCPoptions() {
		var adv = $('#adv_dhcp_config_advanced').prop('checked');
		var ovr = $('#adv_dhcp_config_file_override').prop('checked');

		if (ovr) {
			hideInput('dhcphostname', true);
			hideIpAddress('alias-address', true);
			hideInput('dhcprejectfrom', true);
			hideInput('adv_dhcp_config_file_override_path', false);
			hideClass('dhcpadvanced', true);
		} else {
			hideInput('dhcphostname', false);
			hideIpAddress('alias-address', false);
			hideInput('dhcprejectfrom', false);
			hideInput('adv_dhcp_config_file_override_path', true);
			hideClass('dhcpadvanced', !adv);
		}
	}

	// DHCP preset actions
	// Set presets from value of radio buttons
	function setPresets(val) {
		// timeout, retry, select-timeout, reboot, backoff-cutoff, initial-interval
		if (val == "DHCP")		setPresetsnow("60", "300", "0", "10", "120", "10");
		if (val == "FreeSense")	setPresetsnow("60", "15", "0", "", "", "1");
		if (val == "SavedCfg")	setPresetsnow("<?=htmlspecialchars(array_get_path($pconfig, 'adv_dhcp_pt_timeout'));?>", "<?=htmlspecialchars(array_get_path($pconfig, 'adv_dhcp_pt_retry'));?>", "<?=htmlspecialchars(array_get_path($pconfig, 'adv_dhcp_pt_select_timeout'));?>", "<?=htmlspecialchars(array_get_path($pconfig, 'adv_dhcp_pt_reboot'));?>", "<?=htmlspecialchars(array_get_path($pconfig, 'adv_dhcp_pt_backoff_cutoff'));?>", "<?=htmlspecialchars(array_get_path($pconfig, 'adv_dhcp_pt_initial_interval'));?>");
		if (val == "Clear")		setPresetsnow("", "", "", "", "", "");
	}

	function setPresetsnow(timeout, retry, selecttimeout, reboot, backoffcutoff, initialinterval) {
		$('#adv_dhcp_pt_timeout').val(timeout);
		$('#adv_dhcp_pt_retry').val(retry);
		$('#adv_dhcp_pt_select_timeout').val(selecttimeout);
		$('#adv_dhcp_pt_reboot').val(reboot);
		$('#adv_dhcp_pt_backoff_cutoff').val(backoffcutoff);
		$('#adv_dhcp_pt_initial_interval').val(initialinterval);
	}

	function setPPPoEDialOnDemandItems() {
		setRequired('pppoe_idletimeout', $('#pppoe_dialondemand').prop('checked'));
	}

	function setPPTPDialOnDemandItems() {
		setRequired('pptp_idletimeout', $('#pptp_dialondemand').prop('checked'));
	}

	function show_wpaoptions() {
		var wpa = !($('#wpa_enable').prop('checked'));

		hideInput('passphrase', wpa);
		hideInput('wpa_mode', wpa);
		hideInput('wpa_key_mgmt', wpa);
		hideInput('wpa_pairwise', wpa);
		hideCheckbox('wpa_strict_rekey', wpa);
		hideClass('ieee8021x_group', true);
		if ($('#mode').val() == 'hostap') {
			hideInput('wpa_group_rekey', wpa);
			hideInput('wpa_gmk_rekey', wpa);
			hideCheckbox('wpa_strict_rekey', wpa);
		} else {
			hideInput('wpa_group_rekey', true);
			hideInput('wpa_gmk_rekey', true);
			hideCheckbox('wpa_strict_rekey', true);
		}
		updatewpakeymgmt($('#wpa_key_mgmt').val());
	}

	function updatewifistandard(s) {
		switch (s) {
			case "auto": {
				hideInput('protmode', false);
				hideInput('channel_width', false);
				break;
			}
			case "11b": {
				hideInput('protmode', true);
				hideInput('channel_width', true);
				break;
			}
			case "11g": {
				hideInput('protmode', false);
				hideInput('channel_width', true);
				break;
			}
			case "11ng": {
				hideInput('protmode', false);
				hideInput('channel_width', false);
				break;
			}
			case "11a": {
				hideInput('protmode', true);
				hideInput('channel_width', true);
				break;
			}
			case "11na": {
				hideInput('protmode', true);
				hideInput('channel_width', false);
				break;
			}
			default: {
				break;
			}
		}
	}

	function updatewifimode(m) {
		switch (m) {
			case "adhoc": {
				hideInput('puremode', true);
				hideCheckbox('apbridge_enable', true);
				hideCheckbox('hidessid_enable', false);
				break;
			}
			case "hostap": {
				hideInput('puremode', false);
				hideCheckbox('apbridge_enable', false);
				hideCheckbox('hidessid_enable', false);
				break;
			}
			default: {
				hideInput('puremode', true);
				hideCheckbox('apbridge_enable', true);
				hideCheckbox('hidessid_enable', true);
				break;
			}
		}
		show_wpaoptions();
		updateeapclientmode($('#wpa_eap_client_mode').val());
		updatewpakeymgmt($('#wpa_key_mgmt').val());
	}

	function updateeapclientmode(m) {
		if ($('#mode').val() == 'bss') {
			var wpa = !($('#wpa_enable').prop('checked'));
		} else {
			var wpa = true;
		}
		switch (m) {
			case "PEAP": {
				hideInput('wpa_eap_cert', true);
				hideInput('wpa_eap_inner_auth', wpa);
				hideInput('wpa_eap_inner_id', wpa);
				hideInput('wpa_eap_inner_password', wpa);
				break;
			}
			case "TLS": {
				hideInput('wpa_eap_cert', wpa);
				hideInput('wpa_eap_inner_auth', true);
				hideInput('wpa_eap_inner_id', true);
				hideInput('wpa_eap_inner_password', true);
				break;
			}
			case "TTLS": {
				hideInput('wpa_eap_cert', wpa);
				hideInput('wpa_eap_inner_auth', wpa);
				hideInput('wpa_eap_inner_id', wpa);
				hideInput('wpa_eap_inner_password', wpa);
				break;
			}
			default: {
				break;
			}
		}
	}

	function updatewpakeymgmt(m) {
		hideInput('passphrase', false);
		hideInput('wpa_eap_client_mode', true);
		hideInput('wpa_eap_ca', true);
		hideInput('wpa_eap_cert', true);
		hideInput('wpa_eap_inner_auth', true);
		hideInput('wpa_eap_inner_id', true);
		hideInput('wpa_eap_inner_password', true);
		hideClass('ieee8021x_group', true);
		if (m == "WPA-EAP") {
			hideInput('passphrase', true);
			if ($('#mode').val() == 'bss') {
				hideInput('wpa_eap_client_mode', false);
				hideInput('wpa_eap_ca', false);
				updateeapclientmode($('#wpa_eap_client_mode').val());
			} else if ($('#mode').val() == 'hostap') {
				hideClass('ieee8021x_group', false);
			}
		} else if (m != "WPA-PSK") {
			hideInput('passphrase', false);
			if ($('#mode').val() == 'bss') {
				hideInput('wpa_eap_client_mode', false);
				hideInput('wpa_eap_ca', false);
				hideInput('wpa_eap_cert', false);
				hideInput('wpa_eap_inner_auth', false);
				hideInput('wpa_eap_inner_id', false);
				hideInput('wpa_eap_inner_password', false);
			} else if ($('#mode').val() == 'hostap') {
				hideClass('ieee8021x_group', false);
			}
		}
	}

	// ---------- On initial page load ------------------------------------------------------------

	updateType($('#type').val());
	updateTypeSix($('#type6').val());
	hideClass('dhcp6advanced', true);
	hideClass('dhcpadvanced', true);
	show_dhcp6adv();
	setDHCPoptions();
	setPPTPDialOnDemandItems();
	show_wpaoptions();
	updatewifistandard($('#standard').val());
	updatewifimode($('#mode').val());

	// Set preset buttons on page load
	var sv = "<?=htmlspecialchars(array_get_path($pconfig, 'adv_dhcp_pt_values'));?>";
	if (sv == "") {
		$("input[name=adv_dhcp_pt_values][value='SavedCfg']").prop('checked', true);
	} else {
		$("input[name=adv_dhcp_pt_values][value="+sv+"]").prop('checked', true);
	}

	// Set preset from value
	setPresets(sv);

	// If the user wants to add a gateway, then add that to the gateway selection
	if ($("#gatewayip4").val() != '') {
		addOption_v4();
	}
	if ($("#gatewayip6").val() != '') {
		addOption_v6();
	}

	// ---------- Click checkbox handlers ---------------------------------------------------------

	$('#type').on('change', function() {
		updateType(this.value);
	});

	$('#type6').on('change', function() {
		updateTypeSix(this.value);
	});

	$('#standard').on('change', function() {
		updatewifistandard(this.value);
	});

	$('#mode').on('change', function() {
		updatewifimode(this.value);
	});

	$('#wpa_key_mgmt').on('change', function() {
		updatewpakeymgmt(this.value);
	});

	$('#wpa_eap_client_mode').on('change', function() {
		updateeapclientmode(this.value);
	});

	$('#track6-interface').on('change', function() {
		update_track6_prefix();
	});

	$('#pppoe-reset-type').on('change', function() {
		show_reset_settings(this.value);
	});

	$("#add4").click(function() {
		addOption_v4();
		bootstrap.Modal.getOrCreateInstance(document.getElementById('newgateway4')).hide();
	});

	$("#cnx4").click(function() {
		$("#gatewayname4").val(<?=json_encode($defgatewayname4, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>);
		$("#gatewayip4").val('');
		$("#gatewaydescr4").val('');
		$("#defaultgw4").prop("checked", false);
		bootstrap.Modal.getOrCreateInstance(document.getElementById('newgateway4')).hide();
	});

	$("#add6").click(function() {
		addOption_v6();
		bootstrap.Modal.getOrCreateInstance(document.getElementById('newgateway6')).hide();
	});

	$("#cnx6").click(function() {
		$("#gatewayname6").val(<?=json_encode($defgatewayname6, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>);
		$("#gatewayip6").val('');
		$("#gatewaydescr6").val('');
		$("#defaultgw6").prop("checked", false);
		bootstrap.Modal.getOrCreateInstance(document.getElementById('newgateway6')).hide();
	});

	$('#country').on('change', function() {
		providers_list();
	});

	$('#provider_list').on('change', function() {
		providerplan_list();
	});

	$('#providerplan').on('change', function() {
		prefill_provider();
	});

	$('#adv_dhcp_config_advanced, #adv_dhcp_config_file_override').click(function () {
		setDHCPoptions();
	});

	$('#adv_dhcp6_config_advanced').click(function () {
		show_dhcp6adv();
	});

	$('#adv_dhcp6_config_file_override').click(function () {
		show_dhcp6adv();
	});

	// On click . .
	$('#pppoe_dialondemand').click(function () {
		setPPPoEDialOnDemandItems();
	});

	$('#pptp_dialondemand').click(function () {
		setPPTPDialOnDemandItems();
	});

	$('[name=adv_dhcp_pt_values]').click(function () {
	   setPresets($('input[name=adv_dhcp_pt_values]:checked').val());
	});

	$('#wpa_enable').click(function () {
		show_wpaoptions();
	});

	$('#pppoe_resetdate').datepicker();

});
//]]>
</script>

<?php include("foot.inc");
