/*
 * vpn.js — dashboard widget "VPN tunnels"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * WireGuard, OpenVPN and IPsec together. Data: GET /api/v1/status/vpn.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var ICON = { wireguard: 'fa-bolt', openvpn: 'fa-lock', ipsec: 'fa-key' };

	D.define('vpn', {
		mount: function (w) { w.$body.append(el('ul', 'fs-wlist fs-vpn-list')); },
		load: function (w) {
			return FS.api.get('/status/vpn').then(function (r) {
				var $l = w.$body.find('.fs-vpn-list').empty();
				if (!r.data.length) {
					$l.append(el('li', '').append(D.empty('fa-shield-halved', T('No VPN tunnels are configured.'))));
					return;
				}
				r.data.forEach(function (v) {
					var st = { up: 'ok', connecting: 'warn', idle: 'off' }[v.status] || 'down';
					var detail = (v.handshake != null) ? T('handshake %s', F.ago(v.handshake)) : ((v.since != null) ? T('up %s', F.uptime(v.since)) : T(v.status));
					var $li = el('li', 'fs-vpn');
					$li.append(D.dot(st, T(v.status)), el('span', 'fs-vpn-type').attr('title', v.type).append($('<i class="fa-solid" aria-hidden="true"></i>').addClass(ICON[v.type] || 'fa-lock')));
					$li.append(el('div', '').append(el('strong', '', v.name), el('small', '', v.type + ' · ' + detail + (v.peer ? ' · ' + v.peer : ''))));
					$li.append(el('div', 'fs-mono text-end fs-vpn-bytes').append(el('span', '', '↓ ' + F.bytes(v.rx)), el('span', '', '↑ ' + F.bytes(v.tx))));
					$l.append($li);
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
