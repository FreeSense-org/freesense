/*
 * services.js — dashboard widget "Services"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Every service with start, restart and stop, like Status > Services.
 * Data: GET /api/v1/status/services; actions POST /services/{name}/{action}.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;
	var DONE = { start: 'started', restart: 'restarted', stop: 'stopped' };

	function button(s, act, icon, label) {
		var $b = el('button', 'fs-icon-btn' + (act === 'start' ? ' fs-icon-btn--go' : '')).attr({
			type: 'button', 'data-svc-act': act, title: T(label), 'aria-label': T(label) + ' ' + s.name
		}).append($('<i class="fa-solid" aria-hidden="true"></i>').addClass(icon));
		$b.data('svc', s);
		return $b;
	}

	D.define('services', {
		mount: function (w) {
			w.$body.append(el('div', 'fs-svc-sum'), el('ul', 'fs-wlist fs-svc-list'));
			w.$body.on('click', '[data-svc-act]', function () {
				var $b = $(this).prop('disabled', true).addClass('is-busy');
				var s = $b.data('svc'), act = $b.attr('data-svc-act');
				var body = { confirm: true };
				['mode', 'vpnid', 'zone'].forEach(function (k) { if (s[k] != null) body[k] = s[k]; });
				FS.api.post('/services/' + encodeURIComponent(s.name) + '/' + act, body).then(function () {
					FS.toast(T('%s: %s', s.description || s.name, T(DONE[act])), 'success');
					w.refresh();
				}, function (e) {
					FS.toast(e.message, 'danger');
					$b.prop('disabled', false).removeClass('is-busy');
				});
			});
		},
		load: function (w) {
			return FS.api.get('/status/services').then(function (r) {
				var all = r.data, up = all.filter(function (s) { return s.running; }).length;
				var $sum = w.$body.find('.fs-svc-sum').empty().append(el('span', '', T('%s of %s running', up, all.length)));
				if (up < all.length) $sum.append(D.tag(T('%s stopped', all.length - up), 'bad'));
				var rows = all.filter(function (s) { return !w.settings.problems || !s.running; });
				var $l = w.$body.find('.fs-svc-list').empty();
				if (!rows.length) {
					$l.append(el('li', '').append(D.empty('fa-circle-check', T('All services are running.'))));
					return;
				}
				rows.forEach(function (s) {
					var $li = el('li', 'fs-svc');
					$li.append(D.dot(s.running ? 'ok' : 'down', s.running ? T('running') : T('stopped')));
					$li.append(el('div', '').append(el('strong', '', s.description || s.name), el('small', '', s.name + (s.zone ? ' · ' + s.zone : ''))));
					var $a = el('div', 'fs-svc-act');
					if (s.running) {
						$a.append(button(s, 'restart', 'fa-rotate', 'Restart'), button(s, 'stop', 'fa-stop', 'Stop'));
					} else {
						$a.append(button(s, 'start', 'fa-play', 'Start'));
					}
					$li.append($a);
					$l.append($li);
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
