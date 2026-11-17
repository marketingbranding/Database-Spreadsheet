# PSJB staging workflow

## Scope

Operational writes cover PSJB create, BI Checking, Pemberkasan, Proses Bank,
TTD PPJB, Withdraw/Mundur, and Cancel/Batal for Kalinegoro and Kuwasen staging
only. Pre-signing buyer substitutions (Ganti Nama) and lot transfers (Pindah
Kavling) are also available through the Laravel workflow. Akad and BAST remain
Laravel-managed as previously documented.

Production Sheets stay read-only. Do not enable operational writes or switch
production project mode. Mode command accepts only pilot codes `PRJ-MGL-KLG`
and `PRJ-JPR-KWS`.

## Ganti Nama and Pindah Kavling

These actions are limited to active bookings before a PPJB, Akad, or BAST record
exists. The record-level document check is authoritative even if the booking
stage is stale. A change preserves the source booking and creates a fresh PSJB
successor with an explicit transition record containing the source and successor
IDs, action, effective date, reason, actor, and idempotency key.

Ganti Nama keeps the same lot and requires a different buyer. Pindah Kavling
keeps the buyer, locks and reserves the destination, and releases the source
only after the destination is confirmed available. Payment method, price,
sales/coordinator, and UTJ metadata carry forward. The successor references the
original PSJB UTJ record so it is not counted as a second payment. BI,
Pemberkasan, bank approvals, PPJB, Akad, and BAST remain attached to the source.

Changes after signed documents are still Sheets-managed and must not be
performed through these staging forms. Laravel-owned source bookings and their
successors survive stale Sheets imports; conflicting source rows are reported as
`import_conflict`.

## Konflik Impor pilot

The project-scoped Konflik Impor register is staging-only and disabled by
default with `MARISON_IMPORT_CONFLICTS_ENABLED=false`. It presents exact lot,
booking cycle, source tab/row/key, fingerprint, labeled Sheets/Laravel values,
and inventory impact. Branch admins submit proposed resolutions with evidence;
HQ approves or rejects. Approved claim and BI corrections run through
`PsjbWorkflow` in one transaction and create booking audits plus conflict
events. `CONFIRM_LARAVEL` records the reviewed fingerprint without changing
records; a material source change opens a successor review and retains history.

Only unresolved `COMPETING_CLAIMS` and `STOCK_DISAGREEMENT` conflicts block new
bookings, and only on their exact lots. Other lots remain writable. A lot with
an active holder remains unavailable even after its conflict closes.

Bank rejects are routed to the existing booking decision workflow. PPJB, Akad,
and BAST conflicts accept evidence and HQ escalation only; signed documents and
their links are never changed by this module.

See `docs/import-conflict-staging-rehearsal.md` for commands, projected counts,
sample evidence, browser checks, rollback, and pending HQ decisions.

## Staging setup

Run dry-runs first. Review unresolved issues. Explicit acknowledgment required
when switching mode with unresolved issues:

```bash
php artisan marison:import --project=PRJ-MGL-KLG --dry-run
php artisan marison:import --project=PRJ-JPR-KWS --dry-run
php artisan marison:project-mode PRJ-MGL-KLG laravel --acknowledge
php artisan marison:project-mode PRJ-JPR-KWS laravel --acknowledge
```

Enable writes only in staging environment:

```dotenv
APP_ENV=staging
MARISON_OPERATIONAL_WRITES=true
```

Application rejects operational writes unless `APP_ENV=staging`; `MARISON_OPERATIONAL_WRITES=true` alone does not enable them.

Production must use:

```dotenv
APP_ENV=production
MARISON_OPERATIONAL_WRITES=false
```

Only assigned `branch_admin` users may create or decide bookings. HQ remains
read-only for this workflow.

## BI Checking

1. Open **BI Checking** from project page for a Laravel-managed pilot project.
2. Queue includes project-scoped KPR bookings in PSJB/BI stage with actionable
   status only. Records open by internal booking ID, but project ownership is
   checked before and inside transactional write.
3. Enter check date, result, notes, and exact decision `LANJUT` or
   `TIDAK LANJUT`. Assigned branch admins may save; HQ may view only.
4. BI values become Laravel-owned, booking stage becomes `BI_CHECKING`, and the
   booking timeline plus immutable audit retain decision data.
5. `TIDAK LANJUT` does not release lot. Use explicit Withdraw/Mundur or
   Cancel/Batal to release claim. Repeat booking gets a new booking ID while
   original BI history remains attached.

## Pemberkasan

1. Open **Pemberkasan** from a Laravel-managed pilot project in staging.
2. Exact `CASH` bookings may enter directly after PSJB. `KPR` requires the
   latest Laravel-owned BI decision `LANJUT`; missing BI or latest `TIDAK LANJUT`
   stays out of queue and direct writes are rejected.
3. Assigned branch admins submit `No. berkas`, bank details, status, and notes.
   Each distinct file creates a new Laravel-owned revision; earlier records stay
   visible in booking history. Same booking plus same non-empty file number
   returns `409` and creates no duplicate.
4. Withdraw/Cancel preserves all Pemberkasan records and records the decision at
   stage `PEMBERKASAN`. Pemberkasan rows owned by Laravel survive stale Sheets
   imports; differing source values produce warning `import_conflict`.

## Create PSJB

1. Open project page.
2. Select **Buat PSJB**.
3. Choose available lot, buyer, salesperson, payment method, PSJB date, and
   optional UTJ/price/DP details.
4. Submit once. Server locks project and lot, checks claims again, then writes
   booking, PSJB record, and audit row in one transaction.

Valid claims are `ACTIVE`, `COMPLETED`, and `NEEDS_REVIEW`. Historical Akad lots
and lots with multiple claims are unavailable. A concurrent conflicting submit
receives a lot conflict and creates no booking.

## Withdraw / Cancel

Booking detail exposes actions only for `ACTIVE` and `NEEDS_REVIEW`. Each action
locks project, booking, and lot, preserves PSJB and later stage rows, records
actor/time/before/after in `booking_audits`, and marks booking Laravel-owned.
A later booking receives a new `LARAVEL:PSJB:<UUID>` key; previous salesperson
and history remain unchanged.

## Proses Bank

1. Open **Proses Bank** from a Laravel-managed pilot project in staging.
2. Queue includes project-scoped `KPR` bookings only, status `ACTIVE` (not
   `NEEDS_REVIEW`), current stage `PEMBERKASAN` or `PROSES_BANK`, with a current
   Pemberkasan whose status is `Analisa`, `Input`, `OTS`, or `LENGKAP`. `CASH`
   bookings and `Pemberkasan CASH` files never enter the queue. A `NEEDS_REVIEW`
   booking is excluded from the queue and its create endpoint returns `422`;
   its existing submission history stays reachable through the booking
   timeline for any authorized user regardless of eligibility.
3. Each submission links to exact `booking_id` and `pemberkasan_id`; a retry
   with the same idempotency key returns the existing submission and creates
   no duplicate, a different key creates a new history row with the next
   `submission_sequence`. A later current Pemberkasan revision never retargets
   or relabels an earlier submission; all submissions stay attached to the
   file revision they were created against.
4. Recording a result accepts only `APPROVE`, `REJECT`, or `REVISI`. `REJECT`
   never mutates booking status or decision fields; it stays a bank-only
   outcome. Use explicit Withdraw/Mundur or Cancel/Batal to change the booking
   decision. A result retry with the identical input is idempotent; a
   conflicting retry on an already-decided submission returns `409`.
5. Booking detail eager loads `bankSubmissions.pemberkasan` and lists every
   submission — current and historical — in ascending submitted-date order
   with null/blank dates last. Each entry shows exact submission ID,
   Pemberkasan ID, file number, revision, bank/unit, sequence, submitted and
   decision dates, result, approved amount, and tenor. G09 April and
   September bookings are separate records; each booking page shows only its
   own bank history.
6. Routes: `GET /projects/{project}/proses-bank`,
   `GET /projects/{project}/proses-bank/{booking}/create`,
   `POST /projects/{project}/proses-bank/{booking}`,
   `GET /projects/{project}/proses-bank/{submission}/result`,
   `POST /projects/{project}/proses-bank/{submission}/result`.
7. Import matches bank rows by source key first, then by exact booking +
   Pemberkasan/file business key. Laravel-owned bank rows survive stale Sheets
   reimport unchanged; differing incoming values emit `import_conflict` and do
   not overwrite protected fields.
8. Open business rule: resubmission is allowed against any current eligible
   Pemberkasan revision and all previous submissions remain visible. If
   operations require only the latest revision or only one active submission
   per bank, add that policy before production cutover.

## TTD PPJB

1. Open **TTD PPJB** from a Laravel-managed pilot project. Only assigned branch
   admins may access the queue/form and sign. Writes also require the staging
   environment and operational-write flag.
2. Both paths require `ACTIVE` status and no existing PPJB:
   - `KPR`: stage `PROSES_BANK`, with an `APPROVE` submission belonging to the
     exact booking and project. One qualifying submission is preselected;
     multiple approvals require explicit radio-button selection. There is no
     inferred "latest approval wins" rule.
   - `CASH`: stage `PEMBERKASAN`, with the current Pemberkasan revision having
     status `Pemberkasan CASH`.
3. Required signing fields: PPJB number, signing date, idempotency key, and
   exactly one source ID (`bank_submission_id` for KPR or `pemberkasan_id` for
   CASH). Status DP and notes are optional. Source IDs from another booking,
   cycle, or project cannot qualify the signature.
4. Record creation, stage advancement to `PPJB`, and `PPJB_CREATED` audit run
   in one transaction. Identical retries return the existing record even after
   the booking leaves the queue; changed payload with the same key returns
   `409`. A booking cannot acquire a second signature through this workflow.
5. Source foreign keys retain the selected record, not a dynamic latest pointer.
   Later bank-result changes or Pemberkasan revisions do not retarget the PPJB.
   The timeline displays the source record ID. Physical deletion of a source
   would null its foreign key under the migration; no deletion UI is added.
6. Laravel-owned PPJB values survive stale Sheets imports. Differences emit
   `import_conflict`; a different Sheets source key does not create a duplicate
   PPJB for that protected booking.
7. Routes:
   - `GET /projects/{project}/ttd-ppjb` (`ppjb.index`)
   - `GET /projects/{project}/ttd-ppjb/{booking}/create` (`ppjb.create`)
   - `POST /projects/{project}/ttd-ppjb/{booking}` (`ppjb.store`)

### PPJB verification status — 17 Nov 2026

- [x] Full suite: `php artisan test` — **123 passed, 1909 assertions**.
- [x] Focused PPJB/ownership suite — **14 passed, 63 assertions**.
- [x] Mixed-source-ID regression failed before the exactly-one-source fix and
  passed afterward.
- [x] Tests cover KPR/CASH signing, exact source links, multiple approval
  selection, G09-style April/September cycle isolation, retry with one record
  and one creation audit, later result/file changes, and stale Sheets conflicts.
- [x] Earlier browser KPR signing: booking `226`, submission `319`, signature
  `PPJB-BROWSER-226`; redirect/timeline confirmed.
- [x] Playwright screenshots reviewed: desktop `1440x900` queue and KPR form;
  mobile `390x844` queue and CASH form. Mobile document width equals viewport
  width (`390`); queue uses its existing internal horizontal table scroller.
- [x] KPR form console check: zero warnings/errors on that page.
- [x] CASH browser signing: authorized `requestSubmit()` completed booking
  `310`; redirect and timeline confirmed `PPJB-BROWSER-310` linked to
  `Berkas #324`.
- [x] Signed CASH timeline reviewed at desktop `1440x900`; mobile form and
  timeline state had no horizontal overflow, and the final browser console
  check reported zero warnings/errors.

### Production follow-ups and business decisions

- Proses Bank simultaneous idempotency-key retries: the unique constraint
  prevents duplicates, but a racing request may surface a raw database
  exception. Add catch-and-replay handling and a real concurrent HTTP test
  before production. Sequential replay tests are not concurrency evidence.
- PPJB production concurrency likewise needs an independent-session test;
  transaction/project locking and a unique key are not a substitute for it.
- Multiple bank approvals deliberately use explicit operator selection because
  no authoritative latest-approval rule was found. Confirm any narrower bank
  policy before production; the implementation does not silently choose one.
- Repository-wide Pint still has baseline failures; no broad formatting cleanup
  is included. No production cutover, scheduled imports, Akad, or BAST work is
  included.

## Import ownership

For `sheets` projects, ordinary import behavior remains unchanged.

For `laravel` projects:

- Laravel-owned bookings are not overwritten by Sheets.
- Differences in protected booking/PSJB fields produce warning
  `import_conflict` in import report/issues.
- Source stage rows still import, but cannot recompute protected Laravel booking
  rollups or holder flags.
- Dry-run compares inside rollback and persists nothing.
- New Sheets bookings remain Sheets-owned until an operational write changes
  ownership.

## Verification

Pemberkasan staging verification, 17 Nov 2026:

- Full SQLite suite: `63 passed`, `655 assertions`.
- Routes: three named routes confirmed with `php artisan route:list --path=pemberkasan`.
- Browser staging server: `APP_ENV=staging`, `MARISON_OPERATIONAL_WRITES=true`.
- Desktop `1440x900`: queue rendered 3 eligible bookings, no console errors.
- Mobile `390x844`: queue and form rendered without horizontal overflow, no console errors.
- CASH submission redirected to booking detail; timeline showed `BROWSER-20261116-001`, revision `1`, current state, bank, status, and note.


SQLite behavior suite:

```bash
php artisan test
```

Focused ownership checks:

```bash
php artisan test tests/Feature/PsjbWorkflowTest.php tests/Feature/ImporterOwnershipTest.php tests/Feature/BiWorkflowTest.php tests/Feature/BiControllerTest.php tests/Feature/ImporterBiOwnershipTest.php
```

Verified staging HTTP concurrency evidence, 16 Nov 2026:

- Project `PRJ-JPR-KWS`, lot `B02` (`lot_id=21`), two independent sessions.
- Request 1: `302` to `/bookings/451` — PSJB created.
- Request 2: `302` back to `/projects/PRJ-JPR-KWS/psjb/create` — lot conflict validation.
- Database counts after race: `all_bookings_for_lot=1`,
  `active_bookings_for_lot=1`, `creation_audits_for_lot=1`,
  `active_allocations_for_lot=1`.
- MySQL lock smoke previously measured approximately `5,021 ms` wait behind
  a five-second `lockForUpdate()` holder.

MySQL staging smoke:

1. Point `.env` to staging MySQL.
2. Run migrations and seed test users.
3. Start two independent requests for same available lot.
4. Confirm one `ACTIVE` booking only; second request gets lot conflict.
5. Confirm booking audit has actor, action, before/after, request ID.

MySQL row lock uses `lockForUpdate()` inside transactions. Project lock comes
first, lot lock second for both imports and operational writes.

Proses Bank timeline verification, 17 Nov 2026:

- Focused: `php artisan test tests/Feature/ProsesBankTimelineTest.php tests/Feature/PemberkasanTimelineTest.php tests/Feature/BookingAuditTest.php` — `7 passed`, `282 assertions`.
- Full SQLite suite: `106 passed`, `1830 assertions`.
- Pint baseline: `vendor\bin\pint --test` fails on pre-existing `app/Services/Operations/PsjbWorkflow.php` (`concat_space`, `unary_operator_spaces`, `not_operator_with_successor_space`, `blank_line_before_statement`, `ordered_imports`) and pre-existing `app/Http/Controllers/BookingController.php` (`concat_space`); both predate this task and are not reformatted.
- Browser staging verification remains pending Task 6.

## Unresolved staging issues

Existing source exceptions remain visible in reconciliation/import issues. Do
not resolve them by changing operational records. Branch teams must review
Kuwasen orphan BI, unmapped PSJB statuses, bank-reject warnings, and multiple
PSJB cycle notes; Kalinegoro transfer-link mismatches and bank-reject warning
also remain source review items.
