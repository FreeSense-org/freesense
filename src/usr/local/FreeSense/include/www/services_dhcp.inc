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
