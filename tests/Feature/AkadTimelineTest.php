<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AkadTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_timeline_shows_akad_and_exact_ppjb_source_before_bast(): void
    {
        [$project, $booking, $admin] = $this->scenario();

        $this->actingAs($admin)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Akad')
            ->assertSee('AKD-TIMELINE-01')
            ->assertSee('PPJB #'.$booking->ppjb->id)
            ->assertSee('BAST-ONLY-01');
    }

    private function scenario(): array
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $admin = User::query()->create([
            'name' => 'Timeline Admin', 'email' => 'timeline-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $admin->projects()->attach($project);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:timeline', 'name' => 'Timeline Buyer']);
        $lot = Lot::query()->create(['project_id' => $project->id, 'code' => 'T1', 'source_key' => 'lot:timeline']);
        $booking = Booking::query()->create([
            'project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:timeline', 'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_BAST, 'payment_method' => 'KPR',
        ]);
        $ppjb = PpjbRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $project->id, 'source_key' => 'PPJB:timeline',
            'ppjb_number' => 'PPJB-TIMELINE-01', 'signed_date' => '2026-11-17',
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
        ]);
        AkadRecord::query()->create([
            'booking_id' => $booking->id, 'ppjb_id' => $ppjb->id, 'project_id' => $project->id,
            'source_key' => 'AKAD:timeline', 'akad_number' => 'AKD-TIMELINE-01',
            'akad_date' => '2026-11-18', 'quality' => 'Akad Sempurna',
            'source_owner' => AkadRecord::OWNER_LARAVEL,
        ]);
        $booking->bast()->create([
            'project_id' => $project->id, 'source_key' => 'BAST:timeline',
            'bast_number' => 'BAST-ONLY-01', 'bast_date' => '2026-11-20',
        ]);

        return [$project, $booking->fresh('ppjb'), $admin];
    }
}
