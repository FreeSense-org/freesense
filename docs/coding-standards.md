# FreeSense coding standards

These rules apply to `freesense`, `freesense-packages` and `freesense-system-ports`.
The WebUI has its own detailed guide in [`docs/webui`](webui/README.md); this
document covers everything else and the rules the WebUI guide builds on.

**First rule: match the code around you.** When you edit an existing file, follow
its style even where it differs from this document. Don't restyle code you aren't
otherwise changing. The rules below decide what *new* code looks like.

## Files

- **Formatting** follows `.editorconfig`: tabs for indentation in PHP, LF line
  endings, UTF-8, a final newline, and no trailing whitespace.
- **Header:** every new file starts with the FreeSense header (Apache-2.0,
  `Copyright (c) 2026 The FreeSense Project`). Keep the upstream Netgate/ESF lines
  only in files that still contain pfSense code.
- **Purpose comment:** under the header, a short block comment says what the file
  is for. Also name the `config.xml` section it owns and where it keeps any data
  outside `config.xml`.
- **New file checklist:**
  - every file under `src/` goes into `security/FreeSense-system/pkg-plist` in
    `freesense-system-ports` (CI audits this)
  - every new page with its own `##|*IDENT` privilege also goes into
    `src/etc/inc/priv.defs.inc`. Insert the entry by hand in file-name order.
    Don't regenerate the whole file: the generator's output has drifted from it.
  - every new test goes into the list in `.github/workflows/quality.yml`

## PHP

- **Version:** PHP 8.5. The code must pass `tools/ci/php-lint.sh` with no
  deprecations.
- **Types:** new functions declare parameter and return types (`function
  fleet_vault_fits(int $size, …): bool`). Don't add `declare(strict_types=1)` to
  shared includes. Callers across the tree pass strings from `config.xml`.
- **Arrays:** files use either `array()` (most of `etc/inc`, all of `restapi/`) or
  `[]` (newer code). Use whichever the file or directory already uses. A new
  standalone file picks one and keeps it.
- **Strings:** use single quotes unless you interpolate.
- **Comparisons:** use `===` / `!==`. Use `in_array(..., true)`.
- **Errors:** never use `@` to hide an error you don't then check. It is fine on a
  filesystem call whose result you test (`@file_get_contents()` followed by a
  check).

### Code structure

- **Libraries:** System code lives in procedural libraries in `src/etc/inc/<area>.inc`.
  Every function carries the module's prefix (`fleet_`, `restapi_`, `be_`,
  `remote_backup_`). Constants use `define()` with the same prefix in upper case
  (`FLEET_VAULT_DIR`).
- **Page logic:** shared by the GUI page and the REST API, it lives in
  `src/usr/local/FreeSense/include/www/<page>.inc`. The page and the API call the
  same validate and save functions, so a rule is written once.
- **Pure functions apart from system access:**
  - Put functions that only compute (parse, validate, decide) apart from those
    that read `config.xml`, run commands or touch disk.
  - Mark them `/* pure: … */`.
  - Pure functions are what tests load. A library should be loadable in
    `php:8.5-cli` without the appliance.
- **Size:** keep a function to one job. Split it when it grows past a screen.

### Classes

The System code is mostly procedural. Use a class only when it earns its place:

- **Exceptions:** extend `Exception` (e.g. `RestApiError`). Throw inside libraries
  and convert at the boundary: to `input_errors` for a page, or to `RestApiError`
  for the API.
- **Several interchangeable implementations:** use an `interface` and one class
  each, for example the Fleet transport drivers (WireGuard, Tailscale, Direct).
  Callers depend on the interface only.
- **Value objects** with behaviour that would otherwise travel as loose arrays
  through many functions.
- **Existing hierarchies:** `Form_*`, the shaper classes and `dyndns`. Follow the
  style of the class you extend, including the `Form_` underscore.

Rules for new classes:

- **Names:** classes and interfaces in PascalCase, with a module prefix
  (`FleetTransport`, `FleetWireGuardTransport`). Methods and properties in
  camelCase. Constants in UPPER_SNAKE.
- **Small and focused:** one responsibility each. Properties are `private` or
  `protected` unless the class is a plain data holder. Use typed properties and
  `readonly` where a value never changes.
- **No container classes:** no class whose only job is to group static functions.
  Use prefixed functions instead.
- **No namespaces in System includes.** They share one global include path. A
  package may use namespaces inside its own directory.
- **Dependencies are passed in** through the constructor, not read from globals
  inside methods. This keeps classes testable.

### Naming

- **Functions, variables and array keys:** snake_case
  (`$vault_quota_pct`, `fleet_node_membership()`).
- **Prefixes:** a function's prefix states its area. `restapi_h_*` is a REST
  handler. A REST route's helpers share its file's prefix (`restapi_mgmt_*`).
- **`config.xml` element names:** lower case, as in the existing sections. Never
  rename an existing element. Write an `upgrade_config` step if one must move.

### Configuration

- **Access:** read and write through `config_get_path()` / `config_set_path()`.
  Don't use the `$config` global in new code.
- **Saving:** `write_config()` takes a plain sentence describing the change. It is
  stored in the revision history and shown to admins.
- **What stays out of `config.xml`:** anything that changes often or is large,
  such as caches, state, replicas and logs. Those belong in a file of their own.
  `config.xml` is rewritten, copied into every revision, and pushed over HA sync.
  - Persistent data: `/cf/conf/<area>` or a dedicated dataset.
  - Data that may be lost on reboot: `/var/db`. `/var` can be a RAM disk.
- **Atomic writes:** write private files to a temporary file, `chmod 0600`, then
  `rename()`.

### Errors and logging

- **GUI save functions** return `array('input_errors' => [...])`, with messages
  wrapped in `gettext()`.
- **REST handlers** throw `RestApiError(status, code, message)`:
  - validation problems → `restapi_validation_error()` / `restapi_field_error()` (422)
  - a resource that doesn't exist → 404
  - a state conflict → 409
- **Logging:** use `logger()` with `localize_text()`. Log what happened and to
  what, never secret values.
- **No silent failures:** an error is shown, returned or logged.

### Security

- **Output:** escape every value printed in HTML (`htmlspecialchars()` / `fs_h()`).
  Wrap user-visible text in `gettext()`.
- **Commands:** pass every argument through `escapeshellarg()`. Prefer a fixed
  absolute path to the binary.
- **Input:** validate against an allowlist with an anchored regex (`/^…$/D`), so an
  identifier never reaches a path, command or config key unchecked.
- **Secrets:**
  - compare them with `hash_equals()`
  - generate them with `random_bytes()`
  - store a hash, not the secret, wherever only verification is needed
- **Never** print, log or commit a secret, and never return one from the API.
- **No unsafe remote execution:** no new XMLRPC methods, and no `eval` or
  remote-exec paths.

### REST API

- **Defining routes:** use `restapi_route()` with a privilege `page` and an
  `area`. Set `write => true` on anything that changes state. Reuse the GUI's
  functions instead of writing a second implementation.
- **Areas:** a new area goes in `restapi/areas.inc`. It creates the
  `api-<area>-read/write` privileges automatically.
- **Versioning:** a new route or response field that clients rely on raises
  `RESTAPI_LEVEL` and adds a capability in `restapi_capabilities()`. Responses
  only gain fields; never remove or rename one in v1.

### Comments

- **Explain why, not what.** Write full sentences in sentence case, and keep them
  short.
- **Function comments:** a one-line comment above a function says what it returns
  or decides, and marks `pure:` when it is.
- **Not allowed:** commented-out code, TODOs without an owner and issue, or
  personal names and addresses (in code, comments, tests, demo data or commits).

## Tests

- **One standalone test per feature:** `tests/<Feature>SmokeTest.php`. It sets the
  include path, defines the few stubs it needs (`gettext`, `userHasPrivilege`), uses
  a local `check($condition, $message)` helper, exits 1 on the first failure and
  prints one OK line.
- **What to test:** the pure functions, the route table (paths, areas, write
  flags), and every refusal path, not only the happy path.
- **Before pushing:** run lint and the smoke tests in `php:8.5-cli` exactly as
  `quality.yml` does.

## Shell and Python

- **Shell:**
  - POSIX `sh` unless the file already uses bash
  - `set -eu`; quote every variable; `sh -n` must pass
  - in port scripts, avoid environment variable names that `pkg` also reads (see
    `freesense-system-ports/AGENTS.md`)
- **Python:** 3.12, standard library only unless the repository already depends on
  more. Indent with 4 spaces, use type hints on functions, and use `pathlib` for
  paths.

## Commits

- **Author:** `dev@freesense.org` only.
- **Summary line:** starts with the area (`Fleet: …`, `FreeSense-system: …`, `docs: …`)
  and says what changed.
- **Body:** says why, and what follows in a later change.
- **One logical change per commit.** Formatting-only changes go in their own commit.
