<?php

namespace Tests\Feature;

use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PemberkasanControllerTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $code, string $branchCode): array
    {
        $branch = Branch::query()->create(['code' => $branchCode, 'name' => $branchCode]);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => $code, 'name' => $code, 'management_mode' => Project::MANAGEMENT_LARAVEL]);
        $lot = Lot::query()->create(['project_id' => $project->id, 'code' => $code . '-A1', 'source_key' => 'lot:' . $code]);
        return [$branch, $project, $lot];
    }

    private function admin(Branch $branch, Project $project, string $email): User
    {
        $admin = User::query()->create(['name' => 'Admin', 'email' => $email, 'password' => 'password', 'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin->projects()->attach($project);
        return $admin;
    }

    private function booking(Project $project, Lot $lot, string $method = 'KPR'): Booking
    {
        $buyer = Buyer::query()->create(['source_key' => 'buyer:' . uniqid(), 'name' => 'Buyer']);
        return Booking::query()->create(['project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id, 'source_booking_key' => 'booking:' . uniqid(), 'payment_method' => $method, 'status' => Booking::STATUS_ACTIVE, 'current_stage' => Booking::STAGE_PSJB]);
    }

    public function test_assigned_admin_sees_queue_and_submits_record(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project, 'pemberkasan-controller@test');
        $booking = $this->booking($project, $lot, 'CASH');

        $this->actingAs($admin)->get(route('pemberkasan.index', $project))->assertOk()->assertSee($lot->code);
        $this->actingAs($admin)->post(route('pemberkasan.update', [$project, $booking]), [
            'file_number' => 'BRK-HTTP-001', 'received_date' => '2026-11-18', 'bank' => 'BTN', 'status' => 'LENGKAP',
        ])->assertRedirect(route('bookings.show', $booking));
    }

    public function test_kpr_without_lanjut_and_duplicate_submission_are_rejected(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project, 'pemberkasan-eligibility@test');
        $booking = $this->booking($project, $lot, 'KPR');

        $this->actingAs($admin)->get(route('pemberkasan.index', $project))->assertOk()->assertDontSee($lot->code);
        $this->actingAs($admin)->post(route('pemberkasan.update', [$project, $booking]), ['file_number' => 'BRK-NO-BI', 'received_date' => '2026-11-18'])->assertStatus(422);

        BiCheck::query()->create(['booking_id' => $booking->id, 'project_id' => $project->id, 'source_key' => 'BI:' . uniqid(), 'decision' => 'LANJUT', 'source_owner' => BiCheck::OWNER_LARAVEL, 'check_date' => '2026-11-17']);
        $this->actingAs($admin)->post(route('pemberkasan.update', [$project, $booking]), ['file_number' => 'BRK-HTTP-002', 'received_date' => '2026-11-18'])->assertRedirect();
        $this->actingAs($admin)->post(route('pemberkasan.update', [$project, $booking]), ['file_number' => 'BRK-HTTP-002', 'received_date' => '2026-11-18'])->assertStatus(409);
    }

    public function test_cross_project_booking_id_tampering_is_forbidden(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        [$branch, $mine, $myLot] = $this->project('PRJ-JPR-KWS', 'JPR');
        [, $other, $otherLot] = $this->project('PRJ-MGL-KLG', 'MGL');
        $admin = $this->admin($branch, $mine, 'pemberkasan-tamper@test');
        $booking = $this->booking($other, $otherLot, 'CASH');

        $this->actingAs($admin)->post(route('pemberkasan.update', [$mine, $booking]), ['file_number' => 'BRK-TAMPER', 'received_date' => '2026-11-18'])->assertForbidden();
    }

    public function test_sheets_managed_project_is_forbidden(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $project->update(['management_mode' => Project::MANAGEMENT_SHEETS]);
        $admin = $this->admin($branch, $project, 'pemberkasan-sheets@test');

        $this->actingAs($admin)->get(route('pemberkasan.index', $project))->assertForbidden();
    }
}
