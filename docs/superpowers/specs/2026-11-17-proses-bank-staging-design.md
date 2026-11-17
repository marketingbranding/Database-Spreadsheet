# Proses Bank Staging Workflow Design

## Intent

Add staging-only Proses Bank processing for pilot projects `PRJ-JPR-KWS` and `PRJ-MGL-KLG`. Branch admins move eligible KPR bookings from Pemberkasan into bank submissions, record bank results, preserve submission and file-revision history, and protect Laravel-owned values during Sheets import.

## Goals

- Project-scoped Proses Bank queue and create/result forms.
- Link each submission to exact `booking_id` and `pemberkasan_id`.
- Preserve prior submissions when a booking is resubmitted or a later Pemberkasan revision becomes current.
- Keep bank result separate from booking status and decision.
- Record Laravel changes and audits atomically.
- Reject cross-project, stale-ID, unauthorized, closed, non-pilot, non-staging, and CASH writes.
- Preserve Laravel-owned bank values during stale Sheets imports and emit `import_conflict`.
- Keep existing timeline, importer, G09, K20, PSJB, BI, and Pemberkasan behavior intact.

## Non-goals

- PPJB, Akad, BAST, buyer substitutions, lot transfers, production cutover, scheduled imports.
- Automatic booking Withdraw/Cancel from bank `REJECT`.
- Treating bank `REJECT` as booking outcome.
- CASH bank submissions.

## Verified source rules

- Existing Proses Bank results: `APPROVE`, `REJECT`, `REVISI`.
- Existing Pemberkasan statuses: `Analisa`, `Input`, `OTS`, `LENGKAP`, and `Pemberkasan CASH`.
- KPR eligibility uses current exact Pemberkasan record with status `Analisa`, `Input`, `OTS`, or `LENGKAP`.
- `Pemberkasan CASH` and every non-`KPR` booking stay outside queue.
- Existing source rows identify bank submissions by `ID Putusan`; booking identity remains exact PSJB/booking instance, not repeated legacy transaction ID.
- G09 April and September cycles must remain separate records and histories.

## Architecture decision

Extend existing `bank_submissions` table and model.

Add:

- `pemberkasan_id` foreign key to exact file revision.
- `source_owner` with `sheets` and `laravel` constants.
- `idempotency_key` unique for Laravel-created submissions, nullable for legacy Sheets rows.
- Existing source key remains importer identity.

Use `Booking::bankSubmissions(): HasMany` as submission history ordered by submitted date and ID. Add `PemberkasanRecord::bankSubmissions(): HasMany` for exact revision traceability.

No separate operation table. Existing importer, reconciliation, audit, and timeline remain canonical.

## Workflow contract

### Queue

`eligibleBankBookings(Project $project)` returns bookings satisfying:

- pilot project and Laravel-managed project guard at controller/workflow boundary;
- status `ACTIVE` or `NEEDS_REVIEW`;
- payment method exactly `KPR`;
- current stage `PEMBERKASAN` or `PROSES_BANK`;
- current Pemberkasan exists, belongs to booking/project, and has status in `Analisa`, `Input`, `OTS`, `LENGKAP`;
- no CASH or `Pemberkasan CASH` path;
- queue exposes exact current Pemberkasan ID.

A previous `APPROVE`, `REJECT`, or `REVISI` does not mutate booking decision. Resubmission remains allowed against a new or selected current file revision according to exact Pemberkasan linkage.

### Create submission

`recordBankSubmission(User $actor, Booking $booking, PemberkasanRecord $pemberkasan, array $input): BankSubmission`:

- validates actor, staging write flag, pilot code, project ownership, booking ownership, Pemberkasan ownership, KPR method, actionable status, stage, and eligible Pemberkasan status;
- locks project, booking, lot, Pemberkasan, then duplicate identity in stable order;
- requires non-empty bank and submitted date;
- creates Laravel-owned submission with exact booking/file revision link;
- retries with same idempotency key return existing submission and create no duplicate;
- different idempotency key creates a new submission history row;
- advances booking `current_stage` to `PROSES_BANK` and marks booking Laravel-owned;
- writes `bank_submission_created` audit atomically.

### Record result

`recordBankResult(User $actor, BankSubmission $submission, array $input): BankSubmission`:

- rechecks project, booking, Pemberkasan, actor, staging, and submission ownership;
- accepts exact supported results `APPROVE`, `REJECT`, `REVISI`;
- updates only targeted submission fields and creates `bank_result_recorded` audit;
- does not update booking status or decision fields;
- rejects modified submission/booking/Pemberkasan IDs;
- result retry with same request identity is idempotent; conflicting retry is rejected.

Explicit existing Withdraw/Cancel remains the only booking decision path. It preserves all bank submissions, sets decision stage to current booking stage, and applies existing lot-release logic.

## Import ownership

For Laravel-managed pilot projects:

- match bank rows by source key first;
- fallback to exact booking + Pemberkasan/file business key when source key differs;
- Laravel-owned bank protected fields remain unchanged;
- differing incoming values emit `import_conflict`;
- Sheets rows remain Sheets-owned and retain ordinary upsert behavior;
- imported rows receive nullable `pemberkasan_id` when exact source file resolves;
- protected bank rows do not recompute Laravel booking stage, holder, or decisions;
- dry-run remains rollback-only.

Protected bank fields: `booking_id`, `project_id`, `pemberkasan_id`, `file_number`, `decision_number`, `submission_sequence`, bank/unit, submitted/decision dates, result, approved amount, tenor, flags, notes, ownership, and idempotency key.

## UI

Add project-scoped routes:

- `GET /projects/{project}/proses-bank` → queue.
- `GET /projects/{project}/proses-bank/{booking}/create` → submission form.
- `POST /projects/{project}/proses-bank/{booking}` → create submission.
- `GET /projects/{project}/proses-bank/{submission}/result` → result form.
- `POST /projects/{project}/proses-bank/{submission}/result` → record result.

Use existing Blade layout and Indonesian labels. Display exact file revision, bank history, result values, and validation/authorization errors. Add project navigation only for Laravel-managed pilot projects.

## Audit and timeline

Timeline renders every bank submission with:

- file number and revision;
- bank/unit;
- submitted date;
- result and decision date;
- approved amount/tenor where present;
- notes;
- current/previous Pemberkasan linkage where useful.

Audits contain safe booking/submission snapshots and no raw NIK, phone, passwords, or secrets.

## Verification

- Focused workflow tests for eligibility, exact links, retries, result separation, authorization, G09 cycle isolation, and atomic audits.
- Import ownership/conflict and regression tests, including G09/K20.
- Full `php artisan test`.
- `php artisan route:list --path=proses-bank`.
- Browser staging verification at desktop `1440x900` and mobile `390x844`: queue, create, result, timeline, no overflow, no console errors.
- Record Pint baseline failures separately; no unrelated formatter changes.

## Open business rule

Current proposal allows resubmission against any current eligible Pemberkasan revision and retains all previous submissions. If operations require only the latest revision or only one active submission per bank, add that policy before production cutover.
