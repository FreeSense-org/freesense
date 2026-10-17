/*
 * fleet-join.js
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
 * System > Central Management, "Add to fleet": while the join runs in the
 * background, read its progress every 2 s and show the steps, the approval
 * code to compare, and the end (joined, or what failed and why).
 */
(function () {
	'use strict';

	var root = document.getElementById('fs-fleet-join');
	if (!root) {
		return;
	}
	var T = JSON.parse(root.getAttribute('data-strings'));
	var q = function (k) { return root.querySelector('[data-join-' + k + ']'); };
	var ICON = { done: 'fa-circle-check', running: 'fa-spinner fa-spin', wait: 'fa-hourglass-half', fail: 'fa-circle-xmark', todo: 'fa-circle' };

	function steps(list) {
		var ol = q('steps');
		ol.textContent = '';
		list.forEach(function (s) {
			var li = document.createElement('li');
			li.className = 'fs-fleet-step fs-fleet-step-' + s.state;
			var i = document.createElement('i');
			i.className = 'fa-solid ' + (ICON[s.state] || ICON.todo);
			i.setAttribute('aria-hidden', 'true');
			li.appendChild(i);
			var label = document.createElement('span');
			label.textContent = s.label;
			li.appendChild(label);
			var st = document.createElement('span');
			st.className = 'visually-hidden';
			st.textContent = ' (' + (T[s.state] || T.todo) + ')';
			li.appendChild(st);
			ol.appendChild(li);
		});
	}

	/* The guide's stepper (fs_stepper): Join while working, Approve while the code waits, then Done. */
	var stepper = document.querySelector('.fs-stepper');
	function stepTo(key) {
		if (!stepper) {
			return;
		}
		var items = stepper.querySelectorAll('[data-step]');
		var seen = false;
		Array.prototype.forEach.call(items, function (li, j) {
			var k = li.getAttribute('data-step');
			var state = (key === 'done') ? 'done' : ((k === key) ? 'current' : (seen ? 'todo' : (li.classList.contains('is-skipped') ? 'skipped' : 'done')));
			if (k === key) {
				seen = true;
			}
			li.className = 'fs-step is-' + state;
			if (state === 'current') {
				li.setAttribute('aria-current', 'step');
			} else {
				li.removeAttribute('aria-current');
			}
			var num = li.querySelector('.fs-step-num');
			num.innerHTML = (state === 'done') ? '<i class="fa-solid fa-check"></i>' : String(j + 1);
			var st = li.querySelector('[data-step-status]');
			if (st) {
				st.textContent = ' (' + (T['step_' + state] || state) + ')';
			}
		});
	}

	function poll() {
		fetch(root.getAttribute('data-poll'), { credentials: 'same-origin', cache: 'no-store' })
		    .then(function (r) { return r.json(); })
		    .then(function (d) {
			steps(d.steps || []);
			stepTo((d.state === 'done' || d.enrolled) ? 'done' : ((d.state === 'waiting') ? 'code' : 'join'));
			q('say').textContent = (d.state === 'running') ? (d.say || '') : '';
			var code = q('code');
			code.hidden = (d.state !== 'waiting');
			q('digits').textContent = d.code ? d.code.replace(/^(\d{3})(\d{3})$/, '$1 $2') : '';
			q('done').hidden = !(d.state === 'done' || d.enrolled);
			var failed = (d.state === 'failed') && !d.enrolled;
			q('failed').hidden = !failed;
			if (failed && d.error) {
				q('err-title').textContent = d.error.title;
				q('err-cause').textContent = d.error.cause;
				q('err-text').textContent = 'FLEET-' + d.error.code + ': ' + d.error.text;
			}
			if (d.state === 'running' || d.state === 'waiting' || (d.state === 'idle' && !d.enrolled)) {
				setTimeout(poll, 2000);
			}
		})
		    .catch(function () { setTimeout(poll, 4000); });
	}
	poll();
}());
