<?php
/*
 * nic_tuning.inc
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
 * Network adapter detection and per-adapter performance tuning for
 * Interfaces > NIC Settings.
 *
 * Detection reads the adapter's own sysctl tree (dev.<driver>.<unit>), so a
 * control is only offered when the driver really has it on this FreeBSD
 * version. Settings live in config.xml under
 * system/nicsettings/adapters/<id>/tuning/<setting>; an empty or missing
 * value keeps the driver default. Live settings are written with sysctl
 * (nic_tuning_apply_live(), also at boot through
 * hardware_offloading_applyflags()); boot settings become loader.conf lines
 * (nic_tuning_loader_lines(), written by setup_loader_settings()).
 *
 * The recommendations follow FreeBSD 16 forwarding measurements (netbenches,
 * Olivier Cochard-Labbe): flow control off on a router, iflib TX abdicate on
 * (+22 % packets per second on an 82599). They are offered, never applied
 * without the administrator asking.
 */

define('NIC_TUNING_SYSCTL', '/sbin/sysctl');

/* The subtrees read per adapter; reading the whole device tree also runs register dumps. */
define('NIC_TUNING_NODES', ['%desc', '%driver', '%pnpinfo', '%location', 'iflib', 'fc', 'enable_aim',
    'dropped', 'rx_overruns', 'mac_stats.missed_packets', 'mac_stats.recv_no_buff']);

/* Drivers whose adaptive interrupt moderation has a low-latency mode (2). */
define('NIC_TUNING_AIM_LOW_LATENCY', ['em', 'igb', 'lem', 'igc']);

/* pure: the interface name split into driver and unit ("ix10" -> ix, 10); null for VLANs and other names */
function nic_tuning_split(string $device): ?array {
	if (!preg_match('/^([a-z][a-z0-9_]*?)([0-9]+)$/D', $device, $m)) {
		return null;
	}
	return ['driver' => $m[1], 'unit' => $m[2]];
}

/*
 * pure: `sysctl -e` output below $prefix keyed by the rest of the name. A line
 * that does not start with the prefix continues the previous (multi-line) value.
 */
function nic_tuning_parse_sysctl(string $prefix, array $lines): array {
	$nodes = [];
	$last = null;
	$start = $prefix . '.';
	$length = strlen($start);
	foreach ($lines as $line) {
		$line = rtrim($line, "\r\n");
		$equals = strpos($line, '=');
		if (strncmp($line, $start, $length) === 0 && $equals !== false) {
			$last = substr($line, $length, $equals - $length);
			$nodes[$last] = substr($line, $equals + 1);
		} elseif ($last !== null) {
			$nodes[$last] .= "\n" . $line;
		}
	}
	return $nodes;
}

/* pure: PCI ids from a %pnpinfo string ("vendor=0x8086 device=0x10fb ..."), four lower-case hex digits (virtio pads to eight) */
function nic_tuning_parse_pnp(string $pnpinfo): array {
	$ids = [];
	foreach (['vendor', 'device', 'subvendor', 'subdevice'] as $key) {
		if (preg_match('/(?:^|\s)' . $key . '=0x([0-9a-f]+)(?:\s|$)/i', $pnpinfo, $m)) {
			$ids[$key] = sprintf('%04x', hexdec($m[1]) & 0xffff);
		}
	}
	return $ids;
}

/*
 * pure: the tuning controls FreeSense knows. "mib" is below dev.<driver>.<unit>;
 * "apply" is live (sysctl) or boot (loader.conf, needs a reboot).
 */
function nic_tuning_controls(): array {
	return [
		'fc' => ['mib' => 'fc', 'apply' => 'live', 'type' => 'choice'],
		'aim' => ['mib' => 'enable_aim', 'apply' => 'live', 'type' => 'choice'],
		'tx_abdicate' => ['mib' => 'iflib.tx_abdicate', 'apply' => 'live', 'type' => 'choice'],
		'queues' => ['mib' => 'iflib.override_nrxqs', 'apply' => 'boot', 'type' => 'count'],
		'rxds' => ['mib' => 'iflib.override_nrxds', 'apply' => 'boot', 'type' => 'descriptors'],
		'txds' => ['mib' => 'iflib.override_ntxds', 'apply' => 'boot', 'type' => 'descriptors'],
	];
}

/* pure: the values a choice control accepts on this driver, with their labels */
function nic_tuning_choices(string $setting, string $driver): array {
	switch ($setting) {
		case 'fc':
			return ['0' => gettext('Off'), '1' => gettext('Receive pause only'),
			    '2' => gettext('Transmit pause only'), '3' => gettext('Receive and transmit pause')];
		case 'aim':
			$choices = ['0' => gettext('Off (fixed interrupt rate)'), '1' => gettext('On')];
			if (in_array($driver, NIC_TUNING_AIM_LOW_LATENCY, true)) {
				$choices['2'] = gettext('On, low latency');
			}
			return $choices;
		case 'tx_abdicate':
			return ['0' => gettext('Off'), '1' => gettext('On')];
	}
	return [];
}

/* pure: queue numbers that appear below iflib (rxq0, rxq1, ...) for one direction */
function nic_tuning_queue_ids(array $nodes, string $direction): array {
	$ids = [];
	foreach (array_keys($nodes) as $name) {
		if (preg_match('/^iflib\.' . $direction . 'q([0-9]+)\./', $name, $m)) {
			$ids[(int)$m[1]] = true;
		}
	}
	ksort($ids);
	return array_keys($ids);
}

/* pure: sum of one per-queue iflib counter over every queue of a direction, null when absent */
function nic_tuning_queue_sum(array $nodes, string $direction, string $counter): ?int {
	$sum = null;
	foreach (nic_tuning_queue_ids($nodes, $direction) as $id) {
		$value = $nodes["iflib.{$direction}q{$id}.{$counter}"] ?? null;
		if ($value !== null && is_numeric($value)) {
			$sum = ($sum ?? 0) + (int)$value;
		}
	}
	return $sum;
}

/*
 * pure: what one adapter has, from its parsed sysctl nodes: identity, iflib
 * queues, the tuning controls present (setting => current value) and counters.
 * A descriptor control is only offered when the queue set has one ring: the
 * value is a per-ring list ("0,0,0" on vmx) that a single number cannot express.
 */
function nic_tuning_detect(string $device, array $nodes): array {
	$split = nic_tuning_split($device);
	$driver = $nodes['%driver'] ?? ($split['driver'] ?? '');
	$rxqs = nic_tuning_queue_ids($nodes, 'rx');
	$txqs = nic_tuning_queue_ids($nodes, 'tx');
	$cpus = [];
	foreach ($rxqs as $id) {
		if (isset($nodes["iflib.rxq{$id}.cpu"]) && ctype_digit($nodes["iflib.rxq{$id}.cpu"])) {
			$cpus[$id] = (int)$nodes["iflib.rxq{$id}.cpu"];
		}
	}

	$controls = [];
	foreach (nic_tuning_controls() as $setting => $control) {
		if (!isset($nodes[$control['mib']])) {
			continue;
		}
		$value = trim($nodes[$control['mib']]);
		if ($control['type'] === 'descriptors' && !preg_match('/^[0-9]+$/D', $value)) {
			continue;
		}
		$controls[$setting] = $value;
	}

	$counters = [];
	$candidates = [
		'tx_ring_drops' => nic_tuning_queue_sum($nodes, 'tx', 'r_drops'),
		'tx_no_descriptors' => nic_tuning_queue_sum($nodes, 'tx', 'no_desc_avail'),
		'tx_watchdog' => isset($nodes['iflib.tx_watchdog_events']) ? (int)$nodes['iflib.tx_watchdog_events'] : null,
		'dropped' => isset($nodes['dropped']) ? (int)$nodes['dropped'] : null,
		'rx_overruns' => isset($nodes['rx_overruns']) ? (int)$nodes['rx_overruns'] : null,
		'rx_missed' => isset($nodes['mac_stats.missed_packets']) ? (int)$nodes['mac_stats.missed_packets'] : null,
		'rx_no_buffers' => isset($nodes['mac_stats.recv_no_buff']) ? (int)$nodes['mac_stats.recv_no_buff'] : null,
	];
	foreach ($candidates as $name => $value) {
		if ($value !== null) {
			$counters[$name] = $value;
		}
	}

	return [
		'device' => $device,
		'driver' => $driver,
		'unit' => $split['unit'] ?? '',
		'found' => !empty($nodes),
		'desc' => trim($nodes['%desc'] ?? ''),
		'pci' => nic_tuning_parse_pnp($nodes['%pnpinfo'] ?? ''),
		'location' => trim($nodes['%location'] ?? ''),
		'iflib' => isset($nodes['iflib.override_nrxqs']),
		'iflib_version' => trim($nodes['iflib.driver_version'] ?? ''),
		'msix' => isset($nodes['iflib.allocated_msix_vectors']) ? (int)$nodes['iflib.allocated_msix_vectors'] : null,
		'rxqs' => count($rxqs),
		'txqs' => count($txqs),
		'queue_cpus' => $cpus,
		'controls' => $controls,
		'counters' => $counters,
	];
}

/*
 * pure: recommended values for this adapter under a NIC profile, limited to
 * the controls it has. The Packet Capture profile recommends nothing.
 */
function nic_tuning_recommend(array $detect, string $profile): array {
	$want = [];
	if ($profile === 'capture') {
		return [];
	}
	/* a router should not stall every flow behind one slow link with pause frames */
	$want['fc'] = '0';
	if ($profile === 'latency') {
		if (in_array($detect['driver'] ?? '', NIC_TUNING_AIM_LOW_LATENCY, true)) {
			$want['aim'] = '2';
		}
	} else {
		/* +22 % forwarded packets per second measured on FreeBSD 16 (netbenches) */
		$want['tx_abdicate'] = '1';
	}
	if ($profile === 'throughput') {
		$want['rxds'] = '2048';
		$want['txds'] = '2048';
	}
	return array_intersect_key($want, $detect['controls'] ?? []);
}

/*
 * pure: settings whose current value differs from the recommendation, as
 * setting => ['current' => ..., 'recommended' => ...]. A descriptor or queue
 * override of 0 means the driver default and counts as different.
 */
function nic_tuning_differences(array $detect, array $recommended): array {
	$result = [];
	foreach ($recommended as $setting => $value) {
		$current = (string)($detect['controls'][$setting] ?? '');
		if ($current !== (string)$value) {
			$result[$setting] = ['current' => $current, 'recommended' => (string)$value];
		}
	}
	return $result;
}

/*
 * pure: an error message for a tuning value, or null when it is valid. An
 * empty value keeps the driver default. iflib clamps queue and descriptor
 * overrides to what the hardware supports, so the limits here only catch typos.
 */
function nic_tuning_validate(string $setting, string $value, array $detect, int $ncpu): ?string {
	$controls = nic_tuning_controls();
	if (!isset($controls[$setting])) {
		return gettext('Unknown tuning setting.');
	}
	if ($value === '') {
		return null;
	}
	if (!array_key_exists($setting, $detect['controls'] ?? [])) {
		return gettext('This adapter does not have this setting.');
	}
	switch ($controls[$setting]['type']) {
		case 'choice':
			if (!array_key_exists($value, nic_tuning_choices($setting, $detect['driver'] ?? ''))) {
				return gettext('Choose one of the listed values.');
			}
			return null;
		case 'count':
			if (!ctype_digit($value) || (int)$value < 1 || (int)$value > max(1, $ncpu)) {
				return sprintf(gettext('Enter a number of queues from 1 to %d (the number of CPUs).'), max(1, $ncpu));
			}
			return null;
		case 'descriptors':
			$number = ctype_digit($value) ? (int)$value : 0;
			if ($number < 64 || $number > 16384 || ($number & ($number - 1)) !== 0) {
				return gettext('Enter a power of two from 64 to 16384.');
			}
			return null;
	}
	return gettext('Unknown tuning setting.');
}

/* pure: the saved tuning of one adapter, only known settings with a valid-looking value */
function nic_tuning_saved(array $adapter): array {
	$tuning = $adapter['tuning'] ?? [];
	if (!is_array($tuning)) {
		return [];
	}
	$result = [];
	foreach (array_keys(nic_tuning_controls()) as $setting) {
		$value = $tuning[$setting] ?? '';
		if (is_string($value) || is_int($value)) {
			$value = (string)$value;
			if (preg_match('/^[0-9]{1,5}$/D', $value)) {
				$result[$setting] = $value;
			}
		}
	}
	return $result;
}

/*
 * pure: loader.conf lines for the boot-time tuning of every saved adapter
 * (system/nicsettings/adapters). The device name comes from config.xml, so it
 * must pass nic_tuning_split() before it reaches the file.
 */
function nic_tuning_loader_lines(array $adapters): array {
	$lines = [];
	foreach ($adapters as $adapter) {
		if (!is_array($adapter)) {
			continue;
		}
		$split = nic_tuning_split((string)($adapter['device'] ?? ''));
		if ($split === null) {
			continue;
		}
		$base = "dev.{$split['driver']}.{$split['unit']}.iflib";
		$tuning = nic_tuning_saved($adapter);
		if (isset($tuning['queues'])) {
			$lines[] = "{$base}.override_nrxqs=\"{$tuning['queues']}\"";
			$lines[] = "{$base}.override_ntxqs=\"{$tuning['queues']}\"";
		}
		if (isset($tuning['rxds'])) {
			$lines[] = "{$base}.override_nrxds=\"{$tuning['rxds']}\"";
		}
		if (isset($tuning['txds'])) {
			$lines[] = "{$base}.override_ntxds=\"{$tuning['txds']}\"";
		}
	}
	return $lines;
}

/* pure: whether a loader.conf variable is one nic_tuning_loader_lines() writes */
function nic_tuning_is_loader_key(string $name): bool {
	return (bool)preg_match('/^dev\.[a-z][a-z0-9_]*\.[0-9]+\.iflib\.override_n(rx|tx)(qs|ds)$/D', trim($name));
}

/*
 * pure: advice for one adapter as a list of sentences: driver quirks and
 * queue layout. $vm_guest is kern.vm_guest ("none" on bare metal).
 */
function nic_tuning_notes(array $detect, int $ncpu, string $vm_guest): array {
	$notes = [];
	switch ($detect['driver'] ?? '') {
		case 're':
			$notes[] = gettext('Realtek 1 Gbit/s adapters are known for unstable offloads in the base driver: if you see drops or corruption, turn checksum offload off.');
			break;
		case 'rge':
			$notes[] = gettext('The Realtek 2.5/5/10 Gbit/s driver is new in FreeBSD 16. Leave its offloads at the profile default until it has matured.');
			break;
		case 'cxl':
		case 'cc':
		case 'vcxl':
			$notes[] = gettext('Chelsio: TCP offload (TOE) is never enabled here, because offloaded connections bypass the firewall.');
			break;
		case 'ice':
			$notes[] = gettext('Intel E800 adapters need their DDP package; without it the driver runs in Safe Mode with one queue (check the boot log).');
			break;
		case 'vtnet':
		case 'hn':
			$notes[] = gettext('ALTQ traffic shaping on this driver turns multiqueue off (Profile view, needs a reboot).');
			break;
	}
	if (($detect['driver'] ?? '') === 'vmx' && ($detect['msix'] ?? 2) <= 1 && $vm_guest === 'vmware') {
		$notes[] = gettext('Only one interrupt vector: VMware multiqueue needs the loader tunable hw.pci.honor_msi_blacklist="0".');
	}
	if (!empty($detect['iflib']) && ($detect['rxqs'] ?? 0) === 1 && $ncpu > 1 && ($detect['msix'] ?? 0) > 1) {
		$notes[] = sprintf(gettext('One receive queue on a %d-CPU system: all traffic from this adapter is handled by one CPU.'), $ncpu);
	}
	return $notes;
}

/* The adapter's sysctl nodes, read once per request unless $refresh asks again. */
function nic_tuning_read(string $device, bool $refresh = false): array {
	static $cache = [];
	if (isset($cache[$device]) && !$refresh) {
		return $cache[$device];
	}
	$split = nic_tuning_split($device);
	if ($split === null) {
		return $cache[$device] = [];
	}
	$prefix = "dev.{$split['driver']}.{$split['unit']}";
	$args = '';
	foreach (NIC_TUNING_NODES as $node) {
		$args .= ' ' . escapeshellarg("{$prefix}.{$node}");
	}
	$lines = [];
	exec(NIC_TUNING_SYSCTL . ' -ei' . $args . ' 2>/dev/null', $lines);
	return $cache[$device] = nic_tuning_parse_sysctl($prefix, $lines);
}

/* What one adapter has right now (nic_tuning_detect() over the live sysctl tree). */
function nic_tuning_adapter(string $device, bool $refresh = false): array {
	return nic_tuning_detect($device, nic_tuning_read($device, $refresh));
}

/*
 * Writes the saved live settings of one adapter with sysctl. A setting
 * cleared back to the driver default keeps its current value until reboot,
 * because the driver default is not known at run time. Returns the settings
 * that failed, logged as well.
 */
function nic_tuning_apply_live(string $device, array $tuning): array {
	$split = nic_tuning_split($device);
	if ($split === null || empty($tuning)) {
		return [];
	}
	$detect = nic_tuning_adapter($device, true);
	$failed = [];
	foreach (nic_tuning_controls() as $setting => $control) {
		if ($control['apply'] !== 'live' || !isset($tuning[$setting]) ||
		    !array_key_exists($setting, $detect['controls']) || $detect['controls'][$setting] === $tuning[$setting]) {
			continue;
		}
		$mib = "dev.{$split['driver']}.{$split['unit']}.{$control['mib']}";
		$output = [];
		$rc = 0;
		exec(NIC_TUNING_SYSCTL . ' ' . escapeshellarg("{$mib}={$tuning[$setting]}") . ' 2>&1', $output, $rc);
		if ($rc !== 0) {
			$failed[] = $setting;
			logger(LOG_WARNING, localize_text('Could not set %1$s to %2$s: %3$s', $mib, $tuning[$setting], implode(' ', $output)));
		}
	}
	return $failed;
}

/* pure: whether a change between two saved tuning sets touches a boot setting (needs loader.conf and a reboot) */
function nic_tuning_boot_changed(array $old, array $new): bool {
	foreach (nic_tuning_controls() as $setting => $control) {
		if ($control['apply'] === 'boot' && ($old[$setting] ?? '') !== ($new[$setting] ?? '')) {
			return true;
		}
	}
	return false;
}
