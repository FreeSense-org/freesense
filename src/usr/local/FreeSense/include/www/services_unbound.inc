<?php
/*
 * services_unbound.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2014 Warren Baker (warren@freesense.org)
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
 * DNS Resolver (Unbound) logic shared by services_unbound.php,
 * services_unbound_host_edit.php, services_unbound_domainoverride_edit.php,
 * services_unbound_acls.php, services_unbound_advanced.php and the REST API,
 * moved out of the pages with the form fields as parameters. Every change is
 * staged (the "unbound" dirty flag) until unbound_apply_changes() runs (the
 * pages' "Apply Changes"). None of these functions redirects.
 */

require_once("config.gui.inc");
require_once("certs.inc");
require_once("interfaces.inc");
require_once("services.inc");
require_once("system.inc");
require_once("unbound.inc");
require_once("util.inc");
require_once("freesense-utils.inc");

/*
 * Apply the staged changes (every DNS Resolver page's "Apply Changes").
 * Returns 0 on success.
 */
function unbound_apply_changes() {
	$retval = 0;
	$retval |= services_unbound_configure();
	if ($retval == 0) {
		clear_subsystem_dirty('unbound');
	}
	/* Update resolv.conf in case the interface bindings exclude localhost. */
	system_resolvconf_generate();
	/* Start or restart dhcpleases when it's necessary */
	system_dhcpleases_configure();
	if (unlink_if_exists(g_get('tmp_path') . "/.unbound_kea_resync")) {
		services_dhcpd_configure();
	}
	return $retval;
}

/* General settings */

/* The Python module scripts the general page offers (name => name). */
function unbound_python_scripts() {
	$python_files = glob(g_get('unbound_chroot_path') . "/*.py");
	$python_scripts = array();
	if (!empty($python_files)) {
		foreach ($python_files as $file) {
			$file = pathinfo($file, PATHINFO_FILENAME);
			$python_scripts[$file] = $file;
		}
	}
	else {
		$python_scripts = array('' => 'No Python Module scripts found');
	}
	return $python_scripts;
}

function unbound_certs_available() {
	return (count(config_get_path('cert', [])) > 0);
}

/* The general settings form's fields as the page fills them. */
function unbound_general_settings() {
	$pconfig = array();
	$pconfig['enable'] = config_path_enabled('unbound');
	$pconfig['enablessl'] = config_path_enabled('unbound', 'enablessl');
	$pconfig['strictout'] = config_path_enabled('unbound', 'strictout');
	$pconfig['dnssec'] = config_path_enabled('unbound', 'dnssec');
	$pconfig['python'] = config_path_enabled('unbound', 'python');
	$pconfig['forwarding'] = config_path_enabled('unbound', 'forwarding');
	$pconfig['forward_tls_upstream'] = config_path_enabled('unbound', 'forward_tls_upstream');
	$pconfig['regdhcp'] = config_path_enabled('unbound', 'regdhcp');
	$pconfig['regdhcpstatic'] = config_path_enabled('unbound', 'regdhcpstatic');
	$pconfig['regovpnclients'] = config_path_enabled('unbound', 'regovpnclients');

	$pconfig['python_order'] = config_get_path('unbound/python_order');
	$pconfig['python_script'] = config_get_path('unbound/python_script');
	$pconfig['port'] = config_get_path('unbound/port');
	$pconfig['tlsport'] = config_get_path('unbound/tlsport');
	$pconfig['sslcertref'] = config_get_path('unbound/sslcertref');
	$pconfig['custom_options'] = base64_decode(config_get_path('unbound/custom_options'));

	if (config_get_path('unbound/active_interface')) {
		$pconfig['active_interface'] = explode(",", config_get_path('unbound/active_interface'));
	} else {
		$pconfig['active_interface'] = array();
	}

	if (config_get_path('unbound/outgoing_interface')) {
		$pconfig['outgoing_interface'] = explode(",", config_get_path('unbound/outgoing_interface'));
	} else {
		$pconfig['outgoing_interface'] = array();
	}

	$pconfig['system_domain_local_zone_type'] = config_get_path('unbound/system_domain_local_zone_type', 'transparent');
	return $pconfig;
}

/* The interface address list of the general page (options and the preselected entries). */
function unbound_build_if_list($selectedifs) {
	$interface_addresses = get_possible_listen_ips(true);
	$iflist = array('options' => array(), 'selected' => array());

	$iflist['options']['all']	= gettext("All");
	if (empty($selectedifs) || empty($selectedifs[0]) || in_array("all", $selectedifs)) {
		array_push($iflist['selected'], "all");
	}

	foreach ($interface_addresses as $laddr => $ldescr) {
		$iflist['options'][$laddr] = htmlspecialchars($ldescr);

		if ($selectedifs && in_array($laddr, $selectedifs)) {
			array_push($iflist['selected'], $laddr);
		}
	}

	unset($interface_addresses);

	return($iflist);
}

/*
 * Save the general settings form ($post). The generated configuration must
 * pass unbound-checkconf. Returns input_errors (empty when saved) and
 * "pconfig", the form fields to show again.
 */
function unbound_save_general($post) {
	$python_scripts = unbound_python_scripts();
	$certs_available = unbound_certs_available();
	$pconfig = $post;
	$input_errors = array();
	$display_active_interface = null;
	$display_outgoing_interface = null;
	/* The interface lists are multi-selects (lists). */
	foreach (array('active_interface', 'outgoing_interface') as $field) {
		if (!empty($pconfig[$field]) && !is_array($pconfig[$field])) {
			$pconfig[$field] = array($pconfig[$field]);
		}
	}

	if (isset($pconfig['enable']) && config_path_enabled('dnsmasq')) {
		if ($pconfig['port'] == config_get_path('dnsmasq/port')) {
			$input_errors[] = gettext("The DNS Forwarder is enabled using this port. Choose a non-conflicting port, or disable the DNS Forwarder.");
		}
	}

	if (isset($pconfig['enablessl']) && (!$certs_available || empty($pconfig['sslcertref']))) {
		$input_errors[] = gettext("Acting as an SSL/TLS server requires a valid server certificate");
	}

	// forwarding mode requires having valid DNS servers
	if (isset($pconfig['forwarding'])) {
		$founddns = false;
		foreach (get_dns_nameservers(false, true) as $dns_server) {
			if (is_ipaddr($dns_server) && !ip_in_subnet($dns_server, "127.0.0.0/8") && !ip_in_subnet($dns_server, "::1/128")) {
				$founddns = true;
			}
		}
		if ($founddns == false) {
			$input_errors[] = gettext("At least one DNS server must be specified under System > General Setup to enable Forwarding mode.");
		}
	}

	if (empty($pconfig['active_interface'])) {
		$input_errors[] = gettext("One or more Network Interfaces must be selected for binding.");
	} elseif ((config_get_path('system/dnslocalhost') != 'remote') && (!in_array("lo0", $pconfig['active_interface']) && !in_array("all", $pconfig['active_interface']))) {
		$input_errors[] = gettext("This system is configured to use the DNS Resolver as its DNS server, so Localhost or All must be selected in Network Interfaces.");
	}

	if (empty($pconfig['outgoing_interface'])) {
		$input_errors[] = gettext("One or more Outgoing Network Interfaces must be selected.");
	}

	/* Only the interface addresses the page offers (they are written into unbound.conf). */
	$ifoptions = unbound_build_if_list(array())['options'];
	foreach (array('active_interface', 'outgoing_interface') as $field) {
		foreach ((array)($pconfig[$field] ?? array()) as $ifaddr) {
			if (!is_string($ifaddr) || !array_key_exists($ifaddr, $ifoptions)) {
				$input_errors[] = sprintf(gettext('%s is not an interface the DNS Resolver offers.'),
				    htmlspecialchars(is_string($ifaddr) ? $ifaddr : ''));
			}
		}
	}

	if ($pconfig['port'] && !is_port($pconfig['port'])) {
		$input_errors[] = gettext("A valid port number must be specified.");
	}
	if ($pconfig['tlsport'] && !is_port($pconfig['tlsport'])) {
		$input_errors[] = gettext("A valid SSL/TLS port number must be specified.");
	}

	if (is_array($pconfig['active_interface']) && !empty($pconfig['active_interface'])) {
		$display_active_interface = $pconfig['active_interface'];
		$pconfig['active_interface'] = implode(",", $pconfig['active_interface']);
	}

	if ((isset($pconfig['regdhcp']) || isset($pconfig['regdhcpstatic'])) && !is_dhcp_server_enabled()) {
		$input_errors[] = gettext("DHCP Server must be enabled for DHCP Registration to work in DNS Resolver.");
	}

	if (($pconfig['system_domain_local_zone_type'] == "redirect") && isset($pconfig['regdhcp'])) {
		$input_errors[] = gettext('A System Domain Local Zone Type of "redirect" is not compatible with dynamic DHCP Registration.');
	}

	if (isset($pconfig['python']) &&
	    !array_key_exists(array_get_path($pconfig, 'python_script'), $python_scripts)) {
		array_del_path($pconfig, 'python_script');
		$input_errors[] = gettext('The submitted Python Module Script does not exist or is invalid.');
	}

	$display_custom_options = $pconfig['custom_options'];
	$pconfig['custom_options'] = base64_encode(str_replace("\r\n", "\n", $pconfig['custom_options']));

	if (is_array($pconfig['outgoing_interface']) && !empty($pconfig['outgoing_interface'])) {
		$display_outgoing_interface = $pconfig['outgoing_interface'];
		$pconfig['outgoing_interface'] = implode(",", $pconfig['outgoing_interface']);
	}

	$test_output = array();
	if (test_unbound_config(array_merge(config_get_path('unbound', []), $pconfig), $test_output)) {
		$input_errors[] = gettext("The generated config file cannot be parsed by unbound. Please correct the following errors:");
		$input_errors = array_merge($input_errors, $test_output);
	}

	if (!$input_errors) {
		/* Kea only loads its DNS registration hooks while Unbound is
		 * enabled, so it must be reconfigured when that changes. */
		if ((isset($pconfig['enable']) != config_path_enabled('unbound')) &&
		    dhcp_is_backend('kea')) {
			@touch(g_get('tmp_path') . "/.unbound_kea_resync");
		}
		config_set_path('unbound/enable', isset($pconfig['enable']));
		config_set_path('unbound/enablessl', isset($pconfig['enablessl']));
		config_set_path('unbound/port', $pconfig['port']);
		config_set_path('unbound/tlsport', $pconfig['tlsport']);
		config_set_path('unbound/sslcertref', $pconfig['sslcertref']);
		config_set_path('unbound/strictout', isset($pconfig['strictout']));
		config_set_path('unbound/dnssec', isset($pconfig['dnssec']));

		config_set_path('unbound/python', isset($pconfig['python']));
		if (isset($pconfig['python'])) {
			config_set_path('unbound/python_order', $pconfig['python_order']);
			config_set_path('unbound/python_script', $pconfig['python_script']);
		} else {
			config_del_path('unbound/python_order');
			config_del_path('unbound/python_script');
		}

		config_set_path('unbound/forwarding', isset($pconfig['forwarding']));
		config_set_path('unbound/forward_tls_upstream', isset($pconfig['forward_tls_upstream']));
		config_set_path('unbound/regdhcp', isset($pconfig['regdhcp']));
		config_set_path('unbound/regdhcpstatic', isset($pconfig['regdhcpstatic']));
		config_set_path('unbound/regovpnclients', isset($pconfig['regovpnclients']));
		config_set_path('unbound/active_interface', $pconfig['active_interface']);
		config_set_path('unbound/outgoing_interface', $pconfig['outgoing_interface']);
		config_set_path('unbound/system_domain_local_zone_type', $pconfig['system_domain_local_zone_type']);
		config_set_path('unbound/custom_options', $pconfig['custom_options']);

		write_config(gettext("DNS Resolver configured."));
		mark_subsystem_dirty('unbound');
	}

	$pconfig['active_interface'] = $display_active_interface;
	$pconfig['outgoing_interface'] = $display_outgoing_interface;
	$pconfig['custom_options'] = $display_custom_options;

	return array('input_errors' => $input_errors, 'pconfig' => $pconfig);
}

/*
 * Delete the host override ($type "host") or domain override ("doverride")
 * at position $id. Returns true when it existed.
 */
function unbound_delete_override($type, $id) {
	if (!is_numericint($id)) {
		return false;
	}
	if ($type == 'host') {
		if (config_get_path('unbound/hosts/' . $id)) {
			config_del_path('unbound/hosts/' . $id);
			write_config(gettext("Host override deleted from DNS Resolver."));
			mark_subsystem_dirty('unbound');
			return true;
		}
	} elseif ($type == 'doverride') {
		if (config_get_path('unbound/domainoverrides/' . $id)) {
			config_del_path('unbound/domainoverrides/' . $id);
			write_config(gettext("Domain override deleted from DNS Resolver."));
			mark_subsystem_dirty('unbound');
			return true;
		}
	}
	return false;
}

/* Host overrides */

function unbound_hostcmp($a, $b) {
	return strcasecmp($a['host'], $b['host']);
}

function unbound_hosts_sort() {
	$hosts = config_get_path('unbound/hosts', []);
	if (empty($hosts)) {
		return;
	}
	usort($hosts, "unbound_hostcmp");
	config_set_path('unbound/hosts', $hosts);
}

/* The host override edit form's fields for the entry at position $id. */
function unbound_host_settings($id) {
	$pconfig = [];
	if (isset($id) &&
	    config_get_path('unbound/hosts/' . $id)) {
		$pconfig['host']    = config_get_path('unbound/hosts/' . $id . '/host');
		$pconfig['domain']  = config_get_path('unbound/hosts/' . $id . '/domain');
		$pconfig['ip']      = config_get_path('unbound/hosts/' . $id . '/ip');
		$pconfig['descr']   = config_get_path('unbound/hosts/' . $id . '/descr');
		$pconfig['aliases'] = config_get_path('unbound/hosts/' . $id . '/aliases');
	}
	return $pconfig;
}

/*
 * Save the host override form ($post: host, domain, ip, descr and the alias
 * rows aliashost0/aliasdomain0/aliasdescription0, ...) as the entry at
 * position $id, or as a new entry when $id is not set or does not exist. The
 * list is kept sorted by host. Returns input_errors (empty when saved),
 * "pconfig" (the form fields to show again) and "id", the entry's position
 * after sorting.
 */
function unbound_save_host($post, $id) {
	$id = is_numericint($id) ? $id : null;
	$input_errors = array();
	$pconfig = $post;

	/* input validation */
	$reqdfields = explode(" ", "domain ip");
	$reqdfieldsn = array(gettext("Domain"), gettext("IP address"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($post['host']) {
		if (!is_hostname($post['host'])) {
			$input_errors[] = gettext("The hostname can only contain the characters A-Z, 0-9, '_' and '-'. It may not start or end with '-'.");
		} else {
			if (!is_unqualified_hostname($post['host'])) {
				$input_errors[] = gettext("A valid hostname is specified, but the domain name part should be omitted");
			}
		}
	}

	if (($post['domain'] && !is_domain($post['domain']))) {
		$input_errors[] = gettext("A valid domain must be specified.");
	}

	if ($post['ip']) {
		foreach (explode(',', $post['ip']) as $ip) {
			if (!is_ipaddr($ip)) {
				$input_errors[] = gettext("A valid IP addresses must be specified.");
				break;
			}
		}
	}

	/* collect aliases */
	$aliases = array();
	foreach ($post as $key => $value) {
		$entry = '';
		if (!substr_compare('aliashost', $key, 0, 9)) {
			$entry = substr($key, 9);
			$field = 'host';
		} elseif (!substr_compare('aliasdomain', $key, 0, 11)) {
			$entry = substr($key, 11);
			$field = 'domain';
		} elseif (!substr_compare('aliasdescription', $key, 0, 16)) {
			$entry = substr($key, 16);
			$field = 'description';
		}
		if (ctype_digit(strval($entry))) {
			array_set_path($aliases, "{$entry}/{$field}", $value);
		}
	}

	array_set_path($pconfig, 'aliases/item', $aliases);

	/* validate aliases */
	foreach ($aliases as $idx => $alias) {
		if ((count($aliases) > 1) ||
		    !empty($alias['host'])) {
			$aliasreqdfields = array('aliasdomain' . $idx);
			$aliasreqdfieldsn = array(gettext("Alias Domain"));

			do_input_validation($post, $aliasreqdfields, $aliasreqdfieldsn, $input_errors);
		}

		/* Remove empty values */
		if (empty($alias['host']) &&
		    empty($alias['domain'])) {
			unset($aliases[$idx]);
			continue;
		}

		if ($alias['host']) {
			if (!is_hostname($alias['host'])) {
				$input_errors[] = gettext("Hostnames in an alias list can only contain the characters A-Z, 0-9 and '-'. They may not start or end with '-'.");
			} else {
				if (!is_unqualified_hostname($alias['host'])) {
					$input_errors[] = gettext("A valid alias hostname is specified, but the domain name part should be omitted");
				}
			}
		}
		if (($alias['domain'] && !is_domain($alias['domain']))) {
			$input_errors[] = gettext("A valid domain must be specified in alias list.");
		}
	}

	/* check for overlaps */
	foreach (config_get_path('unbound/hosts', []) as $hostent) {
		if (isset($id) && (config_get_path('unbound/hosts/' . $id) === $hostent)) {
			continue;
		}

		if (($hostent['host'] == $post['host']) &&
		    ($hostent['domain'] == $post['domain'])) {
			if (is_ipaddrv4($hostent['ip']) && is_ipaddrv4($post['ip'])) {
				$input_errors[] = gettext("This host/domain override combination already exists with an IPv4 address.");
				break;
			}
			if (is_ipaddrv6($hostent['ip']) && is_ipaddrv6($post['ip'])) {
				$input_errors[] = gettext("This host/domain override combination already exists with an IPv6 address.");
				break;
			}
		}
	}

	$saved_id = null;
	if (!$input_errors) {
		$hostent = array();
		$hostent['host'] = $post['host'];
		$hostent['domain'] = $post['domain'];
		$hostent['ip'] = $post['ip'];
		$hostent['descr'] = $post['descr'];
		array_set_path($hostent, 'aliases/item', $aliases);

		if (isset($id) && config_get_path('unbound/hosts/' . $id)) {
			config_set_path('unbound/hosts/' . $id, $hostent);
		} else {
			config_set_path('unbound/hosts/' . count(config_get_path('unbound/hosts', [])) + 1, $hostent);
		}
		unbound_hosts_sort();

		/* The entry's position in the sorted list. */
		foreach (config_get_path('unbound/hosts', []) as $pos => $entry) {
			if ($entry === $hostent) {
				$saved_id = $pos;
				break;
			}
		}

		mark_subsystem_dirty('unbound');

		write_config(gettext("Host override configured for DNS Resolver."));
	}

	return array('input_errors' => $input_errors, 'pconfig' => $pconfig, 'id' => $saved_id);
}

/* Domain overrides */

/* The domain override edit form's fields for the entry at position $id (empty when it does not exist). */
function unbound_domain_override_settings($id) {
	$pconfig = array();
	$this_domainOverrides_config = isset($id) ? config_get_path("unbound/domainoverrides/{$id}") : null;
	if ($this_domainOverrides_config) {
		$pconfig['domain'] = $this_domainOverrides_config['domain'];
		$pconfig['ip'] = $this_domainOverrides_config['ip'];
		$pconfig['descr'] = $this_domainOverrides_config['descr'];
		$pconfig['tls_hostname'] = $this_domainOverrides_config['tls_hostname'];
		$pconfig['forward_tls_upstream'] = isset($this_domainOverrides_config['forward_tls_upstream']);
	}
	return $pconfig;
}

/*
 * Save the domain override form ($post: domain, ip, descr, tls_hostname,
 * forward_tls_upstream) as the entry at position $id, or as a new entry when
 * $id is not set or does not exist. Returns the input errors.
 */
function unbound_save_domain_override($post, $id) {
	$id = is_numericint($id) ? $id : null;
	$input_errors = array();
	$this_domainOverrides_config = isset($id) ? config_get_path("unbound/domainoverrides/{$id}") : null;

	/* input validation */
	$reqdfields = explode(" ", "domain ip");
	$reqdfieldsn = array(gettext("Domain"), gettext("IP address"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (str_starts_with($post['domain'], '_msdcs')) {
		$subdomainstr = substr($post['domain'], 7);
		if ($subdomainstr && !is_domain($subdomainstr)) {
			$input_errors[] = gettext("A valid domain must be specified after _msdcs.");
		}
	} elseif ($post['domain'] && !is_domain($post['domain'])) {
		$input_errors[] = gettext("A valid domain must be specified.");
	}

	if ($post['ip']) {
		if (strpos($post['ip'], '@') !== false) {
			$ip_details = explode("@", $post['ip']);
			if (!is_ipaddr($ip_details[0]) || !is_port($ip_details[1])) {
				$input_errors[] = gettext("A valid IP address and port must be specified, for example 192.168.100.10@5353.");
			}
		} else if (!is_ipaddr($post['ip'])) {
			$input_errors[] = gettext("A valid IP address must be specified, for example 192.168.100.10.");
		}
	}

	if (!empty($post['tls_hostname']) && !is_hostname($post['tls_hostname'])) {
		$input_errors[] = gettext("The supplied TLS hostname is not valid.");
	}

	if (!$input_errors) {
		$doment = array();
		$doment['domain'] = $post['domain'];
		$doment['ip'] = $post['ip'];
		$doment['descr'] = $post['descr'];
		$doment['tls_hostname'] = $post['tls_hostname'];
		$doment['forward_tls_upstream'] = isset($post['forward_tls_upstream']);

		if ($this_domainOverrides_config) {
			config_set_path("unbound/domainoverrides/{$id}", $doment);
		} else {
			config_set_path('unbound/domainoverrides/', $doment);
		}

		mark_subsystem_dirty('unbound');

		write_config(gettext("Domain override configured for DNS Resolver."));
	}

	return $input_errors;
}

/* Access lists */

/* The actions an access list can take (the edit form's choices). */
function unbound_acl_actions() {
	return array('allow' => gettext('Allow'), 'deny' => gettext('Deny'), 'refuse' => gettext('Refuse'),
	    'allow snoop' => gettext('Allow Snoop'), 'deny nonlocal' => gettext('Deny Nonlocal'),
	    'refuse nonlocal' => gettext('Refuse Nonlocal'));
}

/* Delete the access list at position $id. Returns true when it existed. */
function unbound_delete_acl($id) {
	if (!is_numericint($id) || !config_get_path('unbound/acls/' . $id)) {
		return false;
	}

	config_del_path('unbound/acls/' . $id);
	write_config(gettext("Access list deleted from DNS Resolver."));
	mark_subsystem_dirty('unbound');
	return true;
}

/*
 * Save the access list form ($post: aclname, aclaction, description and the
 * network rows acl_network0/mask0/description0, ... up to 50) as the access
 * list at position $id, or as a new one when $id is not set or does not
 * exist. A new access list gets the next free ACL id; an edited one keeps its
 * id. Returns input_errors (empty when saved) and "networkacl", the network
 * rows to show again.
 */
function unbound_save_acl($post, $id) {
	$id = is_numericint($id) ? $id : null;
	$input_errors = array();
	$pconfig = $post;
	$existing = isset($id) ? config_get_path('unbound/acls/' . $id) : null;
	$networkacl = is_array($existing) ? array_get_path($existing, 'row', array()) : array();
	if (!is_array($networkacl)) {
		$networkacl = array();
	}

	// input validation - only allow 50 entries in a single ACL
	for ($x = 0; $x < 50; $x++) {
		if (isset($pconfig["acl_network{$x}"])) {
			$networkacl[$x] = array();
			$networkacl[$x]['acl_network'] = $pconfig["acl_network{$x}"];
			$networkacl[$x]['mask'] = $pconfig["mask{$x}"];
			$networkacl[$x]['description'] = $pconfig["description{$x}"];
			if (!is_ipaddr($networkacl[$x]['acl_network'])) {
				$input_errors[] = gettext("A valid IP address must be entered for each row under Networks.");
			}

			if (is_ipaddr($networkacl[$x]['acl_network'])) {
				if (!is_subnet($networkacl[$x]['acl_network']."/".$networkacl[$x]['mask'])) {
					$input_errors[] = gettext("A valid IPv4 netmask must be entered for each IPv4 row under Networks.");
				}
			} else if (function_exists("is_ipaddrv6")) {
				if (!is_ipaddrv6($networkacl[$x]['acl_network'])) {
					$input_errors[] = gettext("A valid IPv6 address must be entered for {$networkacl[$x]['acl_network']}.");
				} else if (!is_subnetv6($networkacl[$x]['acl_network']."/".$networkacl[$x]['mask'])) {
					$input_errors[] = gettext("A valid IPv6 netmask must be entered for each IPv6 row under Networks.");
				}
			} else {
				$input_errors[] = gettext("A valid IP address must be entered for each row under Networks.");
			}
		} else if (isset($networkacl[$x])) {
			unset($networkacl[$x]);
		}
	}

	/* The action and the name are written into access_lists.conf. */
	if (!array_key_exists((string)($pconfig['aclaction'] ?? ''), unbound_acl_actions())) {
		$input_errors[] = gettext("A valid action must be selected.");
	}
	if (preg_match('/[\r\n]/', (string)($pconfig['aclname'] ?? ''))) {
		$input_errors[] = gettext("The access list name cannot contain line breaks.");
	}

	if (!$input_errors) {
		$acl_entry = array();
		$acl_entry['aclid'] = (is_array($existing) && isset($existing['aclid']) && ($existing['aclid'] !== '')) ?
		    $existing['aclid'] : unbound_get_next_id();
		$acl_entry['aclname'] = $pconfig['aclname'];
		$acl_entry['aclaction'] = $pconfig['aclaction'];
		$acl_entry['description'] = $pconfig['description'];
		$acl_entry['row'] = array();

		foreach ($networkacl as $acl) {
			$acl_entry['row'][] = $acl;
		}

		if (is_array($existing)) {
			config_set_path('unbound/acls/' . $id, $acl_entry);
		} else {
			config_set_path('unbound/acls/', $acl_entry);
		}

		mark_subsystem_dirty("unbound");
		write_config(gettext("Access list configured for DNS Resolver."));
	}

	return array('input_errors' => $input_errors, 'networkacl' => $networkacl);
}

/* Advanced settings */

function unbound_edns_sizes() {
	return array(
		"auto" => "Automatic value based on active interface MTUs",
		"512"  => "512: IPv4 Minimum",
		"1220" => "1220: NSD IPv6 EDNS Minimum",
		"1232" => "1232: IPv6 Minimum",
		"1432" => "1432: 1500 Byte MTU",
		"1480" => "1480: NSD IPv4 EDNS Minimum",
		"4096" => "4096: Unbound Default"
	);
}

/* The values the advanced settings accept for each select (the first is the form's default choice). */
function unbound_advanced_choices() {
	return array(
		'msgcachesize' => array('4', '10', '20', '50', '100', '250', '512'),
		'outgoing_num_tcp' => array('0', '10', '20', '30', '40', '50'),
		'incoming_num_tcp' => array('0', '10', '20', '30', '40', '50'),
		'edns_buffer_size' => array_map('strval', array_keys(unbound_edns_sizes())),
		'num_queries_per_thread' => array('512', '1024', '2048'),
		'jostle_timeout' => array('100', '200', '500', '1000'),
		'infra_host_ttl' => array('60', '120', '300', '600', '900'),
		'infra_cache_numhosts' => array('1000', '5000', '10000', '20000', '50000', '100000', '200000'),
		'unwanted_reply_threshold' => array('disabled', '5000000', '10000000', '20000000', '40000000', '50000000'),
		'log_verbosity' => array('0', '1', '2', '3', '4', '5'),
	);
}

/* The advanced settings form's fields as the page fills them. */
function unbound_advanced_settings() {
	$pconfig = array();
	$pconfig['hideidentity'] = config_path_enabled('unbound', 'hideidentity');
	$pconfig['hideversion'] = config_path_enabled('unbound', 'hideversion');
	$pconfig['qname-minimisation'] = config_path_enabled('unbound', 'qname-minimisation');
	$pconfig['qname-minimisation-strict'] = config_path_enabled('unbound', 'qname-minimisation-strict');
	$pconfig['prefetch'] = config_path_enabled('unbound', 'prefetch');
	$pconfig['prefetchkey'] = config_path_enabled('unbound', 'prefetchkey');
	$pconfig['dnssecstripped'] = config_path_enabled('unbound', 'dnssecstripped');
	$pconfig['dnsrecordcache'] = config_path_enabled('unbound', 'dnsrecordcache');
	$pconfig['sock_queue_timeout'] = config_get_path('unbound/sock_queue_timeout', 0);
	$pconfig['aggressivensec'] = config_path_enabled('unbound', 'aggressivensec');

	$pconfig['msgcachesize'] = config_get_path('unbound/msgcachesize');
	$pconfig['outgoing_num_tcp'] = config_get_path('unbound/outgoing_num_tcp', 10);
	$pconfig['incoming_num_tcp'] = config_get_path('unbound/incoming_num_tcp', 10);
	$pconfig['edns_buffer_size'] = config_get_path('unbound/edns_buffer_size', 'auto');
	$pconfig['num_queries_per_thread'] = config_get_path('unbound/num_queries_per_thread');
	$pconfig['jostle_timeout'] = config_get_path('unbound/jostle_timeout', 200);
	$pconfig['cache_max_ttl'] = config_get_path('unbound/cache_max_ttl', 86400);
	$pconfig['cache_min_ttl'] = config_get_path('unbound/cache_min_ttl', 0);

	/* default to enabled if not explicitly set */
	if (config_get_path('unbound/infra_keep_probing', 'enabled') == "enabled") {
		$pconfig['infra_keep_probing'] = true;
	}

	$pconfig['infra_host_ttl'] = config_get_path('unbound/infra_host_ttl', 900);
	$pconfig['infra_cache_numhosts'] = config_get_path('unbound/infra_cache_numhosts', 10000);
	$pconfig['unwanted_reply_threshold'] = config_get_path('unbound/unwanted_reply_threshold', 'disabled');
	$pconfig['log_verbosity'] = config_get_path('unbound/log_verbosity', 1);

	$pconfig['disable_auto_added_access_control'] = config_path_enabled('unbound', 'disable_auto_added_access_control');
	$pconfig['disable_auto_added_host_entries'] = config_path_enabled('unbound', 'disable_auto_added_host_entries');
	$pconfig['use_caps'] = config_path_enabled('unbound', 'use_caps');

	$pconfig['dns64_enable'] = config_path_enabled('unbound/dns64');
	$pconfig['dns64_prefix'] = config_get_path('unbound/dns64/prefix', '');
	$pconfig['allow_dns64_for_localhost'] = config_path_enabled('unbound/dns64', 'allow_dns64_for_localhost');
	return $pconfig;
}

/* Save the advanced settings form ($post). Returns the input errors. */
function unbound_save_advanced($post) {
	$input_errors = array();
	$choices = unbound_advanced_choices();

	if (isset($post['msgcachesize']) && !in_array($post['msgcachesize'], $choices['msgcachesize'], true)) {
		$input_errors[] = gettext("A valid value for Message Cache Size must be specified.");
	}
	if (isset($post['outgoing_num_tcp']) && !in_array($post['outgoing_num_tcp'], $choices['outgoing_num_tcp'], true)) {
		$input_errors[] = gettext("A valid value must be specified for Outgoing TCP Buffers.");
	}
	if (isset($post['incoming_num_tcp']) && !in_array($post['incoming_num_tcp'], $choices['incoming_num_tcp'], true)) {
		$input_errors[] = gettext("A valid value must be specified for Incoming TCP Buffers.");
	}
	if (isset($post['edns_buffer_size']) && !in_array($post['edns_buffer_size'], $choices['edns_buffer_size'], true)) {
		$input_errors[] = gettext("A valid value must be specified for EDNS Buffer Size.");
	}
	if (isset($post['num_queries_per_thread']) && !in_array($post['num_queries_per_thread'], $choices['num_queries_per_thread'], true)) {
		$input_errors[] = gettext("A valid value must be specified for Number of Queries per Thread.");
	}
	if (isset($post['jostle_timeout']) && !in_array($post['jostle_timeout'], $choices['jostle_timeout'], true)) {
		$input_errors[] = gettext("A valid value must be specified for Jostle Timeout.");
	}
	if (isset($post['cache_max_ttl']) && (!is_numericint($post['cache_max_ttl']) || ($post['cache_max_ttl'] < 0))) {
		$input_errors[] = gettext("'Maximum TTL for RRsets and Messages' must be a positive integer.");
	}
	if (isset($post['cache_min_ttl']) && (!is_numericint($post['cache_min_ttl']) || ($post['cache_min_ttl'] < 0))) {
		$input_errors[] = gettext("'Minimum TTL for RRsets and Messages' must be a positive integer.");
	}
	if (isset($post['infra_host_ttl']) && !in_array($post['infra_host_ttl'], $choices['infra_host_ttl'], true)) {
		$input_errors[] = gettext("A valid value must be specified for TTL for Host Cache Entries.");
	}
	if (isset($post['infra_cache_numhosts']) && !in_array($post['infra_cache_numhosts'], $choices['infra_cache_numhosts'], true)) {
		$input_errors[] = gettext("A valid value must be specified for Number of Hosts to Cache.");
	}
	if (isset($post['unwanted_reply_threshold']) && !in_array($post['unwanted_reply_threshold'], $choices['unwanted_reply_threshold'], true)) {
		$input_errors[] = gettext("A valid value must be specified for Unwanted Reply Threshold.");
	}
	if (isset($post['log_verbosity']) && !in_array($post['log_verbosity'], $choices['log_verbosity'], true)) {
		$input_errors[] = gettext("A valid value must be specified for Log Level.");
	}
	if (isset($post['dnssecstripped']) && !config_path_enabled('unbound', 'dnssec')) {
		$input_errors[] = gettext("Harden DNSSEC Data option can only be enabled if DNSSEC support is enabled.");
	}
	if (!empty($post['dns64_prefix']) && !validate_nat64_prefix($post['dns64_prefix'])) {
		$input_errors[] = gettext("The DNS64 prefix is invalid.");
	}
	/* Written into unbound.conf as is (empty means 0). */
	if (isset($post['sock_queue_timeout']) && ($post['sock_queue_timeout'] !== '') && !is_numericint($post['sock_queue_timeout'])) {
		$input_errors[] = gettext("'Drop Old UDP Queries' must be a non-negative integer.");
	}

	if (!$input_errors) {
		if (isset($post['hideidentity'])) {
			config_set_path('unbound/hideidentity', true);
		} else {
			config_del_path('unbound/hideidentity');
		}
		if (isset($post['hideversion'])) {
			config_set_path('unbound/hideversion', true);
		} else {
			config_del_path('unbound/hideversion');
		}
		if (isset($post['qname-minimisation'])) {
			config_set_path('unbound/qname-minimisation', true);
		} else {
			config_del_path('unbound/qname-minimisation');
		}
		if (isset($post['qname-minimisation-strict'])) {
			config_set_path('unbound/qname-minimisation-strict', true);
		} else {
			config_del_path('unbound/qname-minimisation-strict');
		}
		if (isset($post['prefetch'])) {
			config_set_path('unbound/prefetch', true);
		} else {
			config_del_path('unbound/prefetch');
		}
		if (isset($post['prefetchkey'])) {
			config_set_path('unbound/prefetchkey', true);
		} else {
			config_del_path('unbound/prefetchkey');
		}
		if (isset($post['dnssecstripped'])) {
			config_set_path('unbound/dnssecstripped', true);
		} else {
			config_del_path('unbound/dnssecstripped');
		}
		if (isset($post['dnsrecordcache'])) {
			config_set_path('unbound/dnsrecordcache', true);
		} else {
			config_del_path('unbound/dnsrecordcache');
		}
		config_set_path('unbound/sock_queue_timeout', $post['sock_queue_timeout']);
		if (isset($post['aggressivensec'])) {
			config_set_path('unbound/aggressivensec', true);
		} else {
			config_del_path('unbound/aggressivensec');
		}
		config_set_path('unbound/msgcachesize', $post['msgcachesize']);
		config_set_path('unbound/outgoing_num_tcp', $post['outgoing_num_tcp']);
		config_set_path('unbound/incoming_num_tcp', $post['incoming_num_tcp']);
		config_set_path('unbound/edns_buffer_size', $post['edns_buffer_size']);
		config_set_path('unbound/num_queries_per_thread', $post['num_queries_per_thread']);
		config_set_path('unbound/jostle_timeout', $post['jostle_timeout']);
		config_set_path('unbound/cache_max_ttl', $post['cache_max_ttl']);
		config_set_path('unbound/cache_min_ttl', $post['cache_min_ttl']);
		if (isset($post['infra_keep_probing'])) {
			config_set_path('unbound/infra_keep_probing', "enabled");
		} else {
			config_set_path('unbound/infra_keep_probing', "disabled");
		}
		config_set_path('unbound/infra_host_ttl', $post['infra_host_ttl']);
		config_set_path('unbound/infra_cache_numhosts', $post['infra_cache_numhosts']);
		config_set_path('unbound/unwanted_reply_threshold', $post['unwanted_reply_threshold']);
		config_set_path('unbound/log_verbosity', $post['log_verbosity']);

		if (isset($post['disable_auto_added_access_control'])) {
			config_set_path('unbound/disable_auto_added_access_control', true);
		} else {
			config_del_path('unbound/disable_auto_added_access_control');
		}

		if (isset($post['disable_auto_added_host_entries'])) {
			config_set_path('unbound/disable_auto_added_host_entries', true);
		} else {
			config_del_path('unbound/disable_auto_added_host_entries');
		}

		if (isset($post['use_caps'])) {
			config_set_path('unbound/use_caps', true);
		} else {
			config_del_path('unbound/use_caps');
		}

		if (isset($post['dns64_enable'])) {
			config_set_path('unbound/dns64/enable', true);
		} else {
			config_del_path('unbound/dns64/enable');
		}
		if (config_path_enabled('system', 'allow_nat64_prefix_override') && !empty($post['dns64_prefix'])) {
			config_set_path('unbound/dns64/prefix', $post['dns64_prefix']);
		} else {
			config_del_path('unbound/dns64/prefix');
		}
		if ($post['allow_dns64_for_localhost'] == "yes") {
			config_set_path('unbound/dns64/allow_dns64_for_localhost', true);
		} else {
			config_del_path('unbound/dns64/allow_dns64_for_localhost');
		}
		if (empty(config_get_path('unbound/dns64'))) {
			config_del_path('unbound/dns64');
		}

		write_config(gettext("DNS Resolver configured."));

		mark_subsystem_dirty('unbound');
	}

	return $input_errors;
}
