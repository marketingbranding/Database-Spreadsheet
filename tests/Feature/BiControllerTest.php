<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_branch_admin_can_open_queue_and_submit_bi(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen', 'management_mode' => Project::MANAGEMENT_LARAVEL]);
        $lot = Lot::query()->create(['project_id' => $project->id, 'code' => 'A1', 'source_key' => 'lot:a1']);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:a1', 'name' => 'Buyer A1']);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'bi-controller@test', 'password' => 'password', 'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin->projects()->attach($project);
        $booking = Booking::query()->create(['project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id, 'source_booking_key' => 'booking:a1', 'payment_method' => 'KPR', 'status' => Booking::STATUS_ACTIVE, 'current_stage' => Booking::STAGE_PSJB]);

        $this->actingAs($admin)->get(route('bi.index', $project))->assertOk()->assertSee('A1');
        $this->actingAs($admin)->post(route('bi.update', [$project, $booking]), [
            'check_date' => '2026-11-17', 'result' => 'Lolos', 'decision' => 'LANJUT', 'notes' => 'OK',
        ])->assertRedirect(route('bookings.show', $booking));
    }

    public function test_sheets_managed_bi_queue_is_forbidden(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen', 'management_mode' => Project::MANAGEMENT_SHEETS]);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'bi-sheets@test', 'password' => 'password', 'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin->projects()->attach($project);

        $this->actingAs($admin)->get(route('bi.index', $project))->assertForbidden();
    }

    public function test_booking_id_tampering_across_projects_is_forbidden(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        $jpr = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $mgl = Branch::query()->create(['code' => 'MGL', 'name' => 'Magelang']);
        $mine = Project::query()->create(['branch_id' => $jpr->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen', 'management_mode' => Project::MANAGEMENT_LARAVEL]);
        $other = Project::query()->create(['branch_id' => $mgl->id, 'code' => 'PRJ-MGL-KLG', 'name' => 'Kalinegoro', 'management_mode' => Project::MANAGEMENT_LARAVEL]);
        $lot = Lot::query()->create(['project_id' => $other->id, 'code' => 'M1', 'source_key' => 'lot:m1']);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:m1', 'name' => 'Buyer M1']);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'bi-tamper@test', 'password' => 'password', 'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $jpr->id, 'is_active' => true]);
        $admin->projects()->attach($mine);
        $booking = Booking::query()->create(['project_id' => $other->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id, 'source_booking_key' => 'booking:m1', 'payment_method' => 'KPR', 'status' => Booking::STATUS_ACTIVE, 'current_stage' => Booking::STAGE_PSJB]);

        $this->actingAs($admin)->post(route('bi.update', [$mine, $booking]), [
            'check_date' => '2026-11-17', 'decision' => 'LANJUT',
        ])->assertForbidden();
    }
}
