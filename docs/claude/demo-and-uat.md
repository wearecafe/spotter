# Spotter: demo data and UAT

**Editing this file:** several sessions may work at once. Read it immediately before writing, and keep every persona and row already here. New UAT rows go in their feature's own doc, not here.

## Demo personas

The demo gym is North Shore Strength (`docs/claude/modules/demo.md`). Demo people have no password: sign in with one click at `/demo` (needs `SPOTTER_DEMO=true`). Emails are `<name>@northshorestrength.example`.

| Persona | Role | Tenant | What they test |
|---|---|---|---|
| Alex | Owner | North Shore Strength | The whole gym, team management, in-person group session (Gord and Linda) |
| Jess | Admin | North Shore Strength | Managing every client without owning the team |
| Sam | Coach | North Shore Strength | Seeing only their own clients |
| Maya | Client | North Shore Strength | The client app in English |
| Luc | Client (French) | North Shore Strength | The client app and drafted replies in French |
| Jordan Ellis Coaching (`jordan.coach@example.com` / `password`) | Owner | A solo team (local `DemoSeeder` only) | A second tenant, for cross-tenant checks |
| Spotter Support (`staff@example.com` / `password`) | Staff | — (local `DemoSeeder` only) | The backoffice |

## Facts that affect testing

- No email ever leaves the demo, and demo settings and invites are locked. To test real email, use your own account and `php artisan spotter:mail-test <email>`.
- Locally, mail goes to the log unless `MAIL_MAILER` is set.
- Visitors get rule-based drafts; AI drafts appear only after the nightly rebuild (`spotter:demo:reset --force`).
- Dates are relative to today, so the demo always has a session waiting.

## UAT row IDs

Every UAT row has a feature ID, `<CODE>-<n>`: the area's code, then the next free number for that code. Each feature's rows live in its own doc. `scripts/docs_check.py` checks that IDs are unique, are never bare numbers, and use a code from this table.

To add rows, find the highest number for the code with `grep -ohE "\bCODE-[0-9]+" docs/claude/*.md | sort -t- -k2 -n | tail -1`, then number on from it. A new area gets a new code: add it to this table in the same PR.

| Code | Area | Docs |
|---|---|---|
| F | Foundation (v0, PRs #1–#11) | this doc |
| CF | Coach feedback features (2026-11-04) | `coach-feedback.md` |

## UAT checklist (foundation)

Rows only for what needs a person: real emails, phones, wording and judgement. Results are Jon's to fill in.

| # | Feature | What to check | Who | Result |
|---|---|---|---|---|
| F-1 | Email | `spotter:mail-test` reaches a real inbox from the production from-address, not spam. | Jon | |
| F-2 | Client invite | Invite yourself as a client in English and in French; read both emails and invite pages on a phone. | Jon | |
| F-3 | Team invite | Invite a second coach by email; they join and land in the team. | Jon | |
| F-4 | Unread email | Leave a chat message unread for 10 minutes; one email arrives, then nothing more for an hour. | Jon | |
| F-5 | Logger on a phone | Log a full workout on a phone: sets, RPE, rest timer, form-check video. | Jon | |
| F-6 | Trainer session | Run a group session for two in-person clients on a phone or tablet at the gym. | Jon | |
| F-7 | Demo in production | `/demo` on the live site: each persona signs in, the banner switches people, nothing can be emailed. | Jon | |
| F-8 | Home page | Read the home page pricing and comparison against what you want to charge. | Jon | |
