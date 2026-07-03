<div align="center">

<a href="https://github.com/FreeSense-org">
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/FreeSense-org/.github/main/brand/lockup-dark.png">
  <img alt="FreeSense — open firewall distro" src="https://raw.githubusercontent.com/FreeSense-org/.github/main/brand/lockup-light.png" width="440">
</picture>
</a>

### The open firewall &amp; router distribution — **open source all the way down, including the updater.**

[![License](https://img.shields.io/badge/license-Apache--2.0-EA4F2D?style=flat-square)](LICENSE)
[![Built on FreeBSD](https://img.shields.io/badge/built%20on-FreeBSD-14181F?style=flat-square)](https://www.freebsd.org/)
[![Packages](https://img.shields.io/badge/pkg-pkg.freesense.org-EA4F2D?style=flat-square)](https://pkg.freesense.org)
[![ISOs](https://img.shields.io/badge/downloads-downloads.freesense.org-14181F?style=flat-square)](https://downloads.freesense.org)

</div>

---

This is the **main source &amp; build tree** for FreeSense: a community-owned firewall and router
operating system built from source on FreeBSD — custom kernel, full web GUI, and a curated set of
networking packages. It grew out of the open-source pfSense® CE codebase, rebuilt cleanly under a
neutral name so the *entire* stack — base OS, packages, web UI, and the update client — is open and
buildable outside any vendor's private infrastructure.

## Highlights

- 🔓 **Open all the way down — including the updater.** The component that decides which firmware and
  packages your box trusts and installs is a small, readable, fully open implementation — not a closed
  vendor binary.
- 🛠️ **Self-buildable end to end.** `build.sh` builds the FreeBSD world+kernel, the core packages
  (`base`/`kernel`/`rc`), the ports, and the installer ISO — no dependency on a private build farm.
- 📦 **Reproducible from source.** OS base = stock upstream FreeBSD + a small
  [auditable patch series](https://github.com/FreeSense-org/freesense-os-base/tree/os-base/freebsd-16.0), not an opaque fork.
- 🔑 **Own your trust root.** Rebuild under Apache 2.0 with your own signing key and run an
  independent, equally-official distribution.
- 🧭 **Release &amp; devel channels** with clean cross-version upgrades, straight from the web UI.

## What's in this repo

- `src/` — the rebranded OS sources (web GUI, configuration system, `rc` scripts).
- `tools/` — the builder (`builder_common.sh`, `builder_defaults.sh`) and CI helpers.
- `build.sh` — the entry point (`--build-core`, `--update-poudriere-ports`, `--update-pkg-repo`, ISO, …).
- `.github/workflows/` — build &amp; publish pipelines.

> Built package binaries and ISO images are published to **[pkg.freesense.org](https://pkg.freesense.org)**
> and **[downloads.freesense.org](https://downloads.freesense.org)** — not stored in Git.

## Build

See [`tools/`](tools/) and `build.sh`. In short: `build.sh --build-core` for the OS base, then
`build.sh --update-poudriere-ports && build.sh --update-pkg-repo` for the packages. The
[freesense-os-base](https://github.com/FreeSense-org/freesense-os-base) repo runs these on CI and
publishes signed artifacts to R2.

## Status

Actively developed. The distribution builds from source and boots to a working installer and web GUI;
the rebrand (removing remaining upstream branding from strings, assets, namespaces, and boot
artifacts) is ongoing — any leftover `pfSense` strings are in-progress rebrand, not product naming.

---

<sub>

**Upstream &amp; license.** FreeSense is a derivative work of **pfSense® CE**, © 2004–2026 Rubicon Communications, LLC (Netgate) and earlier Electric Sheep Fencing, LLC, originally published under the Apache License 2.0. FreeSense is licensed under the **Apache License 2.0** (see [`LICENSE`](LICENSE)); original copyright notices are retained and modifications relative to upstream are documented in [`NOTICE`](NOTICE). *"pfSense" is a registered trademark of Electric Sheep Fencing, LLC, licensed to Netgate.* FreeSense is **not** pfSense and is **not** affiliated with, sponsored by, or endorsed by Netgate or Electric Sheep Fencing — the name is used only to identify the upstream project FreeSense is derived from.

</sub>
