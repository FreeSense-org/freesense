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

$form = new Form(false);

$section = new Form_Section('Traceroute');

$section->addInput(new Form_Input(
	'host',
	'*Hostname',
	'text',
	$host_utf8,
	['placeholder' => 'Hostname to trace.']
));

$section->addInput(new Form_Select(
	'ipproto',
	'*IP Protocol',
	$ipproto,
	array('ipv4' => 'IPv4', 'ipv6' => 'IPv6')
))->setHelp('Select the protocol to use.');

$section->addInput(new Form_Select(
	'sourceip',
	'*Source Address',
	$sourceip,
	array('any' => gettext('Any')) + get_possible_traffic_source_addresses(true)
))->setHelp('Select source address for the trace.');

$section->addInput(new Form_Select(
	'ttl',
	'Maximum number of hops',
	$ttl,
	array_combine(range(1, DIAG_TRACEROUTE_MAX_TTL), range(1, DIAG_TRACEROUTE_MAX_TTL))
))->setHelp('Select the maximum number of network hops to trace.');

$section->addInput(new Form_Checkbox(
	'resolve',
	'Reverse Address Lookup',
	'',
	$resolve
))->setHelp('When checked, traceroute will attempt to perform a PTR lookup to locate hostnames for hops along the path. This will slow down the process as it has to wait for DNS replies.');

$section->addInput(new Form_Checkbox(
	'useicmp',
	gettext("Use ICMP"),
	'',
	$useicmp
))->setHelp('By default, traceroute uses UDP but that may be blocked by some routers. Check this box to use ICMP instead, which may succeed. ');

$form->add($section);

$form->addGlobal(new Form_Button(
	'Submit',
	'Traceroute',
	null,
	'fa-solid fa-rss'
))->addClass('btn-primary');

print $form;

/* Show the traceroute results */
if ($do_traceroute && $result) {
?>
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Results')?></h2></div>
		<div class="panel-body">
<?php
	print('<pre>' . htmlspecialchars($result) . '</pre>');
?>
		</div>
	</div>
<?php
}

include("foot.inc");
?>
