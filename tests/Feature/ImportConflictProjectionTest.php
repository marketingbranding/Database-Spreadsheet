<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\ImportBatch;
use App\Models\ImportConflict;
use App\Models\ImportConflictBooking;
use App\Models\ImportIssue;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ImportConflictProjector;
use App\Services\Import\ProjectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportConflictProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_competing_claims_map_the_exact_project_booking_cycles_and_named_source_values(): void
    {
        [$project, $lot] = $this->projectWithLot('PRJ-JPR-KWS', 'M22');
        $older = $this->booking($project, $lot, 'PSJB:M22-OLD', false);
        $current = $this->booking($project, $lot, 'PSJB:M22-CURRENT', true, Booking::STATUS_NEEDS_REVIEW);
        [$otherProject, $otherLot] = $this->projectWithLot('PRJ-MGL-KLG', 'M22');
        $other = $this->booking($otherProject, $otherLot, 'PSJB:OTHER-M22-CURRENT', true);

        $issues = [[
            'severity' => 'warning',
            'code' => 'multiple_active_bookings',
            'message' => 'More than one open booking exists for this lot.',
            'source_tab' => 'PSJB',
            'source_row' => 2,
            'source_key' => 'PSJB:M22-CURRENT',
            'context' => ['lot' => 'M22', 'bookings' => 2],
        ]];
        $rawTabs = [
            'PSJB' => [
                ['Nama', 'Kavling', 'Status', '[SYS] ID Transaksi', '[AUTO] No. PSJB'],
                ['Norma', 'M22', 'PERLU CEK', 'TRX-M22', 'M22-CURRENT'],
            ],
        ];

        $descriptors = app(ImportConflictProjector::class)
            ->preview($project, $issues, $rawTabs, []);

        $this->assertCount(1, $descriptors);
        $descriptor = $descriptors[0];
        $this->assertSame(ImportConflict::CASE_COMPETING_CLAIMS, $descriptor['case_type']);
        $this->assertSame([$lot->id], $descriptor['lot_ids']);
        $this->assertEqualsCanonicalizing(
            [$older->id, $current->id],
            array_column($descriptor['booking_links'], 'booking_id')
        );
        $this->assertNotContains($other->id, array_column($descriptor['booking_links'], 'booking_id'));
        $this->assertSame('Norma', $descriptor['source_value']['Nama']);
        $this->assertSame('PERLU CEK', $descriptor['source_value']['Status']);
        $this->assertSame($current->id, $descriptor['laravel_value']['current_holder_booking_id']);
        $this->assertSame('BOOKED', $descriptor['inventory_impact']['laravel_position']);
    }

    public function test_orphan_bi_keeps_named_source_evidence_without_guessing_a_booking(): void
    {
        [$project] = $this->projectWithLot('PRJ-JPR-KWS', 'M22');
        $issues = [[
            'severity' => 'error',
            'code' => 'orphan_bi',
            'message' => 'BI Checking row could not be linked to a PSJB booking.',
            'source_tab' => 'BI Checking',
            'source_row' => 2,
            'source_key' => 'BI-',
            'context' => ['transaction' => null],
        ]];
        $rawTabs = [
            'BI Checking' => [
                ['Pilih Konsumen', 'Tanggal BI', 'Hasil SLIK', 'Keputusan', '[SYS] ID BI', '[SYS] ID Transaksi'],
                ['Norma | M22', '3 Sep 2026', 'KOL 5', 'REJECT', 'BI-', ''],
            ],
        ];

        $descriptor = app(ImportConflictProjector::class)
            ->preview($project, $issues, $rawTabs, [])[0];

        $this->assertSame(ImportConflict::CASE_UNLINKED_BI, $descriptor['case_type']);
        $this->assertSame([], $descriptor['booking_links']);
        $this->assertSame('BI Checking', $descriptor['source_tab']);
        $this->assertSame(2, $descriptor['source_row']);
        $this->assertSame('KOL 5', $descriptor['source_value']['Hasil SLIK']);
        $this->assertSame('No booking is linked; inventory is unchanged.', $descriptor['inventory_impact']['summary']);
    }

    public function test_stock_disagreement_is_detected_without_an_import_issue(): void
    {
        [$project, $lot] = $this->projectWithLot('PRJ-JPR-KWS', 'L02');

        $descriptors = app(ImportConflictProjector::class)
            ->preview($project, [], [], ['L02' => 'PROSES_BANK']);

        $this->assertCount(1, $descriptors);
        $descriptor = $descriptors[0];
        $this->assertSame(ImportConflict::CASE_STOCK_DISAGREEMENT, $descriptor['case_type']);
        $this->assertSame([$lot->id], $descriptor['lot_ids']);
        $this->assertSame('PROSES_BANK', $descriptor['source_value']['hq_position']);
        $this->assertSame('AVAILABLE', $descriptor['laravel_value']['inventory_position']);
        $this->assertTrue($descriptor['inventory_impact']['availability_disagrees']);
    }

    public function test_persist_links_the_latest_issue_lot_and_exact_booking_roles(): void
    {
        [$project, $lot] = $this->projectWithLot('PRJ-JPR-KWS', 'M22');
        $older = $this->booking($project, $lot, 'PSJB:M22-OLD', false);
        $current = $this->booking($project, $lot, 'PSJB:M22-CURRENT', true, Booking::STATUS_NEEDS_REVIEW);
        $batch = ImportBatch::query()->create([
            'project_id' => $project->id,
            'mode' => 'apply',
            'source' => 'fixture:test',
            'status' => 'running',
        ]);
        $issue = ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'project_id' => $project->id,
            'severity' => 'warning',
            'code' => 'multiple_active_bookings',
            'message' => 'More than one open booking exists for this lot.',
            'source_tab' => 'PSJB',
            'source_row' => 2,
            'source_key' => 'PSJB:M22-CURRENT',
            'context' => ['lot' => 'M22', 'bookings' => 2],
        ]);
        $rawTabs = [
            'PSJB' => [
                ['Nama', 'Kavling', 'Status'],
                ['Norma', 'M22', 'PERLU CEK'],
            ],
        ];

        app(ImportConflictProjector::class)->persist($project, $batch, $rawTabs, []);

        $conflict = ImportConflict::query()->firstOrFail();
        $this->assertSame($issue->id, $conflict->current_import_issue_id);
        $this->assertSame([$lot->id], $conflict->lots()->pluck('lots.id')->all());
        $this->assertDatabaseHas('import_conflict_bookings', [
            'import_conflict_id' => $conflict->id,
            'booking_id' => $older->id,
            'role' => ImportConflictBooking::ROLE_CLAIM,
        ]);
        $this->assertDatabaseHas('import_conflict_bookings', [
            'import_conflict_id' => $conflict->id,
            'booking_id' => $current->id,
            'role' => ImportConflictBooking::ROLE_CURRENT_HOLDER,
        ]);
    }

    public function test_projection_command_previews_then_applies_conflicts_without_operational_imports(): void
    {
        config(['app.env' => 'staging']);
        [$project] = $this->projectWithLot('PRJ-JPR-KWS', 'TEMP');
        $fixture = base_path('tests/Fixtures/kuwasen-regression.json');
        (new ProjectImporter(new FixtureSheetReader($fixture)))->run($project, false);
        ImportConflict::query()->where('project_id', $project->id)->delete();
        $bookingCount = Booking::query()->where('project_id', $project->id)->count();
        $lotCount = Lot::query()->where('project_id', $project->id)->count();

        $this->artisan('marison:conflicts:project', [
            'project' => $project->code,
            '--fixture' => $fixture,
        ])->assertSuccessful();
        $this->assertSame(0, ImportConflict::query()->where('project_id', $project->id)->count());

        $this->artisan('marison:conflicts:project', [
            'project' => $project->code,
            '--fixture' => $fixture,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertGreaterThan(0, ImportConflict::query()->where('project_id', $project->id)->count());
        $this->assertSame($bookingCount, Booking::query()->where('project_id', $project->id)->count());
        $this->assertSame($lotCount, Lot::query()->where('project_id', $project->id)->count());
    }

    private function projectWithLot(string $projectCode, string $lotCode): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-'.uniqid(),
            'name' => 'Branch',
        ]);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => $projectCode,
            'name' => $projectCode,
        ]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => $lotCode,
            'source_key' => $projectCode.':LOT:'.$lotCode,
        ]);

        return [$project, $lot];
    }

    private function booking(
        Project $project,
        Lot $lot,
        string $sourceKey,
        bool $holder,
        string $status = Booking::STATUS_ACTIVE,
    ): Booking {
        $buyer = Buyer::query()->create([
            'source_key' => $project->code.':BUYER:'.uniqid(),
            'name' => 'Buyer '.uniqid(),
        ]);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => $sourceKey,
            'source_transaction_id' => 'TRX-M22',
            'booking_date' => '2026-10-14',
            'payment_method' => 'KPR',
            'status' => $status,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => $holder,
            'source_owner' => Booking::OWNER_SHEETS,
        ]);
    }
}
