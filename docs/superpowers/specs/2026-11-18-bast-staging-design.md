# BAST Staging Design

## Intent

Add an operational Serah Terima Unit (BAST) flow for the Laravel-managed pilot
projects `PRJ-JPR-KWS` and `PRJ-MGL-KLG`. A branch admin should be able to
select the verified Akad for the same internal booking, record the actual
handover, and move the booking out of the operational queue without losing
source provenance.

## Goals

- Provide project-scoped BAST queue and form.
- Support both KPR and CASH bookings after a qualifying Akad.
- Enforce booking, project, and Akad identity at request time and inside the
  transaction.
- Persist BAST, booking transition, and creation audit atomically.
- Make sequential idempotent retries safe even after the booking is completed.
- Preserve the permanent Akad-to-BAST link in the timeline.
- Protect Laravel-owned BAST data from stale Sheets imports and report conflicts.
- Count BAST periods by actual `bast_date`, independently of Akad date.
- Keep historical inventory markers from creating handover events.

## Non-goals

- Scheduled imports.
- Production cutover.
- Concurrent retry catch-and-replay or concurrent HTTP testing.
- Changing historical inventory markers or existing importer stage semantics.
- Redesigning existing PSJB, PPJB, or Akad behavior.

## Confirmed source and business rules

The existing importer reads these BAST source fields:

- `No. BAST`
- `Tanggal BAST`
- `ID Transaksi`
- `ID Akad Sebelumnya`
- `Pilih Akad`
- notes from `Keterangan`

For operational BAST, both `KPR` and `CASH` use the same eligibility rule:
the booking is `ACTIVE`, at stage `AKAD`, has no BAST record, and has an active
Akad record linked to the same booking and project. The selected Akad is the
permanent source link; legacy transaction IDs are not sufficient for
authorization. Historical inventory checkboxes alone never qualify.

Operational fields are required BAST number and actual handover date; notes are
optional. On save, the booking advances to stage `BAST`, becomes
`COMPLETED`, and remains the current holder (`is_current_holder=true`). It
leaves the BAST queue while the lot remains assigned and unavailable.

## Approaches

### Selected: extend `PsjbWorkflow`

Add BAST eligibility, payload validation, transactional recording, and
authorization to the existing `PsjbWorkflow`, with a controller and views
mirroring TTD PPJB and Akad. This reuses the established staging write guard,
project/lot locking order, audit snapshot, and idempotency conventions.

### Rejected: separate `BastWorkflow`

This would duplicate authorization, locking, audit, and idempotency behavior
already shared by the operational workflow and make stage consistency harder to
verify.

### Rejected: controller-only validation

This would leave direct service calls and retries without the same
same-booking/project guarantees and would not provide an atomic domain boundary.

## Data and ownership

Extend `bast_records` with:

- `akad_id` as a nullable foreign key to `akad_records` with restrictive delete
  behavior
- `source_owner`
- unique nullable `idempotency_key`

Operational records use `source_owner=laravel`, a Laravel source key, and an
input fingerprint in `source_hash`. Imported records remain Sheets-owned and
retain their existing source keys. A Laravel-owned row is matched by booking
and protected from stale Sheets updates; differing source fields produce
`import_conflict`.

## Routes and UI

- `GET /projects/{project}/bast`
- `GET /projects/{project}/bast/{booking}/create`
- `POST /projects/{project}/bast/{booking}`

The queue is visible only for authorized branch admins on pilot projects in
Laravel management mode. It shows buyer, lot, payment method, and actual Akad
date, with a `Catat BAST` action. The form shows booking identity and linked
Akad, distinguishes actual handover date from the Akad date, retains old input,
and shows field-level validation. Successful writes redirect to the booking
timeline with status feedback.

## Metrics and verification

Dashboard BAST counts continue to use `BastRecord.bast_date`, so a 2026 BAST
counts in 2026 even when its Akad occurred in 2025. A completed operational
BAST is counted once and removes the booking from the queue while retaining the
lot holder.

Tests cover KPR and CASH paths, cross-project and cross-booking Akad IDs,
sequential retry and conflicting key reuse, atomic audit/transition behavior,
stale Sheets conflicts, G09 April/September cycle isolation, K20 regression,
historical inventory-marker isolation, period metrics, and existing workflow
regressions. Browser checks cover queue, form, timeline, and desktop/mobile
layouts.
