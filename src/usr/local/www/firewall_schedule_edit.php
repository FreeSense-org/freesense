<?php
/*
 * firewall_schedule_edit.php
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
##|*IDENT=page-firewall-schedules-edit
##|*NAME=Firewall: Schedules: Edit
##|*DESCR=Allow access to the 'Firewall: Schedules: Edit' page.
##|*MATCH=firewall_schedule_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("firewall_schedule.inc");

$pgtitle = array(gettext("Firewall"), gettext("Schedules"), gettext("Edit"));
$pglinks = array("", "firewall_schedule.php", "@self");

$referer = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/firewall_schedule.php');

$dayArray = array (gettext('Mon'), gettext('Tues'), gettext('Wed'), gettext('Thur'), gettext('Fri'), gettext('Sat'), gettext('Sun'));
$monthArray = array (gettext('January'), gettext('February'), gettext('March'), gettext('April'), gettext('May'), gettext('June'), gettext('July'), gettext('August'), gettext('September'), gettext('October'), gettext('November'), gettext('December'));

$a_schedules = config_get_path('schedules/schedule', []);

if (isset($_REQUEST['id']) && is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

if (isset($id) && $a_schedules[$id]) {
	$pconfig['name'] = $a_schedules[$id]['name'];
	$pconfig['descr'] = $a_schedules[$id]['descr'];
	$pconfig['timerange'] = $a_schedules[$id]['timerange'];
	$pconfig['schedlabel'] = $a_schedules[$id]['schedlabel'];
	$getSchedule = true;
}

if (isset($id) && $a_schedules[$id]) {
	$pgtitle = array(gettext("Firewall"), gettext("Schedules"), htmlspecialchars($a_schedules[$id]['name']), gettext("Edit schedule"));
	$pglinks = array("", "firewall_schedule.php", "", "@self");
} else {
	$pgtitle = array(gettext("Firewall"), gettext("Schedules"), gettext("Add schedule"));
	$pglinks = array("", "firewall_schedule.php", "@self");
}

if ($_POST['save']) {
	$result = saveSchedule($_POST, (isset($id) && $a_schedules[$id]) ? $id : null);
	$input_errors = $result['input_errors'];

	if (empty($input_errors)) {
		header("Location: firewall_schedule.php");
		exit;
	}

	//we received input errors, copy data to prevent retype
	$getSchedule = (bool)$_POST['schedule0'];
	$pconfig['name'] = $result['schedule']['name'];
	$pconfig['descr'] = $result['schedule']['descr'];
	$pconfig['timerange'] = $result['schedule']['timerange'];
}

include("head.inc");

/*
 * Month calendars for the next 12 months. Every day is a button whose id is
 * w<ISO week>p<weekday 1-7> and whose data-day is the token the page posts
 * (w<week>p<weekday>-m<month>d<day>); weekday headers select every
 * occurrence of that weekday (token w1p<weekday>).
 */
function build_date_table() {
	global $monthArray;
	$weekdays = [gettext('Mon'), gettext('Tue'), gettext('Wed'), gettext('Thu'), gettext('Fri'), gettext('Sat'), gettext('Sun')];
	$every = [gettext('Every Monday'), gettext('Every Tuesday'), gettext('Every Wednesday'), gettext('Every Thursday'),
	    gettext('Every Friday'), gettext('Every Saturday'), gettext('Every Sunday')];
	$tblstr = '';

	$firstmonth = true;
	$monthcounter = date("n");
	$yearcounter = date("Y");

	for ($k = 0; $k < 12; $k++) {
		$firstdayofmonth = date("w", mktime(0, 0, 0, $monthcounter, 1, $yearcounter));
		if ($firstdayofmonth == 0) {
			$firstdayofmonth = 7;
		}
		$numberofdays = date("t", mktime(0, 0, 0, $monthcounter, 1, $yearcounter));
		$title = $monthArray[$monthcounter - 1] . ' ' . $yearcounter;

		$mostr = '<div class="fs-cal-month" id="fs-month-' . $monthcounter . '"' . ($firstmonth ? '' : ' hidden') . '>';
		$mostr .= '<div class="fs-cal-title">' . htmlspecialchars($title) . '</div>';
		$mostr .= '<div class="fs-cal-grid" role="group" aria-label="' . htmlspecialchars($title) . '">';
		foreach ($weekdays as $i => $wd) {
			$mostr .= '<button type="button" class="fs-cal-wd" data-day="w1p' . ($i + 1) . '" aria-pressed="false" title="' .
			    htmlspecialchars($every[$i]) . '" aria-label="' . htmlspecialchars($every[$i]) . '">' . htmlspecialchars($wd) . '</button>';
		}
		for ($pos = 1; $pos < $firstdayofmonth; $pos++) {
			$mostr .= '<span class="fs-cal-blank"></span>';
		}
		for ($day = 1; $day <= $numberofdays; $day++) {
			$position = date("N", mktime(0, 0, 0, $monthcounter, $day, $yearcounter));
			$week = ltrim(date("W", mktime(0, 0, 0, $monthcounter, $day, $yearcounter)), "0");
			$cellid = 'w' . $week . 'p' . $position;
			$mostr .= '<button type="button" class="fs-cal-day" id="' . $cellid . '" data-day="' . $cellid . '-m' . $monthcounter . 'd' . $day .
			    '" aria-pressed="false" aria-label="' . htmlspecialchars($monthArray[$monthcounter - 1] . ' ' . $day) . '">' . $day . '</button>';
		}
		$mostr .= '</div></div>';
		$firstmonth = false;

		if ($monthcounter == 12) {
			$monthcounter = 1;
			$yearcounter++;
		} else {
			$monthcounter++;
		}

		$tblstr .= $mostr;
	}

	return ($tblstr);
}

function build_month_list() {
	global $monthArray;

	$list = array();

	$monthcounter = date("n");
	$yearcounter = date("Y");

	for ($k = 0; $k < 12; $k++) {
		$list[$monthcounter] = $monthArray[$monthcounter - 1] . ' ' . $yearcounter;

		if ($monthcounter == 12) {
			$monthcounter = 1;
			$yearcounter++;
		} else {
			$monthcounter++;
		}
	}

	return ($list);
}

/* Builds a <select> with the same name, id and options as the Form_Select it replaces. */
function sched_time_select($name, $label, $options, $selected) {
	$html = '<select class="form-select" name="' . $name . '" id="' . $name . '" aria-label="' . htmlspecialchars($label) . '">';
	foreach ($options as $value => $text) {
		$html .= '<option value="' . htmlspecialchars($value) . '"' . (((string)$value === (string)$selected) ? ' selected' : '') . '>' . htmlspecialchars($text) . '</option>';
	}
	return $html . '</select>';
}

/* The configured time ranges, with the posted tokens and a readable form of the days. */
$ranges = [];
if ($getSchedule && !empty($pconfig['timerange'])) {
	foreach ($pconfig['timerange'] as $timerange) {
		$tempFriendlyTime = "";
		$tempID = "";
		if ($timerange) {
			$dayFriendly = "";
			$timedescr = $timerange['rangedescr'];

			//get hours
			$temptimerange = $timerange['hour'];
			$temptimeseparator = strrpos($temptimerange, "-");

			$starttime = substr($temptimerange, 0, $temptimeseparator);
			$stoptime = substr($temptimerange, $temptimeseparator+1);
			$firstDayFound = false;
			$firstPrint = false;
			$firstprint2 = false;

			if ($timerange['month']) {
				$tempmontharray = explode(",", $timerange['month']);
				$tempdayarray = explode(",", $timerange['day']);
				$arraycounter = 0;
				foreach ($tempmontharray as $monthtmp) {
					$month = (int)$tempmontharray[$arraycounter];
					$day = (int)$tempdayarray[$arraycounter];

					$daypos = date("w", mktime(0, 0, 0, $month, $day, date("Y")));
					//if sunday, set position to 7 to get correct week number (ISO-8601)
					if ($daypos == 0) {
						$daypos = 7;
					}

					$weeknumber = ltrim(date("W", mktime(0, 0, 0, $month, $day, date("Y"))), "0");

					if ($firstPrint) {
						$tempID .= ",";
					}

					$tempID .= "w" . $weeknumber . "p" . $daypos . "-m" .  $month . "d" . $day;
					$firstPrint = true;

					if (!$firstDayFound) {
						$firstDay = $day;
						$firstmonth = $month;
						$firstDayFound = true;
					}

					$currentDay = $day;
					$nextDay = $tempdayarray[$arraycounter+1];
					$currentDay++;
					if (($currentDay != $nextDay) || ($tempmontharray[$arraycounter] != $tempmontharray[$arraycounter+1])) {
						if ($firstprint2) {
							$tempFriendlyTime .= ", ";
						}

						$currentDay--;

						if ($currentDay != $firstDay) {
							$tempFriendlyTime .= $monthArray[$firstmonth-1] . " " . $firstDay . " - " . $currentDay ;
						} else {
							$tempFriendlyTime .=  $monthArray[$month-1] . " " . $day;
						}

						$firstDayFound = false;
						$firstprint2 = true;
					}
					$arraycounter++;
				}
			} else {
				$dayFriendly = $timerange['position'];
				$tempID = $dayFriendly;
			}

			//make the days friendly, i.e. Mon - Wed instead of Mon, Tues, Wed
			$firstDayFound = false;
			$firstprint = false;
			$tempFriendlyDayArray = explode(",", $dayFriendly);
			$i = 0;

			if (!$timerange['month']) {
				foreach ($tempFriendlyDayArray as $day) {
					if ($day != "") {
						if (!$firstDayFound) {
							$firstDay = $tempFriendlyDayArray[$i];
							$firstDayFound = true;
						}

						$currentDay = $tempFriendlyDayArray[$i];
						$nextDay = $tempFriendlyDayArray[$i+1];
						$currentDay++;

						if ($currentDay != $nextDay) {
							if ($firstprint) {
								$tempFriendlyTime .= ", ";
							}

							$currentDay--;

							if ($currentDay != $firstDay) {
								$tempFriendlyTime .= $dayArray[$firstDay-1] . " - " . $dayArray[$currentDay-1];
							} else {
								$tempFriendlyTime .= $dayArray[$firstDay-1];
							}

							$firstDayFound = false;
							$firstprint = true;
						}
						$i++;
					}
				}
			}

			$ranges[] = ['days' => $tempFriendlyTime, 'start' => $starttime, 'stop' => $stoptime, 'descr' => $timedescr,
			    'token' => $tempID, 'weekly' => !$timerange['month']];
		}
	}
}
$counter = count($ranges);
$editing = (isset($id) && $a_schedules[$id]);

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($editing) {
	$inuse = is_schedule_inuse($pconfig['name']);
	fs_summary_card([
		'icon' => 'fa-regular fa-calendar',
		'title' => $pconfig['name'],
		'subtitle' => $pconfig['descr'],
		'badges' => [filter_get_time_based_rule_status($a_schedules[$id]) ? fs_badge('active', null, gettext('Schedule is currently active')) : fs_badge('idle'),
		    $inuse ? fs_badge('info', gettext('In use')) : ''],
		'facts' => [
			[gettext('Time ranges'), (string)$counter],
			[gettext('Rules'), $inuse ? gettext('Used by at least one rule; the name cannot change') : gettext('Not used by any rule')],
		],
	]);
}

$form = new Form();

$section = new Form_Section('Schedule');

$input = new Form_Input(
	'name',
	'*Schedule Name',
	'text',
	$pconfig['name']
);

if (is_schedule_inuse($pconfig['name']) != true) {
	$input->setHelp('The name of the schedule may only consist of the characters "a-z, A-Z, 0-9 and _".');
} else {
	$input->setHelp('This schedule is in use so the name may not be modified!');
}

if (is_schedule_inuse($pconfig['name']) == true) {
	$input->setReadonly();
}

$section->addInput($input);

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed). ');

$form->add($section);

/* The time range builder and the configured ranges are plain markup inside the form. */
$hours = array_combine(range(0, 23, 1), range(0, 23, 1));
$mins = array('00' => '00', '15' => '15', '30' => '30', '45' => '45', '59' => '59');
$monthsel = '<select class="form-select form-select-sm" name="monthsel" id="monthsel" aria-label="' . htmlspecialchars(gettext('Month')) . '">';
foreach (build_month_list() as $value => $text) {
	$monthsel .= '<option value="' . $value . '">' . htmlspecialchars($text) . '</option>';
}
$monthsel .= '</select>';

ob_start();
?>
<div class="panel panel-default fs-sched-builder">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Add time range')?></h2></div>
	<div class="panel-body">
		<div class="fs-sched-grid">
			<div class="fs-sched-cal">
				<div class="fs-sched-step">
					<span class="fs-sched-num">1</span>
					<label for="monthsel"><?=gettext('Pick days')?></label>
					<?=$monthsel?>
				</div>
				<?=build_date_table()?>
				<p class="fs-sched-help"><?=gettext('Click a date to select that date only. Click a weekday name to select every occurrence of that weekday.')?></p>
			</div>
			<div class="fs-sched-time">
				<div class="fs-sched-step">
					<span class="fs-sched-num">2</span>
					<span><?=gettext('Set the time')?></span>
				</div>
				<div class="fs-sched-times">
					<div>
						<div class="fs-sched-label"><?=gettext('Start')?></div>
						<div class="fs-sched-hm">
							<?=sched_time_select('starttimehour', gettext('Start hour'), $hours, '0')?>
							<span aria-hidden="true">:</span>
							<?=sched_time_select('starttimemin', gettext('Start minutes'), $mins, '00')?>
						</div>
					</div>
					<div>
						<div class="fs-sched-label"><?=gettext('Stop')?></div>
						<div class="fs-sched-hm">
							<?=sched_time_select('stoptimehour', gettext('Stop hour'), $hours, '23')?>
							<span aria-hidden="true">:</span>
							<?=sched_time_select('stoptimemin', gettext('Stop minutes'), $mins, '59')?>
						</div>
					</div>
				</div>
				<p class="fs-sched-help"><?=gettext('A full day is 0:00 to 23:59.')?></p>
				<label class="fs-sched-label" for="timerangedescr"><?=gettext('Description')?> <span class="fs-muted">(<?=gettext('optional')?>)</span></label>
				<input class="form-control" type="text" name="timerangedescr" id="timerangedescr" value="<?=htmlspecialchars($pconfig['timerangedescr'] ?? '')?>">
				<div class="fs-sched-selected" id="fs-sched-selected" aria-live="polite"></div>
				<div class="fs-sched-msg text-danger" id="fs-sched-msg" role="alert"></div>
				<div class="fs-sched-buttons">
					<button type="button" class="btn btn-sm btn-outline-secondary" id="btnaddtime" name="btnaddtime"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=gettext('Add time')?></button>
					<button type="button" class="btn btn-sm btn-outline-secondary" id="btnclrsel" name="btnclrsel"><i class="fa-solid fa-arrow-rotate-left icon-embed-btn" aria-hidden="true"></i><?=gettext('Clear selection')?></button>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="panel panel-default fs-table" id="fs-sched-card">
<?php fs_table_toolbar([
	'title' => gettext('Time ranges'),
	'search' => false,
	'noun' => gettext('time ranges'),
	'noun_one' => gettext('time range'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" id="fs-sched-ranges">
			<thead>
				<tr>
					<th><?=gettext('Days')?></th>
					<th><?=gettext('Time')?></th>
					<th><?=gettext('Description')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($ranges as $n => $r): ?>
				<tr class="schedulegrp<?=$n?>">
					<td><?=fs_badge('info', $r['weekly'] ? gettext('Weekly') : gettext('Dates'))?> <?=htmlspecialchars($r['days'])?>
						<input type="hidden" name="tempFriendlyTime<?=$n?>" id="tempFriendlyTime<?=$n?>" value="<?=htmlspecialchars($r['days'])?>"></td>
					<td class="fs-mono"><?=htmlspecialchars($r['start'] . ' – ' . $r['stop'])?>
						<input type="hidden" name="starttime<?=$n?>" id="starttime<?=$n?>" value="<?=htmlspecialchars($r['start'])?>">
						<input type="hidden" name="stoptime<?=$n?>" id="stoptime<?=$n?>" value="<?=htmlspecialchars($r['stop'])?>"></td>
					<td><input class="form-control form-control-sm" type="text" name="timedescr<?=$n?>" id="timedescr<?=$n?>" value="<?=htmlspecialchars($r['descr'])?>" aria-label="<?=gettext('Description')?>"></td>
					<td class="fs-col-actions">
						<input type="hidden" name="schedule<?=$n?>" id="schedule<?=$n?>" value="<?=htmlspecialchars($r['token'])?>">
						<button type="button" class="btn btn-sm btn-link fs-action fs-action--delete" data-fs-sched-delete="<?=$n?>" title="<?=gettext('Remove time range')?>" aria-label="<?=gettext('Remove time range')?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
					</td>
				</tr>
<?php endforeach; ?>
<?php fs_empty_row(4, gettext('No time ranges yet. Pick days and a time above, then click Add time.')); ?>
			</tbody>
		</table>
	</div>
	<input type="hidden" name="marker" id="marker" value="">
</div>
<?php
$builder_html = ob_get_clean();

/* Form::add() takes sections; this one prints the markup above. */
$form->add(new class($builder_html) extends Form_Section {
	private $html;
	public function __construct($html) {
		parent::__construct('');
		$this->html = $html;
	}
	public function __toString() {
		return $this->html;
	}
});

if ($editing) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

fs_form_cancel($form, 'firewall_schedule.php');

print($form);
?>

<style>
.fs-sched-builder > .panel-body { padding: var(--fs-sp-4); }
.fs-sched-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--fs-sp-5); }
.fs-sched-step { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); margin-bottom: var(--fs-sp-3); font-weight: 600; }
.fs-sched-step label { margin: 0; }
.fs-sched-step .form-select { width: auto; max-width: 100%; margin-left: auto; }
.fs-sched-num {
	display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem;
	border-radius: 50%; background: var(--fs-accent-tint); color: var(--fs-coral-text); font-size: var(--fs-fs-xs);
}
.fs-cal-title { margin-bottom: var(--fs-sp-2); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 600; text-align: center; }
.fs-cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: var(--fs-sp-1); max-width: 26rem; margin: 0 auto; }
.fs-cal-wd, .fs-cal-day {
	min-width: 0; height: 2.25rem; padding: 0; border: 1px solid transparent; border-radius: var(--fs-r-sm);
	background: transparent; color: var(--fs-text); font-size: var(--fs-fs-sm); font-variant-numeric: tabular-nums;
	cursor: pointer; transition: background-color var(--fs-t-fast) var(--fs-ease), border-color var(--fs-t-fast) var(--fs-ease);
}
.fs-cal-wd { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 600; text-transform: uppercase; }
.fs-cal-day { border-color: var(--fs-border); }
.fs-cal-wd:hover, .fs-cal-day:hover { border-color: var(--fs-coral); }
.fs-cal-wd:focus-visible, .fs-cal-day:focus-visible { outline: 2px solid var(--fs-coral); outline-offset: 1px; }
.fs-cal-wd[aria-pressed="true"] { color: var(--fs-info); }
.fs-cal-day.fs-cal-weekly { border-color: var(--fs-info); background: color-mix(in srgb, var(--fs-info) 18%, transparent); color: var(--fs-text-strong); }
.fs-cal-day.fs-cal-date { border-color: var(--fs-coral); background: var(--fs-coral); color: #fff; font-weight: 600; }
.fs-sched-help { margin: var(--fs-sp-3) 0 0; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-sched-times { display: flex; flex-wrap: wrap; gap: var(--fs-sp-4); }
.fs-sched-label { display: block; margin-bottom: var(--fs-sp-1); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 500; }
.fs-sched-time > label.fs-sched-label { margin-top: var(--fs-sp-4); }
.fs-sched-hm { display: flex; align-items: center; gap: var(--fs-sp-1); }
.fs-sched-hm .form-select { width: 5rem; }
.fs-sched-selected { margin-top: var(--fs-sp-3); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-sched-msg:empty, .fs-sched-selected:empty { display: none; }
.fs-sched-msg { margin-top: var(--fs-sp-2); font-size: var(--fs-fs-sm); }
.fs-sched-buttons { display: flex; flex-wrap: wrap; gap: var(--fs-sp-2); margin-top: var(--fs-sp-3); }
#fs-sched-ranges tr.fs-empty { display: none; }
#fs-sched-ranges tbody tr.fs-empty:only-child { display: table-row; }
#fs-sched-ranges td .form-control { min-width: 8rem; }
#fs-sched-ranges td.fs-mono { white-space: nowrap; }
@media (max-width: 991.98px) {
	.fs-sched-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>

<script type="text/javascript">
//<![CDATA[
var daysSelected = "";
var month_array = <?=json_encode(array_values($monthArray), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
var day_array = <?=json_encode(array_values($dayArray), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
var sched_text = <?=json_encode([
	'noday' => gettext('Select at least one day before adding a time.'),
	'hour' => gettext('The start hour cannot be later than the stop hour.'),
	'min' => gettext('The start minute cannot be later than the stop minute.'),
	'selected' => gettext('Selected: %s'),
	'weekly' => gettext('Weekly'),
	'dates' => gettext('Dates'),
	'remove' => gettext('Remove time range'),
	'descr' => gettext('Description'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
var counter = <?=(int)$counter?>;

/*
 * Selection state lives in daysSelected (comma separated tokens, as before):
 * a date token w<week>p<weekday>-m<month>d<day> marks that cell fs-cal-date,
 * a weekday token w1p<weekday> marks every cell of that weekday fs-cal-weekly.
 */
function sched_cell(id) {
	return document.getElementById(id);
}

function daytogglerepeating(week, daypos, bExists) {
	var tempstr, daycell, dayoriginalpos, dayoriginalend;

	for (var j = 1; j <= 53; j++) {
		tempstr = 'w' + j + 'p' + daypos;
		daycell = sched_cell(tempstr);
		dayoriginalpos = daysSelected.indexOf(tempstr);

		// bExists: the weekday is selected, so unselect it and drop its tokens
		if (daycell != null) {
			daycell.classList.toggle('fs-cal-weekly', !bExists);

			if (dayoriginalpos != -1) {
				dayoriginalend = daysSelected.indexOf(',', dayoriginalpos);
				tempstr = daysSelected.substring(dayoriginalpos, dayoriginalend + 1);
				daysSelected = daysSelected.replace(tempstr, "");
			}
		}
	}
}

function daytoggle(id) {
	var runrepeat = false, idmod, daypos, daycell;
	var bFoundValid = false;
	var iddashpos = id.search("-");
	var tempstrdaypos = id.search("p");
	var week = parseInt(id.substring(1, tempstrdaypos));

	if (iddashpos == -1) {
		idmod = id;
		runrepeat = true;
		daypos = id.substr(tempstrdaypos + 1);
	} else {
		idmod = id.substring(0, iddashpos);
		daypos = id.substring(tempstrdaypos + 1, iddashpos);
	}

	daypos = parseInt(daypos);

	while (!bFoundValid) {
		daycell = sched_cell(idmod);

		if (daycell != null) {
			if (daycell.classList.contains('fs-cal-date')) {
				daycell.classList.remove('fs-cal-date');
				daysSelected = daysSelected.replace(id + ",", "");
			} else if (daycell.classList.contains('fs-cal-weekly')) {
				daytogglerepeating(week, daypos, true);
			} else {
				if (!runrepeat) {
					daycell.classList.add('fs-cal-date');
				} else {
					daycell.classList.add('fs-cal-weekly');
					daytogglerepeating(week, daypos, false);
				}
				daysSelected += id + ",";
			}
			bFoundValid = true;
		} else if (week > 54) {
			bFoundValid = true;
		} else {
			// no such cell in this week (column clicked): try the next week
			week++;
			idmod = "w" + week + "p" + daypos;
		}
	}
	sched_sync();
}

/* aria-pressed, weekday headers and the "Selected" line follow the cell classes */
function sched_sync() {
	var weekly = {}, dates = 0;

	document.querySelectorAll('.fs-cal-day').forEach(function (cell) {
		var on = cell.classList.contains('fs-cal-date') || cell.classList.contains('fs-cal-weekly');
		cell.setAttribute('aria-pressed', on ? 'true' : 'false');
		if (cell.classList.contains('fs-cal-weekly')) {
			weekly[cell.id.slice(cell.id.indexOf('p') + 1)] = true;
		}
	});
	document.querySelectorAll('.fs-cal-wd').forEach(function (wd) {
		wd.setAttribute('aria-pressed', weekly[wd.getAttribute('data-day').slice(3)] ? 'true' : 'false');
	});
	daysSelected.split(',').forEach(function (t) {
		if (t.indexOf('-') != -1) {
			dates++;
		}
	});

	var parts = [];
	Object.keys(weekly).sort().forEach(function (p) {
		parts.push(day_array[p - 1]);
	});
	if (dates) {
		parts.push(dates + ' ' + sched_text.dates.toLowerCase());
	}
	document.getElementById('fs-sched-selected').textContent = parts.length ? sched_text.selected.replace('%s', parts.join(', ')) : '';
}

function update_month() {
	var select = document.getElementById('monthsel');

	Array.prototype.forEach.call(select.options, function (option) {
		var layer = document.getElementById('fs-month-' + option.value);
		if (layer) {
			layer.hidden = (option.value !== select.value);
		}
	});
}

function sched_message(text) {
	document.getElementById('fs-sched-msg').textContent = text;
}

function processEntries() {
	var starttimehour = parseInt(document.getElementById("starttimehour").value);
	var starttimemin = parseInt(document.getElementById("starttimemin").value);
	var stoptimehour = parseInt(document.getElementById("stoptimehour").value);
	var stoptimemin = parseInt(document.getElementById("stoptimemin").value);

	if (starttimehour > stoptimehour) {
		sched_message(sched_text.hour);
	} else if ((starttimehour == stoptimehour) && (starttimemin > stoptimemin)) {
		sched_message(sched_text.min);
	} else {
		sched_message('');
		addTimeRange();
	}
}

function addTimeRange() {
	var tempdayarray = daysSelected.split(",");
	var tempstr, starttimehour, starttimemin, stoptimehour, stoptimemin, timeRange, tempstrdaypos, week, daypos, day, month, dashpos, monthpos;
	var rtempFriendlyTime = "", nrtempFriendlyTime = "", nrtempID = "", rtempID = "", nrtempTime = "", rtempTime = "";
	var rtempFriendlyDay = "", monthstr = "", daystr = "";
	var nonrepeatingfound = false, repeatingfound = false;
	var i, k, t;

	tempdayarray.sort();

	if (daysSelected == "") {
		sched_message(sched_text.noday);
		return;
	}

	for (i = 0; i < tempdayarray.length; i++) {
		tempstr = tempdayarray[i];
		if (tempstr != "") {
			tempstrdaypos = tempstr.search("p");
			week = parseInt(tempstr.substring(1, tempstrdaypos));
			dashpos = tempstr.search("-");

			if (dashpos != -1) {
				nonrepeatingfound = true;
				monthpos = tempstr.search("m");
				tempstrdaypos = tempstr.search("d");
				month = parseInt(tempstr.substring(monthpos + 1, tempstrdaypos));
				day = parseInt(tempstr.substring(tempstrdaypos + 1));
				monthstr += month + ",";
				daystr += day + ",";
				nrtempID += tempstr + ",";
			} else {
				repeatingfound = true;
				daypos = parseInt(tempstr.substr(tempstrdaypos + 1));
				rtempFriendlyDay += daypos + ",";
				rtempID += daypos + ",";
			}
		}
	}

	// readable form of the dates, e.g. "November 3-5, November 10"
	var firstDayFound = false, firstprint = false;
	var tempFriendlyMonthArray = monthstr.split(",");
	var tempFriendlyDayArray = daystr.split(",");
	var currentDay, firstDay, nextDay, firstMonth;

	for (k = 0; k < tempFriendlyMonthArray.length; k++) {
		tempstr = tempFriendlyMonthArray[k];
		if (tempstr != "") {
			if (!firstDayFound) {
				firstDay = parseInt(tempFriendlyDayArray[k]);
				firstMonth = parseInt(tempFriendlyMonthArray[k]);
				firstDayFound = true;
			}

			currentDay = parseInt(tempFriendlyDayArray[k]);
			nextDay = parseInt(tempFriendlyDayArray[k + 1]);
			currentDay++;
			if ((currentDay != nextDay) || (tempFriendlyMonthArray[k] != tempFriendlyMonthArray[k + 1])) {
				if (firstprint) {
					nrtempFriendlyTime += ", ";
				}
				currentDay--;
				if (currentDay != firstDay) {
					nrtempFriendlyTime += month_array[firstMonth - 1] + " " + firstDay + "-" + currentDay;
				} else {
					nrtempFriendlyTime += month_array[firstMonth - 1] + " " + currentDay;
				}
				firstDayFound = false;
				firstprint = true;
			}
		}
	}

	// readable form of the weekdays, e.g. "Mon - Wed, Fri"
	firstDayFound = false;
	firstprint = false;
	tempFriendlyDayArray = rtempFriendlyDay.split(",");
	tempFriendlyDayArray.sort();

	for (k = 0; k < tempFriendlyDayArray.length; k++) {
		tempstr = tempFriendlyDayArray[k];
		if (tempstr != "") {
			if (!firstDayFound) {
				firstDay = parseInt(tempFriendlyDayArray[k]);
				firstDayFound = true;
			}

			currentDay = parseInt(tempFriendlyDayArray[k]);
			nextDay = parseInt(tempFriendlyDayArray[k + 1]);
			currentDay++;

			if (currentDay != nextDay) {
				if (firstprint) {
					rtempFriendlyTime += ", ";
				}
				currentDay--;
				if (currentDay != firstDay) {
					rtempFriendlyTime += day_array[firstDay - 1] + " - " + day_array[currentDay - 1];
				} else {
					rtempFriendlyTime += day_array[firstDay - 1];
				}
				firstDayFound = false;
				firstprint = true;
			}
		}
	}

	// sorted weekday list, e.g. "1,3,5"
	var tempsortArray = rtempID.split(",");
	tempsortArray.sort();
	rtempID = tempsortArray.filter(function (v) { return v != ""; }).join(",");

	starttimehour = document.getElementById("starttimehour").value;
	starttimemin = document.getElementById("starttimemin").value;
	stoptimehour = document.getElementById("stoptimehour").value;
	stoptimemin = document.getElementById("stoptimemin").value;

	timeRange = "||" + starttimehour + ":" + starttimemin + "-" + stoptimehour + ":" + stoptimemin;

	var tempdescr = document.getElementById("timerangedescr").value;

	if (nonrepeatingfound) {
		nrtempTime += nrtempID + timeRange + "||" + tempdescr;
		insertElements(nrtempFriendlyTime, starttimehour, starttimemin, stoptimehour, stoptimemin, tempdescr, nrtempTime, nrtempID, false);
	}

	if (repeatingfound) {
		rtempTime += rtempID + timeRange + "||" + tempdescr;
		insertElements(rtempFriendlyTime, starttimehour, starttimemin, stoptimehour, stoptimemin, tempdescr, rtempTime, rtempID, true);
	}
}

function clearCalendar() {
	daysSelected = "";
	document.querySelectorAll('.fs-cal-day').forEach(function (cell) {
		cell.classList.remove('fs-cal-date', 'fs-cal-weekly');
	});
	sched_sync();
}

function clearTime() {
	document.getElementById("starttimehour").value = "0";
	document.getElementById("starttimemin").value = "00";
	document.getElementById("stoptimehour").value = "23";
	document.getElementById("stoptimemin").value = "59";
}

function clearDescr() {
	document.getElementById("timerangedescr").value = "";
}

function sched_hidden(name, value) {
	var input = document.createElement('input');
	input.type = 'hidden';
	input.name = name + counter;
	input.id = name + counter;
	input.value = value;
	return input;
}

/* the toolbar count follows added and removed rows */
function sched_count() {
	var card = document.getElementById('fs-sched-card');
	var count = card.querySelector('[data-fs-count]');

	if (card._fsTable) {
		card._fsTable.apply(false);
	}
	if (count && !card.querySelector('#fs-sched-ranges tbody tr:not(.fs-empty)')) {
		count.textContent = '';
	}
}

// Add a time-range row (same field names as the server-rendered rows)
function insertElements(tempFriendlyTime, starttimehour, starttimemin, stoptimehour, stoptimemin, tempdescr, tempTime, tempID, weekly) {
	var tbody = document.querySelector('#fs-sched-ranges tbody');
	var row = document.createElement('tr');
	var td, badge, input, button, icon;

	row.className = 'schedulegrp' + counter;

	td = document.createElement('td');
	badge = document.createElement('span');
	badge.className = 'fs-badge fs-badge--info';
	badge.textContent = weekly ? sched_text.weekly : sched_text.dates;
	td.append(badge, ' ' + tempFriendlyTime, sched_hidden('tempFriendlyTime', tempFriendlyTime));
	row.appendChild(td);

	td = document.createElement('td');
	td.className = 'fs-mono';
	td.append(starttimehour + ':' + starttimemin + ' – ' + stoptimehour + ':' + stoptimemin,
	    sched_hidden('starttime', starttimehour + ':' + starttimemin), sched_hidden('stoptime', stoptimehour + ':' + stoptimemin));
	row.appendChild(td);

	td = document.createElement('td');
	input = document.createElement('input');
	input.className = 'form-control form-control-sm';
	input.type = 'text';
	input.name = 'timedescr' + counter;
	input.id = 'timedescr' + counter;
	input.value = tempdescr;
	input.setAttribute('aria-label', sched_text.descr);
	td.appendChild(input);
	row.appendChild(td);

	td = document.createElement('td');
	td.className = 'fs-col-actions';
	button = document.createElement('button');
	button.type = 'button';
	button.className = 'btn btn-sm btn-link fs-action fs-action--delete';
	button.setAttribute('data-fs-sched-delete', counter);
	button.title = sched_text.remove;
	button.setAttribute('aria-label', sched_text.remove);
	icon = document.createElement('i');
	icon.className = 'fa-solid fa-trash-can';
	icon.setAttribute('aria-hidden', 'true');
	button.appendChild(icon);
	td.append(sched_hidden('schedule', tempID), button);
	row.appendChild(td);

	tbody.insertBefore(row, tbody.querySelector('tr.fs-empty'));

	counter++;
	sched_count();

	clearCalendar();
	clearTime();
	clearDescr();
}

function fse_delete_row(row) {
	document.querySelectorAll('#fs-sched-ranges tr.schedulegrp' + row).forEach(function (tr) {
		tr.remove();
	});
	sched_count();
}

events.push(function() {
	var form = document.getElementById('monthsel').form;

	document.getElementById('monthsel').addEventListener('change', update_month);

	// one handler for the calendar, the builder buttons and the row delete buttons
	form.addEventListener('click', function (event) {
		var el = event.target.closest('[data-day], #btnaddtime, #btnclrsel, [data-fs-sched-delete]');

		if (!el || !form.contains(el)) {
			return;
		}
		if (el.hasAttribute('data-day')) {
			sched_message('');
			daytoggle(el.getAttribute('data-day'));
		} else if (el.id === 'btnaddtime') {
			processEntries();
		} else if (el.id === 'btnclrsel') {
			sched_message('');
			clearCalendar();
			clearTime();
			clearDescr();
		} else {
			fse_delete_row(el.getAttribute('data-fs-sched-delete'));
		}
	});

	// Enter in the range description adds the range instead of saving the schedule
	document.getElementById('timerangedescr').addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			processEntries();
		}
	});
});
//]]>
</script>

<?php

include("foot.inc");
