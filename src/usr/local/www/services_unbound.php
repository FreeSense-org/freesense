<?php
/*
 * services_unbound.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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

##|+PRIV
##|*IDENT=page-services-dnsresolver
##|*NAME=Services: DNS Resolver
##|*DESCR=Allow access to the 'Services: DNS Resolver' page.
##|*MATCH=services_unbound.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("unbound.inc");
require_once("freesense-utils.inc");
require_once("system.inc");
require_once("services_unbound.inc");

$python_scripts = unbound_python_scripts();
$pconfig = unbound_general_settings();
$certs_available = unbound_certs_available();

if ($_POST['apply']) {
	$retval = unbound_apply_changes();
}

if ($_POST['save']) {
	unset($input_errors);
	$rv = unbound_save_general($_POST);
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];
}


if ($pconfig['custom_options']) {
	$customoptions = true;
} else {
	$customoptions = false;
}

/* the overrides are views of this page (docs/webui/PLAN.md, rule R1) */
$view = fs_view_param(['general', 'hosts', 'domains'], 'general');
$view_url = 'services_unbound.php' . (($view === 'general') ? '' : '?view=' . $view);

if ($_POST['act'] == "del") {
	if (unbound_delete_override($_POST['type'], $_POST['id'])) {
		header("Location: " . $view_url);
		exit;
	}
}

$view_titles = [
	'general' => gettext("General Settings"),
	'hosts' => gettext("Host Overrides"),
	'domains' => gettext("Domain Overrides"),
];
$pgtitle = array(gettext("Services"), gettext("DNS Resolver"), $view_titles[$view]);
$pglinks = array("", "services_unbound.php", "@self");

if ($view === 'hosts') {
	fs_page_action(gettext('Add host override'), 'services_unbound_host_edit.php', 'fa-plus');
} elseif ($view === 'domains') {
	fs_page_action(gettext('Add domain override'), 'services_unbound_domainoverride_edit.php', 'fa-plus');
}
$shortcut_section = "resolver";

include_once("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('unbound')) {
	print_apply_box(gettext("The DNS resolver configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

display_isc_warning();

fs_tabs('services-dnsresolver', $view_url);

if ($view === 'general'):

$form = new Form();

$section = new Form_Section('General DNS Resolver Options');

$section->addInput(new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Enable DNS resolver'),
	$pconfig['enable']
));

$section->addInput(new Form_Input(
	'port',
	gettext('Listen Port'),
	'number',
	$pconfig['port'],
	['placeholder' => '53']
))->setHelp('The port used for responding to DNS queries. It should normally be left blank unless another service needs to bind to TCP/UDP port 53.');

$section->addInput(new Form_Checkbox(
	'enablessl',
	gettext('Enable SSL/TLS Service'),
	gettext('Respond to incoming SSL/TLS queries from local clients'),
	$pconfig['enablessl']
))->setHelp('Configures the DNS Resolver to act as a DNS over SSL/TLS server which can answer queries from clients which also support DNS over TLS. ' .
		'Activating this option disables automatic interface response routing behavior, thus it works best with specific interface bindings.');

if ($certs_available) {
	$section->addInput($input = new Form_Select(
		'sslcertref',
		gettext('SSL/TLS Certificate'),
		$pconfig['sslcertref'],
		cert_build_list('cert', 'IPsec')
	))->setHelp('The server certificate to use for SSL/TLS service. The CA chain will be determined automatically.');
} else {
	$section->addInput(new Form_StaticText(
		'SSL/TLS Certificate',
		sprintf(gettext('No Certificates have been defined. A certificate is required before SSL/TLS can be enabled. %1$s Create or Import %2$s a Certificate.'),
			'<a href="system_certmanager.php">', '</a>')
	));
}

$section->addInput(new Form_Input(
	'tlsport',
	gettext('SSL/TLS Listen Port'),
	'number',
	$pconfig['tlsport'],
	['placeholder' => '853']
))->setHelp('The port used for responding to SSL/TLS DNS queries. It should normally be left blank unless another service needs to bind to TCP/UDP port 853.');

$activeiflist = unbound_build_if_list($pconfig['active_interface']);

$section->addInput(new Form_Select(
	'active_interface',
	'*'.gettext('Network Interfaces'),
	$activeiflist['selected'],
	$activeiflist['options'],
	true
))->addClass('general', 'resizable')->setHelp('Interface IP addresses used by the DNS Resolver for responding to queries from clients. If an interface has both IPv4 and IPv6 addresses, both are used. Queries to addresses not selected in this list are discarded. ' .
			'The default behavior is to respond to queries on every available IPv4 and IPv6 address.');

$outiflist = unbound_build_if_list($pconfig['outgoing_interface']);

$section->addInput(new Form_Select(
	'outgoing_interface',
	'*'.gettext('Outgoing Network Interfaces'),
	$outiflist['selected'],
	$outiflist['options'],
	true
))->addClass('general', 'resizable')->setHelp('Utilize different network interface(s) that the DNS Resolver will use to send queries to authoritative servers and receive their replies. By default all interfaces are used.');

$section->addInput(new Form_Checkbox(
	'strictout',
	gettext('Strict Outgoing Network Interface Binding'),
	gettext('Do not send recursive queries if none of the selected Outgoing Network Interfaces are available.'),
	$pconfig['strictout']
))->setHelp('By default the DNS Resolver sends recursive DNS requests over any available interfaces if none of the selected Outgoing Network Interfaces are available. This option makes the DNS Resolver refuse recursive queries.');

$section->addInput(new Form_Select(
	'system_domain_local_zone_type',
	'*'.gettext('System Domain Local Zone Type'),
	$pconfig['system_domain_local_zone_type'],
	unbound_local_zone_types()
))->setHelp('The local-zone type used for the %1$s system domain (%2$sSystem &gt; General Setup%3$s). Transparent is the default.', g_get('product_label'), '<a href="system.php">','</a>');

$section->addInput(new Form_Checkbox(
	'dnssec',
	'DNSSEC',
	gettext('Enable DNSSEC Support'),
	$pconfig['dnssec']
));

$section->addInput(new Form_Checkbox(
	'python',
	gettext('Python Module'),
	gettext('Enable Python Module'),
	$pconfig['python']
))->setHelp('Enable the Python Module.');

$section->addInput(new Form_Select(
	'python_order',
	gettext('Python Module Order'),
	$pconfig['python_order'],
	[ 'pre_validator' => 'Pre Validator', 'post_validator' => 'Post Validator' ]
))->setHelp('Select the Python Module ordering.');

$section->addInput(new Form_Select(
	'python_script',
	gettext('Python Module Script'),
	$pconfig['python_script'],
	$python_scripts
))->setHelp('Select the Python module script to utilize.');

$section->addInput(new Form_Checkbox(
	'forwarding',
	gettext('DNS Query Forwarding'),
	gettext('Enable Forwarding Mode'),
	$pconfig['forwarding']
))->setHelp('If this option is set, DNS queries will be forwarded to the upstream DNS servers defined under'.
					' %1$sSystem &gt; General Setup%2$s or those obtained via dynamic ' .
					'interfaces such as DHCP, PPP, or OpenVPN (if DNS Server Override ' .
				        'is enabled there).','<a href="system.php">','</a>');

$section->addInput(new Form_Checkbox(
	'forward_tls_upstream',
	null,
	gettext('Use SSL/TLS for outgoing DNS Queries to Forwarding Servers'),
	$pconfig['forward_tls_upstream']
))->setHelp('When set in conjunction with DNS Query Forwarding, queries to all upstream forwarding DNS servers will be sent using SSL/TLS on the default port of 853. Note that ALL configured forwarding servers MUST support SSL/TLS queries on port 853.');

if (dhcp_is_backend('isc')):
$section->addInput(new Form_Checkbox(
	'regdhcp',
	gettext('DHCP Registration'),
	gettext('Register DHCP leases in the DNS Resolver'),
	$pconfig['regdhcp']
))->setHelp('If this option is set, then machines that specify their hostname when requesting an IPv4 DHCP lease will be registered'.
					' in the DNS Resolver so that their name can be resolved.'.
	    				' Note that this will cause the Resolver to reload and flush its resolution cache whenever a DHCP lease is issued.'.
					' The domain in %1$sSystem &gt; General Setup%2$s should also be set to the proper value.','<a href="system.php">','</a>');

$section->addInput(new Form_Checkbox(
	'regdhcpstatic',
	gettext('Static DHCP'),
	gettext('Register DHCP static mappings in the DNS Resolver'),
	$pconfig['regdhcpstatic']
))->setHelp('If this option is set, then DHCP static mappings will be registered in the DNS Resolver, so that their name can be resolved. '.
					'The domain in %1$sSystem &gt; General Setup%2$s should also be set to the proper value.','<a href="system.php">','</a>');
endif;

$section->addInput(new Form_Checkbox(
	'regovpnclients',
	gettext('OpenVPN Clients'),
	gettext('Register connected OpenVPN clients in the DNS Resolver'),
	$pconfig['regovpnclients']
))->setHelp('If this option is set, then the common name (CN) of connected OpenVPN clients will be ' .
	    'registered in the DNS Resolver, so that their name can be resolved. This only works for OpenVPN ' .
	    'servers (Remote Access SSL/TLS or User Auth with Username as Common Name option) operating ' .
	    'in "tun" mode. The domain in %1$sSystem &gt; General Setup%2$s should also be set to the proper value.',
	    '<a href="system.php">','</a>');

$btnadv = new Form_Button(
	'btnadvcustom',
	gettext('Custom options'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('Display Custom Options'),
	$btnadv
));

$section->addInput(new Form_Textarea (
	'custom_options',
	gettext('Custom options'),
	$pconfig['custom_options']
))->setHelp(gettext('Enter any additional configuration parameters to add to the DNS Resolver configuration here, separated by a newline.'));

$form->add($section);
print($form);
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// Show advanced custom options ==============================================
	var showadvcustom = false;

	function show_advcustom(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
			showadvcustom = <?=($customoptions ? 'true' : 'false');?>;
		} else {
			// It was a click, swap the state.
			showadvcustom = !showadvcustom;
		}

		hideInput('custom_options', !showadvcustom);

		if (showadvcustom) {
			text = "<?=gettext('Hide Custom Options');?>";
		} else {
			text = "<?=gettext('Display Custom Options');?>";
		}
		var children = $('#btnadvcustom').children();
		$('#btnadvcustom').text(text).prepend(children);
	}

	// Un-hide additional controls
	$('#btnadvcustom').click(function(event) {
		show_advcustom();
	});

	// On initial load
	if ($('#custom_options').val().length == 0) {
		hideInput('custom_options', true);
	}

	show_advcustom(true);

	// When the Python Module 'enable' is clicked, disable/enable the Python Module options
	function show_python_script() {
		var python = $('#python').prop('checked');
		hideInput('python_order', !python);
		hideInput('python_script', !python);
	}
	show_python_script();
	$('#python').click(function () {
		show_python_script();
	});

});
//]]>
</script>

<?php endif; /* general */ ?>

<?php if ($view === 'hosts'): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Host Overrides'),
	'search' => gettext('Search host overrides…'),
	'noun' => gettext('host overrides'),
	'noun_one' => gettext('host override'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Host")?></th>
					<th data-fs-search><?=gettext("Parent domain of host")?></th>
					<th data-fs-search><?=gettext("IP to return for host")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
$hosts = config_get_path('unbound/hosts', []);
foreach ($hosts as $idx => $hostent):
	$fqdn = $hostent['host'] ? $hostent['host'] . '.' . $hostent['domain'] : $hostent['domain'];
	$aliases = [];
	foreach (array_get_path($hostent, 'aliases/item', []) as $alias) {
		$aliases[] = $alias['host'] ? $alias['host'] . '.' . $alias['domain'] : $alias['domain'];
	}
?>
				<tr>
					<td>
						<a href="services_unbound_host_edit.php?id=<?=$idx?>"><?=htmlspecialchars($hostent['host'])?></a>
<?php	if (!empty($aliases)): ?>
						<div class="fs-muted fs-mono small"><?=gettext("Aliases:")?> <?=htmlspecialchars(implode(', ', $aliases))?></div>
<?php	endif; ?>
					</td>
					<td><?=htmlspecialchars($hostent['domain'])?></td>
					<td class="fs-mono"><?=htmlspecialchars(is_array($hostent['ip']) ? implode(', ', $hostent['ip']) : $hostent['ip'])?></td>
					<td><?=htmlspecialchars($hostent['descr'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', "services_unbound_host_edit.php?id={$idx}", $fqdn],
							['delete', "services_unbound.php?type=host&act=del&id={$idx}&view=hosts", $fqdn, ['thing' => gettext('host override')]],
						])?>
					</td>
				</tr>
<?php
endforeach;

if (empty($hosts)) {
	fs_empty_row(5, gettext('No host overrides yet.'), 'services_unbound_host_edit.php', gettext('Add host override'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<p class="help-block">
	Enter any individual hosts for which the resolver's standard DNS lookup process should be overridden and a specific
	IPv4 or IPv6 address should automatically be returned by the resolver. Standard and also non-standard names and parent domains
	can be entered, such as 'test', 'nas.home.arpa', 'mycompany.localdomain', '1.168.192.in-addr.arpa', or 'somesite.com'. Any lookup attempt for
	the host will automatically return the given IP address, and the usual lookup server for the domain will not be queried for
	the host's records.
</p>
<?php endif; /* hosts */ ?>

<?php if ($view === 'domains'): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Domain Overrides'),
	'search' => gettext('Search domain overrides…'),
	'noun' => gettext('domain overrides'),
	'noun_one' => gettext('domain override'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Domain")?></th>
					<th data-fs-search><?=gettext("Lookup Server IP Address")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
$domains = config_get_path('unbound/domainoverrides', []);
$i = 0;
foreach ($domains as $doment):
?>
				<tr>
					<td><a href="services_unbound_domainoverride_edit.php?id=<?=$i?>"><?=htmlspecialchars($doment['domain'])?></a></td>
					<td class="fs-mono"><?=htmlspecialchars($doment['ip'])?></td>
					<td><?=htmlspecialchars($doment['descr'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', "services_unbound_domainoverride_edit.php?id={$i}", $doment['domain']],
							['delete', "services_unbound.php?act=del&type=doverride&id={$i}&view=domains", $doment['domain'], ['thing' => gettext('domain override')]],
						])?>
					</td>
				</tr>
<?php
	$i++;
endforeach;

if (empty($domains)) {
	fs_empty_row(4, gettext('No domain overrides yet.'), 'services_unbound_domainoverride_edit.php', gettext('Add domain override'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<p class="help-block">
	Enter any domains for which the resolver's standard DNS lookup process should be overridden and a different (non-standard)
	lookup server should be queried instead. Non-standard, 'invalid' and local domains, and subdomains, can also be entered,
	such as 'test', 'nas.home.arpa', 'mycompany.localdomain', '1.168.192.in-addr.arpa', or 'somesite.com'. The IP address is treated as the
	authoritative lookup server for the domain (including all of its subdomains), and other lookup servers will not be queried.
	If there are multiple authoritative DNS servers available for a domain then make a separate entry for each,
	using the same domain name.
</p>
<?php endif; /* domains */ ?>

<?php if ($view === 'general'): ?>
<div class="infoblock">
	<?php print_info_box(sprintf(gettext('If the DNS Resolver is enabled, the DHCP'.
		' service (if enabled) will automatically serve the LAN IP'.
		' address as a DNS server to DHCP clients so they will use'.
		' the DNS Resolver. If Forwarding is enabled, the DNS Resolver will use the DNS servers'.
		' entered in %1$sSystem &gt; General Setup%2$s'.
		' or those obtained via DHCP or PPP on WAN if &quot;Allow'.
		' DNS server list to be overridden by DHCP/PPP on WAN&quot;'.
		' is checked.'), '<a href="system.php">', '</a>'), 'info', false); ?>
</div>
<?php endif; /* general */ ?>

<?php
include("foot.inc");
