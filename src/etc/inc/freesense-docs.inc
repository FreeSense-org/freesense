<?php
/*
 * freesense-docs.inc
 *
 * Edition-aware links from the WebUI to the FreeSense documentation site.
 */

function freesense_docs_edition_prefix($version) {
	/* The current Stable release is served at the docs root. */
	if (preg_match('/^1\.0(?:\.|$)/', (string)$version)) {
		return '/1.0';
	}
	if (preg_match('/^1\.2(?:\.|$)/', (string)$version)) {
		return '/1.2';
	}
	return '';
}

function freesense_docs_topic_path($page) {
	$key = rawurldecode((string)$page);
	$key = str_replace(array('%', ':', '..', '\\'), '', $key);
	$key = trim($key, '/');
	$key = preg_replace('/\.(php|xml)(?=-|$)/i', '', $key);
	$key = strtolower($key);

	$exact = array(
		'' => '/',
		'index' => '/',
		'pkg_mgr_install' => '/packages/installing-packages/',
		'pkg_mgr_installed' => '/packages/installing-packages/',
		'pkg_mgr_repos' => '/packages/custom-repositories/',
		'pkg_mgr_repos_edit' => '/packages/custom-repositories/',
		'pkg_mgr_settings' => '/packages/installing-packages/',
		'system_packages' => '/packages/installing-packages/',
		'system_update' => '/guides/updates-and-channels/',
		'system_bootenvironments' => '/operations/zfs-boot-environments/',
		'wireguard' => '/packages/wireguard/',
		'webgateway-av' => '/packages/web-gateway/',
		'openvpn-client-export' => '/packages/openvpn-client-export/',
		'vpn_openvpn_export' => '/packages/openvpn-client-export/',
		'diag_backup' => '/configure/access-and-recovery/',
		'network_tools' => '/packages/diagnostics/',
		'interfaces_vxlan' => '/configure/interfaces-vlans-bridges/#vxlan',
		'interfaces_vxlan_edit' => '/configure/interfaces-vlans-bridges/#vxlan',
	);
	if (isset($exact[$key])) {
		return $exact[$key];
	}

	$routes = array(
		/* Optional Packages: each package has its own guide. These run before
		 * the base-system prefixes so status_<package> pages match here. */
		'/^threatshield/' => '/packages/threat-shield/',
		'/^webgateway/' => '/packages/web-gateway/',
		'/^suricata/' => '/packages/suricata/',
		'/^crowdsec/' => '/packages/crowdsec/',
		'/^acme/' => '/packages/acme/',
		'/^(wireguard|vpn_wg_|status_wireguard)/' => '/packages/wireguard/',
		'/^(tailscale|status_tailscale)/' => '/packages/tailscale/',
		'/^zerotier/' => '/packages/zerotier/',
		'/^(frr|status_frr)/' => '/packages/frr/',
		'/^haproxy/' => '/packages/haproxy/',
		'/^bind/' => '/packages/bind/',
		'/^freeradius/' => '/packages/freeradius/',
		'/^avahi/' => '/packages/avahi/',
		'/^mdns[-_]bridge/' => '/packages/mdns-bridge/',
		'/^mcast_bridge/' => '/packages/mcast-bridge/',
		'/^udpbroadcastrelay/' => '/packages/udpbroadcastrelay/',
		'/^tftp/' => '/packages/tftpd/',
		'/^automation/' => '/packages/automation/',
		'/^ntopng/' => '/packages/ntopng/',
		'/^telegraf/' => '/packages/telegraf/',
		'/^node_exporter/' => '/packages/node-exporter/',
		'/^zabbix/' => '/packages/zabbix/',
		'/^(net-snmp|netsnmp)/' => '/packages/net-snmp/',
		'/^syslog-?ng/' => '/packages/syslog-ng/',
		'/^softflowd/' => '/packages/softflowd/',
		'/^(status_traffic_totals|vnstat)/' => '/packages/traffic-totals/',
		'/^(andwatch|status_andwatch)/' => '/packages/andwatch/',
		'/^lldpd/' => '/packages/lldpd/',
		'/^nut(_|$)/' => '/packages/nut/',
		'/^diagnostics$/' => '/packages/diagnostics/',
		'/^open-vm-tools/' => '/packages/open-vm-tools/',
		'/^(system|status)_restapi/' => '/api/overview/',
		'/^system_(gateways?|routing|staticroutes)/' => '/base-os/routing-and-high-availability/',
		'/^(interfaces|vlan|bridge|lagg|wireless|assign)/' => '/base-os/interfaces-and-networking/',
		'/^(firewall|alias|nat|virtual_ip|shaper)/' => '/base-os/firewall-and-nat/',
		'/^(gateway|route|static_routes|multiwan|carp)/' => '/base-os/routing-and-high-availability/',
		'/^(services|dhcp|dns|unbound|dnsmasq|ntp|captiveportal)/' => '/base-os/built-in-services/',
		'/^(vpn|openvpn|ipsec|pptp|l2tp)/' => '/base-os/built-in-vpn/',
		'/^(status|logs|graph|rrd)/' => '/operations/monitoring-and-logs/',
		'/^(diag|diagnostics)/' => '/operations/diagnostics-and-troubleshooting/',
		'/^(pkg|package)/' => '/packages/catalog/',
		'/^(system|user|auth|cert|ca|backup|config|wizard)/' => '/base-os/system-administration/',
	);
	foreach ($routes as $pattern => $path) {
		if (preg_match($pattern, $key)) {
			return $path;
		}
	}

	/* Every screen retains a useful, direct reference destination while its
	 * page-specific map entry is being expanded. */
	return '/reference/menu-guide/';
}

function freesense_docs_url($page, $version) {
	return 'https://docs.freesense.org' . freesense_docs_edition_prefix($version) . freesense_docs_topic_path($page);
}
?>
