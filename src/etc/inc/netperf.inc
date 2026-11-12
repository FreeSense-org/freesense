<?php
/*
 * netperf.inc
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
 * System-wide network performance settings for System > Advanced >
 * Performance: how received packets are spread over the CPUs (netisr with
 * the RSS kernel) and the network buffer (mbuf cluster) limit. Both are boot
 * settings, written to /boot/loader.conf by setup_loader_settings().
 *
 * config.xml: system/netisr_threads ("" = automatic, "single"),
 * system/nmbclusters ("" = automatic, or a number).
 *
 * Why automatic means every CPU: the FreeSense kernel is built with options
 * RSS, which hands each packet to the netisr thread of the CPU its RSS hash
 * belongs to. With the FreeBSD default of one netisr thread, packets for the
 * other CPUs pile up on that single thread; FreeBSD 16 forwarding tests lost
 * about 96 % of small packets that way, and net.isr.maxthreads=-1 was ten
 * times faster (netbenches, Olivier Cochard-Labbe).
 */

define('NETPERF_NMBCLUSTERS_MIN', 32768);
define('NETPERF_NMBCLUSTERS_MAX', 16777216);

/* pure: the netisr setting as stored, "auto" for anything unknown */
function netperf_netisr_mode(?string $stored): string {
	return ($stored === 'single') ? 'single' : 'auto';
}

/*
 * pure: loader.conf lines for the saved settings. $rss_kernel says whether
 * the running kernel has RSS; without it netisr dispatches directly and the
 * thread settings do nothing, so none are written.
 */
function netperf_loader_lines(array $settings, bool $rss_kernel): array {
	$lines = [];
	if ($rss_kernel && netperf_netisr_mode($settings['netisr_threads'] ?? null) === 'auto') {
		$lines[] = 'net.isr.maxthreads="-1"';
		$lines[] = 'net.isr.bindthreads="1"';
	}
	$clusters = (string)($settings['nmbclusters'] ?? '');
	if (preg_match('/^[0-9]{1,9}$/D', $clusters) && netperf_validate_nmbclusters($clusters) === null) {
		$lines[] = "kern.ipc.nmbclusters=\"{$clusters}\"";
	}
	return $lines;
}

/* pure: the loader.conf variables netperf_loader_lines() manages */
function netperf_loader_keys(): array {
	return ['net.isr.maxthreads', 'net.isr.bindthreads', 'kern.ipc.nmbclusters'];
}

/* pure: an error message for an mbuf cluster limit, or null when valid ("" = automatic) */
function netperf_validate_nmbclusters(string $value): ?string {
	if ($value === '') {
		return null;
	}
	if (!ctype_digit($value) || (int)$value < NETPERF_NMBCLUSTERS_MIN || (int)$value > NETPERF_NMBCLUSTERS_MAX) {
		return sprintf(gettext('Network buffers must be a number from %1$d to %2$d.'), NETPERF_NMBCLUSTERS_MIN, NETPERF_NMBCLUSTERS_MAX);
	}
	return null;
}

/*
 * pure: loader values that differ between what the settings ask for and what
 * the kernel booted with (kenv), as name => ['wanted' => ..., 'booted' => ...].
 * A value the settings no longer write counts when the kernel still has it.
 */
function netperf_pending(array $lines, array $booted, array $keys): array {
	$wanted = [];
	foreach ($lines as $line) {
		if (preg_match('/^([a-z0-9_.]+)="([^"]*)"$/D', $line, $m)) {
			$wanted[$m[1]] = $m[2];
		}
	}
	$pending = [];
	foreach ($keys as $key) {
		$want = $wanted[$key] ?? null;
		$have = $booted[$key] ?? null;
		if ($want !== $have) {
			$pending[$key] = ['wanted' => $want, 'booted' => $have];
		}
	}
	return $pending;
}

/* pure: mbuf cluster use from `vmstat -z --libxo=json`, or null when the zone is missing */
function netperf_parse_mbuf_zone(string $json): ?array {
	$data = json_decode($json, true);
	$zones = $data['vmstat']['memory-zone-statistics']['zone'] ?? null;
	if (!is_array($zones)) {
		return null;
	}
	foreach ($zones as $zone) {
		if (($zone['name'] ?? '') === 'mbuf_cluster') {
			return [
				'limit' => (int)($zone['limit'] ?? 0),
				'used' => (int)($zone['used'] ?? 0),
				'free' => (int)($zone['free'] ?? 0),
				'failures' => (int)($zone['fail'] ?? 0),
			];
		}
	}
	return null;
}

/* Whether the running kernel has RSS, and with how many hash bits. */
function netperf_rss_state(): array {
	$feature = (string)get_single_sysctl('kern.features.rss');
	$bits = (string)get_single_sysctl('net.inet.rss.bits');
	return [
		'enabled' => ($feature === '1') || ($bits !== ''),
		'bits' => ctype_digit($bits) ? (int)$bits : null,
	];
}

/* The settings as netperf_loader_lines() takes them, from config.xml. */
function netperf_settings(): array {
	return [
		'netisr_threads' => netperf_netisr_mode(config_get_path('system/netisr_threads')),
		'nmbclusters' => (string)config_get_path('system/nmbclusters', ''),
	];
}

/* The loader.conf lines for the current configuration and kernel. */
function netperf_current_loader_lines(): array {
	return netperf_loader_lines(netperf_settings(), netperf_rss_state()['enabled']);
}

/* The managed loader values the running kernel booted with. */
function netperf_booted_values(): array {
	$values = [];
	foreach (netperf_loader_keys() as $key) {
		$output = [];
		$rc = 0;
		exec('/bin/kenv -q ' . escapeshellarg($key) . ' 2>/dev/null', $output, $rc);
		if ($rc === 0 && isset($output[0])) {
			$values[$key] = trim($output[0]);
		}
	}
	return $values;
}

/* Live mbuf cluster use, or null when it cannot be read. */
function netperf_mbuf_usage(): ?array {
	$output = [];
	exec('/usr/bin/vmstat -z --libxo=json 2>/dev/null', $output);
	return netperf_parse_mbuf_zone(implode("\n", $output));
}

/*
 * pure: errors for the pf SYN cookie settings (System > Advanced > Firewall &
 * NAT). Adaptive switches cookies on when the state table is "start" percent
 * full and off again below "end" percent; empty uses pf's 25 % and 12 %.
 */
function netperf_validate_syncookies(string $mode, string $start, string $end): array {
	$errors = [];
	if (!in_array($mode, ['', 'never', 'adaptive', 'always'], true)) {
		$errors[] = gettext('Choose when SYN cookies are used.');
		return $errors;
	}
	if ($mode !== 'adaptive') {
		return $errors;
	}
	foreach (['start' => $start, 'end' => $end] as $name => $value) {
		if ($value !== '' && (!ctype_digit($value) || (int)$value < 1 || (int)$value > 100)) {
			$errors[] = sprintf(gettext('The SYN cookie %s value must be a percentage from 1 to 100.'), $name);
		}
	}
	if (!$errors && (int)($start === '' ? 25 : $start) <= (int)($end === '' ? 12 : $end)) {
		$errors[] = gettext('The SYN cookie start value must be higher than the end value.');
	}
	return $errors;
}

/* pure: the pf.conf line for the SYN cookie settings, or null for pf's default (never) */
function netperf_pf_syncookies(string $mode, string $start, string $end): ?string {
	if ($mode === 'always') {
		return 'set syncookies always';
	}
	if ($mode !== 'adaptive' || netperf_validate_syncookies($mode, $start, $end)) {
		return null;
	}
	$start = ($start === '') ? 25 : (int)$start;
	$end = ($end === '') ? 12 : (int)$end;
	return "set syncookies adaptive (start {$start}%, end {$end}%)";
}
