# Staging cutover rehearsal

## Scope

This rehearsal covers only `PRJ-JPR-KWS` and `PRJ-MGL-KLG` in staging. It does
not switch production, enable scheduled imports, or run a production cutover.

## Merge and verification

- Fast-forward merge: `68f0d24..c17f325`
- Merged branch: local `main`
- Full suite: `162 passed`, `2,143 assertions`
- Targeted booking/import regressions: `14 passed`, `81 assertions`
- Targeted Pint for new booking-change files: passed
- Existing `PsjbWorkflow.php` Pint findings remain baseline-only.
- Vite build is blocked: the repository has no `package-lock.json` or
  `npm-shrinkwrap.json`, so `npm ci` exits with npm `EUSAGE`; `node_modules` and
  `node_modules/.bin/vite` are absent. `npm run build` therefore cannot run
  reproducibly from the checked-out tree.

## Rehearsal commands

```bash
APP_ENV=staging php artisan marison:import --project=PRJ-JPR-KWS --dry-run
APP_ENV=staging php artisan marison:import --project=PRJ-MGL-KLG --dry-run

APP_ENV=staging php artisan marison:project-mode PRJ-JPR-KWS laravel --acknowledge
APP_ENV=staging php artisan marison:project-mode PRJ-MGL-KLG laravel --acknowledge

APP_ENV=staging php artisan marison:import --project=PRJ-JPR-KWS --dry-run
APP_ENV=staging php artisan marison:import --project=PRJ-MGL-KLG --dry-run
```

Both projects switched to `laravel`. Dry-runs remained rollback-only. A staging
apply was then run for each project to verify post-switch importer behavior.

## Source-sheet reconciliation

| Project | Total lots | HQ available | HQ BAST | PSJB rows | Akad rows | BAST rows | Issues |
|---|---:|---:|---:|---:|---:|---:|---|
| `PRJ-JPR-KWS` | 260 | 43 | 183 | 251 | 186 | 183 | 1 error, 7 warnings, 1 info |
| `PRJ-MGL-KLG` | 197 | 65 | 70 | 199 | 96 | 70 | 5 warnings |

The applied staging database reported:

| Project | Available | Active current holders | Akad | BAST | Unresolved |
|---|---:|---:|---:|---:|---:|
| `PRJ-JPR-KWS` | 45 | 30 | 186 | 183 | 9 |
| `PRJ-MGL-KLG` | 65 | 62 | 96 | 70 | 5 |

The Kuwasen difference is explainable by current-holder reconciliation:
31 `ACTIVE` rows exist, but one is not the current holder because the source
contains a multiple-active-booking issue; two additional bookings are
`NEEDS_REVIEW`. The unresolved issue codes are:

- Kuwasen: `unmapped_psjb_status` (4), `bank_reject_without_booking_decision`
  (2), `multiple_psjb_cycles` (1), `orphan_bi` (1), and
  `multiple_active_bookings` (1).
- Kalinegoro: `transfer_link_mismatch` (4) and
  `bank_reject_without_booking_decision` (1).

No unresolved `import_conflict` existed after the apply. The rehearsal outcome
is **HOLD** until the Kuwasen error and ownership/reconciliation warnings are
reviewed.

## Booking-change smoke evidence

A rolled-back staging transaction created a source booking, a same-lot Ganti
Nama successor, and a Pindah Kavling successor:

- Source lot ended `AVAILABLE`.
- Destination lot ended `BOOKED`.
- Source status became `REPLACED`, then the name successor became `MOVED`.
- The final successor remained at `PSJB`.
- PPJB, Akad, and BAST remained null on the chain.
- The UTJ root set contained one original PSJB ID, so the chain represented one
  payment rather than three.

The signed-document and stale-import regressions passed. A Laravel-owned BAST
changed against the incoming Sheets value was preserved and reported as
`import_conflict`.

## Rollback procedure

1. Freeze operational writes and scheduled/import jobs.
2. Export the transition table, booking IDs, audit rows, and affected source
   records created after the switch.
3. Run a dry-run in both projects and review conflicts before changing mode.
4. If Laravel writes must stop, run:

   ```bash
   APP_ENV=staging php artisan marison:project-mode PRJ-JPR-KWS sheets
   APP_ENV=staging php artisan marison:project-mode PRJ-MGL-KLG sheets
   ```

5. Do **not** delete successor bookings, transitions, audits, or Laravel-owned
   stage records. They are the evidence needed to reconcile records created
   after the switch and are not represented by old Sheets source keys.
6. Re-import only after the ownership decision is documented. Review every
   `import_conflict`, then reconcile the preserved Laravel records manually.
7. A code rollback must revert the merged commit only after the database
   records are exported and the migration/data compatibility is reviewed; do
   not run the transition migration down against live staging data.

## Post-signing Sheets-managed case

There is no verified Laravel write path for a buyer substitution or lot transfer
after PPJB, Akad, or BAST. The staged regression demonstrates the safe behavior:
the Laravel-owned signed record is not overwritten by a changed Sheets row, and
the importer reports `import_conflict`. An admin or HQ operator must review the
conflict and decide which system is authoritative; automatic reconciliation of
both changed systems is not currently verified.
