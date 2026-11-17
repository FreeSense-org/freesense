/*
 * quick-actions.js — dashboard widget "Quick actions"
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 *
 * Shortcuts to common pages. The pages check their own privileges.
 */
(function ($, FS, D) {
	'use strict';

	var T = FS.t, el = D.el;
	var LINKS = [['Rules', 'fa-shield', '/firewall_rules.php'], ['Logs', 'fa-scroll', '/status_logs_filter.php'],
		['Interfaces', 'fa-network-wired', '/status_interfaces.php'], ['States', 'fa-table-cells', '/diag_dump_states.php'],
		['Services', 'fa-gears', '/status_services.php'], ['Backup', 'fa-download', '/diag_backup.php'],
		['Updates', 'fa-cloud-arrow-down', '/pkg_mgr_install.php?id=firmware'], ['Diagnostics', 'fa-stethoscope', '/diag_ping.php']];

	D.define('quick-actions', {
		mount: function (w) {
			var $g = el('div', 'fs-qa-grid');
			LINKS.forEach(function (l) {
				$g.append($('<a class="fs-qa"></a>').attr('href', l[2]).append($('<i class="fa-solid" aria-hidden="true"></i>').addClass(l[1]), el('span', '', T(l[0]))));
			});
			w.$body.append($g);
		},
		load: function () { return Promise.resolve(); }
	});
})(jQuery, window.FS, window.FSDashboard);
