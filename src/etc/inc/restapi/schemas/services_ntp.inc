<?php
/*
 * services_ntp.inc
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
 * Schema of the NTP server settings (GET/PUT /api/v1/services/ntp). Field
 * names match the API. Choices come from the same functions the GUI page and
 * the API use, so the form offers exactly what the backend accepts.
 */
function restapi_schema_services_ntp() {
	$choices = function ($pairs) {
		$out = array();
		foreach ((array)$pairs as $value => $label) {
			$out[] = array('value' => (string)$value, 'label' => (string)$label);
		}
		return $out;
	};
	$ifaces = function_exists('ntpd_build_interface_list') ? $choices(ntpd_build_interface_list(array())['options'] ?? array()) : array();
	$poll = function_exists('system_ntp_poll_values') ? $choices(system_ntp_poll_values()) : array();
	$resolv = function_exists('ntpd_dnsresolv_choices') ? $choices(ntpd_dnsresolv_choices()) : array();
	global $ntp_auth_halgos;
	$algos = $choices(is_array($ntp_auth_halgos ?? null) ? $ntp_auth_halgos : array('md5' => 'MD5', 'sha1' => 'SHA1', 'sha256' => 'SHA256'));

	return array(
		'title' => gettext('NTP server'),
		'order' => array('general', 'servers', 'logging', 'auth', 'advanced'),
		'sections' => array(
			array('id' => 'general', 'title' => gettext('General'), 'fields' => array(
				array('name' => 'enable', 'type' => 'switch', 'label' => gettext('NTP server'), 'text' => gettext('Serve time to clients')),
				array('name' => 'interface', 'type' => 'checklist', 'label' => gettext('Interfaces'), 'options' => $ifaces,
				    'help' => gettext('Listen on these interfaces. None selected: all interfaces.')),
			)),
			array('id' => 'servers', 'title' => gettext('Time servers'),
			    'description' => gettext('Up to 10 servers, pools or peers. Without any, pool.ntp.org is used.'), 'fields' => array(
				array('name' => 'servers', 'type' => 'entry-grid', 'label' => gettext('Time servers'), 'max' => 10, 'reorder' => true,
				    'errorMatch' => array('time server', 'time servers', 'server names'),
				    'addLabel' => gettext('Add server'), 'fields' => array(
					array('name' => 'server', 'type' => 'text', 'label' => gettext('Server'), 'mono' => true, 'required' => true, 'width' => 'lg'),
					array('name' => 'type', 'type' => 'select', 'label' => gettext('Type'), 'default' => 'server', 'width' => 'sm', 'options' => array(
						array('value' => 'server', 'label' => gettext('Server')),
						array('value' => 'pool', 'label' => gettext('Pool')),
						array('value' => 'peer', 'label' => gettext('Peer')),
					)),
					array('name' => 'prefer', 'type' => 'switch', 'label' => gettext('Prefer'), 'width' => 'xs'),
					array('name' => 'noselect', 'type' => 'switch', 'label' => gettext('No select'), 'width' => 'xs'),
				    )),
			)),
			array('id' => 'logging', 'title' => gettext('Logging and graphs'), 'fields' => array(
				array('name' => 'statsgraph', 'type' => 'switch', 'label' => gettext('RRD graphs'), 'text' => gettext('Graph NTP statistics')),
				array('name' => 'logpeer', 'type' => 'switch', 'label' => gettext('Log peer messages')),
				array('name' => 'logsys', 'type' => 'switch', 'label' => gettext('Log system messages')),
				array('name' => 'clockstats', 'type' => 'switch', 'label' => gettext('Clock statistics')),
				array('name' => 'loopstats', 'type' => 'switch', 'label' => gettext('Loop statistics')),
				array('name' => 'peerstats', 'type' => 'switch', 'label' => gettext('Peer statistics')),
			)),
			array('id' => 'auth', 'title' => gettext('Authentication'), 'advanced' => true, 'fields' => array(
				array('name' => 'serverauth', 'type' => 'switch', 'label' => gettext('Require authentication'), 'text' => gettext('Clients must use the key')),
				array('name' => 'serverauthkeyid', 'type' => 'number', 'label' => gettext('Key ID'), 'min' => 1, 'max' => 65535, 'width' => 'third',
				    'visibleWhen' => array('field' => 'serverauth', 'truthy' => true)),
				array('name' => 'serverauthalgo', 'type' => 'select', 'label' => gettext('Digest algorithm'), 'options' => $algos, 'width' => 'third',
				    'visibleWhen' => array('field' => 'serverauth', 'truthy' => true)),
				array('name' => 'serverauthkey', 'type' => 'secret', 'label' => gettext('Key'), 'mono' => true,
				    'visibleWhen' => array('field' => 'serverauth', 'truthy' => true),
				    'help' => gettext('Shown as "(set)" when a key exists; leave it to keep the key.')),
			)),
			array('id' => 'advanced', 'title' => gettext('Advanced'), 'advanced' => true, 'fields' => array(
				array('name' => 'ntpmaxpeers', 'type' => 'number', 'label' => gettext('Maximum peers'), 'min' => 4, 'max' => 25, 'width' => 'third',
				    'errorMatch' => array('max peers', 'maximum peers')),
				array('name' => 'ntporphan', 'type' => 'number', 'label' => gettext('Orphan mode stratum'), 'min' => 1, 'max' => 15, 'width' => 'third',
				    'errorMatch' => array('orphan mode')),
				array('name' => 'ntpminpoll', 'type' => 'select', 'label' => gettext('Minimum poll interval'), 'options' => $poll, 'width' => 'third',
				    'errorMatch' => array('minimum poll')),
				array('name' => 'ntpmaxpoll', 'type' => 'select', 'label' => gettext('Maximum poll interval'), 'options' => $poll, 'width' => 'third',
				    'errorMatch' => array('maximum poll')),
				array('name' => 'dnsresolv', 'type' => 'segmented', 'label' => gettext('DNS resolution'), 'options' => $resolv, 'width' => 'two-thirds'),
				array('name' => 'leaptext', 'type' => 'textarea', 'label' => gettext('Leap seconds'), 'code' => true, 'rows' => 6,
				    'help' => gettext('A leap seconds file, usually not needed.')),
			)),
		),
	);
}
