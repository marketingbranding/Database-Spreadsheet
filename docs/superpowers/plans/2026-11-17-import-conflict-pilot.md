# Import Conflict Pilot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a staging-only, project-scoped import conflict module with branch proposals, HQ approval, lot-scoped booking blocks, transactional corrections, immutable audit history, and fingerprint-aware reimport behavior.

**Architecture:** Durable `ImportConflict` aggregates are projected from transient import issues and HQ stock-position differences. `ImportConflictWorkflow` owns proposal, approval, correction, and closure transactions; existing `PsjbWorkflow` remains the authority for booking allocation and receives only narrow additions needed for conflict blocking and exact-cycle correction.

**Tech Stack:** PHP 8.3, Laravel 13, Blade, Eloquent, PHPUnit 12, SQLite test database/MySQL-compatible migrations.

**Spec:** `docs/superpowers/specs/2026-11-17-import-conflict-pilot-design.md`

## Global Constraints

- Enable only in staging with `MARISON_OPERATIONAL_WRITES=true`.
- Limit to `PRJ-JPR-KWS` and `PRJ-MGL-KLG`.
- Branch admins may propose only for assigned projects; HQ alone approves or rejects.
- Never edit source Sheets.
- Never relink or mutate existing PPJB, Akad, or BAST records through this module.
- Availability is derived from booking claims; it is never stored as an independent override.
- Conflict closure and operational correction must commit atomically.
- Persist stable source identity, reviewed fingerprint, exact booking IDs, before/after values, actor, approver, reason, evidence, and source reference.
- Production cutover and scheduled imports remain out of scope.

## Review Focus

- A source key reused for another project or booking cycle must not cross-link records; Task 2 tests project and exact-booking isolation.
- Formatting-only source changes must retain the fingerprint while a material value change opens a successor review; Task 3 tests both.
- Two concurrent/pending proposals must not bypass uniqueness or idempotency; Task 4 tests identical retry, changed retry, and duplicate pending submission.
- Approval against changed claims or a changed fingerprint must return 409 without partial mutation; Task 5 tests stale approval rollback.
- Closing one conflict must not release a lot that another allocation conflict or valid booking claim still blocks; Task 5 tests both conditions.

---

## File structure

### New files

- `database/migrations/2026_11_20_000001_create_import_conflict_tables.php` — durable conflicts, exact booking links, proposals, and events.
- `app/Models/ImportConflict.php` — conflict states, case types, resolution types, relationships, and blocking query.
- `app/Models/ImportConflictBooking.php` — exact booking-cycle role.
- `app/Models/ImportConflictProposal.php` — proposal actions, states, idempotency metadata, and approval data.
- `app/Models/ImportConflictEvent.php` — append-only conflict event.
- `app/Services/Import/ImportConflictProjector.php` — canonical snapshots, identities, fingerprints, issue projection, and stock-difference detection.
- `app/Services/Operations/ImportConflictWorkflow.php` — proposal and HQ review transactions.
- `app/Console/Commands/ProjectImportConflicts.php` — read-only preview and
  conflict-only projection from current pilot Sheets/snapshots.
- `app/Http/Controllers/ImportConflictController.php` — scoped list/detail/proposal/review endpoints.
- `app/Http/Requests/StoreImportConflictProposalRequest.php` — action-specific branch/HQ proposal validation.
- `app/Http/Requests/ReviewImportConflictProposalRequest.php` — HQ approval/rejection validation.
- `resources/views/import-conflicts/index.blade.php` — project conflict register.
- `resources/views/import-conflicts/show.blade.php` — evidence, values, proposals, controls, and event history.
- `tests/Feature/ImportConflictSchemaTest.php`
- `tests/Feature/ImportConflictProjectionTest.php`
- `tests/Feature/ImportConflictReimportTest.php`
- `tests/Feature/ImportConflictWorkflowTest.php`
- `tests/Feature/ImportConflictControllerTest.php`
- `tests/Feature/ImportConflictStagingRehearsalTest.php`
- `docs/import-conflict-staging-rehearsal.md`

### Modified files

- `app/Models/ImportIssue.php`, `Project.php`, `Booking.php`, `Lot.php`, and `User.php` — relationships only.
- `app/Models/BookingAudit.php` — `ACTION_IMPORT_CONFLICT_RESOLVED`.
- `app/Services/Import/ProjectImporter.php` — capture named source snapshots/HQ positions and invoke projector in the import transaction.
- `app/Services/Operations/PsjbWorkflow.php` — exclude blocked lots, enforce create-time block, and apply exact-cycle corrections.
- `config/marison.php` and `.env.example` — disabled-by-default pilot feature gate.
- `routes/web.php` — project-scoped conflict routes.
- `resources/views/layouts/app.blade.php` and `resources/views/projects/show.blade.php` — pilot navigation.
- `app/Http/Controllers/ImportController.php` — remove unsafe direct issue resolution route/action.

---

### Task 1: Durable conflict schema and model contracts

**Files:**
- Create: `database/migrations/2026_11_20_000001_create_import_conflict_tables.php`
- Create: `app/Models/ImportConflict.php`
- Create: `app/Models/ImportConflictBooking.php`
- Create: `app/Models/ImportConflictProposal.php`
- Create: `app/Models/ImportConflictEvent.php`
- Modify: `app/Models/ImportIssue.php`
- Modify: `app/Models/Project.php`
- Modify: `app/Models/Booking.php`
- Modify: `app/Models/Lot.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/BookingAudit.php`
- Test: `tests/Feature/ImportConflictSchemaTest.php`

**Interfaces:**
- Produces: `ImportConflict::blocksAllocation(): bool`
- Produces: `ImportConflict::openAllocationDisputesForLot(int $projectId, int $lotId): Builder`
- Produces: model constants exactly matching the spec's case, status, action, role, event, and resolution values.

- [ ] **Step 1: Write the failing schema and constant tests**

Assert all four tables and required columns exist, foreign keys restrict audit-history deletion, proposal `idempotency_key` is unique, exact conflict/booking links are unique, JSON fields cast to arrays, and `BookingAudit::ACTION_IMPORT_CONFLICT_RESOLVED` equals `import_conflict_resolved`.

- [ ] **Step 2: Run the schema test and verify failure**

Run: `php artisan test --compact tests/Feature/ImportConflictSchemaTest.php`

Expected: FAIL because conflict tables and models do not exist.

- [ ] **Step 3: Add the migration and focused models**

Use portable unique constraints:

- conflict identity: `project_id + stable_source_identity + source_fingerprint`;
- booking link: `import_conflict_id + booking_id + role`;
- proposal idempotency: global unique `idempotency_key`;
- one pending proposal: nullable `pending_guard`, set to `1` only while pending,
  with unique `import_conflict_id + pending_guard`.

Represent affected lots through `import_conflict_lot` (`import_conflict_id`,
`lot_id`) rather than a JSON-only relation so allocation queries remain
indexable; retain `inventory_impact` as JSON evidence.

- [ ] **Step 4: Add relationships to existing models**

Add only relationships and the booking-audit constant; do not add mutation
logic to models.

- [ ] **Step 5: Run the test and verify pass**

Run: `php artisan test --compact tests/Feature/ImportConflictSchemaTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_11_20_000001_create_import_conflict_tables.php app/Models tests/Feature/ImportConflictSchemaTest.php
git commit -m "feat: add durable import conflict records"
```

### Task 2: Canonical conflict projection and exact-cycle evidence

**Files:**
- Create: `app/Services/Import/ImportConflictProjector.php`
- Create: `app/Console/Commands/ProjectImportConflicts.php`
- Modify: `app/Services/Import/ProjectImporter.php`
- Test: `tests/Feature/ImportConflictProjectionTest.php`

**Interfaces:**
- Consumes: Task 1 models and constants.
- Produces: `ImportConflictProjector::preview(Project $project, array $issues, array $rawTabs, array $hqPositions): array`
- Produces: `ImportConflictProjector::persist(Project $project, ImportBatch $batch, array $rawTabs, array $hqPositions): void`
- Produces: `ImportConflictProjector::persistCurrent(Project $project, array $issues, array $rawTabs, array $hqPositions): void`
- Produces: `ImportConflictProjector::fingerprint(string $caseType, array $sourceValue): string`

- [ ] **Step 1: Write failing projection tests**

Cover:

- M22-style `multiple_active_bookings` maps both exact booking IDs and the lot;
- an orphan BI row stores tab, row, named Sheets values, no guessed booking ID,
  and an explicit inventory impact;
- L02/M20-style disagreement is detected from `hq_report_export` position
  versus claim-derived Laravel availability even without an `ImportIssue`;
- same source key in another project cannot link its booking;
- two cycles sharing one legacy transaction retain exact IDs and roles;
- raw row values are converted to named, escaped data rather than stored as an
  unlabeled positional array.

- [ ] **Step 2: Run the test and verify failure**

Run: `php artisan test --compact tests/Feature/ImportConflictProjectionTest.php`

Expected: FAIL because the projector does not exist.

- [ ] **Step 3: Implement canonical descriptors**

Implement case-specific allowlists for claims, BI, stock, bank rejection, and
signed documents. Stable identity includes project code, case type, source tab,
and normalized source key/business locator. Link bookings only by resolved
database IDs in the same project.

- [ ] **Step 4: Add stock-position capture to the importer**

Extend `parseHqExport()` to retain a normalized `lot code => posisi_rumah` map
in importer state. Reset this state between runs. Do not put full source rows in
logs or command output.

- [ ] **Step 5: Integrate preview and persistence**

For dry-run, call `preview()` and expose conflict counts in report stats without
database writes. For apply, persist import issues first, then call `persist()`
inside the existing transaction before commit.

- [ ] **Step 6: Add the conflict-only projection command**

Add `marison:conflicts:project {project} {--apply}`. Without `--apply` it reads
current source/snapshots and prints descriptors without writes. With `--apply`
it persists conflict records only—never operational booking/import records—and
is restricted to staging pilot codes.

- [ ] **Step 7: Run focused importer and projection tests**

Run:

```bash
php artisan test --compact tests/Feature/ImportConflictProjectionTest.php tests/Feature/ImporterRegressionTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Import/ImportConflictProjector.php app/Services/Import/ProjectImporter.php app/Console/Commands/ProjectImportConflicts.php tests/Feature/ImportConflictProjectionTest.php
git commit -m "feat: project import evidence into conflicts"
```

### Task 3: Fingerprint-aware reimport preservation

**Files:**
- Modify: `app/Services/Import/ImportConflictProjector.php`
- Modify: `app/Services/Import/ProjectImporter.php`
- Test: `tests/Feature/ImportConflictReimportTest.php`

**Interfaces:**
- Consumes: Task 2 `preview`, `persist`, and `fingerprint`.
- Produces: successor conflicts through `supersedes_conflict_id`.

- [ ] **Step 1: Write failing reimport tests**

Assert:

- unchanged source reuses one open conflict rather than duplicating it;
- an unchanged fingerprint previously resolved by `CONFIRM_LARAVEL` remains
  resolved and the newly generated `ImportIssue` is marked resolved;
- an applied Laravel correction remains preserved by importer ownership rules;
- whitespace, date formatting, and case normalization do not reopen review;
- changed lot, booking identity, buyer identity, status, BI result/decision, or
  chain reference creates one new open conflict linked to the prior conflict;
- material change marks an unresolved prior fingerprint `SUPERSEDED` so only
  the new version remains actionable;
- a bank-reject conflict closes with an event only after the existing booking
  decision workflow supplies a valid MUNDUR/BATAL decision; other disappeared
  conflict types do not silently close;
- dry-run creates no conflict, proposal, event, or issue rows.

- [ ] **Step 2: Run the test and verify failure**

Run: `php artisan test --compact tests/Feature/ImportConflictReimportTest.php`

Expected: FAIL on conflict reuse/reopening behavior.

- [ ] **Step 3: Implement conflict reuse and supersession**

Lock by project and stable identity. Reuse identical fingerprints, honor a
reviewed fingerprint, and create a successor only for material change. Never
alter prior proposals or events. Reconcile absent bank-reject descriptors
against the current booking decision, but require explicit HQ resolution for
all other cases.

- [ ] **Step 4: Run reimport and ownership regressions**

Run:

```bash
php artisan test --compact tests/Feature/ImportConflictReimportTest.php tests/Feature/BookingChangeImporterOwnershipTest.php tests/Feature/ImporterAkadOwnershipTest.php tests/Feature/ImporterBastOwnershipTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Import/ImportConflictProjector.php app/Services/Import/ProjectImporter.php tests/Feature/ImportConflictReimportTest.php
git commit -m "feat: preserve conflict decisions across imports"
```

### Task 4: Branch proposals, access control, and idempotency

**Files:**
- Create: `app/Services/Operations/ImportConflictWorkflow.php`
- Create: `app/Http/Controllers/ImportConflictController.php`
- Create: `app/Http/Requests/StoreImportConflictProposalRequest.php`
- Create: `app/Http/Requests/ReviewImportConflictProposalRequest.php`
- Modify: `config/marison.php`
- Modify: `.env.example`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ImportController.php`
- Test: `tests/Feature/ImportConflictControllerTest.php`

**Interfaces:**
- Produces: `ImportConflictWorkflow::propose(User $actor, ImportConflict $conflict, array $input): ImportConflictProposal`
- Produces: `ImportConflictWorkflow::approve(User $hq, ImportConflictProposal $proposal, array $input): ImportConflict`
- Produces: `ImportConflictWorkflow::reject(User $hq, ImportConflictProposal $proposal, string $reason): ImportConflictProposal`

- [ ] **Step 1: Write failing access and proposal tests**

Cover branch access only to assigned pilot projects, cross-project nested ID
tampering, non-pilot and non-staging rejection, inactive users, HQ review
access, disabled `MARISON_IMPORT_CONFLICTS_ENABLED`, action-specific validation,
evidence/reason limits, and signed-case action allowlists.

- [ ] **Step 2: Add failing duplicate request tests**

Assert identical idempotent retries return one proposal, changed payload reuse
returns 409, a second pending proposal returns 409, and concurrent uniqueness
violations map to 409 rather than 500.

- [ ] **Step 3: Run controller tests and verify failure**

Run: `php artisan test --compact tests/Feature/ImportConflictControllerTest.php`

Expected: FAIL because routes and workflow are absent.

- [ ] **Step 4: Implement request contracts and proposal transaction**

Hash the canonical action-specific payload. Claim idempotency through the unique
constraint, lock the conflict, and append `PROPOSAL_SUBMITTED`. A rejected
proposal leaves its conflict `OPEN`; approval is HQ-only.

Add `marison.import_conflicts_enabled`, backed by
`MARISON_IMPORT_CONFLICTS_ENABLED=false`, and enforce it in every module
controller action without disabling the projection/rehearsal command.

- [ ] **Step 5: Remove unsafe direct issue closure**

Delete the `ImportController::resolve()` action and its route. Existing import
pages may link to the project conflict detail instead; no user may directly set
`ImportIssue.resolved_at`.

- [ ] **Step 6: Run controller tests and verify pass**

Run: `php artisan test --compact tests/Feature/ImportConflictControllerTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Operations/ImportConflictWorkflow.php app/Http routes/web.php config/marison.php .env.example tests/Feature/ImportConflictControllerTest.php
git commit -m "feat: add scoped conflict proposals"
```

### Task 5: Lot blocking and transactional HQ resolutions

**Files:**
- Modify: `app/Services/Operations/PsjbWorkflow.php`
- Modify: `app/Services/Operations/ImportConflictWorkflow.php`
- Modify: `app/Models/BookingAudit.php`
- Test: `tests/Feature/ImportConflictWorkflowTest.php`

**Interfaces:**
- Produces: `PsjbWorkflow::applyConflictClaimResolution(User $actor, Project $project, array $input): array`
- Consumes: Task 4 `approve()` and Task 1 allocation-dispute query.

- [ ] **Step 1: Write failing lot-block tests**

Assert an unresolved competing-claim or stock conflict removes only its lots
from `availableLots()` and rejects a stale create request after locking. An
unlinked BI, bank-reject, or signed escalation does not block unrelated lots.

- [ ] **Step 2: Write failing approval tests**

Cover:

- `CONFIRM_LARAVEL` records reviewed fingerprint and closes without changing
  bookings;
- `LINK_BI` calls `PsjbWorkflow::recordBiCheck()` for the approved exact
  booking using the canonical source snapshot and refuses an ambiguous or
  already Laravel-owned BI target;
- `CORRECT_LARAVEL` names exact booking IDs and explicit dispositions;
- current claims and fingerprint are revalidated under lock;
- one holder at most remains;
- an active holder keeps the lot unavailable;
- another open allocation conflict keeps the lot blocked;
- stale claims/fingerprint return 409;
- signed documents reject mutation but permit escalation/confirmation;
- before/after `BookingAudit` and conflict event include actor, HQ approver,
  reason, evidence, and source reference;
- an injected failure rolls back booking, audit, proposal, event, and conflict.

- [ ] **Step 3: Run workflow tests and verify failure**

Run: `php artisan test --compact tests/Feature/ImportConflictWorkflowTest.php`

Expected: FAIL on missing block and approval behavior.

- [ ] **Step 4: Add allocation block to `PsjbWorkflow`**

Apply the same indexed unresolved-conflict predicate in `availableLots()` and
inside `create()` after project/lot locks.

- [ ] **Step 5: Add exact-cycle correction method**

`applyConflictClaimResolution()` accepts:

- `conflict_id`;
- `current_booking_id` nullable;
- `dispositions`: exact booking ID to `MUNDUR` or `BATAL`;
- `reason`;
- `source_reference`.

It verifies every ID belongs to the locked project/lot/conflict, rejects signed
cycles, delegates withdrawal/cancellation semantics to private shared domain
helpers extracted from existing public methods, recalculates current-holder
flags, and returns before/after snapshots.

For `LINK_BI`, approval validates the exact booking cycle and converts only the
canonical BI fields into the existing `recordBiCheck()` input contract. It does
not infer a booking when the proposal omits the target ID.

- [ ] **Step 6: Implement HQ approval transaction**

Lock proposal, conflict, project, lots, and linked bookings in deterministic ID
order. Apply the selected action, verify postconditions, record audit/events,
then mark proposal `APPLIED` and conflict `RESOLVED`.

- [ ] **Step 7: Run workflow and booking regressions**

Run:

```bash
php artisan test --compact tests/Feature/ImportConflictWorkflowTest.php tests/Feature/PsjbWorkflowTest.php tests/Feature/BookingChangeWorkflowTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Operations app/Models/BookingAudit.php tests/Feature/ImportConflictWorkflowTest.php
git commit -m "feat: apply approved conflict resolutions"
```

### Task 6: Conflict register and approval UI

**Files:**
- Create: `resources/views/import-conflicts/index.blade.php`
- Create: `resources/views/import-conflicts/show.blade.php`
- Modify: `app/Http/Controllers/ImportConflictController.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/projects/show.blade.php`
- Modify: `resources/views/imports/index.blade.php`
- Modify: `resources/views/imports/show.blade.php`
- Test: `tests/Feature/ImportConflictControllerTest.php`

**Interfaces:**
- Consumes: Task 4 routes/workflow and Task 1 relationships.
- Produces: accessible project-scoped HTML forms and conflict register.

- [ ] **Step 1: Add failing rendering tests**

Assert the pages show exact lot, exact booking IDs/cycles, source tab/row/key,
fingerprint, labeled Sheets/Laravel values, inventory impact, evidence,
proposal status, actor/approver, event history, and signed-document warning.
Assert bank-reject conflicts link to the existing booking decision workflow.

- [ ] **Step 2: Run rendering tests and verify failure**

Run:

```bash
php artisan test --compact --filter=ImportConflictControllerTest
```

Expected: FAIL because views do not exist.

- [ ] **Step 3: Build the list and detail pages**

Use existing layout/classes and Blade escaping. Branch users see proposal
controls; HQ sees pending approval/rejection controls. Hide controls that the
server would reject, while retaining server-side authorization.

- [ ] **Step 4: Add navigation and replace direct issue resolution**

Add project conflict links and point supported import issues to their durable
conflict. Do not expose a direct “mark resolved” button.

- [ ] **Step 5: Run controller tests and targeted Pint**

Run:

```bash
php artisan test --compact tests/Feature/ImportConflictControllerTest.php
vendor/bin/pint --test app/Http/Controllers/ImportConflictController.php app/Http/Requests/StoreImportConflictProposalRequest.php app/Http/Requests/ReviewImportConflictProposalRequest.php
```

Expected: tests PASS; Pint reports no targeted changes.

- [ ] **Step 6: Commit**

```bash
git add resources/views app/Http/Controllers/ImportConflictController.php app/Http/Requests tests/Feature/ImportConflictControllerTest.php
git commit -m "feat: add import conflict review screens"
```

### Task 7: Staging-fixture rehearsal and complete verification

**Files:**
- Create: `tests/Feature/ImportConflictStagingRehearsalTest.php`
- Create: `docs/import-conflict-staging-rehearsal.md`
- Modify: `docs/psjb-staging-workflow.md`

**Interfaces:**
- Consumes: all prior tasks.
- Produces: rollback-safe pilot rehearsal evidence and operator procedure.

- [ ] **Step 1: Write the staging rehearsal test**

Using fixture copies, project M22-style competing claims, an orphan BI row,
L02/M20-style stock disagreements, and one signed-document conflict. Exercise:

- affected-lot block with unaffected-lot create in a rolled-back transaction;
- branch proposal and HQ `CONFIRM_LARAVEL`;
- fixture-only `CORRECT_LARAVEL`;
- unchanged reimport preservation;
- material source change reopening;
- signed record/link preservation.

- [ ] **Step 2: Run the rehearsal test and fix only observed defects**

Run: `php artisan test --compact tests/Feature/ImportConflictStagingRehearsalTest.php`

Expected: PASS with no external Sheets writes.

- [ ] **Step 3: Preview and project current staging conflicts**

Run with the UI feature gate still disabled:

```bash
APP_ENV=staging php artisan marison:conflicts:project PRJ-JPR-KWS
APP_ENV=staging php artisan marison:conflicts:project PRJ-MGL-KLG
APP_ENV=staging php artisan marison:conflicts:project PRJ-JPR-KWS --apply
APP_ENV=staging php artisan marison:conflicts:project PRJ-MGL-KLG --apply
```

Verify the preview and apply counts match, operational booking/document row
checksums are unchanged, and M22, orphan BI, L02/M20, four Kalinegoro transfer
links, and bank-reject routes retain exact project/source/booking evidence.

- [ ] **Step 4: Enable the staging UI and perform browser verification**

Set `MARISON_IMPORT_CONFLICTS_ENABLED=true` only after Step 3 succeeds. Reuse
the project dev command/server. Check branch and HQ states at approximately
1440×900 and 390×844. Exercise list/detail, proposal validation, pending,
approved/rejected, escalation-only, and bank-route states. Verify no console
errors, overflow, broken controls, or obvious keyboard/focus issues.

- [ ] **Step 5: Record the rehearsal**

Document commands, projected pilot counts, sample conflict identities,
unchanged/materially changed reimport outcomes, affected-lot behavior, signed
record checks, rollback procedure, and remaining approval/tooling gaps.

- [ ] **Step 6: Run complete verification**

Run:

```bash
php artisan test --compact
vendor/bin/pint --test app/Models/ImportConflict.php app/Models/ImportConflictBooking.php app/Models/ImportConflictProposal.php app/Models/ImportConflictEvent.php app/Services/Import/ImportConflictProjector.php app/Services/Operations/ImportConflictWorkflow.php app/Http/Controllers/ImportConflictController.php app/Http/Requests/StoreImportConflictProposalRequest.php app/Http/Requests/ReviewImportConflictProposalRequest.php tests/Feature/ImportConflictSchemaTest.php tests/Feature/ImportConflictProjectionTest.php tests/Feature/ImportConflictReimportTest.php tests/Feature/ImportConflictWorkflowTest.php tests/Feature/ImportConflictControllerTest.php tests/Feature/ImportConflictStagingRehearsalTest.php
git diff --check
```

Expected: all tests pass, targeted Pint is clean, and diff check is empty.

- [ ] **Step 7: Verify deployment assets**

Confirm operational conflict pages inherit `layouts.app` and therefore use
`public/css/app.css`; report the existing welcome-page Vite lockfile limitation
separately without blocking pilot UI deployment.

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/ImportConflictStagingRehearsalTest.php docs
git commit -m "docs: record import conflict staging rehearsal"
```
