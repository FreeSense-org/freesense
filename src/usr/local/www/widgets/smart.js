/*
 * smart.js — dashboard widget "Disk health"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/status/smart (cached five minutes on the firewall).
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;

	D.define('smart', {
		mount: function (w) { w.$body.append(el('ul', 'fs-wlist fs-smart-list')); },
		load: function (w) {
			return FS.api.get('/status/smart').then(function (r) {
				var $l = w.$body.find('.fs-smart-list').empty();
				if (!r.data.drives.length) {
					$l.append(el('li', '').append(D.empty('fa-heart-pulse', T('No drives report SMART data.'))));
					w.foot(null);
					return;
				}
				r.data.drives.forEach(function (d) {
					var good = (d.health === 'PASSED' || d.health === 'OK'), st = (d.health === '') ? 'off' : (good ? 'ok' : 'down');
					$l.append(el('li', 'fs-svc').append(D.dot(st, d.health || T('Unknown')),
						el('div', '').append(el('strong', 'fs-mono', d.device), el('small', 'fs-mono', d.ident)),
						D.tag(d.health === '' ? T('Unknown') : (good ? T('Passed') : T('Failed')), st === 'ok' ? 'ok' : (st === 'down' ? 'bad' : ''))));
				});
				w.foot(T('Checked %s', F.ago(r.data.checked)));
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
