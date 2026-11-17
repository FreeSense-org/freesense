/*
 * packages.js — dashboard widget "Packages"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/packages/installed.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;

	D.define('packages', {
		mount: function (w) { w.$body.append(el('div', 'fs-svc-sum'), el('ul', 'fs-wlist fs-pkg-list')); },
		load: function (w) {
			return FS.api.get('/packages/installed').then(function (r) {
				var list = r.data || [], $l = w.$body.find('.fs-pkg-list').empty();
				var upd = list.filter(function (p) { return p.update_available; });
				w.$body.find('.fs-svc-sum').empty().append(el('span', '', T('%s installed', list.length)), upd.length ? D.tag(T('%s updates', upd.length), 'info') : null);
				if (!list.length) {
					$l.append(el('li', '').append(D.empty('fa-box-open', T('No packages are installed.'))));
					return;
				}
				list.sort(function (a, b) { return (!!b.update_available - !!a.update_available) || String(a.display_name).localeCompare(String(b.display_name)); });
				list.forEach(function (p) {
					var $li = el('li', 'fs-svc').append(D.dot(p.update_available ? 'warn' : 'ok'), el('div', '').append(el('strong', '', p.display_name), el('small', 'fs-mono', p.version || '')));
					if (p.update_available) {
						$li.append($('<a class="text-decoration-none"></a>').attr('href', '/pkg_mgr_installed.php').append(D.tag(T('Update to %s', p.latest_version || ''), 'info', 'fa-arrow-up')));
					} else {
						$li.append(el('span', ''));
					}
					$l.append($li);
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
