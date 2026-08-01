<?php
/*
 * freesense-docs.inc
 *
 * Edition-aware links from the WebUI to the FreeSense documentation site.
 */

function freesense_docs_edition_prefix($version) {
	return preg_match('/^1\.1(?:\.|$)/', (string)$version) ? '/1.1' : '';
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
		'pkg_mgr_settings' => '/packages/installing-packages/',
		'system_packages' => '/packages/installing-packages/',
		'system_update' => '/guides/updates-and-channels/',
		'system_bootenvironments' => '/operations/zfs-boot-environments/',
		'wireguard' => '/packages/wireguard/',
		'crowdsec' => '/packages/security-and-filtering/',
		'suricata' => '/packages/security-and-filtering/',
		'pfblockerng' => '/packages/security-and-filtering/',
		'webgateway' => '/packages/web-gateway/',
		'webgateway-av' => '/packages/web-gateway/',
		'openvpn-client-export' => '/packages/vpn-and-private-networking/',
	);
	if (isset($exact[$key])) {
		return $exact[$key];
	}

	$routes = array(
		'/^(interfaces|vlan|bridge|lagg|wireless|assign)/' => '/base-os/interfaces-and-networking/',
		'/^(firewall|alias|nat|virtual_ip|shaper)/' => '/base-os/firewall-and-nat/',
		'/^(gateway|route|static_routes|multiwan|carp)/' => '/base-os/routing-and-high-availability/',
		'/^(services|dhcp|dns|unbound|dnsmasq|ntp|captiveportal)/' => '/base-os/built-in-services/',
		'/^(vpn|openvpn|ipsec|pptp|l2tp)/' => '/base-os/built-in-vpn/',
		'/^(status|logs|graph|rrd)/' => '/operations/monitoring-and-logs/',
		'/^(diag|diagnostics)/' => '/operations/diagnostics-and-troubleshooting/',
		'/^(pkg|package|acme|freeradius|frr|haproxy|bind|avahi|tftpd|mdns|mcast|udpbroadcast)/' => '/packages/catalog/',
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
