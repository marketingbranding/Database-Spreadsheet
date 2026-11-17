# Booking change staging implementation plan

## Goal

Add project-scoped, staging-only Ganti Nama and Pindah Kavling workflows with atomic inventory changes, explicit transition records, importer ownership protection, timelines, and responsive forms.

## Ordered tasks

### Phase 1 — contract and transactional workflow

- [x] Add transition schema/model with unique nullable idempotency key and explicit source/successor references.
- [x] Add failing workflow tests for same-day name substitution, successful transfer, occupied destination, signed-document guard, cross-project access, idempotent replay/conflict, UTJ carryover, and inventory claims.
- [x] Implement the transactional service using project/source/lot locks and fresh PSJB successor records.

### Checkpoint

- [x] Focused workflow tests pass.
- [x] Existing G09, K20, Akad, BAST, and importer regressions pass.

### Phase 2 — HTTP and importer boundary

- [x] Add validated project-scoped routes/controllers/forms for both actions.
- [x] Preserve Laravel-owned transition/source/successor records during stale Sheets imports and report conflicts.
- [x] Add timeline transition entries and safe audit snapshots.

### Phase 3 — UI and verification

- [x] Add source preview, buyer/destination selection, explicit price confirmation, reason/effective-date/idempotency fields, and error states.
- [x] Verify desktop and mobile forms and timeline with browser tooling; check console and overflow.
- [x] Run full suite, targeted Pint, and staging workflow evidence.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Destination becomes occupied between form load and submit | Lock destination lot and re-check valid claims inside the transaction |
| Signed documents exist while stage is stale | Check PPJB/Akad/BAST relations directly |
| Re-import overwrites Laravel changes | Protect Laravel-owned source and transition-linked bookings and report conflicts |
| UTJ counted twice | Keep one original UTJ reference and exclude carried-forward successor rows from UTJ aggregation |
