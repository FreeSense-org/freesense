<?php
/*
 * sysstat.inc
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
 * System statistics for the WebUI and the REST API (status/system).
 *
 * The sampler freesense-sysstatd reads the kernel once a second (CPU ticks
 * of every core, load, frequency) and the slower figures every few seconds
 * (memory, swap, mbufs, the state table, file systems), and writes one
 * snapshot to SYSSTAT_SNAPSHOT. A request only reads that file: no command
 * runs while a page or the API waits, CPU use is measured over a real
 * second whoever asks, and the last two minutes of CPU history come along.
 *
 * The sampler starts on demand (the first read starts it) and stops after
 * SYSSTAT_IDLE seconds without a reader, so an unwatched firewall pays
 * nothing. Owns no config.xml section.
 */

require_once('config.inc');
require_once('util.inc');

define('SYSSTAT_SNAPSHOT', '/var/run/sysstat.json');
define('SYSSTAT_READMARK', '/var/run/sysstat.read');
define('SYSSTAT_PIDFILE', '/var/run/freesense-sysstatd.pid');
define('SYSSTAT_DAEMON', '/usr/local/sbin/freesense-sysstatd');
define('SYSSTAT_FRESH', 5);
define('SYSSTAT_IDLE', 600);
define('SYSSTAT_HISTORY', 120);

/* ------------------------------------------------------------ parsers */

/* pure: kern.cp_times ("u n s i id u n s i id ...") → one [user, nice, system, interrupt, idle] list per core */
function sysstat_parse_cptimes(string $text): array {
	$v = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
	if (empty($v) || (count($v) % 5) !== 0) {
		return [];
	}
	return array_map(function ($c) { return array_map('intval', $c); }, array_chunk($v, 5));
}

/*
 * pure: CPU use between two kern.cp_times samples: total and per core, each
 * {busy, user, system, interrupt} in percent; null when the samples do not
 * fit together (core count changed, counters went back).
 */
function sysstat_cpu_use(array $prev, array $now): ?array {
	if (empty($prev) || (count($prev) !== count($now))) {
		return null;
	}
	$sum = [0, 0, 0, 0, 0];
	$cores = [];
	foreach ($now as $i => $c) {
		$d = [];
		for ($k = 0; $k < 5; $k++) {
			$d[$k] = $c[$k] - $prev[$i][$k];
			if ($d[$k] < 0) {
				return null;
			}
			$sum[$k] += $d[$k];
		}
		$cores[] = sysstat_cpu_split($d);
	}
	return ['total' => sysstat_cpu_split($sum), 'cores' => $cores];
}

/* pure: tick deltas [user, nice, system, interrupt, idle] → percentages */
function sysstat_cpu_split(array $d): array {
	$all = array_sum($d);
	if ($all <= 0) {
		return ['busy' => 0.0, 'user' => 0.0, 'system' => 0.0, 'interrupt' => 0.0];
	}
	$p = function ($n) use ($all) { return round(100 * $n / $all, 1); };
	return ['busy' => $p($all - $d[4]), 'user' => $p($d[0] + $d[1]), 'system' => $p($d[2]), 'interrupt' => $p($d[3])];
}

/* ------------------------------------------------------------ sampler */

/* One fast sample: CPU ticks, load, frequency. */
function sysstat_fast(): array {
	$load = sys_getloadavg();
	$freq = (int)get_single_sysctl('dev.cpu.0.freq');
	return [
		'ticks' => sysstat_parse_cptimes((string)get_single_sysctl('kern.cp_times')),
		'load' => is_array($load) ? array_map(function ($v) { return round($v, 2); }, $load) : null,
		'freq_mhz' => ($freq > 0) ? $freq : null,
	];
}

/* The slower figures (memory, swap, mbufs, states, temperatures, disks): the status_metrics parsers over one run each. */
function sysstat_slow(): array {
	require_once('status_metrics.inc');
	$page = (int)get_single_sysctl('hw.pagesize') ?: 4096;
	$pages = (int)get_single_sysctl('vm.stats.vm.v_page_count');
	$avail = (int)get_single_sysctl('vm.stats.vm.v_inactive_count') + (int)get_single_sysctl('vm.stats.vm.v_laundry_count') +
	    (int)get_single_sysctl('vm.stats.vm.v_free_count');
	$states = status_metrics_states();
	return [
		'memory' => ['total_bytes' => $pages * $page, 'used_bytes' => max(0, ($pages - $avail) * $page)],
		'swap' => status_metrics_parse_swapinfo((string)shell_exec('/usr/sbin/swapinfo -k 2>/dev/null')),
		'mbuf' => status_metrics_parse_mbuf((string)shell_exec('/usr/bin/vmstat -z --libxo=json 2>/dev/null')),
		'states' => ['current' => $states['current'], 'max' => $states['max']],
		'temperatures' => status_metrics_sensors(),
	];
}

function sysstat_disks(): array {
	require_once('status_metrics.inc');
	return status_metrics_parse_df((string)shell_exec('/bin/df -kTt ufs,zfs,tmpfs,cd9660 2>/dev/null'));
}

/* Write the snapshot atomically (mode 0644: readable by the WebUI's PHP). */
function sysstat_write(array $snap): void {
	$tmp = SYSSTAT_SNAPSHOT . '.' . getmypid();
	if (@file_put_contents($tmp, json_encode($snap)) !== false) {
		@chmod($tmp, 0644);
		@rename($tmp, SYSSTAT_SNAPSHOT);
	}
}

/* The sampler loop (freesense-sysstatd). Returns when nobody read the snapshot for SYSSTAT_IDLE seconds. */
function sysstat_run(): void {
	$prev = sysstat_fast()['ticks'];
	$slow = sysstat_slow();
	$disks = sysstat_disks();
	$history = [];
	$tick = 0;
	@touch(SYSSTAT_READMARK);
	while (true) {
		sleep(1);
		$tick++;
		$fast = sysstat_fast();
		$use = sysstat_cpu_use($prev, $fast['ticks']);
		$prev = $fast['ticks'];
		if (($tick % 5) === 0) {
			$slow = sysstat_slow();
		}
		if (($tick % 60) === 0) {
			$disks = sysstat_disks();
		}
		$history[] = ($use === null) ? null : $use['total']['busy'];
		$history = array_slice($history, -SYSSTAT_HISTORY);
		sysstat_write(['t' => time(), 'cpu' => $use, 'cpu_history' => $history, 'load' => $fast['load'],
		    'freq_mhz' => $fast['freq_mhz'], 'disks' => $disks] + $slow);
		if ((time() - (int)@filemtime(SYSSTAT_READMARK)) > SYSSTAT_IDLE) {
			@unlink(SYSSTAT_SNAPSHOT);
			return;
		}
	}
}

/* True while a sampler process runs. */
function sysstat_running(): bool {
	$pid = (int)@file_get_contents(SYSSTAT_PIDFILE);
	return ($pid > 0) && posix_kill($pid, 0);
}

/*
 * The current snapshot, starting the sampler when it is not running. The
 * first call after a start waits up to two seconds for the first sample.
 * Null when there is still no snapshot.
 */
function sysstat_read(): ?array {
	@touch(SYSSTAT_READMARK);
	$fresh = function () {
		$snap = @json_decode((string)@file_get_contents(SYSSTAT_SNAPSHOT), true);
		return (is_array($snap) && ((time() - (int)($snap['t'] ?? 0)) <= SYSSTAT_FRESH)) ? $snap : null;
	};
	$snap = $fresh();
	if ($snap !== null) {
		return $snap;
	}
	if (!sysstat_running()) {
		mwexec_bg(SYSSTAT_DAEMON);
	}
	for ($i = 0; $i < 10; $i++) {
		usleep(250000);
		if (($snap = $fresh()) !== null) {
			return $snap;
		}
	}
	return null;
}
