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

/* Two views: the Guide (how the API works) and the explorer itself (default). */
$view = (($_GET['view'] ?? '') === 'guide') ? 'guide' : '';
$me = (string)($_SESSION['Username'] ?? '');
$viewer = restapi_local_user($me);
$settings = restapi_settings();
$can_settings = isAllowedPage('system_restapi.php');
$can_keys = isAllowedPage('system_restapi_keys.php');

$pgtitle = array(gettext('System'), gettext('REST API'), ($view === 'guide') ? gettext('Guide') : gettext('API Explorer'));
$pglinks = array('', $can_settings ? 'system_restapi.php' : '', '@self');
fs_page_action(gettext('Download openapi.json'), 'system_restapi_explorer.php?download=openapi', 'fa-download', 'secondary',
    array('title' => gettext('The same document as GET /api/v1/openapi.json, without needing a key.')));
include("head.inc");

restapi_print_tabs('system_restapi_explorer.php', false, $view);

if (!restapi_enabled()) {
	print_info_box($can_settings ?
	    sprintf(gettext('The REST API is disabled. Enable it in %1$sSettings%2$s before trying requests.'),
	    '<a href="system_restapi.php">', '</a>') :
	    gettext('The REST API is disabled by the administrator; requests will fail until it is enabled.'), 'warning', false);
} elseif (empty($settings['guiapi'])) {
	print_info_box(sprintf(gettext('The REST API is not served on the WebGUI port, so "Try it" cannot reach it from this page. ' .
	    'Turn on "Serve the API on the WebGUI port" in %1$sSettings%2$s, or call a listener with the copied curl command.'),
	    $can_settings ? '<a href="system_restapi.php">' : '<span>', $can_settings ? '</a>' : '</span>'), 'warning', false);
} elseif (empty($settings['allowhttp']) && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
	print_info_box(gettext('This page was loaded over HTTP, but the REST API only accepts HTTPS requests. Open the WebGUI over HTTPS to try requests.'),
	    'warning', false);
}
?>
<style>
	.fx-pad { padding: var(--fs-sp-4); }
	.panel-body.fx-pad > p, .panel-body.fx-pad > ol, .panel-body.fx-pad > ul { padding-left: 0; padding-right: 0; }
	.fx-guide .panel { height: 100%; margin-bottom: 0; }
	.fx-guide p:last-child, .fx-guide ul:last-child { margin-bottom: 0; }
	.fx-guide .panel-title > i { color: var(--fs-coral-text); margin-right: .4rem; }
	.fx-steps { list-style: none; counter-reset: fx-step; padding: 0; margin: 0 0 1rem; }
	.fx-steps > li { counter-increment: fx-step; position: relative; padding: 0 0 .9rem 2.6rem; }
	.fx-steps > li::before { content: counter(fx-step); position: absolute; left: 0; top: -.1rem; width: 1.8rem; height: 1.8rem;
	    border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: .9rem;
	    background: var(--fs-accent-tint); color: var(--fs-coral-text); }
	.fx-steps > li strong { display: block; color: var(--fs-text-strong); }
	.fx-code { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); }

	/* Method badges: fs-badge colors, fixed width, mono. */
	.fx-method { justify-content: center; min-width: 4.6em; font-family: var(--fs-font-mono); letter-spacing: .02em; }

	/* The key card. */
	.fx-keycard .panel-body { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: var(--fs-sp-4) var(--fs-sp-5); align-items: start; }
	.fx-keycard .input-group { max-width: 40rem; }
	.fx-keystat { text-align: right; min-width: 12rem; }
	.fx-keystat-value { font-size: var(--fs-fs-xl); font-weight: 600; color: var(--fs-text-strong); font-variant-numeric: tabular-nums; line-height: 1.2; }
	@media (max-width: 767.98px) {
		.fx-keycard .panel-body { grid-template-columns: minmax(0, 1fr); }
		.fx-keystat { text-align: left; }
	}

	/* Two panes: the endpoint list and the selected endpoint. */
	.fx-layout { display: grid; grid-template-columns: minmax(20rem, 27rem) minmax(0, 1fr); gap: var(--fs-sp-4); align-items: start; }
	.fx-layout > .panel { margin-bottom: 0; }
	.fx-list-panel { position: sticky; top: calc(var(--fs-navbar-h, 3.5rem) + 1rem); }
	.fx-list-panel .fs-toolbar-default { flex-wrap: wrap; }
	.fx-list-panel .fs-search { flex: 1 1 100%; }
	.fx-list-panel .form-select { flex: 1 1 0; min-width: 0; }
	.fx-list { max-height: calc(100vh - var(--fs-navbar-h, 3.5rem) - 13rem); min-height: 16rem; overflow-y: auto; }
	.fx-group-head { position: sticky; top: 0; z-index: 1; display: flex; align-items: center; gap: .5rem; width: 100%; padding: .45rem var(--fs-sp-4);
	    border: 0; border-bottom: 1px solid var(--fs-border); background: var(--fs-surface-raised); color: var(--fs-text-muted);
	    font-size: var(--fs-fs-xs); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; text-align: left; }
	.fx-group-head .fx-group-id { font-family: var(--fs-font-mono); text-transform: none; letter-spacing: 0; font-weight: 400; }
	.fx-group-head .fx-group-n { margin-left: auto; font-variant-numeric: tabular-nums; }
	.fx-group-head > i { transition: transform var(--fs-t-fast, .15s); }
	.fx-group-head[aria-expanded="false"] > i { transform: rotate(-90deg); }
	.fx-group ul { list-style: none; margin: 0; padding: 0; }
	.fx-group[data-collapsed] ul { display: none; }
	.fx-item { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .15rem .6rem; align-items: center; padding: .45rem var(--fs-sp-4);
	    border-bottom: 1px solid var(--fs-border); color: var(--fs-text); text-decoration: none; }
	.fx-item:hover { background: var(--fs-accent-tint); color: var(--fs-text); }
	.fx-item:focus-visible { outline: 2px solid var(--fs-coral-text); outline-offset: -2px; }
	.fx-item[aria-current="true"] { background: var(--fs-accent-tint); box-shadow: inset 3px 0 0 var(--fs-coral-text); }
	.fx-item .fx-path { font-family: var(--fs-font-mono); font-size: var(--fs-fs-sm); color: var(--fs-text-strong); overflow-wrap: anywhere; }
	.fx-item .fx-sum { grid-column: 2; font-size: var(--fs-fs-xs); color: var(--fs-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	.fx-item.fx-denied .fx-path, .fx-item.fx-denied .fx-sum { opacity: .55; }
	.fx-item .fx-lock { font-size: .7rem; color: var(--fs-text-muted); margin-left: .3rem; }
	.fx-noresults { padding: var(--fs-sp-5) var(--fs-sp-4); text-align: center; color: var(--fs-text-muted); }

	/* The selected endpoint. */
	.fx-detail-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; padding: var(--fs-sp-4); border-bottom: 1px solid var(--fs-border); }
	.fx-detail-head .fx-path { font-family: var(--fs-font-mono); font-size: var(--fs-fs-md, 1rem); color: var(--fs-text-strong); overflow-wrap: anywhere; flex: 1 1 16rem; margin: 0; }
	.fx-detail-sum { padding: var(--fs-sp-3) var(--fs-sp-4) 0; margin: 0; }
	.fx-detail-chips { padding: var(--fs-sp-3) var(--fs-sp-4) 0; }
	.fx-detail-body { padding: var(--fs-sp-4); display: grid; gap: var(--fs-sp-4); }
	.fx-ref dl { display: grid; grid-template-columns: minmax(8rem, 12rem) minmax(0, 1fr); gap: .35rem 1rem; margin: 0; font-size: var(--fs-fs-sm); }
	.fx-ref dt { color: var(--fs-text-muted); font-weight: 600; }
	.fx-ref dd { margin: 0; overflow-wrap: anywhere; }
	.fx-ref ul { list-style: none; margin: 0; padding: 0; }
	@media (max-width: 575.98px) { .fx-ref dl { grid-template-columns: minmax(0, 1fr); } .fx-ref dd { margin-bottom: .4rem; } }
	.fx-card { border: 1px solid var(--fs-border); border-radius: var(--fs-r-md); background: var(--fs-surface); }
	.fx-card-head { display: flex; align-items: center; gap: .5rem; padding: .6rem var(--fs-sp-4); border-bottom: 1px solid var(--fs-border);
	    font-weight: 600; color: var(--fs-text-strong); }
	.fx-card-head > i { color: var(--fs-text-muted); }
	.fx-card-head .fx-card-aside { margin-left: auto; font-weight: 400; font-size: var(--fs-fs-sm); color: var(--fs-text-muted); }
	.fx-card-body { padding: var(--fs-sp-4); }
	.fx-card .fs-tool-empty { min-height: 8rem; }
	.fx-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: .75rem; margin-bottom: .75rem; }
	.fx-fields .form-label, .fx-card .form-label { font-size: var(--fs-fs-xs); color: var(--fs-text-muted); font-family: var(--fs-font-mono); margin-bottom: .2rem; }
	.fx-card textarea { font-family: var(--fs-font-mono); font-size: var(--fs-fs-sm); }
	.fx-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
	.fx-curl { margin-top: var(--fs-sp-4); }
	.fx-curl pre, .fx-out pre { max-height: 28rem; border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); font-size: var(--fs-fs-xs); white-space: pre-wrap; overflow-wrap: anywhere; }
	.fx-statusline { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; margin-bottom: .75rem; }
	.fx-headers { font-family: var(--fs-font-mono); font-size: var(--fs-fs-xs); margin: .5rem 0 .75rem; }
	.fx-headers td { padding: .1rem .75rem .1rem 0; vertical-align: top; overflow-wrap: anywhere; }
	.fx-headers td:first-child { color: var(--fs-text-muted); white-space: nowrap; }
	.fx-out details > summary { cursor: pointer; font-size: var(--fs-fs-sm); color: var(--fs-text-muted); }
	.fx-detail-empty { min-height: 22rem; }
	@media (max-width: 991.98px) {
		.fx-layout { grid-template-columns: minmax(0, 1fr); }
		.fx-list-panel { position: static; }
		.fx-list { max-height: 60vh; }
	}
	@media (prefers-reduced-motion: reduce) { .fx-group-head > i { transition: none; } }
</style>
<?php
if ($view === 'guide'):
	$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'firewall');
	$errors = array(
		'400' => gettext('Invalid input (bad JSON, unknown fields, wrong types).'),
		'401' => gettext('Missing, malformed, unknown, expired or revoked key. Failed attempts are logged and count for login protection.'),
		'403' => gettext('The key\'s user lacks the scope, the key is read-only or limited to other scopes, or the client address is not allowed.'),
		'404' => gettext('The object does not exist.'),
		'409' => gettext('The object is in use, or the change conflicts with the current state.'),
		'412' => gettext('ETag mismatch: the configuration changed since the ETag in If-Match was read.'),
		'422' => gettext('The GUI validation refused the input; details.messages lists the GUI\'s messages.'),
	);
?>
<div class="row g-3 mb-3 fx-guide">
	<div class="col-lg-7">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-rocket" aria-hidden="true"></i><?=gettext('Quick start')?></h2></div>
			<div class="panel-body fx-pad">
				<ol class="fx-steps">
					<li><strong><?=gettext('Enable the API and grant access')?></strong>
						<?=$can_settings ?
						    sprintf(gettext('Turn it on in %1$sSettings%2$s and give users the "WebCfg - System: REST API access" privilege in %3$sUser Manager%4$s.'),
						    '<a href="system_restapi.php">', '</a>', '<a href="system_usermanager.php">', '</a>') :
						    gettext('An administrator enables the API and grants the "WebCfg - System: REST API access" privilege.')?></li>
					<li><strong><?=gettext('Create a key')?></strong>
						<?=sprintf(gettext('In %1$sMy API Keys%2$s. A key acts as your user: it can do what your account can do in the GUI, ' .
						    'and less if it is read-only, expires or is limited to scopes. It is shown only once.'),
						    $can_keys ? '<a href="system_restapi_keys.php">' : '<span>', $can_keys ? '</a>' : '</span>')?></li>
					<li><strong><?=gettext('Send it with every request')?></strong>
						<?=gettext('As a bearer token in the Authorization header:')?></li>
				</ol>
				<pre class="fs-console fx-code">curl -H "Authorization: Bearer $FREESENSE_API_KEY" \
  https://<?=$host?>/api/v1/me</pre>
			</div>
		</div>
	</div>
	<div class="col-lg-5">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-file-code" aria-hidden="true"></i><?=gettext('OpenAPI document')?></h2></div>
			<div class="panel-body fx-pad">
				<p><?=sprintf(gettext('Every endpoint is described in an OpenAPI 3 document at %1$s (it needs an API key). Import it into an API client ' .
				    'or a code generator. The download below is the same document, without needing a key.'), '<code>/api/v1/openapi.json</code>')?></p>
				<div class="d-flex flex-wrap gap-2">
					<a class="btn btn-sm btn-primary" href="system_restapi_explorer.php">
						<i class="fa-solid fa-compass icon-embed-btn" aria-hidden="true"></i><?=gettext('Open the API Explorer')?></a>
					<a class="btn btn-sm btn-outline-secondary" href="system_restapi_explorer.php?download=openapi">
						<i class="fa-solid fa-download icon-embed-btn" aria-hidden="true"></i><?=gettext('Download openapi.json')?></a>
				</div>
			</div>
		</div>
	</div>
	<div class="col-md-6">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-key" aria-hidden="true"></i><?=gettext('Scopes')?></h2></div>
			<div class="panel-body fx-pad">
				<p><?=htmlspecialchars(gettext('Each endpoint needs a scope such as firewall.aliases:read or firewall.aliases:write; a write scope includes reading.'))?></p>
				<p><?=htmlspecialchars(gettext('The key\'s user holds a scope with GUI access to the page the endpoint mirrors, or with the "REST API - <area>" privilege. ' .
				    'Limiting a key to scopes only narrows it: it never gets more than its user holds.'))?></p>
			</div>
		</div>
	</div>
	<div class="col-md-6">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><?=gettext('Staged changes')?></h2></div>
			<div class="panel-body fx-pad">
				<p><?=htmlspecialchars(gettext('Most changes are staged like in the GUI. Apply them with the area\'s POST .../apply endpoint, ' .
				    'or add ?apply=true to the change to apply it at once.'))?></p>
				<p><?=gettext('The API Explorer marks each change as <em>staged</em>, <em>applies</em> or <em>no staging</em>.')?></p>
			</div>
		</div>
	</div>
	<div class="col-md-6">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-code-compare" aria-hidden="true"></i><?=gettext('Concurrent changes (ETag)')?></h2></div>
			<div class="panel-body fx-pad">
				<p><?=htmlspecialchars(gettext('Responses carry an ETag of the configuration. Send it back as If-Match on a change to have it refused (412) ' .
				    'when the configuration changed meanwhile.'))?></p>
				<p><?=htmlspecialchars(gettext('List entries are addressed by position, so this matters when several clients or administrators make changes.'))?></p>
			</div>
		</div>
	</div>
	<div class="col-md-6">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=gettext('Disruptive operations')?></h2></div>
			<div class="panel-body fx-pad">
				<p><?=htmlspecialchars(gettext('Interface assignments, service control, state resets, reboot, halt, packages, the system update and ' .
				    'configuration restore require {"confirm": true} in the body.'))?></p>
				<p><?=htmlspecialchars(gettext('Reboot, halt, packages and the system update also need an administrator (the admin user or "WebCfg - All pages").'))?></p>
			</div>
		</div>
	</div>
	<div class="col-12">
		<div class="panel panel-default fs-table">
			<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><?=gettext('Errors')?></h2></div>
			<p class="fx-pad mb-0"><?=gettext('Errors are JSON:')?> <code>{"error": {"code": "...", "message": "...", "details": {...}}}</code></p>
			<div class="panel-body table-responsive">
				<table class="table">
					<thead><tr><th style="width: 7em"><?=gettext('Status')?></th><th><?=gettext('Meaning')?></th></tr></thead>
					<tbody>
<?php	foreach ($errors as $code => $meaning): ?>
						<tr><td><?=fs_badge(($code === '401' || $code === '403') ? 'block' : 'warn', (string)$code)?></td>
							<td><?=htmlspecialchars($meaning)?></td></tr>
<?php	endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<?php
	include("foot.inc");
	exit;
endif;

global $priv_list;
$model = restapi_explorer_model(restapi_routes_v1(), restapi_areas(),
    restapi_explorer_privnames_for(is_array($priv_list) ? $priv_list : array()), restapi_explorer_access_for($viewer));
$callable_count = 0;
foreach ($model['areas'] as $group) {
	foreach ($group['endpoints'] as $ep) {
		$callable_count += $ep['callable'] ? 1 : 0;
	}
}

/* fs-badge variant per method. */
$method_class = array('GET' => 'info', 'POST' => 'pass', 'PUT' => 'warn', 'PATCH' => 'warn', 'DELETE' => 'block');
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
	'copyLink' => gettext('Copy a link to this endpoint'),
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
	'showSchema' => gettext('Show the schema'),
	'responseType' => gettext('Response type'),
	'access' => gettext('Your access'),
	'youCan' => gettext('You can call this endpoint with a key of your own (unless the key is read-only or limited to other scopes).'),
	'tryIt' => gettext('Try it'),
	'request' => gettext('Request'),
	'response' => gettext('Response'),
	'reference' => gettext('Reference'),
	'noResponse' => gettext('Send the request to see the response here.'),
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
	'showing' => gettext('%1$d of %2$d endpoints'),
	'notCallable' => gettext('Not available to you: %s'),
	'notForYou' => gettext('Not for you'),
	'read' => gettext('read'),
	'write' => gettext('write'),
	'confirm' => gettext('confirm'),
	'confirmMaybe' => gettext('confirm?'),
	'admin' => gettext('admin'),
	'applyLabels' => array_map(function ($a) { return $a[0]; }, $apply_label),
	'applyTitles' => array_map(function ($a) { return $a[1]; }, $apply_label),
	'methodClass' => $method_class,
);
?>

<div class="panel panel-default fx-keycard">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('API key for "Try it"')?></h2></div>
	<div class="panel-body">
		<div>
			<label class="form-label visually-hidden" for="fx-key"><?=gettext('API key')?></label>
			<div class="input-group">
				<span class="input-group-text"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
				<input type="password" class="form-control fs-mono" id="fx-key" autocomplete="off" spellcheck="false"
				    placeholder="fsk_..." data-lpignore="true" aria-describedby="fx-key-help" aria-label="<?=gettext('API key')?>" />
				<button type="button" class="btn btn-outline-secondary" id="fx-key-show" title="<?=gettext('Show or hide the key')?>"
				    aria-label="<?=gettext('Show or hide the key')?>"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
				<button type="button" class="btn btn-outline-secondary" id="fx-key-clear" title="<?=gettext('Forget the key')?>"
				    aria-label="<?=gettext('Forget the key')?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
			</div>
			<small id="fx-key-note" class="text-danger d-block mt-1" role="status"></small>
			<div class="form-check form-switch mt-1">
				<input class="form-check-input" type="checkbox" role="switch" id="fx-key-remember" />
				<label class="form-check-label" for="fx-key-remember"><?=gettext('Remember for this browser tab')?>
					<span class="fs-muted small"><?=gettext('(sessionStorage; forgotten when the tab closes)')?></span></label>
			</div>
			<p class="fs-muted small mt-2 mb-0" id="fx-key-help"><i class="fa-solid fa-shield-halved me-1" aria-hidden="true"></i><?=gettext('The key stays in this browser tab. ' .
			    'It is only sent as the Authorization header of the requests you send to /api/v1 from here, never to this page, never in a URL, ' .
			    'and it is not stored on the firewall.')?></p>
		</div>
		<div class="fx-keystat">
<?php	if ($viewer === null): ?>
			<p class="mb-0 fs-muted"><?=htmlspecialchars(gettext('API keys are available to local users only, so no endpoint is marked as available to you.'))?></p>
<?php	else: ?>
			<div class="fs-muted small"><?=htmlspecialchars(sprintf(gettext('Signed in as %s'), $me))?></div>
			<div class="fx-keystat-value"><?=(int)$callable_count?> <span class="fs-muted small fw-normal">/ <?=(int)$model['count']?></span></div>
			<div class="small"><?=gettext('endpoints you could call')?></div>
<?php		if ($can_keys): ?>
			<a class="btn btn-sm btn-outline-secondary mt-2" href="system_restapi_keys.php"><i class="fa-solid fa-key icon-embed-btn" aria-hidden="true"></i><?=gettext('My API keys')?></a>
<?php		endif; ?>
<?php	endif; ?>
		</div>
	</div>
</div>

<div class="fx-layout">
	<nav class="panel panel-default fx-list-panel" aria-label="<?=gettext('Endpoints')?>">
		<div class="fs-toolbar">
			<div class="fs-toolbar-default">
				<div class="fs-search" role="search">
					<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
					<input type="search" class="form-control" id="fx-search" autocomplete="off"
					    placeholder="<?=gettext('Search path, summary, area or scope…')?>" aria-label="<?=gettext('Search endpoints')?>" />
				</div>
				<select class="form-select form-select-sm" id="fx-method" aria-label="<?=gettext('Method')?>">
					<option value=""><?=gettext('All methods')?></option>
<?php	foreach (array_keys($method_class) as $m): ?>
					<option value="<?=$m?>"><?=$m?></option>
<?php	endforeach; ?>
				</select>
				<select class="form-select form-select-sm" id="fx-access" aria-label="<?=gettext('Access')?>">
					<option value=""><?=gettext('All endpoints')?></option>
					<option value="callable"><?=gettext('Available to me')?></option>
					<option value="read"><?=gettext('Reads')?></option>
					<option value="write"><?=gettext('Writes')?></option>
				</select>
				<select class="form-select form-select-sm" id="fx-area" aria-label="<?=gettext('Area')?>">
					<option value=""><?=gettext('All areas')?></option>
<?php	foreach ($model['areas'] as $group): ?>
					<option value="<?=htmlspecialchars($group['id'])?>"><?=htmlspecialchars($group['label'])?></option>
<?php	endforeach; ?>
				</select>
				<span class="fs-toolbar-spacer"></span>
				<span class="fs-toolbar-count" id="fx-count" aria-live="polite"></span>
			</div>
		</div>
		<div class="fx-list" id="fx-list">
<?php foreach ($model['areas'] as $group): ?>
			<section class="fx-group" data-area="<?=htmlspecialchars($group['id'])?>">
				<button type="button" class="fx-group-head" aria-expanded="true">
					<i class="fa-solid fa-chevron-down" aria-hidden="true"></i><span><?=htmlspecialchars($group['label'])?></span>
<?php	if ($group['id'] !== 'meta'): ?>
					<span class="fx-group-id"><?=htmlspecialchars($group['id'])?></span>
<?php	endif; ?>
					<span class="fx-group-n" data-count><?=count($group['endpoints'])?></span>
				</button>
				<ul>
<?php	foreach ($group['endpoints'] as $ep):
		$search = strtolower("{$ep['method']} {$ep['url']} {$ep['summary']} {$group['label']} {$ep['area']} {$ep['scope']} {$ep['page']}");
?>
					<li><a class="fx-item<?=$ep['callable'] ? '' : ' fx-denied'?>" href="#<?=htmlspecialchars($ep['id'])?>" id="<?=htmlspecialchars($ep['id'])?>"
					    data-ep="<?=htmlspecialchars($ep['id'])?>" data-method="<?=htmlspecialchars($ep['method'])?>" data-kind="<?=htmlspecialchars($ep['flags']['kind'])?>"
					    data-callable="<?=$ep['callable'] ? '1' : '0'?>" data-search="<?=htmlspecialchars($search)?>">
						<span class="fs-badge fs-badge--<?=$method_class[$ep['method']] ?? 'neutral'?> fx-method"><?=htmlspecialchars($ep['method'])?></span>
						<span class="fx-path"><?=htmlspecialchars($ep['url'])?><?php if (!$ep['callable']): ?><i class="fa-solid fa-lock fx-lock"
						    title="<?=htmlspecialchars(sprintf($i18n['notCallable'], $ep['reason']))?>" aria-label="<?=htmlspecialchars($i18n['notForYou'])?>"></i><?php endif; ?></span>
						<span class="fx-sum"><?=htmlspecialchars($ep['summary'])?></span>
					</a></li>
<?php	endforeach; ?>
				</ul>
			</section>
<?php endforeach; ?>
			<div class="fx-noresults d-none" id="fx-none"><?=gettext('No endpoint matches the filters.')?></div>
		</div>
	</nav>

	<section class="panel panel-default fx-detail" id="fx-detail" aria-live="polite" aria-label="<?=gettext('Selected endpoint')?>">
		<div class="fs-tool-empty fx-detail-empty">
			<i class="fa-solid fa-compass" aria-hidden="true"></i>
			<span><?=gettext('Select an endpoint to see its reference and try it.')?></span>
		</div>
	</section>
</div>

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
	function icon(cls) { return el('i', {cls: 'fa-solid ' + cls, 'aria-hidden': 'true'}); }
	function methodBadge(m) { return el('span', {cls: 'fs-badge fs-badge--' + (T.methodClass[m] || 'neutral') + ' fx-method', text: m}); }
	function chip(text, cls, title) { var c = el('span', {cls: 'fs-chip ' + (cls || ''), text: text}); if (title) { c.title = title; } return c; }

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

	/* ---- the endpoint list: search, method, access and area; collapsible groups ---- */
	var items = Array.prototype.slice.call(document.querySelectorAll('a.fx-item'));
	var groups = Array.prototype.slice.call(document.querySelectorAll('.fx-group'));
	var search = document.getElementById('fx-search'), fMethod = document.getElementById('fx-method'),
	    fArea = document.getElementById('fx-area'), fAccess = document.getElementById('fx-access');
	var list = document.getElementById('fx-list');
	function applyFilter() {
		var words = search.value.toLowerCase().split(/\s+/).filter(function(w) { return w !== ''; });
		var filtering = words.length > 0 || fMethod.value !== '' || fAccess.value !== '';
		var shown = 0;
		groups.forEach(function(g) {
			var inArea = (fArea.value === '' || g.dataset.area === fArea.value), n = 0;
			g.querySelectorAll('a.fx-item').forEach(function(a) {
				var ok = inArea && words.every(function(w) { return a.dataset.search.indexOf(w) !== -1; }) &&
				    (fMethod.value === '' || a.dataset.method === fMethod.value) &&
				    (fAccess.value === '' || (fAccess.value === 'callable' ? a.dataset.callable === '1' : a.dataset.kind === fAccess.value));
				a.parentNode.hidden = !ok;
				n += ok ? 1 : 0;
			});
			g.hidden = (n === 0);
			g.querySelector('[data-count]').textContent = n;
			/* While filtering, every group with a match is open. */
			if (filtering && n > 0) { setGroup(g, true); }
			shown += n;
		});
		document.getElementById('fx-none').classList.toggle('d-none', shown !== 0);
		document.getElementById('fx-count').textContent = fmt(T.showing, shown, model.count);
	}
	function setGroup(g, open) {
		g.querySelector('.fx-group-head').setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) { g.removeAttribute('data-collapsed'); } else { g.setAttribute('data-collapsed', ''); }
	}
	groups.forEach(function(g) {
		var head = g.querySelector('.fx-group-head');
		head.addEventListener('click', function() { setGroup(g, head.getAttribute('aria-expanded') !== 'true'); });
	});
	var timer = null;
	search.addEventListener('input', function() { clearTimeout(timer); timer = setTimeout(applyFilter, 120); });
	search.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && search.value !== '') { e.preventDefault(); search.value = ''; applyFilter(); }
		if (e.key === 'ArrowDown') { e.preventDefault(); var first = visibleItems()[0]; if (first) { first.focus(); } }
	});
	[fMethod, fAccess, fArea].forEach(function(s) { s.addEventListener('change', applyFilter); });
	function visibleItems() { return items.filter(function(a) { return !a.parentNode.hidden && !a.closest('.fx-group').hidden && !a.closest('[data-collapsed]'); }); }
	/* Up and down move through the shown endpoints. */
	list.addEventListener('keydown', function(e) {
		if ((e.key !== 'ArrowDown' && e.key !== 'ArrowUp') || !e.target.classList.contains('fx-item')) { return; }
		e.preventDefault();
		var vis = visibleItems(), at = vis.indexOf(e.target);
		var next = vis[at + ((e.key === 'ArrowDown') ? 1 : -1)];
		if (next) { next.focus(); } else if (e.key === 'ArrowUp') { search.focus(); }
	});
	applyFilter();

	/* ---- the selected endpoint: reference, request ("Try it") and response ---- */
	function shq(s) { return "'" + String(s).replace(/'/g, "'\''") + "'"; }
	function codeList(entries) {
		if (entries.length === 0) { return T.none; }
		var ul = el('ul');
		entries.forEach(function(i) { ul.appendChild(el('li', {}, [el('code', {text: i[0]}), i[1] ? ' - ' + i[1] : ''])); });
		return ul;
	}
	function card(iconCls, title, aside) {
		var head = el('div', {cls: 'fx-card-head'}, [icon(iconCls), el('span', {text: title})]);
		if (aside) { head.appendChild(aside); }
		var body = el('div', {cls: 'fx-card-body'});
		return {node: el('div', {cls: 'fx-card'}, [head, body]), head: head, body: body};
	}

	function buildDetails(ep) {
		var f = ep.flags, wrap = el('div');
		var head = el('div', {cls: 'fx-detail-head'}, [methodBadge(ep.method), el('h2', {cls: 'fx-path', text: ep.url})]);
		var link = el('button', {type: 'button', cls: 'btn btn-sm btn-outline-secondary', title: T.copyLink, 'aria-label': T.copyLink}, [icon('fa-link')]);
		link.addEventListener('click', function() {
			var url = window.location.href.split('#')[0] + '#' + ep.id;
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(url); }
			link.firstChild.className = 'fa-solid fa-check';
			setTimeout(function() { link.firstChild.className = 'fa-solid fa-link'; }, 1500);
		});
		head.appendChild(link);
		wrap.appendChild(head);
		if (ep.summary) { wrap.appendChild(el('p', {cls: 'fx-detail-sum', text: ep.summary})); }

		var chips = el('div', {cls: 'fs-chips fx-detail-chips'});
		if (ep.scope) { chips.appendChild(chip(ep.scope, 'fs-chip--mono', T.scope)); }
		chips.appendChild(chip(f.kind === 'write' ? T.write : T.read, f.kind === 'write' ? 'is-warn' : ''));
		if (f.apply) { chips.appendChild(chip(T.applyLabels[f.apply], '', T.applyTitles[f.apply])); }
		if (f.confirm) { chips.appendChild(chip(f.confirm === 'always' ? T.confirm : T.confirmMaybe, 'is-warn',
		    f.confirm === 'always' ? T.confirmAlways : T.confirmConditional)); }
		if (f.admin) { chips.appendChild(chip(T.admin, 'is-warn', T.adminOnly)); }
		if (!ep.callable) { chips.appendChild(chip(T.notForYou, 'is-na', fmt(T.notCallable, ep.reason))); }
		wrap.appendChild(chips);

		var body = el('div', {cls: 'fx-detail-body'});
		if (f.destructive || f.admin || f.confirm !== '') {
			var parts = [];
			if (f.destructive) { parts.push(T.confirmDanger); }
			if (f.confirm === 'always') { parts.push(T.confirmAlways); }
			if (f.confirm === 'conditional') { parts.push(T.confirmConditional); }
			if (f.admin) { parts.push(T.adminOnly); }
			body.appendChild(el('div', {cls: 'alert alert-warning py-2 mb-0 small', role: 'note'},
			    [icon('fa-triangle-exclamation me-1'), parts.join(' ')]));
		}
		var access = ep.callable ? el('span', {text: T.youCan}) : el('span', {cls: 'text-danger', text: ep.reason});
		var bodyDoc = null;
		if (ep.body) {
			bodyDoc = ep.body.properties ? el('details', {}, [el('summary', {cls: 'small', text: T.showSchema}),
			    el('pre', {cls: 'fs-console mt-1', text: JSON.stringify(ep.body, null, 2)})]) :
			    (ep.body.description || JSON.stringify(ep.body));
		}
		var pairs = [[T.scope, ep.scope ? el('code', {text: ep.scope}) : T.none], [T.guiPage, ep.page]];
		if (ep.privileges.length) { pairs.push([T.grantedBy, ep.privileges.join(' | ')]); }
		pairs.push([T.pathParams, codeList(ep.params.map(function(p) { return [p, '']; }))]);
		pairs.push([T.queryParams, codeList(ep.query.map(function(q) { return [q.name, q.description]; }))]);
		if (bodyDoc) { pairs.push([T.requestBody, bodyDoc]); }
		pairs.push([T.responseType, ep.produces], [T.access, access]);
		var ref = card('fa-book', T.reference);
		ref.node.classList.add('fx-ref');
		var dlist = el('dl');
		pairs.forEach(function(p) {
			dlist.appendChild(el('dt', {text: p[0]}));
			var dd = el('dd');
			(Array.isArray(p[1]) ? p[1] : [p[1]]).forEach(function(c) { dd.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
			dlist.appendChild(dd);
		});
		ref.body.appendChild(dlist);
		body.appendChild(ref.node);
		buildTry(ep).forEach(function(n) { body.appendChild(n); });
		wrap.appendChild(body);
		return wrap;
	}

	/* The request card (fields, Send, curl) and the response card. */
	function buildTry(ep) {
		var f = ep.flags, req = card('fa-paper-plane', T.request + ' · ' + T.tryIt);
		var wrap = req.body;
		var inputs = {}, query = {};
		var grid = el('div', {cls: 'fx-fields'});
		ep.params.forEach(function(p) {
			inputs[p] = el('input', {type: 'text', cls: 'form-control form-control-sm', placeholder: p, 'aria-label': p, autocomplete: 'off'});
			grid.appendChild(el('div', {}, [el('label', {cls: 'form-label', text: '{' + p + '}'}), inputs[p]]));
		});
		ep.query.forEach(function(q) {
			query[q.name] = el('input', {type: 'text', cls: 'form-control form-control-sm', placeholder: q.description, title: q.description,
			    'aria-label': q.name, autocomplete: 'off'});
			grid.appendChild(el('div', {}, [el('label', {cls: 'form-label', text: '?' + q.name}), query[q.name]]));
		});
		var ifMatch = null;
		if (f.kind === 'write') {
			ifMatch = el('input', {type: 'text', cls: 'form-control form-control-sm', title: T.ifMatchHelp, 'aria-label': T.ifMatch, autocomplete: 'off'});
			var useEtag = el('button', {type: 'button', cls: 'btn btn-sm btn-outline-secondary', text: T.useEtag});
			useEtag.addEventListener('click', function() { ifMatch.value = lastEtag; update(); });
			grid.appendChild(el('div', {}, [el('label', {cls: 'form-label', text: T.ifMatch}),
			    el('div', {cls: 'input-group input-group-sm'}, [ifMatch, useEtag])]));
		}
		if (grid.childNodes.length) { wrap.appendChild(grid); }
		var body = null;
		if (ep.method !== 'GET' && (ep.body || ep.method === 'POST' || ep.method === 'PUT' || ep.method === 'PATCH')) {
			var bodyId = ep.id + '-body';
			body = el('textarea', {id: bodyId, cls: 'form-control mb-3', rows: Math.min(14, Math.max(3, (ep.example || '{}').split('\n').length + 1)),
			    spellcheck: 'false'});
			body.value = ep.example || (ep.body ? '{\n}' : '');
			wrap.appendChild(el('label', {cls: 'form-label', 'for': bodyId, text: T.requestBody + ' (JSON)'}));
			wrap.appendChild(body);
		}
		var send = el('button', {type: 'button', cls: 'btn btn-sm fx-send ' + (f.destructive ? 'btn-danger' : 'btn-primary')},
		    [icon('fa-paper-plane icon-embed-btn'), T.send]);
		var confirmBox = el('div', {cls: 'alert alert-warning py-2 mt-2 d-none'});
		var msg = el('div', {cls: 'text-danger small mt-1', role: 'status'});
		wrap.appendChild(el('div', {cls: 'fx-actions'}, [send]));
		wrap.appendChild(msg);
		wrap.appendChild(confirmBox);

		var incKey = el('input', {type: 'checkbox', cls: 'form-check-input'});
		var curlPre = el('pre', {cls: 'fs-console mb-2'});
		var copy = el('button', {type: 'button', cls: 'btn btn-sm btn-outline-secondary'}, [icon('fa-copy icon-embed-btn'), el('span', {text: T.copy})]);
		var incId = ep.id + '-inckey';
		incKey.id = incId;
		wrap.appendChild(el('div', {cls: 'fx-curl'}, [el('div', {cls: 'form-label', text: T.curl}), curlPre,
		    el('div', {cls: 'fx-actions'}, [copy,
		    el('div', {cls: 'form-check mb-0'}, [incKey, el('label', {cls: 'form-check-label small', 'for': incId, text: T.includeKey})])])]));

		var res = card('fa-reply', T.response);
		var aside = el('span', {cls: 'fx-card-aside'});
		res.head.appendChild(aside);
		var out = el('div', {cls: 'fx-out'});
		res.body.appendChild(out);
		function emptyOut() {
			out.textContent = '';
			out.appendChild(el('div', {cls: 'fs-tool-empty'}, [icon('fa-reply'), el('span', {text: T.noResponse})]));
		}
		emptyOut();

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
			copy.lastChild.textContent = T.copied;
			setTimeout(function() { copy.lastChild.textContent = T.copy; }, 1500);
		});
		update();

		function showResponse(r, text, ms) {
			out.textContent = '';
			var variant = r.ok ? 'pass' : (r.status >= 500 ? 'block' : 'warn');
			aside.textContent = ms + ' ms';
			out.appendChild(el('div', {cls: 'fx-statusline'}, [el('span', {cls: 'fs-badge fs-badge--' + variant + ' fx-status', text: r.status + ' ' + r.statusText}),
			    el('span', {cls: 'small fs-muted', text: T.duration + ': ' + ms + ' ms'})]));
			var type = r.headers.get('Content-Type') || '', json = null;
			if (type.indexOf('json') !== -1) { try { json = JSON.parse(text); } catch (e) {} }
			if (!r.ok && json && json.error) {
				var err = el('div', {cls: 'alert alert-danger py-2 mb-2 fx-error'});
				err.appendChild(el('strong', {text: (json.error.code || T.errorTitle) + ': '}));
				err.appendChild(document.createTextNode(json.error.message || ''));
				var hint = {401: T.hint401, 403: T.hint403, 412: T.hint412, 422: T.hint422}[r.status];
				if (hint) { err.appendChild(el('div', {cls: 'small', text: hint})); }
				var msgs = json.error.details && Array.isArray(json.error.details.messages) ? json.error.details.messages : [];
				if (msgs.length) {
					var ul = el('ul', {cls: 'mb-0'});
					msgs.forEach(function(m) { ul.appendChild(el('li', {text: String(m)})); });
					err.appendChild(ul);
				}
				out.appendChild(err);
			}
			var ht = el('table', {cls: 'fx-headers'});
			r.headers.forEach(function(v, k) { ht.appendChild(el('tr', {}, [el('td', {text: k}), el('td', {text: v})])); });
			out.appendChild(el('details', {}, [el('summary', {text: T.headers + (r.headers.get('ETag') ? ' (ETag ' + r.headers.get('ETag') + ')' : '')}), ht]));
			var shown = json !== null ? JSON.stringify(json, null, 2) : text;
			var limit = 500000;
			out.appendChild(el('div', {cls: 'form-label mt-2', text: T.body + (shown.length > limit ? ' ' + T.truncated : '')}));
			out.appendChild(el('pre', {cls: 'fs-console fx-body', text: shown.length > limit ? shown.slice(0, limit) : shown}));
		}

		function run(r) {
			var headers = {'Authorization': 'Bearer ' + apiKey, 'Accept': 'application/json, */*'};
			if (r.body !== '') { headers['Content-Type'] = 'application/json'; }
			if (ifMatch && ifMatch.value.trim() !== '') { headers['If-Match'] = ifMatch.value.trim(); }
			out.textContent = T.sending;
			aside.textContent = '';
			send.disabled = true;
			var t0 = performance.now();
			fetch(r.url, {method: ep.method, headers: headers, body: (r.body !== '') ? r.body : undefined,
			    credentials: 'omit', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer'})
			.then(function(resp) {
				return resp.text().then(function(text) {
					var etag = resp.headers.get('ETag');
					if (etag) { lastEtag = etag; }
					showResponse(resp, text, Math.round(performance.now() - t0));
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
			var no = el('button', {type: 'button', cls: 'btn btn-sm btn-outline-secondary', text: T.cancel});
			yes.addEventListener('click', function() { confirmBox.classList.add('d-none'); run(r); });
			no.addEventListener('click', function() { confirmBox.classList.add('d-none'); });
			confirmBox.appendChild(yes);
			confirmBox.appendChild(no);
			yes.focus();
		});
		return [req.node, res.node];
	}

	/* One detail view per endpoint, kept so its fields survive switching. */
	var detail = document.getElementById('fx-detail'), built = {}, current = null;
	function select(id, scroll) {
		var ep = endpoints[id];
		if (!ep) { return; }
		if (current) { current.removeAttribute('aria-current'); }
		current = document.getElementById(id);
		current.setAttribute('aria-current', 'true');
		if (!built[id]) { built[id] = buildDetails(ep); }
		detail.textContent = '';
		detail.appendChild(built[id]);
		if (window.history.replaceState) { window.history.replaceState(null, '', '#' + id); }
		/* Stacked layout (narrow screens): bring the details into view. */
		if (scroll && detail.getBoundingClientRect().top > window.innerHeight * 0.5) {
			detail.scrollIntoView({block: 'start'});
		}
	}
	items.forEach(function(a) {
		a.addEventListener('click', function(e) { e.preventDefault(); select(a.dataset.ep, true); });
	});
	var hash = window.location.hash.slice(1);
	if (hash && endpoints[hash]) {
		var g = document.getElementById(hash).closest('.fx-group');
		setGroup(g, true);
		select(hash, false);
		document.getElementById(hash).scrollIntoView({block: 'nearest'});
	}
});
//]]>
</script>
<?php
include("foot.inc");
