/*
 * jquery-cookie-shim.js
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
 * js-cookie 3 no longer installs the old jquery.cookie plugin API. jquery-treegrid
 * (saveState, used by the disks widget and package pages) still calls
 * $.cookie(name) and $.cookie(name, value), so map those calls onto js-cookie.
 *
 * Loaded after jQuery and js.cookie.min.js.
 */

(function ($) {
	"use strict";

	if (!$ || $.cookie || !window.Cookies) {
		return;
	}

	$.cookie = function (name, value, options) {
		if (arguments.length < 2) {
			return window.Cookies.get(name);
		}
		if (value === null) {
			window.Cookies.remove(name, options);
			return true;
		}
		return window.Cookies.set(name, String(value), options);
	};

	$.removeCookie = function (name, options) {
		window.Cookies.remove(name, options);
		return true;
	};
})(window.jQuery);
