/*
 * fs-chart.js
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Charts for the WebUI. Time series use uPlot (vendor/uplot, MIT); small
 * marks (sparkline, ring, meter) are plain SVG/HTML. Every colour comes
 * from the design tokens (--fs-series-*, --fs-border, --fs-text-muted), so
 * charts follow the light and dark themes.
 *
 *   var c = FS.chart.timeseries(el, {
 *       series: [{key: 'in', label: 'In', color: '--fs-series-1'},
 *                {key: 'out', label: 'Out', color: '--fs-series-2', mirror: true}],
 *       window: 600,                      // seconds kept (0 = everything given)
 *       format: FS.fmt.bps,               // axis and tooltip values
 *       label: 'Traffic on WAN'});        // accessible name
 *   c.seed(t[], {in: [...], out: [...]}); // history (unix seconds, null = gap)
 *   c.push(t, {in: v, out: v});           // live samples
 *
 * Points far apart in time stay apart (real time axis), missing values are
 * gaps, drawing waits for the next animation frame and is skipped while the
 * chart is off screen.
 */
(function ($, window, document) {
	'use strict';

	var FS = window.FS = window.FS || {};
	FS.chart = FS.chart || {};

	/* A CSS colour (hex or rgb[a]) with an alpha, for canvas fills. */
	function alpha(color, a) {
		var m;
		color = (color || '').trim();
		if ((m = /^#([0-9a-f]{3})$/i.exec(color))) {
			color = '#' + m[1].split('').map(function (c) { return c + c; }).join('');
		}
		if ((m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})/i.exec(color))) {
			return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + a + ')';
		}
		if ((m = /^rgba?\(([^)]+)\)/i.exec(color))) {
			var p = m[1].split(/[ ,/]+/).filter(Boolean);
			return 'rgba(' + p[0] + ',' + p[1] + ',' + p[2] + ',' + a + ')';
		}
		return color;
	}
	FS.chart.alpha = alpha;

	function tokenColor(c, el) {
		return (c && c.indexOf('--') === 0) ? (FS.cssVar(c, el) || '#2a78d6') : c;
	}

	FS.chart.timeseries = function (el, opts) {
		opts = $.extend({ window: 600, format: FS.fmt.bps, fill: 0.14, height: null, axes: true, label: '' }, opts);
		if (!window.uPlot) {
			$(el).text(FS.t('Charts are not available.'));
			return { seed: $.noop, push: $.noop, destroy: $.noop, redraw: $.noop, setWindow: $.noop, last: function () { return {}; } };
		}
		var $el = $(el).addClass('fs-chart').empty();
		var $plot = $('<div class="fs-chart-plot"></div>').appendTo($el);
		var $tip = $('<div class="fs-chart-tip" hidden></div>').appendTo($el);
		$el.attr({ role: 'img', 'aria-label': opts.label || FS.t('Chart') });

		var keys = opts.series.map(function (s) { return s.key; });
		var data = [[]].concat(keys.map(function () { return []; }));
		var u = null;
		var frame = 0;
		var onScreen = true;
		var pending = false;

		function signed(s, v) { return (v == null) ? null : (s.mirror ? -Math.abs(v) : v); }

		function build() {
			var font = '11px ' + (getComputedStyle(document.body).fontFamily || 'system-ui, sans-serif');
			var grid = FS.cssVar('--fs-border', el) || 'rgba(127,127,127,.2)';
			var text = FS.cssVar('--fs-text-muted', el) || '#6b7380';
			var w = Math.max(120, Math.floor($el.width()));
			var h = opts.height || Math.max(80, Math.floor($el.height())) || 180;
			var series = [{}].concat(opts.series.map(function (s) {
				var col = tokenColor(s.color, el);
				return {
					label: s.label, stroke: col, width: 1.5, scale: s.scale || 'y',
					fill: opts.fill ? alpha(col, opts.fill) : null,
					points: { show: false }, spanGaps: false
				};
			}));
			var axes = [
				{ show: opts.axes, stroke: text, font: font, size: 26, grid: { stroke: grid, width: 1 }, ticks: { show: false } },
				{
					show: opts.axes, scale: 'y', stroke: text, font: font, size: 74, gap: 6,
					grid: { stroke: grid, width: 1 }, ticks: { show: false },
					values: function (self, vals) { return vals.map(function (v) { return opts.format(Math.abs(v)); }); }
				}
			];
			var scales = {
				x: { time: true },
				y: { range: function (self, min, max) {
					var lo = Math.min(0, min || 0), hi = Math.max(0, max || 0);
					if (lo === 0 && hi === 0) hi = 1;
					return [lo * 1.1, hi * 1.1];
				} }
			};
			if (opts.series.some(function (s) { return s.scale === 'y2'; })) {
				axes.push({
					scale: 'y2', side: 1, stroke: text, font: font, size: 52, gap: 6, grid: { show: false }, ticks: { show: false },
					values: function (self, vals) { return vals.map(function (v) { return (opts.format2 || opts.format)(v); }); }
				});
				scales.y2 = { range: function (self, min, max) { return [0, Math.max(opts.y2min || 1, (max || 0) * 1.1)]; } };
			}
			if (u) u.destroy();
			u = new window.uPlot({
				width: w, height: h, series: series, axes: axes, scales: scales,
				legend: { show: false },
				cursor: { y: false, points: { size: 6 }, drag: { x: !!opts.zoom, y: false } },
				padding: [6, 4, 0, 0],
				hooks: { setCursor: [tip] }
			}, data, $plot[0]);
		}

		function tip(self) {
			var i = self.cursor.idx;
			if (i == null || !data[0].length) {
				$tip.prop('hidden', true);
				if (opts.onHover) opts.onHover(null);
				return;
			}
			$tip.empty().append($('<div class="fs-chart-tip-t"></div>').text(FS.fmt.time(data[0][i] * 1000)));
			var vals = {};
			opts.series.forEach(function (s, k) {
				var v = data[k + 1][i];
				vals[s.key] = (v == null) ? null : Math.abs(v);
				var $row = $('<div class="fs-chart-tip-row"><i></i><span></span><b></b></div>');
				$row.find('i').css('background', self.series[k + 1].stroke());
				$row.find('span').text(s.label);
				$row.find('b').text((v == null) ? FS.fmt.dash : (s.scale === 'y2' && opts.format2 ? opts.format2 : opts.format)(Math.abs(v)));
				$tip.append($row);
			});
			var left = self.cursor.left + self.bbox.left / window.devicePixelRatio;
			var w = $tip.outerWidth() || 140;
			$tip.prop('hidden', false).css({ left: Math.min(left + 12, $el.width() - w - 4) + 'px', top: '4px' });
			if (opts.onHover) opts.onHover(data[0][i], vals);
		}

		function prune() {
			if (!opts.window || !data[0].length) return;
			var cut = data[0][data[0].length - 1] - opts.window;
			var n = 0;
			while (n < data[0].length && data[0][n] < cut) n++;
			if (n) data = data.map(function (col) { return col.slice(n); });
		}

		function draw() {
			if (!onScreen) { pending = true; return; }
			if (frame) return;
			frame = window.requestAnimationFrame(function () {
				frame = 0;
				pending = false;
				if (u) u.setData(data, true);
				updateLabel();
			});
		}

		function updateLabel() {
			var n = data[0].length;
			if (!n) return;
			var parts = opts.series.map(function (s, k) {
				var v = data[k + 1][n - 1];
				return s.label + ' ' + ((v == null) ? FS.fmt.dash : opts.format(Math.abs(v)));
			});
			$el.attr('aria-label', (opts.label ? opts.label + ': ' : '') + parts.join(', '));
		}

		var ro = new ResizeObserver(FS.debounce(function () {
			if (!u) return;
			var w = Math.max(120, Math.floor($el.width()));
			var h = opts.height || Math.max(80, Math.floor($el.height()));
			if (w !== u.width || h !== u.height) u.setSize({ width: w, height: h });
		}, 60));
		ro.observe(el);
		var io = new IntersectionObserver(function (entries) {
			onScreen = entries[0].isIntersecting;
			if (onScreen && pending) draw();
		});
		io.observe(el);
		/* The theme is a stylesheet; rebuild when the colour mode or the DPR changes. */
		var mo = new MutationObserver(function () { build(); });
		mo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
		var dprQuery = window.matchMedia('(resolution: ' + window.devicePixelRatio + 'dppx)');
		var onDpr = function () { build(); };
		if (dprQuery.addEventListener) dprQuery.addEventListener('change', onDpr);

		build();

		return {
			seed: function (t, values) {
				data = [t.slice()].concat(opts.series.map(function (s) {
					return (values[s.key] || []).map(function (v) { return signed(s, v); });
				}));
				prune();
				draw();
			},
			push: function (t, values) {
				if (t == null) return;
				var last = data[0].length ? data[0][data[0].length - 1] : null;
				if (last !== null && t <= last) return;
				data[0].push(t);
				opts.series.forEach(function (s, k) { data[k + 1].push(signed(s, values[s.key])); });
				prune();
				draw();
			},
			last: function () {
				var o = {}, n = data[0].length;
				opts.series.forEach(function (s, k) { o[s.key] = n ? data[k + 1][n - 1] : null; });
				return o;
			},
			setWindow: function (sec) { opts.window = sec; prune(); draw(); },
			redraw: function () { build(); },
			destroy: function () {
				ro.disconnect(); io.disconnect(); mo.disconnect();
				if (dprQuery.removeEventListener) dprQuery.removeEventListener('change', onDpr);
				if (frame) window.cancelAnimationFrame(frame);
				if (u) u.destroy();
				$el.empty();
			}
		};
	};

	/* Inline SVG sparkline (a string; values are numbers, null = gap). */
	FS.chart.spark = function (values, o) {
		o = $.extend({ w: 120, h: 28, color: 'var(--fs-series-1)', fill: true, max: null, min: 0 }, o);
		var pts = (values || []).filter(function (v) { return v != null; });
		var svg = '<svg class="fs-spark" viewBox="0 0 ' + o.w + ' ' + o.h + '" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
		if (pts.length < 2) return svg + '</svg>';
		var max = (o.max != null) ? o.max : Math.max.apply(null, pts);
		var min = (o.min != null) ? o.min : Math.min.apply(null, pts);
		var span = (max - min) || 1, n = values.length;
		var segs = [], cur = [];
		values.forEach(function (v, i) {
			if (v == null) { if (cur.length) segs.push(cur); cur = []; return; }
			var x = (n === 1 ? 0 : i / (n - 1) * o.w).toFixed(1);
			var y = (o.h - 1 - ((Math.min(Math.max(v, min), max) - min) / span) * (o.h - 2)).toFixed(1);
			cur.push(x + ',' + y);
		});
		if (cur.length) segs.push(cur);
		segs.forEach(function (s) {
			if (o.fill && s.length > 1) {
				svg += '<polygon points="' + s[0].split(',')[0] + ',' + o.h + ' ' + s.join(' ') + ' ' + s[s.length - 1].split(',')[0] + ',' + o.h +
					'" style="fill:' + o.color + '" opacity=".16"/>';
			}
			svg += '<polyline points="' + s.join(' ') + '" fill="none" style="stroke:' + o.color + '" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>';
		});
		return svg + '</svg>';
	};

	function level(pct, o) { return pct >= o.crit ? 'crit' : (pct >= o.warn ? 'warn' : 'ok'); }

	/* SVG ring gauge, 0-100 %. */
	FS.chart.ring = function (pct, o) {
		o = $.extend({ size: 84, stroke: 8, label: '', warn: 75, crit: 90 }, o);
		var known = (pct != null);
		pct = known ? Math.max(0, Math.min(100, pct)) : 0;
		var r = (o.size - o.stroke) / 2, c = 2 * Math.PI * r, mid = o.size / 2;
		return '<svg class="fs-ring fs-ring-' + level(pct, o) + '" viewBox="0 0 ' + o.size + ' ' + o.size + '" width="' + o.size + '" height="' + o.size +
			'" role="img" aria-label="' + FS.esc(o.label + ' ' + (known ? Math.round(pct) + '%' : FS.fmt.dash)) + '">' +
			'<circle class="fs-ring-track" cx="' + mid + '" cy="' + mid + '" r="' + r + '" fill="none" stroke-width="' + o.stroke + '"/>' +
			'<circle class="fs-ring-val" cx="' + mid + '" cy="' + mid + '" r="' + r + '" fill="none" stroke-width="' + o.stroke + '" stroke-linecap="round"' +
			' stroke-dasharray="' + (c * pct / 100).toFixed(2) + ' ' + c.toFixed(2) + '" transform="rotate(-90 ' + mid + ' ' + mid + ')"/>' +
			'<text x="50%" y="52%" text-anchor="middle" dominant-baseline="middle" class="fs-ring-text">' + (known ? Math.round(pct) + '%' : FS.fmt.dash) + '</text></svg>';
	};

	/* Horizontal meter bar, 0-100 %. */
	FS.chart.meter = function (pct, o) {
		o = $.extend({ warn: 75, crit: 90, label: '' }, o);
		pct = Math.max(0, Math.min(100, pct || 0));
		return '<div class="fs-meter fs-meter-' + level(pct, o) + '" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' +
			Math.round(pct) + '" aria-label="' + FS.esc(o.label) + '"><span style="width:' + pct.toFixed(1) + '%"></span></div>';
	};
})(jQuery, window, document);
