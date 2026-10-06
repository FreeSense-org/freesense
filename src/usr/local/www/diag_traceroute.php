<?php
/*
 * diag_traceroute.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2005 Paul Taylor (paultaylor@winndixie.com)
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
##|*IDENT=page-diagnostics-traceroute
##|*NAME=Diagnostics: Traceroute
##|*DESCR=Allow access to the 'Diagnostics: Traceroute' page.
##|*MATCH=diag_traceroute.php*
##|-PRIV

require_once("guiconfig.inc");

$allowautocomplete = true;
$pgtitle = array(gettext("Diagnostics"), gettext("Traceroute"));
include("head.inc");

require_once("diag_tools.inc");

// Set defaults in case they are not supplied.
$do_traceroute = false;
$host = $host_utf8 = '';
$ttl = DIAG_TRACEROUTE_DEFAULT_TTL;
$ipproto = 'ipv4';
$sourceip = 'any';

if ($_POST || $_REQUEST['host']) {
	unset($input_errors);

	/* input validation */
	$trace = diag_traceroute_check($_REQUEST);
	$input_errors = $trace['input_errors'];
	$host = $trace['host'];
	$host_utf8 = $trace['host_utf8'];
	$ipproto = $trace['ipproto'];
	$sourceip = $trace['sourceip'];
	$ttl = $trace['ttl'];
	$resolve = $trace['resolve'];
	$useicmp = $trace['useicmp'];

	if ($_POST && !$input_errors) {
		$do_traceroute = true;
	}

} else {
	$resolve = false;
	$useicmp = false;
}

if ($input_errors) {
	print_input_errors($input_errors);
}

/* Do the traceroute and show any error */
if ($do_traceroute) {
	$result = diag_exec(diag_traceroute_command($host, $ipproto, $sourceip, $ttl, isset($resolve), isset($useicmp)))['stdout'];

	if (!$result) {
		print_info_box(sprintf(gettext('Error: %s could not be traced/resolved'), htmlspecialchars($host_utf8)));
	}
}

$sources = array('any' => gettext('Any')) + get_possible_traffic_source_addresses(true);
?>

<style>
.fs-tool { display: grid; grid-template-columns: minmax(0, 22rem) minmax(0, 1fr); gap: var(--fs-sp-4); align-items: start; margin-bottom: var(--fs-sp-5); }
.fs-tool > .panel, .fs-tool > form > .panel { margin-bottom: 0; }
.fs-tool-form .panel-body { display: flex; flex-direction: column; gap: var(--fs-sp-3); padding: var(--fs-sp-4); }
.fs-tool-form .form-label { margin-bottom: var(--fs-sp-1); font-weight: 500; }
.fs-tool-form .form-text { margin-top: var(--fs-sp-1); }
.fs-tool-form .panel-footer { display: flex; flex-wrap: wrap; gap: var(--fs-sp-2); padding: var(--fs-sp-3) var(--fs-sp-4); }
.fs-tool-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--fs-sp-3); }
.fs-tool-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--fs-sp-2); min-height: 16rem; padding: var(--fs-sp-5); color: var(--fs-text-muted); text-align: center; }
.fs-tool-empty > i { font-size: var(--fs-fs-xl); opacity: .6; }
@media (max-width: 991.98px) { .fs-tool { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="fs-tool">
	<form method="post" action="diag_traceroute.php" class="fs-tool-form">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Options')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="host"><?=gettext('Hostname or IP address')?></label>
					<input class="form-control fs-mono" type="text" id="host" name="host" value="<?=htmlspecialchars($host_utf8)?>" placeholder="<?=gettext('Hostname to trace')?>" required autofocus>
				</div>
				<div class="fs-tool-row">
					<div>
						<label class="form-label" for="ipproto"><?=gettext('IP protocol')?></label>
						<select class="form-select" id="ipproto" name="ipproto">
<?php foreach (['ipv4' => 'IPv4', 'ipv6' => 'IPv6'] as $k => $v): ?>
							<option value="<?=$k?>"<?=($ipproto == $k) ? ' selected' : ''?>><?=$v?></option>
<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="form-label" for="ttl"><?=gettext('Maximum hops')?></label>
						<select class="form-select" id="ttl" name="ttl">
<?php foreach (range(1, DIAG_TRACEROUTE_MAX_TTL) as $n): ?>
							<option value="<?=$n?>"<?=($ttl == $n) ? ' selected' : ''?>><?=$n?></option>
<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div>
					<label class="form-label" for="sourceip"><?=gettext('Source address')?></label>
					<select class="form-select" id="sourceip" name="sourceip">
<?php foreach ($sources as $k => $v): ?>
						<option value="<?=htmlspecialchars($k)?>"<?=($sourceip == $k) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
					</select>
				</div>
				<div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="resolve" name="resolve" value="yes"<?=$resolve ? ' checked' : ''?>>
						<label class="form-check-label" for="resolve"><?=gettext('Reverse address lookup')?></label>
					</div>
					<div class="form-text"><?=gettext('Looks up host names for the hops. Slower, because it waits for DNS replies.')?></div>
				</div>
				<div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="useicmp" name="useicmp" value="yes"<?=$useicmp ? ' checked' : ''?>>
						<label class="form-check-label" for="useicmp"><?=gettext('Use ICMP')?></label>
					</div>
					<div class="form-text"><?=gettext('Traceroute uses UDP by default, which some routers block. ICMP may get through.')?></div>
				</div>
			</div>
			<div class="panel-footer">
				<button type="submit" class="btn btn-primary" name="Submit" value="Traceroute" data-fs-busy="true">
					<i class="fa-solid fa-route icon-embed-btn" aria-hidden="true"></i><?=gettext('Traceroute')?>
				</button>
			</div>
		</div>
	</form>

	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=gettext('Results')?></h2>
<?php if ($do_traceroute && $result): ?>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#traceroute-output">
				<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
			</button>
<?php endif; ?>
		</div>
<?php if ($do_traceroute && $result): ?>
		<pre class="fs-console" id="traceroute-output"><?=htmlspecialchars($result)?></pre>
<?php else: ?>
		<div class="fs-tool-empty">
			<i class="fa-solid fa-route" aria-hidden="true"></i>
			<span><?=gettext('Enter a host and run a traceroute to see each hop on the path here.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>

<?php
include("foot.inc");
