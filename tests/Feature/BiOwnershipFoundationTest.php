<?php

namespace Tests\Feature;

use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiOwnershipFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bi_check_defaults_to_sheets_owner(): void
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen']);
        $lot = Lot::query()->create(['project_id' => $project->id, 'code' => 'A1', 'source_key' => 'lot:a1']);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:a1', 'name' => 'Buyer']);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:a1',
        ]);

        $bi = BiCheck::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'BI:a1',
            'decision' => 'LANJUT',
        ]);

        $this->assertSame(BiCheck::OWNER_SHEETS, $bi->fresh()->source_owner);
    }
}
