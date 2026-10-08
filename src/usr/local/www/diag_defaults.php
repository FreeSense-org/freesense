<?php
/*
 * diag_defaults.php
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
##|*IDENT=page-diagnostics-factorydefaults
##|*NAME=Diagnostics: Factory defaults
##|*DESCR=Allow access to the 'Diagnostics: Factory defaults' page.
##|*WARN=standard-warning-root
##|*MATCH=diag_defaults.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("pkg-utils.inc");

if ($_POST['Submit'] == " " . gettext("No") . " ") {
	header("Location: index.php");
	exit;
}

$pgtitle = array(gettext("Diagnostics"), gettext("Factory Defaults"));
include("head.inc");
?>

<style>
.fs-defaults-hint { display: flex; gap: var(--fs-sp-2); margin: 0; padding: var(--fs-sp-2) var(--fs-sp-3); border-radius: var(--fs-r-sm); background: var(--fs-surface-raised); font-size: var(--fs-fs-sm); }
.fs-defaults-hint > i { margin-top: .2rem; color: var(--fs-info); }
</style>

<?php if ($_POST['Submit'] == " " . gettext("Yes") . " "):
	print_info_box(gettext("The system has been reset to factory defaults and is now rebooting. This may take a few minutes, depending on the hardware."))?>
<pre class="fs-console">
<?php
	reset_factory_defaults();
	system_reboot();
?>
</pre>
<?php else:?>
<form action="diag_defaults.php" method="post">
	<div class="panel panel-default fs-danger-card">
		<div class="panel-heading">
			<h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=gettext("Reset to factory defaults")?></h2>
		</div>
		<div class="panel-body fs-danger-body">
			<p><strong><?=gettext('Resetting the system to factory defaults will remove all user configuration and apply the following settings:')?></strong></p>
			<ul class="fs-danger-list">
				<li><?=gettext("Reset to factory defaults")?></li>
				<li><?=gettext("LAN IP address will be reset to 192.168.1.1")?></li>
				<li><?=gettext("System will be configured as a DHCP server on the default LAN interface")?></li>
				<li><?=gettext("Reboot after changes are installed")?></li>
				<li><?=gettext("WAN interface will be set to obtain an address automatically from a DHCP server")?></li>
				<li><?=gettext("webConfigurator admin username will be reset to 'admin'")?></li>
				<li><?=htmlspecialchars(sprintf(gettext("webConfigurator admin password will be reset to '%s'"), g_get('factory_shipped_password')))?></li>
			</ul>
<?php if (isAllowedPage('diag_backup.php')): ?>
			<p class="fs-defaults-hint"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span><?=sprintf(gettext('This cannot be undone. %1$sDownload a backup%2$s first to keep the current configuration.'), '<a href="diag_backup.php">', '</a>')?></span></p>
<?php endif; ?>
		</div>
		<div class="panel-footer">
			<button name="Submit" type="submit" class="btn btn-danger" value=" <?=gettext("Yes")?> " title="<?=gettext("Perform a factory reset")?>">
				<i class="fa-solid fa-arrow-rotate-left icon-embed-btn" aria-hidden="true"></i><?=gettext("Reset to factory defaults")?>
			</button>
			<button name="Submit" type="submit" class="btn btn-outline-secondary" value=" <?=gettext("No")?> " title="<?=gettext("Return to the dashboard")?>">
				<?=gettext("Cancel")?>
			</button>
		</div>
	</div>
</form>
<?php endif?>
<?php include("foot.inc")?>
