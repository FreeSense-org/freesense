<?php
/*
 * tabs/services.inc
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

/* Tab groups for the Services menu (loaded by fs_tab_groups(); labels are plain text). */

$fs_tab_groups['services-dyndns'] = [
	[gettext('Dynamic DNS Clients'), 'services_dyndns.php'],
	[gettext('RFC 2136 Clients'), 'services_rfc2136.php'],
	[gettext('Check IP Services'), 'services_checkip.php'],
];

/* per zone: pages pass ['zone' => $cpzone] */
$fs_tab_groups['services-captiveportal'] = [
	[gettext('Configuration'), 'services_captiveportal.php'],
	[gettext('MACs'), 'services_captiveportal_mac.php'],
	[gettext('Allowed IP Addresses'), 'services_captiveportal_ip.php'],
	[gettext('Allowed Hostnames'), 'services_captiveportal_hostname.php'],
	[gettext('Vouchers'), 'services_captiveportal_vouchers.php'],
	[gettext('High Availability'), 'services_captiveportal_hasync.php'],
	[gettext('File Manager'), 'services_captiveportal_filemanager.php'],
];

/* DNS Forwarder: the overrides are views of services_dnsmasq.php (rule R1) */
$fs_tab_groups['services-dnsforwarder'] = [
	[gettext('General Settings'), 'services_dnsmasq.php'],
	[gettext('Host Overrides'), 'services_dnsmasq.php?view=hosts'],
	[gettext('Domain Overrides'), 'services_dnsmasq.php?view=domains'],
];

/* DNS Resolver: the overrides are views of services_unbound.php (rule R1) */
$fs_tab_groups['services-dnsresolver'] = [
	[gettext('General Settings'), 'services_unbound.php'],
	[gettext('Host Overrides'), 'services_unbound.php?view=hosts'],
	[gettext('Domain Overrides'), 'services_unbound.php?view=domains'],
	[gettext('Access Lists'), 'services_unbound_acls.php'],
	[gettext('Advanced Settings'), 'services_unbound_advanced.php'],
];

$fs_tab_groups['services-ntp'] = [
	[gettext('Settings'), 'services_ntpd.php'],
	[gettext('ACLs'), 'services_ntpd_acls.php'],
	[gettext('Serial GPS'), 'services_ntpd_gps.php'],
	[gettext('PPS'), 'services_ntpd_pps.php'],
];
