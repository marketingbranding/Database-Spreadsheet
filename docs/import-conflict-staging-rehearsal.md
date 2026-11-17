# Import Conflict Pilot — staging rehearsal

## Scope and decision

Read-only source review and staging-only module rehearsal for `PRJ-JPR-KWS` and
`PRJ-MGL-KLG`. Source Sheets and operational booking/document records were not
edited. HQ approved staging access for the two project-scoped branch admins on
17 Nov 2026; the staging gate is enabled in the local staging `.env` for
`jepara@marison.test` and `magelang@marison.test`. Real conflicts remain open
for separate HQ decisions.

Pilot readiness: **HOLD** — projection is working, but HQ decisions are needed on
the evidence exceptions listed below.

## Setup

The staging database needed the new migration before conflict-only projection
could persist. The first `--apply` attempt failed with `1146 Table
'marison_multibranch.import_conflicts' doesn't exist`; no records were changed.
After migration, projection succeeded.

```bash
APP_ENV=staging php artisan migrate --force

APP_ENV=staging php artisan marison:conflicts:project PRJ-JPR-KWS
APP_ENV=staging php artisan marison:conflicts:project PRJ-MGL-KLG
APP_ENV=staging php artisan marison:conflicts:project PRJ-JPR-KWS --apply
APP_ENV=staging php artisan marison:conflicts:project PRJ-MGL-KLG --apply
```

Preview and apply counts matched:

| Project | Previewed | Projected | Cases |
|---|---:|---:|---|
| `PRJ-JPR-KWS` | 11 | 11 | 6 bank reject, 2 competing claims, 2 stock, 1 orphan BI |
| `PRJ-MGL-KLG` | 7 | 7 | 1 bank reject, 6 stock |

Operational record counts and max IDs before and after projection were unchanged:

| Table | Count | Max ID |
|---|---:|---:|
| `bookings` | 450 | 1804 |
| `lots` | 457 | 1832 |
| `ppjb_records` | 284 | 1136 |
| `akad_records` | 282 | 1128 |
| `bast_records` | 253 | 1012 |
| `bi_checks` | 383 | 1532 |
| `booking_audits` | 0 | 0 |
| `booking_transitions` | 0 | 0 |

## Exact evidence retained

Representative persisted conflicts:

- Kuwasen M22: `COMPETING_CLAIMS`, PSJB row 214, source key
  `PSJB:PSJB-TRX-JPR-3275040601830014-M22`, lot ID 1615, booking IDs
  **1567** (`CURRENT_HOLDER`, `NEEDS_REVIEW`) and **1601** (`CLAIM`, `ACTIVE`).
- Kuwasen orphan BI: `BI Checking` row 201, source key `BI-`, no guessed booking
  link, inventory unchanged.
- Kuwasen L02: `STOCK_DISAGREEMENT`, `hq_report_export`, lot ID 1583, booking ID
  1557; HQ position `PEMBERKASAN`, Laravel position `AVAILABLE`.
- Kuwasen M20: `STOCK_DISAGREEMENT`, `hq_report_export`, lot ID 1613, booking ID
  1565; HQ position `PEMBERKASAN`, Laravel position `AVAILABLE`.
- Kalinegoro transfer links: D07 (1633, 1634, 1635), G03 (1639, 1660, 1662),
  G05 (1616, 1628, 1629, 1652, 1654), G16 (1626, 1658, 1659, 1661).
- Kalinegoro bank reject: Proses Bank row 8, source key
  `PSJB:PSJB-TRX-MGL-3325116004060001-F15`, booking ID 1612, but the resolved
  source row/lot is **D14 / lot ID 1701**. HQ must decide whether the source key
  or the source row is authoritative.
- Additional stock disagreements detected from the HQ export: Kalinegoro H07
  (booking 1804) and H11 (booking 1608). These are not part of the original
  14-issue register and need separate HQ review.

## Fixture rehearsal

`tests/Feature/ImportConflictStagingRehearsalTest.php` passed with 23 assertions.
It verified:

- M22-style competing claims, orphan BI, bank rejects, and L02/M20-style stock
  disagreement project from fixture data.
- An affected lot is absent from `availableLots()` and rejected by the locked
  create path, while an unaffected lot remains writable in a rolled-back
  transaction.
- A branch `CONFIRM_LARAVEL` proposal plus HQ approval resolved a conflict
  without changing booking counts.
- A fixture-only `CORRECT_LARAVEL` selected the exact current cycle and withdrew
  the competing claim.
- Unchanged reimport preserved the reviewed fingerprint and resolved state.
- A materially changed BI decision created one successor conflict linked by
  `supersedes_conflict_id`.
- PPJB, Akad, and BAST IDs and links stayed unchanged during signed-document
  escalation.

## Browser rehearsal

With the UI gate temporarily enabled in staging, Playwright verified:

- Branch role: project register, M22 detail, exact booking IDs, source tab/row,
  fingerprint, labeled Sheets/Laravel values, inventory impact, and proposal
  form. Zero console errors.
- HQ role: pending proposal, evidence, submitter, Setujui/Tolak controls, and
  approval writing `RESOLVED` with audit history. Zero console errors.
- Desktop `1440×900`: no document overflow (`scrollWidth 1440 = clientWidth`).
- Mobile `390×844`: initial overflow `653px`; fixed by wrapping JSON evidence
  and collapsing the timeline/grid at the mobile breakpoint. Rechecked at
  `390px` with `scrollWidth 390 = clientWidth` and no console errors.

The temporary rehearsal users, proposal, events, and HQ approval were removed
after verification. Conflict 10 was restored to `OPEN`; the staging gate was
disabled again and `.env` restored to `APP_ENV=local`.

## Known gaps and HQ decisions

1. Decide M22: confirm booking 1567 as holder or approve a linked correction.
2. Decide L02/M20: confirm Laravel authoritative or approve a claim correction.
3. Decide the four Kalinegoro transfer-link mismatches and whether same-lot
   source/destination is valid historical practice.
4. Resolve the F15 source key / D14 source row mismatch.
5. Review H07/H11 stock disagreements newly detected from the HQ export.
6. Bank rejects remain routed to the existing booking decision workflow.
7. Signed PPJB/Akad/BAST changes remain escalation-only; no automatic
   two-system reconciliation is implemented.

## Staging admin pilot readiness

Designated pilot roles are the existing active users
`jepara@marison.test` (branch admin, PRJ-JPR-KWS only) and
`magelang@marison.test` (branch admin, PRJ-MGL-KLG only). HQ is
`hq@marison.test`. No new roles were added.

Blocked lots are exactly the lots referenced by unresolved `COMPETING_CLAIMS`
or `STOCK_DISAGREEMENT` conflicts:

| Project | Lot | Reason |
|---|---|---|
| PRJ-JPR-KWS | G09 | Legacy cycle conflict: booking 1480 completed/BAST and booking 1598 withdrawn; one current holder, lot SOLD. |
| PRJ-JPR-KWS | M22 | Two open claims: booking 1567 `NEEDS_REVIEW` (current) and 1601 `ACTIVE`; lot BOOKED. |
| PRJ-JPR-KWS | L02 | HQ says PEMBERKASAN, Laravel derives AVAILABLE; only replaced booking 1557. |
| PRJ-JPR-KWS | M20 | HQ says PEMBERKASAN, Laravel derives AVAILABLE; only replaced booking 1565. |
| PRJ-MGL-KLG | D07 | Historical PINDAH KAVLING/GANTI NAMA chain ends with no valid current claim; lot AVAILABLE. |
| PRJ-MGL-KLG | G03 | Historical PINDAH KAVLING chain ends with no valid current claim; lot AVAILABLE. |
| PRJ-MGL-KLG | G05 | Historical link mismatch, but booking 1616 is `ACTIVE` current holder; lot BOOKED. |
| PRJ-MGL-KLG | G16 | Historical link mismatch, but booking 1626 is `ACTIVE` current holder; lot BOOKED. |
| PRJ-MGL-KLG | H07 | HQ BELUM_TERJUAL, Laravel BOOKED; booking 1804 is active. |
| PRJ-MGL-KLG | H11 | HQ BELUM_TERJUAL, Laravel BOOKED; booking 1608 is active. |

Transfer-link review:

- **D07**: booking 1634 is `MOVED` with predecessor 1633 (`GANTI_NAMA`); no
  valid claim remains. The mismatch affects current availability and keeps D07
  blocked until HQ decides whether the lot is genuinely available.
- **G03**: booking 1639 is `MOVED` with predecessor 1660; bookings 1660 and
  1662 are `MUNDUR`. No valid claim remains. The mismatch affects current
  availability and keeps G03 blocked.
- **G05**: mismatch is on moved booking 1652; booking 1616 is the sole active
  holder. Current allocation is already represented and the lot is BOOKED; the
  mismatch is historical for allocation purposes.
- **G16**: mismatch is on moved booking 1658; booking 1626 is the sole active
  holder. Current allocation is already represented and the lot is BOOKED; the
  mismatch is historical for allocation purposes.

Role rehearsal with the actual pilot users (rolled back):

- JPR admin can access only PRJ-JPR-KWS; MGL admin only PRJ-MGL-KLG; HQ both.
- Each branch admin created a booking on an unaffected lot (B01 / A01).
- Creating into a blocked lot (L02 / D07) was rejected.
- Transferring into a blocked lot (L02 / D07) was rejected after the
  destination guard fix; this rehearsal exposed and corrected the gap.
- Branch admins submitted `CONFIRM_LARAVEL` proposals for their assigned
  project; HQ approved one and rejected the other. A cross-project proposal was
  denied.
- Bank conflict 16 (F15 source key, D14 source row, booking 1612) accepted only
  a `ROUTE_BOOKING_DECISION` proposal. HQ approval left the booking ACTIVE and
  the conflict not resolved; no MUNDUR/BATAL decision can be applied through
  the conflict module before the key, row, and exact booking are reconciled.

All rehearsal writes were rolled back. Real conflicts remained 18 OPEN, with 0
proposals and 0 audits; booking count and max ID were unchanged.

## Rollback

- Disabling `MARISON_IMPORT_CONFLICTS_ENABLED` hides the UI only. It must not
  delete or reset real conflict, proposal, event, or `booking_audit` history.
- Leave the migration in place. Applied booking corrections are reversed only
  through a new approved domain action, never by deleting audit records.
- Re-projection is fingerprint-aware and reuses unchanged conflicts. Do not
  clear pilot conflict/proposal/event rows as a routine rollback step.
- Cleanup is limited to explicitly identified rehearsal fixtures: temporary
  users, proposals/events created for `pilot-*` or `rehearsal-*` idempotency
  keys, and conflicts created solely from fixture data. List the exact IDs and
  keys before deletion; real source-derived conflicts stay open for HQ.
- Production cutover and scheduled imports remain out of scope.
