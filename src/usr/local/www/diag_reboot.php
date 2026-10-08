<?php
/*
 * diag_reboot.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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
##|*IDENT=page-diagnostics-rebootsystem
##|*NAME=Diagnostics: Reboot System
##|*DESCR=Allow access to the 'Diagnostics: Reboot System' page.
##|*MATCH=diag_reboot.php*
##|-PRIV

global $g;

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("captiveportal.inc");
require_once("diag_system.inc");

$guitimeout = 90;	// Seconds to wait before reloading the page after reboot
$guiretry = 20;		// Seconds to try again if $guitimeout was not long enough

$pgtitle = array(gettext("Diagnostics"), gettext("Reboot"));
$platform = system_identify_specific_platform();

include("head.inc");
?>

<style>
.fs-reboot-modes { display: grid; gap: var(--fs-sp-2); }
.fs-reboot-mode {
	position: relative; display: flex; flex-direction: column; gap: .15rem; padding: var(--fs-sp-3) var(--fs-sp-4) var(--fs-sp-3) 2.6rem;
	border: 1px solid var(--fs-border); border-radius: var(--fs-r-md); cursor: pointer;
}
.fs-reboot-mode:hover { border-color: var(--fs-text-muted); }
.fs-reboot-mode:has(input:checked) { border-color: var(--fs-coral); background: var(--fs-accent-tint); }
.fs-reboot-mode:has(input:focus-visible) { outline: 2px solid var(--fs-coral); outline-offset: 2px; }
.fs-reboot-mode > input { position: absolute; top: 1.05rem; left: 1rem; accent-color: var(--fs-coral); }
.fs-reboot-mode-name { color: var(--fs-text-strong); font-weight: 600; }
.fs-reboot-mode-help { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
</style>

<?php
if (isset($_POST['rebootmode'])):
	if (g_get('debug')) {
		print_info_box(gettext("Not actually rebooting (DEBUG is set true)."), 'success');
	} else {
		print('<div><pre class="fs-console">');
		if (!diag_reboot_run($_POST['rebootmode'])) {
			header('Location: /diag_reboot.php');
		}
		print('</pre></div>');
	}
?>

<div class="panel panel-default">
	<div class="fs-danger-wait" aria-live="polite">
		<i class="fa-solid fa-rotate fa-spin" aria-hidden="true"></i>
		<h2 id="reboot-title"><?=gettext('Rebooting')?></h2>
		<p><span id="reboot-text"><?=gettext('The page reloads automatically in')?></span> <span id="secs" class="fs-mono"><?=$guitimeout?></span> <?=gettext('seconds')?></p>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	var time = <?=(int)$guitimeout?>;
	var retry = <?=(int)$guiretry?>;
	var secs = document.getElementById('secs');

	function checkonline() {
		$.ajax({
			url	: "/index.php", // or other resource
			type : "HEAD"
		})
		.done(function() {
			window.location="/index.php";
		});
	}

	setInterval(function() {
		if (time > 0) {
			time--;
		} else {
			time = retry;
			document.getElementById('reboot-title').textContent = <?=json_encode(gettext('Not yet ready'))?>;
			document.getElementById('reboot-text').textContent = <?=json_encode(gettext('Retrying in'))?>;
			checkonline();
		}
		secs.textContent = time;
	}, 1000);
});
//]]>
</script>
<?php
else:

$rebootmodes = diag_reboot_modes();
$modeslist = $rebootmodes['modes'];
$mode_help = [
	'reboot' => gettext('Restarts the system right away.'),
	'fsckreboot' => gettext('Restarts and checks the filesystem during boot. Boot takes longer.'),
	'reroot' => gettext('Stops processes, remounts the disks and runs the startup sequence again, without restarting the kernel.'),
];
$checked = array_key_first($modeslist);
?>

<form method="post" action="diag_reboot.php">
	<div class="panel panel-default fs-danger-card">
		<div class="panel-heading">
			<h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=gettext('Reboot the system')?></h2>
		</div>
		<div class="panel-body fs-danger-body">
			<p><?=gettext('Rebooting interrupts every service this firewall provides until it is back up.')?></p>
			<ul class="fs-danger-list">
				<li><?=gettext('Routing, firewalling and NAT stop: clients lose internet and network access.')?></li>
				<li><?=gettext('VPN tunnels, DHCP, DNS and other services go down and active connections are dropped.')?></li>
				<li><?=gettext('This page reconnects automatically when the system is back, usually within a few minutes.')?></li>
			</ul>
			<div class="fs-reboot-modes" role="radiogroup" aria-label="<?=gettext('Reboot method')?>">
<?php foreach ($modeslist as $mode => $label): ?>
				<label class="fs-reboot-mode">
					<input type="radio" name="rebootmode" value="<?=htmlspecialchars($mode)?>"<?=($mode === $checked) ? ' checked' : ''?>>
					<span class="fs-reboot-mode-name"><?=htmlspecialchars($label)?></span>
					<span class="fs-reboot-mode-help"><?=htmlspecialchars($mode_help[$mode] ?? '')?></span>
				</label>
<?php endforeach; ?>
			</div>
		</div>
		<div class="panel-footer">
			<button type="submit" class="btn btn-danger" name="Submit" value="Submit">
				<i class="fa-solid fa-power-off icon-embed-btn" aria-hidden="true"></i><?=gettext('Reboot now')?>
			</button>
			<a href="/index.php" class="btn btn-outline-secondary"><?=gettext('Cancel')?></a>
		</div>
	</div>
</form>

<?php
endif;

include("foot.inc");
