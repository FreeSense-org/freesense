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
 * check; $revoke_page is the page that handles ?act=revoke.
 */
function restapi_print_key_table(array $tokens, $show_user, $revoke_page) {
	$used = restapi_last_used_read();
	$cols = $show_user ? 8 : 7;
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('API Keys')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm">
				<thead><tr>
					<th><?=gettext('Key ID')?></th>
<?php	if ($show_user): ?>
					<th><?=gettext('User')?></th>
<?php	endif; ?>
					<th><?=gettext('Description')?></th>
					<th><?=gettext('Access')?></th>
					<th><?=gettext('Created')?></th>
					<th><?=gettext('Expires')?></th>
					<th><?=gettext('Last used')?></th>
					<th><?=gettext('Actions')?></th>
				</tr></thead>
				<tbody>
<?php	if (empty($tokens)): ?>
					<tr><td colspan="<?=$cols?>"><?=gettext('No API keys have been created.')?></td></tr>
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
?>
					<tr<?=($expired || $problem !== '') ? ' class="disabled"' : ''?>>
						<td><code><?=htmlspecialchars(RESTAPI_TOKEN_PREFIX . '_' . $token['id'])?></code></td>
<?php		if ($show_user): ?>
						<td><?=htmlspecialchars($token['username'])?><?=($problem !== '') ?
						    '<br /><small class="text-danger">' . htmlspecialchars($problem) . '</small>' : ''?></td>
<?php		endif; ?>
						<td><?=htmlspecialchars($token['descr'] ?? '')?></td>
						<td><?=!empty($token['readonly']) ? gettext('Read-only') : gettext('Read/write')?></td>
						<td><?=htmlspecialchars(date('Y-m-d H:i', (int)$token['created']))?></td>
						<td><?=empty($token['expires']) ? gettext('Never') : ($expired ? '<span class="text-danger">' : '') .
						    htmlspecialchars(date('Y-m-d', (int)$token['expires'])) . ($expired ? ' (' . gettext('expired') . ')</span>' : '')?></td>
						<td><?=$last ? htmlspecialchars(date('Y-m-d H:i', (int)$last['time']) . ' ' . gettext('from') . ' ' . $last['ip']) : gettext('Never')?></td>
						<td>
							<a class="fa-solid fa-trash-can" title="<?=gettext('Revoke this API key')?>" href="<?=htmlspecialchars($revoke_page)?>?act=revoke&amp;id=<?=htmlspecialchars($token['id'])?>" usepost></a>
						</td>
					</tr>
<?php	endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
}

/*
 * The create form. $users maps username => label; with one entry and
 * $fixed_user set, the owner is shown instead of a selector.
 */
function restapi_print_create_form(array $users, array $create, $fixed_user = null) {
	$form = new Form(false);
	$section = new Form_Section(gettext('Create API Key'));
	if ($fixed_user !== null) {
		$section->addInput(new Form_StaticText(gettext('User'), htmlspecialchars($fixed_user)));
	} else {
		$section->addInput(new Form_Select(
			'username',
			'*' . gettext('User'),
			$create['username'] ?? '',
			$users
		))->setHelp(gettext('The key acts as this local user and gets exactly the user\'s GUI privileges. ' .
		    'Only users with the "WebCfg - System: REST API access" privilege are listed.'));
	}
	$section->addInput(new Form_Input(
		'descr',
		'*' . gettext('Description'),
		'text',
		$create['descr'] ?? '',
		array('placeholder' => gettext('e.g. Ansible on mgmt01'), 'maxlength' => 120)
	));
	$section->addInput(new Form_Checkbox(
		'readonly',
		gettext('Read-only'),
		gettext('Only allow requests that do not change anything'),
		!empty($create['readonly'])
	));
	$section->addInput(new Form_Input(
		'expires_days',
		gettext('Expires after (days)'),
		'number',
		$create['expires_days'] ?? '',
		array('min' => 1, 'max' => 3650, 'placeholder' => gettext('never'))
	));
	$section->addInput(new Form_Button(
		'create',
		gettext('Create key'),
		null,
		'fa-solid fa-key'
	))->addClass('btn-success');
	$form->add($section);
	print($form);
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
	return $users;
}
