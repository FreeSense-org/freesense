<?php
/*
 * diag_limiter_info.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-diagnostics-limiter-info
##|*NAME=Diagnostics: Limiter Info
##|*DESCR=Allows access to the 'Diagnostics: Limiter Info' page
##|*MATCH=diag_limiter_info.php*
##|-PRIV

require_once("guiconfig.inc");

$pgtitle = array(gettext("Diagnostics"), gettext("Limiter Info"));
$shortcut_section = "trafficshaper-limiters";

if ($_REQUEST['getactivity']) {
	$text = shell_exec('/sbin/dnctl pipe show');
	if ($text == "") {
		$text = gettext("No limiters were found on this system.");
	}
	echo gettext("Limiters:") . "\n";
	echo $text;
	$text = shell_exec('/sbin/dnctl sched show');
	if ($text != "") {
		echo "\n\n" . gettext("Schedulers") . ":\n";
		echo $text;
	}
	$text = shell_exec('/sbin/dnctl queue show');
	if ($text != "") {
		echo "\n\n" . gettext("Queues") . ":\n";
		echo $text;
	}
	exit;
}

$sections = array(
	'pipes' => array(gettext('Limiters'), (string)shell_exec('/sbin/dnctl pipe show'), '/^\d{5}:/m', gettext('No limiters were found on this system.')),
	'scheds' => array(gettext('Schedulers'), (string)shell_exec('/sbin/dnctl sched show'), '/^\d{5}:/m', gettext('No schedulers are active.')),
	'queues' => array(gettext('Queues'), (string)shell_exec('/sbin/dnctl queue show'), '/^q\d+/m', gettext('No queues are active.')),
);

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}
?>

<style>
.fs-limiter-bar { display: flex; justify-content: flex-end; margin-bottom: var(--fs-sp-3); }
.fs-limiter-empty { margin: 0; padding: var(--fs-sp-4); color: var(--fs-text-muted); }
</style>

<div class="fs-tiles" id="limiter-tiles" data-fs-live>
<?php foreach ($sections as $key => $s): ?>
<?php fs_tile($s[0], preg_match_all($s[2], $s[1])); ?>
<?php endforeach; ?>
</div>

<div class="fs-limiter-bar">
	<div class="form-check form-switch mb-0">
		<input class="form-check-input" type="checkbox" role="switch" id="refresh" checked>
		<label class="form-check-label" for="refresh"><?=gettext('Refresh automatically')?></label>
	</div>
</div>

<?php foreach ($sections as $key => $s): ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=htmlspecialchars($s[0])?></h2>
		<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#limiter-<?=$key?>">
			<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
		</button>
	</div>
<?php if (trim($s[1]) === ''): ?>
	<p class="fs-limiter-empty" id="limiter-<?=$key?>" data-fs-live><?=htmlspecialchars($s[3])?></p>
<?php else: ?>
	<pre class="fs-console" id="limiter-<?=$key?>" data-fs-live><?=htmlspecialchars($s[1])?></pre>
<?php endif; ?>
</div>
<?php endforeach; ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Refresh the live parts in place (text only for the outputs, nodes for the tiles).
	function refresh() {
		if (!document.getElementById('refresh').checked || document.hidden) {
			return;
		}
		fetch(window.location.href, {credentials: 'same-origin'}).then(function (r) {
			return r.ok ? r.text() : null;
		}).then(function (text) {
			if (!text) {
				return;
			}
			var doc = new DOMParser().parseFromString(text, 'text/html');
			document.querySelectorAll('[data-fs-live][id]').forEach(function (el) {
				var fresh = doc.getElementById(el.id);
				if (!fresh) {
					return;
				}
				if (el.tagName === 'PRE' || el.tagName === 'P') {
					el.textContent = fresh.textContent;
					return;
				}
				var nodes = Array.prototype.map.call(fresh.childNodes, function (n) {
					return document.importNode(n, true);
				});
				el.replaceChildren.apply(el, nodes);
			});
		}).catch(function () {});
	}

	setInterval(refresh, 2500);
});
//]]>
</script>

<?php include("foot.inc");
