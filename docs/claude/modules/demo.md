# Demo gym (`/demo`)

Safe in production. Modelled on Yardline's demo yard.

## What's in it

- **North Shore Strength**: 3 coaches (Alex owner, Jess admin, Sam coach), 11 clients (two train in person: Gord and Linda Wilson, with a group session waiting today), team templates, recurring programs with progressed loads, check-ins, nutrition, chat, a pending team invite and a drafted Coach Inbox. Everything is dated relative to today.
- `/demo` picker: one click to sign in as Alex (owner), Sam (coach), Maya (client) or Luc (client, French). In-app banner with "Switch person" and "Start your own". Linked from the home page and login page when on.

## Guardrails

- On only with `SPOTTER_DEMO=true`.
- Accounts use the reserved `northshorestrength.example` domain (`App\Support\Demo::DOMAIN`), blocked for sign-ups and new clients.
- No password sign-in, settings locked (`ProtectDemoAccounts`), invites blocked, and no email ever leaves the demo.
- Visitor activity uses rule-based drafts; Claude is used only during the nightly rebuild (`Demo::mayUseAi()`).
- Backoffice totals leave the demo out (tenant list shows a Demo badge).

## Rebuilding

- `php artisan spotter:demo:reset --force` (`ResetDemoTeam`) rebuilds only the demo (plus any demo-domain account, e.g. a coach a visitor removed) and deletes its uploaded media. Scheduled 03:00 Toronto when on. The first `/demo` visit builds it if missing.
- Content lives in `database/seeders/DemoTeamSeeder.php`. Locally, `DemoSeeder` builds it along with a second solo tenant (`jordan.coach@example.com`) and `staff@example.com` / `password`.
- When a feature has something to show, add it to `DemoTeamSeeder`, idempotent and with loose ends. `tests/Feature/DemoTeamTest.php` covers the guardrails.
