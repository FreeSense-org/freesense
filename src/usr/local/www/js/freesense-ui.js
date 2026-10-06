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
 *   [data-fs-copy]     copy an element's text to the clipboard
 */

(function () {
	"use strict";

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
			var modal = window.bootstrap.Modal.getOrCreateInstance(el);

			function onOk() {
				confirmed = true;
				modal.hide();
			}
			function onShown() {
				cancel.focus();
			}
			function onHidden() {
				ok.removeEventListener('click', onOk);
				el.removeEventListener('shown.bs.modal', onShown);
				el.removeEventListener('hidden.bs.modal', onHidden);
				if (opts.returnFocus && opts.returnFocus.focus) {
					opts.returnFocus.focus();
				}
				resolve(confirmed);
			}

			ok.addEventListener('click', onOk);
			el.addEventListener('shown.bs.modal', onShown);
			el.addEventListener('hidden.bs.modal', onHidden);
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
				if (!tr.classList.contains('fs-empty') && !tr.hasAttribute('data-fs-static')) {
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
		document.querySelectorAll('table[data-sortable] th').forEach(function (th) {
			new MutationObserver(function () {
				var dir = th.getAttribute('data-sorted') === 'true' ? th.getAttribute('data-sorted-direction') : null;
				if (dir === 'ascending' || dir === 'descending') {
					th.setAttribute('aria-sort', dir);
				} else {
					th.removeAttribute('aria-sort');
				}
			}).observe(th, {attributes: true, attributeFilter: ['data-sorted', 'data-sorted-direction']});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
