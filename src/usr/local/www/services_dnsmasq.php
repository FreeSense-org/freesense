<?php
/*
 * services_dnsmasq.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2003-2004 Bob Zoller <bob@kludgebox.com>
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
##|*IDENT=page-services-dnsforwarder
##|*NAME=Services: DNS Forwarder
##|*DESCR=Allow access to the 'Services: DNS Forwarder' page.
##|*MATCH=services_dnsmasq.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_dnsmasq.inc");

$retval = 0;

$rv = getDNSMasqConfig();
$pconfig = $rv['config'];
$a_hosts = $rv['hosts'];
$a_domainOverrides = $rv['domainoverrides'];
$iflist = $rv['iflist'];

/* the overrides are views of this page (docs/webui/PLAN.md, rule R1) */
$view = fs_view_param(['general', 'hosts', 'domains'], 'general');
$view_url = 'services_dnsmasq.php' . (($view === 'general') ? '' : '?view=' . $view);

if ($_POST['apply']) {
	$retval = applyDNSMasqConfig();
} else if ($_POST['save']) {
	$rv = saveDNSMasqConfig($_POST);
	$pconfig = $rv['pconfig'];
	$input_errors = $rv['input_errors'];
	$iflist = $rv['iflist'];
} else if ($_POST['act'] == "del") {
	deleteDNSMasqEntry($_POST);
	header("Location: " . $view_url);
	exit;
}

$view_titles = [
	'general' => gettext("General Settings"),
	'hosts' => gettext("Host Overrides"),
	'domains' => gettext("Domain Overrides"),
];
$pgtitle = array(gettext("Services"), gettext("DNS Forwarder"), $view_titles[$view]);
$pglinks = array("", "services_dnsmasq.php", "@self");

if ($view === 'hosts') {
	fs_page_action(gettext('Add host override'), 'services_dnsmasq_edit.php', 'fa-plus');
} elseif ($view === 'domains') {
	fs_page_action(gettext('Add domain override'), 'services_dnsmasq_domainoverride_edit.php', 'fa-plus');
}
$shortcut_section = "forwarder";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('hosts')) {
	print_apply_box(gettext("The DNS forwarder configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

display_isc_warning();

fs_tabs('services-dnsforwarder', $view_url);

if ($view === 'general'):

$form = new Form();

$section = new Form_Section('General DNS Forwarder Options');

$section->addInput(new Form_Checkbox(
	'enable',
	'Enable',
	'Enable DNS forwarder',
	$pconfig['enable']
))->toggles('.toggle-dhcp', 'disable');

if (dhcp_is_backend('isc')):
$section->addInput(new Form_Checkbox(
	'regdhcp',
	'DHCP Registration',
	'Register DHCP leases in DNS forwarder',
	$pconfig['regdhcp']
))->setHelp('If this option is set machines that specify'.
			' their hostname when requesting a DHCP lease will be registered'.
			' in the DNS forwarder, so that their name can be resolved.'.
			' The domain in %1$sSystem: General Setup%2$s should also'.
			' be set to the proper value.', '<a href="system.php">', '</a>')
	->addClass('toggle-dhcp');

$section->addInput(new Form_Checkbox(
	'regdhcpstatic',
	'Static DHCP',
	'Register DHCP static mappings in DNS forwarder',
	$pconfig['regdhcpstatic']
))->setHelp('If this option is set, IPv4 DHCP static mappings will '.
					'be registered in the DNS forwarder so that their name can be '.
					'resolved. The domain in %1$sSystem: General Setup%2$s should also '.
					'be set to the proper value.', '<a href="system.php">', '</a>')
	->addClass('toggle-dhcp');

$section->addInput(new Form_Checkbox(
	'dhcpfirst',
	'Prefer DHCP',
	'Resolve DHCP mappings first',
	$pconfig['dhcpfirst']
))->setHelp("If this option is set DHCP mappings will ".
					"be resolved before the manual list of names below. This only ".
					"affects the name given for a reverse lookup (PTR).")
	->addClass('toggle-dhcp');
endif;

$section->addInput(new Form_Checkbox(
	'no_system_dns',
	'Ignore System DNS',
	'Do not use system DNS servers',
	$pconfig['no_system_dns']
))->setHelp('If this option is set the configured system DNS servers will be ignored '.
					'and custom "server=" options must be used.')
	->addClass('toggle-dhcp');

$group = new Form_Group('DNS Query Forwarding');

$group->add(new Form_Checkbox(
	'strict_order',
	'DNS Query Forwarding',
	'Query DNS servers sequentially',
	$pconfig['strict_order']
))->setHelp('If this option is set %1$s DNS Forwarder (dnsmasq) will '.
					'query the DNS servers sequentially in the order specified (%2$sSystem - General Setup - DNS Servers%3$s), '.
					'rather than all at once in parallel. ', g_get('product_label'), '<i>', '</i>');

$group->add(new Form_Checkbox(
	'domain_needed',
	null,
	'Require domain',
	$pconfig['domain_needed']
))->setHelp("If this option is set %s DNS Forwarder (dnsmasq) will ".
					"not forward A or AAAA queries for plain names, without dots or domain parts, to upstream name servers.	 ".
					"If the name is not known from /etc/hosts or DHCP then a \"not found\" answer is returned. ", g_get('product_label'));

$group->add(new Form_Checkbox(
	'no_private_reverse',
	null,
	'Do not forward private reverse lookups',
	$pconfig['no_private_reverse']
))->setHelp("If this option is set %s DNS Forwarder (dnsmasq) will ".
					"not forward reverse DNS lookups (PTR) for private addresses (RFC 1918) to upstream name servers.  ".
					"Any entries in the Domain Overrides section forwarding private \"n.n.n.in-addr.arpa\" names to a specific server are still forwarded. ".
					"If the IP to name is not known from /etc/hosts, DHCP or a specific domain override then a \"not found\" answer is immediately returned. ", g_get('product_label'));

$section->add($group);

$section->addInput(new Form_Input(
	'port',
	'Listen Port',
	'number',
	$pconfig['port'],
	['placeholder' => '53']
))->setHelp('The port used for responding to DNS queries. It should normally be left blank unless another service needs to bind to TCP/UDP port 53.');

$section->addInput(new Form_Select(
	'interface',
	'*Interfaces',
	$iflist['selected'],
	$iflist['options'],
	true
))->setHelp('Interface IPs used by the DNS Forwarder for responding to queries from clients. If an interface has both IPv4 and IPv6 IPs, ' .
			'both are used. Queries to other interface IPs not selected above are discarded. ' .
			'The default behavior is to respond to queries on every available IPv4 and IPv6 address.');

$section->addInput(new Form_Checkbox(
	'strictbind',
	'Strict binding',
	'Strict interface binding',
	$pconfig['strictbind']
))->setHelp('If this option is set, the DNS forwarder will only bind to the interfaces containing the IP addresses selected above, ' .
					'rather than binding to all interfaces and discarding queries to other addresses.%1$s' .
					'This option does NOT work with IPv6. If set, dnsmasq will not bind to IPv6 addresses.', '<br /><br />');

$section->addInput(new Form_Textarea(
	'custom_options',
	'Custom options',
	$pconfig['custom_options']
))->setHelp('Enter any additional options to add to the dnsmasq configuration here, separated by a space or newline.')
  ->addClass('advanced');

$form->add($section);
print($form);

?>
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
					<th data-fs-search><?=gettext("Domain")?></th>
					<th data-fs-search><?=gettext("IP")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
foreach ($a_hosts as $hostent):
	$fqdn = $hostent['host'] ? $hostent['host'] . '.' . $hostent['domain'] : $hostent['domain'];
	$aliases = [];
	foreach (array_get_path($hostent, 'aliases/item', []) as $alias) {
		$aliases[] = $alias['host'] ? $alias['host'] . '.' . $alias['domain'] : $alias['domain'];
	}
?>
				<tr>
					<td>
						<a href="services_dnsmasq_edit.php?id=<?=htmlspecialchars($hostent['idx'])?>"><?=htmlspecialchars($hostent['host'])?></a>
<?php	if (!empty($aliases)): ?>
						<div class="fs-muted fs-mono small"><?=gettext("Aliases:")?> <?=htmlspecialchars(implode(', ', $aliases))?></div>
<?php	endif; ?>
					</td>
					<td><?=htmlspecialchars($hostent['domain'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($hostent['ip'])?></td>
					<td><?=htmlspecialchars($hostent['descr'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', 'services_dnsmasq_edit.php?id=' . urlencode($hostent['idx']), $fqdn],
							['delete', 'services_dnsmasq.php?type=host&act=del&view=hosts&id=' . urlencode($hostent['idx']), $fqdn, ['thing' => gettext('host override')]],
						])?>
					</td>
				</tr>
<?php
endforeach;

if (empty($a_hosts)) {
	fs_empty_row(5, gettext('No host overrides yet.'), 'services_dnsmasq_edit.php', gettext('Add host override'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<p class="help-block">
	<?=gettext('The forwarder answers lookups for these hosts with the given address instead of asking the upstream DNS servers.')?>
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
foreach ($a_domainOverrides as $doment):
?>
				<tr>
					<td><a href="services_dnsmasq_domainoverride_edit.php?id=<?=htmlspecialchars($doment['idx'])?>"><?=htmlspecialchars($doment['domain'])?></a></td>
					<td class="fs-mono"><?=htmlspecialchars($doment['ip'])?></td>
					<td><?=htmlspecialchars($doment['descr'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', 'services_dnsmasq_domainoverride_edit.php?id=' . urlencode($doment['idx']), $doment['domain']],
							['delete', 'services_dnsmasq.php?act=del&type=doverride&view=domains&id=' . urlencode($doment['idx']), $doment['domain'], ['thing' => gettext('domain override')]],
						])?>
					</td>
				</tr>
<?php
endforeach;

if (empty($a_domainOverrides)) {
	fs_empty_row(4, gettext('No domain overrides yet.'), 'services_dnsmasq_domainoverride_edit.php', gettext('Add domain override'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<p class="help-block">
	<?=gettext('Lookups for these domains (and their subdomains) are sent to the given server instead of the upstream DNS servers. For several authoritative servers of one domain, add one entry per server.')?>
</p>
<?php endif; /* domains */ ?>

<?php if ($view === 'general'): ?>
<div class="infoblock">
<?php
print_info_box(
	'<p>' .
	gettext('If the DNS forwarder is enabled, the DHCP service (if enabled) will automatically' .
		    ' serve the LAN IP address as a DNS server to DHCP clients so they will use the forwarder.') . '</p><p>' .
	sprintf(gettext('The DNS forwarder will use the DNS servers entered in %1$sSystem > General Setup%2$s or' .
				    ' those obtained via DHCP or PPP on WAN if &quot;Allow DNS server list to be overridden by DHCP/PPP on WAN&quot; is checked.' .
				    ' If that option is not used (or if a static IP address is used on WAN),' .
				    ' at least one DNS server must be manually specified on the %1$sSystem > General Setup%2$s page.'),
			'<a href="system.php">',
			'</a>') .
	'</p>',
	'info',
	false
);
?>
</div>
<?php endif; /* general */ ?>

<?php
include("foot.inc");
