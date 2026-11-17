<?php

namespace Tests\Feature;

use App\Models\BastRecord;
use App\Models\Booking;
use App\Models\ImportBatch;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;
use App\Services\Metrics\PortfolioMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Regression suite against an anonymized fixture of the Kuwasen (Jepara)
 * pilot workbook. Structure and identifiers match the live sheet; all
 * personal data is anonymized.
 */
class ImporterRegressionTest extends FixtureProjectTestCase
{
    public function test_reimport_creates_no_duplicates(): void
    {
        $before = [
            'lots' => Lot::query()->count(),
            'bookings' => Booking::query()->count(),
            'batches' => ImportBatch::query()->count(),
        ];

        $report = (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project, false);

        foreach (['lots', 'buyers', 'bookings', 'psjb_records', 'bi_checks', 'pemberkasan_records',
            'bank_submissions', 'ppjb_records', 'akad_records', 'bast_records'] as $entity) {
            $this->assertSame(0, $report->created($entity), "Reimport must not create new {$entity}.");
        }

        $this->assertSame($before['lots'], Lot::query()->count());
        $this->assertSame($before['bookings'], Booking::query()->count());
        $this->assertSame($before['batches'] + 1, ImportBatch::query()->count());
    }

    public function test_dry_run_commits_nothing(): void
    {
        $second = Project::query()->create([
            'branch_id' => $this->project->branch_id,
            'code' => 'PRJ-JPR-DRY',
            'name' => 'Dry run target',
            'is_active' => true,
        ]);

        (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($second, true);

        $this->assertSame(0, Lot::query()->where('project_id', $second->id)->count());
        $this->assertSame(0, Booking::query()->where('project_id', $second->id)->count());
        $this->assertSame(0, ImportBatch::query()->where('project_id', $second->id)->count());

        // The applied import from setUp is untouched.
        $this->assertGreaterThan(0, Lot::query()->where('project_id', $this->project->id)->count());
    }

    public function test_g09_two_cycles_share_legacy_id_but_resolve_correctly(): void
    {
        $lot = Lot::query()->where('code', 'G09')->first();
        $this->assertNotNull($lot, 'Fixture must contain lot G09.');

        $bookings = Booking::query()->where('lot_id', $lot->id)->get();
        $this->assertCount(2, $bookings, 'G09 April and September cycles are separate PSJB instances.');

        // April cycle: withdrawal recorded at Proses Bank even though PSJB says LANJUT.
        $april = $bookings->firstWhere('status', Booking::STATUS_MUNDUR);
        $this->assertNotNull($april, 'The withdrawn April cycle must exist.');
        $this->assertSame(Booking::STAGE_BANK, $april->decision_stage);
        $this->assertSame('2026-04-15', $april->decision_date?->toDateString());
        $this->assertFalse($april->is_current_holder);

        // September cycle: completed through Akad + BAST on 2026-09-30, current holder.
        $september = $bookings->firstWhere('status', Booking::STATUS_COMPLETED);
        $this->assertNotNull($september, 'The completed September cycle must exist.');
        $this->assertSame('2026-09-30', $september->akad_date?->toDateString());
        $this->assertSame('2026-09-30', $september->bast_date?->toDateString());
        $this->assertTrue($september->is_current_holder);

        $this->assertSame(
            1,
            Booking::query()->where('lot_id', $lot->id)->where('is_current_holder', true)->count(),
            'Lot G09 must have exactly one current holder.',
        );
    }

    public function test_k20_chain_is_preserved_and_counted_once(): void
    {
        $lot = Lot::query()->where('code', 'K20')->first();
        $this->assertNotNull($lot, 'Fixture must contain lot K20.');

        $bookings = Booking::query()->where('lot_id', $lot->id)->orderBy('booking_date')->get();

        $statuses = $bookings->pluck('status')->all();
        $this->assertSame(
            [Booking::STATUS_MOVED, Booking::STATUS_REPLACED, Booking::STATUS_MUNDUR,
                Booking::STATUS_MUNDUR, Booking::STATUS_COMPLETED],
            array_values($statuses),
            'K20 chain: Rikki moved, replacement replaced, two withdrawals, final completed holder.',
        );

        $holders = $bookings->where('is_current_holder', true);
        $this->assertCount(1, $holders);
        $this->assertSame(Booking::STATUS_COMPLETED, $holders->first()->status);

        // The completed booking sits at BAST and counts the lot once.
        $this->assertSame('SOLD', $lot->inventoryPosition());
    }

    public function test_historical_2025_akad_lots_excluded_from_available_stock(): void
    {
        $historical = Lot::query()->where('historical_akad_before_2026', true)->count();
        $this->assertSame(82, $historical, 'Kuwasen fixture has 82 "Sudah Akad s.d. 2025" markers.');

        $summary = PortfolioMetrics::forProjects(collect([$this->project]))->summary();

        // Historical lots count in inventory but not in available stock.
        $this->assertSame(260, $summary['total_lots']);
        $occupied = $summary['occupied'];
        $this->assertSame(260 - $historical - $occupied, $summary['available']);

        // Historical markers were akad'd before 2026; they count in inventory
        // but never in available stock (already asserted via the subtraction
        // above). Their completed bookings must not change available stock.
        $positions = PortfolioMetrics::forProjects(collect([$this->project]))->positions();
        $positionTotal = array_sum(array_column($positions, 'total'));
        $this->assertSame(260, $positionTotal);
        $this->assertSame(82, collect($positions)->firstWhere('key', 'historical')['total']);
    }

    public function test_bast_2026_counted_even_when_akad_was_before_2026(): void
    {
        $crossYear = BastRecord::query()
            ->whereYear('bast_date', 2026)
            ->whereHas('booking.akad', fn ($q) => $q->whereYear('akad_date', '<', 2026))
            ->count();
        $this->assertGreaterThan(0, $crossYear, 'Fixture must contain BAST-2026 after a pre-2026 Akad.');

        $metrics = PortfolioMetrics::forProjects(
            collect([$this->project]),
            CarbonImmutable::create(2026, 1, 1),
            CarbonImmutable::create(2026, 12, 31)->endOfDay(),
        );
        $summary = $metrics->summary();

        // Period actuals are event dates: every 2026 BAST counts, regardless of Akad year.
        $bastInPeriod = BastRecord::query()->whereYear('bast_date', 2026)->count();
        $this->assertSame($bastInPeriod, $summary['bast']);

        // Pre-2026 Akad must not leak into 2026 Akad actuals.
        $akadInPeriod = DB::table('akad_records')
            ->where('project_id', $this->project->id)
            ->whereYear('akad_date', 2026)
            ->count();
        $this->assertSame($akadInPeriod, $summary['akad']);
    }
}
