<?php
/*
 * firewall_schedules.inc
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
 * Schema of a schedule (GET /api/v1/firewall/schedules/{name} "fields",
 * POST/PUT /api/v1/firewall/schedules). A schedule is a list of time
 * ranges; each range applies on some weekdays, or on some dates (month and
 * day, every year), between a start and a stop time. The 1.x editor picks
 * them on a calendar; here a range is one row: weekdays as a checklist,
 * dates as text ("12-24, 12-25"), start and stop as times. A row has
 * weekdays or dates, never both, as the 1.x editor builds them.
 */

/* Weekday numbers as schedules store them (1 = Monday). */
function restapi_sched_weekdays() {
	return array('1' => gettext('Mon'), '2' => gettext('Tue'), '3' => gettext('Wed'), '4' => gettext('Thu'),
	    '5' => gettext('Fri'), '6' => gettext('Sat'), '7' => gettext('Sun'));
}

function restapi_schema_firewall_schedules() {
	return array(
		'title' => gettext('Schedule'),
		'summary' => gettext('Schedule {name}[ with {ranges}]'),
		'summaryIcon' => 'calendar-days',
		'sections' => array(
			array('id' => 'general', 'title' => gettext('Schedule'), 'fields' => array(
				array('name' => 'name', 'type' => 'text', 'label' => gettext('Name'), 'required' => true, 'mono' => true, 'width' => 'half',
				    'maxLength' => 31, 'pattern' => '^[A-Za-z0-9_]+$',
				    'patternMessage' => gettext('Use letters, digits and underscores only.'),
				    'help' => gettext('Rules select the schedule by this name. While a rule uses the schedule, the name cannot be changed.'),
				    'errorMatch' => array('schedule name')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'half',
				    'help' => gettext('For your reference (not parsed).')),
			)),
			array('id' => 'ranges', 'title' => gettext('Time ranges'),
			    'description' => gettext('Rules with this schedule apply during any of these ranges. Choose weekdays for a weekly range, or enter dates (month-day, every year) for specific days.'),
			    'fields' => array(
				array('name' => 'ranges', 'type' => 'entry-grid', 'label' => gettext('Time ranges'), 'min' => 1, 'max' => 99,
				    'addLabel' => gettext('Add time range'), 'emptyText' => gettext('No time ranges yet.'),
				    'errorMatch' => array('time range', 'start time', 'stop time', 'schedule specification'),
				    'fields' => array(
					array('name' => 'weekdays', 'type' => 'checklist', 'label' => gettext('Weekdays'), 'inline' => true, 'width' => 'lg',
					    'options' => restapi_schema_choices(restapi_sched_weekdays())),
					array('name' => 'dates', 'type' => 'text', 'label' => gettext('Dates'), 'mono' => true, 'width' => 'md',
					    'placeholder' => '12-24, 12-25'),
					array('name' => 'start', 'type' => 'datetime', 'mode' => 'time', 'label' => gettext('Start'), 'required' => true, 'width' => 'sm'),
					array('name' => 'stop', 'type' => 'datetime', 'mode' => 'time', 'label' => gettext('Stop'), 'required' => true, 'width' => 'sm'),
					array('name' => 'description', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'md'),
				    )),
			)),
		),
	);
}
