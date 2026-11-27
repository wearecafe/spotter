# Tests keep running on SQLite for now

Status: proposed
Date: 2026-11-27
Area: CI
Decided by: Claude, at build

**Decision:** The pack runs CI on the production database (MySQL). Spotter runs Postgres in production and its tests on SQLite in memory; this PR doesn't change that. Moving CI to Postgres 16 (with the rollback-then-migrate check) is its own phase if Jon wants it.

**Turned down:** Switching CI to Postgres in this PR.

**Source:** `docs/claude/delivery-framework.md`.
