/*
 * traffic.js — dashboard widget "Traffic"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Live throughput of one interface on a real time axis: the chart opens
 * with the RRD history of the chosen span (GET /api/v1/status/traffic/history)
 * and continues with a sample every 2 seconds (GET /status/traffic). In above
 * the axis, out below it.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var RANGE = { 120: '10m', 600: '10m', 3600: '1h', 86400: '24h' };

	function name(w) {
		var i = (D.config.interfaces || []).filter(function (x) { return x.name === w.settings.interface; })[0];
		return i ? i.description : w.settings.interface;
	}

	D.define('traffic', {
		mount: function (w) {
			var pps = (w.settings.unit === 'pps');
			var span = +w.settings.window || 600;
			var $head = el('div', 'fs-traffic-head');
			var $seg = el('div', 'fs-seg').attr({ role: 'group', 'aria-label': T('Interface') });
			(D.config.interfaces || []).slice(0, w.size === 'md' ? 3 : 6).forEach(function (i) {
				$seg.append(el('button', '', i.description).attr({ type: 'button', 'data-if': i.name, 'aria-pressed': i.name === w.settings.interface ? 'true' : 'false' }));
			});
			var $now = el('div', 'fs-traffic-now');
			$now.append(el('span', 'fs-legend').attr('data-k', 'in').append(el('i', '').css('background', 'var(--fs-series-1)'), document.createTextNode(T('In') + ' '), el('b', '', F.dash)));
			$now.append(el('span', 'fs-legend').attr('data-k', 'out').append(el('i', '').css('background', 'var(--fs-series-2)'), document.createTextNode(T('Out') + ' '), el('b', '', F.dash)));
			$head.append($seg, $now);
			var $chart = el('div', 'fs-traffic-chart');
			w.$body.append($head, $chart);
			w.state.chart = FS.chart.timeseries($chart[0], {
				window: span, format: pps ? F.pps : F.bps, label: T('Traffic on %s', name(w)),
				series: [{ key: 'in', label: T('In'), color: '--fs-series-1' }, { key: 'out', label: T('Out'), color: '--fs-series-2', mirror: true }]
			});
			$seg.on('click', 'button', function () {
				w.settings.interface = $(this).attr('data-if');
				w.save();
				D.remountLater(w);
			});
		},
		load: function (w) {
			var iface = w.settings.interface || 'wan', pps = (w.settings.unit === 'pps');
			var span = +w.settings.window || 600;
			var seed = w.state.seeded ? Promise.resolve() : FS.api.get('/status/traffic/history', { if: iface, range: RANGE[span] || '10m' }).then(function (r) {
				var d = r.data;
				w.state.chart.seed(d.t, pps ? { in: d.in_pps || [], out: d.out_pps || [] } : { in: d.in_bps, out: d.out_bps });
				w.foot(T('History from the RRD, then live every 2 seconds'));
			}, function () {
				w.foot(T('Live only: there is no history for this interface yet'));
			}).then(function () { w.state.seeded = true; });
			return seed.then(function () { return FS.api.get('/status/traffic', { if: iface }); }).then(function (r) {
				var v = (r.data.interfaces || {})[iface] || {};
				/* back from a hidden tab or a pause: reseed from the RRD so the hole fills */
				if (w.state.lastT && (r.data.t - w.state.lastT) > 20) w.state.seeded = false;
				w.state.lastT = r.data.t;
				var vin = pps ? v.in_pps : v.in_bps, vout = pps ? v.out_pps : v.out_bps;
				w.state.chart.push(r.data.t, { in: vin, out: vout });
				var fmt = pps ? F.pps : F.bps;
				w.$body.find('.fs-legend[data-k=in] b').text(fmt(vin));
				w.$body.find('.fs-legend[data-k=out] b').text(fmt(vout));
			});
		},
		destroy: function (w) { if (w.state.chart) w.state.chart.destroy(); }
	});
})(jQuery, window.FS, window.FSDashboard);
