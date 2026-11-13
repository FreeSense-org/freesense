/*
 * top-talkers.js — dashboard widget "Top talkers"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/status/top-talkers?interface=.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;

	D.define('top-talkers', {
		mount: function (w) { w.$body.append(el('ol', 'fs-wlist fs-talkers')); },
		load: function (w) {
			return FS.api.get('/status/top-talkers', { interface: w.settings.interface || 'lan' }).then(function (r) {
				var rows = (r.data || []).slice(0, +w.settings.count || 8), $l = w.$body.find('.fs-talkers').empty();
				if (!rows.length) {
					$l.append(el('li', '').append(D.empty('fa-ranking-star', T('No traffic right now.'))));
					return;
				}
				var max = Math.max.apply(null, rows.map(function (h) { return h.in_bps + h.out_bps; })) || 1;
				rows.forEach(function (h) {
					var $li = el('li', '');
					$li.append(el('div', 'fs-talker-row').append(
						el('span', '').append(el('strong', '', h.host || h.ip), h.host ? el('small', 'd-inline fs-mono fs-muted ms-1', h.ip) : null),
						el('b', 'fs-mono', F.bps(h.in_bps + h.out_bps))));
					$li.append(el('div', 'fs-talker-bar').attr('aria-hidden', 'true').append(
						el('span', 'in').css('width', (h.in_bps / max * 100).toFixed(1) + '%'),
						el('span', 'out').css('width', (h.out_bps / max * 100).toFixed(1) + '%')));
					$l.append($li);
				});
				w.foot(T('Sampled over %s', (r.meta && r.meta.window) || '1s') + ' · ' + T('In') + ' / ' + T('Out'));
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
