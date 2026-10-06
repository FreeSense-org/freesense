/*
 * freesense-ui.js
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * WebUI component behavior (docs/webui/03-components.md). Progressive
 * enhancement only: every page works without this file.
 *
 *   fsConfirm()        shared confirmation modal (#fs-confirm in foot.inc)
 *   [data-fs-confirm]  buttons / anchors that confirm before acting
 *   .fs-tabs           tabs that do not fit move into a "More" menu
 *   .fs-table          search, filters, count, no-results row, bulk selection
 *                      (FreeSenseUI.initTables(root) for lists loaded later)
 *   [data-fs-copy]     copy an element's text to the clipboard
 */

(function () {
	"use strict";

	/* translated strings from foot.inc (data-fs-i18n on this script tag) */
	var I18N = {};
	try {
		I18N = JSON.parse((document.currentScript && document.currentScript.getAttribute('data-fs-i18n')) || '{}');
	} catch (e) {
		I18N = {};
	}

	function fmt(format, values) {
		var i = 0;
		return String(format || '').replace(/%(?:(\d+)\$)?s/g, function (m, pos) {
			var v = pos ? values[parseInt(pos, 10) - 1] : values[i++];
			return (v === undefined) ? '' : String(v);
		});
	}

	/* ------------------------------------------------------------ confirm modal */

	/*
	 * fsConfirm({title, detail, action, returnFocus}) -> Promise<boolean>
	 * Cancel is the default (focused) button; Esc and the backdrop cancel.
	 */
	window.fsConfirm = function (opts) {
		opts = opts || {};
		var el = document.getElementById('fs-confirm');

		if (!el || !window.bootstrap || !window.bootstrap.Modal) {
			return Promise.resolve(window.confirm(opts.title || ''));
		}

		var title = el.querySelector('.modal-title');
		var detail = el.querySelector('.fs-confirm-detail');
		var ok = el.querySelector('[data-fs-confirm-ok]');
		var cancel = el.querySelector('.modal-footer [data-bs-dismiss="modal"]');

		title.textContent = opts.title || '';
		detail.textContent = opts.detail || '';
		detail.hidden = !opts.detail;
		ok.textContent = opts.action || ok.getAttribute('data-default-label');

		return new Promise(function (resolve) {
			var confirmed = false;
			var shown = false;
			var escPending = false;
			var modal = window.bootstrap.Modal.getOrCreateInstance(el);

			function onOk() {
				confirmed = true;
				modal.hide();
			}
			function onShown() {
				shown = true;
				if (escPending) {
					modal.hide();
					return;
				}
				cancel.focus();
			}
			/*
			 * Esc cancels from the moment the dialog opens. Bootstrap only
			 * listens on the modal itself and ignores keys during the fade-in,
			 * while focus is still on the trigger; listen on the document
			 * (capture) instead, and stop the key here so an underlying modal
			 * or list search does not react to it as well.
			 */
			function onKey(e) {
				if (e.key !== 'Escape' && e.key !== 'Esc') {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				if (confirmed) {
					return;    // already closing after Confirm
				}
				if (shown) {
					modal.hide();
				} else {
					escPending = true;
				}
			}
			function onHidden() {
				ok.removeEventListener('click', onOk);
				el.removeEventListener('shown.bs.modal', onShown);
				el.removeEventListener('hidden.bs.modal', onHidden);
				document.removeEventListener('keydown', onKey, true);
				if (opts.returnFocus && opts.returnFocus.focus) {
					opts.returnFocus.focus();
				}
				resolve(confirmed);
			}

			ok.addEventListener('click', onOk);
			el.addEventListener('shown.bs.modal', onShown);
			el.addEventListener('hidden.bs.modal', onHidden);
			document.addEventListener('keydown', onKey, true);
			modal.show();
		});
	};

	/*
	 * Buttons (and plain links) with data-fs-confirm ask first. Runs in the
	 * capture phase so no other click handler sees an unconfirmed click.
	 * usepost anchors are confirmed by interceptGET() in FreeSenseHelpers.js.
	 */
	document.addEventListener('click', function (e) {
		var el = e.target.closest ? e.target.closest('[data-fs-confirm]') : null;

		if (!el || el.hasAttribute('usepost') || el.disabled) {
			return;
		}
		if (el.getAttribute('data-fs-confirmed') === '1') {
			el.removeAttribute('data-fs-confirmed');
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		window.fsConfirm({
			title: el.getAttribute('data-fs-confirm'),
			detail: el.getAttribute('data-fs-confirm-detail'),
			action: el.getAttribute('data-fs-confirm-action') || (el.textContent || '').trim() || null,
			returnFocus: el
		}).then(function (yes) {
			if (!yes) {
				return;
			}
			if (el.tagName === 'A' && el.href) {
				window.location.href = el.href;
				return;
			}
			el.setAttribute('data-fs-confirmed', '1');
			el.click();
		});
	}, true);

	/*
	 * Submit buttons with data-fs-busy show a spinner and mark the form busy
	 * while the request runs (Tool pages). The button stays enabled so its
	 * name/value is still posted; a second submit is ignored.
	 */
	document.addEventListener('submit', function (e) {
		var btn = e.submitter;
		var form = e.target;

		if (!btn || !btn.hasAttribute('data-fs-busy')) {
			return;
		}
		if (form.getAttribute('aria-busy') === 'true') {
			e.preventDefault();
			return;
		}
		form.setAttribute('aria-busy', 'true');
		var icon = btn.querySelector('i');
		if (icon) {
			icon.setAttribute('data-fs-icon', icon.className);
			icon.className = 'fa-solid fa-spinner fa-spin icon-embed-btn';
		}
	});

	// restored from the back/forward cache: clear the busy state
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) {
			return;
		}
		document.querySelectorAll('form[aria-busy="true"]').forEach(function (f) {
			f.removeAttribute('aria-busy');
		});
		document.querySelectorAll('i[data-fs-icon]').forEach(function (i) {
			i.className = i.getAttribute('data-fs-icon');
			i.removeAttribute('data-fs-icon');
		});
	});

	/*
	 * Modal forms (fs_modal_form_begin/end): a trigger with data-fs-modal="#id"
	 * opens the modal, pre-fills fields from data-fs-fill (JSON, by field name)
	 * and can set the title with data-fs-modal-title. Fields not named in
	 * data-fs-fill are reset, so a modal never shows the previous row's values.
	 */
	function openModalForm(modal, trigger) {

		var form = modal.querySelector('form');
		var fill = {};
		try {
			fill = JSON.parse(trigger.getAttribute('data-fs-fill') || '{}');
		} catch (err) {
			fill = {};
		}
		if (form) {
			form.reset();
			Object.keys(fill).forEach(function (name) {
				var field = form.elements[name];
				if (field && field.type === 'checkbox') {
					field.checked = !!fill[name];
				} else if (field) {
					field.value = fill[name];
				}
			});
		}
		var title = trigger.getAttribute('data-fs-modal-title');
		var titleEl = modal.querySelector('.modal-title');
		if (title && titleEl) {
			if (!titleEl.hasAttribute('data-fs-default-title')) {
				titleEl.setAttribute('data-fs-default-title', titleEl.textContent);
			}
			titleEl.textContent = title;
		} else if (titleEl && titleEl.hasAttribute('data-fs-default-title')) {
			titleEl.textContent = titleEl.getAttribute('data-fs-default-title');
		}

		modal.addEventListener('shown.bs.modal', function onShown() {
			modal.removeEventListener('shown.bs.modal', onShown);
			var first = modal.querySelector('.modal-body input:not([type=hidden]):not([readonly]), .modal-body select, .modal-body textarea');
			if (first) {
				first.focus();
				if (first.select && first.type === 'text') {
					first.select();
				}
			}
		});
		window.bootstrap.Modal.getOrCreateInstance(modal).show();
	}

	document.addEventListener('click', function (e) {
		var trigger = e.target.closest ? e.target.closest('[data-fs-modal]') : null;
		if (!trigger || !window.bootstrap || !window.bootstrap.Modal) {
			return;
		}
		var modal = document.querySelector(trigger.getAttribute('data-fs-modal'));
		if (!modal) {
			return;
		}
		e.preventDefault();
		openModalForm(modal, trigger);
	});

	/* fs_modal_form_begin(..., $reopen): reopen with the posted values after a failed save */
	function reopenModalForm() {
		var modal = document.querySelector('.fs-modal-form[data-fs-open]');
		if (modal && window.bootstrap && window.bootstrap.Modal) {
			openModalForm(modal, modal);
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', reopenModalForm);
	} else {
		setTimeout(reopenModalForm, 0);
	}

	/* --------------------------------------------------------------------- tabs */

	function layoutTabs(nav) {
		var ul = nav.querySelector(':scope > ul');
		if (!ul) {
			return;
		}

		// restore any tabs moved into "More" by a previous layout
		var more = ul.querySelector(':scope > li.fs-tabs-more');
		if (more) {
			more.querySelectorAll('.dropdown-menu > li').forEach(function (li) {
				var a = li.querySelector('a');
				a.classList.remove('dropdown-item', 'active');
				li.classList.remove('fs-moved');
				ul.insertBefore(li, more);
			});
			more.remove();
		}
		nav.classList.remove('fs-tabs--measured');

		if (ul.scrollWidth <= ul.clientWidth + 1) {
			return;
		}

		more = document.createElement('li');
		more.className = 'dropdown fs-tabs-more';
		more.setAttribute('role', 'presentation');
		more.innerHTML = '<a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" role="button" aria-expanded="false"></a>' +
		    '<ul class="dropdown-menu dropdown-menu-end"></ul>';
		more.firstChild.textContent = nav.getAttribute('data-fs-more-label') || 'More';
		ul.appendChild(more);
		nav.classList.add('fs-tabs--measured');

		var menu = more.querySelector('.dropdown-menu');
		var items = Array.prototype.slice.call(ul.querySelectorAll(':scope > li:not(.fs-tabs-more)'));

		var overflows = function () {
			return more.getBoundingClientRect().right > ul.getBoundingClientRect().right + 1;
		};

		// move tabs from the end into "More" until the bar fits; the active tab stays visible
		for (var i = items.length - 1; i >= 0 && overflows(); i--) {
			if (items[i].classList.contains('active')) {
				continue;
			}
			var a = items[i].querySelector('a');
			a.classList.add('dropdown-item');
			items[i].classList.add('fs-moved');
			menu.insertBefore(items[i], menu.firstChild);
		}
		more.classList.toggle('fs-has-active', !!menu.querySelector('li.active'));
		if (!menu.children.length) {
			more.remove();
			nav.classList.remove('fs-tabs--measured');
		}
	}

	/* --------------------------------------------------------------- data table */

	function FsTable(root) {
		this.root = root;
		this.table = root.querySelector('table');
		this.toolbar = root.querySelector('.fs-toolbar');
		this.search = root.querySelector('[data-fs-search-input]');
		this.filters = Array.prototype.slice.call(root.querySelectorAll('[data-fs-filter]'));
		this.countEl = root.querySelector('[data-fs-count]');
		this.selAll = root.querySelector('[data-fs-select-all]');
		this.selCount = root.querySelector('[data-fs-selected-count]');

		if (!this.table) {
			return;
		}

		var head = this.table.tHead ? this.table.tHead.rows[this.table.tHead.rows.length - 1] : null;
		this.columns = head ? head.cells.length : 1;
		this.searchCols = [];
		if (head) {
			for (var i = 0; i < head.cells.length; i++) {
				if (head.cells[i].hasAttribute('data-fs-search')) {
					this.searchCols.push(i);
				}
			}
		}

		var self = this;
		var timer = null;

		if (this.search) {
			var q = new URLSearchParams(window.location.search).get('q');
			if (q) {
				this.search.value = q;
			}
			this.search.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					self.apply(true);
				}, 150);
			});
			this.search.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && self.search.value !== '') {
					e.preventDefault();
					self.search.value = '';
					self.apply(true);
				}
			});
		}
		this.filters.forEach(function (sel) {
			sel.addEventListener('change', function () {
				self.apply(false);
			});
		});

		if (this.selAll) {
			this.selAll.addEventListener('change', function () {
				self.rows().forEach(function (tr) {
					var cb = tr.querySelector('[data-fs-select]');
					if (cb && !tr.hidden && !cb.disabled) {
						cb.checked = self.selAll.checked;
					}
				});
				self.updateSelection();
			});
		}
		root.addEventListener('change', function (e) {
			if (e.target.matches && e.target.matches('[data-fs-select]')) {
				self.updateSelection();
			}
		});
		var clear = root.querySelector('[data-fs-clear-selection]');
		if (clear) {
			clear.addEventListener('click', function () {
				self.rows().forEach(function (tr) {
					var cb = tr.querySelector('[data-fs-select]');
					if (cb) {
						cb.checked = false;
					}
				});
				self.updateSelection();
			});
		}

		this.apply(false);
		this.updateSelection();
	}

	FsTable.prototype.rows = function () {
		var out = [];
		Array.prototype.forEach.call(this.table.tBodies, function (tb) {
			Array.prototype.forEach.call(tb.rows, function (tr) {
				// rule separators (filter.inc display_separator) are static like data-fs-static rows
				if (!tr.classList.contains('fs-empty') && !tr.classList.contains('separator') &&
				    !tr.hasAttribute('data-fs-static')) {
					out.push(tr);
				}
			});
		});
		return out;
	};

	FsTable.prototype.rowText = function (tr) {
		if (tr._fsText === undefined) {
			var cells = tr.cells;
			var parts = [];
			var cols = this.searchCols;
			for (var i = 0; i < cells.length; i++) {
				if (!cols.length || cols.indexOf(i) !== -1) {
					parts.push(cells[i].textContent);
				}
			}
			tr._fsText = parts.join(' ').replace(/\s+/g, ' ').toLowerCase();
		}
		return tr._fsText;
	};

	FsTable.prototype.apply = function (updateUrl) {
		var self = this;
		var q = this.search ? this.search.value.trim().toLowerCase() : '';
		var active = this.filters.filter(function (s) {
			return s.value !== '';
		});
		var rows = this.rows();
		var shown = 0;

		rows.forEach(function (tr) {
			var ok = (q === '') || (self.rowText(tr).indexOf(q) !== -1);
			for (var i = 0; ok && i < active.length; i++) {
				ok = (tr.getAttribute('data-fs-filter-' + active[i].getAttribute('data-fs-filter')) === active[i].value);
			}
			tr.hidden = !ok;
			if (!ok) {
				var cb = tr.querySelector('[data-fs-select]');
				if (cb) {
					cb.checked = false;
				}
			}
			shown += ok ? 1 : 0;
		});

		var filtered = (q !== '') || active.length > 0;
		var tb = this.toolbar;

		if (this.countEl && rows.length) {
			this.countEl.textContent = filtered
			    ? fmt(tb.getAttribute('data-fs-count-format'), [shown, rows.length])
			    : fmt(tb.getAttribute((rows.length === 1) ? 'data-fs-total-format-one' : 'data-fs-total-format'), [rows.length]);
		}

		var nr = this.table.querySelector('tr.fs-noresults');
		if (filtered && rows.length && !shown) {
			if (!nr) {
				nr = document.createElement('tr');
				nr.className = 'fs-empty fs-noresults';
				nr.innerHTML = '<td><span class="fs-empty-message"></span><button type="button" class="btn btn-sm btn-outline-secondary"></button></td>';
				nr.firstChild.colSpan = this.columns;
				nr.querySelector('button').addEventListener('click', function () {
					if (self.search) {
						self.search.value = '';
					}
					self.filters.forEach(function (s) {
						s.value = '';
					});
					self.apply(true);
					if (self.search) {
						self.search.focus();
					}
				});
				this.table.tBodies[this.table.tBodies.length - 1].appendChild(nr);
			}
			nr.querySelector('.fs-empty-message').textContent = (q !== '')
			    ? fmt(tb.getAttribute('data-fs-noresults'), [this.search.value.trim()])
			    : tb.getAttribute('data-fs-noresults-filtered');
			nr.querySelector('button').textContent = tb.getAttribute('data-fs-clear-label');
		} else if (nr) {
			nr.remove();
		}

		if (updateUrl && this.search && window.history.replaceState) {
			var url = new URL(window.location.href);
			if (this.search.value.trim() === '') {
				url.searchParams.delete('q');
			} else {
				url.searchParams.set('q', this.search.value.trim());
			}
			window.history.replaceState(window.history.state, '', url.toString());
		}

		this.updateSelection();
	};

	FsTable.prototype.updateSelection = function () {
		var visible = 0;
		var checked = 0;

		this.rows().forEach(function (tr) {
			var cb = tr.querySelector('[data-fs-select]');
			if (!cb) {
				return;
			}
			tr.classList.toggle('fs-selected', cb.checked);
			if (!tr.hidden) {
				visible++;
				checked += cb.checked ? 1 : 0;
			}
		});

		if (this.selAll) {
			this.selAll.checked = (visible > 0) && (checked === visible);
			this.selAll.indeterminate = (checked > 0) && (checked < visible);
		}
		this.root.classList.toggle('fs-has-selection', checked > 0);
		if (this.selCount && this.toolbar) {
			this.selCount.textContent = fmt(this.toolbar.getAttribute('data-fs-selected-format'), [checked]);
		}
	};

	/* --------------------------------------------------------------- navigation */

	function filterMegamenu(input) {
		var menu = input.closest('.fs-megamenu');
		var q = input.value.trim().toLowerCase();
		var any = false;

		menu.querySelectorAll('.fs-megamenu-group').forEach(function (group) {
			var shown = 0;
			group.querySelectorAll('li').forEach(function (li) {
				var ok = (q === '') || (li.textContent.toLowerCase().indexOf(q) !== -1);
				li.hidden = !ok;
				shown += ok ? 1 : 0;
			});
			group.hidden = (shown === 0);
			any = any || (shown > 0);
		});
		var empty = menu.querySelector('.fs-megamenu-empty');
		if (empty) {
			empty.hidden = any;
		}
	}

	function initNavigation() {
		document.addEventListener('input', function (e) {
			if (e.target.matches && e.target.matches('[data-fs-menu-filter]')) {
				filterMegamenu(e.target);
			}
		});

		// Enter in the filter opens the first visible item
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('[data-fs-menu-filter]')) {
				return;
			}
			var first = e.target.closest('.fs-megamenu').querySelector('.fs-megamenu-group:not([hidden]) li:not([hidden]) a');
			if (first) {
				e.preventDefault();
				first.click();
			}
		});

		document.addEventListener('shown.bs.dropdown', function (e) {
			var menu = e.target.nextElementSibling;
			if (!menu || !menu.classList.contains('fs-megamenu')) {
				return;
			}
			// keep the wide panel inside the viewport
			menu.style.left = '';
			if (window.innerWidth >= 992) {
				var r = menu.getBoundingClientRect();
				var over = r.right - (document.documentElement.clientWidth - 16);
				if (over > 0) {
					menu.style.left = (-over) + 'px';
				}
			}
			var input = menu.querySelector('[data-fs-menu-filter]');
			if (input && window.matchMedia('(pointer: fine)').matches) {
				input.focus();
			}
		});

		document.addEventListener('hidden.bs.dropdown', function (e) {
			var input = e.target.nextElementSibling ? e.target.nextElementSibling.querySelector('[data-fs-menu-filter]') : null;
			if (input && input.value !== '') {
				input.value = '';
				filterMegamenu(input);
			}
		});
	}

	/* command palette: search every menu (Ctrl+K or the navbar search button) */
	var palette = null;

	function paletteEntries() {
		var out = [];
		document.querySelectorAll('#topmenu .navbar-nav > .nav-item > a.navlnk').forEach(function (a) {
			var label = a.textContent.trim();
			out.push({link: a, label: label, path: '', text: label.toLowerCase()});
		});
		document.querySelectorAll('#topmenu .nav-item.dropdown').forEach(function (li) {
			var toggle = li.querySelector(':scope > .dropdown-toggle');
			var menuName = toggle ? toggle.textContent.trim() : '';
			li.querySelectorAll('.dropdown-menu a.navlnk').forEach(function (a) {
				var section = a.closest('.fs-megamenu-group');
				var group = section ? section.querySelector('.fs-megamenu-group-title').textContent.trim() : '';
				var label = a.textContent.trim();
				out.push({
					link: a,
					label: label,
					path: group ? menuName + ' › ' + group : menuName,
					text: (label + ' ' + menuName + ' ' + group).toLowerCase()
				});
			});
		});
		return out;
	}

	function buildPalette() {
		var el = document.createElement('div');
		el.className = 'modal';    // no fade: typing right after Ctrl+K must reach the input
		el.id = 'fs-palette';
		el.tabIndex = -1;
		el.setAttribute('aria-label', 'Search menus');
		el.innerHTML = '<div class="modal-dialog"><div class="modal-content">' +
		    '<div class="position-relative"><i class="fa-solid fa-magnifying-glass fs-palette-icon" aria-hidden="true"></i>' +
		    '<input type="search" class="form-control fs-palette-input" autocomplete="off" role="combobox"' +
		    ' aria-expanded="true" aria-controls="fs-palette-list" aria-autocomplete="list"></div>' +
		    '<ul class="fs-palette-list" id="fs-palette-list" role="listbox"></ul>' +
		    '<div class="fs-palette-none" hidden></div></div></div>';
		document.body.appendChild(el);

		var btn = document.querySelector('[data-fs-palette-open]');
		var input = el.querySelector('input');
		var list = el.querySelector('ul');
		var none = el.querySelector('.fs-palette-none');
		input.placeholder = btn ? btn.getAttribute('aria-label') + '…' : 'Search menus…';
		none.textContent = (btn && btn.getAttribute('data-fs-palette-empty')) || 'No matching pages.';

		var entries = [];
		var shown = [];
		var sel = 0;

		function render() {
			var words = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
			var q = words.join(' ');
			var rank = function (en) {
				var l = en.label.toLowerCase();
				return (l.indexOf(q) === 0) ? 0 : ((l.indexOf(q) !== -1) ? 1 : 2);
			};
			shown = entries.filter(function (en) {
				return words.every(function (w) { return en.text.indexOf(w) !== -1; });
			});
			if (q !== '') {
				// stable: label prefix, then label match, then path match; menu order within each
				shown = shown.map(function (en, i) { return [rank(en), i, en]; })
				    .sort(function (a, b) { return (a[0] - b[0]) || (a[1] - b[1]); })
				    .map(function (x) { return x[2]; });
			}
			shown = shown.slice(0, 50);
			sel = 0;
			list.innerHTML = '';
			shown.forEach(function (en, i) {
				var li = document.createElement('li');
				var a = document.createElement('a');
				a.href = en.link.getAttribute('href');
				a.id = 'fs-palette-opt-' + i;
				a.setAttribute('role', 'option');
				a.innerHTML = '<span></span><span class="fs-palette-path"></span>';
				a.firstChild.textContent = en.label;
				a.lastChild.textContent = en.path;
				a.addEventListener('click', function (ev) {
					ev.preventDefault();
					go(i);
				});
				li.appendChild(a);
				list.appendChild(li);
			});
			none.hidden = shown.length > 0;
			mark();
		}

		function mark() {
			list.querySelectorAll('a').forEach(function (a, i) {
				a.setAttribute('aria-selected', i === sel ? 'true' : 'false');
				if (i === sel) {
					a.scrollIntoView({block: 'nearest'});
					input.setAttribute('aria-activedescendant', a.id);
				}
			});
		}

		function go(i) {
			var en = shown[i];
			if (!en) {
				return;
			}
			window.bootstrap.Modal.getInstance(el).hide();
			en.link.click();    // keeps usepost (Logout) and target (Help) behavior
		}

		input.addEventListener('input', render);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (shown.length) {
					sel = (sel + (e.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length;
					mark();
				}
			} else if (e.key === 'Enter') {
				e.preventDefault();
				go(sel);
			}
		});
		el.addEventListener('show.bs.modal', function () {
			entries = paletteEntries();
			input.value = '';
			render();
		});
		el.addEventListener('shown.bs.modal', function () {
			input.focus();
		});

		return el;
	}

	function openPalette() {
		if (!window.bootstrap || !window.bootstrap.Modal || !document.getElementById('topmenu')) {
			return;
		}
		palette = palette || buildPalette();
		window.bootstrap.Modal.getOrCreateInstance(palette).show();
	}

	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
			e.preventDefault();
			openPalette();
		}
	});
	document.addEventListener('click', function (e) {
		if (e.target.closest && e.target.closest('[data-fs-palette-open]')) {
			e.preventDefault();
			openPalette();
		}
	});

	/* --------------------------------------------------------------- entry grid */

	/*
	 * Repeatable Form groups (.form-group.repeatable, FreeSenseHelpers.js add_row /
	 * delete_row) shown as a grid (docs/webui/03-components.md "Entry grid"):
	 * one header row built from the column help titles (or placeholders), per-row
	 * help hidden and its explanations shown once under the grid, an icon-only
	 * delete button and the Add button directly under the rows. The rows, field
	 * names and the add/delete code are unchanged.
	 */
	/*
	 * Split a column's help at its first <br>: the text before it is the column
	 * title, the nodes after it (cloned, links kept) are the explanation.
	 */
	function splitHelp(help) {
		var title = '';
		var rest = null;
		Array.prototype.forEach.call(help.childNodes, function (node) {
			if (rest) {
				rest.appendChild(node.cloneNode(true));
			} else if (node.nodeName === 'BR') {
				rest = document.createDocumentFragment();
			} else {
				title += node.textContent;
			}
		});
		return {title: title.replace(/\s+/g, ' ').trim(), rest: rest};
	}

	function cellTitle(cell) {
		var help = cell.querySelector('.help-block');
		if (help) {
			var first = splitHelp(help).title;
			if (first && first.length <= 40) {
				return first;
			}
		}
		var field = cell.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
		return (field && field.getAttribute('placeholder')) ? field.getAttribute('placeholder') : '';
	}

	/* {title, nodes} for the note under the grid, or null */
	function cellNote(cell) {
		var help = cell.querySelector('.help-block');
		if (!help) {
			return null;
		}
		var parts = splitHelp(help);
		if (parts.rest && parts.title.length <= 40) {
			return parts.rest.textContent.trim() ? {title: parts.title, nodes: parts.rest} : null;
		}
		if (parts.title.length > 40) {
			var all = document.createDocumentFragment();
			Array.prototype.forEach.call(help.childNodes, function (node) {
				all.appendChild(node.cloneNode(true));
			});
			return {title: '', nodes: all};
		}
		return null;
	}

	function cellHidden(cell) {
		return cell.classList.contains('hidden') || window.getComputedStyle(cell).display === 'none';
	}

	function initEntryGrid() {
		var rows = document.querySelectorAll('.form-group.repeatable');
		if (!rows.length) {
			return;
		}
		var first = rows[0];
		var last = rows[rows.length - 1];
		var label = first.querySelector(':scope > label');
		var cells = Array.prototype.filter.call(first.children, function (c) {
			return c.tagName === 'DIV';
		});
		/* help titles live on the last row (or on every row with retainhelp) */
		var lastCells = Array.prototype.filter.call(last.children, function (c) {
			return c.tagName === 'DIV';
		});

		var head = document.createElement('div');
		head.className = 'form-group fs-entrygrid-head';
		head.setAttribute('aria-hidden', 'true');
		var headLabel = document.createElement('div');
		/* the row label stays on the first row: pages rewrite it (firewall_aliases_edit.php) */
		headLabel.className = label ? label.className.replace('control-label', '').trim() : 'col-sm-2';
		head.appendChild(headLabel);
		var any = false;
		var notes = [];
		var fromPlaceholder = [];
		cells.forEach(function (cell, i) {
			var h = document.createElement('div');
			h.className = cell.className;
			h.classList.toggle('hidden', cellHidden(cell));
			var src = lastCells[i] || cell;
			if (!cell.querySelector('[id^=deleterow]')) {
				h.textContent = cellTitle(src) || cellTitle(cell);
				fromPlaceholder[i] = !src.querySelector('.help-block');
				any = any || (h.textContent !== '');
				var note = cellNote(src);
				if (note) {
					notes.push(note);
				}
			}
			head.appendChild(h);
		});
		if (!any) {
			return;
		}
		first.parentNode.insertBefore(head, first);
		rows.forEach(function (r) {
			r.classList.add('fs-entrygrid-row');
		});

		/* column visibility and placeholder titles follow the page (alias type, IPsec PRF) */
		function syncHead() {
			var row = document.querySelector('.form-group.repeatable');
			if (!row) {
				return;
			}
			var now = Array.prototype.filter.call(row.children, function (c) {
				return c.tagName === 'DIV';
			});
			now.forEach(function (cell, i) {
				var h = head.children[i + 1];
				if (!h) {
					return;
				}
				h.classList.toggle('hidden', cellHidden(cell));
				if (fromPlaceholder[i]) {
					h.textContent = cellTitle(cell);
				}
			});
		}
		var form = first.closest('form');
		if (form) {
			form.addEventListener('change', function () {
				setTimeout(syncHead, 0);
			});
			form.addEventListener('click', function () {
				setTimeout(syncHead, 0);
			});
		}
		/* pages adjust their rows in their own ready handlers */
		window.addEventListener('load', function () {
			setTimeout(syncHead, 0);
		});

		/* addrow, or a page's own name for it (vpn_ipsec_phase1.php: algoaddrow) */
		var section = first.closest('.panel') || document;
		var add = section.querySelector('button[id$=addrow]') || document.querySelector('[id^=addrow]');
		var anchor = last;
		if (add) {
			var addGroup = add.closest('.form-group');
			if (!addGroup || addGroup.classList.contains('repeatable') || addGroup.parentNode !== last.parentNode) {
				addGroup = document.createElement('div');
				addGroup.className = 'form-group';
				var spacer = document.createElement('div');
				spacer.className = headLabel.className;
				var col = document.createElement('div');
				col.className = 'col-sm-10';
				addGroup.appendChild(spacer);
				addGroup.appendChild(col);
				col.appendChild(add);
				last.parentNode.insertBefore(addGroup, last.nextSibling);
			} else {
				var addLabel = addGroup.querySelector(':scope > label');
				if (addLabel) {
					addLabel.textContent = '';
				}
				if (addGroup.previousElementSibling !== last) {
					last.parentNode.insertBefore(addGroup, last.nextSibling);
				}
			}
			/* restyled in CSS: pages find the button by .btn-success / .addbtn */
			addGroup.classList.add('fs-entrygrid-add');
			anchor = addGroup;
		}

		if (notes.length) {
			var noteGroup = document.createElement('div');
			noteGroup.className = 'form-group fs-entrygrid-notes';
			var nSpacer = document.createElement('div');
			nSpacer.className = headLabel.className;
			var nCol = document.createElement('div');
			nCol.className = 'col-sm-10';
			notes.forEach(function (n) {
				var p = document.createElement('p');
				if (n.title) {
					var strong = document.createElement('strong');
					strong.textContent = n.title + ':';
					p.appendChild(strong);
					p.appendChild(document.createTextNode(' '));
				}
				p.appendChild(n.nodes);
				nCol.appendChild(p);
			});
			noteGroup.appendChild(nSpacer);
			noteGroup.appendChild(nCol);
			anchor.parentNode.insertBefore(noteGroup, anchor.nextSibling);
		}

		if (rows.length > ENTRYGRID_FILTER_MIN) {
			initEntryGridFilter(head, headLabel.className);
		}
	}

	var ENTRYGRID_FILTER_MIN = 20;

	/*
	 * Long grids (aliases with hundreds of entries) get a filter above the header.
	 * Rows are only hidden, never removed, so the page still posts every row.
	 * Empty rows (just added) always stay visible.
	 */
	function initEntryGridFilter(head, labelClass) {
		var group = document.createElement('div');
		group.className = 'form-group fs-entrygrid-filter';
		var spacer = document.createElement('div');
		spacer.className = labelClass;
		var col = document.createElement('div');
		col.className = 'col-sm-10';
		var input = document.createElement('input');
		input.type = 'search';
		input.className = 'form-control';
		input.placeholder = I18N.filterEntries || 'Filter entries';
		input.setAttribute('aria-label', input.placeholder);
		input.setAttribute('data-fs-entrygrid-filter', '');
		var count = document.createElement('span');
		count.className = 'fs-entrygrid-count';
		count.setAttribute('aria-live', 'polite');
		col.appendChild(input);
		col.appendChild(count);
		group.appendChild(spacer);
		group.appendChild(col);
		head.parentNode.insertBefore(group, head);

		function rowText(row) {
			return Array.prototype.map.call(row.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), select, textarea'), function (el) {
				if (el.tagName === 'SELECT') {
					return Array.prototype.map.call(el.selectedOptions, function (o) {
						return o.textContent;
					}).join(' ');
				}
				return el.value;
			}).join(' ').trim();
		}
		/* a fresh row: its text fields are empty (selects such as a subnet mask keep a default) */
		function isBlank(row) {
			return Array.prototype.every.call(row.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), textarea'), function (el) {
				return el.value === '';
			});
		}
		function apply() {
			var q = input.value.trim().toLowerCase();
			var all = document.querySelectorAll('.form-group.repeatable');
			var shown = 0;
			all.forEach(function (row) {
				var show = !q || isBlank(row) || rowText(row).toLowerCase().indexOf(q) !== -1;
				row.classList.toggle('fs-entrygrid-filtered', !show);
				if (show) {
					shown++;
				}
			});
			count.textContent = q ? (I18N.entriesShown || '%1$s of %2$s shown').replace('%1$s', shown).replace('%2$s', all.length) : '';
		}
		input.addEventListener('input', apply);
		/* Enter must not submit the page's form */
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
			}
		});
		var form = input.closest('form');
		if (form) {
			form.addEventListener('click', function (e) {
				var btn = input.value && e.target.closest ? e.target.closest('[id$=addrow], [id^=deleterow]') : null;
				if (btn) {
					setTimeout(function () {
						apply();
						/* a row the user just added stays in view, whatever the filter */
						if (/addrow$/.test(btn.id)) {
							var all = document.querySelectorAll('.form-group.repeatable');
							all[all.length - 1].classList.remove('fs-entrygrid-filtered');
						}
					}, 0);
				}
			});
		}
	}

	/* ------------------------------------------------------------ advanced toggles */

	/*
	 * The pages' "Display Advanced" / "Hide Advanced" buttons (btn-info with a gear
	 * icon; each page shows / hides its own fields and flips the text) become quiet
	 * disclosure buttons with a chevron and aria-expanded. Open = the page has set
	 * a translated "Hide Advanced (Options)" text.
	 */
	function initAdvancedToggles() {
		var hide = [].concat(I18N.hideAdvanced || ['Hide Advanced', 'Hide Advanced Options']).map(function (t) {
			return String(t).trim();
		});
		document.querySelectorAll('.fs-main button.btn-info').forEach(function (btn) {
			var icon = btn.querySelector('i.fa-gear');
			if (!icon) {
				return;
			}
			btn.classList.remove('btn-info');
			btn.classList.add('fs-disclosure');
			icon.classList.remove('fa-gear');
			icon.classList.add('fa-chevron-right');
			function sync() {
				var open = hide.indexOf(btn.textContent.trim()) !== -1;
				btn.classList.toggle('is-open', open);
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			}
			sync();
			new MutationObserver(sync).observe(btn, {childList: true, characterData: true, subtree: true});
		});
	}

	/* ---------------------------------------------------------- searchable checklist */

	/*
	 * <select multiple data-fs-checklist> shown as a searchable checklist grouped by
	 * the label prefix before " - " (privileges: "WebCfg - …", "REST API - …").
	 * Ticking an item selects its option, so the form posts exactly as before.
	 * Optional: data-fs-descs (JSON value → description), data-fs-warn (JSON list
	 * of values flagged as administrator-level).
	 */
	function initChecklist(select) {
		var descs = {};
		var warn = [];
		try {
			descs = JSON.parse(select.getAttribute('data-fs-descs') || '{}');
			warn = JSON.parse(select.getAttribute('data-fs-warn') || '[]');
		} catch (e) {
			descs = {};
			warn = [];
		}
		var t = function (key, fallback) {
			return select.getAttribute('data-fs-text-' + key) || fallback;
		};

		var root = document.createElement('div');
		root.className = 'fs-checklist';
		root.innerHTML =
		    '<div class="fs-checklist-toolbar">' +
		    '<div class="fs-checklist-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>' +
		    '<input type="search" class="form-control form-control-sm" autocomplete="off"></div>' +
		    '<label class="fs-checklist-only"><input type="checkbox" class="form-check-input"> <span></span></label>' +
		    '<span class="fs-checklist-count" aria-live="polite"></span></div>' +
		    '<div class="fs-checklist-list" role="group"></div>' +
		    '<div class="fs-checklist-empty" hidden></div>';
		var search = root.querySelector('input[type=search]');
		var only = root.querySelector('.fs-checklist-only input');
		var count = root.querySelector('.fs-checklist-count');
		var list = root.querySelector('.fs-checklist-list');
		var empty = root.querySelector('.fs-checklist-empty');
		search.setAttribute('placeholder', t('search', 'Search…'));
		search.setAttribute('aria-label', t('search', 'Search…'));
		root.querySelector('.fs-checklist-only span').textContent = t('only', 'Selected only');
		empty.textContent = t('empty', 'Nothing matches the search.');
		list.setAttribute('aria-label', select.getAttribute('aria-label') || t('search', ''));

		var groups = {};
		var items = [];
		Array.prototype.forEach.call(select.options, function (opt, i) {
			var label = opt.text;
			var cut = label.indexOf(' - ');
			var group = (cut > 0) ? label.slice(0, cut) : '';
			var name = (cut > 0) ? label.slice(cut + 3) : label;
			if (!groups[group]) {
				var g = document.createElement('div');
				g.className = 'fs-checklist-group';
				if (group) {
					var h = document.createElement('div');
					h.className = 'fs-checklist-group-title';
					h.textContent = group;
					g.appendChild(h);
				}
				list.appendChild(g);
				groups[group] = g;
			}
			var id = (select.id || 'fs-checklist') + '-item-' + i;
			var row = document.createElement('div');
			row.className = 'fs-checklist-item form-check';
			var box = document.createElement('input');
			box.type = 'checkbox';
			box.className = 'form-check-input';
			box.id = id;
			box.checked = opt.selected;
			var lab = document.createElement('label');
			lab.className = 'form-check-label';
			lab.htmlFor = id;
			var strong = document.createElement('span');
			strong.className = 'fs-checklist-name';
			strong.textContent = name;
			lab.appendChild(strong);
			if (warn.indexOf(opt.value) !== -1) {
				var b = document.createElement('span');
				b.className = 'fs-badge fs-badge--warn';
				b.textContent = t('warn', 'Admin-level');
				lab.appendChild(document.createTextNode(' '));
				lab.appendChild(b);
			}
			if (descs[opt.value]) {
				var d = document.createElement('span');
				d.className = 'fs-checklist-desc';
				d.textContent = descs[opt.value];
				lab.appendChild(d);
			}
			box.addEventListener('change', function () {
				opt.selected = box.checked;
				update();
			});
			row.appendChild(box);
			row.appendChild(lab);
			groups[group].appendChild(row);
			items.push({row: row, box: box, text: (label + ' ' + (descs[opt.value] || '')).toLowerCase()});
		});

		function update() {
			var q = search.value.trim().toLowerCase();
			var shown = 0;
			var selected = 0;
			items.forEach(function (it) {
				var show = (!q || it.text.indexOf(q) !== -1) && (!only.checked || it.box.checked);
				it.row.hidden = !show;
				shown += show ? 1 : 0;
				selected += it.box.checked ? 1 : 0;
			});
			Object.keys(groups).forEach(function (k) {
				groups[k].hidden = !groups[k].querySelector('.fs-checklist-item:not([hidden])');
			});
			empty.hidden = (shown !== 0);
			count.textContent = t('count', '%d selected').replace('%d', selected);
		}
		search.addEventListener('input', update);
		search.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
			}
		});
		only.addEventListener('change', update);

		select.hidden = true;
		select.parentNode.insertBefore(root, select.nextSibling);
		update();
	}

	/* ---------------------------------------------------------------- utilities */

	function copyText(btn) {
		var src = document.querySelector(btn.getAttribute('data-fs-copy'));
		if (!src || !navigator.clipboard) {
			return;
		}
		navigator.clipboard.writeText(src.textContent).then(function () {
			var icon = btn.querySelector('i');
			if (!icon) {
				return;
			}
			var old = icon.className;
			icon.className = 'fa-solid fa-check icon-embed-btn';
			setTimeout(function () {
				icon.className = old;
			}, 1200);
		});
	}

	function init() {
		initNavigation();
		initEntryGrid();
		initAdvancedToggles();

		// a field the browser rejects inside a closed section: open the section so it can be shown
		document.addEventListener('invalid', function (e) {
			var body = e.target.closest ? e.target.closest('.panel-body.collapse:not(.show)') : null;
			if (body && window.bootstrap && window.bootstrap.Collapse) {
				window.bootstrap.Collapse.getOrCreateInstance(body, {toggle: false}).show();
			}
		}, true);
		document.querySelectorAll('select[multiple][data-fs-checklist]').forEach(initChecklist);

		// icon-only header links: give them an accessible name
		document.querySelectorAll('.context-links a[title]:not([aria-label])').forEach(function (a) {
			a.setAttribute('aria-label', a.getAttribute('title'));
		});
		document.querySelectorAll('.context-links a > i[title]').forEach(function (i) {
			if (!i.parentNode.hasAttribute('aria-label')) {
				i.parentNode.setAttribute('aria-label', i.getAttribute('title'));
			}
		});

		var navs = document.querySelectorAll('nav.fs-tabs');
		navs.forEach(layoutTabs);
		var rt = null;
		window.addEventListener('resize', function () {
			clearTimeout(rt);
			rt = setTimeout(function () {
				navs.forEach(layoutTabs);
			}, 120);
		});

		document.querySelectorAll('.fs-table').forEach(function (root) {
			root._fsTable = new FsTable(root);
		});

		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-fs-copy]') : null;
			if (btn) {
				e.preventDefault();
				copyText(btn);
			}
		});

		// "/" focuses the first list search, unless the user is typing
		document.addEventListener('keydown', function (e) {
			if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) {
				return;
			}
			var t = e.target;
			if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) {
				return;
			}
			var s = document.querySelector('.fs-table [data-fs-search-input]');
			if (s) {
				e.preventDefault();
				s.focus();
			}
		});

		// keep aria-sort in step with sortable.js (data-sorted-direction on th)
		document.querySelectorAll('table[data-sortable] th').forEach(watchSortHeader);
	}

	function watchSortHeader(th) {
		if (th._fsSortWatch) {
			return;
		}
		th._fsSortWatch = true;
		new MutationObserver(function () {
			var dir = th.getAttribute('data-sorted') === 'true' ? th.getAttribute('data-sorted-direction') : null;
			if (dir === 'ascending' || dir === 'descending') {
				th.setAttribute('aria-sort', dir);
			} else {
				th.removeAttribute('aria-sort');
			}
		}).observe(th, {attributes: true, attributeFilter: ['data-sorted', 'data-sorted-direction']});
	}

	/*
	 * Public re-init hook for lists whose <table> is inserted after page load
	 * (e.g. loaded over AJAX into a .fs-table card): enhances every .fs-table
	 * in root (or root itself) that has a table and is not enhanced yet, and
	 * makes a data-sortable table sortable. Safe to call more than once.
	 *   $('#pkgtbl').html(data); FreeSenseUI.initTables(document.getElementById('pkg-list'));
	 */
	function initTables(root) {
		root = root || document;
		var roots = root.querySelectorAll ? Array.prototype.slice.call(root.querySelectorAll('.fs-table')) : [];
		if (root.classList && root.classList.contains('fs-table')) {
			roots.unshift(root);
		}
		roots.forEach(function (el) {
			var table = el.querySelector('table');
			if (!table || (el._fsTable && el._fsTable.table)) {
				return;
			}
			if (table.hasAttribute('data-sortable')) {
				if (window.Sortable && window.Sortable.initTable) {
					window.Sortable.initTable(table);
				}
				table.querySelectorAll('th').forEach(watchSortHeader);
			}
			el._fsTable = new FsTable(el);
		});
	}

	window.FreeSenseUI = window.FreeSenseUI || {};
	window.FreeSenseUI.initTables = initTables;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
