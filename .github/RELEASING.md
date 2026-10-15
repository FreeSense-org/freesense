# FreeSense release policy

FreeSense has two channels, planned and published from `freesense-os-base`:

- **Stable** is the immutable `x.y.z` release line (currently `1.1.x`). Every
  Stable release is built from a sealed release lock and is never rebuilt or
  rewritten once published.
- **Development** is the rolling next line (currently `1.2`). Development builds
  are experimental and unsupported.

Upgrading from an older Stable line to a newer one is one-way: an installed
system never downgrades to an earlier line.

## How a Stable release is made

The full rules (R1–R9) are in `freesense-os-base/docs/release-policy.md`.

Stable artifacts are never built from a moving branch. A release is an exact
lock file, `config/releases/<version>.json` in `freesense-os-base`, which pins
the `freesense`, `freesense-system-ports` and `freesense-packages` commits on
the maintenance branch (`RELENG_1_1`), the FreeBSD source and ports commits,
and the worker inputs. Locks are append-only.

1. Fix on `main`, then cherry-pick to `RELENG_1_1` with `git cherry-pick -x`
   through a pull request.
2. Add the next lock (`1.1.N`), proposed daily by `stable-refresh.yml` from
   the `RELENG_1_1` heads. A version number is never reused: a cancelled or
   broken release is listed in `config/releases/withdrawn.json`.
3. Dispatch `Publish FreeSense Stable train` (`stable.yml`) with
   `stage=candidate`. It builds System and Optional Packages; nothing goes
   live.
4. Upgrade-test the current Stable release to the candidate on a test VM.
5. Dispatch it again with `stage=seal`. After approval in the
   `stable-publisher` environment it signs the Stable channel (live on
   devices), tags `v1.1.N` in the four repositories, builds the installer
   images and publishes the downloads and release documents. Seals are at
   least 24 hours apart unless the lock gives a reason.
6. Confirm the live release documents
   (`pkg.freesense.org/v1/releases/stable.amd64.json`) and the website.

System and Optional Packages are planned independently: a System-only change
does not force an Optional Packages rebuild.

## Fixes to a released line

Stable patches are cut for security fixes, broken-update or regression fixes,
or a reviewed batch of fixes. Features land on `main` and ship with the next
line.

## Retention

Stable package, ISO and image snapshots are immutable and retained
indefinitely, including those of earlier Stable lines.
