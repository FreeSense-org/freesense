/*
 * gateways.js — dashboard widget "Gateways"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Every gateway with its state, latency, jitter and loss (dpinger), and a
 * latency sparkline that starts with the last hour of its quality RRD.
 * Data: GET /api/v1/status/gateways, /status/gateways/history once.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var KEEP = 80;

	function state(g) {
		if (g.monitor_disable || g.status === 'none') return ['off', T('Monitoring off')];
		if (g.status === 'online' && (!g.substatus || g.substatus === 'none')) return ['ok', T('Online')];
		if (g.status === 'online') return ['warn', g.substatus];
		return ['down', T('Down')];
	}

	function num(v, unit) { return (v == null) ? F.dash : (Math.round(v * 10) / 10) + unit; }

	D.define('gateways', {
		mount: function (w) {
			w.state.h = {};
			w.$body.append(el('ul', 'fs-wlist fs-gw-list'));
			FS.api.get('/status/gateways/history', { range: '1h' }).then(function (r) {
				Object.keys(r.data.gateways || {}).forEach(function (k) {
					w.state.h[k] = (r.data.gateways[k].delay || []).slice(-KEEP / 2).concat(w.state.h[k] || []);
				});
			}, function () { /* no RRD yet */ });
		},
		load: function (w) {
			return FS.api.get('/status/gateways').then(function (r) {
				var $l = w.$body.find('.fs-gw-list').empty();
				if (!r.data.length) {
					$l.append(el('li', '').append(D.empty('fa-route', T('No gateways are configured.'))));
					return;
				}
				r.data.sort(function (a, b) { return (b.default - a.default) || a.name.localeCompare(b.name); });
				r.data.forEach(function (g) {
					var st = state(g);
					D.push(w.state.h[g.name] = w.state.h[g.name] || [], g.delay_ms, KEEP);
					var $li = el('li', 'fs-gw');
					$li.append(D.dot(st[0], st[1]));
					var $n = el('div', 'fs-gw-name').append(el('strong', '', g.name));
					if (g.default) $n.find('strong').append(' ', D.tag(T('default')));
					$n.append(el('small', '', (g.monitorip || '') + (st[0] === 'warn' ? ' · ' + st[1] : '')));
					$li.append($n);
					$li.append(el('div', 'fs-gw-spark').html(FS.chart.spark(w.state.h[g.name], { w: 84, h: 24, color: st[0] === 'ok' ? 'var(--fs-pass)' : 'var(--fs-warn)' })));
					var $s = el('div', 'fs-gw-stats').append(el('span', '', num(g.delay_ms, ' ms')).attr('title', T('Latency')));
					$s.append(el('small', 'd-block fs-muted', '±' + num(g.stddev_ms, '') + ' · ' + num(g.loss_pct, '%')).attr('title', T('Loss')));
					$li.append($s);
					$l.append($li);
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
