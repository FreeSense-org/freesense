# FreeSense prebuilt-overlay package builder — DESIGN (test branch: overlay-build)

Status: DESIGN / prototype. The CURRENT lean-seed path (main) works and stays the
default. This is an isolated experiment on the `overlay-build` branch to prove a
simpler, deterministic package-assembly model, then test it to a TEST R2 segment
before any production flip.

## The problem with the current model (why we're exploring this)
The lean-seed feeds FreeBSD's stock binaries into poudriere as *seeds*, then runs
`poudriere bulk` on the FULL ~510-port closure. poudriere RE-VERIFIES every seed
(pkgname+version+options+deps) and REBUILDS FROM SOURCE on any mismatch — and a
mismatch on a LOW-LEVEL dep CASCADES up. Example just hit: one wrong option force
(`ca_root_nss ETCSYMLINK`, excluded on FreeBSD 16) made ca_root_nss rebuild ->
lang/rust rebuilt (~2.5h) -> suricata/ntopng/snort3 workers slow + VM timeouts.
There are dozens of such latent landmines. Build time is unpredictable (30min–5h).

## The idea
Don't let poudriere re-derive the whole repo. Instead:
  FreeSense repo = FreeBSD's frozen stock binaries (verbatim) OVERLAID with the
  ~135 FreeSense-custom / option-divergent packages we build ourselves.
FreeBSD's binaries are FROZEN INPUTS — never re-verified, never cascade-rebuilt.

## What does NOT change (crucial — scoped to ONE layer)
FreeSense has 3 independent layers. This touches only #2.
  1. Base OS (buildworld + buildkernel from patched freebsd-src -> base.txz/kernel) — UNCHANGED
  2. Packages (the pkg repo)                                                        — THIS
  3. ISO assembly + installer + bootloader rebrand                                  — UNCHANGED
Also unchanged: the updater (repoc points the box at ONE FreeSense repo, box is
agnostic to how it was assembled), signing (catalog-level), the branch selector,
the frozen-stock-snapshot job.

## Why the hard problems are already solved
- **ABI**: NOT a new risk. The current lean-seed ALREADY mixes FreeBSD stock
  binaries with our world, keyed to the pin's __FreeBSD_version via the frozen
  snapshot. Overlay reuses that SAME frozen snapshot as its base layer -> same
  ABI guarantee, no new drift. (Snapshot records FreeBSD_version + ports hash.)
- **Signing**: `pkg repo <dir> signing_command:<key>` signs the CATALOG
  (packagesite/meta); individual .pkg are trusted via checksums INSIDE that signed
  catalog. So FreeBSD-origin binaries dropped into All/ and cataloged by OUR
  `pkg repo` are trusted under OUR fingerprint with NO per-package re-sign. This
  is EXACTLY how today's rust seed already ships (builder_common.sh:1516).
- **Updater**: repoc writes one repo conf -> our server, FreeBSD:{enabled:no},
  signature_type:fingerprints. The box never sees FreeBSD's repo. Assembly method
  is invisible to it.
- **Kernel/world/boot/ISO**: separate layers, untouched.

## The frozen snapshot we build on (already exists)
`R2:freesense-pkg/ports-cache/stock/<chan>/<REV>/`
  packages.tar        — all FreeBSD stock .pkg at top level (untars into All/)
  ports_top_git_hash  — the freebsd-ports commit binaries were built from
  meta / FreeBSD_version
`stock/<chan>/current` -> <REV>  (the ready signal)
The overlay build CONSUMES packages.tar as its base layer. Same input the
lean-seed already uses — we just don't re-verify it.

## New builder design

### Component A — the "what to build ourselves" set (deterministic)
Union of:
  (a) overlay origins: everything under tools/conf/pfPorts overlay
      (FreeSense-*/vendored/patched) — the ~117 custom ports;
  (b) option-divergent origins: parsed from make.conf per-port *_SET/UNSET_FORCE
      (the ~52 must-build.extra list) — stock ports where OUR options differ from
      FreeBSD's default binary so FreeBSD's binary genuinely doesn't fit.
  => the CUSTOM SET (~135). Everything else is PURE STOCK -> use FreeBSD's binary.
This is computed statically from the repo, NOT from a poudriere -n closure ->
no cascade, no "why did this rebuild" surprises.

### Component B — build ONLY the custom set (thin poudriere)
  - jail from the pin's base seed (same as today);
  - ports tree pinned to the frozen ports_top_git_hash (same as today);
  - overlay applied (same as today);
  - UNTAR packages.tar into the poudriere repo All/ FIRST, and regen the catalog,
    so the custom ports' BUILD-DEPS resolve to FreeBSD's prebuilt binaries
    (pkg-install as build deps, NOT compile);
  - `poudriere bulk -f <CUSTOM SET ONLY>` (not the full closure). poudriere builds
    the ~135 custom ports; their stock build/run deps come from the untarred
    binaries. No stock port is ever a bulk TARGET -> no ca_root_nss->rust cascade.
  KEY DIFFERENCE from lean-seed: the bulk LIST is the custom set, not the full
  closure. Today's bulk lists all 510 and RELIES on reuse; here we list only what
  we actually build.

### Component C — merge + sign (repo assembly)
  finalAll/ = packages.tar (FreeBSD stock, verbatim)  UNION  custom-built .pkg
  RESOLUTION: on name collision, OUR package wins (a FreeSense-patched openssl,
    if any, shadows FreeBSD's). Implemented by copying stock first, then custom
    on top (custom overwrites), OR by pkg-version compare. Custom pkgs either
    have FreeSense-* names FreeBSD never publishes (no collision) or a divergent
    build of a stock name (we want ours).
  Then: `pkg repo finalAll/ signing_command:<FREESENSE key>` -> one signed
  catalog under our fingerprint. Same command the current build already runs.
  Sync finalAll/ + catalog to R2 production segment (or TEST prefix).

### Component D — the workflow
New `ports-overlay-build.yml` (on overlay-build branch):
  decide -> snapshot (reuse existing) -> ONE build job (the custom set is small
  enough it likely fits one runner < cap; keep the dynamic-worker split available
  if not) -> merge+sign+publish.
No 6-category / 12-worker fan-out NEEDED (only ~135 thin builds), though the
dynamic split can still apply if the custom set ever grows.

## Risks + mitigations
- R1 stale/incomplete snapshot: if a custom port needs a stock dep NOT in
  packages.tar (a new dep added since the snapshot), that dep builds from source
  (poudriere falls back). Self-healing, costs one build. Same as today.
- R2 collision resolution wrong: a FreeSense-divergent stock pkg must WIN over
  FreeBSD's. Verify by version/explicit shadow list. Test: install a package that
  pulls a divergent stock dep, confirm OURS is installed.
- R3 catalog completeness: the merged catalog must list the FULL closure the box
  can install (stock + custom), all resolvable under our fingerprint. Verify:
  published packagesite lists ~510 pkgs; deep-dep install resolves 100% local.
- R4 ABI: unchanged from today (frozen snapshot pins FreeBSD_version).

## Test plan (before ANY production flip)
1. Build to `r2_prefix=test-overlay/` (never production segment).
2. Assert: finalAll has ~510 pkgs, catalog signed under FreeSense fingerprint,
   only ~135 were actually compiled (log the count), NO rust/php85 from-source
   (they came from packages.tar).
3. On a throwaway box (or the test VM): point repoc at the test repo, then
   `pkg install FreeSense-pkg-suricata` — must resolve suricata + rust + python +
   libs ENTIRELY from our repo (never pkg.freebsd.org), all validating under our
   fingerprint. `pkg upgrade` clean. `pkg check -d` clean.
4. Diff the produced repo's pkg SET against a current-model build — same closure?
5. Only if all green: consider promoting. Old lean-seed stays as fallback.

## Files (all on overlay-build branch)
- freesense-src/tools/ci/freesense-overlay-build.sh   (NEW — components A/B/C)
- freesense-src/tools/builder_common.sh               (hook: overlay mode in
                                                        poudriere_bulk, gated by
                                                        FREESENSE_OVERLAY=1)
- freesense-os-base/.github/workflows/ports-overlay-build.yml (NEW workflow)
- keep the existing lean-seed + ports-build.yml untouched (the working default).
