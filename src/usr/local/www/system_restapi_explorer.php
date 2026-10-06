<?php
/*
 * system_restapi_explorer.php
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
 * API Explorer: every REST API endpoint of the installed system (from the
 * live route table, including package routes), whether the signed-in user
 * could call it, and "Try it", which sends requests from the browser to
 * /api/v1 with an API key the user pastes. The key stays in the browser:
 * this page never receives, stores or logs it. Users with "REST API access"
 * (page-system-restapi-keys) or the REST API settings page may open it too.
 */

##|+PRIV
##|*IDENT=page-system-restapi-explorer
##|*NAME=System: REST API explorer
##|*DESCR=Allow access to the 'System: REST API explorer' page (the endpoint reference, the OpenAPI document and Try it with the user's own API key).
##|*MATCH=system_restapi_explorer.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("restapi_keys.inc");
require_once("restapi/routes_v1.inc");
require_once("restapi_explorer.inc");

/* The OpenAPI document for the signed-in GUI user, without an API key. */
if (($_GET['download'] ?? '') === 'openapi') {
	/* guiconfig.inc already refused users who may not open this page; check again before serving the document. */
	if (!isAllowedPage('system_restapi_explorer.php')) {
		http_response_code(403);
		exit;
	}
	$doc = json_encode(restapi_openapi(restapi_routes_v1(), trim((string)@file_get_contents('/etc/version')) ?: '1'),
	    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="freesense-openapi.json"');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo $doc;
	exit;
}

$me = (string)($_SESSION['Username'] ?? '');
$viewer = restapi_local_user($me);
global $priv_list;
$model = restapi_explorer_model(restapi_routes_v1(), restapi_areas(),
    restapi_explorer_privnames_for(is_array($priv_list) ? $priv_list : array()), restapi_explorer_access_for($viewer));
$callable_count = 0;
foreach ($model['areas'] as $group) {
	foreach ($group['endpoints'] as $ep) {
		$callable_count += $ep['callable'] ? 1 : 0;
	}
}
$settings = restapi_settings();

$pgtitle = array(gettext('System'), gettext('REST API'), gettext('API Explorer'));
$pglinks = array('', isAllowedPage('system_restapi.php') ? 'system_restapi.php' : '', '@self');
include("head.inc");

restapi_print_tabs('system_restapi_explorer.php');

if (!restapi_enabled()) {
	print_info_box(isAllowedPage('system_restapi.php') ?
	    sprintf(gettext('The REST API is disabled. Enable it in %1$sSettings & All Keys%2$s before trying requests.'),
	    '<a href="system_restapi.php">', '</a>') :
	    gettext('The REST API is disabled by the administrator; requests will fail until it is enabled.'), 'warning', false);
} elseif (empty($settings['allowhttp']) && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
	print_info_box(gettext('This page was loaded over HTTP, but the REST API only accepts HTTPS requests. Open the WebGUI over HTTPS to try requests.'),
	    'warning', false);
}

$method_class = array('GET' => 'text-bg-info', 'POST' => 'text-bg-success', 'PUT' => 'text-bg-warning',
    'PATCH' => 'text-bg-secondary', 'DELETE' => 'text-bg-danger');
$apply_label = array(
	'staged' => array(gettext('staged'), gettext('Staged like the GUI: apply with the area\'s apply endpoint, or add ?apply=true to apply at once.')),
	'applies' => array(gettext('applies'), gettext('Applies the pending (staged) changes of this area.')),
	'direct' => array(gettext('no staging'), gettext('Not staged: takes effect like the GUI page does when saved.')),
);
$i18n = array(
	'noKey' => gettext('Paste an API key first.'),
	'badKey' => gettext('This does not look like an API key (fsk_<id>_<secret>); the API will refuse it.'),
	'missingParam' => gettext('Fill in the path parameter "%s".'),
	'badJson' => gettext('The body is not valid JSON: %s'),
	'notObject' => gettext('The body must be a JSON object.'),
	'sending' => gettext('Sending...'),
	'networkError' => gettext('The request failed in the browser: %s'),
	'confirmWrite' => gettext('This request can change the firewall: %s. Send it?'),
	'confirmDanger' => gettext('Disruptive or destructive operation. Check the target and the body before sending.'),
	'yesSend' => gettext('Yes, send'),
	'cancel' => gettext('Cancel'),
	'send' => gettext('Send'),
	'copy' => gettext('Copy'),
	'copied' => gettext('Copied'),
	'includeKey' => gettext('Include my key (otherwise $FREESENSE_API_KEY)'),
	'useEtag' => gettext('Use last ETag'),
	'status' => gettext('Status'),
	'duration' => gettext('Time'),
	'headers' => gettext('Response headers'),
	'body' => gettext('Response body'),
	'truncated' => gettext('(truncated in this view)'),
	'summary' => gettext('Summary'),
	'scope' => gettext('Scope'),
	'grantedBy' => gettext('Granted by'),
	'guiPage' => gettext('GUI page'),
	'pathParams' => gettext('Path parameters'),
	'queryParams' => gettext('Query parameters'),
	'requestBody' => gettext('Request body'),
	'responseType' => gettext('Response type'),
	'access' => gettext('Your access'),
	'youCan' => gettext('You can call this endpoint with a key of your own (unless the key is read-only or limited to other scopes).'),
	'tryIt' => gettext('Try it'),
	'ifMatch' => gettext('If-Match (optional)'),
	'ifMatchHelp' => gettext('Send the ETag of a previous response to refuse the change (412) when the configuration changed meanwhile.'),
	'none' => gettext('none'),
	'confirmAlways' => gettext('Requires {"confirm": true} in the body.'),
	'confirmConditional' => gettext('Some changes require {"confirm": true} (see the description).'),
	'adminOnly' => gettext('Administrators only (the admin user or "WebCfg - All pages").'),
	'curl' => gettext('curl'),
	'errorTitle' => gettext('Error'),
	'hint401' => gettext('The key is missing, malformed, unknown, expired or revoked. Failed attempts are logged and count for login protection.'),
	'hint403' => gettext('The key\'s user lacks the scope, the key is read-only or limited to other scopes, or the request is not allowed from here.'),
	'hint412' => gettext('The configuration changed since the ETag in If-Match was read. Read it again and retry.'),
	'hint422' => gettext('The GUI validation refused the input:'),
	'showing' => gettext('Showing %1$d of %2$d endpoints'),
	'notCallable' => gettext('Not available to you: %s'),
);
?>
<style>
	.fx-method { display: inline-block; min-width: 4.6em; text-align: center; font-family: var(--bs-font-monospace); }
	.fx-path { font-family: var(--bs-font-monospace); white-space: nowrap; }
	.fx-row { cursor: pointer; }
	.fx-row.fx-denied .fx-path, .fx-row.fx-denied .fx-summary { opacity: .6; }
	.fx-flags .badge { margin: 0 .15em .15em 0; font-weight: 500; }
	.fx-details > td { background-color: var(--bs-tertiary-bg) !important; }
	.fx-details pre, .fx-out pre { max-height: 32em; overflow: auto; background-color: var(--bs-body-bg);
	    color: var(--bs-body-color); border: 1px solid var(--bs-border-color); border-radius: .25rem; padding: .5em; font-size: .85em; }
	.fx-details dl { margin-bottom: .5em; }
	.fx-details dt { font-weight: 600; }
	.fx-try { border-top: 1px solid var(--bs-border-color); margin-top: .75em; padding-top: .75em; }
	.fx-try textarea { font-family: var(--bs-font-monospace); font-size: .85em; }
	.fx-headers td { font-family: var(--bs-font-monospace); font-size: .85em; padding: .1em .5em; }
	.fx-filter .form-control, .fx-filter .form-select { max-width: 100%; }
</style>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Getting started')?></h2></div>
	<div class="panel-body">
		<ol class="mb-2">
			<li><?=isAllowedPage('system_restapi.php') ?
			    sprintf(gettext('Enable the API in %1$sSettings & All Keys%2$s and grant users the "WebCfg - System: REST API access" privilege.'),
			    '<a href="system_restapi.php">', '</a>') :
			    gettext('An administrator enables the API and grants the "WebCfg - System: REST API access" privilege.')?></li>
			<li><?=sprintf(gettext('Create a key in %1$sMy API Keys%2$s. A key acts as your user: it can do what your account can do in the GUI, ' .
			    'and less if it is read-only, expires or is limited to scopes.'),
			    isAllowedPage('system_restapi_keys.php') ? '<a href="system_restapi_keys.php">' : '<span>',
			    isAllowedPage('system_restapi_keys.php') ? '</a>' : '</span>')?></li>
			<li><?=gettext('Send it with every request:')?> <code>Authorization: Bearer fsk_&lt;id&gt;_&lt;secret&gt;</code>.
			    <?=htmlspecialchars(gettext('Each endpoint needs a scope such as firewall.aliases:read or firewall.aliases:write (write includes read). ' .
			    'You hold it with GUI access to the page the endpoint mirrors or with the "REST API - <area>" privilege.'))?></li>
			<li><?=htmlspecialchars(gettext('Most changes are staged like in the GUI: apply them with the area\'s POST .../apply endpoint, or add ?apply=true to the change.'))?></li>
			<li><?=htmlspecialchars(gettext('Responses carry an ETag of the configuration. Send it back as If-Match on a change to have it refused (412) ' .
			    'when the configuration changed meanwhile; list entries are addressed by position, so this matters.'))?></li>
			<li><?=htmlspecialchars(gettext('Disruptive operations (interface assignments, service control, state resets, reboot, halt, packages, system update, ' .
			    'configuration restore) require {"confirm": true}; reboot, halt, packages and the system update also need an administrator.'))?></li>
			<li><?=htmlspecialchars(gettext('Errors are JSON: {"error": {"code", "message", "details"}}. 400 invalid input, 401 missing or invalid key ' .
			    '(failed attempts are logged and count for login protection), 403 missing scope or read-only key, 404 not found, ' .
			    '409 in use, 412 ETag mismatch, 422 validation failed (details.messages lists the GUI\'s messages).'))?></li>
		</ol>
		<a class="btn btn-sm btn-secondary" href="system_restapi_explorer.php?download=openapi">
			<i class="fa-solid fa-download icon-embed-btn"></i><?=gettext('Download openapi.json')?>
		</a>
		<span class="text-muted ms-2"><?=gettext('The same document as GET /api/v1/openapi.json, without needing a key.')?></span>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('API key for "Try it"')?></h2></div>
	<div class="panel-body">
		<div class="input-group mb-2">
			<span class="input-group-text"><i class="fa-solid fa-key"></i></span>
			<input type="password" class="form-control" id="fx-key" autocomplete="off" spellcheck="false"
			    placeholder="fsk_..." aria-label="<?=gettext('API key')?>" data-lpignore="true" />
			<button type="button" class="btn btn-secondary" id="fx-key-show" title="<?=gettext('Show or hide the key')?>"><i class="fa-solid fa-eye"></i></button>
			<button type="button" class="btn btn-secondary" id="fx-key-clear" title="<?=gettext('Forget the key')?>"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="form-check mb-2">
			<input class="form-check-input" type="checkbox" id="fx-key-remember" />
			<label class="form-check-label" for="fx-key-remember"><?=gettext('Remember for this browser tab (sessionStorage; forgotten when the tab closes)')?></label>
		</div>
		<p class="text-muted mb-1"><small><?=gettext('The key stays in this browser tab. It is only sent as the Authorization header of the requests you send ' .
		    'to /api/v1 from here, never to this page, never in a URL, and it is not stored on the firewall.')?></small></p>
		<p class="mb-0"><small id="fx-key-note" class="text-danger"></small></p>
		<p class="mb-0"><?php
if ($viewer === null) {
	echo htmlspecialchars(gettext('API keys are available to local users only, so no endpoint is marked as available to you.'));
} else {
	echo htmlspecialchars(sprintf(gettext('Signed in as %1$s: you could call %2$d of the %3$d endpoints with a key of your own ' .
	    '(a read-only key or one limited to scopes allows fewer).'), $me, $callable_count, $model['count']));
}
?></p>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Endpoints')?></h2></div>
	<div class="panel-body">
		<div class="row g-2 fx-filter mb-2">
			<div class="col-md-5">
				<input type="search" class="form-control" id="fx-search" autocomplete="off"
				    placeholder="<?=gettext('Search path, summary, area or scope')?>" />
			</div>
			<div class="col-md-2">
				<select class="form-select" id="fx-method" aria-label="<?=gettext('Method')?>">
					<option value=""><?=gettext('All methods')?></option>
<?php	foreach (array_keys($method_class) as $m): ?>
					<option value="<?=$m?>"><?=$m?></option>
<?php	endforeach; ?>
				</select>
			</div>
			<div class="col-md-3">
				<select class="form-select" id="fx-area" aria-label="<?=gettext('Area')?>">
					<option value=""><?=gettext('All areas')?></option>
<?php	foreach ($model['areas'] as $group): ?>
					<option value="<?=htmlspecialchars($group['id'])?>"><?=htmlspecialchars($group['label'])?></option>
<?php	endforeach; ?>
				</select>
			</div>
			<div class="col-md-2">
				<select class="form-select" id="fx-access" aria-label="<?=gettext('Access')?>">
					<option value=""><?=gettext('All endpoints')?></option>
					<option value="callable"><?=gettext('Available to me')?></option>
					<option value="read"><?=gettext('Reads')?></option>
					<option value="write"><?=gettext('Writes')?></option>
				</select>
			</div>
		</div>
		<p class="text-muted mb-0" id="fx-count"></p>
	</div>
</div>

<?php foreach ($model['areas'] as $group): ?>
<div class="panel panel-default fx-area" data-area="<?=htmlspecialchars($group['id'])?>">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars($group['label'])?>
		<?php if ($group['id'] !== 'meta'): ?><small class="text-muted ms-1"><code><?=htmlspecialchars($group['id'])?></code></small><?php endif; ?>
		<span class="badge text-bg-secondary ms-1"><?=count($group['endpoints'])?></span></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-sm mb-0">
				<tbody>
<?php	foreach ($group['endpoints'] as $ep):
		$f = $ep['flags'];
		$search = strtolower("{$ep['method']} {$ep['url']} {$ep['summary']} {$group['label']} {$ep['area']} {$ep['scope']} {$ep['page']}");
?>
					<tr class="fx-row<?=$ep['callable'] ? '' : ' fx-denied'?>" id="<?=$ep['id']?>" data-ep="<?=$ep['id']?>"
					    data-method="<?=htmlspecialchars($ep['method'])?>" data-kind="<?=$f['kind']?>" data-callable="<?=$ep['callable'] ? '1' : '0'?>"
					    data-search="<?=htmlspecialchars($search)?>" tabindex="0" aria-expanded="false">
						<td style="width: 6em"><span class="badge fx-method <?=$method_class[$ep['method']] ?? 'text-bg-secondary'?>"><?=htmlspecialchars($ep['method'])?></span></td>
						<td class="fx-path"><?=htmlspecialchars($ep['url'])?></td>
						<td class="fx-summary"><?=htmlspecialchars($ep['summary'])?></td>
						<td class="fx-flags text-nowrap">
<?php		if ($ep['scope'] !== ''): ?>
							<span class="badge text-bg-light border" title="<?=gettext('Required scope')?>"><?=htmlspecialchars($ep['scope'])?></span>
<?php		endif; ?>
							<span class="badge <?=($f['kind'] === 'write') ? 'text-bg-warning' : 'text-bg-secondary'?>"><?=($f['kind'] === 'write') ? gettext('write') : gettext('read')?></span>
<?php		if ($f['apply'] !== ''): ?>
							<span class="badge text-bg-light border" title="<?=htmlspecialchars($apply_label[$f['apply']][1])?>"><?=htmlspecialchars($apply_label[$f['apply']][0])?></span>
<?php		endif;
		if ($f['confirm'] !== ''): ?>
							<span class="badge text-bg-danger" title="<?=htmlspecialchars(($f['confirm'] === 'always') ? $i18n['confirmAlways'] : $i18n['confirmConditional'])?>"><?=($f['confirm'] === 'always') ? gettext('confirm') : gettext('confirm?')?></span>
<?php		endif;
		if ($f['admin']): ?>
							<span class="badge text-bg-danger" title="<?=htmlspecialchars($i18n['adminOnly'])?>"><?=gettext('admin')?></span>
<?php		endif;
		if (!$ep['callable']): ?>
							<span class="badge text-bg-dark" title="<?=htmlspecialchars(sprintf($i18n['notCallable'], $ep['reason']))?>"><i class="fa-solid fa-lock"></i> <?=gettext('not for you')?></span>
<?php		endif; ?>
						</td>
					</tr>
<?php	endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php endforeach; ?>

<script type="application/json" id="fx-model"><?=restapi_explorer_json($model)?></script>
<script type="application/json" id="fx-i18n"><?=restapi_explorer_json($i18n)?></script>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	'use strict';
	var model = JSON.parse(document.getElementById('fx-model').textContent);
	var T = JSON.parse(document.getElementById('fx-i18n').textContent);
	var STORE = <?=json_encode(RESTAPI_EXPLORER_STORAGE_KEY)?>;
	var KEY_RE = /^fsk_[0-9a-f]{12}_[A-Za-z0-9_-]{43}$/;
	var endpoints = {};
	var apiKey = '';
	var lastEtag = '';
	model.areas.forEach(function(g) { g.endpoints.forEach(function(ep) { endpoints[ep.id] = ep; }); });

	function fmt(s) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return s.replace(/%(\d+\$)?[sd]/g, function() { return String(args[i++]); });
	}
	/* Build DOM nodes; text always goes through textContent. */
	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function(k) {
			if (k === 'text') { n.textContent = attrs[k]; }
			else if (k === 'cls') { n.className = attrs[k]; }
			else { n.setAttribute(k, attrs[k]); }
		});
		(kids || []).forEach(function(c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}

	/* ---- the key: memory, optionally sessionStorage ---- */
	var keyField = document.getElementById('fx-key');
	var remember = document.getElementById('fx-key-remember');
	var keyNote = document.getElementById('fx-key-note');
	function storeGet() { try { return window.sessionStorage.getItem(STORE) || ''; } catch (e) { return ''; } }
	function storeSet(v) { try { if (v) { window.sessionStorage.setItem(STORE, v); } else { window.sessionStorage.removeItem(STORE); } } catch (e) {} }
	function setKey(v) {
		apiKey = v.trim();
		keyNote.textContent = (apiKey !== '' && !KEY_RE.test(apiKey)) ? T.badKey : '';
		storeSet(remember.checked ? apiKey : '');
	}
	var saved = storeGet();
	if (saved !== '') {
		remember.checked = true;
		keyField.value = saved;
		setKey(saved);
	}
	keyField.addEventListener('input', function() { setKey(keyField.value); });
	remember.addEventListener('change', function() { setKey(keyField.value); });
	document.getElementById('fx-key-clear').addEventListener('click', function() { keyField.value = ''; setKey(''); });
	document.getElementById('fx-key-show').addEventListener('click', function() {
		keyField.type = (keyField.type === 'password') ? 'text' : 'password';
	});

	/* ---- filtering ---- */
	var rows = Array.prototype.slice.call(document.querySelectorAll('tr.fx-row'));
	var search = document.getElementById('fx-search'), fMethod = document.getElementById('fx-method'),
	    fArea = document.getElementById('fx-area'), fAccess = document.getElementById('fx-access');
	function applyFilter() {
		var words = search.value.toLowerCase().split(/\s+/).filter(function(w) { return w !== ''; });
		var shown = 0;
		rows.forEach(function(r) {
			var ok = words.every(function(w) { return r.dataset.search.indexOf(w) !== -1; }) &&
			    (fMethod.value === '' || r.dataset.method === fMethod.value) &&
			    (fArea.value === '' || r.closest('.fx-area').dataset.area === fArea.value) &&
			    (fAccess.value === '' || (fAccess.value === 'callable' ? r.dataset.callable === '1' : r.dataset.kind === fAccess.value));
			r.classList.toggle('d-none', !ok);
			var d = r.nextElementSibling;
			if (d && d.classList.contains('fx-details')) { d.classList.toggle('d-none', !ok || r.getAttribute('aria-expanded') !== 'true'); }
			shown += ok ? 1 : 0;
		});
		document.querySelectorAll('.fx-area').forEach(function(p) {
			p.classList.toggle('d-none', p.querySelector('tr.fx-row:not(.d-none)') === null);
		});
		document.getElementById('fx-count').textContent = fmt(T.showing, shown, model.count);
	}
	var timer = null;
	search.addEventListener('input', function() { clearTimeout(timer); timer = setTimeout(applyFilter, 120); });
	[fMethod, fArea, fAccess].forEach(function(s) { s.addEventListener('change', applyFilter); });
	applyFilter();

	/* ---- details and "Try it" ---- */
	function shq(s) { return "'" + String(s).replace(/'/g, "'\\''") + "'"; }
	function dl(pairs) {
		var d = el('dl', {cls: 'row'});
		pairs.forEach(function(p) {
			d.appendChild(el('dt', {cls: 'col-sm-2', text: p[0]}));
			var dd = el('dd', {cls: 'col-sm-10'});
			(Array.isArray(p[1]) ? p[1] : [p[1]]).forEach(function(c) { dd.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
			d.appendChild(dd);
		});
		return d;
	}
	function codeList(items) {
		if (items.length === 0) { return T.none; }
		var ul = el('ul', {cls: 'list-unstyled mb-0'});
		items.forEach(function(i) { ul.appendChild(el('li', {}, [el('code', {text: i[0]}), i[1] ? ' - ' + i[1] : ''])); });
		return ul;
	}

	function buildDetails(ep) {
		var f = ep.flags, box = el('div');
		if (f.destructive || f.admin || f.confirm !== '') {
			var warn = el('div', {cls: 'alert alert-danger py-2 mb-2'});
			var parts = [];
			if (f.destructive) { parts.push(T.confirmDanger); }
			if (f.confirm === 'always') { parts.push(T.confirmAlways); }
			if (f.confirm === 'conditional') { parts.push(T.confirmConditional); }
			if (f.admin) { parts.push(T.adminOnly); }
			warn.appendChild(el('i', {cls: 'fa-solid fa-triangle-exclamation me-1'}));
			warn.appendChild(document.createTextNode(parts.join(' ')));
			box.appendChild(warn);
		}
		var access = ep.callable ? el('span', {cls: 'text-success', text: T.youCan}) : el('span', {cls: 'text-danger', text: ep.reason});
		var bodyDoc = null;
		if (ep.body) {
			bodyDoc = ep.body.properties ? el('pre', {cls: 'mb-0', text: JSON.stringify(ep.body, null, 2)}) :
			    (ep.body.description || JSON.stringify(ep.body));
		}
		var pairs = [[T.summary, ep.summary], [T.scope, ep.scope || T.none], [T.guiPage, ep.page]];
		if (ep.privileges.length) { pairs.push([T.grantedBy, ep.privileges.join(' | ')]); }
		pairs.push([T.pathParams, codeList(ep.params.map(function(p) { return [p, '']; }))]);
		pairs.push([T.queryParams, codeList(ep.query.map(function(q) { return [q.name, q.description]; }))]);
		if (bodyDoc) { pairs.push([T.requestBody, bodyDoc]); }
		pairs.push([T.responseType, ep.produces], [T.access, access]);
		box.appendChild(dl(pairs));
		box.appendChild(buildTry(ep));
		return box;
	}

	function buildTry(ep) {
		var f = ep.flags, wrap = el('div', {cls: 'fx-try'});
		wrap.appendChild(el('h3', {cls: 'h6', text: T.tryIt}));
		var inputs = {}, query = {};
		var grid = el('div', {cls: 'row g-2 mb-2'});
		ep.params.forEach(function(p) {
			inputs[p] = el('input', {type: 'text', cls: 'form-control form-control-sm', placeholder: p, 'aria-label': p, autocomplete: 'off'});
			grid.appendChild(el('div', {cls: 'col-md-3'}, [el('label', {cls: 'form-label small mb-0', text: '{' + p + '}'}), inputs[p]]));
		});
		ep.query.forEach(function(q) {
			query[q.name] = el('input', {type: 'text', cls: 'form-control form-control-sm', placeholder: q.description, title: q.description,
			    'aria-label': q.name, autocomplete: 'off'});
			grid.appendChild(el('div', {cls: 'col-md-3'}, [el('label', {cls: 'form-label small mb-0', text: '?' + q.name}), query[q.name]]));
		});
		var ifMatch = null;
		if (f.kind === 'write') {
			ifMatch = el('input', {type: 'text', cls: 'form-control form-control-sm', title: T.ifMatchHelp, autocomplete: 'off'});
			var useEtag = el('button', {type: 'button', cls: 'btn btn-sm btn-outline-secondary', text: T.useEtag});
			useEtag.addEventListener('click', function() { ifMatch.value = lastEtag; update(); });
			grid.appendChild(el('div', {cls: 'col-md-4'}, [el('label', {cls: 'form-label small mb-0', text: T.ifMatch}),
			    el('div', {cls: 'input-group input-group-sm'}, [ifMatch, useEtag])]));
		}
		if (grid.childNodes.length) { wrap.appendChild(grid); }
		var body = null;
		if (ep.method !== 'GET' && (ep.body || ep.method === 'POST' || ep.method === 'PUT' || ep.method === 'PATCH')) {
			body = el('textarea', {cls: 'form-control mb-2', rows: Math.min(14, Math.max(3, (ep.example || '{}').split('\n').length + 1)),
			    spellcheck: 'false', 'aria-label': T.requestBody});
			body.value = ep.example || (ep.body ? '{\n}' : '');
			wrap.appendChild(el('label', {cls: 'form-label small mb-0', text: T.requestBody + ' (JSON)'}));
			wrap.appendChild(body);
		}
		var send = el('button', {type: 'button', cls: 'btn btn-sm fx-send ' + (f.destructive ? 'btn-danger' : 'btn-primary')},
		    [el('i', {cls: 'fa-solid fa-paper-plane icon-embed-btn'}), T.send]);
		var confirmBox = el('div', {cls: 'alert alert-warning py-2 mt-2 d-none'});
		var msg = el('div', {cls: 'text-danger small mt-1'});
		wrap.appendChild(el('div', {}, [send]));
		wrap.appendChild(msg);
		wrap.appendChild(confirmBox);

		var incKey = el('input', {type: 'checkbox', cls: 'form-check-input'});
		var curlPre = el('pre', {cls: 'mb-1'});
		var copy = el('button', {type: 'button', cls: 'btn btn-sm btn-outline-secondary', text: T.copy});
		var incId = ep.id + '-inckey';
		incKey.id = incId;
		wrap.appendChild(el('div', {cls: 'mt-2'}, [el('label', {cls: 'form-label small mb-0', text: T.curl}), curlPre,
		    el('div', {cls: 'd-flex gap-3 align-items-center'}, [copy,
		    el('div', {cls: 'form-check mb-0'}, [incKey, el('label', {cls: 'form-check-label small', 'for': incId, text: T.includeKey})])])]));
		var out = el('div', {cls: 'fx-out mt-2'});
		wrap.appendChild(out);

		/* Returns {url, body} or throws a message. */
		function request() {
			var path = ep.path.replace(/\{([a-z_][a-z0-9_]*)\}/gi, function(m, name) {
				var v = inputs[name].value.trim();
				if (v === '') { throw fmt(T.missingParam, name); }
				return encodeURIComponent(v);
			});
			var qs = new URLSearchParams();
			Object.keys(query).forEach(function(k) { if (query[k].value.trim() !== '') { qs.append(k, query[k].value.trim()); } });
			var text = body ? body.value.trim() : '';
			if (text !== '') {
				var parsed;
				try { parsed = JSON.parse(text); } catch (e) { throw fmt(T.badJson, e.message); }
				if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) { throw T.notObject; }
			}
			return {url: '/api' + path + (qs.toString() ? '?' + qs.toString() : ''), body: text};
		}
		function curl(r) {
			var auth = incKey.checked && apiKey !== '' ? shq('Authorization: Bearer ' + apiKey) : '"Authorization: Bearer $FREESENSE_API_KEY"';
			var lines = ['curl -sS -X ' + ep.method, '-H ' + auth];
			if (ifMatch && ifMatch.value.trim() !== '') { lines.push('-H ' + shq('If-Match: ' + ifMatch.value.trim())); }
			if (r.body !== '') { lines.push("-H 'Content-Type: application/json'", '--data ' + shq(r.body)); }
			lines.push(shq(window.location.origin + r.url));
			return lines.join(' \\\n  ');
		}
		function update() {
			try { curlPre.textContent = curl(request()); msg.textContent = ''; }
			catch (e) { curlPre.textContent = ''; msg.textContent = String(e); }
		}
		wrap.addEventListener('input', update);
		incKey.addEventListener('change', update);
		copy.addEventListener('click', function() {
			var t = curlPre.textContent;
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t); }
			else { var a = el('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); }
			copy.textContent = T.copied;
			setTimeout(function() { copy.textContent = T.copy; }, 1500);
		});
		update();

		function showResponse(res, text, ms) {
			out.textContent = '';
			var cls = res.ok ? 'text-bg-success' : (res.status >= 500 ? 'text-bg-danger' : 'text-bg-warning');
			out.appendChild(el('p', {cls: 'mb-1'}, [T.status + ': ', el('span', {cls: 'badge ' + cls + ' fx-status', text: res.status + ' ' + res.statusText}),
			    '  ' + T.duration + ': ' + ms + ' ms']));
			var type = res.headers.get('Content-Type') || '', json = null;
			if (type.indexOf('json') !== -1) { try { json = JSON.parse(text); } catch (e) {} }
			if (!res.ok && json && json.error) {
				var err = el('div', {cls: 'alert alert-danger py-2 mb-2 fx-error'});
				err.appendChild(el('strong', {text: (json.error.code || T.errorTitle) + ': '}));
				err.appendChild(document.createTextNode(json.error.message || ''));
				var hint = {401: T.hint401, 403: T.hint403, 412: T.hint412, 422: T.hint422}[res.status];
				if (hint) { err.appendChild(el('div', {cls: 'small', text: hint})); }
				var msgs = json.error.details && Array.isArray(json.error.details.messages) ? json.error.details.messages : [];
				if (msgs.length) {
					var ul = el('ul', {cls: 'mb-0'});
					msgs.forEach(function(m) { ul.appendChild(el('li', {text: String(m)})); });
					err.appendChild(ul);
				}
				out.appendChild(err);
			}
			var ht = el('table', {cls: 'fx-headers mb-2'});
			res.headers.forEach(function(v, k) { ht.appendChild(el('tr', {}, [el('td', {text: k}), el('td', {text: v})])); });
			out.appendChild(el('details', {}, [el('summary', {cls: 'small', text: T.headers + (res.headers.get('ETag') ? ' (ETag ' + res.headers.get('ETag') + ')' : '')}), ht]));
			var shown = json !== null ? JSON.stringify(json, null, 2) : text;
			var limit = 500000;
			out.appendChild(el('label', {cls: 'form-label small mb-0', text: T.body + (shown.length > limit ? ' ' + T.truncated : '')}));
			out.appendChild(el('pre', {cls: 'fx-body', text: shown.length > limit ? shown.slice(0, limit) : shown}));
		}

		function run(r) {
			var headers = {'Authorization': 'Bearer ' + apiKey, 'Accept': 'application/json, */*'};
			if (r.body !== '') { headers['Content-Type'] = 'application/json'; }
			if (ifMatch && ifMatch.value.trim() !== '') { headers['If-Match'] = ifMatch.value.trim(); }
			out.textContent = T.sending;
			send.disabled = true;
			var t0 = performance.now();
			fetch(r.url, {method: ep.method, headers: headers, body: (r.body !== '') ? r.body : undefined,
			    credentials: 'omit', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer'})
			.then(function(res) {
				return res.text().then(function(text) {
					var etag = res.headers.get('ETag');
					if (etag) { lastEtag = etag; }
					showResponse(res, text, Math.round(performance.now() - t0));
				});
			})
			.catch(function(e) { out.textContent = fmt(T.networkError, e.message); })
			.then(function() { send.disabled = false; });
		}

		send.addEventListener('click', function() {
			var r;
			confirmBox.classList.add('d-none');
			if (apiKey === '') { msg.textContent = T.noKey; keyField.focus(); return; }
			try { r = request(); } catch (e) { msg.textContent = String(e); return; }
			msg.textContent = '';
			if (f.kind !== 'write') { run(r); return; }
			/* Writes need an explicit second click. */
			confirmBox.textContent = '';
			confirmBox.className = 'alert py-2 mt-2 ' + (f.destructive ? 'alert-danger' : 'alert-warning');
			confirmBox.appendChild(el('p', {cls: 'mb-2', text: fmt(T.confirmWrite, ep.method + ' ' + r.url)}));
			if (f.destructive) { confirmBox.appendChild(el('p', {cls: 'mb-2 fw-bold', text: T.confirmDanger})); }
			var yes = el('button', {type: 'button', cls: 'btn btn-sm btn-danger me-2 fx-confirm', text: T.yesSend});
			var no = el('button', {type: 'button', cls: 'btn btn-sm btn-secondary', text: T.cancel});
			yes.addEventListener('click', function() { confirmBox.classList.add('d-none'); run(r); });
			no.addEventListener('click', function() { confirmBox.classList.add('d-none'); });
			confirmBox.appendChild(yes);
			confirmBox.appendChild(no);
		});
		return wrap;
	}

	function toggle(row) {
		var open = row.getAttribute('aria-expanded') === 'true';
		var next = row.nextElementSibling;
		if (!next || !next.classList.contains('fx-details')) {
			next = el('tr', {cls: 'fx-details d-none'}, [el('td', {colspan: '4'}, [buildDetails(endpoints[row.dataset.ep])])]);
			row.parentNode.insertBefore(next, row.nextSibling);
		}
		row.setAttribute('aria-expanded', open ? 'false' : 'true');
		next.classList.toggle('d-none', open);
	}
	rows.forEach(function(r) {
		r.addEventListener('click', function() { toggle(r); });
		r.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(r); } });
	});
	if (window.location.hash && document.getElementById(window.location.hash.slice(1)) && endpoints[window.location.hash.slice(1)]) {
		toggle(document.getElementById(window.location.hash.slice(1)));
	}
});
//]]>
</script>
<?php
include("foot.inc");
