<?php
/*
 * status_filter_reload.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

##|+PRIV
##|*IDENT=page-status-filterreloadstatus
##|*NAME=Status: Filter Reload Status
##|*DESCR=Allow access to the 'Status: Filter Reload Status' page.
##|*MATCH=status_filter_reload.php*
##|-PRIV

require_once("globals.inc");
require_once("guiconfig.inc");
require_once("functions.inc");

$pgtitle = array(gettext("Status"), gettext("Filter Reload"));
$shortcut_section = "firewall";

if (file_exists("{$g['varrun_path']}/filter_reload_status")) {
	$status = file_get_contents("{$g['varrun_path']}/filter_reload_status");
}

if ($_REQUEST['getstatus']) {
	echo "|" . htmlspecialchars($status) . "|";
	exit;
}
if ($_POST['reloadfilter']) {
	send_event("filter reload");
	header("Location: status_filter_reload.php");
	exit;
}
if ($_POST['syncfilter']) {
	send_event("filter sync");
	header("Location: status_filter_reload.php");
	exit;
}

/* Reload filter / Force config sync post back here (usepost), like the old form buttons */
fs_page_action(gettext('Reload filter'), '/status_filter_reload.php?reloadfilter=' . rawurlencode(gettext("Reload Filter")), 'fa-arrows-rotate', 'primary', ['usepost' => true]);
if (!empty(config_get_path('hasync/synchronizetoip'))) {
	fs_page_action(gettext('Force config sync'), '/status_filter_reload.php?syncfilter=' . rawurlencode(gettext("Force Config Sync")), 'fa-regular fa-clone', 'secondary', ['usepost' => true]);
}

$watch = empty($_REQUEST['user']);
$done = (substr((string)$status, -5) === "Done\n");

include("head.inc");
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Reload status")?></h2>
		<span id="fs-reload-running"<?=($watch && !$done) ? '' : ' hidden'?>><?=fs_badge('pending', gettext('Reloading'))?></span>
		<span id="fs-reload-done"<?=$done ? '' : ' hidden'?>><?=fs_badge('pass', gettext('Done'))?></span>
		<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#status">
			<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
		</button>
	</div>
	<pre class="fs-console" id="status" aria-live="polite"><?=($status !== null && $status !== '') ? htmlspecialchars($status) : gettext("Obtaining filter status...")?></pre>
	<div class="panel-footer small fs-muted" id="reloadinfo">
<?php if ($watch && !$done): ?>
		<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
		<?=gettext("This page refreshes the status every few seconds until the filter is done reloading.")?>
<?php else: ?>
		<?=gettext("Use Reload filter to rebuild and reload the firewall rules.")?>
<?php endif; ?>
		<span id="doneurl"<?=$done ? '' : ' hidden'?>> <a href="status_queues.php"><?=gettext("Queue status")?></a></span>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var out = document.getElementById('status');
	var info = document.getElementById('reloadinfo');

	/* the status endpoint returns "|<escaped text>|"; decode the entities without parsing HTML */
	function decode(text) {
		return text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&');
	}

	function setState(done) {
		document.getElementById('fs-reload-running').hidden = done;
		document.getElementById('fs-reload-done').hidden = !done;
	}

	function update() {
		fetch('status_filter_reload.php?getstatus=true', {credentials: 'same-origin'})
		    .then(function (r) { return r.text(); })
		    .then(function (text) {
			var parts = text.split('|');
			var result = parts.length > 1 ? decode(parts[1]) : '';
			out.textContent = result || <?=json_encode(gettext("Obtaining filter status..."))?>;
			if (result.endsWith("Done\n")) {
				setState(true);
				var spin = info.querySelector('.fa-spinner');
				if (spin) {
					spin.remove();
				}
				document.getElementById('doneurl').hidden = false;
			} else {
				setState(false);
				window.setTimeout(update, 1500);
			}
		    })
		    .catch(function () {
			window.setTimeout(update, 3000);
		    });
	}

<?php if ($watch): ?>
	window.setTimeout(update, 1500);
<?php endif; ?>
});
//]]>
</script>

<?php include("foot.inc");
