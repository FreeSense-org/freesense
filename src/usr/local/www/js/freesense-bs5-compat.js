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
 * Bootstrap 5 jQuery plugin bridge.
 *
 * The BS3->BS5 markup sweep is complete: all data-* attributes are now data-bs-*
 * and no ".collapse.in" markers remain, so the old data-* rewriter and the
 * .in->.show converter (and their MutationObserver) have been removed.
 *
 * What remains is ONLY the jQuery plugin bridge so that jQuery-style Bootstrap
 * calls still hit the vanilla Bootstrap 5 API:
 *   $(el).modal('show') / .collapse('toggle') / .tab('show') / .tooltip() /
 *   .popover() / .dropdown()
 * These are still used by some package pages (snort/suricata/pfBlockerNG).
 * Once BS5-migration Phase 3 converts those calls to bootstrap.X.getOrCreate-
 * Instance(...), this file and its foot.inc include can be deleted outright.
 *
 * Loaded AFTER jquery + bootstrap.bundle and BEFORE FreeSense.js so the bridge
 * is defined before any ready-handler that uses it runs.
 */

(function ($) {
	"use strict";

	if (typeof window.bootstrap === "undefined" || !$ || !$.fn) {
		return;
	}

	var bs = window.bootstrap;

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

	$.fn.tab      = makeBridge(bs.Tab);
	$.fn.tooltip  = makeBridge(bs.Tooltip);
	$.fn.popover  = makeBridge(bs.Popover);
	$.fn.dropdown = makeBridge(bs.Dropdown);
})(window.jQuery);
