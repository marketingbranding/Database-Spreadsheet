# Akad Staging Design

## Intent

Add an operational Akad flow for the two Laravel-managed pilot projects:
`PRJ-JPR-KWS` and `PRJ-MGL-KLG`. A branch admin should be able to select a
signed PPJB from the same internal booking, record the actual Akad document,
and move the booking to the Akad stage without losing source provenance.

## Goals

- Provide project-scoped Akad queue and form.
- Support both KPR and CASH bookings after a qualifying signed PPJB.
- Enforce booking, project, and PPJB identity at request time and inside the
  transaction.
- Persist Akad, booking transition, and creation audit atomically.
- Make sequential idempotent retries safe.
- Preserve the permanent PPJB-to-Akad link in the timeline.
- Protect Laravel-owned Akad data from stale Sheets imports and report conflicts.
- Keep Akad period metrics based on actual `akad_date`, separate from BAST.

## Non-goals

- BAST input or workflow.
- Scheduled imports.
- Production cutover.
- Changing historical inventory markers.
- Redesigning dashboard period semantics or existing PSJB/PPJB behavior.

## Confirmed source and business rules

The importer reads these Akad source fields:

- `No. Akad`
- `Tanggal Akad`
- `Kualitas Akad`
- delay/detail notes represented by the existing `notes` field
- source PPJB references and historical action flags

For operational Akad, both `KPR` and `CASH` use the same eligibility rule:
the booking is `ACTIVE`, at stage `PPJB`, and has a signed PPJB record owned by
the same booking and project. The selected PPJB is the permanent source link;
legacy transaction IDs are not sufficient for authorization. No additional
KPR bank or CASH Pemberkasan check is re-run after PPJB has been signed.

On save, the booking advances to `AKAD` and remains `ACTIVE`; BAST is the later
independent stage that may make a booking `COMPLETED` through import behavior.
The source `akad_date` is the actual event date and is the only date used for
Akad period totals.

## Approaches

### Selected: extend `PsjbWorkflow`

Add Akad eligibility, payload validation, transactional recording, and
authorization to the existing `PsjbWorkflow`, with a controller and views
mirroring TTD PPJB. This reuses the established staging write guard,
project/lot locking order, audit snapshot, and idempotency conventions.

### Rejected: separate `AkadWorkflow`

This would create a cleaner nominal boundary but duplicate the existing
authorization, locking, and audit patterns and make cross-stage consistency
harder to verify.

## Data and ownership

Extend `akad_records` with:

- `ppjb_id` as a nullable foreign key to `ppjb_records` with `nullOnDelete`
- `source_owner`
- unique nullable `idempotency_key`

Operational records use `source_owner=laravel`, a Laravel source key, and an
input fingerprint in `source_hash`. Imported records remain Sheets-owned and
retain their existing source keys. A Laravel-owned row is matched by booking
and protected from stale Sheets updates; differing source fields produce
`import_conflict`.

## Routes and UI

- `GET /projects/{project}/akad`
- `GET /projects/{project}/akad/{booking}/create`
- `POST /projects/{project}/akad/{booking}`

The queue is visible only for authorized branch admins on pilot projects in
Laravel management mode. The form records Akad number, actual Akad date,
quality, and notes. Booking timeline displays Akad and its PPJB source.

## Verification

Tests will cover KPR and CASH paths, cross-project and cross-booking IDs,
G09 April/September cycle isolation, idempotent retry, conflicting key reuse,
audit count, source-link persistence, stale Sheets conflicts, dashboard
period counting, imported historical Akad, K20, and existing PSJB-to-PPJB
regressions. Browser checks will cover queue/form/timeline at desktop and
mobile widths.

Concurrent idempotency catch-and-replay and a concurrent HTTP test remain
production follow-ups, as with Proses Bank and PPJB.
