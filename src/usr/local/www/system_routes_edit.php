<?php
/*
 * system_routes_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-system-staticroutes-editroute
##|*NAME=System: Static Routes: Edit route
##|*DESCR=Allow access to the 'System: Static Routes: Edit route' page.
##|*MATCH=system_routes_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("filter.inc");
require_once("util.inc");
require_once("gwlb.inc");
require_once("system_routing.inc");

$referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/system_routes.php');

$a_gateways = get_gateways(GW_CACHE_DISABLED | GW_CACHE_LOCALHOST);

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	$id = $_REQUEST['dup'];
}

$this_routes_config = isset($id) ? config_get_path("staticroutes/route/{$id}") : null;
if ($this_routes_config) {
	list($pconfig['network'], $pconfig['network_subnet']) =
		explode('/', $this_routes_config['network']);
	$pconfig['gateway'] = $this_routes_config['gateway'];
	$pconfig['descr'] = $this_routes_config['descr'];
	$pconfig['disabled'] = isset($this_routes_config['disabled']);
}

if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	unset($id);
}

if ($_POST['save']) {

	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = routing_save_static_route($_POST, $id ?? null);

	if (!$input_errors) {
		header("Location: system_routes.php");
		exit;
	}
}

$pgtitle = array(gettext("System"), gettext("Routing"), gettext("Static Routes"), gettext("Edit"));
$pglinks = array("", "system_gateways.php", "system_routes.php", "@self");
$shortcut_section = "routing";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;

if ($this_routes_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$section = new Form_Section('Edit Route Entry');

$section->addInput(new Form_IpAddress(
	'network',
	'*'.gettext('Destination network'),
	$pconfig['network'],
	'ALIASV4V6'
))->addClass('autotrim')
  ->addMask('network_subnet', $pconfig['network_subnet'])->setHelp(gettext('Destination network for this static route'));

$allGateways = array_combine(
	array_map(function($gw){ return $gw['name']; }, $a_gateways),
	array_map(function($gw){ return $gw['name'] .' - '. $gw['gateway']; }, $a_gateways)
);
$section->addInput(new Form_Select(
	'gateway',
	'*'.gettext('Gateway'),
	$pconfig['gateway'],
	$allGateways
))->setHelp(gettext('Choose which gateway this route applies to or %1$sadd a new one first%2$s'),
	'<a href="/system_gateways_edit.php">', '</a>');

$section->addInput(new Form_Checkbox(
	'disabled',
	gettext('Disabled'),
	gettext('Disable this static route'),
	$pconfig['disabled']
))->setHelp(gettext('Set this option to disable this static route without removing it from '.
	'the list.'));

$section->addInput(new Form_Input(
	'descr',
	gettext('Description'),
	'text',
	$pconfig['descr']
))->setHelp(gettext('A description may be entered here for administrative reference (not parsed).'));

$form->add($section);

print $form;

?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// --------- Autocomplete -----------------------------------------------------------------------------------------
	var addressarray = <?= json_encode(get_alias_list('host,network')) ?>;

	$('#network').autocomplete({
		source: addressarray
	});
});
//]]>
</script>
<?php
include("foot.inc");
