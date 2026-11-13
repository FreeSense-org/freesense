/*
 * firewall-log.js — dashboard widget "Firewall log"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Live tail: GET /api/v1/logs/firewall?format=webui, then ?after=<last id>.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;
	var ACT = { pass: ['fa-circle-check', 'success', 'Pass'], block: ['fa-ban', 'danger', 'Block'], reject: ['fa-circle-xmark', 'warning', 'Reject'], match: ['fa-circle-half-stroke', 'info', 'Match'] };

	D.define('firewall-log', {
		mount: function (w) {
			w.state.rows = [];
			w.state.last = null;
			var $t = $('<div class="table-responsive"><table class="table table-sm fs-log-mini mb-0"><thead><tr></tr></thead><tbody></tbody></table></div>');
			['Action', 'Time', 'Interface', 'Source', 'Destination', 'Protocol'].forEach(function (h) { $t.find('tr').append(el('th', '', T(h)).attr('scope', 'col')); });
			w.$body.append($t);
		},
		load: function (w) {
			var n = Math.max(3, Math.min(50, +w.settings.rows || 10));
			var q = { format: 'webui', limit: n };
			if (w.state.last) q.after = w.state.last;
			if (w.settings.action) q.action = w.settings.action;
			if (w.settings.interface) q.iface = w.settings.interface;
			return FS.api.get('/logs/firewall', q).then(function (r) {
				var fresh = r.data || [], meta = r.meta || {};
				if (meta.reset) w.state.rows = [];
				if (meta.last_id) w.state.last = meta.last_id;
				if (q.after) fresh = fresh.slice().reverse();
				var isNew = {};
				if (q.after) fresh.forEach(function (e) { isNew[e.id] = 1; });
				w.state.rows = fresh.concat(w.state.rows).slice(0, n);
				var $b = w.$body.find('tbody').empty();
				if (!w.state.rows.length) {
					$b.append(el('tr', '').append(el('td', 'text-center fs-muted', T('No log entries yet.')).attr('colspan', 6)));
					return;
				}
				w.state.rows.forEach(function (e) {
					var a = ACT[e.action] || ['fa-circle', 'secondary', e.action];
					var $r = el('tr', isNew[e.id] ? 'is-new' : '');
					$r.append(el('td', '').append($('<i class="fa-solid" aria-hidden="true"></i>').addClass(a[0] + ' text-' + a[1]), el('span', 'visually-hidden', T(a[2]))));
					$r.append(el('td', 'fs-mono', String(e.time || '').slice(11, 19)), el('td', '', e.iface_descr || e.iface));
					$r.append(el('td', 'fs-mono', e.src + (e.srcport ? ':' + e.srcport : '')), el('td', 'fs-mono', e.dst + (e.dstport ? ':' + e.dstport : '')), el('td', '', e.proto));
					$b.append($r);
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
