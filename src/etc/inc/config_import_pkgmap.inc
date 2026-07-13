<?php
/*
 * config_import_pkgmap.inc
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

/*
 * Package-name translation tables used by the foreign-config importer.
 *
 * The importer does NOT install anything on its own. It uses these tables to
 * AUTO-MAP a source distribution's package to the matching FreeSense package,
 * then SUGGESTS the result to the user (pre-checked when the match is confident,
 * un-checked when it is only a guess) so the user picks what actually gets
 * installed. Anything with no known equivalent is reported as "skipped", never
 * silently dropped.
 *
 * Confidence levels (per entry):
 *   'exact'  - same lineage / identical software; safe to pre-check.
 *   'likely' - clearly the same software, minor name/version divergence;
 *              pre-check but flag "review".
 *   'guess'  - plausible but ambiguous (e.g. version-pinned agent); SUGGEST
 *              only, leave un-checked for the user to confirm.
 *
 * The FreeSense target is the package INTERNAL name (the <name> used in
 * <installedpackages>), NOT the FreeSense-pkg-* pkg-origin. Keep targets in
 * sync with the optional package catalog (pkg rquery -r FreeSense-packages '%n').
 *
 * SHARED TABLE: the authoritative mappings live in the language-neutral file
 * /etc/config_import_pkgmap.map so the installer importer (POSIX sh) and this
 * GUI importer (PHP) use identical data and can never drift. The arrays below
 * are a compiled-in fallback used only if that file is missing/unreadable.
 */

/* Path to the shared, language-neutral mapping table. */
define('FREESENSE_IMPORT_PKGMAP_FILE', '/etc/config_import_pkgmap.map');

/*
 * Load the shared .map table for a lineage. Returns the same shape as the
 * hardcoded maps below: [ source-name => [freesense-name, confidence] ].
 * Returns null if the file is unavailable so callers fall back to the
 * compiled-in arrays.
 */
function freesense_import_load_map($lineage) {
	if (!is_readable(FREESENSE_IMPORT_PKGMAP_FILE)) {
		return null;
	}
	$out = array();
	foreach (file(FREESENSE_IMPORT_PKGMAP_FILE, FILE_IGNORE_NEW_LINES) as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		$f = preg_split('/\s+/', $line);
		if (count($f) < 4 || $f[0] !== $lineage) {
			continue;
		}
		$target = ($f[2] === '-') ? '' : $f[2];
		$out[$f[1]] = array($target, $f[3]);
	}
	return empty($out) ? null : $out;
}

/*
 * pfSense CE -> FreeSense.
 *
 * FreeSense is rebranded pfSense CE, so the package internal names are
 * identical. This map is therefore an identity map for every package the
 * FreeSense repo ships; it exists (a) so the importer can flag a pfSense
 * package that FreeSense does NOT ship as "no equivalent", and (b) to give a
 * single place to record any future rename. Any pfSense internal name not
 * listed here but present in the FreeSense catalog still maps 1:1 at runtime
 * (see freesense_import_map_package()).
 */
function freesense_import_pkgmap_pfsense() {
	/* Prefer the shared .map file so we match the installer exactly. */
	$shared = freesense_import_load_map('pfsense');
	if ($shared !== null) {
		return $shared;
	}
	/* internal-name => [freesense internal-name, confidence] */
	$m = array();
	$identity = array(
		'acme', 'apcupsd', 'arping', 'arpwatch', 'Avahi', 'Backup', 'bind',
		'cellular', 'collectd', 'Cron', 'darkstat', 'Filer', 'freeradius3',
		'frr', 'haproxy', 'haproxy-devel', 'iperf', 'LADVD', 'lldpd',
		'mcast-bridge', 'mDNS-Bridge', 'mtr-nox11', 'net-snmp', 'nmap',
		'node_exporter', 'Notes', 'ntopng', 'nut', 'Open-VM-Tools',
		'openvpn-client-export', 'pfBlockerNG', 'pfBlockerNG-devel', 'pimd',
		'RRD_Summary', 'Service_Watchdog', 'Shellcmd', 'snort', 'softflowd',
		'squid', 'Status_Traffic_Totals', 'stunnel', 'sudo', 'suricata',
		'syslog-ng', 'Tailscale', 'Telegraf', 'tftpd', 'udpbroadcastrelay',
		'WireGuard', 'zabbix-agent6', 'zabbix-agent7', 'zabbix-agent74',
		'zabbix-proxy6', 'zabbix-proxy7', 'zabbix-proxy74',
	);
	foreach ($identity as $n) {
		$m[$n] = array($n, 'exact');
	}
	return $m;
}

/*
 * OPNsense -> FreeSense.
 *
 * OPNsense plugins use os-* names and store their settings in the incompatible
 * <OPNsense> MVC tree, so we can map the PACKAGE to install but generally NOT
 * its configuration - the user reconfigures after import. Entries are keyed by
 * the OPNsense plugin name (with and without the os- prefix accepted at
 * lookup time). Where OPNsense ships one plugin but FreeSense ships several
 * versioned variants (zabbix agents), we default to the current stable and
 * mark the match 'guess' so the user confirms the version.
 */
function freesense_import_pkgmap_opnsense() {
	/* Prefer the shared .map file so we match the installer exactly. */
	$shared = freesense_import_load_map('opnsense');
	if ($shared !== null) {
		return $shared;
	}
	/* opnsense plugin (sans os-) => [freesense internal-name, confidence] */
	return array(
		'acme-client'   => array('acme', 'likely'),
		'haproxy'       => array('haproxy', 'likely'),
		'wireguard'     => array('WireGuard', 'likely'),
		'frr'           => array('frr', 'likely'),
		'nut'           => array('nut', 'likely'),
		'ntopng'        => array('ntopng', 'likely'),
		'tftp'          => array('tftpd', 'likely'),
		'tinc'          => array('', 'none'),
		'freeradius'    => array('freeradius3', 'likely'),
		'radsecproxy'   => array('', 'none'),
		'nmap'          => array('nmap', 'exact'),
		'iperf'         => array('iperf', 'exact'),
		'mtr'           => array('mtr-nox11', 'likely'),
		'lldpd'         => array('lldpd', 'exact'),
		'net-snmp'      => array('net-snmp', 'exact'),
		'collectd'      => array('collectd', 'exact'),
		'telegraf'      => array('Telegraf', 'likely'),
		'node_exporter' => array('node_exporter', 'exact'),
		'stunnel'       => array('stunnel', 'exact'),
		'squid'         => array('squid', 'likely'),
		'siproxd'       => array('', 'none'),
		'apcupsd'       => array('apcupsd', 'exact'),
		'zabbix-agent'  => array('zabbix-agent7', 'guess'),
		'zabbix-proxy'  => array('zabbix-proxy7', 'guess'),
		'zabbix-agent-lts' => array('zabbix-agent74', 'guess'),
		'zabbix-proxy-lts' => array('zabbix-proxy74', 'guess'),
		'bind'          => array('bind', 'likely'),
		'redis'         => array('', 'none'),
		'udpbroadcastrelay' => array('udpbroadcastrelay', 'exact'),
		'relayd'        => array('', 'none'),
		'softflowd'     => array('softflowd', 'exact'),
		'darkstat'      => array('darkstat', 'exact'),
		'arpwatch'      => array('arpwatch', 'exact'),
		'avahi'         => array('Avahi', 'likely'),
		'mdns-repeater' => array('mDNS-Bridge', 'guess'),
		'openvpn-client-export' => array('openvpn-client-export', 'likely'),
		/* OPNsense built-ins that are core in FreeSense (no package needed) */
		'dyndns'        => array('', 'core'),
		'wol'           => array('', 'core'),
		'captiveportal' => array('', 'core'),
	);
}

/*
 * Extract the list of source packages from a parsed foreign config.
 *
 * Returns array of ['name' => <source internal name>, 'raw' => <original>].
 * - pfSense/FreeSense: read <installedpackages><package><name>.
 * - OPNsense: there is no <installedpackages>; plugins are recorded under
 *   <system><firmware><plugins> as a comma/newline list of os-* names. We read
 *   that when present. (Absent on many exports - then we return an empty list
 *   and the importer simply reports "no packages detected".)
 */
function freesense_import_source_packages($conf, $lineage) {
	$out = array();
	if ($lineage == 'pfsense' || $lineage == 'freesense') {
		$pkgs = array_get_path($conf, 'installedpackages/package', array());
		if (is_array($pkgs) && !empty($pkgs) && !isset($pkgs['name'])) {
			foreach ($pkgs as $p) {
				if (!empty($p['name'])) {
					$out[] = array('name' => $p['name'], 'raw' => $p['name']);
				}
			}
		} elseif (is_array($pkgs) && isset($pkgs['name'])) {
			/* single-package configs parse to a bare assoc array */
			$out[] = array('name' => $pkgs['name'], 'raw' => $pkgs['name']);
		}
	} elseif ($lineage == 'opnsense') {
		$plugins = array_get_path($conf, 'system/firmware/plugins', '');
		if (is_string($plugins) && strlen(trim($plugins)) > 0) {
			foreach (preg_split('/[\s,]+/', trim($plugins)) as $p) {
				if ($p === '') {
					continue;
				}
				$out[] = array('name' => $p, 'raw' => $p);
			}
		}
	}
	return $out;
}

/*
 * Map ONE source package name to a FreeSense package suggestion.
 *
 * Returns:
 *   ['source' => .., 'target' => <freesense internal name or ''>,
 *    'confidence' => exact|likely|guess|core|none,
 *    'suggest' => bool  (show as a suggestion at all),
 *    'precheck' => bool (pre-tick it for the user)]
 *
 * $catalog is an optional array of internal names the FreeSense repo actually
 * ships (from pkg rquery). When provided, a target that is not in the catalog
 * is downgraded to 'none' so we never suggest installing something unavailable.
 */
function freesense_import_map_package($name, $lineage, $catalog = null) {
	$src = $name;
	$target = '';
	$conf = 'none';

	if ($lineage == 'pfsense' || $lineage == 'freesense') {
		$map = freesense_import_pkgmap_pfsense();
		if (isset($map[$name])) {
			list($target, $conf) = $map[$name];
		} else {
			/* identity fallback: same lineage, assume 1:1 by name */
			$target = $name;
			$conf = 'likely';
		}
	} elseif ($lineage == 'opnsense') {
		$key = preg_replace('/^os-/', '', $name);
		$map = freesense_import_pkgmap_opnsense();
		if (isset($map[$key])) {
			list($target, $conf) = $map[$key];
		} else {
			/* last-ditch: try the bare name against the catalog below */
			$target = $key;
			$conf = 'guess';
		}
	}

	/* Validate against the real catalog when we have one. */
	if ($conf !== 'core' && $target !== '' && is_array($catalog)) {
		$hit = false;
		foreach ($catalog as $c) {
			if (strcasecmp($c, $target) === 0) {
				$target = $c; /* normalize case to the catalog spelling */
				$hit = true;
				break;
			}
		}
		if (!$hit) {
			$target = '';
			$conf = 'none';
		}
	}

	return array(
		'source'     => $src,
		'target'     => $target,
		'confidence' => $conf,
		'suggest'    => ($conf !== 'none'),
		'precheck'   => in_array($conf, array('exact', 'likely'), true),
	);
}

/*
 * Build the full suggestion list for a foreign config.
 * Each element is the freesense_import_map_package() result. The caller renders
 * these as a checklist (pre-checked per 'precheck') and installs the ticked
 * targets on first boot. 'core' entries are informational ("already built in").
 */
function freesense_import_map_all_packages($conf, $lineage, $catalog = null) {
	$suggestions = array();
	foreach (freesense_import_source_packages($conf, $lineage) as $p) {
		$suggestions[] = freesense_import_map_package($p['name'], $lineage, $catalog);
	}
	return $suggestions;
}

/*
 * Convenience: read the FreeSense repo catalog of installable package internal
 * names, for catalog validation above. Best-effort; returns [] if pkg/repo is
 * unavailable (e.g. running in the installer before the repo is reachable), in
 * which case mapping proceeds without catalog validation.
 */
function freesense_import_pkg_catalog() {
	$out = array();
	$raw = array();
	exec('/usr/sbin/pkg rquery -r FreeSense-packages %n 2>/dev/null', $raw);
	foreach ($raw as $line) {
		$line = trim($line);
		/* keep only the -pkg- add-ons, strip the FreeSense-pkg- prefix to the
		 * internal name used in <installedpackages> */
		if (preg_match('/^FreeSense-pkg-(.+)$/', $line, $mm)) {
			$out[] = $mm[1];
		}
	}
	return $out;
}
