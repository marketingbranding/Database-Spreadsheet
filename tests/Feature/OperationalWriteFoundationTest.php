<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Project;
use App\Models\Lot;
use App\Models\Buyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalWriteFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ownership_defaults_and_constants_exist(): void
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
        ]);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:test', 'name' => 'Test Buyer']);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => Lot::query()->create([
                'project_id' => $project->id,
                'code' => 'A1',
                'source_key' => 'lot:test',
            ])->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:test',
        ]);

        $this->assertSame(Project::MANAGEMENT_SHEETS, $project->fresh()->management_mode);
        $this->assertSame(Booking::OWNER_SHEETS, $booking->fresh()->source_owner);
    }
}
