/*
 * freesense-bs5-compat.js
 *
 * part of FreeSense (https://www.freesense.org)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 */

/*
 * Bootstrap 3 -> Bootstrap 5 compatibility shim.
 *
 * The FreeSense GUI was written against Bootstrap 3 (markup in classes/Form/*,
 * 200+ pages and 29 dashboard widgets). Bootstrap 5 removed the jQuery plugin
 * interface and renamed the data-api attributes. This shim bridges the gap so
 * legacy markup keeps working while pages are migrated to native BS5 one at a
 * time:
 *
 *   1. A jQuery plugin bridge so $(el).modal('show') / .collapse('toggle') /
 *      .tab('show') / .tooltip() / .popover() / .dropdown() call the vanilla
 *      Bootstrap 5 API.
 *   2. Rewrites BS3 data-* attributes to BS5 data-bs-* at load so the BS5
 *      delegated click handlers fire (data-toggle -> data-bs-toggle, etc.).
 *   3. Converts the BS3 "shown collapse" class .in to the BS5 .show.
 *
 * Loaded AFTER jquery + bootstrap.bundle and BEFORE FreeSense.js so the bridge
 * is defined before any ready-handler that uses it runs.
 */

(function ($) {
	"use strict";

	if (typeof window.bootstrap === "undefined") {
		// Bundle failed to load; nothing we can do.
		return;
	}

	var bs = window.bootstrap;

	/* ---- jQuery plugin bridge -------------------------------------------- */
	if ($ && $.fn) {
		// Generic bridge: $(el).plugin('command') or $(el).plugin({options})
		function makeBridge(Ctor, defaults) {
			return function (arg, extra) {
				return this.each(function () {
					var opts = (arg && typeof arg === "object") ? arg : (defaults || {});
					var inst = Ctor.getOrCreateInstance(this, opts);
					if (typeof arg === "string" && typeof inst[arg] === "function") {
						inst[arg](extra);
					}
				});
			};
		}

		// Collapse must NOT auto-toggle when instantiated programmatically.
		$.fn.collapse = function (arg) {
			return this.each(function () {
				var opts = (arg && typeof arg === "object") ? arg : { toggle: false };
				var inst = bs.Collapse.getOrCreateInstance(this, opts);
				if (typeof arg === "string" && typeof inst[arg] === "function") {
					inst[arg]();
				}
			});
		};

		// In BS3, $(el).modal() (no/object arg) also SHOWS the modal.
		$.fn.modal = function (arg) {
			return this.each(function () {
				var opts = (arg && typeof arg === "object") ? arg : {};
				var inst = bs.Modal.getOrCreateInstance(this, opts);
				if (typeof arg === "string") {
					if (typeof inst[arg] === "function") { inst[arg](); }
				} else {
					inst.show();
				}
			});
		};

		$.fn.tab     = makeBridge(bs.Tab);
		$.fn.tooltip = makeBridge(bs.Tooltip);
		$.fn.popover = makeBridge(bs.Popover);
		$.fn.dropdown = makeBridge(bs.Dropdown);
	}

	/* ---- data-* -> data-bs-* + .in -> .show ------------------------------ */
	var ATTR_MAP = {
		"data-toggle":   "data-bs-toggle",
		"data-target":   "data-bs-target",
		"data-dismiss":  "data-bs-dismiss",
		"data-parent":   "data-bs-parent",
		"data-ride":     "data-bs-ride",
		"data-slide":    "data-bs-slide",
		"data-slide-to": "data-bs-slide-to",
		"data-backdrop": "data-bs-backdrop",
		"data-keyboard": "data-bs-keyboard",
		"data-spy":      "data-bs-spy"
	};

	function upgradeMarkup(root) {
		root = root || document;
		Object.keys(ATTR_MAP).forEach(function (oldAttr) {
			var bsAttr = ATTR_MAP[oldAttr];
			root.querySelectorAll("[" + oldAttr + "]").forEach(function (el) {
				if (!el.hasAttribute(bsAttr)) {
					el.setAttribute(bsAttr, el.getAttribute(oldAttr));
				}
			});
		});
		// BS3 tooltip text attr: BS5 reads title / data-bs-original-title
		root.querySelectorAll("[data-original-title]").forEach(function (el) {
			if (!el.hasAttribute("data-bs-original-title")) {
				el.setAttribute("data-bs-original-title", el.getAttribute("data-original-title"));
			}
			if (!el.hasAttribute("title")) {
				el.setAttribute("title", el.getAttribute("data-original-title"));
			}
		});
		// BS3 shown-collapse class .in -> BS5 .show
		root.querySelectorAll(".collapse.in").forEach(function (el) {
			el.classList.add("show");
			el.classList.remove("in");
		});
	}

	// Expose so dynamically-injected markup can be re-upgraded if needed.
	window.fsBs5Upgrade = upgradeMarkup;

	// Dynamic content: widgets and pkg pages inject markup via ajax AFTER the
	// initial pass, so their data-toggle/... attributes were never upgraded and
	// tooltips/collapses in refreshed fragments went dead. Re-run the upgrade
	// (scoped to the added subtree) whenever elements are inserted.
	function watchDynamicMarkup() {
		if (!window.MutationObserver || !document.body) { return; }
		var pending = [];
		var scheduled = false;
		var observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (m) {
				for (var i = 0; i < m.addedNodes.length; i++) {
					if (m.addedNodes[i].nodeType === 1) { pending.push(m.addedNodes[i]); }
				}
			});
			if (pending.length && !scheduled) {
				scheduled = true;
				requestAnimationFrame(function () {
					var batch = pending; pending = []; scheduled = false;
					batch.forEach(function (el) { upgradeMarkup(el); });
				});
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", function () {
			upgradeMarkup(document);
			watchDynamicMarkup();
		});
	} else {
		upgradeMarkup(document);
		watchDynamicMarkup();
	}
})(window.jQuery);
