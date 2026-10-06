<?php
/*
 * diag_ping.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2003-2005 Bob Zoller (bob@kludgebox.com)
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
##|*IDENT=page-diagnostics-ping
##|*NAME=Diagnostics: Ping
##|*DESCR=Allow access to the 'Diagnostics: Ping' page.
##|*MATCH=diag_ping.php*
##|-PRIV

$allowautocomplete = true;
$pgtitle = array(gettext("Diagnostics"), gettext("Ping"));
require_once("guiconfig.inc");

require_once("diag_tools.inc");

$do_ping = false;
$host = $host_utf8 = '';
$count = DIAG_PING_DEFAULT_COUNT;
$wait = DIAG_PING_DEFAULT_WAIT;

if ($_POST || $_REQUEST['host']) {
	unset($input_errors);
	unset($do_ping);

	/* input validation */
	$ping = diag_ping_check($_REQUEST);
	$input_errors = $ping['input_errors'];
	$host = $ping['host'];
	$host_utf8 = $ping['host_utf8'];
	$ipproto = $ping['ipproto'];

	if (!$input_errors) {
		if ($_POST) {
			$do_ping = true;
		}
		if (isset($_REQUEST['sourceip'])) {
			$sourceip = $ping['sourceip'];
		}
		$count = $ping['count'];
		$wait = $ping['wait'];
	}
}

if ($do_ping) {
	$result = diag_exec(diag_ping_command($host, $ipproto, $sourceip, $count, $wait))['stdout'];

	if (empty($result)) {
		$input_errors[] = sprintf(gettext('Host "%s" did not respond or could not be resolved.'), $host_utf8);
	}

}

include('head.inc');

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form(false);

$section = new Form_Section('Ping');

$section->addInput(new Form_Input(
	'host',
	'*Hostname',
	'text',
	$host_utf8,
	['placeholder' => 'Hostname to ping']
));

$section->addInput(new Form_Select(
	'ipproto',
	'*IP Protocol',
	$ipproto,
	['ipv4' => 'IPv4', 'ipv6' => 'IPv6']
));

$section->addInput(new Form_Select(
	'sourceip',
	'*Source address',
	$sourceip,
	array('' => gettext('Automatically selected (default)')) + get_possible_traffic_source_addresses(true)
))->setHelp('Select source address for the ping.');

$section->addInput(new Form_Select(
	'count',
	'Maximum number of pings',
	$count,
	array_combine(range(1, DIAG_PING_MAX_COUNT), range(1, DIAG_PING_MAX_COUNT))
))->setHelp('Select the maximum number of pings.');

$section->addInput(new Form_Select(
	'wait',
	'Seconds between pings',
	$wait,
	array_combine(range(1, DIAG_PING_MAX_WAIT), range(1, DIAG_PING_MAX_WAIT))
))->setHelp('Select the number of seconds to wait between pings.');

$form->add($section);

$form->addGlobal(new Form_Button(
	'Submit',
	'Ping',
	null,
	'fa-solid fa-play'
))->addClass('btn-primary')->setAttribute('data-fs-busy', 'true');

print $form;

if ($do_ping && !empty($result) && !$input_errors) {
?>
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=gettext('Results')?></h2>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#ping-output">
				<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
			</button>
		</div>
		<pre class="fs-console" id="ping-output"><?= htmlspecialchars($result) ?></pre>
	</div>
<?php
}

include('foot.inc');
