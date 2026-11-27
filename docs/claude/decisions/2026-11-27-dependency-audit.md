# Dependency audit runs in CI

Status: proposed
Date: 2026-11-27
Area: CI
Decided by: Claude, at build

**Decision:** `tests.yml` gains an audit job: `composer audit --locked` and `npm audit --omit=dev --audit-level=high`. A failing audit is fixed in the PR that finds it (`security.md` rule 15).

**Turned down:** Auditing dev dependencies and low-severity advisories too (more noise than signal for now).

**Source:** `docs/claude/delivery-framework.md`.
