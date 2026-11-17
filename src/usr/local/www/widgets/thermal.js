/*
 * thermal.js — dashboard widget "Thermal sensors"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Every temperature sensor with warning and critical levels.
 * Data: GET /api/v1/status/system (temperatures).
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;

	D.define('thermal', {
		mount: function (w) { w.$body.append(el('ul', 'fs-wlist fs-meters')); },
		load: function (w) {
			return FS.api.get('/status/system').then(function (r) {
				var temps = r.data.temperatures || [], warn = +w.settings.warn || 70, crit = +w.settings.crit || 85;
				var $l = w.$body.find('.fs-meters').empty();
				if (!temps.length) {
					$l.append(el('li', '').append(D.empty('fa-temperature-empty', T('This system reports no temperature sensors.'))));
					return;
				}
				temps.forEach(function (t) {
					var c = t.celsius, shown = w.settings.fahrenheit ? (c * 9 / 5 + 32).toFixed(1) + ' °F' : c.toFixed(1) + ' °C';
					var $li = el('li', '');
					$li.append(el('div', 'fs-res-row').append(el('span', '', t.name), el('b', 'fs-mono', shown)));
					/* the bar runs to 20 degrees above the critical level */
					$li.append(FS.chart.meter(c / (crit + 20) * 100, { warn: warn / (crit + 20) * 100, crit: crit / (crit + 20) * 100, label: t.name }));
					$l.append($li);
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
