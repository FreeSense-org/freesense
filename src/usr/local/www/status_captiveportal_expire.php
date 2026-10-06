<?php
/*
 * status_captiveportal_expire.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2007 Marcel Wiget <mwiget@mac.com>
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
##|*IDENT=page-status-captiveportal-expire
##|*NAME=Status: Captive Portal: Expire Vouchers
##|*DESCR=Allow access to the 'Status: Captive Portal: Expire Vouchers' page.
##|*MATCH=status_captiveportal_expire.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("captiveportal.inc");
require_once("voucher.inc");

$cpzone = strtolower($_REQUEST['zone']);

/* If the zone does not exist, do not display the invalid zone */
if (!array_key_exists($cpzone, config_get_path('captiveportal', []))) {
	$cpzone = "";
}

if (empty($cpzone)) {
	header("Location: services_captiveportal_zones.php");
	exit;
}

$pgtitle = array(gettext("Status"), gettext("Captive Portal"), htmlspecialchars($cpzone), gettext("Expire Vouchers"));
$pglinks = array("", "status_captiveportal.php", "status_captiveportal.php?zone=" . $cpzone, "@self");

$expired = null;
if ($_POST['Submit'] && $_POST['vouchers']) {
	$expired = voucher_expire(trim($_POST['vouchers'])) ? true : false;
}

include("head.inc");

fs_tabs('status-captiveportal', 'status_captiveportal_expire.php', ['zone' => $cpzone]);
?>

<div class="fs-tool">
	<form method="post" action="status_captiveportal_expire.php" class="fs-tool-form">
		<input type="hidden" name="zone" value="<?=htmlspecialchars($cpzone)?>">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Expire vouchers')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="vouchers"><?=gettext('Vouchers')?></label>
					<textarea class="form-control fs-mono" id="vouchers" name="vouchers" rows="6" required autofocus><?=htmlspecialchars($_POST['vouchers'])?></textarea>
					<div class="form-text"><?=gettext('Enter multiple vouchers separated by space or newline. All valid vouchers will be marked as expired.')?></div>
				</div>
			</div>
			<div class="panel-footer">
				<button type="submit" class="btn btn-warning" name="Submit" value="Expire"
					data-fs-confirm="<?=gettext('Expire these vouchers?')?>"
					data-fs-confirm-detail="<?=gettext('Valid vouchers are marked as used. Users logged in with them are disconnected and the vouchers cannot be used again.')?>"
					data-fs-confirm-action="<?=gettext('Expire')?>">
					<i class="fa-solid fa-trash-can icon-embed-btn" aria-hidden="true"></i><?=gettext('Expire')?>
				</button>
			</div>
		</div>
	</form>
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Result')?></h2></div>
<?php if ($expired === true): ?>
		<div class="fs-tool-verdict">
			<?=fs_badge('pass', gettext('Done'))?>
			<span><?=gettext('Voucher(s) successfully marked.')?></span>
		</div>
<?php elseif ($expired === false): ?>
		<div class="fs-tool-verdict">
			<?=fs_badge('error', gettext('Failed'))?>
			<span><?=gettext('Voucher(s) could not be processed.')?></span>
		</div>
<?php else: ?>
		<div class="fs-tool-empty">
			<i class="fa-solid fa-ticket" aria-hidden="true"></i>
			<span><?=gettext('Expire vouchers to end their sessions early or to take lost vouchers out of use.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>
<?php
include("foot.inc");
