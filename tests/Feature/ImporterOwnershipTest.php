<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class ImporterOwnershipTest extends FixtureProjectTestCase
{
    public function test_laravel_owned_booking_survives_source_reimport_and_reports_conflict(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $booking = Booking::query()->where('project_id', $this->project->id)->firstOrFail();
        $booking->update([
            'source_owner' => Booking::OWNER_LARAVEL,
            'sales_name' => 'Laravel Sales',
            'status' => Booking::STATUS_MUNDUR,
        ]);

        $report = (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project->fresh(), false);

        $fresh = $booking->fresh();
        $this->assertSame('Laravel Sales', $fresh->sales_name);
        $this->assertSame(Booking::STATUS_MUNDUR, $fresh->status);
        $this->assertTrue(collect($report->issues)->contains(fn (array $issue) => $issue['code'] === 'import_conflict'));
    }

    public function test_laravel_owned_holder_flag_is_not_recomputed_by_import(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $booking = Booking::query()->where('project_id', $this->project->id)->firstOrFail();
        $booking->update(['source_owner' => Booking::OWNER_LARAVEL, 'is_current_holder' => true]);

        (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), false);

        $this->assertTrue($booking->fresh()->is_current_holder);
    }

    public function test_status_only_laravel_change_reports_conflict(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $booking = Booking::query()->where('project_id', $this->project->id)->firstOrFail();
        $booking->update(['source_owner' => Booking::OWNER_LARAVEL, 'status' => Booking::STATUS_MUNDUR]);

        $report = (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), true);

        $this->assertTrue(collect($report->issues)->contains(fn (array $issue) => $issue['code'] === 'import_conflict'));
        $this->assertSame(Booking::STATUS_MUNDUR, $booking->fresh()->status);
    }

    public function test_laravel_psjb_values_and_audit_payload_are_preserved(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);
        $booking = Booking::query()->where('project_id', $this->project->id)->firstOrFail();
        $booking->update(['source_owner' => Booking::OWNER_LARAVEL]);
        $booking->psjb()->update(['utj_amount' => 1234567, 'dp_all_in' => 7654321]);

        $report = (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), true);

        $this->assertTrue(collect($report->issues)->contains(fn (array $issue) => $issue['code'] === 'import_conflict'));
        $this->assertSame('1234567.00', $booking->fresh()->psjb->utj_amount);
    }

    public function test_sheets_owned_booking_still_updates(): void
    {
        $booking = Booking::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(Booking::OWNER_SHEETS, $booking->source_owner);

        $booking->update(['sales_name' => 'Temporary']);
        (new ProjectImporter(new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))))
            ->run($this->project->fresh(), false);

        $this->assertNotSame('Temporary', $booking->fresh()->sales_name);
    }
}
