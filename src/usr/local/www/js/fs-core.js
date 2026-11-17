/*
 * fs-core.js
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Shared runtime for pages that read live data from /api/v1 (the dashboard
 * first). Needs jQuery 4 and Bootstrap 5, both loaded by foot.inc.
 *
 *   FS.api   JSON client for /api/v1 through the session bridge: the session
 *            cookie plus the X-CSRF-Token header from <meta name="fs-csrf">
 *            (head.inc). GETs made in the same tick are sent as one
 *            POST /api/v1/batch. Uses fetch(), which csrf-magic.js does not
 *            rewrite (it prepends a form field to every XHR POST body).
 *   FS.live  one scheduler for every poller: a task never overlaps itself,
 *            runs every N seconds measured from its start, backs off on
 *            failure (at most 5 minutes), pauses while the tab is hidden or
 *            the task says it is not visible, and reports its freshness.
 *   FS.fmt   formatters (bits/s, bytes, link speed, uptime, relative time).
 *   FS.t     translations of the strings the page passed in (gettext).
 *   FS.toast Bootstrap toasts.
 */
(function ($, window, document) {
	'use strict';

	var FS = window.FS = window.FS || {};

	/* ------------------------------------------------------------ utils */

	FS.esc = function (v) {
		return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};
	FS.cssVar = function (name, el) {
		return getComputedStyle(el || document.documentElement).getPropertyValue(name).trim();
	};
	FS.debounce = function (fn, ms) {
		var t;
		return function () {
			var a = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, a); }, ms);
		};
	};
	FS.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* Translations: FS.i18n is filled by the page (a JSON map of English -> translated). */
	FS.i18n = FS.i18n || {};
	FS.t = function (s) {
		var out = FS.i18n[s] || s;
		var args = arguments;
		for (var i = 1; i < args.length; i++) {
			var v = String(args[i]);
			out = out.replace('%' + i + '$s', function () { return v; }).replace('%s', function () { return v; });
		}
		return out;
	};

	/* -------------------------------------------------------------- api */

	var csrf = function () { return $('meta[name="fs-csrf"]').attr('content') || ''; };
	var BATCH_MAX = 20;

	function ApiError(status, code, message, details) {
		this.status = status;
		this.code = code || 'error';
		this.message = message || FS.t('Request failed');
		this.details = details || null;
	}

	function fail(status, body) {
		var e = (body && body.error) || {};
		if (status === 401 || (status === 403 && e.code === 'csrf_failed')) {
			FS.sessionLost();
		}
		return new ApiError(status, e.code, e.message || (status ? FS.t('Request failed (HTTP %s)', status) : FS.t('The firewall did not answer')), e.details);
	}

	function send(method, url, body) {
		var init = {
			method: method,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() }
		};
		if (body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}
		var ctl = window.AbortController ? new AbortController() : null;
		var timer = null;
		if (ctl) {
			init.signal = ctl.signal;
			timer = setTimeout(function () { ctl.abort(); }, 30000);
		}
		return fetch(url, init).then(function (res) {
			return res.text().then(function (text) {
				var json = null;
				try { json = text ? JSON.parse(text) : {}; } catch (e) { json = null; }
				if (!res.ok || json === null) {
					throw fail(res.status, json);
				}
				return json;
			});
		}, function () {
			throw fail(0, null);
		}).finally(function () { clearTimeout(timer); });
	}

	function url(path, query) {
		var u = '/api/v1' + path;
		if (query) {
			var q = $.param(query);
			if (q) u += (u.indexOf('?') < 0 ? '?' : '&') + q;
		}
		return u;
	}

	/* GETs queued in one tick, sent together as /api/v1/batch. */
	var queue = [];
	var flushing = false;
	function flush() {
		flushing = false;
		var jobs = queue.splice(0, queue.length);
		while (jobs.length) {
			var chunk = jobs.splice(0, BATCH_MAX);
			if (chunk.length === 1) {
				var j = chunk[0];
				send('GET', j.url).then(j.resolve, j.reject);
				continue;
			}
			(function (chunk) {
				send('POST', '/api/v1/batch', {
					requests: chunk.map(function (j, i) { return { id: String(i), method: 'GET', path: j.url }; })
				}).then(function (res) {
					var byId = {};
					(res.data || []).forEach(function (r) { byId[r.id] = r; });
					chunk.forEach(function (j, i) {
						var r = byId[String(i)];
						if (r && r.status < 400) {
							j.resolve(r.body || {});
						} else {
							j.reject(fail(r ? r.status : 0, r ? r.body : null));
						}
					});
				}, function (e) {
					chunk.forEach(function (j) { j.reject(e); });
				});
			})(chunk);
		}
	}

	FS.api = {
		/* Resolves to {data, meta}; rejects with {status, code, message, details}. */
		get: function (path, query, opts) {
			var u = url(path, query);
			if (opts && opts.batch === false) {
				return send('GET', u);
			}
			return new Promise(function (resolve, reject) {
				queue.push({ url: u, resolve: resolve, reject: reject });
				if (!flushing) {
					flushing = true;
					setTimeout(flush, 0);
				}
			});
		},
		post: function (path, body) { return send('POST', url(path), body || {}); },
		put: function (path, body) { return send('PUT', url(path), body || {}); },
		patch: function (path, body) { return send('PATCH', url(path), body || {}); },
		del: function (path, query) { return send('DELETE', url(path, query)); }
	};

	/* The session is gone (timed out or signed out elsewhere): say so once and stop polling. */
	var lost = false;
	FS.sessionLost = function () {
		if (lost) return;
		lost = true;
		FS.live.pause(true);
		var $b = $('<div class="alert alert-warning fs-session-lost" role="alert"></div>');
		$b.append(document.createTextNode(FS.t('Your session has ended, so live data stopped updating.') + ' '));
		$b.append($('<a class="alert-link" href="/"></a>').text(FS.t('Sign in again')));
		$('main .fs-page, main').first().prepend($b);
	};

	/* ------------------------------------------------------------- live */

	/*
	 * FS.live.add({id, every: seconds, run: () => Promise, onState(state, info), visible: () => bool})
	 * state: 'loading' | 'ok' | 'stale' | 'error'. every 0 runs only on FS.live.now(id).
	 */
	FS.live = (function () {
		var tasks = {};
		var paused = false;
		var listeners = [];

		function setState(t, s, info) {
			if (t.state === s && s !== 'ok' && s !== 'error') return;
			t.state = s;
			if (t.onState) t.onState(s, $.extend({ lastOk: t.lastOk, failures: t.failures }, info || {}));
		}
		function runTask(t) {
			if (t.busy || !tasks[t.id]) return;
			t.busy = true;
			t.startedAt = Date.now();
			if (!t.lastOk) setState(t, 'loading');
			var p;
			try { p = Promise.resolve(t.run()); } catch (e) { p = Promise.reject(e); }
			p.then(function () {
				t.failures = 0;
				t.retryAt = 0;
				t.lastOk = Date.now();
				setState(t, 'ok');
			}, function (e) {
				t.failures = Math.min((t.failures || 0) + 1, 8);
				t.retryAt = Date.now() + Math.min(Math.max(t.every, 2) * 1000 * Math.pow(2, t.failures), 300000);
				setState(t, 'error', { error: (e && e.message) ? e.message : String(e), retryAt: t.retryAt });
				if (window.console && !(e instanceof ApiError)) console.error(e);
			}).finally(function () {
				t.busy = false;
				t.next = t.startedAt + t.every * 1000;
			});
		}
		function loop() {
			var now = Date.now();
			if (!document.hidden && !paused) {
				Object.keys(tasks).forEach(function (k) {
					var t = tasks[k];
					if (t.every <= 0 || t.busy) return;
					if (t.retryAt && now < t.retryAt) return;
					if (t.visible && !t.visible()) return;
					if (now >= (t.next || 0) - 50) runTask(t);
					if (t.lastOk && t.state === 'ok' && now - t.lastOk > Math.max(t.every * 3000, 15000)) setState(t, 'stale');
				});
			}
			setTimeout(loop, 250);
		}
		setTimeout(loop, 0);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && !paused) Object.keys(tasks).forEach(function (k) { tasks[k].next = 0; });
		});

		return {
			add: function (o) {
				var t = $.extend({ every: 5, failures: 0, next: 0 }, o);
				tasks[t.id] = t;
				if (!paused) runTask(t);
				return t;
			},
			remove: function (id) { delete tasks[id]; },
			now: function (id) {
				var t = tasks[id];
				if (t) { t.retryAt = 0; runTask(t); }
			},
			setEvery: function (id, s) {
				var t = tasks[id];
				if (t) { t.every = s; t.next = 0; }
			},
			isPaused: function () { return paused; },
			pause: function (v) {
				paused = (v === undefined) ? !paused : !!v;
				if (!paused) Object.keys(tasks).forEach(function (k) { tasks[k].next = 0; });
				listeners.forEach(function (fn) { fn(paused); });
				return paused;
			},
			onPause: function (fn) { listeners.push(fn); }
		};
	})();

	/* -------------------------------------------------------------- fmt */

	function scaled(v, units, base) {
		var i = 0;
		var neg = v < 0;
		v = Math.abs(v);
		while (v >= base && i < units.length - 1) { v /= base; i++; }
		var s = (v >= 100 || i === 0) ? String(Math.round(v)) : v.toFixed(v >= 10 ? 1 : 2);
		return (neg ? '-' : '') + s + ' ' + units[i];
	}

	FS.fmt = {
		dash: '—',
		bps: function (v) { return v == null ? FS.fmt.dash : scaled(v, ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s', 'Tbit/s'], 1000); },
		pps: function (v) { return v == null ? FS.fmt.dash : scaled(v, ['pps', 'kpps', 'Mpps'], 1000); },
		bytes: function (v) { return v == null ? FS.fmt.dash : scaled(v, ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'], 1024); },
		num: function (v) { return v == null ? FS.fmt.dash : Number(v).toLocaleString(); },
		compact: function (v) {
			if (v == null) return FS.fmt.dash;
			if (Math.abs(v) < 1000) return String(Math.round(v));
			return scaled(v, ['', 'k', 'M', 'G'], 1000).replace(' ', '');
		},
		pct: function (v, d) { return v == null ? FS.fmt.dash : Number(v).toFixed(d || 0) + '%'; },
		/* Link speed in Mbit/s -> "100 Mbit/s", "2.5 Gbit/s", "10 Gbit/s". */
		speed: function (mbps) {
			if (!mbps) return '';
			if (mbps >= 1000) return (mbps / 1000).toFixed(mbps % 1000 ? 1 : 0) + ' Gbit/s';
			return mbps + ' Mbit/s';
		},
		uptime: function (s) {
			if (s == null) return FS.fmt.dash;
			var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
			return (d ? d + 'd ' : '') + h + 'h ' + m + 'm';
		},
		/* Seconds (number) or an ISO time (string) -> "5m ago". */
		ago: function (v) {
			var s = (typeof v === 'number') ? v : Math.max(0, (Date.now() - new Date(v).getTime()) / 1000);
			if (s < 5) return FS.t('just now');
			if (s < 60) return FS.t('%ss ago', Math.floor(s));
			if (s < 3600) return FS.t('%sm ago', Math.floor(s / 60));
			if (s < 86400) return FS.t('%sh ago', Math.floor(s / 3600));
			return FS.t('%sd ago', Math.floor(s / 86400));
		},
		time: function (ms) { return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }); },
		datetime: function (v) { var d = new Date(v); return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour12: false }); }
	};

	/* ------------------------------------------------------------ toast */

	FS.toast = function (msg, level) {
		level = level || 'success';
		var $wrap = $('#fs-toasts');
		if (!$wrap.length) {
			$wrap = $('<div id="fs-toasts" class="toast-container position-fixed bottom-0 end-0 p-3" aria-live="polite"></div>').appendTo('body');
		}
		var icon = { success: 'fa-circle-check', danger: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' }[level];
		var $t = $('<div class="toast fs-toast" role="status"><div class="toast-body d-flex gap-2 align-items-start">' +
			'<i class="fa-solid mt-1" aria-hidden="true"></i><span class="flex-grow-1"></span>' +
			'<button type="button" class="btn-close" data-bs-dismiss="toast"></button></div></div>');
		$t.addClass('fs-toast-' + level).find('i').addClass(icon);
		$t.find('span').text(msg);
		$t.find('.btn-close').attr('aria-label', FS.t('Close'));
		$wrap.append($t);
		var t = new bootstrap.Toast($t[0], { delay: 4000 });
		$t.on('hidden.bs.toast', function () { $t.remove(); });
		t.show();
	};
})(jQuery, window, document);
