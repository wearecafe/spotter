# Spotter: phase and feature index

**Start here.** Each phase or feature keeps its scope, access changes, security notes and UAT rows in its own doc, listed below. Its decisions are files in `decisions/`.

**Rules for sessions:**
- A new phase or feature gets its own doc (`phase-NN.md`, or a named doc like `billing.md`) with sections for scope, access (→ `permissions.md`), security and UAT rows (feature IDs: `demo-and-uat.md` → UAT row IDs), and links to its decision files.
- Add one row below when the doc is created, and update its status when it's built and merged. Edit only your own row (or add one) in a small change, rebase before merging, and keep every row.
- Where a newer doc and an older one disagree, the newer one wins, and it says what it reverses.

| Phase or feature | Doc | Status | Changes access? | UAT rows |
|---|---|---|---|---|
| v0: PRs #1–#11 (mockups to help center), before the log | `archive/v0-status.md` | Merged | Yes (teams, staff, in-person clients) | F-1 to F-8 |
| Coach feedback features (2026-11-04, pushed to main) | `coach-feedback.md` | Merged | Yes (archived clients) | CF-1 to CF-8 |
| Adopting the delivery framework | `delivery-framework.md` | In review | No | None |

## Reference docs

- `delivery-checklist.md`, `permissions.md`, `security.md`, `demo-and-uat.md`, `modules/`, `archive/`.
