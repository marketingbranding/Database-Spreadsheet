<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PemberkasanCurrentFlagBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_migration_leaves_exactly_one_current_record_per_booking(): void
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen']);
        $lot = Lot::query()->create(['project_id' => $project->id, 'code' => 'A1', 'source_key' => 'lot:a1']);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:a1', 'name' => 'Buyer']);
        $booking = Booking::query()->create([
            'project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:a1', 'payment_method' => 'KPR',
            'status' => Booking::STATUS_ACTIVE, 'current_stage' => Booking::STAGE_PEMBERKASAN,
        ]);

        // Simulate the bug: every revision stuck at is_current=true (what
        // 2026_11_16_000008's default did to pre-existing rows).
        $old = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $project->id,
            'source_key' => 'BRK:A1', 'file_number' => 'A1', 'revision' => 1, 'is_current' => true,
        ]);
        $latest = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $project->id,
            'source_key' => 'BRK:A2', 'file_number' => 'A2', 'revision' => 2, 'is_current' => true,
        ]);

        (require base_path('database/migrations/2026_11_17_000002_backfill_pemberkasan_current_flag.php'))->up();

        $this->assertFalse($old->fresh()->is_current);
        $this->assertTrue($latest->fresh()->is_current);
        $this->assertSame(
            1,
            PemberkasanRecord::query()->where('booking_id', $booking->id)->where('is_current', true)->count()
        );
    }
}
