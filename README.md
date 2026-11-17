# Marison's Multi-Branch Database

Standalone Laravel application that replaces the Google Sheets data entry for
Marison's branch projects. It imports the pilot workbooks (Jepara–Kuwasen and
Magelang–Kalinegoro) into a normalized database, serves a multi-branch
dashboard with reconciliation views, and keeps full source provenance for
every record.

## Features

- **Idempotent importer** (`php artisan marison:import --project=…`) reading
  the branch workbooks through a read-only Google service account. Supports
  `--dry-run` (validates inside a rolled-back transaction, writes nothing),
  fixture sources for tests, and a JSON report.
- **Normalized schema** — branches, projects, users (HQ / branch admin),
  lots, buyers, bookings with per-stage tables (PSJB, BI Checking,
  Pemberkasan, Proses Bank, PPJB Developer, Akad, BAST), import batches and
  import issues with severity and resolution tracking.
- **Business rules** — one booking per PSJB instance (legacy transaction IDs
  may repeat across cycles), stage rows resolved to the nearest PSJB cycle,
  bank REJECT stored separately from booking decisions, GANTI NAMA / PINDAH
  KAVLING chains with verifiable predecessor links, "Sudah Akad s.d. 2025"
  lots counted in inventory but excluded from available stock, monthly
  actuals from verified event dates (BAST counted in 2026 even when Akad was
  pre-2026).
- **Dashboard** with branch / project / period filters (YTD, 2026, 2025, all,
  custom), KPI summary, lot positions, active-booking pipeline, per-project
  comparison, recent bookings.
- **Reconciliation view** per project — source-row vs imported counts per tab
  with explanations, plus a comparison against `hq_report_export` that makes
  the current-position vs period-actual distinction explicit.
- **Import console** — batch list, per-batch detail, issue resolution.
- **Staging PSJB workflow** — assigned branch admins can create, Withdraw, and
  Cancel PSJB bookings with MySQL row locks, immutable audit rows, repeat-booking
  history, and Laravel ownership protection during imports. See
  [`docs/psjb-staging-workflow.md`](docs/psjb-staging-workflow.md).
- **Access control** — session auth, roles `hq` and `branch_admin`, project
  assignment via a pivot; branch admins only see assigned projects.
- **Test suite** — 123 tests / 1,909 assertions including regression coverage
  for the G09 two-cycle case, the K20 five-cycle chain, historical-Akad
  exclusion, cross-year BAST actuals, staging workflow eligibility,
  idempotent re-imports, and dry-run no-write guarantees (run against an
  anonymized Kuwasen fixture).

## Getting started (local, XAMPP)

```bash
composer install
cp .env.example .env        # set DB + GOOGLE_SHEETS_* values
php artisan key:generate
php artisan migrate --seed
php artisan test
php artisan marison:import --project=PRJ-JPR-KWS --dry-run
php artisan serve
```

Default seeded accounts (change before production):

| Role | Email | Password |
|---|---|---|
| HQ | `hq@marison.test` | `password` |
| Admin Magelang | `magelang@marison.test` | `password` |
| Admin Jepara | `jepara@marison.test` | `password` |

## Documentation

- [`docs/source-audit.md`](docs/source-audit.md) — workbook audit, header
  contract, data semantics, known source exceptions.
- [`docs/reconciliation.md`](docs/reconciliation.md) — reconciliation results
  per pilot project and the automated regression evidence.
- [`docs/hostinger-deployment.md`](docs/hostinger-deployment.md) — deployment
  to Hostinger Business (hPanel + MySQL), cron setup, security notes.
