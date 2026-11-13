/*
 * interfaces.js — dashboard widget "Interfaces"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * One row per assigned interface: link state, speed and duplex, addressing,
 * VLAN/LAGG, addresses, MAC and vendor, gateway, MTU and live rates with a
 * sparkline. A row opens the interface drawer (FSDashboard.nicDrawer).
 * Data: GET /api/v1/status/nics every 30 s, /status/traffic every tick.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, F = FS.fmt, el = D.el;
	var KIND_ICON = { ethernet: 'fa-ethernet', vlan: 'fa-tags', lagg: 'fa-link', wireless: 'fa-wifi', ppp: 'fa-plug', tunnel: 'fa-shield-halved', bridge: 'fa-bridge', loopback: 'fa-rotate', other: 'fa-network-wired' };
	var NICS_EVERY = 30000;

	/* ------------------------------------------------ shared formatting */

	var nic = D.nic = {
		linkStatus: function (n) { return n.link.state === 'up' ? 'ok' : (n.link.state === 'admin_down' || !n.enabled ? 'off' : 'down'); },
		linkLabel: function (n) { return !n.enabled ? T('Disabled') : (n.link.state === 'up' ? T('Up') : (n.link.state === 'admin_down' ? T('Disabled') : T('No carrier'))); },
		speed: function (n) {
			if (n.link.state !== 'up' || !n.link.speed_mbps) return '';
			var s = F.speed(n.link.speed_mbps);
			if (n.link.duplex === 'half') s += ' · ' + T('Half duplex');
			return s;
		},
		addressing: function (n) {
			var map = { dhcp: T('DHCP'), pppoe: T('PPPoE'), pptp: 'PPTP', l2tp: 'L2TP', ppp: 'PPP', 'static': T('Static') };
			var v6 = { dhcp6: 'DHCPv6', slaac: T('SLAAC'), track6: T('Track'), '6rd': '6RD', '6to4': '6to4', 'static': T('Static') };
			var out = [];
			if (n.addressing.ipv4 !== 'none') out.push(map[n.addressing.ipv4] || n.addressing.ipv4);
			if (n.addressing.ipv6 !== 'none') out.push((v6[n.addressing.ipv6] || n.addressing.ipv6) + (n.addressing.ipv6 === 'static' ? ' v6' : ''));
			return out.join(' · ');
		},
		v4: function (n) { return n.ipv4.map(function (a) { return a.address + (a.prefix != null ? '/' + a.prefix : ''); }); },
		v6: function (n, scope) {
			return n.ipv6.filter(function (a) { return scope ? a.scope === scope : a.scope !== 'link'; })
				.map(function (a) { return a.address + (a.prefix != null ? '/' + a.prefix : ''); });
		},
		problems: function (n) { var c = n.counters; return (c.in_errors || 0) + (c.out_errors || 0) + (c.in_drops || 0) + (c.out_drops || 0); }
	};

	function tags(n) {
		var $t = el('div', 'fs-nic-tags');
		var sp = nic.speed(n);
		if (sp) $t.append(D.tag(sp, 'strong'));
		var ad = nic.addressing(n);
		if (ad) $t.append(D.tag(ad));
		if (n.vlan) $t.append(D.tag(T('VLAN %s on %s', n.vlan.tag, n.vlan.parent), '', 'fa-tags'));
		if (n.lagg) {
			var act = n.lagg.ports.filter(function (p) { return p.active; }).length;
			$t.append(D.tag(T('LAGG %s', n.lagg.proto) + ' ' + act + '/' + n.lagg.ports.length, act < n.lagg.ports.length ? 'warn' : ''));
		}
		return $t;
	}

	function row(w, n) {
		var $r = el('li', 'fs-nic').attr({ 'data-if': n.name, 'data-link': n.enabled ? n.link.state : 'admin_down', tabindex: 0, role: 'button' });
		$r.attr('aria-label', n.description + ', ' + nic.linkLabel(n) + (nic.speed(n) ? ', ' + nic.speed(n) : ''));
		$r.append(D.dot(nic.linkStatus(n), nic.linkLabel(n)));

		var $name = el('div', 'fs-nic-name');
		$name.append(el('strong', '', n.description));
		$name.append(el('small', '').append($('<i class="fa-solid fa-fw" aria-hidden="true"></i>').addClass(KIND_ICON[n.kind] || KIND_ICON.other))
			.append(document.createTextNode(' ' + n.device + (n.link.state !== 'up' && n.enabled ? ' · ' + nic.linkLabel(n) : ''))));
		$name.append(tags(n));
		$r.append($name);

		var $addr = el('div', 'fs-nic-addr');
		var v4 = nic.v4(n), v6 = nic.v6(n);
		$addr.append(el('div', '', v4[0] || (n.link.state === 'up' ? T('No IPv4 address') : F.dash)));
		if (v4.length > 1) $addr.append(el('small', '', v4.slice(1).join(', ')));
		if (v6.length) $addr.append(el('small', '', v6[0]));
		$r.append($addr);

		var $meta = el('div', 'fs-nic-meta');
		if (n.mac) $meta.append(el('span', 'fs-mono', n.mac + (n.vendor ? ' · ' + n.vendor : '')).attr('title', T('MAC')));
		if (n.gateway.ipv4) $meta.append(el('span', '', T('Gateway') + ' ' + n.gateway.ipv4));
		if (n.mtu) $meta.append(el('span', '', T('MTU') + ' ' + n.mtu + (n.hardware && n.hardware.desc ? ' · ' + n.hardware.desc : '')));
		$r.append($meta);

		var $rate = el('div', 'fs-nic-rate');
		var $bps = el('div', 'fs-nic-bps');
		$bps.append(el('span', 'fs-nic-in').append('<i class="fa-solid fa-arrow-down" aria-hidden="true"></i>').append(el('b', '', F.dash).attr('title', T('In'))));
		$bps.append(el('span', 'fs-nic-out').append('<i class="fa-solid fa-arrow-up" aria-hidden="true"></i>').append(el('b', '', F.dash).attr('title', T('Out'))));
		var prob = w.state.growth[n.name] || 0;
		if (prob > 0) $bps.append(D.tag('+' + prob + ' ' + T('errors'), 'bad'));
		$rate.append($bps, el('div', 'fs-nic-spark'));
		$r.append($rate);
		return $r;
	}

	function render(w) {
		var chosen = w.settings.interfaces || [];
		var rows = (w.state.nics || []).filter(function (n) {
			if (chosen.length && chosen.indexOf(n.name) < 0) return false;
			return w.settings.show_down || n.link.state === 'up';
		});
		var $list = w.$body.find('.fs-nic-list').empty();
		if (!rows.length) {
			$list.append(el('li', '').append(D.empty('fa-network-wired', T('No interfaces to show.'))));
			return;
		}
		rows.forEach(function (n) { $list.append(row(w, n)); });
		rates(w);
	}

	function rates(w) {
		var tr = w.state.traffic || {};
		w.$body.find('.fs-nic').each(function () {
			var name = $(this).attr('data-if'), t = tr[name];
			if (!t) return;
			$(this).find('.fs-nic-in b').text(F.bps(t.in_bps));
			$(this).find('.fs-nic-out b').text(F.bps(t.out_bps));
			var h = w.state.hist[name] || [];
			$(this).find('.fs-nic-spark').html(FS.chart.spark(h, { w: 72, h: 28, color: 'var(--fs-series-1)' }));
		});
	}

	D.define('interfaces', {
		mount: function (w) {
			w.state.hist = {};
			w.state.growth = {};
			w.state.prev = {};
			w.state.nicsAt = 0;
			w.$body.append(el('ul', 'fs-wlist fs-nic-list').attr('role', 'list'));
			w.$body.on('click keydown', '.fs-nic', function (e) {
				if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
				if ($(e.target).closest('.fs-copy').length) return;
				e.preventDefault();
				D.nicDrawer($(this).attr('data-if'));
			});
		},
		load: function (w) {
			var jobs = [];
			if (Date.now() - w.state.nicsAt > NICS_EVERY || !w.state.nics) {
				jobs.push(FS.api.get('/status/nics').then(function (r) {
					w.state.nicsAt = Date.now();
					(r.data || []).forEach(function (n) {
						var p = nic.problems(n), before = w.state.prev[n.name];
						w.state.growth[n.name] = (before != null && p > before) ? p - before : 0;
						w.state.prev[n.name] = p;
					});
					w.state.nics = r.data || [];
					return true;
				}));
			}
			jobs.push(FS.api.get('/status/traffic').then(function (r) {
				w.state.traffic = (r.data && r.data.interfaces) || {};
				Object.keys(w.state.traffic).forEach(function (k) {
					var t = w.state.traffic[k];
					D.push(w.state.hist[k] = w.state.hist[k] || [], (t.in_bps == null) ? null : t.in_bps + (t.out_bps || 0), 40);
				});
				return false;
			}));
			return Promise.all(jobs).then(function (res) {
				if (res[0] === true) render(w); else rates(w);
			});
		}
	});

	/* ------------------------------------------------ interface drawer */

	function kv($dl, label, value, mono) {
		if (value == null || value === '' || (Array.isArray(value) && !value.length)) return;
		var $dd = el('dd', mono ? 'fs-mono' : '');
		(Array.isArray(value) ? value : [value]).forEach(function (v, i) {
			if (i) $dd.append('<br>');
			$dd.append(document.createTextNode(String(v)));
		});
		$dl.append(el('div', '').append(el('dt', '', label), $dd));
	}

	function section(title) { return el('h3', 'fs-nicd-section', title); }

	/* The detail of one interface, into $box (the drawer or the Interface detail widget). */
	D.nicDetail = function ($box, n, opts) {
		opts = opts || {};
		$box.empty();
		var $head = el('div', 'fs-nicd-head');
		$head.append(D.dot(nic.linkStatus(n), nic.linkLabel(n)), el('strong', '', n.description), D.tag(n.device, '', KIND_ICON[n.kind] || KIND_ICON.other));
		$head.append(D.tag(nic.linkLabel(n), n.link.state === 'up' ? 'ok' : (n.enabled ? 'bad' : '')));
		$box.append($head);

		var $tiles = el('div', 'fs-nicd-tiles');
		function tile(label, value) { $tiles.append(el('div', 'fs-nicd-tile').append(el('span', '', label), el('b', '', value || F.dash))); }
		tile(T('Speed'), n.link.speed_mbps && n.link.state === 'up' ? F.speed(n.link.speed_mbps) : '');
		tile(T('Link'), n.link.duplex ? (n.link.duplex === 'full' ? T('Full duplex') : T('Half duplex')) : nic.linkLabel(n));
		tile(T('In'), '');
		tile(T('Out'), '');
		$tiles.children().eq(2).addClass('fs-nicd-in');
		$tiles.children().eq(3).addClass('fs-nicd-out');
		$box.append($tiles);
		if (opts.chart !== false) $box.append(section(T('Traffic, last 10 minutes')), el('div', 'fs-nicd-chart'));

		var $a = el('dl', 'fs-kv');
		$box.append(section(T('Details')), $a);
		kv($a, T('IPv4'), nic.v4(n), true);
		kv($a, T('IPv6'), nic.v6(n), true);
		kv($a, T('Link-local'), nic.v6(n, 'link'), true);
		kv($a, T('Gateway'), [n.gateway.ipv4, n.gateway.ipv6].filter(Boolean), true);
		kv($a, 'DHCP / PPP', nic.addressing(n));
		if (n.mac) {
			var $dd = el('dd', 'fs-mono').append(document.createTextNode(n.mac + ' ')).append(D.copy(n.mac, T('MAC')));
			if (n.vendor) $dd.append(el('small', '', n.vendor));
			$a.append(el('div', '').append(el('dt', '', T('MAC')), $dd));
		}
		kv($a, T('MTU'), n.mtu);
		kv($a, T('Media'), n.link.media, true);
		if (n.link.autoneg) kv($a, T('Link'), T('Autonegotiated'));
		if (n.link.flowcontrol) kv($a, T('Flow control'), (n.link.flowcontrol.rx ? 'RX ' : '') + (n.link.flowcontrol.tx ? 'TX' : ''));
		if (n.vlan) kv($a, 'VLAN', T('VLAN %s on %s', n.vlan.tag, n.vlan.parent) + (n.vlan.pcp ? ' · PCP ' + n.vlan.pcp : ''));
		if (n.lagg) {
			kv($a, T('Ports'), n.lagg.ports.map(function (p) { return p.name + (p.active ? '' : ' (' + T('none') + ')'); }));
			kv($a, 'LAGG', n.lagg.proto + (n.lagg.hash ? ' · ' + n.lagg.hash : ''));
		}
		if (n.hardware) {
			kv($a, T('Adapter'), n.hardware.desc);
			kv($a, T('Driver'), n.hardware.driver);
			if (n.hardware.pci && n.hardware.pci.vendor) kv($a, T('PCI'), n.hardware.pci.vendor + ':' + n.hardware.pci.device + (n.hardware.pci.subvendor ? ' (' + n.hardware.pci.subvendor + (n.hardware.pci.subdevice ? ':' + n.hardware.pci.subdevice : '') + ')' : ''), true);
			if (n.hardware.rx_queues) kv($a, T('Queues'), 'RX ' + n.hardware.rx_queues + ' · TX ' + n.hardware.tx_queues + (n.hardware.msix ? ' · MSI-X ' + n.hardware.msix : ''));
			kv($a, T('Location'), n.hardware.location, true);
		}

		if (n.sfp) {
			var $s = el('dl', 'fs-kv');
			$box.append(section(T('SFP module')), $s);
			kv($s, T('Module'), n.sfp.plugged);
			kv($s, T('Vendor'), n.sfp.vendor);
			if (n.sfp.temperature != null) kv($s, T('Temperature'), n.sfp.temperature.toFixed(1) + ' °C');
			if (n.sfp.voltage != null) kv($s, T('Voltage'), n.sfp.voltage.toFixed(2) + ' V');
			if (n.sfp.rx_power) kv($s, T('RX power'), n.sfp.rx_power.map(function (v) { return v == null ? F.dash : v.toFixed(2) + ' dBm'; }));
			if (n.sfp.tx_bias) kv($s, T('TX bias'), n.sfp.tx_bias.map(function (v) { return v.toFixed(2) + ' mA'; }));
		}
		if (n.wireless) {
			var $w = el('dl', 'fs-kv');
			$box.append(section(T('Wireless')), $w);
			kv($w, T('SSID'), n.wireless.ssid);
			kv($w, T('Channel'), n.wireless.channel);
			kv($w, 'BSSID', n.wireless.bssid, true);
			kv($w, T('Signal'), n.wireless.rssi);
		}
		if (n.ppp && (n.ppp.uptime || n.ppp.link)) {
			var $p = el('dl', 'fs-kv');
			$box.append(section(T('PPP')), $p);
			kv($p, T('Link'), n.ppp.link);
			kv($p, T('Uptime'), n.ppp.uptime);
		}

		var c = n.counters, $c = el('dl', 'fs-kv');
		$box.append(section(T('Counters')), $c);
		kv($c, T('Bytes in') + ' / ' + T('Bytes out'), F.bytes(c.in_bytes) + ' / ' + F.bytes(c.out_bytes));
		kv($c, T('Packets in') + ' / ' + T('Packets out'), F.num(c.in_packets) + ' / ' + F.num(c.out_packets));
		kv($c, T('Errors in') + ' / ' + T('Errors out'), F.num(c.in_errors) + ' / ' + F.num(c.out_errors));
		if (c.in_drops != null) kv($c, T('Drops in') + ' / ' + T('Drops out'), F.num(c.in_drops) + ' / ' + F.num(c.out_drops));
		kv($c, T('Collisions'), F.num(c.collisions));
		if (n.pf) {
			kv($c, T('Passed in') + ' / ' + T('Passed out'), F.bytes(n.pf.in_bytes_pass) + ' / ' + F.bytes(n.pf.out_bytes_pass));
			kv($c, T('Blocked in') + ' / ' + T('Blocked out'), F.num(n.pf.in_packets_block) + ' / ' + F.num(n.pf.out_packets_block) + ' pkts');
		}
		if (n.adapter_counters && Object.keys(n.adapter_counters).length) {
			kv($c, T('Adapter drops'), Object.keys(n.adapter_counters).map(function (k) { return k.replace(/_/g, ' ') + ': ' + F.num(n.adapter_counters[k]); }));
		}
		$box.append(el('p', 'fs-muted small mt-2 mb-0', T('Bytes and packets since boot')));

		var $links = el('div', 'fs-nicd-links');
		$links.append($('<a class="btn btn-sm btn-outline-secondary"></a>').attr('href', '/status_interfaces.php').text(T('Status page')));
		$links.append($('<a class="btn btn-sm btn-outline-secondary"></a>').attr('href', '/interfaces.php?if=' + encodeURIComponent(n.name)).text(T('Interface settings')));
		if (n.hardware) $links.append($('<a class="btn btn-sm btn-outline-secondary"></a>').attr('href', '/interfaces_nic_settings.php').text(T('NIC settings')));
		$box.append($links);
	};

	/* The interface drawer: detail plus a live chart while it is open. */
	var drawer = null;
	D.nicDrawer = function (name) {
		if (!drawer) {
			var $o = $('<div class="offcanvas offcanvas-end fs-dash fs-nic-drawer" tabindex="-1" aria-labelledby="fs-nic-drawer-title">' +
				'<div class="offcanvas-header"><h2 class="offcanvas-title fs-5" id="fs-nic-drawer-title"></h2>' +
				'<button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body"></div></div>').appendTo('body');
			$o.find('.btn-close').attr('aria-label', T('Close'));
			drawer = { $o: $o, oc: bootstrap.Offcanvas.getOrCreateInstance($o[0]) };
			$o.on('hidden.bs.offcanvas', function () {
				FS.live.remove('nic-drawer');
				if (drawer.chart) { drawer.chart.destroy(); drawer.chart = null; }
			});
		}
		var $body = drawer.$o.find('.offcanvas-body').empty().append(el('div', 'fs-dash-loading').append('<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>'));
		drawer.$o.find('.offcanvas-title').text(name);
		drawer.oc.show();
		FS.api.get('/status/nics/' + encodeURIComponent(name), null, { batch: false }).then(function (r) {
			var n = r.data;
			drawer.$o.find('.offcanvas-title').text(n.description);
			D.nicDetail($body, n);
			D.nicLive($body, n.name, function (c) { drawer.chart = c; }, 'nic-drawer');
		}, function (e) {
			$body.empty().append(D.empty('fa-plug-circle-xmark', e.message));
		});
	};

	/* Live In/Out tiles and a 10-minute chart (RRD seed, then samples every 2 s) in a detail box. */
	D.nicLive = function ($box, name, onChart, taskId) {
		var $chart = $box.find('.fs-nicd-chart');
		var chart = $chart.length ? FS.chart.timeseries($chart[0], {
			window: 600, label: T('Traffic, last 10 minutes'),
			series: [{ key: 'in', label: T('In'), color: '--fs-series-1' }, { key: 'out', label: T('Out'), color: '--fs-series-2', mirror: true }]
		}) : null;
		if (onChart) onChart(chart);
		var seeded = !chart, lastT = 0;
		FS.live.add({
			id: taskId, every: 2,
			run: function () {
				var seed = seeded ? Promise.resolve() : FS.api.get('/status/traffic/history', { if: name, range: '10m' }).then(function (r) {
					chart.seed(r.data.t, { in: r.data.in_bps, out: r.data.out_bps });
				}, function () { /* no RRD yet: live only */ }).then(function () { seeded = true; });
				return seed.then(function () { return FS.api.get('/status/traffic', { if: name }); }).then(function (r) {
					var t = (r.data.interfaces || {})[name] || {};
					if (lastT && (r.data.t - lastT) > 20) seeded = !chart;
					lastT = r.data.t;
					$box.find('.fs-nicd-in b').text(F.bps(t.in_bps));
					$box.find('.fs-nicd-out b').text(F.bps(t.out_bps));
					if (chart) chart.push(r.data.t, { in: t.in_bps, out: t.out_bps });
				});
			}
		});
	};
})(jQuery, window.FS, window.FSDashboard);
