# BI Checking Staging Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add staging-only BI Checking queue and decision workflow for KPR bookings in `PRJ-MGL-KLG` and `PRJ-JPR-KWS`, while proving PSJB same-lot concurrency on MySQL.

**Architecture:** Extend existing `PsjbWorkflow` with BI queue and transactional BI decision methods. Add Laravel ownership metadata to `bi_checks`, preserve Laravel BI values during Sheets imports, and record booking-plus-BI snapshots in existing immutable audits. Keep rejected BI bookings allocated until explicit Withdraw/Cancel.

**Tech Stack:** Laravel 12, PHP 8.3, Blade, Eloquent, MySQL/MariaDB, SQLite tests, PHPUnit, Playwright.

**Spec:** User request; `docs/source-audit.md`; `docs/psjb-staging-workflow.md`.

## Global Constraints

- Writes require `APP_ENV=staging` and `MARISON_OPERATIONAL_WRITES=true`.
- Only assigned `branch_admin` users may submit BI results or BI-stage decisions.
- HQ may view project-scoped BI queues but remains read-only.
- BI decision values are exactly `LANJUT` and `TIDAK LANJUT`.
- KPR queue is project-scoped and opens records by internal booking ID.
- `TIDAK LANJUT` does not release stock automatically; explicit Withdraw/Cancel releases only when no valid claim remains.
- Valid lot claims remain `ACTIVE`, `COMPLETED`, or `NEEDS_REVIEW`.
- Laravel-owned BI fields survive Sheets import and differing source values produce `import_conflict`.
- Pemberkasan, bank submissions, buyer substitutions, transfers, production cutover, and cron remain outside scope.

## Review Focus

- Two authenticated requests for one lot cannot create two active bookings.
- BI URL and posted booking ID cannot cross project authorization.
- `TIDAK LANJUT` cannot silently release a lot or overwrite later booking history.
- Laravel BI result, decision, notes, and date survive stale Sheets import.
- Repeat booking after BI-stage Withdraw uses a new booking ID while original BI decision remains attached.

---

## Task List

### Task 1: Prove PSJB MySQL HTTP concurrency

**Files:**
- Create: `tests/Support/HttpConcurrencyProbe.php` or staging-only verification script under `storage/app/private` (delete after run).
- Modify: `app/Services/Operations/PsjbWorkflow.php` only if a staging/test lock-delay hook is required; guard it from production.
- Test: `tests/Feature/PsjbConcurrencyTest.php` for database invariant coverage.

**Interfaces:**
- Two independent authenticated sessions POST same project/lot with different buyers.
- Capture HTTP status/body for both responses.
- Assert one success, one lot-conflict response, one active booking, one creation audit, one current holder.

- [ ] Add failing invariant test for same-lot concurrent creates.
- [ ] Run test and confirm failure or missing harness.
- [ ] Add minimum test-only/staging-only synchronization needed to hold the first request's lot lock.
- [ ] Run two independent HTTP requests against MySQL and save response/status evidence.
- [ ] Verify booking, audit, and inventory counts directly in MySQL.
- [ ] Remove temporary harness artifacts and leave no enabled lock delay.

### Task 2: Add Laravel ownership to BI records

**Files:**
- Create: `database/migrations/2026_11_16_000007_add_bi_ownership.php`.
- Modify: `app/Models/BiCheck.php`.
- Test: `tests/Feature/BiOwnershipFoundationTest.php`.

**Interfaces:**
- `BiCheck::OWNER_SHEETS`, `BiCheck::OWNER_LARAVEL`.
- `bi_checks.source_owner`, default `sheets`, indexed.

- [ ] Write failing default/cast test.
- [ ] Add migration/model metadata.
- [ ] Run focused test.

### Task 3: Implement BI queue and transactional decisions

**Files:**
- Modify: `app/Services/Operations/PsjbWorkflow.php`, `app/Models/BookingAudit.php`.
- Create: `app/Exceptions/BiDecisionConflict.php` only if needed.
- Test: `tests/Feature/BiWorkflowTest.php`.

**Interfaces:**
- `eligibleBiBookings(Project $project): Builder` filters project, `payment_method=KPR`, actionable booking statuses, and BI stage/absence.
- `recordBiCheck(User $actor, Booking $booking, array $input): Booking` locks project, booking, and lot in approved order; stores `check_date`, `result`, `decision`, `notes`; marks BI Laravel-owned; updates booking stage to `BI_CHECKING`; writes audit.
- `withdraw` and `cancel` accept BI-stage bookings through existing methods and preserve BI record.

- [ ] Add failing tests for LANJUT, TIDAK LANJUT, KPR filtering, authorization, duplicate/closed decisions, no automatic release, BI-stage withdrawal, and repeat booking history.
- [ ] Implement minimum transactional behavior with project → booking → lot locks.
- [ ] Extend audit snapshots with BI fields.
- [ ] Run focused tests.

### Task 4: Add authorized BI HTTP surface and Blade UI

**Files:**
- Create: `app/Http/Requests/StoreBiCheckRequest.php`.
- Create: `app/Http/Controllers/BiCheckController.php`.
- Create: `resources/views/bi/index.blade.php`, `resources/views/bi/edit.blade.php`.
- Modify: `routes/web.php`, `resources/views/layouts/app.blade.php`, `resources/views/bookings/show.blade.php`, `public/css/app.css` only as needed.
- Test: `tests/Feature/BiControllerTest.php`.

**Interfaces:**
- `GET /projects/{project}/bi-checking` queue.
- `GET /projects/{project}/bi-checking/{booking}` form, internal booking ID scoped to project.
- `POST /projects/{project}/bi-checking/{booking}` save result.

- [ ] Add failing tests for queue scope, KPR filter, URL tampering, posted booking-ID tampering, HQ read-only, branch-admin write, and validation.
- [ ] Implement routes/controllers/forms with project and staging guards.
- [ ] Render exact decisions `LANJUT` / `TIDAK LANJUT`, timeline link, and existing Withdraw/Cancel actions.
- [ ] Run focused HTTP/view tests.

### Task 5: Protect Laravel BI values during Sheets import

**Files:**
- Modify: `app/Services/Import/ProjectImporter.php`.
- Test: `tests/Feature/ImporterBiOwnershipTest.php`.

**Interfaces:**
- In Laravel project mode, protected `BiCheck` fields are preserved: `check_date`, `result`, `decision`, flags, `notes`, and Laravel source identity.
- Differing Sheets values emit `import_conflict` with safe field context.
- Protected BI events cannot overwrite Laravel booking decision/stage fields.
- Sheets projects retain current behavior; dry-run remains rollback-only.

- [ ] Add failing stale-import tests for result/decision/notes/date.
- [ ] Implement protected BI comparison and event isolation.
- [ ] Run focused importer tests plus G09/K20.

### Task 6: Browser and final verification

**Files:**
- Modify: `docs/psjb-staging-workflow.md`, `README.md`, `tasks/todo.md`.

- [ ] Browser verify PSJB creation → BI queue → BI result → booking timeline.
- [ ] Browser verify BI-stage Withdraw → same lot repeat booking; original BI decision remains.
- [ ] Verify desktop 1440×900 and mobile 390×844; zero console errors.
- [ ] Run `php artisan test`.
- [ ] Rerun G09, K20, importer regression suite.
- [ ] Record both HTTP concurrency outcomes, booking/audit counts, BI behavior, and MySQL evidence in handoff docs.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| BI rejection releases stock unexpectedly | Duplicate allocation | Keep `TIDAK LANJUT` as valid claim/needs review; only explicit Withdraw/Cancel releases. |
| Stale Sheets BI overwrites staging result | Lost decision | `bi_checks.source_owner` and protected importer path with conflict issue. |
| Booking-ID tampering crosses projects | Unauthorized write | Route binding plus project ownership check before service call and inside transaction. |
| Concurrency test gives false confidence | Duplicate booking | Independent sessions, MySQL lock evidence, direct post-test counts. |
