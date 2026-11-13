/*
 * carp.js — dashboard widget "High availability"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/status/carp.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;
	var ST = { MASTER: ['ok', 'Master', 'ok'], BACKUP: ['off', 'Backup', ''], INIT: ['warn', 'Init', 'warn'], DISABLED: ['down', 'Disabled', 'bad'] };

	D.define('carp', {
		mount: function (w) { w.$body.append(el('div', 'fs-nic-tags mb-2'), el('ul', 'fs-wlist fs-carp-list')); },
		load: function (w) {
			return FS.api.get('/status/carp').then(function (r) {
				var d = r.data, $s = w.$body.find('.fs-nic-tags').empty(), $l = w.$body.find('.fs-carp-list').empty();
				if (!d.enabled) $s.append(D.tag(T('CARP is disabled'), 'bad'));
				if (d.maintenance) $s.append(D.tag(T('Maintenance mode'), 'warn'));
				if (d.demotion) $s.append(D.tag(T('Demotion %s', d.demotion), 'warn'));
				if (!(d.vips || []).length) {
					$l.append(el('li', '').append(D.empty('fa-clone', T('No CARP virtual IPs are configured.'))));
					return;
				}
				d.vips.forEach(function (v) {
					var st = ST[v.state] || ['warn', 'Unknown', 'warn'];
					$l.append(el('li', 'fs-svc').append(D.dot(st[0], T(st[1])),
						el('div', '').append(el('strong', 'fs-mono', v.address), el('small', '', v.interface + ' · VHID ' + v.vhid + (v.description ? ' · ' + v.description : ''))),
						D.tag(T(st[1]), st[2])));
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
