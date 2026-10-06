<?php
/*
 * restapi_keys.inc
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
 * API key widgets shared by System > REST API (every user's keys) and
 * System > My API Keys (the signed-in user's own keys).
 */

require_once('restapi.inc');

/* The one-time display of a newly created key. */
function restapi_print_new_token($token) {
	$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'firewall');
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('New API Key')?></h2></div>
	<div class="panel-body">
		<p><strong><?=gettext('Copy this key now. It is shown only once and cannot be recovered.')?></strong></p>
		<div class="input-group">
			<input type="text" class="form-control" id="newtoken" readonly value="<?=htmlspecialchars($token)?>" />
			<button type="button" class="btn btn-primary" id="copytoken">
				<i class="fa-solid fa-copy"></i> <?=gettext('Copy')?>
			</button>
		</div>
		<p class="mt-2 mb-0 text-muted"><?=gettext('Example:')?>
			<code>curl -H "Authorization: Bearer <?=htmlspecialchars($token)?>" https://<?=$host?>/api/v1/me</code>
		</p>
	</div>
</div>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$('#copytoken').on('click', function() {
		var field = document.getElementById('newtoken');
		field.select();
		if (navigator.clipboard) {
			navigator.clipboard.writeText(field.value);
		} else {
			document.execCommand('copy');
		}
		$(this).html('<i class="fa-solid fa-check"></i> ' + <?=json_encode(gettext('Copied'))?>);
	});
});
//]]>
</script>
<?php
}

/*
 * The key list's actions: act=revoke|delete with id (a row's buttons), or
 * act=revoke_selected|delete_selected with ids[] (the toolbar). With
 * $only_user, only that user's keys are touched. Returns array($savemsg,
 * $input_errors), or null when $post holds no key action.
 */
function restapi_key_action(array $post, $only_user = null) {
	$act = (string)($post['act'] ?? '');
	if (($act === 'revoke') || ($act === 'delete')) {
		$ids = array((string)($post['id'] ?? ''));
	} elseif (($act === 'revoke_selected') || ($act === 'delete_selected')) {
		$ids = array_values(array_filter((array)($post['ids'] ?? array()), 'is_string'));
		$act = substr($act, 0, -strlen('_selected'));
	} else {
		return null;
	}
	if ($act === 'revoke') {
		$n = restapi_revoke_tokens($ids, $only_user);
		return ($n > 0) ? array(sprintf(gettext('%d API key(s) revoked. They no longer work and stay listed until deleted.'), $n), array()) :
		    array('', array(gettext('No key was revoked: none was selected, or the selected keys no longer exist or are already revoked.')));
	}
	$n = restapi_delete_tokens($ids, $only_user);
	return ($n > 0) ? array(sprintf(gettext('%d API key(s) deleted.'), $n), array()) :
	    array('', array(gettext('No key was deleted: none was selected, or the selected keys no longer exist.')));
}

/*
 * The key list: a toolbar (filter, Add, Revoke and Delete the selected keys),
 * sortable columns and per-row Revoke and Delete. $page handles the actions
 * (restapi_key_action()); $show_user adds the owner column with an API access
 * check; $can_add enables Add, which opens restapi_print_create_form()'s modal.
 */
function restapi_print_key_table(array $tokens, $show_user, $page, $can_add = true) {
	$used = restapi_last_used_read();
	$cols = $show_user ? 8 : 7;
	$stale_count = 0;
	$sort_th = function ($key, $label, $numeric = false) {
		return '<th aria-sort="none"><button type="button" class="restapi-sort" data-sort="' . $key . '"' .
		    ($numeric ? ' data-numeric="1"' : '') . '>' . $label . '<i class="fa-solid fa-sort ms-1" aria-hidden="true"></i></button></th>';
	};
	$i18n = array(
		'none' => gettext('Select keys to revoke or delete them'),
		'some' => gettext('%1$d of %2$d keys selected'),
		'selectKey' => gettext('Select key %s'),
		'confirmRevoke' => gettext('Revoke %d API key(s)? They stop working immediately and cannot be re-enabled; they stay listed until deleted.'),
		'confirmDelete' => gettext('Delete %d API key(s)? They stop working immediately and are removed from the list.'),
	);
?>
<form method="post" action="<?=htmlspecialchars($page)?>" id="restapi-keys-form">
<div class="panel panel-default restapi-keys-panel">
	<div class="panel-heading restapi-keys-toolbar">
		<h2 class="panel-title mb-0 me-auto"><?=gettext('API Keys')?>
			<span class="badge text-bg-secondary ms-1"><?=count($tokens)?></span></h2>
<?php	if (!empty($tokens)): ?>
		<div class="input-group input-group-sm restapi-keys-filter">
			<span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
			<input type="search" class="form-control" id="restapi-keys-filter" autocomplete="off"
			    placeholder="<?=$show_user ? gettext('Filter by key, user, description or scope') : gettext('Filter by key, description or scope')?>"
			    aria-label="<?=gettext('Filter the API keys')?>" />
		</div>
<?php	endif; ?>
		<div class="d-flex gap-2">
			<button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#restapi-create-modal"<?=$can_add ? '' : ' disabled'?>
			    title="<?=gettext('Create a new API key')?>"><i class="fa-solid fa-plus icon-embed-btn"></i><?=gettext('Add')?></button>
<?php	if (!empty($tokens)): ?>
			<button type="submit" class="btn btn-sm btn-outline-warning no-confirm restapi-bulk" name="act" value="revoke_selected" id="restapi-keys-revoke" disabled
			    title="<?=gettext('Revoke the selected keys: they stop working but stay listed')?>"><i class="fa-solid fa-ban icon-embed-btn"></i><?=gettext('Revoke')?>
				<span class="badge rounded-pill ms-1 restapi-bulk-n d-none"></span></button>
			<button type="submit" class="btn btn-sm btn-outline-danger no-confirm restapi-bulk" name="act" value="delete_selected" id="restapi-keys-delete" disabled
			    title="<?=gettext('Delete the selected keys')?>"><i class="fa-solid fa-trash-can icon-embed-btn"></i><?=gettext('Delete')?>
				<span class="badge rounded-pill ms-1 restapi-bulk-n d-none"></span></button>
<?php	endif; ?>
		</div>
	</div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm align-middle mb-0 restapi-keys">
				<thead><tr>
					<th style="width: 2.5rem"><input type="checkbox" class="form-check-input" id="restapi-keys-all"
					    aria-label="<?=gettext('Select all shown keys')?>"<?=empty($tokens) ? ' disabled' : ''?> /></th>
					<?=$sort_th('key', gettext('Key'))?>
<?php	if ($show_user): ?>
					<?=$sort_th('user', gettext('User'))?>
<?php	endif; ?>
					<?=$sort_th('access', gettext('Access'), true)?>
					<?=$sort_th('created', gettext('Created'), true)?>
					<?=$sort_th('expires', gettext('Expires'), true)?>
					<?=$sort_th('used', gettext('Last used'), true)?>
					<th class="text-end"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr></thead>
				<tbody>
<?php	if (empty($tokens)): ?>
					<tr><td colspan="<?=$cols?>" class="restapi-keys-empty">
						<i class="fa-solid fa-key" aria-hidden="true"></i>
						<div class="fw-semibold"><?=gettext('No API keys yet')?></div>
						<div class="small text-muted"><?=$can_add ? gettext('Use Add to create one. A key is shown only once, right after it is created.') :
						    gettext('Keys can be created once a user has the "WebCfg - System: REST API access" privilege.')?></div>
					</td></tr>
<?php	endif;
	foreach ($tokens as $token):
		$revoked = !empty($token['revoked']);
		$expired = !empty($token['expires']) && ((int)$token['expires'] < time());
		$owner = restapi_local_user($token['username']);
		$problem = '';
		if ($owner === null) {
			$problem = gettext('user missing or disabled');
		} elseif (!restapi_user_has_access($owner)) {
			$problem = gettext('user has no REST API access');
		}
		$stale = $revoked || $expired || ($problem !== '');
		$stale_count += $stale ? 1 : 0;
		$last = $used[$token['id']] ?? null;
		$scopes = restapi_token_scopes($token);
		$key_name = RESTAPI_TOKEN_PREFIX . '_' . $token['id'];
		$search = strtolower(implode(' ', array($key_name, $show_user ? $token['username'] : '', $token['descr'] ?? '',
		    implode(' ', $scopes), $revoked ? gettext('revoked') : '', $expired ? gettext('expired') : '')));
		$href = htmlspecialchars($page) . '?id=' . htmlspecialchars($token['id']) . '&amp;act=';
?>
					<tr class="restapi-key<?=$stale ? ' restapi-stale' : ''?>" data-key="<?=htmlspecialchars($token['id'])?>"
					    data-user="<?=htmlspecialchars($token['username'])?>" data-access="<?=!empty($token['readonly']) ? 0 : 1?>"
					    data-created="<?=(int)$token['created']?>" data-expires="<?=empty($token['expires']) ? PHP_INT_MAX : (int)$token['expires']?>"
					    data-used="<?=$last ? (int)$last['time'] : 0?>" data-stale="<?=$stale ? 1 : 0?>" data-search="<?=htmlspecialchars($search)?>">
						<td><input type="checkbox" class="form-check-input restapi-key-check" name="ids[]" value="<?=htmlspecialchars($token['id'])?>"
						    aria-label="<?=htmlspecialchars(sprintf($i18n['selectKey'], $key_name))?>" /></td>
						<td><code class="restapi-key-id"><?=htmlspecialchars($key_name)?></code>
<?php		if ($revoked): ?>
							<span class="badge text-bg-danger ms-1" title="<?=htmlspecialchars(sprintf(gettext('Revoked %s'), date('Y-m-d H:i', (int)$token['revoked'])))?>">
								<i class="fa-solid fa-ban"></i> <?=gettext('revoked')?></span>
<?php		endif; ?>
<?php		if (($token['descr'] ?? '') !== ''): ?>
							<div class="small text-muted"><?=htmlspecialchars($token['descr'])?></div>
<?php		endif; ?>
						</td>
<?php		if ($show_user): ?>
						<td><span class="restapi-user"><i class="fa-solid fa-user" aria-hidden="true"></i><?=htmlspecialchars($token['username'])?></span><?=($problem !== '') ?
						    '<div class="small text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ' . htmlspecialchars($problem) . '</div>' : ''?></td>
<?php		endif; ?>
						<td><?=!empty($token['readonly']) ? '<span class="badge text-bg-secondary">' . gettext('Read-only') . '</span>' :
						    '<span class="badge text-bg-warning">' . gettext('Read/write') . '</span>'?>
							<div class="small text-muted"><?=empty($scopes) ? gettext('All of the user\'s scopes') :
							    htmlspecialchars(implode(', ', $scopes))?></div></td>
						<td class="text-nowrap"><?=htmlspecialchars(date('Y-m-d H:i', (int)$token['created']))?></td>
						<td class="text-nowrap"><?=empty($token['expires']) ? '<span class="text-muted">' . gettext('Never') . '</span>' :
						    ($expired ? '<span class="text-danger">' : '') . htmlspecialchars(date('Y-m-d', (int)$token['expires'])) .
						    ($expired ? ' (' . gettext('expired') . ')</span>' : '')?></td>
						<td class="text-nowrap"><?php
		if ($last):
?><?=htmlspecialchars(date('Y-m-d H:i', (int)$last['time']))?>
							<div class="small text-muted"><?=gettext('from')?> <?=htmlspecialchars($last['ip'])?></div><?php
		else:
?><span class="text-muted"><?=gettext('Never')?></span><?php
		endif;
?></td>
						<td class="text-end text-nowrap restapi-row-actions">
<?php		if (!$revoked): ?>
							<a class="btn btn-sm btn-outline-warning do-confirm" title="<?=gettext('Revoke this API key')?>" aria-label="<?=gettext('Revoke this API key')?>"
							    href="<?=$href?>revoke" usepost><i class="fa-solid fa-ban"></i></a>
<?php		endif; ?>
							<a class="btn btn-sm btn-outline-danger do-confirm" title="<?=gettext('Delete this API key')?>" aria-label="<?=gettext('Delete this API key')?>"
							    href="<?=$href?>delete" usepost><i class="fa-solid fa-trash-can"></i></a>
						</td>
					</tr>
<?php	endforeach; ?>
					<tr class="d-none" id="restapi-keys-nomatch"><td colspan="<?=$cols?>" class="text-muted py-3"><?=gettext('No key matches the filter.')?></td></tr>
				</tbody>
			</table>
		</div>
	</div>
<?php	if (!empty($tokens)): ?>
	<div class="panel-footer d-flex flex-wrap align-items-center gap-2">
		<span class="small text-muted me-auto" id="restapi-keys-count" aria-live="polite"><?=$i18n['none']?></span>
<?php		if ($stale_count > 0): ?>
		<button type="button" class="btn btn-sm btn-link text-decoration-none" id="restapi-keys-stale">
			<i class="fa-solid fa-broom icon-embed-btn"></i><?=sprintf(gettext('Select revoked, expired and unusable (%d)'), $stale_count)?></button>
<?php		endif; ?>
	</div>
<?php	endif; ?>
</div>
</form>
<style>
	.restapi-keys-filter { width: 20rem; max-width: 100%; }
	.restapi-sort { all: unset; cursor: pointer; white-space: nowrap; }
	.restapi-sort:focus-visible { outline: 2px solid var(--fs-coral); outline-offset: 2px; border-radius: 2px; }
	.restapi-sort .fa-sort { opacity: .35; }
	th[aria-sort="ascending"] .restapi-sort, th[aria-sort="descending"] .restapi-sort { color: var(--bs-emphasis-color); }
	th[aria-sort="ascending"] .restapi-sort i, th[aria-sort="descending"] .restapi-sort i { opacity: 1; color: var(--fs-coral); }
	tr.restapi-key { cursor: pointer; }
	tr.restapi-key.restapi-selected > td { background-color: rgba(var(--bs-primary-rgb), .10) !important; }
	tr.restapi-key.restapi-selected > td:first-child { box-shadow: inset 3px 0 0 var(--fs-coral); }
	tr.restapi-stale .restapi-key-id { text-decoration: line-through; opacity: .7; }
	.restapi-user { display: inline-flex; align-items: center; gap: .4rem; }
	.restapi-user i { font-size: .75em; color: var(--bs-secondary-color); }
	.restapi-bulk .badge { color: var(--bs-body-bg); background: var(--bs-btn-color); }
	.restapi-bulk:disabled { opacity: .4; filter: grayscale(.7); }
</style>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var form = document.getElementById('restapi-keys-form');
	var tbody = form.querySelector('tbody');
	var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.restapi-key'));
	if (rows.length === 0) {
		return;
	}
	var T = <?=json_encode($i18n, JSON_HEX_TAG | JSON_HEX_AMP)?>;
	var all = document.getElementById('restapi-keys-all');
	var count = document.getElementById('restapi-keys-count');
	var bulk = Array.prototype.slice.call(form.querySelectorAll('.restapi-bulk'));
	var filter = document.getElementById('restapi-keys-filter');
	var nomatch = document.getElementById('restapi-keys-nomatch');
	var stale = document.getElementById('restapi-keys-stale');
	var lastIndex = null;
	function check(r) { return r.querySelector('.restapi-key-check'); }
	function visible() { return rows.filter(function(r) { return !r.classList.contains('d-none'); }); }
	function selected() { return rows.filter(function(r) { return check(r).checked; }).length; }

	/* The selection: count, header checkbox (visible rows), row highlight and the toolbar buttons. */
	function update() {
		var n = selected(), shown = visible(), shownChecked = 0;
		rows.forEach(function(r) { r.classList.toggle('restapi-selected', check(r).checked); });
		shown.forEach(function(r) { shownChecked += check(r).checked ? 1 : 0; });
		all.checked = shown.length > 0 && shownChecked === shown.length;
		all.indeterminate = shownChecked > 0 && shownChecked < shown.length;
		count.textContent = (n === 0) ? T.none : T.some.replace('%1$d', n).replace('%2$d', rows.length);
		bulk.forEach(function(b) {
			b.disabled = (n === 0);
			var badge = b.querySelector('.restapi-bulk-n');
			badge.textContent = n;
			badge.classList.toggle('d-none', n === 0);
		});
	}
	all.addEventListener('change', function() {
		visible().forEach(function(r) { check(r).checked = all.checked; });
		update();
	});
	/* Click a row to select it; shift-click selects the range from the last click. */
	rows.forEach(function(r) {
		r.addEventListener('click', function(e) {
			if (e.target.closest('a, button')) {
				return;
			}
			var box = check(r);
			if (e.target !== box) {
				box.checked = !box.checked;
			}
			var order = visible(), at = order.indexOf(r);
			if (e.shiftKey && lastIndex !== null && order[lastIndex]) {
				order.slice(Math.min(lastIndex, at), Math.max(lastIndex, at) + 1).forEach(function(x) { check(x).checked = box.checked; });
				window.getSelection().removeAllRanges();
			}
			lastIndex = at;
			update();
		});
	});
	if (stale) {
		stale.addEventListener('click', function() {
			rows.forEach(function(r) { check(r).checked = (r.dataset.stale === '1'); });
			update();
		});
	}
	/* Revoke and Delete ask first, with the number of keys. */
	bulk.forEach(function(b) {
		b.addEventListener('click', function(e) {
			var msg = (b.value === 'revoke_selected') ? T.confirmRevoke : T.confirmDelete;
			if (!window.confirm(msg.replace('%d', selected()))) {
				e.preventDefault();
			}
		});
	});

	/* Enter in the filter must not submit the form (its first button is Revoke). */
	filter.addEventListener('keydown', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
		}
	});
	/* Filter: hides rows that do not match; hidden rows keep their selection. */
	filter.addEventListener('input', function() {
		var words = filter.value.toLowerCase().split(/\s+/).filter(function(w) { return w !== ''; });
		rows.forEach(function(r) {
			r.classList.toggle('d-none', !words.every(function(w) { return r.dataset.search.indexOf(w) !== -1; }));
		});
		nomatch.classList.toggle('d-none', visible().length !== 0);
		lastIndex = null;
		update();
	});

	/* Sort: click a column header; again to reverse. */
	var sortKey = null, sortDir = 1;
	form.querySelectorAll('.restapi-sort').forEach(function(b) {
		b.addEventListener('click', function() {
			var key = b.dataset.sort, numeric = b.dataset.numeric === '1';
			sortDir = (sortKey === key) ? -sortDir : (numeric ? -1 : 1);
			sortKey = key;
			rows.sort(function(x, y) {
				var a = x.dataset[key], c = y.dataset[key];
				var d = numeric ? (Number(a) - Number(c)) : a.localeCompare(c, undefined, {numeric: true, sensitivity: 'base'});
				return d * sortDir;
			});
			rows.forEach(function(r) { tbody.insertBefore(r, nomatch); });
			form.querySelectorAll('th[aria-sort]').forEach(function(th) {
				var on = th.contains(b);
				th.setAttribute('aria-sort', on ? ((sortDir === 1) ? 'ascending' : 'descending') : 'none');
				th.querySelector('i').className = 'fa-solid ms-1 ' + (on ? ((sortDir === 1) ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort');
			});
			lastIndex = null;
		});
	});
	update();
});
//]]>
</script>
<?php
}

/* At most this many users are listed by the user lookup; typing narrows the list. */
define('RESTAPI_USER_LOOKUP_LIMIT', 15);

/*
 * The create form, in a modal that the key list's Add button opens. $users
 * maps username => label; with $fixed_user set, the owner is shown instead of
 * a selector. The user selector is a plain <select> that the page turns into
 * a type-ahead lookup. $errors (a refused create) are shown in the modal,
 * which then opens by itself with the entered values.
 */
function restapi_print_create_form(array $users, array $create, $fixed_user = null, array $errors = array()) {
	$form = new Form(false);
	$form->setAttribute('id', 'restapi-create-form');
	$section = new Form_Section('NOTITLE');
	if ($fixed_user !== null) {
		$section->addInput(new Form_StaticText(gettext('User'), htmlspecialchars($fixed_user)));
	} else {
		$chosen_user = $create['username'] ?? ((count($users) === 1) ? array_key_first($users) : '');
		$section->addInput(new Form_Select(
			'username',
			'*' . gettext('User'),
			$chosen_user,
			array('' => gettext('(choose a user)')) + $users
		))->setHelp(sprintf(ngettext('The key acts as this local user and gets exactly the user\'s GUI privileges. ' .
		    '%d user has the "WebCfg - System: REST API access" privilege; type part of a name to find it.',
		    'The key acts as this local user and gets exactly the user\'s GUI privileges. ' .
		    '%d users have the "WebCfg - System: REST API access" privilege; type part of a name to find one.', count($users)), count($users)));
	}
	$section->addInput(new Form_Input(
		'descr',
		'*' . gettext('Description'),
		'text',
		$create['descr'] ?? '',
		array('placeholder' => gettext('e.g. Ansible on mgmt01'), 'maxlength' => 120)
	))->setHelp(gettext('Where or what the key is used for, so it can be recognized and revoked later.'));
	$section->addInput(new Form_Checkbox(
		'readonly',
		gettext('Read-only'),
		gettext('Only allow requests that do not change anything'),
		!empty($create['readonly'])
	));

	$limited = (($create['scope_mode'] ?? '') === 'limited');
	$chosen = array_flip((array)($create['scopes'] ?? array()));
	$group = new Form_Group(gettext('Scopes'));
	$group->add(new Form_Checkbox(
		'scope_mode',
		gettext('Scopes'),
		gettext('Everything the user may do'),
		!$limited,
		'all'
	))->displayAsRadio();
	$group->add(new Form_Checkbox(
		'scope_mode',
		gettext('Scopes'),
		gettext('Only the scopes selected below'),
		$limited,
		'limited'
	))->displayAsRadio();
	$group->setHelp(gettext('Scopes only narrow a key: it never gets more than its user holds. ' .
	    'A write scope includes reading.'));
	$section->add($group);

	$rows = '';
	foreach (restapi_areas() as $area => $label) {
		$cells = '';
		foreach (restapi_actions() as $action) {
			$scope = restapi_scope($area, $action);
			$cells .= '<td class="text-center"><input type="checkbox" class="form-check-input restapi-scope" name="scopes[]" value="' .
			    htmlspecialchars($scope) . '"' . (isset($chosen[$scope]) ? ' checked' : '') .
			    ' aria-label="' . htmlspecialchars($scope) . '" /></td>';
		}
		$rows .= '<tr><td>' . htmlspecialchars(gettext($label)) . ' <code class="small">' . htmlspecialchars($area) . '</code></td>' . $cells . '</tr>';
	}
	$section->addInput(new Form_StaticText(
		'',
		'<div id="restapi-scope-table"><table class="table table-sm table-striped table-hover mb-0"><thead><tr><th>' .
		gettext('Area') . '</th><th class="text-center">' . gettext('Read') . '</th><th class="text-center">' .
		gettext('Write') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
	));
	$section->addInput(new Form_Input(
		'expires_days',
		gettext('Expires after (days)'),
		'number',
		$create['expires_days'] ?? '',
		array('min' => 1, 'max' => 3650, 'placeholder' => gettext('never'), 'inputmode' => 'numeric')
	))->setHelp(gettext('Leave empty for a key that does not expire.'));
	$form->add($section);
?>
<div class="modal fade restapi-modal" id="restapi-create-modal" tabindex="-1" aria-labelledby="restapi-create-title" aria-hidden="true"
    data-open="<?=empty($errors) ? '0' : '1'?>">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<div class="restapi-modal-icon" aria-hidden="true"><i class="fa-solid fa-key"></i></div>
				<div class="me-auto">
					<h2 class="modal-title h5 mb-0" id="restapi-create-title"><?=gettext('Create API Key')?></h2>
					<div class="small text-muted"><?=($fixed_user !== null) ? htmlspecialchars(sprintf(gettext('The key acts as you (%s).'), $fixed_user)) :
					    gettext('The key acts as the user you choose.')?></div>
				</div>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?=gettext('Close')?>"></button>
			</div>
			<div class="modal-body">
<?php	if (!empty($errors)): ?>
				<div class="alert alert-danger py-2" role="alert"><ul class="mb-0 ps-3">
<?php		foreach ($errors as $error): ?>
					<li><?=$error?></li>
<?php		endforeach; ?>
				</ul></div>
<?php	endif; ?>
				<?php print($form); ?>
			</div>
			<div class="modal-footer">
				<span class="small text-muted me-auto"><i class="fa-solid fa-circle-info me-1"></i><?=gettext('The key is shown only once, right after it is created.')?></span>
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?=gettext('Cancel')?></button>
				<button type="submit" class="btn btn-success" form="restapi-create-form" name="create" value="create">
					<i class="fa-solid fa-key icon-embed-btn"></i><?=gettext('Create key')?></button>
			</div>
		</div>
	</div>
</div>
<?php
	$i18n = array(
		'placeholder' => gettext('Type a user name'),
		'more' => gettext('%d more; keep typing to narrow the list'),
		'none' => gettext('No user with REST API access matches'),
		'invalid' => gettext('Choose a user from the list.'),
	);
?>
<style>
	.restapi-combo .dropdown-menu { top: 100%; left: 0; margin-top: .125rem; max-height: min(34rem, 70vh); overflow-y: auto; }
	.restapi-combo .dropdown-item { cursor: pointer; display: flex; align-items: center; gap: .5rem; padding-top: .3rem; padding-bottom: .3rem; }
	.restapi-combo .dropdown-item .fa-check { width: 1em; color: var(--fs-coral); }
	.restapi-combo .dropdown-item mark { padding: 0; background: none; color: inherit; font-weight: 700; text-decoration: underline; }
	.restapi-combo .dropdown-item.active mark { color: inherit; }
	#restapi-scope-table { max-height: 26rem; overflow-y: auto; border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius-sm); }
	#restapi-scope-table thead th { position: sticky; top: 0; background: var(--bs-tertiary-bg); z-index: 1; }
</style>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function showScopes() {
		var limited = $('input[name="scope_mode"]:checked').val() === 'limited';
		$('#restapi-scope-table').closest('.form-group, .row').toggle(limited);
	}
	$('input[name="scope_mode"]').on('change', showScopes);
	showScopes();

	/* The modal: opens by itself after a refused create; focuses the user lookup until a user is chosen, then the description. */
	var modal = document.getElementById('restapi-create-modal');
	modal.addEventListener('shown.bs.modal', function() {
		var owner = document.getElementById('username');
		var first = (owner && owner.tagName === 'SELECT' && owner.value === '') ? document.getElementById('username-lookup') :
		    document.getElementById('descr');
		if (first) {
			first.focus();
		}
	});
	if (modal.dataset.open === '1') {
		bootstrap.Modal.getOrCreateInstance(modal).show();
	}

	/*
	 * The user lookup: a combobox over the hidden username <select>. It lists
	 * up to LIMIT matches (names starting with the text first), fills in the
	 * rest of the first match while typing, and only accepts listed users.
	 */
	var select = document.getElementById('username');
	if (!select || select.tagName !== 'SELECT') {
		return;
	}
	var T = <?=json_encode($i18n, JSON_HEX_TAG | JSON_HEX_AMP)?>;
	var LIMIT = <?=(int)RESTAPI_USER_LOOKUP_LIMIT?>;
	var opts = Array.prototype.filter.call(select.options, function(o) { return o.value !== ''; })
	    .map(function(o) { return {value: o.value, label: o.text}; });
	var wrap = document.createElement('div');
	var input = document.createElement('input');
	var list = document.createElement('ul');
	var feedback = document.createElement('div');
	wrap.className = 'restapi-combo position-relative';
	input.type = 'text';
	input.id = 'username-lookup';
	input.className = 'form-control';
	input.autocomplete = 'off';
	input.spellcheck = false;
	input.placeholder = T.placeholder;
	input.setAttribute('role', 'combobox');
	input.setAttribute('aria-autocomplete', 'both');
	input.setAttribute('aria-expanded', 'false');
	input.setAttribute('aria-controls', 'username-lookup-list');
	list.id = 'username-lookup-list';
	list.className = 'dropdown-menu w-100';
	list.setAttribute('role', 'listbox');
	feedback.className = 'invalid-feedback';
	feedback.textContent = T.invalid;
	select.parentNode.insertBefore(wrap, select);
	wrap.appendChild(input);
	wrap.appendChild(list);
	wrap.appendChild(feedback);
	select.classList.add('d-none');
	select.tabIndex = -1;
	select.setAttribute('aria-hidden', 'true');
	/* The Form framework's label has no "for": point it at the lookup. */
	var label = $(wrap).closest('.form-group, .row').find('label.control-label')[0];
	if (label) {
		label.htmlFor = input.id;
	}

	var matches = [], active = -1;
	function norm(s) { return String(s).trim().toLowerCase(); }
	function byValue(v) { return opts.filter(function(o) { return o.value === v; })[0] || null; }
	function exact(text) {
		var t = norm(text);
		return (t === '') ? null : (opts.filter(function(o) { return norm(o.label) === t || norm(o.value) === t; })[0] || null);
	}
	function find(text) {
		var q = norm(text), starts = [], contains = [];
		if (q === '') {
			return opts.slice();
		}
		opts.forEach(function(o) {
			var l = norm(o.label);
			if (norm(o.value).indexOf(q) === 0 || l.indexOf(q) === 0) {
				starts.push(o);
			} else if (l.indexOf(q) !== -1) {
				contains.push(o);
			}
		});
		return starts.concat(contains);
	}
	function item(cls, text) {
		var li = document.createElement('li');
		li.className = cls;
		li.textContent = text;
		return li;
	}
	function render(text) {
		var q = norm(text);
		matches = find(text);
		active = -1;
		list.textContent = '';
		input.removeAttribute('aria-activedescendant');
		matches.slice(0, LIMIT).forEach(function(o, i) {
			var li = document.createElement('li');
			var opt = document.createElement('div');
			var check = document.createElement('i');
			var at = (q === '') ? -1 : o.label.toLowerCase().indexOf(q);
			opt.id = 'username-lookup-' + i;
			opt.className = 'dropdown-item';
			opt.setAttribute('role', 'option');
			opt.setAttribute('aria-selected', 'false');
			check.className = (o.value === select.value) ? 'fa-solid fa-check' : 'fa-solid fa-check invisible';
			check.setAttribute('aria-hidden', 'true');
			opt.appendChild(check);
			var name = document.createElement('span');
			if (at === -1) {
				name.textContent = o.label;
			} else {
				var mark = document.createElement('mark');
				mark.textContent = o.label.substr(at, q.length);
				name.appendChild(document.createTextNode(o.label.slice(0, at)));
				name.appendChild(mark);
				name.appendChild(document.createTextNode(o.label.slice(at + q.length)));
			}
			opt.appendChild(name);
			opt.addEventListener('mousedown', function(e) { e.preventDefault(); choose(o); });
			li.appendChild(opt);
			list.appendChild(li);
		});
		if (matches.length === 0) {
			list.appendChild(item('dropdown-item-text text-muted small', T.none));
		} else if (matches.length > LIMIT) {
			list.appendChild(item('dropdown-item-text text-muted small border-top mt-1 pt-2',
			    T.more.replace('%d', matches.length - LIMIT)));
		}
		var opening = !isOpen();
		list.classList.add('show');
		input.setAttribute('aria-expanded', 'true');
		/* Near the bottom of the page, scroll once so the whole list is visible. */
		if (opening && list.getBoundingClientRect().bottom > window.innerHeight) {
			list.scrollIntoView({block: 'nearest'});
		}
	}
	function close() {
		list.classList.remove('show');
		input.setAttribute('aria-expanded', 'false');
		input.removeAttribute('aria-activedescendant');
		active = -1;
	}
	function isOpen() { return list.classList.contains('show'); }
	function setActive(i) {
		var items = list.querySelectorAll('[role="option"]');
		if (items.length === 0) {
			return;
		}
		active = (i + items.length) % items.length;
		Array.prototype.forEach.call(items, function(n, k) {
			n.classList.toggle('active', k === active);
			n.setAttribute('aria-selected', (k === active) ? 'true' : 'false');
		});
		input.setAttribute('aria-activedescendant', items[active].id);
		items[active].scrollIntoView({block: 'nearest'});
	}
	function choose(o) {
		select.value = o.value;
		input.value = o.label;
		input.classList.remove('is-invalid');
		close();
	}

	var current = byValue(select.value);
	input.value = current ? current.label : '';

	input.addEventListener('focus', function() {
		render(byValue(select.value) ? '' : input.value);
		input.select();
	});
	input.addEventListener('click', function() {
		if (!isOpen()) {
			render(byValue(select.value) ? '' : input.value);
		}
	});
	input.addEventListener('input', function(e) {
		var typed = input.value;
		input.classList.remove('is-invalid');
		render(typed);
		/* While typing forward at the end, fill in the rest of the first match and select the filled-in part. */
		if (matches.length && typed.trim() !== '' && (e.inputType || '').indexOf('insert') === 0 &&
		    input.selectionEnd === typed.length) {
			var first = matches[0];
			if (norm(first.label).indexOf(norm(typed)) === 0 && first.label.length > typed.length) {
				input.value = typed + first.label.slice(typed.length);
				input.setSelectionRange(typed.length, input.value.length);
			}
			if (norm(first.label).indexOf(norm(typed)) === 0 || norm(first.value).indexOf(norm(typed)) === 0) {
				setActive(0);
			}
		}
		var hit = exact(input.value);
		select.value = hit ? hit.value : '';
	});
	input.addEventListener('keydown', function(e) {
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			if (!isOpen()) {
				render(byValue(select.value) ? '' : input.value);
			}
			setActive(active + ((e.key === 'ArrowDown') ? 1 : -1));
		} else if (e.key === 'Enter' && isOpen()) {
			var pick = (active >= 0) ? matches[active] : (exact(input.value) || ((matches.length === 1) ? matches[0] : null));
			if (pick) {
				e.preventDefault();
				choose(pick);
			}
		} else if (e.key === 'Tab' && isOpen() && active >= 0) {
			choose(matches[active]);
		} else if (e.key === 'Escape') {
			if (isOpen()) {
				e.preventDefault();
				close();
			} else if (input.value !== '') {
				input.value = '';
				select.value = '';
			}
		}
	});
	input.addEventListener('blur', function() {
		var pick = exact(input.value) || ((matches.length === 1 && norm(input.value) !== '') ? matches[0] : null);
		if (pick) {
			choose(pick);
		} else {
			close();
			select.value = '';
			input.classList.toggle('is-invalid', norm(input.value) !== '');
		}
	});
	$(input).closest('form').on('submit', function(e) {
		if (select.value === '') {
			e.preventDefault();
			input.classList.add('is-invalid');
			input.focus();
		}
	});
});
//]]>
</script>
<?php
}

/* Enabled local users that hold REST API access, as username => label. */
function restapi_users_with_access() {
	$users = array();
	foreach (config_get_path('system/user', []) as $user) {
		if (is_array($user) && !empty($user['name']) && empty($user['disabled']) &&
		    restapi_user_has_access($user)) {
			$users[$user['name']] = $user['name'] . (!empty($user['descr']) ? " ({$user['descr']})" : '');
		}
	}
	uksort($users, 'strnatcasecmp');
	return $users;
}

/*
 * The System > REST API tabs the signed-in user may open: the settings page
 * (Settings and All Keys views), My Keys, and the explorer (Guide and API
 * Explorer views). $view is the current page's view. $always shows them even
 * when only the current page is allowed (the settings page always did).
 */
function restapi_print_tabs($active, $always = false, $view = '') {
	$current = $active . (($view !== '') ? "?view={$view}" : '');
	$tab_array = array();
	foreach (array(
	    'system_restapi.php' => gettext('Settings'),
	    'system_restapi.php?view=listeners' => gettext('Listeners'),
	    'system_restapi.php?view=keys' => gettext('All Keys'),
	    'system_restapi_keys.php' => gettext('My API Keys'),
	    'system_restapi_explorer.php?view=guide' => gettext('Guide'),
	    'system_restapi_explorer.php' => gettext('API Explorer')) as $url => $label) {
		if (isAllowedPage(strtok($url, '?'))) {
			$tab_array[] = array($label, $url === $current, $url);
		}
	}
	if ($always || (count($tab_array) > 1)) {
		display_top_tabs($tab_array);
	}
	restapi_print_shared_styles();
}

/* Styles the REST API pages share: list toolbars, row buttons, empty states and the modals. */
function restapi_print_shared_styles() {
?>
<style>
	.restapi-keys-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
	.restapi-row-actions .btn { --bs-btn-padding-x: .45rem; --bs-btn-padding-y: .2rem; }
	.restapi-row-actions .btn + .btn { margin-left: .25rem; }
	.restapi-keys-empty { text-align: center; padding: 2.5rem 1rem !important; }
	.restapi-keys-empty > i { font-size: 1.75rem; color: var(--fs-coral); opacity: .8; margin-bottom: .5rem; }
	tr.restapi-stale > td:not(:last-child) { opacity: .75; }
	.restapi-modal .modal-content { border: 1px solid var(--bs-border-color); box-shadow: 0 1.5rem 3rem rgba(0, 0, 0, .35); }
	.restapi-modal .modal-header { gap: .85rem; align-items: center; border-bottom-color: var(--bs-border-color); }
	.restapi-modal .modal-footer { border-top-color: var(--bs-border-color); }
	.restapi-modal .panel { border: 0; box-shadow: none; margin: 0; background: transparent; }
	.restapi-modal-icon { width: 2.5rem; height: 2.5rem; flex: none; border-radius: .65rem; display: grid; place-items: center;
	    background: rgba(var(--bs-primary-rgb), .14); color: var(--fs-coral); font-size: 1.1rem; }
</style>
<?php
}
