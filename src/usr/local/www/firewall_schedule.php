<?php
/*
 * firewall_schedule.php
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
##|*IDENT=page-firewall-schedules
##|*NAME=Firewall: Schedules
##|*DESCR=Allow access to the 'Firewall: Schedules' page.
##|*MATCH=firewall_schedule.php*
##|-PRIV


$dayArray = array (gettext('Mon'), gettext('Tues'), gettext('Wed'), gettext('Thur'), gettext('Fri'), gettext('Sat'), gettext('Sun'));
$monthArray = array (gettext('January'), gettext('February'), gettext('March'), gettext('April'), gettext('May'), gettext('June'), gettext('July'), gettext('August'), gettext('September'), gettext('October'), gettext('November'), gettext('December'));

require_once("guiconfig.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("firewall_schedule.inc");

$pgtitle = array(gettext("Firewall"), gettext("Schedules"));

if ($_POST['act'] == "del") {
	$errmsg = deleteSchedule($_POST);
}

fs_page_action(gettext('Add schedule'), 'firewall_schedule_edit.php', 'fa-plus');

include("head.inc");

if ($errmsg) {
	print_info_box($errmsg, 'danger');
}
?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Schedules'),
	'search' => gettext('Search schedules…'),
	'noun' => gettext('schedules'),
	'noun_one' => gettext('schedule'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit">
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext("Status")?></th>
					<th data-fs-search><?=gettext("Name")?></th>
					<th data-fs-search><?=gettext("Range: Date / Times / Name")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
$i = 0;
foreach (config_get_path('schedules/schedule', []) as $schedule):
	$schedstatus = filter_get_time_based_rule_status($schedule);
?>
				<tr>
					<td>
						<?=$schedstatus ? fs_badge('active', null, gettext("Schedule is currently active")) : fs_badge('idle')?>
					</td>
					<td>
						<a href="firewall_schedule_edit.php?id=<?=$i?>"><?=htmlspecialchars($schedule['name'])?></a>
					</td>
					<td>
<?php
	$first = true;
	foreach ($schedule['timerange'] as $timerange) {
		$tempFriendlyTime = "";
		$tempID = "";
		$firstprint = false;

		if ($timerange) {
			$dayFriendly = "";
			$tempFriendlyTime = "";

			//get hours
			$temptimerange = $timerange['hour'];
			$temptimeseparator = strrpos($temptimerange, "-");

			$starttime = substr ($temptimerange, 0, $temptimeseparator);
			$stoptime = substr ($temptimerange, $temptimeseparator+1);

			if ($timerange['month']) {
				$tempmontharray = explode(",", $timerange['month']);
				$tempdayarray = explode(",", $timerange['day']);
				$arraycounter = 0;
				$firstDayFound = false;
				$firstPrint = false;
				foreach ($tempmontharray as $monthtmp) {
					$month = (int)$tempmontharray[$arraycounter];
					$day = (int)$tempdayarray[$arraycounter];

					if (!$firstDayFound) {
						$firstDay = $day;
						$firstmonth = $month;
						$firstDayFound = true;
					}

					$currentDay = $day;
					$nextDay = $tempdayarray[$arraycounter+1];
					$currentDay++;

					if (($currentDay != $nextDay) || ($tempmontharray[$arraycounter] != $tempmontharray[$arraycounter+1])) {
						if ($firstPrint) {
							$dayFriendly .= "<br />";
						}

						$currentDay--;

						if ($currentDay != $firstDay) {
							$dayFriendly .= $monthArray[$firstmonth-1] . " " . $firstDay . " - " . $currentDay ;
						} else {
							$dayFriendly .=	 $monthArray[$month-1] . " " . $day;
						}

						$firstDayFound = false;
						$firstPrint = true;
					}
					$arraycounter++;
				}
			} else {
				$tempdayFriendly = $timerange['position'];
				$firstDayFound = false;
				$tempFriendlyDayArray = explode(",", $tempdayFriendly);
				$currentDay = "";
				$firstDay = "";
				$nextDay = "";
				$counter = 0;

				foreach ($tempFriendlyDayArray as $day) {
					if ($day != "") {
						if (!$firstDayFound) {
							$firstDay = $tempFriendlyDayArray[$counter];
							$firstDayFound = true;
						}

						$currentDay =$tempFriendlyDayArray[$counter];
						//get next day
						$nextDay = $tempFriendlyDayArray[$counter+1];
						$currentDay++;

						if ($currentDay != $nextDay) {
							if ($firstprint) {
								$dayFriendly .= "<br />";
							}

							$currentDay--;

							if ($currentDay != $firstDay) {
								$dayFriendly .= $dayArray[$firstDay-1] . " - " . $dayArray[$currentDay-1];
							} else {
								$dayFriendly .= $dayArray[$firstDay-1];
							}

							$firstDayFound = false;
							$firstprint = true;
						}
						$counter++;
					}
				}
			}

			$timeFriendly = $starttime . "-" . $stoptime;
			$description = htmlspecialchars($timerange['rangedescr']);

			print(($first ? '':'<br />') . $dayFriendly . ' / ' . $timeFriendly . ' / ' . $description);
		}
	$first = false;
	}
?>
					</td>

					<td>
						<?=htmlspecialchars($schedule['descr'])?>
					</td>

					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', "firewall_schedule_edit.php?id={$i}", $schedule['name']],
							['delete', "firewall_schedule.php?act=del&id={$i}", $schedule['name'],
							    ['thing' => gettext('schedule'), 'detail' => gettext('Rules that use this schedule must be changed first.')]],
						])?>
					</td>
				</tr>
<?php
	$i++;
endforeach;

if ($i == 0) {
	fs_empty_row(5, gettext('No schedules yet.'), 'firewall_schedule_edit.php', gettext('Add schedule'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<div class="infoblock">
	<?php print_info_box(gettext('Schedules act as placeholders for time ranges to be used in firewall rules.'), 'info', false); ?>
</div>

<?php

include("foot.inc");
