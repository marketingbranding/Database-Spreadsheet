# Akad Staging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a verified, project-scoped Akad staging workflow for KPR and CASH bookings that links permanently to the exact signed PPJB.

**Architecture:** Extend `PsjbWorkflow` with Akad eligibility and transactional recording, mirroring the existing PPJB workflow. Add a dedicated controller/request/views and migration/model ownership fields; extend importer protection and timeline/audit snapshots without changing BAST behavior or dashboard date semantics.

**Tech Stack:** Laravel 12, Eloquent, SQLite/MySQL, Blade, PHPUnit/Pest-compatible Laravel feature tests, Playwright browser verification.

**Spec:** `docs/superpowers/specs/2026-11-18-akad-staging-design.md`

## Global Constraints

- Scope is limited to Laravel-managed pilot projects `PRJ-JPR-KWS` and `PRJ-MGL-KLG`.
- BAST input, scheduled imports, and production cutover remain out of scope.
- Akad eligibility requires `ACTIVE`, current stage `PPJB`, and a signed PPJB for the exact booking/project.
- Both KPR and CASH use the same post-PPJB Akad eligibility rule.
- Operational Akad requires `akad_number`, `akad_date`, and free-text `quality`; notes are optional.
- One Akad record per booking; no re-Akad/correction flow.
- Imported historical records remain preserved and dashboard Akad totals use actual `akad_date`.
- Concurrent idempotency catch-and-replay and concurrent HTTP testing remain production follow-ups.
- Do not apply unrelated formatter changes; report pre-existing Pint failures separately.

## Review Focus

- PPJB from another booking/cycle, especially G09 April versus September, must return 403 and create no Akad row — covered by `AkadControllerTest`.
- Reusing an idempotency key with changed payload must return 409 and preserve the first row — covered by `AkadControllerTest`.
- A Sheets-owned PPJB should qualify only when its booking/project matches, while an unrelated PPJB ID must fail — covered by `AkadControllerTest`.
- A Laravel-owned Akad must survive stale Sheets values and protect booking stage/date rollups with `import_conflict` — covered by `ImporterAkadOwnershipTest`.
- Pre-2026 Akad and 2026 BAST must remain distinct in period metrics — covered by `AkadMetricsTest` and existing importer regression tests.

---

### Task 1: Extend Akad schema and model contract

**Files:**
- Create: `database/migrations/2026_11_18_000002_extend_akad_records_for_staging.php`
- Modify: `app/Models/AkadRecord.php`
- Modify: `app/Models/PpjbRecord.php`
- Modify: `app/Models/BookingAudit.php`
- Test: `tests/Feature/AkadWorkflowTest.php`

**Interfaces:**
- `AkadRecord::OWNER_SHEETS`, `AkadRecord::OWNER_LARAVEL`
- `AkadRecord` fillables/relations: `ppjb()`
- `BookingAudit::ACTION_AKAD_CREATED`
- Snapshot includes safe Akad fields and PPJB source fields.

- [ ] Write failing contract tests for nullable imported compatibility, owner constants, PPJB relation, unique nullable idempotency key, and audit action/snapshot.
- [ ] Run `php artisan test --filter=AkadWorkflowTest` and confirm failure on missing fields/constants.
- [ ] Add nullable `ppjb_id` FK with `restrictOnDelete`, indexed `source_owner`, and unique nullable `idempotency_key`.
- [ ] Add model constants, fillables, casts, and `BelongsTo ppjb()` relation.
- [ ] Add audit action and safe snapshot/eager-loading support.
- [ ] Run the focused test and confirm it passes.
- [ ] Commit: `feat: add Akad staging record contract`.

### Task 2: Implement transactional Akad workflow

**Files:**
- Modify: `app/Services/Operations/PsjbWorkflow.php`
- Test: `tests/Feature/AkadWorkflowTest.php`

**Interfaces:**
- `eligibleAkadBookings(Project $project): Builder`
- `recordAkad(User $actor, Booking $booking, array $input): AkadRecord`

- [ ] Add failing tests for KPR/CASH eligibility, exact booking/project PPJB checks, `ACTIVE`/`PPJB` guards, cross-project/cross-booking rejection, signed PPJB flags rejection, source-owner acceptance, stage transition, audit, sequential replay, conflicting key reuse, duplicate Akad, and exact PPJB link.
- [ ] Run the focused tests and confirm failures.
- [ ] Implement queue query requiring `whereDoesntHave('akad')`, `ACTIVE`, `STAGE_PPJB`, and a same-booking/project PPJB with false withdrawal/cancel flags.
- [ ] Implement payload validation for required number/date/quality, optional notes, and idempotency key.
- [ ] Implement transactional project → booking → lot locks, source PPJB lock/verification, idempotent replay, Akad create, booking stage/ownership update, and `AKAD_CREATED` audit.
- [ ] Return 403 for ownership/project mismatch, 422 for eligibility/validation failures, and 409 for conflicting retry or duplicate booking Akad.
- [ ] Run focused workflow tests and confirm pass.
- [ ] Commit: `feat: add transactional Akad workflow`.

### Task 3: Add Akad controller, request, routes, and Blade UI

**Files:**
- Create: `app/Http/Controllers/AkadController.php`
- Create: `app/Http/Requests/StoreAkadRequest.php`
- Create: `resources/views/akad/index.blade.php`
- Create: `resources/views/akad/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/projects/show.blade.php`
- Test: `tests/Feature/AkadControllerTest.php`

**Interfaces:**
- Named routes: `akad.index`, `akad.create`, `akad.store`.
- Controller uses `eligibleAkadBookings()` for queue/create and `recordAkad()` for POST.

- [ ] Add failing feature tests for queue/form, KPR/CASH source display, role/project/mode guards, route count, cross-project booking IDs, and invalid direct POSTs.
- [ ] Run controller tests and confirm failures.
- [ ] Implement request authorization and field rules matching the workflow contract.
- [ ] Implement controller and route group using existing project route model binding and authorization patterns.
- [ ] Implement responsive Blade queue/form with PPJB source details and hidden/selected `ppjb_id`.
- [ ] Add navigation links gated to pilot Laravel-managed projects.
- [ ] Run controller tests and confirm pass.
- [ ] Commit: `feat: add Akad queue and signing form`.

### Task 4: Preserve Akad source in timeline and importer ownership

**Files:**
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `app/Services/Import/ProjectImporter.php`
- Test: `tests/Feature/AkadTimelineTest.php`
- Create: `tests/Feature/ImporterAkadOwnershipTest.php`

**Interfaces:**
- Timeline displays Akad number, quality, notes, and `PPJB #<id>` source.
- Importer protects Laravel-owned Akad records by exact booking and reports `import_conflict`.

- [ ] Add failing timeline tests for Akad + PPJB source and separation from BAST.
- [ ] Add failing importer tests for stale value preservation, no duplicate, protected booking stage/akad date, and conflict field reporting.
- [ ] Run both tests and confirm failures.
- [ ] Add timeline eager loading and Akad entry.
- [ ] Capture Laravel Akad booking IDs during Laravel-mode import, protect matching Akad rows, and report field differences without overwriting.
- [ ] Ensure imported Akad rows resolve and populate exact `ppjb_id` when the source PPJB reference resolves to the same booking/project; report orphan/mismatch issues otherwise.
- [ ] Run timeline/importer tests and confirm pass.
- [ ] Commit: `feat: protect Akad provenance during import`.

### Task 5: Verify metrics and regression behavior

**Files:**
- Create: `tests/Feature/AkadMetricsTest.php`
- Create: `tests/Feature/AkadG09Test.php`
- Modify: `docs/psjb-staging-workflow.md`

**Interfaces:**
- No dashboard API change is expected; `PortfolioMetrics::stageCount()` remains the Akad period-count contract.

- [ ] Add failing tests proving actual `akad_date` controls period totals, historical pre-2026 Akad is excluded from 2026 totals, BAST date does not create an Akad count, and one Akad row counts once.
- [ ] Add failing G09 test proving April PPJB/Akad cannot qualify September booking despite shared legacy transaction ID.
- [ ] Run metrics/G09 tests and confirm failures.
- [ ] Implement only the minimal metric/import linkage changes required by the failing tests; do not alter BAST counting.
- [ ] Run focused Akad, existing PSJB-to-PPJB, importer, K20, and dashboard regression tests.
- [ ] Update staging workflow documentation with eligibility, routes, source ownership, and verification evidence.
- [ ] Commit: `test: verify Akad metrics and cycle isolation`.

### Task 6: Run staging migration and browser verification

**Files:**
- No new source files; use migration and browser artifacts outside the repository.

- [ ] Run `php artisan migrate --force` in staging and verify migration status.
- [ ] Start/reuse the staging server with `APP_ENV=staging` and `MARISON_OPERATIONAL_WRITES=true`.
- [ ] Verify KPR signed-PPJB → Akad for `PRJ-JPR-KWS` or `PRJ-MGL-KLG`.
- [ ] Verify CASH signed-PPJB → Akad separately; report if unavailable.
- [ ] Verify queue, form, and timeline at `1440x900` and `390x844`.
- [ ] Inspect screenshots, document width/overflow, accessibility snapshot, and console errors/warnings.
- [ ] Run `php artisan test` and report full results.
- [ ] Run `vendor/bin/pint --test`; report baseline failures separately without unrelated formatting.
- [ ] Commit documentation/verification updates if needed.

### Task 7: Final review and handoff

- [ ] Run `git diff --check`, inspect staged files for credentials/secrets, and verify `git status`.
- [ ] Review all acceptance criteria line by line.
- [ ] Report implemented rules, KPR/CASH browser status, automated test counts, Pint baseline, and production follow-ups.
