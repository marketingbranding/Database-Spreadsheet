<?php

namespace Tests\Feature;

use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class ImporterBiOwnershipTest extends FixtureProjectTestCase
{
    public function test_laravel_bi_decision_survives_stale_sheets_import(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $bi = BiCheck::query()->where('project_id', $this->project->id)->firstOrFail();
        $booking = $bi->booking;
        $bi->update([
            'source_owner' => BiCheck::OWNER_LARAVEL,
            'check_date' => '2026-11-18',
            'result' => 'Staging result',
            'decision' => 'TIDAK LANJUT',
            'notes' => 'Staging note',
        ]);
        $booking->update(['source_owner' => Booking::OWNER_LARAVEL, 'current_stage' => Booking::STAGE_BI]);

        $report = (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), false);

        $fresh = $bi->fresh();
        $this->assertSame(BiCheck::OWNER_LARAVEL, $fresh->source_owner);
        $this->assertSame('TIDAK LANJUT', $fresh->decision);
        $this->assertSame('Staging note', $fresh->notes);
        $this->assertSame('2026-11-18', $fresh->check_date->toDateString());
        $this->assertSame(Booking::STAGE_BI, $booking->fresh()->current_stage);
        $this->assertTrue(collect($report->issues)->contains(fn (array $issue) => $issue['code'] === 'import_conflict'));
    }
}
