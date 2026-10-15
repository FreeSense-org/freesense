# freesense agent rules

Base product source: installer, WebUI, system behavior. Release rules are in
`FreeSense-org/freesense-os-base` `docs/release-policy.md` (R1–R9).

- `main` is Development (1.2). Features and fixes land here first.
- `RELENG_1_1` is the Stable 1.1 maintenance branch. Only fixes reach it, as
  `git cherry-pick -x` of a `main` commit through a pull request against
  `RELENG_1_1`; a 1.1-only fix says why in its pull request. Never push to it
  directly, never force-push it.
- `v1.1.N` tags mark released commits. They are created by the Stable seal
  and never moved or deleted.
- `src/etc/version` stays `<development version>-DEVELOPMENT`; Stable builds
  stamp their own version from the release lock.
- Every file under `src/` must be listed in the FreeSense-system pkg-plist in
  `freesense-system-ports`; CI audits both repositories.
- Merge with `gh pr merge --rebase` (keeps the `dev@freesense.org` author).
