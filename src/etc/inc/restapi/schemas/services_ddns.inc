<?php
/*
 * services_ddns.inc
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
 * Schemas of the Dynamic DNS and RFC 2136 clients (POST/PUT
 * /api/v1/services/dyndns/clients and /api/v1/services/rfc2136/clients).
 * Field names are the API's, which are the edit pages' form fields except
 * "enable" (true for an enabled client; the Dynamic DNS form's checkbox is
 * "Disable") and the Dynamic DNS "password" (the form's "passwordfld").
 * Choices come from the functions the pages use (services_dyndns.inc).
 * Secrets are never returned: an empty password or key keeps the stored one.
 *
 *   services/dyndns   a Dynamic DNS client (services_dyndns_edit.php)
 *   services/rfc2136  an RFC 2136 client (services_rfc2136_edit.php)
 */

/*
 * The service types each Dynamic DNS form field is shown for, as the edit
 * page's setVisible() script shows and hides them: field => [types], for
 * the fields that depend on the type. $types are the service type values.
 */
function restapi_dyndns_field_types(array $types) {
	$hidden = array('domainname', 'resultmatch', 'updateurl', 'requestif', 'curl_ipresolve_v4', 'curl_ssl_verifypeer', 'proxied',
	    'zoneid', 'ttl', 'maxcacheage');
	$shown = array('username', 'host', 'mx', 'wildcard');
	$v6 = function (array $names) {
		$out = array();
		foreach ($names as $n) {
			$out[] = $n;
			$out[] = "{$n}-v6";
		}
		return $out;
	};
	/* [service types, fields shown, fields hidden] in the page's order */
	$rules = array(
		array(array('custom', 'custom-v6'), array('resultmatch', 'updateurl', 'requestif', 'curl_ipresolve_v4', 'curl_ssl_verifypeer', 'maxcacheage'),
		    array('host', 'mx', 'wildcard')),
		array(array('azure', 'azurev6'), array('zoneid', 'ttl'), array('mx', 'wildcard')),
		array($v6(array('cloudflare')), array('domainname', 'proxied', 'ttl'), array('mx', 'wildcard')),
		array(array('cloudns'), array('domainname', 'ttl'), array()),
		array($v6(array('desec')), array(), array('username', 'mx')),
		array($v6(array('digitalocean', 'gandi-livedns', 'yandex')), array('domainname', 'ttl'), array('username', 'mx', 'wildcard')),
		array($v6(array('dnsimple')), array('curl_ssl_verifypeer', 'zoneid', 'ttl'), array()),
		array(array_merge($v6(array('domeneshop', 'dreamhost', 'nicru')), array('dyfi')), array(), array('mx', 'wildcard')),
		array($v6(array('godaddy', 'linode', 'luadns', 'name.com', 'onecom', 'porkbun')), array('domainname', 'ttl'), array('mx', 'wildcard')),
		array(array('gratisdns', 'hover', 'namecheap'), array('domainname'), array()),
		array($v6(array('mythicbeasts')), array('domainname'), array('mx', 'wildcard')),
		array($v6(array('route53')), array('curl_ssl_verifypeer', 'zoneid', 'ttl'), array('mx', 'wildcard')),
		array(array('strato', 'dnsmadeeasy'), array(), array('mx', 'wildcard')),
	);
	$out = array();
	foreach (array_merge($hidden, $shown) as $field) {
		$out[$field] = array();
	}
	foreach ($types as $type) {
		$type = (string)$type;
		$visible = array_fill_keys($shown, true) + array_fill_keys($hidden, false);
		foreach ($rules as list($match, $show, $hide)) {
			if (in_array($type, $match, true)) {
				foreach ($show as $f) {
					$visible[$f] = true;
				}
				foreach ($hide as $f) {
					$visible[$f] = false;
				}
				break;
			}
		}
		foreach ($visible as $f => $on) {
			if ($on) {
				$out[$f][] = $type;
			}
		}
	}
	return $out;
}

function restapi_schema_services_dyndns() {
	$types = function_exists('dyndns_type_list') ? dyndns_type_list() : array();
	$ifaces = function_exists('dyndns_build_if_list') ? dyndns_build_if_list() : array();
	$modes = function_exists('dyndns_check_ip_mode_list') ? dyndns_check_ip_mode_list() : array('default' => 'Automatic (default)');
	$by_field = restapi_dyndns_field_types(array_keys($types));
	/* visibleWhen for a type-dependent field (none when every type shows it) */
	$when = function ($field) use ($by_field, $types) {
		$list = $by_field[$field] ?? array();
		return (count($list) === count($types)) ? array() : array('visibleWhen' => array('field' => 'type', 'in' => $list));
	};
	$proxy = function_exists('config_get_path') && !empty(config_get_path('system/proxyurl'));

	$client = array(
		array('name' => 'enable', 'type' => 'switch', 'label' => gettext('Enabled'), 'default' => true,
		    'text' => gettext('Keep this client\'s host name updated')),
		array('name' => 'type', 'type' => 'select', 'label' => gettext('Service type'), 'required' => true,
		    'options' => restapi_schema_choices($types), 'errorMatch' => array('service type')),
		array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface to monitor'), 'required' => true, 'width' => 'half',
		    'options' => restapi_schema_choices($ifaces),
		    'help' => gettext('Its address is published. When it is private, the public address is fetched from the check IP service.')),
		array('name' => 'requestif', 'type' => 'select', 'label' => gettext('Interface to send update from'), 'required' => true, 'width' => 'half',
		    'options' => restapi_schema_choices($ifaces), 'help' => gettext('Almost always the interface to monitor.'),
		    'errorMatch' => array('interface to send the update from')) + $when('requestif'),
		array('name' => 'check_ip_mode', 'type' => 'select', 'label' => gettext('Check IP mode'), 'default' => 'default', 'width' => 'half',
		    'options' => restapi_schema_choices($modes),
		    'help' => gettext('By default the check IP service is only used for a private interface address.')),
		array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'half',
		    'help' => gettext('Also shown in the Dynamic DNS widget for custom services.')),
	);
	$record = array(
		array('name' => 'host', 'type' => 'text', 'label' => gettext('Hostname'), 'mono' => true, 'width' => 'half',
		    'placeholder' => 'myhost.example.org',
		    'help' => gettext('The fully qualified name, or the host part when the service has a separate domain name ("@" for the domain itself where the provider allows it). Some services want an ID instead (DNS Made Easy, GleSYS, he.net); DNSimple wants only the domain.'),
		    'errorMatch' => array('hostname contains')) + $when('host'),
		array('name' => 'domainname', 'type' => 'text', 'label' => gettext('Domain name'), 'mono' => true, 'width' => 'half',
		    'help' => gettext('The zone the provider handles.')) + $when('domainname'),
		array('name' => 'mx', 'type' => 'text', 'label' => gettext('MX'), 'mono' => true, 'width' => 'half',
		    'help' => gettext('Only when a special MX record is needed (a host name, not an address). Not all services support it.')) + $when('mx'),
		array('name' => 'zoneid', 'type' => 'text', 'label' => gettext('Zone ID'), 'mono' => true, 'width' => 'half',
		    'help' => gettext('Azure: the resource ID of the DNS zone. DNSimple: the record ID. Route 53: the AWS zone ID.')) + $when('zoneid'),
		array('name' => 'ttl', 'type' => 'text', 'label' => gettext('TTL'), 'width' => 'third',
		    'help' => gettext('TTL of the DNS record.')) + $when('ttl'),
		array('name' => 'wildcard', 'type' => 'switch', 'label' => gettext('Wildcards'), 'text' => gettext('Enable wildcard'), 'width' => 'third') +
		    $when('wildcard'),
		array('name' => 'proxied', 'type' => 'switch', 'label' => gettext('Cloudflare proxy'), 'text' => gettext('Route traffic through Cloudflare'),
		    'width' => 'third', 'help' => gettext('Off by default: the real address is published.')) + $when('proxied'),
	);
	$auth = array(
		array('name' => 'username', 'type' => 'text', 'label' => gettext('Username'), 'width' => 'half', 'autocomplete' => 'off',
		    'help' => gettext('Required except for custom entries and the services that use only a token. Some services want an API key, account ID or e-mail address here.'),
		    'errorMatch' => array('username contains')) + $when('username'),
		array('name' => 'password', 'type' => 'secret', 'label' => gettext('Password'), 'width' => 'half', 'autocomplete' => 'new-password',
		    'help' => gettext('Password, API token or secret of the service. Never shown again; leave it empty to keep the current one.'),
		    'errorMatch' => array('confirmed password')),
	);
	$custom = array(
		array('name' => 'updateurl', 'type' => 'text', 'label' => gettext('Update URL'), 'mono' => true,
		    'help' => gettext('The only field a custom entry needs. %IP% is replaced by the address.')) + $when('updateurl'),
		array('name' => 'resultmatch', 'type' => 'textarea', 'label' => gettext('Result match'), 'code' => true, 'rows' => 3,
		    'help' => gettext('What the provider returns on success (%IP% for the address, alternatives separated by |). Empty: the result is not checked.')) +
		    $when('resultmatch'),
	);
	$advanced = array(
		array('name' => 'maxcacheage', 'type' => 'number', 'label' => gettext('Max cache age'), 'min' => 1, 'unit' => gettext('days'), 'width' => 'third',
		    'help' => gettext('The record is updated at least this often. Empty: the default.')) + $when('maxcacheage'),
		array('name' => 'verboselog', 'type' => 'switch', 'label' => gettext('Verbose logging'), 'width' => 'third'),
		array('name' => 'curl_ipresolve_v4', 'type' => 'switch', 'label' => gettext('Force IPv4 DNS resolution'), 'width' => 'third') +
		    $when('curl_ipresolve_v4'),
		array('name' => 'curl_ssl_verifypeer', 'type' => 'switch', 'label' => gettext('Verify TLS certificate'), 'default' => true, 'width' => 'third',
		    'help' => gettext('The service must present a certificate this firewall trusts.')) + $when('curl_ssl_verifypeer'),
	);
	if ($proxy) {
		$advanced[] = array('name' => 'curl_proxy', 'type' => 'switch', 'label' => gettext('Use proxy'), 'width' => 'third',
		    'help' => gettext('The proxy of System > Advanced > Miscellaneous.'));
	}

	return array(
		'title' => gettext('Dynamic DNS client'),
		'summary' => gettext('{type}[ {host}][ on {interface}]'),
		'summaryIcon' => 'globe',
		'sections' => array(
			array('id' => 'client', 'title' => gettext('Client'), 'fields' => $client),
			array('id' => 'record', 'title' => gettext('Record'), 'fields' => $record),
			array('id' => 'auth', 'title' => gettext('Credentials'), 'fields' => $auth),
			array('id' => 'custom', 'title' => gettext('Custom update'), 'fields' => $custom,
			    'visibleWhen' => array('field' => 'type', 'in' => array('custom', 'custom-v6'))),
			array('id' => 'advanced', 'title' => gettext('Advanced'), 'advanced' => true, 'fields' => $advanced),
		),
	);
}

function restapi_schema_services_rfc2136() {
	$ifaces = function_exists('dyndns_build_if_list') ? dyndns_build_if_list() : array();
	$algos = function_exists('rfc2136_key_algos') ? rfc2136_key_algos() : array('hmac-md5' => 'HMAC-MD5 (legacy default)');
	$sources = function_exists('rfc2136_build_us_list') ? rfc2136_build_us_list() : array('' => 'Default (use Interface above)');
	$families = function_exists('rfc2136_source_families') ? rfc2136_source_families() : array('' => 'Default');
	$records = function_exists('rfc2136_record_types') ? rfc2136_record_types() : array('both' => 'Both');

	return array(
		'title' => gettext('RFC 2136 client'),
		'summary' => gettext('{host}[ at {server}]'),
		'summaryIcon' => 'globe',
		'sections' => array(
			array('id' => 'client', 'title' => gettext('Client'), 'fields' => array(
				array('name' => 'enable', 'type' => 'switch', 'label' => gettext('Enabled'), 'text' => gettext('Keep this host name updated')),
				array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'width' => 'half',
				    'options' => restapi_schema_choices($ifaces), 'help' => gettext('Its address is published.')),
				array('name' => 'recordtype', 'type' => 'segmented', 'label' => gettext('Record type'), 'default' => 'both', 'width' => 'half',
				    'options' => restapi_schema_choices($records)),
				array('name' => 'host', 'type' => 'text', 'label' => gettext('Hostname'), 'required' => true, 'mono' => true, 'width' => 'half',
				    'placeholder' => 'myhost.example.org', 'help' => gettext('The fully qualified name to update.'),
				    'errorMatch' => array('host name contains')),
				array('name' => 'zone', 'type' => 'text', 'label' => gettext('Zone'), 'mono' => true, 'width' => 'half',
				    'help' => gettext('The zone of the host name (optional).'), 'errorMatch' => array('zone name')),
				array('name' => 'ttl', 'type' => 'number', 'label' => gettext('TTL'), 'required' => true, 'min' => 0, 'unit' => gettext('seconds'),
				    'width' => 'third', 'default' => 60),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'two-thirds'),
			)),
			array('id' => 'key', 'title' => gettext('Key'), 'fields' => array(
				array('name' => 'keyname', 'type' => 'text', 'label' => gettext('Key name'), 'required' => true, 'mono' => true, 'width' => 'half',
				    'help' => gettext('As the DNS server knows the key.')),
				array('name' => 'keyalgorithm', 'type' => 'select', 'label' => gettext('Key algorithm'), 'width' => 'half',
				    'options' => restapi_schema_choices($algos)),
				array('name' => 'keydata', 'type' => 'secret', 'label' => gettext('Key'), 'mono' => true,
				    'autocomplete' => 'new-password',
				    'help' => gettext('The secret TSIG key. Never shown again; leave it empty to keep the current key.')),
			)),
			array('id' => 'server', 'title' => gettext('Server'), 'fields' => array(
				array('name' => 'server', 'type' => 'text', 'label' => gettext('Server'), 'mono' => true, 'width' => 'half',
				    'placeholder' => '192.0.2.53 5353',
				    'help' => gettext('An address or host name, optionally followed by a space and a port.'),
				    'errorMatch' => array('update server must be')),
				array('name' => 'usetcp', 'type' => 'switch', 'label' => gettext('Protocol'), 'text' => gettext('Use TCP instead of UDP'),
				    'width' => 'half'),
				array('name' => 'usepublicip', 'type' => 'switch', 'label' => gettext('Use public IP'),
				    'text' => gettext('Fetch the public address when the interface address is private')),
				array('name' => 'updatesource', 'type' => 'select', 'label' => gettext('Update source'), 'width' => 'half',
				    'options' => restapi_schema_choices($sources), 'help' => gettext('The address the update is sent from.')),
				array('name' => 'updatesourcefamily', 'type' => 'select', 'label' => gettext('Update source family'), 'width' => 'half',
				    'options' => restapi_schema_choices($families), 'help' => gettext('The address family the update is sent with.')),
			)),
		),
	);
}
