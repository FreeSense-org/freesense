<?php
/*
 * status_metrics.inc
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
 * Live system metrics as structured data, for GET /api/v1/status/system,
 * /status/traffic, /status/traffic/history and /status/states.
 *
 * Counters that need two samples (CPU ticks, interface bytes) keep the last
 * sample in a small file under /var/run, so a rate is available from the
 * second request on without sleeping inside a request. Command output is
 * parsed by pure functions (status_metrics_parse_*) that the tests cover.
 */

require_once('config.inc');
require_once('util.inc');
require_once('interfaces.inc');
require_once('freesense-utils.inc');

define('STATUS_METRICS_CPU_SAMPLE', '/var/run/fs_metrics_cpu.json');
define('STATUS_METRICS_IF_SAMPLE', '/var/run/fs_metrics_if.json');
/* A previous sample older than this is not used for a rate. */
define('STATUS_METRICS_MAX_AGE', 30);

/* ------------------------------------------------------------ samples */

function status_metrics_sample_read($file) {
	$data = @json_decode((string)@file_get_contents($file), true);
	return is_array($data) ? $data : array();
}

function status_metrics_sample_write($file, array $data) {
	@file_put_contents($file, json_encode($data), LOCK_EX);
	@chmod($file, 0600);
}

/*
 * Rate per second between two counter samples, or null when there is no
 * usable previous sample or the counter went backwards (reset, wrap).
 */
function status_metrics_rate($prev_value, $prev_time, $value, $time) {
	if (!is_numeric($prev_value) || !is_numeric($prev_time)) {
		return null;
	}
	$dt = (float)$time - (float)$prev_time;
	if (($dt <= 0) || ($dt > STATUS_METRICS_MAX_AGE) || ((float)$value < (float)$prev_value)) {
		return null;
	}
	return ((float)$value - (float)$prev_value) / $dt;
}

/* The stored sample to compute a rate against: the latest, or the one before it when the latest is under a second old. */
function status_metrics_rate_base(array $sample, $now) {
	if (isset($sample['t']) && (((float)$now - (float)$sample['t']) < 1) && is_array($sample['prev'] ?? null)) {
		return $sample['prev'];
	}
	return $sample;
}

/* ------------------------------------------------------------ parsers */

/* kern.cp_time "user nice sys intr idle" → busy percent between two samples, or null. */
function status_metrics_cpu_percent($prev, $now) {
	if (!is_array($prev) || (count($prev) !== 5) || !is_array($now) || (count($now) !== 5)) {
		return null;
	}
	$total = array_sum($now) - array_sum($prev);
	$idle = $now[4] - $prev[4];
	if ($total <= 0) {
		return null;
	}
	return round(max(0, min(100, 100 * (1 - ($idle / $total)))), 1);
}

/* `pfctl -si` → state table totals and rates. */
function status_metrics_parse_pfinfo($text) {
	$out = array('current' => null, 'searches' => null, 'inserts' => null, 'removals' => null,
	    'search_rate' => null, 'insert_rate' => null, 'removal_rate' => null);
	foreach (preg_split('/\r?\n/', (string)$text) as $line) {
		if (preg_match('/^\s*current entries\s+(\d+)/', $line, $m)) {
			$out['current'] = (int)$m[1];
		} elseif (preg_match('/^\s*(searches|inserts|removals)\s+(\d+)\s+([0-9.]+)\/s/', $line, $m)) {
			$out[$m[1]] = (int)$m[2];
			$out[array('searches' => 'search_rate', 'inserts' => 'insert_rate', 'removals' => 'removal_rate')[$m[1]]] = (float)$m[3];
		}
	}
	return $out;
}

/* `df -kTt ufs,zfs,tmpfs,cd9660` → filesystems with sizes in bytes. */
function status_metrics_parse_df($text) {
	$out = array();
	foreach (preg_split('/\r?\n/', trim((string)$text)) as $i => $line) {
		if (($i === 0) || (trim($line) === '')) {
			continue;
		}
		$f = preg_split('/\s+/', trim($line), 7);
		if ((count($f) < 7) || !is_numeric($f[2]) || !is_numeric($f[3])) {
			continue;
		}
		$out[] = array('device' => $f[0], 'type' => $f[1], 'total_bytes' => (int)$f[2] * 1024,
		    'used_bytes' => (int)$f[3] * 1024, 'mount' => $f[6]);
	}
	return $out;
}

/* `swapinfo -k` → total and used swap in bytes (the Total line when there are several devices). */
function status_metrics_parse_swapinfo($text) {
	$total = 0;
	$used = 0;
	$lines = preg_split('/\r?\n/', trim((string)$text));
	foreach ($lines as $i => $line) {
		$f = preg_split('/\s+/', trim($line));
		if (($i === 0) || (count($f) < 4) || !is_numeric($f[1])) {
			continue;
		}
		if ($f[0] === 'Total') {
			return array('total_bytes' => (int)$f[1] * 1024, 'used_bytes' => (int)$f[2] * 1024);
		}
		$total += (int)$f[1];
		$used += (int)$f[2];
	}
	return array('total_bytes' => $total * 1024, 'used_bytes' => $used * 1024);
}

/* `vmstat -z --libxo=json` → mbuf clusters in use and their limit. */
function status_metrics_parse_mbuf($json) {
	$data = json_decode((string)$json, true);
	foreach ((array)($data['vmstat']['memory-zone-statistics']['zone'] ?? array()) as $zone) {
		if (($zone['name'] ?? '') === 'mbuf_cluster') {
			return array('used' => (int)($zone['used'] ?? 0) + (int)($zone['free'] ?? 0), 'max' => (int)($zone['limit'] ?? 0));
		}
	}
	return array('used' => null, 'max' => null);
}

/* A sysctl temperature such as "45.0C" → 45.0, or null. */
function status_metrics_parse_temp($value) {
	$value = trim(rtrim(trim((string)$value), 'C'));
	return (($value !== '') && is_numeric($value) && ((float)$value > -50)) ? round((float)$value, 1) : null;
}

/*
 * `rrdtool xport` XML → array('t' => [...], <legend> => [...]). Values that
 * are NaN (no data) become null. Rows carry <t> with --showtime; without it
 * (rrdtool 1.11 default) row i is at start + i * step.
 */
function status_metrics_parse_xport($xml) {
	$doc = @simplexml_load_string((string)$xml);
	if ($doc === false) {
		return null;
	}
	$legend = array();
	foreach ($doc->meta->legend->entry as $e) {
		$legend[] = (string)$e;
	}
	$out = array('t' => array());
	foreach ($legend as $name) {
		$out[$name] = array();
	}
	$start = (int)$doc->meta->start;
	$step = (int)$doc->meta->step;
	$n = 0;
	foreach ($doc->data->row as $row) {
		$out['t'][] = isset($row->t) ? (int)$row->t : $start + ($n * $step);
		$n++;
		$i = 0;
		foreach ($row->v as $v) {
			$v = (string)$v;
			$out[$legend[$i] ?? "v{$i}"][] = (is_numeric($v) && (stripos($v, 'nan') === false)) ? round((float)$v, 2) : null;
			$i++;
		}
	}
	$out['step'] = $step;
	return $out;
}

/* ------------------------------------------------------------- system */

/* CPU busy percent since the previous call (any client), or null on the first call. */
function status_metrics_cpu_usage() {
	$now = array_map('intval', explode(' ', trim((string)get_single_sysctl('kern.cp_time'))));
	$sample = status_metrics_sample_read(STATUS_METRICS_CPU_SAMPLE);
	$usage = null;
	if (isset($sample['t'], $sample['ticks']) && ((time() - (int)$sample['t']) <= STATUS_METRICS_MAX_AGE)) {
		$usage = status_metrics_cpu_percent($sample['ticks'], $now);
	}
	/* Keep the older sample when the last one is very recent, so polls from
	 * several clients still measure over a useful interval. */
	if (!isset($sample['t']) || ((time() - (int)$sample['t']) >= 1)) {
		status_metrics_sample_write(STATUS_METRICS_CPU_SAMPLE, array('t' => time(), 'ticks' => $now, 'usage' => $usage));
	} elseif ($usage === null) {
		$usage = $sample['usage'] ?? null;
	}
	return $usage;
}

function status_metrics_system() {
	sysctl_prefetch(array('kern.cp_time', 'vm.stats.vm.v_page_count', 'vm.stats.vm.v_inactive_count',
	    'vm.stats.vm.v_laundry_count', 'vm.stats.vm.v_free_count', 'hw.pagesize', 'hw.ncpu', 'hw.model',
	    'dev.cpu.0.freq_levels', 'dev.cpu.0.freq', 'hw.acpi.thermal.tz0.temperature'));

	$ncpu = (int)get_single_sysctl('hw.ncpu');
	$page = (int)get_single_sysctl('hw.pagesize') ?: 4096;
	$pages = (int)get_single_sysctl('vm.stats.vm.v_page_count');
	$avail = (int)get_single_sysctl('vm.stats.vm.v_inactive_count') + (int)get_single_sysctl('vm.stats.vm.v_laundry_count') +
	    (int)get_single_sysctl('vm.stats.vm.v_free_count');
	$load = sys_getloadavg();
	$levels = explode(' ', trim((string)get_single_sysctl('dev.cpu.0.freq_levels')));
	$maxfreq = (int)explode('/', $levels[0] ?? '')[0];

	$temps = array();
	for ($i = 0; $i < $ncpu; $i++) {
		$t = status_metrics_parse_temp(get_single_sysctl("dev.cpu.{$i}.temperature"));
		if ($t !== null) {
			$temps[] = array('name' => sprintf('CPU %d', $i), 'celsius' => $t);
		}
	}
	$tz = status_metrics_parse_temp(get_single_sysctl('hw.acpi.thermal.tz0.temperature'));
	if ($tz !== null) {
		$temps[] = array('name' => 'ACPI zone 0', 'celsius' => $tz);
	}
	sysctl_prefetch(null);

	$mbuf = status_metrics_parse_mbuf((string)shell_exec('/usr/bin/vmstat -z --libxo=json 2>/dev/null'));
	$states = status_metrics_states();
	return array(
		'cpu' => array(
			'usage' => status_metrics_cpu_usage(),
			'count' => $ncpu,
			'model' => (string)get_single_sysctl('hw.model'),
			'load' => is_array($load) ? array_map(function ($v) { return round($v, 2); }, $load) : null,
			'freq_mhz' => ((int)get_single_sysctl('dev.cpu.0.freq') > 0) ? (int)get_single_sysctl('dev.cpu.0.freq') : null,
			'freq_max_mhz' => ($maxfreq > 0) ? $maxfreq : null,
		),
		'memory' => array('total_bytes' => $pages * $page, 'used_bytes' => max(0, ($pages - $avail) * $page)),
		'swap' => status_metrics_parse_swapinfo((string)shell_exec('/usr/sbin/swapinfo -k 2>/dev/null')),
		'mbuf' => $mbuf,
		'states' => array('current' => $states['current'], 'max' => $states['max']),
		'temperatures' => $temps,
		'disks' => status_metrics_parse_df((string)shell_exec('/bin/df -kTt ufs,zfs,tmpfs,cd9660 2>/dev/null')),
		'uptime_seconds' => (int)get_uptime_sec(),
		'time' => gmdate('c'),
	);
}

function status_metrics_states() {
	$info = status_metrics_parse_pfinfo((string)shell_exec('/sbin/pfctl -si 2>/dev/null'));
	$max = (int)config_get_path('system/maximumstates', 0);
	$info['max'] = ($max > 0) ? $max : (int)freesense_default_state_size();
	return $info;
}

/* ------------------------------------------------------------ traffic */

/*
 * Current counters and rates (bits/s, packets/s) for the given interfaces
 * (friendly names such as wan, lan, opt1); all assigned interfaces when empty.
 */
function status_metrics_traffic(array $ifnames = array()) {
	$all = get_configured_interface_with_descr();
	$ifnames = empty($ifnames) ? array_keys($all) : array_values(array_intersect($ifnames, array_keys($all)));
	$prev = status_metrics_sample_read(STATUS_METRICS_IF_SAMPLE);
	$next = $prev;
	$now = microtime(true);
	$out = array();
	foreach ($ifnames as $if) {
		$realif = get_real_interface($if);
		if (empty($realif)) {
			continue;
		}
		$s = FreeSense_get_interface_stats($realif);
		if (!is_array($s)) {
			continue;
		}
		/*
		 * Rate against the newest sample at least a second old: another request
		 * (e.g. earlier in the same /v1/batch) may just have stored a fresh one.
		 */
		$p = status_metrics_rate_base($prev[$if] ?? array(), $now);
		$in = status_metrics_rate($p['in'] ?? null, $p['t'] ?? null, $s['inbytes'], $now);
		$outb = status_metrics_rate($p['out'] ?? null, $p['t'] ?? null, $s['outbytes'], $now);
		$inp = status_metrics_rate($p['inp'] ?? null, $p['t'] ?? null, $s['inpkts'] ?? 0, $now);
		$outp = status_metrics_rate($p['outp'] ?? null, $p['t'] ?? null, $s['outpkts'] ?? 0, $now);
		$out[$if] = array(
			'description' => $all[$if],
			'in_bps' => ($in === null) ? null : (int)round($in * 8),
			'out_bps' => ($outb === null) ? null : (int)round($outb * 8),
			'in_pps' => ($inp === null) ? null : (int)round($inp),
			'out_pps' => ($outp === null) ? null : (int)round($outp),
			'in_bytes' => (int)$s['inbytes'],
			'out_bytes' => (int)$s['outbytes'],
		);
		/* Store a new sample at most once a second, keeping the one before it. */
		$last = $prev[$if] ?? array();
		if (!isset($last['t']) || (($now - (float)$last['t']) >= 1)) {
			unset($last['prev']);
			$next[$if] = array('t' => $now, 'in' => $s['inbytes'], 'out' => $s['outbytes'], 'inp' => $s['inpkts'] ?? 0, 'outp' => $s['outpkts'] ?? 0,
			    'prev' => isset($last['t']) ? $last : null);
		}
	}
	status_metrics_sample_write(STATUS_METRICS_IF_SAMPLE, $next);
	return array('t' => (int)floor($now), 'interfaces' => $out);
}

/* History ranges: seconds back and the RRA step that holds them. */
function status_metrics_ranges() {
	return array('10m' => array(600, 60), '1h' => array(3600, 60), '24h' => array(86400, 300),
	    '7d' => array(604800, 3600), '30d' => array(2592000, 3600));
}

/* rrdtool xport over a range: array(step, t, series...) or null. $defs are DEF/CDEF/XPORT arguments. */
function status_metrics_xport($range, $defs) {
	$ranges = status_metrics_ranges();
	if (!isset($ranges[$range])) {
		return null;
	}
	list($back, $step) = $ranges[$range];
	$cmd = '/usr/local/bin/rrdtool xport --showtime --start ' . escapeshellarg("-{$back}") . ' --end now --step ' . (int)$step .
	    " {$defs} 2>/dev/null";
	$data = status_metrics_parse_xport((string)shell_exec($cmd));
	return is_array($data) ? $data : null;
}

/*
 * CPU and memory history in percent from the system RRDs: cpu = user + nice
 * + system + interrupt, mem = active + wired + laundry (of all memory).
 * Null when the RRDs do not exist yet.
 */
function status_metrics_system_history($range) {
	$dir = g_get('vardb_path') . '/rrd';
	$cpu = "{$dir}/system-processor.rrd";
	$mem = "{$dir}/system-memory.rrd";
	if (!is_file($cpu) || !is_file($mem)) {
		return null;
	}
	$c = escapeshellarg($cpu);
	$m = escapeshellarg($mem);
	$data = status_metrics_xport($range,
	    "DEF:u={$c}:user:AVERAGE DEF:n={$c}:nice:AVERAGE DEF:s={$c}:system:AVERAGE DEF:i={$c}:interrupt:AVERAGE " .
	    "DEF:a={$m}:active:AVERAGE DEF:w={$m}:wire:AVERAGE DEF:l={$m}:laundry:AVERAGE " .
	    'CDEF:cpu=u,n,ADDNAN,s,ADDNAN,i,ADDNAN CDEF:mem=a,w,ADDNAN,l,ADDNAN XPORT:cpu:cpu XPORT:mem:mem');
	if ($data === null) {
		return null;
	}
	return array('range' => $range, 'step' => $data['step'], 't' => $data['t'], 'cpu' => $data['cpu'] ?? array(), 'mem' => $data['mem'] ?? array());
}

/*
 * Gateway quality history from each gateway's RRD: delay and stddev in ms,
 * loss in percent. Gateways without an RRD are left out.
 */
function status_metrics_gateway_history(array $names, $range) {
	$out = array('range' => $range, 'step' => null, 't' => array(), 'gateways' => array());
	foreach ($names as $name) {
		if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string)$name)) {
			continue;
		}
		$file = g_get('vardb_path') . "/rrd/{$name}-quality.rrd";
		if (!is_file($file)) {
			continue;
		}
		$f = escapeshellarg($file);
		$data = status_metrics_xport($range, "DEF:d={$f}:delay:AVERAGE DEF:s={$f}:stddev:AVERAGE DEF:l={$f}:loss:AVERAGE " .
		    'CDEF:dms=d,1000,* CDEF:sms=s,1000,* XPORT:dms:delay XPORT:sms:stddev XPORT:l:loss');
		if ($data === null) {
			continue;
		}
		if ($out['step'] === null) {
			$out['step'] = $data['step'];
			$out['t'] = $data['t'];
		}
		$out['gateways'][$name] = array('delay' => $data['delay'] ?? array(), 'stddev' => $data['stddev'] ?? array(), 'loss' => $data['loss'] ?? array());
	}
	return $out;
}

/*
 * `wg show all dump` → peers: array of array(interface, public_key,
 * endpoint, allowed_ips, handshake (unix time or 0), rx, tx). Interface lines
 * (5 fields) are skipped; peer lines have 9 tab-separated fields.
 */
function status_metrics_parse_wg_dump($text) {
	$peers = array();
	foreach (preg_split('/\r?\n/', trim((string)$text)) as $line) {
		$f = explode("\t", $line);
		if (count($f) !== 9) {
			continue;
		}
		$peers[] = array('interface' => $f[0], 'public_key' => $f[1], 'endpoint' => ($f[3] === '(none)') ? '' : $f[3],
		    'allowed_ips' => ($f[4] === '(none)') ? '' : $f[4], 'handshake' => (int)$f[5], 'rx' => (int)$f[6], 'tx' => (int)$f[7]);
	}
	return $peers;
}

/* A WireGuard peer counts as up while its last handshake is recent (WireGuard re-keys every 2 minutes). */
define('STATUS_METRICS_WG_UP_SECONDS', 180);

/*
 * VPN tunnels for the dashboard: array of array(type, name, description,
 * status: up | connecting | idle (a server without clients) | down, peer,
 * since (seconds connected) or null,
 * handshake (seconds ago, WireGuard) or null, rx, tx (bytes)).
 * OpenVPN and IPsec come from core; WireGuard from `wg` when the package
 * provides it, named after the peers' descriptions in its configuration.
 */
function status_metrics_vpn() {
	$out = array();
	$now = time();

	if (function_exists('openvpn_get_active_servers')) {
		foreach (array_merge(openvpn_get_active_servers(), openvpn_get_active_servers('p2p')) as $server) {
			$conns = is_array($server['conns'] ?? null) ? $server['conns'] : array();
			$rx = $tx = 0;
			$since = null;
			foreach ($conns as $c) {
				$rx += (int)($c['bytes_recv'] ?? 0);
				$tx += (int)($c['bytes_sent'] ?? 0);
				if (!empty($c['connect_time_unix'])) {
					$since = max((int)$since, $now - (int)$c['connect_time_unix']);
				}
			}
			$n = count(array_filter($conns, function ($c) { return ($c['common_name'] ?? '') !== '[error]'; }));
			$out[] = array('type' => 'openvpn', 'name' => (string)($server['name'] ?? ''), 'description' => (string)($server['name'] ?? ''),
			    'status' => ($n > 0) ? 'up' : 'idle', 'peer' => sprintf(ngettext('%d client', '%d clients', $n), $n),
			    'since' => $since, 'handshake' => null, 'rx' => $rx, 'tx' => $tx, 'vpnid' => (int)($server['vpnid'] ?? 0));
		}
		foreach (openvpn_get_active_clients() as $client) {
			$state = strtolower((string)($client['status'] ?? ''));
			$out[] = array('type' => 'openvpn', 'name' => (string)($client['name'] ?? ''), 'description' => (string)($client['name'] ?? ''),
			    'status' => ($state === 'up') ? 'up' : ((strpos($state, 'reconnect') !== false || strpos($state, 'connect') !== false) ? 'connecting' : 'down'),
			    'peer' => (string)($client['remote_host'] ?? ''), 'since' => null, 'handshake' => null, 'rx' => 0, 'tx' => 0,
			    'vpnid' => (int)($client['vpnid'] ?? 0));
		}
	}

	if (function_exists('ipsec_enabled') && ipsec_enabled() && function_exists('ipsec_list_sa')) {
		$cmap = function_exists('ipsec_map_config_by_id') ? ipsec_map_config_by_id() : array();
		foreach ((array)ipsec_list_sa() as $ikesa) {
			$conid = (string)($ikesa['con-id'] ?? '');
			$ikeid = function_exists('ipsec_id_by_conid') ? ipsec_id_by_conid($conid)[0] : $conid;
			$rx = $tx = 0;
			foreach ((array)($ikesa['child-sas'] ?? array()) as $child) {
				$rx += (int)($child['bytes-in'] ?? 0);
				$tx += (int)($child['bytes-out'] ?? 0);
			}
			$state = strtoupper((string)($ikesa['state'] ?? ''));
			$descr = (string)($cmap[$ikeid]['p1']['descr'] ?? '');
			$out[] = array('type' => 'ipsec', 'name' => $conid, 'description' => ($descr !== '') ? $descr : $conid,
			    'status' => ($state === 'ESTABLISHED') ? 'up' : (($state === 'CONNECTING') ? 'connecting' : 'down'),
			    'peer' => (string)($ikesa['remote-host'] ?? ''), 'since' => isset($ikesa['established']) ? (int)$ikesa['established'] : null,
			    'handshake' => null, 'rx' => $rx, 'tx' => $tx);
		}
	}

	$wg = is_executable('/usr/local/bin/wg') ? '/usr/local/bin/wg' : (is_executable('/usr/bin/wg') ? '/usr/bin/wg' : '');
	if ($wg !== '') {
		$names = array();
		foreach ((array)config_get_path('installedpackages/wireguard/peers/item', array()) as $peer) {
			if (!empty($peer['publickey'])) {
				$names[$peer['publickey']] = (string)($peer['descr'] ?? '');
			}
		}
		foreach (status_metrics_parse_wg_dump((string)shell_exec(escapeshellarg($wg) . ' show all dump 2>/dev/null')) as $p) {
			$ago = ($p['handshake'] > 0) ? max(0, $now - $p['handshake']) : null;
			$descr = $names[$p['public_key']] ?? '';
			$out[] = array('type' => 'wireguard', 'name' => ($descr !== '') ? $descr : substr($p['public_key'], 0, 8) . '…',
			    'description' => $descr, 'interface' => $p['interface'], 'status' => (($ago !== null) && ($ago <= STATUS_METRICS_WG_UP_SECONDS)) ? 'up' : 'down',
			    'peer' => $p['endpoint'], 'since' => null, 'handshake' => $ago, 'rx' => $p['rx'], 'tx' => $p['tx']);
		}
	}
	return $out;
}

/* Traffic history in bits/s from the interface's RRD, or null when there is none. */
function status_metrics_traffic_history($if, $range) {
	$ranges = status_metrics_ranges();
	if (!isset($ranges[$range]) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string)$if)) {
		return null;
	}
	$file = g_get('vardb_path') . "/rrd/{$if}-traffic.rrd";
	if (!is_file($file)) {
		return null;
	}
	list($back, $step) = $ranges[$range];
	$f = escapeshellarg($file);
	$cmd = '/usr/local/bin/rrdtool xport --showtime --start ' . escapeshellarg("-{$back}") . ' --end now --step ' . (int)$step .
	    " DEF:ip={$f}:inpass:AVERAGE DEF:ip6={$f}:inpass6:AVERAGE DEF:op={$f}:outpass:AVERAGE DEF:op6={$f}:outpass6:AVERAGE" .
	    ' CDEF:in=ip,ip6,ADDNAN,8,* CDEF:out=op,op6,ADDNAN,8,* XPORT:in:in_bps XPORT:out:out_bps 2>/dev/null';
	$data = status_metrics_parse_xport((string)shell_exec($cmd));
	if (!is_array($data)) {
		return null;
	}
	return array('interface' => $if, 'range' => $range, 'step' => $data['step'], 't' => $data['t'],
	    'in_bps' => $data['in_bps'] ?? array(), 'out_bps' => $data['out_bps'] ?? array());
}
