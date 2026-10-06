<?php
/*
 * services_dhcp.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

require_once('config.gui.inc');
require_once('util.inc');
require_once('interfaces.inc');
require_once('freesense-utils.inc');

/* Kea logger severities offered in the GUI (#16230); WARN is the default. */
function kea_log_levels(): array {
	return [
		'FATAL' => gettext('Fatal'),
		'ERROR' => gettext('Error'),
		'WARN' => gettext('Warning (default)'),
		'INFO' => gettext('Informational'),
		'DEBUG' => gettext('Debug'),
	];
}

function kea_defaults(string $key): int|string {
	/* throws an exception if $key isn't matched */
	return (match($key) {
		'fallback'					=> config_get_path('system/domain', 'unknown.home.arpa'),
		'heartbeatdelay'			=> 10000,
		'listenport'				=> 8765,
		'maxackdelay'				=> 60000,
		'maxrejectedleaseupdates'	=> 10,
		'maxresponsedelay' 			=> 60000,
		'maxunackedclients' 		=> 0,
		'name' 						=> config_get_path('system/hostname', g_get('product_name'))
	});
}

function kea_earlydnsreg_enabled(array $dhcpdconf, array $mapent): array|false {
	$ret = false;
	if (!($mapent['hostname'] && $mapent['ipaddr'])) {
		goto done;
	}

	$policies = [];
	$policies[] = config_path_enabled('kea', 'earlydnsreg') ? 'enable' : 'disable';
	$policies[] = array_get_path($dhcpdconf, 'earlydnsregpolicy', 'default');
	$policies[] = array_get_path($mapent, 'earlydnsregpolicy', 'default');

	$domain = kea_defaults('fallback');
	if ($dhcpdconf['domain']) {
		$domain = $dhcpdconf['domain'];
	}
	if ($mapent['domain']) {
		$domain = $mapent['domain'];
	}

	$name = trim($mapent['hostname']);
	$domain = trim($domain);
	$fqdn = "{$name}.{$domain}";
	$ipaddr = trim($mapent['ipaddr']);

	foreach (array_reverse($policies) as $policy) {
		switch ($policy) {
			case 'enable':
				$ret = [
					'name'		=> $name,
					'domain'	=> $domain,
					'fqdn'		=> $fqdn,
					'ipaddr'	=> $ipaddr,
				];
			case 'disable':
				break 2;
			default:
				/* 'default' defers to the next, broader policy. */
				continue 2;
		}
	}

done:
	return ($ret);
}

function kea6_earlydnsreg_enabled(array $dhcpdconf, array $mapent): array|false {
	$ret = false;
	if (!($mapent['hostname'] && $mapent['ipaddrv6'])) {
		goto done;
	}

	$policies = [];
	$policies[] = config_path_enabled('kea6', 'earlydnsreg') ? 'enable' : 'disable';
	$policies[] = array_get_path($dhcpdconf, 'earlydnsregpolicy', 'default');
	$policies[] = array_get_path($mapent, 'earlydnsregpolicy', 'default');

	$domain = kea_defaults('fallback');
	if ($dhcpdconf['domain']) {
		$domain = $dhcpdconf['domain'];
	}
	if ($mapent['domain']) {
		$domain = $mapent['domain'];
	}

	$name = trim($mapent['hostname']);
	$domain = trim($domain);
	$fqdn = "{$name}.{$domain}";
	$ipaddr = trim($mapent['ipaddrv6']);

	foreach (array_reverse($policies) as $policy) {
		switch ($policy) {
			case 'enable':
				$ret = [
					'name'		=> $name,
					'domain'	=> $domain,
					'fqdn'		=> $fqdn,
					'ipaddr'	=> $ipaddr,
				];
			case 'disable':
				break 2;
			default:
				/* 'default' defers to the next, broader policy. */
				continue 2;
		}
	}

done:
	return ($ret);
}

function dhcp_static_mapping_icons(array $dhcpdconf, array $mapent): string {
	$icons = [];

	if (dhcp_is_backend('kea') &&
	    kea_earlydnsreg_enabled($dhcpdconf, $mapent)) {
		$icons[] = [ 'fa-solid fa-globe', gettext('Early DNS Registration Enabled') ];
	}

	if (isset($mapent['arp_table_static_entry'])) {
		$icons[] = [ 'fa-solid fa-lock', gettext('Static ARP Enabled') ];
	}

	return (implode(' ', (array_map(function($v) {
		return (sprintf('<i class="%s" title="%s" />', $v[0], $v[1]));
	}, $icons))));
}

function dhcp6_static_mapping_icons(array $dhcpdconf, array $mapent): string {
	$icons = [];

	if (dhcp_is_backend('kea') &&
	    kea6_earlydnsreg_enabled($dhcpdconf, $mapent)) {
		$icons[] = [ 'fa-solid fa-globe', gettext('Early DNS Registration Enabled') ];
	}

	return (implode(' ', (array_map(function($v) {
		return (sprintf('<i class="%s" title="%s" />', $v[0], $v[1]));
	}, $icons))));
}

function kea_earlydnsreg_mappings(): array {
	$mappings = [];
	foreach ((array)config_get_path('dhcpd', []) as $if => $conf) {
		/* an interface left without settings (e.g. its last static mapping deleted) reads as '' */
		if (!is_array($conf) || !is_array(array_get_path($conf, 'staticmap'))) {
			continue;
		}
		foreach (array_get_path($conf, 'staticmap', []) as $static) {
			if (is_array($static) && ($mapping = kea_earlydnsreg_enabled($conf, $static))) {
				$mappings[] = $mapping;
			}
		}
	}
	return ($mappings);
}

function kea6_earlydnsreg_mappings(): array {
	$mappings = [];
	foreach ((array)config_get_path('dhcpdv6', []) as $if => $conf) {
		/* an interface left without settings (e.g. its last static mapping deleted) reads as '' */
		if (!is_array($conf) || !is_array(array_get_path($conf, 'staticmap'))) {
			continue;
		}
		foreach (array_get_path($conf, 'staticmap', []) as $static) {
			if (is_array($static) && ($mapping = kea6_earlydnsreg_enabled($conf, $static))) {
				$mappings[] = $mapping;
			}
		}
	}
	return ($mappings);
}

function kea_dnsreg_enabled_ids(): array {
	$ids = [];
	/* Registration goes through Unbound; nothing to do without it (#16865). */
	if (!config_path_enabled('unbound')) {
		return ($ids);
	}
	$enabled['enable'] = true;
	if (config_path_enabled('kea', 'dnsreg')) {
		$enabled['default'] = true;
	}
	foreach (array_keys(config_get_path('dhcpd', [])) as $idx => $if) {
		$dnsregpolicy = config_get_path("dhcpd/{$if}/dnsregpolicy", 'default');
		if (array_key_exists($dnsregpolicy, $enabled)) {
			$ids[$idx + 1] = true;
		}
	}
	return ($ids);
}

function kea_dnsreg_enabled(): bool {
	return ((bool)kea_dnsreg_enabled_ids());
}

function kea_dnsreg_subnet_enabled(int $subnet_id): bool {
	return (array_key_exists($subnet_id, kea_dnsreg_enabled_ids()));
}

function kea6_dnsreg_enabled_ids(): array {
	$ids = [];
	/* Registration goes through Unbound; nothing to do without it (#16865). */
	if (!config_path_enabled('unbound')) {
		return ($ids);
	}
	$enabled['enable'] = true;
	if (config_path_enabled('kea6', 'dnsreg')) {
		$enabled['default'] = true;
	}
	foreach (array_keys(config_get_path('dhcpdv6', [])) as $idx => $if) {
		$dnsregpolicy = config_get_path("dhcpdv6/{$if}/dnsregpolicy", 'default');
		if (array_key_exists($dnsregpolicy, $enabled)) {
			$ids[$idx + 1] = true;
		}
	}
	return ($ids);
}

function kea6_dnsreg_enabled(): bool {
	return ((bool)kea6_dnsreg_enabled_ids());
}

function kea6_dnsreg_subnet_enabled(int $subnet_id): bool {
	return (array_key_exists($subnet_id, kea6_dnsreg_enabled_ids()));
}

function kea_format_age(string|null $age): string {
	if (!isset($age)) {
		return gettext('N/A');
	}

	$parts = [];
	$age = intval($age);

	$values = [
		'day' => floor($age / 86400),
		'hour' => floor(($age % 86400) / 3600),
		'minute' => floor((($age % 86400) % 3600) / 60),
		'second' => ceil((($age % 86400) % 3600) % 60)
	];

	$pluralize = fn($n, $v) => ($v == 1) ? "{$n}" : "{$n}s";

	foreach ($values as $name => $value) {
		if ($value > 0) {
			$parts[] = "{$value} {$pluralize($name, $value)}";
		}
	}

	if (empty($parts)) {
		$parts[] = gettext('0 seconds');
	}

	return (implode(', ', $parts). ' ' . gettext('ago'));
}

function dhcp_ha_status_icon($where, $server) {
	/* local is always assumed online */
	$class = 'text-success';
	$icon = 'fa-solid fa-heart';
	$title = gettext('Online');

	if ($where === 'remote') {
		$heartbeatdelay = (int) config_get_path('kea/ha/heartbeatdelay', kea_defaults('heartbeatdelay'));
		$heartbeatdelay /= 1000;
		$heartbeatdelay += 2; /* some grace */
		if ($server['age'] >= $heartbeatdelay) {
			$class = 'text-warning';
			$icon = 'fa-solid fa-heart-pulse';
			$title = gettext('Interrupted');
		}
		if (!$server['in-touch'] || $server['communication-interrupted']) {
			$class = 'text-danger';
			$icon = 'fa-solid fa-heart-crack';
			$title = gettext('Offline');
		}
	}

	printf('<i class="%s %s" title="%s"></i>', $icon, $class, $title);
}

/*
 * Custom Kea JSON can load hook libraries and choose file paths, which is
 * equivalent to root access (pfSense-SA-26_20). Only administrators or users
 * holding the dedicated privilege may change it.
 */
function kea_custom_config_editable() {
	$user_entry = getUserEntry($_SESSION['Username']);
	$user_entry = $user_entry['item'] ?? [];
	return (isAdminUID($_SESSION['Username']) ||
	    userHasPrivilege($user_entry, 'page-services-dhcpserver-kea-custom') ||
	    userHasPrivilege($user_entry, 'page-all'));
}

/*
 * For users who may not edit custom Kea JSON, keep the stored value and
 * reject any attempt to change it. $stored is the base64 config value.
 * $post is the submitted form (default: $_POST); its custom_kea_config is
 * replaced by the stored value.
 */
function kea_custom_config_enforce($stored, &$input_errors, ?array &$post = null) {
	if ($post === null) {
		$post = &$_POST;
	}
	if (kea_custom_config_editable()) {
		return;
	}
	$stored = base64_decode((string)$stored);
	$normalize = function ($text) {
		return trim(str_replace("\r\n", "\n", (string)$text));
	};
	if (isset($post['custom_kea_config']) &&
	    ($normalize($post['custom_kea_config']) !== $normalize($stored))) {
		$input_errors[] = gettext("This user does not have sufficient privileges to edit the custom Kea configuration.");
	}
	$post['custom_kea_config'] = $stored;
}

/* The Kea HA node roles offered by the settings pages. */
function kea_ha_roles(): array {
	return [
		'primary' => gettext('Primary'),
		'standby' => gettext('Standby'),
	];
}

function dhcp_validate_settings_post(array $pconfig): array {
	$input_errors = [];

	/* validate local name */
	$localname = trim(array_get_path($pconfig, 'ha/localname'));
	if (!(empty($localname) || is_hostname($localname))) {
		$input_errors[] = gettext('Local name must be hostname-like.');
	}

	/* validate remote name */
	$remotename = trim(array_get_path($pconfig, 'ha/remotename'));
	if ((array_path_enabled($pconfig, 'ha') && empty($remotename)) ||
	    !(empty($remotename) || is_hostname($remotename))) {
		$input_errors[] = gettext('Remote name must be non-empty and hostname-like.');
	}

	/* validate addresses */
	foreach ([
		[
			'msg' => gettext('Local'),
			'val' => trim(array_get_path($pconfig, 'ha/localip'))
		], [
			'msg' => gettext('Remote'),
			'val' => trim(array_get_path($pconfig, 'ha/remoteip'))
		]
	] as $ip) {
		if (((array_path_enabled($pconfig, 'ha')) && (empty($ip['val']))) ||
		    !(empty($ip['val']) || is_ipaddr($ip['val'])))  {
			$input_errors[] = sprintf(gettext('%s address must be a valid IPv4 or IPv6 address.'), $ip['msg']);
		}
	}

	/* validate ports (they are written into the Kea HA peer URLs) */
	foreach ([
		[
			'msg' => gettext('local'),
			'val' => trim(array_get_path($pconfig, 'ha/localport'))
		], [
			'msg' => gettext('remote'),
			'val' => trim(array_get_path($pconfig, 'ha/remoteport'))
		]
	] as $port) {
		if (!(empty($port['val']) || (ctype_digit($port['val']) && is_port($port['val']))))  {
			$input_errors[] = sprintf(gettext('Invalid %s port.'), $port['msg']);
		}
	}

	/* the role is written into the Kea configuration; the form offers two */
	if (array_path_enabled($pconfig, 'ha') &&
	    !array_key_exists((string)array_get_path($pconfig, 'ha/role'), kea_ha_roles())) {
		$input_errors[] = gettext('Invalid node role.');
	}

	/* the advanced HA options are durations and counts */
	foreach ([
		'heartbeatdelay' => gettext('Heartbeat Delay'),
		'maxresponsedelay' => gettext('Max Response Delay'),
		'maxackdelay' => gettext('Max Ack Delay'),
		'maxunackedclients' => gettext('Max Unacked Clients'),
		'maxrejectedleaseupdates' => gettext('Max Rejected Updates')
	] as $key => $name) {
		$val = trim((string)array_get_path($pconfig, "ha/{$key}"));
		if (($val !== '') && !ctype_digit($val)) {
			$input_errors[] = sprintf(gettext('%s must be a non-negative integer.'), $name);
		}
	}

	/* TLS needs certificates the form offers */
	if (array_path_enabled($pconfig, 'ha', 'tls')) {
		if (!array_key_exists((string)array_get_path($pconfig, 'ha/scertref'), kea_server_cert_list())) {
			$input_errors[] = gettext('A valid server certificate must be selected for TLS transport.');
		}
		if (array_path_enabled($pconfig, 'ha', 'mutualtls') &&
		    !array_key_exists((string)array_get_path($pconfig, 'ha/ccertref'), kea_client_cert_list())) {
			$input_errors[] = gettext('A valid client certificate must be selected for mutual TLS.');
		}
	}

	if (!empty($pconfig['loglevel']) && !array_key_exists($pconfig['loglevel'], kea_log_levels())) {
		$input_errors[] = gettext('Invalid log level.');
	}

	/* validate custom config */
	if (!empty($pconfig['custom_kea_config'])) {
		$json = json_decode(base64_decode(array_get_path($pconfig, 'custom_kea_config')), true);
		if (!is_array($json) || (json_last_error() !== JSON_ERROR_NONE)) {
			$input_errors[] = gettext('Custom configuration is not a well formed JSON object.');
		}
	}

    return ($input_errors);
}

/*
 * Save the DHCP (section "kea") or DHCPv6 (section "kea6") server settings
 * form. $post holds the form fields. Returns [$input_errors, $pconfig].
 * Writes the configuration only when something changed.
 */
function kea_do_settings_post(string $section, array $post, string $description, bool $server_enabled, string $subsystem): array {
	$pconfig = $old_config = config_get_path($section, []);
	$priv_errors = [];
	kea_custom_config_enforce(array_get_path($old_config, 'custom_kea_config'), $priv_errors, $post);

	/* normalize these fields so that changes comparison below works properly */
	foreach ([
		['', 'dnsreg'],
		['', 'earlydnsreg'],
		['ha', 'enable'],
		['ha', 'tls'],
		['ha', 'mutualtls']
	] as $parts) {
		if (array_path_enabled($old_config, $parts[0], $parts[1])) {
			array_set_path($old_config, ltrim(implode('/', $parts), '/'), true);
		}
	}

	if (isset($post['dnsreg'])) {
		array_set_path($pconfig, 'dnsreg', true);
	} else {
		array_del_path($pconfig, 'dnsreg');
	}

	if (isset($post['earlydnsreg'])) {
		array_set_path($pconfig, 'earlydnsreg', true);
	} else {
		array_del_path($pconfig, 'earlydnsreg');
	}

	if (isset($post['ha_enable'])) {
		array_set_path($pconfig, 'ha/enable', true);
		array_set_path($pconfig, 'ha/role', $post['ha_role']);
	} else {
		array_del_path($pconfig, 'ha/enable');
		array_del_path($pconfig, 'ha/role');
	}

	if (isset($post['ha_tls'])) {
		array_set_path($pconfig, 'ha/tls', true);
		array_set_path($pconfig, 'ha/scertref', $post['ha_scertref']);
		if (isset($post['ha_mutualtls'])) {
			array_set_path($pconfig, 'ha/mutualtls', true);
			array_set_path($pconfig, 'ha/ccertref', $post['ha_ccertref']);
		} else {
			array_del_path($pconfig, 'ha/mutualtls');
			array_del_path($pconfig, 'ha/ccertref');
		}
	} else {
		array_del_path($pconfig, 'ha/tls');
		array_del_path($pconfig, 'ha/scertref');
		array_del_path($pconfig, 'ha/mutualtls');
		array_del_path($pconfig, 'ha/ccertref');
	}

	/* these are optional settings */
	foreach ([
		'ha_localname' => 'ha/localname',
		'ha_localip' => 'ha/localip',
		'ha_localport' => 'ha/localport',
		'ha_remotename' => 'ha/remotename',
		'ha_remoteip' => 'ha/remoteip',
		'ha_remoteport' => 'ha/remoteport',
		'ha_heartbeatdelay' => 'ha/heartbeatdelay',
		'ha_maxresponsedelay' => 'ha/maxresponsedelay',
		'ha_maxackdelay' => 'ha/maxackdelay',
		'ha_maxunackedclients' => 'ha/maxunackedclients',
		'ha_maxrejectedleaseupdates' => 'ha/maxrejectedleaseupdates'
	] as $id => $path) {
		if (!empty($post[$id])) {
			array_set_path($pconfig, $path, $post[$id]);
		} else {
			array_del_path($pconfig, $path);
		}
	}

	if (!empty($post['custom_kea_config'])) {
		array_set_path($pconfig, 'custom_kea_config', base64_encode($post['custom_kea_config']));
	} else {
		array_del_path($pconfig, 'custom_kea_config');
	}

	if (!empty($post['loglevel']) && ($post['loglevel'] != 'WARN')) {
		array_set_path($pconfig, 'loglevel', $post['loglevel']);
	} else {
		array_del_path($pconfig, 'loglevel');
	}

	/* don't leave empty ha config section */
	if (empty(array_get_path($pconfig, 'ha', []))) {
		array_del_path($pconfig, 'ha');
	}

	$input_errors = array_merge($priv_errors, dhcp_validate_settings_post($pconfig));
	if (!$input_errors && ($pconfig !== $old_config)) {
		config_set_path($section, $pconfig);
		write_config($description);
		if ($server_enabled) {
			mark_subsystem_dirty($subsystem);
		}
	}

	return ([$input_errors, $pconfig]);
}

function dhcp_do_settings_post(array $post): array {
	return (kea_do_settings_post('kea', $post, gettext('DHCP Server - Settings changed'),
	    is_dhcp_server_enabled(), 'dhcpd'));
}

function dhcp6_do_settings_post(array $post): array {
	return (kea_do_settings_post('kea6', $post, gettext('DHCPv6 Server - Settings changed'),
	    is_dhcpv6_server_enabled(), 'dhcpd6'));
}

function dhcp_apply_changes(): int {
	$retval = 0;
	$retvaldhcp = 0;
	$retvaldns = 0;
	$retvalfc = 0;
	/* dnsmasq_configure calls dhcpd_configure */
	/* no need to restart dhcpd twice */
	if (config_path_enabled('dnsmasq')) {
		$retvaldns |= services_dnsmasq_configure();
		if ($retvaldns == 0) {
			clear_subsystem_dirty('hosts');
			clear_subsystem_dirty('dhcpd');
		}
	} elseif (config_path_enabled('unbound')) {
		$retvaldns |= services_unbound_configure();
		if ($retvaldns == 0) {
			clear_subsystem_dirty('unbound');
			clear_subsystem_dirty('hosts');
			clear_subsystem_dirty('dhcpd');
		}
	} else {
		$retvaldhcp |= services_dhcpd_configure();
		if ($retvaldhcp == 0) {
			clear_subsystem_dirty('dhcpd');
		}
	}
	/* BIND package - Bug #3710 */
	if (!function_exists('is_package_installed')) {
		require_once('pkg-utils.inc');
	}
	if (is_package_installed('FreeSense-pkg-bind') &&
	    config_path_enabled('installedpackages/bind/config/0', 'enable_bind') &&
	    dhcp_is_backend('isc')) {
		$reloadbind = false;
		$bindzone = config_get_path('installedpackages/bindzone/config', []);

		for ($x = 0; $x < sizeof($bindzone); $x++) {
			$zone = $bindzone[$x];
			if ($zone['regdhcpstatic'] == 'on') {
				$reloadbind = true;
				break;
			}
		}
		if ($reloadbind === true) {
			if (file_exists("/usr/local/pkg/bind.inc")) {
				require_once("/usr/local/pkg/bind.inc");
				bind_sync();
			}
		}
	}
	$retvalfc |= filter_configure();
	if ($retvaldhcp == 1 || $retvaldns == 1 || $retvalfc == 1) {
		$retval = 1;
	}
	return $retval;
}

function dhcp6_apply_changes(): int {
	$retval = 0;
	$retvaldhcp = 0;
	$retvaldns = 0;
	$retvalfc = 0;
	/* dnsmasq_configure calls dhcpd_configure */
	/* no need to restart dhcpd twice */
	if (config_path_enabled('dnsmasq')) {
		$retvaldns |= services_dnsmasq_configure();
		if ($retvaldns == 0) {
			clear_subsystem_dirty('hosts');
			clear_subsystem_dirty('dhcpd6');
		}
	} elseif (config_path_enabled('unbound')) {
		$retvaldns |= services_unbound_configure();
		if ($retvaldns == 0) {
			clear_subsystem_dirty('unbound');
			clear_subsystem_dirty('dhcpd6');
		}
	} else {
		$retvaldhcp |= services_dhcpd_configure();
		if ($retvaldhcp == 0) {
			clear_subsystem_dirty('dhcpd6');
		}
	}
	/* BIND package - Bug #3710 */
	if (!function_exists('is_package_installed')) {
		require_once('pkg-utils.inc');
	}
	if (is_package_installed('FreeSense-pkg-bind') &&
	    config_path_enabled('installedpackages/bind/config/0', 'enable_bind')) {
		$reloadbind = false;
		$bindzone = config_get_path('installedpackages/bindzone/config', []);

		for ($x = 0; $x < sizeof($bindzone); $x++) {
			$zone = $bindzone[$x];
			if ($zone['regdhcpstatic'] == 'on') {
				$reloadbind = true;
				break;
			}
		}
		if ($reloadbind === true) {
			if (file_exists("/usr/local/pkg/bind.inc")) {
				require_once("/usr/local/pkg/bind.inc");
				bind_sync();
			}
		}
	}
	$retvalfc |= filter_configure();
	if ($retvaldhcp == 1 || $retvaldns == 1 || $retvalfc == 1) {
		$retval = 1;
	}
	return $retval;
}

function kea_server_cert_list(): array {
	return (array_filter(cert_build_list('cert', 'HTTPS'), function($refid) {
		$cert = lookup_cert($refid);
		$is_valid = false;
		if (isset($cert['item']) && isset($cert['item']['caref'])) {
			if (isset($cert['item']['type']) && ($cert['item']['type'] == 'server')) {
				$is_valid = true;
			} elseif (isset($cert['item']['crt'])) {
				$purpose = cert_get_purpose($cert['item']['crt']);
				$is_valid = ($purpose['server'] == 'Yes');
			}
		}
		return $is_valid;
	}, ARRAY_FILTER_USE_KEY));
}

function kea_client_cert_list(): array {
	return (array_filter(cert_build_list('cert', 'HTTPS'), function($refid) {
		$cert = lookup_cert($refid);
		$is_valid = false;
		if (isset($cert['item']) && isset($cert['item']['caref'])) {
			if (isset($cert['item']['type']) && ($cert['item']['type'] == 'user')) {
				$is_valid = true;
			} elseif (isset($cert['item']['crt'])) {
				$purpose = cert_get_purpose($cert['item']['crt']);
				$is_valid = ($purpose['server'] == 'No');
			}
		}
		return $is_valid;
	}, ARRAY_FILTER_USE_KEY));
}

/* ------------------------------------------------------------------ */
/* Interfaces                                                          */
/* ------------------------------------------------------------------ */

/*
 * What the DHCP Server pages need to know about an interface, or null when
 * $if is not a configured interface. "eligible" is true when the DHCP Server
 * can serve the interface (a static IPv4 subnet smaller than /31).
 */
function dhcp_iface_context(string $if): ?array {
	$iflist = get_configured_interface_with_descr();
	if (($if === '') || !isset($iflist[$if])) {
		return (null);
	}

	$ipaddr = get_interface_ip($if);
	$subnet = get_interface_subnet($if);
	$cfgip = config_get_path("interfaces/{$if}/ipaddr");
	$cfgsn = config_get_path("interfaces/{$if}/subnet");

	$pools = [];
	foreach (config_get_path("dhcpd/{$if}/pool", []) as $pool) {
		if (is_array($pool)) {
			$pools[] = [
				'from' => (string)array_get_path($pool, 'range/from', ''),
				'to' => (string)array_get_path($pool, 'range/to', '')
			];
		}
	}

	return ([
		'if' => $if,
		'name' => $iflist[$if],
		'descr' => convert_friendly_interface_to_friendly_descr($if),
		'ipaddr' => $ipaddr,
		'subnet' => $subnet,
		'network' => gen_subnetv4($ipaddr, $subnet),
		'broadcast' => gen_subnetv4_max($ipaddr, $subnet),
		'eligible' => (is_ipaddrv4($cfgip) && !empty($cfgsn) && ((int)$cfgsn < 31)),
		'enabled' => config_path_enabled("dhcpd/{$if}"),
		'staticarp' => config_path_enabled("dhcpd/{$if}", 'staticarp'),
		'range' => [
			'from' => (string)config_get_path("dhcpd/{$if}/range/from", ''),
			'to' => (string)config_get_path("dhcpd/{$if}/range/to", '')
		],
		'pools' => $pools
	]);
}

/*
 * The DHCPv6 counterpart of dhcp_iface_context(). "eligible" is true for a
 * track6 interface or a static IPv6 address that is not link-local.
 */
function dhcp6_iface_context(string $if): ?array {
	$iflist = get_configured_interface_with_descr();
	if (($if === '') || !isset($iflist[$if])) {
		return (null);
	}

	$cfgipv6 = config_get_path("interfaces/{$if}/ipaddrv6");

	return ([
		'if' => $if,
		'name' => $iflist[$if],
		'descr' => convert_friendly_interface_to_friendly_descr($if),
		'ipaddrv6' => get_interface_ipv6($if),
		'subnetv6' => get_interface_subnetv6($if),
		'track6' => ($cfgipv6 == 'track6'),
		'eligible' => (bool)(($cfgipv6 == 'track6') || (is_ipaddrv6($cfgipv6) && !is_linklocal($cfgipv6))),
		'enabled' => config_path_enabled("dhcpdv6/{$if}"),
		'netboot' => config_path_enabled("dhcpdv6/{$if}", 'netboot'),
		'ramode' => (string)config_get_path("dhcpdv6/{$if}/ramode", 'disabled'),
		'range' => [
			'from' => (string)config_get_path("dhcpdv6/{$if}/range/from", ''),
			'to' => (string)config_get_path("dhcpdv6/{$if}/range/to", '')
		]
	]);
}

/* ------------------------------------------------------------------ */
/* Static mappings                                                     */
/* ------------------------------------------------------------------ */

/* Early DNS registration policies a static mapping can choose. */
function dhcp_staticmap_dnsregpolicy_values(): array {
	return ([
		'default' => gettext('Track subnet'),
		'enable' => gettext('Enable'),
		'disable' => gettext('Disable')
	]);
}

/*
 * After a static mapping is saved or deleted: stage the DHCP server and,
 * when they register static mappings, the DNS Forwarder or Resolver.
 */
function dhcp_staticmap_mark_dirty(string $if, bool $v6 = false): void {
	if (config_path_enabled(($v6 ? 'dhcpdv6' : 'dhcpd') . "/{$if}")) {
		mark_subsystem_dirty($v6 ? 'dhcpd6' : 'dhcpd');
		if (config_path_enabled('dnsmasq') && config_path_enabled('dnsmasq', 'regdhcpstatic')) {
			mark_subsystem_dirty('hosts');
		}
		if (config_path_enabled('unbound') && config_path_enabled('unbound', 'regdhcpstatic')) {
			mark_subsystem_dirty('unbound');
		}
	}
}

function dhcp_staticmap_cmp($a, $b) {
	return ipcmp($a['ipaddr'], $b['ipaddr']);
}

function dhcp_staticmaps_sort($ifgui) {
	$dhcpd_config = config_get_path("dhcpd/{$ifgui}/staticmap");
	usort($dhcpd_config, "dhcp_staticmap_cmp");
	config_set_path("dhcpd/{$ifgui}/staticmap", $dhcpd_config);
}

/*
 * The edit form of DHCP static mapping $id on interface $if: the stored
 * mapping, or for a new one the fields given in $request (the ARP table and
 * DHCP leases pages link here with mac, hostname, ...).
 */
function dhcp_staticmap_form(string $if, $id, array $request = []): array {
	$pconfig = [];
	$id = is_numericint($id) ? $id : null;
	$this_map_config = isset($id) ? config_get_path("dhcpd/{$if}/staticmap/{$id}") : null;
	if ($this_map_config) {
		$pconfig['mac'] = $this_map_config['mac'];
		$pconfig['cid'] = $this_map_config['cid'];
		$pconfig['hostname'] = $this_map_config['hostname'];
		$pconfig['ipaddr'] = $this_map_config['ipaddr'];
		$pconfig['filename'] = $this_map_config['filename'];
		$pconfig['rootpath'] = $this_map_config['rootpath'];
		$pconfig['descr'] = $this_map_config['descr'];
		$pconfig['earlydnsregpolicy'] = $this_map_config['earlydnsregpolicy'];
		$pconfig['arp_table_static_entry'] = isset($this_map_config['arp_table_static_entry']);
		$pconfig['deftime'] = $this_map_config['defaultleasetime'];
		$pconfig['maxtime'] = $this_map_config['maxleasetime'];
		$pconfig['gateway'] = $this_map_config['gateway'];
		$pconfig['domain'] = $this_map_config['domain'];
		$pconfig['domainsearchlist'] = $this_map_config['domainsearchlist'];
		list($pconfig['wins1'], $pconfig['wins2']) = $this_map_config['winsserver'];
		list($pconfig['dns1'], $pconfig['dns2'], $pconfig['dns3'], $pconfig['dns4']) = $this_map_config['dnsserver'];
		$pconfig['ddnsdomain'] = $this_map_config['ddnsdomain'];
		$pconfig['ddnsdomainprimary'] = $this_map_config['ddnsdomainprimary'];
		$pconfig['ddnsdomainsecondary'] = $this_map_config['ddnsdomainsecondary'];
		$pconfig['ddnsdomainkeyname'] = $this_map_config['ddnsdomainkeyname'];
		$pconfig['ddnsdomainkeyalgorithm'] = $this_map_config['ddnsdomainkeyalgorithm'];
		$pconfig['ddnsdomainkey'] = $this_map_config['ddnsdomainkey'];
		$pconfig['ddnsupdate'] = isset($this_map_config['ddnsupdate']);
		$pconfig['ddnsforcehostname'] = isset($this_map_config['ddnsforcehostname']);
		list($pconfig['ntp1'], $pconfig['ntp2'], $pconfig['ntp3'], $pconfig['ntp4']) = $this_map_config['ntpserver'];
		$pconfig['tftp'] = $this_map_config['tftp'];
		$pconfig['ldap'] = $this_map_config['ldap'];
		$pconfig['netboot'] = isset($this_map_config['netboot']);
		$pconfig['nextserver'] = $this_map_config['nextserver'];
		$pconfig['filename32'] = $this_map_config['filename32'];
		$pconfig['filename64'] = $this_map_config['filename64'];
		$pconfig['filename32arm'] = $this_map_config['filename32arm'];
		$pconfig['filename64arm'] = $this_map_config['filename64arm'];
		$pconfig['uefihttpboot'] = $this_map_config['uefihttpboot'];
		$pconfig['netmask'] = $this_map_config['netmask'];
		$pconfig['numberoptions'] = $this_map_config['numberoptions'];
		if (dhcp_is_backend('kea')) {
			$pconfig['custom_kea_config'] = base64_decode($this_map_config['custom_kea_config']);
		}
	} else {
		$pconfig['mac'] = $request['mac'];
		$pconfig['cid'] = $request['cid'];
		$pconfig['hostname'] = $request['hostname'];
		$pconfig['filename'] = $request['filename'];
		$pconfig['rootpath'] = $request['rootpath'];
		$pconfig['descr'] = $request['descr'];
		$pconfig['earlydnsregpolicy'] = $request['earlydnsregpolicy'];
		$pconfig['arp_table_static_entry'] = $request['arp_table_static_entry'];
		$pconfig['deftime'] = $request['defaultleasetime'];
		$pconfig['maxtime'] = $request['maxleasetime'];
		$pconfig['gateway'] = $request['gateway'];
		$pconfig['domain'] = $request['domain'];
		$pconfig['domainsearchlist'] = $request['domainsearchlist'];
		$pconfig['wins1'] = $request['wins1'];
		$pconfig['wins2'] = $request['wins2'];
		$pconfig['dns1'] = $request['dns1'];
		$pconfig['dns2'] = $request['dns2'];
		$pconfig['dns3'] = $request['dns3'];
		$pconfig['dns4'] = $request['dns4'];
		$pconfig['ddnsdomain'] = $request['ddnsdomain'];
		$pconfig['ddnsdomainprimary'] = $request['ddnsdomainprimary'];
		$pconfig['ddnsdomainsecondary'] = $request['ddnsdomainsecondary'];
		$pconfig['ddnsdomainkeyname'] = $request['ddnsdomainkeyname'];
		$pconfig['ddnsdomainkeyalgorithm'] = $request['ddnsdomainkeyalgorithm'];
		$pconfig['ddnsdomainkey'] = $request['ddnsdomainkey'];
		$pconfig['ddnsupdate'] = isset($request['ddnsupdate']);
		$pconfig['ddnsforcehostname'] = isset($request['ddnsforcehostname']);
		$pconfig['ntp1'] = $request['ntp1'];
		$pconfig['ntp2'] = $request['ntp2'];
		$pconfig['ntp3'] = $request['ntp3'];
		$pconfig['ntp4'] = $request['ntp4'];
		$pconfig['tftp'] = $request['tftp'];
		$pconfig['ldap'] = $request['ldap'];
		$pconfig['netboot'] = isset($request['netboot']);
		$pconfig['nextserver'] = $request['nextserver'];
		$pconfig['filename'] = $request['filename'];
		$pconfig['filename32'] = $request['filename32'];
		$pconfig['filename64'] = $request['filename64'];
		$pconfig['filename32arm'] = $request['filename32arm'];
		$pconfig['filename64arm'] = $request['filename64arm'];
		$pconfig['uefihttpboot'] = $request['uefihttpboot'];
		$pconfig['rootpath'] = $request['rootpath'];
		$pconfig['netmask'] = $request['netmask'];
		$pconfig['numberoptions'] = $request['numberoptions'];
		if (dhcp_is_backend('kea')) {
			$pconfig['custom_kea_config'] = $request['custom_kea_config'];
		}
	}

	return ($pconfig);
}

/*
 * Save the DHCP static mapping edit form $post as mapping $id (null: a new
 * one) on interface $if. The list is kept sorted by IP address.
 * Returns ['input_errors' => [...], 'pconfig' => the form to show again,
 * 'warnings' => [...] (to show, not errors), 'id' => the saved mapping's
 * new position].
 */
function dhcp_staticmap_save(string $if, $id, array $post): array {
	$input_errors = [];
	$warnings = [];
	$pconfig = $post;

	$ctx = dhcp_iface_context($if);
	if ($ctx === null) {
		$input_errors[] = gettext('The interface must be a configured interface.');
		return (['input_errors' => $input_errors, 'pconfig' => $pconfig, 'warnings' => $warnings, 'id' => null]);
	}
	$static_arp_enabled = $ctx['staticarp'];
	$ifcfgip = $ctx['ipaddr'];
	$ifcfgsn = $ctx['subnet'];
	$ifcfgdescr = $ctx['descr'];
	$dnsregpolicy_values = dhcp_staticmap_dnsregpolicy_values();

	$id = is_numericint($id) ? $id : null;
	$this_map_config = isset($id) ? config_get_path("dhcpd/{$if}/staticmap/{$id}") : null;

	$numberoptions = array();
	for ($x = 0; $x < 99; $x++) {
		if (isset($post["number{$x}"]) && ctype_digit(strval($post["number{$x}"]))) {
			if ($post["number{$x}"] < 1 || $post["number{$x}"] > 254) {
				$input_errors[] = gettext("The DHCP option must be a number between 1 and 254.");
				continue;
			}
			$numbervalue = array();
			$numbervalue['number'] = htmlspecialchars($post["number{$x}"]);
			$numbervalue['type'] = htmlspecialchars($post["itemtype{$x}"]);
			$numbervalue['value'] = base64_encode($post["value{$x}"]);
			$numberoptions['item'][] = $numbervalue;
		}
	}

	// Reload the new pconfig variable that the form uses.
	$pconfig['numberoptions'] = $numberoptions;

	/* input validation */
	$reqdfields = array();
	$reqdfieldsn = array();

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	/* either MAC or Client-ID must be specified */
	if (empty($post['mac']) && empty($post['cid'])) {
		$input_errors[] = gettext("Either MAC address or Client identifier must be specified");
	}

	/* normalize MAC addresses - lowercase and convert Windows-ized hyphenated MACs to colon delimited */
	$post['mac'] = trim(strtolower(str_replace("-", ":", $post['mac'])));

	if ($post['hostname']) {
		preg_match("/\-\$/", $post['hostname'], $matches);
		if ($matches) {
			$input_errors[] = gettext("The hostname cannot end with a hyphen according to RFC952");
		}
		if (!is_hostname($post['hostname'])) {
			$input_errors[] = gettext("The hostname can only contain the characters A-Z, 0-9 and '-'.");
		} else {
			if (!is_unqualified_hostname($post['hostname'])) {
				$input_errors[] = gettext("A valid hostname is specified, but the domain name part should be omitted");
			}
		}
	}


	if ($post['earlydnsregpolicy'] && !array_key_exists($post['earlydnsregpolicy'], $dnsregpolicy_values)) {
		$input_errors[] = gettext("Invalid Early DNS Registration Policy.");
	}

	if (($post['ipaddr'] && !is_ipaddrv4($post['ipaddr']))) {
		$input_errors[] = gettext("A valid IPv4 address must be specified.");
	}

	if (($post['mac'] && !is_macaddr($post['mac']))) {
		$input_errors[] = gettext("A valid MAC address must be specified.");
	}
	if ($static_arp_enabled && !$post['ipaddr']) {
		$input_errors[] = gettext("Static ARP is enabled.  An IP address must be specified.");
	}

	/* check for overlaps */
	foreach (config_get_path("dhcpd/{$if}/staticmap", []) as $mapent) {
		if ($this_map_config && ($this_map_config === $mapent)) {
			continue;
		}
		if ((($mapent['mac'] == $post['mac']) && $mapent['mac']) ||
		    (($mapent['cid'] == $post['cid']) && $mapent['cid'])) {
			$input_errors[] = gettext("This MAC address or Client identifier already exists.");
			break;
		}
		if (($mapent['ipaddr'] == $post['ipaddr']) && $mapent['ipaddr']) {
			$warnings[] = sprintf(gettext('The IP address %1$s is in use by another static DHCP mapping. ' .
			'This has the potential to cause an IP conflict.'), $mapent['ipaddr']);
			break;
		}
	}

	/* make sure it's not within the dynamic subnet */
	if ($post['ipaddr']) {
		if (is_inrange_v4($post['ipaddr'], config_get_path("dhcpd/{$if}/range/from"), config_get_path("dhcpd/{$if}/range/to"))) {
			$input_errors[] = sprintf(gettext("The IP address must not be within the DHCP range for this interface."));
		}

		foreach (config_get_path("dhcpd/{$if}/pool", []) as $p) {
			if (is_inrange_v4($post['ipaddr'], $p['range']['from'], $p['range']['to'])) {
				$input_errors[] = gettext("The IP address must not be within the range configured on a DHCP pool for this interface.");
				break;
			}
		}

		$lansubnet_start = gen_subnetv4($ifcfgip, $ifcfgsn);
		$lansubnet_end = gen_subnetv4_max($ifcfgip, $ifcfgsn);
		if (!is_inrange_v4($post['ipaddr'], $lansubnet_start, $lansubnet_end)) {
			$input_errors[] = sprintf(gettext("The IP address must lie in the %s subnet."), $ifcfgdescr);
		}

		if ($post['ipaddr'] == $lansubnet_start) {
			$input_errors[] = sprintf(gettext("The IP address cannot be the %s network address."), $ifcfgdescr);
		}

		if ($post['ipaddr'] == $lansubnet_end) {
			$input_errors[] = sprintf(gettext("The IP address cannot be the %s broadcast address."), $ifcfgdescr);
		}
	}

	if (($post['gateway'] && !is_ipaddrv4($post['gateway']))) {
		$input_errors[] = gettext("A valid IPv4 address must be specified for the gateway.");
	}
	if (($post['wins1'] && !is_ipaddrv4($post['wins1'])) || ($post['wins2'] && !is_ipaddrv4($post['wins2']))) {
		$input_errors[] = gettext("A valid IPv4 address must be specified for the primary/secondary WINS servers.");
	}

	$parent_ip = get_interface_ip($if);
	if (is_ipaddrv4($parent_ip) && $post['gateway']) {
		$parent_sn = get_interface_subnet($if);
		if (!ip_in_subnet($post['gateway'], gen_subnet($parent_ip, $parent_sn) . "/" . $parent_sn) && !ip_in_interface_alias_subnet($if, $post['gateway'])) {
			$input_errors[] = sprintf(gettext("The gateway address %s does not lie within the chosen interface's subnet."), $post['gateway']);
		}
	}
	if (($post['dns1'] && !is_ipaddrv4($post['dns1'])) ||
	    ($post['dns2'] && !is_ipaddrv4($post['dns2'])) ||
	    ($post['dns3'] && !is_ipaddrv4($post['dns3'])) ||
	    ($post['dns4'] && !is_ipaddrv4($post['dns4']))) {
		$input_errors[] = gettext("A valid IPV4 address must be specified for each of the DNS servers.");
	}

	if ($post['deftime'] && (!is_numeric($post['deftime']) || ($post['deftime'] < 60))) {
		$input_errors[] = gettext("The default lease time must be at least 60 seconds.");
	}
	if ($post['maxtime'] && (!is_numeric($post['maxtime']) || ($post['maxtime'] < 60) || ($post['maxtime'] < $post['deftime']))) {
		$input_errors[] = gettext("The maximum lease time must be at least 60 seconds, and the same value or greater than the default lease time.");
	}
	if ($post['ddnsupdate']) {
		if (!is_domain($post['ddnsdomain'])) {
			$input_errors[] = gettext("A valid domain name must be specified for the dynamic DNS registration.");
		}
		if (!is_ipaddr($post['ddnsdomainprimary'])) {
			$input_errors[] = gettext("A valid primary domain name server IP address must be specified for the dynamic domain name.");
		}
		if (!empty($post['ddnsdomainsecondary']) && !is_ipaddr($post['ddnsdomainsecondary'])) {
			$input_errors[] = gettext("A valid secondary domain name server IP address must be specified for the dynamic domain name.");
		}
		if (!$post['ddnsdomainkeyname'] || !$post['ddnsdomainkeyalgorithm'] || !$post['ddnsdomainkey']) {
			$input_errors[] = gettext("A valid domain key name, algorithm and secret must be specified.");
		}
		if (preg_match('/[^A-Za-z0-9\.\-\_]/', $post['ddnsdomainkeyname'])) {
			$input_errors[] = gettext("The domain key name may only contain the characters a-z, A-Z, 0-9, '-', '_' and '.'");
		}
		if ($post['ddnsdomainkey'] && !base64_decode($post['ddnsdomainkey'], true)) {
			$input_errors[] = gettext("The domain key secret must be a Base64 encoded value.");
		}
	}
	if ($post['domainsearchlist']) {
		$domain_array=preg_split("/[ ;]+/", $post['domainsearchlist']);
		foreach ($domain_array as $curdomain) {
			if (!is_domain($curdomain)) {
				$input_errors[] = gettext("A valid domain search list must be specified.");
				break;
			}
		}
	}

	if (($post['ntp1'] && !(is_ipaddrv4($post['ntp1']) || (dhcp_is_backend('isc') && is_hostname($post['ntp1'])))) ||
	    ($post['ntp2'] && !(is_ipaddrv4($post['ntp2']) || (dhcp_is_backend('isc') && is_hostname($post['ntp2'])))) ||
	    ($post['ntp3'] && !(is_ipaddrv4($post['ntp3']) || (dhcp_is_backend('isc') && is_hostname($post['ntp3'])))) ||
	    ($post['ntp4'] && !(is_ipaddrv4($post['ntp4']) || (dhcp_is_backend('isc') && is_hostname($post['ntp4']))))) {
		if (dhcp_is_backend('isc')) {
			$input_errors[] = gettext("A valid IP address or hostname must be specified for the NTP servers.");
		} else {
			$input_errors[] = gettext("A valid IP address must be specified for the NTP servers.");
		}
	}
	if ($post['domain'] && (!is_domain($post['domain'], false, false))) {
		$input_errors[] = gettext("A valid domain name must be specified for the DNS domain.");
	}
	if ($post['tftp'] && !is_ipaddrv4($post['tftp']) && !is_domain($post['tftp']) && !filter_var($post['tftp'], FILTER_VALIDATE_URL)) {
		$input_errors[] = gettext("A valid IPv4 address, hostname or URL must be specified for the TFTP server.");
	}
	if (($post['nextserver'] && !is_ipaddrv4($post['nextserver']))) {
		$input_errors[] = gettext("A valid IPv4 address must be specified for the network boot server.");
	}
	if (isset($post['arp_table_static_entry']) && empty($post['mac'])) {
		$input_errors[] = gettext("A valid MAC address must be specified for use with static ARP.");
	}
	if (isset($post['arp_table_static_entry']) && empty($post['ipaddr'])) {
		$input_errors[] = gettext("A valid IPv4 address must be specified for use with static ARP.");
	}

	/* Redmine #13584 */
	if (is_array($pconfig['numberoptions']['item'])) {
		foreach ($pconfig['numberoptions']['item'] as $numberoption) {
			$numberoption_value = base64_decode($numberoption['value']);
			if ($numberoption['type'] == 'text' && strstr($numberoption_value, '"')) {
				$input_errors[] = gettext("Text type cannot include quotation marks.");
			} else if ($numberoption['type'] == 'string' && !preg_match('/^"[^"]*"$/', $numberoption_value) && !preg_match('/^[0-9a-f]{2}(?:\:[0-9a-f]{2})*$/i', $numberoption_value)) {
				$input_errors[] = gettext("String type must be enclosed in quotes like \"this\" or must be a series of octets specified in hexadecimal, separated by colons, like 01:23:45:67:89:ab:cd:ef");
			} else if ($numberoption['type'] == 'boolean' && $numberoption_value != 'true' && $numberoption_value != 'false' && $numberoption_value != 'on' && $numberoption_value != 'off') {
				$input_errors[] = gettext("Boolean type must be true, false, on, or off.");
			} else if ($numberoption['type'] == 'unsigned integer 8' && (!is_numeric($numberoption_value) || $numberoption_value < 0 || $numberoption_value > 255)) {
				$input_errors[] = gettext("Unsigned 8-bit integer type must be a number in the range 0 to 255.");
			} else if ($numberoption['type'] == 'unsigned integer 16' && (!is_numeric($numberoption_value) || $numberoption_value < 0 || $numberoption_value > 65535)) {
				$input_errors[] = gettext("Unsigned 16-bit integer type must be a number in the range 0 to 65535.");
			} else if ($numberoption['type'] == 'unsigned integer 32' && (!is_numeric($numberoption_value) || $numberoption_value < 0 || $numberoption_value > 4294967295)) {
				$input_errors[] = gettext("Unsigned 32-bit integer type must be a number in the range 0 to 4294967295.");
			} else if ($numberoption['type'] == 'signed integer 8' && (!is_numeric($numberoption_value) || $numberoption_value < -128 || $numberoption_value > 127)) {
				$input_errors[] = gettext("Signed 8-bit integer type must be a number in the range -128 to 127.");
			} else if ($numberoption['type'] == 'signed integer 16' && (!is_numeric($numberoption_value) || $numberoption_value < -32768 || $numberoption_value > 32767)) {
				$input_errors[] = gettext("Signed 16-bit integer type must be a number in the range -32768 to 32767.");
			} else if ($numberoption['type'] == 'signed integer 32' && (!is_numeric($numberoption_value) || $numberoption_value < -2147483648 || $numberoption_value > 2147483647)) {
				$input_errors[] = gettext("Signed 32-bit integer type must be a number in the range -2147483648 to 2147483647.");
			} else if ($numberoption['type'] == 'ip-address' && !is_ipaddrv4($numberoption_value) && !is_hostname($numberoption_value)) {
				$input_errors[] = gettext("IP address or host type must be an IP address or host name.");
			}
		}
	}

	/* validate custom config */
	if (dhcp_is_backend('kea')) {
		kea_custom_config_enforce($this_map_config['custom_kea_config'] ?? '', $input_errors, $post);
		if (!empty($post['custom_kea_config'])) {
			$json = json_decode($post['custom_kea_config'], true);
			if (!is_array($json) || (json_last_error() !== JSON_ERROR_NONE)) {
				$input_errors[] = gettext('Custom configuration is not a well formed JSON object.');
			}
		}
	}

	if ($input_errors) {
		return (['input_errors' => $input_errors, 'pconfig' => $pconfig, 'warnings' => $warnings, 'id' => null]);
	}

	$mapent = array();
	$mapent['mac'] = $post['mac'];
	$mapent['cid'] = $post['cid'];
	$mapent['ipaddr'] = $post['ipaddr'];
	$mapent['hostname'] = $post['hostname'];
	$mapent['descr'] = $post['descr'];
	$mapent['earlydnsregpolicy'] = $post['earlydnsregpolicy'];
	$mapent['arp_table_static_entry'] = ($post['arp_table_static_entry']) ? true : false;
	$mapent['filename'] = $post['filename'];
	$mapent['rootpath'] = $post['rootpath'];
	$mapent['defaultleasetime'] = $post['deftime'];
	$mapent['maxleasetime'] = $post['maxtime'];

	unset($mapent['winsserver']);
	if ($post['wins1']) {
		$mapent['winsserver'][] = $post['wins1'];
	}
	if ($post['wins2']) {
		$mapent['winsserver'][] = $post['wins2'];
	}

	unset($mapent['dnsserver']);
	if ($post['dns1']) {
		$mapent['dnsserver'][] = $post['dns1'];
	}
	if ($post['dns2']) {
		$mapent['dnsserver'][] = $post['dns2'];
	}
	if ($post['dns3']) {
		$mapent['dnsserver'][] = $post['dns3'];
	}
	if ($post['dns4']) {
		$mapent['dnsserver'][] = $post['dns4'];
	}

	$mapent['gateway'] = $post['gateway'];
	$mapent['domain'] = $post['domain'];
	$mapent['domainsearchlist'] = $post['domainsearchlist'];
	$mapent['ddnsdomain'] = $post['ddnsdomain'];
	$mapent['ddnsdomainprimary'] = $post['ddnsdomainprimary'];
	$mapent['ddnsdomainsecondary'] = $post['ddnsdomainsecondary'];
	$mapent['ddnsdomainkeyname'] = $post['ddnsdomainkeyname'];
	$mapent['ddnsdomainkeyalgorithm'] = $post['ddnsdomainkeyalgorithm'];
	$mapent['ddnsdomainkey'] = $post['ddnsdomainkey'];
	$mapent['ddnsupdate'] = ($post['ddnsupdate']) ? true : false;
	$mapent['ddnsforcehostname'] = ($post['ddnsforcehostname']) ? true : false;

	/* the form has four NTP server fields (the fourth was validated but not saved) */
	unset($mapent['ntpserver']);
	if ($post['ntp1']) {
		$mapent['ntpserver'][] = $post['ntp1'];
	}
	if ($post['ntp2']) {
		$mapent['ntpserver'][] = $post['ntp2'];
	}
	if ($post['ntp3']) {
		$mapent['ntpserver'][] = $post['ntp3'];
	}
	if ($post['ntp4']) {
		$mapent['ntpserver'][] = $post['ntp4'];
	}

	$mapent['tftp'] = $post['tftp'];
	$mapent['ldap'] = $post['ldap'];
	$mapent['netboot'] = ($post['netboot']) ? true : false;
	$mapent['nextserver'] = $post['nextserver'];
	$mapent['filename'] = $post['filename'];
	$mapent['filename32'] = $post['filename32'];
	$mapent['filename64'] = $post['filename64'];
	$mapent['filename32arm'] = $post['filename32arm'];
	$mapent['filename64arm'] = $post['filename64arm'];
	$mapent['uefihttpboot'] = $post['uefihttpboot'];
	$mapent['numberoptions'] = $pconfig['numberoptions'];

	if (dhcp_is_backend('kea')) {
		$mapent['custom_kea_config'] = base64_encode($post['custom_kea_config']);
	}

	if ($this_map_config) {
		config_set_path("dhcpd/{$if}/staticmap/{$id}", $mapent);
	} else {
		config_set_path("dhcpd/{$if}/staticmap/", $mapent);
	}
	dhcp_staticmaps_sort($if);
	/* its position, before write_config() normalises the stored values */
	$newid = array_search($mapent, config_get_path("dhcpd/{$if}/staticmap", []), true);

	write_config("DHCP Server settings saved");

	dhcp_staticmap_mark_dirty($if);

	/* Configure static ARP entry, or remove ARP entry if this host is dynamic. See upstream issue 6821 */
	if ($mapent['arp_table_static_entry']) {
		mwexec("/usr/sbin/arp -S " . escapeshellarg($mapent['ipaddr']) . " " . escapeshellarg($mapent['mac']) . " >/dev/null", true);
	} else {
		mwexec("/usr/sbin/arp -d " . escapeshellarg($mapent['ipaddr']) . " >/dev/null", true);
	}

	return (['input_errors' => [], 'pconfig' => $pconfig, 'warnings' => $warnings, 'id' => ($newid === false) ? null : $newid]);
}

/* Delete DHCP static mapping $id (its position) on interface $if; false when there is no such mapping. */
function dhcp_staticmap_delete(string $if, $id): bool {
	if (!is_numericint($id) || !is_array(config_get_path("dhcpd/{$if}/staticmap/{$id}"))) {
		return (false);
	}
	/* Remove static ARP entry, if necessary */
	if (config_get_path("dhcpd/{$if}/staticmap/{$id}/arp_table_static_entry") !== null) {
		mwexec("/usr/sbin/arp -d " . escapeshellarg(config_get_path("dhcpd/{$if}/staticmap/{$id}/ipaddr")));
	}
	config_del_path("dhcpd/{$if}/staticmap/{$id}");
	write_config("DHCP Server static map deleted");
	dhcp_staticmap_mark_dirty($if);
	return (true);
}

/* ------------------------------------------------------------------ */
/* DHCP server of an interface and its additional address pools        */
/* ------------------------------------------------------------------ */

/* The DNS registration policies the DHCP Server page offers (Kea). */
function dhcp_server_dnsregpolicy_values(): array {
	return ([
		'default' => gettext('Track server'),
		'enable' => gettext('Enable'),
		'disable' => gettext('Disable')
	]);
}

function dhcp_validate_partial_mac_list($maclist) {
	$macs = explode(',', $maclist);

	// Loop through and look for invalid MACs.
	foreach ($macs as $mac) {
		if (!is_macaddr($mac, true)) {
			return false;
		}
	}

	return true;
}

/*
 * The stored settings the DHCP Server page edits on interface $if: additional
 * pool $pool (its position) when it exists, an empty pool for $act "newpool",
 * else the interface's settings; null when the interface has no DHCP
 * settings at all.
 */
function dhcp_server_conf(string $if, $pool, $act): ?array {
	if (empty(config_get_path("dhcpd/{$if}"))) {
		return (null);
	}

	if (is_numeric($pool) && config_get_path("dhcpd/{$if}/pool/{$pool}")) {
		$dhcpdconf = config_get_path("dhcpd/{$if}/pool/{$pool}");
	} elseif ($act == "newpool") {
		$dhcpdconf = array();
	} else {
		$dhcpdconf = config_get_path("dhcpd/{$if}", []);
	}

	array_init_path($dhcpdconf, "staticmap");

	return ($dhcpdconf);
}

/*
 * The DHCP Server page's form for $dhcpdconf (from dhcp_server_conf(); null:
 * an empty form). $is_pool: the form of an additional address pool.
 */
function dhcp_server_form(?array $dhcpdconf, bool $is_pool): array {
	$pconfig = [];

	if (is_array($dhcpdconf)) {
		// Global Options
		if (!$is_pool) {
			$pconfig['enable'] = isset($dhcpdconf['enable']);
			$pconfig['staticarp'] = isset($dhcpdconf['staticarp']);
			// No reason to specify this per-pool, per the dhcpd.conf man page it needs to be in every
			//	 pool and should be specified in every pool both nodes share, so we'll treat it as global
			$pconfig['failover_peerip'] = $dhcpdconf['failover_peerip'];

			// dhcpleaseinlocaltime is global to all interfaces. So if it is selected on any interface,
			// then show it true/checked.
			$dhcpleaseinlocaltime = null;
			foreach (config_get_path('dhcpd', []) as $dhcpdifitem) {
				if (empty($dhcpdifitem)) {
					continue;
				}
				$dhcpleaseinlocaltime = $dhcpdifitem['dhcpleaseinlocaltime'];
				if ($dhcpleaseinlocaltime) {
					break;
				}
			}

			$pconfig['dhcpleaseinlocaltime'] = $dhcpleaseinlocaltime;
			$pconfig['dnsregpolicy'] = $dhcpdconf['dnsregpolicy'];
			$pconfig['earlydnsregpolicy'] = $dhcpdconf['earlydnsregpolicy'];
		} else {
			// Options that exist only in pools
			$pconfig['descr'] = $dhcpdconf['descr'];
		}

		// Options that can be global or per-pool.
		if (is_array($dhcpdconf['range'])) {
			$pconfig['range_from'] = $dhcpdconf['range']['from'];
			$pconfig['range_to'] = $dhcpdconf['range']['to'];
		}

		$pconfig['deftime'] = $dhcpdconf['defaultleasetime'];
		$pconfig['maxtime'] = $dhcpdconf['maxleasetime'];
		$pconfig['gateway'] = $dhcpdconf['gateway'];
		$pconfig['domain'] = $dhcpdconf['domain'];
		$pconfig['domainsearchlist'] = $dhcpdconf['domainsearchlist'];
		list($pconfig['wins1'], $pconfig['wins2']) = $dhcpdconf['winsserver'];
		list($pconfig['dns1'], $pconfig['dns2'], $pconfig['dns3'], $pconfig['dns4']) = $dhcpdconf['dnsserver'];
		$pconfig['ignorebootp'] = isset($dhcpdconf['ignorebootp']);

		if (isset($dhcpdconf['denyunknown'])) {
			$pconfig['denyunknown'] = empty($dhcpdconf['denyunknown']) ? "enabled" : $dhcpdconf['denyunknown'];
		} else {
			$pconfig['denyunknown'] = "disabled";
		}

		$pconfig['ignoreclientuids'] = isset($dhcpdconf['ignoreclientuids']);
		$pconfig['nonak'] = isset($dhcpdconf['nonak']);
		$pconfig['ddnsdomain'] = $dhcpdconf['ddnsdomain'];
		$pconfig['ddnsdomainprimary'] = $dhcpdconf['ddnsdomainprimary'];
		$pconfig['ddnsdomainprimaryport'] = $dhcpdconf['ddnsdomainprimaryport'];
		$pconfig['ddnsdomainsecondary'] = $dhcpdconf['ddnsdomainsecondary'];
		$pconfig['ddnsdomainsecondaryport'] = $dhcpdconf['ddnsdomainsecondaryport'];
		$pconfig['ddnsdomainkeyname'] = $dhcpdconf['ddnsdomainkeyname'];
		$pconfig['ddnsdomainkeyalgorithm'] = $dhcpdconf['ddnsdomainkeyalgorithm'];
		$pconfig['ddnsdomainkey'] = $dhcpdconf['ddnsdomainkey'];
		$pconfig['ddnsupdate'] = isset($dhcpdconf['ddnsupdate']);
		$pconfig['ddnsforcehostname'] = isset($dhcpdconf['ddnsforcehostname']);
		$pconfig['mac_allow'] = $dhcpdconf['mac_allow'];
		$pconfig['mac_deny'] = $dhcpdconf['mac_deny'];
		list($pconfig['ntp1'], $pconfig['ntp2'], $pconfig['ntp3'], $pconfig['ntp4']) = $dhcpdconf['ntpserver'];
		$pconfig['tftp'] = $dhcpdconf['tftp'];
		$pconfig['ldap'] = $dhcpdconf['ldap'];
		$pconfig['netboot'] = isset($dhcpdconf['netboot']);
		$pconfig['nextserver'] = $dhcpdconf['nextserver'];
		$pconfig['filename'] = $dhcpdconf['filename'];
		$pconfig['filename32'] = $dhcpdconf['filename32'];
		$pconfig['filename64'] = $dhcpdconf['filename64'];
		$pconfig['filename32arm'] = $dhcpdconf['filename32arm'];
		$pconfig['filename64arm'] = $dhcpdconf['filename64arm'];
		$pconfig['uefihttpboot'] = $dhcpdconf['uefihttpboot'];
		$pconfig['rootpath'] = $dhcpdconf['rootpath'];
		$pconfig['netmask'] = $dhcpdconf['netmask'];
		$pconfig['numberoptions'] = $dhcpdconf['numberoptions'];
		$pconfig['statsgraph'] = $dhcpdconf['statsgraph'];
		$pconfig['disablepingcheck'] = $dhcpdconf['disablepingcheck'];
		$pconfig['ddnsclientupdates'] = $dhcpdconf['ddnsclientupdates'];

		// OMAPI Settings
		if(isset($dhcpdconf['omapi_port'])) {
			$pconfig['omapi_port'] = $dhcpdconf['omapi_port'];
			$pconfig['omapi_key'] = $dhcpdconf['omapi_key'];
			$pconfig['omapi_key_algorithm'] = $dhcpdconf['omapi_key_algorithm'];
		}

		if (dhcp_is_backend('kea')) {
			$pconfig['custom_kea_config'] = base64_decode($dhcpdconf['custom_kea_config']);
		}
	}

	return ($pconfig);
}

/*
 * Save the DHCP Server page's form $post for interface $if: additional
 * address pool $pool (its position), a new pool when $act is "newpool", else
 * the interface's settings. Validation, the ISC and Kea branches and the
 * staging ("dhcpd" dirty) are the page's. Returns ['input_errors' => [...],
 * 'pconfig' => the form to show again, 'saved' => true when saved, 'id' =>
 * the saved pool's position, 'dhcpdconf' => the saved settings,
 * 'missing_pool' => true when $pool names no pool (nothing saved; the page
 * goes back to its start)].
 */
function dhcp_server_save(string $if, $pool, $act, array $post): array {
	$input_errors = [];
	$ret = ['input_errors' => [], 'pconfig' => $post, 'saved' => false, 'missing_pool' => false, 'id' => null, 'dhcpdconf' => null];

	/* only an interface the page offers: a static IPv4 subnet smaller than /31 (it was reachable with ?if=) */
	$ctx = dhcp_iface_context($if);
	if (($ctx === null) || !$ctx['eligible']) {
		$ret['input_errors'][] = gettext('The DHCP Server requires a static IPv4 subnet large enough to serve addresses to clients.');
		return ($ret);
	}

	$iflist = get_configured_interface_with_descr();
	$dnsregpolicy_values = dhcp_server_dnsregpolicy_values();
	$dhcpdconf = dhcp_server_conf($if, $pool, $act);
	$ifcfgip = config_get_path("interfaces/{$if}/ipaddr");
	$ifcfgsn = config_get_path("interfaces/{$if}/subnet");
	$subnet_start = gen_subnetv4($ifcfgip, $ifcfgsn);
	$subnet_end = gen_subnetv4_max($ifcfgip, $ifcfgsn);

	$pconfig = $post;

	$numberoptions = array();
	for ($x = 0; $x < 99; $x++) {
		if (isset($post["number{$x}"]) && ctype_digit(strval($post["number{$x}"]))) {
			if ($post["number{$x}"] < 1 || $post["number{$x}"] > 254) {
				$input_errors[] = gettext("The DHCP option must be a number between 1 and 254.");
				continue;
			}
			$numbervalue = array();
			$numbervalue['number'] = htmlspecialchars($post["number{$x}"]);
			$numbervalue['type'] = htmlspecialchars($post["itemtype{$x}"]);
			$numbervalue['value'] = base64_encode($post["value{$x}"]);
			$numberoptions['item'][] = $numbervalue;
		}
	}

	// Reload the new pconfig variable that the form uses.
	$pconfig['numberoptions'] = $numberoptions;

	/* input validation */

	/*
	 * Check the OMAPI settings
	 * - Make sure that if the port is defined, that it is valid and isn't in use
	 * - Make sure the key is defined and the length is appropriate for the selected algorithm
	 * - Generate a new key if selected
	 */
	if (!empty($post['omapi_port'])) {
		// Check the port entry
		switch(true){
			case !is_port($post['omapi_port']) || $post['omapi_port'] <= 1024:
				$input_errors[] = gettext("The specified OMAPI port number is invalid. Port number must be between 1024 and 65635.");
				break;
			case is_port_in_use($post['omapi_port']) && $post['omapi_port'] != $dhcpdconf['omapi_port']:
				$input_errors[] = gettext("Specified port number for OMAPI is in use. Please choose another port or consider using the default.");
				break;
		}

		// Define the minimum base64 character length for each algorithm
		$key_char_len_by_alg = array(
			'hmac-md5' => 24,
			'hmac-sha1' => 28,
			'hmac-sha224' => 40,
			'hmac-sha256' => 44,
			'hmac-sha384' => 64,
			'hmac-sha512' => 88
		);

		// Generate a key if checked
		if ($post['omapi_gen_key'] == "yes") {
			// Figure out the key bits from the selected algorithm
			switch ($post['omapi_key_algorithm']) {
				case "hmac-md5":
					$key_bit_len = 128;
					break;
				case "hmac-sha1":
					$key_bit_len = 160;
					break;
				default:
					$key_bit_len = str_replace("hmac-sha","",$post['omapi_key_algorithm']);
					break;
			}

			// Convert the bits to bytes
			$key_bytes_len = $key_bit_len / 8; // 8 bits = 1 Byte

			// Generate random bytes based on key length
			$ran_bytes = openssl_random_pseudo_bytes($key_bytes_len);

			// Encode the bytes to get the key string
			$key_str = base64_encode($ran_bytes);

			// Set the key
			$post['omapi_key'] = $key_str;
			$pconfig['omapi_key'] = $key_str;

			// Uncheck the generate box
			unset($post['omapi_gen_key']);
			unset($pconfig['omapi_gen_key']);
		} elseif (!empty($post['omapi_key'])) { // Check the key if it's not being generated
			if (strlen($post['omapi_key']) < $key_char_len_by_alg[$post['omapi_key_algorithm']]) {
				$input_errors[] = gettext("Please specify a valid OMAPI key. Key does not meet the minimum length requirement of {$key_char_len_by_alg[$post['omapi_key_algorithm']]} for the selected algorithm {$post['omapi_key_algorithm']}.");
			}
		} else {
			$input_errors[] = gettext("A key is required when OMAPI is enabled (port specified).");
		}
	}

	// Note: if DHCP Server is not enabled, then it is OK to adjust other parameters without specifying range from-to.
	if ($post['enable'] || is_numeric($pool) || $act == "newpool") {
		$reqdfields = explode(" ", "range_from range_to");
		$reqdfieldsn = array(gettext("Range begin"), gettext("Range end"));

		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
	}

	if (($post['nonak']) && !empty($post['failover_peerip'])) {
		$input_errors[] = gettext("Ignore Denied Clients may not be used when a Failover Peer IP is defined.");
	}

	if ($post['range_from'] && !is_ipaddrv4($post['range_from'])) {
		$input_errors[] = gettext("A valid IPv4 address must be specified for range from.");
	}
	if ($post['range_to'] && !is_ipaddrv4($post['range_to'])) {
		$input_errors[] = gettext("A valid IPv4 address must be specified for range to.");
	}
	if (($post['range_from'] && !$post['range_to']) || ($post['range_to'] && !$post['range_from'])) {
		$input_errors[] = gettext("Range From and Range To must both be entered.");
	}
	if (($post['gateway'] && $post['gateway'] != "none" && !is_ipaddrv4($post['gateway']))) {
		$input_errors[] = gettext("A valid IP address must be specified for the gateway.");
	}
	if (($post['wins1'] && !is_ipaddrv4($post['wins1'])) || ($post['wins2'] && !is_ipaddrv4($post['wins2']))) {
		$input_errors[] = gettext("A valid IP address must be specified for the primary/secondary WINS servers.");
	}
	/* the page's interface (it read the posted "if" field: a post without it skipped this check) */
	$parent_ip = get_interface_ip($if);
	if (is_ipaddrv4($parent_ip) && $post['gateway'] && $post['gateway'] != "none") {
		$parent_sn = get_interface_subnet($if);
		if (!ip_in_subnet($post['gateway'], gen_subnet($parent_ip, $parent_sn) . "/" . $parent_sn) && !ip_in_interface_alias_subnet($if, $post['gateway'])) {
			$input_errors[] = sprintf(gettext("The gateway address %s does not lie within the chosen interface's subnet."), $post['gateway']);
		}
	}

	if (($post['dns1'] && !is_ipaddrv4($post['dns1'])) || ($post['dns2'] && !is_ipaddrv4($post['dns2'])) || ($post['dns3'] && !is_ipaddrv4($post['dns3'])) || ($post['dns4'] && !is_ipaddrv4($post['dns4']))) {
		$input_errors[] = gettext("A valid IP address must be specified for each of the DNS servers.");
	}

	/* the DNS registration policy (this checked the early policy twice) */
	if ($post['dnsregpolicy'] && !array_key_exists($post['dnsregpolicy'], $dnsregpolicy_values)) {
		$input_errors[] = gettext("Invalid DNS Registration Policy.");
	}

	if ($post['earlydnsregpolicy'] && !array_key_exists($post['earlydnsregpolicy'], $dnsregpolicy_values)) {
		$input_errors[] = gettext("Invalid Early DNS Registration Policy.");
	}

	if ($post['deftime'] && (!is_numeric($post['deftime']) || ($post['deftime'] < 60))) {
		$input_errors[] = gettext("The default lease time must be at least 60 seconds.");
	}

	// Default value if it's empty
	$deftime = (is_numeric($post['deftime'])) ? $post['deftime'] : 7200;
	foreach (config_get_path('captiveportal', []) as $cpZone => $cpdata) {
		if (!isset($cpdata['enable'])) {
			continue;
		}
		if (!isset($cpdata['timeout']) || !is_numeric($cpdata['timeout'])) {
			continue;
		}
		$cp_ifs = explode(',', $cpdata['interface']);
		if (!in_array($if, $cp_ifs)) {
			continue;
		}
		if ($cpdata['timeout'] > $deftime) {
			$input_errors[] = sprintf(gettext(
				'The Captive Portal zone (%1$s) has Hard Timeout parameter set to a value bigger than Default lease time (%2$s).'), $cpZone, $deftime);
		}
	}

	if ($post['maxtime'] && (!is_numeric($post['maxtime']) || ($post['maxtime'] < 60) || ($post['maxtime'] < $post['deftime']))) {
		$input_errors[] = gettext("The maximum lease time must be at least 60 seconds, and the same value or greater than the default lease time.");
	}
	if ($post['ddnsupdate']) {
		if (!is_domain($post['ddnsdomain'])) {
			$input_errors[] = gettext("A valid domain name must be specified for the dynamic DNS registration.");
		}
		if (!is_ipaddr($post['ddnsdomainprimary'])) {
			$input_errors[] = gettext("A valid primary domain name server IP address must be specified for the dynamic domain name.");
		}
		if ($post['ddnsdomainprimaryport'] && !is_port($post['ddnsdomainprimaryport'])) {
			$input_errors[] = gettext('A valid primary DDNS port number must be specified.');
		}
		if ($post['ddnsdomainsecondaryport'] && !is_port($post['ddnsdomainsecondaryport'])) {
			$input_errors[] = gettext('A valid secondary DDNS port number must be specified.');
		}
		if (!empty($post['ddnsdomainsecondary']) && !is_ipaddr($post['ddnsdomainsecondary'])) {
			$input_errors[] = gettext("A valid secondary domain name server IP address must be specified for the dynamic domain name.");
		}
		if (dhcp_is_backend('isc')) {
			if (!$post['ddnsdomainkeyname'] || !$post['ddnsdomainkeyalgorithm'] || !$post['ddnsdomainkey']) {
				$input_errors[] = gettext("A valid domain key name, algorithm and secret must be specified.");
			}
		} elseif (dhcp_is_backend('kea')) {
			if (!$post['ddnsdomainkeyalgorithm'] || !$post['ddnsdomainkey']) {
				$input_errors[] = gettext("A valid domain key algorithm and secret must be specified.");
			}
		}
		if (preg_match('/[^A-Za-z0-9\.\-\_]/', $post['ddnsdomainkeyname'])) {
			$input_errors[] = gettext("The domain key name may only contain the characters a-z, A-Z, 0-9, '-', '_' and '.'");
		}
		if ($post['ddnsdomainkey'] && !base64_decode($post['ddnsdomainkey'], true)) {
			$input_errors[] = gettext("The domain key secret must be a Base64 encoded value.");
		}
	}
	if ($post['domainsearchlist']) {
		$domain_array = preg_split("/[ ;]+/", $post['domainsearchlist']);
		foreach ($domain_array as $curdomain) {
			if (!is_domain($curdomain)) {
				$input_errors[] = gettext("A valid domain search list must be specified.");
				break;
			}
		}
	}

	// Validate MACs
	if (!empty($post['mac_allow']) && !dhcp_validate_partial_mac_list($post['mac_allow'])) {
		$input_errors[] = gettext("If a mac allow list is specified, it must contain only valid partial MAC addresses.");
	}
	if (!empty($post['mac_deny']) && !dhcp_validate_partial_mac_list($post['mac_deny'])) {
		$input_errors[] = gettext("If a mac deny list is specified, it must contain only valid partial MAC addresses.");
	}

	if (($post['ntp1'] && !(is_ipaddrv4($post['ntp1']) || (dhcp_is_backend('isc') && is_hostname($post['ntp1'])))) ||
	    ($post['ntp2'] && !(is_ipaddrv4($post['ntp2']) || (dhcp_is_backend('isc') && is_hostname($post['ntp2'])))) ||
	    ($post['ntp3'] && !(is_ipaddrv4($post['ntp3']) || (dhcp_is_backend('isc') && is_hostname($post['ntp3'])))) ||
	    ($post['ntp4'] && !(is_ipaddrv4($post['ntp4']) || (dhcp_is_backend('isc') && is_hostname($post['ntp4']))))) {
		if (dhcp_is_backend('isc')) {
			$input_errors[] = gettext("A valid IP address or hostname must be specified for the NTP servers.");
		} else {
			$input_errors[] = gettext("A valid IP address must be specified for the NTP servers.");
		}
	}
	if ($post['domain'] && (!is_domain($post['domain'], false, false))) {
		$input_errors[] = gettext("A valid domain name must be specified for the DNS domain.");
	}
	if ($post['tftp'] && !is_ipaddrv4($post['tftp']) && !is_domain($post['tftp']) && !filter_var($post['tftp'], FILTER_VALIDATE_URL)) {
		$input_errors[] = gettext("A valid IP address, hostname or URL must be specified for the TFTP server.");
	}
	if (($post['nextserver'] && !is_ipaddrv4($post['nextserver']))) {
		$input_errors[] = gettext("A valid IP address must be specified for the network boot server.");
	}

	if (gen_subnet($ifcfgip, $ifcfgsn) == $post['range_from']) {
		$input_errors[] = gettext("The network address cannot be used in the starting subnet range.");
	}
	if (gen_subnet_max($ifcfgip, $ifcfgsn) == $post['range_to']) {
		$input_errors[] = gettext("The broadcast address cannot be used in the ending subnet range.");
	}

	// Disallow a range that includes the virtualip
	foreach (config_get_path('virtualip/vip', []) as $vip) {
		if ($vip['interface'] == $if) {
			if ($vip['subnet'] && is_inrange_v4($vip['subnet'], $post['range_from'], $post['range_to'])) {
				$input_errors[] = sprintf(gettext("The subnet range cannot overlap with virtual IP address %s."), $vip['subnet']);
			}
		}
	}

	$noip = false;
	foreach (config_get_path("dhcpd/{$if}/staticmap", []) as $map) {
		if (empty($map['ipaddr'])) {
			$noip = true;
		}
	}

	if ($post['staticarp'] && $noip) {
		$input_errors[] = gettext("Cannot enable static ARP when there are static map entries without IP addresses. Ensure all static maps have IP addresses and try again.");
	}

	if (is_array($pconfig['numberoptions']['item'])) {
		foreach ($pconfig['numberoptions']['item'] as $numberoption) {
			$numberoption_value = base64_decode($numberoption['value']);
			if ($numberoption['type'] == 'text' && strstr($numberoption_value, '"')) {
				$input_errors[] = gettext("Text type cannot include quotation marks.");
			} else if ($numberoption['type'] == 'string' && !preg_match('/^"[^"]*"$/', $numberoption_value) && !preg_match('/^[0-9a-f]{2}(?:\:[0-9a-f]{2})*$/i', $numberoption_value)) {
				$input_errors[] = gettext("String type must be enclosed in quotes like \"this\" or must be a series of octets specified in hexadecimal, separated by colons, like 01:23:45:67:89:ab:cd:ef");
			} else if ($numberoption['type'] == 'boolean' && $numberoption_value != 'true' && $numberoption_value != 'false' && $numberoption_value != 'on' && $numberoption_value != 'off') {
				$input_errors[] = gettext("Boolean type must be true, false, on, or off.");
			} else if ($numberoption['type'] == 'unsigned integer 8' && (!is_numeric($numberoption_value) || $numberoption_value < 0 || $numberoption_value > 255)) {
				$input_errors[] = gettext("Unsigned 8-bit integer type must be a number in the range 0 to 255.");
			} else if ($numberoption['type'] == 'unsigned integer 16' && (!is_numeric($numberoption_value) || $numberoption_value < 0 || $numberoption_value > 65535)) {
				$input_errors[] = gettext("Unsigned 16-bit integer type must be a number in the range 0 to 65535.");
			} else if ($numberoption['type'] == 'unsigned integer 32' && (!is_numeric($numberoption_value) || $numberoption_value < 0 || $numberoption_value > 4294967295)) {
				$input_errors[] = gettext("Unsigned 32-bit integer type must be a number in the range 0 to 4294967295.");
			} else if ($numberoption['type'] == 'signed integer 8' && (!is_numeric($numberoption_value) || $numberoption_value < -128 || $numberoption_value > 127)) {
				$input_errors[] = gettext("Signed 8-bit integer type must be a number in the range -128 to 127.");
			} else if ($numberoption['type'] == 'signed integer 16' && (!is_numeric($numberoption_value) || $numberoption_value < -32768 || $numberoption_value > 32767)) {
				$input_errors[] = gettext("Signed 16-bit integer type must be a number in the range -32768 to 32767.");
			} else if ($numberoption['type'] == 'signed integer 32' && (!is_numeric($numberoption_value) || $numberoption_value < -2147483648 || $numberoption_value > 2147483647)) {
				$input_errors[] = gettext("Signed 32-bit integer type must be a number in the range -2147483648 to 2147483647.");
			} else if ($numberoption['type'] == 'ip-address' && !is_ipaddrv4($numberoption_value) && !is_hostname($numberoption_value)) {
				$input_errors[] = gettext("IP address or host type must be an IP address or host name.");
			}
		}
	}

	if ((!isset($pool) || !is_numeric($pool)) && $act != "newpool") {
		/* If enabling DHCP Server, make sure that the DHCP Relay isn't enabled on this interface */
		if ($post['enable'] && config_path_enabled('dhcrelay') &&
		    (stristr(config_get_path('dhcrelay/interface', ''), $if) !== false)) {
			$input_errors[] = sprintf(gettext(
			    "The DHCP relay on the %s interface must be disabled before enabling the DHCP server."),
			    $iflist[$if]);
		}

		/* If disabling DHCP Server, make sure that DHCP registration isn't enabled for DNS forwarder/resolver */
		if (!$post['enable']) {
			/* Find out how many other interfaces have DHCP enabled. */
			$dhcp_enabled_count = 0;
			foreach (config_get_path('dhcpd', []) as $dhif => $dhcps) {
				if ($dhif == $if) {
					/* Skip this interface, we only want to know how many others are enabled. */
					continue;
				}
				if (config_path_enabled("dhcpd/{$dhif}")) {
					$dhcp_enabled_count++;
				}
			}

			if (config_path_enabled('dnsmasq') &&
			    ($dhcp_enabled_count == 0) &&
			    (config_path_enabled('dnsmasq', 'regdhcp') ||
			    config_path_enabled('dnsmasq', 'regdhcpstatic') ||
			    config_path_enabled('dnsmasq', 'dhcpfirst'))) {
				$input_errors[] = gettext(
				    "DHCP Registration features in the DNS Forwarder are active and require at least one enabled DHCP Server.");
			}
			if (config_path_enabled('unbound') &&
			    ($dhcp_enabled_count == 0) &&
			    (config_path_enabled('unbound', 'regdhcp') ||
			    config_path_enabled('unbound', 'regdhcpstatic'))) {
				$input_errors[] = gettext(
				    "DHCP Registration features in the DNS Resolver are active and require at least one enabled DHCP Server.");
			}
		}
	}

	// If nothing is wrong so far, and we have range from and to, then check conditions related to the values of range from and to.
	if (!$input_errors && $post['range_from'] && $post['range_to']) {
		/* make sure the range lies within the current subnet */
		if (ip_greater_than($post['range_from'], $post['range_to'])) {
			$input_errors[] = gettext("The range is invalid (first element higher than second element).");
		}

		if (!is_inrange_v4($post['range_from'], $subnet_start, $subnet_end) ||
			!is_inrange_v4($post['range_to'], $subnet_start, $subnet_end)) {
			$input_errors[] = gettext("The specified range lies outside of the current subnet.");
		}

		if (is_numeric($pool) || ($act === 'newpool')) {
			if (is_inrange_v4($post['range_from'],
				config_get_path("dhcpd/{$if}/range/from"),
				config_get_path("dhcpd/{$if}/range/to")) ||
				is_inrange_v4($post['range_to'],
				config_get_path("dhcpd/{$if}/range/from"),
				config_get_path("dhcpd/{$if}/range/to"))) {
				$input_errors[] = gettext('The specified range must not be within the primary address range for this interface.');
			}
		}

		foreach (config_get_path("dhcpd/{$if}/pool", []) as $id => $p) {
			if (is_numeric($pool) && ($id == $pool)) {
				continue;
			}

			if (is_inrange_v4($post['range_from'], $p['range']['from'], $p['range']['to']) ||
			    is_inrange_v4($post['range_to'], $p['range']['from'], $p['range']['to'])) {
				$input_errors[] = gettext('The specified range must not be within the range configured on another DHCP pool for this interface.');
				break;
			}
		}

		foreach (config_get_path("dhcpd/{$if}/staticmap", []) as $map) {
			if (empty($map['ipaddr'])) {
				continue;
			}
			if (is_inrange_v4($map['ipaddr'], $post['range_from'], $post['range_to'])) {
				$input_errors[] = sprintf(gettext("The DHCP range cannot overlap any static DHCP mappings."));
				break;
			}
		}
	}

	/* validate custom config */
	if (dhcp_is_backend('kea')) {
		kea_custom_config_enforce(is_array($dhcpdconf) ? array_get_path($dhcpdconf, 'custom_kea_config') : null, $input_errors, $post);
		if (!empty($post['custom_kea_config'])) {
			$json = json_decode($post['custom_kea_config'], true);
			if (!is_array($json) || (json_last_error() !== JSON_ERROR_NONE)) {
				$input_errors[] = gettext('Custom configuration is not a well formed JSON object.');
			}
		}
	}

	if (!$input_errors) {
		if (!is_numeric($pool)) {
			if ($act == "newpool") {
				$dhcpdconf = array();
			} else {
				$dhcpdconf = config_get_path("dhcpd/{$if}", []);
			}
		} else {
			if (is_array(config_get_path("dhcpd/{$if}/pool/{$pool}"))) {
				$dhcpdconf = config_get_path("dhcpd/{$if}/pool/{$pool}");
			} else {
				// Someone specified a pool but it doesn't exist. Punt.
				$ret['missing_pool'] = true;
				return ($ret);
			}
		}
		if (!is_array($dhcpdconf)) {
			$dhcpdconf = array();
		}
		if (!is_array($dhcpdconf['range'])) {
			$dhcpdconf['range'] = array();
		}

		$old_dhcpdconf = $dhcpdconf;

		$dhcpd_enable_changed = false;

		// Global Options
		if (!is_numeric($pool) && !($act == "newpool")) {
			$old_dhcpd_enable = isset($dhcpdconf['enable']);
			$new_dhcpd_enable = ($post['enable']) ? true : false;
			if ($old_dhcpd_enable != $new_dhcpd_enable) {
				/* DHCP has been enabled or disabled. The pf ruleset will need to be rebuilt to allow or disallow DHCP. */
				$dhcpd_enable_changed = true;
			}

			$dhcpdconf['enable'] = $new_dhcpd_enable;
			$dhcpdconf['staticarp'] = ($post['staticarp']) ? true : false;
			$previous = $dhcpdconf['failover_peerip'];
			if ($previous != $post['failover_peerip']) {
				mwexec("/bin/rm -rf /var/dhcpd/var/db/*");
			}

			$dhcpdconf['failover_peerip'] = $post['failover_peerip'];
			// dhcpleaseinlocaltime is global to all interfaces. So update the setting on all interfaces.
			foreach (config_get_path('dhcpd', []) as $dhcpdifkey => $keyvalue) {
				if (empty($keyvalue)) {
					continue;
				}
				if (isset($post['dhcpleaseinlocaltime'])) {
					config_set_path("dhcpd/{$dhcpdifkey}/dhcpleaseinlocaltime", $post['dhcpleaseinlocaltime']);
				} else {
					config_del_path("dhcpd/{$dhcpdifkey}/dhcpleaseinlocaltime");
				}
			}

			$dhcpdconf['dnsregpolicy'] = $post['dnsregpolicy'];
			$dhcpdconf['earlydnsregpolicy'] = $post['earlydnsregpolicy'];
		} else {
			// Options that exist only in pools
			$dhcpdconf['descr'] = $post['descr'];
		}

		// Options that can be global or per-pool.
		$dhcpdconf['range']['from'] = $post['range_from'];
		$dhcpdconf['range']['to'] = $post['range_to'];
		$dhcpdconf['defaultleasetime'] = $post['deftime'];
		$dhcpdconf['maxleasetime'] = $post['maxtime'];
		$dhcpdconf['netmask'] = $post['netmask'];

		unset($dhcpdconf['winsserver']);
		if ($post['wins1']) {
			$dhcpdconf['winsserver'][] = $post['wins1'];
		}
		if ($post['wins2']) {
			$dhcpdconf['winsserver'][] = $post['wins2'];
		}

		unset($dhcpdconf['dnsserver']);
		if ($post['dns1']) {
			$dhcpdconf['dnsserver'][] = $post['dns1'];
		}
		if ($post['dns2']) {
			$dhcpdconf['dnsserver'][] = $post['dns2'];
		}
		if ($post['dns3']) {
			$dhcpdconf['dnsserver'][] = $post['dns3'];
		}
		if ($post['dns4']) {
			$dhcpdconf['dnsserver'][] = $post['dns4'];
		}

		$dhcpdconf['gateway'] = $post['gateway'];
		$dhcpdconf['domain'] = $post['domain'];
		$dhcpdconf['domainsearchlist'] = $post['domainsearchlist'];
		$dhcpdconf['ignorebootp'] = ($post['ignorebootp']) ? true : false;

		if (in_array($post['denyunknown'], array("enabled", "class"))) {
			$dhcpdconf['denyunknown'] = $post['denyunknown'];
		} else {
			unset($dhcpdconf['denyunknown']);
		}

		$dhcpdconf['ignoreclientuids'] = ($post['ignoreclientuids']) ? true : false;
		$dhcpdconf['nonak'] = ($post['nonak']) ? true : false;
		$dhcpdconf['ddnsdomain'] = $post['ddnsdomain'];
		$dhcpdconf['ddnsdomainprimary'] = $post['ddnsdomainprimary'];
		$dhcpdconf['ddnsdomainprimaryport'] = $post['ddnsdomainprimaryport'];
		$dhcpdconf['ddnsdomainsecondary'] = (!empty($post['ddnsdomainsecondary'])) ? $post['ddnsdomainsecondary'] : '';
		$dhcpdconf['ddnsdomainsecondaryport'] = $post['ddnsdomainsecondaryport'];
		$dhcpdconf['ddnsdomainkeyname'] = $post['ddnsdomainkeyname'];
		$dhcpdconf['ddnsdomainkeyalgorithm'] = $post['ddnsdomainkeyalgorithm'];
		$dhcpdconf['ddnsdomainkey'] = $post['ddnsdomainkey'];
		$dhcpdconf['ddnsupdate'] = ($post['ddnsupdate']) ? true : false;
		$dhcpdconf['ddnsforcehostname'] = ($post['ddnsforcehostname']) ? true : false;
		$dhcpdconf['mac_allow'] = $post['mac_allow'];
		$dhcpdconf['mac_deny'] = $post['mac_deny'];
		if ($post['disablepingcheck']) {
			$dhcpdconf['disablepingcheck'] = $post['disablepingcheck'];
		} else {
			unset($dhcpdconf['disablepingcheck']);
		}
		$dhcpdconf['ddnsclientupdates'] = $post['ddnsclientupdates'];

		unset($dhcpdconf['ntpserver']);
		if ($post['ntp1']) {
			$dhcpdconf['ntpserver'][] = $post['ntp1'];
		}
		if ($post['ntp2']) {
			$dhcpdconf['ntpserver'][] = $post['ntp2'];
		}
		if ($post['ntp3']) {
			$dhcpdconf['ntpserver'][] = $post['ntp3'];
		}
		if ($post['ntp4']) {
			$dhcpdconf['ntpserver'][] = $post['ntp4'];
		}

		$dhcpdconf['tftp'] = $post['tftp'];
		$dhcpdconf['ldap'] = $post['ldap'];
		$dhcpdconf['netboot'] = ($post['netboot']) ? true : false;
		$dhcpdconf['nextserver'] = $post['nextserver'];
		$dhcpdconf['filename'] = $post['filename'];
		$dhcpdconf['filename32'] = $post['filename32'];
		$dhcpdconf['filename64'] = $post['filename64'];
		$dhcpdconf['filename32arm'] = $post['filename32arm'];
		$dhcpdconf['filename64arm'] = $post['filename64arm'];
		$dhcpdconf['uefihttpboot'] = $post['uefihttpboot'];
		$dhcpdconf['rootpath'] = $post['rootpath'];

		if (empty($post['statsgraph']) == isset($dhcpdconf['statsgraph'])) {
			$enable_rrd_graphing = true;
		}
		if (!empty($post['statsgraph'])) {
			$dhcpdconf['statsgraph'] = $post['statsgraph'];
		} elseif (isset($dhcpdconf['statsgraph'])) {
			unset($dhcpdconf['statsgraph']);
		}
		if ($enable_rrd_graphing) {
			enable_rrd_graphing();
		}

		// Handle the custom options rowhelper
		if (isset($dhcpdconf['numberoptions']['item'])) {
			unset($dhcpdconf['numberoptions']['item']);
		}

		$dhcpdconf['numberoptions'] = $numberoptions;

		// OMAPI Settings
		if ($post['omapi_port'] == ""){
			unset($dhcpdconf['omapi_port']);
			unset($dhcpdconf['omapi_key']);
			unset($dhcpdconf['omapi_key_algorithm']);

			unset($pconfig['omapi_port']);
			unset($pconfig['omapi_key']);
			unset($pconfig['omapi_key_algorithm']);
		} else {
			$dhcpdconf['omapi_port'] = $post['omapi_port'];
			$dhcpdconf['omapi_key'] = $post['omapi_key'];
			$dhcpdconf['omapi_key_algorithm'] = $post['omapi_key_algorithm'];
		}

		if (dhcp_is_backend('kea')) {
			$dhcpdconf['custom_kea_config'] = base64_encode($post['custom_kea_config']);
		}

		if (is_numeric($pool) && is_array(config_get_path("dhcpd/{$if}/pool/{$pool}"))) {
			config_set_path("dhcpd/{$if}/pool/{$pool}", $dhcpdconf);
			$ret['id'] = (int)$pool;
		} elseif ($act == "newpool") {
			config_set_path("dhcpd/{$if}/pool/", $dhcpdconf);
			$ret['id'] = array_key_last(config_get_path("dhcpd/{$if}/pool", []));
		} else {
			config_set_path("dhcpd/{$if}", $dhcpdconf);
		}

		mark_subsystem_dirty('dhcpd');

		write_config(gettext("DHCP Server - Settings changed for interface " . strtoupper($if)));

		$ret['saved'] = true;
		$ret['dhcpdconf'] = $dhcpdconf;
	}

	$ret['input_errors'] = $input_errors;
	$ret['pconfig'] = $pconfig;
	return ($ret);
}

/* The additional pools of interface $if as an HTML table for a Form_StaticText element. */
function dhcp_build_pooltable(string $if): string {
	$pooltbl =	'<div class="contains-table table-responsive">';
	$pooltbl .=		'<table class="table table-striped table-hover table-sm">';
	$pooltbl .=			'<thead>';
	$pooltbl .=				'<tr>';
	$pooltbl .=					'<th>' . gettext("Pool Start") . '</th>';
	$pooltbl .=					'<th>' . gettext("Pool End") . '</th>';
	$pooltbl .=					'<th>' . gettext("Description") . '</th>';
	$pooltbl .=					'<th>' . gettext("Actions") . '</th>';
	$pooltbl .=				'</tr>';
	$pooltbl .=			'</thead>';
	$pooltbl .=			'<tbody>';

	$i = 0;
	foreach (config_get_path("dhcpd/{$if}/pool", []) as $poolent) {
		if (!empty($poolent['range']['from']) && !empty($poolent['range']['to'])) {
			$pooltbl .= '<tr>';
			$pooltbl .= '<td ondblclick="document.location=\'services_dhcp.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '\';">' .
						htmlspecialchars($poolent['range']['from']) . '</td>';

			$pooltbl .= '<td ondblclick="document.location=\'services_dhcp.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '\';">' .
						htmlspecialchars($poolent['range']['to']) . '</td>';

			$pooltbl .= '<td ondblclick="document.location=\'services_dhcp.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '\';">' .
						htmlspecialchars($poolent['descr']) . '</td>';

			$pooltbl .= '<td><a class="fa-solid fa-pencil" title="'. gettext("Edit pool") . '" href="services_dhcp.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '"></a>';

			$pooltbl .= ' <a class="fa-solid fa-trash-can text-danger" title="'. gettext("Delete pool") . '" href="services_dhcp.php?if=' . htmlspecialchars($if) . '&act=delpool&id=' . $i . '" usepost></a></td>';
			$pooltbl .= '</tr>';
		}
		$i++;
	}

	$pooltbl .=			'</tbody>';
	$pooltbl .=		'</table>';
	$pooltbl .= '</div>';

	return($pooltbl);
}

/*
 * Delete additional pool $id (its position) of interface $if and stage the
 * DHCP server; false when there is no such pool.
 */
function dhcp_pool_delete(string $if, $id): bool {
	if (!is_numericint($id) || !is_array(config_get_path("dhcpd/{$if}/pool/{$id}"))) {
		return (false);
	}
	config_del_path("dhcpd/{$if}/pool/{$id}");
	write_config("DHCP Server pool deleted");
	mark_subsystem_dirty('dhcpd');
	return (true);
}

function dhcp6_staticmap_cmp($a, $b) {
	return ipcmp($a['ipaddrv6'], $b['ipaddrv6']);
}

function dhcp6_staticmaps_sort($ifgui) {
	$dhcpd6_config = config_get_path("dhcpdv6/{$ifgui}/staticmap");
	usort($dhcpd6_config, "dhcp6_staticmap_cmp");
	config_set_path("dhcpdv6/{$ifgui}/staticmap", $dhcpd6_config);
}

/*
 * The edit form of DHCPv6 static mapping $id on interface $if: the stored
 * mapping, or for a new one the fields given in $request (the DHCPv6 leases
 * page links here with duid and hostname).
 */
function dhcp6_staticmap_form(string $if, $id, array $request = []): array {
	$pconfig = [];
	$id = is_numericint($id) ? $id : null;
	$this_map_config = isset($id) ? config_get_path("dhcpdv6/{$if}/staticmap/{$id}") : null;
	if ($this_map_config) {
		$pconfig['duid'] = $this_map_config['duid'];
		$pconfig['hostname'] = $this_map_config['hostname'];
		$pconfig['ipaddrv6'] = $this_map_config['ipaddrv6'];
		$pconfig['filename'] = $this_map_config['filename'];
		$pconfig['rootpath'] = $this_map_config['rootpath'];
		$pconfig['descr'] = $this_map_config['descr'];
		if (dhcp_is_backend('kea')) {
			$pconfig['earlydnsregpolicy'] = $this_map_config['earlydnsregpolicy'];
			$pconfig['pdprefix'] = $this_map_config['pdprefix'];
			$pconfig['custom_kea_config'] = base64_decode($this_map_config['custom_kea_config']);
		}
	} else {
		$pconfig['duid'] = $request['duid'];
		$pconfig['hostname'] = $request['hostname'];
		$pconfig['filename'] = $request['filename'];
		$pconfig['rootpath'] = $request['rootpath'];
		$pconfig['descr'] = $request['descr'];
		if (dhcp_is_backend('kea')) {
			$pconfig['earlydnsregpolicy'] = $request['earlydnsregpolicy'];
			$pconfig['pdprefix'] = $request['pdprefix'];
			$pconfig['custom_kea_config'] = $request['custom_kea_config'];
		}
	}

	return ($pconfig);
}

/*
 * Save the DHCPv6 static mapping edit form $post as mapping $id (null: a
 * new one) on interface $if. The list is kept sorted by IPv6 address.
 * Returns ['input_errors' => [...], 'pconfig' => the form to show again,
 * 'id' => the saved mapping's new position].
 */
function dhcp6_staticmap_save(string $if, $id, array $post): array {
	$input_errors = [];
	$pconfig = $post;

	$ctx = dhcp6_iface_context($if);
	if ($ctx === null) {
		$input_errors[] = gettext('The interface must be a configured interface.');
		return (['input_errors' => $input_errors, 'pconfig' => $pconfig, 'id' => null]);
	}
	$ifcfgsnv6 = $ctx['subnetv6'];
	$dnsregpolicy_values = dhcp_staticmap_dnsregpolicy_values();

	$id = is_numericint($id) ? $id : null;
	$this_map_config = isset($id) ? config_get_path("dhcpdv6/{$if}/staticmap/{$id}") : null;

	/* input validation */
	$reqdfields = explode(" ", "duid");
	$reqdfieldsn = array(gettext("DUID"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($post['hostname']) {
		preg_match("/\-\$/", $post['hostname'], $matches);
		if ($matches) {
			$input_errors[] = gettext("The hostname cannot end with a hyphen according to RFC952");
		}
		if (!is_hostname($post['hostname'])) {
			$input_errors[] = gettext("The hostname can only contain the characters A-Z, 0-9 and '-'.");
		} else {
			if (!is_unqualified_hostname($post['hostname'])) {
				$input_errors[] = gettext("A valid hostname is specified, but the domain name part should be omitted");
			}
		}
	}

	if ($post['earlydnsregpolicy'] && !array_key_exists($post['earlydnsregpolicy'], $dnsregpolicy_values)) {
		$input_errors[] = gettext("Invalid Early DNS Registration Policy.");
	}

	if ($post['ipaddrv6']) {
		if (!is_ipaddrv6($post['ipaddrv6'])) {
			$input_errors[] = gettext("A valid IPv6 address must be specified.");
		} elseif ($ctx['track6']) {
			/* a track6 interface takes the suffix only (this read dhcpdv6/<if>/ipaddrv6, which never exists) */
			$trackifname = config_get_path("interfaces/{$if}/track6-interface");
			$trackcfg = config_get_path("interfaces/{$trackifname}");
			$pdlen = 64 - $trackcfg['dhcp6-ia-pd-len'];
			if (!Net_IPv6::isInNetmask($post['ipaddrv6'], '::', $pdlen)) {
				$input_errors[] = sprintf(gettext(
				    'The prefix (upper %1$s bits) must be zero.  Use the form %2$s'),
				    $pdlen, dhcpv6_pd_str_help($ifcfgsnv6));
			}
		}
	}

	if (dhcp_is_backend('kea')) {
		if ($post['pdprefix']) {
			$parts = explode('/', trim($post['pdprefix']));
			if (!is_ipaddrv6($parts[0]) || !is_numeric($parts[1])) {
				$input_errors[] = gettext('A valid delegated prefix must be specified.');
			}
		}
	}

	if (empty($post['duid'])) {
		$input_errors[] = gettext("A valid DUID must be specified.");
	}

	/* check for overlaps (compare the DUID as it is stored, with colons) */
	foreach (config_get_path("dhcpdv6/{$if}/staticmap", []) as $mapent) {
		if ($this_map_config && ($this_map_config === $mapent)) {
			continue;
		}

		if ((($mapent['hostname'] == $post['hostname']) && $mapent['hostname']) ||
		    ($mapent['duid'] == str_replace("-", ":", $post['duid']))) {
			$input_errors[] = gettext("This Hostname, IP or DUID already exists.");
			break;
		}
	}

	/* make sure it's not within the dynamic subnet */
	if ($post['ipaddrv6']) {
		/* oh boy, we need to be able to somehow do this at some point. skip */
	}

	/* validate custom config */
	if (dhcp_is_backend('kea')) {
		kea_custom_config_enforce($this_map_config['custom_kea_config'] ?? '', $input_errors, $post);
		if (!empty($post['custom_kea_config'])) {
			$json = json_decode($post['custom_kea_config'], true);
			if (!is_array($json) || (json_last_error() !== JSON_ERROR_NONE)) {
				$input_errors[] = gettext('Custom configuration is not a well formed JSON object.');
			}
		}
	}

	if ($input_errors) {
		return (['input_errors' => $input_errors, 'pconfig' => $pconfig, 'id' => null]);
	}

	$mapent = array();
	$mapent['duid'] = str_replace("-", ":", $post['duid']);
	$mapent['ipaddrv6'] = $post['ipaddrv6'];
	$mapent['hostname'] = $post['hostname'];
	$mapent['descr'] = $post['descr'];
	$mapent['earlydnsregpolicy'] = $post['earlydnsregpolicy'];
	$mapent['filename'] = $post['filename'];
	$mapent['rootpath'] = $post['rootpath'];

	if (dhcp_is_backend('kea')) {
		if (!empty($post['pdprefix'])) {
			$mapent['pdprefix'] = text_to_compressed_ip6($post['pdprefix']);
		}
		$mapent['custom_kea_config'] = base64_encode($post['custom_kea_config']);
	}

	if ($this_map_config) {
		config_set_path("dhcpdv6/{$if}/staticmap/{$id}", $mapent);
	} else {
		config_set_path("dhcpdv6/{$if}/staticmap/", $mapent);
	}
	dhcp6_staticmaps_sort($if);
	/* its position, before write_config() normalises the stored values */
	$newid = array_search($mapent, config_get_path("dhcpdv6/{$if}/staticmap", []), true);

	write_config("DHCPv6 server static maps saved");

	dhcp_staticmap_mark_dirty($if, true);

	return (['input_errors' => [], 'pconfig' => $pconfig, 'id' => ($newid === false) ? null : $newid]);
}

/* Delete DHCPv6 static mapping $id (its position) on interface $if; false when there is no such mapping. */
function dhcp6_staticmap_delete(string $if, $id): bool {
	if (!is_numericint($id) || !config_get_path("dhcpdv6/{$if}/staticmap/{$id}")) {
		return (false);
	}
	config_del_path("dhcpdv6/{$if}/staticmap/{$id}");
	write_config("DHCPv6 server static map deleted");
	dhcp_staticmap_mark_dirty($if, true);
	return (true);
}

/* ------------------------------------------------------------------ */
/* DHCPv6 server of an interface and its additional address pools      */
/* ------------------------------------------------------------------ */

/* The interfaces the DHCPv6 Server page knows: the configured interfaces and the PPPoE server interfaces. */
function dhcp6_server_iflist(): array {
	$iflist = get_configured_interface_with_descr();
	$iflist = array_merge($iflist, get_configured_pppoe_server_interfaces());
	return ($iflist);
}

/*
 * Whether the DHCPv6 Server page offers interface $if (has a tab for it):
 * DHCPv6 is enabled on it, or it tracks another interface or has a static
 * IPv6 address that is not link-local.
 */
function dhcp6_server_offered(string $if): bool {
	$iflist = dhcp6_server_iflist();
	if (($if === '') || !isset($iflist[$if])) {
		return (false);
	}
	$ipaddrv6 = config_get_path("interfaces/{$if}/ipaddrv6");
	return (config_path_enabled("dhcpdv6/{$if}") || ($ipaddrv6 == 'track6') ||
	    (is_ipaddrv6($ipaddrv6) && !is_linklocal($ipaddrv6)));
}

/*
 * Whether the DHCPv6 relay is enabled on an interface of $iflist that is not
 * a bridge member: the DHCPv6 server cannot be enabled then.
 */
function dhcp6_server_relay_enabled(array $iflist): bool {
	$dhcrelay_enabled = false;
	$dhcrelaycfg = config_get_path('dhcrelay6');

	if (is_array($dhcrelaycfg) && isset($dhcrelaycfg['enable']) && isset($dhcrelaycfg['interface']) && !empty($dhcrelaycfg['interface'])) {
		$dhcrelayifs = explode(",", $dhcrelaycfg['interface']);

		foreach ($dhcrelayifs as $dhcrelayif) {

			if (isset($iflist[$dhcrelayif]) && (!link_interface_to_bridge($dhcrelayif))) {
				$dhcrelay_enabled = true;
				break;
			}
		}
	}

	return ($dhcrelay_enabled);
}

/*
 * The prefix the DHCPv6 Server page serves on $if: 'ip' and 'sn' (address
 * and prefix length; "::"/64 when the interface tracks another one),
 * 'trackifname' (the tracked interface, else null) and 'str_help_mask' (the
 * form of a tracked range, else null).
 */
function dhcp6_server_prefix(string $if): array {
	$trackifname = null;
	$str_help_mask = null;
	if (config_get_path("interfaces/{$if}/ipaddrv6") == 'track6') {
		$trackifname = config_get_path("interfaces/{$if}/track6-interface");
		$ifcfgsn = "64";
		$ifcfgip = '::';

		$str_help_mask = dhcpv6_pd_str_help($ifcfgsn);
	} else {
		$ifcfgip = get_interface_ipv6($if);
		$ifcfgsn = get_interface_subnetv6($if);
	}

	return (['ip' => $ifcfgip, 'sn' => $ifcfgsn, 'trackifname' => $trackifname, 'str_help_mask' => $str_help_mask]);
}

/*
 * The stored settings the DHCPv6 Server page edits on interface $if:
 * additional pool $pool (its position) when it exists, an empty pool for
 * $act "newpool", else the interface's settings; null when the interface has
 * no DHCPv6 settings at all.
 */
function dhcp6_server_conf(string $if, $pool, $act): ?array {
	if (empty(config_get_path("dhcpdv6/{$if}"))) {
		return (null);
	}

	if (is_numeric($pool) && config_get_path("dhcpdv6/{$if}/pool/{$pool}")) {
		$dhcpdconf = config_get_path("dhcpdv6/{$if}/pool/{$pool}");
	} elseif ($act === 'newpool') {
		$dhcpdconf = [];
	} else {
		$dhcpdconf = config_get_path("dhcpdv6/{$if}", []);
	}

	return ($dhcpdconf);
}

/*
 * The DHCPv6 Server page's form for $dhcpdconf (from dhcp6_server_conf();
 * null: an empty form). $is_pool: the form of an additional address pool.
 */
function dhcp6_server_form(?array $dhcpdconf, bool $is_pool): array {
	$pconfig = [];

	if (is_array($dhcpdconf)) {
		if (!$is_pool) {
			$pconfig['enable'] = isset($dhcpdconf['enable']);
			$pconfig['dnsregpolicy'] = $dhcpdconf['dnsregpolicy'];
			$pconfig['earlydnsregpolicy'] = $dhcpdconf['earlydnsregpolicy'];
		} else {
			$pconfig['descr'] = $dhcpdconf['descr'];
		}

		/* DHCPv6 */
		if (is_array($dhcpdconf['range'])) {
			$pconfig['range_from'] = $dhcpdconf['range']['from'];
			$pconfig['range_to'] = $dhcpdconf['range']['to'];
		}

		if (is_array($dhcpdconf['prefixrange'])) {
			$pconfig['prefixrange_from'] = $dhcpdconf['prefixrange']['from'];
			$pconfig['prefixrange_to'] = $dhcpdconf['prefixrange']['to'];
			$pconfig['prefixrange_length'] = $dhcpdconf['prefixrange']['prefixlength'];
		}

		$pconfig['pdprefix'] = $dhcpdconf['pdprefix'];
		$pconfig['pdprefixlen'] = $dhcpdconf['pdprefixlen'];
		$pconfig['pddellen'] = $dhcpdconf['pddellen'];

		$pconfig['deftime'] = $dhcpdconf['defaultleasetime'];
		$pconfig['maxtime'] = $dhcpdconf['maxleasetime'];
		$pconfig['domain'] = $dhcpdconf['domain'];
		$pconfig['domainsearchlist'] = $dhcpdconf['domainsearchlist'];
		list($pconfig['dns1'], $pconfig['dns2'], $pconfig['dns3'], $pconfig['dns4']) = $dhcpdconf['dnsserver'];
		$pconfig['dhcp6c-dns'] = ($dhcpdconf['dhcp6c-dns'] !== 'disabled' ? 'enabled' : 'disabled');
		if (isset($dhcpdconf['denyunknown'])) {
			$pconfig['denyunknown'] = empty($dhcpdconf['denyunknown']) ? "enabled" : $dhcpdconf['denyunknown'];
		} else {
			$pconfig['denyunknown'] = "disabled";
		}
		$pconfig['ddnsdomain'] = $dhcpdconf['ddnsdomain'];
		$pconfig['ddnsdomainprimary'] = $dhcpdconf['ddnsdomainprimary'];
		$pconfig['ddnsdomainprimaryport'] = $dhcpdconf['ddnsdomainprimaryport'];
		$pconfig['ddnsdomainsecondary'] = $dhcpdconf['ddnsdomainsecondary'];
		$pconfig['ddnsdomainsecondaryport'] = $dhcpdconf['ddnsdomainsecondaryport'];
		$pconfig['ddnsdomainkeyname'] = $dhcpdconf['ddnsdomainkeyname'];
		$pconfig['ddnsdomainkeyalgorithm'] = $dhcpdconf['ddnsdomainkeyalgorithm'];
		$pconfig['ddnsdomainkey'] = $dhcpdconf['ddnsdomainkey'];
		$pconfig['ddnsupdate'] = isset($dhcpdconf['ddnsupdate']);
		$pconfig['ddnsforcehostname'] = isset($dhcpdconf['ddnsforcehostname']);
		$pconfig['ddnsclientupdates'] = $dhcpdconf['ddnsclientupdates'];
		$pconfig['ddnsreverse'] = isset($dhcpdconf['ddnsreverse']);
		list($pconfig['ntp1'], $pconfig['ntp2'], $pconfig['ntp3'], $pconfig['ntp4']) = $dhcpdconf['ntpserver'];
		$pconfig['tftp'] = $dhcpdconf['tftp'];
		$pconfig['ldap'] = $dhcpdconf['ldap'];
		$pconfig['netboot'] = isset($dhcpdconf['netboot']);
		$pconfig['bootfile_url'] = $dhcpdconf['bootfile_url'];
		$pconfig['netmask'] = $dhcpdconf['netmask'];
		$pconfig['numberoptions'] = $dhcpdconf['numberoptions'];
		$pconfig['dhcpv6leaseinlocaltime'] = $dhcpdconf['dhcpv6leaseinlocaltime'];

		if (dhcp_is_backend('kea')) {
			$pconfig['custom_kea_config'] = base64_decode($dhcpdconf['custom_kea_config']);
		}
	}

	return ($pconfig);
}

/*
 * Save the DHCPv6 Server page's form $post for interface $if: additional
 * address pool $pool (its position), a new pool when $act is "newpool", else
 * the interface's settings. Validation, the ISC and Kea branches and the
 * staging ("dhcpd6" dirty) are the page's. Returns ['input_errors' => [...],
 * 'pconfig' => the form to show again, 'saved' => true when saved, 'id' =>
 * the saved pool's position, 'dhcpdconf' => the saved settings,
 * 'missing_pool' => true when $pool names no pool (nothing saved; the page
 * goes back to its start)].
 */
function dhcp6_server_save(string $if, $pool, $act, array $post): array {
	$input_errors = [];
	$ret = ['input_errors' => [], 'pconfig' => $post, 'saved' => false, 'missing_pool' => false, 'id' => null, 'dhcpdconf' => null];

	/* only an interface the page offers (it was reachable with ?if=, e.g. an interface without IPv6) */
	if (!dhcp6_server_offered($if)) {
		$ret['input_errors'][] = gettext('The DHCPv6 Server can only be enabled on interfaces configured with a static IPv6 address or Track Interface.');
		return ($ret);
	}

	$iflist = dhcp6_server_iflist();
	$dnsregpolicy_values = dhcp_server_dnsregpolicy_values();
	$dhcrelay_enabled = dhcp6_server_relay_enabled($iflist);
	$dhcpdconf = dhcp6_server_conf($if, $pool, $act);
	$prefix = dhcp6_server_prefix($if);
	$ifcfgip = $prefix['ip'];
	$ifcfgsn = $prefix['sn'];
	$str_help_mask = $prefix['str_help_mask'];

	$pconfig = $post;

	$numberoptions = array();
	for ($x = 0; $x < 99; $x++) {
		if (isset($post["number{$x}"]) && ctype_digit(strval($post["number{$x}"]))) {
			$numbervalue = array();
			$numbervalue['number'] = htmlspecialchars($post["number{$x}"]);
			$numbervalue['value'] = base64_encode($post["value{$x}"]);
			$numberoptions['item'][] = $numbervalue;
		}
	}
	// Reload the new pconfig variable that the form uses.
	$pconfig['numberoptions'] = $numberoptions;

	/* input validation */

	if ($post['pdprefix']) {
		if (!is_ipaddrv6($post['pdprefix'])) {
			$input_errors[] = gettext('Delegated prefix must be a valid IPv6 prefix.');
		}
		if (!is_numericint($post['pdprefixlen']) ||
		    ($post['pdprefixlen'] < 48) ||
		    ($post['pdprefixlen'] > 64)) {
			$input_errors[] = gettext('Delegated Prefix Length is not an integer in the required range.');
		}
		if (!is_numericint($post['pddellen']) ||
		    ($post['pddellen'] < 48) ||
		    ($post['pddellen'] > 128)) {
			$input_errors[] = gettext('Delegated Length is not an integer in the required range.');
		}
		if ((int)$post['pddellen'] < (int)$post['pdprefixlen']) {
			$input_errors[] = gettext('Delegated length must be greater than or equal to the prefix length.');
		}
	}

	// Note: if DHCPv6 Server is not enabled, then it is OK to adjust other parameters without specifying range from-to.
	if ($post['enable'] || is_numeric($pool) || ($act === 'newpool')) {
		if ((empty($post['range_from']) || empty($post['range_to'])) &&
		    (config_get_path("dhcpdv6/{$if}/ramode") != 'stateless_dhcp')) {
			$input_errors[] = gettext('A valid range must be specified for any Router Advertisement mode except "Stateless DHCP."');
		}
	}

	if (($post['prefixrange_from'] && !is_ipaddrv6($post['prefixrange_from']))) {
		$input_errors[] = gettext("A valid prefix range must be specified.");
	}
	if (($post['prefixrange_to'] && !is_ipaddrv6($post['prefixrange_to']))) {
		$input_errors[] = gettext("A valid prefix range must be specified.");
	}

	if ($post['prefixrange_from'] && $post['prefixrange_to'] &&
		$post['prefixrange_length']) {
		$netmask = Net_IPv6::getNetmask($post['prefixrange_from'],
			$post['prefixrange_length']);
		$netmask = text_to_compressed_ip6($netmask);

		if ($netmask != text_to_compressed_ip6(strtolower(
			$post['prefixrange_from']))) {
			$input_errors[] = sprintf(gettext(
				"Prefix Delegation From address is not a valid IPv6 Netmask for %s"),
				$netmask . '/' . $post['prefixrange_length']);
		}

		$netmask = Net_IPv6::getNetmask($post['prefixrange_to'],
			$post['prefixrange_length']);
		$netmask = text_to_compressed_ip6($netmask);

		if ($netmask != text_to_compressed_ip6(strtolower(
			$post['prefixrange_to']))) {
			$input_errors[] = sprintf(gettext(
				"Prefix Delegation To address is not a valid IPv6 Netmask for %s"),
				$netmask . '/' . $post['prefixrange_length']);
		}
	}

	$range_from_to_ok = true;

	if ($post['range_from']) {
		if (!is_ipaddrv6($post['range_from'])) {
			$input_errors[] = gettext("A valid range must be specified.");
			$range_from_to_ok = false;
		} elseif (config_get_path("interfaces/{$if}/ipaddrv6") == 'track6' &&
			!Net_IPv6::isInNetmask($post['range_from'], '::', $ifcfgsn)) {
			$input_errors[] = sprintf(gettext(
				'The prefix (upper %1$s bits) must be zero.  Use the form %2$s'),
				$ifcfgsn, $str_help_mask);
			$range_from_to_ok = false;
		}
	}
	if ($post['range_to']) {
		if (!is_ipaddrv6($post['range_to'])) {
			$input_errors[] = gettext("A valid range must be specified.");
			$range_from_to_ok = false;
		} elseif (config_get_path("interfaces/{$if}/ipaddrv6") == 'track6' &&
			!Net_IPv6::isInNetmask($post['range_to'], '::', $ifcfgsn)) {
			$input_errors[] = sprintf(gettext(
				'The prefix (upper %1$s bits) must be zero.  Use the form %2$s'),
				$ifcfgsn, $str_help_mask);
			$range_from_to_ok = false;
		}
	}
	if (($post['range_from'] && !$post['range_to']) || ($post['range_to'] && !$post['range_from'])) {
		$input_errors[] = gettext("Range From and Range To must both be entered.");
	}
	if (($post['gateway'] && !is_ipaddrv6($post['gateway']))) {
		$input_errors[] = gettext("A valid IPv6 address must be specified for the gateway.");
	}
	if (($post['dns1'] && !is_ipaddrv6($post['dns1'])) ||
		($post['dns2'] && !is_ipaddrv6($post['dns2'])) ||
		($post['dns3'] && !is_ipaddrv6($post['dns3'])) ||
		($post['dns4'] && !is_ipaddrv6($post['dns4']))) {
		$input_errors[] = gettext("A valid IPv6 address must be specified for each of the DNS servers.");
	}

	if ($post['dnsregpolicy'] && !array_key_exists($post['dnsregpolicy'], $dnsregpolicy_values)) {
		$input_errors[] = gettext("Invalid DNS Registration Policy.");
	}

	if ($post['earlydnsregpolicy'] && !array_key_exists($post['earlydnsregpolicy'], $dnsregpolicy_values)) {
		$input_errors[] = gettext("Invalid Early DNS Registration Policy.");
	}

	if ($post['deftime'] && (!is_numeric($post['deftime']) || ($post['deftime'] < 60))) {
		$input_errors[] = gettext("The default lease time must be at least 60 seconds.");
	}
	if ($post['maxtime'] && (!is_numeric($post['maxtime']) || ($post['maxtime'] < 60) || ($post['maxtime'] < $post['deftime']))) {
		$input_errors[] = gettext("The maximum lease time must be at least 60 seconds, and the same value or greater than the default lease time.");
	}
	if ($post['ddnsupdate']) {
		if (!is_domain($post['ddnsdomain'])) {
			$input_errors[] = gettext("A valid domain name must be specified for the dynamic DNS registration.");
		}
		if (!is_ipaddr($post['ddnsdomainprimary'])) {
			$input_errors[] = gettext("A valid primary domain name server IP address must be specified for the dynamic domain name.");
		}
		if (!empty($post['ddnsdomainsecondary']) && !is_ipaddr($post['ddnsdomainsecondary'])) {
			$input_errors[] = gettext("A valid secondary domain name server IP address must be specified for the dynamic domain name.");
		}
		if (!$post['ddnsdomainkeyname'] || !$post['ddnsdomainkeyalgorithm'] || !$post['ddnsdomainkey']) {
			$input_errors[] = gettext("A valid domain key name, algorithm and secret must be specified.");
		}
		if (preg_match('/[^A-Za-z0-9\.\-\_]/', $post['ddnsdomainkeyname'])) {
			$input_errors[] = gettext("The domain key name may only contain the characters a-z, A-Z, 0-9, '-', '_' and '.'");
		}
		if ($post['ddnsdomainkey'] && !base64_decode($post['ddnsdomainkey'], true)) {
			$input_errors[] = gettext("The domain key secret must be a Base64 encoded value.");
		}
	}
	if ($post['domainsearchlist']) {
		$domain_array = preg_split("/[ ;]+/", $post['domainsearchlist']);
		foreach ($domain_array as $curdomain) {
			if (!is_domain($curdomain)) {
				$input_errors[] = gettext("A valid domain search list must be specified.");
				break;
			}
		}
	}

	if (($post['ntp1'] && !is_ipaddrv6($post['ntp1'])) ||
	    ($post['ntp2'] && !is_ipaddrv6($post['ntp2'])) ||
	    ($post['ntp3'] && !is_ipaddrv6($post['ntp3'])) ||
	    ($post['ntp4'] && !is_ipaddrv6($post['ntp4']))) {
		$input_errors[] = gettext('A valid IPv6 address must be specified for the NTP servers.');
	}
	if (($post['domain'] && !is_domain($post['domain']))) {
		$input_errors[] = gettext("A valid domain name must be specified for the DNS domain.");
	}
	if ($post['tftp'] && !is_ipaddr($post['tftp']) && !is_domain($post['tftp']) && !is_URL($post['tftp'])) {
		$input_errors[] = gettext("A valid IPv6 address or hostname must be specified for the TFTP server.");
	}
	if (($post['bootfile_url'] && !is_URL($post['bootfile_url']))) {
		$input_errors[] = gettext("A valid URL must be specified for the network bootfile.");
	}

	// Disallow a range that includes the virtualip
	if ($range_from_to_ok) {
		foreach (config_get_path('virtualip/vip', []) as $vip) {
			if ($vip['interface'] == $if) {
				if ($vip['subnetv6'] && is_inrange_v6($vip['subnetv6'], $post['range_from'], $post['range_to'])) {
					$input_errors[] = sprintf(gettext("The subnet range cannot overlap with virtual IPv6 address %s."), $vip['subnetv6']);
				}
			}
		}
	}

	$noip = false;
	foreach (config_get_path("dhcpdv6/{$if}/staticmap", []) as $map) {
		if (empty($map['ipaddrv6'])) {
			$noip = true;
		}
	}

	/* make sure that the DHCP Relay isn't enabled on this interface */
	if ($post['enable'] && $dhcrelay_enabled) {
		$input_errors[] = sprintf(gettext("The DHCP relay on the %s interface must be disabled before enabling the DHCP server."), $iflist[$if]);
	}

	// If nothing is wrong so far, and we have range from and to, then check conditions related to the values of range from and to.
	if (!$input_errors && $post['range_from'] && $post['range_to']) {
		/* make sure the range lies within the current subnet */
		$subnet_start = gen_subnetv6($ifcfgip, $ifcfgsn);
		$subnet_end = gen_subnetv6_max($ifcfgip, $ifcfgsn);

		if (is_ipaddrv6($ifcfgip)) {
			if ((!is_inrange_v6($post['range_from'], $subnet_start, $subnet_end)) ||
				(!is_inrange_v6($post['range_to'], $subnet_start, $subnet_end))) {
				$input_errors[] = gettext("The specified range lies outside of the current subnet.");
			}
		}

		if (is_numeric($pool) || ($act === 'newpool')) {
			if (is_inrange_v6($post['range_from'],
				config_get_path("dhcpdv6/{$if}/range/from"),
				config_get_path("dhcpdv6/{$if}/range/to")) ||
				is_inrange_v6($post['range_to'],
				config_get_path("dhcpdv6/{$if}/range/from"),
				config_get_path("dhcpdv6/{$if}/range/to"))) {
				$input_errors[] = gettext('The specified range must not be within the primary DHCPv6 address pool for this interface.');
			}
		}

		foreach (config_get_path("dhcpdv6/{$if}/pool", []) as $id => $p) {
			if (is_numeric($pool) && ($id == $pool)) {
				continue;
			}

			if (is_inrange_v6($post['range_from'], $p['range']['from'], $p['range']['to']) ||
			    is_inrange_v6($post['range_to'], $p['range']['from'], $p['range']['to'])) {
				$input_errors[] = gettext('The specified range must not be within the range configured on another DHCPv6 pool for this interface.');
				break;
			}
		}


		/* "from" cannot be higher than "to" */
		if (inet_pton($post['range_from']) > inet_pton($post['range_to'])) {
			$input_errors[] = gettext("The range is invalid (first element higher than second element).");
		}

		/* Verify static mappings do not overlap:
		   - available DHCP range
		   - prefix delegation range (FIXME: still need to be completed) */
		$dynsubnet_start = inet_pton($post['range_from']);
		$dynsubnet_end = inet_pton($post['range_to']);

		foreach (config_get_path("dhcpdv6/{$if}/staticmap", []) as $map) {
			if (empty($map['ipaddrv6'])) {
				continue;
			}
			/* the first and the last address of the range count too (they were not checked) */
			if ((inet_pton($map['ipaddrv6']) >= $dynsubnet_start) &&
				(inet_pton($map['ipaddrv6']) <= $dynsubnet_end)) {
				$input_errors[] = sprintf(gettext("The DHCP range cannot overlap any static DHCP mappings."));
				break;
			}
		}
	}

	/* validate custom config */
	if (dhcp_is_backend('kea')) {
		/* an interface without DHCPv6 settings has nothing stored (this failed with a TypeError: no first save was possible) */
		kea_custom_config_enforce(is_array($dhcpdconf) ? array_get_path($dhcpdconf, 'custom_kea_config') : null, $input_errors, $post);
		if (!empty($post['custom_kea_config'])) {
			$json = json_decode($post['custom_kea_config'], true);
			if (!is_array($json) || (json_last_error() !== JSON_ERROR_NONE)) {
				$input_errors[] = gettext('Custom configuration is not a well formed JSON object.');
			}
		}
	}

	if (!$input_errors) {
		if (!is_numeric($pool)) {
			if ($act === 'newpool') {
				$dhcpdconf = [];
			} else {
				$dhcpdconf = config_get_path("dhcpdv6/{$if}", []);
			}
		} else {
			if (is_array(config_get_path("dhcpdv6/{$if}/pool/{$pool}"))) {
				$dhcpdconf = config_get_path("dhcpdv6/{$if}/pool/{$pool}");
			} else {
				// Someone specified a pool but it doesn't exist. Punt.
				$ret['missing_pool'] = true;
				return ($ret);
			}
		}

		if (!is_array($dhcpdconf)) {
			$dhcpdconf = [];
		}

		if (!is_array($dhcpdconf['range'])) {
			$dhcpdconf['range'] = [];
		}
		if (!is_array($dhcpdconf['range'])) {
			$dhcpdconf['range'] = [];
		}

		// Kea prefix delegation
		if ($post['pdprefix']) {
			$dhcpdconf['pdprefix'] = $post['pdprefix'];
			$dhcpdconf['pdprefixlen'] = intval($post['pdprefixlen']);
			$dhcpdconf['pddellen'] = intval($post['pddellen']);
		} else {
			unset($dhcpdconf['pdprefix']);
			unset($dhcpdconf['pdprefixlen']);
			unset($dhcpdconf['pddellen']);
		}

		// Global options
		if (!is_numeric($pool) && !($act === 'newpool')) {
			$dhcpdconf['enable'] = ($post['enable']) ? true : false;
			$dhcpdconf['dnsregpolicy'] = $post['dnsregpolicy'];
			$dhcpdconf['earlydnsregpolicy'] = $post['earlydnsregpolicy'];
		} else {
			// Options that exist only in pools
			$dhcpdconf['descr'] = $post['descr'];
		}


		if (in_array($post['denyunknown'], array("enabled", "class"))) {
			$dhcpdconf['denyunknown'] = $post['denyunknown'];
		} else {
			unset($dhcpdconf['denyunknown']);
		}

		$dhcpdconf['range']['from'] = $post['range_from'];
		$dhcpdconf['range']['to'] = $post['range_to'];
		$dhcpdconf['prefixrange']['from'] = $post['prefixrange_from'];
		$dhcpdconf['prefixrange']['to'] = $post['prefixrange_to'];
		$dhcpdconf['prefixrange']['prefixlength'] = $post['prefixrange_length'];
		$dhcpdconf['defaultleasetime'] = $post['deftime'];
		$dhcpdconf['maxleasetime'] = $post['maxtime'];
		$dhcpdconf['netmask'] = $post['netmask'];

		unset($dhcpdconf['dnsserver']);
		if ($post['dns1']) {
			$dhcpdconf['dnsserver'][] = $post['dns1'];
		}
		if ($post['dns2']) {
			$dhcpdconf['dnsserver'][] = $post['dns2'];
		}
		if ($post['dns3']) {
			$dhcpdconf['dnsserver'][] = $post['dns3'];
		}
		if ($post['dns4']) {
			$dhcpdconf['dnsserver'][] = $post['dns4'];
		}
		$dhcpdconf['dhcp6c-dns'] = ($post['dhcp6c-dns']) ? 'enabled' : 'disabled';
		$dhcpdconf['domain'] = $post['domain'];
		$dhcpdconf['domainsearchlist'] = $post['domainsearchlist'];

		$dhcpdconf['ddnsdomain'] = $post['ddnsdomain'];
		$dhcpdconf['ddnsdomainprimary'] = $post['ddnsdomainprimary'];
		$dhcpdconf['ddnsdomainsecondary'] = (!empty($post['ddnsdomainsecondary'])) ? $post['ddnsdomainsecondary'] : '';
		$dhcpdconf['ddnsdomainkeyname'] = $post['ddnsdomainkeyname'];
		$dhcpdconf['ddnsdomainkeyalgorithm'] = $post['ddnsdomainkeyalgorithm'];
		$dhcpdconf['ddnsdomainkey'] = $post['ddnsdomainkey'];
		$dhcpdconf['ddnsupdate'] = ($post['ddnsupdate']) ? true : false;
		$dhcpdconf['ddnsforcehostname'] = ($post['ddnsforcehostname']) ? true : false;
		$dhcpdconf['ddnsreverse'] = ($post['ddnsreverse']) ? true : false;
		$dhcpdconf['ddnsclientupdates'] = $post['ddnsclientupdates'];

		unset($dhcpdconf['ntpserver']);
		if ($post['ntp1']) {
			$dhcpdconf['ntpserver'][] = $post['ntp1'];
		}
		if ($post['ntp2']) {
			$dhcpdconf['ntpserver'][] = $post['ntp2'];
		}
		if ($post['ntp3']) {
			$dhcpdconf['ntpserver'][] = $post['ntp3'];
		}
		if ($post['ntp4']) {
			$dhcpdconf['ntpserver'][] = $post['ntp4'];
		}

		$dhcpdconf['tftp'] = $post['tftp'];
		$dhcpdconf['ldap'] = $post['ldap'];
		$dhcpdconf['netboot'] = ($post['netboot']) ? true : false;
		$dhcpdconf['bootfile_url'] = $post['bootfile_url'];
		$dhcpdconf['dhcpv6leaseinlocaltime'] = $post['dhcpv6leaseinlocaltime'];

		// Handle the custom options rowhelper
		if (isset($dhcpdconf['numberoptions']['item'])) {
			unset($dhcpdconf['numberoptions']['item']);
		}

		$dhcpdconf['numberoptions'] = $numberoptions;

		if (dhcp_is_backend('kea')) {
			$dhcpdconf['custom_kea_config'] = base64_encode($post['custom_kea_config']);
		}

		if (is_numeric($pool) && is_array(config_get_path("dhcpdv6/{$if}/pool/{$pool}"))) {
			config_set_path("dhcpdv6/{$if}/pool/{$pool}", $dhcpdconf);
			$ret['id'] = (int)$pool;
		} elseif ($act === 'newpool') {
			config_set_path("dhcpdv6/{$if}/pool/", $dhcpdconf);
			$ret['id'] = array_key_last(config_get_path("dhcpdv6/{$if}/pool", []));
		} else {
			config_set_path("dhcpdv6/{$if}", $dhcpdconf);
		}

		mark_subsystem_dirty('dhcpd6');

		write_config("DHCPv6 Server settings saved");

		$ret['saved'] = true;
		$ret['dhcpdconf'] = $dhcpdconf;
	}

	$ret['input_errors'] = $input_errors;
	$ret['pconfig'] = $pconfig;
	return ($ret);
}

/* The additional pools of interface $if as an HTML table for a Form_StaticText element. */
function dhcp6_build_pooltable(string $if): string {
	$pooltbl =	'<div class="table-responsive">';
	$pooltbl .=		'<table class="table table-striped table-hover table-sm">';
	$pooltbl .=			'<thead>';
	$pooltbl .=				'<tr>';
	$pooltbl .=					'<th>' . gettext("Pool Start") . '</th>';
	$pooltbl .=					'<th>' . gettext("Pool End") . '</th>';
	$pooltbl .=					'<th>' . gettext("Description") . '</th>';
	$pooltbl .=					'<th>' . gettext("Actions") . '</th>';
	$pooltbl .=				'</tr>';
	$pooltbl .=			'</thead>';
	$pooltbl .=			'<tbody>';

	$i = 0;
	foreach (config_get_path("dhcpdv6/{$if}/pool", []) as $poolent) {
		if (!empty($poolent['range']['from']) && !empty($poolent['range']['to'])) {
			$pooltbl .= '<tr>';
			$pooltbl .= '<td ondblclick="document.location=\'services_dhcpv6.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '\';">' .
						htmlspecialchars($poolent['range']['from']) . '</td>';

			$pooltbl .= '<td ondblclick="document.location=\'services_dhcpv6.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '\';">' .
						htmlspecialchars($poolent['range']['to']) . '</td>';

			$pooltbl .= '<td ondblclick="document.location=\'services_dhcpv6.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '\';">' .
						htmlspecialchars($poolent['descr']) . '</td>';

			$pooltbl .= '<td><a class="fa-solid fa-pencil" title="'. gettext("Edit pool") . '" href="services_dhcpv6.php?if=' . htmlspecialchars($if) . '&pool=' . $i . '"></a>';

			$pooltbl .= ' <a class="fa-solid fa-trash-can text-danger" title="'. gettext("Delete pool") . '" href="services_dhcpv6.php?if=' . htmlspecialchars($if) . '&act=delpool&id=' . $i . '" usepost></a></td>';
			$pooltbl .= '</tr>';
		}
		$i++;
	}

	$pooltbl .=			'</tbody>';
	$pooltbl .=		'</table>';
	$pooltbl .= '</div>';

	return($pooltbl);
}

/*
 * Delete additional DHCPv6 pool $id (its position) of interface $if and
 * stage the DHCPv6 server; false when there is no such pool.
 */
function dhcp6_pool_delete(string $if, $id): bool {
	if (!is_numericint($id) || !is_array(config_get_path("dhcpdv6/{$if}/pool/{$id}"))) {
		return (false);
	}
	config_del_path("dhcpdv6/{$if}/pool/{$id}");
	write_config('DHCPv6 Server pool deleted');
	mark_subsystem_dirty('dhcpd6');
	return (true);
}

/* ------------------------------------------------------------------ */
/* Router advertisements                                               */
/* ------------------------------------------------------------------ */

/* The Router Mode choices of the Router Advertisement page. */
function radvd_ramode_values(): array {
	return ([
		"disabled" => 	gettext("Disabled"),
		"router" => 	gettext("Router Only - RA Flags [none], Prefix Flags [router]"),
		"unmanaged" => 	gettext("Unmanaged - RA Flags [none], Prefix Flags [onlink, auto, router]"),
		"managed" => 	gettext("Managed - RA Flags [managed, other stateful], Prefix Flags [onlink, router]"),
		"assist" => 	gettext("Assisted - RA Flags [managed, other stateful], Prefix Flags [onlink, auto, router]"),
		"stateless_dhcp" => gettext("Stateless DHCP - RA Flags [other stateful], Prefix Flags [onlink, auto, router]")
	]);
}

/* The Router Priority choices of the Router Advertisement page. */
function radvd_rapriority_values(): array {
	return ([
		"low" => 	gettext("Low"),
		"medium" => gettext("Normal"),
		"high" => 	gettext("High")
	]);
}

/*
 * Whether the Router Advertisement page offers interface $if (has a tab for
 * it): a static IPv6 address, Track Interface, or no IPv6 configuration (to
 * allow disabling the service, upstream issue 14967).
 */
function radvd_offered(string $if): bool {
	$iflist = get_configured_interface_with_descr();
	if (($if === '') || !isset($iflist[$if])) {
		return (false);
	}
	$oc = config_get_path("interfaces/{$if}", []);
	$ipaddrv6 = array_get_path($oc, 'ipaddrv6');
	return (is_ipaddrv6($ipaddrv6) || ($ipaddrv6 == "track6") || (array_get_path($oc, 'ipaddrv6', 'none') == 'none'));
}

/*
 * The RA Interface choices of interface $if: the interface itself and its
 * IPv6 CARP VIPs; empty when it has no IPv6 CARP VIP (the page then has no
 * such field).
 */
function radvd_rainterface_values(string $if): array {
	$carplist = get_configured_vip_list("inet6", VIP_CARP);

	$carplistif = array();

	if (count($carplist) > 0) {
		foreach ($carplist as $ifname => $vip) {
			if (get_configured_vip_interface($ifname) == $if) {
				$carplistif[$ifname] = $vip;
			}
		}
	}

	$iflist = array();
	if (count($carplistif) > 0) {
		$iflist['interface'] = convert_friendly_interface_to_friendly_descr($if);
		foreach ($carplistif as $ifname => $vip) {
			$iflist[$ifname] = get_vip_descr($vip) . " - " . $vip;
		}
	}

	return ($iflist);
}

/* The Router Advertisement page's form for interface $if. */
function radvd_form(string $if): array {
	$pconfig = [];

	if (!empty(config_get_path("dhcpdv6/{$if}"))) {
		/* RA specific */
		$pconfig['ramode'] = config_get_path("dhcpdv6/{$if}/ramode");
		$pconfig['rapriority'] = config_get_path("dhcpdv6/{$if}/rapriority");
		$pconfig['rainterface'] = config_get_path("dhcpdv6/{$if}/rainterface");
		if ($pconfig['rapriority'] == "") {
			$pconfig['rapriority'] = "medium";
		}

		$pconfig['ravalidlifetime'] = config_get_path("dhcpdv6/{$if}/ravalidlifetime");
		$pconfig['rapreferredlifetime'] = config_get_path("dhcpdv6/{$if}/rapreferredlifetime");
		$pconfig['raminrtradvinterval'] = config_get_path("dhcpdv6/{$if}/raminrtradvinterval");
		$pconfig['ramaxrtradvinterval'] = config_get_path("dhcpdv6/{$if}/ramaxrtradvinterval");
		$pconfig['raadvdefaultlifetime'] = config_get_path("dhcpdv6/{$if}/raadvdefaultlifetime");
		$pconfig['pref64_enable'] = config_path_enabled("dhcpdv6/{$if}/pref64");
		$pconfig['pref64_prefix'] = config_get_path("dhcpdv6/{$if}/pref64/prefix");
		$pconfig['pref64_lifetime'] = config_get_path("dhcpdv6/{$if}/pref64/lifetime");

		$pconfig['radomainsearchlist'] = config_get_path("dhcpdv6/{$if}/radomainsearchlist");
		list($pconfig['radns1'], $pconfig['radns2'], $pconfig['radns3'], $pconfig['radns4']) = config_get_path("dhcpdv6/{$if}/radnsserver");
		$pconfig['radvd-dns'] = (config_get_path("dhcpdv6/{$if}/radvd-dns") != 'disabled') ? true : false;
		$pconfig['rasamednsasdhcp6'] = config_path_enabled("dhcpdv6/{$if}", 'rasamednsasdhcp6');

		$pconfig['subnets'] = config_get_path("dhcpdv6/{$if}/subnets/item");
	}
	if (!is_array($pconfig['subnets'])) {
		$pconfig['subnets'] = array();
	}

	return ($pconfig);
}

/*
 * Save the Router Advertisement page's form $post for interface $if and
 * reconfigure the RA daemon at once (like the page). Returns ['input_errors'
 * => [...], 'pconfig' => the form to show again, 'saved' => true when saved,
 * 'retval' => the result of services_radvd_configure() (0: success)].
 */
function radvd_save(string $if, array $post): array {
	$input_errors = [];
	$ret = ['input_errors' => [], 'pconfig' => $post, 'saved' => false, 'retval' => null];

	/* only an interface the page offers (any configured interface was accepted) */
	if (!radvd_offered($if)) {
		$ret['input_errors'][] = gettext("Router Advertisements can only be enabled on interfaces configured with static IPv6 or Track Interface.");
		return ($ret);
	}

	$pconfig = $post;

	/* input validation */

	/* the router mode, priority and RA interface are one of the page's choices (any value was stored) */
	if (!array_key_exists((string)$post['ramode'], radvd_ramode_values())) {
		$input_errors[] = gettext("Invalid Router Mode.");
	}
	if (!array_key_exists((string)$post['rapriority'], radvd_rapriority_values())) {
		$input_errors[] = gettext("Invalid Router Priority.");
	}
	if (!empty($post['rainterface']) && !array_key_exists((string)$post['rainterface'], radvd_rainterface_values($if))) {
		$input_errors[] = gettext("Invalid RA Interface.");
	}

	if (config_get_path("interfaces/{$if}/ipaddrv6", 'none') == "none" && $post['ramode'] != 'disabled') {
		$input_errors[] = gettext("Router Advertisements can only be enabled on interfaces configured with static IPv6 or Track Interface.");
	}

	$pconfig['subnets'] = array();
	for ($x = 0; $x < 5000; $x += 1) {
		$address = trim($post['subnet_address' . $x]);
		if ($address === "") {
			continue;
		}

		$bits = trim($post['subnet_bits' . $x]);
		if ($bits === "") {
			$bits = "128";
		}

		if (is_alias($address)) {
			$pconfig['subnets'][] = $address;
		} else {
			$pconfig['subnets'][] = $address . "/" . $bits;
			if (!is_ipaddrv6($address)) {
				$input_errors[] = sprintf(gettext('An invalid subnet or alias was specified. [%1$s/%2$s]'), $address, $bits);
			}
		}
	}

	if (($post['radns1'] && !is_ipaddrv6($post['radns1'])) || ($post['radns2'] && !is_ipaddrv6($post['radns2'])) || ($post['radns3'] && !is_ipaddrv6($post['radns3'])) || ($post['radns4'] && !is_ipaddrv6($post['radns4']))) {
		$input_errors[] = gettext("A valid IPv6 address must be specified for each of the DNS servers.");
	}
	if ($post['radomainsearchlist']) {
		$domain_array=preg_split("/[ ;]+/", $post['radomainsearchlist']);
		foreach ($domain_array as $curdomain) {
			if (!is_domain($curdomain)) {
				$input_errors[] = gettext("A valid domain search list must be specified.");
				break;
			}
		}
	}

	if ($post['ravalidlifetime'] && ($post['ravalidlifetime'] < 7200)) {
		$input_errors[] = gettext("A valid lifetime below 2 hours will be ignored by clients (RFC 4862 Section 5.5.3 point e)");
	}
	if ($post['ravalidlifetime'] && !is_numericint($post['ravalidlifetime'])) {
		$input_errors[] = gettext("Valid lifetime must be an integer.");
	}
	if ($post['raminrtradvinterval']) {
		if (!is_numericint($post['raminrtradvinterval'])) {
			$input_errors[] = gettext("Minimum advertisement interval must be an integer.");
		}
		if ($post['raminrtradvinterval'] < 3) {
			$input_errors[] = gettext("Minimum advertisement interval must be no less than 3.");
		}
		if ($post['ramaxrtradvinterval'] && $post['raminrtradvinterval'] > (0.75 * $post['ramaxrtradvinterval'])) {
			$input_errors[] = gettext("Minimum advertisement interval must be no greater than 0.75 * Maximum advertisement interval");
		}
	}
	if ($post['ramaxrtradvinterval']) {
		if (!is_numericint($post['ramaxrtradvinterval'])) {
			$input_errors[] = gettext("Maximum advertisement interval must be an integer.");
		}
		if ($post['ramaxrtradvinterval'] < 4 || $post['ramaxrtradvinterval'] > 1800) {
			$input_errors[] = gettext("Maximum advertisement interval must be no less than 4 and no greater than 1800.");
		}
	}
	if ($post['rapreferredlifetime']) {
		if (!is_numericint($post['rapreferredlifetime'])) {
			$input_errors[] = gettext("Default preferred lifetime must be an integer.");
		}
	}
	if ($post['raadvdefaultlifetime'] && (($post['raadvdefaultlifetime'] < 1) || ($post['raadvdefaultlifetime'] > 9000))) {
		$input_errors[] = gettext("Router lifetime must be an integer between 0 and 9000.");
	}
	if (($post['ravalidlifetime'] && $post['rapreferredlifetime'] &&
	    ($post['ravalidlifetime'] < $post['rapreferredlifetime'])) ||
	    ($post['ravalidlifetime'] && empty($post['rapreferredlifetime']) &&
	    ($post['ravalidlifetime'] < 14400)) || (empty($post['ravalidlifetime']) &&
	    $post['rapreferredlifetime'] && ($post['rapreferredlifetime'] > 86400))) {
		$input_errors[] = gettext("Default valid lifetime must be greater than Default preferred lifetime.");
	}

	if (!empty($post['pref64_prefix']) && !validate_nat64_prefix($pconfig['pref64_prefix'])) {
		$input_errors[] = gettext("The NAT64 prefix is invalid.");
	}
	if ($post['pref64_lifetime']) {
		if (!is_numericint($post['pref64_lifetime'])) {
			$input_errors[] = gettext("NAT64 Prefix Lifetime must be an integer.");
		} elseif (intval($post['pref64_lifetime']) < 1 || intval($post['pref64_lifetime']) > 65528) {
			$input_errors[] = gettext("NAT64 Prefix Lifetime must be from 1 to 65528.");
		}
	}

	if (!$input_errors) {
		$dhcpd6_config = config_get_path("dhcpdv6/{$if}", []);

		$dhcpd6_config['ramode'] = $post['ramode'];
		$dhcpd6_config['rapriority'] = $post['rapriority'];
		$dhcpd6_config['rainterface'] = $post['rainterface'];

		$dhcpd6_config['ravalidlifetime'] = $post['ravalidlifetime'];
		$dhcpd6_config['rapreferredlifetime'] = $post['rapreferredlifetime'];
		$dhcpd6_config['raminrtradvinterval'] = $post['raminrtradvinterval'];
		$dhcpd6_config['ramaxrtradvinterval'] = $post['ramaxrtradvinterval'];
		$dhcpd6_config['raadvdefaultlifetime'] = $post['raadvdefaultlifetime'];
		if (!empty($pconfig['pref64_enable'])) {
			array_set_path($dhcpd6_config, 'pref64/enable', true);
		} else {
			array_del_path($dhcpd6_config, 'pref64/enable');
		}
		if ($post['pref64_lifetime']) {
			array_set_path($dhcpd6_config, 'pref64/lifetime', $post['pref64_lifetime']);
		} else {
			array_del_path($dhcpd6_config, 'pref64/lifetime');
		}
		if (config_path_enabled('system', 'allow_nat64_prefix_override') && !empty($post['pref64_prefix'])) {
			array_set_path($dhcpd6_config, 'pref64/prefix', $post['pref64_prefix']);
		} else {
			array_del_path($dhcpd6_config, 'pref64/prefix');
		}
		if (empty(array_get_path($dhcpd6_config, 'pref64'))) {
			array_del_path($dhcpd6_config, 'pref64');
		}

		$dhcpd6_config['radomainsearchlist'] = $post['radomainsearchlist'];
		array_del_path($dhcpd6_config, 'radnsserver');
		if ($post['radns1']) {
			$dhcpd6_config['radnsserver'][] = $post['radns1'];
		}
		if ($post['radns2']) {
			$dhcpd6_config['radnsserver'][] = $post['radns2'];
		}
		if ($post['radns3']) {
			$dhcpd6_config['radnsserver'][] = $post['radns3'];
		}
		if ($post['radns4']) {
			$dhcpd6_config['radnsserver'][] = $post['radns4'];
		}

		$dhcpd6_config['radvd-dns'] = ($post['radvd-dns']) ? "enabled" : "disabled";
		$dhcpd6_config['rasamednsasdhcp6'] = ($post['rasamednsasdhcp6']) ? true : false;

		if (count($pconfig['subnets'])) {
			$dhcpd6_config['subnets']['item'] = $pconfig['subnets'];
		} else {
			array_del_path($dhcpd6_config, 'subnets');
		}

		config_set_path("dhcpdv6/{$if}", $dhcpd6_config);
		write_config("Router Advertisements settings saved");
		$retval = 0;
		$retval |= services_radvd_configure();

		$ret['saved'] = true;
		$ret['retval'] = $retval;
	}

	$ret['input_errors'] = $input_errors;
	$ret['pconfig'] = $pconfig;
	return ($ret);
}
