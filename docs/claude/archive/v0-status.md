> **Archive.** This is the Spotter status doc as it stood in the claude.ai Project on 2026-10-28 (PRs #1–#10, merged to `main`), moved into the repo unchanged on 2026-11-27 when Spotter adopted the delivery framework. What it records stands as built. Choices in it that Jon hasn't confirmed are listed in `../decisions/pending.md`. Where a file in `../decisions/` reverses something here, that file wins. Don't edit this file except to fix a broken link; record changes as decision files. Later work: `../coach-feedback.md` (2026-11-04) and `../decisions-index.md`.

# Spotter: status (2026-10-28)

Spotter is a coaching web app that competes with TrueCoach and Trainerize. The repo is github.com/wearecafe/spotter, which Jon created. Everything below is merged to `main` (currently at `1fccaaf`), and CI passes.

## What's on main
- **PR #1: the mockups.**
  - Coach Inbox with drafted replies (`/coach/inbox`, plus phone mode at `/coach/inbox/focus`).
  - Multi-week programs with progression rules (`/coach/programs/{id}`).
  - Client app: workout logging with RPE, rest timer and form-check video; weekly check-in; messages.
  - Coach onboarding (`/coach/setup`).
  - Import from TrueCoach, Trainerize or spreadsheets (`/coach/imports/{id}`).
  - Invite page and email in English and French.
- **PR #2: marketing home page.** It leads with programming ("Programming that keeps up with your clients"), then the client app, inbox, switching, pricing (Starter free to 3 clients; Coach $39 for 15 clients + $1.50 per extra; Team $99), a 21-client cost comparison dated October 2026, and an FAQ. It has a 1200×630 share preview image (`public/images/og-home.jpg`).
- **PR #3: gym teams, nutrition and the staff backoffice.**
  - **Teams.** Every coach belongs to a team. Solo coaches are a team of one; a gym has an owner, admins and coaches. Owners and admins manage every client in the team; coaches manage their own. Exercises and templates are shared across the team. Team management is at `/coach/team` (roles, email invites, removing a coach with client hand-off, reassigning clients).
  - **Nutrition.** Clients log one entry per day (weight, calories, protein, carbs, fat) at `/training/nutrition`. They can import MacroFactor CSV, MyFitnessPal export zip or Nutrition Summary CSV, Lose It! food log CSV, or a generic CSV. No live sync, because none of these apps offers an open API. Coaches set targets and see 7- and 30-day averages at `/coach/clients/{id}/nutrition`. Check-in summaries include the week's nutrition.
  - **Staff backoffice** at `/admin`. Grant access with `php artisan spotter:staff email` (`--revoke` to remove). Tenant list with usage and estimated MRR; tenant detail covers plan, free months, suspend/reactivate, staff notes and owner password reset, all audit-logged. Staff never see client names or health data.
- **PR #4: recurring programs and the open exercise library.**
  - A program is a blueprint: each training day sits on a fixed weekday with its own exercises and progression rules. Creating a program schedules every session; editing it at `/coach/programs/{id}` updates every upcoming session that hasn't been started or edited by hand. Logged history is never changed.
  - Progression re-plans from what the client logged: hit targets → step up; miss → hold; skipped weeks and midweek starts don't add a step; a hand-edited deload resumes at the planned load.
  - Single sessions can be edited ("Edited"), reset to the program until started, skipped and restored. Calendar view shows each week's status. Templates can repeat weekly.
  - Existing programs are converted by migration.
  - 876 exercises from free-exercise-db (Unlicense, pinned commit `f00c92c`) with photos, steps, muscles, equipment and level. Photos come from GitHub by default; set `EXERCISE_IMAGE_BASE_URL` to self-host.
- **PR #5: Resend email and home page update.**
  - `resend/resend-php` installed so the `resend` mailer works. `services.resend.key` reads `RESEND_API_KEY` (or `RESEND_KEY`).
  - `php artisan spotter:mail-test you@example.com` sends a test email immediately and reports the mailer and from-address.
  - Home page now covers recurring programs ("Build it once. It schedules itself."), the exercise library, a Nutrition section, a Gyms section, Team plan no longer "coming soon", new FAQs, fresh screenshots and share image.
- **PR #6: exercise library loads on migrate.** Production never ran the separate seeder step, so the library was missing. The `2026_10_30_000001_load_exercise_library` migration now runs the idempotent `ExerciseLibrarySeeder`, so a normal `migrate --force` loads it (checked on Postgres 16).
- **PR #7: coach–client chat (polling, no websockets).**
  - Coaches: `/coach/messages` lists every conversation (newest first, unread counts, search) with the thread beside it; owners/admins see the whole gym. Sidebar badge for unread.
  - Clients: `/training/messages` is a full-height chat; sidebar badge for unread.
  - Shared thread (`pages::chat.thread`): polls every 5 s while visible and skips the render when nothing changed; photos (up to 4, private, on `MEDIA_DISK`); "Seen" receipts; day separators; Enter to send on desktop.
  - `messages:notify-unread` (every 5 min) emails the recipient once when a message is unread for 10 min, with a 60-min quiet period; skips clients who haven't joined.
  - Coach replies from chat clear the client's inbox message item; inbox replies mark the client's messages read. Logic lives in `App\Services\Chat`.
  - Home page has a Chat section, nav link, Starter feature line and FAQ.
- **PR #8: demo gym at `/demo`, safe in production** (modelled on Yardline's demo yard).
  - **North Shore Strength**: 3 coaches (Alex owner, Jess admin, Sam coach), 11 clients (two train in person), team templates, recurring programs with progressed loads, check-ins, nutrition, chat, a pending team invite and a drafted Coach Inbox. Everything is dated relative to today.
  - `/demo` picker: one click to sign in as Alex (owner), Sam (coach), Maya (client) or Luc (client, French). In-app banner with "Switch person" and "Start your own". Linked from the home page and login page when on.
  - Guardrails: on only with `SPOTTER_DEMO=true`. Accounts use the reserved `northshorestrength.example` domain (blocked for sign-ups and new clients). No password sign-in, settings locked, invites blocked, and no email ever leaves the demo. Visitor activity uses rule-based drafts; Claude is used only during the nightly rebuild. Backoffice totals leave the demo out (tenant list shows a Demo badge).
  - `php artisan spotter:demo:reset --force` rebuilds only the demo (plus any demo-domain account, e.g. a coach a visitor removed) and deletes its uploaded media. Scheduled 03:00 Toronto when on. The first `/demo` visit builds it if missing.
- **PR #9: trainer-run sessions for in-gym clients.**
  - **Train** (`/coach/train`, sidebar): tick one client or several training together. Today's sessions come first; for anyone else, their next session moves to today when started (a program session is marked edited so it stays put).
  - **Session screen** (`/coach/session?w=ids`): a tab per client with sets done/total; every change saves immediately; "Last time" per exercise (e.g. "4 × 5 @ 87.5 kg"); a rest timer per client; session notes; add or drop clients mid-session; finish one or everyone. Logic lives in `App\Services\TrainerSession`.
  - "Run session" button on each client's page.
  - **In-person clients** (`users.in_person`): "In person only" when adding a client. No invite, an "In person" badge, a toggle on the client page, left out of onboarding invites, a note in Messages. Email is still required.
  - Sessions record `logged_by_id` and `session_notes`. Clients see "Logged by Alex in your session" plus the notes; the coach's history shows "Logged by …". The trainer who ran it gets no inbox items; a teammate covering the session still reports new bests to the client's coach. Finishing re-plans the program like a client-logged session.
  - Demo: Gord and Linda Wilson train in person with Alex, with a group session waiting today.
- **PR #10: help center at `/help`** (modelled on Yardline's).
  - 52 articles in 10 topics: Getting started, Clients, Programs and workouts, Training in the gym, Inbox and messages, Nutrition, Gyms and teams, Switching to Spotter, Your account, For clients. Written from how each feature works in the code.
  - Articles are Markdown in `resources/help/{topic}/{slug}.md` with front matter (`title`, `summary`, `order`, `popular`, `related`). Blockquotes starting **Tip**, **Note** or **Heads up** become callouts. Classes: `App\Help\HelpCenter`, `HelpArticle`, `HelpSearchIndex`; controller `HelpController`; Blade views in `resources/views/help`.
  - Search ported from Yardline: stems, typos, prefix-as-you-type, coaching synonyms, section snippets with highlighted words, suggestions as you type (`/help/suggest`). A synonym's weight is capped at the typed word's.
  - Linked from the sidebar (clients go to the For clients section), home footer, login page ("Trouble signing in?"), Train, setup step 2 and the progression rules box. Support card shows when `SUPPORT_EMAIL` is set.
  - Deliberately no articles on plans/pricing (billing isn't built) or the demo (404s where it's off). Help content is English only.
  - Tests check every page renders, every link and related article exists, and that real coach queries find the right article. When adding a feature, add or update its article.
- **Totals:** 222 tests and Pint is clean. Independent code reviews were run on PR #1, #3 and #4; findings are fixed with regression tests.

## Stack
Laravel 13 (PHP 8.3), Livewire 4 single-file pages, Flux (free), Tailwind 4, Fortify. Fonts are Archivo and Inter; brand colours are green #0A7A55 and lime #D4F25A. The app targets Laravel Cloud.

## Laravel Cloud setup
- **Environment variables:**
  - `ANTHROPIC_API_KEY` (optionally `ANTHROPIC_MODEL`; default is claude-sonnet-5-5)
  - `APP_TIMEZONE=America/Toronto`
  - `MEDIA_DISK`: an object storage bucket
  - `SUPPORT_EMAIL` (also shows "Email support" in the help center)
  - Email: `MAIL_MAILER=resend`, `RESEND_API_KEY`, and `MAIL_FROM_ADDRESS` on a domain verified in Resend
  - `SPOTTER_DEMO=true` to turn on the demo gym
  - Optional: `EXERCISE_IMAGE_BASE_URL`
- **Queue worker:** AI drafts, program-file reading, invite emails, team invites and unread-message emails all run on the queue.
- **Scheduler:** `inbox:scan-missed` daily at 06:00; `messages:notify-unread` every five minutes; `spotter:demo:reset --force` at 03:00 when the demo is on.
- **Deploy command:** `php artisan migrate --force` is enough; it also loads the exercise library. To refresh the library by hand: `php artisan db:seed --class=ExerciseLibrarySeeder --force`.
- After deploying: `php artisan spotter:staff <email>` for staff access; `php artisan spotter:mail-test <email>` to check email; optionally `php artisan spotter:demo:reset --force` to build the demo with AI-drafted inbox right away (also picks up new demo content such as Gord and Linda).
- **Local data:** `php artisan db:seed --class=DemoSeeder` builds the demo gym (open `/demo` with `SPOTTER_DEMO=true`), a second solo tenant, and staff@example.com / `password` for the backoffice.

## Known issues
- **A coach deleting their own account deletes their clients' programs, workouts and messages** (`workouts.coach_id`, `programs.coach_id` and `messages.coach_id` cascade on delete); the clients themselves remain, with no coach. The help article warns about this. Worth guarding: block deletion while the coach has clients, or hand them off first.

## Not built yet
- Stripe payments and billing. Plans are recorded, but nothing is charged, and plan client limits aren't enforced.
- Live sync with nutrition apps or wearables.
- Real-time chat (websockets) and push notifications; chat polls every 5 s and emails after 10 min unread.
- A per-user setting to turn off unread-message emails.
- Subdomain routing (subdomains are reserved only).
- Per-user timezones.
- Changing a client's language after they've joined (no UI), and a French coach interface.
- Gym-level branding beyond the team owner's colour and name.
- Staff impersonation ("log in as"), deliberately left out.
- Storing each logged set as its own row (still JSON on `workout_exercises`).
- In a trainer-run session: adding an extra set, swapping an exercise, or starting from a template or blank (only today's or the next scheduled session can be run).
- Session packages / counting paid PT sessions.
- Clients without an email address.
- Help center in French.
- Larastan is still removed.
