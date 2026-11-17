/*
 * interface-detail.js — dashboard widget "Interface detail"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * One interface in full, the same view as the Interfaces drawer
 * (FSDashboard.nicDetail in interfaces.js): GET /api/v1/status/nics/{name}
 * every 30 s, live traffic every 2 s.
 */
(function ($, FS, D) {
	'use strict';

	function stopLive(w) {
		FS.live.remove('nicd:' + w.id);
		if (w.state.chart) { w.state.chart.destroy(); w.state.chart = null; }
	}

	D.define('interface-detail', {
		mount: function (w) {
			w.state.box = D.el('div', 'fs-nicd');
			w.$body.append(w.state.box);
		},
		load: function (w) {
			var name = w.settings.interface || 'wan';
			return FS.api.get('/status/nics/' + encodeURIComponent(name)).then(function (r) {
				var n = r.data;
				if (!w.settings.title) w.$root.find('.fs-widget-title').text(n.description);
				/* Refresh the facts but keep the running chart (its history and live task). */
				var $chart = w.state.chart ? w.state.box.find('.fs-nicd-chart').detach() : null;
				D.nicDetail(w.state.box, n);
				if ($chart) {
					w.state.box.find('.fs-nicd-chart').replaceWith($chart);
				} else {
					D.nicLive(w.state.box, n.name, function (c) { w.state.chart = c; }, 'nicd:' + w.id);
				}
			}, function (e) {
				if (e.status === 404) {
					stopLive(w);
					w.state.box.empty().append(D.empty('fa-ethernet', FS.t('The interface is not assigned.')));
					return;
				}
				throw e;
			});
		},
		destroy: function (w) { stopLive(w); }
	});
})(jQuery, window.FS, window.FSDashboard);
