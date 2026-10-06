<?php
/*
 * vpn_openvpn_server.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-openvpn-server
##|*NAME=OpenVPN: Servers
##|*DESCR=Allow access to the 'OpenVPN: Servers' page.
##|*MATCH=vpn_openvpn_server.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("openvpn.inc");
require_once("freesense-utils.inc");
require_once("pkg-utils.inc");
require_once("vpn_openvpn.inc");

global $openvpn_topologies, $openvpn_tls_modes, $openvpn_exit_notify_server;
global $openvpn_sharedkey_warning;
global $openvpn_prots;

$openvpn_all_data_ciphers = openvpn_get_cipherlist();

foreach (config_get_path('crl', []) as $cid => $acrl) {
	if (!isset($acrl['refid'])) {
		config_del_path("crl/{$cid}");
	}
}

$certlist = openvpn_build_cert_list(false, true);

if (isset($_REQUEST['id']) && is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

$this_server_config = isset($id) ? config_get_path("openvpn/openvpn-server/{$id}") : null;

if (isset($_REQUEST['act'])) {
	$act = $_REQUEST['act'];
}

$user_can_edit_advanced = openvpn_user_can_edit_advanced("page-openvpn-server-advanced");

if ($this_server_config) {
	$vpnid = $this_server_config['vpnid'];
} else {
	$vpnid = 0;
}

if ($_POST['act'] == "del") {
	$rv = openvpn_instance_delete('server', $id ?? null, $user_can_edit_advanced);
	if ($rv === null) {
		FreeSenseHeader("vpn_openvpn_server.php");
		exit;
	}
	if (!empty($rv['input_errors'])) {
		$input_errors = $rv['input_errors'];
	}
	if ($rv['deleted']) {
		$savemsg = gettext("Server successfully deleted.");
	}
}

$pconfig = openvpn_server_form($act, $this_server_config);

/* the editor's header card shows the stored server (or the new one's defaults), not posted values */
$server_summary = $pconfig;
$server_summary_saved = ($act == "edit") && $this_server_config;

if ($act == "dup") {
	$act = "new";
	$vpnid = 0;
	unset($id);
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = openvpn_server_save($pconfig, $id ?? null, $act, $user_can_edit_advanced);
	if (!$input_errors) {
		header("Location: vpn_openvpn_server.php");
		exit;
	}

	if (!empty($pconfig['data_ciphers']) && is_array($pconfig['data_ciphers'])) {
		$pconfig['data_ciphers'] = implode(",", $pconfig['data_ciphers']);
	}

	if (!empty($pconfig['authmode']) && is_array($pconfig['authmode'])) {
		$pconfig['authmode'] = implode(",", $pconfig['authmode']);
	}
}

/* short mode names for the list badges and the editor's header card: [kind, detail] */
$server_mode_short = [
	'p2p_tls' => [gettext('Peer to peer'), gettext('SSL/TLS')],
	'p2p_shared_key' => [gettext('Peer to peer'), gettext('Shared key')],
	'server_tls' => [gettext('Remote access'), gettext('SSL/TLS')],
	'server_user' => [gettext('Remote access'), gettext('User auth')],
	'server_tls_user' => [gettext('Remote access'), gettext('SSL/TLS + user auth')],
];

$is_editor = ($act == "new" || $act == "edit");

$pgtitle = array(gettext("VPN"), gettext("OpenVPN"), gettext("Servers"));
$pglinks = array("", "vpn_openvpn_server.php", "vpn_openvpn_server.php");

if ($act == "edit") {
	if (!empty($server_summary['description'])) {
		$pgtitle[] = htmlspecialchars($server_summary['description']);
		$pglinks[] = "";
	}
	$pgtitle[] = gettext('Edit server');
	$pglinks[] = "@self";
} elseif ($act == "new") {
	$pgtitle[] = gettext('Add server');
	$pglinks[] = "@self";
}
$shortcut_section = "openvpn";

if (!$is_editor) {
	fs_page_action(gettext('Add server'), 'vpn_openvpn_server.php?act=new', 'fa-plus');
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

fs_tabs('vpn-openvpn', 'vpn_openvpn_server.php');
?>
<style>
.fs-ovpn-mode { display: inline-flex; flex-direction: column; gap: .1rem; }
.fs-ovpn-kind { display: inline-block; align-self: flex-start; padding: 0 .5rem; border-radius: 999px; font-size: var(--fs-fs-xs); font-weight: 600; line-height: 1.35rem; white-space: nowrap;
	background: color-mix(in srgb, var(--fs-info) 12%, transparent); color: var(--fs-info); border: 1px solid color-mix(in srgb, var(--fs-info) 35%, transparent); }
.fs-ovpn-kind.is-p2p { background: var(--fs-accent-tint); color: var(--fs-coral-text); border-color: color-mix(in srgb, var(--fs-coral) 35%, transparent); }
.fs-ovpn-sub { display: block; font-size: var(--fs-fs-xs); }
.fs-ovpn-line { display: block; }
.fs-ovpn-empty { display: none !important; }
</style>
<?php

$form = new Form();

if ($is_editor):

	/* ---------------------------------------------------------------- header card */
	$sum_mode = $server_mode_short[$server_summary['mode']] ?? [$openvpn_server_modes[$server_summary['mode']] ?? '', ''];
	$sum_if = explode('|', (string)$server_summary['interface'])[0];
	$sum_tunnel = array_filter([$server_summary['tunnel_network'] ?? '', $server_summary['tunnel_networkv6'] ?? '']);
	$sum_name = $server_summary['description'] ?: ($server_summary_saved ? sprintf(gettext('Server %d'), $id + 1) : gettext('New server'));

	fs_summary_card([
		'icon' => 'fa-server',
		'title' => $sum_name,
		'badges' => $server_summary_saved ? [empty($server_summary['disable']) ? fs_badge('enabled') : fs_badge('disabled')] : [fs_badge('pending', gettext('Not saved yet'))],
		'meta' => $server_summary_saved ? 'ovpns' . $vpnid : '',
		'facts' => [
			[gettext('Mode'), trim($sum_mode[0] . ($sum_mode[1] ? ' · ' . $sum_mode[1] : ''))],
			[gettext('Protocol / port'), ($server_summary['protocol'] ?: (array_key_first($openvpn_prots) ?? '–')) . ' / ' . ($server_summary['local_port'] ?: '–') . ' · ' . strtoupper($server_summary['dev_mode'] ?: 'tun'), 'mono' => true],
			[gettext('Interface'), convert_openvpn_interface_to_friendly_descr($sum_if) ?: $sum_if],
			[gettext('Tunnel network'), implode(', ', $sum_tunnel), 'mono' => true, 'empty' => gettext('None')],
		],
		'label' => gettext('Server summary'),
	]);

	/* -------------------------------------------------------------------- general */
	$section = new Form_Section('General', 'ovpn-general');

	$section->addInput(new Form_Input(
		'description',
		'Description',
		'text',
		$pconfig['description']
	))->setHelp('A description for administrative reference.');

	$section->addInput(new Form_Checkbox(
		'disable',
		'Disabled',
		'Disable this server',
		$pconfig['disable']
	))->setHelp('Keeps the server in the list without running it.');

	if ($vpnid) {
		$section->addInput(new Form_StaticText(
			'Unique VPN ID',
			gettext('Server') . " {$vpnid} (ovpns{$vpnid})"
		));
	}

	$section->addInput(new Form_Select(
		'mode',
		'*Server mode',
		$pconfig['mode'],
		openvpn_build_mode_list()
		));

	$group = new Form_Group('WARNING:');
	$group->add(new Form_StaticText(
		'',
		$openvpn_sharedkey_warning
	));
	$group->addClass('text-danger')->addClass('sharedkeywarning');
	$section->add($group);

	$options = array();
	$authmodes = array();
	$authmodes = explode(",", $pconfig['authmode']);

	$auth_servers = auth_get_authserver_list();

	$data_ciphers_list = array();
	foreach (array_filter(explode(",", $pconfig['data_ciphers'])) as $cipher) {
		$data_ciphers_list[$cipher] = $cipher;
	}

	// If no authmodes set then default to selecting the first entry in auth_servers
	if (empty($authmodes[0]) && !empty(key($auth_servers))) {
		$authmodes[0] = key($auth_servers);
	}

	foreach ($auth_servers as $auth_server_key => $auth_server) {
		$options[$auth_server_key] = $auth_server['name'];
	}

	$section->addInput(new Form_Select(
		'authmode',
		'*Backend for authentication',
		$authmodes,
		$options,
		true
		))->addClass('authmode');

	$section->addInput(new Form_Select(
		'dev_mode',
		'*Device mode',
		empty($pconfig['dev_mode']) ? 'tun':$pconfig['dev_mode'],
		$openvpn_dev_mode
		))->setHelp('"tun" carries IPv4 and IPv6 (layer 3) and works with every client.%1$s' .
		    '"tap" carries Ethernet frames (layer 2).', '<br/>');

	$form->add($section);

	/* ------------------------------------------------------------------- endpoint */
	$section = new Form_Section('Endpoint', 'ovpn-endpoint');

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
		))->setHelp("The interface or virtual IP address where OpenVPN receives client connections.");

	$section->addInput(new Form_Input(
		'local_port',
		'*Local port',
		'number',
		$pconfig['local_port'],
		['min' => '0']
	))->setHelp("The port where OpenVPN receives client connections.");

	$form->add($section);

	/* ----------------------------------------------------- cryptographic settings */
	$section = new Form_Section('Cryptographic settings', 'ovpn-crypto');

	$section->addInput(new Form_Checkbox(
		'tlsauth_enable',
		'TLS key',
		'Use a TLS key',
		$pconfig['tlsauth_enable']
	))->setHelp("Both peers must share this key before a TLS handshake starts, so control channel packets without it are dropped. " .
	    "It does not affect tunnel data.");

	if (!$pconfig['tls']) {
		$section->addInput(new Form_Checkbox(
			'autotls_enable',
			null,
			'Automatically generate a TLS key',
			$pconfig['autotls_enable']
		));
	}

	$section->addInput(new Form_Textarea(
		'tls',
		'*TLS key',
		$pconfig['tls']
	))->setHelp('Paste the TLS key here. It signs control channel packets with an HMAC signature.');

	$section->addInput(new Form_Select(
		'tls_type',
		'*TLS key usage mode',
		empty($pconfig['tls_type']) ? 'auth':$pconfig['tls_type'],
		$openvpn_tls_modes
		))->setHelp('Authentication only signs the control channel. %1$s' .
		    'Encryption and authentication also encrypts it, for more privacy and obfuscation.',
			'<br/>');

	if (strlen($pconfig['tlsauth_keydir']) == 0) {
		$pconfig['tlsauth_keydir'] = "default";
	}
	$section->addInput(new Form_Select(
		'tlsauth_keydir',
		'*TLS key direction',
		$pconfig['tlsauth_keydir'],
		openvpn_get_keydirlist()
	))->setHelp('Client and server need complementary values (server 0, client 1), or both omit the direction to use the key bidirectionally.');

	if (count(config_get_path('ca', []))) {
		$section->addInput(new Form_Select(
			'caref',
			'*Peer certificate authority',
			$pconfig['caref'],
			cert_build_list('ca', 'OpenVPN')
		));
	} else {
		$section->addInput(new Form_StaticText(
			'*Peer certificate authority',
			sprintf('No certificate authorities defined. Create one in %s.', '<a href="system_camanager.php">System &gt; Cert. Manager</a>')
		));
	}

	if (count(config_get_path('crl', []))) {
		$section->addInput(new Form_Select(
			'crlref',
			'Peer certificate revocation list',
			$pconfig['crlref'],
			openvpn_build_crl_list()
		));
	} else {
		$section->addInput(new Form_StaticText(
			'Peer certificate revocation list',
			sprintf('No certificate revocation lists defined. Create one in %s.', '<a href="system_camanager.php">System &gt; Cert. Manager</a>')
		));
	}

	$section->addInput(new Form_Checkbox(
		'ocspcheck',
		'OCSP check',
		'Check client certificates with OCSP',
		$pconfig['ocspcheck']
	));

	$section->addInput(new Form_Input(
		'ocspurl',
		'OCSP URL',
		'url',
		$pconfig['ocspurl']
	));

	$certhelp = '<span id="certtype"></span>';
	if (count(config_get_path('cert', []))) {
		if (!empty(trim($pconfig['certref']))) {
			$thiscert = lookup_cert($pconfig['certref']);
			$thiscert = $thiscert['item'];
			$purpose = cert_get_purpose($thiscert['crt'], true);
			if ($purpose['server'] != "Yes") {
				$certhelp = '<span id="certtype" class="text-danger">' . gettext("Warning: The selected server certificate was not created as an SSL/TLS Server certificate and may not work as expected") . ' </span>';
			}
		}
	} else {
		$certhelp = sprintf(gettext('No compatible certificates defined. One may be created at %1$s%2$s%3$s'), '<span id="certtype">', '<a href="system_camanager.php">' . gettext("System &gt; Certificates") . '</a>', '</span>');
	}
	$certhelp .= gettext('Certificates known to be incompatible with use for OpenVPN are not included in this list, ' .
				'such as certificates using incompatible ECDSA curves or weak digest algorithms.');

	//Save the number of server certs for use at run-time
	$servercerts = count($certlist['server']);

	$section->addInput(new Form_Select(
		'certref',
		'*Server certificate',
		$pconfig['certref'],
		$certlist['server'] + $certlist['non-server']
		))->setHelp($certhelp);

	$section->addInput(new Form_Select(
		'dh_length',
		'*DH parameter length',
		$pconfig['dh_length'],
		$openvpn_dh_lengths
		))->setHelp('Diffie-Hellman (DH) parameter set used for key exchange.%1$s%2$s%3$s',
		    '<div class="infoblock">',
		    sprint_info_box(gettext('Only DH parameter sets which exist in /etc/ are shown.') .
		        '<br/>' .
		        gettext('Generating new or stronger DH parameters is CPU-intensive and must be performed manually.') . ' ' .
		        sprintf(gettext('Consult %1$sthe doc wiki article on DH Parameters%2$sfor information on generating new or stronger parameter sets.'),
					'<a href="https://docs.freesense.org/en/latest/vpn/openvpn/configure.html#dh-parameters-length">',
					'</a> '),
				'info', false),
		    '</div>');

	$section->addInput(new Form_Select(
		'ecdh_curve',
		'ECDH curve',
		$pconfig['ecdh_curve'],
		openvpn_get_curvelist()
		))->setHelp('The elliptic curve for key exchange. By default the curve of an ECDSA server certificate is used, otherwise secp384r1.');

	if (!$pconfig['shared_key']) {
		$section->addInput(new Form_Checkbox(
			'autokey_enable',
			'Shared key',
			'Automatically generate a shared key',
			$pconfig['autokey_enable']
		));
	}

	$section->addInput(new Form_Textarea(
		'shared_key',
		'*Shared key',
		$pconfig['shared_key']
	))->setHelp('Paste the shared key here.');

	$group = new Form_Group('Data encryption algorithms');
	$group->addClass("datacipherlist");

	$group->add(new Form_Select(
		'availciphers',
		null,
		array(),
		$openvpn_all_data_ciphers,
		true
	))->setAttribute('size', '10')
	  ->setHelp('Available algorithms. Click one to add or remove it.');

	$group->add(new Form_Select(
		'data_ciphers',
		null,
		array(),
		$data_ciphers_list,
		true
	))->setReadonly()
	  ->setAttribute('size', '10')
	  ->setHelp('Allowed algorithms, in order of preference. Click one to remove it.');

	$group->setHelp('OpenVPN respects the order of this list. It is ignored in shared key mode.%1$s%2$s%3$s',
					'<div class="infoblock">',
					sprint_info_box(
						gettext('For backward compatibility, when an older peer connects that does not support dynamic negotiation, OpenVPN will use the Fallback Data Encryption Algorithm ' .
							'requested by the peer so long as it is selected in this list or chosen as the Fallback Data Encryption Algorithm.'), 'info', false),
					'</div>');

	$section->add($group);

	$section->addInput(new Form_Select(
		'data_ciphers_fallback',
		'Fallback data encryption algorithm',
		$pconfig['data_ciphers_fallback'],
		$openvpn_all_data_ciphers
		))->setHelp('Used with peers that cannot negotiate an algorithm (e.g. shared key). It is always included in the list above.');

	$section->addInput(new Form_Select(
		'digest',
		'*Auth digest algorithm',
		$pconfig['digest'],
		openvpn_get_digestlist()
		))->setHelp('Authenticates data channel packets, and control channel packets when a TLS key is used. ' .
		    'With an AEAD algorithm such as AES-GCM it applies to the control channel only. Server and clients must match; avoid SHA1.');

	$form->add($section);

	/* -------------------------------------------------- certificate checks (rare) */
	$section = new Form_Section('Certificate checks', 'ovpn-certchecks', COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED));

	$section->addInput(new Form_Select(
		'cert_depth',
		'*Certificate depth',
		$pconfig['cert_depth'],
		["" => gettext("Do Not Check")] + $openvpn_cert_depths
		))->setHelp('Refuse client certificates below this depth, e.g. ones made by intermediate CAs of the server\'s CA.');

	$section->addInput(new Form_Checkbox(
		'strictusercn',
		'Strict user-CN matching',
		'Enforce match',
		$pconfig['strictusercn']
	))->setHelp('The client certificate common name must match the username given at login.');

	$section->addInput(new Form_Checkbox(
		'remote_cert_tls',
		'Client certificate key usage',
		'Enforce key usage',
		$pconfig['remote_cert_tls']
	))->setHelp('Only hosts with a client certificate (EKU "TLS Web Client Authentication") can connect.');

	$form->add($section);

	/* ------------------------------------------------------------ tunnel settings */
	$section = new Form_Section('Tunnel settings', 'ovpn-tunnel');

	$section->addInput(new Form_Input(
		'tunnel_network',
		'IPv4 tunnel network',
		'text',
		$pconfig['tunnel_network']
	))->setHelp('Private IPv4 network in CIDR notation (e.g. 10.0.8.0/24), or a network alias with one entry. ' .
			'The server takes the first usable address, clients get the rest.%1$s' .
			'A /30 or smaller network puts OpenVPN in peer-to-peer mode, which cannot push settings and ignores Exit Notify and Inactive.',
			'<br/>');

	$section->addInput(new Form_Select(
		'tunnel_networkv6_type',
		'IPv6 tunnel type',
		$pconfig['tunnel_networkv6_type'] ?: 'staticv6',
		["staticv6" => gettext("Static IPv6"), "track6" => gettext("Track Interface")]
	));

	$section->addInput(new Form_Input(
		'tunnel_networkv6',
		'IPv6 tunnel network',
		'text',
		$pconfig['tunnel_networkv6']
	))->setHelp('Private IPv6 network in CIDR notation (e.g. fe80::/64), or a network alias with one entry. The server takes ::1, clients get the rest.');

	$section->addInput(new Form_Select(
		'tunnel_track6_interface',
		'*IPv6 interface',
		array_get_path($pconfig, 'tunnel_track6_interface'),
		build_ipv6interface_list()
	))->setHelp('Select the 6rd interface to track for configuration.');

	if (array_get_path($pconfig, 'tunnel_track6_prefix_id') == "") {
		array_set_path($pconfig, 'tunnel_track6_prefix_id', 0);
	}

	$section->addInput(new Form_Input(
		'tunnel_track6_prefix_id',
		'IPv6 prefix ID',
		'text',
		$pconfig['tunnel_track6_prefix_id']
	))->setHelp('(%1$shexadecimal%2$s from 0 to %3$s) The (delegated) IPv6 prefix ID. It determines the network ID based on the 6rd IPv6 connection. The default is 0.', '<b>', '</b>', '<span id="track6-prefix-id-range"></span>');

	$section->addInput(new Form_Checkbox(
		'serverbridge_dhcp',
		'Bridge DHCP',
		'Allow clients on the bridge to obtain DHCP.',
		$pconfig['serverbridge_dhcp']
	));

	$section->addInput(new Form_Select(
		'serverbridge_interface',
		'Bridge interface',
		$pconfig['serverbridge_interface'],
		openvpn_build_bridge_list()
		))->setHelp('The interface this TAP instance is bridged to. Assign it and create the bridge separately; its address and mask are used for the bridge. ' .
						'"none" ignores the bridge DHCP settings below.');

	$section->addInput(new Form_Checkbox(
		'serverbridge_routegateway',
		'Bridge route gateway',
		'Push the Bridge Interface IPv4 address to connecting clients as a route gateway',
		$pconfig['serverbridge_routegateway']
	))->setHelp('Without an IPv4 tunnel network, clients cannot find a gateway for the local networks or a redirected gateway. ' .
						'This sends them the bridge interface address to use instead. Not supported for IPv6.');

	$section->addInput(new Form_Input(
		'serverbridge_dhcp_start',
		'Server bridge DHCP start',
		'text',
		$pconfig['serverbridge_dhcp_start']
	))->setHelp('Optional DHCP range for a multi-point TAP server on the bridged interface. Leave blank to pass DHCP through to the LAN.');

	$section->addInput(new Form_Input(
		'serverbridge_dhcp_end',
		'Server bridge DHCP end',
		'text',
		$pconfig['serverbridge_dhcp_end']
	));

	$section->addInput(new Form_Checkbox(
		'gwredir',
		'Redirect IPv4 gateway',
		'Force all client-generated IPv4 traffic through the tunnel.',
		$pconfig['gwredir']
	));
	$section->addInput(new Form_Checkbox(
		'gwredir6',
		'Redirect IPv6 gateway',
		'Force all client-generated IPv6 traffic through the tunnel.',
		$pconfig['gwredir6']
	));

	$section->addInput(new Form_Input(
		'local_network',
		'IPv4 local network(s)',
		'text',
		$pconfig['local_network']
	))->setHelp('IPv4 networks reachable from the remote end: comma-separated CIDR ranges or host/network aliases. Usually the LAN network.');

	$section->addInput(new Form_Input(
		'local_networkv6',
		'IPv6 local network(s)',
		'text',
		$pconfig['local_networkv6']
	))->setHelp('IPv6 networks reachable from the remote end: comma-separated IP/prefix or host/network aliases. Usually the LAN network.');

	$section->addInput(new Form_Input(
		'remote_network',
		'IPv4 remote network(s)',
		'text',
		$pconfig['remote_network']
	))->setHelp('IPv4 networks routed through the tunnel for a site-to-site VPN: comma-separated CIDR ranges or host/network aliases.');

	$section->addInput(new Form_Input(
		'remote_networkv6',
		'IPv6 remote network(s)',
		'text',
		$pconfig['remote_networkv6']
	))->setHelp('IPv6 networks routed through the tunnel for a site-to-site VPN: comma-separated IP/prefix or host/network aliases.');

	$section->addInput(new Form_Select(
		'allow_compression',
		'Allow compression',
		$pconfig['allow_compression'],
		$openvpn_allow_compression
		))->setHelp('Compression can raise throughput but may let an attacker who controls compressed plaintext extract secrets ' .
				'(VORACLE, CRIME, TIME, BREACH). Asymmetric compression eases connecting older peers.');

	$section->addInput(new Form_Select(
		'compression',
		'Compression',
		$pconfig['compression'],
		$openvpn_compression_modes
		))->setHelp('Deprecated and potentially insecure: compress tunnel packets with LZO. ' .
				'Adaptive compression turns itself off for a while when the data does not compress well.');

	$section->addInput(new Form_Checkbox(
		'compression_push',
		'Push compression',
		'Push the selected Compression setting to connecting clients.',
		$pconfig['compression_push']
	));

	$section->addInput(new Form_Checkbox(
		'passtos',
		'Type-of-Service',
		'Set the TOS IP header value of tunnel packets to match the encapsulated packet value.',
		$pconfig['passtos']
	));

	$form->add($section);

	/* ------------------------------------------------------------ client settings */
	$section = new Form_Section('Client settings', 'ovpn-clients');

	$section->addInput(new Form_Input(
		'maxclients',
		'Concurrent connections',
		'number',
		$pconfig['maxclients']
	))->setHelp('The maximum number of clients connected at the same time.');

	$section->addInput(new Form_Checkbox(
		'client2client',
		'Inter-client communication',
		'Allow communication between clients connected to this server',
		$pconfig['client2client']
	));

	$section->addInput(new Form_Checkbox(
		'duplicate_cn',
		'Duplicate connection',
		'Allow multiple concurrent connections from the same user',
		$pconfig['duplicate_cn']
	))->setHelp('Otherwise a new connection from a user disconnects the previous one. ' .
			'Users are identified by username or certificate. Discouraged for security reasons.');

	$section->addInput(new Form_Input(
		'connlimit',
		'Duplicate connection limit',
		'number',
		$pconfig['connlimit']
	))->setHelp('Limit the number of concurrent connections from the same user.');

	/* hidden with the rest of the client options in peer-to-peer shared key mode */
	$group = new Form_Group('Dynamic IP');
	$group->add(new Form_Checkbox(
		'dynamic_ip',
		'Dynamic IP',
		'Allow connected clients to retain their connections if their IP address changes.',
		$pconfig['dynamic_ip']
	));
	$group->addClass('advanced');
	$section->add($group);

	$group = new Form_Group('Topology');
	$group->add(new Form_Select(
		'topology',
		'Topology',
		$pconfig['topology'],
		$openvpn_topologies
	))->setHelp('How clients get a virtual adapter address in TUN mode on IPv4.%1$s' .
				'Some clients need "subnet" even for IPv6 (e.g. OpenVPN Connect for iOS/Android); very old clients or Yealink phones may need "net30".', '<br />');
	$group->addClass('advanced');
	$section->add($group);

	$form->add($section);

	/* ---------------------------------------------------- pushed client options */
	$section = new Form_Section('DNS, NTP and NetBIOS for clients', 'ovpn-clientadv', COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED));
	$section->addClass("clientadv");

	$section->addInput(new Form_Checkbox(
		'dns_domain_enable',
		'DNS default domain',
		'Provide a default domain name to clients',
		$pconfig['dns_domain_enable']
	));

	$section->addInput(new Form_Input(
		'dns_domain',
		'DNS default domain',
		'text',
		$pconfig['dns_domain']
	));

	$section->addInput(new Form_Checkbox(
		'dns_server_enable',
		'DNS servers',
		'Provide a DNS server list to clients. Addresses may be IPv4 or IPv6.',
		$pconfig['dns_server_enable']
	));

	$section->addInput(new Form_Input(
		'dns_server1',
		'DNS server 1',
		'text',
		$pconfig['dns_server1']
	));

	$section->addInput(new Form_Input(
		'dns_server2',
		'DNS server 2',
		'text',
		$pconfig['dns_server2']
	));

	$section->addInput(new Form_Input(
		'dns_server3',
		'DNS server 3',
		'text',
		$pconfig['dns_server3']
	));

	$section->addInput(new Form_Input(
		'dns_server4',
		'DNS server 4',
		'text',
		$pconfig['dns_server4']
	));

	$section->addInput(new Form_Checkbox(
		'push_blockoutsidedns',
		'Block outside DNS',
		'Make Windows 10 Clients Block access to DNS servers except across OpenVPN while connected, forcing clients to use only VPN DNS servers.',
		$pconfig['push_blockoutsidedns']
	))->setHelp('Requires Windows 10 and OpenVPN 2.3.9 or later. Other clients ignore it.');

	$section->addInput(new Form_Checkbox(
		'push_register_dns',
		'Force DNS cache update',
		'Run "net stop dnscache", "net start dnscache", "ipconfig /flushdns" and "ipconfig /registerdns" on connection initiation.',
		$pconfig['push_register_dns']
	))->setHelp('Helps Windows pick up pushed DNS servers.');

	$section->addInput(new Form_Checkbox(
		'ntp_server_enable',
		'NTP servers',
		'Provide an NTP server list to clients',
		$pconfig['ntp_server_enable']
	));

	$section->addInput(new Form_Input(
		'ntp_server1',
		'NTP server 1',
		'text',
		$pconfig['ntp_server1']
	));

	$section->addInput(new Form_Input(
		'ntp_server2',
		'NTP server 2',
		'text',
		$pconfig['ntp_server2']
	));

	$section->addInput(new Form_Checkbox(
		'netbios_enable',
		'NetBIOS',
		'Enable NetBIOS over TCP/IP',
		$pconfig['netbios_enable']
	))->setHelp('When off, all NetBIOS-over-TCP/IP options (including WINS) are disabled.');

	$section->addInput(new Form_Select(
		'netbios_ntype',
		'Node type',
		$pconfig['netbios_ntype'],
		$netbios_nodetypes
		))->setHelp('b-node (broadcasts), p-node (point-to-point name queries to a WINS server), ' .
					'm-node (broadcast then query name server), h-node (query name server, then broadcast).');

	$section->addInput(new Form_Input(
		'netbios_scope',
		'Scope ID',
		'text',
		$pconfig['netbios_scope']
	))->setHelp('Limits NetBIOS traffic on a network to nodes with the same scope ID.');

	$section->addInput(new Form_Checkbox(
		'wins_server_enable',
		'WINS servers',
		'Provide a WINS server list to clients',
		$pconfig['wins_server_enable']
	));

	$section->addInput(new Form_Input(
		'wins_server1',
		'WINS server 1',
		'text',
		$pconfig['wins_server1']
	));

	$section->addInput(new Form_Input(
		'wins_server2',
		'WINS server 2',
		'text',
		$pconfig['wins_server2']
	));

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

	/* ------------------------------------------------------------ ping (rare) */
	$section = new Form_Section('Ping and keepalive', 'ovpn-ping', COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED));

	$section->addInput(new Form_Input(
		'inactive_seconds',
		'Inactivity timeout',
		'number',
		$pconfig['inactive_seconds'] ?: 0,
		['min' => '0']
	))->setHelp('Close a client connection after this many seconds without tunnel traffic. 0 disables it.%1$s' .
		'Ignored in peer-to-peer shared key mode and in SSL/TLS mode with a blank or /30 tunnel network, where it would stop the server.', '<br />');

	$section->addInput(new Form_Select(
		'ping_method',
		'Ping method',
		$pconfig['ping_method'],
		$openvpn_ping_method
	))->setHelp('keepalive sets ping = interval and ping-restart = timeout × 2, and pushes ping = interval and ping-restart = timeout.');

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
	))->setHelp('Ping the remote over the control channel when no packets were sent for this many seconds.');

	$section->addInput(new Form_Checkbox(
		'ping_push',
		'Push ping to client',
		'Push ping to VPN client',
		$pconfig['ping_push']
	));

	$section->addInput(new Form_Select(
		'ping_action',
		'Ping restart or exit',
		$pconfig['ping_action'],
		$openvpn_ping_action
	))->setHelp('Exit or restart OpenVPN after a timeout from the remote.');

	$section->addInput(new Form_Input(
		'ping_action_seconds',
		'Ping restart or exit seconds',
		'number',
		$pconfig['ping_action_seconds']
		    ?: $openvpn_default_keepalive_timeout,
		['min' => '0']
	));

	$section->addInput(new Form_Checkbox(
		'ping_action_push',
		'Push to client',
		'Push ping-restart/ping-exit to VPN client',
		$pconfig['ping_action_push']
	));

	$form->add($section);

	/* ------------------------------------------------------------------- advanced */
	$section = new Form_Section('Advanced', 'ovpn-advanced', COLLAPSIBLE | ((!empty($input_errors) || !empty($pconfig['custom_options'])) ? SEC_OPEN : SEC_CLOSED));

	$custops = new Form_Textarea(
		'custom_options',
		'Custom options',
		$pconfig['custom_options']
	);
	if (!$user_can_edit_advanced) {
		$custops->setDisabled();
	}
	$section->addInput($custops)->setHelp('Additional OpenVPN server options, separated by semicolons.%1$s' .
				'Example: push "route 10.0.0.0 255.255.255.0"', '<br />');

	$section->addInput(new Form_Checkbox(
		'username_as_common_name',
		'Username as common name',
		'Use the authenticated client username instead of the certificate common name (CN).',
		$pconfig['username_as_common_name']
	))->setHelp('The username then replaces the certificate common name, e.g. to select client specific overrides.');

	$section->addInput(new Form_Checkbox(
		'udp_fast_io',
		'UDP fast I/O',
		'Use fast I/O operations with UDP writes to tun/tap. Experimental.',
		$pconfig['udp_fast_io']
	))->setHelp('Uses 5–10% less CPU. Not supported on every platform, and not compatible with bandwidth limiting.');

	$section->addInput(new Form_Select(
		'exit_notify',
		'Exit notify',
		$pconfig['exit_notify'],
		$openvpn_exit_notify_server
	))->setHelp('Tell connected clients when the server restarts or stops, so they reconnect (or move to the next server) without waiting for a timeout. ' .
		'Ignored in peer-to-peer shared key mode and in SSL/TLS mode with a blank or /30 tunnel network.');

	$section->addInput(new Form_Select(
		'sndrcvbuf',
		'Send/receive buffer',
		$pconfig['sndrcvbuf'],
		openvpn_get_buffer_values()
		))->setHelp('The default buffer is often too small for fast links. Start testing at 512 KiB and try higher and lower values.');

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

	$group->setHelp('Which gateways are created when this server is assigned as an interface. The default is both.');

	$section->add($group);

	$section->addInput(new Form_Select(
		'verbosity_level',
		'Verbosity level',
		$pconfig['verbosity_level'],
		$openvpn_verbosity_level
		))->setHelp('Each level includes the previous ones; 3 is a good summary.%1$s' .
					'None: fatal errors only. Default to 4: normal use. 5: an R/W character per packet. 6–11: debugging.', '<br />');

	$form->addGlobal(new Form_Input(
		'act',
		null,
		'hidden',
		$act
	));

	if ($this_server_config) {
		$form->addGlobal(new Form_Input(
			'id',
			null,
			'hidden',
			$id
		));
	}

	$form->add($section);
	fs_form_cancel($form, 'vpn_openvpn_server.php');
	print($form);

else:
	/* ------------------------------------------------------------------ list view */
	$servers = config_get_path('openvpn/openvpn-server', []);
	$counts = ['enabled' => 0, 'remote' => 0, 'p2p' => 0];
	$print_sk_warning = false;
	foreach ($servers as $server) {
		$counts['enabled'] += isset($server['disable']) ? 0 : 1;
		$counts[(substr($server['mode'], 0, 4) == 'p2p_') ? 'p2p' : 'remote']++;
		if ($server['mode'] == 'p2p_shared_key') {
			$print_sk_warning = true;
		}
	}

	if (!empty($servers)):
?>
<div class="fs-tiles">
<?php
	fs_tile(gettext('Servers'), count($servers));
	fs_tile(gettext('Enabled'), $counts['enabled'], null, (count($servers) - $counts['enabled']) ? sprintf(gettext('%d disabled'), count($servers) - $counts['enabled']) : null);
	fs_tile(gettext('Remote access'), $counts['remote']);
	fs_tile(gettext('Peer to peer'), $counts['p2p']);
?>
</div>
<?php endif; ?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('OpenVPN servers'),
	'search' => gettext('Search servers…'),
	'noun' => gettext('servers'),
	'noun_one' => gettext('server'),
	'filters' => [
		'kind' => [gettext('All modes'), 'remote' => gettext('Remote access'), 'p2p' => gettext('Peer to peer')],
		'state' => [gettext('All states'), 'enabled' => gettext('Enabled'), 'disabled' => gettext('Disabled')],
	],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status d-none d-sm-table-cell"><?=gettext("Status")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th data-fs-search><?=gettext("Mode")?></th>
					<th data-fs-search data-sortable-type="alpha"><?=gettext("Protocol / port")?></th>
					<th data-fs-search><?=gettext("Interface")?></th>
					<th data-fs-search><?=gettext("Tunnel network")?></th>
					<th data-fs-search><?=gettext("Crypto")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>

			<tbody>
<?php
	$i = 0;
	foreach ($servers as $server):
		$name = $server['description'] ?: sprintf(gettext('server %d'), $i + 1);
		$enabled = !isset($server['disable']);
		$kind = (substr($server['mode'], 0, 4) == 'p2p_') ? 'p2p' : 'remote';
		$mode = $server_mode_short[$server['mode']] ?? [$openvpn_server_modes[$server['mode']] ?? $server['mode'], ''];
		$dca = array_values(array_filter(explode(',', openvpn_build_data_cipher_list($server['data_ciphers'], $server['data_ciphers_fallback']))));
		$dc_more = array_slice($dca, 3);
		$badge = $enabled ? fs_badge('enabled') : fs_badge('disabled');
?>
				<tr data-fs-filter-kind="<?=$kind?>" data-fs-filter-state="<?=$enabled ? 'enabled' : 'disabled'?>"<?=$enabled ? '' : ' class="fs-row-disabled"'?>>
					<td class="d-none d-sm-table-cell"><?=$badge?></td>
					<td>
						<a href="vpn_openvpn_server.php?act=edit&amp;id=<?=$i?>"><strong><?=htmlspecialchars($server['description'] ?: sprintf(gettext('Server %d'), $i + 1))?></strong></a>
						<span class="fs-ovpn-sub fs-mono fs-muted">ovpns<?=htmlspecialchars($server['vpnid'])?></span>
						<div class="d-sm-none mt-1"><?=$badge?></div>
					</td>
					<td data-value="<?=htmlspecialchars($server['mode'])?>">
						<span class="fs-ovpn-mode" title="<?=htmlspecialchars($openvpn_server_modes[$server['mode']] ?? '')?>">
							<span class="fs-ovpn-kind<?=($kind == 'p2p') ? ' is-p2p' : ''?>"><?=htmlspecialchars($mode[0])?></span>
							<span class="fs-ovpn-sub fs-muted"><?=htmlspecialchars($mode[1])?></span>
						</span>
					</td>
					<td class="text-nowrap" data-value="<?=htmlspecialchars($server['local_port']) . '-' . htmlspecialchars($server['protocol'])?>">
						<span class="fs-mono"><?=htmlspecialchars($server['protocol'])?> / <?=htmlspecialchars($server['local_port'])?></span>
						<span class="fs-ovpn-sub fs-mono fs-muted"><?=htmlspecialchars(strtoupper(empty($server['dev_mode']) ? 'TUN' : $server['dev_mode']))?></span>
					</td>
					<td><?=htmlspecialchars(convert_openvpn_interface_to_friendly_descr($server['interface']))?></td>
					<td class="fs-mono">
<?php	if (empty($server['tunnel_network']) && empty($server['tunnel_networkv6'])): ?>
						<span class="fs-muted">—</span>
<?php	endif; ?>
<?php	foreach (array_filter([$server['tunnel_network'], $server['tunnel_networkv6']]) as $net): ?>
						<span class="fs-ovpn-line"><?=htmlspecialchars($net)?></span>
<?php	endforeach; ?>
					</td>
					<td>
						<div class="fs-chips">
<?php	foreach (array_slice($dca, 0, 3) as $cipher): ?>
							<span class="fs-chip fs-chip--mono fs-chip--muted"><?=htmlspecialchars($cipher)?></span>
<?php	endforeach; ?>
<?php	if (!empty($dc_more)): ?>
							<span class="fs-chip fs-chip--mono fs-chip--muted" title="<?=htmlspecialchars(implode(', ', $dc_more))?>">+<?=count($dc_more)?></span>
<?php	endif; ?>
<?php	if (!empty($server['digest'])): ?>
							<span class="fs-chip fs-chip--mono fs-chip--muted" title="<?=gettext('Auth digest')?>"><?=htmlspecialchars($server['digest'])?></span>
<?php	endif; ?>
<?php	if (!empty($server['dh_length']) && is_numeric($server['dh_length'])): ?>
							<span class="fs-chip fs-chip--mono fs-chip--muted" title="<?=gettext('D-H parameters')?>">DH <?=htmlspecialchars($server['dh_length'])?></span>
<?php	elseif (($server['dh_length'] ?? '') == "none"): ?>
							<span class="fs-chip fs-chip--mono fs-chip--muted" title="<?=gettext('D-H parameters')?>"><?=gettext('ECDH only')?></span>
<?php	endif; ?>
<?php	if (!empty($server['tls']) && ($server['mode'] != 'p2p_shared_key')): ?>
							<span class="fs-chip fs-chip--mono is-on"><?=(($server['tls_type'] ?? '') == 'crypt') ? gettext('TLS crypt') : gettext('TLS auth')?></span>
<?php	endif; ?>
						</div>
					</td>
					<td class="fs-col-actions">
<?=fs_row_actions([
							['edit', "vpn_openvpn_server.php?act=edit&id={$i}", $name],
							['custom', "vpn_openvpn_server.php?act=dup&id={$i}", $name, ['icon' => 'fa-regular fa-clone', 'post' => true,
							    'label' => sprintf(gettext('Copy %s'), $name)]],
							['delete', "vpn_openvpn_server.php?act=del&id={$i}", $name, ['thing' => gettext('server')]],
						])?>
					</td>
				</tr>
<?php
		$i++;
	endforeach;
?>
<?php if (empty($servers)) {
	fs_empty_row(8, gettext('No servers yet.'), 'vpn_openvpn_server.php?act=new', gettext('Add server'));
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

	function advanced_change(hide, mode) {
		if (!hide) {
			hideClass('advanced', false);
			hideClass("clientadv", false);
		} else if (mode == "p2p_tls") {
			hideClass('advanced', false);
			hideClass("clientadv", true);
		} else {
			hideClass('advanced', true);
			hideClass("clientadv", true);
		}
	}

	function mode_change() {
		value = $('#mode').val();

		hideCheckbox('autotls_enable', false);
		hideCheckbox('tlsauth_enable', false);
		hideInput('caref', false);
		hideInput('crlref', false);
		hideCheckbox('ocspcheck', false);
		hideLabel('Peer certificate revocation list', false);
		hideClass('sharedkeywarning', true);

		switch (value) {
			case "p2p_tls":
			case "server_tls":
			case "server_user":
				hideInput('tls', false);
				hideInput('certref', false);
				hideInput('dh_length', false);
				hideInput('ecdh_curve', false);
				hideInput('cert_depth', false);
				hideCheckbox('strictusercn', true);
				hideCheckbox('remote_cert_tls', false);
				hideCheckbox('autokey_enable', true);
				hideInput('shared_key', false);
				hideInput('topology', false);
				hideCheckbox('compression_push', false);
				hideCheckbox('duplicate_cn', false);
				hideInput('inactive_seconds', false);
			break;
			case "server_tls_user":
				hideInput('tls', false);
				hideInput('certref', false);
				hideInput('dh_length', false);
				hideInput('ecdh_curve', false);
				hideInput('cert_depth', false);
				hideCheckbox('strictusercn', false);
				hideCheckbox('remote_cert_tls', false);
				hideCheckbox('autokey_enable', true);
				hideInput('shared_key', true);
				hideInput('topology', false);
				hideCheckbox('compression_push', false);
				hideCheckbox('duplicate_cn', false);
				hideInput('inactive_seconds', false);
			break;
			case "p2p_shared_key":
				hideClass('sharedkeywarning', false);
				hideInput('tls', true);
				hideInput('caref', true);
				hideInput('crlref', true);
				hideLabel('Peer certificate revocation list', true);
				hideLabel('Peer certificate authority', true);
				hideInput('certref', true);
				hideCheckbox('tlsauth_enable', true);
				hideInput('dh_length', true);
				hideInput('ecdh_curve', true);
				hideInput('cert_depth', true);
				hideCheckbox('strictusercn', true);
				hideCheckbox('remote_cert_tls', true);
				hideCheckbox('autokey_enable', true);
				hideInput('shared_key', false);
				hideInput('topology', true);
				hideCheckbox('compression_push', true);
				hideCheckbox('duplicate_cn', true);
				hideCheckbox('ocspcheck', true);
				hideInput('inactive_seconds', true);
			break;
		}

		switch (value) {
			case "p2p_shared_key":
				advanced_change(true, value);
				hideInput('remote_network', false);
				hideInput('remote_networkv6', false);
				hideCheckbox('gwredir', true);
				hideCheckbox('gwredir6', true);
				hideInput('local_network', true);
				hideInput('local_networkv6', true);
				hideMultiClass('authmode', true);
				hideCheckbox('client2client', true);
				hideCheckbox('autokey_enable', false);
				hideCheckbox('username_as_common_name', true);
				hideInput('exit_notify', true);
			break;
			case "p2p_tls":
				advanced_change(true, value);
				hideInput('remote_network', false);
				hideInput('remote_networkv6', false);
				hideCheckbox('gwredir', false);
				hideCheckbox('gwredir6', false);
				hideInput('local_network', false);
				hideInput('local_networkv6', false);
				hideMultiClass('authmode', true);
				hideCheckbox('client2client', false);
				hideCheckbox('username_as_common_name', true);
				hideInput('exit_notify', false);
			break;
			case "server_user":
			case "server_tls_user":
				advanced_change(false, value);
				hideInput('remote_network', true);
				hideInput('remote_networkv6', true);
				hideCheckbox('gwredir', false);
				hideCheckbox('gwredir6', false);
				hideInput('local_network', false);
				hideInput('local_networkv6', false);
				hideMultiClass('authmode', false);
				hideCheckbox('client2client', false);
				hideCheckbox('autokey_enable', true);
				hideCheckbox('username_as_common_name', false);
				hideInput('exit_notify', false);
			break;
			case "server_tls":
				hideMultiClass('authmode', true);
				advanced_change(false, value);
				hideCheckbox('autokey_enable', true);
			default:
				hideInput('custom_options', false);
				hideInput('verbosity_level', false);
				hideInput('remote_network', true);
				hideInput('remote_networkv6', true);
				hideCheckbox('gwredir', false);
				hideCheckbox('gwredir6', false);
				hideInput('local_network', false);
				hideInput('local_networkv6', false);
				hideCheckbox('client2client', false);
				hideCheckbox('username_as_common_name', true);
				hideInput('exit_notify', false);
			break;
		}

		gwredir_change();
		gwredir6_change();
		tlsauth_change();
		autokey_change();
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

	// Process "Enable authentication of TLS packets" checkbox
	function tlsauth_change() {
		autotls_change();
	}

	// Process "Automatically generate a shared TLS authentication key" checkbox
	// Hide 'autotls_enable' AND 'tls' if mode == p2p_shared_key
	// Otherwise hide 'tls' based on state of 'autotls_enable'
	function autotls_change() {
		if (($('#mode').val() == 'p2p_shared_key') || (!$('#tlsauth_enable').prop('checked'))) {
			hideInput('tls', true);
			hideInput('tls_type', true);
			hideInput('tlsauth_keydir', true);
			hideInput('autotls_enable', true);
		} else {
			hideInput('autotls_enable', false);
			hideInput('tls', $('#autotls_enable').prop('checked') || !$('#tlsauth_enable').prop('checked'));
			hideInput('tls_type', $('#autotls_enable').prop('checked') || !$('#tlsauth_enable').prop('checked'));
			hideInput('tlsauth_keydir', $('#autotls_enable').prop('checked') || !$('#tlsauth_enable').prop('checked'));
		}
	}

	function autokey_change() {
		var hide  = $('#autokey_enable').prop('checked')

		if ($('#mode').val() != 'p2p_shared_key') {
			hideCheckbox('autokey_enable', true);
			hideInput('shared_key', true);
		} else {
			hideInput('shared_key', hide);
			hideCheckbox('autokey_enable', false);
		}


	}

	function gwredir_change() {
		var hide = $('#gwredir').prop('checked')

		hideInput('local_network', hide);
//		hideInput('remote_network', hide);
	}

	function gwredir6_change() {
		var hide = $('#gwredir6').prop('checked')

		hideInput('local_networkv6', hide);
//		hideInput('remote_networkv6', hide);
	}

	function dns_domain_change() {
		var hide  = ! $('#dns_domain_enable').prop('checked')

		hideInput('dns_domain', hide);
	}

	function dns_server_change() {
		var hide  = ! $('#dns_server_enable').prop('checked')

		hideInput('dns_server1', hide);
		hideInput('dns_server2', hide);
		hideInput('dns_server3', hide);
		hideInput('dns_server4', hide);
	}

	function wins_server_change() {
		var hide  = (! $('#wins_server_enable').prop('checked') || ! $('#netbios_enable').prop('checked'))

		hideInput('wins_server1', hide);
		hideInput('wins_server2', hide);
	}

	function nbdd_server_change() {
		hideClass('nbddservers', ! $('#nbdd_server_enable').prop('checked') || ! $('#netbios_enable').prop('checked'));
	}

	function ntp_server_change() {
		var hide  = ! $('#ntp_server_enable').prop('checked')

		hideInput('ntp_server1', hide);
		hideInput('ntp_server2', hide);
	}

	function netbios_change() {
		var hide  = ! $('#netbios_enable').prop('checked')

		hideInput('netbios_ntype', hide);
		hideInput('netbios_scope', hide);
		hideCheckbox('wins_server_enable', hide);
		wins_server_change();
		hideCheckbox('nbdd_server_enable', hide);
		nbdd_server_change();
	}

	function tuntap_change() {

		mvalue = $('#mode').val();

		switch (mvalue) {
			case "p2p_shared_key":
				sharedkey = true;
				p2p = true;
				break;
			case "p2p_tls":
				sharedkey = false;
				p2p = true;
				break;
			default:
				sharedkey = false;
				p2p = false;
				break;
		}

		value = $('#dev_mode').val();

		switch (value) {
			case "tun":
				hideInput('tunnel_network', false);
				hideInput('tunnel_networkv6', false);
				hideCheckbox('serverbridge_dhcp', true);
				hideInput('serverbridge_interface', true);
				hideCheckbox('serverbridge_routegateway', true);
				hideInput('serverbridge_dhcp_start', true);
				hideInput('serverbridge_dhcp_end', true);
				if (sharedkey) {
					hideInput('local_network', true);
					hideInput('local_networkv6', true);
					hideInput('topology', true);
				} else {
					// For tunnel mode that is not shared key,
					// the display status of local network fields depends on
					// the state of the gwredir checkbox.
					gwredir_change();
					gwredir6_change();
					hideInput('topology', false);
				}
				break;

			case "tap":
				hideInput('tunnel_network', false);

				if (!p2p) {
					hideCheckbox('serverbridge_dhcp', false);
					disableInput('serverbridge_dhcp', false);
					hideInput('serverbridge_interface', false);
					hideCheckbox('serverbridge_routegateway', false);
					hideInput('serverbridge_dhcp_start', false);
					hideInput('serverbridge_dhcp_end', false);
					hideInput('topology', true);

					if ($('#serverbridge_dhcp').prop('checked')) {
						disableInput('serverbridge_interface', false);
						hideCheckbox('serverbridge_routegateway', false);
						disableInput('serverbridge_dhcp_start', false);
						disableInput('serverbridge_dhcp_end', false);
					} else {
						disableInput('serverbridge_interface', true);
						hideCheckbox('serverbridge_routegateway', true);
						disableInput('serverbridge_dhcp_start', true);
						disableInput('serverbridge_dhcp_end', true);
					}
				} else {
					hideInput('topology', true);
					disableInput('serverbridge_dhcp', true);
					disableInput('serverbridge_interface', true);
					hideCheckbox('serverbridge_routegateway', true);
					disableInput('serverbridge_dhcp_start', true);
					disableInput('serverbridge_dhcp_end', true);
				}

				break;
		}
	}

	function ping_method_change() {
		pvalue = $('#ping_method').val();

		keepalive = (pvalue == 'keepalive');

		hideInput('keepalive_interval', !keepalive);
		hideInput('keepalive_timeout', !keepalive);
		hideInput('ping_seconds', keepalive);
		hideCheckbox('ping_push', keepalive);
		hideInput('ping_action', keepalive);
		hideInput('ping_action_seconds', keepalive);
		hideCheckbox('ping_action_push', keepalive);
	}

	function ocspcheck_change() {
		var hide  = ! $('#ocspcheck').prop('checked')

		hideInput('ocspurl', hide);
	}

	function allow_compression_change() {
		var hide  = ($('#allow_compression').val() == 'no')
		hideInput('compression', hide);
		hideCheckbox('compression_push', hide);
	}

	function duplicate_cn_change() {
		var hide  = ! $('#duplicate_cn').prop('checked');

		hideInput('connlimit', hide);
	}

	function update_track6_prefix() {
		var iface = $("#tunnel_track6_interface").val();
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


	function ipv6_type_change() {
		var hide = ($('#tunnel_networkv6_type').val() == 'track6')
		hideInput('tunnel_networkv6', hide);
		hideInput('tunnel_track6_interface', !hide);
		hideInput('tunnel_track6_prefix_id', !hide);
		update_track6_prefix();
	}

	// ---------- Monitor elements for change and call the appropriate display functions ------------------------------

	// NTP
	$('#ntp_server_enable').click(function () {
		ntp_server_change();
	});

	// Netbios
	$('#netbios_enable').click(function () {
		netbios_change();
	});

	 // Wins server port
	$('#wins_server_enable').click(function () {
		wins_server_change();
	});

	// NBDD server
	$('#nbdd_server_enable').click(function () {
		nbdd_server_change();
	});

	 // DNS server port
	$('#dns_server_enable').click(function () {
		dns_server_change();
	});

	 // DNS server port
	$('#dns_domain_enable').click(function () {
		dns_domain_change();
	});

	 // Gateway redirect
	$('#gwredir').click(function () {
		gwredir_change();
	});

	 // Gateway redirect IPv6
	$('#gwredir6').click(function () {
		gwredir6_change();
	});

	 // Auto TLSkey generation
	$('#autotls_enable').click(function () {
		autotls_change();
	});

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
		tuntap_change();
		protocol_change();
	});

	// Protocol
	$('#protocol').change(function () {
		protocol_change();
	});

	 // Tun/tap mode
	$('#dev_mode, #serverbridge_dhcp').change(function () {
		tuntap_change();
	});

	// ping
	$('#ping_method').change(function () {
		ping_method_change();
	});

	// OCSP
	$('#ocspcheck').click(function () {
		ocspcheck_change();
	});

	// Duplicate Connection
	$('#duplicate_cn').click(function () {
		duplicate_cn_change();
	});

	// Certref
	$('#certref').on('change', function() {
		var errmsg = "";

		if ($(this).find(":selected").index() >= "<?=$servercerts?>") {
			var errmsg = '<span class="text-danger">' + "<?=gettext('Warning: The selected server certificate was not created as an SSL/TLS Server certificate and may not work as expected')?>" + '</span>';
		}

		$('#certtype').html(errmsg);
	});

	// Compression Settings
	$('#allow_compression').change(function () {
		allow_compression_change();
	});

	// IPv6 Tunnel Type
	$('#tunnel_networkv6_type').change(function () {
		ipv6_type_change();
	});

	$('#tunnel_track6_interface').on('change', function() {
		update_track6_prefix();
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
	gwredir_change();
	gwredir6_change();
	dns_domain_change();
	dns_server_change();
	wins_server_change();
	nbdd_server_change();
	ntp_server_change();
	netbios_change();
	tuntap_change();
	ping_method_change();
	ocspcheck_change();
	allow_compression_change();
	duplicate_cn_change();
	ipv6_type_change();

	// A card whose fields are all hidden by the mode (e.g. certificate checks in
	// shared key mode) is hidden too; collapsed cards still count as visible.
	function empty_sections() {
		$('form .panel').each(function() {
			var panel = this;
			var shown = $(panel).find('.form-group').filter(function() {
				for (var el = this; el && el !== panel; el = el.parentElement) {
					if ($(el).hasClass('hidden') || el.style.display === 'none') {
						return false;
					}
				}
				return true;
			});
			$(panel).toggleClass('fs-ovpn-empty', shown.length === 0);
		});
	}

	$('form').on('change click', 'input, select', function() {
		setTimeout(empty_sections, 0);
	});
	empty_sections();
});
//]]>
</script>
<?php

include("foot.inc");
