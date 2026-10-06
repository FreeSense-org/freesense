<?php
/*
 * firewall_schedule.inc.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

require_once("config.gui.inc");

// Functions included by firewall_schedule*.php =============================
// Delete a schedule after first checking that no rules are using it
// Return an error string if the schedule is in use
function deleteSchedule($post, $json = false) {
    $a_schedules = config_get_path('schedules/schedule', []);

    $id = $post['id'];

	if ($a_schedules[$id]) {
		/* make sure rule is not being referenced by any nat or filter rules */
		$is_schedule_referenced = false;
		$referenced_by = false;
		$schedule_name = $a_schedules[$id]['name'];

		foreach (get_filter_rules_list() as $rule) {
			//check for this later once this is established
			if ($rule['sched'] == $schedule_name) {
				$referenced_by = $rule['descr'];
				$is_schedule_referenced = true;
				break;
			}
		}

		if ($is_schedule_referenced == true) {
			$errmsg = sprintf(gettext("Cannot delete schedule. Currently in use by %s."), $referenced_by);
		} else {
			unset($a_schedules[$post['id']]);
			config_set_path('schedules/schedule', $a_schedules);
			write_config(gettext("Firewall schedule deleted."));
            if (!$json) {
                header("Location: firewall_schedule.php");
                exit;
            }
		}
    }

    return $errmsg;
}

function schedulecmp($a, $b) {
	return strcmp($a['name'], $b['name']);
}

/* Keep schedules sorted by name. Note: this renumbers schedule ids. */
function schedule_sort() {
	$schedule_config = config_get_path('schedules/schedule');
	if (!is_array($schedule_config)) {
		return;
	}

	usort($schedule_config, "schedulecmp");
	config_set_path('schedules/schedule', $schedule_config);
}

/*
 * Validate and save a schedule from firewall_schedule_edit.php form fields:
 * name, descr and numbered rows scheduleN / starttimeN / stoptimeN /
 * timedescrN (N = 0..98). $id is the schedule index to replace, or null to
 * add one. Applies the filter on success.
 * Returns array('input_errors' => [...], 'schedule' => the built schedule).
 */
function saveSchedule($post, $id = null) {
	$input_errors = array();
	$a_schedules = config_get_path('schedules/schedule', []);
	if (($id !== null) && !isset($a_schedules[$id])) {
		$id = null;
	}

	if (empty($post['name'])) {
		$input_errors[] = gettext("Schedule name cannot be blank.");
	}

	/* Schedule names are not directly referenced in firewall rules, so they
	 * do not have to follow this format, but since this limitation was
	 * already in place, it makes for convenient validation. */
	if (!is_validaliasname($post['name'])) {
		$input_errors[] = invalidaliasnamemsg($post['name'], gettext("schedule"));
	}

	/* Check for name conflicts */
	foreach ($a_schedules as $schedule) {
		if (($id !== null) && ($a_schedules[$id] === $schedule)) {
			continue;
		}

		if ($schedule['name'] == $post['name']) {
			$input_errors[] = gettext("A Schedule with this name already exists.");
			break;
		}
	}

	$schedule = array();

	$schedule['name'] = $post['name'];
	$schedule['descr'] = $post['descr'];

	$timerangeFound = false;

	for ($x = 0; $x < 99; $x++) {
		if ($post['schedule' . $x]) {
			if (!preg_match('/^[0-9]+:[0-9]+$/', $post['starttime' . $x])) {
				$input_errors[] = sprintf(gettext("Invalid start time - '%s'"), $post['starttime' . $x]);
				continue;
			}

			if (!preg_match('/^[0-9]+:[0-9]+$/', $post['stoptime' . $x])) {
				$input_errors[] = sprintf(gettext("Invalid stop time - '%s'"), $post['stoptime' . $x]);
				continue;
			}

			/* Valid schedule specifications are a comma-separated list containing
			 * or or more of:
			 *
			 * - Single digit "day of week" numbers: <1-7>
			 * - Specific days in the format: w<1-52>p<1-7>-m<1-12>d<1-31>
			 */
			if (!preg_match('/^([1-7]|,|w(5[0-2]|[1-4][0-9]|[0-9])p([1-7])-m(1[0-2]|[1-9])d([12][0-9]|3[01]|[1-9]))+$/', $post['schedule' . $x])) {
				$input_errors[] = sprintf(gettext("Invalid schedule specification in row %d."), $x+1);
				continue;
			}

			$timerangeFound = true;
			$timeparts = array();
			$firstprint = false;
			$timestr = $post['schedule' . $x];
			$timehourstr = $post['starttime' . $x];
			$timehourstr .= "-";
			$timehourstr .= $post['stoptime' . $x];
			$timedescrstr = $post['timedescr' . $x];
			$dashpos = strpos($timestr, '-');

			if ($dashpos === false) {
				$timeparts['position'] = $timestr;
			} else {
				$tempindarray = array();
				$monthstr = "";
				$daystr = "";
				$tempindarray = explode(",", $timestr);
				foreach ($tempindarray as $currentselection) {
					if ($currentselection) {
						if ($firstprint) {
							$monthstr .= ",";
							$daystr .= ",";
						}
						$monthpos = strpos($currentselection, "m");
						$daypos = strpos($currentselection, "d");
						$monthstr .= substr($currentselection, $monthpos+1, $daypos-$monthpos-1);
						$daystr .=	substr($currentselection, $daypos+1);
						$firstprint = true;
					}
				}

				$timeparts['month'] = $monthstr;
				$timeparts['day'] = $daystr;
			}

			$timeparts['hour'] = $timehourstr;
			$timeparts['rangedescr'] = $timedescrstr;
			$schedule['timerange'][$x] = $timeparts;
		}
	}

	if (!$timerangeFound) {
		$input_errors[] = gettext("The schedule must have at least one time range configured.");
	}

	if (empty($input_errors)) {
		if (($id !== null) && !empty($a_schedules[$id]['schedlabel'])) {
			$schedule['schedlabel'] = $a_schedules[$id]['schedlabel'];
		} else {
			$schedule['schedlabel'] = uniqid();
		}

		if ($id !== null) {
			$a_schedules[$id] = $schedule;
		} else {
			$a_schedules[] = $schedule;
		}
		config_set_path('schedules/schedule', $a_schedules);

		schedule_sort();

		if (write_config(gettext("Firewall schedule configured."))) {
			filter_configure();
		}
	}

	return array('input_errors' => $input_errors, 'schedule' => $schedule);
}
