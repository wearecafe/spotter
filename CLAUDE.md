# Spotter

Spotter is a coaching web app for personal trainers, online coaches and gyms, competing with TrueCoach and Trainerize. Programming is the core: recurring programs with progression rules. Around it sit the client app (logging, check-ins, nutrition, habits, chat) and the Coach Inbox with drafted replies. It runs on Laravel Cloud. Coaches and clients use English or French.

Product scope, decisions and the access model live in `docs/claude/` in this repo, the only master copy. Don't keep copies in a claude.ai Project or elsewhere. (The Project keeps the design mockups only, as a visual reference.)

- **Decisions:** each decision is its own file in `docs/claude/decisions/` with a status (`docs/claude/decisions/README.md`). Never re-ask or undo a confirmed decision. Waiting ones are files with `Status: proposed` and the rows in `docs/claude/decisions/pending.md`.
- **Phases and features:** each keeps its scope, access changes, security notes and UAT rows in its own doc in `docs/claude/`, listed in `docs/claude/decisions-index.md`.
- **Before the decision log:** PRs #1–#11 are recorded in `docs/claude/archive/v0-status.md`, and the 2026-11-04 work in `docs/claude/coach-feedback.md`. What they record stands as built.

## Workflow

This section is the one written copy of the workflow. Other docs, and any Project instructions, point here.

- One phase per PR. Commit and push as you go. Squash-merge when CI is green, then start the next phase. Stop only for questions. Never push features straight to `main`.
- Ask before any judgement call, as multiple choice with the recommended option first (always through the multiple-choice question tool, never as text in a reply), especially anything legal or compliance-related, and anything about health data. Describe the rule and let Jon choose. Never build a rule in on an assumption.
- Never lose existing management functionality or fields.
- Docs travel with the work. A PR adds a decision file for each choice made (Jon's answers as `confirmed`, your own build-time choices as `proposed`, listed in the PR body), and in the same PR updates its phase doc (access, security notes, UAT rows), its `decisions-index.md` row and the module notes. Several sessions may work at once, so keep doc edits small, rebase on main before merging, and never settle a conflict by dropping another session's entries.
- Every feature ships a help article (`resources/help/{topic}/{slug}.md`, see `docs/claude/modules/help-center.md`), marketing-site points (the home page, `resources/views/welcome.blade.php`), French for anything a client sees (`lang/fr.json`), and UAT rows with feature IDs (`docs/claude/demo-and-uat.md` → UAT row IDs). Add the feature to the demo gym when it has something to show.
- Run the pre-merge checks in `docs/claude/delivery-checklist.md` and report what they found in the PR.
- Use the `delivery` plugin's skills: `/delivery:feature-shaping` before building, `/delivery:phased-delivery` while building, `/delivery:security-review` before merging, and `/delivery:decision-batch` to confirm what's pending.

## Keep this file short

This file is loaded into every session, so it holds only what every session needs: workflow, stack, commands and cross-cutting rules. How a module is built goes in `docs/claude/modules/<module>.md`, linked below. CI fails when this file passes 16 KB or links a `docs/claude/` file that doesn't exist (`scripts/docs_check.py`, and `tests/Unit/Docs/`).

## Module notes (read before working on that area)

| Area | Notes |
| ---- | ----- |
| Help center (`/help`) | `docs/claude/modules/help-center.md` |
| Demo gym (`/demo`) | `docs/claude/modules/demo.md` |
| Hosting, environment, queue and scheduler | `docs/claude/modules/deployment.md` |

When a module gets substantial work, start its notes file and add a row here.

## Stack

- Laravel 13 (PHP 8.3) on Laravel Cloud. Fortify for sign-in (email and password, TOTP two-factor). Livewire 4 single-file pages (`resources/views/pages`, routed with `Route::livewire`), Flux (free) and Tailwind 4. Stay with Livewire and Flux: one design system across the app.
- Postgres in production. Tests run on SQLite in memory (`phpunit.xml`). Every migration has a working `down`.
- Fonts (Archivo, Inter) are self-hosted. No third-party font or script requests from the app. Exercise photos load from GitHub unless `EXERCISE_IMAGE_BASE_URL` is set.
- Uploads (form checks, check-in and chat photos) go to `MEDIA_DISK`, a private bucket in production, and are served only through `MediaController`, which checks access. Never `->url()` or public visibility.
- Queues: AI drafts, program-file reading and every email run on the queue. Claude (`ANTHROPIC_API_KEY`) only drafts; rules (progression, deloads, restrictions) are worked out in code, never by the model.
- Email through Resend. Mail goes out in the recipient's language (`User::preferredLocale()`).

## Commands

- `composer ci:check`: Pint check and the full PHPUnit suite. Must pass before pushing. (No static analysis yet: Larastan was removed.)
- `composer lint`: format. Only format files you touched.
- `php artisan db:seed --class=DemoSeeder`: local demo data (the demo gym, a second solo tenant, and `staff@example.com` / `password` for `/admin`). Never in production.
- `php artisan spotter:demo:reset --force`: rebuild only the demo gym.
- `python3 scripts/docs_check.py`: the living-docs check (also in CI).

## Tenancy (read before adding any table)

- The tenant is the **Team** (`teams`, key `users.team_id`). Every coach belongs to one (a solo coach is a team of one, created on sign-up), and a client takes their coach's team.
- There's no global tenant scope. Data hangs off people: `coach_id` and `client_id` on workouts, programs, check-ins, messages, nutrition and habits. Scoping goes through `User::managesClient()` and `User::visibleClients()` (own clients, or the whole team for owners and admins), `User::teamCoachIds()` for shared exercises and templates, and the policies. A query that skips these isn't scoped. See `docs/claude/security.md`.
- `TenantGuard` signs out people of a suspended team and locked-out archived clients.

## Access

- Roles, in order: team **Owner** > **Admin** > **Coach** (`users.role = coach`, `users.team_role`), then **Client** (`role = client`). **Staff** (`users.is_staff`) is separate and only reaches `/admin`, never client names or health data.
- The rules live on `User` (`isTeamOwner`, `isTeamAdmin`, `managesClient`, `visibleClients`) and in `app/Policies`. Routes are split by `role:coach`, `role:client` and `staff` middleware. Every action checks access.
- When access changes, update `docs/claude/permissions.md`, the access tests and the roles help article (`resources/help/teams/`).

## Design

- Design within the documented system: the Flux components, the brand in `resources/css/app.css` (green #0A7A55, lime #D4F25A, Archivo and Inter) and the mockups in the claude.ai Project. Don't make different parts of the app look like different software.
- Make it enjoyable to use, but nothing is added for decoration. The design serves the jobs people do. Phones first for clients and for trainers on the gym floor; touch targets at least 44 px.

## Public pages

- The home page (`/`), `/help` and `/demo` are public. They still run in the `web` middleware group, so they set a session cookie and can't be edge-cached yet (see `docs/claude/security.md` → Known gaps).
