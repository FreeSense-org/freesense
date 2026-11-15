## Contributing to FreeSense

FreeSense welcomes contributions, big or small — bug fixes, enhancements, and
documentation improvements.

All FreeSense source lives on GitHub under the
[FreeSense-org](https://github.com/FreeSense-org) organization. If you're new to
GitHub, see [GitHub's documentation](https://docs.github.com).

If you want to help but don't have a specific topic in mind, browse the
[open issues](https://github.com/FreeSense-org/freesense/issues).

For general questions, use
[Discussions](https://github.com/FreeSense-org/freesense/discussions).

### Security submissions

We prefer
[responsible disclosure](https://en.wikipedia.org/wiki/Responsible_disclosure)
for security concerns.

If you discover a security issue, **do not** open a public issue or pull
request. Instead, report it privately via a
[GitHub security advisory](https://github.com/FreeSense-org/freesense/security/advisories/new).

### Bug reports and feature requests

Bug reports and feature requests are tracked in
[GitHub Issues](https://github.com/FreeSense-org/freesense/issues). Please search
existing issues first, then open a new one using the **Bug report** or
**Feature request** template.

### Submitting changes

The best way to contribute code is a pull request on GitHub:

1. Fork the repo and create a topic branch off `main` (the development branch;
   stable releases live on `RELENG_x_y` branches).
2. Make your change, keeping the surrounding code style.
3. Open a pull request against `main`. Reference any related issue with
   `Fixes #NNN`.

Keep PRs focused and describe what changed and why.

#### Commit messages

Every commit becomes a line of "What is new" on System > Update of a Development
system, so the first line says what changed, for the person reading the update:

```
Area: what changed
```

For example `WebUI: the page width setting is per user and lives in Account`.
Add `Release-Note: skip` to the commit message for internal work that should not
be listed, or `Release-Note: <one line>` to word the note differently from the
subject. A check on every pull request reports commits that do not follow this.
The full rules are in
[docs/commit-messages.md](https://github.com/FreeSense-org/freesense-os-base/blob/main/docs/commit-messages.md).
