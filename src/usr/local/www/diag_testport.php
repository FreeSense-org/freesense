<?php
/*
 * diag_testport.php
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
##|*IDENT=page-diagnostics-testport
##|*NAME=Diagnostics: Test Port
##|*DESCR=Allow access to the 'Diagnostics: Test Port' page.
##|*MATCH=diag_testport.php*
##|-PRIV

// Calling netcat and parsing the results has been moved to the if ($_POST) section so that the results are known
// before we draw the form and any resulting error messages will appear in the correct place

$allowautocomplete = true;

$pgtitle = array(gettext("Diagnostics"), gettext("Test Port"));
require_once("guiconfig.inc");

include("head.inc");

define('NC_TIMEOUT', 10);
$do_testport = false;
$retval = 1;

if ($_POST || $_REQUEST['host']) {
	unset($input_errors);

	/* input validation */
	$reqdfields = explode(" ", "host port");
	$reqdfieldsn = array(gettext("Host"), gettext("Port"));
	do_input_validation($_REQUEST, $reqdfields, $reqdfieldsn, $input_errors);

	if (!is_ipaddr($_REQUEST['host']) && !is_hostname($_REQUEST['host'])) {
		$input_errors[] = gettext("Please enter a valid IP or hostname.");
	}

	if (!is_port($_REQUEST['port'])) {
		$input_errors[] = gettext("Please enter a valid port number.");
	}

	if (($_REQUEST['srcport'] != "") && (!is_numeric($_REQUEST['srcport']) || !is_port($_REQUEST['srcport']))) {
		$input_errors[] = gettext("Please enter a valid source port number, or leave the field blank.");
	}

	if (is_ipaddrv4($_REQUEST['host']) && ($_REQUEST['ipprotocol'] == "ipv6")) {
		$input_errors[] = gettext("Cannot connect to an IPv4 address using IPv6.");
	}
	if (is_ipaddrv6($_REQUEST['host']) && ($_REQUEST['ipprotocol'] == "ipv4")) {
		$input_errors[] = gettext("Cannot connect to an IPv6 address using IPv4.");
	}

	if (!$input_errors) {
		$do_testport = true;
		$timeout = NC_TIMEOUT;
	}

	/* Save these request vars even if there were input errors. Then the fields are refilled for the user to correct. */
	$host = $_REQUEST['host'];
	$sourceip = $_REQUEST['sourceip'];
	$port = $_REQUEST['port'];
	$srcport = $_REQUEST['srcport'];
	$showtext = isset($_REQUEST['showtext']);
	$ipprotocol = $_REQUEST['ipprotocol'];

	if ($do_testport) {
		$result = "";
		$ncoutput = "";
		$nc_base_cmd = '/usr/bin/nc';
		$nc_args = "-w " . escapeshellarg($timeout);
		if (!$showtext) {
			$nc_args .= ' -z ';
		}
		if (!empty($srcport)) {
			$nc_args .= ' -p ' . escapeshellarg($srcport) . ' ';
		}

		/* Attempt to determine the interface address, if possible. Else try both. */
		if (is_ipaddrv4($host)) {
			if ($sourceip == "any") {
				$ifaddr = "";
			} else {
				if (is_ipaddr($sourceip)) {
					$ifaddr = $sourceip;
				} else {
					$ifaddr = get_interface_ip($sourceip);
				}
			}
			$nc_args .= ' -4';
		} elseif (is_ipaddrv6($host)) {
			if ($sourceip == "any") {
				$ifaddr = '';
			} else if (is_linklocal($sourceip)) {
				$ifaddr = $sourceip;
			} else {
				$ifaddr = get_interface_ipv6($sourceip);
			}
			$nc_args .= ' -6';
		} else {
			switch ($ipprotocol) {
				case "ipv4":
					$ifaddr = get_interface_ip($sourceip);
					$nc_ipproto = ' -4';
					break;
				case "ipv6":
					$ifaddr = (is_linklocal($sourceip) ? $sourceip : get_interface_ipv6($sourceip));
					$nc_ipproto = ' -6';
					break;
				case "any":
					$ifaddr = get_interface_ip($sourceip);
					$nc_ipproto = (!empty($ifaddr)) ? ' -4' : '';
					if (empty($ifaddr)) {
						$ifaddr = (is_linklocal($sourceip) ? $sourceip : get_interface_ipv6($sourceip));
						$nc_ipproto = (!empty($ifaddr)) ? ' -6' : '';
					}
					break;
			}
			/* Netcat doesn't like it if we try to connect using a certain type of IP without specifying the family. */
			if (!empty($ifaddr)) {
				$nc_args .= $nc_ipproto;
			} elseif ($sourceip == "any") {
				switch ($ipprotocol) {
					case "ipv4":
						$nc_ipproto = ' -4';
						break;
					case "ipv6":
						$nc_ipproto = ' -6';
						break;
				}
				$nc_args .= $nc_ipproto;
			}
		}
		/* Only add on the interface IP if we managed to find one. */
		if (!empty($ifaddr)) {
			$nc_args .= ' -s ' . escapeshellarg($ifaddr) . ' ';
			$scope = get_ll_scope($ifaddr);
			if (!empty($scope) && !strstr($host, "%")) {
				$host .= "%{$scope}";
			}
		}

		$nc_cmd = "{$nc_base_cmd} {$nc_args} " . escapeshellarg($host) . ' ' . escapeshellarg($port) . ' 2>&1';
		exec($nc_cmd, $result, $retval);

		if (!empty($result)) {
			if (is_array($result)) {
				foreach ($result as $resline) {
					$ncoutput .= $resline . "\n";
				}
			} else {
				$ncoutput .= $result;
			}
		}
	}
}

if ($input_errors) {
	print_input_errors($input_errors);
}

$sources = ['' => gettext('Any')] + get_possible_traffic_source_addresses(true);
?>

<style>
.fs-tool { display: grid; grid-template-columns: minmax(0, 22rem) minmax(0, 1fr); gap: var(--fs-sp-4); align-items: start; margin-bottom: var(--fs-sp-5); }
.fs-tool .panel { margin-bottom: 0; }
.fs-tool-form .panel-body { display: flex; flex-direction: column; gap: var(--fs-sp-3); padding: var(--fs-sp-4); }
.fs-tool-form .form-label { margin-bottom: var(--fs-sp-1); font-weight: 500; }
.fs-tool-form .form-text { margin-top: var(--fs-sp-1); }
.fs-tool-form .panel-footer { display: flex; flex-wrap: wrap; gap: var(--fs-sp-2); padding: var(--fs-sp-3) var(--fs-sp-4); }
.fs-tool-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--fs-sp-3); }
.fs-tool-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--fs-sp-2); min-height: 16rem; padding: var(--fs-sp-5); color: var(--fs-text-muted); text-align: center; }
.fs-tool-empty > i { font-size: var(--fs-fs-xl); opacity: .6; }
.fs-tool-verdict { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-3); padding: var(--fs-sp-4); }
.fs-tool-verdict + .fs-console { border-top: 1px solid var(--fs-border); }
@media (max-width: 991.98px) { .fs-tool { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="fs-tool">
	<form method="post" action="diag_testport.php" class="fs-tool-form">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Options')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="host"><?=gettext('Hostname or IP address')?></label>
					<input class="form-control fs-mono" type="text" id="host" name="host" value="<?=htmlspecialchars($host)?>" placeholder="<?=gettext('Host to connect to')?>" required autofocus>
				</div>
				<div class="fs-tool-row">
					<div>
						<label class="form-label" for="port"><?=gettext('Port')?></label>
						<input class="form-control fs-mono" type="text" inputmode="numeric" id="port" name="port" value="<?=htmlspecialchars($port)?>" placeholder="443" required>
					</div>
					<div>
						<label class="form-label" for="srcport"><?=gettext('Source port')?></label>
						<input class="form-control fs-mono" type="text" inputmode="numeric" id="srcport" name="srcport" value="<?=htmlspecialchars($srcport)?>" placeholder="<?=gettext('Any')?>">
					</div>
				</div>
				<div class="fs-tool-row">
					<div>
						<label class="form-label" for="ipprotocol"><?=gettext('IP protocol')?></label>
						<select class="form-select" id="ipprotocol" name="ipprotocol">
<?php foreach (['ipv4' => 'IPv4', 'ipv6' => 'IPv6'] as $k => $v): ?>
							<option value="<?=$k?>"<?=($ipprotocol == $k) ? ' selected' : ''?>><?=$v?></option>
<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="form-label" for="sourceip"><?=gettext('Source address')?></label>
						<select class="form-select" id="sourceip" name="sourceip">
<?php foreach ($sources as $k => $v): ?>
							<option value="<?=htmlspecialchars($k)?>"<?=((string)$sourceip === (string)$k) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="showtext" name="showtext" value="yes"<?=$showtext ? ' checked' : ''?>>
						<label class="form-check-label" for="showtext"><?=gettext('Show remote text')?></label>
					</div>
					<div class="form-text"><?=gettext('Shows what the server sends after connecting. Takes 10 seconds or more.')?></div>
				</div>
			</div>
			<div class="panel-footer">
				<button type="submit" class="btn btn-primary" name="Submit" value="Test" data-fs-busy="true">
					<i class="fa-solid fa-plug icon-embed-btn" aria-hidden="true"></i><?=gettext('Test port')?>
				</button>
			</div>
		</div>
	</form>

	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=gettext('Result')?></h2>
<?php if ($do_testport && $retval == 0 && $showtext && $ncoutput !== ''): ?>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#testport-output">
				<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
			</button>
<?php endif; ?>
		</div>
<?php if ($do_testport): ?>
		<div class="fs-tool-verdict">
<?php if ($retval == 0): ?>
			<?=fs_badge('pass', gettext('Connected'))?>
			<span><?=htmlspecialchars(sprintf(gettext('Port test to host: %1$s Port: %2$s successful.'), $host, $port))?></span>
<?php else: ?>
			<?=fs_badge('block', gettext('Failed'))?>
			<span><?=$showtext ? gettext('No output received, or connection failed. Try with "Show Remote Text" unchecked first.') : gettext('Connection failed.')?></span>
<?php endif; ?>
		</div>
<?php if ($retval == 0 && $showtext && $ncoutput !== ''): ?>
		<pre class="fs-console" id="testport-output"><?=htmlspecialchars($ncoutput)?></pre>
<?php endif; ?>
<?php else: ?>
		<div class="fs-tool-empty">
			<i class="fa-solid fa-plug" aria-hidden="true"></i>
			<span><?=gettext('Tests whether a host accepts TCP connections on a port.')?></span>
			<span class="small"><?=gettext('UDP cannot be tested this way, because there is no reliable way to tell whether a UDP port accepts connections.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>

<?php
include("foot.inc");
