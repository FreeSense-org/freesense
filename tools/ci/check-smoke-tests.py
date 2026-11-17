#!/usr/bin/env python3
# Copyright (c) 2026 The FreeSense Project
# SPDX-License-Identifier: Apache-2.0
"""Fail when a standalone smoke test is omitted from the workflow's executable list."""
import re
from pathlib import Path

root = Path(__file__).resolve().parents[2]
workflow = (root / ".github/workflows/quality.yml").read_text()
match = re.search(r"for t in \\(?P<tests>.*?)\s*;?\s*do\n", workflow, re.S)
if match is None:
    raise SystemExit("Could not locate the smoke-test execution list")
listed = set(re.findall(r"\b[A-Za-z0-9_]+SmokeTest\b", match.group("tests")))
expected = {path.stem for path in (root / "tests").glob("*SmokeTest.php")}
if listed != expected:
    raise SystemExit(f"Smoke-test list mismatch: missing={sorted(expected - listed)}, stale={sorted(listed - expected)}")
print(f"All {len(expected)} standalone smoke tests are included in CI.")
