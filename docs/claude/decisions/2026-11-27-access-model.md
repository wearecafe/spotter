# The Team is the tenant; Owner > Admin > Coach, then Client; staff apart

Status: confirmed
Date: 2026-11-27
Area: Access
Decided by: Jon

**Decision:** The tenant is the Team (`users.team_id`); a solo coach is a team of one. Roles are team Owner > Admin > Coach, then Client. Spotter staff reach only the backoffice and never see client names or health data. `security.md` and `permissions.md` are written from this, as the code has it today.

**Source:** `docs/claude/delivery-framework.md`.
