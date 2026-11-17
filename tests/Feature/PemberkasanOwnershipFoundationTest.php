<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class PemberkasanOwnershipFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function booking(): Booking
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen']);
        $lot = Lot::query()->create(['project_id' => $project->id, 'code' => 'A1', 'source_key' => 'lot:a1']);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:a1', 'name' => 'Buyer']);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:a1',
            'payment_method' => 'KPR',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PEMBERKASAN,
        ]);
    }

    public function test_imported_record_defaults_to_sheets_owner_revision_one_current(): void
    {
        $booking = $this->booking();
        $record = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:A1',
            'file_number' => 'A1',
        ]);

        $this->assertSame(PemberkasanRecord::OWNER_SHEETS, $record->fresh()->source_owner);
        $this->assertSame(1, $record->fresh()->revision);
        $this->assertTrue($record->fresh()->is_current);
    }

    public function test_booking_exposes_history_and_current_record(): void
    {
        $booking = $this->booking();
        $old = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $booking->project_id,
            'source_key' => 'BRK:A1', 'file_number' => 'A1', 'revision' => 1, 'is_current' => false,
        ]);
        $current = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $booking->project_id,
            'source_key' => 'BRK:A2', 'file_number' => 'A2', 'revision' => 2, 'is_current' => true,
        ]);

        $this->assertCount(2, $booking->fresh()->pemberkasan);
        $this->assertSame($current->id, $booking->fresh()->currentPemberkasan->id);
        $this->assertNotSame($old->id, $booking->fresh()->currentPemberkasan->id);
    }

    public function test_same_booking_and_file_number_cannot_be_created_twice(): void
    {
        $booking = $this->booking();
        $attributes = [
            'booking_id' => $booking->id, 'project_id' => $booking->project_id,
            'source_key' => 'BRK:A1', 'file_number' => 'A1',
        ];
        PemberkasanRecord::query()->create($attributes);

        $this->expectException(QueryException::class);
        PemberkasanRecord::query()->create([...$attributes, 'source_key' => 'LARAVEL:BRK:A1']);
    }
}
