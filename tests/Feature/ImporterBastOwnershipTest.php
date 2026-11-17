<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\BastRecord;
use App\Models\Booking;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class ImporterBastOwnershipTest extends FixtureProjectTestCase
{
    public function test_laravel_owned_bast_survives_stale_sheets_and_protects_booking_rollups(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $record = BastRecord::query()->whereNotNull('bast_date')->firstOrFail();
        $booking = $record->booking;
        $akad = AkadRecord::query()->where('booking_id', $booking->id)->firstOrFail();
        $record->update([
            'akad_id' => $akad->id,
            'source_owner' => BastRecord::OWNER_LARAVEL,
            'source_key' => 'LARAVEL:BAST:ownership-test',
            'bast_number' => 'BAST-LARAVEL-01',
            'bast_date' => '2026-11-20',
            'notes' => 'Protected BAST',
            'idempotency_key' => 'bast-import-protection',
        ]);
        $booking->update([
            'source_owner' => Booking::OWNER_LARAVEL,
            'current_stage' => Booking::STAGE_BAST,
            'status' => Booking::STATUS_COMPLETED,
            'bast_date' => '2026-11-20',
            'is_current_holder' => true,
        ]);

        $report = (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project->fresh(), false);

        $fresh = $record->fresh();
        $freshBooking = $booking->fresh();
        $this->assertSame(BastRecord::OWNER_LARAVEL, $fresh->source_owner);
        $this->assertSame($akad->id, $fresh->akad_id);
        $this->assertSame('BAST-LARAVEL-01', $fresh->bast_number);
        $this->assertSame('Protected BAST', $fresh->notes);
        $this->assertSame('bast-import-protection', $fresh->idempotency_key);
        $this->assertSame(Booking::STAGE_BAST, $freshBooking->current_stage);
        $this->assertSame(Booking::STATUS_COMPLETED, $freshBooking->status);
        $this->assertSame('2026-11-20', $freshBooking->bast_date?->toDateString());
        $this->assertTrue(collect($report->issues)->contains(
            fn (array $issue) => $issue['code'] === 'import_conflict'
                && in_array('bast_number', $issue['context']['fields'] ?? [], true)
        ));
    }

    public function test_imported_bast_links_to_exact_akad_for_same_booking_and_project(): void
    {
        $record = BastRecord::query()->whereNotNull('bast_date')->firstOrFail();
        $akad = AkadRecord::query()->where('booking_id', $record->booking_id)->firstOrFail();

        $this->assertNotNull($record->akad_id);
        $this->assertSame($akad->id, $record->akad_id);
        $this->assertSame($record->booking_id, $record->akad->booking_id);
        $this->assertSame($record->project_id, $record->akad->project_id);
    }
}
