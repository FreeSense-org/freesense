<?php
/*
 * tabs/logs.inc
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

/*
 * Tab group for Status > System Logs (loaded by fs_tab_groups(); labels are plain text).
 * tab_array_logs_common() in status_logs_common.inc picks the active tab and renders the
 * second level (General / Gateways / ..., Normal / Dynamic / Summary) as a view switch.
 */

$fs_tab_groups['status-logs'] = [
	[gettext('System'), 'status_logs.php'],
	[gettext('Firewall'), 'status_logs_filter.php'],
	[gettext('DHCP'), 'status_logs.php?logfile=dhcpd'],
	[gettext('Authentication'), 'status_logs.php?logfile=auth'],
	[gettext('IPsec'), 'status_logs.php?logfile=ipsec'],
	[gettext('PPP'), 'status_logs.php?logfile=ppp'],
	[gettext('PPPoE/L2TP Server'), 'status_logs_vpn.php'],
	[gettext('OpenVPN'), 'status_logs.php?logfile=openvpn'],
	[gettext('NTP'), 'status_logs.php?logfile=ntpd'],
	[gettext('Packages'), 'status_logs_packages.php'],
	[gettext('Settings'), 'status_logs_settings.php'],
];
