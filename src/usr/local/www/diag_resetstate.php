<?php
/*
 * diag_resetstate.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-diagnostics-resetstate
##|*NAME=Diagnostics: Reset states
##|*DESCR=Allow access to the 'Diagnostics: Reset states' page.
##|*MATCH=diag_resetstate.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("filter.inc");
require_once("diag_system.inc");

if ($_POST) {
	$savemsg = diag_resetstate_run(!empty($_POST['statetable']), !empty($_POST['sourcetracking']));
}

$pgtitle = array(gettext("Diagnostics"), gettext("States"), gettext("Reset States"));
$pglinks = array("", "diag_dump_states.php", "@self");
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

fs_tabs('diagnostics-states', 'diag_resetstate.php');

$has_sourcetracking = diag_resetstate_sourcetracking_available();
?>

<style>
.fs-reset-options { display: grid; gap: var(--fs-sp-3); margin-top: var(--fs-sp-3); }
.fs-reset-option { display: flex; gap: var(--fs-sp-3); padding: var(--fs-sp-3) var(--fs-sp-4); border: 1px solid var(--fs-border); border-radius: var(--fs-r-md); cursor: pointer; }
.fs-reset-option:has(input:checked) { border-color: var(--fs-block); }
.fs-reset-option .form-check-input { flex: none; margin-top: .2rem; }
.fs-reset-option strong { display: block; }
.fs-reset-option span { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-reset-none { margin-top: var(--fs-sp-2); color: var(--fs-block); font-size: var(--fs-fs-sm); }
</style>

<form method="post" action="diag_resetstate.php" id="resetstate-form" novalidate>
	<div class="panel panel-default fs-danger-card">
		<div class="panel-heading">
			<h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=gettext('Reset firewall states')?></h2>
		</div>
		<div class="panel-body fs-danger-body">
			<p><?=gettext('The firewall normally keeps its state tables when rules change. A reset is only needed after larger rule or NAT changes, for example when connections with protocol mappings (PPTP, IPv6) are still open.')?></p>
			<div class="fs-reset-options" role="group" aria-label="<?=gettext('What to reset')?>">
				<label class="fs-reset-option" for="statetable">
					<input class="form-check-input" type="checkbox" name="statetable" id="statetable" value="yes">
					<div>
						<strong><?=gettext('Firewall state table')?></strong>
						<span><?=gettext('Removes every state: all open connections break and must be set up again. The browser may seem to hang after the reset; reload the page to continue.')?></span>
					</div>
				</label>
<?php if ($has_sourcetracking): ?>
				<label class="fs-reset-option" for="sourcetracking">
					<input class="form-check-input" type="checkbox" name="sourcetracking" id="sourcetracking" value="yes">
					<div>
						<strong><?=gettext('Source tracking table')?></strong>
						<span><?=gettext('Clears every sticky source/destination association. Active connection states stay as they are.')?></span>
					</div>
				</label>
<?php endif; ?>
			</div>
			<p class="fs-reset-none" id="reset-none" hidden role="alert"><?=gettext("Please select at least one reset option")?></p>
		</div>
		<div class="panel-footer">
			<button type="submit" class="btn btn-danger no-confirm" name="Submit" value="Reset" id="Submit">
				<i class="fa-solid fa-trash-can icon-embed-btn" aria-hidden="true"></i><?=gettext('Reset')?>
			</button>
			<a class="btn btn-outline-secondary" href="diag_dump_states.php"><?=gettext('Cancel')?></a>
		</div>
	</div>
</form>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var form = document.getElementById('resetstate-form');
	var confirmed = false;

	$(form).on('change', 'input[type=checkbox]', function () {
		$('#reset-none').prop('hidden', true);
	});

	form.addEventListener('submit', function (event) {
		if (confirmed) {
			return;
		}
		event.preventDefault();
		if (!$(form).find('input[type=checkbox]:checked').length) {
			$('#reset-none').prop('hidden', false);
			return;
		}
		var btn = event.submitter || document.getElementById('Submit');
		window.fsConfirm({
			title: <?=json_encode(gettext("Do you really want to reset the selected states?"))?>,
			detail: <?=json_encode(gettext('Open connections that use the reset entries are interrupted.'))?>,
			action: <?=json_encode(gettext('Reset'))?>,
			returnFocus: btn
		}).then(function (yes) {
			if (yes) {
				confirmed = true;
				form.requestSubmit(btn);
			}
		});
	});
});
//]]>
</script>

<?php include("foot.inc"); ?>
