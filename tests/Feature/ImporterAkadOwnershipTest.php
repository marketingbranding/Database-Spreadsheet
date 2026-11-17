<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\Booking;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class ImporterAkadOwnershipTest extends FixtureProjectTestCase
{
    public function test_laravel_owned_akad_survives_stale_sheets_and_protects_booking_rollups(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $record = AkadRecord::query()->whereNotNull('akad_date')->firstOrFail();
        $booking = $record->booking;
        $record->update([
            'source_owner' => AkadRecord::OWNER_LARAVEL,
            'source_key' => 'LARAVEL:AKAD:ownership-test',
            'akad_number' => 'AKD-LARAVEL-01',
            'akad_date' => '2026-11-18',
            'quality' => 'Laravel Quality',
            'notes' => 'Protected Akad',
            'idempotency_key' => 'akad-import-protection',
        ]);
        $booking->update([
            'source_owner' => Booking::OWNER_LARAVEL,
            'current_stage' => Booking::STAGE_AKAD,
            'akad_date' => '2026-11-18',
        ]);

        $report = (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project->fresh(), false);

        $fresh = $record->fresh();
        $freshBooking = $booking->fresh();
        $this->assertSame(AkadRecord::OWNER_LARAVEL, $fresh->source_owner);
        $this->assertSame('AKD-LARAVEL-01', $fresh->akad_number);
        $this->assertSame('Laravel Quality', $fresh->quality);
        $this->assertSame('akad-import-protection', $fresh->idempotency_key);
        $this->assertSame(Booking::STAGE_AKAD, $freshBooking->current_stage);
        $this->assertSame('2026-11-18', $freshBooking->akad_date?->toDateString());
        $this->assertTrue(collect($report->issues)->contains(
            fn (array $issue) => $issue['code'] === 'import_conflict'
                && in_array('akad_number', $issue['context']['fields'] ?? [], true)
        ));
    }

    public function test_imported_akad_links_to_exact_ppjb_for_same_booking(): void
    {
        $record = AkadRecord::query()->whereNotNull('akad_date')->firstOrFail();

        $this->assertNotNull($record->ppjb_id);
        $this->assertSame($record->booking_id, $record->ppjb->booking_id);
        $this->assertSame($record->project_id, $record->ppjb->project_id);
    }
}
