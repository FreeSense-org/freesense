<?php
/*
 * services_dyndns.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
 * Dynamic DNS and RFC 2136 client logic shared by services_dyndns.php,
 * services_dyndns_edit.php, services_rfc2136.php, services_rfc2136_edit.php
 * and the REST API, moved out of the pages with the form fields as
 * parameters. Like the pages, a save updates the client at once (a
 * Dynamic DNS client contacts its provider, an RFC 2136 client runs
 * nsupdate) when it is enabled; there is nothing to apply.
 */

require_once("config.gui.inc");
require_once("gwlb.inc");
require_once("interfaces.inc");
require_once("services.inc");
require_once("util.inc");

/* ------------------------------------------------------------------ */
/* Dynamic DNS                                                         */
/* ------------------------------------------------------------------ */

/* returns true if $uname is a valid DynDNS username */
function is_dyndns_username($uname) {
	if (!is_string($uname)) {
		return false;
	}

	if (preg_match("/[^a-z0-9\-\+.@_:#]/i", $uname)) {
		return false;
	} else {
		return true;
	}
}

/* The page's "Check IP Mode" select (renamed from the page's build_check_ip_mode_list()). */
function dyndns_check_ip_mode_list() {
	return [
		'default' => 'Automatic (default)',
		'always' => 'Always use the Check IP service',
		'never' => 'Never use the Check IP service'
	];
}

/* The page's "Service Type" select (renamed from the page's build_type_list()). */
function dyndns_type_list() {
	$types = explode(",", DYNDNS_PROVIDER_DESCRIPTIONS);
	$vals = explode(" ", DYNDNS_PROVIDER_VALUES);
	$typelist = array();

	for ($j = 0; $j < count($vals); $j++) {
		$typelist[$vals[$j]] = htmlspecialchars($types[$j]);
	}

	return($typelist);
}

/*
 * The interface selects of both edit pages: interfaces and gateway groups
 * (renamed from the pages' build_if_list(), which clashed with
 * services_dnsmasq.inc).
 */
function dyndns_build_if_list() {
	$list = array();

	$iflist = get_configured_interface_with_descr();

	foreach ($iflist as $if => $ifdesc) {
		$list[$if] = $ifdesc;
	}

	unset($iflist);

	$grouplist = return_gateway_groups_array();

	foreach ($grouplist as $name => $group) {
		$list[$name] = 'GW Group ' . $name;
	}

	unset($grouplist);

	return($list);
}

/* The host name a client updates, as its cache file names use it. */
function dyndns_client_hostname($conf) {
	global $dyndns_split_domain_types;

	if (in_array($conf['type'], $dyndns_split_domain_types)) {
		return $conf['host'] . "." . $conf['domainname'];
	}
	return $conf['host'];
}

/* The IPv4 and IPv6 cache files of a client (they hold "address|time"). */
function dyndns_client_cache_files($conf) {
	$base = g_get('conf_path') . "/dyndns_{$conf['interface']}{$conf['type']}" . escapeshellarg(dyndns_client_hostname($conf)) . "{$conf['id']}";
	return array("{$base}.cache", "{$base}_v6.cache");
}

/* The address in a client cache file ("address|time"), or null without the file. */
function dyndns_cache_address($file) {
	if (!file_exists($file)) {
		return null;
	}
	$parts = explode("|", file_get_contents($file));
	return $parts[0];
}

/*
 * The current address of $interface as dyndnsCheckIP() finds it. With
 * $remote false no check IP service is asked: null when dyndnsCheckIP()
 * would ask one (a private address, or the "always" mode).
 */
function dyndns_current_ip($interface, $mode, $address_family, $remote = true) {
	if ($remote) {
		return dyndnsCheckIP($interface, $mode, $address_family);
	}
	list($address, $error) = get_request_source_address($interface, $address_family);
	if (isset($error)) {
		return false;
	} elseif ($mode == 'never') {
		return $address;
	}
	if (is_ipaddrv6($address)) {
		return (($mode != 'always') && is_v6gua($address)) ? $address : null;
	}
	return (($mode != 'always') && is_ipaddrv4($address) && !is_private_ip($address)) ? $address : null;
}

/*
 * The update state the list page shows for a Dynamic DNS client: the
 * cached addresses and whether the cached address (IPv4, else IPv6) is the
 * current one: "ok", "fail", or "unknown" before the first update (or when
 * $remote is false and only a check IP service could tell). "family" is
 * the cache that was compared (inet, inet6 or null).
 */
function dyndns_client_status($conf, $remote = true) {
	list($file4, $file6) = dyndns_client_cache_files($conf);
	$out = array('status' => 'unknown', 'family' => null,
	    'cached_ip' => dyndns_cache_address($file4), 'cached_ipv6' => dyndns_cache_address($file6));
	if ($out['cached_ip'] !== null) {
		$out['family'] = 'inet';
		$current = dyndns_current_ip($conf['interface'], array_get_path($conf, 'check_ip_mode'), AF_INET, $remote);
		$cached = $out['cached_ip'];
	} elseif ($out['cached_ipv6'] !== null) {
		$out['family'] = 'inet6';
		$current = dyndns_current_ip($conf['interface'], array_get_path($conf, 'check_ip_mode'), AF_INET6, $remote);
		$cached = $out['cached_ipv6'];
	} else {
		return $out;
	}
	if ($current !== null) {
		$out['status'] = ($current == $cached) ? 'ok' : 'fail';
	}
	return $out;
}

/*
 * A client's interface as the list pages name it: the interface
 * description (ignoring a "_stf" suffix when $strip_stf) or the gateway
 * group name.
 */
function dyndns_interface_label($interface, $strip_stf = false) {
	$label = '';
	foreach (get_configured_interface_with_descr() as $if => $ifdesc) {
		if (($strip_stf ? str_replace('_stf', '', $interface) : $interface) == $if) {
			$label .= $ifdesc;
			break;
		}
	}
	foreach (return_gateway_groups_array() as $if => $group) {
		if ($interface == $if) {
			$label .= $if;
			break;
		}
	}
	return $label;
}

/*
 * The edit form's fields as the page preselects them for client $id ($dup:
 * a copy without the host name). An empty array for a new client.
 */
function dyndns_client_settings($id, $dup = false) {
	$pconfig = array();
	$this_dyndns_config = isset($id) ? config_get_path("dyndnses/dyndns/{$id}") : null;
	if ($this_dyndns_config) {
		$pconfig['check_ip_mode'] = array_get_path($this_dyndns_config, 'check_ip_mode', 'default');
		$pconfig['username'] = $this_dyndns_config['username'];
		$pconfig['password'] = $this_dyndns_config['password'];
		if (!$dup) {
			$pconfig['host'] = $this_dyndns_config['host'];
		}
		$pconfig['domainname'] = $this_dyndns_config['domainname'];
		$pconfig['mx'] = $this_dyndns_config['mx'];
		$pconfig['type'] = $this_dyndns_config['type'];
		$pconfig['enable'] = !isset($this_dyndns_config['enable']);
		$pconfig['interface'] = str_replace('_stf', '', $this_dyndns_config['interface']);
		$pconfig['wildcard'] = isset($this_dyndns_config['wildcard']);
		$pconfig['proxied'] = isset($this_dyndns_config['proxied']);
		$pconfig['verboselog'] = isset($this_dyndns_config['verboselog']);
		$pconfig['curl_ipresolve_v4'] = isset($this_dyndns_config['curl_ipresolve_v4']);
		$pconfig['curl_ssl_verifypeer'] = isset($this_dyndns_config['curl_ssl_verifypeer']);
		$pconfig['zoneid'] = $this_dyndns_config['zoneid'];
		$pconfig['ttl'] = $this_dyndns_config['ttl'];
		$pconfig['maxcacheage'] = $this_dyndns_config['maxcacheage'];
		$pconfig['updateurl'] = $this_dyndns_config['updateurl'];
		$pconfig['resultmatch'] = $this_dyndns_config['resultmatch'];
		$pconfig['requestif'] = str_replace('_stf', '', $this_dyndns_config['requestif']);
		$pconfig['curl_proxy'] = isset($this_dyndns_config['curl_proxy']);
		$pconfig['descr'] = $this_dyndns_config['descr'];
	}
	return $pconfig;
}

/* Provider properties the edit page's validation uses. */
function dyndns_provider_attributes() {
	return array(
		"cloudflare" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"cloudflare-v6" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"desec" => array("apex" => false, "wildcard" => true, "username_none" => true),
		"desec-v6" => array("apex" => false, "wildcard" => true, "username_none" => true),
		"digitalocean" => array("apex" => true, "wildcard" => true, "username_none" => true),
		"digitalocean-v6" => array("apex" => true, "wildcard" => true, "username_none" => true),
		"dnsmadeeasy" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"freedns" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"freedns-v6" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"freedns2" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"freedns2-v6" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"gandi-livedns" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"gandi-livedns-v6" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"godaddy" => array("apex" => true, "wildcard" => true, "username_none" => false),
		"godaddy-v6" => array("apex" => true, "wildcard" => true, "username_none" => false),
		"googledomains" => array("apex" => false, "wildcard" => true, "username_none" => false),
		"linode" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"linode-v6" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"luadns" => array("apex" => true, "wildcard" => true, "username_none" => false),
		"luadns-v6" => array("apex" => true, "wildcard" => true, "username_none" => false),
		"namecheap" => array("apex" => true, "wildcard" => true, "username_none" => true),
		"yandex" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"yandex-v6" => array("apex" => false, "wildcard" => false, "username_none" => true),
		"dnsexit" => array("apex" => false, "wildcard" => false, "username_none" => true),
	);
}

/*
 * Save the edit form for client $id (null: a new client; $dup: a copy of
 * client $id) and update the client at once when it is enabled ("Save &
 * Force Update" posts "force"). Returns input_errors and the client's id.
 */
function dyndns_save_client($post, $id, $dup = false) {
	global $dyndns_split_domain_types;

	$this_dyndns_config = isset($id) ? config_get_path("dyndnses/dyndns/{$id}") : null;
	$input_errors = array();
	$pconfig = $post;

	$ddns_attr = dyndns_provider_attributes();

	if (isset($ddns_attr[$pconfig['type']]['username_none']) &&
	    ($ddns_attr[$pconfig['type']]['username_none'] == true) &&
	    empty($post['username'])) {
		$post['username'] = "none";
	}
	/* input validation */
	$reqdfields = array();
	$reqdfieldsn = array();
	$reqdfields = array("type");
	$reqdfieldsn = array(gettext("Service type"));

	if ($pconfig['type'] != "custom" && $pconfig['type'] != "custom-v6") {
		if ($pconfig['type'] != "dnsomatic") {
			$reqdfields[] = "host";
			$reqdfieldsn[] = gettext("Hostname");
		}
		$reqdfields[] = "passwordfld";
		$reqdfieldsn[] = gettext("Password");
		$reqdfields[] = "username";
		$reqdfieldsn[] = gettext("Username");
		if (in_array($pconfig['type'], $dyndns_split_domain_types)) {
			$reqdfields[] = "domainname";
			$reqdfieldsn[] = gettext("Domain name");
		}
	} else {
		$reqdfields[] = "updateurl";
		$reqdfieldsn[] = gettext("Update URL");
	}

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($post['passwordfld'] != $post['passwordfld_confirm']) {
		$input_errors[] = gettext("Password and confirmed password must match.");
	}

	if (isset($post['check_ip_mode']) && !in_array($post['check_ip_mode'], array_keys(dyndns_check_ip_mode_list()))) {
		$input_errors[] = gettext("The specified option for Check IP Mode is invalid.");
	}

	/*
	 * The service type and interfaces name the client's cache file and
	 * select the update code: only those the page offers.
	 */
	if (!empty($post['type']) && !array_key_exists($post['type'], dyndns_type_list())) {
		$input_errors[] = gettext("The specified Service Type is invalid.");
	}
	$iflist = dyndns_build_if_list();
	if (!array_key_exists($post['interface'], $iflist)) {
		$input_errors[] = gettext("A valid interface to monitor must be selected.");
	}
	if ((($post['type'] == "custom") || ($post['type'] == "custom-v6")) && !array_key_exists($post['requestif'], $iflist)) {
		$input_errors[] = gettext("A valid interface to send the update from must be selected.");
	}

	if (isset($post['host']) && in_array("host", $reqdfields)) {
		$allow_wildcard = false;
		if (((array_get_path($ddns_attr, "{$pconfig['type']}/apex") == true) && (($post['host'] == '@.') || ($post['host'] == '@'))) ||
		    ((array_get_path($ddns_attr, "{$pconfig['type']}/wildcard") == true) && (($post['host'] == '*.') || ($post['host'] == '*')))) {
			$host_to_check = $post['domainname'];
		} else {
			switch ($pconfig['type']) {
				case 'azure':
				case 'azurev6':
				case 'cloudflare':
				case 'cloudflare-v6':
				case 'gandi-livedns':
				case 'gandi-livedns-v6':
				case 'hover':
				case 'linode':
				case 'linode-v6':
				case 'name.com':
				case 'name.com-v6':
				case 'noip':
				case 'porkbun':
				case 'porkbun-v6':
				case 'yandex':
				case 'yandex-v6':
					$host_to_check = ($post['host'] == '@') ? $post['domainname'] : "{$post['host']}.{$post['domainname']}";
					$allow_wildcard = true;
					break;
				case 'route53':
				case 'route53-v6':
					$host_to_check = $post['host'];
					$allow_wildcard = true;
					break;
				default:
					$host_to_check = $post['host'];
			}
		}

		if ($pconfig['type'] != "custom" && $pconfig['type'] != "custom-v6") {
			if (!is_domain($host_to_check, $allow_wildcard)) {
				$input_errors[] = gettext("The hostname contains invalid characters.");
			}
		}

		unset($host_to_check);
	}
	if (($post['mx'] && !is_domain($post['mx']))) {
		$input_errors[] = gettext("The MX contains invalid characters.");
	}
	if ((in_array("username", $reqdfields) && $post['username'] && !is_dyndns_username($post['username'])) || ((in_array("username", $reqdfields)) && ($post['username'] == ""))) {
		$input_errors[] = gettext("The username contains invalid characters.");
	}
	if (isset($post['maxcacheage']) && $post['maxcacheage'] !== "" && (!is_numericint($post['maxcacheage']) || (int)$post['maxcacheage'] < 1)) {
		$input_errors[] = gettext("The max cache age must be an integer and greater than 0 (or empty for the default).");
	}

	if ($input_errors) {
		return array('input_errors' => $input_errors, 'id' => null);
	}

	$dyndns = array();
	if (array_get_path($post, 'check_ip_mode', 'default') != 'default') {
		$dyndns['check_ip_mode'] = $post['check_ip_mode'];
	}
	$dyndns['type'] = $post['type'];
	$dyndns['username'] = $post['username'];
	if ($post['passwordfld'] != DMYPWD) {
		$dyndns['password'] = base64_encode($post['passwordfld']);
	} else {
		$dyndns['password'] = $this_dyndns_config['password'];;
	}
	switch ($pconfig['type']) {
		case 'name.com':
		case 'name.com-v6':
			$dyndns['host'] = ($post['host'] == "@") ? '' : $post['host'];
			break;
		default:
			$dyndns['host'] = $post['host'];
			break;
	}
	$dyndns['domainname'] = $post['domainname'];
	$dyndns['mx'] = $post['mx'];
	$dyndns['wildcard'] = $post['wildcard'] ? true : false;
	$dyndns['proxied'] = $post['proxied'] ? true : false;
	$dyndns['verboselog'] = $post['verboselog'] ? true : false;
	$dyndns['curl_ipresolve_v4'] = $post['curl_ipresolve_v4'] ? true : false;
	$dyndns['curl_ssl_verifypeer'] = $post['curl_ssl_verifypeer'] ? true : false;
	// In this place enable means disabled
	if ($post['enable']) {
		unset($dyndns['enable']);
	} else {
		$dyndns['enable'] = true;
	}
	if (preg_match('/.+-v6/', $post['type']) && is_stf_interface($post['interface'])) {
		$dyndns['interface'] = $post['interface'] . '_stf';
	} else {
		$dyndns['interface'] = $post['interface'];
	}
	$dyndns['zoneid'] = $post['zoneid'];
	$dyndns['ttl'] = $post['ttl'];
	$dyndns['maxcacheage'] = $post['maxcacheage'];
	$dyndns['updateurl'] = $post['updateurl'];
	// Trim hard-to-type but sometimes returned characters
	$dyndns['resultmatch'] = trim($post['resultmatch'], "\t\n\r");
	($dyndns['type'] == "custom") ? $dyndns['requestif'] = $post['requestif'] : $dyndns['requestif'] = $post['interface'];
	if (($dyndns['type'] == "custom-v6") && !$dyndns['curl_ipresolve_v4'] && is_stf_interface($post['requestif'])) {
		$dyndns['requestif'] = $post['requestif'] . '_stf';
	} elseif (($dyndns['type'] == "custom") || ($dyndns['type'] == "custom-v6")) {
		$dyndns['requestif'] = $post['requestif'];
	}
	$dyndns['curl_proxy'] = $post['curl_proxy'] ? true : false;
	$dyndns['descr'] = $post['descr'];

	/**
	 * An update is forced when the interface changes because the IP
	 * for the new interface may already be cached; if that cache
	 * contains the same IP address as the new interface then the
	 * record is not updated.
	 */
	if (isset($post['force']) || $dup || ($post['interface'] != $this_dyndns_config['interface'])) {
		$dyndns['force'] = true;
	} else {
		$dyndns['force'] = false;
	}

	if ($dyndns['username'] == "none") {
		$dyndns['username'] = "";
	}

	if ($this_dyndns_config && !$dup) {
		config_set_path("dyndnses/dyndns/{$id}", $dyndns);
	} else {
		config_set_path("dyndnses/dyndns/", $dyndns);
		$id = count(config_get_path('dyndnses/dyndns', [])) - 1;
	}

	$dyndns['id'] = $id;
	//Probably overkill, but its better to be safe
	for ($i = 0; $i < count(config_get_path('dyndnses/dyndns', [])); $i++) {
		config_set_path("dyndnses/dyndns/{$i}/id", $i);
	}

	write_config(gettext("Dynamic DNS client configured."));

	services_dyndns_configure_client($dyndns);

	return array('input_errors' => array(), 'id' => (int)$id);
}

/* Delete client $id and its cache files. False when there is no such client. */
function dyndns_delete_client($id) {
	if (!is_numericint($id) || !is_array(config_get_path("dyndnses/dyndns/{$id}"))) {
		return false;
	}
	$conf = config_get_path("dyndnses/dyndns/{$id}");
	foreach (dyndns_client_cache_files($conf) as $cache) {
		@unlink($cache);
	}
	config_del_path("dyndnses/dyndns/{$id}");

	write_config(gettext("Dynamic DNS client deleted."));
	services_dyndns_configure();
	return true;
}

/* Enable or disable client $id and update the clients. False when there is no such client. */
function dyndns_toggle_client($id) {
	if (!is_numericint($id) || !config_get_path("dyndnses/dyndns/{$id}")) {
		return false;
	}
	if (config_path_enabled("dyndnses/dyndns/{$id}")) {
		config_del_path("dyndnses/dyndns/{$id}/enable");
		$wc_msg = gettext('Dynamic DNS client disabled.');
	} else {
		config_set_path("dyndnses/dyndns/{$id}/enable", true);
		$wc_msg = gettext('Dynamic DNS client enabled.');
	}
	write_config($wc_msg);
	services_dyndns_configure();
	return true;
}

/* ------------------------------------------------------------------ */
/* RFC 2136                                                            */
/* ------------------------------------------------------------------ */

function rfc2136_key_algos() {
	return array(
		'hmac-md5'    => 'HMAC-MD5 (legacy default)',
		'hmac-sha1'   => 'HMAC-SHA1',
		'hmac-sha224' => 'HMAC-SHA224',
		'hmac-sha256' => 'HMAC-SHA256 (current bind9 default)',
		'hmac-sha384' => 'HMAC-SHA384',
		'hmac-sha512' => 'HMAC-SHA512 (most secure)',
	);
}

/* The page's "Update Source" select (renamed from the page's build_us_list()). */
function rfc2136_build_us_list() {
	$list = array(
		'' => 'Default (use Interface above)',
		'none' => 'Do not specify',
	);

	return(array_merge($list, get_possible_listen_ips()));
}

function rfc2136_source_families() {
	return array(
		'' => 'Default',
		'inet' => 'IPv4',
		'inet6' => 'IPv6',
	);
}

function rfc2136_record_types() {
	return array('A' => 'A (IPv4)', 'AAAA' => 'AAAA (IPv6)', 'both' => 'Both');
}

/* The IPv4 and IPv6 cache files of a client (they hold "address|time"). */
function rfc2136_client_cache_files($conf) {
	$base = g_get('conf_path') . "/dyndns_{$conf['interface']}_rfc2136_" . escapeshellarg($conf['host']) . "_{$conf['server']}";
	return array("{$base}.cache", "{$base}_v6.cache");
}

/*
 * The update state the list page shows for an RFC 2136 client: the cached
 * addresses, whether each is the current one ("ok"/"fail", null without a
 * cache) and the overall "status" (IPv4, else IPv6; "unknown" before the
 * first update, or when $remote is false and only a check IP service could
 * tell the public IPv4 address).
 */
function rfc2136_client_status($conf, $remote = true) {
	list($file4, $file6) = rfc2136_client_cache_files($conf);
	$if = get_failover_interface($conf['interface']);
	$out = array('status' => 'unknown', 'status_ipv4' => null, 'status_ipv6' => null,
	    'cached_ip' => dyndns_cache_address($file4), 'cached_ipv6' => dyndns_cache_address($file6));
	if ($out['cached_ip'] !== null) {
		if (isset($conf['usepublicip'])) {
			$current = dyndns_current_ip($if, null, AF_INET, $remote);
			$out['status_ipv4'] = ($current === null) ? 'unknown' : (($current == $out['cached_ip']) ? 'ok' : 'fail');
		} else {
			$out['status_ipv4'] = (get_interface_ip($if) == $out['cached_ip']) ? 'ok' : 'fail';
		}
	}
	if ($out['cached_ipv6'] !== null) {
		$out['status_ipv6'] = (get_interface_ipv6($if) == $out['cached_ipv6']) ? 'ok' : 'fail';
	}
	$out['status'] = $out['status_ipv4'] ?? $out['status_ipv6'] ?? 'unknown';
	return $out;
}

/* The edit form's fields as the page preselects them for client $id ($dup: a copy without the host name). */
function rfc2136_client_settings($id, $dup = false) {
	$pconfig = array();
	$this_rfc2136_config = isset($id) ? config_get_path("dnsupdates/dnsupdate/{$id}") : null;
	if ($this_rfc2136_config) {
		$pconfig['enable'] = isset($this_rfc2136_config['enable']);
		if (!$dup) {
			$pconfig['host'] = $this_rfc2136_config['host'];
		}
		$pconfig['ttl'] = $this_rfc2136_config['ttl'];
		if (!$pconfig['ttl']) {
			$pconfig['ttl'] = 60;
		}
		$pconfig['zone'] = $this_rfc2136_config['zone'];
		$pconfig['keyname'] = $this_rfc2136_config['keyname'];
		$pconfig['keyalgorithm'] = $this_rfc2136_config['keyalgorithm'];
		$pconfig['keydata'] = $this_rfc2136_config['keydata'];
		$pconfig['server'] = $this_rfc2136_config['server'];
		$pconfig['interface'] = $this_rfc2136_config['interface'];
		$pconfig['updatesource'] = $this_rfc2136_config['updatesource'];
		$pconfig['updatesourcefamily'] = $this_rfc2136_config['updatesourcefamily'];
		$pconfig['usetcp'] = isset($this_rfc2136_config['usetcp']);
		$pconfig['usepublicip'] = isset($this_rfc2136_config['usepublicip']);
		$pconfig['recordtype'] = $this_rfc2136_config['recordtype'];
		if (!$pconfig['recordtype']) {
			$pconfig['recordtype'] = "both";
		}
		$pconfig['descr'] = $this_rfc2136_config['descr'];

	}
	return $pconfig;
}

/*
 * Save the edit form for client $id (null: a new client; $dup: a copy of
 * client $id) and run the RFC 2136 updates at once ("Save & Force Update"
 * posts "force" and forces this host's update). Returns input_errors and
 * the client's id.
 */
function rfc2136_save_client($post, $id, $dup = false) {
	$this_rfc2136_config = isset($id) ? config_get_path("dnsupdates/dnsupdate/{$id}") : null;
	$tsig_key_algos = rfc2136_key_algos();
	$input_errors = array();

	/* input validation */
	$reqdfields = array('host', 'ttl', 'keyname', 'keydata');
	$reqdfieldsn = array(gettext("Hostname"), gettext("TTL"), gettext("Key name"), gettext("Key"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($post['host'] && !is_domain($post['host'])) {
		$input_errors[] = gettext("The DNS update host name contains invalid characters.");
	}
	if ($post['zone'] && !is_domain($post['zone'])) {
		$input_errors[] = gettext("The DNS zone name contains invalid characters.");
	}
	if ($post['ttl'] && !is_numericint($post['ttl'])) {
		$input_errors[] = gettext("The DNS update TTL must be an integer.");
	}
	if ($post['keyname'] && !is_domain($post['keyname'])) {
		$input_errors[] = gettext("The DNS update key name contains invalid characters.");
	}
	if ($post['keyalgorithm'] && !array_key_exists($post['keyalgorithm'], $tsig_key_algos)) {
		$input_errors[] = gettext("The DNS update key algorithm is invalid.");
	}

	/*
	 * The key is written into the nsupdate key file in quotes and the
	 * server into the nsupdate commands and the cache file name; the
	 * selects must hold what the page offers.
	 */
	if (preg_match('/["\\\\\r\n]/', (string)$post['keydata'])) {
		$input_errors[] = gettext("The DNS update key contains an invalid character (quote, backslash or line break).");
	}
	if (((string)$post['server'] !== '') && (!preg_match('/^(\S+)(?: (\d+))?$/', $post['server'], $m) ||
	    !(is_ipaddr($m[1]) || is_hostname($m[1])) || (isset($m[2]) && !is_port($m[2])))) {
		$input_errors[] = gettext("The DNS update server must be an IP address or host name, optionally followed by a space and a port.");
	}
	if (!array_key_exists($post['interface'], dyndns_build_if_list())) {
		$input_errors[] = gettext("A valid interface must be selected.");
	}
	if (!array_key_exists((string)$post['updatesource'], rfc2136_build_us_list())) {
		$input_errors[] = gettext("A valid update source must be selected.");
	}
	if (!array_key_exists((string)$post['updatesourcefamily'], rfc2136_source_families())) {
		$input_errors[] = gettext("A valid update source family must be selected.");
	}
	if (!empty($post['recordtype']) && !array_key_exists($post['recordtype'], rfc2136_record_types())) {
		$input_errors[] = gettext("A valid record type must be selected.");
	}

	if ($input_errors) {
		return array('input_errors' => $input_errors, 'id' => null);
	}

	$rfc2136 = array();
	$rfc2136['enable'] = $post['enable'] ? true : false;
	$rfc2136['host'] = $post['host'];
	$rfc2136['zone'] = $post['zone'];
	$rfc2136['ttl'] = $post['ttl'];
	$rfc2136['keyname'] = $post['keyname'];
	$rfc2136['keyalgorithm'] = $post['keyalgorithm'];
	$rfc2136['keydata'] = $post['keydata'];
	$rfc2136['server'] = $post['server'];
	$rfc2136['usetcp'] = $post['usetcp'] ? true : false;
	$rfc2136['usepublicip'] = $post['usepublicip'] ? true : false;
	$rfc2136['recordtype'] = $post['recordtype'];
	$rfc2136['interface'] = $post['interface'];
	$rfc2136['updatesource'] = $post['updatesource'];
	$rfc2136['updatesourcefamily'] = $post['updatesourcefamily'];
	$rfc2136['descr'] = $post['descr'];

	if ($this_rfc2136_config && !$dup) {
		config_set_path("dnsupdates/dnsupdate/{$id}", $rfc2136);
	} else {
		config_set_path('dnsupdates/dnsupdate/', $rfc2136);
		$id = count(config_get_path('dnsupdates/dnsupdate', [])) - 1;
	}

	write_config(gettext("New/Edited RFC2136 dnsupdate entry was posted."));

	if ($post['force']) {
		$retval = services_dnsupdate_process("", $rfc2136['host'], true);
	} else {
		$retval = services_dnsupdate_process();
	}

	return array('input_errors' => array(), 'id' => (int)$id);
}

/* Delete client $id and its cache files. False when there is no such client. */
function rfc2136_delete_client($id) {
	if (!is_numericint($id) || !is_array(config_get_path("dnsupdates/dnsupdate/{$id}"))) {
		return false;
	}
	foreach (rfc2136_client_cache_files(config_get_path("dnsupdates/dnsupdate/{$id}")) as $cache) {
		@unlink($cache);
	}
	config_del_path("dnsupdates/dnsupdate/{$id}");
	write_config("RFC 2136 client deleted");
	return true;
}

/* Enable or disable client $id (like the page, no update is run). False when there is no such client. */
function rfc2136_toggle_client($id) {
	if (!is_numericint($id) || !config_get_path("dnsupdates/dnsupdate/{$id}")) {
		return false;
	}
	if (config_path_enabled("dnsupdates/dnsupdate/{$id}")) {
		config_del_path("dnsupdates/dnsupdate/{$id}/enable");
		$action = "disabled";
	} else {
		config_set_path("dnsupdates/dnsupdate/{$id}/enable", true);
		$action = "enabled";
	}
	write_config("RFC 2136 {$action}");
	return true;
}
