/*
 * wol.js — dashboard widget "Wake on LAN"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/services/wol/entries; wake: POST .../entries/{id}/wake.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;

	D.define('wol', {
		mount: function (w) {
			w.$body.append(el('ul', 'fs-wlist fs-wol-list'));
			w.$body.on('click', '[data-wake]', function () {
				var $b = $(this).prop('disabled', true).addClass('is-busy'), name = $b.attr('data-name');
				FS.api.post('/services/wol/entries/' + encodeURIComponent($b.attr('data-wake')) + '/wake', {}).then(function () {
					FS.toast(T('Magic packet sent to %s', name), 'success');
				}, function (e) {
					FS.toast(e.message, 'danger');
				}).finally(function () { $b.prop('disabled', false).removeClass('is-busy'); });
			});
		},
		load: function (w) {
			return FS.api.get('/services/wol/entries').then(function (r) {
				var $l = w.$body.find('.fs-wol-list').empty();
				if (!r.data.length) {
					$l.append(el('li', '').append(D.empty('fa-power-off', T('No devices are stored.'))));
					return;
				}
				r.data.forEach(function (d) {
					var name = d.descr || d.mac;
					$l.append(el('li', 'fs-svc').append($('<i class="fa-solid fa-desktop fs-muted" aria-hidden="true"></i>'),
						el('div', '').append(el('strong', '', name), el('small', 'fs-mono', d.mac + ' · ' + d.interface)),
						el('button', 'fs-icon-btn fs-icon-btn--go').attr({ type: 'button', 'data-wake': d.id, 'data-name': name, title: T('Wake'), 'aria-label': T('Wake %s', name) })
							.append('<i class="fa-solid fa-power-off" aria-hidden="true"></i>')));
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
