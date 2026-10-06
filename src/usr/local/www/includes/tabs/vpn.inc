<?php
/*
 * tabs/vpn.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
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

/* Tab groups for the VPN menu (loaded by fs_tab_groups(); labels are plain text). */

$fs_tab_groups['vpn-ipsec'] = [
	[gettext('Tunnels'), 'vpn_ipsec.php'],
	[gettext('Mobile Clients'), 'vpn_ipsec_mobile.php'],
	[gettext('Pre-Shared Keys'), 'vpn_ipsec_keys.php'],
	[gettext('Advanced Settings'), 'vpn_ipsec_settings.php'],
];

$fs_tab_groups['vpn-openvpn'] = ['package_group' => 'OpenVPN', 'tabs' => [
	[gettext('Servers'), 'vpn_openvpn_server.php'],
	[gettext('Clients'), 'vpn_openvpn_client.php'],
	[gettext('Client Specific Overrides'), 'vpn_openvpn_csc.php'],
	[gettext('Wizards'), 'wizard.php?xml=openvpn_wizard.xml'],
]];

$fs_tab_groups['vpn-l2tp'] = [
	[gettext('Configuration'), 'vpn_l2tp.php'],
	[gettext('Users'), 'vpn_l2tp_users.php'],
];
