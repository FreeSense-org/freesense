<?php
/*
 * vpn_openvpn_csc.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc.
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
##|*IDENT=page-openvpn-csc
##|*NAME=OpenVPN: Client Specific Override
##|*DESCR=Allow access to the 'OpenVPN: Client Specific Override' page.
##|*MATCH=vpn_openvpn_csc.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("openvpn.inc");
require_once("freesense-utils.inc");
require_once("pkg-utils.inc");
require_once("vpn_openvpn.inc");

global $openvpn_tls_server_modes, $openvpn_ping_action;

$serveroptionlist = openvpn_csc_server_list();

if (isset($_REQUEST['id']) && is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

if (isset($_REQUEST['act'])) {
	$act = $_REQUEST['act'];
}

$user_can_edit_advanced = openvpn_user_can_edit_advanced("page-openvpn-csc-advanced");

$this_csc_config = isset($id) ? config_get_path("openvpn/openvpn-csc/{$id}") : null;

if ($_POST['act'] == "del") {
	$rv = openvpn_csc_delete($id ?? null, $user_can_edit_advanced);
	if ($rv === null) {
		FreeSenseHeader("vpn_openvpn_csc.php");
		exit;
	}
	if (!empty($rv['input_errors'])) {
		$input_errors = $rv['input_errors'];
	}
	if ($rv['deleted']) {
		$savemsg = gettext("Client specific override successfully deleted.");
	}
}

$pconfig = openvpn_csc_form($act, $this_csc_config);

if ($act == "dup") {
	$act = "new";
	unset($id);
}

if ($_POST['save']) {

	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = openvpn_csc_save($pconfig, $id ?? null, $act, $user_can_edit_advanced);
	if (!$input_errors) {
		header("Location: vpn_openvpn_csc.php");
		exit;
	}
}

$is_editor = ($act == "new" || $act == "edit");

/* the servers by vpnid, for the list and the summary */
$server_names = array();
foreach (config_get_path('openvpn/openvpn-server', []) as $srv) {
	$server_names[$srv['vpnid']] = $srv['description'] ?: sprintf(gettext('Server %s'), $srv['vpnid']);
}
$override_labels = array(
	'default' => gettext('Keeps server options'),
	'push_reset' => gettext('Resets all server options'),
	'remove_specified' => gettext('Removes some server options'),
);

$pgtitle = array(gettext("VPN"), gettext("OpenVPN"), gettext("Client Specific Overrides"));
$pglinks = array("", "vpn_openvpn_server.php", "vpn_openvpn_csc.php");

if ($is_editor) {
	if ($act == "edit" && $this_csc_config) {
		$pgtitle[] = htmlspecialchars($this_csc_config['common_name']);
		$pglinks[] = "";
		$pgtitle[] = gettext('Edit override');
	} else {
		$pgtitle[] = gettext('Add override');
	}
	$pglinks[] = "@self";
}
$shortcut_section = "openvpn";

if (!$is_editor) {
	fs_page_action(gettext('Add override'), 'vpn_openvpn_csc.php?act=new', 'fa-plus');
}
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

fs_tabs('vpn-openvpn', 'vpn_openvpn_csc.php');
?>

<style>
.fs-ovpn-sub { margin-top: .15rem; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
</style>

<?php
if ($is_editor):
	/* header summary: the stored override when editing, otherwise the form's starting values */
	$sum = ($act == "edit" && $this_csc_config) ? openvpn_csc_form('edit', $this_csc_config) : (array)$pconfig;
	$sum_servers = array();
	foreach ((array)($sum['server_list'] ?? array()) as $vid) {
		$sum_servers[] = $server_names[$vid] ?? sprintf(gettext('Server %s'), $vid);
	}
	$sum_override = ($sum['override_options'] ?? '') ?: 'default';
	$sum_networks = array_filter(array($sum['tunnel_network'] ?? '', $sum['tunnel_networkv6'] ?? ''));
	if ($act == "edit") {
		$sum_name = $sum['common_name'];
	} elseif ((($_REQUEST['act'] ?? '') == 'dup') && !empty($sum['common_name'])) {
		$sum_name = sprintf(gettext('Copy of %s'), $sum['common_name']);
	} else {
		$sum_name = gettext('New override');
	}

	$sum_badges = [fs_badge('info', gettext('Not saved yet'))];
	if ($act == "edit") {
		$sum_badges = [!empty($sum['disable']) ? fs_badge('disabled') : fs_badge('enabled')];
		if (!empty($sum['block'])) {
			$sum_badges[] = fs_badge('block', gettext('Blocked'));
		}
	}
	fs_summary_card([
		'icon' => 'fa-user-gear',
		'title' => $sum_name,
		'subtitle' => ($act == "edit") ? (string)($sum['description'] ?? '') : '',
		'badges' => $sum_badges,
		'facts' => [
			[gettext('Applies to'), '', 'chips' => $sum_servers, 'empty' => gettext('All servers')],
			[gettext('Tunnel address'), implode(', ', $sum_networks), 'mono' => true, 'empty' => gettext('From server')],
			[gettext('Server options'), $override_labels[$sum_override] ?? $sum_override],
		],
		'label' => gettext('Override summary'),
	]);
	$form = new Form();
	$closed = !empty($input_errors) ? SEC_OPEN : SEC_CLOSED;

	/* ---------------------------------------------------------------- General */
	$section = new Form_Section('General');

	$section->addInput(new Form_Input(
		'description',
		'Description',
		'text',
		$pconfig['description']
	))->setHelp('A description of this override for administrative reference.');

	$section->addInput(new Form_Checkbox(
		'disable',
		'Disable',
		'Disable this override',
		$pconfig['disable']
	))->setHelp('Keeps the override in the list without applying it.');

	$form->add($section);

	/* ----------------------------------------------------------------- Client */
	$section = new Form_Section('Client');

	$section->addInput(new Form_Input(
		'common_name',
		'*Common Name',
		'text',
		$pconfig['common_name']
	))->setHelp('The X.509 common name of the client certificate, or the username for password authentication (case sensitive). ' .
		'Enter "DEFAULT" to override the default client behavior.');

	$section->addInput(new Form_Checkbox(
		'block',
		'Connection blocking',
		'Block this client connection based on its common name.',
		$pconfig['block']
	))->setHelp('Prevents the client from connecting. To lock out a compromised key or password permanently, use a certificate revocation list instead.');

	$section->addInput(new Form_Select(
		'server_list',
		'Server List',
		$pconfig['server_list'],
		$serveroptionlist,
		true
		))->setHelp('The servers that use this override. When none are selected, it applies to all servers.');

	$form->add($section);

	/* --------------------------------------------------------- Server options */
	$section = new Form_Section('Server options');

	$section->addInput(new Form_Select(
		'override_options',
		'Reset Server Options',
		($pconfig['override_options'] ?? 'default'),
		openvpn_csc_override_options()
	))->setHelp('Stop this client from receiving server-defined client settings. The options on this page still apply.');

	$section->addInput(new Form_Select(
		'remove_options',
		'Remove Options',
		$pconfig['remove_options'],
		openvpn_csc_remove_options(),
		true
	))->addClass('remove_options')->setHelp('A "push-remove" is sent to the client for each selected option.');

	$section->addInput(new Form_Checkbox(
		'keep_minimal',
		'Keep minimal options',
		'Automatically determine the client topology and gateway',
		$pconfig['keep_minimal']
	))->setHelp('Generates the required client configuration when server options are reset or removed.');

	$form->add($section);

	/* ------------------------------------------------------- Tunnel addresses */
	$section = new Form_Section('Tunnel addresses');

	$section->addInput(new Form_Input(
		'tunnel_network',
		'IPv4 Tunnel Network',
		'text',
		$pconfig['tunnel_network']
	))->setHelp('The client\'s tunnel address in CIDR notation (e.g. 10.0.8.5/24), or a network alias with one entry. ' .
		'With subnet topology the mask must match the server\'s IPv4 Tunnel Network; with net30 the client gets the second address of the /30.');

	$section->addInput(new Form_Input(
		'tunnel_networkv6',
		'IPv6 Tunnel Network',
		'text',
		$pconfig['tunnel_networkv6']
	))->setHelp('The client\'s IPv6 address and prefix (e.g. 2001:db9:1:1::100/64). The prefix must match the server\'s IPv6 Tunnel Network.');

	$section->addInput(new Form_Input(
		'gateway',
		'IPv4 Gateway',
		'text',
		$pconfig['gateway']
	))->setHelp('The IPv4 gateway pushed to the client. Usually left empty (automatic).');

	$section->addInput(new Form_Input(
		'gateway6',
		'IPv6 Gateway',
		'text',
		$pconfig['gateway6']
	))->setHelp('The IPv6 gateway pushed to the client. Usually left empty (automatic).');

	$form->add($section);

	/* ---------------------------------------------------------------- Routing */
	$section = new Form_Section('Routing');

	$section->addInput(new Form_Checkbox(
		'gwredir',
		'Redirect IPv4 Gateway',
		'Force all client generated IPv4 traffic through the tunnel.',
		$pconfig['gwredir']
	));

	$section->addInput(new Form_Checkbox(
		'gwredir6',
		'Redirect IPv6 Gateway',
		'Force all client-generated IPv6 traffic through the tunnel.',
		$pconfig['gwredir6']
	));

	$section->addInput(new Form_Input(
		'local_network',
		'IPv4 Local Network/s',
		'text',
		$pconfig['local_network']
	))->setHelp('Server-side IPv4 networks this client can reach: a comma-separated list of CIDR ranges or host/network aliases. ' .
		'Not needed for networks already set on the server.');

	$section->addInput(new Form_Input(
		'local_networkv6',
		'IPv6 Local Network/s',
		'text',
		$pconfig['local_networkv6']
	))->setHelp('Server-side IPv6 networks this client can reach: a comma-separated list of IP/PREFIX networks. ' .
		'Not needed for networks already set on the server.');

	$section->addInput(new Form_Input(
		'remote_network',
		'IPv4 Remote Network/s',
		'text',
		$pconfig['remote_network']
	))->setHelp('Client-side IPv4 networks routed to this client (iroute) for a site-to-site VPN: a comma-separated list of CIDR ranges. ' .
		'Also add them to the server\'s IPv4 Remote Networks.');

	$section->addInput(new Form_Input(
		'remote_networkv6',
		'IPv6 Remote Network/s',
		'text',
		$pconfig['remote_networkv6']
	))->setHelp('Client-side IPv6 networks routed to this client (iroute): a comma-separated list of IP/PREFIX networks. ' .
		'Also add them to the server\'s IPv6 Remote Networks.');

	$form->add($section);

	/* ------------------------------------------------------ Timeouts and ping */
	$has_timers = !empty($pconfig['inactive_seconds']) || !empty($pconfig['ping_seconds']) ||
	    (($pconfig['ping_action'] ?? 'default') != 'default');
	$section = new Form_Section('Timeouts and ping', 'csc-timers', COLLAPSIBLE | ($has_timers ? SEC_OPEN : $closed));

	$section->addInput(new Form_Input(
		'inactive_seconds',
		'Inactivity Timeout',
		'number',
		$pconfig['inactive_seconds'],
		['min' => '0']
	))->setHelp('Set connection inactivity timeout')->setWidth(3);

	$section->addInput(new Form_Input(
		'ping_seconds',
		'Ping Interval',
		'number',
		$pconfig['ping_seconds'],
		['min' => '0']
	))->setHelp('Set peer ping interval')->setWidth(3);

	$group = new Form_Group('Ping Action');
	$group->add(new Form_Select(
		'ping_action',
		null,
		$pconfig['ping_action'] ?? 'default',
		array_merge([
			'default' => 'Don\'t override option (default)'
		], $openvpn_ping_action)
	))->setHelp('Exit or restart OpenVPN client after server timeout')->setWidth(4);
	$group->add(new Form_Input(
		'ping_action_seconds',
		'timeout seconds',
		'number',
		$pconfig['ping_action_seconds'],
		['min' => '0']
	))->setWidth(2)->addClass('ping_action_seconds');
	$section->add($group);

	$form->add($section);

	/* ------------------------------------------------------------ DNS and NTP */
	$has_dns = !empty($pconfig['dns_domain_enable']) || !empty($pconfig['dns_server_enable']) || !empty($pconfig['ntp_server_enable']) ||
	    !empty($pconfig['push_blockoutsidedns']) || !empty($pconfig['push_register_dns']);
	$section = new Form_Section('DNS and NTP', 'csc-dns', COLLAPSIBLE | ($has_dns ? SEC_OPEN : $closed));

	$section->addInput(new Form_Checkbox(
		'dns_domain_enable',
		'DNS Default Domain',
		'Provide a default domain name to clients',
		$pconfig['dns_domain_enable']
	));

	$group = new Form_Group('DNS Domain');
	$group->addClass('dnsdomain');

	$group->add(new Form_Input(
		'dns_domain',
		'DNS Domain',
		'text',
		$pconfig['dns_domain']
	));

	$section->add($group);

	// DNS servers
	$section->addInput(new Form_Checkbox(
		'dns_server_enable',
		'DNS Servers',
		'Provide a DNS server list to clients',
		$pconfig['dns_server_enable']
	));

	$group = new Form_Group(null);
	$group->addClass('dnsservers');

	$group->add(new Form_Input(
		'dns_server1',
		null,
		'text',
		$pconfig['dns_server1']
	))->setHelp('Server 1');

	$group->add(new Form_Input(
		'dns_server2',
		null,
		'text',
		$pconfig['dns_server2']
	))->setHelp('Server 2');

	$group->add(new Form_Input(
		'dns_server3',
		null,
		'text',
		$pconfig['dns_server3']
	))->setHelp('Server 3');

	$group->add(new Form_Input(
		'dns_server4',
		null,
		'text',
		$pconfig['dns_server4']
	))->setHelp('Server 4');

	$section->add($group);

	$section->addInput(new Form_Checkbox(
		'push_blockoutsidedns',
		'Block Outside DNS',
		'Make Windows 10 Clients Block access to DNS servers except across OpenVPN while connected, forcing clients to use only VPN DNS servers.',
		$pconfig['push_blockoutsidedns']
	))->setHelp('Requires Windows 10 and OpenVPN 2.3.9 or later. Other clients ignore it.');

	$section->addInput(new Form_Checkbox(
		'push_register_dns',
		'Force DNS cache update',
		'Run "net stop dnscache", "net start dnscache", "ipconfig /flushdns" and "ipconfig /registerdns" on connection initiation.',
		$pconfig['push_register_dns']
	))->setHelp('This is known to kick Windows into recognizing pushed DNS servers.');

	// NTP servers
	$section->addInput(new Form_Checkbox(
		'ntp_server_enable',
		'NTP Servers',
		'Provide an NTP server list to clients',
		$pconfig['ntp_server_enable']
	));

	$group = new Form_Group(null);
	$group->addClass('ntpservers');

	$group->add(new Form_Input(
		'ntp_server1',
		null,
		'text',
		$pconfig['ntp_server1']
	))->setHelp('Server 1');

	$group->add(new Form_Input(
		'ntp_server2',
		null,
		'text',
		$pconfig['ntp_server2']
	))->setHelp('Server 2');

	$section->add($group);

	$form->add($section);

	/* -------------------------------------------------------- NetBIOS and WINS */
	// NetBIOS - For this section we need to use JavaScript hiding since there
	// are nested toggles
	$section = new Form_Section('NetBIOS and WINS', 'csc-netbios', COLLAPSIBLE | (!empty($pconfig['netbios_enable']) ? SEC_OPEN : $closed));

	$section->addInput(new Form_Checkbox(
		'netbios_enable',
		'NetBIOS Options',
		'Enable NetBIOS over TCP/IP',
		$pconfig['netbios_enable']
	))->setHelp('When off, all NetBIOS over TCP/IP options (including WINS) are disabled.');

	$section->addInput(new Form_Select(
		'netbios_ntype',
		'Node Type',
		$pconfig['netbios_ntype'],
		$netbios_nodetypes
	))->setHelp('b-node: broadcasts; p-node: point-to-point queries to a WINS server; m-node: broadcast, then query; h-node: query, then broadcast.');

	$section->addInput(new Form_Input(
		'netbios_scope',
		null,
		'text',
		$pconfig['netbios_scope']
	))->setHelp('NetBIOS Scope ID: limits NetBIOS traffic to nodes with the same scope ID.');

	$section->addInput(new Form_Checkbox(
		'wins_server_enable',
		'WINS servers',
		'Provide a WINS server list to clients',
		$pconfig['wins_server_enable']
	));

	$group = new Form_Group(null);

	$group->add(new Form_Input(
		'wins_server1',
		null,
		'text',
		$pconfig['wins_server1']
	))->setHelp('Server 1');

	$group->add(new Form_Input(
		'wins_server2',
		null,
		'text',
		$pconfig['wins_server2']
	))->setHelp('Server 2');

	$group->addClass('winsservers');

	$section->add($group);

	$section->addInput(new Form_Checkbox(
		'nbdd_server_enable',
		'NBDD servers',
		'Provide a NetBIOS over TCP/IP Datagram Distribution Servers list to clients',
		$pconfig['nbdd_server_enable']
	));

	$group = new Form_Group(null);

	$group->add(new Form_Input(
		'nbdd_server1',
		null,
		'text',
		$pconfig['nbdd_server1']
	))->setHelp('Server 1');

	$group->add(new Form_Input(
		'nbdd_server2',
		null,
		'text',
		$pconfig['nbdd_server2']
	))->setHelp('Server 2');

	$group->addClass('nbddservers');

	$section->add($group);

	$form->add($section);

	/* --------------------------------------------------------------- Advanced */
	$section = new Form_Section('Advanced', 'csc-advanced', COLLAPSIBLE | (!empty($pconfig['custom_options']) ? SEC_OPEN : $closed));

	$custops = new Form_Textarea(
		'custom_options',
		'Advanced',
		$pconfig['custom_options']
	);
	if (!$user_can_edit_advanced) {
		$custops->setDisabled();
	}
	$section->addInput($custops)->setHelp('Additional options for this override, separated by semicolons. %1$s' .
				'EXAMPLE: push "route 10.0.0.0 255.255.255.0"; ',
				'<br />');

	// The hidden fields
	$form->addGlobal(new Form_Input(
		'act',
		null,
		'hidden',
		$act
	));

	if ($this_csc_config) {
		$form->addGlobal(new Form_Input(
			'id',
			null,
			'hidden',
			$id
		));
	}

	$form->add($section);
	fs_form_cancel($form, 'vpn_openvpn_csc.php');
	print($form);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function gwredir_change() {
		hideInput('local_network', ($('#gwredir').prop('checked')));
	}

	function gwredir6_change() {
		hideInput('local_networkv6', ($('#gwredir6').prop('checked')));
	}

	function ping_action_change() {
		hideClass('ping_action_seconds', ($('#ping_action').find('option:selected').val() == 'default'));
	}

	function dnsdomain_change() {
		if ($('#dns_domain_enable').prop('checked')) {
			hideClass('dnsdomain', false);
		} else {
			hideClass('dnsdomain', true);
		}
	}

	function dnsservers_change() {
		if ($('#dns_server_enable').prop('checked')) {
			hideClass('dnsservers', false);
		} else {
			hideClass('dnsservers', true);
		}
	}

	function ntpservers_change() {
		if ($('#ntp_server_enable').prop('checked')) {
			hideClass('ntpservers', false);
		} else {
			hideClass('ntpservers', true);
		}
	}

	// Hide/show that section, but have to also respect the wins_server_enable and nbdd_server_enable checkboxes
	function setNetbios() {
		if ($('#netbios_enable').prop('checked')) {
			hideInput('netbios_ntype', false);
			hideInput('netbios_scope', false);
			hideCheckbox('wins_server_enable', false);
			setWins();
			hideCheckbox('nbdd_server_enable', false);
			setNbdds();
		} else {
			hideInput('netbios_ntype', true);
			hideInput('netbios_scope', true);
			hideCheckbox('wins_server_enable', true);
			hideClass('winsservers', true);
			hideCheckbox('nbdd_server_enable', true);
			hideClass('nbddservers', true);
		}
	}

	function setWins() {
		hideClass('winsservers', ! $('#wins_server_enable').prop('checked'));
	}

	function setNbdds() {
		hideClass('nbddservers', ! $('#nbdd_server_enable').prop('checked'));
	}

	function remove_options_change() {
		hideCheckbox('keep_minimal', ($('#override_options').find('option:selected').val() == 'default'));
		hideMultiClass('remove_options', ($('#override_options').find('option:selected').val() != 'remove_specified'));
	}

	// ---------- Click checkbox handlers ---------------------------------------------------------

	 // On clicking Gateway redirect
	$('#gwredir').click(function () {
		gwredir_change();
	});

	 // On clicking Gateway redirect IPv6
	$('#gwredir6').click(function () {
		gwredir6_change();
	});

	 // On clicking or changing Ping Action
	$('#ping_action').on('click change', function () {
		ping_action_change();
	});

	 // On clicking DNS Default Domain
	$('#dns_domain_enable').click(function () {
		dnsdomain_change();
	});

	 // On clicking DNS Servers
	$('#dns_server_enable').click(function () {
		dnsservers_change();
	});

	 // On clicking NTP Servers
	$('#ntp_server_enable').click(function () {
		ntpservers_change();
	});

	// On clicking the netbios_enable checkbox
	$('#netbios_enable').click(function () {
		setNetbios();
	});

	// On clicking the wins_server_enable checkbox
	$('#wins_server_enable').click(function () {
		setWins();
	});

	// On clicking the nbdd_server_enable checkbox
	$('#nbdd_server_enable').click(function () {
		setNbdds();
	});

	$('#override_options').on('change', function() {
		remove_options_change();
	});
	// ---------- On initial page load ------------------------------------------------------------

	remove_options_change();
	gwredir_change();
	gwredir6_change();
	ping_action_change();
	setNetbios();
	dnsdomain_change();
	dnsservers_change();
	ntpservers_change();

});
//]]>
</script>

<?php
else :  // Not an 'add' or an 'edit'. Just the table of Override CSCs
	$cscs = config_get_path('openvpn/openvpn-csc', []);
	$remove_labels = openvpn_csc_remove_options();
?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Client specific overrides'),
	'search' => gettext('Search overrides…'),
	'noun' => gettext('overrides'),
	'noun_one' => gettext('override'),
	'filters' => [
		'status' => [gettext('All states'), 'enabled' => gettext('Enabled'), 'disabled' => gettext('Disabled'), 'blocked' => gettext('Blocked')],
	],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext("Status")?></th>
					<th data-fs-search><?=gettext("Common name")?></th>
					<th data-fs-search><?=gettext("Servers")?></th>
					<th data-fs-search><?=gettext("Tunnel address")?></th>
					<th data-fs-search><?=gettext("Overrides")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
	$i = 0;
	foreach ($cscs as $csc):
		$disabled = isset($csc['disable']);
		$blocked = !empty($csc['block']);
		$name = $csc['common_name'];
		$servers = array();
		foreach (array_filter(explode(',', $csc['server_list'] ?? '')) as $vid) {
			$servers[] = $server_names[$vid] ?? sprintf(gettext('Server %s'), $vid);
		}
		$networks = array_filter(array($csc['tunnel_network'] ?? '', $csc['tunnel_networkv6'] ?? ''));
		/* what the override changes, as short chips */
		$chips = array();
		if (!empty($csc['remove_options'])) {
			$removed = array_map(function ($o) use ($remove_labels) { return $remove_labels[$o] ?? $o; }, explode(',', $csc['remove_options']));
			$chips[] = array((count($removed) == 1) ? gettext('Removes 1 option') : sprintf(gettext('Removes %d options'), count($removed)), implode(', ', $removed));
		} elseif (isset($csc['push_reset'])) {
			$chips[] = array(gettext('Resets options'), '');
		}
		if (!empty($csc['gwredir']) || !empty($csc['gwredir6'])) {
			$chips[] = array(gettext('Redirect gateway'), '');
		}
		if (!empty($csc['local_network']) || !empty($csc['local_networkv6'])) {
			$chips[] = array(gettext('Local networks'), implode(', ', array_filter(array($csc['local_network'] ?? '', $csc['local_networkv6'] ?? ''))));
		}
		if (!empty($csc['remote_network']) || !empty($csc['remote_networkv6'])) {
			$chips[] = array(gettext('Remote networks'), implode(', ', array_filter(array($csc['remote_network'] ?? '', $csc['remote_networkv6'] ?? ''))));
		}
		if (!empty($csc['dns_domain']) || !empty($csc['dns_server1']) || !empty($csc['dns_server2']) || !empty($csc['dns_server3']) || !empty($csc['dns_server4'])) {
			$chips[] = array(gettext('DNS'), '');
		}
		if (!empty($csc['custom_options'])) {
			$chips[] = array(gettext('Custom options'), '');
		}
		if ($disabled) {
			$status = 'disabled';
		} elseif ($blocked) {
			$status = 'blocked';
		} else {
			$status = 'enabled';
		}
?>
				<tr data-fs-filter-status="<?=$status?>"<?=$disabled ? ' class="fs-row-disabled"' : ''?>>
					<td>
						<?=$disabled ? fs_badge('disabled') : fs_badge('enabled')?>
						<?=$blocked ? fs_badge('block', gettext('Blocked')) : ''?>
					</td>
					<td class="fs-mono"><a href="vpn_openvpn_csc.php?act=edit&amp;id=<?=$i?>"><?=htmlspecialchars($name)?></a></td>
					<td><?=empty($servers) ? '<span class="fs-muted">' . gettext('All servers') . '</span>' : '<span class="fs-chips">' . implode('', array_map(function ($n) { return '<span class="fs-chip fs-chip--strong">' . htmlspecialchars($n) . '</span>'; }, $servers)) . '</span>'?></td>
					<td><?=(empty($networks)) ? '<span class="fs-muted">' . gettext('From server') . '</span>' : '<span class="fs-mono">' . htmlspecialchars(implode(', ', $networks)) . '</span>'?></td>
					<td>
<?php if (empty($chips)): ?>
						<span class="fs-muted">&ndash;</span>
<?php else: ?>
						<span class="fs-chips">
<?php foreach ($chips as $chip): ?>
							<span class="fs-chip fs-chip--strong"<?=($chip[1] !== '') ? ' title="' . htmlspecialchars($chip[1]) . '"' : ''?>><?=htmlspecialchars($chip[0])?></span>
<?php endforeach; ?>
						</span>
<?php endif; ?>
					</td>
					<td><?=htmlspecialchars($csc['description'] ?? '')?></td>
					<td class="fs-col-actions">
<?=fs_row_actions([
							['edit', "vpn_openvpn_csc.php?act=edit&id={$i}", $name],
							['copy', "vpn_openvpn_csc.php?act=dup&id={$i}", $name],
							['delete', "vpn_openvpn_csc.php?act=del&id={$i}", $name, ['thing' => gettext('client specific override'), 'detail' => gettext('The client gets the server settings at its next connection.')]],
						])?>
					</td>
				</tr>
<?php
	   $i++;
	endforeach;
?>
<?php if (empty($cscs)) {
	fs_empty_row(7, gettext('No client specific overrides yet.'), 'vpn_openvpn_csc.php?act=new', gettext('Add override'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php
endif;
include("foot.inc");
