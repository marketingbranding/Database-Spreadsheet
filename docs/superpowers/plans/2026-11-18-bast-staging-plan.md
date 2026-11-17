# BAST Staging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a transactional, project-scoped BAST staging workflow for KPR and CASH that links to the exact Akad, protects Laravel-owned data from stale imports, and counts actual handover dates.

**Architecture:** Extend `PsjbWorkflow` with BAST eligibility and recording, mirroring the existing PPJB/Akad contracts. Add a project-scoped controller/request/views, extend BAST provenance and ownership fields, and keep imported historical BAST rows nullable-linked and Sheets-owned.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, Blade, PHPUnit, Laravel Pint, MySQL/SQLite test database.

**Spec:** `docs/superpowers/specs/2026-11-18-bast-staging-design.md`

## Global Constraints

- Operational writes are staging-only and must use the existing `MARISON_OPERATIONAL_WRITES=true` guard.
- Only branch admins assigned to Laravel-managed pilot projects `PRJ-JPR-KWS` and `PRJ-MGL-KLG` may use the queue and write flow.
- KPR and CASH use the same post-Akad eligibility rule; do not re-run bank or Pemberkasan checks.
- Operational BAST requires an exact Akad belonging to the same booking and project.
- Successful BAST sets `current_stage=BAST`, `status=COMPLETED`, and preserves `is_current_holder=true`.
- BAST period metrics use actual `bast_date`; historical inventory markers alone never create BAST events.
- Do not change BAST input/import scheduling, production cutover, or concurrent retry behavior.

## Review Focus

- A completed booking must leave the queue while retaining its lot holder and counting exactly once: test in Task 2.
- A valid-looking Akad ID from another booking or G09 cycle must be rejected inside the transaction: test in Task 2.
- A retry after completion must replay by key without creating a second record or audit: test in Task 2.
- A stale Sheets row must not overwrite a Laravel-owned BAST or booking transition and must report `import_conflict`: test in Task 4.
- A 2026 handover after a 2025 Akad must count in 2026, while an inventory marker alone must not: test in Task 5.

---

### Task 1: BAST provenance contract and audit snapshot

**Files:**
- Create: `database/migrations/2026_11_18_000003_extend_bast_records_for_staging.php`
- Modify: `app/Models/BastRecord.php`
- Modify: `app/Models/AkadRecord.php`
- Modify: `app/Models/BookingAudit.php`
- Test: `tests/Feature/BastWorkflowTest.php`

**Interfaces:**
- Produces `BastRecord::OWNER_SHEETS`, `BastRecord::OWNER_LARAVEL`, `BastRecord::akad()` and nullable `akad_id`, `source_owner`, `idempotency_key`.
- Produces `BookingAudit::ACTION_BAST_CREATED` and a safe `bast` snapshot containing the BAST record and Akad source fields.

- [ ] **Step 1: Write failing contract tests**
  - Assert the ownership constants and audit action.
  - Assert the migration exposes `akad_id`, `source_owner`, and `idempotency_key`.
  - Create a BAST linked to an Akad and assert `BookingAudit::snapshot()` includes the BAST number/date and exact `akad_id`/Akad number.
- [ ] **Step 2: Run the focused test to verify RED**

  Run: `vendor\bin\phpunit tests\Feature\BastWorkflowTest.php`

  Expected: failure because the BAST staging contract is not defined.
- [ ] **Step 3: Implement the migration, model relations, casts, constants, and audit snapshot**
  - Use a nullable restrictive foreign key to `akad_records`.
  - Keep legacy imported rows compatible by allowing nullable ownership/idempotency/source links.
- [ ] **Step 4: Run the focused test to verify GREEN**

  Run: `vendor\bin\phpunit tests\Feature\BastWorkflowTest.php`

  Expected: contract tests pass.
- [ ] **Step 5: Commit**

  ```bash
  git add database/migrations/2026_11_18_000003_extend_bast_records_for_staging.php app/Models/BastRecord.php app/Models/AkadRecord.php app/Models/BookingAudit.php tests/Feature/BastWorkflowTest.php
  git commit -m "feat: add BAST staging record contract"
  ```

### Task 2: Transactional BAST workflow

**Files:**
- Modify: `app/Services/Operations/PsjbWorkflow.php`
- Test: `tests\Feature\BastWorkflowTest.php`
- Test: `tests\Feature\BastG09Test.php`

**Interfaces:**
- Produces `eligibleBastBookings(Project $project): Builder`.
- Produces `recordBast(User $actor, Booking $booking, array $input): BastRecord`.

- [ ] **Step 1: Extend failing workflow tests**
  - Cover KPR and CASH bookings with exact active Akad eligibility.
  - Assert BAST number/date/notes persistence, `BAST` stage, `COMPLETED` status, `is_current_holder=true`, queue removal, and one audit.
  - Reject inactive/non-Akad bookings, cross-project Akad IDs, cross-booking Akad IDs, and G09 April/September mismatches with `403`/`422`.
  - Assert same-key/same-payload replay returns the existing record after completion, while changed payload returns `409`.
  - Assert failed audit or invalid source leaves record, booking, and transition unchanged.
- [ ] **Step 2: Run the workflow tests to verify RED**

  Run: `vendor\bin\phpunit tests\Feature\BastWorkflowTest.php tests\Feature\BastG09Test.php`

  Expected: failures because the BAST workflow methods are not defined.
- [ ] **Step 3: Implement `eligibleBastBookings()` and `recordBast()`**
  - Reuse existing pilot authorization and operational write guard.
  - Lock project, booking, lot, and selected Akad in the established order.
  - Fingerprint booking ID, Akad ID, BAST number, actual date, and notes.
  - Check idempotency before eligibility so completed-booking retries replay safely.
  - Enforce same booking/project, active Akad, current stage `AKAD`, active booking, and no existing BAST.
  - Create the Laravel-owned BAST, update the booking atomically, and record `ACTION_BAST_CREATED`.
- [ ] **Step 4: Run the workflow tests to verify GREEN**

  Run: `vendor\bin\phpunit tests\Feature\BastWorkflowTest.php tests\Feature\BastG09Test.php`

  Expected: all workflow and cycle-isolation tests pass.
- [ ] **Step 5: Commit**

  ```bash
  git add app/Services/Operations/PsjbWorkflow.php tests/Feature/BastWorkflowTest.php tests/Feature/BastG09Test.php
  git commit -m "feat: add transactional BAST workflow"
  ```

### Task 3: Project-scoped BAST controller and UI

**Files:**
- Create: `app/Http/Requests/StoreBastRequest.php`
- Create: `app/Http/Controllers/BastController.php`
- Create: `resources/views/bast/index.blade.php`
- Create: `resources/views/bast/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/projects/show.blade.php`
- Test: `tests\Feature\BastControllerTest.php`

**Interfaces:**
- Adds named routes `bast.index`, `bast.create`, and `bast.store`.
- Controller actions mirror TTD PPJB/Akad and redirect successful writes to `bookings.show`.

- [ ] **Step 1: Write failing controller/UI tests**
  - Assert assigned branch admins see buyer, lot, payment method, Akad date, and `Catat BAST`.
  - Assert form shows buyer, lot, linked Akad number/date, `Tanggal Serah Terima Aktual`, and `Simpan BAST`.
  - Submit invalid fields and assert field-level messages plus retained values.
  - Submit valid KPR and CASH flows and assert redirect, session success status, exact BAST persistence, and timeline-visible fields.
  - Reject cross-project booking/Akad IDs, unauthorized roles, and non-pilot or Sheets-managed projects.
  - Assert exactly three project-scoped named routes.
- [ ] **Step 2: Run controller tests to verify RED**

  Run: `vendor\bin\phpunit tests\Feature\BastControllerTest.php`

  Expected: failure because BAST routes/controller/views do not exist.
- [ ] **Step 3: Implement request, controller, routes, navigation, and Blade views**
  - Mirror TTD PPJB/Akad layout and authorization.
  - Use `Catat BAST` queue action and `Simpan BAST` form action.
  - Show `Tanggal Akad` and `Tanggal Serah Terima Aktual` as separate values.
  - Put validation errors beside each editable field and preserve `old()` values.
  - Keep the form mobile-safe using existing form-grid/table-wrap patterns.
- [ ] **Step 4: Extend booking timeline and add browser-facing assertions**
  - Show BAST number, actual BAST date through the timeline entry, and `Sumber Akad` with exact Akad ID/number.
  - Verify shared session success feedback is visible after redirect.
- [ ] **Step 5: Run controller tests to verify GREEN**

  Run: `vendor\bin\phpunit tests\Feature\BastControllerTest.php`

  Expected: all controller/UI tests pass.
- [ ] **Step 6: Commit**

  ```bash
  git add app/Http/Requests/StoreBastRequest.php app/Http/Controllers/BastController.php resources/views/bast/index.blade.php resources/views/bast/edit.blade.php routes/web.php resources/views/layouts/app.blade.php resources/views/projects/show.blade.php app/Http/Controllers/BookingController.php tests/Feature/BastControllerTest.php
  git commit -m "feat: add BAST queue and handover form"
  ```

### Task 4: Import ownership and provenance protection

**Files:**
- Modify: `app/Services/Import/ProjectImporter.php`
- Test: `tests\Feature\ImporterBastOwnershipTest.php`

**Interfaces:**
- Imported BAST rows remain Sheets-owned and may retain nullable `akad_id`.
- Laravel-owned BAST rows are protected by booking identity; differences emit `import_conflict` without changing the record or booking rollup.

- [ ] **Step 1: Write failing importer tests**
  - Create a Laravel-owned BAST, run a stale fixture import, and assert BAST number/date/notes, `akad_id`, booking stage/status/date, and holder ownership remain unchanged.
  - Assert the report contains `import_conflict` with changed BAST fields.
  - Assert imported BAST links to an exact same-booking/project Akad when the source reference resolves, without cross-cycle retargeting.
- [ ] **Step 2: Run importer tests to verify RED**

  Run: `vendor\bin\phpunit tests\Feature\ImporterBastOwnershipTest.php`

  Expected: failure because the importer currently overwrites BAST rows and has no Laravel ownership protection/link persistence.
- [ ] **Step 3: Implement BAST importer protection**
  - Index Laravel-owned BAST booking IDs during importer setup.
  - Resolve `ID Akad Sebelumnya`/`Pilih Akad` only within the exact booking and project.
  - Add `akad_id` to imported attributes when an exact source Akad resolves.
  - Preserve Laravel-owned BAST rows, report field differences through a dedicated conflict helper, and preserve protected booking rollups.
- [ ] **Step 4: Run importer tests to verify GREEN**

  Run: `vendor\bin\phpunit tests\Feature\ImporterBastOwnershipTest.php`

  Expected: ownership and exact-link tests pass.
- [ ] **Step 5: Commit**

  ```bash
  git add app/Services/Import/ProjectImporter.php tests/Feature/ImporterBastOwnershipTest.php
  git commit -m "feat: protect BAST provenance during import"
  ```

### Task 5: Metrics, historical markers, and regression coverage

**Files:**
- Test: `tests\Feature\BastMetricsTest.php`
- Test: `tests\Feature\BastK20Test.php`
- Modify: `tests\Feature\ImporterRegressionTest.php` only if a missing regression assertion is required.

**Interfaces:**
- Keeps `PortfolioMetrics::summary()['bast']` based on actual `BastRecord.bast_date`.
- Does not change inventory-marker semantics or unrelated dashboard stages.

- [ ] **Step 1: Write failing metrics/regression tests**
  - Assert a 2026 BAST after a 2025 Akad counts in a 2026 period.
  - Assert one BAST record counts once and completion does not duplicate the metric.
  - Assert a historical inventory checkbox without a BAST row does not increase BAST count or create a handover event.
  - Assert K20 booking/lot chain remains isolated and BAST does not retarget historical records.
- [ ] **Step 2: Run metrics/regression tests**

  Run: `vendor\bin\phpunit tests\Feature\BastMetricsTest.php tests\Feature\BastK20Test.php tests\Feature\ImporterRegressionTest.php`

  Expected: any missing metric or regression contract fails before implementation is accepted.
- [ ] **Step 3: Make only required metric/importer corrections**
  - Preserve existing actual-date `stageCount` behavior if tests demonstrate it already satisfies the requirement.
  - Do not create BAST records from `historical_akad_before_2026`.
- [ ] **Step 4: Run the tests to verify GREEN**

  Run: `vendor\bin\phpunit tests\Feature\BastMetricsTest.php tests\Feature\BastK20Test.php tests\Feature\ImporterRegressionTest.php`

  Expected: all metrics and historical regressions pass.
- [ ] **Step 5: Commit**

  ```bash
  git add tests/Feature/BastMetricsTest.php tests/Feature/BastK20Test.php tests/Feature/ImporterRegressionTest.php
  git commit -m "test: verify BAST metrics and historical isolation"
  ```

### Task 6: Full verification and browser handoff

**Files:**
- Modify: `.superpowers/sdd/2026-11-18-bast-staging-plan/progress.md`
- No production BAST code changes unless verification exposes a requirement failure.

- [ ] **Step 1: Run migrations**

  Run: `php artisan migrate --force`

- [ ] **Step 2: Run the full regression suite**

  Run: `php artisan test`

  Expected: all existing and BAST tests pass; report exact test/assertion counts.
- [ ] **Step 3: Run targeted Pint**

  Run Pint `--test` on every changed PHP file.

  Expected: changed files pass; report unrelated repository baseline failures separately.
- [ ] **Step 4: Browser verify desktop and mobile**
  - Use the existing development command and authenticated staging browser session.
  - Verify KPR and CASH queue/form/timeline flows at approximately `1440x900`.
  - Verify queue/form/timeline at `390x844`; inspect `scrollWidth === innerWidth`, ensure `Simpan BAST` is reachable vertically, and check console for errors.
  - Verify success status, actual BAST date, linked Akad, and document number on the timeline.
- [ ] **Step 5: Review the diff and commit the verification ledger**

  Run:

  ```bash
  git diff --check main..HEAD
  git status --short
  ```

  Expected: no whitespace errors and no unintended uncommitted files.
- [ ] **Step 6: Merge into local `main`**

  ```bash
  git -C ..\Database-Spreadsheet merge --ff-only feature/bast-staging
  ```

  Keep concurrent retry testing, scheduled imports, and production cutover documented as follow-ups.
