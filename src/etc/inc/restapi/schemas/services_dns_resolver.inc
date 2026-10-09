<?php
/*
 * services_dns_resolver.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
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
 * Schemas of the DNS Resolver (Unbound) editors, /api/v1/services/dns-resolver:
 *
 *   services/dns_resolver           general settings (services_unbound.php)
 *   services/dns_resolver_advanced  advanced settings (services_unbound_advanced.php)
 *   services/dns_host_override      a host override (services_unbound_host_edit.php)
 *   services/dns_domain_override    a domain override (services_unbound_domainoverride_edit.php)
 *   services/dns_access_list        an access list (services_unbound_acls.php)
 *
 * Field names are the pages' form fields, which the API takes (the host
 * aliases and the access list networks, numbered rows on the pages, are
 * entry grids). Choices come from the functions the pages use
 * (services_unbound.inc, unbound.inc), so the form offers what the save
 * functions accept. "errorMatch" lists the wording of every save message
 * that names a field differently than its label.
 */

/* The general settings form. */
function restapi_schema_services_dns_resolver() {
	$ifaces = function_exists('unbound_build_if_list') ? restapi_schema_choices(unbound_build_if_list(array())['options']) : array();
	$zones = function_exists('unbound_local_zone_types') ? restapi_schema_choices(unbound_local_zone_types()) : array();
	$scripts = function_exists('unbound_python_scripts') ? restapi_schema_choices(unbound_python_scripts()) : array();
	$certs_available = function_exists('unbound_certs_available') && unbound_certs_available();
	$certs = ($certs_available && function_exists('cert_build_list')) ? restapi_schema_choices(cert_build_list('cert', 'IPsec')) : array();
	$isc = function_exists('dhcp_is_backend') ? dhcp_is_backend('isc') : true;
	$ssl = array('field' => 'enablessl', 'truthy' => true);
	$python = array('field' => 'python', 'truthy' => true);

	$general = array(
		array('name' => 'enable', 'type' => 'switch', 'label' => gettext('Enable'), 'text' => gettext('Enable DNS resolver')),
		array('name' => 'port', 'type' => 'port', 'label' => gettext('Listen port'), 'range' => false, 'alias' => false, 'placeholder' => '53', 'width' => 'third',
		    'help' => gettext('The port used for responding to DNS queries. It should normally be left blank unless another service needs to bind to TCP/UDP port 53.'),
		    'errorMatch' => array('valid port number', 'using this port')),
		array('name' => 'active_interface', 'type' => 'checklist', 'label' => gettext('Network interfaces'), 'required' => true, 'options' => $ifaces,
		    'help' => gettext('Interface IP addresses used by the DNS Resolver for responding to queries from clients. If an interface has both IPv4 and IPv6 addresses, both are used. Queries to addresses not selected in this list are discarded. The default behavior is to respond to queries on every available IPv4 and IPv6 address.'),
		    'errorMatch' => array('is not an interface the dns resolver offers')),
		array('name' => 'outgoing_interface', 'type' => 'checklist', 'label' => gettext('Outgoing network interfaces'), 'required' => true, 'options' => $ifaces,
		    'help' => gettext('Utilize different network interface(s) that the DNS Resolver will use to send queries to authoritative servers and receive their replies. By default all interfaces are used.')),
		array('name' => 'strictout', 'type' => 'switch', 'label' => gettext('Strict outgoing network interface binding'),
		    'text' => gettext('Do not send recursive queries if none of the selected Outgoing Network Interfaces are available.'),
		    'help' => gettext('By default the DNS Resolver sends recursive DNS requests over any available interfaces if none of the selected Outgoing Network Interfaces are available. This option makes the DNS Resolver refuse recursive queries.')),
		array('name' => 'system_domain_local_zone_type', 'type' => 'select', 'label' => gettext('System domain local zone type'), 'required' => true,
		    'default' => 'transparent', 'width' => 'half', 'options' => $zones,
		    'help' => gettext('The local-zone type used for the system domain (System > General Setup). Transparent is the default.')),
		array('name' => 'dnssec', 'type' => 'switch', 'label' => gettext('DNSSEC'), 'text' => gettext('Enable DNSSEC Support')),
	);

	$tls_cert = array('name' => 'sslcertref', 'type' => 'select', 'label' => gettext('SSL/TLS certificate'), 'width' => 'half', 'options' => $certs,
	    'visibleWhen' => $ssl, 'errorMatch' => array('valid server certificate'),
	    'help' => $certs_available ? gettext('The server certificate to use for SSL/TLS service. The CA chain will be determined automatically.') :
	    gettext('No Certificates have been defined. A certificate is required before SSL/TLS can be enabled (System > Certificates).'));
	$tls = array(
		array('name' => 'enablessl', 'type' => 'switch', 'label' => gettext('Enable SSL/TLS service'),
		    'text' => gettext('Respond to incoming SSL/TLS queries from local clients'),
		    'help' => gettext('Configures the DNS Resolver to act as a DNS over SSL/TLS server which can answer queries from clients which also support DNS over TLS. Activating this option disables automatic interface response routing behavior, thus it works best with specific interface bindings.')),
		$tls_cert,
		array('name' => 'tlsport', 'type' => 'port', 'label' => gettext('SSL/TLS listen port'), 'range' => false, 'alias' => false, 'placeholder' => '853', 'width' => 'third',
		    'visibleWhen' => $ssl, 'errorMatch' => array('ssl/tls port number'),
		    'help' => gettext('The port used for responding to SSL/TLS DNS queries. It should normally be left blank unless another service needs to bind to TCP/UDP port 853.')),
	);

	$forwarding = array(
		array('name' => 'forwarding', 'type' => 'switch', 'label' => gettext('DNS query forwarding'), 'text' => gettext('Enable Forwarding Mode'),
		    'errorMatch' => array('forwarding mode'),
		    'help' => gettext('If this option is set, DNS queries will be forwarded to the upstream DNS servers defined under System > General Setup or those obtained via dynamic interfaces such as DHCP, PPP, or OpenVPN (if DNS Server Override is enabled there).')),
		array('name' => 'forward_tls_upstream', 'type' => 'switch', 'label' => gettext('Forwarding over SSL/TLS'),
		    'text' => gettext('Use SSL/TLS for outgoing DNS Queries to Forwarding Servers'),
		    'visibleWhen' => array('field' => 'forwarding', 'truthy' => true),
		    'help' => gettext('When set in conjunction with DNS Query Forwarding, queries to all upstream forwarding DNS servers will be sent using SSL/TLS on the default port of 853. Note that ALL configured forwarding servers MUST support SSL/TLS queries on port 853.')),
	);

	$registration = array();
	/* The page offers the DHCP registration options only with the ISC backend. */
	if ($isc) {
		$registration[] = array('name' => 'regdhcp', 'type' => 'switch', 'label' => gettext('DHCP registration'),
		    'text' => gettext('Register DHCP leases in the DNS Resolver'),
		    'help' => gettext('If this option is set, then machines that specify their hostname when requesting an IPv4 DHCP lease will be registered in the DNS Resolver so that their name can be resolved. Note that this will cause the Resolver to reload and flush its resolution cache whenever a DHCP lease is issued. The domain in System > General Setup should also be set to the proper value.'));
		$registration[] = array('name' => 'regdhcpstatic', 'type' => 'switch', 'label' => gettext('Static DHCP'),
		    'text' => gettext('Register DHCP static mappings in the DNS Resolver'),
		    'help' => gettext('If this option is set, then DHCP static mappings will be registered in the DNS Resolver, so that their name can be resolved. The domain in System > General Setup should also be set to the proper value.'));
	}
	$registration[] = array('name' => 'regovpnclients', 'type' => 'switch', 'label' => gettext('OpenVPN clients'),
	    'text' => gettext('Register connected OpenVPN clients in the DNS Resolver'),
	    'help' => gettext('If this option is set, then the common name (CN) of connected OpenVPN clients will be registered in the DNS Resolver, so that their name can be resolved. This only works for OpenVPN servers (Remote Access SSL/TLS or User Auth with Username as Common Name option) operating in "tun" mode. The domain in System > General Setup should also be set to the proper value.'));

	$python_fields = array(
		array('name' => 'python', 'type' => 'switch', 'label' => gettext('Python module'), 'text' => gettext('Enable Python Module')),
		array('name' => 'python_order', 'type' => 'select', 'label' => gettext('Python module order'), 'width' => 'half', 'default' => 'pre_validator',
		    'visibleWhen' => $python, 'help' => gettext('Select the Python Module ordering.'),
		    'options' => restapi_schema_choices(array('pre_validator' => gettext('Pre Validator'), 'post_validator' => gettext('Post Validator')))),
		array('name' => 'python_script', 'type' => 'select', 'label' => gettext('Python module script'), 'width' => 'half', 'options' => $scripts,
		    'visibleWhen' => $python, 'help' => gettext('Select the Python module script to utilize.')),
	);

	return array(
		'title' => gettext('DNS resolver'),
		'order' => array('general', 'tls', 'forwarding', 'registration', 'python', 'custom'),
		'sections' => array(
			array('id' => 'general', 'title' => gettext('General DNS resolver options'), 'fields' => $general),
			array('id' => 'tls', 'title' => gettext('DNS over SSL/TLS'), 'fields' => $tls),
			array('id' => 'forwarding', 'title' => gettext('Forwarding'), 'fields' => $forwarding),
			array('id' => 'registration', 'title' => gettext('Registration'), 'fields' => $registration),
			array('id' => 'python', 'title' => gettext('Python module'), 'advanced' => true, 'fields' => $python_fields),
			array('id' => 'custom', 'title' => gettext('Custom options'), 'advanced' => true, 'fields' => array(
				array('name' => 'custom_options', 'type' => 'textarea', 'label' => gettext('Custom options'), 'code' => true, 'rows' => 8,
				    'help' => gettext('Enter any additional configuration parameters to add to the DNS Resolver configuration here, separated by a newline.'),
				    'errorMatch' => array('generated config file')),
			)),
		),
	);
}

/* The advanced settings form. */
function restapi_schema_services_dns_resolver_advanced() {
	$choices = function_exists('unbound_advanced_choices') ? unbound_advanced_choices() : array();
	$same = function ($key) use ($choices) {
		$values = (array)($choices[$key] ?? array());
		return restapi_schema_choices(array_combine($values, $values) ?: array());
	};
	$labelled = function ($key, callable $label) use ($choices) {
		$out = array();
		foreach ((array)($choices[$key] ?? array()) as $value) {
			$out[$value] = $label($value);
		}
		return restapi_schema_choices($out);
	};
	$minutes = gettext('minutes');
	$million = gettext('million');
	$levels = array('0' => 'No logging', '1' => 'Basic operational information', '2' => 'Detailed operational information',
	    '3' => 'Query level information', '4' => 'Algorithm level information', '5' => 'Client identification for cache misses');
	$edns = function_exists('unbound_edns_sizes') ? unbound_edns_sizes() : array();
	$prefix_override = function_exists('config_path_enabled') && config_path_enabled('system', 'allow_nat64_prefix_override');
	$select = function ($name, $label, $options, $help, $default = null) use ($choices) {
		$f = array('name' => $name, 'type' => 'select', 'label' => $label, 'width' => 'half', 'options' => $options, 'help' => $help);
		$f['default'] = $default ?? ((string)(($choices[$name] ?? array(''))[0]));
		return $f;
	};

	$resolver = array(
		array('name' => 'prefetch', 'type' => 'switch', 'label' => gettext('Prefetch support'),
		    'text' => gettext('Message cache elements are prefetched before they expire to help keep the cache up to date'),
		    'help' => gettext('When enabled, this option can cause an increase of around 10% more DNS traffic and load on the server, but frequently requested items will not expire from the cache.')),
		array('name' => 'prefetchkey', 'type' => 'switch', 'label' => gettext('Prefetch DNS key support'),
		    'text' => gettext('DNSKEYs are fetched earlier in the validation process when a Delegation signer is encountered'),
		    'help' => gettext('This helps lower the latency of requests but does utilize a little more CPU.')),
		array('name' => 'dnssecstripped', 'type' => 'switch', 'label' => gettext('Harden DNSSEC data'),
		    'text' => gettext('DNSSEC data is required for trust-anchored zones.'),
		    'help' => gettext('If such data is absent, the zone becomes bogus. If Disabled and no DNSSEC data is received, then the zone is made insecure. Requires DNSSEC support on the General tab.')),
		array('name' => 'dnsrecordcache', 'type' => 'switch', 'label' => gettext('Serve expired'), 'text' => gettext('Serve cache records even with TTL of 0'),
		    'help' => gettext('When enabled, allows unbound to serve one query even with a TTL of 0, if TTL is 0 then new record will be requested in the background when the cache is served to ensure cache is updated without latency on service of the DNS request.')),
		array('name' => 'sock_queue_timeout', 'type' => 'number', 'label' => gettext('Drop old UDP queries'), 'min' => 0, 'unit' => gettext('seconds'),
		    'width' => 'third', 'help' => gettext('Timeout in seconds before dropping UDP queries waiting in the socket buffer. Queries that have waited for a long time don\'t need to be processed and can be dropped. Disabled by default (0).')),
		array('name' => 'aggressivensec', 'type' => 'switch', 'label' => gettext('Aggressive NSEC'), 'text' => gettext('Aggressive Use of DNSSEC-Validated Cache'),
		    'help' => gettext('When enabled, unbound uses the DNSSEC NSEC chain to synthesize NXDOMAIN and other denials, using information from previous NXDOMAINs answers. It helps to reduce the query rate towards targets that get a very high nonexistent name lookup rate.')),
	);

	$cache = array(
		$select('msgcachesize', gettext('Message cache size'), $labelled('msgcachesize', function ($v) { return "{$v} MB"; }),
		    gettext('Size of the message cache. The message cache stores DNS response codes and validation statuses. The Resource Record Set (RRSet) cache will automatically be set to twice this amount. The RRSet cache contains the actual RR data. The default is 4 megabytes.')),
		$select('outgoing_num_tcp', gettext('Outgoing TCP buffers'), $same('outgoing_num_tcp'),
		    gettext('The number of outgoing TCP buffers to allocate per thread. The default value is 10. If 0 is selected then TCP queries are not sent to authoritative servers.'), '10'),
		$select('incoming_num_tcp', gettext('Incoming TCP buffers'), $same('incoming_num_tcp'),
		    gettext('The number of incoming TCP buffers to allocate per thread. The default value is 10. If 0 is selected then TCP queries are not accepted from clients.'), '10'),
		$select('edns_buffer_size', gettext('EDNS buffer size'), $labelled('edns_buffer_size', function ($v) use ($edns) { return $edns[$v] ?? $v; }),
		    gettext('Number of bytes size to advertise as the EDNS reassembly buffer size. This is the value that is used in UDP datagrams sent to peers. Auto mode sets optimal buffer size by using the smallest MTU of active interfaces and subtracting the IPv4/IPv6 header size. If fragmentation reassemble problems occur, usually seen as timeouts, then a value of 1432 should help. The 512/1232 values bypasses most IPv4/IPv6 MTU path problems, but it can generate an excessive amount of TCP fallback.'), 'auto'),
		$select('num_queries_per_thread', gettext('Number of queries per thread'), $same('num_queries_per_thread'),
		    gettext('The number of queries that every thread will service simultaneously. If more queries arrive that need to be serviced, and no queries can be jostled, then these queries are dropped.')),
		$select('jostle_timeout', gettext('Jostle timeout'), $labelled('jostle_timeout', function ($v) { return "{$v} ms"; }),
		    gettext('This timeout is used for when the server is very busy. This protects against denial of service by slow queries or high query rates. The default value is 200 milliseconds.'), '200'),
		array('name' => 'cache_max_ttl', 'type' => 'number', 'label' => gettext('Maximum TTL for RRsets and messages'), 'min' => 0, 'unit' => gettext('seconds'),
		    'width' => 'half', 'placeholder' => '86400',
		    'help' => gettext('The Maximum Time to Live for RRsets and messages in the cache. The default is 86400 seconds (1 day). When the internal TTL expires the cache item is expired. This can be configured to force the resolver to query for data more often and not trust (very large) TTL values.')),
		array('name' => 'cache_min_ttl', 'type' => 'number', 'label' => gettext('Minimum TTL for RRsets and messages'), 'min' => 0, 'unit' => gettext('seconds'),
		    'width' => 'half', 'placeholder' => '0',
		    'help' => gettext('The Minimum Time to Live for RRsets and messages in the cache. The default is 0 seconds. If the minimum value kicks in, the data is cached for longer than the domain owner intended, and thus less queries are made to look up the data. The 0 value ensures the data in the cache is as the domain owner intended. High values can lead to trouble as the data in the cache might not match up with the actual data anymore.')),
		array('name' => 'infra_keep_probing', 'type' => 'switch', 'label' => gettext('Keep probing'), 'text' => gettext('Keep probing servers that are down'),
		    'help' => gettext('When disabled, it may take up to "TTL for Host Cache Entries" for a server to be used again after being marked as down.')),
		$select('infra_host_ttl', gettext('TTL for host cache entries'), $labelled('infra_host_ttl', function ($v) use ($minutes) { return ((int)$v / 60) . " {$minutes}"; }),
		    gettext('Time to Live duration for entries in the infrastructure host cache. The infrastructure host cache contains round trip timing, lameness, and EDNS support information for DNS servers. The default value is 15 minutes.'), '900'),
		$select('infra_cache_numhosts', gettext('Number of hosts to cache'), $same('infra_cache_numhosts'),
		    gettext('Number of infrastructure hosts for which information is cached. The default is 10,000.'), '10000'),
		$select('unwanted_reply_threshold', gettext('Unwanted reply threshold'), $labelled('unwanted_reply_threshold', function ($v) use ($million) {
			return ($v === 'disabled') ? gettext('Disabled') : ((int)$v / 1000000) . " {$million}";
		    }), gettext('If enabled, a total number of unwanted replies is kept track of in every thread. When it reaches the threshold, a defensive action is taken and a warning is printed to the log file. This defensive action is to clear the RRSet and message caches, hopefully flushing away any poison. The default is disabled, but if enabled a value of 10 million is suggested.'), 'disabled'),
		$select('log_verbosity', gettext('Log level'), $labelled('log_verbosity', function ($v) use ($levels) {
			return sprintf(gettext('Level %s'), $v) . ': ' . gettext($levels[$v] ?? '');
		    }), gettext('Select the level of detail to be logged. Each level also includes the information from previous levels. The default is basic operational information (level 1)'), '1'),
	);

	$access = array(
		array('name' => 'disable_auto_added_access_control', 'type' => 'switch', 'label' => gettext('Disable auto-added access control'),
		    'text' => gettext('Disable the automatically-added access control entries'),
		    'help' => gettext('By default, IPv4 and IPv6 networks residing on internal interfaces of this system are permitted. Allowed networks must be manually configured on the Access Lists tab if the auto-added entries are disabled.')),
		array('name' => 'disable_auto_added_host_entries', 'type' => 'switch', 'label' => gettext('Disable auto-added host entries'),
		    'text' => gettext('Disable the automatically-added host entries'),
		    'help' => gettext('By default, the primary IPv4 and IPv6 addresses of this firewall are added as records for the system domain of this firewall as configured in System > General Setup. This disables the auto generation of these entries.')),
		array('name' => 'use_caps', 'type' => 'switch', 'label' => gettext('Experimental bit 0x20 support'),
		    'text' => gettext('Use 0x-20 encoded random bits in the DNS query to foil spoofing attempts.'),
		    'help' => gettext('See the implementation draft dns-0x20 for more information.')),
	);

	$dns64 = array(
		array('name' => 'dns64_enable', 'type' => 'switch', 'label' => gettext('DNS64 support'), 'text' => gettext('Enable DNS64 (RFC 6147)'),
		    'help' => gettext('DNS64 is used with an IPv6/IPv4 translator to enable client-server communication between an IPv6-only client and an IPv4-only servers.')),
	);
	/* The page offers the prefix only when System > Advanced allows overriding the NAT64 prefix. */
	if ($prefix_override) {
		$dns64[] = array('name' => 'dns64_prefix', 'type' => 'text', 'label' => gettext('DNS64 prefix'), 'mono' => true, 'width' => 'half',
		    'placeholder' => defined('NAT64_WELLKNOWN_PREFIX_CIDR') ? NAT64_WELLKNOWN_PREFIX_CIDR : '64:ff9b::/96',
		    'help' => gettext('Override the default prefix for DNS64.'));
	}
	$dns64[] = array('name' => 'allow_dns64_for_localhost', 'type' => 'switch', 'label' => gettext('DNS64 for localhost'),
	    'text' => gettext('Disable the automatically-added configuration that removes DNS64 answers from localhost queries.'));

	return array(
		'title' => gettext('DNS resolver advanced settings'),
		'order' => array('privacy', 'resolver', 'cache', 'access', 'dns64'),
		'sections' => array(
			array('id' => 'privacy', 'title' => gettext('Advanced privacy options'), 'fields' => array(
				array('name' => 'hideidentity', 'type' => 'switch', 'label' => gettext('Hide identity'),
				    'text' => gettext('id.server and hostname.bind queries are refused')),
				array('name' => 'hideversion', 'type' => 'switch', 'label' => gettext('Hide version'),
				    'text' => gettext('version.server and version.bind queries are refused')),
				array('name' => 'qname-minimisation', 'type' => 'switch', 'label' => gettext('Query name minimization'),
				    'text' => gettext('Send minimum amount of QNAME/QTYPE information to upstream servers to enhance privacy'),
				    'help' => gettext('Only send minimum required labels of the QNAME and set QTYPE to A when possible. Best effort approach; full QNAME and original QTYPE will be sent when upstream replies with a RCODE other than NOERROR, except when receiving NXDOMAIN from a DNSSEC signed zone. See RFC 7816.')),
				array('name' => 'qname-minimisation-strict', 'type' => 'switch', 'label' => gettext('Strict query name minimization'),
				    'text' => gettext('Do not fall-back to sending full QNAME to potentially broken DNS servers'),
				    'visibleWhen' => array('field' => 'qname-minimisation', 'truthy' => true),
				    'help' => gettext('QNAME minimization in strict mode. A significant number of domains will fail to resolve when this option in enabled. Only use if you know what you are doing. This option only has effect when Query Name Minimization is enabled. Default is off.')),
			)),
			array('id' => 'resolver', 'title' => gettext('Advanced resolver options'), 'fields' => $resolver),
			array('id' => 'cache', 'title' => gettext('Buffers, cache and logging'), 'advanced' => true, 'fields' => $cache),
			array('id' => 'access', 'title' => gettext('Automatic entries'), 'advanced' => true, 'fields' => $access),
			array('id' => 'dns64', 'title' => gettext('DNS64'), 'fields' => $dns64),
		),
	);
}

/* A host override (with its additional names). */
function restapi_schema_services_dns_host_override() {
	return array(
		'title' => gettext('Host override'),
		'summary' => gettext('[{host}.]{domain} → {ip}'),
		'summaryIcon' => 'server',
		'sections' => array(
			array('id' => 'host', 'title' => gettext('Host override options'),
			    'description' => gettext('Lookups of this host return the given addresses; the usual lookup server for the domain is not queried for it.'),
			    'fields' => array(
				array('name' => 'host', 'type' => 'text', 'label' => gettext('Host'), 'mono' => true, 'width' => 'half',
				    'help' => gettext('Name of the host, without the domain part, e.g. enter "myhost" if the full domain name is "myhost.example.com". Empty for the domain itself.'),
				    'errorMatch' => array('hostname', 'host/domain override combination')),
				array('name' => 'domain', 'type' => 'text', 'label' => gettext('Domain'), 'mono' => true, 'required' => true, 'width' => 'half',
				    'help' => gettext('Parent domain of the host, e.g. enter "example.com" for "myhost.example.com".')),
				array('name' => 'ip', 'type' => 'text', 'label' => gettext('IP address'), 'mono' => true, 'required' => true,
				    'placeholder' => '192.168.100.100',
				    'help' => gettext('IPv4 or IPv6 comma-separated addresses to be returned for the host, e.g. 192.168.100.100 or fd00:abcd:: or the list 192.168.1.3,192.168.4.5,fc00:123::3'),
				    'errorMatch' => array('ip addresses')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('A description may be entered here for administrative reference (not parsed).')),
			)),
			array('id' => 'aliases', 'title' => gettext('Additional names for this host'),
			    'description' => gettext('If the host can be accessed using multiple names, then enter any other names for the host which should also be overridden.'),
			    'fields' => array(
				array('name' => 'aliases', 'type' => 'entry-grid', 'label' => gettext('Additional names'), 'min' => 0,
				    'addLabel' => gettext('Add host name'), 'emptyText' => gettext('No additional names.'),
				    'errorMatch' => array('alias domain', 'alias list', 'alias hostname'),
				    'fields' => array(
					array('name' => 'host', 'type' => 'text', 'label' => gettext('Host name'), 'mono' => true, 'width' => 'md'),
					array('name' => 'domain', 'type' => 'text', 'label' => gettext('Domain'), 'mono' => true, 'required' => true, 'width' => 'md'),
					array('name' => 'description', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'lg'),
				    )),
			)),
		),
	);
}

/* A domain override. */
function restapi_schema_services_dns_domain_override() {
	return array(
		'title' => gettext('Domain override'),
		'summary' => gettext('{domain} → {ip}'),
		'summaryIcon' => 'sitemap',
		'sections' => array(
			array('id' => 'domain', 'title' => gettext('Domain to override with a custom lookup server'),
			    'description' => gettext('The IP address is treated as the authoritative lookup server for the domain (including all of its subdomains); other lookup servers are not queried. For several servers, add an entry for each with the same domain.'),
			    'fields' => array(
				array('name' => 'domain', 'type' => 'text', 'label' => gettext('Domain'), 'mono' => true, 'required' => true, 'width' => 'half',
				    'help' => gettext('Domain whose lookups will be directed to a user-specified DNS lookup server.'),
				    'errorMatch' => array('after _msdcs')),
				array('name' => 'ip', 'type' => 'text', 'label' => gettext('IP address'), 'mono' => true, 'required' => true, 'width' => 'half',
				    'placeholder' => '192.168.100.100', 'pattern' => '^[a-zA-Z0-9@.:]+$',
				    'help' => gettext('IPv4 or IPv6 address of the authoritative DNS server for this domain, e.g. 192.168.100.100. To use a non-default port for communication, append an \'@\' with the port number.')),
				array('name' => 'forward_tls_upstream', 'type' => 'switch', 'label' => gettext('TLS queries'),
				    'text' => gettext('Use SSL/TLS for DNS Queries forwarded to this server'),
				    'help' => gettext('When set, queries to all DNS servers for this domain will be sent using SSL/TLS on the default port of 853.')),
				array('name' => 'tls_hostname', 'type' => 'text', 'label' => gettext('TLS hostname'), 'mono' => true, 'width' => 'half',
				    'visibleWhen' => array('field' => 'forward_tls_upstream', 'truthy' => true),
				    'help' => gettext('An optional TLS hostname used to verify the server certificate when performing TLS Queries.')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('A description may be entered here for administrative reference (not parsed).')),
			)),
		),
	);
}

/* An access list. */
function restapi_schema_services_dns_access_list() {
	$actions = function_exists('unbound_acl_actions') ? unbound_acl_actions() :
	    (function_exists('restapi_unbound_acl_actions') ? restapi_unbound_acl_actions() : array());
	return array(
		'title' => gettext('Access list'),
		'summary' => gettext('{aclaction} {aclname}'),
		'summaryIcon' => 'list-check',
		'sections' => array(
			array('id' => 'acl', 'title' => gettext('Access list'), 'fields' => array(
				array('name' => 'aclname', 'type' => 'text', 'label' => gettext('Access list name'), 'width' => 'half',
				    'help' => gettext('Provide an Access List name.')),
				array('name' => 'aclaction', 'type' => 'select', 'label' => gettext('Action'), 'required' => true, 'default' => 'allow', 'width' => 'half',
				    'options' => restapi_schema_choices($actions),
				    'help' => gettext('Deny drops queries from these networks; Refuse also answers REFUSED. Allow permits queries. Allow Snoop also permits non-recursive (cache snooping) queries, ideally only for the administrative host. Deny Nonlocal and Refuse Nonlocal permit only authoritative local-data queries, dropping or refusing the others.')),
				array('name' => 'description', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('A description may be entered here for administrative reference.')),
			)),
			array('id' => 'networks', 'title' => gettext('Networks'), 'fields' => array(
				array('name' => 'networks', 'type' => 'entry-grid', 'label' => gettext('Networks'), 'min' => 0, 'max' => 50,
				    'addLabel' => gettext('Add network'), 'emptyText' => gettext('No networks yet.'),
				    'errorMatch' => array('valid ipv6 address must be entered'),
				    'fields' => array(
					array('name' => 'acl_network', 'type' => 'text', 'label' => gettext('Network'), 'mono' => true, 'required' => true, 'width' => 'lg'),
					array('name' => 'mask', 'type' => 'number', 'label' => gettext('Prefix length'), 'min' => 0, 'max' => 128, 'required' => true, 'width' => 'xs'),
					array('name' => 'description', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'lg'),
				    )),
			)),
		),
	);
}
