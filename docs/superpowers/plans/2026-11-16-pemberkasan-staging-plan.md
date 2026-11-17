# Pemberkasan Staging Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add staging-only project-scoped Pemberkasan processing for `PRJ-JPR-KWS` and `PRJ-MGL-KLG`, preserving source/history and protecting Laravel-owned records during import.

**Architecture:** Extend existing `PsjbWorkflow` with Pemberkasan eligibility and transactional submission methods. Keep `pemberkasan_records` canonical, change booking access to history-aware `hasMany`, add ownership/current revision metadata, and reuse existing booking decisions, timeline, audits, and importer conflict handling.

**Tech Stack:** Laravel 12, PHP 8.3, Blade, Eloquent, MySQL/MariaDB, SQLite tests, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-16-pemberkasan-staging-design.md`

## Global Constraints

- Writes require `APP_ENV=staging` and `MARISON_OPERATIONAL_WRITES=true`.
- Only assigned `branch_admin` users may submit Pemberkasan or stage decisions.
- Routes and workflow reject non-pilot projects; pilots are `PRJ-JPR-KWS` and `PRJ-MGL-KLG`.
- KPR requires latest BI record Laravel-owned with exact decision `LANJUT`.
- CASH may enter directly after PSJB when booking is actionable.
- KPR without BI and KPR `TIDAK LANJUT` stay out of queue and direct writes.
- Laravel writes require non-empty `file_number`; duplicate booking/file returns `409`.
- Existing Pemberkasan rows remain preserved; new submissions do not overwrite history.
- Sheets-managed projects retain ordinary import behavior; dry-run remains rollback-only.
- Proses Bank, buyer substitutions, lot transfers, production cutover, and scheduled imports remain out of scope.

## Review Focus

- Latest BI decision selection must not treat an older `LANJUT` as current when latest says `TIDAK LANJUT`; pin in workflow tests.
- CASH must be eligible without BI while KPR without BI remains blocked; pin both paths.
- Repeated file submissions must preserve earlier rows and reject same booking/file duplicates; pin revision and duplicate tests.
- Laravel-owned rows must match stale Sheets rows by business key when source keys differ; pin importer conflict test.
- Null/empty source dates must sort after dated timeline entries without dropping records; pin timeline test.

---

### Task 1: Add history-aware Pemberkasan schema and model contract

**Files:**
- Create: `database/migrations/2026_11_16_000008_add_pemberkasan_ownership.php`
- Modify: `app/Models/PemberkasanRecord.php`
- Modify: `app/Models/Booking.php`
- Test: `tests/Feature/PemberkasanOwnershipFoundationTest.php`

**Interfaces:**
- `PemberkasanRecord::OWNER_SHEETS`, `PemberkasanRecord::OWNER_LARAVEL`.
- `PemberkasanRecord` fields: `source_owner`, `revision`, `is_current`.
- `Booking::pemberkasan(): HasMany` returns records ordered by `received_date` with null dates last, then `id`.
- `Booking::currentPemberkasan(): HasOne` returns current record.

- [ ] **Step 1: Write failing model tests**
  - Assert existing imported records default to `OWNER_SHEETS`, revision `1`, current `true`.
  - Assert booking can hold two records and `currentPemberkasan` returns the current one.
  - Assert same booking/file uniqueness is enforced for non-null file numbers.

- [ ] **Step 2: Run focused test and confirm RED**

Run: `php artisan test tests/Feature/PemberkasanOwnershipFoundationTest.php`

Expected: missing constants/columns/relation or uniqueness failure.

- [ ] **Step 3: Implement migration/model/relation**
  - Add nullable-safe uniqueness for `(booking_id, file_number)` compatible with existing MySQL and SQLite behavior; preserve legacy duplicate/null import rows if any.
  - Add ownership defaults and casts.
  - Keep `source_key` unique for importer compatibility.

- [ ] **Step 4: Run focused test and confirm GREEN**

Run: `php artisan migrate --force; php artisan test tests/Feature/PemberkasanOwnershipFoundationTest.php`

- [ ] **Step 5: Record migration result in execution ledger**

---

### Task 2: Add transactional Pemberkasan eligibility and submission

**Files:**
- Modify: `app/Services/Operations/PsjbWorkflow.php`
- Modify: `app/Models/BookingAudit.php`
- Test: `tests/Feature/PemberkasanWorkflowTest.php`

**Interfaces:**
- `eligiblePemberkasanBookings(Project $project): Builder` returns actionable project bookings where `payment_method` is `KPR` with latest Laravel BI `LANJUT`, or exact `CASH` without BI prerequisite.
- `recordPemberkasan(User $actor, Booking $booking, array $input): Booking` creates a new Laravel-owned revision and advances booking to `PEMBERKASAN`.
- `BookingAudit::ACTION_PEMBERKASAN_CREATED = 'pemberkasan_created'`.
- `BookingAudit::snapshot()` includes current/all relevant Pemberkasan record fields without PII.

- [ ] **Step 1: Write failing workflow tests**
  - KPR with Laravel BI `LANJUT` appears and saves exact source fields.
  - CASH actionable booking appears and saves without BI.
  - KPR without BI and KPR `TIDAK LANJUT` are absent and direct submission throws `422`.
  - Latest BI row controls eligibility when older row differs.
  - New submission sets `current_stage=PEMBERKASAN`, `source_owner=laravel`, revision `1`, current `true`, and creates audit.
  - Second distinct file preserves prior record, increments revision, and keeps one current row.
  - Same booking/file submission throws `409` and creates no new row.
  - Non-staging, HQ, other branch, non-pilot, closed, and cross-project writes fail.
  - Withdraw/Cancel from Pemberkasan preserves record and sets `decision_stage=PEMBERKASAN`; lot release follows existing rules.
  - Withdraw then repeat booking gets new booking ID; old file and audit stay attached.

- [ ] **Step 2: Run focused test and confirm RED**

Run: `php artisan test tests/Feature/PemberkasanWorkflowTest.php`

- [ ] **Step 3: Implement minimum transactional workflow**
  - Lock project, booking, lot, and duplicate key in existing lock order.
  - Use latest BI by `updated_at`, then `id`; no older `LANJUT` may override newer `TIDAK LANJUT`.
  - Permit exact `CASH`; do not add `CASH_KERAS`.
  - Mark previous Pemberkasan row `is_current=false`, create next revision, and record audit snapshot.
  - Reuse existing `withdraw`/`cancel` decision methods and preserve current record.

- [ ] **Step 4: Run focused tests and confirm GREEN**

Run: `php artisan test tests/Feature/PemberkasanWorkflowTest.php`

---

### Task 3: Protect Pemberkasan importer ownership and matching

**Files:**
- Modify: `app/Services/Import/ProjectImporter.php`
- Modify: `app/Models/PemberkasanRecord.php` if query scopes are useful
- Test: `tests/Feature/ImporterPemberkasanOwnershipTest.php`

**Interfaces:**
- Imported Pemberkasan records default to `source_owner=sheets`, revision derived per booking/file history, current metadata maintained by source order.
- Laravel-owned matching checks `source_key` first, then `(booking_id, file_number)` for non-empty file numbers.
- Protected fields: `file_number`, `received_date`, `bank`, `bank_unit`, `status`, flags, `notes`, `source_owner`, `revision`, `is_current`.
- Differences produce warning `import_conflict`; Laravel values remain unchanged.

- [ ] **Step 1: Write failing importer tests**
  - Laravel-owned Pemberkasan with a different Sheets row preserves all protected fields and emits `import_conflict`.
  - Laravel source key differs but same booking/file matches existing Laravel row; no duplicate row.
  - Sheets-owned rows still upsert normally.
  - Multiple source rows for one booking remain separate and existing G09/K20 behavior stays unchanged.
  - Dry-run does not persist ownership or conflict changes.

- [ ] **Step 2: Run focused test and confirm RED**

Run: `php artisan test tests/Feature/ImporterPemberkasanOwnershipTest.php tests/Feature/ImporterRegressionTest.php`

- [ ] **Step 3: Implement protected import path**
  - Add protected record lookup before `upsert`.
  - Keep current source key behavior for Sheets-owned records.
  - Isolate protected Pemberkasan events from Laravel booking stage/holder recomputation.
  - Preserve source rows and file mapping for later `Proses Bank` resolution.

- [ ] **Step 4: Run focused importer regressions and confirm GREEN**

Run: `php artisan test tests/Feature/ImporterPemberkasanOwnershipTest.php tests/Feature/ImporterRegressionTest.php tests/Feature/ImporterOwnershipTest.php`

---

### Task 4: Add authorized HTTP routes, requests, and Blade UI

**Files:**
- Create: `app/Http/Requests/StorePemberkasanRequest.php`
- Create: `app/Http/Controllers/PemberkasanController.php`
- Create: `resources/views/pemberkasan/index.blade.php`
- Create: `resources/views/pemberkasan/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/projects/show.blade.php`
- Modify: `resources/views/bookings/show.blade.php` only if stage action link is needed
- Test: `tests/Feature/PemberkasanControllerTest.php`

**Interfaces:**
- `GET /projects/{project}/pemberkasan` named `pemberkasan.index`.
- `GET /projects/{project}/pemberkasan/{booking}` named `pemberkasan.edit`.
- `POST /projects/{project}/pemberkasan/{booking}` named `pemberkasan.update`.

- [ ] **Step 1: Write failing HTTP tests**
  - Assigned branch admin sees only eligible KPR/CASH queue rows.
  - KPR no BI and `TIDAK LANJUT` do not appear.
  - HQ can view only if project route policy permits, but cannot POST.
  - Sheets-managed and non-pilot project routes return `403`.
  - Modified posted booking ID across projects returns `403`.
  - Invalid/missing file number, invalid date, and duplicate file return validation/`409` responses.
  - Successful POST redirects to booking detail with status message.

- [ ] **Step 2: Run focused HTTP test and confirm RED**

Run: `php artisan test tests/Feature/PemberkasanControllerTest.php`

- [ ] **Step 3: Implement controller/request/routes/views**
  - Reuse BI controller authorization pattern plus pilot/management guard.
  - Keep Indonesian labels: `No. berkas`, `Tanggal Terima Bank`, `Bank`, `KC / Unit Bank`, `Status Pemberkasan`, `Keterangan`.
  - Use exact queue eligibility from workflow; never trust posted booking ID without project match and eligibility recheck.
  - Provide empty queue and validation error states.
  - Add project-page navigation only for Laravel-managed pilot projects.

- [ ] **Step 4: Run focused HTTP tests and confirm GREEN**

Run: `php artisan test tests/Feature/PemberkasanControllerTest.php`

---

### Task 5: Update timeline, stage history, and documentation

**Files:**
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `resources/views/bookings/show.blade.php` if needed for repeated entries/current state
- Modify: `docs/psjb-staging-workflow.md`
- Modify: `tasks/todo.md`
- Test: `tests/Feature/PemberkasanTimelineTest.php` or extend workflow/controller tests

**Interfaces:**
- Booking detail timeline renders every Pemberkasan record ordered by date ascending, `id` ascending, null dates last.
- Each entry shows file number, bank/unit, status, notes, and revision/current state where useful.
- Withdraw/Cancel status and `decision_stage=Pemberkasan` remain visible alongside stage history.

- [ ] **Step 1: Write failing timeline test**
  - Two Pemberkasan records render as two entries; null date does not sort before dated records.
  - Old record remains visible after new revision and later withdrawal.

- [ ] **Step 2: Run test and confirm RED**

Run: `php artisan test tests/Feature/PemberkasanTimelineTest.php`

- [ ] **Step 3: Implement history-aware timeline and docs**
  - Replace singular `$booking->pemberkasan` access with collection/current relation as appropriate.
  - Document KPR `LANJUT`, CASH direct path, duplicate identity, revision behavior, and unresolved source ambiguity: none after current source inspection unless new import data contradicts it.

- [ ] **Step 4: Run timeline test and confirm GREEN**

Run: `php artisan test tests/Feature/PemberkasanTimelineTest.php`

---

### Task 6: End-to-end browser and regression verification

**Files:**
- Modify: `docs/psjb-staging-workflow.md`, `tasks/todo.md` with evidence only.

- [ ] **Step 1: Run full automated suite**

Run: `php artisan test`

Expected: all existing and new tests pass; PSJB, BI, G09, K20, importer ownership, and importer regressions remain green.

- [ ] **Step 2: Verify staging browser flow**
  - Use authenticated branch-admin session for `PRJ-JPR-KWS`.
  - Create/select KPR booking, save BI `LANJUT`, open Pemberkasan queue, submit file record, inspect booking timeline.
  - Verify KPR no-decision and `TIDAK LANJUT` are absent/direct writes rejected.
  - Verify CASH queue path against an existing CASH-eligible record.
  - Withdraw during Pemberkasan, repeat same lot, verify new booking ID and old record/history.
  - Verify desktop approximately `1440x900` and mobile `390x844`.
  - Inspect console, network status, accessibility snapshot, and horizontal overflow.

- [ ] **Step 3: Run final checks**

Run: `php artisan route:list --path=pemberkasan` and confirm three routes. Confirm temporary harnesses removed. Confirm no production mode/cron changes.

- [ ] **Step 4: Update handoff evidence**
  - Report implemented fields, KPR/CASH eligibility, duplicate/revision behavior, import conflict behavior, browser outcomes, test counts, and unresolved ambiguity.
