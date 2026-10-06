<?php
/*
 * status_captiveportal_test.php
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
##|*IDENT=page-status-captiveportal-test
##|*NAME=Status: Captive Portal: Test Vouchers
##|*DESCR=Allow access to the 'Status: Captive Portal: Test Vouchers' page.
##|*MATCH=status_captiveportal_test.php*
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

$pgtitle = array(gettext("Status"), gettext("Captive Portal"), htmlspecialchars($cpzone), gettext("Test Vouchers"));
$pglinks = array("", "status_captiveportal.php", "status_captiveportal.php?zone=" . $cpzone, "@self");
$shortcut_section = "captiveportal-vouchers";

$tested = false;
$test_results = [];
$summary = '';
if ($_POST['Submit'] && $_POST['vouchers']) {
	$tested = true;
	$test_results = voucher_auth(trim($_POST['vouchers']), 1);
	if (!is_array($test_results)) {
		$test_results = [];
	}
	/* the last line is the overall verdict ("Access granted …" / "Access denied!") */
	$summary = (string)array_pop($test_results);
}
$granted = (strpos($summary, " granted ") !== false);

include("head.inc");

fs_tabs('status-captiveportal', 'status_captiveportal_test.php', ['zone' => $cpzone]);
?>

<style>
.fs-cp-results { margin: 0; padding: 0; list-style: none; }
.fs-cp-results li { display: flex; align-items: flex-start; gap: var(--fs-sp-3); padding: var(--fs-sp-2) var(--fs-sp-4); border-top: 1px solid var(--fs-border); }
.fs-cp-results li > .fs-badge { flex: 0 0 auto; }
.fs-cp-results li > span:last-child { min-width: 0; overflow-wrap: anywhere; }
</style>

<div class="fs-tool">
	<form method="post" action="status_captiveportal_test.php" class="fs-tool-form">
		<input type="hidden" name="zone" value="<?=htmlspecialchars($cpzone)?>">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Test vouchers')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="vouchers"><?=gettext('Vouchers')?></label>
					<textarea class="form-control fs-mono" id="vouchers" name="vouchers" rows="6" required autofocus><?=htmlspecialchars($_POST['vouchers'])?></textarea>
					<div class="form-text"><?=gettext('Enter multiple vouchers separated by space or newline. The remaining time, if valid, will be shown for each voucher.')?></div>
				</div>
			</div>
			<div class="panel-footer">
				<button type="submit" class="btn btn-primary" name="Submit" value="Test" data-fs-busy="true">
					<i class="fa-solid fa-wrench icon-embed-btn" aria-hidden="true"></i><?=gettext('Test')?>
				</button>
			</div>
		</div>
	</form>
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Results')?></h2></div>
<?php if ($tested): ?>
		<div class="fs-tool-verdict">
			<?=$granted ? fs_badge('pass', gettext('Granted')) : fs_badge('block', gettext('Denied'))?>
			<span><?=htmlspecialchars(($summary !== '') ? $summary : gettext('Vouchers are not enabled for this zone.'))?></span>
		</div>
		<ul class="fs-cp-results">
<?php foreach ($test_results as $result):
	$ok = (strpos($result, " good ") !== false);
?>
			<li><?=$ok ? fs_badge('pass', gettext('Valid')) : fs_badge('block', gettext('Invalid'))?><span class="fs-mono"><?=htmlspecialchars($result)?></span></li>
<?php endforeach; ?>
		</ul>
<?php else: ?>
		<div class="fs-tool-empty">
			<i class="fa-solid fa-ticket" aria-hidden="true"></i>
			<span><?=gettext('Enter one or more vouchers to see whether they are valid and how much time they have left. Testing does not activate a voucher.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>
<?php
include("foot.inc");
