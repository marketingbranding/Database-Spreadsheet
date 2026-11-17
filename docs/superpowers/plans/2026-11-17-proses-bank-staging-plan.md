# Proses Bank Staging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add staging-only Proses Bank processing for `PRJ-JPR-KWS` and `PRJ-MGL-KLG` with exact Pemberkasan links, submission history, result separation, authorization, audit atomicity, and importer ownership protection.

**Architecture:** Extend existing `bank_submissions` with `pemberkasan_id`, ownership, and idempotency metadata. Keep all mutations in `PsjbWorkflow`, expose project-scoped Blade routes through a dedicated controller and FormRequests, and render history through existing booking timeline/audit paths. Existing Sheets rows remain importer-owned; Laravel-owned rows remain immutable during stale imports and produce `import_conflict` issues.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, Blade, SQLite tests, MySQL/MariaDB production.

**Spec:** `docs/superpowers/specs/2026-11-17-proses-bank-staging-design.md`

## Global Constraints

- Staging writes require `APP_ENV=staging` and `MARISON_OPERATIONAL_WRITES=true`.
- Writes require assigned `ROLE_BRANCH_ADMIN`, project access, Laravel management mode, and pilot code `PRJ-JPR-KWS` or `PRJ-MGL-KLG`.
- Bank queue accepts exact payment method `KPR` only.
- Eligible current Pemberkasan statuses are `Analisa`, `Input`, `OTS`, and `LENGKAP`.
- `Pemberkasan CASH` and all CASH bookings stay outside Proses Bank.
- Bank results are `APPROVE`, `REJECT`, and `REVISI`; result changes never change booking decision/status.
- Every Laravel submission links exact `booking_id` and `pemberkasan_id`.
- Prior submissions remain preserved across retries, banks, and later Pemberkasan revisions.
- Explicit existing Withdraw/Cancel remains only booking decision path and keeps existing lot-release behavior.
- PPJB, Akad, BAST, substitutions, lot transfers, production cutover, and scheduled imports stay out of scope.
- Avoid unrelated formatter changes; report Pint baseline failures separately.

## Review Focus

- Modified `booking`, `submission`, or `pemberkasan` route IDs must fail authorization/eligibility checks; test cross-project and mismatched-record writes in Task 3.
- A current Pemberkasan revision change must not retarget old bank history; test exact revision links and G09 April/September isolation in Task 2.
- Repeated create requests with one idempotency key must not duplicate rows, while a new key must preserve history; test both in Task 2.
- Bank `REJECT` must remain a bank result without changing booking status or decision; test result separation in Task 2.
- Stale Sheets rows must not overwrite Laravel-owned bank fields or emit duplicate protected events; test conflict preservation in Task 4.

---

### Task 1: Bank Submission Contract and Schema

**Files:**
- Create: `database/migrations/2026_11_17_000001_extend_bank_submissions_for_staging.php`
- Modify: `app/Models/BankSubmission.php`
- Modify: `app/Models/Booking.php`
- Modify: `app/Models/PemberkasanRecord.php`
- Modify: `app/Models/BookingAudit.php`
- Test: `tests/Feature/ProsesBankWorkflowTest.php`

**Interfaces:**
- Produces `BankSubmission::OWNER_SHEETS`, `BankSubmission::OWNER_LARAVEL`, `BankSubmission::RESULTS`, `BankSubmission::$fillable`, casts, `pemberkasan()` relation, and `Booking::bankSubmissions()`/`PemberkasanRecord::bankSubmissions()` exact-link relations.
- Produces `BookingAudit::ACTION_BANK_SUBMISSION_CREATED` and `BookingAudit::ACTION_BANK_RESULT_RECORDED`.
- Schema adds nullable `pemberkasan_id`, nullable `source_owner`, nullable unique `idempotency_key`, and indexes for exact booking/file lookup. Existing Sheets rows remain valid.

- [ ] **Step 1: Write failing model/schema tests**
  - Assert migration creates nullable legacy-compatible ownership/idempotency columns.
  - Assert Laravel-created records cast dates/booleans/sequence and resolve exact booking/Pemberkasan relations.
  - Assert audit constants exist and snapshots include bank submission history without sensitive buyer fields.

- [ ] **Step 2: Run focused tests and verify failure**

Run: `php artisan test tests/Feature/ProsesBankWorkflowTest.php`

Expected: FAIL because new columns, relations, constants, and workflow test fixture do not exist.

- [ ] **Step 3: Implement migration and model contract**
  - Add foreign key to `pemberkasan_records` with nullable cascade behavior appropriate for existing data.
  - Add owner constants/default, idempotency key, result constants, relations, and casts.
  - Extend `BookingAudit::snapshot()` to include safe bank fields and exact Pemberkasan ID/revision.

- [ ] **Step 4: Run focused model tests**

Run: `php artisan test tests/Feature/ProsesBankWorkflowTest.php`

Expected: schema/model assertions pass; workflow assertions remain red.

---

### Task 2: Transactional Proses Bank Workflow

**Files:**
- Modify: `app/Services/Operations/PsjbWorkflow.php`
- Test: `tests/Feature/ProsesBankWorkflowTest.php`

**Interfaces:**
- Produces `eligibleBankBookings(Project $project): Builder`.
- Produces `recordBankSubmission(User $actor, Booking $booking, PemberkasanRecord $pemberkasan, array $input): BankSubmission`.
- Produces `recordBankResult(User $actor, BankSubmission $submission, array $input): BankSubmission`.
- Uses exact supported statuses/results and existing `withdraw()`/`cancel()` unchanged except for stage coverage where required.

- [ ] **Step 1: Write failing eligibility and workflow tests**
  - Eligible KPR rows with current Pemberkasan status `Analisa`, `Input`, `OTS`, `LENGKAP` appear.
  - CASH and `Pemberkasan CASH`, non-KPR, stale/non-current Pemberkasan, closed bookings, and wrong-stage bookings stay out.
  - Submission stores exact booking and Pemberkasan IDs, sequence, bank, date, owner, and audit atomically; booking advances to `PROSES_BANK` only.
  - Same `idempotency_key` and same payload returns existing submission; different key creates a second history row.
  - New Pemberkasan revision does not alter prior submission’s `pemberkasan_id`, file number, or revision history.
  - `recordBankResult()` accepts `APPROVE`, `REJECT`, `REVISI`, updates only targeted submission, and leaves booking `status`, `decision_stage`, and `decision_date` unchanged.
  - Result targeting another booking/project or another Pemberkasan fails; bank `REJECT` does not trigger lot release.
  - Reychan G09 fixture/regression proves April withdrawal remains attached to April booking and September booking retains separate status/history.
  - Transaction rollback test proves failed audit/write leaves no submission or stage mutation.

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test tests/Feature/ProsesBankWorkflowTest.php`

Expected: FAIL with missing workflow methods or unmet assertions.

- [ ] **Step 3: Implement minimum transactional workflow**
  - Apply authorization/staging/pilot/management checks before and inside transaction.
  - Lock project, booking, lot, Pemberkasan, and idempotency row in stable order.
  - Enforce current exact Pemberkasan ownership/project/booking and eligible status server-side.
  - Claim idempotency through unique constraint; same-key conflicting payload returns `409`.
  - Record result with supported-value validation and conflict-safe repeat behavior.
  - Record safe audit snapshots inside same transaction; do not mutate booking decision from result.

- [ ] **Step 4: Run workflow tests to green**

Run: `php artisan test tests/Feature/ProsesBankWorkflowTest.php`

Expected: PASS with all eligibility, history, G09, result-separation, authorization, and rollback assertions.

---

### Task 3: Authorized HTTP Surface and Blade UI

**Files:**
- Create: `app/Http/Controllers/ProsesBankController.php`
- Create: `app/Http/Requests/StoreBankSubmissionRequest.php`
- Create: `app/Http/Requests/StoreBankResultRequest.php`
- Create: `resources/views/proses-bank/index.blade.php`
- Create: `resources/views/proses-bank/edit.blade.php`
- Create: `resources/views/proses-bank/result.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/projects/show.blade.php`
- Test: `tests/Feature/ProsesBankControllerTest.php`

**Interfaces:**
- Routes:
  - `GET /projects/{project}/proses-bank` named `bank.index`.
  - `GET /projects/{project}/proses-bank/{booking}/create` named `bank.create`.
  - `POST /projects/{project}/proses-bank/{booking}` named `bank.store`.
  - `GET /projects/{project}/proses-bank/{submission}/result` named `bank.result.edit`.
  - `POST /projects/{project}/proses-bank/{submission}/result` named `bank.result.store`.
- Controller methods: `index`, `create`, `store`, `editResult`, `storeResult`.
- Store request fields: exact `pemberkasan_id`, required `bank`, required `submitted_date`, optional `bank_unit`, `notes`, and required `idempotency_key`.
- Result request fields: required `result` in `APPROVE|REJECT|REVISI`, optional `decision_date`, `approved_amount`, `tenor`, `notes`.

- [ ] **Step 1: Write failing HTTP tests**
  - Assigned branch admin sees only project queue and form with exact current Pemberkasan ID.
  - HTTP submission redirects to booking detail and timeline data is persisted.
  - HTTP result form records result and redirects to booking detail.
  - Modified booking ID, submission ID, Pemberkasan ID, cross-project route, unauthorized user, Sheets project, non-pilot project, non-staging writes, CASH, and ineligible status return expected 403/422/409.
  - Invalid bank/result/date/idempotency input returns 422.
  - Routes list contains exactly five named Proses Bank routes.

- [ ] **Step 2: Run controller tests and verify failure**

Run: `php artisan test tests/Feature/ProsesBankControllerTest.php`

Expected: FAIL because routes, requests, controller, and views do not exist.

- [ ] **Step 3: Implement requests/controller/routes/views**
  - Controller authorization must validate route project, route booking/submission, exact Pemberkasan linkage, and queue eligibility before calling workflow.
  - Forms display current file number/revision, existing submission history, and exact result values `APPROVE`, `REJECT`, `REVISI`.
  - Use escaped Blade output, CSRF, accessible labels, errors, empty state, and existing layout/classes.
  - Show Proses Bank navigation only for authorized Laravel-managed pilot project context.

- [ ] **Step 4: Run HTTP tests to green**

Run: `php artisan test tests/Feature/ProsesBankControllerTest.php`

Expected: PASS.

---

### Task 4: Import Ownership and Exact Revision Matching

**Files:**
- Modify: `app/Services/Import/ProjectImporter.php`
- Modify: `tests/Feature/ImporterPemberkasanOwnershipTest.php` or create `tests/Feature/ImporterBankOwnershipTest.php`
- Modify: `tests/Feature/ImporterRegressionTest.php` only where G09/K20 coverage belongs

**Interfaces:**
- Importer keeps source-key-first matching, then exact project + booking + file/Pemberkasan business-key matching.
- Imported rows set `source_owner=BankSubmission::OWNER_SHEETS` and nullable exact `pemberkasan_id` when source file resolves.
- Laravel-owned bank fields remain unchanged and conflicts emit `import_conflict`; protected rows do not create duplicate stage events or mutate booking ownership/decision.

- [ ] **Step 1: Write failing importer tests**
  - Laravel-owned bank row survives stale fixture reimport with booking, project, Pemberkasan link, bank, dates, result, notes, owner, and idempotency key unchanged.
  - Different source key with same exact booking + file matches protected Laravel row without duplicate.
  - Sheets-owned rows receive exact Pemberkasan ID when resolvable and remain reimport-idempotent.
  - Protected bank conflict appears in report with `import_conflict`.
  - G09 April/September bank rows resolve to distinct booking IDs and exact file revisions; no legacy transaction-ID merge.

- [ ] **Step 2: Run importer tests and verify failure**

Run: `php artisan test tests/Feature/ImporterBankOwnershipTest.php tests/Feature/ImporterRegressionTest.php`

Expected: FAIL on missing ownership/link protection.

- [ ] **Step 3: Implement importer matching and protection**
  - Add exact Pemberkasan lookup from resolved file and booking before bank upsert.
  - Preserve Laravel-owned rows through existing conflict reporting path.
  - Suppress protected bank stage event emission and booking mutation.
  - Keep Sheets rows ordinary upserts with owner metadata and no Laravel idempotency key.

- [ ] **Step 4: Run importer regressions to green**

Run: `php artisan test tests/Feature/ImporterBankOwnershipTest.php tests/Feature/ImporterRegressionTest.php`

Expected: PASS, including G09/K20 existing assertions.

---

### Task 5: Timeline, Documentation, and UI Contract Verification

**Files:**
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `tests/Feature/PemberkasanTimelineTest.php` or create `tests/Feature/ProsesBankTimelineTest.php`
- Modify: `docs/psjb-staging-workflow.md`
- Modify: `tasks/todo.md`
- Modify: `.superpowers/sdd/2026-11-17-proses-bank-staging-plan/progress.md`

**Interfaces:**
- Booking timeline displays every bank submission in date order with file, revision, bank/unit, sequence, result, decision date, approved amount, tenor, and note.
- Null dates sort last, old revisions remain visible, and bank result does not rewrite booking decision display.

- [ ] **Step 1: Write failing timeline tests**
  - Timeline contains multiple submissions linked to different Pemberkasan revisions.
  - `APPROVE`, `REJECT`, and `REVISI` render as bank results; booking status remains independent.
  - G09 April withdrawal and September history render under separate booking detail pages.
  - Safe audit payload contains no NIK, phone, password, or secret values.

- [ ] **Step 2: Run timeline tests and verify failure**

Run: `php artisan test tests/Feature/ProsesBankTimelineTest.php`

Expected: FAIL on missing bank revision/result details or audit coverage.

- [ ] **Step 3: Implement timeline and docs**
  - Add Pemberkasan revision lookup to bank entries without changing existing stage ordering.
  - Document verified eligibility/status values, history/idempotency behavior, result separation, G09 rule, routes, and unresolved latest-revision business rule.
  - Update task ledger only after automated and browser evidence exists.

- [ ] **Step 4: Run timeline tests to green**

Run: `php artisan test tests/Feature/ProsesBankTimelineTest.php`

Expected: PASS.

---

### Task 6: Full Regression and Browser Verification

**Files:**
- No production code expected; update docs/ledger with evidence only.

- [ ] **Step 1: Run focused regression set**

Run:

```text
php artisan test tests/Feature/PsjbWorkflowTest.php tests/Feature/PemberkasanWorkflowTest.php tests/Feature/PemberkasanControllerTest.php tests/Feature/ImporterPemberkasanOwnershipTest.php tests/Feature/ImporterBankOwnershipTest.php tests/Feature/ImporterRegressionTest.php tests/Feature/ProsesBankWorkflowTest.php tests/Feature/ProsesBankControllerTest.php tests/Feature/ProsesBankTimelineTest.php
```

Expected: PASS; record test/assertion count.

- [ ] **Step 2: Run full suite**

Run: `php artisan test`

Expected: all existing PSJB, BI, Pemberkasan, G09, K20, importer, and new Proses Bank tests pass.

- [ ] **Step 3: Verify routes and formatting baseline**

Run:

```text
php artisan route:list --path=proses-bank
vendor\bin\pint --test
```

Expected: five Proses Bank routes. If Pint fails on pre-existing violations, record baseline failure and do not reformat unrelated files.

- [ ] **Step 4: Verify browser at desktop and mobile**
  - Start/reuse existing staging command with `APP_ENV=staging` and `MARISON_OPERATIONAL_WRITES=true`.
  - At `1440x900`, open authorized pilot Proses Bank queue, create submission, record result, and inspect booking timeline.
  - Verify a second file revision preserves first submission history.
  - At `390x844`, verify queue/forms/result controls, labels, focus, no horizontal overflow, and no console errors.
  - Capture separate desktop/mobile evidence and record browser-created IDs without credentials.

- [ ] **Step 5: Final verification report**
  - Report eligibility rules, submission history/idempotency, result separation, importer conflict behavior, G09 evidence, test counts, route count, desktop/mobile results, Pint baseline, and unresolved latest-revision policy.

## Self-review

- Spec coverage: schema/model contract Task 1; transactional queue/submissions/results Task 2; HTTP authorization/UI Task 3; importer ownership Task 4; timeline/audit/docs Task 5; automated/browser verification Task 6.
- Type consistency: all later tasks consume the exact workflow methods and route names defined above.
- Review focus: all five high-risk inputs have explicit owning tests.
- Scope: no PPJB/Akad/BAST, production cutover, scheduled imports, or unrelated formatter work.
