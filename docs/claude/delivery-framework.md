# Adopting the delivery framework (2026-11-27)

Brings Spotter onto the delivery framework (`wearecafe/framework`): `CLAUDE.md`, the `docs/claude/` living docs, the decision log, UAT IDs, the PR template, the docs check in CI and the Laravel stack's docs tests. No app behaviour changes.

## Scope

- `CLAUDE.md` with Spotter's workflow, stack, commands, tenancy and access. `.gitignore` no longer hides `CLAUDE.md` or `.claude/settings.json`.
- `.claude/settings.json` turns on the `delivery` plugin from the `worksite` marketplace (`wearecafe/framework`).
- Living docs: this folder. The claude.ai Project's status doc moved here unchanged as `archive/v0-status.md`; the Project copy becomes a pointer. The 2026-11-04 work is written up in `coach-feedback.md`.
- Module notes for the help center, the demo gym and deployment, taken from the status doc.
- `decisions/pending.md` lists the choices from before the log that Jon hasn't confirmed.
- CI: a `docs-check` workflow (`scripts/docs_check.py`), the PHPUnit docs tests in `tests/Unit/Docs/`, and a dependency audit job in `tests.yml`.
- PR template: `.github/pull_request_template.md`.

## Access

No change.

## Security notes

No code change. `security.md` now records how isolation works today and the known gaps.

## Decisions

- `decisions/2026-11-27-product-owner.md` (confirmed)
- `decisions/2026-11-27-access-model.md` (confirmed)
- `decisions/2026-11-27-archive-old-docs.md` (confirmed)
- `decisions/2026-11-27-laravel-pack.md` (confirmed)
- `decisions/2026-11-27-one-ci-runner.md` (proposed)
- `decisions/2026-11-27-keep-livewire-flux.md` (proposed)
- `decisions/2026-11-27-tests-stay-on-sqlite.md` (proposed)
- `decisions/2026-11-27-dependency-audit.md` (proposed)

## UAT rows

None: nothing a person needs to check.
