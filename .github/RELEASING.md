# FreeSense release policy

FreeSense uses `main` for development and matching `RELENG_x_y` branches for
supported releases. Stable artifacts are never built directly: a release branch
produces an immutable `x.y.z-rc.N` candidate, and the tested candidate is promoted
without rebuilding it.

## Monthly train

1. Merge features to `main` during days 1–14.
2. Freeze and build a candidate on day 15.
3. Test and fix candidates through day 25.
4. Promote on the final Tuesday after the acceptance checklist passes.

Critical security releases may bypass the calendar, but not candidate isolation,
signature verification, acceptance, or manual promotion approval.

## Required acceptance record

Record the exact source, ports, OS-definition, and FreeBSD commits; BIOS and UEFI
installation results; OTA results on two independent VMs; configuration-import
results; signature/hash checks; rollback drill; known issues; and approver. Link
that record when dispatching `Promote or roll back Stable`.

Stable promotion requires the protected `stable-release` GitHub environment.
Configure it with required reviewers and restrict deployment branches to `main`.
Protect `main` and `RELENG_*` from force pushes and require pull requests plus CI.
Review authority belongs to the FreeSense organization and its configured repository
teams; release automation must not depend on a contributor's personal namespace.

## Operator sequence

1. Open a Release acceptance issue in `freesense-os-base`.
2. Dispatch `Build ALL` with `ref=RELENG_1_0`, `candidate_id=1.0.0-rc.N`, and the
   appropriate runner. The workflow derives the isolated R2 prefix and refuses a
   direct Stable build.
3. Test the Candidate channel and complete the acceptance issue.
4. Dispatch `Promote or roll back Stable` with `operation=promote`, the candidate,
   stable version, acceptance URL, curated notes, and confirmation `PROMOTE`.
5. For an operational rollback, dispatch the same workflow with `operation=rollback`,
   the retained Stable version, acceptance/incident URL, and confirmation `ROLLBACK`.

## Retention and rollback

Stable package and ISO snapshots are immutable and retained indefinitely.
Candidates remain available through the following stable release. Rollback changes
only the public channel pointers to an earlier immutable Stable snapshot; it never
rewrites or deletes a published release.
