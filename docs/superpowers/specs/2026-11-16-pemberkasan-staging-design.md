# Pemberkasan Staging Workflow Design

## Intent

Add staging-only Pemberkasan workflow for `PRJ-JPR-KWS` and `PRJ-MGL-KLG`. Branch admins process eligible bookings, preserve every file record, expose stage history, and protect Laravel-owned values from stale Sheets imports.

## Goals

- Project-scoped Pemberkasan queue and form.
- Reuse existing `PsjbWorkflow`, project authorization, staging write guard, booking actions, timeline, and immutable audits.
- Preserve multiple Pemberkasan records per booking.
- Keep exact source fields: `file_number`, `received_date`, `bank`, `bank_unit`, `status`, flags, and `notes`.
- Reject duplicate same-booking/same-file submissions without creating another row.
- Preserve Laravel-owned records during Sheets import and emit `import_conflict` on differences.
- Support KPR PSJB → BI `LANJUT` → Pemberkasan browser flow.
- Support CASH Pemberkasan path based on existing source records.

## Eligibility

- KPR: booking must be actionable (`ACTIVE` or `NEEDS_REVIEW`) and have a Laravel-owned BI record with exact decision `LANJUT`.
- CASH: every actionable booking may enter directly after PSJB without BI.
- KPR without BI decision: excluded.
- KPR with `TIDAK LANJUT`: excluded.
- Closed bookings: excluded.
- Existing source records do not make an ineligible booking writable.

## Record model

Keep `pemberkasan_records` as canonical table. Change booking relation to `hasMany` and add Laravel ownership plus revision/current metadata.

- Imported records default to `source_owner=sheets`.
- Laravel submissions use `source_owner=laravel`.
- Each Laravel submission is a new row, preserving prior records.
- Duplicate same booking + same `file_number` returns `409`; no unintended duplicate.
- Laravel writes require non-empty `file_number`; source imports may use their existing row fallback.
- Existing source IDs are globally unique in current data; matching uses source key first, then booking + file number fallback.
- Timeline shows all Pemberkasan records in `received_date ASC, id ASC` order, with null dates last.

## Write transaction

`PemberkasanWorkflow` remains inside `PsjbWorkflow` unless a separate service is required by existing structure. Lock project, booking, lot, and duplicate lookup in stable order. Validate actor, pilot project, management mode, staging flag, booking method/status, and BI eligibility before create. KPR requires latest BI record to be Laravel-owned with `LANJUT`; CASH requires no BI record. Set booking `current_stage=PEMBERKASAN`, `source_owner=laravel`, and record create audit with Pemberkasan snapshot.

Withdraw/Cancel reuse existing booking decision methods. Decision stage records `PEMBERKASAN`; current Pemberkasan history remains attached. Existing lot-release rules stay unchanged.

## Import protection

For Laravel-managed projects, existing Laravel-owned Pemberkasan rows preserve all Laravel fields and ownership. Differing Sheets values emit `import_conflict`. Sheets-owned projects retain current importer behavior. Dry-run remains rollback-only.

## HTTP/UI

- `GET /projects/{project}/pemberkasan`
- `GET /projects/{project}/pemberkasan/{booking}`
- `POST /projects/{project}/pemberkasan/{booking}`

All routes enforce project access, Laravel management mode, booking project match, eligibility, staging writes, and branch-admin writes. UI keeps Indonesian terms and existing Blade design. Queue states include empty and blocked-by-eligibility behavior through omission and direct-request `403/422`.

## Non-goals

Proses Bank input, buyer substitutions, lot transfers, production cutover, scheduled imports, and unrelated stage abstractions remain out of scope.

## Approaches

1. Add ownership/current metadata to existing `pemberkasan_records`, use `hasMany`, preserve importer compatibility. Chosen: smallest compatible change and matches observed multiple records.
2. Add separate submissions table. Rejected: duplicates existing importer model and expands scope.
3. Overwrite one row. Rejected: loses history and violates acceptance.

## Verification

- Focused Pemberkasan workflow/controller/importer tests.
- Existing PSJB, BI, G09, K20, and importer regression tests.
- Full `php artisan test`.
- Authenticated browser flow at desktop and `390x844` mobile sizes.
- Verify KPR LANJUT, KPR no decision, KPR TIDAK LANJUT, CASH, cross-project tampering, duplicate submission, withdrawal/rebook history, stale import conflict, and clean console.
