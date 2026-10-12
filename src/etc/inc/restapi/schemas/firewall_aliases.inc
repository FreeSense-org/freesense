<?php
/*
 * firewall_aliases.inc
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

/* Schema of an alias (GET/POST/PUT /api/v1/firewall/aliases). Field names match the API. */
function restapi_schema_firewall_aliases() {
	$urltypes = array('url', 'url_ports', 'urltable', 'urltable_ports');
	return array(
		'title' => gettext('Alias'),
		'summary' => gettext('{type} alias {name}[ with {entries}]'),
		'summaryIcon' => 'tags',
		'sections' => array(
			array('id' => 'general', 'title' => gettext('General'), 'fields' => array(
				array('name' => 'name', 'type' => 'text', 'label' => gettext('Name'), 'required' => true, 'mono' => true, 'width' => 'half',
				    'maxLength' => 31, 'pattern' => '^[A-Za-z0-9_]+$',
				    'patternMessage' => gettext('Use letters, digits and underscores only.'),
				    'help' => gettext('Used in rules instead of the addresses or ports it contains.')),
				array('name' => 'type', 'type' => 'select', 'label' => gettext('Type'), 'required' => true, 'width' => 'half', 'default' => 'host',
				    'options' => array(
					array('value' => 'host', 'label' => gettext('Host(s)'), 'group' => gettext('Addresses')),
					array('value' => 'network', 'label' => gettext('Network(s)'), 'group' => gettext('Addresses')),
					array('value' => 'port', 'label' => gettext('Port(s)'), 'group' => gettext('Ports')),
					array('value' => 'url', 'label' => gettext('URL (IPs)'), 'group' => gettext('Downloaded lists')),
					array('value' => 'url_ports', 'label' => gettext('URL (Ports)'), 'group' => gettext('Downloaded lists')),
					array('value' => 'urltable', 'label' => gettext('URL Table (IPs)'), 'group' => gettext('Downloaded lists')),
					array('value' => 'urltable_ports', 'label' => gettext('URL Table (Ports)'), 'group' => gettext('Downloaded lists')),
				    )),
				array('name' => 'description', 'type' => 'text', 'label' => gettext('Description'), 'maxLength' => 200,
				    'help' => gettext('For your reference (not parsed).')),
			)),
			array('id' => 'entries', 'title' => gettext('Entries'),
			    'description' => gettext('Hosts, networks (CIDR), ranges (a-b), ports, port ranges (a:b), other aliases, or URLs for downloaded lists.'),
			    'fields' => array(
				array('name' => 'entries', 'type' => 'entry-grid', 'label' => gettext('Entries'), 'min' => 0, 'max' => 5000, 'reorder' => true,
				    'addLabel' => gettext('Add entry'), 'emptyText' => gettext('No entries yet.'),
				    'fields' => array(
					array('name' => 'address', 'type' => 'text', 'label' => gettext('Entry'), 'mono' => true, 'required' => true, 'width' => 'lg'),
					array('name' => 'detail', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'lg'),
				    )),
				array('name' => 'update_frequency', 'type' => 'number', 'label' => gettext('Update frequency'), 'min' => 1, 'max' => 365,
				    'unit' => gettext('days'), 'width' => 'third', 'visibleWhen' => array('field' => 'type', 'in' => array('urltable', 'urltable_ports')),
				    'help' => gettext('How often the list is downloaded again.')),
			)),
		),
	);
}
