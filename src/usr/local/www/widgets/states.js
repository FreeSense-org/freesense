/*
 * states.js — dashboard widget "State table"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/status/states.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;

	D.define('states', {
		mount: function (w) {
			w.state.h = [];
			w.$body.append(el('div', 'fs-stat'), el('div', 'fs-states-spark'), el('dl', 'fs-kv fs-kv-inline'));
		},
		load: function (w) {
			return FS.api.get('/status/states').then(function (r) {
				var d = r.data;
				D.push(w.state.h, d.current, 90);
				w.$body.find('.fs-stat').empty().append(el('span', 'fs-stat-val', F.num(d.current)),
					el('span', 'fs-stat-sub', T('%s of %s', F.pct(d.current / d.max * 100, 1), F.compact(d.max))));
				w.$body.find('.fs-states-spark').html(FS.chart.spark(w.state.h, { w: 300, h: 36, color: 'var(--fs-series-3)', min: null }));
				var $kv = w.$body.find('.fs-kv').empty();
				[['Inserts/s', d.insert_rate], ['Removals/s', d.removal_rate], ['Searches/s', d.search_rate]].forEach(function (k) {
					$kv.append(el('div', '').append(el('dt', '', T(k[0])), el('dd', '', k[1] == null ? F.dash : F.compact(k[1]))));
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
