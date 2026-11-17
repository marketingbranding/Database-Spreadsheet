# PSJB Operational Write Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add staging-only Laravel PSJB create/withdraw/cancel workflows for Kalinegoro and Kuwasen with MySQL-safe lot allocation, audit history, authorization, and ownership-aware imports.

**Architecture:** Extend existing project/booking models with explicit management ownership, add immutable booking audits, and put all operational mutations in a small transactional service. Keep existing importer behavior for `sheets` projects; for `laravel` projects preserve Laravel-owned records and emit reconciliation issues for source conflicts.

**Tech Stack:** Laravel 12, PHP 8.3, Blade, Eloquent, MySQL/MariaDB, sqlite test database, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-16-psjb-operational-write-design.md`

## Global Constraints

- Both pilot projects remain staging-only; production Sheets stay read-only and production cutover/schedules remain pending.
- Existing navigation, dashboard, booking timeline, reconciliation, and import reports must remain available.
- Bank input, buyer substitutions, lot transfers, Akad, and BAST editing stay outside scope.
- Every request validates authenticated active-user access to the project/resource; browser project or record IDs are never trusted.
- Lot allocation locks the lot inside a DB transaction and re-checks valid claims after locking.
- Valid claims are bookings with status `ACTIVE`, `COMPLETED`, or `NEEDS_REVIEW`.
- Laravel-owned import conflicts preserve DB values and report `import_conflict`; dry-run remains rollback-only.
- No raw NIK, phone, credentials, or secrets enter audit logs.

## Review Focus

- Two concurrent create requests for one lot: at most one active booking; the other gets a clear conflict.
- A Laravel-managed booking changed in Laravel then reimported: Laravel value survives and conflict is reported.
- M22-style multiple valid claims: unavailable for create; withdraw/cancel cannot incorrectly release another claim.
- Cross-project POST tampering: changing project, lot, buyer, or booking IDs cannot bypass authorization.
- Repeat booking after withdrawal/cancel: new booking ID; original booking, salesperson, and stage dates remain unchanged.

---

## Task List

### Phase 1: Data ownership and audit foundation

### Task 1: Add project and record ownership metadata

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_management_ownership.php`
- Modify: `app/Models/Project.php`, `app/Models/Booking.php`
- Test: `tests/Feature/OperationalWriteFoundationTest.php`

**Interfaces:**
- Produces `Project::management_mode` (`sheets`/`laravel`) defaulting to `sheets`.
- Produces `Booking::source_owner` (`sheets`/`laravel`) defaulting to `sheets`.
- Adds constants/casts/fillable entries required by later service/import code.

- [ ] Write failing schema/model tests for defaults and casts.
- [ ] Run `php artisan test tests/Feature/OperationalWriteFoundationTest.php` and confirm failure.
- [ ] Add migration and model metadata.
- [ ] Run focused test; expect pass.
- [ ] Run `php artisan migrate:fresh --env=testing` if needed to verify migration.

### Task 2: Add immutable booking audit records

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_booking_audits_table.php`
- Create: `app/Models/BookingAudit.php`
- Modify: `app/Models/Booking.php`, `app/Models/Project.php`, `app/Models/User.php`
- Test: `tests/Feature/BookingAuditTest.php`

**Interfaces:**
- `BookingAudit::record(Booking $booking, ?User $user, string $action, ?array $before, ?array $after, ?string $requestId): BookingAudit`
- Relations: booking, project, user.
- Audit allowlist excludes encrypted NIK, phone, passwords, and secrets.

- [ ] Write failing test for actor/action/before/after/request ID persistence and relations.
- [ ] Run focused test; confirm failure.
- [ ] Implement migration/model/relations and allowlisted payload helper.
- [ ] Run focused test; expect pass.

### Checkpoint: Foundation

- [ ] `php artisan test tests/Feature/OperationalWriteFoundationTest.php tests/Feature/BookingAuditTest.php`
- [ ] Existing importer regression suite still passes.

### Phase 2: Transactional operational service

### Task 3: Implement PSJB allocation service

**Files:**
- Create: `app/Services/Operations/PsjbWorkflow.php`
- Create: `app/Exceptions/LotAllocationConflict.php`
- Modify: `app/Models/Lot.php`, `app/Models/Buyer.php`
- Test: `tests/Feature/PsjbWorkflowTest.php`

**Interfaces:**
- `PsjbWorkflow::create(User $actor, Project $project, array $input): Booking`
- `PsjbWorkflow::withdraw(User $actor, Booking $booking, ?string $note = null): Booking`
- `PsjbWorkflow::cancel(User $actor, Booking $booking, ?string $note = null): Booking`
- `PsjbWorkflow::availableLots(Project $project): Builder`
- `PsjbWorkflow::validClaimQuery(int $lotId): Builder`

**Behavior pinned by tests:**
- Create locks lot with `lockForUpdate()`, rechecks project access, rejects valid claims and competing claims, creates a fresh PSJB booking/record, marks current holder, and writes audit.
- Existing buyer matching order: source customer ID, NIK hash, normalized name; new buyer uses encrypted NIK/phone and existing normalizer helpers.
- Withdraw/cancel lock booking + lot, allow only `ACTIVE`/`NEEDS_REVIEW`, preserve stage records, set decision stage/date/note, release only when no valid claim remains, and audit.
- Repeat create after outcome gets a different booking ID and leaves prior salesperson/date/stage history unchanged.
- M22-style multiple claims is unavailable.
- Unauthorized actor/project mismatch throws authorization failure before mutation.

- [ ] Write failing tests for create, buyer reuse/new buyer, withdrawal, cancellation, repeat booking, M22 conflict, and cross-project actor.
- [ ] Run `php artisan test tests/Feature/PsjbWorkflowTest.php`; confirm failure.
- [ ] Implement minimum transactional service and conflict exception.
- [ ] Run focused tests; expect pass.
- [ ] Add one MySQL-only lock smoke test path or script using two independent DB connections; sqlite tests cover state behavior.

### Task 4: Add HTTP routes/controllers/forms

**Files:**
- Create: `app/Http/Controllers/PsjbController.php`
- Create: `app/Http/Requests/StorePsjbRequest.php`
- Create: `app/Http/Requests/BookingDecisionRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PsjbControllerTest.php`

**Interfaces:**
- `GET /projects/{project}/psjb/create` → authorized form.
- `POST /projects/{project}/psjb` → create PSJB.
- `POST /bookings/{booking}/withdraw` → withdraw.
- `POST /bookings/{booking}/cancel` → cancel.

- [ ] Write failing request tests for validation, redirects, conflict message, CSRF-protected actions, and URL/form/record-ID tampering.
- [ ] Run focused test; confirm failure.
- [ ] Implement form requests, scoped access checks, service calls, and safe user-facing errors.
- [ ] Run focused tests; expect pass.

### Phase 3: Blade workflow

### Task 5: Build PSJB create and decision UI

**Files:**
- Create: `resources/views/psjb/create.blade.php`
- Modify: `resources/views/bookings/show.blade.php`, `resources/views/projects/show.blade.php`, `public/css/app.css`
- Test: `tests/Feature/PsjbViewTest.php`

**Interfaces:**
- Create page shows only authorized projects and allocatable lots.
- Existing buyer select and new buyer fields are mutually clear.
- Salesperson, payment method, PSJB/UTJ dates and amounts have labels/errors.
- Booking detail shows Withdraw/Cancel only for actionable statuses and keeps existing timeline.
- Conflict response is visible without exposing SQL/internal details.

- [ ] Write failing view/HTTP tests for form fields, unavailable M22 exclusion, decision buttons, and validation errors.
- [ ] Run focused test; confirm failure.
- [ ] Implement Blade views using existing layout/styles; no JS dependency required.
- [ ] Run focused test; expect pass.

### Checkpoint: Operational flow

- [ ] `php artisan test tests/Feature/PsjbWorkflowTest.php tests/Feature/PsjbControllerTest.php tests/Feature/PsjbViewTest.php`
- [ ] Browser verifies authorized create → detail → withdraw/cancel → repeat create.
- [ ] Browser verifies clear conflict on unavailable lot.

### Phase 4: Import ownership protection

### Task 6: Make importer ownership-aware and add management-mode command

**Files:**
- Create: `app/Console/Commands/SetProjectManagementMode.php`
- Modify: `app/Console/Commands/ImportProject.php`, `app/Services/Import/ProjectImporter.php`, `app/Models/ImportIssue.php`, `app/Services/Metrics/Reconciliation.php`
- Test: `tests/Feature/ImporterOwnershipTest.php`

**Interfaces:**
- Artisan: `marison:project-mode {project} {mode}` where mode is `sheets|laravel`.
- Importer preserves Laravel-owned booking protected fields in `laravel` mode.
- Importer emits `import_conflict` warning with source tab/row/key and safe field-level context.
- Identical source/current values produce no conflict.
- Sheets-managed projects retain current update behavior.
- Dry-run reports conflict but commits no operational or issue rows.

- [ ] Write failing test: import fixture, edit booking through Laravel workflow, rerun original fixture, assert Laravel value survives and conflict exists.
- [ ] Add tests for sheets mode unchanged and dry-run rollback.
- [ ] Run focused tests; confirm failure.
- [ ] Implement ownership-aware upsert comparison and command.
- [ ] Run focused tests; expect pass.

### Task 7: Staging configuration, MySQL verification, docs

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php` only if safe/default staging metadata needs it.
- Create: `docs/psjb-staging-workflow.md`
- Modify: `docs/reconciliation.md`, `README.md`
- Test: `tests/Feature/StagingModeTest.php`

**Interfaces:**
- Both pilots remain explicitly documented as staging-only.
- Production remains Sheets-managed; no production cutover/schedule commands are run.
- Documentation includes exact staging commands, MySQL lock verification, import conflict behavior, and unresolved issues.

- [ ] Write failing staging-mode test for both pilot projects.
- [ ] Implement only staging-safe setup/documentation.
- [ ] Run focused test; expect pass.
- [ ] Run full `php artisan test`.
- [ ] Run browser checks at 1440×900 and 390×844.
- [ ] Run MySQL staging lock smoke and record result.

### Final Checkpoint

- [ ] Existing G09 and K20 regressions pass.
- [ ] PSJB create/withdraw/cancel passes on sqlite tests.
- [ ] MySQL concurrent allocation proves at most one active booking.
- [ ] Laravel-owned edit survives ordinary reimport and creates `import_conflict`.
- [ ] Cross-project reads/writes return 403.
- [ ] Browser console has no errors/warnings on main admin flow.
- [ ] Production Sheets remain read-only; no production mode change or cron is applied.
