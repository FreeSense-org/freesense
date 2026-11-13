/*
 * notices.js — dashboard widget "Notices"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * System notices with their age, a link when they have one, and dismiss.
 * Data: GET /api/v1/notices; DELETE /notices/{id} (or "all").
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var ICON = { crit: 'fa-circle-exclamation', warn: 'fa-triangle-exclamation', info: 'fa-circle-info' };

	D.define('notices', {
		mount: function (w) {
			w.$body.append(el('ul', 'fs-wlist fs-notice-list'));
			w.$body.on('click', '[data-dismiss]', function () {
				var id = $(this).attr('data-dismiss');
				$(this).prop('disabled', true);
				FS.api.del('/notices/' + encodeURIComponent(id)).then(function () { w.refresh(); }, function (e) { FS.toast(e.message, 'danger'); });
			});
		},
		load: function (w) {
			return FS.api.get('/notices').then(function (r) {
				var list = r.data || [];
				w.$root.find('.fs-widget-title').attr('data-count', list.length || null);
				var $l = w.$body.find('.fs-notice-list').empty();
				if (!list.length) {
					$l.append(el('li', '').append(D.empty('fa-circle-check', T('Nothing needs attention.'))));
					w.foot(null);
					return;
				}
				list.forEach(function (n) {
					var $li = el('li', 'fs-notice fs-notice--' + n.level);
					$li.append($('<i class="fa-solid" aria-hidden="true"></i>').addClass(ICON[n.level] || ICON.info));
					var $txt = el('div', '');
					$txt.append(el('strong', '', n.source || n.category || T('Notices')));
					$txt.append(el('p', '', n.text));
					var $meta = el('small', '', F.ago(n.time));
					if (n.url && /^\/[A-Za-z0-9_.\/?=&%-]*$/.test(n.url)) $meta.append(' · ', $('<a></a>').attr('href', n.url).text(T('Open')));
					$txt.append($meta);
					$li.append($txt);
					$li.append(el('button', 'fs-icon-btn').attr({ type: 'button', 'data-dismiss': n.id, title: T('Dismiss'), 'aria-label': T('Dismiss') + ': ' + (n.source || '') })
						.append('<i class="fa-solid fa-xmark" aria-hidden="true"></i>'));
					$l.append($li);
				});
				if (list.length > 1) {
					w.foot(el('button', 'btn btn-sm btn-link p-0', T('Dismiss all')).attr({ type: 'button', 'data-dismiss': 'all' })[0]);
				} else {
					w.foot(null);
				}
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
