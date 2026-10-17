<?php
/*
 * system_fleet.php
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
 * System > Central Management: whether a FreeSense Fleet controller manages
 * this firewall, and what it keeps here. A firewall that is not managed
 * gets the "Add to fleet" guide: paste the controller's invitation, check
 * it, join (Tailscale is installed and logged in when the invitation says
 * so) and compare the approval code. This page's privilege also grants
 * the /api/v1/management endpoints (restapi/routes_management.inc).
 */

##|+PRIV
##|*IDENT=page-system-fleet
##|*NAME=System: Central Management
##|*DESCR=Allow access to the 'System: Central Management' page and the matching REST API endpoints, used by a FreeSense Fleet controller.
##|*WARN=standard-warning-root
##|*MATCH=system_fleet.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("fleet_agent.inc");
require_once("fleet_node.inc");
require_once("pkg-utils.inc");
require_once("system_boot_environments.inc");

$pgtitle = array(gettext("System"), gettext("Central Management"));
$m = fleet_node_membership();

/* The join guide's progress, polled by js/fleet-join.js. */
if (($_GET['progress'] ?? '') === '1') {
	$steps = @json_decode((string)@file_get_contents(FLEET_PROGRESS_FILE), true);
	$state = fleet_join_guide_state(is_array($steps) ? $steps : array(), (string)($_SESSION['fleet_join_mode'] ?? ''), is_file(FLEET_JOIN_REQUEST_FILE));
	$state['enrolled'] = $m['enrolled'];
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode($state);
	exit;
}

/* The replicas this box keeps, as one file for Recover fleet on a new controller (they stay encrypted). */
if (($_POST['vault_download'] ?? '') === '1') {
	$name = 'fleet-vault-' . preg_replace('/[^A-Za-z0-9.-]/', '_', (string)config_get_path('system/hostname', 'firewall')) . '-' . date('Ymd-His') . '.fsfa';
	header('Content-Type: application/gzip');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	header('Cache-Control: no-store');
	log_error(sprintf(gettext('Fleet: the backup vault was downloaded by %s.'), $_SESSION['Username'] ?? '?'));
	fleet_vault_export(fopen('php://output', 'w'));
	exit;
}

$input_errors = array();
$advice = null;
$checked = null;
$invitation = (string)($_POST['invitation'] ?? '');
$key_typed = trim((string)($_POST['join_key'] ?? ''));

if ($m['enrolled'] && (($_POST['leave'] ?? '') === 'leave')) {
	fleet_leave('leave');
	header('Location: /system_fleet.php');
	exit;
}

/*
 * The guide, one step at a time: Paste -> Check -> Join key (not needed when
 * the invitation brought it) -> Review -> Join -> Approve -> Done. Each step
 * posts where it goes in step_go; the invitation and a typed key travel along
 * in hidden fields, so Back keeps them.
 */
$steps = array('paste' => gettext('Paste'), 'check' => gettext('Check'), 'key' => gettext('Join key'), 'review' => gettext('Review'),
    'join' => gettext('Join'), 'code' => gettext('Approve'), 'done' => gettext('Done'));
$step = 'paste';
$skipped = array();
$go = (string)($_POST['step_go'] ?? '');
if (!$m['enrolled'] && ((($go !== '') && ($go !== 'paste')) || isset($_POST['join_start']))) {
	/* Manual: link and key in their own fields. */
	if (($_POST['view'] ?? '') === 'manual') {
		$invitation = trim((string)($_POST['manual_link'] ?? ''));
	}
	try {
		$paste = fleet_join_paste_parse($invitation);
		$link = $paste['link'];
		$checked = array('link' => $link, 'key' => $paste['key'], 'reach' => null);
		$step = 'check';
		/* Over Tailscale the controller answers only once this firewall is in the tailnet: checked while joining. */
		if ($link['mode'] !== 'tailscale') {
			if (($go === 'check') || isset($_POST['join_start'])) {
				fleet_join_probe($link);
			}
			$checked['reach'] = true;
		}
		if (($paste['key'] !== null) && ($key_typed === '')) {
			$skipped[] = 'key';
		}
		$key = ($key_typed !== '') ? fleet_join_key_normalize($key_typed) : $paste['key'];
		if ($go === 'key') {
			$step = in_array('key', $skipped, true) ? 'review' : 'key';
		}
		if (($go === 'review') || isset($_POST['join_start'])) {
			$step = 'key';
			if ($key === null) {
				throw new FleetJoinError('E103', ($key_typed === '') ? 'Enter the join key.' : 'The join key is not valid. Check it for typing mistakes.');
			}
			$step = 'review';
		}
		if (isset($_POST['join_start'])) {
			@unlink(FLEET_PROGRESS_FILE);
			/* $key is the normalised data; with its check character fleet_join() takes it like a typed key. */
			file_put_contents(FLEET_JOIN_REQUEST_FILE, json_encode(array('text' => $paste['link_text'], 'key' => $key . fleet_join_key_check($key))), LOCK_EX);
			chmod(FLEET_JOIN_REQUEST_FILE, 0600);
			$_SESSION['fleet_join_mode'] = $link['mode'];
			mwexec_bg('/usr/local/bin/php /usr/local/sbin/freesense-join webjob');
			header('Location: /system_fleet.php?view=progress');
			exit;
		}
	} catch (FleetJoinError $e) {
		list($title, $cause) = fleet_join_advice($e->fleet_code);
		$advice = array('code' => $e->fleet_code, 'text' => $e->getMessage(), 'title' => $title, 'cause' => $cause);
		if ($checked === null) {
			$step = 'paste';
		} elseif ($e->fleet_code === 'E103') {
			$step = 'key';
		} else {
			$step = 'check';
			$checked['reach'] = false;
		}
	}
}

$job = fleet_join_guide_state((array)(@json_decode((string)@file_get_contents(FLEET_PROGRESS_FILE), true) ?: array()),
    (string)($_SESSION['fleet_join_mode'] ?? ''), is_file(FLEET_JOIN_REQUEST_FILE));
$running = !$m['enrolled'] && in_array($job['state'], array('running', 'waiting'), true);
$view = $running ? 'progress' : fs_view_param(array('guide', 'manual', 'progress'), 'guide');
if (($view === 'progress') && ($job['state'] === 'idle')) {
	$view = 'guide';
}

$methods = array('wireguard' => gettext('Fleet WireGuard'), 'tailscale' => gettext('Tailscale'), 'direct' => gettext('Direct'));
$link_methods = array(
	'tunnel' => array(gettext('Fleet tunnel (WireGuard)'), gettext('This firewall builds an encrypted WireGuard tunnel to the controller. It only connects out: no port needs to be opened here.')),
	'tailscale' => array(gettext('Tailscale'), gettext('Tailscale is installed when it is missing and logged in with the invitation\'s one-time key; the controller is then reached in the tailnet. The key is removed after use.')),
	'direct' => array(gettext('Direct'), gettext('The controller reaches this firewall directly on a network both share. A WireGuard tunnel is built as well, as the fallback.')),
);
$roles = array('monitor' => gettext('Monitor only'), 'managed' => gettext('Managed'));

include("head.inc");

if ($m['enrolled']):
	$bundle = fleet_policy_bundle_read();
	$vault = fleet_vault_usage($m);
	$be = be_load();
	$protected = fleet_rollback_protection($be['available'] && $be['compatible'], (array)config_get_path('system/bootenv', []));
	fs_summary_card([
		'icon' => 'fa-network-wired',
		'title' => $m['controller']['url'],
		'subtitle' => gettext('Fleet controller'),
		'badges' => [fs_badge('online', gettext('Managed'))],
		'meta' => $m['node_id'],
		'label' => gettext('Fleet membership'),
		'facts' => [
			[gettext('Connection'), $methods[$m['method']] ?? ''],
			[gettext('Role'), $roles[$m['role']]],
			[gettext('Overlay address'), $m['overlay_address'], 'mono' => true],
			[gettext('Controller fingerprint'), $m['controller']['fingerprint'], 'mono' => true],
			[gettext('Joined'), ($m['joined'] === null) ? '' : date('Y-m-d H:i', $m['joined'])],
		],
	]);
?>
<div class="fs-tiles">
<?php
	fs_tile(gettext('Policy bundle'), ($bundle === null) ? gettext('None') : sprintf(gettext('Version %d'), (int)$bundle['bundle']['version']),
	    null, ($bundle === null) ? null : sprintf(gettext('Received %s'), date('Y-m-d H:i', (int)$bundle['received'])));
	fs_tile(gettext('Backup replicas'), $vault['replicas'], null,
	    sprintf(gettext('%1$s of %2$s (%3$d%% of free space)'), format_bytes($vault['used_bytes']), format_bytes($vault['quota_bytes']),
	    $vault['quota_pct']));
	fs_tile(gettext('Rollback protection'), $protected ? gettext('Yes') : gettext('No'), $protected ? null : 'warn',
	    $protected ? gettext('ZFS boot environments') : gettext('A failed update cannot roll back by itself. ZFS is recommended.'));
?>
</div>
<?php if ($vault['replicas'] > 0): ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Fleet backup copies')?></h2></div>
	<div class="panel-body">
		<p><?=sprintf(gettext('This firewall keeps %1$d encrypted backup copies for its fleet (%2$s). It cannot read them. If the controller is lost, download them here and use Recover fleet on a new controller with the fleet\'s recovery key.'),
		    $vault['replicas'], format_bytes($vault['used_bytes']))?></p>
		<form method="post">
			<button class="btn btn-sm btn-outline-secondary" type="submit" name="vault_download" value="1"><i class="fa-solid fa-download icon-embed-btn" aria-hidden="true"></i><?=gettext('Download the copies (.fsfa)')?></button>
		</form>
	</div>
</div>
<?php endif; ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Leave the fleet')?></h2></div>
	<div class="panel-body">
		<p><?=gettext('The controller is told, the Fleet connection and access are removed, and this firewall keeps its configuration. Its last configuration from the fleet stays in the archive.')?></p>
		<form method="post">
			<button class="btn btn-sm btn-outline-danger do-confirm" type="submit" name="leave" value="leave" title="<?=gettext('Leave the fleet')?>"><i class="fa-solid fa-right-from-bracket icon-embed-btn" aria-hidden="true"></i><?=gettext('Leave the fleet')?></button>
		</form>
	</div>
</div>
<?php
	include("foot.inc");
	exit;
endif;

/* ---- the fleet controller itself joins no fleet ---- */
if (!empty(config_get_path('installedpackages/fleet/config/0/enable'))):
?>
<section class="fs-fleet-guide">
	<header class="fs-fleet-guide-head">
		<i class="fa-solid fa-network-wired" aria-hidden="true"></i>
		<div><h2><?=gettext('This firewall is the fleet controller')?></h2>
			<p><?=gettext('It manages the fleet and is not managed by one. Add other firewalls from Fleet > Firewalls > Add and approve.')?></p></div>
	</header>
	<p><a class="btn btn-primary" href="/fleet/fleet_dashboard.php"><i class="fa-solid fa-arrow-right icon-embed-btn" aria-hidden="true"></i><?=gettext('Open Fleet')?></a></p>
</section>
<?php
	include("foot.inc");
	exit;
endif;

/* ---- not in a fleet: the guide ---- */
$hidden = function (array $fields) {
	foreach ($fields as $n => $v) {
		echo '<input type="hidden" name="' . htmlspecialchars($n) . '" value="' . htmlspecialchars($v) . '"/>';
	}
};
$carry = array('invitation' => $invitation) + (($key_typed !== '') ? array('join_key' => $key_typed) : array());
$nav_back = function (string $to) {
	return '<button class="btn btn-outline-secondary" type="submit" name="step_go" value="' . htmlspecialchars($to) . '" formnovalidate>' .
	    '<i class="fa-solid fa-arrow-left icon-embed-btn" aria-hidden="true"></i>' . gettext('Back') . '</button>';
};
$nav_next = function (string $to, string $label, string $icon = 'fa-arrow-right') {
	return '<button class="btn btn-primary" type="submit" name="step_go" value="' . htmlspecialchars($to) . '">' .
	    '<i class="fa-solid ' . $icon . ' icon-embed-btn" aria-hidden="true"></i>' . htmlspecialchars($label) . '</button>';
};
?>
<section class="fs-fleet-guide">
	<header class="fs-fleet-guide-head">
		<i class="fa-solid fa-network-wired" aria-hidden="true"></i>
		<div>
			<h2><?=gettext('Add to fleet')?></h2>
			<p><?=gettext('A FreeSense Fleet controller can monitor and manage this firewall. Ask its administrator for an invitation (Fleet > Firewalls > Add and approve) and follow the steps. Nothing changes on this firewall until you select Join on the review.')?></p>
		</div>
	</header>
<?php if ($view !== 'progress') { fs_view_switch(array('guide' => gettext('Guided'), 'manual' => gettext('Manual')), $view); } ?>
<?php if ($view !== 'manual') { fs_stepper($steps, ($view === 'progress') ? 'join' : $step, $skipped, gettext('Add to fleet')); } ?>
<?php
if ($advice !== null) {
	print_callout('<p>' . htmlspecialchars($advice['cause']) . '</p><p class="fs-muted small mb-0">FLEET-' . htmlspecialchars($advice['code']) . ': ' .
	    htmlspecialchars($advice['text']) . '</p>', ($advice['code'] === 'E101') ? 'danger' : 'warning', $advice['title']);
}
?>
<?php if ($view === 'progress'): ?>
	<div class="panel panel-default" id="fs-fleet-join" data-poll="/system_fleet.php?progress=1"
	    data-strings="<?=htmlspecialchars(json_encode(array('done' => gettext('Done'), 'running' => gettext('Working'), 'todo' => gettext('Waiting'),
	    'fail' => gettext('Failed'), 'wait' => gettext('Waiting for approval'), 'step_done' => gettext('done'), 'step_current' => gettext('current step'),
	    'step_todo' => gettext('to do'), 'step_skipped' => gettext('not needed'))))?>">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('5. Join')?></h2></div>
		<div class="panel-body">
			<p class="fs-step-lead"><?=gettext('This runs in the background; you can leave this page and come back. If any step fails, everything is undone.')?></p>
			<ol class="fs-fleet-steps" data-join-steps></ol>
			<p class="fs-muted small" data-join-say aria-live="polite"></p>
			<div class="fs-fleet-code" data-join-code hidden>
				<p><?=gettext('Ask the controller\'s administrator to approve this firewall (Fleet > Firewalls > Add and approve) when the controller shows the same code:')?></p>
				<output class="fs-fleet-code-digits" data-join-digits aria-live="polite"></output>
			</div>
			<div data-join-done hidden>
				<?php print_callout(htmlspecialchars(gettext('This firewall is now managed by the fleet. The controller takes its first backup in the next minutes.')), 'info', gettext('Joined')); ?>
				<a class="btn btn-primary" href="/system_fleet.php"><?=gettext('Show the membership')?></a>
			</div>
			<div data-join-failed hidden>
				<div class="alert alert-warning fs-callout fs-callout-warning" role="note"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
					<div class="fs-callout-body"><h2 class="fs-callout-title" data-join-err-title></h2><p data-join-err-cause></p>
					<p class="fs-muted small mb-0" data-join-err-text></p></div></div>
				<p><?=gettext('Everything this attempt changed was undone.')?></p>
				<a class="btn btn-outline-secondary" href="/system_fleet.php"><?=gettext('Start again')?></a>
			</div>
		</div>
	</div>
<?php elseif ($view === 'manual'): ?>
	<form method="post" class="panel panel-default">
		<input type="hidden" name="view" value="manual"/>
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Join with a link and a key')?></h2></div>
		<div class="panel-body">
			<div class="mb-3"><label class="form-label" for="manual_link"><?=gettext('Join link')?></label>
				<input class="form-control fs-mono" id="manual_link" name="manual_link" required autocomplete="off" spellcheck="false"
				    value="<?=htmlspecialchars(trim((string)($_POST['manual_link'] ?? '')))?>" placeholder="https://fleet.example.net:8443/fleet/join#..."/>
				<div class="form-text"><?=gettext('The link says how this firewall connects (Fleet tunnel, Tailscale or direct).')?></div></div>
			<div class="mb-3"><label class="form-label" for="manual_key"><?=gettext('Join key')?></label>
				<input class="form-control fs-mono" id="manual_key" name="join_key" required autocomplete="off" spellcheck="false" placeholder="FLT-XXXX-XXXX-XXXX-XXXX-XXXX"/></div>
			<p class="fs-muted small"><?=gettext('On the console the same is option 17, or: freesense-join join \'<link>\'')?></p>
		</div>
		<div class="panel-footer"><button class="btn btn-primary" type="submit" name="join_start" value="1"><i class="fa-solid fa-right-to-bracket icon-embed-btn" aria-hidden="true"></i><?=gettext('Join')?></button></div>
	</form>
<?php elseif ($step === 'paste'): ?>
	<form method="post" class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('1. Paste the invitation')?></h2></div>
		<div class="panel-body">
			<p class="fs-step-lead"><?=gettext('On the controller, create an invitation and select "Copy for the firewall". Paste all of it here. A link alone works too; you then type the key in a later step.')?></p>
			<label class="form-label" for="invitation"><?=gettext('Invitation')?></label>
			<textarea class="form-control fs-mono" id="invitation" name="invitation" rows="4" required autocomplete="off" spellcheck="false"
			    placeholder="FreeSense Fleet invitation&#10;https://fleet.example.net:8443/fleet/join#...&#10;Key: FLT-XXXX-XXXX-XXXX-XXXX-XXXX"><?=htmlspecialchars($invitation)?></textarea>
		</div>
		<div class="panel-footer fs-step-nav"><?=$nav_next('check', gettext('Next: check it'))?></div>
	</form>
<?php elseif ($step === 'check'): list($mname, $mtext) = $link_methods[$checked['link']['mode']]; ?>
	<form method="post" class="panel panel-default">
		<?php $hidden($carry); ?>
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('2. Check')?></h2></div>
		<div class="panel-body">
			<p class="fs-step-lead"><?=gettext('This firewall asked the controller for its certificate and compared it with the one the invitation names. Nothing was changed.')?></p>
			<dl class="fs-review">
				<dt><?=gettext('Controller')?></dt><dd class="fs-mono"><?=htmlspecialchars($checked['link']['authority'])?></dd>
				<dt><?=gettext('Reachable')?></dt><dd><?=($checked['reach'] === null) ? fs_badge('neutral', gettext('Checked after Tailscale is up')) :
				    ($checked['reach'] ? fs_badge('online', gettext('Yes, certificate as in the invitation')) : fs_badge('offline', gettext('No')))?></dd>
				<dt><?=gettext('Connection')?></dt><dd><strong><?=htmlspecialchars($mname)?></strong><br/><span class="fs-muted small"><?=htmlspecialchars($mtext)?></span></dd>
				<dt><?=gettext('Join key')?></dt><dd><?=in_array('key', $skipped, true) ? fs_badge('online', gettext('In the invitation')) : htmlspecialchars(gettext('Typed in the next step'))?></dd>
			</dl>
		</div>
		<div class="panel-footer fs-step-nav"><?=$nav_back('paste')?>
<?php if ($checked['reach'] === false): ?>
			<?=$nav_next('check', gettext('Check again'), 'fa-rotate')?>
<?php else: ?>
			<?=$nav_next('key', gettext('Next'))?>
<?php endif; ?>
		</div>
	</form>
<?php elseif ($step === 'key'): ?>
	<form method="post" class="panel panel-default">
		<?php $hidden(array('invitation' => $invitation)); ?>
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('3. Join key')?></h2></div>
		<div class="panel-body">
			<p class="fs-step-lead"><?=gettext('The controller\'s administrator gives you the join key separately from the link, so the link alone cannot add a firewall.')?></p>
			<label class="form-label" for="join_key"><?=gettext('Join key')?></label>
			<input class="form-control fs-mono fs-field-md" id="join_key" name="join_key" required autocomplete="off" spellcheck="false" placeholder="FLT-XXXX-XXXX-XXXX-XXXX-XXXX"
			    value="<?=htmlspecialchars($key_typed)?>"/>
		</div>
		<div class="panel-footer fs-step-nav"><?=$nav_back('check')?><?=$nav_next('review', gettext('Next: review'))?></div>
	</form>
<?php else:
	list($mname, $mtext) = $link_methods[$checked['link']['mode']];
	$ts_needed = ($checked['link']['mode'] === 'tailscale');
	$ts_have = $ts_needed && is_pkg_installed('FreeSense-pkg-Tailscale');
?>
	<form method="post" class="panel panel-default">
		<?php $hidden($carry); ?>
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('4. Review')?></h2></div>
		<div class="panel-body">
			<p class="fs-step-lead"><?=gettext('This is what happens when you select Join.')?></p>
			<dl class="fs-review">
				<dt><?=gettext('Controller')?></dt><dd class="fs-mono"><?=htmlspecialchars($checked['link']['authority'])?></dd>
				<dt><?=gettext('Connection')?></dt><dd><strong><?=htmlspecialchars($mname)?></strong><br/><span class="fs-muted small"><?=htmlspecialchars($mtext)?></span></dd>
<?php if ($ts_needed): ?>
				<dt><?=gettext('Tailscale')?></dt><dd><?=htmlspecialchars($ts_have ? gettext('Installed; it logs in with the invitation\'s one-time key.') :
				    gettext('Installed now (package FreeSense-pkg-Tailscale), then logged in with the invitation\'s one-time key.'))?></dd>
<?php endif; ?>
				<dt><?=gettext('Join key')?></dt><dd><?=htmlspecialchars(in_array('key', $skipped, true) ? gettext('From the invitation') : gettext('As typed'))?></dd>
				<dt><?=gettext('Name and group')?></dt><dd><?=gettext('Set by the controller when it approves this firewall.')?></dd>
			</dl>
			<ol class="mb-0">
<?php if ($ts_needed): ?>
				<li><?=htmlspecialchars($ts_have ? gettext('Tailscale logs in to the controller\'s tailnet.') : gettext('Tailscale is installed and logs in to the controller\'s tailnet.'))?></li>
<?php endif; ?>
				<li><?=gettext('This firewall makes a WireGuard key and asks the controller to join.')?></li>
				<li><?=gettext('A 6-digit approval code is shown here and on the controller; the administrator approves when they match.')?></li>
				<li><?=gettext('The controller gets access to this firewall and takes its first backup.')?></li>
			</ol>
		</div>
		<div class="panel-footer fs-step-nav"><?=$nav_back(in_array('key', $skipped, true) ? 'check' : 'key')?>
			<button class="btn btn-primary" type="submit" name="join_start" value="1"><i class="fa-solid fa-right-to-bracket icon-embed-btn" aria-hidden="true"></i><?=gettext('Join')?></button>
			<span class="fs-muted small"><?=gettext('If any step fails, everything is undone.')?></span></div>
	</form>
<?php endif; ?>
</section>
<script src="/js/fleet-join.js?v=<?=filemtime('/usr/local/www/js/fleet-join.js')?>"></script>
<?php
include("foot.inc");
