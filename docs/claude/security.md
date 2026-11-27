# Spotter: security checklist

**Editing this file:** read it immediately before writing, and keep every rule, route, check and gap already here.

## How tenant isolation works

- The tenant is the Team (`users.team_id`). A coach's team is created on sign-up; a client takes their coach's team when created.
- There's no global tenant scope and no "current tenant" set per request. Records are owned by people (`coach_id`, `client_id`) and reached through `User::managesClient()`, `User::visibleClients()` and `User::teamCoachIds()`, and the policies. A query that doesn't start from one of these isn't scoped, so review every new query against this.
- Not tenant-scoped on purpose: the open exercise library (`exercises` with no coach), `staff_audit_logs` (staff only) and the backoffice's `TenantStats` aggregates (staff only, no client names).
- A suspended team is signed out on its next request (`TenantGuard`).

## Rules for new code

1. Every new table that belongs to a team's data carries `coach_id` and/or `client_id` (foreign key, cascade or null on delete as decided), and is only read through the helpers above.
2. No query builder or raw SQL on these tables in web requests unless the team or person filter is added by hand with a comment saying why. Current uses: `App\Services\TenantStats` (backoffice aggregates), `ResetDemoTeam` (demo team only).
3. Filter users through the team, including pickers, recipients and the user a job acts for.
4. Scope lists, dropdown options and validation, not just show pages.
5. Jobs and commands take the ids they act for and load through the owner, never "all".
6. Cache keys and file paths include the team or a globally unique record id.
7. Secrets: encrypted, hidden, never in Livewire public properties or logs. Tokens are stored as hashes.
8. Livewire public properties hold only what the page uses (they're sent to the browser).
9. Rate-limit guest posts and anything that sends email.
10. Guest routes answer the same whether or not a record exists.
11. Public site pages: no session, cookies, CSRF, forms or signed-in data (not true yet: Known gaps).
12. Uploads: size and type allow-listed, no formats a browser would run, on `MEDIA_DISK`, served through `MediaController`.
13. Outbound calls to addresses people type: HTTPS only, public addresses only, pinned, no redirects. (None today.)
14. Health and personal data never in URLs, logs or history, and never shown to staff.
15. A failing dependency audit is fixed in the same PR.

## Guest and signed routes

| Route | Why it's public | Rate limit | Test |
|---|---|---|---|
| `/` | Marketing home page | None | `HomePageTest` |
| `/help`, `/help/search`, `/help/{category}`, `/help/{category}/{article}` | Help center | None | `HelpCenterTest` |
| `/help/suggest` | Search suggestions | 120 a minute | `HelpCenterTest` |
| `GET /demo`, `/demo/leave` | Demo picker (404 when off) | 60 a minute on `GET /demo` | `DemoTeamTest` |
| `POST /demo` | Sign in as a demo person | 20 a minute | `DemoTeamTest` |
| `/invitation/{token}` | A client accepts their coach's invite | None | `OnboardingAndImportTest` |
| `/team-invitation/{token}` | A coach joins a team | None | `TeamsTest` |
| Fortify sign-in, registration, password reset, two-factor | Accounts | Login and two-factor limited | `tests/Feature/Auth` |

## Automated checks

- Cross-team access: spread across the feature tests (`TeamsTest`, `TeamsReviewRegressionTest`, `ChatTest` and others). No single cross-tenant probe yet.
- Media access: covered in the feature tests that upload.
- Demo guardrails: `DemoTeamTest`.
- No sensitive-data sweep, write probes or hardening test yet (`delivery-checklist.md`).

## Known gaps

- **No global scope.** Isolation depends on every query going through the helpers. Closing it: a cross-tenant probe test over every page and action, then a team scope on client-owned models.
- **Invitation links aren't rate-limited** and return 404 for unknown tokens. Tokens are long and random, so guessing isn't practical, but rule 9 and 10 call for a limit.
- **Public pages set a session cookie**, so they can't be edge-cached.
- **Health data** isn't encrypted field by field and has no written privacy rule yet. Waiting for Jon: `decisions/pending.md`.
- **Coach self-deletion cascades** to their clients' programs, workouts and messages (`archive/v0-status.md` → Known issues). Waiting for Jon: `decisions/pending.md`.
- **No static analysis** (Larastan was removed).
