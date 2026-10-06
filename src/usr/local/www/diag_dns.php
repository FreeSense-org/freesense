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
	print_info_box(sprintf(gettext('Host "%s" could not be resolved.'), $host_utf8), 'warning', false);
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
		print_info_box(sprintf(gettext("Alias already exists for %s"), $host), 'warning', false);
	} else {
		print_info_box(sprintf(gettext("Could not create alias for %s"), $host), 'warning', false);
	}
}

$form = new Form(false);
$section = new Form_Section('DNS Lookup');

$section->addInput(new Form_Input(
	'host',
	'*Hostname',
	'text',
	$host_utf8,
	['placeholder' => 'Hostname to look up.']
));

$form->add($section);

$form->addGlobal(new Form_Button(
        'Submit',
        'Lookup',
        null,
        'fa-solid fa-magnifying-glass'
))->addClass('btn-primary');

if (!empty($resolved) && isAllowedPage('firewall_aliases_edit.php')) {
	$form->addGlobal(new Form_Button(
		'create_alias',
		($alias_exists) ? gettext("Update Alias") : gettext("Add Alias"),
		null,
		($alias_exists) ? 'fa-solid fa-arrows-rotate' : 'fa-solid fa-plus'
	))->removeClass('btn-primary')->addClass('btn-success');
}

print $form;

if (!$input_errors && $type) {
	if ($resolved):
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Results')?></h2></div>
	<div class="panel-body">

		<table class="table">
		<thead>
			<tr>
				<th><?=gettext('Result')?></th>
				<th><?=gettext('Record type')?></th>
			</tr>
		</thead>
		<tbody>
<?php foreach ((array)$resolved as $hostitem):?>
		<tr>
			<td><?=htmlspecialchars($hostitem['data'])?></td><td><?=htmlspecialchars($hostitem['type'])?></td>
		</tr>
<?php endforeach; ?>
		</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<!-- Second table displays the server resolution times -->
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Timings')?></h2></div>
	<div class="panel-body">
		<table class="table">
		<thead>
			<tr>
				<th><?=gettext('Name server')?></th>
				<th><?=gettext('Query time')?></th>
			</tr>
		</thead>

		<tbody>
<?php foreach ((array)$dns_speeds as $qt):?>
		<tr>
			<td><?=htmlspecialchars($qt['dns_server'])?></td><td><?=htmlspecialchars($qt['query_time'])?></td>
		</tr>
<?php endforeach; ?>
		</tbody>
		</table>
	</div>
</div>

<!-- Third table displays "More information" -->
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('More Information')?></h2></div>
	<div class="panel-body">
		<ul class="list-group">
			<li class="list-group-item"><a href="/diag_ping.php?host=<?=htmlspecialchars($host)?>&amp;count=3"><?=gettext("Ping")?></a></li>
			<li class="list-group-item"><a href="/diag_traceroute.php?host=<?=htmlspecialchars($host)?>&amp;ttl=18"><?=gettext("Traceroute")?></a></li>
		</ul>
	</div>
</div>
<?php
}
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
