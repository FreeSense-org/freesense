<?php
/*
 * diag_ping.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
$ipproto = 'ipv4';
$sourceip = '';

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

$sources = array('' => gettext('Automatically selected (default)')) + get_possible_traffic_source_addresses(true);
$has_result = (!empty($do_ping) && !empty($result) && !$input_errors);
?>

<div class="fs-tool">
	<form method="post" action="diag_ping.php" class="fs-tool-form">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Options')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="host"><?=gettext('Hostname or IP address')?></label>
					<input class="form-control fs-mono" type="text" id="host" name="host" value="<?=htmlspecialchars($host_utf8)?>" placeholder="<?=gettext('Hostname to ping')?>" required autofocus>
				</div>
				<div>
					<label class="form-label" for="ipproto"><?=gettext('IP protocol')?></label>
					<select class="form-select" id="ipproto" name="ipproto">
<?php foreach (['ipv4' => 'IPv4', 'ipv6' => 'IPv6'] as $k => $v): ?>
						<option value="<?=$k?>"<?=($ipproto == $k) ? ' selected' : ''?>><?=$v?></option>
<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="form-label" for="sourceip"><?=gettext('Source address')?></label>
					<select class="form-select" id="sourceip" name="sourceip">
<?php foreach ($sources as $k => $v): ?>
						<option value="<?=htmlspecialchars($k)?>"<?=($sourceip == $k) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
					</select>
					<div class="form-text"><?=gettext('The address the pings are sent from.')?></div>
				</div>
				<div class="fs-tool-row">
					<div>
						<label class="form-label" for="count"><?=gettext('Number of pings')?></label>
						<select class="form-select" id="count" name="count">
<?php foreach (range(1, DIAG_PING_MAX_COUNT) as $n): ?>
							<option value="<?=$n?>"<?=($count == $n) ? ' selected' : ''?>><?=$n?></option>
<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="form-label" for="wait"><?=gettext('Interval (seconds)')?></label>
						<select class="form-select" id="wait" name="wait">
<?php foreach (range(1, DIAG_PING_MAX_WAIT) as $n): ?>
							<option value="<?=$n?>"<?=($wait == $n) ? ' selected' : ''?>><?=$n?></option>
<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>
			<div class="panel-footer">
				<button type="submit" class="btn btn-primary" name="Submit" value="Ping" data-fs-busy="true">
					<i class="fa-solid fa-play icon-embed-btn" aria-hidden="true"></i><?=gettext('Ping')?>
				</button>
			</div>
		</div>
	</form>

	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=gettext('Results')?></h2>
<?php if ($has_result): ?>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#ping-output">
				<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
			</button>
<?php endif; ?>
		</div>
<?php if ($has_result): ?>
		<pre class="fs-console" id="ping-output"><?=htmlspecialchars($result)?></pre>
<?php else: ?>
		<div class="fs-tool-empty">
			<i class="fa-solid fa-satellite-dish" aria-hidden="true"></i>
			<span><?=gettext('Enter a host and run a ping to see the replies and round-trip times here.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>

<?php
include('foot.inc');
