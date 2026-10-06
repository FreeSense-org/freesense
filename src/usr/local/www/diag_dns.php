<?php
/*
 * diag_dns.php
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
##|*IDENT=page-diagnostics-dns
##|*NAME=Diagnostics: DNS Lookup
##|*DESCR=Allow access to the 'Diagnostics: DNS Lookup' page.
##|*MATCH=diag_dns.php*
##|-PRIV

$pgtitle = array(gettext("Diagnostics"), gettext("DNS Lookup"));
require_once("guiconfig.inc");
require_once("freesense-utils.inc");

require_once("diag_tools.inc");

$host = $host_utf8 = '';

list($host, $host_utf8) = diag_dns_host($_REQUEST['host']);

$alias_exists = diag_dns_alias_state($host)['exists'];

if (isAllowedPage('firewall_aliases_edit.php') && isset($_POST['create_alias']) && (is_hostname($host) || is_ipaddr($host))) {
	if (diag_dns_create_alias($host)) {
		$createdalias = true;
	} else {
		$couldnotcreatealias = true;
	}
}

if ($_POST) {
	unset($input_errors);

	$lookup = diag_dns_lookup($_POST, $host);
	$input_errors = $lookup['input_errors'];
	if (isset($lookup['dns_speeds'])) {
		$dns_speeds = $lookup['dns_speeds'];
	}
	$type = $lookup['type'];
	$resolved = $lookup['resolved'];
	$ipaddr = $lookup['ipaddr'];
	if (isset($lookup['resolvedptr'])) {
		$resolvedptr = $lookup['resolvedptr'];
	}
}

if ($_POST['host'] && $_POST['dialog_output']) {
	$host = (isset($resolvedptr) ? $resolvedptr : $host);
	diag_dns_display_host_results($ipaddr, $host, $dns_speeds);
	exit;
}

include("head.inc");

/* Display any error messages resulting from user input */
if ($input_errors) {
	print_input_errors($input_errors);
} else if (!$resolved && $type) {
	print_info_box(sprintf(gettext('Host "%s" could not be resolved.'), htmlspecialchars($host_utf8)), 'warning', false);
}

if ($createdalias) {
	if ($alias_exists) {
		print_info_box(gettext("Alias was updated successfully."), 'success');
	} else {
		print_info_box(gettext("Alias was created successfully."), 'success');
	}

	$alias_exists = true;
}

if ($couldnotcreatealias) {
	if ($alias_exists) {
		print_info_box(sprintf(gettext("Alias already exists for %s"), htmlspecialchars($host)), 'warning', false);
	} else {
		print_info_box(htmlspecialchars(str_replace('%s', $host, gettext("Could not create alias for %s"))), 'warning', false);
	}
}

$show_results = (!$input_errors && $type);
?>

<style>
.fs-tool { display: grid; grid-template-columns: minmax(0, 22rem) minmax(0, 1fr); gap: var(--fs-sp-4); align-items: start; margin-bottom: var(--fs-sp-5); }
.fs-tool .panel { margin-bottom: 0; }
.fs-tool-stack { display: flex; flex-direction: column; gap: var(--fs-sp-4); min-width: 0; }
.fs-tool-form .panel-body { display: flex; flex-direction: column; gap: var(--fs-sp-3); padding: var(--fs-sp-4); }
.fs-tool-form .form-label { margin-bottom: var(--fs-sp-1); font-weight: 500; }
.fs-tool-form .form-text { margin-top: var(--fs-sp-1); }
.fs-tool-form .panel-footer { display: flex; flex-wrap: wrap; gap: var(--fs-sp-2); padding: var(--fs-sp-3) var(--fs-sp-4); }
.fs-tool-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--fs-sp-2); min-height: 16rem; padding: var(--fs-sp-5); color: var(--fs-text-muted); text-align: center; }
.fs-tool-empty > i { font-size: var(--fs-fs-xl); opacity: .6; }
.fs-tool .panel-footer.fs-tool-links { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); }
@media (max-width: 991.98px) { .fs-tool { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="fs-tool">
	<form method="post" action="diag_dns.php" class="fs-tool-form">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Lookup')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="host"><?=gettext('Hostname or IP address')?></label>
					<input class="form-control fs-mono" type="text" id="host" name="host" value="<?=htmlspecialchars($host_utf8)?>" placeholder="<?=gettext('Hostname to look up')?>" required autofocus>
					<div class="form-text"><?=gettext('An IP address is looked up in reverse (PTR).')?></div>
				</div>
			</div>
			<div class="panel-footer">
				<button type="submit" class="btn btn-primary" name="Submit" value="Lookup" data-fs-busy="true">
					<i class="fa-solid fa-magnifying-glass icon-embed-btn" aria-hidden="true"></i><?=gettext('Lookup')?>
				</button>
<?php if (!empty($resolved) && isAllowedPage('firewall_aliases_edit.php')): ?>
				<button type="submit" class="btn btn-outline-secondary" id="create_alias" name="create_alias" value="<?=$alias_exists ? gettext('Update Alias') : gettext('Add Alias')?>"
				    title="<?=gettext('Create or update a host alias with these addresses')?>">
					<i class="fa-solid <?=$alias_exists ? 'fa-arrows-rotate' : 'fa-plus'?> icon-embed-btn" aria-hidden="true"></i><?=$alias_exists ? gettext('Update alias') : gettext('Add alias')?>
				</button>
<?php endif; ?>
			</div>
		</div>
	</form>

	<div class="fs-tool-stack">
<?php if (!$show_results): ?>
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Results')?></h2></div>
			<div class="fs-tool-empty">
				<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
				<span><?=gettext('Look up a host to see its records and how fast each name server answered.')?></span>
			</div>
		</div>
<?php else: ?>
<?php if ($resolved): ?>
		<div class="panel panel-default fs-table">
			<div class="panel-heading">
				<h2 class="panel-title"><?=gettext('Results')?> <span class="fs-count"><?=(int)count((array)$resolved)?></span></h2>
			</div>
			<div class="panel-body table-responsive">
				<table class="table table-hover">
					<thead>
						<tr>
							<th><?=gettext('Result')?></th>
							<th class="fs-col-status"><?=gettext('Record type')?></th>
						</tr>
					</thead>
					<tbody>
<?php foreach ((array)$resolved as $hostitem): ?>
						<tr>
							<td class="fs-mono"><?=htmlspecialchars($hostitem['data'])?></td>
							<td><?=fs_badge('info', htmlspecialchars($hostitem['type']))?></td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="panel-footer fs-tool-links">
				<span class="fs-muted small"><?=gettext('Next:')?></span>
				<a class="btn btn-sm btn-outline-secondary" href="/diag_ping.php?host=<?=htmlspecialchars(urlencode($host))?>&amp;count=3"><i class="fa-solid fa-satellite-dish icon-embed-btn" aria-hidden="true"></i><?=gettext("Ping")?></a>
				<a class="btn btn-sm btn-outline-secondary" href="/diag_traceroute.php?host=<?=htmlspecialchars(urlencode($host))?>&amp;ttl=18"><i class="fa-solid fa-route icon-embed-btn" aria-hidden="true"></i><?=gettext("Traceroute")?></a>
			</div>
		</div>
<?php endif; ?>

		<div class="panel panel-default fs-table">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Timings')?></h2></div>
			<div class="panel-body table-responsive">
				<table class="table table-hover">
					<thead>
						<tr>
							<th><?=gettext('Name server')?></th>
							<th><?=gettext('Query time')?></th>
						</tr>
					</thead>
					<tbody>
<?php foreach ((array)$dns_speeds as $qt): ?>
						<tr>
							<td class="fs-mono"><?=htmlspecialchars($qt['dns_server'])?></td>
							<td class="fs-mono"><?=htmlspecialchars($qt['query_time'])?></td>
						</tr>
<?php endforeach; ?>
<?php if (empty($dns_speeds)) {
	fs_empty_row(2, gettext('No name server timings are available.'));
} ?>
					</tbody>
				</table>
			</div>
		</div>
<?php endif; ?>
	</div>
</div>

<?php
if (!$input_errors):
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var original_host = <?=json_encode($host);?>;

	$('input[name="host"]').on('input', function() {
		if ($('#host').val() == original_host) {
			disableInput('create_alias', false);
		} else {
			disableInput('create_alias', true);
		}
	});
});
//]]>
</script>
<?php
endif;
include("foot.inc");
