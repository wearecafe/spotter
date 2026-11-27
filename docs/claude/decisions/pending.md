# Pending confirmations (backlog before the decision log)

Each row is a set of older choices, made while building or noted for later, that the product owner hasn't confirmed or overruled. They stand as built until then. New unconfirmed choices don't go here: they're decision files with `Status: proposed`.

**How this list shrinks:** the product owner confirms in batches (`/delivery:decision-batch`), usually a whole doc's set at once. Each batch becomes one decision file, and its rows here are ticked with that file's name. Never delete a row; tick it.

| Doc | What's waiting | Settled by |
|---|---|---|
| `archive/v0-status.md` (PR #2, #5) | Pricing on the home page: Starter free to 3 clients; Coach $39 for 15 clients + $1.50 per extra; Team $99. The 21-client cost comparison dated October 2026. | |
| `archive/v0-status.md` (PR #3) | Teams: owners and admins manage every client in the team, coaches their own; exercises and templates shared across the team; removing a coach hands off their clients. | |
| `archive/v0-status.md` (PR #3) | Nutrition: one entry per day; file imports only (MacroFactor, MyFitnessPal, Lose It!, generic CSV), no live sync; 7- and 30-day averages. | |
| `archive/v0-status.md` (PR #3) | Backoffice: staff see usage and estimated MRR but never client names or health data; owner password reset and suspend are audit-logged; no impersonation. | |
| `archive/v0-status.md` (PR #4) | Progression: hit targets → step up; miss → hold; skipped weeks and midweek starts don't add a step; a hand-edited deload resumes at the planned load. Program edits reach only upcoming sessions not started or edited by hand. | |
| `archive/v0-status.md` (PR #4) | Exercise library: free-exercise-db (Unlicense), photos from GitHub unless self-hosted. | |
| `archive/v0-status.md` (PR #7) | Chat: polling every 5 s, no websockets or push; unread email after 10 min with a 60-min quiet period; no per-user opt-out yet. | |
| `archive/v0-status.md` (PR #8) | Demo gym: on in production with `SPOTTER_DEMO=true`; no password sign-in; rule-based drafts for visitors; Claude only in the nightly rebuild. | |
| `archive/v0-status.md` (PR #9) | Trainer-run sessions: the trainer who runs a session gets no inbox items; in-person clients still need an email address. | |
| `archive/v0-status.md` (PR #10) | Help center: English only; no articles on pricing or the demo. | |
| `archive/v0-status.md` (Known issues) | A coach deleting their own account cascades to their clients' programs, workouts and messages. Block deletion while they have clients, or require a hand-off first? | |
| `coach-feedback.md` | Archive: read-only by default for archived clients; billing stops from the month after archiving; auto-archive window set per coach. | |
| `coach-feedback.md` | Pain flag: high-priority inbox item once per exercise per session; draft asks rather than diagnoses. | |
| `coach-feedback.md` | Habits: clients can add their own; log today or yesterday only. | |
| `coach-feedback.md` | Move a session: only within its planned week, from today on; on by default, coach can turn it off per client. | |
| `coach-feedback.md` | Restrictions: a set list matched by exercise name, flagged not blocked; swap leaves done, started and hand-edited sessions alone. | |
| `coach-feedback.md` | Deload: two sessions running over target RPE; 10% off the load or one set fewer; once per exercise per fortnight; coach applies it. | |
| `coach-feedback.md` | Progress measure (heaviest set, estimated 1RM or volume) chosen by the coach, and the client sees the same. | |
| `security.md` (Known gaps) | Health data (check-ins, nutrition, pain flags, restrictions, progress photos) isn't encrypted field by field, and there's no written privacy rule for it yet (PIPEDA, Quebec Law 25 for French clients). Jon to choose how strict, with a privacy review as a UAT row. | |
