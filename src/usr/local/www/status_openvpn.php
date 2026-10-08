<?php
/*
 * status_openvpn.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc.
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
##|*IDENT=page-status-openvpn
##|*NAME=Status: OpenVPN
##|*DESCR=Allow access to the 'Status: OpenVPN' page.
##|*MATCH=status_openvpn.php*
##|-PRIV

$pgtitle = array(gettext("Status"), gettext("OpenVPN"));
$shortcut_section = "openvpn";

require_once("guiconfig.inc");
require_once("openvpn.inc");
require_once("shortcuts.inc");
require_once("service-utils.inc");

$servers = openvpn_get_active_servers();
$sk_servers = openvpn_get_active_servers("p2p");
$clients = openvpn_get_active_clients();

/* Handle AJAX */
if ($_POST['action']) {
	if ($_POST['action'] == "kill") {
		$port      = $_POST['port'];
		$remipp    = $_POST['remipp'];
		$client_id = $_POST['client_id'];
		$error     = false;

		/* Validate remote IP address and port. */
		if (!is_ipaddrwithport($remipp)) {
			$error = true;
		}
		/* Validate submitted server ID */
		$found_server = false;
		foreach ($servers as $server) {
			if ($port == $server['mgmt']) {
				$found_server = true;
			} else {
				continue;
			}
		}

		if (!$error && $found_server) {
			$retval = openvpn_kill_client($port, $remipp, $client_id);
			echo htmlentities("|{$port}|{$remipp}|{$retval}|");
		} else {
			echo gettext("invalid input");
		}
		exit;
	}
}
if ($_POST['action']) {
	if (($_POST['action'] == "showrule") && is_numeric($_POST['vpnid']) &&
	    !preg_match("/[^a-zA-Z0-9\.\-_]/", $_POST['username']) && is_port($_POST['port'])) {
		$rulesfile = "{$g['tmp_path']}/ovpn_ovpns{$_POST['vpnid']}_{$_POST['username']}_{$_POST['port']}.rules";
		if (file_exists($rulesfile)) {
			$rule_text = base64_encode(file_get_contents($rulesfile));
			echo $rule_text;
		}
		exit;
	}
}

/* a server whose management socket does not answer reports one "[error]" connection */
foreach ($servers as $idx => $server) {
	$servers[$idx]['error'] = (($server['conns'][0]['common_name'] ?? '') == '[error]');
	if ($servers[$idx]['error']) {
		$servers[$idx]['conns'] = [];
	}
}

/* Running / Stopped / Disabled badge and start/restart/stop row actions for one instance */
$service_info = function ($vpnid) {
	$svc = find_service_by_openvpn_vpnid($vpnid);
	if (empty($svc)) {
		return [fs_badge('unknown'), [], false];
	}
	$running = get_service_status($svc);
	$badge = $running ? fs_badge('up', gettext('Running')) : fs_badge('down', gettext('Stopped'));
	$id = "{$svc['mode']}-{$svc['vpnid']}";
	$actions = [];
	/* ids are picked up by the shared service control handler (FreeSenseHelpers.js) */
	if ($running) {
		$actions[] = ['custom', '#', $svc['description'] ?? '', ['icon' => 'fa-arrow-rotate-right', 'label' => gettext('Restart service'),
		    'attrs' => ['id' => "openvpn-restartservice-{$id}"]]];
		$actions[] = ['custom', '#', $svc['description'] ?? '', ['icon' => 'fa-regular fa-circle-stop', 'label' => gettext('Stop service'),
		    'attrs' => ['id' => "openvpn-stopservice-{$id}"]]];
	} else {
		$actions[] = ['custom', '#', $svc['description'] ?? '', ['icon' => 'fa-circle-play', 'label' => gettext('Start service'),
		    'attrs' => ['id' => "openvpn-startservice-{$id}"]]];
	}
	return [$badge, $actions, $running];
};

/* Connected / connecting / down badge for a peer-to-peer server or a client instance */
$link_badge = function ($status) {
	$s = strtolower((string)$status);
	if (in_array($s, ['up', strtolower(gettext('Connected'))], true)) {
		return fs_badge('up', gettext('Connected'));
	}
	if (($s === '') || in_array($s, ['down', strtolower(gettext('Down'))], true)) {
		return fs_badge('down', ($status !== '') ? ucfirst((string)$status) : gettext('Down'));
	}
	return fs_badge('pending', ucfirst((string)$status));
};

$total_conns = 0;
$total_sent = 0;
$total_recv = 0;
foreach ($servers as $server) {
	$total_conns += count($server['conns']);
	foreach ($server['conns'] as $conn) {
		$total_sent += (float)$conn['bytes_sent'];
		$total_recv += (float)$conn['bytes_recv'];
	}
}
foreach (array_merge($sk_servers, $clients) as $inst) {
	$total_sent += (float)($inst['bytes_sent'] ?? 0);
	$total_recv += (float)($inst['bytes_recv'] ?? 0);
}
$up_instances = 0;
foreach (array_merge($sk_servers, $clients) as $inst) {
	if (in_array(strtolower((string)$inst['status']), ['up', strtolower(gettext('Connected'))], true)) {
		$up_instances++;
	}
}

if (isAllowedPage('vpn_openvpn_server.php')) {
	fs_page_action(gettext('OpenVPN servers'), 'vpn_openvpn_server.php', 'fa-gear', 'secondary');
}

include("head.inc");

if ((empty($clients)) && (empty($servers)) && (empty($sk_servers))) {
	print_info_box(gettext("No OpenVPN instances defined."));
}
?>

<style>
.fs-ovpn-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; padding: .85rem 1rem; border-bottom: 1px solid var(--fs-border); }
.fs-ovpn-title { margin: 0; font-size: var(--fs-fs-md); font-weight: 600; color: var(--fs-text-strong); }
.fs-ovpn-title .fs-mono { color: var(--fs-text-muted); font-weight: 500; margin-right: .35rem; }
.fs-ovpn-facts { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; margin: 0; padding: .75rem 1rem; border-bottom: 1px solid var(--fs-border); font-size: var(--fs-fs-sm); }
.fs-ovpn-facts div { display: flex; gap: .4rem; align-items: baseline; }
.fs-ovpn-facts dt { color: var(--fs-text-muted); font-weight: 500; }
.fs-ovpn-facts dd { margin: 0; color: var(--fs-text-strong); font-variant-numeric: tabular-nums; }
.fs-ovpn-head .fs-actions { margin-left: auto; }
.fs-ovpn-sub { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-ovpn-routes { padding: 0 1rem 1rem; }
.fs-ovpn-routes-toggle { margin: .5rem 1rem; padding-left: 0; color: var(--fs-text); text-decoration: none; }
.fs-ovpn-routes-toggle:hover, .fs-ovpn-routes-toggle:focus-visible { color: var(--fs-text-strong); }
.fs-ovpn-routes-toggle[aria-expanded="true"] .fa-chevron-right { transform: rotate(90deg); }
.fs-ovpn-routes-toggle .fa-chevron-right { transition: transform var(--fs-t-fast) var(--fs-ease); }
.fs-ovpn-error { display: flex; gap: .6rem; margin: 1rem; padding: .6rem .8rem; border-radius: var(--fs-r-sm); background: color-mix(in srgb, var(--fs-block) 10%, transparent); font-size: var(--fs-fs-sm); }
.fs-ovpn-error > i { color: var(--fs-block); margin-top: .2rem; }
.fs-ovpn-num { white-space: nowrap; font-variant-numeric: tabular-nums; }
tr.fs-ovpn-killed { opacity: .45; }
</style>

<?php if (!empty($servers) || !empty($sk_servers) || !empty($clients)): ?>
<div class="fs-tiles">
<?php
fs_tile(gettext('Connected clients'), $total_conns, ($total_conns > 0) ? 'online' : null, sprintf(ngettext('%d server', '%d servers', count($servers)), count($servers)));
if (!empty($sk_servers) || !empty($clients)) {
	fs_tile(gettext('Tunnels up'), sprintf('%d / %d', $up_instances, count($sk_servers) + count($clients)), null, gettext('Peer-to-peer and client instances'));
}
fs_tile(gettext('Sent'), format_bytes($total_sent));
fs_tile(gettext('Received'), format_bytes($total_recv));
?>
</div>
<?php endif; ?>

<?php foreach ($servers as $i => $server):
	list($svc_badge, $svc_actions) = $service_info($server['vpnid']);
	$sent = 0;
	$recv = 0;
	foreach ($server['conns'] as $conn) {
		$sent += (float)$conn['bytes_sent'];
		$recv += (float)$conn['bytes_recv'];
	}
	$has_routes = is_array($server['routes'] ?? null) && count($server['routes']);
?>
<div class="panel panel-default fs-table fs-ovpn-server">
	<div class="fs-ovpn-head">
		<h2 class="fs-ovpn-title"><span class="fs-mono">ovpns<?=htmlspecialchars($server['vpnid'])?></span><?=htmlspecialchars($server['name'])?></h2>
		<?=$svc_badge?>
		<?=fs_row_actions($svc_actions)?>
	</div>
	<dl class="fs-ovpn-facts">
		<div><dt><?=gettext('Clients')?></dt><dd><?=count($server['conns'])?></dd></div>
		<div><dt><?=gettext('Sent')?></dt><dd><?=htmlspecialchars(format_bytes($sent))?></dd></div>
		<div><dt><?=gettext('Received')?></dt><dd><?=htmlspecialchars(format_bytes($recv))?></dd></div>
		<div><dt><?=gettext('Mode')?></dt><dd><?=htmlspecialchars(openvpn_build_mode_list()[$server['mode']] ?? $server['mode'])?></dd></div>
	</dl>
<?php if ($server['error']): ?>
	<div class="fs-ovpn-error"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><?=gettext('Unable to contact the OpenVPN daemon. Is the service running?')?></div>
<?php else: ?>
<?php fs_table_toolbar([
	'search' => gettext('Search clients…'),
	'noun' => gettext('clients'),
	'noun_one' => gettext('client'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Client')?></th>
					<th data-fs-search><?=gettext('Real address')?></th>
					<th data-fs-search><?=gettext('Virtual address')?></th>
					<th data-fs-search><?=gettext('Connected since')?></th>
					<th><?=gettext('Sent')?></th>
					<th><?=gettext('Received')?></th>
					<th data-fs-search><?=gettext('Cipher')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($server['conns'] as $conn):
	$remote_port = substr($conn['remote_host'], strpos($conn['remote_host'], ':') + 1);
	$rulesfile = "{$g['tmp_path']}/ovpn_ovpns{$server['vpnid']}_{$conn['user_name']}_{$remote_port}.rules";
	$has_user = !empty($conn['user_name']) && ($conn['user_name'] != "UNDEF");
	$who = $conn['common_name'] ?: $conn['remote_host'];
	$kill_attrs = ['data-ovpn-kill' => '', 'data-port' => $server['mgmt'], 'data-remipp' => $conn['remote_host']];
	$actions = [];
	if (file_exists($rulesfile)) {
		$actions[] = ['custom', '#', $who, ['icon' => 'fa-list-check', 'label' => sprintf(gettext('Show RADIUS ACL rules for %s'), $who),
		    'attrs' => ['data-ovpn-rules' => '', 'data-vpnid' => $server['vpnid'], 'data-username' => $conn['user_name'], 'data-port' => $remote_port]]];
	}
	$actions[] = ['custom', '#', $who, ['icon' => 'fa-xmark', 'label' => sprintf(gettext('Disconnect %s'), $who),
	    'attrs' => $kill_attrs + ['data-client-id' => '',
	        'data-confirm' => sprintf(gettext('Disconnect “%s”?'), $who),
	        'data-confirm-detail' => gettext('The client is dropped and can reconnect right away.'),
	        'data-confirm-action' => gettext('Disconnect')]]];
	$actions[] = ['custom', '#', $who, ['icon' => 'fa-circle-xmark', 'label' => sprintf(gettext('Halt %s'), $who),
	    'attrs' => $kill_attrs + ['data-client-id' => $conn['client_id'], 'class' => 'fs-action fs-action--delete',
	        'data-confirm' => sprintf(gettext('Halt “%s”?'), $who),
	        'data-confirm-detail' => gettext('The client is told to exit and does not reconnect on its own.'),
	        'data-confirm-action' => gettext('Halt client')]]];
?>
				<tr>
					<td>
						<?=htmlspecialchars($conn['common_name'])?>
<?php if ($has_user): ?>
						<span class="fs-ovpn-sub"><i class="fa-solid fa-user" aria-hidden="true"></i> <?=htmlspecialchars($conn['user_name'])?></span>
<?php endif; ?>
					</td>
					<td class="fs-mono"><?=htmlspecialchars($conn['remote_host'])?></td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($conn['virtual_addr'])?></span>
<?php if (!empty($conn['virtual_addr6'])): ?>
						<span class="fs-ovpn-sub fs-mono"><?=htmlspecialchars($conn['virtual_addr6'])?></span>
<?php endif; ?>
					</td>
					<td class="fs-ovpn-num" data-value="<?=htmlspecialchars($conn['connect_time_unix'] ?? '')?>"><?=htmlspecialchars($conn['connect_time'])?></td>
					<td class="fs-mono fs-ovpn-num" data-value="<?=htmlspecialchars(trim($conn['bytes_sent']))?>"><?=htmlspecialchars(format_bytes($conn['bytes_sent']))?></td>
					<td class="fs-mono fs-ovpn-num" data-value="<?=htmlspecialchars(trim($conn['bytes_recv']))?>"><?=htmlspecialchars(format_bytes($conn['bytes_recv']))?></td>
					<td class="fs-mono"><?=htmlspecialchars($conn['cipher'])?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($server['conns'])) {
	fs_empty_row(8, gettext('No clients are connected.'));
} ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
<?php if ($has_routes): ?>
	<button type="button" class="btn btn-sm btn-link fs-ovpn-routes-toggle" data-bs-toggle="collapse" data-bs-target="#ovpn-routes-<?=$i?>" aria-expanded="false" aria-controls="ovpn-routes-<?=$i?>">
		<i class="fa-solid fa-chevron-right icon-embed-btn" aria-hidden="true"></i><?=sprintf(gettext('Routing table (%d)'), count($server['routes']))?>
	</button>
	<div class="collapse fs-ovpn-routes" id="ovpn-routes-<?=$i?>">
		<div class="table-responsive">
			<table class="table table-sm table-hover" data-sortable>
				<thead>
					<tr>
						<th><?=gettext('Common name')?></th>
						<th><?=gettext('Real address')?></th>
						<th><?=gettext('Target network')?></th>
						<th><?=gettext('Last used')?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($server['routes'] as $conn): ?>
					<tr>
						<td><?=htmlspecialchars($conn['common_name'])?></td>
						<td class="fs-mono"><?=htmlspecialchars($conn['remote_host'])?></td>
						<td class="fs-mono"><?=htmlspecialchars($conn['virtual_addr'])?></td>
						<td class="fs-ovpn-num"><?=htmlspecialchars($conn['last_time'])?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="form-text"><?=gettext('An IP address followed by C indicates a host currently connected through the VPN.')?></p>
	</div>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php
/* peer-to-peer servers and client instances: one row per instance */
$instance_lists = [];
if (!empty($sk_servers)) {
	$instance_lists[] = [gettext('Peer-to-peer servers'), 'ovpns', $sk_servers, false];
}
if (!empty($clients)) {
	$instance_lists[] = [gettext('Client instances'), 'ovpnc', $clients, true];
}
foreach ($instance_lists as list($title, $prefix, $list, $is_client)):
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => $title,
	'search' => (count($list) > 5) ? gettext('Search instances…') : false,
	'noun' => gettext('instances'),
	'noun_one' => gettext('instance'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext('Status')?></th>
					<th data-fs-search><?=gettext('Name')?></th>
					<th><?=gettext('Since')?></th>
<?php if ($is_client): ?>
					<th data-fs-search><?=gettext('Local address')?></th>
<?php endif; ?>
					<th data-fs-search><?=gettext('Virtual address')?></th>
					<th data-fs-search><?=gettext('Remote host')?></th>
					<th><?=gettext('Sent')?></th>
					<th><?=gettext('Received')?></th>
					<th><?=gettext('Service')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($list as $inst):
	list($svc_badge, $svc_actions) = $service_info($inst['vpnid']);
?>
				<tr>
					<td><?=$link_badge($inst['status'])?></td>
					<td>
						<?=htmlspecialchars($inst['name'])?>
						<span class="fs-ovpn-sub fs-mono"><?=$prefix?><?=htmlspecialchars($inst['vpnid'])?></span>
					</td>
					<td class="fs-ovpn-num"><?=htmlspecialchars($inst['connect_time'])?></td>
<?php if ($is_client): ?>
					<td class="fs-mono">
<?php if (empty($inst['local_host']) && empty($inst['local_port'])): ?>
						<span class="fs-muted"><?=gettext('pending')?></span>
<?php else: ?>
						<?=htmlspecialchars($inst['local_host'])?>:<?=htmlspecialchars($inst['local_port'])?>
<?php endif; ?>
					</td>
<?php endif; ?>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($inst['virtual_addr'])?></span>
<?php if (!empty($inst['virtual_addr6'])): ?>
						<span class="fs-ovpn-sub fs-mono"><?=htmlspecialchars($inst['virtual_addr6'])?></span>
<?php endif; ?>
					</td>
					<td class="fs-mono">
<?php if ($is_client && empty($inst['remote_host']) && empty($inst['remote_port'])): ?>
						<span class="fs-muted"><?=gettext('pending')?></span>
<?php elseif ($is_client): ?>
						<?=htmlspecialchars($inst['remote_host'])?>:<?=htmlspecialchars($inst['remote_port'])?>
<?php else: ?>
						<?=htmlspecialchars($inst['remote_host'])?>
<?php endif; ?>
					</td>
					<td class="fs-mono fs-ovpn-num" data-value="<?=htmlspecialchars(trim($inst['bytes_sent']))?>"><?=htmlspecialchars(format_bytes($inst['bytes_sent']))?></td>
					<td class="fs-mono fs-ovpn-num" data-value="<?=htmlspecialchars(trim($inst['bytes_recv']))?>"><?=htmlspecialchars(format_bytes($inst['bytes_recv']))?></td>
					<td><?=$svc_badge?></td>
					<td class="fs-col-actions"><?=fs_row_actions($svc_actions)?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endforeach; ?>

<?php if (!empty($DisplayNote)) {
	print_info_box(gettext("If there are custom options that override the management features of OpenVPN on a client or server, they will cause that OpenVPN instance to not work correctly with this status page."));
} ?>

<div class="modal fade" id="rulesviewer" tabindex="-1" aria-labelledby="rulesviewer-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title" id="rulesviewer-title"><?=gettext('RADIUS ACL generated ruleset')?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?=gettext('Close')?>"></button>
		</div>
		<div class="modal-body">
			<pre class="fs-console" id="rulesviewer_text"></pre>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-outline-secondary" data-fs-copy="#rulesviewer_text"><i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?></button>
			<button type="button" class="btn btn-primary" data-bs-dismiss="modal"><?=gettext('Close')?></button>
		</div>
	</div></div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var loading = <?=json_encode(gettext('Loading…'))?>;
	var failed = <?=json_encode(gettext('The client could not be disconnected.'))?>;

	/* disconnect (client_id empty) or halt (client_id set) one client; the server answers "|port|remipp|retval|" */
	$(document).on('click', '[data-ovpn-kill]', function (e) {
		e.preventDefault();
		var a = this;
		fsConfirm({
			title: a.getAttribute('data-confirm'),
			detail: a.getAttribute('data-confirm-detail'),
			action: a.getAttribute('data-confirm-action'),
			returnFocus: a
		}).then(function (ok) {
			if (!ok) {
				return;
			}
			var icon = a.querySelector('i');
			var iconClass = icon.className;
			icon.className = 'fa-solid fa-spinner fa-spin';
			$.ajax(window.location.pathname, {
				type: 'post',
				data: {action: 'kill', port: a.getAttribute('data-port'), remipp: a.getAttribute('data-remipp'), client_id: a.getAttribute('data-client-id')},
				complete: function (req) {
					var values = (req.responseText || '').split('|');
					icon.className = iconClass;
					if (values[3] !== '0') {
						a.setAttribute('title', failed);
						return;
					}
					var tr = a.closest('tr');
					tr.classList.add('fs-ovpn-killed');
					tr.querySelectorAll('.fs-action').forEach(function (b) {
						b.remove();
					});
				}
			});
		});
	});

	$(document).on('click', '[data-ovpn-rules]', function (e) {
		e.preventDefault();
		var a = this;
		var out = document.getElementById('rulesviewer_text');
		out.textContent = loading;
		bootstrap.Modal.getOrCreateInstance(document.getElementById('rulesviewer')).show();
		$.ajax(window.location.pathname, {
			type: 'post',
			data: {action: 'showrule', vpnid: a.getAttribute('data-vpnid'), username: a.getAttribute('data-username'), port: a.getAttribute('data-port')},
			complete: function (req) {
				try {
					out.textContent = atob(req.responseText || '');
				} catch (err) {
					out.textContent = '';
				}
			}
		});
	});
});
//]]>
</script>

<?php include("foot.inc"); ?>
