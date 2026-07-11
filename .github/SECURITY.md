# Security policy

## Supported versions

FreeSense supports the current stable minor release. After a new minor release,
the preceding line receives critical security fixes for 90 days. Development and
release-candidate builds are test channels and receive no support guarantee.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
vulnerability reporting feature for `FreeSense-org/freesense`. Include affected
versions, reproduction steps, impact, and any proposed mitigation.

The project aims to acknowledge reports within 72 hours, provide an initial
severity assessment within seven days, and coordinate disclosure after a signed
fix is available. Do not include production credentials or personal data.

## Severity and releases

- Critical: active exploitation or unauthenticated compromise; out-of-band patch.
- High: serious confidentiality, integrity, or availability impact; expedited patch.
- Medium/Low: normally included in the next monthly stable release.

Security releases use patch versions (`1.0.1`, `1.0.2`) and are built, tested,
and promoted from immutable candidates through the normal release workflow.

