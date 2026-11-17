/*
 * ntp.js — dashboard widget "NTP"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/status/ntp.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;

	D.define('ntp', {
		mount: function (w) { w.$body.append(el('div', 'fs-ntp')); },
		load: function (w) {
			return FS.api.get('/status/ntp').then(function (r) {
				var d = r.data, $b = w.$body.find('.fs-ntp').empty();
				$b.append(el('div', 'fs-stat').append(el('span', 'fs-stat-val', new Date(d.time).toLocaleTimeString([], { hour12: false }))));
				if (!d.enabled) {
					$b.append(D.tag(T('The NTP server is off')));
					return;
				}
				$b.append(d.synced ? D.tag(T('Synchronised'), 'ok', 'fa-circle-check') : D.tag(T('Not synchronised'), 'warn'));
				if (d.source) {
					$b.append(el('div', 'small mt-2').append(el('strong', 'fs-mono', d.source.remote),
						el('small', 'd-block fs-muted', T('Stratum %s', d.source.stratum) + ' · ' + T('offset %s ms', d.source.offset))));
				}
				if (w.size !== 'sm' && d.peers.length) {
					var $l = el('ul', 'fs-wlist mt-2');
					d.peers.forEach(function (p) {
						var st = (p.tally === '*' || p.tally === 'o') ? 'ok' : (p.tally === '+' ? 'off' : 'down');
						$l.append(el('li', 'fs-svc').append(D.dot(st), el('span', 'fs-mono', p.remote), el('small', 'fs-mono fs-muted', p.delay == null ? '' : p.delay + ' ms')));
					});
					$b.append($l);
				}
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
