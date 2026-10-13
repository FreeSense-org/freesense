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

Stable artifacts are never built from a moving branch. A release is an exact
lock file, `config/releases/<version>.json` in `freesense-os-base`, which pins
the `freesense`, `freesense-system-ports` and `freesense-packages` commits, the
FreeBSD source and ports commits, and the worker inputs.

1. Verify the commits to release in a Development build and on the test VM.
2. Add the sealed lock (`schema_version: freesense.release-lock/v1`) and a pull
   request that passes CI. Patch releases (`1.1.1`, ...) add a new lock only.
3. Dispatch `Publish FreeSense Stable train` (`stable.yml`) with the exact
   `release` and, if wanted, `queue_development`. The workflow plans from the
   lock, builds System and Optional Packages, seals and signs the Stable
   channel, builds the installer images, publishes the downloads, and finally
   writes the signed release document. The `channel-publisher` GitHub
   environment gates publication.
4. Confirm the live release documents and download metadata
   (`pkg.freesense.org/v1/releases/stable.json`) and the website.

System and Optional Packages are planned independently: a System-only change
does not force an Optional Packages rebuild.

## Fixes to a released line

Only necessary security fixes are released as Stable patches. Ordinary fixes
land on `main` and ship with the next line.

## Retention

Stable package, ISO and image snapshots are immutable and retained
indefinitely, including those of earlier Stable lines.
