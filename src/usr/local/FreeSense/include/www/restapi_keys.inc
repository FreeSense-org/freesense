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
 * The key table. $show_user adds the owner column with an API access
 * check; $revoke_page is the page that handles ?act=revoke; $footer is
 * HTML for the panel footer (e.g. "Revoke all").
 */
function restapi_print_key_table(array $tokens, $show_user, $revoke_page, $footer = '') {
	$used = restapi_last_used_read();
	$cols = $show_user ? 7 : 6;
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('API Keys')?>
		<span class="badge text-bg-secondary ms-1"><?=count($tokens)?></span></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm align-middle mb-0 restapi-keys">
				<thead><tr>
					<th><?=gettext('Key')?></th>
<?php	if ($show_user): ?>
					<th><?=gettext('User')?></th>
<?php	endif; ?>
					<th><?=gettext('Access')?></th>
					<th><?=gettext('Created')?></th>
					<th><?=gettext('Expires')?></th>
					<th><?=gettext('Last used')?></th>
					<th class="text-end"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr></thead>
				<tbody>
<?php	if (empty($tokens)): ?>
					<tr><td colspan="<?=$cols?>" class="text-muted py-3"><?=gettext('No API keys have been created.')?></td></tr>
<?php	endif;
	foreach ($tokens as $token):
		$expired = !empty($token['expires']) && ((int)$token['expires'] < time());
		$owner = restapi_local_user($token['username']);
		$problem = '';
		if ($owner === null) {
			$problem = gettext('user missing or disabled');
		} elseif (!restapi_user_has_access($owner)) {
			$problem = gettext('user has no REST API access');
		}
		$last = $used[$token['id']] ?? null;
		$scopes = restapi_token_scopes($token);
?>
					<tr<?=($expired || $problem !== '') ? ' class="disabled"' : ''?>>
						<td><code><?=htmlspecialchars(RESTAPI_TOKEN_PREFIX . '_' . $token['id'])?></code>
<?php		if (($token['descr'] ?? '') !== ''): ?>
							<div class="small text-muted"><?=htmlspecialchars($token['descr'])?></div>
<?php		endif; ?>
						</td>
<?php		if ($show_user): ?>
						<td><?=htmlspecialchars($token['username'])?><?=($problem !== '') ?
						    '<div class="small text-danger"><i class="fa-solid fa-triangle-exclamation"></i> ' . htmlspecialchars($problem) . '</div>' : ''?></td>
<?php		endif; ?>
						<td><?=!empty($token['readonly']) ? '<span class="badge text-bg-secondary">' . gettext('Read-only') . '</span>' :
						    '<span class="badge text-bg-warning">' . gettext('Read/write') . '</span>'?>
							<div class="small text-muted restapi-scopes"><?=empty($scopes) ? gettext('All of the user\'s scopes') :
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
						<td class="text-end">
							<a class="btn btn-sm btn-outline-danger do-confirm" title="<?=gettext('Revoke this API key')?>"
							    href="<?=htmlspecialchars($revoke_page)?>?act=revoke&amp;id=<?=htmlspecialchars($token['id'])?>" usepost><i class="fa-solid fa-trash-can"></i></a>
						</td>
					</tr>
<?php	endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php	if ($footer !== ''): ?>
	<div class="panel-footer"><?=$footer?></div>
<?php	endif; ?>
</div>
<?php
}

/* At most this many users are listed by the user lookup; typing narrows the list. */
define('RESTAPI_USER_LOOKUP_LIMIT', 15);

/*
 * The create form. $users maps username => label; with one entry and
 * $fixed_user set, the owner is shown instead of a selector. The user
 * selector is a plain <select> that the page turns into a type-ahead lookup.
 */
function restapi_print_create_form(array $users, array $create, $fixed_user = null) {
	$form = new Form(false);
	$section = new Form_Section(gettext('Create API Key'));
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
	$section->addInput(new Form_Button(
		'create',
		gettext('Create key'),
		null,
		'fa-solid fa-key'
	))->addClass('btn-success');
	$form->add($section);
	print($form);
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
}
