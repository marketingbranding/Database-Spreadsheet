# Source audit — pilot workbooks

Audit date: 16 Nov 2026. Dumps of every tab (TSV) were taken from the live
read-only spreadsheets and are archived in the audit working folder
(`marison-audit/dump/{magelang,jepara,hq}/*.tsv`).

## Workbooks

| Project | Code | Spreadsheet ID | Coded lots | Notes |
|---|---|---|---|---|
| Marison Regency Kuwasen (Jepara) | `PRJ-JPR-KWS` | `10IGS9vuDHP4E2-tgGu7pO-blWn_R9pFY3yDKTKmf5aI` | 260 | 82 "Sudah Akad s.d. 2025" markers |
| Marison Regency Kalinegoro (Magelang) | `PRJ-MGL-KLG` | `1k7tkvzJ09L_X2i75YUgzQ_UfNkRnX5veOYHt6I2t-mw` | 197 | no historical markers |

HQ dashboard workbook: `1ZQxyhLlkugBY5rSmAV7XlJGIMpCoidbwMDo5qXDEQjk`
(the `hq_report_export` tab also exists inside each branch workbook and is
read from there by the importer).

## Header contract

All nine operational tabs use identical header maps in both workbooks
(verified by dump diff):

`Data Kav`, `Data Konsumen`, `PSJB`, `BI Checking`, `Pemberkasan`,
`Proses Bank`, `PPJB Developer`, `Akad`, `BAST`, plus the computed
`hq_report_export`.

Required columns enforced by the importer (`ProjectImporter::validateHeaders`):

- Data Kav: `Kode Kavling`
- Data Konsumen: `Nama Konsumen`
- PSJB: `Nama Konsumen`, `Kavling`, `ID Transaksi`, `ID PSJB`, `Tanggal PSJB`
- BI Checking: `ID Transaksi`, `Tanggal BI Checking`
- Pemberkasan: `ID Transaksi`, `ID Berkas`
- Proses Bank: `ID Transaksi`, `ID Putusan`
- PPJB Developer: `ID Transaksi`, `No. PPJB`
- Akad: `ID Transaksi`, `No. Akad`, `Tanggal Akad`
- BAST: `ID Transaksi`, `No. BAST`, `Tanggal BAST`

## Data semantics discovered during the audit

1. **`[SYS] ID Transaksi` repeats across booking cycles.** Example: lot G09 in
   Kuwasen has an April 2026 cycle (withdrawn) and a September 2026 cycle
   (completed) sharing one legacy transaction ID. The booking identity is
   therefore the PSJB instance (`PSJB:<id>`), not the legacy transaction ID.
2. **Stage-tab reference columns can be stale.** `[SYS] ID PSJB Sebelumnya`
   etc. sometimes point to the cycle root instead of the immediate predecessor.
   Stage rows are resolved by transaction ID + nearest preceding PSJB date;
   reference IDs are only a fallback when a single cycle exists.
3. **Withdrawal truth lives in stage-tab checkboxes.** G09 April is `LANJUT`
   on the PSJB tab but `Mundur? = 1` in Proses Bank (bank rejected). The
   importer derives booking decisions from stage-tab flags, with the PSJB
   status as a fallback.
4. **`GANTI NAMA` / `PINDAH KAVLING` are terminal only for source rows.**
   Destination rows (with a parent transaction) stay active and become the
   current holder. Source/destination links are preserved in
   `bookings.predecessor_booking_id`.
5. **`REJECT BANK` / `REJECT SLIK` on PSJB are not booking decisions.** Bank
   results are stored in `bank_submissions`; the booking stays open and is
   flagged for review.
6. **Historical Akad markers.** Kuwasen has 82 `Sudah Akad s.d. 2025` markers,
   matching exactly the 82 Akad records dated ≤ 2025. Magelang has none.
   These lots count in inventory but are excluded from available stock.
7. **`hq_report_export` is a current-position snapshot** (e.g. Kuwasen
   `AKAD = 3`, `SERAH_TERIMA = 183`), not a period actual. The dashboard
   reconciles against it with explicit explanations.

## Known source-data exceptions (found in the audit, reproduced by the importer)

| Exception | Where | Handling |
|---|---|---|
| BI Checking row without any transaction ID (buyer "Norma", M22) | Kuwasen BI Checking baris 201 | imported row unlinked, `orphan_bi` **error** |
| Lot M22 has two open bookings at once | Kalinegoro PSJB | `multiple_active_bookings` warning, latest = current holder |
| 4 × PINDAH KAVLING where source and destination lot are the same | Kalinegoro PSJB (D07, G03, G05, G16) | `transfer_link_mismatch` warning |
| PSJB statuses `REJECT BANK` / `REJECT SLIK` (3 rows) | Kuwasen PSJB | stored, `unmapped_psjb_status` warning |
| 2 × bank REJECT without a booking-side decision | both projects | `bank_reject_without_booking_decision` warning |
| G09 April bank decision has no decision date | Kuwasen | decision recorded at stage Proses Bank with the withdrawal date |
