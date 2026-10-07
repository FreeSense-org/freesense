<?php
/*
 * services_dyndns_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-services-dynamicdnsclient
##|*NAME=Services: Dynamic DNS client
##|*DESCR=Allow access to the 'Services: Dynamic DNS client' page.
##|*MATCH=services_dyndns_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_dyndns.inc");

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$dup = false;
if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	$id = $_REQUEST['dup'];
	$dup = true;
}

$this_dyndns_config = isset($id) ? config_get_path("dyndnses/dyndns/{$id}") : null;
$pconfig = dyndns_client_settings($id, $dup);

if ($_POST['save'] || $_POST['force']) {
	unset($input_errors);
	$pconfig = $_POST;
	$rv = dyndns_save_client($_POST, $id, $dup);
	$input_errors = $rv['input_errors'];
	if (!$input_errors) {
		header("Location: services_dyndns.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("Dynamic DNS"), gettext("Dynamic DNS Clients"), gettext("Edit"));
$pglinks = array("", "services_dyndns.php", "services_dyndns.php", "@self");
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;

$section = new Form_Section('Dynamic DNS Client');

// Confusingly the 'enable' checkbox is labelled 'Disable', but thats the way it works!
// No action (hide or disable) is taken on selecting this.
$section->addInput(new Form_Checkbox(
	'enable',
	'Disable',
	'Disable this client',
	$pconfig['enable']
));

$section->addInput(new Form_Select(
	'type',
	'*Service Type',
	$pconfig['type'],
	dyndns_type_list()
));

$interfacelist = dyndns_build_if_list();

$section->addInput(new Form_Select(
	'interface',
	'*Interface to monitor',
	$pconfig['interface'],
	$interfacelist
))->setHelp('If the interface IP address is private the public IP address will be fetched and used instead.');

$section->addInput(new Form_Select(
	'requestif',
	'*Interface to send update from',
	$pconfig['requestif'],
	$interfacelist
))->setHelp('This is almost always the same as the Interface to Monitor. ');

$section->addInput(new Form_Select(
	'check_ip_mode',
	'Check IP Mode',
	$pconfig['check_ip_mode'],
	dyndns_check_ip_mode_list()
))->setHelp('By default, the Check IP service will only be used if a private address is detected.');

$group = new Form_Group('*Hostname');

$group->add(new Form_Input(
	'host',
	'Hostname',
	'text',
	$pconfig['host']
));
$group->add(new Form_Input(
	'domainname',
	'Domain name',
	'text',
	$pconfig['domainname']
));

$group->setHelp('Enter the complete fully qualified domain name. Example: myhost.dyndns.org%1$s' .
				'Azure, Cloudflare, Linode, LuaDNS, Porkbun: Name.com: Enter @ as the hostname to indicate an empty field.%1$s' .
				'deSEC: Enter the FQDN.%1$s' .
				'DNSimple: Enter only the domain name.%1$s' .
				'DNS Made Easy: Dynamic DNS ID (NOT hostname)%1$s' .
				'GleSYS: Enter the record ID.%1$s' .
				'he.net tunnelbroker: Enter the tunnel ID.%1$s' .
				'Cloudflare, ClouDNS, DigitalOcean, GoDaddy, GratisDNS, Hover, Linode, LuaDNS, Name.com, Namecheap, Porkbun: Enter the hostname and domain name separately.
					The domain name is the domain or subdomain zone being handled by the provider.', '<br />');

$section->add($group);

$section->addInput(new Form_Input(
	'mx',
	'MX',
	'text',
	$pconfig['mx']
))->setHelp('Note: With DynDNS service only a hostname can be used, not an IP address. '.
			'Set this option only if a special MX record is needed. Not all services support this.');

$section->addInput(new Form_Checkbox(
	'wildcard',
	'Wildcards',
	'Enable Wildcard',
	$pconfig['wildcard']
));

$section->addInput(new Form_Checkbox(
	'proxied',
	'Cloudflare Proxy',
	'Enable Proxy',
	$pconfig['proxied']
))->setHelp('Note: This enables Cloudflare Virtual DNS proxy.  When Enabled it will route all traffic '.
			'through their servers. By Default this is disabled and your Real IP is exposed.'.
			'More info: %s', '<a href="https://blog.cloudflare.com/announcing-virtual-dns-ddos-mitigation-and-global-distribution-for-dns-traffic/" target="_blank">Cloudflare Blog</a>');

$section->addInput(new Form_Checkbox(
	'verboselog',
	'Verbose logging',
	'Enable verbose logging',
	$pconfig['verboselog']
))->setHelp('Note: Make sure to set an appropriate default log level (%s) to see informational messages.',
		'<a href="/status_logs_settings.php">Status > System Logs > Settings</a>');

$section->addInput(new Form_Checkbox(
	'curl_ipresolve_v4',
	'HTTP API DNS Options',
	'Force IPv4 DNS Resolution',
	$pconfig['curl_ipresolve_v4']
));

$section->addInput(new Form_Checkbox(
	'curl_ssl_verifypeer',
	'HTTP API SSL/TLS Options',
	'Verify SSL/TLS Certificate Trust',
	$pconfig['curl_ssl_verifypeer'] ?? true
))->setHelp('When set, the server must provide a valid SSL/TLS certificate trust chain which can be verified by this firewall.');

$section->addInput(new Form_Input(
	'username',
	'Username',
	'text',
	$pconfig['username'],
	['autocomplete' => 'new-password']
))->setHelp('Username is required for all providers except Cloudflare, Custom Entries, DigitalOcean, DNS Made Easy, FreeDNS (APIv1&2), FreeDNS-v6 (APIv1&2), Linode and Namecheap.%1$s' .
			'Azure: Enter your Azure AD application ID%1$s' .
			'Cloudflare: Enter email for Global API Key or (optionally) Zone ID for API token.%1$s' .
			'Custom Entries: Username and Password represent HTTP Authentication username and passwords.%1$s' .
			'DNSimple: User account ID (In the URL after the \'/a/\')%1$s' .
			'Domeneshop: Enter the API token.%1$s' .
			'Dreamhost: Enter a value to appear in the DNS record comment.%1$s' .
			'GleSYS: Enter the API user.%1$s' .
			'Godaddy: Enter the API key.%1$s' .
			'LuaDNS: Enter account email.%1$s' .
			'NoIP: For group authentication, replace semicolon (:) with pound-key (#).%1$s' .
			'Porkbun: Enter the API key.%1$s' .
			'Route 53: Enter the Access Key ID.', '<br />');

$section->addPassword(new Form_Input(
	'passwordfld',
	'Password',
	'password',
	$pconfig['password']
))->setHelp('Azure: client secret of the AD application%1$s' .
			'Cloudflare: Enter the Global API Key or API token with DNS edit permission on the provided zone.%1$s' .
			'deSEC: Enter the API token.%1$s' .
			'DigitalOcean: Enter API token%1$s' .
			'DNSExit: Enter the API Key.%1$s' .
			'DNSimple: Enter the API token.%1$s' .
			'DNS Made Easy: Dynamic DNS Password%1$s' .
			'Domeneshop: Enter the API secret.%1$s' .
			'Dreamhost: Enter the API Key.%1$s' .
			'FreeDNS (freedns.afraid.org): Enter the "Token" provided by FreeDNS. The token is after update.php? for API v1 or after  u/ for v2.%1$s' .
			'Gandi LiveDNS: Enter PAT token%1$s' .
			'GleSYS: Enter the API key.%1$s' .
			'GoDaddy: Enter the API secret.%1$s' .
			'Linode: Enter the Personal Access Token.%1$s' .
			'LuaDNS: Enter the API key.%1$s' .
			'Name.com: Enter the API token.%1$s' .
			'Porkbun: Enter the API secret.%1$s' .
			'Route 53: Enter the Secret Access Key.%1$s' .
			'Yandex: Yandex PDD Token.', '<br />');

$section->addInput(new Form_Input(
	'zoneid',
	'Zone ID',
	'text',
	$pconfig['zoneid']
))->setHelp('Azure: Enter the resource id of the of the DNS Zone%1$s' .
			'DNSimple: Enter the Record ID of record to update.%1$s' .
			'Route53: Enter AWS Zone ID.', '<br />');

$section->addInput(new Form_Input(
	'updateurl',
	'Update URL',
	'text',
	$pconfig['updateurl']
))->setHelp('This is the only field required by for Custom Dynamic DNS, and is only used by Custom Entries.');

$section->addInput(new Form_Textarea(
	'resultmatch',
	'Result Match',
	$pconfig['resultmatch']
))->sethelp('This field should be identical to what the DDNS Provider will return if the update succeeds, leave it blank to disable checking of returned results.%1$s' .
			'To include the new IP in the request, put %%IP%% in its place.%1$s' .
			'To include multiple possible values, separate them with a |. If the provider includes a |, escape it with \\|)%1$s' .
			'Tabs (\\t), newlines (\\n) and carriage returns (\\r) at the beginning or end of the returned results are removed before comparison.', '<br />');

$section->addInput(new Form_Input(
	'ttl',
	'TTL',
	'text',
	$pconfig['ttl']
))->setHelp('Choose TTL for the DNS record.');

$section->addInput(new Form_Input(
	'maxcacheage',
	'Max Cache Age',
	'number',
	$pconfig['maxcacheage']
))->setHelp('The number of days after which the DNS record is always updated. The DNS record is updated when: update is forced, WAN address changes or this number of days has passed.');

if (!empty(config_get_path('system/proxyurl'))) {
	$section->addInput(new Form_Checkbox(
		'curl_proxy',
		'Use Proxy',
		'Use Proxy for DynDNS updates',
		$pconfig['curl_proxy'],
	))->setHelp('Use proxy configured under System > Advanced, on the Miscellaneous tab.');
}

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).%1$s' .
			'This field will be used in the Dynamic DNS Status Widget for Custom services.', '<br />');

if ($this_dyndns_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));

	$form->addGlobal(new Form_Button(
		'force',
		'Save & Force Update',
		null,
		'fa-solid fa-arrows-rotate'
	))->addClass('btn-outline-secondary');
}

$form->add($section);

fs_form_cancel($form, 'services_dyndns.php');
print($form);

// Certain input elements are hidden/shown based on the service type in the following script
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	function setVisible(service) {
		// First, set default visibility and then modify per service, if needed.
		hideGroupInput('domainname', true);
		hideInput('resultmatch', true);
		hideInput('updateurl', true);
		hideInput('requestif', true);
		hideCheckbox('curl_ipresolve_v4', true);
		hideCheckbox('curl_ssl_verifypeer', true);
		hideInput('username', false); // show by default
		hideInput('host', false); // show by default
		hideInput('mx', false); // show by default
		hideCheckbox('wildcard', false); // show by default
		hideCheckbox('proxied', true);
		hideInput('zoneid', true);
		hideInput('ttl', true);
		hideInput('maxcacheage', true);

		switch (service) {
			case "custom" :
			case "custom-v6" :
				hideInput('resultmatch', false);
				hideInput('updateurl', false);
				hideInput('requestif', false);
				hideCheckbox('curl_ipresolve_v4', false);
				hideCheckbox('curl_ssl_verifypeer', false);
				hideInput('host', true);
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				hideInput('maxcacheage', false);
				break;
			// providers in an alphabetical order (based on the first provider in a group of cases)
			case "azure":
			case "azurev6":
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				hideInput('zoneid', false);
				hideInput('ttl', false);
				break;
			case "cloudflare":
			case "cloudflare-v6":
				hideGroupInput('domainname', false);
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				hideCheckbox('proxied', false);
				hideInput('ttl', false);
				break;
			case "cloudns":
				hideGroupInput('domainname', false);
				hideInput('ttl', false);
				break;
			case 'desec':
			case 'desec-v6':
				hideInput('username', true);
				hideInput('mx', true);
				break;
			case "digitalocean":
			case "digitalocean-v6":
			case "gandi-livedns":
			case "gandi-livedns-v6":
			case "yandex":
			case "yandex-v6":
				hideGroupInput('domainname', false);
				hideInput('username', true);
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				hideInput('ttl', false);
				break;
			case "dnsimple":
			case "dnsimple-v6":
				hideCheckbox('curl_ssl_verifypeer', false);
				hideInput('zoneid', false);
				hideInput('ttl', false);
				break;
			case "domeneshop":
			case "domeneshop-v6":
			case "dreamhost":
			case "dreamhost-v6":
			case "dyfi":
			case "nicru":
			case "nicru-v6":
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				break;
			case "godaddy":
			case "godaddy-v6":
			case "linode":
			case "linode-v6":
			case "luadns":
			case "luadns-v6":
			case "name.com":
			case "name.com-v6":
			case "onecom":
			case "onecom-v6":
			case "porkbun":
			case "porkbun-v6":
				hideGroupInput('domainname', false);
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				hideInput('ttl', false);
				break;
			case "gratisdns":
			case "hover":
			case "namecheap":
				hideGroupInput('domainname', false);
				break;
			case "mythicbeasts":
			case "mythicbeasts-v6":
				hideGroupInput('domainname', false);
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				break;
			case "route53":
			case "route53-v6":
				hideCheckbox('curl_ssl_verifypeer', false);
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				hideInput('zoneid', false);
				hideInput('ttl', false);
				break;
			case "strato":
			case "dnsmadeeasy":
				hideInput('mx', true);
				hideCheckbox('wildcard', true);
				break;
			default:
		}
	}

	// When the 'Service type" selector is changed, we show/hide certain elements
	$('#type').on('change', function() {
		setVisible(this.value);
	});

	// ---------- On initial page load ------------------------------------------------------------

	setVisible($('#type').val());

});
//]]>
</script>

<?php include("foot.inc");
