<?php
/*
 * restapi/areas.inc
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
 * REST API permission areas. Every endpoint belongs to one area and has an
 * action, read or write, giving a scope such as "firewall.aliases:write".
 *
 * A user holds a scope when either
 *   - the user may open the GUI page the endpoint mirrors (GUI access
 *     implies API access to the same thing), or
 *   - the user holds the API privilege for the area and action,
 *     "api-<area>-<action>" (write implies read). These privileges grant
 *     no GUI access, so they build API-only service accounts.
 * A key may further be limited to a list of scopes.
 *
 * Kept free of other includes: /etc/inc/priv/restapi.priv.inc loads it.
 */

function restapi_areas() {
	return array(
		'system' => 'System information',
		'status' => 'Status (interfaces, gateways, services, ARP, DHCP leases)',
		'config' => 'Configuration history and revert',
		'backup' => 'Remote backups',
		'firewall.aliases' => 'Firewall aliases',
		'firewall.rules' => 'Firewall rules',
		'firewall.nat' => 'Firewall NAT (port forward, 1:1, outbound, NPt)',
		'firewall.schedules' => 'Firewall schedules',
		'firewall.vips' => 'Firewall virtual IPs',
		'routing' => 'Gateways and static routes',
		'interfaces' => 'Interfaces (VLAN, VXLAN, GIF, GRE, LAGG, QinQ, bridges, groups, assignments)',
		/*
		 * Services, one area per family. services.dns also covers the DNS
		 * Resolver and services.dhcp the DHCP servers as they are added.
		 */
		'services.dns' => 'DNS services (DNS Forwarder, DNS Resolver)',
		'services.misc' => 'Other services (UPnP IGD & PCP, Wake-on-LAN, IGMP Proxy, SNMP, DHCP/DHCPv6 Relay)',
		'services.time' => 'NTP server (settings and access restrictions)',
		'services.ddns' => 'Dynamic DNS (Dynamic DNS and RFC 2136 clients)',
		'services.dhcp' => 'DHCP and DHCPv6 servers, static mappings and router advertisements',
		/* VPNs, one area per kind. */
		'vpn.ipsec' => 'IPsec (tunnels, pre-shared keys, status)',
		'vpn.l2tp' => 'L2TP VPN server and users',
		'vpn.openvpn' => 'OpenVPN servers, clients and client-specific overrides',
		'pki' => 'Certificates, certificate authorities and revocation lists',
		'users' => 'Users, groups, privileges and authentication servers',
		'system.settings' => 'System settings (general setup, high availability, update settings, admin access, advanced firewall, networking, miscellaneous, notifications, tunables)',
		'logs' => 'System logs (read)',
		'packages' => 'Packages and system version (lists, update check, install, reinstall, remove, system update)',
		'diagnostics' => 'Diagnostics (ping, traceroute, DNS lookup, states)',
		'operations' => 'System operations (service start/stop/restart, state table reset, reboot, halt)',
	);
}

function restapi_actions() {
	return array('read', 'write');
}

/* "firewall.aliases" + "write" => "api-firewall-aliases-write" */
function restapi_area_privilege($area, $action) {
	return 'api-' . str_replace('.', '-', $area) . '-' . $action;
}

function restapi_scope($area, $action) {
	return "{$area}:{$action}";
}

/* Every valid scope string. */
function restapi_all_scopes() {
	$scopes = array();
	foreach (array_keys(restapi_areas()) as $area) {
		foreach (restapi_actions() as $action) {
			$scopes[] = restapi_scope($area, $action);
		}
	}
	return $scopes;
}

/* Parse a stored scope list; unknown entries are dropped. */
function restapi_parse_scopes($text) {
	$valid = array_flip(restapi_all_scopes());
	$out = array();
	foreach (preg_split('/[\s,]+/', trim((string)$text)) as $scope) {
		if ($scope !== '' && isset($valid[$scope])) {
			$out[$scope] = true;
		}
	}
	return array_keys($out);
}

/*
 * True when a key limited to $scopes may perform $area:$action. An empty
 * list means the key is not limited. A write scope also allows reading.
 */
function restapi_scopes_allow(array $scopes, $area, $action) {
	if (empty($scopes)) {
		return true;
	}
	if (in_array(restapi_scope($area, $action), $scopes, true)) {
		return true;
	}
	return ($action === 'read') && in_array(restapi_scope($area, 'write'), $scopes, true);
}
