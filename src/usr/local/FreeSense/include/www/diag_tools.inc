<?php
/*
 * diag_tools.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2003-2005 Bob Zoller (bob@kludgebox.com)
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

/*
 * The shared code of Diagnostics > Ping, Traceroute and DNS Lookup: request
 * validation, the commands (argument lists run without a shell) and the DNS
 * lookup. The pages and the REST API both use it.
 */

require_once("util.inc");
require_once("interfaces.inc");
require_once("freesense-utils.inc");

define('DIAG_PING_MAX_COUNT', 10);
define('DIAG_PING_DEFAULT_COUNT', 3);
define('DIAG_PING_MAX_WAIT', 10);
define('DIAG_PING_DEFAULT_WAIT', 1);

/* Max TTL of both traceroute and traceroute6 is 255, but in practice more than
   64 hops would most likely time out in the GUI. If a user requires a
   traceroute that long, they can use the CLI. */
define('DIAG_TRACEROUTE_MAX_TTL', 64);
define('DIAG_TRACEROUTE_DEFAULT_TTL', 18);

/*
 * Run a command given as an argument list (never through a shell). Returns
 * its standard output and error. With $timeout > 0 the command is stopped
 * after that many seconds ('timed_out'): it gets SIGINT (ping and traceroute
 * then print what they have, ping its statistics) and SIGKILL 2 seconds later
 * if it is still running. Output beyond $maxbytes is dropped.
 */
function diag_exec(array $argv, $timeout = 0, $maxbytes = 1048576) {
	$result = array('stdout' => '', 'stderr' => '', 'timed_out' => false, 'status' => null);
	$pipes = array();
	$proc = @proc_open(array_map('strval', array_values($argv)),
	    array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($proc)) {
		return $result;
	}
	$open = array('stdout' => $pipes[1], 'stderr' => $pipes[2]);
	foreach ($open as $pipe) {
		stream_set_blocking($pipe, false);
	}
	$deadline = ($timeout > 0) ? microtime(true) + $timeout : null;
	$killed = false;
	while (!empty($open)) {
		$wait = 1.0;
		if ($deadline !== null) {
			$wait = min($wait, $deadline - microtime(true));
			if ($wait <= 0) {
				if ($result['timed_out']) {
					/* Still running 2 seconds after SIGINT. */
					proc_terminate($proc, 9);
					$killed = true;
					break;
				}
				$result['timed_out'] = true;
				proc_terminate($proc, 2);
				$deadline = microtime(true) + 2;
				continue;
			}
		}
		$read = array_values($open);
		$write = $except = null;
		if (@stream_select($read, $write, $except, 0, (int)($wait * 1000000)) === false) {
			break;
		}
		foreach ($read as $pipe) {
			$name = array_search($pipe, $open, true);
			$data = fread($pipe, 8192);
			if (($data === false) || ($data === '')) {
				if (feof($pipe)) {
					unset($open[$name]);
				}
				continue;
			}
			if (strlen($result[$name]) < $maxbytes) {
				$result[$name] .= substr($data, 0, $maxbytes - strlen($result[$name]));
			}
		}
	}
	if (!$killed && !empty($open)) {
		proc_terminate($proc, 9);
	}
	fclose($pipes[1]);
	fclose($pipes[2]);
	$result['status'] = proc_close($proc);
	return $result;
}

/* A request field as a string (form fields are strings; anything else is treated as empty). */
function diag_request_string(array $request, $field) {
	return (isset($request[$field]) && is_string($request[$field])) ? $request[$field] : '';
}

/*
 * A host name converted to ASCII (IDN) and back: array(ascii, utf8). A name
 * IDN refuses (e.g. one starting with "-") gives "" as the ASCII form, so it
 * fails validation, and is shown as entered; idn_to_utf8(false) threw a
 * ValueError and the pages stopped with an error.
 */
function diag_idn_host($host) {
	$ascii = idn_to_ascii($host);
	if ($ascii === false) {
		return array('', $host);
	}
	return array($ascii, idn_to_utf8($ascii));
}

/*
 * The host of a Ping or Traceroute request: trimmed and converted to ASCII
 * (IDN). Returns array(ascii, utf8) (see diag_idn_host()).
 */
function diag_request_host(array $request) {
	$host = $host_utf8 = '';
	$host = trim(diag_request_string($request, 'host'));
	if (!empty($host)) {
		list($host, $host_utf8) = diag_idn_host($host);
	}
	return array($host, $host_utf8);
}

/* Input errors for the target host and IP protocol of Ping and Traceroute. */
function diag_host_errors($host, $ipproto) {
	$input_errors = array();
	if (!in_array($ipproto, array(null, '', 'ipv4', 'ipv6'), true)) {
		$input_errors[] = gettext("The IP protocol must be IPv4 or IPv6.");
	}
	if (($ipproto == "ipv4") && is_ipaddrv6($host)) {
		$input_errors[] = gettext("When using IPv4, the target host must be an IPv4 address or hostname.");
	}
	if (($ipproto == "ipv6") && is_ipaddrv4($host)) {
		$input_errors[] = gettext("When using IPv6, the target host must be an IPv6 address or hostname.");
	}
	if (!is_ipaddr($host) && !is_hostname($host)) {
		$input_errors[] = gettext("Hostname must be a valid hostname or IP address.");
	}
	return $input_errors;
}

/*
 * Input error for a source address that is not one of the page's choices
 * ($default is the page's "automatic" choice); null when it is valid.
 */
function diag_source_error($request, $default) {
	if (!isset($request['sourceip'])) {
		return null;
	}
	$sourceip = $request['sourceip'];
	if (is_string($sourceip) && (($sourceip === $default) ||
	    array_key_exists($sourceip, get_possible_traffic_source_addresses(true)))) {
		return null;
	}
	return gettext("The source address must be one of the listed addresses.");
}

/* ------------------------------------------------------------------ */
/* Ping                                                                */
/* ------------------------------------------------------------------ */

/*
 * Validate a Diagnostics > Ping request (the form fields host, ipproto,
 * sourceip, count and wait). Returns the input errors and the checked values;
 * count and wait default when empty.
 */
function diag_ping_check(array $request) {
	$input_errors = array();

	/* input validation */
	$reqdfields = explode(" ", "host count");
	$reqdfieldsn = array(gettext("Host"), gettext("Count"));
	do_input_validation($request, $reqdfields, $reqdfieldsn, $input_errors);
	$count = $request['count'] ?? null;
	$wait = $request['wait'] ?? null;
	if (($count < 1) || ($count > DIAG_PING_MAX_COUNT) || (!is_numericint($count))) {
		$input_errors[] = sprintf(gettext("Count must be between 1 and %s"), DIAG_PING_MAX_COUNT);
	}
	if (isset($wait) && (($wait < 1) ||
	    ($wait > DIAG_PING_MAX_WAIT) || (!is_numericint($wait)))) {
		$input_errors[] = sprintf(gettext("Wait must be between 1 and %s"), DIAG_PING_MAX_WAIT);
	}
	list($host, $host_utf8) = diag_request_host($request);
	$ipproto = $request['ipproto'] ?? null;
	$input_errors = array_merge($input_errors, diag_host_errors($host, $ipproto));
	if ($error = diag_source_error($request, '')) {
		$input_errors[] = $error;
	}

	return array(
		'input_errors' => $input_errors,
		'host' => $host,
		'host_utf8' => $host_utf8,
		'ipproto' => $ipproto,
		'sourceip' => $request['sourceip'] ?? null,
		/* The count used to fall back to DEFAULT_WAIT. */
		'count' => (empty($count)) ? DIAG_PING_DEFAULT_COUNT : $count,
		'wait' => (empty($wait)) ? DIAG_PING_DEFAULT_WAIT : $wait,
	);
}

/* The ping command for checked values (see diag_ping_check()), as an argument list. */
function diag_ping_command($host, $ipproto, $sourceip, $count, $wait) {
	$ifscope = '';
	$command = "/sbin/ping";
	if ($ipproto == "ipv6") {
		$command .= "6";
		$ifaddr = is_ipaddr($sourceip) ? $sourceip : get_interface_ipv6($sourceip);
		if (is_linklocal($ifaddr)) {
			$ifscope = get_ll_scope($ifaddr);
		}
	} else {
		$ifaddr = is_ipaddr($sourceip) ? $sourceip : get_interface_ip($sourceip);
	}

	$argv = array($command);
	if ($ifaddr && (is_ipaddr($host) || is_hostname($host))) {
		$argv[] = "-S{$ifaddr}";
		if (is_linklocal($host) && !strstr($host, "%") && !empty($ifscope)) {
			$host .= "%{$ifscope}";
		}
	}
	$argv[] = "-c{$count}";
	$argv[] = "-i{$wait}";
	$argv[] = $host;
	return $argv;
}

/* ------------------------------------------------------------------ */
/* Traceroute                                                          */
/* ------------------------------------------------------------------ */

/*
 * Validate a Diagnostics > Traceroute request (the form fields host, ipproto,
 * sourceip, ttl, resolve and useicmp). Returns the input errors and the values.
 */
function diag_traceroute_check(array $request) {
	$input_errors = array();

	/* input validation */
	$reqdfields = explode(" ", "host ttl");
	$reqdfieldsn = array(gettext("Host"), gettext("ttl"));
	do_input_validation($request, $reqdfields, $reqdfieldsn, $input_errors);

	$ttl = $request['ttl'] ?? null;
	if (($ttl < 1) || ($ttl > DIAG_TRACEROUTE_MAX_TTL) || !is_numericint($ttl)) {
		$input_errors[] = sprintf(gettext("Maximum number of hops must be between 1 and %s"), DIAG_TRACEROUTE_MAX_TTL);
	}
	list($host, $host_utf8) = diag_request_host($request);
	$ipproto = $request['ipproto'] ?? null;
	$input_errors = array_merge($input_errors, diag_host_errors($host, $ipproto));
	if ($error = diag_source_error($request, 'any')) {
		$input_errors[] = $error;
	}

	return array(
		'input_errors' => $input_errors,
		'host' => $host,
		'host_utf8' => $host_utf8,
		'ipproto' => $ipproto,
		'sourceip' => $request['sourceip'] ?? null,
		'ttl' => $ttl,
		'resolve' => $request['resolve'] ?? null,
		'useicmp' => $request['useicmp'] ?? null,
	);
}

/*
 * The traceroute command for checked values (see diag_traceroute_check()), as
 * an argument list. $resolve and $useicmp are booleans (the boxes are ticked).
 */
function diag_traceroute_command($host, $ipproto, $sourceip, $ttl, $resolve, $useicmp) {
	$n = $resolve ? "" : "-n";

	$command = "/usr/sbin/traceroute";
	if ($ipproto == "ipv6") {
		$command .= "6";
		if (empty($n)) {
			$n = "-l";
		}
		$ifaddr = is_ipaddr($sourceip) ? $sourceip : get_interface_ipv6($sourceip);
	} else {
		$ifaddr = is_ipaddr($sourceip) ? $sourceip : get_interface_ip($sourceip);
	}

	$argv = array($command);
	if ($n !== "") {
		$argv[] = $n;
	}
	if ($ifaddr && (is_ipaddr($host) || is_hostname($host))) {
		$argv[] = "-s";
		$argv[] = $ifaddr;
	}
	$argv[] = "-w";
	$argv[] = "2";
	if ($useicmp) {
		$argv[] = "-I";
	}
	$argv[] = "-m";
	$argv[] = $ttl;
	$argv[] = $host;
	return $argv;
}

/* ------------------------------------------------------------------ */
/* DNS Lookup                                                          */
/* ------------------------------------------------------------------ */

/* The host of a DNS Lookup request as the page cleans it: array(ascii, utf8). */
function diag_dns_host($value) {
	$host = $host_utf8 = '';
	$host = trim(is_string($value) ? $value : '', " \t\n\r\0\x0B[];\"'");
	if (!empty($host)) {
		list($host, $host_utf8) = diag_idn_host($host);
	}
	return array($host, $host_utf8);
}

/* The alias "Add Alias" creates for a host: its name, whether it exists and its position. */
function diag_dns_alias_state($host) {
	$a_aliases = config_get_path('aliases/alias', []);

	$aliasname = substr(str_replace(array(".", "-"), "_", $host), 0, 31);
	$alias_exists = false;
	$id = null;
	$counter = 0;
	foreach ($a_aliases as $a) {
		if ($a['name'] == $aliasname) {
			$alias_exists = true;
			$id = $counter;
		}
		$counter++;
	}
	return array('name' => $aliasname, 'exists' => $alias_exists, 'id' => $id);
}

/*
 * The page's "Add Alias"/"Update Alias" button: resolve the host and store
 * its addresses as a network alias. Writes the configuration; only the page
 * calls it (the REST API lookup never creates aliases). Returns true when the
 * alias was written, false when it could not be created.
 */
function diag_dns_create_alias($host) {
	$a_aliases = config_get_path('aliases/alias', []);
	$alias = diag_dns_alias_state($host);
	$aliasname = $alias['name'];
	$alias_exists = $alias['exists'];
	$id = $alias['id'];

	$resolved = gethostbyname($host);
	if ($resolved) {
		$resolved = resolve_host_addresses($host);
		$isfirst = true;
		$addresses = "";
		foreach ($resolved as $re) {
			if ($re['data'] != "") {
				if (!$isfirst) {
					$addresses .= " ";
				}
				$re = rtrim($re['data']);
				if (is_ipaddr($re)) {
					$sn = is_ipaddrv6($re) ? '/128' : '/32';
				} else {
					// The name was a CNAME and resolved to another name, rather than an address.
					// In this case the alias entry will have a FQDN, so do not put a CIDR after it.
					$sn = "";
				}
				$addresses .= $re . $sn;
				$isfirst = false;
			}
		}
		if ($addresses == "") {
			return false;
		} else {
			$newalias = array();
			$newalias['name'] = $aliasname;
			$newalias['type'] = "network";
			$newalias['address'] = $addresses;
			$newalias['descr'] = gettext("Created from Diagnostics-> DNS Lookup");
			if ($alias_exists) {
				$a_aliases[$id] = $newalias;
			} else {
				$a_aliases[] = $newalias;
			}
			config_set_path('aliases/alias', $a_aliases);
			write_config(gettext("Created an alias from Diagnostics - DNS Lookup page."));
			return true;
		}
	}
	return false;
}

/*
 * Look up a host like Diagnostics > DNS Lookup: validate the request ($post
 * holds the form field "host", $host its cleaned form, see diag_dns_host()),
 * time a query at each DNS server and resolve the host ($recordtypes, the
 * page uses A, AAAA and CNAME) or the PTR record of an address. Changes
 * nothing.
 */
function diag_dns_lookup(array $post, $host, ?array $recordtypes = null) {
	$input_errors = array();
	$dns_speeds = null;

	$reqdfields = explode(" ", "host");
	$reqdfieldsn = explode(",", "Host");

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!is_hostname(rtrim($host, '.')) && !is_ipaddr($host)) {
		$input_errors[] = gettext("Host must be a valid hostname or IP address.");
	} else {
		// Test resolution speed of each DNS server.
		$dns_speeds = array();
		$dns_servers = get_dns_nameservers(false, true);
		foreach ($dns_servers as $dns_server) {
			$query_time = exec("/usr/bin/drill " . escapeshellarg($host) . " " . escapeshellarg("@" . trim($dns_server)) . " | /usr/bin/grep Query | /usr/bin/cut -d':' -f2");
			if ($query_time == "") {
				$query_time = gettext("No response");
			}
			$new_qt = array();
			$new_qt['dns_server'] = $dns_server;
			$new_qt['query_time'] = $query_time;
			$dns_speeds[] = $new_qt;
			unset($new_qt);
		}
	}

	$type = "unknown";
	$resolved = array();
	$ipaddr = "";
	$resolvedptr = null;
	if (!$input_errors) {
		if (is_ipaddr($host)) {
			$type = "ip";
			$resolvedptr = gethostbyaddr($host);
			$ipaddr = $host;
			if ($host != $resolvedptr) {
				$tmpresolved = array();
				$tmpresolved['type'] = "PTR";
				$tmpresolved['data'] = $resolvedptr;
				$resolved[] = $tmpresolved;
			}
		} elseif (is_hostname(rtrim($host, '.'))) {
			$type = "hostname";
			$ipaddr = gethostbyname($host);
			$resolved = ($recordtypes === null) ? resolve_host_addresses($host) : resolve_host_addresses($host, $recordtypes);
		}
	}

	return array(
		'input_errors' => $input_errors,
		'dns_speeds' => $dns_speeds,
		'type' => $type,
		'resolved' => $resolved,
		'ipaddr' => $ipaddr,
		'resolvedptr' => $resolvedptr,
	);
}

/* The plain-text result the page prints for dialog_output requests. */
function diag_dns_display_host_results($address, $hostname, $dns_speeds) {
	$map_lengths = function($element) { return strlen($element[0]); };

	echo gettext("IP Address") . ": " . htmlspecialchars($address) . " \n";
	echo gettext("Host Name") . ": " . htmlspecialchars($hostname) .  " \n";
	echo "\n";
	$text_table = array();
	$text_table[] = array(gettext("Server"), gettext("Query Time"));
	if (is_array($dns_speeds)) {
		foreach ($dns_speeds as $qt) {
			$text_table[] = array(trim($qt['dns_server']), trim($qt['query_time']));
		}
	}
	$col0_padlength = max(array_map($map_lengths, $text_table)) + 4;
	foreach ($text_table as $text_row) {
		echo str_pad($text_row[0], $col0_padlength) . $text_row[1] . "\n";
	}
}
