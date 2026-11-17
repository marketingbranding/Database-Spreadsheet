# PSJB Operational Write Workflow — Design

Date: 2026-11-16

## Intent

Let authorized branch admins create, withdraw, and cancel PSJB bookings for
Kalinegoro and Kuwasen in staging without losing existing Sheets history or
allowing two users to claim one lot.

Production remains Sheets-managed and read-only during this phase.

## Goals

- Create a new booking and PSJB record from Blade UI.
- Choose authorized project, available lot, existing/new buyer, salesperson,
  payment method, PSJB date/number, UTJ date/amount, DP, and installment count.
- Preserve withdrawn/cancelled bookings and all existing stage dates.
- Give every operational write an actor, action, before/after values, and time.
- Lock lot allocation inside a MySQL transaction.
- Reject M22-like unresolved competing claims.
- Preserve Laravel-owned records during ordinary Sheets imports.
- Report source conflicts without overwriting Laravel edits.
- Keep existing dashboard, timeline, reconciliation, importer, and navigation.

## Non-goals

- Bank input or bank decision editing.
- Buyer substitutions (`GANTI NAMA`).
- Lot transfers (`PINDAH KAVLING`).
- Akad or BAST editing.
- Production cutover.
- Scheduled production imports.
- New salesperson master-data subsystem.

## Anti-goals

- Do not delete or rewrite historical bookings.
- Do not make Sheets writes.
- Do not silently prefer stale Sheets values over Laravel edits.
- Do not trust project, lot, buyer, or booking IDs from the browser without
  server-side authorization and transaction checks.

## Approaches considered

### A — Ownership flag plus immutable audit trail (selected)

Add `projects.management_mode` (`sheets` or `laravel`) and
`bookings.source_owner` (`sheets` or `laravel`). Add append-only
`booking_audits` with user, action, before/after JSON, request ID, and time.
The existing importer remains the Sheets path; Laravel-owned bookings are
preserved and source differences become `import_conflict` issues.

Selected because it is the smallest change compatible with current models,
metrics, reconciliation, and import behavior.

### B — Separate operational overlay tables

Rejected. It duplicates booking identity, inventory claims, and timeline logic.

### C — Database canonical for every project

Rejected. It breaks current Sheets-managed project behavior and removes the
required comparison-only import path.

## Ownership model

### Project mode

`projects.management_mode`:

- `sheets` — ordinary import may update records from Sheets, as today.
- `laravel` — operational records created or edited in Laravel are protected;
  ordinary import compares source values and reports differences.

Default remains `sheets` for backwards compatibility. Both pilot projects are
set to `laravel` only in staging through an explicit Artisan command. Production
remains `sheets`.

### Booking owner

`bookings.source_owner`:

- `sheets` — imported or still Sheets-owned booking.
- `laravel` — created or operationally changed through Laravel.

When project mode is `laravel`, importer behavior is:

1. Existing Sheets-owned booking: normal idempotent update.
2. Existing Laravel-owned booking with identical incoming source values: no
   overwrite; no conflict.
3. Existing Laravel-owned booking with different incoming values: no
   overwrite; append `import_conflict` issue containing safe field-level
   differences and source provenance.
4. New incoming Sheets booking: import normally as `sheets` owner.

Laravel-created PSJB keys use a Laravel namespace and remain distinct from
legacy `PSJB:<id>` keys. A repeat booking after MUNDUR/BATAL always gets a new
booking ID and new operational PSJB record.

Protected fields include booking status, decision stage/date/note, salesperson,
payment method, booking date, lot, buyer, and PSJB/UTJ values. Stage rows not
edited by this workflow remain importer-owned and unchanged.

## Data model changes

### `projects`

- `management_mode` string, default `sheets`, indexed.

### `bookings`

- `source_owner` string, default `sheets`, indexed.

### `booking_audits`

- `id`
- `project_id`
- `booking_id`
- `user_id` nullable for system/import actions
- `action` (`created`, `withdrawn`, `cancelled`, `import_conflict`)
- `before` JSON nullable
- `after` JSON nullable
- `request_id` nullable/indexed
- timestamps

Audit values use an allowlist; no encrypted NIK, phone, password, or full
credential is logged.

Existing `created_at`/`updated_at` remain useful record timestamps. The audit
row is authoritative for actor/action history.

## Create PSJB flow

1. GET create form. Project list is `Project::visibleTo(auth()->user())` and
   restricted to staging-write projects. Lot list contains only lots with no
   valid claim; M22 is therefore absent while competing claims remain.
2. Buyer is selected from existing buyers or entered as a new buyer. Existing
   buyer matching uses source customer ID first, then NIK hash, then normalized
   name. New NIK/phone values use current encrypted casts and NIK hash helpers.
3. POST validates allowlisted fields, date formats, numeric ranges, payment
   method, salesperson length, and buyer selection/new-buyer shape.
4. Transaction locks selected lot with MySQL `lockForUpdate()`.
5. Re-checks project authorization and valid claims after lock.
6. Creates booking with `source_owner=laravel`, `status=ACTIVE`,
   `current_stage=PSJB`, `is_current_holder=true`, and new source key.
7. Creates PSJB record with `source_owner` provenance represented by its
   Laravel source key and the submitted details.
8. Appends `created` audit row with authenticated user and request ID.
9. Commits and redirects to booking detail with success message.

Valid claims are bookings on the same lot with status `ACTIVE`, `COMPLETED`, or
`NEEDS_REVIEW`. Any valid claim blocks new allocation. More than one valid claim
also blocks allocation, even if importer marked one as current holder.

## Withdraw / cancel flow

- POST action requires authenticated user and project access from the booking
  relationship, not a project ID supplied by the form.
- Transaction locks the booking and lot.
- Only `ACTIVE` or `NEEDS_REVIEW` bookings can be withdrawn/cancelled; completed
  and historical outcome rows are rejected.
- Sets status to `MUNDUR` or `BATAL`, `decision_stage=PSJB`,
  `decision_date=today`, `decision_note` from validated optional note, and
  `is_current_holder=false`.
- Existing PSJB and all later stage history remain untouched.
- Recomputes remaining valid claims under the same lot lock. If exactly one
  valid claim remains, it becomes current holder. If multiple remain, all
  current-holder flags are false and the lot stays blocked for review.
- Appends immutable audit row.
- A later booking on the released lot creates a new booking ID; prior booking
  and salesperson remain unchanged.

## Authorization and security

- All GET and POST routes use `auth` + active-user middleware.
- Project and booking resources use scoped access checks on every read/write.
- Project form fields are never trusted for authorization.
- Lot availability is checked again inside the transaction after row lock.
- CSRF remains enabled on all forms.
- Validation uses allowlists and bounded string lengths.
- User-facing conflict errors are generic and actionable; internal SQL details
  are not exposed.
- Audit logs exclude raw NIK/phone and secrets.

## Import and reconciliation behavior

`ProjectImporter` receives ownership-aware upsert behavior. In Laravel-managed
projects it compares protected incoming fields against Laravel-owned records,
keeps the database value, and emits:

- code: `import_conflict`
- severity: `warning`
- source tab/row/key
- context: field names and normalized source/current values, excluding PII

`--dry-run` still rolls back all writes while returning conflict counts/issues.
Applied imports persist the issue in `import_issues` and keep the Laravel value.
The reconciliation page lists these issues with existing resolve workflow.

A regression test will:

1. import fixture booking;
2. edit salesperson/status or PSJB value through the Laravel write path;
3. re-run the original fixture import for a `laravel` project;
4. assert the Laravel value remains;
5. assert `import_conflict` is reported/persisted.

## Staging mode

Add command:

```bash
php artisan marison:project-mode PRJ-MGL-KLG laravel
php artisan marison:project-mode PRJ-JPR-KWS laravel
```

Before switching either project, run a dry-run and resolve/accept current
issues. No production mode changes are made in this phase.

## Verification contract

- `php artisan test` passes, including existing G09 and K20 tests.
- Focused write tests pass on sqlite for behavior and MySQL for row-lock smoke.
- MySQL staging smoke proves two concurrent submissions yield at most one
  active booking.
- Browser verifies authorized create, withdraw/cancel, repeat booking, clear
  conflict response, and unauthorized cross-project POST.
- Production Sheets remain read-only and scheduled imports remain pending.
