# Dashboard widgets (1.2)

The contract for core and package widgets. Runtime: `js/fs-core.js`, `js/fs-chart.js`,
`js/fs-dashboard.js`; catalogue and default layout: `src/usr/local/FreeSense/include/www/dashboard.inc`;
look: `css/_freesense-dashboard.css` (Horizon, on the tokens of `01-tokens.md`). Users read the
same contract in freesense-docs (`/1.2/developers/dashboard-widgets/`).

A dashboard widget is two static files in `/usr/local/www/widgets/` and, when it needs data
the core API does not already serve, a REST route in the package. The page never renders a
widget on the server: the widget's script fetches JSON from `/api/v1` and builds its own DOM.

## The manifest: `<type>.widget.json`

```json
{
	"type": "mypkg-status",
	"title": "My package",
	"description": "One line for the widget catalogue",
	"icon": "fa-shield-halved",
	"tone": "series-3",
	"category": "Services",
	"page": "pkg.php?xml=mypkg.xml",
	"link": "/pkg.php?xml=mypkg.xml",
	"sizes": ["sm", "md", "lg"],
	"size": "md",
	"every": 10,
	"allow_copies": false,
	"settings": [
		{"key": "rows", "type": "number", "label": "Rows", "value": 8, "min": 3, "max": 50}
	],
	"strings": ["Running", "Stopped"]
}
```

- `type` matches the file name: lower case letters, digits, `-` and `_`. Prefix it with the
  package name.
- `page` is the GUI page whose privilege the widget needs. The catalogue hides the widget from
  users who may not open it. Use the same page for the widget's REST route.
- `sizes` are columns of a 12-column grid: `sm` 3, `md` 4, `lg` 6, `xl` 8, `full` 12.
- `every` is the default refresh in seconds (`0`: only on load and Refresh). The user can
  change it from the widget menu.
- `settings` field types: `select` (with `options`: `[[value, label], ...]`), `checkbox`,
  `number`, `text`, `interface` (one assigned interface; a default of `""` also offers "every
  interface") and `interfaces` (several). `title` and `every` are reserved.
- `tone` colours the title icon: `series-1` to `series-8`, `pass`, `block`, `warn`, `info` or
  `neutral`.
- `strings` lists every English text the script shows, so the page can hand their
  translations to `FS.t()`.

## The script: `<type>.js`

```js
(function ($, FS, D) {
	'use strict';
	var T = FS.t, el = D.el;

	D.define('mypkg-status', {
		mount: function (w) {
			w.$body.append(el('ul', 'fs-wlist'));
		},
		load: function (w) {
			return FS.api.get('/packages/mypkg/status').then(function (r) {
				var $l = w.$body.find('.fs-wlist').empty();
				r.data.items.forEach(function (i) {
					$l.append(el('li', 'fs-svc').append(D.dot(i.up ? 'ok' : 'down'), el('strong', '', i.name)));
				});
			});
		}
	});
})(jQuery, window.FS, window.FSDashboard);
```

- `mount(w)` builds the static DOM once, and again after the user saves the widget's settings.
- `load(w)` returns a Promise. The scheduler calls it every `every` seconds while the widget is
  visible, never twice at once, and backs off when it fails. A failed first load shows an error
  card with **Retry**.
- `destroy(w)` (optional) stops what `mount` started (charts, timers). `resize(w)` (optional)
  runs after a size change. `options(w, key)` (optional) adds `[value, label]` choices to a
  `select` setting at run time.
- `w` holds `id`, `type`, `size`, `settings`, `state` (scratch space), `$body`, `$root`,
  `save()`, `refresh()` and `foot(text | node | null)`.

Helpers: `FS.api.get(path, query)` (GETs made in the same moment go out as one
`/api/v1/batch`), `FS.api.post/put/del`, `FS.fmt` (`bps`, `bytes`, `num`, `pct`, `speed`,
`uptime`, `ago`), `FS.toast()`, `FS.chart.timeseries()` (uPlot), `FS.chart.spark/ring/donut/meter`
(SVG), and `D.el`, `D.dot`, `D.tag`, `D.empty`, `D.copy` for markup.

**Put data in the DOM as text** (`D.el(tag, class, text)`, `.text()`), never by concatenating
it into HTML. The core tests reject `innerHTML` and `eval` in core widgets.

## The REST route

Register routes through the `plugin_restapi` hook. In the package XML:

```xml
<plugins>
	<item>
		<type>plugin_restapi</type>
	</item>
</plugins>
```

In the package's include file, `<package>_plugin_restapi()` returns the routes:

```php
function mypkg_plugin_restapi($params) {
	return array(
		restapi_route('GET', '/v1/packages/mypkg/status', 'mypkg_restapi_status', array(
			'page' => 'pkg.php?xml=mypkg.xml', 'area' => 'packages',
			'summary' => 'My package: what the widget shows')),
	);
}

function mypkg_restapi_status($req) {
	return array('data' => array('items' => mypkg_items()));
}
```

- Use `/v1/packages/<package>/...` paths and the same `page` as the manifest. The API checks the
  privilege; the session bridge lets the dashboard call the route as the signed-in user.
- An action is a `POST`, `PUT` or `DELETE` route with `'write' => true`, never a GET. The
  session bridge requires the CSRF token on every call.
- Throw `RestApiError(404, 'not_found', '...')` for unknown ids, and validate path parameters
  against an allow list before they reach a command.
- Keep requests cheap. A widget may poll every few seconds; cache slow sources (as the core
  does for SMART and ZFS) instead of running them per request.

The plugin entry is copied into the configuration when the package is installed, so a package
update that adds it must bump `PORTREVISION`.

## Packaging

- Install both files to `${PREFIX}/www/widgets/` and list them in `pkg-plist`.
- Bump `PORTREVISION` for every change; the Optional Packages build rebuilds the package.
- Do not edit the dashboard layout on install or removal. Users add the widget from the
  catalogue, and a widget whose package is gone simply drops out of their layout.

The core widgets in `src/usr/local/www/widgets/` are working examples; the Fleet, Threat Shield and Suricata packages show package routes.

## Core widget rules

- A core widget's data route lives in `restapi/routes_status.inc` (or the area's routes file) with
  the privilege of the page it mirrors, and a parser test in `tests/`.
- Requests stay cheap: `status/system` reads the sysstat sampler snapshot (`sysstat.inc`), the
  interface list runs `ifconfig` once, slow sources are cached. No command per poll.
- New files go into the FreeSense-system pkg-plist (same-named branch in freesense-system-ports);
  `tests/DashboardSmokeTest.php` checks every manifest and script.
- Charts use `FS.chart` only (uPlot for time series, SVG for the small marks). No other chart
  library.
