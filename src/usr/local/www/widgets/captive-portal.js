/*
 * captive-portal.js — dashboard widget "Captive portal"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/status/captive-portal; disconnect:
 * DELETE /status/captive-portal/{zone}/sessions/{session}.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;

	D.define('captive-portal', {
		mount: function (w) {
			w.$body.append(el('ul', 'fs-wlist fs-cp-list'));
			w.$body.on('click', '[data-cp]', function () {
				var $b = $(this).prop('disabled', true), who = $b.attr('data-who');
				FS.api.del('/status/captive-portal/' + encodeURIComponent($b.attr('data-zone')) + '/sessions/' + encodeURIComponent($b.attr('data-cp'))).then(function () {
					FS.toast(T('%s disconnected', who), 'success');
					w.refresh();
				}, function (e) {
					FS.toast(e.message, 'danger');
					$b.prop('disabled', false);
				});
			});
		},
		load: function (w) {
			return FS.api.get('/status/captive-portal').then(function (r) {
				var $l = w.$body.find('.fs-cp-list').empty();
				if (!r.data.length) {
					$l.append(el('li', '').append(D.empty('fa-wifi', T('Nobody is signed in.'))));
					return;
				}
				r.data.forEach(function (s) {
					var who = s.user || s.ip;
					var meta = s.ip + ' · ' + s.mac + ' · ' + s.zone_name + (s.last_activity ? ' · ' + T('last active %s', F.ago(s.last_activity)) : '');
					$l.append(el('li', 'fs-svc').append(D.dot('ok'), el('div', '').append(el('strong', '', who), el('small', 'fs-mono', meta)),
						el('button', 'fs-icon-btn').attr({ type: 'button', 'data-cp': s.session, 'data-zone': s.zone, 'data-who': who, title: T('Disconnect'), 'aria-label': T('Disconnect %s', who) })
							.append('<i class="fa-solid fa-user-slash" aria-hidden="true"></i>')));
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
