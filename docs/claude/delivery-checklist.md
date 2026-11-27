# Spotter: delivery checklist

The workflow is written once, in `CLAUDE.md` → Workflow. This doc holds the build order and the checks, with this project's commands.

## Living docs

- **Decisions:** one file per decision in `decisions/`, with a status (`decisions/README.md`). Jon's answers are `confirmed`. Choices made while building are `proposed` and listed in the PR body.
- **Phase or feature doc:** scope, access changes (→ `permissions.md`), security notes (→ `security.md`), UAT rows, and links to its decision files. Add its row to `decisions-index.md` when it's created, and update the status when it merges.
- **Module notes:** update `modules/<module>.md` when how the module is built changes.
- **UAT rows** get feature IDs (`<CODE>-<n>`, the next free number for the area's code; codes are in `demo-and-uat.md` → UAT row IDs).

## Build order per phase

1. Data: migrations with a working `down`; models owned through `coach_id` / `client_id` (`security.md`); rules on `User` or in a policy.
2. Server: access checks on every Livewire action and route; routes in `routes/web.php` under the right role middleware; sidebar items.
3. Pages: Livewire single-file pages with Flux; hide what the person can't use; public properties hold only what the page needs. French strings in `lang/fr.json` for anything a client sees.
4. Demo data: `DemoTeamSeeder`, realistic and idempotent, with loose ends.
5. Tests: every role (Owner, Admin, Coach, Client, and a coach in another team), the main workflow, validation, edge cases, French rendering.
6. Docs: help article and search synonyms, home page points and screenshots, living docs (above).
7. `composer lint` on touched files, then `composer ci:check`.

## Pre-merge checks

| Check | Command or method | Catches |
|---|---|---|
| Permission probe, every role × every new page and action | Feature tests per feature (no single probe test yet: add one) | Wrong access |
| Cross-tenant probe, security tests and diff review | Feature tests with a coach in another team; `/delivery:security-review` against `security.md` | Tenant leaks, sensitive fields, secrets |
| Dependency audit | `composer audit --locked`, `npm audit --omit=dev --audit-level=high` (CI `audit` job) | Vulnerable packages |
| Functional browser run as demo people | Not set up yet: click through as Alex, Sam, Maya and Luc on freshly seeded demo data | Broken flows, console errors, 5xx |
| Link audit | `HelpCenterTest` covers help links; app pages not yet | Links to pages the person can't open |
| UI crawl: desktop and phone, light and dark, axe | Not set up yet | Layout and accessibility bugs |
| Junk-form sweep | Validation tests per feature (no sweep test yet) | Server errors on bad input |
| Query counts per page | Not set up yet | N+1 queries |
| Volume test | Import tests (`OnboardingAndImportTest`, `NutritionTest`); add one per bulk path at realistic size | Slow imports |
| Public pages set no cookies | Not true yet (`security.md` → Known gaps) | Breaks edge caching |
| Migrations roll back | Not in CI yet | Broken rollbacks |
| Docs check | `python3 scripts/docs_check.py` (CI `docs-check` job) and `tests/Unit/Docs/` | Oversized `CLAUDE.md`, broken doc links, bad decision files, UAT ID problems |
| Runner balance | One runner, about 75 s (`decisions/2026-11-27-one-ci-runner.md`) | One slow runner holding up every PR |

Where a check says "not set up yet", the PR says so, and the phase that needs it most adds it.

## PR body

What was built; decisions as Jon made them (with their files); proposed build-time choices for Jon to confirm; each check, what it found and what was fixed; test count. Link the phase doc. The template is `.github/pull_request_template.md`.
