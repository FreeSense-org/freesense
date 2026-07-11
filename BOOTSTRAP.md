# FreeSense web UI development

FreeSense uses Bootstrap 5 for the webConfigurator. New and modified pages must
use the shared PHP form classes and Bootstrap 5 components already provided by
the system; do not add Bootstrap 3 panels, glyphicons, `btn-default`, `pull-*`,
`hidden-*`, or `col-xs-*` classes.

## Page requirements

- Include `guiconfig.inc`, declare the page privilege, and use the normal
  `head.inc`/`foot.inc` lifecycle.
- Use `Form`, `Form_Section`, and the shared input classes for settings pages.
- Keep CSRF protection enabled. A `$nocsrf` exemption requires a documented,
  authenticated, read-only endpoint and a security review.
- Escape untrusted output with `htmlspecialchars()` and validate request data
  before file, command, XML, or configuration operations.
- Use responsive Bootstrap 5 utilities and verify desktop and mobile layouts.
- Preserve upstream copyright and license notices when modifying inherited code.

## Verification

Before merging UI work, run PHP syntax checks, the relevant unit tests, and a
browser smoke test covering validation errors, save/apply behavior, JavaScript
console errors, keyboard navigation, and narrow viewport rendering. Package UI
must meet the same standard as the core webConfigurator.

The completed Bootstrap 5 migration plans were removed before the 1.0 RC
Preview; unresolved compatibility shims must be tracked as GitHub issues.
