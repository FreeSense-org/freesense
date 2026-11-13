/*
 * fs-dashboard.js
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * The dashboard widget runtime (index.php). Widgets are JavaScript modules
 * that read JSON from /api/v1 and update their own DOM; there is no server
 * HTML per refresh.
 *
 * A widget is a manifest (www/widgets/<type>.widget.json: title, icon,
 * category, sizes, refresh interval, settings) that index.php lists for the
 * user's privileges, plus a script (www/widgets/<type>.js) that registers
 * the behaviour:
 *
 *   FSDashboard.define('type', {
 *     mount(w)            build the static DOM once (and again after Settings)
 *     load(w) -> Promise  fetch and render; called by FS.live every N seconds
 *     resize(w)           optional, after a size change
 *     destroy(w)          optional, before the widget is removed or remounted
 *   });
 *   w = {id, type, def, $root, $body, settings, size, state, save(), foot(text|node), refresh()}
 *
 * Layout: a 12-column grid, sizes sm 3 / md 4 / lg 6 / xl 8 / full 12,
 * several copies of a widget, drag to reorder in edit mode (keyboard: Move
 * earlier / later in the widget menu). Saved per user with
 * PUT /api/v1/dashboard/layout (webui_prefs.inc, outside config.xml).
 */
(function ($, window, document, FS) {
	'use strict';

	var cfgEl = document.getElementById('fs-dash-config');
	var CFG = cfgEl ? JSON.parse(cfgEl.textContent) : { catalogue: {}, defaults: [], interfaces: [], i18n: {} };
	$.extend(FS.i18n, CFG.i18n || {});

	var T = FS.t;
	var SIZES = ['sm', 'md', 'lg', 'xl', 'full'];
	var SIZE_LABEL = { sm: T('Small'), md: T('Medium'), lg: T('Large'), xl: T('Wide'), full: T('Full width') };
	var EVERY = [[0, T('Off')], [2, T('Every 2 seconds')], [5, T('Every 5 seconds')], [10, T('Every 10 seconds')], [30, T('Every 30 seconds')], [60, T('Every minute')]];

	var D = window.FSDashboard = {
		config: CFG,
		impl: {},
		instances: {},
		define: function (type, impl) { D.impl[type] = impl; },
		/* [[value, label]] for a settings select of interfaces. */
		interfaceOptions: function () {
			return (CFG.interfaces || []).map(function (i) { return [i.name, i.description + ' (' + i.name + ')']; });
		}
	};

	/* DOM helpers for widgets: data always goes in as text. */
	D.el = function (tag, cls, text) {
		var $e = $(document.createElement(tag));
		if (cls) $e.addClass(cls);
		if (text != null) $e.text(text);
		return $e;
	};
	/* Status dot: ok | warn | down | off. */
	D.dot = function (status, label) {
		return D.el('span', 'fs-dot').attr('data-status', status).append(D.el('span', 'visually-hidden', label || status));
	};
	/* Small tag: kind '' | strong | ok | warn | bad | info. */
	D.tag = function (text, kind, icon) {
		var $t = D.el('span', 'fs-wtag' + (kind ? ' fs-wtag--' + kind : ''));
		if (icon) $t.append($('<i class="fa-solid" aria-hidden="true"></i>').addClass(icon));
		return $t.append(document.createTextNode(text));
	};
	D.empty = function (icon, text) {
		return D.el('div', 'fs-wempty').append($('<i class="fa-solid" aria-hidden="true"></i>').addClass(icon)).append(D.el('p', '', text));
	};
	/* Keep the last n values of a series. */
	D.push = function (arr, v, n) { arr.push(v); while (arr.length > (n || 60)) arr.shift(); return arr; };
	/* A copy-to-clipboard button for a value. */
	D.copy = function (value, label) {
		return D.el('button', 'fs-copy').attr({ type: 'button', title: T('Copy'), 'aria-label': T('Copy') + ' ' + (label || value), 'data-copy': value })
			.append('<i class="fa-regular fa-copy" aria-hidden="true"></i>');
	};
	$(document).on('click', '.fs-copy[data-copy]', function (e) {
		e.stopPropagation();
		var v = $(this).attr('data-copy');
		if (navigator.clipboard) navigator.clipboard.writeText(v).then(function () { FS.toast(T('Copied'), 'success'); });
	});

	function def(type) {
		var m = CFG.catalogue[type], impl = D.impl[type];
		if (!m || !impl) return null;
		return $.extend({ sizes: ['md'], size: 'md', every: 5, settings: [], category: T('Other'), icon: 'fa-puzzle-piece' }, m, impl, { type: type });
	}

	function uid() { return 'w' + Math.random().toString(36).slice(2, 10); }

	var $grid, editing = false;

	/* ---------------------------------------------------------- frame */

	function frame(inst) {
		var $w = $('<section class="fs-widget">' +
			'<header class="fs-widget-head">' +
				'<span class="fs-widget-grip" aria-hidden="true"><i class="fa-solid fa-grip-vertical"></i></span>' +
				'<span class="fs-widget-icon" aria-hidden="true"><i class="fa-solid"></i></span>' +
				'<h2 class="fs-widget-title"></h2>' +
				'<span class="fs-live-dot" data-state="loading" role="status"><span class="visually-hidden"></span></span>' +
				'<div class="fs-widget-tools">' +
					'<button type="button" class="fs-icon-btn" data-w-act="refresh"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button>' +
					'<div class="dropdown">' +
						'<button type="button" class="fs-icon-btn" data-bs-toggle="dropdown" aria-expanded="false"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>' +
						'<ul class="dropdown-menu dropdown-menu-end fs-widget-menu"></ul>' +
					'</div>' +
				'</div>' +
			'</header>' +
			'<div class="fs-widget-body"></div>' +
			'<footer class="fs-widget-foot" hidden></footer>' +
		'</section>');
		$w.attr({ id: 'widget-' + inst.id, 'data-type': inst.type, 'data-size': inst.size, 'aria-labelledby': 'wt-' + inst.id });
		$w.find('.fs-widget-icon i').addClass(inst.def.icon);
		if (inst.def.tone) $w.find('.fs-widget-icon').css('--fs-wtone', 'var(--fs-' + inst.def.tone + ')');
		$w.find('.fs-widget-title').attr('id', 'wt-' + inst.id).text(inst.settings.title || inst.def.title);
		$w.find('[data-w-act=refresh]').attr({ title: T('Refresh now'), 'aria-label': T('Refresh now') });
		$w.find('[data-bs-toggle=dropdown]').attr({ title: T('Widget options'), 'aria-label': T('Widget options') });
		if (inst.def.link) {
			$w.find('.fs-widget-title').empty().append($('<a></a>').attr('href', inst.def.link).text(inst.settings.title || inst.def.title));
		}
		return $w;
	}

	function menu(inst) {
		var d = inst.def, $m = inst.$root.find('.fs-widget-menu').empty();
		var every = (inst.settings.every != null) ? +inst.settings.every : d.every;
		function item(act, icon, label, v, current) {
			var $b = $('<button type="button" class="dropdown-item"></button>').attr('data-w-act', act);
			if (v !== undefined) $b.attr('data-v', v);
			if (current) $b.attr('aria-current', 'true');
			$b.append($('<i class="fa-solid fa-fw me-2" aria-hidden="true"></i>').addClass(icon)).append(document.createTextNode(label));
			return $('<li></li>').append($b);
		}
		function header(text) { return $('<li><h6 class="dropdown-header"></h6></li>').find('h6').text(text).end(); }
		if (d.every > 0) {
			$m.append(header(T('Refresh')));
			EVERY.forEach(function (o) { $m.append(item('every', o[0] === every ? 'fa-check' : 'fa-blank', o[1], o[0], o[0] === every)); });
			$m.append('<li><hr class="dropdown-divider"></li>');
		}
		if (d.sizes.length > 1) {
			$m.append(header(T('Size')));
			d.sizes.forEach(function (s) { $m.append(item('size', s === inst.size ? 'fa-check' : 'fa-blank', SIZE_LABEL[s], s, s === inst.size)); });
			$m.append('<li><hr class="dropdown-divider"></li>');
		}
		$m.append(item('settings', 'fa-sliders', T('Settings') + '…'));
		$m.append(item('up', 'fa-arrow-up', T('Move earlier')));
		$m.append(item('down', 'fa-arrow-down', T('Move later')));
		$m.append(item('collapse', inst.collapsed ? 'fa-chevron-down' : 'fa-chevron-up', inst.collapsed ? T('Expand') : T('Collapse')));
		$m.append('<li><hr class="dropdown-divider"></li>');
		$m.append(item('remove', 'fa-xmark', T('Remove widget')).find('button').addClass('text-danger').end());
	}

	/* --------------------------------------------------------- mount */

	function mount(cfg, $before) {
		var d = def(cfg.type);
		if (!d) return null;
		var settings = {};
		d.settings.forEach(function (s) { if (s.value !== undefined) settings[s.key] = s.value; });
		$.extend(settings, cfg.settings || {});
		var inst = {
			id: cfg.id || uid(), type: cfg.type, def: d,
			size: d.sizes.indexOf(cfg.size) >= 0 ? cfg.size : d.size,
			settings: settings, state: {}, collapsed: !!cfg.collapsed, onScreen: true
		};
		inst.$root = frame(inst);
		inst.$body = inst.$root.find('.fs-widget-body');
		inst.$foot = inst.$root.find('.fs-widget-foot');
		inst.save = function () { persist(); };
		inst.refresh = function () { FS.live.now('w:' + inst.id); };
		inst.foot = function (content) {
			inst.$foot.empty();
			if (content == null || content === '') { inst.$foot.prop('hidden', true); return; }
			inst.$foot.append(typeof content === 'string' ? document.createTextNode(content) : content).prop('hidden', false);
		};
		inst.$root.toggleClass('is-collapsed', inst.collapsed);
		if ($before && $before.length) inst.$root.insertBefore($before); else $grid.append(inst.$root);
		D.instances[inst.id] = inst;
		menu(inst);
		inst.$body.attr('aria-busy', 'true');
		try {
			d.mount(inst);
		} catch (e) {
			if (window.console) console.error(e);
		}
		watch(inst);
		start(inst);
		return inst;
	}

	var io = window.IntersectionObserver ? new IntersectionObserver(function (entries) {
		entries.forEach(function (en) {
			var inst = D.instances[en.target.id.replace('widget-', '')];
			if (!inst) return;
			var was = inst.onScreen;
			inst.onScreen = en.isIntersecting;
			if (!was && inst.onScreen) FS.live.now('w:' + inst.id);
		});
	}, { rootMargin: '120px' }) : null;
	function watch(inst) {
		if (io) io.observe(inst.$root[0]);
		if (ro) ro.observe(inst.$root[0]);
	}

	/* Masonry: each widget spans as many 6px grid rows as its height plus the gap needs. */
	function span(el) {
		var cs = getComputedStyle(el.parentNode);
		var row = parseFloat(cs.gridAutoRows) || 6;
		var gap = parseFloat(getComputedStyle(el).marginBottom) || 0;
		el.style.gridRowEnd = 'span ' + Math.max(1, Math.ceil((el.getBoundingClientRect().height + gap) / row));
	}
	var ro = window.ResizeObserver ? new ResizeObserver(function (entries) {
		entries.forEach(function (en) { span(en.target); });
	}) : null;
	$(window).on('resize', FS.debounce(function () {
		if ($grid) $grid.children('.fs-widget').each(function () { span(this); });
	}, 100));

	function errorCard(inst, message) {
		var $e = $('<div class="fs-wempty fs-wempty-error"><i class="fa-solid fa-plug-circle-xmark" aria-hidden="true"></i><p></p>' +
			'<button type="button" class="btn btn-sm btn-outline-secondary" data-w-act="refresh"></button></div>');
		$e.find('p').text(message || T('The data could not be loaded.'));
		$e.find('button').text(T('Retry'));
		inst.$body.empty().append($e);
	}

	function start(inst) {
		var every = (inst.settings.every != null) ? +inst.settings.every : inst.def.every;
		FS.live.add({
			id: 'w:' + inst.id, every: every || 0,
			visible: function () { return inst.onScreen && !inst.collapsed; },
			run: function () {
				if (inst.collapsed) return Promise.resolve();
				return Promise.resolve(inst.def.load(inst)).then(function () {
					inst.$body.attr('aria-busy', 'false');
					inst.state.loadedOnce = true;
				});
			},
			onState: function (s, info) {
				var label = { ok: T('Up to date'), loading: T('Loading'), stale: T('The data is out of date'), error: T('Update failed') + ': ' + (info.error || '') }[s];
				inst.$root.find('.fs-live-dot').attr({ 'data-state': s, title: label }).find('.visually-hidden').text(label);
				inst.$root.toggleClass('is-error', s === 'error').toggleClass('is-stale', s === 'stale');
				if (s === 'error' && !inst.state.loadedOnce) errorCard(inst, info.error);
			}
		});
	}

	function unmount(inst) {
		FS.live.remove('w:' + inst.id);
		if (io) io.unobserve(inst.$root[0]);
		if (ro) ro.unobserve(inst.$root[0]);
		if (inst.def.destroy) { try { inst.def.destroy(inst); } catch (e) { if (window.console) console.error(e); } }
		inst.$root.remove();
		delete D.instances[inst.id];
	}

	function remount(inst) {
		if (inst.def.destroy) inst.def.destroy(inst);
		inst.state = {};
		inst.$body.off().empty().attr('aria-busy', 'true');
		inst.foot(null);
		inst.def.mount(inst);
		FS.live.now('w:' + inst.id);
	}

	/* ---------------------------------------------------------- save */

	function serialize() {
		return $grid.children('.fs-widget').toArray().map(function (el) {
			var inst = D.instances[el.id.replace('widget-', '')];
			return { id: inst.id, type: inst.type, size: inst.size, settings: inst.settings, collapsed: inst.collapsed };
		});
	}

	var persist = FS.debounce(function () {
		FS.api.put('/dashboard/layout', { widgets: serialize() }).catch(function (e) {
			FS.toast(T('The dashboard layout could not be saved.') + ' ' + e.message, 'danger');
		});
	}, 500);

	/* ----------------------------------------------------- settings */

	function settingsField(s, value) {
		var id = 'fs-ws-' + s.key, $f;
		if (s.type === 'checkbox') {
			$f = $('<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch"><label class="form-check-label"></label></div>');
			$f.find('input').attr({ id: id, name: s.key }).prop('checked', !!value);
			$f.find('label').attr('for', id).text(T(s.label));
		} else if (s.type === 'interfaces') {
			var chosen = Array.isArray(value) ? value : [];
			$f = $('<fieldset class="mb-3"><legend class="form-label fs-6"></legend><div class="fs-ws-checks"></div></fieldset>');
			$f.find('legend').text(T(s.label));
			D.interfaceOptions().forEach(function (o, i) {
				var $c = $('<div class="form-check"><input class="form-check-input" type="checkbox"><label class="form-check-label"></label></div>');
				$c.find('input').attr({ id: id + '-' + i, name: s.key, value: o[0] }).prop('checked', !chosen.length || chosen.indexOf(o[0]) >= 0);
				$c.find('label').attr('for', id + '-' + i).text(o[1]);
				$f.find('.fs-ws-checks').append($c);
			});
		} else {
			$f = $('<div class="mb-3"><label class="form-label"></label></div>');
			$f.find('label').attr('for', id).text(T(s.label));
			var $in;
			if (s.type === 'select' || s.type === 'interface') {
				$in = $('<select class="form-select"></select>');
				var opts = (s.type === 'interface') ? D.interfaceOptions() : (s.options || []);
				/* An interface setting that defaults to '' also offers "every interface". */
				if (s.type === 'interface' && s.value === '') opts = [['', T('Every interface')]].concat(opts);
				opts.forEach(function (o) {
					$in.append($('<option></option>').val(o[0]).text(s.type === 'interface' ? o[1] : T(o[1])).prop('selected', String(o[0]) === String(value)));
				});
			} else {
				$in = $('<input class="form-control">').attr({ type: s.type === 'number' ? 'number' : 'text', placeholder: s.placeholder || '' }).val(value == null ? '' : value);
				if (s.min != null) $in.attr('min', s.min);
				if (s.max != null) $in.attr('max', s.max);
				if (s.type === 'text') $in.attr('maxlength', 80);
			}
			$f.append($in.attr({ id: id, name: s.key }));
		}
		if (s.help) $f.append($('<div class="form-text"></div>').text(T(s.help)));
		return $f;
	}

	function openSettings(inst) {
		var d = inst.def, $m = $('#fs-widget-settings');
		$m.find('.modal-title').text(T('%s settings', d.title));
		var $b = $m.find('.modal-body').empty();
		var fields = [{ key: 'title', label: 'Title', type: 'text', placeholder: d.title }].concat(d.settings);
		fields.forEach(function (s) { $b.append(settingsField(s, inst.settings[s.key])); });
		$m.find('form').off('submit').on('submit', function (e) {
			e.preventDefault();
			fields.forEach(function (s) {
				if (s.type === 'interfaces') {
					var all = $m.find('[name="' + s.key + '"]'), on = all.filter(':checked');
					inst.settings[s.key] = (on.length === all.length) ? [] : on.map(function () { return this.value; }).get();
					return;
				}
				var $in = $m.find('[name="' + s.key + '"]');
				var v = (s.type === 'checkbox') ? $in.prop('checked') : (s.type === 'number' ? +$in.val() : String($in.val() || ''));
				if (s.type === 'number') {
					if (s.min != null) v = Math.max(s.min, v);
					if (s.max != null) v = Math.min(s.max, v);
				}
				inst.settings[s.key] = v;
			});
			if (!inst.settings.title) delete inst.settings.title;
			inst.$root.find('.fs-widget-title').text(inst.settings.title || d.title);
			remount(inst);
			persist();
			bootstrap.Modal.getInstance($m[0]).hide();
		});
		bootstrap.Modal.getOrCreateInstance($m[0]).show();
	}

	/* ---------------------------------------------------- edit mode */

	function setEditing(on) {
		editing = on;
		$('body').toggleClass('fs-dash-editing', on);
		$('[data-fs-dash-edit]').attr('aria-pressed', on ? 'true' : 'false')
			.find('.fs-dash-edit-label').text(on ? T('Done') : T('Edit layout'));
		if (!$.fn.sortable) return;
		if (on) {
			$grid.sortable({
				items: '> .fs-widget', handle: '.fs-widget-head', cancel: 'button, a, input, select',
				placeholder: 'fs-widget-placeholder', tolerance: 'pointer', forcePlaceholderSize: true,
				start: function (e, ui) {
					ui.placeholder.attr('data-size', ui.item.attr('data-size')).css({ height: ui.item.outerHeight(), gridRowEnd: ui.item[0].style.gridRowEnd });
				},
				update: function () { persist(); }
			});
		} else if ($grid.data('ui-sortable')) {
			$grid.sortable('destroy');
		}
	}

	/* ---------------------------------------------------- catalogue */

	function renderCatalogue() {
		var $list = $('[data-fs-catalogue-list]').empty();
		var cats = {};
		Object.keys(CFG.catalogue).forEach(function (t) {
			var d = def(t);
			if (d) (cats[d.category] = cats[d.category] || []).push(d);
		});
		Object.keys(cats).sort().forEach(function (c) {
			var $sec = $('<section class="fs-cat-group"><h3 class="fs-cat-title"></h3><div class="fs-cat-list"></div></section>');
			$sec.find('h3').text(c);
			cats[c].sort(function (a, b) { return a.title.localeCompare(b.title); }).forEach(function (d) {
				var count = $grid.children('[data-type="' + d.type + '"]').length;
				var $it = $('<button type="button" class="fs-cat-item"><span class="fs-cat-icon" aria-hidden="true"><i class="fa-solid"></i></span>' +
					'<span class="fs-cat-text"><strong></strong><small></small></span><span class="fs-cat-add" aria-hidden="true"><i class="fa-solid fa-plus"></i></span></button>');
				$it.attr('data-add', d.type).find('.fs-cat-icon i').addClass(d.icon);
				$it.find('strong').text(d.title);
				$it.find('small').text(d.description + (count ? ' · ' + T('%s on the dashboard', count) : ''));
				if (count && !d.allow_copies) {
					$it.prop('disabled', true).find('.fs-cat-add i').removeClass('fa-plus').addClass('fa-check');
				}
				$sec.find('.fs-cat-list').append($it);
			});
			$list.append($sec);
		});
	}

	/* ------------------------------------------------------- events */

	function bind() {
		$grid.on('click', '[data-w-act]', function () {
			var $b = $(this), act = $b.data('w-act');
			var inst = D.instances[$b.closest('.fs-widget').attr('id').replace('widget-', '')];
			if (!inst) return;
			if (act === 'refresh') {
				FS.live.now('w:' + inst.id);
			} else if (act === 'every') {
				inst.settings.every = +$b.data('v');
				FS.live.setEvery('w:' + inst.id, inst.settings.every);
				menu(inst);
				persist();
			} else if (act === 'size') {
				inst.size = $b.data('v');
				inst.$root.attr('data-size', inst.size);
				menu(inst);
				if (inst.def.resize) inst.def.resize(inst);
				persist();
			} else if (act === 'collapse') {
				inst.collapsed = !inst.collapsed;
				inst.$root.toggleClass('is-collapsed', inst.collapsed);
				menu(inst);
				if (!inst.collapsed) FS.live.now('w:' + inst.id);
				persist();
			} else if (act === 'up' || act === 'down') {
				var $sib = (act === 'up') ? inst.$root.prev('.fs-widget') : inst.$root.next('.fs-widget');
				if ($sib.length) {
					if (act === 'up') inst.$root.insertBefore($sib); else inst.$root.insertAfter($sib);
				}
				inst.$root.find('[data-bs-toggle=dropdown]')[0].focus();
				persist();
			} else if (act === 'remove') {
				var title = inst.$root.find('.fs-widget-title').text();
				unmount(inst);
				persist();
				FS.toast(T('%s removed. Add it again from Add widget.', title), 'info');
			} else if (act === 'settings') {
				openSettings(inst);
			}
		});

		$(document).on('click', '[data-fs-dash-edit]', function (e) { e.preventDefault(); setEditing(!editing); });
		$(document).on('click', '[data-fs-live-toggle]', function (e) { e.preventDefault(); FS.live.pause(); });
		FS.live.onPause(function (p) {
			$('[data-fs-live-toggle]').attr('aria-pressed', p ? 'true' : 'false')
				.find('i').toggleClass('fa-pause', !p).toggleClass('fa-play', p).end()
				.find('.fs-live-label').text(p ? T('Paused') : T('Live'));
			$('body').toggleClass('fs-live-paused', p);
		});
		$(document).on('click', '[data-fs-dash-reset]', function () {
			fsConfirmReset(function () {
				FS.api.del('/dashboard/layout').then(function () {
					Object.keys(D.instances).forEach(function (k) { unmount(D.instances[k]); });
					CFG.defaults.forEach(function (c) { mount(c); });
					FS.toast(T('The dashboard is back to the default layout.'), 'info');
				}, function (e) { FS.toast(e.message, 'danger'); });
			});
		});
		$('#fs-dash-catalogue').on('show.bs.offcanvas', renderCatalogue);
		$(document).on('click', '[data-add]', function () {
			$grid.find('.fs-dash-empty').remove();
			var inst = mount({ type: $(this).data('add') });
			persist();
			renderCatalogue();
			if (inst) {
				inst.$root[0].scrollIntoView({ behavior: FS.reducedMotion ? 'auto' : 'smooth', block: 'center' });
				inst.$root.addClass('is-new');
				setTimeout(function () { inst.$root.removeClass('is-new'); }, 1600);
				FS.toast(T('%s added', inst.def.title), 'success');
			}
		});
	}

	/* The shared confirm modal (freesense-ui.js) when present, else a plain confirm. */
	function fsConfirmReset(go) {
		var $m = $('#fs-confirm');
		if (!$m.length || !window.bootstrap) { go(); return; }
		$m.find('#fs-confirm-title').text(T('Reset the dashboard?'));
		$m.find('.fs-confirm-detail').text(T('Every widget goes back to the default layout and settings.'));
		var $ok = $m.find('[data-fs-confirm-ok]').text(T('Reset')).off('click.fsdash').on('click.fsdash', function () {
			bootstrap.Modal.getInstance($m[0]).hide();
			go();
		});
		$m.one('hidden.bs.modal', function () { $ok.off('click.fsdash'); });
		bootstrap.Modal.getOrCreateInstance($m[0]).show();
	}

	/* --------------------------------------------------------- boot */

	$(function () {
		$grid = $('#fs-dash-grid');
		if (!$grid.length) return;
		bind();
		FS.api.get('/dashboard/layout', null, { batch: false }).then(function (res) {
			return (res.data && res.data.length) ? res.data : CFG.defaults;
		}, function () { return CFG.defaults; }).then(function (layout) {
			$grid.find('.fs-dash-loading').remove();
			layout.forEach(function (c) { mount(c); });
			if (!$grid.children('.fs-widget').length) {
				$grid.append($('<div class="fs-dash-empty"></div>').text(T('The dashboard is empty. Use Add widget to place some.')));
			}
		});
	});

	D.mount = mount;
	/* Rebuild a widget after it changed its own settings (outside a click handler of its body). */
	D.remountLater = function (inst) { setTimeout(function () { remount(inst); }, 0); };
	D.serialize = serialize;
	D.setEditing = setEditing;
	D.SIZES = SIZES;
})(jQuery, window, document, window.FS);
