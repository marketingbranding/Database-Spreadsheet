<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class ImporterPemberkasanOwnershipTest extends FixtureProjectTestCase
{
    public function test_laravel_owned_pemberkasan_survives_stale_sheets_import(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $record = PemberkasanRecord::query()->where('project_id', $this->project->id)->firstOrFail();
        $record->update([
            'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
            'file_number' => 'LARAVEL-FILE',
            'received_date' => '2026-11-19',
            'bank' => 'Laravel Bank',
            'status' => 'DIPERIKSA',
            'notes' => 'Laravel note',
        ]);

        $report = (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), false);

        $fresh = $record->fresh();
        $this->assertSame(PemberkasanRecord::OWNER_LARAVEL, $fresh->source_owner);
        $this->assertSame('LARAVEL-FILE', $fresh->file_number);
        $this->assertSame('Laravel Bank', $fresh->bank);
        $this->assertSame('DIPERIKSA', $fresh->status);
        $this->assertSame('Laravel note', $fresh->notes);
        $this->assertTrue(collect($report->issues)->contains(fn (array $issue) => $issue['code'] === 'import_conflict'));
    }

    public function test_same_booking_and_file_business_key_matches_laravel_record_when_source_key_differs(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $record = PemberkasanRecord::query()->where('project_id', $this->project->id)->firstOrFail();
        $record->update(['source_owner' => PemberkasanRecord::OWNER_LARAVEL]);
        $before = PemberkasanRecord::query()->where('booking_id', $record->booking_id)->count();

        (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), false);

        $this->assertSame($before, PemberkasanRecord::query()->where('booking_id', $record->booking_id)->count());
    }

    public function test_sheets_import_assigns_revision_and_one_current_record_per_booking(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_SHEETS]);

        (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), false);

        $records = PemberkasanRecord::query()->where('project_id', $this->project->id)->get();
        $this->assertGreaterThan(1, $records->count());
        foreach ($records->groupBy('booking_id') as $history) {
            $this->assertSame($history->count(), $history->max('revision'));
            $this->assertCount(1, $history->where('is_current', true));
        }
    }
}
