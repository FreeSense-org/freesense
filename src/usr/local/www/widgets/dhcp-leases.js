/*
 * dhcp-leases.js — dashboard widget "DHCP leases"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/status/dhcp-leases.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;

	function draw(w) {
		var s = (w.$body.find('input[type=search]').val() || '').toLowerCase();
		var all = w.state.data || [], on = all.filter(function (l) { return l.reachable; }).length;
		w.$body.find('.fs-lease-count').empty().append(el('b', '', T('%s online', on)), ' ', el('span', 'fs-muted', T('of %s', all.length)));
		var rows = all.filter(function (l) { return !s || ((l.hostname || '') + ' ' + l.ip + ' ' + l.mac).toLowerCase().indexOf(s) >= 0; })
			.sort(function (a, b) { return b.reachable - a.reachable; }).slice(0, w.size === 'md' ? 8 : 16);
		var $l = w.$body.find('.fs-lease-list').empty();
		if (!rows.length) {
			$l.append(el('li', '').append(D.empty('fa-address-book', T('No lease matches.'))));
			return;
		}
		rows.forEach(function (l) {
			var $li = el('li', 'fs-lease');
			$li.append(D.dot(l.reachable ? 'ok' : 'off', l.reachable ? T('online') : T('offline')));
			$li.append(el('div', '').append(el('strong', '', l.hostname || T('(no name)')), el('small', 'fs-mono', l.mac)));
			$li.append(el('div', 'fs-mono text-end', l.ip));
			$li.append(l.static ? D.tag(T('static')) : el('span', ''));
			$l.append($li);
		});
	}

	D.define('dhcp-leases', {
		mount: function (w) {
			var $search = $('<input type="search" class="form-control form-control-sm">').attr({ placeholder: T('Search name, IP or MAC'), 'aria-label': T('Search name, IP or MAC') });
			w.$body.append(el('div', 'fs-lease-top').append(el('div', 'fs-lease-count'), $search), el('ul', 'fs-wlist fs-lease-list'));
			w.$body.on('input', 'input[type=search]', FS.debounce(function () { draw(w); }, 120));
		},
		load: function (w) {
			return FS.api.get('/status/dhcp-leases').then(function (r) {
				w.state.data = r.data.leases || [];
				draw(w);
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
