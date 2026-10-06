<?php
/*
 * services_unbound_domainoverride_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2014 Warren Baker (warren@decoy.co.za)
 * Copyright (c) 2003-2005 Bob Zoller <bob@kludgebox.com>
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
##|*IDENT=page-services-dnsresolver-editdomainoverride
##|*NAME=Services: DNS Resolver: Edit Domain Override
##|*DESCR=Allow access to the 'Services: DNS Resolver: Edit Domain Override' page.
##|*MATCH=services_unbound_domainoverride_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_unbound.inc");

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$this_domainOverrides_config = isset($id) ? config_get_path("unbound/domainoverrides/{$id}") : null;
$pconfig = unbound_domain_override_settings($id);

if ($_POST['save']) {

	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = unbound_save_domain_override($_POST, $id);

	if (!$input_errors) {
		header("Location: services_unbound.php?view=domains");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("DNS Resolver"), gettext("Domain Overrides"),
    $this_domainOverrides_config ? gettext("Edit domain override") : gettext("Add domain override"));
$pglinks = array("", "services_unbound.php", "services_unbound.php?view=domains", "@self");
$shortcut_section = "resolver";
include("head.inc");

if ($input_errors) {
        print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('Domains to Override with Custom Lookup Servers');

$section->addInput(new Form_Input(
	'domain',
	'*Domain',
	'text',
	$pconfig['domain']
))->setHelp('Domain whose lookups will be directed to a user-specified DNS lookup server.');

$section->addInput(new Form_IpAddress(
	'ip',
	'*IP Address',
	$pconfig['ip']
))->setHelp('IPv4 or IPv6 address of the authoritative DNS server for this domain. e.g.: 192.168.100.100%1$s' .
			'To use a non-default port for communication, append an \'@\' with the port number.', '<br />')->setPattern('[a-zA-Z0-9@.:]+');

$section->addInput(new Form_Checkbox(
	'forward_tls_upstream',
	'TLS Queries',
	'Use SSL/TLS for DNS Queries forwarded to this server',
	$pconfig['forward_tls_upstream']
))->setHelp('When set, queries to %1$sall DNS servers for this domain%2$s will be sent using SSL/TLS on the default port of 853.', '<b>', '</b>');

$section->addInput(new Form_Input(
	'tls_hostname',
	'TLS Hostname',
	'text',
	$pconfig['tls_hostname']
))->setHelp('An optional TLS hostname used to verify the server certificate when performing TLS Queries.');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

if ($this_domainOverrides_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$section->addInput(new Form_StaticText(
	'',
	'<span class="help-block">' .
	gettext("This page is used to specify domains for which the resolver's standard DNS lookup process will be overridden, " .
	"and the resolver will query a different (non-standard) lookup server instead. It is possible to enter 'non-standard', 'invalid' " .
	"and 'local' domains such as 'test', 'nas.home.arpa', 'mycompany.localdomain', or '1.168.192.in-addr.arpa', as well as usual publicly resolvable " .
	"domains such as 'org', 'info', or 'google.co.uk'.  The IP address entered will be treated as the IP address of an authoritative " .
	"lookup server for the domain (including all of its subdomains), and other lookup servers will not be queried.") .
	'</span>'
));

$form->add($section);

fs_form_cancel($form, 'services_unbound.php?view=domains');
print $form;

include("foot.inc");
