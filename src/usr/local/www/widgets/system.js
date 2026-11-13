/*
 * system.js — dashboard widget "System"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Data: GET /api/v1/system/info (each tick) and /system/version (every
 * 15 minutes; needs the Update Center privilege, otherwise left out).
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var VERSION_EVERY = 900000;

	function kv($dl, label, value, small) {
		if (value == null || value === '' || (Array.isArray(value) && !value.length)) return;
		var $dd = el('dd', '', Array.isArray(value) ? value.join(', ') : String(value));
		if (small) $dd.append(el('small', '', small));
		$dl.append(el('div', '').append(el('dt', '', label), $dd));
	}

	function updateTag(w) {
		var v = w.state.version;
		if (v === undefined) return D.tag(T('Checking for updates'), '', 'fa-rotate');
		if (v === null) return null;
		if (v.error) return D.tag(T('Could not check for updates'), 'warn');
		if (v.update_available) {
			return $('<a class="text-decoration-none" href="/pkg_mgr_install.php?id=firmware"></a>').attr('title', T('Update Center'))
				.append(D.tag(T('Update available') + (v.latest ? ' ' + v.latest : ''), 'info', 'fa-cloud-arrow-down'));
		}
		return D.tag(T('Up to date'), 'ok', 'fa-circle-check');
	}

	function render(w) {
		var d = w.state.info;
		if (!d) return;
		var full = w.settings.detail === 'full' || w.size === 'lg';
		var $hero = el('div', 'fs-sys-hero');
		$hero.append(el('span', 'fs-sys-name', d.hostname + (d.domain ? '.' + d.domain : '')));
		var $ver = el('span', 'fs-sys-ver').append(el('span', '', d.product + ' ' + d.version + ' · ' + d.arch));
		var tag = updateTag(w);
		if (tag) $ver.append(tag);
		$hero.append($ver);

		var $dl = el('dl', 'fs-kv');
		kv($dl, T('Uptime'), F.uptime(d.uptime_seconds));
		kv($dl, T('Hardware'), d.hardware, d.serial ? T('Serial') + ' ' + d.serial : '');
		kv($dl, T('CPU'), d.cpu, d.cpu_packages || T('%s CPUs', d.cpu_count));
		var active = (d.crypto || []).filter(function (c) { return c.active; }).map(function (c) { return c.name; });
		kv($dl, T('Crypto'), active.length ? active.join(', ') : T('none active'), (d.crypto_instructions || []).join(', '));
		kv($dl, T('DNS servers'), d.dns_servers);
		if (d.config_revision && d.config_revision.time) {
			kv($dl, T('Config changed'), F.ago(new Date(d.config_revision.time * 1000).toISOString()), d.config_revision.description);
		}
		if (full) {
			kv($dl, T('Kernel'), d.kernel, d.build_time ? T('Built') + ' ' + F.datetime(d.build_time) : '');
			var bios = [d.bios.vendor, d.bios.version].filter(Boolean).join(' ');
			kv($dl, T('BIOS'), bios, [d.bios.date, d.boot_method].filter(Boolean).join(' · '));
			kv($dl, T('Microcode'), d.microcode);
			if (d.pti !== null) kv($dl, T('Kernel PTI'), d.pti ? T('Enabled') : T('Disabled'));
			kv($dl, T('MDS mitigation'), d.mds);
			kv($dl, T('Time zone'), d.timezone);
		}
		w.$body.empty().append($hero, $dl);
	}

	D.define('system', {
		mount: function (w) { w.state.verAt = 0; },
		load: function (w) {
			var jobs = [FS.api.get('/system/info').then(function (r) { w.state.info = r.data; })];
			if (Date.now() - w.state.verAt > VERSION_EVERY) {
				w.state.verAt = Date.now();
				jobs.push(FS.api.get('/system/version').then(function (r) {
					w.state.version = r.data;
				}, function (e) {
					/* no Update Center privilege, or the check is off: leave the tag out */
					w.state.version = (e.status === 403 || e.status === 404) ? null : { error: e.message };
				}));
			}
			return Promise.all(jobs).then(function () { render(w); });
		},
		resize: function (w) { render(w); }
	});
})(jQuery, window.FS, window.FSDashboard);
