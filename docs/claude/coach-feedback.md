# Coach feedback features (2026-11-04)

Ten commits pushed straight to `main` on 2026-11-04 (`593cc32` to `7318929`), before the decision log. Written up on 2026-11-27 from the commits and the code so the work has a doc like any other phase. Everything here stands as built. The choices Jon hasn't confirmed are rows in `decisions/pending.md`.

## Scope

| Feature | What it does | Commit | Tests |
|---|---|---|---|
| Archive clients | Archived clients keep every workout, check-in and message, and leave the client list, inbox, Train and missed-session alerts. They stop counting toward the bill from the month after they're archived. Per client, the coach chooses whether an archived client can still view their history (read-only) or is locked out. Coaches can archive inactive clients automatically after a window they set (`clients:auto-archive`, daily 05:30). Permanent delete stays, separately, for erasure requests. | `593cc32` | `ClientArchiveTest` |
| Exercise history and progress | A progress page for coaches and clients lists every lift with first, best and latest numbers, a sparkline and a per-session history. The coach chooses heaviest set, estimated 1-rep max or total volume, and the client sees it the same way. The client logger shows last time. | `117fbc0` | `ExerciseHistoryTest` |
| Exercise notes and pain flag | Clients leave a note on any exercise and can flag that it caused pain. A pain flag raises a high-priority Coach Inbox item as soon as it's saved (once per exercise per session), with a rule-based draft that asks about it rather than diagnosing. | `35b81c8` | `ExerciseNotesTest` |
| Habits | Coaches add habits for a client and clients add their own: ticked off or a number against a target, daily or a set number of days a week. Clients log today or yesterday; coaches see this week and the last seven days. Stopping a habit keeps its history. | `6c9ec56` | `HabitsTest` |
| Move a session | A client can move a session they haven't started to any day from today to the end of its planned week. The coach gets a low-priority inbox heads-up and can turn moving off per client. A moved program session keeps taking program edits. | `609f64f` | `ReschedulingTest` |
| Injury restrictions and swap | Coaches record restrictions from a set list (knee: no deep knee bends, shoulder: nothing overhead, and so on) with a side and a note. The workout builder, program page and exercise picker flag conflicts by matching exercise names, and say to use judgement too. Swap replaces an exercise on every day of a program, keeping sets, reps and rules; done, started and hand-edited sessions keep theirs, and the coach is told how many edited sessions to check. | `958d5e6` | `RestrictionsAndSwapTest` |
| Deload suggestion | When a completed session has a set above the exercise's target RPE and the previous session with RPE logged did too, the inbox suggests a deload: 10% off the next session's load, or one set fewer with no load. Worked out by rule, never by the model, and reaches the client only when the coach applies it. One suggestion per exercise per fortnight. | `912f9f1` | `DeloadSuggestionTest` |
| French | French for the new client-facing screens and drafted replies; moved-session headlines use the coach's language. | `0855e33`, `c2158d4` | French render checks in the tests above |
| Help and home page | Seven new help articles and updates to six; search synonyms for the new wording. The home page covers progress, habits, moving sessions, pain flags, injury-aware programming, deloads and archived clients not counting toward the bill. | `fa57573` | `HelpCenterTest`, `HomePageTest` |
| Phone layout | The logger's How to and Form check buttons sit under the exercise name on small screens. | `7318929` | — |

## Access

- Archived clients: read-only by default or locked out, per client (`users.archived_access`). `TenantGuard` signs out locked clients; read-only clients see history and change nothing (`User::isReadOnlyClient()`).
- Habits, restrictions, progress settings and the move-session toggle are managed by whoever manages the client (`User::managesClient()`): the client's coach, or a team owner or admin.
- Recorded in `permissions.md`.

## Security notes

- Pain flags, notes and restrictions are health information about the client. They're visible only to the client and to coaches who manage them, never to staff.
- Archiving keeps data; erasure is the separate permanent delete.

## UAT rows

| # | Feature | What to check | Who | Result |
|---|---|---|---|---|
| CF-1 | Archive | Archive a client set to locked; they can't sign in and see the archived message. Unarchive and they can. | Jon | |
| CF-2 | Archive | Auto-archive after the window you set, then check the client's history is all there. | Jon | |
| CF-3 | Pain flag | As a client on a phone, flag pain on an exercise. The coach's inbox shows it at the top with a draft that asks rather than diagnoses. Read the draft's wording in English and French. | Jon | |
| CF-4 | Habits | As a client on a phone, log a daily and a weekly habit for today and yesterday; the coach sees the week. | Jon | |
| CF-5 | Move a session | As a client, move a session later in the week; check the coach's heads-up and that a program edit still reaches it. | Jon | |
| CF-6 | Restrictions | Add a knee restriction and check the conflicts flagged in the workout builder make sense to a coach. | Jon | |
| CF-7 | Deload | After two over-RPE sessions, read the deload suggestion and apply it; the client sees the lighter load. | Jon | |
| CF-8 | French | As Luc in the demo, read the new client screens (progress, habits, move a session, notes) for wording. | Jon | |

## Decisions

None recorded at the time. The choices above that need Jon's confirmation are in `decisions/pending.md` under this doc.
