<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class ImporterPpjbOwnershipTest extends FixtureProjectTestCase
{
    public function test_stale_sheets_preserves_laravel_signature_and_reports_conflict_without_duplicate(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $record = PpjbRecord::query()->firstOrFail();
        $booking = $record->booking;
        $booking->update([
            'source_owner' => Booking::OWNER_LARAVEL,
            'current_stage' => Booking::STAGE_PPJB,
        ]);
        $record->update([
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
            'source_key' => 'LARAVEL:PPJB:ownership-test',
            'ppjb_number' => 'LARAVEL-SIGNED-PPJB',
            'signed_date' => '2026-11-17',
            'notes' => 'Protected signature',
            'idempotency_key' => 'ppjb-import-protection',
        ]);
        $before = $record->fresh()->getAttributes();
        $count = PpjbRecord::query()->count();

        $report = (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project->fresh(), false);

        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame($count, PpjbRecord::query()->count());
        $this->assertSame(Booking::STAGE_PPJB, $booking->fresh()->current_stage);
        $this->assertTrue(collect($report->issues)->contains(
            fn (array $issue) => $issue['code'] === 'import_conflict'
                && in_array('ppjb_number', $issue['context']['fields'] ?? [], true)
        ));
    }
}
