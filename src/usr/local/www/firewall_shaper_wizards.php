<?php
/*
 * firewall_shaper_wizards.php
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

##|+PRIV
##|*IDENT=page-firewall-trafficshaper-wizard
##|*NAME=Firewall: Traffic Shaper: Wizard
##|*DESCR=Allow access to the 'Firewall: Traffic Shaper: Wizard' page.
##|*MATCH=firewall_shaper_wizards.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("util.inc");

if ($_POST['apply']) {
	write_config("Traffic Shaper Wizard settings applied");

	$retval = 0;
	/* Setup pf rules since the user may have changed the optimization value */
	$retval |= filter_configure();

	/* reset rrd queues */
	unlink_if_exists("/var/db/rrd/*queuedrops.rrd");
	unlink_if_exists("/var/db/rrd/*queues.rrd");
	enable_rrd_graphing();

	clear_subsystem_dirty('shaper');
}

$shaperIFlist = get_configured_interface_with_descr();

$pgtitle = array(gettext("Firewall"), gettext("Traffic Shaper"), gettext("Wizards"));
$pglinks = array("", "firewall_shaper.php", "@self");
$shortcut_section = "trafficshaper";

$wizards = array(
	array(
		'title' => gettext("Multiple LAN/WAN"),
		'xml' => "traffic_shaper_wizard_multi_all.xml",
		'icon' => 'fa-network-wired',
		'descr' => gettext("For most networks. Shapes traffic between any number of WAN and LAN connections that share their bandwidth."),
	),
	array(
		'title' => gettext("Dedicated links"),
		'xml' => "traffic_shaper_wizard_dedicated.xml",
		'icon' => 'fa-link',
		'descr' => gettext("For WAN and LAN pairs that each have their own bandwidth, such as a link dedicated to one site or customer."),
	),
);
$wizard_steps = array(gettext('Voice over IP'), gettext('Penalty box'), gettext('Peer to peer'), gettext('Network games'), gettext('Other applications'));
$altq_capable = !empty(get_interface_list_to_show()) || !empty(config_get_path('shaper/queue', []));

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('firewall-shaper', 'firewall_shaper_wizards.php');

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('shaper')) {
	print_apply_box(gettext("The traffic shaper configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

?>

<style>
.fs-wizards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr)); gap: var(--fs-sp-4); margin-bottom: var(--fs-sp-4); }
.fs-wizard { display: flex; flex-direction: column; margin: 0; }
.fs-wizard-body { display: flex; flex: 1; flex-direction: column; gap: var(--fs-sp-3); padding: var(--fs-sp-4); }
.fs-wizard-head { display: flex; align-items: center; gap: var(--fs-sp-3); }
.fs-wizard-icon { display: inline-flex; flex: none; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: var(--fs-r-md); background: var(--fs-accent-tint); color: var(--fs-coral-text); }
.fs-wizard-title { margin: 0; color: var(--fs-text-strong); font-size: var(--fs-fs-md); font-weight: 600; }
.fs-wizard-body p { margin: 0; color: var(--fs-text-muted); }
.fs-wizard-steps { margin-top: auto; }
.fs-wizard .panel-footer { display: flex; justify-content: flex-end; padding: var(--fs-sp-3) var(--fs-sp-4); }
.fs-wizards-note { margin: 0 0 var(--fs-sp-4); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-shaper-empty .fs-tool-empty { text-align: center; }
.fs-shaper-empty .fs-tool-empty p { max-width: 32rem; margin: 0; }
.fs-shaper-empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--fs-sp-2); margin-top: var(--fs-sp-2); }
</style>

<?php if ($altq_capable): ?>
<div class="fs-wizards">
<?php foreach ($wizards as $wizard): ?>
	<section class="panel panel-default fs-wizard" aria-labelledby="wizard-<?=fs_h(basename($wizard['xml'], '.xml'))?>">
		<div class="fs-wizard-body">
			<div class="fs-wizard-head">
				<span class="fs-wizard-icon"><i class="fa-solid <?=fs_h($wizard['icon'])?>" aria-hidden="true"></i></span>
				<h2 class="fs-wizard-title" id="wizard-<?=fs_h(basename($wizard['xml'], '.xml'))?>"><?=fs_h($wizard['title'])?></h2>
			</div>
			<p><?=fs_h($wizard['descr'])?></p>
			<div class="fs-wizard-steps">
				<div class="fs-muted small mb-1"><?=gettext('Steps for')?></div>
				<div class="fs-chips">
<?php	foreach ($wizard_steps as $step): ?>
					<span class="fs-chip"><?=fs_h($step)?></span>
<?php	endforeach; ?>
				</div>
			</div>
		</div>
		<div class="panel-footer">
			<a class="btn btn-primary" href="wizard.php?xml=<?=fs_h($wizard['xml'])?>"><i class="fa-solid fa-wand-magic-sparkles icon-embed-btn" aria-hidden="true"></i><?=gettext('Start wizard')?></a>
		</div>
	</section>
<?php endforeach; ?>
</div>
<p class="fs-wizards-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <?=gettext('Finishing a wizard replaces the current shaper queues and adds firewall rules for the chosen applications.')?></p>
<?php else: ?>
<div class="panel panel-default fs-shaper-empty">
	<div class="fs-tool-empty">
		<i class="fa-solid fa-sliders" aria-hidden="true"></i>
		<strong><?=gettext('No interface can use ALTQ traffic shaping.')?></strong>
		<p><?=gettext('The wizards build ALTQ queues, and none of the assigned interfaces has a driver that supports them. Limiters work on every interface.')?></p>
		<div class="fs-shaper-empty-actions">
			<a class="btn btn-primary" href="firewall_shaper_vinterface.php"><i class="fa-solid fa-gauge-high icon-embed-btn" aria-hidden="true"></i><?=gettext('Use limiters')?></a>
		</div>
	</div>
</div>
<?php endif; ?>

<?php
include("foot.inc");
