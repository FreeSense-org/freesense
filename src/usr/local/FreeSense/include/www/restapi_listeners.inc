<?php
/*
 * restapi_listeners.inc
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
 * System > REST API > Listeners: the listener list and its add/edit modal.
 * The page (system_restapi.php) handles the actions with restapi_listener_action().
 */

require_once('restapi_listener.inc');

/* The badge for a listener state: array(class, icon, label). */
function restapi_listener_state_badge($state) {
	$states = array(
		'running' => array('text-bg-success', 'fa-circle-check', gettext('Running')),
		'no_address' => array('text-bg-warning', 'fa-hourglass-half', gettext('Waiting for address')),
		'no_certificate' => array('text-bg-danger', 'fa-certificate', gettext('No certificate')),
		'error' => array('text-bg-danger', 'fa-triangle-exclamation', gettext('Error')),
		'stopped' => array('text-bg-danger', 'fa-circle-stop', gettext('Stopped')),
		'disabled' => array('text-bg-secondary', 'fa-circle-pause', gettext('Disabled')),
		'api_disabled' => array('text-bg-secondary', 'fa-power-off', gettext('API disabled')),
	);
	return $states[$state] ?? array('text-bg-secondary', 'fa-circle-question', gettext('Not started'));
}

/*
 * The page's listener actions: act=listener_save (id empty to add),
 * listener_delete, listener_enable, listener_disable (id) and
 * listener_restart. Returns array($savemsg, $input_errors, $form_errors,
 * $form_post), or null when $post holds no listener action.
 */
function restapi_listener_action(array $post) {
	$act = (string)($post['act'] ?? '');
	$id = (string)($post['id'] ?? '');
	switch ($act) {
	case 'listener_save':
		if (($id !== '') && (restapi_listener_find($id)[0] === null)) {
			return array('', array(gettext('The listener no longer exists.')), array(), array());
		}
		$result = restapi_listener_save($post, ($id === '') ? null : $id);
		if (!empty($result['input_errors'])) {
			return array('', array(), $result['input_errors'], $post);
		}
		return array(gettext('The listener was saved and applied.'), array(), array(), array());
	case 'listener_delete':
		return restapi_listener_delete($id) ? array(gettext('The listener was deleted.'), array(), array(), array()) :
		    array('', array(gettext('The listener no longer exists.')), array(), array());
	case 'listener_enable':
	case 'listener_disable':
		return restapi_listener_set_enabled($id, $act === 'listener_enable') ?
		    array(($act === 'listener_enable') ? gettext('The listener was enabled.') : gettext('The listener was disabled.'), array(), array(), array()) :
		    array('', array(gettext('The listener no longer exists.')), array(), array());
	case 'listener_restart':
		return restapi_listeners_configure(true) ? array(gettext('The listeners were restarted.'), array(), array(), array()) :
		    array('', array(gettext('The listeners could not be started; see their state below and the system log.')), array(), array());
	}
	return null;
}

function restapi_print_listeners(array $form_errors = array(), array $form_post = array()) {
	$listeners = restapi_listeners();
	$status = restapi_listener_status();
	$choices = restapi_listener_address_choices();
	$certs = restapi_listener_cert_choices();
	$settings = restapi_settings();
	$page = 'system_restapi.php';
?>
<div class="panel panel-default">
	<div class="panel-heading restapi-keys-toolbar">
		<h2 class="panel-title mb-0 me-auto"><?=gettext('API Listeners')?>
			<span class="badge text-bg-secondary ms-1"><?=count($listeners)?></span></h2>
<?php	if (!empty($listeners)): ?>
		<a class="btn btn-sm btn-outline-secondary" href="<?=$page?>?act=listener_restart" usepost title="<?=gettext('Write the configuration again and restart every listener')?>">
			<i class="fa-solid fa-rotate icon-embed-btn"></i><?=gettext('Restart')?></a>
<?php	endif; ?>
		<button type="button" class="btn btn-sm btn-success" id="restapi-listener-add"><i class="fa-solid fa-plus icon-embed-btn"></i><?=gettext('Add')?></button>
	</div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm align-middle mb-0 restapi-listeners">
				<thead><tr>
					<th><?=gettext('State')?></th>
					<th><?=gettext('Listener')?></th>
					<th><?=gettext('Listens on')?></th>
					<th><?=gettext('Access')?></th>
					<th><?=gettext('Protection')?></th>
					<th class="text-end"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr></thead>
				<tbody>
<?php	if (empty($listeners)): ?>
					<tr><td colspan="6" class="restapi-keys-empty">
						<i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i>
						<div class="fw-semibold"><?=gettext('No API listeners')?></div>
						<div class="small text-muted"><?=gettext('The API is served on the WebGUI port only. Add a listener to serve it on its own port and address, e.g. a public IP, without exposing the WebGUI.')?></div>
					</td></tr>
<?php	endif;
	foreach ($listeners as $l):
		$s = $status[$l['id']] ?? array();
		$state = $l['enable'] ? (string)($s['state'] ?? '') : 'disabled';
		list($cls, $icon, $label) = restapi_listener_state_badge($state);
		$addresses = (array)($s['addresses'] ?? array());
		$own_nets = restapi_parse_networks($l['allowednetworks']);
		$nets = !empty($own_nets) ? $own_nets : restapi_parse_networks($settings['allowednetworks']);
		$href = "{$page}?id=" . htmlspecialchars($l['id']) . '&amp;act=';
?>
					<tr class="<?=$l['enable'] ? '' : 'restapi-stale'?>" data-listener="<?=htmlspecialchars(json_encode($l))?>">
						<td><span class="badge <?=$cls?>"<?=!empty($s['error']) ? ' title="' . htmlspecialchars($s['error']) . '"' : ''?>>
							<i class="fa-solid <?=$icon?>"></i> <?=$label?></span>
<?php		if (!empty($s['error'])): ?>
							<div class="small text-danger mt-1 restapi-listener-error"><?=htmlspecialchars($s['error'])?></div>
<?php		endif; ?>
						</td>
						<td><div class="fw-semibold"><?=htmlspecialchars($l['descr'] !== '' ? $l['descr'] : gettext('API listener'))?></div>
							<div class="small text-muted"><?=htmlspecialchars($certs[$l['certref']] ?? gettext('(certificate missing)'))?></div></td>
						<td><div><?=htmlspecialchars($choices[$l['address']] ?? $l['address'])?> <span class="badge fx-port">:<?=(int)$l['port']?></span></div>
							<div class="small text-muted font-monospace"><?=empty($addresses) ? gettext('no address') :
							    htmlspecialchars(implode(', ', array_map(function ($a) use ($l) {
								return (is_ipaddrv6($a) ? "[{$a}]" : $a) . ':' . $l['port'];
							    }, $addresses)))?></div></td>
						<td><?=$l['readonly'] ? '<span class="badge text-bg-secondary">' . gettext('Read-only') . '</span>' :
						    '<span class="badge text-bg-warning">' . gettext('Read/write') . '</span>'?>
							<div class="small text-muted"><?=empty($nets) ? gettext('from any address') :
							    htmlspecialchars(sprintf(gettext('from %s'), implode(', ', $nets)))?><?=empty($own_nets) && !empty($nets) ?
							    ' <span class="text-muted">(' . gettext('general') . ')</span>' : ''?></div></td>
						<td class="small"><div><i class="fa-solid fa-gauge-high fa-fw text-muted"></i> <?=($l['ratelimit'] > 0) ?
						    htmlspecialchars(sprintf(gettext('%d requests/s per client'), $l['ratelimit'])) : gettext('no rate limit')?></div>
							<div><i class="fa-solid fa-shield-halved fa-fw text-muted"></i> <?=$l['firewall'] ? gettext('firewall rule added') :
							    gettext('own firewall rule needed')?></div></td>
						<td class="text-end text-nowrap restapi-row-actions">
							<button type="button" class="btn btn-sm btn-outline-secondary restapi-listener-edit" title="<?=gettext('Edit this listener')?>"
							    aria-label="<?=gettext('Edit this listener')?>"><i class="fa-solid fa-pencil"></i></button>
							<a class="btn btn-sm btn-outline-secondary" href="<?=$href . ($l['enable'] ? 'listener_disable' : 'listener_enable')?>" usepost
							    title="<?=$l['enable'] ? gettext('Disable this listener') : gettext('Enable this listener')?>"
							    aria-label="<?=$l['enable'] ? gettext('Disable this listener') : gettext('Enable this listener')?>"><i class="fa-solid <?=$l['enable'] ? 'fa-ban' : 'fa-circle-check'?>"></i></a>
							<a class="btn btn-sm btn-outline-danger do-confirm" href="<?=$href?>listener_delete" usepost title="<?=gettext('Delete this listener')?>"
							    aria-label="<?=gettext('Delete this listener')?>"><i class="fa-solid fa-trash-can"></i></a>
						</td>
					</tr>
<?php	endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php
	print_info_box(gettext('A listener serves only /api/ (every other path answers 404), over HTTPS, on the addresses of one interface or ' .
	    'virtual IP, in its own web server process: the WebGUI is not reachable on it. Before exposing it to the internet, prefer a ' .
	    'read-only listener, limit the allowed networks, use a trusted certificate (e.g. from the ACME package) and keep a rate limit. ' .
	    'Clients that fail to authenticate are blocked by login protection, as on the WebGUI.'), 'info', false);
	restapi_print_listener_modal($choices, $certs, $form_errors, $form_post, $settings);
}

function restapi_print_listener_modal(array $choices, array $certs, array $errors, array $post, array $settings) {
	$defaults = array('id' => '', 'enable' => true, 'descr' => '', 'address' => isset($choices['wan']) ? 'wan' : (string)array_key_first($choices),
	    'port' => RESTAPI_LISTENER_DEFAULT_PORT, 'certref' => (string)config_get_path('system/webgui/ssl-certref', ''),
	    'readonly' => true, 'allowednetworks' => '', 'ratelimit' => RESTAPI_LISTENER_DEFAULT_RATE, 'firewall' => true);
	$v = empty($post) ? $defaults : array_merge($defaults, array(
		'id' => (string)($post['id'] ?? ''), 'enable' => !empty($post['enable']), 'descr' => (string)($post['descr'] ?? ''),
		'address' => (string)($post['address'] ?? ''), 'port' => (string)($post['port'] ?? ''), 'certref' => (string)($post['certref'] ?? ''),
		'readonly' => !empty($post['readonly']), 'allowednetworks' => (string)($post['allowednetworks'] ?? ''),
		'ratelimit' => (string)($post['ratelimit'] ?? ''), 'firewall' => !empty($post['firewall'])));

	$form = new Form(false);
	$form->setAttribute('id', 'restapi-listener-form');
	$section = new Form_Section('NOTITLE');
	$section->addInput(new Form_Checkbox('enable', gettext('Enable'), gettext('Serve the API on this listener'), $v['enable']));
	$section->addInput(new Form_Input('descr', gettext('Description'), 'text', $v['descr'],
	    array('placeholder' => gettext('e.g. Public API for monitoring'), 'maxlength' => 80)));
	$section->addInput(new Form_Select('address', '*' . gettext('Listen on'), $v['address'], $choices))
	    ->setHelp(gettext('An interface (its current IPv4 and IPv6 address, followed when it changes) or a virtual IP. ' .
	    'The listener binds only these addresses.'));
	$section->addInput(new Form_Input('port', '*' . gettext('Port'), 'number', $v['port'],
	    array('min' => 1, 'max' => 65535, 'inputmode' => 'numeric')))
	    ->setHelp(sprintf(gettext('HTTPS only. It may not be a WebGUI or SSH port (%s).'), implode(', ', restapi_listener_reserved_ports())));
	$section->addInput(new Form_Select('certref', '*' . gettext('Certificate'), $v['certref'], $certs))
	    ->setHelp(gettext('Clients see this certificate. Renewing it (e.g. by ACME) restarts the listeners.'));
	$section->addInput(new Form_Checkbox('readonly', gettext('Read-only'), gettext('Only allow requests that do not change anything'), $v['readonly']))
	    ->setHelp(gettext('Recommended for a listener reachable from the internet: changes then need the WebGUI port or another listener.'));
	$section->addInput(new Form_Input('allowednetworks', gettext('Allowed networks'), 'text', $v['allowednetworks'],
	    array('placeholder' => ($settings['allowednetworks'] !== '') ? $settings['allowednetworks'] : '198.51.100.0/24 2001:db8::/48')))
	    ->setHelp(($settings['allowednetworks'] !== '') ?
	    sprintf(gettext('Addresses or networks, separated by spaces. Empty uses the general allowed networks (%s).'), htmlspecialchars($settings['allowednetworks'])) :
	    gettext('Addresses or networks, separated by spaces. Empty allows any address (the general setting is empty too).'));
	$section->addInput(new Form_Input('ratelimit', gettext('Rate limit'), 'number', $v['ratelimit'],
	    array('min' => 0, 'max' => 1000, 'inputmode' => 'numeric')))
	    ->setHelp(gettext('Requests per second per client address (bursts up to twice that); more get 429. 0 turns it off.'));
	$section->addInput(new Form_Checkbox('firewall', gettext('Firewall rule'), gettext('Pass this port on the interface automatically'), $v['firewall']))
	    ->setHelp(gettext('Adds a pass rule for the port from the allowed networks, before the interface rules. ' .
	    'Without it, add a rule in Firewall > Rules yourself.'));
	$form->add($section);
	$form->addGlobal(new Form_Input('id', null, 'hidden', $v['id']));
?>
<div class="modal fade restapi-modal" id="restapi-listener-modal" tabindex="-1" aria-labelledby="restapi-listener-title" aria-hidden="true"
    data-open="<?=empty($errors) ? '0' : '1'?>">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<div class="restapi-modal-icon" aria-hidden="true"><i class="fa-solid fa-tower-broadcast"></i></div>
				<div class="me-auto">
					<h2 class="modal-title h5 mb-0" id="restapi-listener-title"
					    data-add="<?=gettext('Add API Listener')?>" data-edit="<?=gettext('Edit API Listener')?>"><?=($v['id'] === '') ? gettext('Add API Listener') : gettext('Edit API Listener')?></h2>
					<div class="small text-muted"><?=gettext('An HTTPS port that serves only the REST API.')?></div>
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
				<span class="small text-muted me-auto"><i class="fa-solid fa-circle-info me-1"></i><?=gettext('Saving applies the listener and the firewall rules at once.')?></span>
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?=gettext('Cancel')?></button>
				<button type="submit" class="btn btn-success" form="restapi-listener-form" name="act" value="listener_save">
					<i class="fa-solid fa-floppy-disk icon-embed-btn"></i><?=gettext('Save')?></button>
			</div>
		</div>
	</div>
</div>
<style>
	.fx-port { background: var(--bs-tertiary-bg); color: var(--bs-emphasis-color); border: 1px solid var(--bs-border-color); font-family: var(--bs-font-monospace); }
	.restapi-listener-error { max-width: 22rem; }
</style>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var modal = document.getElementById('restapi-listener-modal');
	var form = document.getElementById('restapi-listener-form');
	var title = document.getElementById('restapi-listener-title');
	var defaults = <?=json_encode($defaults, JSON_HEX_TAG | JSON_HEX_AMP)?>;
	/* Fill the form from a listener (or the defaults) and open the modal. */
	function open(l) {
		Object.keys(defaults).forEach(function(k) {
			var field = form.elements[k];
			if (!field) {
				return;
			}
			if (field.type === 'checkbox') {
				field.checked = !!l[k];
			} else {
				field.value = (l[k] === undefined || l[k] === null) ? '' : l[k];
			}
		});
		title.textContent = l.id ? title.dataset.edit : title.dataset.add;
		modal.querySelectorAll('.alert-danger').forEach(function(a) { a.remove(); });
		bootstrap.Modal.getOrCreateInstance(modal).show();
	}
	document.getElementById('restapi-listener-add').addEventListener('click', function() { open(defaults); });
	document.querySelectorAll('.restapi-listener-edit').forEach(function(b) {
		b.addEventListener('click', function() { open(JSON.parse(b.closest('tr').dataset.listener)); });
	});
	modal.addEventListener('shown.bs.modal', function() { form.elements.descr.focus(); });
	if (modal.dataset.open === '1') {
		bootstrap.Modal.getOrCreateInstance(modal).show();
	}
});
//]]>
</script>
<?php
}
