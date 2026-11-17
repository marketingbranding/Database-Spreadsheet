# Reconciliation results — pilot projects

Numbers below come from the applied imports (16 Nov 2026) and the fresh
`--dry-run` verification runs against the live workbooks. All dry-run numbers
matched the applied state exactly and every entity reported `updated`, none
`created` — the import is idempotent.

## Commands used

```bash
php artisan marison:import --project=PRJ-JPR-KWS --dry-run
php artisan marison:import --project=PRJ-MGL-KLG --dry-run
# apply (idempotent, safe to re-run):
php artisan marison:import --project=PRJ-JPR-KWS
php artisan marison:import --project=PRJ-MGL-KLG
```

## Marison Regency Kuwasen (`PRJ-JPR-KWS`)

### Per-tab: source rows vs imported records

| Tab | Source rows | Imported | Difference |
|---|---|---|---|
| Data Kav | 260 | 260 | 0 |
| Data Konsumen | 241 | 238 | −3 |
| PSJB | 249 | 249 | 0 |
| BI Checking | 211 | 210 | −1 |
| Pemberkasan | 223 | 223 | 0 |
| Proses Bank | 190 | 190 | 0 |
| PPJB Developer | 188 | 188 | 0 |
| Akad | 186 | 186 | 0 |
| BAST | 183 | 183 | 0 |

Explanations:

- **Data Konsumen −3**: the buyer table stores one record per person; a few
  people appear on multiple booking cycles and are counted once per source row
  set. The dashboard metric "buyers linked to this project" counts buyers, not
  rows.
- **BI Checking −1**: the known orphan row (buyer "Norma", lot M22's
  transaction column empty) cannot be linked to any PSJB instance. It is
  reported as an `orphan_bi` **error** rather than silently dropped. Note: the
  M22 orphan is present in the Kuwasen workbook although the lot itself
  belongs to Kalinegoro.

### vs `hq_report_export` (current position)

| Metric | Dashboard | HQ export | Difference | Explanation |
|---|---|---|---|---|
| Total lots | 260 | 260 | 0 | both count coded Data Kav rows |
| Available stock | 46 | 44 | +2 | the 2 PERLU CEK ("needs review") lots are counted as occupied by HQ but are reported separately on the dashboard |
| Sold / handed over (position) | 183 | 183 | 0 | current BAST holders |
| Akad in selected period | 186 (all-time) / 104 (2026) | — | — | HQ's `AKAD = 3` is the number currently sitting at the Akad stage, not a period actual |
| BAST in selected period | 183 (all-time) / 108 (2026) | — | — | dashboard BAST is an event count by BAST date |

### Booking-status distribution (Kuwasen)

- 213 current holders (30 active + 183 BAST-completed, including the 82
  historical pre-2026 Akad lots)
- Remaining cycles: MUNDUR / BATAL / REPLACED / MOVED outcomes plus the two
  PERLU CEK rows

## Marison Regency Kalinegoro (`PRJ-MGL-KLG`)

### Per-tab: source rows vs imported records

| Tab | Source rows | Imported | Difference |
|---|---|---|---|
| Data Kav | 197 | 197 | 0 |
| Data Konsumen | 184 | 184 | 0 |
| PSJB | 199 | 199 | 0 |
| BI Checking | 171 | 171 | 0 |
| Pemberkasan | 156 | 156 | 0 |
| Proses Bank | 128 | 128 | 0 |
| PPJB Developer | 96 | 96 | 0 |
| Akad | 96 | 96 | 0 |
| BAST | 70 | 70 | 0 |

### vs `hq_report_export` (current position)

| Metric | Dashboard | HQ export | Difference |
|---|---|---|---|
| Total lots | 197 | 197 | 0 |
| Available stock | 65 | 65 | 0 |
| Sold / handed over (position) | 70 | 70 | 0 |

Full agreement — Kalinegoro has no historical markers and no orphan rows.

## Open exceptions (unresolved, by design reported in the dashboard)

1. **`orphan_bi` (error, Kuwasen)** — BI Checking baris 201 has no transaction
   ID; needs the source row fixed in the sheet before it can be linked.
2. **`multiple_active_bookings` (Kalinegoro, M22)** — two open bookings on one
   lot; the latest is treated as current holder. Needs a business decision
   (withdraw or cancel one).
3. **4 × `transfer_link_mismatch` (Kalinegoro)** — PINDAH KAVLING rows whose
   source and destination lot are the same (D07, G03, G05, G16). Data-entry
   practice appears to re-book the same lot; links are preserved but flagged.
4. **3 × `unmapped_psjb_status` (Kuwasen)** — PSJB statuses `REJECT BANK` /
   `REJECT SLIK` are bank outcomes, not booking decisions; they are stored on
   the bank submission and the booking stays open for review.
5. **2 × `bank_reject_without_booking_decision`** — bank rejected but no
   MUNDUR/BATAL checkbox was ticked in the source; flagged for review.
6. **G09 April** — the withdrawal (MUNDUR at Proses Bank, 2026-04-15) has no
   explicit decision date in the source; the decision date falls back to the
   stage event date.

## Regression verification (automated, `tests/Feature/ImporterRegressionTest`)

Run against an anonymized fixture built from the Kuwasen workbook
(`tests/Fixtures/kuwasen-regression.json` — names/NIKs/phones replaced,
identifiers and structure preserved):

- re-import creates zero duplicates (all entities updated, none created)
- dry-run commits nothing (no lots/bookings/batches for the target project)
- G09: two cycles on one legacy transaction ID — April `MUNDUR` at Proses Bank
  (2026-04-15), September `COMPLETED` with Akad/BAST 2026-09-30, one current
  holder
- K20: the five-cycle chain (moved → replaced → two withdrawals → completed)
  is preserved, exactly one current holder, lot counted once
- 82 historical "Sudah Akad s.d. 2025" lots count in inventory but are
  excluded from available stock
- BAST dated 2026 after a pre-2026 Akad is counted in 2026 BAST actuals

`php artisan test` → 21 passed, 99 assertions.
