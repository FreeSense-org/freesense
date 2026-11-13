/*
 * system.js — dashboard widget "System"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/system/info (each tick) and /system/version (every
 * 15 minutes; needs the Update Center privilege, otherwise left out).
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var VERSION_EVERY = 900000, INFO_EVERY = 60000;

	function kv($dl, label, value, small) {
		if (value == null || value === '' || (Array.isArray(value) && !value.length)) return;
		var $dd = el('dd', '', Array.isArray(value) ? value.join(', ') : String(value));
		if (small) $dd.append(el('small', '', small));
		$dl.append(el('div', '').append(el('dt', '', label), $dd));
	}

	function updateTag(w) {
		var v = w.state.version;
		if (v === undefined) return D.tag(T('Checking for updates'), '', 'fa-rotate');
		if (v === null) return null;
		if (v.error) return D.tag(T('Could not check for updates'), 'warn');
		if (v.update_available) {
			return $('<a class="text-decoration-none" href="/pkg_mgr_install.php?id=firmware"></a>').attr('title', T('Update Center'))
				.append(D.tag(T('Update available') + (v.latest ? ' ' + v.latest : ''), 'info', 'fa-cloud-arrow-down'));
		}
		return D.tag(T('Up to date'), 'ok', 'fa-circle-check');
	}

	function meterRow(label, pct, sub) {
		var $r = el('div', 'fs-sysm');
		$r.append(el('div', 'fs-res-row').append(el('span', 'fs-res-label', label), el('b', 'fs-num', F.pct(pct))));
		$r.append(FS.chart.meter(pct, { label: label }));
		if (sub) $r.append(el('small', 'fs-muted', sub));
		return $r;
	}

	/* CPU load like the 1.x System Information: usage, a bar per core, load average, frequency, then the other meters. */
	function load(w) {
		var s = w.state.stats;
		if (!s) return null;
		var c = s.cpu, $l = el('div', 'fs-sysload');
		var $cpu = el('div', 'fs-syscpu');
		$cpu.append(el('div', 'fs-res-row').append(el('span', 'fs-res-label', T('CPU')),
			el('b', 'fs-num', F.pct(c.usage, 1))));
		$cpu.append(FS.chart.meter(c.usage, { label: T('CPU') }));
		if ((c.cores || []).length > 1) {
			var $cores = el('div', 'fs-syscores').attr({ role: 'img', 'aria-label': T('Per core') + ': ' + c.cores.map(function (v) { return Math.round(v) + '%'; }).join(', ') });
			c.cores.forEach(function (v, i) {
				$cores.append(el('span', 'fs-syscore' + (v >= 90 ? ' is-crit' : (v >= 75 ? ' is-warn' : ''))).attr('title', T('Core %s', i) + ': ' + Math.round(v) + '%')
					.append(el('i', '').css('height', Math.max(4, Math.min(100, v)) + '%')));
			});
			$cpu.append($cores);
		}
		var facts = [];
		if (c.load) facts.push(T('Load') + ' ' + c.load.join(' · '));
		if (c.freq_mhz) facts.push(c.freq_mhz + (c.freq_max_mhz ? ' / ' + c.freq_max_mhz : '') + ' MHz');
		if (c.user != null) facts.push(T('user %s, system %s, interrupt %s', F.pct(c.user, 1), F.pct(c.system, 1), F.pct(c.interrupt, 1)));
		$cpu.append(el('small', 'fs-muted d-block', facts.join(' · ')));
		$l.append($cpu);
		var pct = function (u, t) { return (t > 0 && u != null) ? u / t * 100 : null; };
		$l.append(meterRow(T('Memory'), pct(s.memory.used_bytes, s.memory.total_bytes), T('%s of %s', F.bytes(s.memory.used_bytes), F.bytes(s.memory.total_bytes))));
		if (s.swap && s.swap.total_bytes > 0) $l.append(meterRow(T('Swap'), pct(s.swap.used_bytes, s.swap.total_bytes), T('%s of %s', F.bytes(s.swap.used_bytes), F.bytes(s.swap.total_bytes))));
		$l.append(meterRow(T('States'), pct(s.states.current, s.states.max), T('%s of %s', F.num(s.states.current), F.num(s.states.max))));
		$l.append(meterRow(T('MBUFs'), pct(s.mbuf.used, s.mbuf.max), T('%s of %s', F.num(s.mbuf.used), F.num(s.mbuf.max))));
		return $l;
	}

	function render(w) {
		var d = w.state.info;
		if (!d) return;
		var full = w.settings.detail === 'full' || w.size === 'lg';
		var $hero = el('div', 'fs-sys-hero');
		$hero.append(el('span', 'fs-sys-name', d.hostname + (d.domain ? '.' + d.domain : '')));
		var $ver = el('span', 'fs-sys-ver').append(el('span', '', d.product + ' ' + d.version + ' · ' + d.arch));
		var tag = updateTag(w);
		if (tag) $ver.append(tag);
		$hero.append($ver);

		var $dl = el('dl', 'fs-kv');
		kv($dl, T('Uptime'), F.uptime(d.uptime_seconds));
		kv($dl, T('Hardware'), d.hardware, d.serial ? T('Serial') + ' ' + d.serial : '');
		kv($dl, T('CPU'), d.cpu, d.cpu_packages || T('%s CPUs', d.cpu_count));
		var active = (d.crypto || []).filter(function (c) { return c.active; }).map(function (c) { return c.name; });
		kv($dl, T('Crypto'), active.length ? active.join(', ') : T('none active'), (d.crypto_instructions || []).join(', '));
		kv($dl, T('DNS servers'), d.dns_servers);
		if (d.config_revision && d.config_revision.time) {
			kv($dl, T('Config changed'), F.ago(new Date(d.config_revision.time * 1000).toISOString()), d.config_revision.description);
		}
		if (full) {
			kv($dl, T('Kernel'), d.kernel, d.build_time ? T('Built') + ' ' + F.datetime(d.build_time) : '');
			var bios = [d.bios.vendor, d.bios.version].filter(Boolean).join(' ');
			kv($dl, T('BIOS'), bios, [d.bios.date, d.boot_method].filter(Boolean).join(' · '));
			kv($dl, T('Microcode'), d.microcode);
			if (d.pti !== null) kv($dl, T('Kernel PTI'), d.pti ? T('Enabled') : T('Disabled'));
			kv($dl, T('MDS mitigation'), d.mds);
			kv($dl, T('Time zone'), d.timezone);
		}
		w.$body.empty().append($hero);
		var $load = load(w);
		if ($load) w.$body.append($load);
		w.$body.append($dl);
	}

	D.define('system', {
		mount: function (w) { w.state.verAt = 0; w.state.infoAt = 0; },
		load: function (w) {
			var jobs = [FS.api.get('/status/system').then(function (r) { w.state.stats = r.data; })];
			if (Date.now() - w.state.infoAt > INFO_EVERY) {
				w.state.infoAt = Date.now();
				jobs.push(FS.api.get('/system/info').then(function (r) { w.state.info = r.data; }));
			}
			if (Date.now() - w.state.verAt > VERSION_EVERY) {
				w.state.verAt = Date.now();
				jobs.push(FS.api.get('/system/version').then(function (r) {
					w.state.version = r.data;
				}, function (e) {
					/* no Update Center privilege, or the check is off: leave the tag out */
					w.state.version = (e.status === 403 || e.status === 404) ? null : { error: e.message };
				}));
			}
			return Promise.all(jobs).then(function () { render(w); });
		},
		resize: function (w) { render(w); }
	});
})(jQuery, window.FS, window.FSDashboard);
