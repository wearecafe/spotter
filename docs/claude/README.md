# Spotter product docs

Scope, decisions, access, security notes, specs and UAT rows. **This folder is the master copy.** Start with `CLAUDE.md` at the repo root, then `decisions-index.md`.

| Path | Holds |
|---|---|
| `decisions/` | One file per decision, with a status. `pending.md` holds older choices still waiting for confirmation. |
| `decisions-index.md` | Every phase or feature doc, its status, whether it changes access, and its UAT rows. |
| `modules/` | How each module is built now: classes, tables, jobs, tests, demo data. Linked from `CLAUDE.md`. |
| `permissions.md` | Roles, access rules, sign-in rules, and the checklist for new features. |
| `security.md` | How tenant isolation works, rules for new code, guest routes, automated checks, known gaps. |
| `demo-and-uat.md` | Demo personas, facts that affect testing, UAT row ID codes, foundation UAT rows. |
| `delivery-checklist.md` | Build order and pre-merge checks for this stack. |
| `<phase or feature>.md` | One per phase or feature: scope, access changes, security notes, UAT rows, links to its decisions. |
| `archive/` | Docs from before the decision log (the v0 status doc, PRs #1–#11). Read-only; what they record stands. |

Each PR updates its own docs in the same PR (`CLAUDE.md` → Workflow).
