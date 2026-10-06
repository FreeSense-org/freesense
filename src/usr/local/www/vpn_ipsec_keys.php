<?php
/*
 * vpn_ipsec_keys.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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
##|*IDENT=page-vpn-ipsec-listkeys
##|*NAME=VPN: IPsec: Pre-Shared Keys List
##|*DESCR=Allow access to the 'VPN: IPsec: Pre-Shared Keys List' page.
##|*MATCH=vpn_ipsec_keys.php*
##|-PRIV

require_once("functions.inc");
require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("filter.inc");
require_once("vpn_ipsec.inc");

$userkeys = array();
foreach (config_get_path('system/user', []) as $id => $user) {
	if (!empty($user['ipsecpsk'])) {
		$userkeys[] = array('ident' => $user['name'], 'type' => 'PSK', 'pre-shared-key' => $user['ipsecpsk'], 'id' => $id);;
	}
}

if (isset($_POST['apply'])) {
	$retval = ipsec_apply_changes();
}

if ($_POST['act'] == "del") {
	if (ipsec_psk_delete($_POST['id'])) {
		header("Location: vpn_ipsec_keys.php");
		exit;
	}
}

$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Pre-Shared Keys"));
$pglinks = array("", "vpn_ipsec.php", "@self");
$shortcut_section = "ipsec";

$can_edit = isAllowedPage('vpn_ipsec_keys_edit.php');
if ($can_edit) {
	fs_page_action(gettext('Add key'), 'vpn_ipsec_keys_edit.php', 'fa-plus');
}
include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('ipsec')) {
	print_apply_box(gettext("The IPsec tunnel configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('vpn-ipsec', 'vpn_ipsec_keys.php');

/* user account keys (edited in the user manager) first, then the mobile keys */
$rows = array();
foreach ($userkeys as $secretent) {
	$rows[] = array('source' => 'user', 'ident' => ($secretent['ident'] == 'allusers') ? gettext("ANY USER") : $secretent['ident'],
	    'type' => empty($secretent['type']) ? 'PSK' : $secretent['type'], 'key' => $secretent['pre-shared-key'], 'id' => $secretent['id'], 'ent' => array());
}
foreach (config_get_path('ipsec/mobilekey', []) as $i => $secretent) {
	$rows[] = array('source' => 'key', 'ident' => $secretent['ident'], 'type' => empty($secretent['type']) ? 'PSK' : $secretent['type'],
	    'key' => $secretent['pre-shared-key'], 'id' => $i, 'ent' => $secretent);
}
$ident_types = ipsec_psk_ident_type_list();
?>

<style>
.fs-psk-ident { font-weight: 600; color: var(--fs-text-strong); word-break: break-all; }
.fs-psk-meta { display: flex; flex-wrap: wrap; gap: .15rem .5rem; margin-top: .15rem; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-psk-type { display: inline-block; padding: 0 .45rem; border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); font-size: var(--fs-fs-xs); font-weight: 600; line-height: 1.4rem; }
.fs-psk-type.is-eap { border-color: color-mix(in srgb, var(--fs-info) 50%, transparent); color: var(--fs-info); }
.fs-psk-secret { display: inline-flex; align-items: center; gap: .25rem; max-width: 100%; }
.fs-psk-secret > code { color: var(--fs-text); font-family: var(--fs-font-mono, monospace); font-size: var(--fs-fs-sm); word-break: break-all; }
.fs-psk-reveal { border: 0; background: transparent; }
</style>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Pre-shared keys'),
	'search' => gettext('Search identifiers…'),
	'noun' => gettext('pre-shared keys'),
	'noun_one' => gettext('pre-shared key'),
	'filters' => [
		'type' => [gettext('All types'), 'PSK' => 'PSK', 'EAP' => 'EAP'],
		'source' => [gettext('All sources'), 'key' => gettext('Mobile keys'), 'user' => gettext('User accounts')],
	],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Identifier")?></th>
					<th data-fs-search><?=gettext("Type")?></th>
					<th><?=gettext("Pre-shared key")?></th>
					<th data-fs-search class="d-none d-md-table-cell"><?=gettext("EAP options")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($rows as $row):
	$ent = $row['ent'];
	if ($row['source'] == 'user') {
		$actions = array(['custom', "system_usermanager.php?act=edit&userid=" . urlencode($row['id']), $row['ident'],
		    ['icon' => 'fa-solid fa-user-pen', 'label' => sprintf(gettext('Edit user %s'), $row['ident'])]]);
	} else {
		$actions = array();
		if ($can_edit) {
			$actions[] = ['edit', "vpn_ipsec_keys_edit.php?id={$row['id']}", $row['ident']];
		}
		$actions[] = ['delete', "vpn_ipsec_keys.php?act=del&id={$row['id']}", $row['ident'], ['thing' => gettext('pre-shared key')]];
	}
	$eap = array();
	if (($row['type'] == 'EAP') && !empty($ent)) {
		if (!empty($ent['ident_type'])) {
			$eap[] = htmlspecialchars($ident_types[$ent['ident_type']] ?? $ent['ident_type']);
		}
		if (!empty($ent['pool_address'])) {
			$eap[] = sprintf(gettext('Pool %s'), '<span class="fs-mono">' . htmlspecialchars($ent['pool_address'] . (strlen((string)$ent['pool_netbits']) ? '/' . $ent['pool_netbits'] : '')) . '</span>');
		}
		if (!empty($ent['dns_address'])) {
			$eap[] = sprintf(gettext('DNS %s'), '<span class="fs-mono">' . htmlspecialchars($ent['dns_address']) . '</span>');
		}
	}
	$show_label = sprintf(gettext('Show the key of %s'), $row['ident']);
?>
				<tr data-fs-filter-type="<?=htmlspecialchars($row['type'])?>" data-fs-filter-source="<?=$row['source']?>">
					<td>
						<span class="fs-psk-ident"><?=htmlspecialchars($row['ident'])?></span>
						<div class="fs-psk-meta"><span><?=($row['source'] == 'user') ? gettext('User account') : gettext('Mobile key')?></span></div>
					</td>
					<td><span class="fs-psk-type<?=($row['type'] == 'EAP') ? ' is-eap' : ''?>"><?=htmlspecialchars($row['type'])?></span></td>
					<td>
						<span class="fs-psk-secret">
							<code data-fs-secret="<?=htmlspecialchars($row['key'])?>">••••••••</code>
							<button type="button" class="fs-action fs-psk-reveal" aria-pressed="false" title="<?=htmlspecialchars($show_label)?>" aria-label="<?=htmlspecialchars($show_label)?>"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
						</span>
					</td>
					<td class="d-none d-md-table-cell small"><?=$eap ? implode('<br>', $eap) : '<span class="fs-muted">' . gettext('none') . '</span>'?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($rows)) {
	fs_empty_row(5, gettext('No pre-shared keys yet.'), $can_edit ? 'vpn_ipsec_keys_edit.php' : null, $can_edit ? gettext('Add key') : null);
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('An identifier of "any" sets the key for any user. Keys stored on a user account are edited in the user manager.')?>
	</div>
</div>

<script>
//<![CDATA[
(function () {
	/* keys are masked until shown; textContent only, never parsed as HTML */
	document.querySelectorAll('.fs-psk-reveal').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var code = btn.parentNode.querySelector('code');
			var show = btn.getAttribute('aria-pressed') !== 'true';
			code.textContent = show ? code.getAttribute('data-fs-secret') : '••••••••';
			btn.setAttribute('aria-pressed', show ? 'true' : 'false');
			btn.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
		});
	});
})();
//]]>
</script>
<?php include("foot.inc"); ?>
