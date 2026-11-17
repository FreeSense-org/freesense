/*
 * resources.js — dashboard widget "Resources"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * CPU, memory, swap, mbuf and state table use, each with a sparkline that
 * starts with the last hour from the RRDs (GET /api/v1/status/system/history)
 * and continues with the live values (GET /status/system).
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var KEEP = 90;

	function pct(used, total) { return (total > 0 && used != null) ? used / total * 100 : null; }

	function items(d) {
		var out = [
			{ key: 'cpu', label: T('CPU'), pct: d.cpu.usage, sub: T('%s cores', d.cpu.count) },
			{ key: 'mem', label: T('Memory'), pct: pct(d.memory.used_bytes, d.memory.total_bytes), sub: T('%s of %s', F.bytes(d.memory.used_bytes), F.bytes(d.memory.total_bytes)) }
		];
		if (d.swap && d.swap.total_bytes > 0) {
			out.push({ key: 'swap', label: T('Swap'), pct: pct(d.swap.used_bytes, d.swap.total_bytes), sub: T('%s of %s', F.bytes(d.swap.used_bytes), F.bytes(d.swap.total_bytes)) });
		}
		out.push({ key: 'states', label: T('States'), pct: pct(d.states.current, d.states.max), sub: T('%s of %s', F.compact(d.states.current), F.compact(d.states.max)) });
		out.push({ key: 'mbuf', label: T('MBUFs'), pct: pct(d.mbuf.used, d.mbuf.max), sub: T('%s of %s', F.compact(d.mbuf.used), F.compact(d.mbuf.max)) });
		return out;
	}

	function render(w, d) {
		var list = items(d), bars = (w.settings.look === 'bars' || w.size === 'sm');
		var $res = w.$body.find('.fs-res').attr('data-look', bars ? 'bars' : 'rings').empty();
		list.forEach(function (i) {
			if (i.pct != null) D.push(w.state.h[i.key] = w.state.h[i.key] || [], i.pct, KEEP);
			var $i = el('div', 'fs-res-item');
			if (bars) {
				$i.append(el('div', 'fs-res-row').append(el('span', 'fs-res-label', i.label), el('b', 'fs-num', F.pct(i.pct))));
				$i.append(FS.chart.meter(i.pct, { label: i.label }));
				$i.append(el('small', '', i.sub));
			} else {
				$i.append(FS.chart.ring(i.pct, { label: i.label }));
				$i.append(el('div', 'fs-res-label', i.label), el('small', '', i.sub));
				$i.append(el('div', 'fs-res-spark').html(FS.chart.spark(w.state.h[i.key] || [], { w: 90, h: 26, max: 100, min: 0, color: 'var(--fs-series-1)' })));
			}
			$res.append($i);
		});
		var $foot = w.$body.find('.fs-res-foot').empty();
		if (d.cpu.load) $foot.append(el('span', '', T('Load') + ' ').append(el('b', '', d.cpu.load.join(' · '))));
		if (d.cpu.freq_mhz) $foot.append(el('span', '', T('Frequency') + ' ').append(el('b', '', d.cpu.freq_mhz + (d.cpu.freq_max_mhz ? ' / ' + d.cpu.freq_max_mhz : '') + ' MHz')));
		$foot.append(el('span', '', T('Uptime') + ' ').append(el('b', '', F.uptime(d.uptime_seconds))));
	}

	D.define('resources', {
		mount: function (w) {
			w.state.h = {};
			w.$body.append(el('div', 'fs-res'), el('div', 'fs-res-foot'));
			FS.api.get('/status/system/history', { range: '1h' }).then(function (r) {
				var h = r.data;
				['cpu', 'mem', 'mbuf'].forEach(function (k) {
					if (h[k]) w.state.h[k] = h[k].slice(-KEEP / 2).concat(w.state.h[k] || []);
				});
				if (h.states && w.state.lastMax) w.state.h.states = h.states.slice(-KEEP / 2).map(function (v) { return v == null ? null : v / w.state.lastMax * 100; });
			}, function () { /* no RRD history yet */ });
		},
		load: function (w) {
			return FS.api.get('/status/system').then(function (r) {
				w.state.lastMax = r.data.states.max;
				render(w, r.data);
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
