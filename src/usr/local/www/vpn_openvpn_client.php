<?php
/*
 * vpn_openvpn_client.php
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
##|*IDENT=page-openvpn-client
##|*NAME=OpenVPN: Clients
##|*DESCR=Allow access to the 'OpenVPN: Clients' page.
##|*MATCH=vpn_openvpn_client.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("openvpn.inc");
require_once("freesense-utils.inc");
require_once("pkg-utils.inc");
require_once("vpn_openvpn.inc");

global $openvpn_topologies, $openvpn_tls_modes;
global $openvpn_sharedkey_warning;
global $openvpn_prots;

$openvpn_all_data_ciphers = openvpn_get_cipherlist();

$proxy_auth_types = openvpn_client_proxy_auth_types();
$certlist = openvpn_build_cert_list(true);

if (isset($_REQUEST['id']) && is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}
$this_client_config = isset($id) ? config_get_path("openvpn/openvpn-client/{$id}") : null;
$act = $_REQUEST['act'];

if ($this_client_config) {
	$vpnid = $this_client_config['vpnid'];
} else {
	$vpnid = 0;
}

$user_can_edit_advanced = openvpn_user_can_edit_advanced("page-openvpn-client-advanced");

if ($_POST['act'] == "del") {
	$rv = openvpn_instance_delete('client', $id ?? null, $user_can_edit_advanced);
	if ($rv === null) {
		FreeSenseHeader("vpn_openvpn_client.php");
		exit;
	}
	if (!empty($rv['input_errors'])) {
		$input_errors = $rv['input_errors'];
	}
	if ($rv['deleted']) {
		$savemsg = gettext("Client successfully deleted.");
	}
}

$pconfig = openvpn_client_form($act, $this_client_config);

if ($act == "dup") {
	$act = "new";
	$vpnid = 0;
	$parentid = $id;
	unset($id);
}

if ($_POST['save']) {

	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = openvpn_client_save($pconfig, $id ?? null, $act, $user_can_edit_advanced);
	if (!$input_errors) {
		header("Location: vpn_openvpn_client.php");
		exit;
	}

	if (!empty($pconfig['data_ciphers']) && is_array($pconfig['data_ciphers'])) {
		$pconfig['data_ciphers'] = implode(",", $pconfig['data_ciphers']);
	}
}

$is_editor = ($act == "new" || $act == "edit");

/* short labels for badges and the summary card */
$client_mode_short = array(
	'p2p_tls' => gettext('SSL/TLS'),
	'p2p_shared_key' => gettext('Shared key'),
);

$pgtitle = array(gettext("VPN"), gettext("OpenVPN"), gettext("Clients"));
$pglinks = array("", "vpn_openvpn_server.php", "vpn_openvpn_client.php");

if ($is_editor) {
	if ($act == "edit" && $this_client_config) {
		$pgtitle[] = htmlspecialchars($this_client_config['description'] ?: sprintf(gettext('Client %s'), $vpnid));
		$pglinks[] = "";
		$pgtitle[] = gettext('Edit client');
	} else {
		$pgtitle[] = gettext('Add client');
	}
	$pglinks[] = "@self";
}
$shortcut_section = "openvpn";

if (!$is_editor) {
	fs_page_action(gettext('Add client'), 'vpn_openvpn_client.php?act=new', 'fa-plus');
}
include("head.inc");

if (!$savemsg) {
	$savemsg = "";
}

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

fs_tabs('vpn-openvpn', 'vpn_openvpn_client.php');
?>

<style>
.fs-ovpn-sub { margin-top: .15rem; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
</style>

<?php
if ($is_editor):
	/* header summary: the stored client when editing, otherwise the form's starting values */
	$sum = ($act == "edit" && $this_client_config) ? $this_client_config : (array)$pconfig;
	$sum_mode = ($sum['mode'] ?? '') ?: array_key_first($openvpn_client_modes);
	$sum_prot = ($sum['protocol'] ?? '') ?: array_key_first($openvpn_prots);
	$sum_dev = strtoupper(($sum['dev_mode'] ?? '') ?: 'tun');
	$sum_server = empty($sum['server_addr']) ? '' : $sum['server_addr'] . ':' . ($sum['server_port'] ?? '');
	$sum_if = empty($sum['interface']) ? '' : convert_openvpn_interface_to_friendly_descr(explode('|', $sum['interface'])[0]);
	if ($act == "edit") {
		$sum_name = ($sum['description'] ?? '') ?: sprintf(gettext('Client %s'), $vpnid);
	} elseif (isset($parentid) && !empty($sum['description'])) {
		$sum_name = sprintf(gettext('Copy of %s'), $sum['description']);
	} else {
		$sum_name = gettext('New client');
	}

	fs_summary_card([
		'icon' => 'fa-plug',
		'title' => $sum_name,
		'badges' => ($act == "edit") ? [isset($sum['disable']) ? fs_badge('disabled') : fs_badge('enabled')] : [fs_badge('info', gettext('Not saved yet'))],
		'meta' => ($act == "edit") ? 'ovpnc' . $vpnid : '',
		'facts' => [
			[gettext('Mode'), sprintf(gettext('Peer to peer, %s'), $client_mode_short[$sum_mode] ?? $sum_mode)],
			[gettext('Server'), $sum_server, 'mono' => true],
			[gettext('Protocol'), '', 'chips' => [$sum_prot, $sum_dev], 'note' => $sum_if ? sprintf(gettext('via %s'), $sum_if) : ''],
			[gettext('Tunnel network'), (string)($sum['tunnel_network'] ?? ''), 'mono' => true, 'empty' => gettext('From server')],
		],
		'label' => gettext('Client summary'),
	]);
	$form = new Form();
	$adv_state = COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED);

	/* ---------------------------------------------------------------- General */
	$section = new Form_Section('General');

	$section->addInput(new Form_Input(
		'description',
		'Description',
		'text',
		$pconfig['description']
	))->setHelp('A name for this client, for administrative reference.');

	$section->addInput(new Form_Checkbox(
		'disable',
		'Disabled',
		'Disable this client',
		$pconfig['disable']
	))->setHelp('Keeps the client in the list without starting it.');

	if ($vpnid) {
		$section->addInput(new Form_StaticText(
			'Unique VPN ID',
			gettext('Client') . " {$vpnid} (ovpnc{$vpnid})"
		));
	}

	$section->addInput(new Form_Select(
		'mode',
		'*Server mode',
		$pconfig['mode'],
		$openvpn_client_modes
		));

	$group = new Form_Group('WARNING:');
	$group->add(new Form_StaticText(
		'',
		$openvpn_sharedkey_warning
	));
	$group->addClass('text-danger')->addClass('sharedkeywarning');
	$section->add($group);

	$section->addInput(new Form_Select(
		'dev_mode',
		'*Device mode',
		empty($pconfig['dev_mode']) ? 'tun':$pconfig['dev_mode'],
		$openvpn_dev_mode
		))->setHelp('"tun" carries IPv4 and IPv6 (layer 3) and is the most compatible. "tap" carries Ethernet frames (layer 2).');

	$form->add($section);

	/* ----------------------------------------------------------------- Server */
	$section = new Form_Section('Server');

	$section->addInput(new Form_Input(
		'server_addr',
		'*Server host or address',
		'text',
		$pconfig['server_addr']
	))->setHelp("The IP address or hostname of the OpenVPN server.");

	$section->addInput(new Form_Input(
		'server_port',
		'*Server port',
		'number',
		$pconfig['server_port']
	))->setHelp("The port the server accepts client connections on.");

	$section->addInput(new Form_Select(
		'protocol',
		'*Protocol',
		$pconfig['protocol'],
		$openvpn_prots
		));

	$section->addInput(new Form_Select(
		'interface',
		'*Interface',
		$pconfig['interface'],
		openvpn_build_if_list()
		))->setHelp("The interface this client connects from.");

	$section->addInput(new Form_Input(
		'local_port',
		'Local port',
		'number',
		$pconfig['local_port'],
		['min' => '0']
	))->setHelp('Bind to a specific local port. Leave empty or enter 0 for a random port.');

	$form->add($section);

	/* ------------------------------------------------------------------ Proxy */
	$section = new Form_Section('HTTP proxy', 'ovpnc-proxy', COLLAPSIBLE | ((!empty($input_errors) || !empty($pconfig['proxy_addr'])) ? SEC_OPEN : SEC_CLOSED));

	$section->addInput(new Form_Input(
		'proxy_addr',
		'Proxy host or address',
		'text',
		$pconfig['proxy_addr']
	))->setHelp('An HTTP proxy this client uses to reach the server. The client and server must use TCP.');

	$section->addInput(new Form_Input(
		'proxy_port',
		'Proxy port',
		'number',
		$pconfig['proxy_port']
	));

	$section->addInput(new Form_Select(
		'proxy_authtype',
		'Proxy Authentication',
		$pconfig['proxy_authtype'],
		$proxy_auth_types
		))->setHelp("The type of authentication used by the proxy server.");

	$section->addInput(new Form_Input(
		'proxy_user',
		'Username',
		'text',
		$pconfig['proxy_user'],
		['autocomplete' => 'new-password']
	));

	$section->addPassword(new Form_Input(
		'proxy_passwd',
		'Password',
		'password',
		$pconfig['proxy_passwd'],
	), false);

	$form->add($section);

	/* ---------------------------------------------------- Peer authentication */
	$section = new Form_Section('Certificates and keys');

	if (count(config_get_path('ca', []))) {
		$section->addInput(new Form_Select(
			'caref',
			'*Peer Certificate Authority',
			$pconfig['caref'],
			cert_build_list('ca', 'OpenVPN')
		));
	} else {
		$section->addInput(new Form_StaticText(
			'*Peer Certificate Authority',
			sprintf('No Certificate Authorities defined. One may be created here: %s', '<a href="system_camanager.php">System &gt; Cert. Manager</a>')
		));
	}

	if (count(config_get_path('crl', []))) {
		$section->addInput(new Form_Select(
			'crlref',
			'Peer Certificate Revocation list',
			$pconfig['crlref'],
			openvpn_build_crl_list()
		));
	} else {
		$section->addInput(new Form_StaticText(
			'Peer Certificate Revocation list',
			sprintf('No Certificate Revocation Lists defined. One may be created here: %s', '<a href="system_crlmanager.php">System &gt; Cert. Manager &gt; Certificate Revocation</a>')
		));
	}

	$section->addInput(new Form_Select(
		'certref',
		'Client Certificate',
		$pconfig['certref'],
		$certlist['server']
		))->setHelp('Certificates that do not work with OpenVPN (incompatible ECDSA curves, weak digests) are not listed.');

	$section->addInput(new Form_Checkbox(
		'remote_cert_tls',
		'Server Certificate Key Usage Validation',
		'Enforce key usage',
		$pconfig['remote_cert_tls']
	))->setHelp('Verify that the remote host uses a server certificate (EKU: "TLS Web Server Authentication").');

	$section->addInput(new Form_Checkbox(
		'autokey_enable',
		'Auto generate',
		'Automatically generate a shared key',
		$pconfig['autokey_enable'] && empty($pconfig['shared_key'])
	));

	$section->addInput(new Form_Textarea(
		'shared_key',
		'*Shared Key',
		$pconfig['shared_key']
	))->setHelp('Paste the shared key here');

	$section->addInput(new Form_Checkbox(
		'tlsauth_enable',
		'TLS Configuration',
		'Use a TLS Key',
		$pconfig['tlsauth_enable']
	))->setHelp('Both peers need the same key before a TLS handshake starts, so control channel packets without it are dropped. It does not affect tunnel data.');

	if (!$pconfig['tls']) {
		$section->addInput(new Form_Checkbox(
			'autotls_enable',
			null,
			'Automatically generate a TLS Key.',
			$pconfig['autotls_enable']
		));
	}

	$section->addInput(new Form_Textarea(
		'tls',
		'*TLS Key',
		$pconfig['tls']
	))->setHelp('Paste the TLS key here. It signs control channel packets with an HMAC signature.');

	$section->addInput(new Form_Select(
		'tls_type',
		'*TLS Key Usage Mode',
		empty($pconfig['tls_type']) ? 'auth':$pconfig['tls_type'],
		$openvpn_tls_modes
		))->setHelp('Authentication only signs the control channel. Encryption and Authentication also encrypts it, for more privacy.');

	if (strlen($pconfig['tlsauth_keydir']) == 0) {
		$pconfig['tlsauth_keydir'] = "default";
	}
	$section->addInput(new Form_Select(
		'tlsauth_keydir',
		'*TLS keydir direction',
		$pconfig['tlsauth_keydir'],
		openvpn_get_keydirlist()
	))->setHelp('Use complementary values on client and server (server 0, client 1), or omit the direction on both.');

	$form->add($section);

	/* ---------------------------------------------------- User authentication */
	$section = new Form_Section('User authentication');
	$section->addClass('authentication');

	$section->addInput(new Form_Input(
		'auth_user',
		'Username',
		'text',
		$pconfig['auth_user'],
		['autocomplete' => 'new-password']
	))->setHelp('Leave empty when no user name is needed');

	$section->addPassword(new Form_Input(
		'auth_pass',
		'Password',
		'password',
		$pconfig['auth_pass']
	), false)->setHelp('Leave empty when no password is needed');

	$section->addInput(new Form_Checkbox(
		'auth-retry-none',
		'Authentication Retry',
		'Do not retry connection when authentication fails',
		$pconfig['auth-retry-none']
	))->setHelp('OpenVPN exits after an authentication failure instead of retrying.%1$s%2$s%3$s', '<div class="infoblock">',
		    sprint_info_box(gettext('If the server needs both a username and a password but only one is filled in, ' .
		    'the system waits for OpenVPN credentials at boot unless this option is checked.'), 'info', false), '</div>');

	$form->add($section);

	/* -------------------------------------------------------- Data encryption */
	$section = new Form_Section('Data encryption');

	$data_ciphers_list = array();
	foreach (array_filter(explode(",", $pconfig['data_ciphers'])) as $cipher) {
		$data_ciphers_list[$cipher] = $cipher;
	}
	$group = new Form_Group('Data Encryption Algorithms');
	$group->addClass("datacipherlist");

	$group->add(new Form_Select(
		'availciphers',
		null,
		array(),
		$openvpn_all_data_ciphers,
		true
	))->setAttribute('size', '10')
	  ->setHelp('Available Data Encryption Algorithms%1$sClick to add or remove an algorithm from the list', '<br />');

	$group->add(new Form_Select(
		'data_ciphers',
		null,
		array(),
		$data_ciphers_list,
		true
	))->setReadonly()
	  ->setAttribute('size', '10')
	  ->setHelp('Allowed Data Encryption Algorithms. Click an algorithm name to remove it from the list');

	$group->setHelp('OpenVPN uses the selected algorithms in this order. Ignored in Shared Key mode. ' .
					'An older peer that cannot negotiate uses the fallback algorithm below.');

	$section->add($group);

	$section->addInput(new Form_Select(
		'data_ciphers_fallback',
		'Fallback Data Encryption Algorithm',
		$pconfig['data_ciphers_fallback'],
		$openvpn_all_data_ciphers
		))->setHelp('Used with peers that do not negotiate an algorithm (e.g. Shared Key). It is always included in the list above.');

	$section->addInput(new Form_Select(
		'digest',
		'*Auth digest algorithm',
		$pconfig['digest'],
		openvpn_get_digestlist()
		))->setHelp('Authenticates data channel packets (control channel only with AEAD ciphers such as AES-GCM). ' .
		    'Set it to the same value as the server; SHA1 is insecure.');

	$form->add($section);

	/* -------------------------------------------------------- Tunnel networks */
	$section = new Form_Section('Tunnel networks');

	$section->addInput(new Form_Input(
		'tunnel_network',
		'IPv4 Tunnel Network',
		'text',
		$pconfig['tunnel_network']
	))->setHelp('Usually left empty: the server assigns the address. Otherwise a CIDR network (e.g. 10.0.8.0/24); ' .
			'the client gets its second usable address. A /30 or smaller network puts OpenVPN into a peer-to-peer mode ' .
			'that cannot receive settings from the server and does not support Exit Notify or Inactive.');

	$section->addInput(new Form_Input(
		'tunnel_networkv6',
		'IPv6 Tunnel Network',
		'text',
		$pconfig['tunnel_networkv6']
	))->setHelp('Usually left empty. Otherwise an IPv6 prefix (e.g. fe80::/64); the client gets the ::2 address.');

	$section->addInput(new Form_Input(
		'remote_network',
		'IPv4 Remote network(s)',
		'text',
		$pconfig['remote_network']
	))->setHelp('IPv4 networks routed through the tunnel, for a site-to-site VPN: a comma-separated list of CIDR ranges or host/network aliases.');

	$section->addInput(new Form_Input(
		'remote_networkv6',
		'IPv6 Remote network(s)',
		'text',
		$pconfig['remote_networkv6']
	))->setHelp('IPv6 networks routed through the tunnel: a comma-separated list of IP/PREFIX values or host/network aliases.');

	$section->addInput(new Form_Select(
		'topology',
		'Topology',
		$pconfig['topology'],
		$openvpn_topologies
	))->setHelp('How the virtual adapter gets its IP address. Match the server.');

	$form->add($section);

	/* --------------------------------------------------------- Routing and DNS */
	$section = new Form_Section('Routing and DNS');

	$section->addInput(new Form_Checkbox(
		'route_no_pull',
		'Don\'t pull routes',
		'Bars the server from adding routes to the client\'s routing table',
		$pconfig['route_no_pull']
	))->setHelp('The server can still set the TCP/IP properties of the tunnel interface.');

	$section->addInput(new Form_Checkbox(
		'route_no_exec',
		'Don\'t add/remove routes',
		'Don\'t add or remove routes automatically',
		$pconfig['route_no_exec']
	))->setHelp('Routes are passed to the --route-up script in environment variables instead of being installed.');

	$section->addInput(new Form_Checkbox(
		'dns_add',
		'Pull DNS',
		'Add server provided DNS',
		$pconfig['dns_add']
	))->setHelp('This firewall uses the DNS servers pushed by the server, including for the DNS Resolver and Forwarder.');

	$form->add($section);

	/* ------------------------------------------------- Traffic and compression */
	$section = new Form_Section('Traffic and compression', 'ovpnc-traffic', $adv_state);

	$section->addInput(new Form_Input(
		'use_shaper',
		'Limit outgoing bandwidth',
		'number',
		$pconfig['use_shaper'],
		['min' => 100, 'max' => 100000000, 'placeholder' => 'Between 100 and 100,000,000 bytes/sec']
	))->setHelp('Maximum outgoing bandwidth in bytes per second (100 to 100,000,000). Leave empty for no limit. Not compatible with UDP Fast I/O.');

	$section->addInput(new Form_Select(
		'allow_compression',
		'Allow Compression',
		$pconfig['allow_compression'],
		$openvpn_allow_compression
		))->setHelp('Compression can leak secrets when an attacker controls part of the traffic (VORACLE, CRIME, BREACH). ' .
				'Asymmetric compression eases connecting to older peers.');

	$section->addInput(new Form_Select(
		'compression',
		'Compression',
		$pconfig['compression'],
		$openvpn_compression_modes
		))->setHelp('Deprecated and potentially insecure. Adaptive compression turns itself off for a while when data does not compress well.');

	$section->addInput(new Form_Checkbox(
		'passtos',
		'Type-of-Service',
		'Set the TOS IP header value of tunnel packets to match the encapsulated packet value.',
		$pconfig['passtos']
	));

	$form->add($section);

	/* ------------------------------------------------------- Keepalive and ping */
	$section = new Form_Section('Keepalive and ping', 'ovpnc-ping', $adv_state);

	$section->addInput(new Form_Input(
		'inactive_seconds',
		'Inactive',
		'number',
		$pconfig['inactive_seconds'] ?: 0,
		['min' => '0']
	))->setHelp('Exit after this many seconds without tunnel traffic (0 disables it). ' .
		'Use with caution: the client does not restart by itself.');

	$section->addInput(new Form_Select(
		'ping_method',
		'Ping method',
		$pconfig['ping_method'],
		$openvpn_ping_method
	))->setHelp('keepalive sets ping = interval and ping-restart = timeout.');

	$section->addInput(new Form_Input(
		'keepalive_interval',
		'Interval',
		'number',
		$pconfig['keepalive_interval']
		    ?: $openvpn_default_keepalive_interval,
		['min' => '0']
	));

	$section->addInput(new Form_Input(
		'keepalive_timeout',
		'Timeout',
		'number',
		$pconfig['keepalive_timeout']
		    ?: $openvpn_default_keepalive_timeout,
		['min' => '0']
	));

	$section->addInput(new Form_Input(
		'ping_seconds',
		'Ping',
		'number',
		$pconfig['ping_seconds'] ?: $openvpn_default_keepalive_interval,
		['min' => '0']
	))->setHelp('Ping the remote over the control channel after this many seconds without packets.');

	$section->addInput(new Form_Select(
		'ping_action',
		'Ping restart or exit',
		$pconfig['ping_action'],
		$openvpn_ping_action
	))->setHelp('Exit or restart OpenVPN after timeout from remote');

	$section->addInput(new Form_Input(
		'ping_action_seconds',
		'Ping restart or exit seconds',
		'number',
		$pconfig['ping_action_seconds']
		    ?: $openvpn_default_keepalive_timeout,
		['min' => '0']
	));

	$form->add($section);

	/* --------------------------------------------------------------- Advanced */
	$section = new Form_Section('Advanced', 'ovpnc-advanced', $adv_state);
	$section->addClass('advanced');

	$custops = new Form_Textarea(
		'custom_options',
		'Custom options',
		$pconfig['custom_options']
	);
	if (!$user_can_edit_advanced) {
		$custops->setDisabled();
	}
	$section->addInput($custops)->setHelp('Additional OpenVPN client options, separated by semicolons.');

	$section->addInput(new Form_Checkbox(
		'udp_fast_io',
		'UDP Fast I/O',
		'Use fast I/O operations with UDP writes to tun/tap. Experimental.',
		$pconfig['udp_fast_io']
	))->setHelp('Saves 5-10% CPU. Not supported on all platforms and not compatible with bandwidth limiting.');

	$section->addInput(new Form_Select(
		'exit_notify',
		'Exit Notify',
		$pconfig['exit_notify'],
		$openvpn_exit_notify_client
	))->setHelp('How many times to tell the server when this client restarts or stops, so it disconnects at once. ' .
		'Ignored in Shared Key mode and with a /30 tunnel network.');

	$section->addInput(new Form_Select(
		'sndrcvbuf',
		'Send/Receive Buffer',
		$pconfig['sndrcvbuf'],
		openvpn_get_buffer_values()
		))->setHelp('The default can be too small for fast links. Start at 512KiB and test higher and lower values.');

	$group = new Form_Group('Gateway creation');
	$group->add(new Form_Checkbox(
		'create_gw',
		null,
		'Both',
		($pconfig['create_gw'] == "both"),
		'both'
	))->displayAsRadio();

	$group->add(new Form_Checkbox(
		'create_gw',
		null,
		'IPv4 only',
		($pconfig['create_gw'] == "v4only"),
		'v4only'
	))->displayAsRadio();

	$group->add(new Form_Checkbox(
		'create_gw',
		null,
		'IPv6 only',
		($pconfig['create_gw'] == "v6only"),
		'v6only'
	))->displayAsRadio();

	$group->setHelp('Which gateways are created when this client is assigned as an interface. Default: both.');

	$section->add($group);

	$section->addInput(new Form_Select(
		'verbosity_level',
		'Verbosity level',
		$pconfig['verbosity_level'],
		$openvpn_verbosity_level
		))->setHelp('Each level includes the previous ones. 3 gives a good summary; 5 logs every packet read and write; 6-11 are for debugging.');

	$form->addGlobal(new Form_Input(
		'act',
		null,
		'hidden',
		$act
	));

	if ($this_client_config) {
		$form->addGlobal(new Form_Input(
			'id',
			null,
			'hidden',
			$id
		));
	}

	if (isset($parentid)) {
		$form->addGlobal(new Form_Input(
			'parentid',
			null,
			'hidden',
			$parentid
		));
	}

	$form->add($section);
	fs_form_cancel($form, 'vpn_openvpn_client.php');
	print($form);
else:
	$clients = config_get_path('openvpn/openvpn-client', []);
	/* live state of the enabled clients (management socket), by vpnid */
	$client_state = array();
	foreach (openvpn_get_active_clients() as $cs) {
		$client_state[$cs['vpnid']] = $cs;
	}
	$count_enabled = 0;
	$count_connected = 0;
	foreach ($clients as $client) {
		if (!isset($client['disable'])) {
			$count_enabled++;
			if (($client_state[$client['vpnid']]['state'] ?? '') == 'CONNECTED') {
				$count_connected++;
			}
		}
	}
	if (!empty($clients)):
?>
<div class="fs-tiles">
<?php
	fs_tile(gettext('Clients'), count($clients));
	fs_tile(gettext('Enabled'), $count_enabled);
	fs_tile(gettext('Connected'), $count_connected, null, gettext('Enabled clients with an established tunnel'));
	fs_tile(gettext('Disabled'), count($clients) - $count_enabled);
?>
</div>
<?php endif; ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('OpenVPN clients'),
	'search' => gettext('Search clients…'),
	'noun' => gettext('clients'),
	'noun_one' => gettext('client'),
	'filters' => [
		'status' => [gettext('All states'), 'enabled' => gettext('Enabled'), 'disabled' => gettext('Disabled')],
		'mode' => [gettext('All modes'), 'p2p_tls' => $client_mode_short['p2p_tls'], 'p2p_shared_key' => $client_mode_short['p2p_shared_key']],
	],
]); ?>
		<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext("Status")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th data-fs-search><?=gettext("Server")?></th>
					<th data-fs-search><?=gettext("Protocol")?></th>
					<th data-fs-search><?=gettext("Mode")?></th>
					<th data-fs-search><?=gettext("Tunnel network")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>

			<tbody>
<?php
	$print_sk_warning = false;
	$i = 0;
	foreach ($clients as $client):
		if ($client['mode'] == 'p2p_shared_key') {
			$print_sk_warning = true;
		}
		$name = $client['description'] ?: sprintf(gettext('Client %s'), $client['vpnid']);
		$server = "{$client['server_addr']}:{$client['server_port']}";
		$disabled = isset($client['disable']);
		$dc = openvpn_build_data_cipher_list($client['data_ciphers'], $client['data_ciphers_fallback']);
		$dca = array_filter(explode(',', $dc));
		if (count($dca) > 3) {
			$dca = array_slice($dca, 0, 3);
			$dca[] = '…';
		}
		$state = $client_state[$client['vpnid']] ?? null;
		if ($disabled) {
			$badge = fs_badge('disabled');
		} elseif ($state && ($state['state'] ?? '') == 'CONNECTED') {
			$badge = fs_badge('up', gettext('Connected'));
		} elseif ($state && !empty($state['state'])) {
			$badge = fs_badge('pending', $state['status'] ?: $state['state']);
		} else {
			$badge = fs_badge('down', gettext('Not connected'));
		}
?>
				<tr data-fs-filter-status="<?=$disabled ? 'disabled' : 'enabled'?>" data-fs-filter-mode="<?=htmlspecialchars($client['mode'])?>"<?=$disabled ? ' class="fs-row-disabled"' : ''?>>
					<td><?=$badge?></td>
					<td>
						<a href="vpn_openvpn_client.php?act=edit&amp;id=<?=$i?>"><?=htmlspecialchars($name)?></a>
						<div class="fs-ovpn-sub fs-mono">ovpnc<?=htmlspecialchars($client['vpnid'])?></div>
					</td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($server)?></span>
						<div class="fs-ovpn-sub"><?=htmlspecialchars(sprintf(gettext('via %s'), convert_openvpn_interface_to_friendly_descr($client['interface'])))?></div>
					</td>
					<td><span class="fs-chips"><span class="fs-chip fs-chip--strong"><?=htmlspecialchars($client['protocol'])?></span><span class="fs-chip fs-chip--strong"><?=htmlspecialchars(strtoupper(empty($client['dev_mode']) ? 'TUN' : $client['dev_mode']))?></span></span></td>
					<td>
						<?=($client['mode'] == 'p2p_shared_key') ? fs_badge('warn', $client_mode_short['p2p_shared_key'], gettext('Shared key mode is deprecated')) : fs_badge('info', $client_mode_short[$client['mode']] ?? $client['mode'])?>
						<div class="fs-ovpn-sub" title="<?=htmlspecialchars($dc)?>"><?=htmlspecialchars(implode(' · ', array_filter(array(implode(', ', $dca), $client['digest'] ?? ''))))?></div>
					</td>
					<td><?=(empty($client['tunnel_network'])) ? '<span class="fs-muted">' . gettext('From server') . '</span>' : '<span class="fs-mono">' . htmlspecialchars($client['tunnel_network']) . '</span>'?></td>
					<td class="fs-col-actions">
<?=fs_row_actions([
							['edit', "vpn_openvpn_client.php?act=edit&id={$i}", $name],
							['copy', "vpn_openvpn_client.php?act=dup&id={$i}", $name],
							['delete', "vpn_openvpn_client.php?act=del&id={$i}", $name, ['thing' => gettext('client'), 'detail' => gettext('The tunnel is stopped and its settings are removed.')]],
						])?>
					</td>
				</tr>
<?php
		$i++;
	endforeach;
?>
<?php if (empty($clients)) {
	fs_empty_row(7, gettext('No clients yet.'), 'vpn_openvpn_client.php?act=new', gettext('Add client'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php
if ($print_sk_warning) {
	print_info_box(gettext('WARNING:') . ' ' . $openvpn_sharedkey_warning, 'warning', false);
}
?>

<?php
endif;

// Note:
// The following *_change() functions were converted from JavaScript/DOM to JQuery but otherwise
// mostly left unchanged. The logic on this form is complex and this works!
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	function mode_change() {
		switch ($('#mode').val()) {
			case "p2p_tls":
				hideClass('sharedkeywarning', true);
				hideCheckbox('tlsauth_enable', false);
				hideInput('tlsauth_keydir', false);
				hideInput('caref', false);
				hideInput('certref', false);
				hideClass('authentication', false);
				hideCheckbox('autokey_enable', true);
				hideCheckbox('remote_cert_tls', false);
				hideInput('shared_key', true);
				hideLabel('Peer Certificate Revocation list', false);
				hideInput('crlref', false);
				hideInput('topology', false);
				hideCheckbox('route_no_pull', false);
				hideInput('inactive_seconds', false);
				hideInput('exit_notify', false);
				break;
			case "p2p_shared_key":
				hideClass('sharedkeywarning', false);
				hideCheckbox('tlsauth_enable', true);
				hideInput('tlsauth_keydir', true);
				hideInput('caref', true);
				hideInput('certref', true);
				hideClass('authentication', true);
				hideCheckbox('autokey_enable', false);
				hideCheckbox('remote_cert_tls', true);
				hideInput('shared_key', false);
				hideLabel('Peer Certificate Revocation list', true);
				hideInput('crlref', true);
				hideInput('topology', true);
				hideCheckbox('route_no_pull', true);
				hideInput('inactive_seconds', true);
				hideInput('exit_notify', true);
				break;
		}

		tlsauth_change();
		autokey_change();
		dev_mode_change();

	}

	function dev_mode_change() {
		hideInput('topology',  ($('#dev_mode').val() == 'tap') || $('#mode').val() == "p2p_shared_key");
	}

	function protocol_change() {
		if ($('#protocol').val() != undefined) {
			hideInput('interface', (($('#protocol').val().toLowerCase() == 'udp') || ($('#protocol').val().toLowerCase() == 'tcp')));
			var notudp = !($('#protocol').val().substring(0, 3).toLowerCase() == 'udp');
			hideCheckbox('udp_fast_io', notudp);
			switch ($('#mode').val()) {
				case "p2p_shared_key":
					hideInput('exit_notify', true);
					break;
				default:
					hideInput('exit_notify', notudp);
			}
		}
	}

	// Process "Automatically generate a shared key" checkbox
	function autokey_change() {
		hideInput('shared_key', ($('#autokey_enable').prop('checked') || ($('#mode').val() == 'p2p_tls')));
	}

	function useproxy_changed() {
		hideInput('proxy_user', ($('#proxy_authtype').val() == 'none'));
		hideInput('proxy_passwd', ($('#proxy_authtype').val() == 'none'));
	}

	// Process "Enable authentication of TLS packets" checkbox
	function tlsauth_change() {
		hideCheckbox('autotls_enable', !($('#tlsauth_enable').prop('checked'))  || ($('#mode').val() == 'p2p_shared_key'));
		autotls_change();
	}

	// Process "Automatically generate a shared TLS authentication key" checkbox
	function autotls_change() {
		hideInput('tls', $('#autotls_enable').prop('checked') || !$('#tlsauth_enable').prop('checked') || ($('#mode').val() == 'p2p_shared_key'));
		hideInput('tls_type', $('#autotls_enable').prop('checked') || !$('#tlsauth_enable').prop('checked') || ($('#mode').val() == 'p2p_shared_key'));
	}

	function ping_method_change() {
		pvalue = $('#ping_method').val();

		keepalive = (pvalue == 'keepalive');

		hideInput('keepalive_interval', !keepalive);
		hideInput('keepalive_timeout', !keepalive);
		hideInput('ping_seconds', keepalive);
		hideInput('ping_action', keepalive);
		hideInput('ping_action_seconds', keepalive);
	}

	function allow_compression_change() {
		var hide  = ($('#allow_compression').val() == 'no')
		hideInput('compression', hide);
	}

	// ---------- Monitor elements for change and call the appropriate display functions ------------------------------

	 // TLS Authorization
	$('#tlsauth_enable').click(function () {
		tlsauth_change();
	});

	 // Auto key
	$('#autokey_enable').click(function () {
		autokey_change();
	});

	 // Mode
	$('#mode').change(function () {
		mode_change();
		protocol_change();
	});

	// Protocol
	$('#protocol').change(function () {
		protocol_change();
	});

	 // Use proxy
	$('#proxy_authtype').change(function () {
		useproxy_changed();
	});

	 // Tun/tap
	$('#dev_mode').change(function () {
		dev_mode_change();
	});

	// ping
	$('#ping_method').change(function () {
		ping_method_change();
	});

	 // Auto TLS
	$('#autotls_enable').click(function () {
		autotls_change();
	});

	// Compression Settings
	$('#allow_compression').change(function () {
		allow_compression_change();
	});

	function updateCipher(mem) {
		var found = false;
		var ciphers_all = <?= json_encode($openvpn_all_data_ciphers) ?>;

		// If the cipher exists, remove it
		$('[id="data_ciphers[]"] option').each(function() {
			if($(this).val().toString() == mem) {
				$(this).remove();
				found = true;
			}
		});

		// If not, add it
		if (!found) {
			$('[id="data_ciphers[]"]').append(new Option(ciphers_all[mem], mem));
		}
	}

	function updateCiphers(mem) {
		mem.toString().split(",").forEach(updateCipher);

		// Unselect all options
		$('[id="availciphers[]"] option:selected').removeAttr("selected");
	}

	// On click, update the ciphers list
	$('[id="availciphers[]"]').click(function () {
		updateCiphers($(this).val());
	});

	// On click, remove the cipher from the list
	$('[id="data_ciphers[]"]').click(function () {
		if ($(this).val() != null) {
			updateCiphers($(this).val());
		}
	});

	// Make sure the "Available ciphers" selector is not submitted with the form,
	// and select all of the chosen ciphers so that they are submitted
	$('form').submit(function() {
		$("#availciphers" ).prop( "disabled", true);
		$('[id="data_ciphers[]"] option').prop("selected", true);
	});

	// ---------- Set initial page display state ----------------------------------------------------------------------
	mode_change();
	protocol_change();
	autokey_change();
	tlsauth_change();
	useproxy_changed();
	ping_method_change();
	allow_compression_change();
});
//]]>
</script>

<?php include("foot.inc");
