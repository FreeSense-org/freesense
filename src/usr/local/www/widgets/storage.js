/*
 * storage.js — dashboard widget "Storage"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * File system and swap usage. ZFS datasets share their pool's space, so by
 * default only distinct sizes are shown (every file system with the setting).
 * Data: GET /api/v1/status/system (disks, swap).
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;

	function item(label, sub, used, total) {
		var p = total > 0 ? used / total * 100 : 0;
		var $li = el('li', '');
		$li.append(el('div', 'fs-res-row').append(el('span', 'fs-mono', label).append(sub ? el('small', 'fs-muted ms-1', sub) : null), el('b', 'fs-num', F.pct(p, 1))));
		$li.append(FS.chart.meter(p, { label: label }));
		$li.append(el('small', 'fs-muted', T('%s of %s', F.bytes(used), F.bytes(total)) + ' · ' + T('%s free', F.bytes(total - used))));
		return $li;
	}

	D.define('storage', {
		mount: function (w) { w.$body.append(el('ul', 'fs-wlist fs-meters')); },
		load: function (w) {
			return FS.api.get('/status/system').then(function (r) {
				var $l = w.$body.find('.fs-meters').empty();
				var seen = {};
				(r.data.disks || []).forEach(function (d) {
					/* ZFS datasets of one pool report the pool's free space: show the root and anything with its own size */
					var key = d.type === 'zfs' ? d.device.split('/')[0] + ':' + (d.total_bytes - d.used_bytes) : d.device;
					if (!w.settings.all && d.type === 'zfs' && d.mount !== '/' && d.used_bytes < d.total_bytes * 0.05) return;
					if (!w.settings.all && seen[key]) return;
					seen[key] = true;
					$l.append(item(d.mount, d.type, d.used_bytes, d.total_bytes));
				});
				if (r.data.swap && r.data.swap.total_bytes > 0) $l.append(item(T('Swap'), '', r.data.swap.used_bytes, r.data.swap.total_bytes));
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
