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

/*
 * The one-time display of a newly created key. The secret exists only in
 * this response: it is not stored, logged or cached (the page sends
 * Cache-Control: no-store), the browser forgets the POST so a reload does
 * not create another key, and "Done" removes it from the page.
 */
function restapi_print_new_token($token) {
	$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'firewall');
?>
<div class="panel panel-default restapi-newkey" id="restapi-newkey" role="region" aria-labelledby="restapi-newkey-title">
	<div class="panel-heading">
		<h2 class="panel-title" id="restapi-newkey-title"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><?=gettext('API key created')?></h2>
	</div>
	<div class="panel-body">
		<p class="restapi-newkey-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
			<strong><?=gettext('Copy this key now. It is shown only once and cannot be recovered.')?></strong></p>
		<label class="form-label" for="newtoken"><?=gettext('API key')?></label>
		<div class="input-group">
			<input type="text" class="form-control fs-mono" id="newtoken" readonly autocomplete="off" spellcheck="false"
			    data-lpignore="true" value="<?=htmlspecialchars($token)?>" />
			<button type="button" class="btn btn-primary" id="copytoken">
				<i class="fa-solid fa-copy icon-embed-btn" aria-hidden="true"></i><span><?=gettext('Copy')?></span></button>
		</div>
		<p class="fs-muted small mt-3 mb-1"><?=gettext('Use it as a bearer token, for example:')?></p>
		<pre class="fs-console restapi-newkey-example">export FREESENSE_API_KEY='…'
curl -H "Authorization: Bearer $FREESENSE_API_KEY" https://<?=$host?>/api/v1/me</pre>
	</div>
	<div class="panel-footer">
		<button type="button" class="btn btn-sm btn-outline-secondary" id="restapi-newkey-done">
			<i class="fa-solid fa-check icon-embed-btn" aria-hidden="true"></i><?=gettext('Done, I have copied it')?></button>
	</div>
</div>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var field = document.getElementById('newtoken');
	var copy = document.getElementById('copytoken');
	/* Reloading must not post the create form again (that would make another key). */
	if (window.history.replaceState) {
		window.history.replaceState(null, '', window.location.href);
	}
	field.addEventListener('focus', function() { field.select(); });
	copy.addEventListener('click', function() {
		field.select();
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(field.value);
		} else {
			document.execCommand('copy');
		}
		copy.querySelector('i').className = 'fa-solid fa-check icon-embed-btn';
		copy.querySelector('span').textContent = <?=json_encode(gettext('Copied'), JSON_HEX_TAG | JSON_HEX_AMP)?>;
	});
	document.getElementById('restapi-newkey-done').addEventListener('click', function() {
		field.value = '';
		document.getElementById('restapi-newkey').remove();
	});
	field.focus();
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
 * A centered empty state inside a card: icon, one sentence and an optional
 * action (already-escaped HTML, e.g. a button).
 */
function restapi_print_empty_card($icon, $text, $action_html = '') {
?>
<div class="panel panel-default restapi-empty">
	<div class="fs-tool-empty">
		<i class="fa-solid <?=htmlspecialchars($icon)?>" aria-hidden="true"></i>
		<span><?=htmlspecialchars($text)?></span>
		<?=$action_html?>
	</div>
</div>
<?php
}

/* The "Create key" button that opens restapi_print_create_form()'s modal. */
function restapi_create_button($label = null) {
	return '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#restapi-create-modal">' .
	    '<i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i>' . htmlspecialchars($label ?? gettext('Create key')) . '</button>';
}

/*
 * The key list: an fs-table card with search, state and access filters,
 * bulk Revoke / Delete of the selected keys (act=revoke_selected|
 * delete_selected with ids[]) and per-row Revoke and Delete (act=revoke|
 * delete with id). $page handles the actions (restapi_key_action());
 * $show_user adds the owner column with an API access check; $can_add
 * offers "Create key" in the empty state.
 */
function restapi_print_key_table(array $tokens, $show_user, $page, $can_add = true) {
	if (empty($tokens)) {
		restapi_print_empty_card('fa-key', $can_add ? gettext('No API keys yet. A key is shown only once, right after it is created.') :
		    gettext('No API keys yet. Keys can be created once a user has the "WebCfg - System: REST API access" privilege.'),
		    $can_add ? restapi_create_button() : '');
		return;
	}
	$used = restapi_last_used_read();
	$stale_count = 0;
	$rows = array();
	foreach ($tokens as $token) {
		$revoked = !empty($token['revoked']);
		$expired = !empty($token['expires']) && ((int)$token['expires'] < time());
		$owner = restapi_local_user($token['username']);
		$problem = '';
		if ($owner === null) {
			$problem = gettext('User missing or disabled');
		} elseif (!restapi_user_has_access($owner)) {
			$problem = gettext('User has no REST API access');
		}
		if ($revoked) {
			$state = array('revoked', 'block', gettext('Revoked'), sprintf(gettext('Revoked %s'), date('Y-m-d H:i', (int)$token['revoked'])));
		} elseif ($expired) {
			$state = array('expired', 'expired', gettext('Expired'), null);
		} elseif ($problem !== '') {
			$state = array('unusable', 'warn', gettext('Unusable'), $problem);
		} else {
			$state = array('active', 'active', gettext('Active'), null);
		}
		$stale_count += ($state[0] !== 'active') ? 1 : 0;
		$rows[] = array($token, $state, $problem, $used[$token['id']] ?? null);
	}
	$custom = ($stale_count > 0) ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="restapi-keys-stale" ' .
	    'title="' . htmlspecialchars(gettext('Select the revoked, expired and unusable keys')) . '">' .
	    '<i class="fa-solid fa-broom icon-embed-btn" aria-hidden="true"></i>' .
	    htmlspecialchars(sprintf(gettext('Select unusable (%d)'), $stale_count)) . '</button>' : '';
?>
<form method="post" action="<?=htmlspecialchars($page)?>" id="restapi-keys-form">
<div class="panel panel-default fs-table" data-fs-table="restapi-keys">
<?php
	fs_table_toolbar(array(
		'search' => $show_user ? gettext('Search key, user, description or scope…') : gettext('Search key, description or scope…'),
		'filters' => array(
			'state' => array(gettext('All states'), 'active' => gettext('Active'), 'revoked' => gettext('Revoked'),
			    'expired' => gettext('Expired'), 'unusable' => gettext('Unusable')),
			'access' => array(gettext('Any access'), 'ro' => gettext('Read-only'), 'rw' => gettext('Read/write')),
		),
		'custom' => $custom,
		'noun' => gettext('keys'),
		'noun_one' => gettext('key'),
		'bulk' => array(
			array('name' => 'act', 'value' => 'revoke_selected', 'label' => gettext('Revoke'), 'icon' => 'fa-ban',
			    'confirm' => gettext('Revoke the selected API keys? They stop working at once and cannot be re-enabled; they stay listed until deleted.')),
			array('name' => 'act', 'value' => 'delete_selected', 'label' => gettext('Delete'), 'icon' => 'fa-trash-can', 'variant' => 'danger',
			    'confirm' => gettext('Delete the selected API keys? They stop working at once and are removed from the list.')),
		),
	));
?>
	<div class="panel-body table-responsive">
		<table class="table table-hover restapi-keys" data-sortable>
			<thead><tr>
				<th class="fs-col-select" data-sortable="false"><input type="checkbox" class="form-check-input" data-fs-select-all
				    aria-label="<?=gettext('Select all shown keys')?>" /></th>
				<th data-fs-search><?=gettext('Key')?></th>
<?php	if ($show_user): ?>
				<th data-fs-search><?=gettext('User')?></th>
<?php	endif; ?>
				<th><?=gettext('Status')?></th>
				<th data-fs-search><?=gettext('Access')?></th>
				<th><?=gettext('Created')?></th>
				<th><?=gettext('Expires')?></th>
				<th><?=gettext('Last used')?></th>
				<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
			</tr></thead>
			<tbody>
<?php
	foreach ($rows as $row):
		list($token, $state, $problem, $last) = $row;
		$scopes = restapi_token_scopes($token);
		$key_name = RESTAPI_TOKEN_PREFIX . '_' . $token['id'];
		$href = $page . '?id=' . rawurlencode($token['id']) . '&act=';
		$actions = array();
		if ($state[0] !== 'revoked') {
			$actions[] = array('custom', $href . 'revoke', $key_name, array('icon' => 'fa-ban', 'post' => true,
			    'label' => sprintf(gettext('Revoke %s'), $key_name), 'confirm' => sprintf(gettext('Revoke API key “%s”?'), $key_name),
			    'detail' => gettext('It stops working at once and cannot be re-enabled. It stays listed until it is deleted.'),
			    'confirm_action' => gettext('Revoke')));
		}
		$actions[] = array('delete', $href . 'delete', $key_name, array('thing' => gettext('API key'),
		    'detail' => gettext('It stops working at once and is removed from the list.')));
?>
				<tr class="<?=($state[0] !== 'active') ? 'fs-row-disabled' : ''?>" data-fs-filter-state="<?=$state[0]?>"
				    data-fs-filter-access="<?=!empty($token['readonly']) ? 'ro' : 'rw'?>" data-stale="<?=($state[0] !== 'active') ? 1 : 0?>">
					<td><input type="checkbox" class="form-check-input" name="ids[]" value="<?=htmlspecialchars($token['id'])?>" data-fs-select
					    aria-label="<?=htmlspecialchars(sprintf(gettext('Select key %s'), $key_name))?>" /></td>
					<td><span class="fs-mono restapi-key-id"><?=htmlspecialchars($key_name)?></span>
<?php		if (($token['descr'] ?? '') !== ''): ?>
						<div class="small fs-muted"><?=htmlspecialchars($token['descr'])?></div>
<?php		endif; ?>
					</td>
<?php		if ($show_user): ?>
					<td><?=htmlspecialchars($token['username'])?></td>
<?php		endif; ?>
					<td data-value="<?=$state[0]?>"><?=fs_badge($state[1], $state[2], $state[3])?>
<?php		if ($problem !== ''): ?>
						<div class="small fs-muted"><?=htmlspecialchars($problem)?></div>
<?php		endif; ?>
					</td>
					<td data-value="<?=!empty($token['readonly']) ? 0 : 1?>"><div class="fs-chips">
						<span class="fs-chip fs-chip--strong<?=!empty($token['readonly']) ? '' : ' is-warn'?>"><?=!empty($token['readonly']) ? gettext('Read-only') : gettext('Read/write')?></span>
<?php		if (empty($scopes)): ?>
						<span class="fs-chip fs-chip--muted"><?=gettext('All of the user\'s scopes')?></span>
<?php		else:
			foreach ($scopes as $scope): ?>
						<span class="fs-chip fs-chip--mono"><?=htmlspecialchars($scope)?></span>
<?php			endforeach;
		endif; ?>
					</div></td>
					<td class="text-nowrap" data-value="<?=(int)$token['created']?>"><?=htmlspecialchars(date('Y-m-d H:i', (int)$token['created']))?></td>
					<td class="text-nowrap" data-value="<?=empty($token['expires']) ? PHP_INT_MAX : (int)$token['expires']?>"><?=empty($token['expires']) ?
					    '<span class="fs-muted">' . gettext('Never') . '</span>' : htmlspecialchars(date('Y-m-d', (int)$token['expires']))?></td>
					<td class="text-nowrap" data-value="<?=$last ? (int)$last['time'] : 0?>"><?php
		if ($last):
?><?=htmlspecialchars(date('Y-m-d H:i', (int)$last['time']))?>
						<div class="small fs-muted fs-mono"><?=htmlspecialchars($last['ip'])?></div><?php
		else:
?><span class="fs-muted"><?=gettext('Never')?></span><?php
		endif;
?></td>
					<td><?=fs_row_actions($actions)?></td>
				</tr>
<?php	endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
</form>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var form = document.getElementById('restapi-keys-form');
	/* Enter in the search must not submit the form (its first submit button is the bulk Revoke). */
	form.querySelector('[data-fs-search-input]').addEventListener('keydown', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
		}
	});
	var stale = document.getElementById('restapi-keys-stale');
	if (!stale) {
		return;
	}
	/* Select the revoked, expired and unusable keys that are shown, e.g. to delete them together. */
	stale.addEventListener('click', function() {
		form.querySelectorAll('tbody tr[data-stale]').forEach(function(tr) {
			var box = tr.querySelector('[data-fs-select]');
			box.checked = !tr.hidden && tr.dataset.stale === '1';
		});
		form.querySelector('[data-fs-select]').dispatchEvent(new Event('change', {bubbles: true}));
	});
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
				<span class="small fs-muted me-auto"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i><?=gettext('The key is shown only once, right after it is created.')?></span>
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?=gettext('Cancel')?></button>
				<button type="submit" class="btn btn-primary" form="restapi-create-form" name="create" value="create">
					<i class="fa-solid fa-key icon-embed-btn" aria-hidden="true"></i><?=gettext('Create key')?></button>
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
	.restapi-combo .dropdown-item .fa-check { width: 1em; color: var(--fs-coral-text); }
	.restapi-combo .dropdown-item mark { padding: 0; background: none; color: inherit; font-weight: 700; text-decoration: underline; }
	.restapi-combo .dropdown-item.active mark { color: inherit; }
	#restapi-scope-table { max-height: 26rem; overflow-y: auto; border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); }
	#restapi-scope-table thead th { position: sticky; top: 0; background: var(--fs-surface-raised); z-index: 1; }
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

/* Styles the REST API pages share: the new-key card, empty states, key and listener lists and the modals. */
function restapi_print_shared_styles() {
?>
<style>
	.restapi-empty .fs-tool-empty { min-height: 12rem; text-align: center; }
	.restapi-empty .fs-tool-empty > .btn { margin-top: var(--fs-sp-2); }
	.restapi-newkey { border-color: color-mix(in srgb, var(--fs-pass) 55%, var(--fs-border)); }
	.restapi-newkey .panel-title > i { color: var(--fs-pass); margin-right: .5rem; }
	.restapi-newkey-warn { display: flex; gap: .5rem; align-items: baseline; }
	.restapi-newkey-warn > i { color: var(--fs-warn); }
	.restapi-newkey .input-group { max-width: 48rem; }
	.restapi-newkey-example { max-width: 48rem; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
	.restapi-keys .fs-chips, .restapi-listeners .fs-chips { max-width: 26rem; }
	.restapi-key-id { color: var(--fs-text-strong); }
	.fs-row-disabled .restapi-key-id { text-decoration: line-through; color: var(--fs-text-muted); }
	.restapi-note { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
	.restapi-note p:last-child { margin-bottom: 0; }
	.restapi-modal .modal-header { gap: .85rem; align-items: center; }
	.restapi-modal .panel { border: 0; box-shadow: none; margin: 0; background: transparent; }
	.restapi-modal-icon { width: 2.5rem; height: 2.5rem; flex: none; border-radius: var(--fs-r-md); display: grid; place-items: center;
	    background: var(--fs-accent-tint); color: var(--fs-coral-text); font-size: 1.1rem; }
</style>
<?php
}
