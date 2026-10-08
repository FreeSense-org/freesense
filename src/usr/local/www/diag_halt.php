<?php
/*
 * diag_halt.php
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
##|*IDENT=page-diagnostics-haltsystem
##|*NAME=Diagnostics: Halt system
##|*DESCR=Allow access to the 'Diagnostics: Halt system' page.
##|*MATCH=diag_halt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("captiveportal.inc");
require_once("diag_system.inc");

if ($_POST['save'] == 'No') {
	header("Location: index.php");
	exit;
}

$pgtitle = array(gettext("Diagnostics"), gettext("Halt System"));
include('head.inc');
?>

<style>
.fs-halt-wait > i { color: var(--fs-block); }
</style>

<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
?>
	<meta http-equiv="refresh" content="70;url=/">
	<div class="panel panel-default">
		<div class="fs-danger-wait fs-halt-wait" aria-live="polite">
			<i class="fa-solid fa-power-off" aria-hidden="true"></i>
			<h2><?=gettext('The system is halting')?></h2>
			<p><?=gettext("The system is halting now. This may take one minute or so.")?></p>
		</div>
	</div>
<?php
	if (g_get('debug')) {
	   printf(gettext("Not actually halting (DEBUG is set true)%s"), "<br />");
	} else {
		print('<pre class="fs-console">');
		diag_halt_run();
		print('</pre>');
	}
} else {
?>

<form action="diag_halt.php" method="post">
	<div class="panel panel-default fs-danger-card">
		<div class="panel-heading">
			<h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=gettext('Halt the system')?></h2>
		</div>
		<div class="panel-body fs-danger-body">
			<p><?=gettext('Halting shuts the system down and powers it off. It stays off until someone turns it on again.')?></p>
			<ul class="fs-danger-list">
				<li><?=gettext('Routing, firewalling and NAT stop: clients lose internet and network access.')?></li>
				<li><?=gettext('VPN tunnels, DHCP, DNS and every other service stay down.')?></li>
				<li><?=gettext('The web interface cannot start the system again. It needs physical, console or hypervisor access.')?></li>
			</ul>
		</div>
		<div class="panel-footer">
			<button type="submit" class="btn btn-danger" name="save" value="<?=gettext("Halt")?>" title="<?=gettext("Halt the system and power off")?>">
				<i class="fa-solid fa-power-off icon-embed-btn" aria-hidden="true"></i><?=gettext('Halt now')?>
			</button>
			<a href="/index.php" class="btn btn-outline-secondary"><?=gettext("Cancel")?></a>
		</div>
	</div>
</form>

<?php
}

include("foot.inc");
