<?php

namespace App\Services\Import;

use App\Models\AkadRecord;
use App\Models\BankSubmission;
use App\Models\BastRecord;
use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\PsjbRecord;
use App\Support\Normalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProjectImporter
{
    private Project $project;

    private bool $dryRun = false;

    /** @var array<string, mixed> */
    private array $stats = [];

    /** @var array<int, array<string, mixed>> */
    private array $issues = [];

    /** @var array<string, string> */
    private array $issueKeys = [];

    /** @var array<string, array<string, mixed>> */
    private array $bookingRows = [];

    /** @var array<string, array<int, string>> */
    private array $bookingsByTrx = [];

    /** @var array<string, string> */
    private array $bookingsByPsjb = [];

    /** @var array<string, string> */
    private array $pemberkasanByFile = [];

    /** @var array<string, PemberkasanRecord> */
    private array $pemberkasanRecordsByFile = [];

    /** @var array<string, string> */
    private array $bankDecisionMap = [];

    /** @var array<string, string> */
    private array $ppjbMap = [];

    /** @var array<string, string> */
    private array $akadMap = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $stageEvents = [];

    /** @var array<string, bool> */
    private array $protectedBookings = [];

    /** @var array<string, bool> */
    private array $protectedBiBookings = [];

    /** @var array<string, bool> */
    private array $protectedBankBookings = [];

    /** @var array<int, bool> */
    private array $laravelBankBookingIds = [];

    /** @var array<int, bool> */
    private array $laravelPpjbBookingIds = [];

    /** @var array<int, bool> */
    private array $laravelAkadBookingIds = [];

    /** @var array<int, bool> */
    private array $laravelBastBookingIds = [];

    /** @var array<string, bool> */
    private array $protectedAkadBookings = [];

    /** @var array<string, bool> */
    private array $protectedBastBookings = [];

    /** @var array<string, bool> */
    private array $protectedPpjbBookings = [];

    /** @var array<string, Lot> */
    private array $lots = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $buyersByName = [];

    /** @var array<string, string> */
    private array $buyersBySourceId = [];

    /** @var array<string, string> */
    private array $buyersByNik = [];

    /** @var array<string, string> */
    private array $hqPositions = [];

    private ?ImportBatch $batch = null;

    public function __construct(private readonly SheetReader $reader)
    {
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>|null  $rawTabs  optional pre-read data
     */
    public function run(Project $project, bool $dryRun = false, ?array $rawTabs = null, ?ImportBatch $existingBatch = null): ImportReport
    {
        $this->project = $project;
        $this->dryRun = $dryRun;
        $this->resetState();

        $started = now();
        $startTime = microtime(true);
        $tabs = config('marison.import.tabs');
        $rawTabs ??= $this->reader->read($tabs);

        foreach ($tabs as $tab) {
            if (! array_key_exists($tab, $rawTabs)) {
                $this->issue('error', 'missing_tab', "Tab [{$tab}] is missing from the source spreadsheet.", $tab);
            }
        }

        $this->stats['mode'] = $dryRun ? 'dry_run' : 'apply';
        $this->stats['source'] = $this->reader->sourceLabel();
        $this->stats['raw_counts'] = [];
        $this->stats['stages'] = [];
        $this->stats['started_at'] = $started->toIso8601String();

        if (! $dryRun) {
            $this->batch = $existingBatch ?? ImportBatch::create([
                'project_id' => $project->id,
                'mode' => 'apply',
                'source' => $this->reader->sourceLabel(),
                'status' => 'running',
                'spreadsheet_id' => $project->spreadsheet_id,
                'started_at' => $started,
            ]);
            $this->batch->update(['status' => 'running', 'started_at' => $started]);
        }

        $connection = DB::connection();
        $connection->beginTransaction();
        if ($project->management_mode === Project::MANAGEMENT_LARAVEL) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->laravelBankBookingIds = array_fill_keys(
                BankSubmission::query()
                    ->where('project_id', $project->id)
                    ->where('source_owner', BankSubmission::OWNER_LARAVEL)
                    ->pluck('booking_id')
                    ->all(),
                true
            );
            $this->laravelPpjbBookingIds = array_fill_keys(
                PpjbRecord::query()
                    ->where('project_id', $project->id)
                    ->where('source_owner', PpjbRecord::OWNER_LARAVEL)
                    ->pluck('booking_id')
                    ->all(),
                true
            );
            $this->laravelAkadBookingIds = array_fill_keys(
                AkadRecord::query()
                    ->where('project_id', $project->id)
                    ->where('source_owner', AkadRecord::OWNER_LARAVEL)
                    ->pluck('booking_id')
                    ->all(),
                true
            );
            $this->laravelBastBookingIds = array_fill_keys(
                BastRecord::query()
                    ->where('project_id', $project->id)
                    ->where('source_owner', BastRecord::OWNER_LARAVEL)
                    ->pluck('booking_id')
                    ->all(),
                true
            );
        }

        try {
            $tabsByName = [];
            foreach ($rawTabs as $name => $rows) {
                $tabsByName[$name] = new Tab($name, $rows);
            }

            $this->validateHeaders($tabsByName);
            $hqExport = $this->readOptionalTab('hq_report_export', $tabsByName, $rawTabs);

            $lots = $this->importLots($tabsByName['Data Kav'] ?? null);
            $nikHints = $this->collectNikHints($tabsByName['BI Checking'] ?? null);
            $buyers = $this->importBuyers($tabsByName['Data Konsumen'] ?? null);

            $this->importBookings($tabsByName['PSJB'] ?? null, $buyers, $nikHints);
            $this->importBi($tabsByName['BI Checking'] ?? null);
            $this->importPemberkasan($tabsByName['Pemberkasan'] ?? null);
            $this->importBank($tabsByName['Proses Bank'] ?? null);
            $this->importPpjb($tabsByName['PPJB Developer'] ?? null);
            $this->importAkad($tabsByName['Akad'] ?? null);
            $this->importBast($tabsByName['BAST'] ?? null);
            $this->linkPredecessors();
            $this->deriveBookings();
            $this->applyHistoricalAkad($lots);

            if ($hqExport) {
                $this->parseHqExport($hqExport, $lots);
            }

            $this->stats['ringkasan_rows'] = $this->optionalRowCount('Ringkasan Transaksi', $tabsByName, $rawTabs);
            $conflictPreview = app(ImportConflictProjector::class)
                ->preview($project, $this->issues, $rawTabs, $this->hqPositions);
            $this->stats['conflict_projection'] = [
                'total' => count($conflictPreview),
                'by_case' => collect($conflictPreview)->countBy('case_type')->all(),
            ];

            $this->stats['issues'] = $this->issueCounts();
            $this->stats['finished_at'] = now()->toIso8601String();
            $this->stats['duration_ms'] = (int) round((microtime(true) - $startTime) * 1000);

            if ($dryRun) {
                $connection->rollBack();
                $batchId = null;
            } else {
                $this->persistIssues();
                app(ImportConflictProjector::class)
                    ->persist($project, $this->batch, $rawTabs, $this->hqPositions);
                $this->batch->update([
                    'status' => 'completed',
                    'stats' => $this->stats,
                    'finished_at' => now(),
                ]);
                $connection->commit();
                $batchId = $this->batch->id;
            }
        } catch (\Throwable $e) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if (! $dryRun && $this->batch) {
                $this->batch->update([
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                    'stats' => $this->stats,
                    'finished_at' => now(),
                ]);
            }
            throw $e;
        }

        return new ImportReport(
            $this->project,
            $this->dryRun,
            $this->stats,
            $this->issues,
            $batchId,
        );
    }

    private function resetState(): void
    {
        $this->stats = [];
        $this->issues = [];
        $this->issueKeys = [];
        $this->bookingRows = [];
        $this->bookingsByTrx = [];
        $this->bookingsByPsjb = [];
        $this->pemberkasanByFile = [];
        $this->pemberkasanRecordsByFile = [];
        $this->bankDecisionMap = [];
        $this->ppjbMap = [];
        $this->akadMap = [];
        $this->stageEvents = [];
        $this->protectedBookings = [];
        $this->protectedBiBookings = [];
        $this->protectedBankBookings = [];
        $this->laravelBankBookingIds = [];
        $this->protectedPpjbBookings = [];
        $this->laravelPpjbBookingIds = [];
        $this->protectedAkadBookings = [];
        $this->laravelAkadBookingIds = [];
        $this->protectedBastBookings = [];
        $this->laravelBastBookingIds = [];
        $this->lots = [];
        $this->buyersByName = [];
        $this->buyersBySourceId = [];
        $this->buyersByNik = [];
        $this->hqPositions = [];
        $this->batch = null;
    }

    private function validateHeaders(array $tabsByName): void
    {
        $required = [
            'Data Kav' => ['Kode Kavling'],
            'Data Konsumen' => ['Nama Konsumen'],
            'PSJB' => ['Nama Konsumen', 'Kavling', 'ID Transaksi', 'ID PSJB', 'Tanggal PSJB'],
            'BI Checking' => ['ID Transaksi', 'Tanggal BI Checking'],
            'Pemberkasan' => ['ID Transaksi', 'ID Berkas'],
            'Proses Bank' => ['ID Transaksi', 'ID Putusan'],
            'PPJB Developer' => ['ID Transaksi', 'No. PPJB'],
            'Akad' => ['ID Transaksi', 'No. Akad', 'Tanggal Akad'],
            'BAST' => ['ID Transaksi', 'No. BAST', 'Tanggal BAST'],
        ];

        foreach ($required as $tabName => $headers) {
            if (! isset($tabsByName[$tabName])) {
                continue;
            }
            foreach ($headers as $header) {
                if ($tabsByName[$tabName]->headerIndex($header) === null) {
                    $this->issue('error', 'missing_header', "Required column [{$header}] is missing from tab [{$tabName}].", $tabName);
                }
            }
        }
    }

    private function importLots(?Tab $tab): array
    {
        $lots = [];
        if (! $tab) {
            return $lots;
        }

        $seen = [];
        $count = 0;

        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $code = Normalizer::lotCode($tab->value($row, ['Kode Kavling', 'Kode']), $this->project->name);

            if (! $code) {
                continue;
            }

            $count++;
            if (isset($seen[$code])) {
                $this->issue('error', 'duplicate_lot', "Lot code [{$code}] appears more than once in Data Kav.", 'Data Kav', $rowNumber, $code, ['first_row' => $seen[$code]]);
            }
            $seen[$code] = $seen[$code] ?? $rowNumber;

            $historical = Normalizer::bool($tab->value($row, ['Sudah Akad s.d. 2025?', 'Sudah Akad']));
            $key = $this->project->code . ':LOT:' . $code;

            $attributes = [
                'project_id' => $this->project->id,
                'code' => $code,
                'source_id_kavling' => $tab->text($row, ['ID Kavling']),
                'building_area' => $tab->number($row, ['Luas Bangunan']),
                'land_area' => $tab->number($row, ['Luas Tanah']),
                'klt_area' => $tab->number($row, ['Luas KLT']),
                'build_progress' => $tab->number($row, ['Progres Bangun']),
                'ready_date' => $this->dateValue($tab, $row, ['Tanggal Ready 100', 'Tanggal Ready'], 'Data Kav', $rowNumber),
                'order_status' => $tab->text($row, ['Status Order Bangun']),
                'historical_akad_before_2026' => $historical,
                'historical_marker' => $historical ? 'Data Kav: Sudah Akad s.d. 2025' : null,
                'opening_status' => $historical ? Lot::OPENING_HISTORICAL_AKAD : null,
                'source_key' => $key,
                'source_tab' => 'Data Kav',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];

            $lot = $this->upsert(Lot::class, $key, $attributes, 'lots');
            $lots[$code] = $lot;
        }

        $this->stats['raw_counts']['Data Kav'] = $count;
        $this->stats['lots']['total'] = $count;
        $this->stats['lots']['unique'] = count($lots);
        $this->lots = $lots;

        return $lots;
    }

    private function importBuyers(?Tab $tab): array
    {
        $buyers = [];
        if (! $tab) {
            return $buyers;
        }

        $count = 0;
        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $name = $tab->text($row, ['Nama Konsumen']);
            $sourceId = $tab->text($row, ['ID Konsumen']);

            if (! $name && ! $sourceId) {
                continue;
            }
            $count++;

            $nik = $tab->text($row, ['NIK']);
            $nikHash = Normalizer::nikHash($nik);
            $base = $this->project->code . ':BUYER:';
            $key = $sourceId
                ? $base . 'ID:' . $sourceId
                : ($nikHash ? $base . 'NIK:' . $nikHash : $base . 'NAME:' . Normalizer::slug($name));

            $attributes = [
                'source_key' => $key,
                'source_customer_id' => $sourceId,
                'name' => $name ?: ('Konsumen ' . $sourceId),
                'nik_hash' => $nikHash,
                'nik_last4' => Normalizer::nikLast4($nik),
                'source_tab' => 'Data Konsumen',
                'source_row' => $rowNumber,
            ];

            if ($nik) {
                $attributes['nik_encrypted'] = $nik;
            }
            $phone = $tab->text($row, ['No. HP']);
            if ($phone) {
                $attributes['phone_encrypted'] = $phone;
            }

            $buyer = $this->upsert(Buyer::class, $key, $attributes, 'buyers');
            $buyers[$key] = $buyer;
            $nameKey = Normalizer::nameKey($buyer->name);
            if ($nameKey) {
                $this->buyersByName[$nameKey][] = $key;
            }
            if ($sourceId) {
                $this->buyersBySourceId[$sourceId] = $key;
            }
            if ($nikHash) {
                $this->buyersByNik[$nikHash] = $key;
            }
        }

        $this->stats['raw_counts']['Data Konsumen'] = $count;
        $this->stats['buyers']['total'] = $count;

        return $buyers;
    }

    private function collectNikHints(?Tab $tab): array
    {
        $hints = [];
        if (! $tab) {
            return $hints;
        }

        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $nik = $tab->text($row, ['NIK']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $label = $tab->text($row, ['Pilih PSJB']);
            $date = Normalizer::dateFromLabel($label);

            if ($nik && $trx && $date) {
                $hints[$trx][$date->toDateString()] = Normalizer::nikHash($nik);
            }
        }

        return $hints;
    }

    private function importBookings(?Tab $tab, array $buyers, array $nikHints): void
    {
        if (! $tab) {
            return;
        }

        $count = 0;
        $seen = [];

        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];

            $psjbNumber = $tab->text($row, ['ID PSJB']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $psjbDate = $this->dateValue($tab, $row, ['Tanggal PSJB'], 'PSJB', $rowNumber);
            $kavling = $tab->text($row, ['Kavling']);
            $lotCode = Normalizer::lotCode($kavling, $this->project->name);
            $name = $tab->text($row, ['Nama Konsumen']);

            if (! $psjbNumber && ! $trx) {
                continue;
            }
            $count++;

            if (! $psjbNumber) {
                $psjbNumber = 'PSJB-' . $this->project->code . '-' . $rowNumber;
                $this->issue('warning', 'missing_psjb_number', 'PSJB row has no PSJB number; a stable fallback key was generated.', 'PSJB', $rowNumber, $psjbNumber);
            }

            if (isset($seen[$psjbNumber])) {
                $this->issue('warning', 'duplicate_psjb', "PSJB [{$psjbNumber}] appears more than once. Later rows update the same booking.", 'PSJB', $rowNumber, $psjbNumber, ['first_row' => $seen[$psjbNumber]]);
            }
            $seen[$psjbNumber] = $seen[$psjbNumber] ?? $rowNumber;

            if (! $lotCode || ! isset($this->lots[$lotCode])) {
                $this->issue('error', 'unknown_lot', 'PSJB references a lot that is not coded in Data Kav.', 'PSJB', $rowNumber, $psjbNumber, ['kavling' => $kavling, 'lot_code' => $lotCode]);
                $this->stats['stages']['PSJB']['skipped'] = ($this->stats['stages']['PSJB']['skipped'] ?? 0) + 1;
                continue;
            }

            $buyerKey = $this->resolveBuyer($name, $trx, $psjbDate, $psjbNumber, $rowNumber, $buyers, $nikHints);
            if (! $buyerKey) {
                $this->issue('error', 'unknown_buyer', 'PSJB row has no resolvable buyer name.', 'PSJB', $rowNumber, $psjbNumber);
                $this->stats['stages']['PSJB']['skipped'] = ($this->stats['stages']['PSJB']['skipped'] ?? 0) + 1;
                continue;
            }

            $sourceKey = 'PSJB:' . $psjbNumber;
            $status = Normalizer::text($tab->value($row, ['Status Transaksi']));
            $normalizedStatus = strtoupper(trim((string) $status));
            $transferType = match ($normalizedStatus) {
                'GANTI NAMA' => 'GANTI_NAMA',
                'PINDAH KAVLING' => 'PINDAH_KAVLING',
                default => null,
            };
            if ($tab->flag($row, ['Ganti Nama'])) {
                $transferType = 'GANTI_NAMA';
            } elseif ($tab->flag($row, ['Pindah Kavling'])) {
                $transferType = 'PINDAH_KAVLING';
            }

            $bookingAttributes = [
                'project_id' => $this->project->id,
                'lot_id' => $this->lots[$lotCode]->id,
                'buyer_id' => $buyers[$buyerKey]->id,
                'source_booking_key' => $sourceKey,
                'source_transaction_id' => $trx,
                'psjb_number' => $psjbNumber,
                'booking_date' => $psjbDate,
                'payment_method' => $tab->text($row, ['Metode Pembayaran']),
                'sales_name' => $tab->text($row, ['Sales']),
                'coordinator_name' => $tab->text($row, ['Koordinator']),
                'base_price' => $tab->number($row, ['Harga Unit']),
                'status' => Booking::STATUS_ACTIVE,
                'current_stage' => Booking::STAGE_PSJB,
                'transfer_type' => $transferType,
                'source_tab' => 'PSJB',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];

            $existingBooking = Booking::query()->where('source_booking_key', $sourceKey)->first();
            $protected = $this->project->management_mode === Project::MANAGEMENT_LARAVEL
                && ($existingBooking?->source_owner === Booking::OWNER_LARAVEL
                    || ($existingBooking && isset($this->laravelBankBookingIds[$existingBooking->id])));
            if ($protected) {
                $this->protectedBookings[$sourceKey] = true;
                $booking = $existingBooking;
            } else {
                $booking = $this->upsert(Booking::class, $sourceKey, $bookingAttributes, 'bookings', 'source_booking_key');
            }
            $this->bookingRows[$sourceKey] = [
                'booking' => $booking,
                'lot_code' => $lotCode,
                'transaction' => $trx,
                'date' => $psjbDate,
                'parent_transaction' => $tab->text($row, ['ID Transaksi Induk']),
                'status' => $status,
                'transfer_type' => $transferType,
                'source_attributes' => $bookingAttributes,
                'row' => $rowNumber,
            ];
            $this->bookingsByPsjb[$psjbNumber] = $sourceKey;
            if ($trx) {
                $this->bookingsByTrx[$trx][] = $sourceKey;
            }

            $psjbAttributes = [
                'booking_id' => $booking->id,
                'project_id' => $this->project->id,
                'source_key' => $sourceKey . ':PSJB',
                'psjb_number' => $psjbNumber,
                'psjb_date' => $psjbDate,
                'sales_name' => $tab->text($row, ['Sales']),
                'coordinator_name' => $tab->text($row, ['Koordinator']),
                'price' => $tab->number($row, ['Harga Unit']),
                'utj_date' => $this->dateValue($tab, $row, ['Tanggal UTJ'], 'PSJB', $rowNumber),
                'utj_amount' => $tab->number($row, ['Nominal UTJ']),
                'dp_all_in' => $tab->number($row, ['DP All In']),
                'installment_count' => $tab->integer($row, ['Jumlah Cicilan DP']),
                'payment_method' => $tab->text($row, ['Metode Pembayaran']),
                'source_status' => $status,
                'notes' => $tab->text($row, ['Keterangan']),
                'source_tab' => 'PSJB',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];
            if ($protected) {
                $this->reportProtectedConflict($booking, array_merge($bookingAttributes, [
                    'psjb_psjb_number' => $psjbAttributes['psjb_number'],
                    'psjb_psjb_date' => $psjbAttributes['psjb_date'],
                    'psjb_sales_name' => $psjbAttributes['sales_name'],
                    'psjb_coordinator_name' => $psjbAttributes['coordinator_name'],
                    'psjb_price' => $psjbAttributes['price'],
                    'psjb_utj_date' => $psjbAttributes['utj_date'],
                    'psjb_utj_amount' => $psjbAttributes['utj_amount'],
                    'psjb_dp_all_in' => $psjbAttributes['dp_all_in'],
                    'psjb_installment_count' => $psjbAttributes['installment_count'],
                    'psjb_payment_method' => $psjbAttributes['payment_method'],
                    'psjb_source_status' => $psjbAttributes['source_status'],
                    'psjb_notes' => $psjbAttributes['notes'],
                ]), 'PSJB', $rowNumber, $sourceKey);
            } else {
                $this->upsert(PsjbRecord::class, $sourceKey . ':PSJB', $psjbAttributes, 'psjb_records');
            }

            $parentTransaction = $tab->text($row, ['ID Transaksi Induk']);
            $isTransferDestination = $parentTransaction !== null;
            $this->recordEvent($sourceKey, 'PSJB', $psjbDate, [
                'withdrawn' => $tab->flag($row, ['Mundur']),
                'cancelled' => $tab->flag($row, ['Batal']),
                'moved' => ! $isTransferDestination && $tab->flag($row, ['Pindah Kavling']),
                'name_change' => ! $isTransferDestination && $tab->flag($row, ['Ganti Nama']),
                'source_status' => $status,
                'notes' => $tab->text($row, ['Keterangan']),
            ]);
        }

        $this->stats['raw_counts']['PSJB'] = $count;
        $this->stats['stages']['PSJB'] = ($this->stats['stages']['PSJB'] ?? []) + ['total' => $count];

        // Same legacy transaction ID with multiple PSJB cycles is allowed; surface it as traceability info.
        foreach ($this->bookingsByTrx as $trx => $keys) {
            if (count($keys) > 1) {
                $this->issue('info', 'multiple_psjb_cycles', "Legacy transaction {$trx} has " . count($keys) . ' booking cycles. Each PSJB is stored separately.', 'PSJB', null, $trx);
            }
        }
    }

    private function resolveBuyer(?string $name, ?string $trx, ?CarbonImmutable $psjbDate, string $psjbNumber, int $row, array $buyers, array $nikHints): ?string
    {
        $nameKey = Normalizer::nameKey($name);
        $candidateKeys = $nameKey ? ($this->buyersByName[$nameKey] ?? []) : [];

        if (count($candidateKeys) === 1) {
            return $candidateKeys[0];
        }

        $nikHash = $trx && $psjbDate ? ($nikHints[$trx][$psjbDate->toDateString()] ?? null) : null;
        if ($nikHash && isset($this->buyersByNik[$nikHash])) {
            return $this->buyersByNik[$nikHash];
        }

        if (count($candidateKeys) > 1) {
            $this->issue('warning', 'ambiguous_buyer', 'Buyer name matches multiple Data Konsumen rows and no unique NIK hint was available. A booking-specific buyer record was created.', 'PSJB', $row, $psjbNumber, ['name' => $name, 'candidates' => count($candidateKeys)]);
        } elseif ($nameKey && isset($this->buyersBySourceId['AUTO:' . $nameKey])) {
            return $this->buyersBySourceId['AUTO:' . $nameKey];
        }

        $key = $this->project->code . ':BUYER:AUTO:' . ($candidateKeys === [] && $nameKey ? $nameKey : $psjbNumber);
        $attributes = [
            'source_key' => $key,
            'source_customer_id' => null,
            'name' => $name ?: 'Konsumen tanpa nama',
            'nik_hash' => $nikHash,
            'nik_last4' => null,
            'source_tab' => 'PSJB',
            'source_row' => $row,
        ];
        $buyer = $this->upsert(Buyer::class, $key, $attributes, 'buyers');
        if ($nameKey && $candidateKeys === []) {
            $this->buyersByName[$nameKey][] = $key;
            $this->buyersBySourceId['AUTO:' . $nameKey] = $key;
        }

        return $key;
    }

    private function importBi(?Tab $tab): void
    {
        if (! $tab) {
            return;
        }

        $count = 0;
        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $number = $tab->text($row, ['ID BI']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $label = $tab->text($row, ['Pilih PSJB']);
            $date = $this->dateValue($tab, $row, ['Tanggal BI Checking'], 'BI Checking', $rowNumber);

            if (! $number && ! $trx) {
                continue;
            }
            $count++;
            $number ??= 'BI-' . $this->project->code . '-' . $rowNumber;
            $bookingKey = $this->resolveStageBooking($tab, $row, 'BI Checking', $rowNumber, $trx, $date, $label);

            if (! $bookingKey) {
                $this->issue('error', 'orphan_bi', 'BI Checking row could not be linked to a PSJB booking.', 'BI Checking', $rowNumber, $number, ['transaction' => $trx]);
                $this->stats['stages']['BI Checking']['skipped'] = ($this->stats['stages']['BI Checking']['skipped'] ?? 0) + 1;
                continue;
            }

            $existingBi = BiCheck::query()->where('source_key', 'BI:' . $number)->first();
            $protectedBi = $this->project->management_mode === Project::MANAGEMENT_LARAVEL
                && $existingBi?->source_owner === BiCheck::OWNER_LARAVEL;
            if ($protectedBi) {
                $this->protectedBiBookings[$bookingKey] = true;
            }

            $attributes = [
                'booking_id' => $this->bookingRows[$bookingKey]['booking']->id,
                'project_id' => $this->project->id,
                'source_key' => 'BI:' . $number,
                'bi_number' => $number,
                'check_date' => $date,
                'result' => $tab->text($row, ['Hasil BI Checking', 'Hasil']),
                'decision' => $tab->text($row, ['Keputusan']),
                'withdrawn_flag' => $tab->flag($row, ['Mundur']),
                'cancelled_flag' => $tab->flag($row, ['Batal']),
                'moved_lot_flag' => $tab->flag($row, ['Pindah Kavling']),
                'name_change_flag' => $tab->flag($row, ['Ganti Nama']),
                'notes' => $tab->text($row, ['Keterangan']),
                'source_tab' => 'BI Checking',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];
            if ($protectedBi) {
                $this->reportBiConflict($existingBi, $attributes, 'BI Checking', $rowNumber, $number);
            } else {
                $this->upsert(BiCheck::class, $attributes['source_key'], $attributes, 'bi_checks');
            }
            if (! $protectedBi) {
                $this->recordEvent($bookingKey, 'BI_CHECKING', $date, [
                    'withdrawn' => $attributes['withdrawn_flag'],
                    'cancelled' => $attributes['cancelled_flag'],
                    'moved' => $attributes['moved_lot_flag'],
                    'name_change' => $attributes['name_change_flag'],
                    'notes' => $attributes['notes'],
                ]);
            }
            $this->checkUnmappedDecision($bookingKey, 'BI Checking', $rowNumber, $number, $attributes['decision']);
        }

        $this->stats['raw_counts']['BI Checking'] = $count;
        $this->stats['stages']['BI Checking'] = ($this->stats['stages']['BI Checking'] ?? []) + ['total' => $count];
    }

    private function importPemberkasan(?Tab $tab): void
    {
        if (! $tab) {
            return;
        }

        $count = 0;
        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $number = $tab->text($row, ['ID Berkas']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $date = $this->dateValue($tab, $row, ['Tanggal Terima Bank'], 'Pemberkasan', $rowNumber);

            if (! $number && ! $trx) {
                continue;
            }
            $count++;
            $number ??= 'BRK-' . $this->project->code . '-' . $rowNumber;
            $psjbRef = $tab->text($row, ['ID PSJB Sebelumnya']);
            $bookingKey = $this->resolveStageBooking(
                $tab, $row, 'Pemberkasan', $rowNumber, $trx, $date,
                $tab->text($row, ['Pilih PSJB']),
                $psjbRef && isset($this->bookingsByPsjb[$psjbRef]) ? $this->bookingsByPsjb[$psjbRef] : null,
            );

            if (! $bookingKey) {
                $this->issue('error', 'orphan_pemberkasan', 'Pemberkasan row could not be linked to a PSJB booking.', 'Pemberkasan', $rowNumber, $number, ['transaction' => $trx]);
                $this->stats['stages']['Pemberkasan']['skipped'] = ($this->stats['stages']['Pemberkasan']['skipped'] ?? 0) + 1;
                continue;
            }

            $sourceKey = 'BRK:' . $number;
            $existingPemberkasan = PemberkasanRecord::query()->where('source_key', $sourceKey)->first();
            $businessPemberkasan = PemberkasanRecord::query()
                ->where('booking_id', $this->bookingRows[$bookingKey]['booking']->id)
                ->where('file_number', $number)
                ->first();
            $protectedPemberkasan = $this->project->management_mode === Project::MANAGEMENT_LARAVEL
                && (($existingPemberkasan?->source_owner === PemberkasanRecord::OWNER_LARAVEL)
                    || ($businessPemberkasan?->source_owner === PemberkasanRecord::OWNER_LARAVEL));

            $attributes = [
                'booking_id' => $this->bookingRows[$bookingKey]['booking']->id,
                'project_id' => $this->project->id,
                'source_key' => $sourceKey,
                'file_number' => $number,
                'received_date' => $date,
                'bank' => $tab->text($row, ['Bank']),
                'bank_unit' => $tab->text($row, ['KC / Unit Bank', 'KC']),
                'status' => $tab->text($row, ['Status Pemberkasan']),
                'withdrawn_flag' => $tab->flag($row, ['Mundur']),
                'cancelled_flag' => $tab->flag($row, ['Batal']),
                'moved_lot_flag' => $tab->flag($row, ['Pindah Kavling']),
                'name_change_flag' => $tab->flag($row, ['Ganti Nama']),
                'notes' => $tab->text($row, ['Keterangan']),
                'source_tab' => 'Pemberkasan',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];
            $record = null;
            if ($protectedPemberkasan) {
                $record = $existingPemberkasan ?: $businessPemberkasan;
                $this->reportPemberkasanConflict($record, $attributes, 'Pemberkasan', $rowNumber, $number);
            } elseif ($businessPemberkasan) {
                $attributes['source_owner'] = PemberkasanRecord::OWNER_SHEETS;
                $attributes['revision'] = $businessPemberkasan->revision;
                $attributes['is_current'] = $businessPemberkasan->is_current;
                $record = $this->upsert(PemberkasanRecord::class, $businessPemberkasan->source_key, $attributes, 'pemberkasan_records');
            } else {
                $previous = PemberkasanRecord::query()->where('booking_id', $this->bookingRows[$bookingKey]['booking']->id)->latest('revision')->first();
                PemberkasanRecord::query()->where('booking_id', $this->bookingRows[$bookingKey]['booking']->id)->update(['is_current' => false]);
                $attributes['source_owner'] = PemberkasanRecord::OWNER_SHEETS;
                $attributes['revision'] = ((int) $previous?->revision) + 1;
                $attributes['is_current'] = true;
                $record = $this->upsert(PemberkasanRecord::class, $attributes['source_key'], $attributes, 'pemberkasan_records');
            }
            $this->pemberkasanByFile[$number] = $bookingKey;
            if ($record) {
                $this->pemberkasanRecordsByFile[$number] = $record;
            }
            if (! $protectedPemberkasan) {
                $this->recordEvent($bookingKey, 'PEMBERKASAN', $date, [
                    'withdrawn' => $attributes['withdrawn_flag'],
                    'cancelled' => $attributes['cancelled_flag'],
                    'moved' => $attributes['moved_lot_flag'],
                    'name_change' => $attributes['name_change_flag'],
                    'notes' => $attributes['notes'],
                ]);
            }
        }

        $this->stats['raw_counts']['Pemberkasan'] = $count;
        $this->stats['stages']['Pemberkasan'] = ($this->stats['stages']['Pemberkasan'] ?? []) + ['total' => $count];
    }

    private function importBank(?Tab $tab): void
    {
        if (! $tab) {
            return;
        }

        $count = 0;
        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $decisionNumber = $tab->text($row, ['ID Putusan']);
            $fileNumber = $tab->text($row, ['ID Berkas']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $submittedDate = $this->dateValue($tab, $row, ['Tanggal Pengajuan Bank'], 'Proses Bank', $rowNumber);

            if (! $decisionNumber && ! $fileNumber && ! $trx) {
                continue;
            }
            $count++;
            $decisionNumber ??= 'PUTUSAN-' . $this->project->code . '-' . $rowNumber;

            $bookingKey = $this->resolveStageBooking(
                $tab, $row, 'Proses Bank', $rowNumber, $trx, $submittedDate,
                $tab->text($row, ['Pilih Berkas']),
                $fileNumber && isset($this->pemberkasanByFile[$fileNumber]) ? $this->pemberkasanByFile[$fileNumber] : null,
            );

            if (! $bookingKey) {
                $this->issue('error', 'orphan_bank', 'Bank submission could not be linked to a PSJB booking.', 'Proses Bank', $rowNumber, $decisionNumber, ['transaction' => $trx, 'file' => $fileNumber]);
                $this->stats['stages']['Proses Bank']['skipped'] = ($this->stats['stages']['Proses Bank']['skipped'] ?? 0) + 1;
                continue;
            }

            $result = $tab->text($row, ['Hasil Bank', 'Hasil']);
            $bookingId = $this->bookingRows[$bookingKey]['booking']->id;
            $pemberkasan = $fileNumber ? ($this->pemberkasanRecordsByFile[$fileNumber] ?? null) : null;
            if ($pemberkasan && $pemberkasan->booking_id !== $bookingId) {
                $pemberkasan = null;
            }

            $sourceKey = 'BANK:' . $decisionNumber;
            $existing = BankSubmission::query()->where('source_key', $sourceKey)->first();
            $businessMatch = null;
            if ($fileNumber && (! $existing || $existing->source_owner !== BankSubmission::OWNER_LARAVEL)) {
                $candidates = BankSubmission::query()
                    ->where('booking_id', $bookingId)
                    ->where('file_number', $fileNumber)
                    ->when($existing, fn ($query) => $query->whereKeyNot($existing->getKey()))
                    ->orderBy('id')
                    ->get();
                $businessMatch = ($pemberkasan ? $candidates->firstWhere('pemberkasan_id', $pemberkasan->id) : null)
                    ?? $candidates->first();
            }

            $laravelRow = collect([$existing, $businessMatch])
                ->first(fn ($candidate) => $candidate?->source_owner === BankSubmission::OWNER_LARAVEL);
            $protected = $this->project->management_mode === Project::MANAGEMENT_LARAVEL && $laravelRow !== null;
            $matched = $protected ? $laravelRow : ($existing ?? $businessMatch);

            $attributes = [
                'booking_id' => $bookingId,
                'project_id' => $this->project->id,
                'source_key' => $sourceKey,
                'file_number' => $fileNumber,
                'decision_number' => $decisionNumber,
                'submission_sequence' => $tab->integer($row, ['Pengajuan Ke']),
                'bank' => $tab->text($row, ['Bank']),
                'bank_unit' => $tab->text($row, ['KC / Unit Bank', 'KC']),
                'submitted_date' => $submittedDate,
                'decision_date' => $this->dateValue($tab, $row, ['Tanggal Putusan Bank'], 'Proses Bank', $rowNumber),
                'result' => $this->normalizeBankResult($result),
                'approved_amount' => $tab->number($row, ['Plafon Disetujui']),
                'tenor' => $tab->text($row, ['Tenor Disetujui']),
                'withdrawn_flag' => $tab->flag($row, ['Mundur']),
                'cancelled_flag' => $tab->flag($row, ['Batal']),
                'moved_lot_flag' => $tab->flag($row, ['Pindah Kavling']),
                'name_change_flag' => $tab->flag($row, ['Ganti Nama']),
                'notes' => $tab->text($row, ['Keterangan']),
                'source_owner' => BankSubmission::OWNER_SHEETS,
                'source_tab' => 'Proses Bank',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];
            if ($pemberkasan) {
                $attributes['pemberkasan_id'] = $pemberkasan->id;
            }
            if ($matched) {
                $attributes['source_key'] = $matched->source_key;
            }

            if ($protected) {
                $this->reportBankConflict($laravelRow, $attributes, 'Proses Bank', $rowNumber, $decisionNumber);
                foreach ($this->bookingRows as $key => $info) {
                    if ($info['booking']->id === $laravelRow->booking_id) {
                        $this->protectedBankBookings[$key] = true;
                        break;
                    }
                }
            } else {
                $this->upsert(BankSubmission::class, $attributes['source_key'], $attributes, 'bank_submissions');
                $this->recordEvent($bookingKey, 'PROSES_BANK', $attributes['decision_date'] ?? $submittedDate, [
                    'withdrawn' => $attributes['withdrawn_flag'],
                    'cancelled' => $attributes['cancelled_flag'],
                    'moved' => $attributes['moved_lot_flag'],
                    'name_change' => $attributes['name_change_flag'],
                    'notes' => $attributes['notes'],
                    'source_status' => $attributes['result'],
                ]);
            }
            $this->bankDecisionMap[$decisionNumber] = $bookingKey;
        }

        $this->stats['raw_counts']['Proses Bank'] = $count;
        $this->stats['stages']['Proses Bank'] = ($this->stats['stages']['Proses Bank'] ?? []) + ['total' => $count];
    }

    private function importPpjb(?Tab $tab): void
    {
        if (! $tab) {
            return;
        }

        $count = 0;
        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $number = $tab->text($row, ['No. PPJB']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $signedDate = $this->dateValue($tab, $row, ['Tanggal TTD PPJB'], 'PPJB Developer', $rowNumber);
            $reference = $tab->text($row, ['Referensi Sebelumnya']);

            if (! $number && ! $trx) {
                continue;
            }
            $count++;
            $number ??= 'PPJB-' . $this->project->code . '-' . $rowNumber;

            $bookingKey = $this->resolveStageBooking(
                $tab, $row, 'PPJB Developer', $rowNumber, $trx, $signedDate,
                $tab->text($row, ['Pilih Konsumen']),
                $reference && isset($this->bankDecisionMap[$reference]) ? $this->bankDecisionMap[$reference] : null,
            );

            if (! $bookingKey) {
                $this->issue('error', 'orphan_ppjb', 'PPJB row could not be linked to a PSJB booking.', 'PPJB Developer', $rowNumber, $number, ['transaction' => $trx, 'reference' => $reference]);
                $this->stats['stages']['PPJB Developer']['skipped'] = ($this->stats['stages']['PPJB Developer']['skipped'] ?? 0) + 1;
                continue;
            }

            $bookingId = $this->bookingRows[$bookingKey]['booking']->id;
            $attributes = [
                'booking_id' => $bookingId,
                'project_id' => $this->project->id,
                'source_key' => 'PPJB:' . $number,
                'ppjb_number' => $number,
                'signed_date' => $signedDate,
                'dp_status' => $tab->text($row, ['Status DP']),
                'withdrawn_flag' => $tab->flag($row, ['Mundur']),
                'cancelled_flag' => $tab->flag($row, ['Batal']),
                'moved_lot_flag' => $tab->flag($row, ['Pindah Kavling']),
                'name_change_flag' => $tab->flag($row, ['Ganti Nama']),
                'notes' => $tab->text($row, ['Keterangan']),
                'source_owner' => PpjbRecord::OWNER_SHEETS,
                'source_tab' => 'PPJB Developer',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];

            $protected = $this->project->management_mode === Project::MANAGEMENT_LARAVEL
                && isset($this->laravelPpjbBookingIds[$bookingId]);

            if ($protected) {
                $laravelRow = PpjbRecord::query()
                    ->where('booking_id', $bookingId)
                    ->where('source_owner', PpjbRecord::OWNER_LARAVEL)
                    ->first();
                $this->reportPpjbConflict($laravelRow, $attributes, 'PPJB Developer', $rowNumber, $number);
                $this->protectedPpjbBookings[$bookingKey] = true;
                $this->ppjbMap[$number] = $bookingKey;
                continue;
            }

            $this->upsert(PpjbRecord::class, $attributes['source_key'], $attributes, 'ppjb_records');
            $this->ppjbMap[$number] = $bookingKey;
            $this->recordEvent($bookingKey, 'PPJB', $signedDate, [
                'withdrawn' => $attributes['withdrawn_flag'],
                'cancelled' => $attributes['cancelled_flag'],
                'moved' => $attributes['moved_lot_flag'],
                'name_change' => $attributes['name_change_flag'],
                'notes' => $attributes['notes'],
            ]);
        }

        $this->stats['raw_counts']['PPJB Developer'] = $count;
        $this->stats['stages']['PPJB Developer'] = ($this->stats['stages']['PPJB Developer'] ?? []) + ['total' => $count];
    }

    private function importAkad(?Tab $tab): void
    {
        if (! $tab) {
            return;
        }

        $count = 0;
        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $number = $tab->text($row, ['No. Akad']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $date = $this->dateValue($tab, $row, ['Tanggal Akad'], 'Akad', $rowNumber);
            $reference = $tab->text($row, ['ID PPJB Sebelumnya']);

            if (! $number && ! $trx) {
                continue;
            }
            $count++;
            $number ??= 'AKD-' . $this->project->code . '-' . $rowNumber;

            $bookingKey = $this->resolveStageBooking(
                $tab, $row, 'Akad', $rowNumber, $trx, $date,
                $tab->text($row, ['Pilih PPJB']),
                $reference && isset($this->ppjbMap[$reference]) ? $this->ppjbMap[$reference] : null,
            );

            if (! $bookingKey) {
                $this->issue('error', 'orphan_akad', 'Akad row could not be linked to a PSJB booking.', 'Akad', $rowNumber, $number, ['transaction' => $trx, 'reference' => $reference]);
                $this->stats['stages']['Akad']['skipped'] = ($this->stats['stages']['Akad']['skipped'] ?? 0) + 1;
                continue;
            }

            $bookingId = $this->bookingRows[$bookingKey]['booking']->id;
            $ppjbReference = $reference ?: $tab->text($row, ['Pilih PPJB']);
            $ppjb = $ppjbReference
                ? PpjbRecord::query()
                    ->where('booking_id', $bookingId)
                    ->where('project_id', $this->project->id)
                    ->where(function ($query) use ($ppjbReference): void {
                        $query->where('ppjb_number', $ppjbReference)
                            ->orWhere('source_key', 'PPJB:' . $ppjbReference);
                    })
                    ->first()
                : null;

            $attributes = [
                'booking_id' => $bookingId,
                'ppjb_id' => $ppjb?->id,
                'project_id' => $this->project->id,
                'source_key' => 'AKAD:' . $number,
                'akad_number' => $number,
                'akad_date' => $date,
                'quality' => $tab->text($row, ['Kualitas Akad']),
                'withdrawn_flag' => $tab->flag($row, ['Mundur']),
                'cancelled_flag' => $tab->flag($row, ['Batal']),
                'moved_lot_flag' => $tab->flag($row, ['Pindah Kavling']),
                'name_change_flag' => $tab->flag($row, ['Ganti Nama']),
                'notes' => $tab->text($row, ['Catatan Keterlambatan', 'Keterangan']),
                'source_owner' => AkadRecord::OWNER_SHEETS,
                'source_tab' => 'Akad',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];

            $protected = $this->project->management_mode === Project::MANAGEMENT_LARAVEL
                && isset($this->laravelAkadBookingIds[$bookingId]);
            if ($protected) {
                $laravelRow = AkadRecord::query()
                    ->where('booking_id', $bookingId)
                    ->where('source_owner', AkadRecord::OWNER_LARAVEL)
                    ->first();
                $this->reportAkadConflict($laravelRow, $attributes, 'Akad', $rowNumber, $number);
                $this->protectedAkadBookings[$bookingKey] = true;
                $this->akadMap[$number] = $bookingKey;
                continue;
            }

            $this->upsert(AkadRecord::class, $attributes['source_key'], $attributes, 'akad_records');
            $this->akadMap[$number] = $bookingKey;
            $this->recordEvent($bookingKey, 'AKAD', $date, [
                'withdrawn' => $attributes['withdrawn_flag'],
                'cancelled' => $attributes['cancelled_flag'],
                'moved' => $attributes['moved_lot_flag'],
                'name_change' => $attributes['name_change_flag'],
                'notes' => $attributes['notes'],
            ]);
        }

        $this->stats['raw_counts']['Akad'] = $count;
        $this->stats['stages']['Akad'] = ($this->stats['stages']['Akad'] ?? []) + ['total' => $count];
    }

    private function importBast(?Tab $tab): void
    {
        if (! $tab) {
            return;
        }

        $count = 0;
        foreach ($tab->records() as $record) {
            $row = $record['data'];
            $rowNumber = $record['row'];
            $number = $tab->text($row, ['No. BAST']);
            $trx = $tab->text($row, ['ID Transaksi']);
            $date = $this->dateValue($tab, $row, ['Tanggal BAST'], 'BAST', $rowNumber);
            $reference = $tab->text($row, ['ID Akad Sebelumnya']);

            if (! $number && ! $trx) {
                continue;
            }
            $count++;
            $number ??= 'BAST-' . $this->project->code . '-' . $rowNumber;

            $bookingKey = $this->resolveStageBooking(
                $tab, $row, 'BAST', $rowNumber, $trx, $date,
                $tab->text($row, ['Pilih Akad']),
                $reference && isset($this->akadMap[$reference]) ? $this->akadMap[$reference] : null,
            );

            if (! $bookingKey) {
                $this->issue('error', 'orphan_bast', 'BAST row could not be linked to a PSJB booking.', 'BAST', $rowNumber, $number, ['transaction' => $trx, 'reference' => $reference]);
                $this->stats['stages']['BAST']['skipped'] = ($this->stats['stages']['BAST']['skipped'] ?? 0) + 1;
                continue;
            }

            $bookingId = $this->bookingRows[$bookingKey]['booking']->id;
            $akadReference = $reference ?: $tab->text($row, ['Pilih Akad']);
            $akad = $akadReference
                ? AkadRecord::query()
                    ->where('booking_id', $bookingId)
                    ->where('project_id', $this->project->id)
                    ->where(function ($query) use ($akadReference): void {
                        $query->where('akad_number', $akadReference)
                            ->orWhere('source_key', 'AKAD:' . $akadReference);
                    })
                    ->first()
                : null;

            $attributes = [
                'booking_id' => $bookingId,
                'akad_id' => $akad?->id,
                'project_id' => $this->project->id,
                'source_key' => 'BAST:' . $number,
                'bast_number' => $number,
                'bast_date' => $date,
                'notes' => $tab->text($row, ['Keterangan']),
                'source_owner' => BastRecord::OWNER_SHEETS,
                'source_tab' => 'BAST',
                'source_row' => $rowNumber,
                'source_hash' => $this->hashRow($row),
            ];

            $protected = $this->project->management_mode === Project::MANAGEMENT_LARAVEL
                && isset($this->laravelBastBookingIds[$bookingId]);
            if ($protected) {
                $laravelRow = BastRecord::query()
                    ->where('booking_id', $bookingId)
                    ->where('source_owner', BastRecord::OWNER_LARAVEL)
                    ->first();
                $this->reportBastConflict($laravelRow, $attributes, 'BAST', $rowNumber, $number);
                $this->protectedBastBookings[$bookingKey] = true;
                continue;
            }

            $this->upsert(BastRecord::class, $attributes['source_key'], $attributes, 'bast_records');
            $this->recordEvent($bookingKey, 'BAST', $date, [
                'withdrawn' => false,
                'cancelled' => false,
                'moved' => false,
                'name_change' => false,
                'notes' => $attributes['notes'],
            ]);
        }

        $this->stats['raw_counts']['BAST'] = $count;
        $this->stats['stages']['BAST'] = ($this->stats['stages']['BAST'] ?? []) + ['total' => $count];
    }

    private function resolveStageBooking(Tab $tab, array $row, string $tabName, int $rowNumber, ?string $trx, ?CarbonImmutable $stageDate, ?string $label, ?string $referenceKey = null): ?string
    {
        $labelDate = Normalizer::dateFromLabel($label);
        $candidates = $trx ? ($this->bookingsByTrx[$trx] ?? []) : [];

        if (count($candidates) === 1 && $referenceKey) {
            return $referenceKey;
        }

        if ($labelDate) {
            foreach ($candidates as $key) {
                $bookingDate = $this->bookingRows[$key]['date'];
                if ($bookingDate && $bookingDate->toDateString() === $labelDate->toDateString()) {
                    return $key;
                }
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (count($candidates) > 1 && $stageDate) {
            $best = null;
            $bestDate = null;
            $tie = false;
            foreach ($candidates as $key) {
                $bookingDate = $this->bookingRows[$key]['date'];
                if (! $bookingDate || $bookingDate->greaterThan($stageDate)) {
                    continue;
                }
                if ($bestDate === null || $bookingDate->greaterThan($bestDate)) {
                    $best = $key;
                    $bestDate = $bookingDate;
                    $tie = false;
                } elseif ($bookingDate->equalTo($bestDate)) {
                    $tie = true;
                }
            }
            if ($best && ! $tie) {
                return $best;
            }
            if ($tie) {
                $this->issue('warning', 'ambiguous_stage_booking', "Multiple booking cycles for transaction {$trx} could match this {$tabName} row.", $tabName, $rowNumber, $trx);
            }
        }

        return $referenceKey;
    }

    private function linkPredecessors(): void
    {
        foreach ($this->bookingRows as $key => &$info) {
            $parentTrx = $info['parent_transaction'];
            if (! $parentTrx) {
                continue;
            }

            $candidates = array_values(array_filter(
                $this->bookingsByTrx[$parentTrx] ?? [],
                fn ($candidate) => $candidate !== $key
            ));

            if ($candidates === []) {
                $this->issue('warning', 'transfer_predecessor_unresolved', "Transfer/name-change row references transaction {$parentTrx}, but no source booking was found.", 'PSJB', $info['row'], $key, ['parent_transaction' => $parentTrx]);
                continue;
            }

            usort($candidates, function ($a, $b) {
                $da = $this->bookingRows[$a]['date'];
                $db = $this->bookingRows[$b]['date'];

                return ($db?->timestamp ?? 0) <=> ($da?->timestamp ?? 0);
            });
            $predecessor = $candidates[0];

            if ($this->dryRun) {
                $info['predecessor_key'] = $predecessor;
                continue;
            }

            $info['booking']->update(['predecessor_booking_id' => $this->bookingRows[$predecessor]['booking']->id]);
            $info['predecessor_key'] = $predecessor;
        }
        unset($info);

        foreach ($this->bookingRows as $key => $info) {
            if (empty($info['predecessor_key'])) {
                continue;
            }
            $parent = $this->bookingRows[$info['predecessor_key']];
            $type = $info['transfer_type'] ?: ($this->bookingRows[$info['predecessor_key']]['transfer_type'] ?? null);

            if ($type === 'GANTI_NAMA' && $parent['lot_code'] !== $info['lot_code']) {
                $this->issue('warning', 'transfer_link_mismatch', 'GANTI NAMA source and destination occupy different lots.', 'PSJB', $info['row'], $key, ['source_lot' => $parent['lot_code'], 'destination_lot' => $info['lot_code']]);
            }
            if ($type === 'PINDAH_KAVLING' && $parent['lot_code'] === $info['lot_code']) {
                $this->issue('warning', 'transfer_link_mismatch', 'PINDAH KAVLING source and destination occupy the same lot.', 'PSJB', $info['row'], $key, ['lot' => $info['lot_code']]);
            }
        }
    }

    private function deriveBookings(): void
    {
        $currentCandidates = [];
        $protectedLots = [];
        $holdersSet = 0;

        foreach ($this->bookingRows as $key => $info) {
            $events = $this->stageEvents[$key] ?? [];
            $outcome = null;
            $outcomeStage = null;
            $outcomeDate = null;
            $outcomeNote = null;
            $highestStage = 'PSJB';
            $highestIndex = 0;
            $akadDate = null;
            $bastDate = null;
            $needsReview = false;
            $lastBankResult = null;

            $stageStatus = $info['status'];
            $normalizedStatus = strtoupper(trim((string) $stageStatus));
            $isTransferDestination = (bool) $info['parent_transaction'];
            if (! $isTransferDestination && in_array($normalizedStatus, ['GANTI NAMA', 'PINDAH KAVLING', 'MUNDUR', 'BATAL'], true)) {
                $outcomeStage = 'PSJB';
                $outcomeDate = $info['date'];
                $outcome = match ($normalizedStatus) {
                    'GANTI NAMA' => Booking::STATUS_REPLACED,
                    'PINDAH KAVLING' => Booking::STATUS_MOVED,
                    'BATAL' => Booking::STATUS_BATAL,
                    default => Booking::STATUS_MUNDUR,
                };
                $outcomeNote = $normalizedStatus;
            } elseif (in_array($normalizedStatus, ['REJECT BANK', 'REJECT SLIK'], true)) {
                $this->issue('warning', 'unmapped_psjb_status', "PSJB status [{$normalizedStatus}] is not a booking decision; bank decisions are stored separately.", 'PSJB', $info['row'], $key);
            } elseif ($normalizedStatus === 'PERLU CEK') {
                $needsReview = true;
            }

            foreach ($events as $event) {
                $stageIndex = array_search($event['stage'], Booking::STAGES, true);
                $stageIndex = $stageIndex === false ? 0 : $stageIndex;
                if ($stageIndex >= $highestIndex) {
                    $highestIndex = $stageIndex;
                    $highestStage = $event['stage'];
                }
                if ($event['stage'] === 'AKAD' && $event['date']) {
                    $akadDate = $event['date'];
                }
                if ($event['stage'] === 'BAST' && $event['date']) {
                    $bastDate = $event['date'];
                }
                if ($event['stage'] === 'PROSES_BANK' && ! empty($event['source_status'])) {
                    $lastBankResult = strtoupper((string) $event['source_status']);
                }

                if ($outcome && $stageIndex > ($outcomeStage ? (int) array_search($outcomeStage, Booking::STAGES, true) : -1)) {
                    // Keep the earliest terminal event; do not let later stages overwrite it.
                    continue;
                }

                $candidate = null;
                if ($event['cancelled']) {
                    $candidate = Booking::STATUS_BATAL;
                } elseif ($event['withdrawn']) {
                    $candidate = Booking::STATUS_MUNDUR;
                } elseif ($event['name_change']) {
                    $candidate = Booking::STATUS_REPLACED;
                } elseif ($event['moved']) {
                    $candidate = Booking::STATUS_MOVED;
                }

                if ($candidate && ! $outcome) {
                    $outcome = $candidate;
                    $outcomeStage = $event['stage'];
                    $outcomeDate = $event['date'] ?? $info['date'];
                    $outcomeNote = $event['notes'] ?: ($event['source_status'] ?? null) ?: $candidate;
                }
            }

            // A PSJB status is often a computed roll-up. When a later tab records
            // the actual action (Mundur, Batal, Ganti Nama, Pindah Kavling), use
            // that tab and its date as the decision stage.
            if ($outcome && $outcomeStage === 'PSJB') {
                $flag = match ($outcome) {
                    Booking::STATUS_MUNDUR => 'withdrawn',
                    Booking::STATUS_BATAL => 'cancelled',
                    Booking::STATUS_REPLACED => 'name_change',
                    Booking::STATUS_MOVED => 'moved',
                    default => null,
                };
                if ($flag) {
                    foreach ($events as $event) {
                        if ($event['stage'] !== 'PSJB' && ! empty($event[$flag])) {
                            $outcomeStage = $event['stage'];
                            $outcomeDate = $event['date'] ?? $outcomeDate;
                            $outcomeNote = $event['notes'] ?: ($event['source_status'] ?? null) ?: $outcomeNote;
                            break;
                        }
                    }
                }
            }

            $status = $outcome
                ? $outcome
                : ($bastDate ? Booking::STATUS_COMPLETED : Booking::STATUS_ACTIVE);

            if (! $outcome && $needsReview) {
                $status = Booking::STATUS_NEEDS_REVIEW;
            }

            if ($status === Booking::STATUS_ACTIVE && $highestStage === 'BAST') {
                $status = Booking::STATUS_COMPLETED;
            }

            if (! $outcome && in_array($lastBankResult, ['REJECT', 'REVISI'], true) && $status !== Booking::STATUS_COMPLETED) {
                $this->issue('warning', 'bank_reject_without_booking_decision', 'Latest bank decision is ' . $lastBankResult . ' but the booking has no withdraw/cancel decision. The bank decision is stored separately and the booking needs review.', 'Proses Bank', $info['row'], $key, ['bank_result' => $lastBankResult]);
            }

            $attributes = [
                'status' => $status,
                'current_stage' => $highestStage,
                'decision_stage' => $outcome ? $outcomeStage : null,
                'decision_date' => $outcome ? $outcomeDate : null,
                'decision_note' => $outcome ? $outcomeNote : null,
                'akad_date' => $akadDate,
                'bast_date' => $bastDate,
                'transfer_type' => $info['transfer_type'],
            ];

            $bookingProtected = isset($this->protectedBookings[$key])
                || isset($this->protectedBankBookings[$key])
                || isset($this->protectedPpjbBookings[$key])
                || isset($this->protectedAkadBookings[$key])
                || isset($this->protectedBastBookings[$key]);
            $effectiveStatus = $bookingProtected
                ? $info['booking']->status
                : $status;

            if ($bookingProtected) {
                $attributes = [];
            }

            if ($this->dryRun) {
                $info['booking'] = clone $info['booking'];
                $info['booking']->fill($attributes);
            } else {
                $info['booking']->update($attributes);
            }
            $this->bookingRows[$key]['booking'] = $info['booking'];

            if ($bookingProtected) {
                $protectedLots[$info['lot_code']] = true;
            }

            if (in_array($effectiveStatus, [Booking::STATUS_ACTIVE, Booking::STATUS_COMPLETED, Booking::STATUS_NEEDS_REVIEW], true)) {
                $currentCandidates[$info['lot_code']][] = $key;
            }
        }

        foreach ($currentCandidates as $lotCode => $keys) {
            usort($keys, function ($a, $b) {
                $da = $this->bookingRows[$a]['date'];
                $db = $this->bookingRows[$b]['date'];
                $cmp = ($db?->timestamp ?? 0) <=> ($da?->timestamp ?? 0);

                return $cmp !== 0 ? $cmp : $b <=> $a;
            });
            $holder = $keys[0];

            $competingSheetsKeys = array_values(array_filter($keys, fn (string $key): bool => ! isset($this->protectedBookings[$key]) && ! isset($this->protectedBankBookings[$key]) && ! isset($this->protectedPpjbBookings[$key])));
            if (isset($protectedLots[$lotCode])) {
                if ($competingSheetsKeys !== []) {
                    $this->issue('warning', 'import_conflict', 'A Sheets booking competes with a Laravel-owned booking on this lot. Current holder flags were preserved for review.', 'PSJB', $this->bookingRows[$competingSheetsKeys[0]]['row'], $competingSheetsKeys[0], ['lot' => $lotCode, 'bookings' => count($keys)]);
                    if (! $this->dryRun) {
                        Booking::whereIn('source_booking_key', $competingSheetsKeys)->update(['is_current_holder' => false, 'status' => Booking::STATUS_NEEDS_REVIEW]);
                    }
                }

                continue;
            }

            if (count($keys) > 1) {
                $this->issue('warning', 'multiple_active_bookings', 'More than one open booking exists for this lot. The latest booking is treated as the current holder.', 'PSJB', $this->bookingRows[$holder]['row'], $holder, ['lot' => $lotCode, 'bookings' => count($keys)]);
            }

            if (! $this->dryRun) {
                Booking::whereIn('source_booking_key', $keys)->update(['is_current_holder' => false]);
                $this->bookingRows[$holder]['booking']->update(['is_current_holder' => true]);
                $holdersSet++;
            }
            foreach ($keys as $key) {
                $this->bookingRows[$key]['is_current_holder'] = $key === $holder;
            }
        }

        $this->stats['current_holders'] = $holdersSet;
    }

    private function applyHistoricalAkad(array $lots): void
    {
        foreach ($lots as $code => $lot) {
            $keys = array_keys(array_filter($this->bookingRows, fn ($info) => $info['lot_code'] === $code));
            $akad2025 = false;
            $akad2026 = false;

            if ($keys) {
                $akadDates = AkadRecord::query()
                    ->whereIn('booking_id', array_map(fn ($key) => $this->bookingRows[$key]['booking']->id, $keys))
                    ->pluck('akad_date')
                    ->filter();
                foreach ($akadDates as $date) {
                    $year = (int) CarbonImmutable::parse($date)->format('Y');
                    $akad2025 = $akad2025 || $year <= 2025;
                    $akad2026 = $akad2026 || $year >= 2026;
                }
            }

            $marker = (bool) $lot->historical_akad_before_2026;
            $historical = $marker || $akad2025;
            $opening = 'AKAD_HISTORIS';
            $source = match (true) {
                $marker && $akad2025 => 'Data Kav marker + Akad record',
                $marker => 'Data Kav marker only',
                $akad2025 => 'Akad record before 2026',
                default => null,
            };

            if ($marker && ! $akad2025) {
                $this->issue('info', 'historical_marker_without_akad', 'Lot is marked Sudah Akad s.d. 2025 but no pre-2026 Akad record was found; it is still excluded from available stock.', 'Data Kav', $lot->source_row, $code);
            }
            if (! $marker && $akad2025) {
                $this->issue('info', 'akad_2025_without_marker', 'A pre-2026 Akad record exists but the Data Kav historical marker is not set; the lot is treated as historical inventory.', 'Akad', null, $code);
            }
            if ($marker && $akad2026) {
                $this->issue('warning', 'historical_marker_conflicts_2026', 'Lot is marked Sudah Akad s.d. 2025 but also has a 2026 Akad record. Review the marker and the re-sale history.', 'Data Kav', $lot->source_row, $code);
            }

            if (! $this->dryRun) {
                $lot->update([
                    'historical_akad_before_2026' => $historical,
                    'opening_status' => $historical ? $opening : null,
                    'historical_marker' => $source,
                ]);
            }
        }
    }

    private function parseHqExport(array $rows, array $lots): void
    {
        $metrics = [];
        $unitIds = [];
        $metadata = [];
        $unitHeaderSeen = false;

        foreach ($rows as $row) {
            $row = array_values((array) $row);
            $first = Normalizer::text($row[0] ?? null);
            $second = Normalizer::text($row[1] ?? null);
            $third = Normalizer::text($row[2] ?? null);
            $idKavling = Normalizer::text($row[23] ?? null);
            $position = Normalizer::text($row[24] ?? null);

            if ($idKavling && strtolower((string) $idKavling) !== 'id_kavling') {
                $unitIds[] = $idKavling;
                $code = Normalizer::lotCode($idKavling, $this->project->name);
                if ($code && $position) {
                    $this->hqPositions[$code] = strtoupper($position);
                }
            }
            if ($position && strtolower((string) $position) === 'posisi_rumah') {
                $unitHeaderSeen = true;
            }

            if ($first && in_array($first, ['branch_code', 'branch_name', 'project_id', 'project_name', 'schema_version', 'source_spreadsheet_id'], true)) {
                $metadata[$first] = $second;
            }

            if ($first && $third !== null && in_array($first, config('marison.hq_export_metrics', []), true)) {
                $metrics[$first] = Normalizer::number($third);
            }
        }

        $codes = [];
        foreach ($unitIds as $id) {
            $code = Normalizer::lotCode($id, $this->project->name);
            if ($code) {
                $codes[$code] = true;
            }
        }

        $omitted = array_values(array_diff(array_keys($lots), array_keys($codes)));
        if ($omitted !== []) {
            $this->issue('warning', 'hq_export_lot_omitted', count($omitted) . ' coded lot(s) are absent from the hq_report_export unit list; the export may use a fixed range.', 'hq_report_export', null, null, ['lots' => array_slice($omitted, 0, 25), 'count' => count($omitted)]);
        }

        $this->stats['hq_export'] = [
            'metadata' => $metadata,
            'metrics' => $metrics,
            'unit_ids' => count($codes),
            'unit_headers_seen' => $unitHeaderSeen,
            'omitted_lots' => count($omitted),
            'omitted_examples' => array_slice($omitted, 0, 10),
        ];
    }

    private function optionalRowCount(string $tabName, array $tabsByName, array $rawTabs): ?int
    {
        if (! isset($rawTabs[$tabName])) {
            return null;
        }
        $tab = $tabsByName[$tabName] ?? new Tab($tabName, $rawTabs[$tabName]);

        return count($tab->records());
    }

    private function readOptionalTab(string $tabName, array $tabsByName, array $rawTabs): ?array
    {
        if (isset($rawTabs[$tabName])) {
            return $rawTabs[$tabName];
        }

        try {
            $data = $this->reader->read([$tabName]);

            return $data[$tabName] ?? null;
        } catch (\Throwable $e) {
            if ($this->reader instanceof ArraySheetReader || $this->reader instanceof FixtureSheetReader) {
                return null;
            }
            $this->issue('warning', 'optional_tab_unavailable', "Optional tab [{$tabName}] could not be read: " . mb_substr($e->getMessage(), 0, 200), $tabName);

            return null;
        }
    }

    private function recordEvent(string $bookingKey, string $stage, ?CarbonImmutable $date, array $flags): void
    {
        $this->stageEvents[$bookingKey][] = array_merge([
            'stage' => $stage,
            'date' => $date,
        ], $flags);
    }

    private function checkUnmappedDecision(string $bookingKey, string $tab, int $row, string $sourceKey, ?string $decision): void
    {
        if (! $decision) {
            return;
        }
        $value = strtoupper(trim($decision));
        if (! in_array($value, ['LANJUT', 'MUNDUR', 'BATAL', 'GANTI NAMA', 'PINDAH KAVLING', 'REJECT', 'APPROVE', 'REVISI', 'OK'], true)) {
            $this->issue('warning', 'unmapped_decision', "Unrecognized decision [{$decision}] was preserved but not mapped to a booking outcome.", $tab, $row, $sourceKey);
        }
    }

    private function normalizeBankResult(?string $result): ?string
    {
        if (! $result) {
            return null;
        }
        $value = strtoupper(trim($result));

        return match (true) {
            str_contains($value, 'APPROVE'), str_contains($value, 'SETUJU') => 'APPROVE',
            str_contains($value, 'REJECT'), str_contains($value, 'TOLAK') => 'REJECT',
            str_contains($value, 'REVISI') => 'REVISI',
            default => $value,
        };
    }

    private function dateValue(Tab $tab, array $row, string|array $aliases, string $tabName, int $rowNumber): ?CarbonImmutable
    {
        $raw = $tab->value($row, $aliases);
        $date = Normalizer::date($raw);
        if (! $date && ! Normalizer::blankPlaceholder($raw)) {
            $this->issue('warning', 'unparseable_date', 'Date value could not be parsed: ' . mb_substr((string) $raw, 0, 80), $tabName, $rowNumber);
        }

        return $date;
    }

    private function upsert(string $modelClass, string $sourceKey, array $attributes, string $statKey, string $keyColumn = 'source_key'): object
    {
        /** @var \Illuminate\Database\Eloquent\Model|null $existing */
        $existing = $modelClass::query()->where($keyColumn, $sourceKey)->first();
        $attributes[$keyColumn] = $sourceKey;

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();
            $this->increment($statKey, 'updated');

            return $existing;
        }

        $model = $modelClass::query()->create($attributes);
        $this->increment($statKey, 'created');

        return $model;
    }

    private function increment(string $statKey, string $counter): void
    {
        $this->stats[$statKey][$counter] = ($this->stats[$statKey][$counter] ?? 0) + 1;
    }

    private function reportProtectedConflict(?Booking $booking, array $source, string $tab, int $row, string $sourceKey): void
    {
        if (! $booking) {
            return;
        }

        $fields = [
            'lot_id', 'buyer_id', 'psjb_number', 'booking_date', 'payment_method', 'sales_name',
            'coordinator_name', 'base_price', 'status', 'current_stage', 'decision_stage',
            'decision_date', 'decision_note', 'is_current_holder', 'akad_date', 'bast_date',
        ];
        $differences = [];
        foreach ($fields as $field) {
            $current = $booking->getAttribute($field);
            $incoming = $source[$field] ?? null;
            if ((string) $current !== (string) $incoming) {
                $differences[$field] = ['current' => $current, 'source' => $incoming];
            }
        }

        $psjb = $booking->psjb;
        if ($psjb) {
            foreach (['psjb_number', 'psjb_date', 'sales_name', 'coordinator_name', 'price', 'utj_date', 'utj_amount', 'dp_all_in', 'installment_count', 'payment_method', 'source_status', 'notes'] as $field) {
                $current = $psjb->getAttribute($field);
                $incoming = $source['psjb_' . $field] ?? null;
                if ($incoming !== null && (string) $current !== (string) $incoming) {
                    $differences['psjb.' . $field] = ['current' => $current, 'source' => $incoming];
                }
            }
        }

        if ($differences !== []) {
            $this->issue('warning', 'import_conflict', 'Laravel-owned booking differs from Sheets. Database values were preserved.', $tab, $row, $sourceKey, ['fields' => array_keys($differences), 'values' => array_map(fn (array $difference) => ['current' => $this->safeConflictValue($difference['current']), 'source' => $this->safeConflictValue($difference['source'])], $differences)]);
        }
    }

    private function reportPemberkasanConflict(?PemberkasanRecord $record, array $source, string $tab, int $row, string $sourceKey): void
    {
        if (! $record) {
            return;
        }

        $fields = ['file_number', 'received_date', 'bank', 'bank_unit', 'status', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag', 'notes'];
        $differences = [];
        foreach ($fields as $field) {
            $current = $record->getAttribute($field);
            $incoming = $source[$field] ?? null;
            if ((string) $current !== (string) $incoming) {
                $differences[$field] = [
                    'current' => $this->safeConflictValue($current),
                    'source' => $this->safeConflictValue($incoming),
                ];
            }
        }

        if ($differences !== []) {
            $this->issue('warning', 'import_conflict', 'Laravel-owned Pemberkasan differs from Sheets. Database values were preserved.', $tab, $row, $sourceKey, ['fields' => array_keys($differences), 'values' => $differences]);
        }
    }

    private function reportBiConflict(?BiCheck $bi, array $source, string $tab, int $row, string $sourceKey): void
    {
        if (! $bi) {
            return;
        }

        $fields = ['check_date', 'result', 'decision', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag', 'notes'];
        $differences = [];
        foreach ($fields as $field) {
            $current = $bi->getAttribute($field);
            $incoming = $source[$field] ?? null;
            if ((string) $current !== (string) $incoming) {
                $differences[$field] = [
                    'current' => $this->safeConflictValue($current),
                    'source' => $this->safeConflictValue($incoming),
                ];
            }
        }

        if ($differences !== []) {
            $this->issue('warning', 'import_conflict', 'Laravel-owned BI Checking differs from Sheets. Database values were preserved.', $tab, $row, $sourceKey, ['fields' => array_keys($differences), 'values' => $differences]);
        }
    }

    private function reportBankConflict(BankSubmission $submission, array $source, string $tab, int $row, string $sourceKey): void
    {
        $fields = ['booking_id', 'project_id', 'pemberkasan_id', 'file_number', 'decision_number', 'submission_sequence', 'bank', 'bank_unit', 'submitted_date', 'decision_date', 'result', 'approved_amount', 'tenor', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag', 'notes'];
        $differences = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $source)) {
                continue;
            }
            $current = $submission->getAttribute($field);
            $incoming = $source[$field];
            if ($this->comparableConflictValue($current) === $this->comparableConflictValue($incoming)) {
                continue;
            }
            $differences[$field] = [
                'current' => $this->safeConflictValue($current),
                'source' => $this->safeConflictValue($incoming),
            ];
        }

        if ($differences !== []) {
            $this->issue('warning', 'import_conflict', 'Laravel-owned Proses Bank differs from Sheets. Database values were preserved.', $tab, $row, $sourceKey, ['fields' => array_keys($differences), 'values' => $differences]);
        }
    }

    private function reportPpjbConflict(?PpjbRecord $record, array $source, string $tab, int $row, string $sourceKey): void
    {
        if (! $record) {
            return;
        }

        $fields = ['ppjb_number', 'signed_date', 'dp_status', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag', 'notes'];
        $differences = [];
        foreach ($fields as $field) {
            $current = $record->getAttribute($field);
            $incoming = $source[$field] ?? null;
            if ($this->comparableConflictValue($current) === $this->comparableConflictValue($incoming)) {
                continue;
            }
            $differences[$field] = [
                'current' => $this->safeConflictValue($current),
                'source' => $this->safeConflictValue($incoming),
            ];
        }

        if ($differences !== []) {
            $this->issue('warning', 'import_conflict', 'Laravel-owned PPJB Developer differs from Sheets. Database values were preserved.', $tab, $row, $sourceKey, ['fields' => array_keys($differences), 'values' => $differences]);
        }
    }

    private function reportAkadConflict(?AkadRecord $record, array $source, string $tab, int $row, string $sourceKey): void
    {
        if (! $record) {
            return;
        }

        $fields = [
            'booking_id', 'project_id', 'ppjb_id', 'akad_number', 'akad_date', 'quality',
            'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag', 'notes',
        ];
        $differences = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $source)) {
                continue;
            }
            $current = $record->getAttribute($field);
            $incoming = $source[$field];
            if ($this->comparableConflictValue($current) === $this->comparableConflictValue($incoming)) {
                continue;
            }
            $differences[$field] = [
                'current' => $this->safeConflictValue($current),
                'source' => $this->safeConflictValue($incoming),
            ];
        }

        if ($differences !== []) {
            $this->issue('warning', 'import_conflict', 'Laravel-owned Akad differs from Sheets. Database values were preserved.', $tab, $row, $sourceKey, ['fields' => array_keys($differences), 'values' => $differences]);
        }
    }

    private function reportBastConflict(?BastRecord $record, array $source, string $tab, int $row, string $sourceKey): void
    {
        if (! $record) {
            return;
        }

        $fields = ['booking_id', 'project_id', 'akad_id', 'bast_number', 'bast_date', 'notes'];
        $differences = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $source)) {
                continue;
            }
            $current = $record->getAttribute($field);
            $incoming = $source[$field];
            if ($this->comparableConflictValue($current) === $this->comparableConflictValue($incoming)) {
                continue;
            }
            $differences[$field] = [
                'current' => $this->safeConflictValue($current),
                'source' => $this->safeConflictValue($incoming),
            ];
        }

        if ($differences !== []) {
            $this->issue('warning', 'import_conflict', 'Laravel-owned BAST differs from Sheets. Database values were preserved.', $tab, $row, $sourceKey, ['fields' => array_keys($differences), 'values' => $differences]);
        }
    }

    private function comparableConflictValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        return (string) $value;
    }

    private function safeConflictValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return '[redacted]';
    }

    private function hashRow(array $row): string
    {
        return hash('sha256', json_encode(array_map(fn ($v) => is_scalar($v) || $v === null ? $v : null, $row)));
    }

    private function issue(string $severity, string $code, string $message, ?string $tab = null, ?int $row = null, ?string $sourceKey = null, array $context = []): void
    {
        $key = implode('|', [$severity, $code, $tab, $row, $sourceKey, $message]);
        if (isset($this->issueKeys[$key])) {
            return;
        }
        $this->issueKeys[$key] = $key;
        $this->issues[] = [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'source_tab' => $tab,
            'source_row' => $row,
            'source_key' => $sourceKey,
            'context' => $context ?: null,
        ];
    }

    private function issueCounts(): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($this->issues as $issue) {
            $counts[$issue['severity']] = ($counts[$issue['severity']] ?? 0) + 1;
        }

        return $counts;
    }

    private function persistIssues(): void
    {
        ImportIssue::query()
            ->where('project_id', $this->project->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        if ($this->issues === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn ($issue) => array_merge($issue, [
            'import_batch_id' => $this->batch?->id,
            'project_id' => $this->project->id,
            'context' => $issue['context'] ? json_encode($issue['context'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]), $this->issues);

        foreach (array_chunk($rows, 200) as $chunk) {
            ImportIssue::query()->insert($chunk);
        }
    }
}
