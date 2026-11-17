# BI Checking and Pemberkasan staging workflow

- [x] Pemberkasan history-aware schema and ownership fields
- [x] Pemberkasan KPR/CASH eligibility and transactional revisions
- [x] Pemberkasan importer ownership protection and conflict reporting
- [x] Pemberkasan authorized routes, request validation, and Blade UI
- [x] Pemberkasan timeline history and staging documentation

## Proses Bank staging workflow

- [x] Proses Bank schema/model contract (`pemberkasan_id`, `source_owner`, `idempotency_key`)
- [x] Proses Bank transactional queue, submission, and result workflow
- [x] Proses Bank authorized HTTP routes and Blade UI
- [x] Proses Bank importer ownership protection and conflict reporting
- [x] Proses Bank timeline traceability (`bankSubmissions.pemberkasan` eager load, exact submission/file/revision display)
- [x] Proses Bank audit snapshots verified free of NIK/phone/password/remember-token keys
- [x] Proses Bank documentation appended to `docs/psjb-staging-workflow.md`
- [ ] Proses Bank browser verification at desktop `1440x900` and mobile `390x844` (Task 6)
- [ ] Proses Bank Pint reformat of pre-existing baseline violations (tracked separately; not reformatted this task)

- [x] Task 1: Prove PSJB MySQL HTTP concurrency
- [x] Task 2: Add Laravel ownership to BI records
- [x] Task 3: Implement BI queue and transactional decisions
- [x] Task 4: Add authorized BI HTTP surface and Blade UI
- [x] Task 5: Protect Laravel BI values during Sheets import
- [x] Task 6: Browser and final verification
- [x] Checkpoint: MySQL concurrency evidence
- [x] Checkpoint: BI workflow and importer protection
- [x] Final checkpoint: full suite, browser, G09/K20, handoff

## Evidence

- Full suite: `46 passed`, `177 assertions`.
- HTTP race: lot `B02`, `lot_id=21`; outcomes `302 /bookings/451` and `302 /projects/PRJ-JPR-KWS/psjb/create`.
- Race counts: one booking, one active booking, one creation audit, one active allocation.
- Browser: desktop queue and mobile queue/form verified; current BI pages had zero console errors.
