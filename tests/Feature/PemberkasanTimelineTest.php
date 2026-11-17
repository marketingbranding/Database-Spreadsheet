<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PemberkasanTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_timeline_renders_all_pemberkasan_revisions_with_null_dates_last(): void
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
            'payment_method' => 'KPR',
            'status' => Booking::STATUS_MUNDUR,
            'current_stage' => Booking::STAGE_PEMBERKASAN,
            'decision_stage' => Booking::STAGE_PEMBERKASAN,
            'decision_date' => '2026-11-20',
            'decision_note' => 'Buyer mundur',
        ]);
        $user = User::query()->create([
            'name' => 'Admin', 'email' => 'timeline@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $user->projects()->attach($project);

        PemberkasanRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $project->id,
            'source_key' => 'BRK:dated', 'file_number' => 'BRK-DATED',
            'received_date' => '2026-11-18', 'bank' => 'BTN', 'bank_unit' => 'Jepara',
            'status' => 'LENGKAP', 'revision' => 1, 'is_current' => false,
        ]);
        PemberkasanRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $project->id,
            'source_key' => 'BRK:undated', 'file_number' => 'BRK-UNDATED',
            'received_date' => null, 'bank' => 'Mandiri', 'bank_unit' => 'Kudus',
            'status' => 'MENUNGGU', 'revision' => 2, 'is_current' => true,
        ]);

        $response = $this->actingAs($user)->get(route('bookings.show', $booking));

        $response->assertOk()
            ->assertSee('BRK-DATED')
            ->assertSee('BRK-UNDATED')
            ->assertSee('Buyer mundur')
            ->assertSeeInOrder(['BRK-DATED', 'BRK-UNDATED']);
    }
}
