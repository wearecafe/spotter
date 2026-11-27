# Spotter: access model

**Editing this file:** read it immediately before writing, and keep every rule already here. A phase that changes access records the change in its own doc too.

## Roles

| Role | Can | Can't |
|---|---|---|
| Owner (team) | Everything an Admin can. The team's account holder, one per team (`teams.owner_id`). | Be removed or have their role changed by anyone in the team; reach `/admin` unless also staff. |
| Admin (team) | Manage every client in the team (programs, workouts, check-ins, nutrition, habits, restrictions, chat, archive); manage the team at `/coach/team` (`manage-team` gate): invite and remove coaches with client hand-off, set roles to Admin or Coach, reassign clients. | Remove or change the Owner, or change their own role. |
| Coach (team) | Manage their own clients; use and add to the team's shared exercises and templates; run trainer sessions for clients they manage. | See or manage other coaches' clients; manage the team. |
| Client | Log their workouts, check-ins, nutrition and habits; move a session within its week (if allowed); chat with their coach; see their progress. | See anything of another client; change anything once archived (read-only) or sign in at all if archived and locked. |
| Staff (`is_staff`) | `/admin`: tenant list, usage, estimated MRR, plan, free months, suspend and reactivate, staff notes, owner password reset, audit log. | See client names or health data; impersonate anyone. |

## Where the rules live

- `App\Models\User`: `isTeamOwner()`, `isTeamAdmin()`, `managesClient()`, `visibleClients()`, `teamCoachIds()`, `isReadOnlyClient()`.
- Gate `manage-team` (Owners and Admins) for `/coach/team`.
- Policies: `WorkoutPolicy` (view, update, delete, run, log) and `ExercisePolicy`.
- Route middleware: `role:coach`, `role:client` (`EnsureUserHasRole`) and `staff` (`EnsureStaff`, 404 for anyone else). `TenantGuard` signs out suspended teams and locked archived clients. All are persistent Livewire middleware, so they run on every Livewire update too.
- Every action checks access. Team management goes through `App\Services\TeamManager`.

## Sign-in rules

- Fortify: email and password, registration, password reset, optional TOTP two-factor with confirmation. Login and two-factor are rate-limited (`FortifyServiceProvider`).
- Clients join through their coach's invitation link; coaches join a team through an emailed link.
- Demo accounts (`northshorestrength.example`) have no password sign-in and locked settings.
- Two-factor isn't required for any role yet.

## Contextual access

- A coach manages a client when `client.coach_id` is theirs, or when they're the team's Owner or Admin and the client is in their team.
- Uploaded media is served by `MediaController` only to the client it belongs to or a coach who manages them.
- Archived clients: read-only (default) or locked, per client (`users.archived_access`).

## Checklist for a feature that changes access

1. Add the rule to `User` or a policy. No checks scattered in pages.
2. Add every new page and action to the access tests, for every role (there's no single permission probe test yet: `delivery-checklist.md`).
3. Update the roles help article (`resources/help/teams/roles.md`) and this file.
4. Record the change in the phase doc's Access section and its `decisions-index.md` row.
