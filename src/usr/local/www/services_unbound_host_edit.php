<?php
/*
 * services_unbound_host_edit.php
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
##|*IDENT=page-services-dnsresolver-edithost
##|*NAME=Services: DNS Resolver: Edit host
##|*DESCR=Allow access to the 'Services: DNS Resolver: Edit host' page.
##|*MATCH=services_unbound_host_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_unbound.inc");

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;
$pconfig = unbound_host_settings($id);

if ($_POST['save']) {
	unset($input_errors);
	$rv = unbound_save_host($_POST, $id);
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];

	if (!$input_errors) {
		header("Location: services_unbound.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("DNS Resolver"), gettext("General Settings"), gettext("Edit Host Override"));
$pglinks = array("", "services_unbound.php", "services_unbound.php", "@self");
$shortcut_section = "resolver";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section('Host Override Options');

$section->addInput(new Form_Input(
	'host',
	'Host',
	'text',
	$pconfig['host']
))->setHelp('Name of the host, without the domain part%1$s' .
			'e.g. enter "myhost" if the full domain name is "myhost.example.com"', '<br />');

$section->addInput(new Form_Input(
	'domain',
	'*Domain',
	'text',
	$pconfig['domain']
))->setHelp('Parent domain of the host%1$s' .
			'e.g. enter "example.com" for "myhost.example.com"', '<br />');

$section->addInput(new Form_IpAddress(
	'ip',
	'*IP Address',
	$pconfig['ip']
))->setHelp('IPv4 or IPv6 comma-separated addresses to be returned for the host%1$s' .
			'e.g.: 192.168.100.100 or fd00:abcd::%1$s' .
			'or list 192.168.1.3,192.168.4.5,fc00:123::3' , '<br />');

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

if (isset($id) && config_get_path('unbound/hosts/' . $id)) {
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
	gettext("This page is used to override the usual lookup process for a specific host. A host is defined by its name " .
		"and parent domain (e.g., 'somesite.google.com' is entered as host='somesite' and parent domain='google.com'). Any " .
		"attempt to lookup that host will automatically return the given IP address, and any usual external lookup server for " .
		"the domain will not be queried. Both the name and parent domain can contain 'non-standard', 'invalid' and 'local' " .
		"domains such as 'test', 'nas.home.arpa', 'mycompany.localdomain', or '1.168.192.in-addr.arpa', as well as usual publicly resolvable names ".
		"such as 'www' or 'google.co.uk'.") .
	'</span>'
));

$form->add($section);

$section = new Form_Section('Additional Names for this Host');

$items = array_get_path($pconfig, 'aliases/item', [['host' => '']]);
$counter = 0;
$last = count($items) - 1;

foreach ($items as $item) {
	if (!is_array($item) || empty($item)) {
		continue;
	}
	$group = new Form_Group(null);
	$group->addClass('repeatable');

	$group->add(new Form_Input(
		'aliashost' . $counter,
		null,
		'text',
		$item['host']
	))->setHelp($counter == $last ? 'Host name':null);

	$group->add(new Form_Input(
		'aliasdomain' . $counter,
		null,
		'text',
		$item['domain']
	))->setHelp($counter == $last ? 'Domain':null);

	$group->add(new Form_Input(
		'aliasdescription' . $counter,
		null,
		'text',
		$item['description']
	))->setHelp($counter == $last ? 'Description':null);

	$group->add(new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning')->addClass('nowarn');

	$section->add($group);
	$counter++;
}

$form->addGlobal(new Form_Button(
	'addrow',
	'Add Host Name',
	null,
	'fa-solid fa-plus'
))->removeClass('btn-primary')->addClass('btn-success addbtn');

$section->addInput(new Form_StaticText(
	'',
	'<span class="help-block">'.
	gettext("If the host can be accessed using multiple names, then enter any other names for the host which should also be overridden.") .
	'</span>'
));

$form->add($section);
print($form);

include("foot.inc");
