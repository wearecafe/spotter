# Tests stay on one CI runner until the suite passes 10 minutes

Status: proposed
Date: 2026-11-27
Area: CI
Decided by: Claude, at build

**Decision:** The pack's sharded test workflow (four runners, timings, coverage floors) isn't installed. The suite is 278 tests and runs in about 72 seconds on one machine, and the pack itself says to move to sharding once the suite takes more than about 10 minutes. `tests.yml` keeps its single job and gains a dependency audit job.

**Turned down:** Installing sharding and coverage floors now.

**Source:** `docs/claude/delivery-framework.md`.
