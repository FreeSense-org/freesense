/*
 * dyndns.js — dashboard widget "Dynamic DNS"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/services/dyndns/clients?display=true (from the cache;
 * never asks a check IP service).
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;

	D.define('dyndns', {
		mount: function (w) { w.$body.append(el('ul', 'fs-wlist fs-dd-list')); },
		load: function (w) {
			return FS.api.get('/services/dyndns/clients', { display: 'true' }).then(function (r) {
				var $l = w.$body.find('.fs-dd-list').empty();
				if (!r.data.length) {
					$l.append(el('li', '').append(D.empty('fa-globe', T('No Dynamic DNS clients are configured.'))));
					return;
				}
				r.data.forEach(function (c) {
					var d = c.display || {}, ok = d.enabled && /ok|up|match|updated/i.test(String(d.status || ''));
					$l.append(el('li', 'fs-svc').append(D.dot(!d.enabled ? 'off' : (ok ? 'ok' : 'warn'), d.status),
						el('div', '').append(el('strong', '', d.hostname || d.description), el('small', '', [d.service, d.interface, d.enabled ? d.status : T('Disabled')].filter(Boolean).join(' · '))),
						el('span', 'fs-mono small', d.cached_ip || F.dash)));
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
