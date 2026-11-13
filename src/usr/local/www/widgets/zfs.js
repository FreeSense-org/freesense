/*
 * zfs.js — dashboard widget "ZFS"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Pool health, capacity, fragmentation, device errors, the running scrub or
 * resilver, and the boot environment. Data: GET /api/v1/status/zfs.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;

	D.define('zfs', {
		mount: function (w) { w.$body.append(el('div', 'fs-zfs-pools')); },
		load: function (w) {
			return FS.api.get('/status/zfs').then(function (r) {
				var d = r.data, $p = w.$body.find('.fs-zfs-pools').empty();
				if (!d.zfs_active || !(d.pools || []).length) {
					$p.append(D.empty('fa-database', T('This system does not use ZFS.')));
					return;
				}
				d.pools.forEach(function (pool) {
					var health = String(pool.health || '').toUpperCase();
					var errors = (pool.devices || []).reduce(function (n, x) { return n + (+x.read_errors || 0) + (+x.write_errors || 0) + (+x.checksum_errors || 0); }, 0);
					var kind = health === 'ONLINE' ? 'ok' : (['DEGRADED', 'OFFLINE', 'REMOVED'].indexOf(health) >= 0 ? 'warn' : 'bad');
					var $s = el('section', 'fs-zfs');
					$s.append(el('div', 'fs-zfs-head').append(el('strong', '', pool.name), D.tag(health, kind), el('span', 'fs-zfs-free', T('%s free', F.bytes(pool.free)))));
					$s.append(FS.chart.meter(+pool.capacity_percent, { label: pool.name }));
					var $f = el('div', 'fs-zfs-facts');
					$f.append(el('span', '').append(el('b', '', pool.capacity_percent + '%'), ' ' + T('used')));
					$f.append(el('span', '').append(el('b', '', pool.fragmentation + '%'), ' ' + T('fragmented')));
					$f.append(el('span', errors ? 'text-danger' : '').append(el('b', '', String(errors)), ' ' + T('errors')));
					$s.append($f);
					if (pool.scan) $s.append(el('small', 'd-block fs-muted mt-1', pool.scan));
					if (w.size !== 'sm' && (pool.devices || []).length) {
						var $t = $('<table class="table table-sm mt-2 mb-0"><thead><tr><th></th><th></th><th class="text-end"></th></tr></thead><tbody></tbody></table>');
						$t.find('th').eq(0).text(T('Device')).end().eq(1).text(T('State')).end().eq(2).text(T('errors'));
						pool.devices.forEach(function (x) {
							var e = (+x.read_errors || 0) + (+x.write_errors || 0) + (+x.checksum_errors || 0);
							$t.find('tbody').append(el('tr', '').append(el('td', 'fs-mono', x.name), el('td', '', x.state), el('td', 'text-end fs-mono' + (e ? ' text-danger' : ''), String(e))));
						});
						$s.append($t);
					}
					$p.append($s);
				});
				if (d.boot_environment) {
					var be = typeof d.boot_environment === 'string' ? d.boot_environment : (d.boot_environment.active || d.boot_environment.name || '');
					if (be) w.foot(T('Boot environment') + ': ' + be); else w.foot(null);
				}
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
