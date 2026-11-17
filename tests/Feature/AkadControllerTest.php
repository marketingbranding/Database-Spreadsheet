<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AkadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_assigned_admin_sees_queue_form_and_can_submit_akad(): void
    {
        [$project, $admin, $booking, $ppjb] = $this->scenario();

        $this->actingAs($admin)->get(route('akad.index', $project))
            ->assertOk()
            ->assertSee($booking->lot->code)
            ->assertSee('Akad');
        $this->actingAs($admin)->get(route('akad.create', [$project, $booking]))
            ->assertOk()
            ->assertSee('name="akad_number"', false)
            ->assertSee('PPJB');

        $this->actingAs($admin)->post(route('akad.store', [$project, $booking]), [
            'ppjb_id' => $ppjb->id,
            'akad_number' => 'AKD-WEB-01',
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'idempotency_key' => 'akad-web-1',
        ])->assertRedirect(route('bookings.show', $booking));

        $this->assertDatabaseHas('akad_records', [
            'booking_id' => $booking->id,
            'ppjb_id' => $ppjb->id,
            'akad_number' => 'AKD-WEB-01',
        ]);
        $this->assertSame(Booking::STAGE_AKAD, $booking->fresh()->current_stage);
        $this->assertSame(1, BookingAudit::query()->where('booking_id', $booking->id)->where('action', BookingAudit::ACTION_AKAD_CREATED)->count());
    }

    public function test_cross_project_booking_and_unauthorized_role_are_forbidden(): void
    {
        [$project, $admin, $booking] = $this->scenario();
        [$otherProject, , $otherBooking] = $this->scenario('PRJ-MGL-KLG', 'MGL');

        $this->actingAs($admin)->get(route('akad.create', [$project, $otherBooking]))->assertForbidden();
        $this->actingAs($admin)->post(route('akad.store', [$project, $otherBooking]), [
            'ppjb_id' => $otherBooking->ppjb->id,
            'akad_number' => 'AKD-CROSS',
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'idempotency_key' => 'akad-cross',
        ])->assertForbidden();

        $hq = User::query()->create([
            'name' => 'HQ', 'email' => 'akad-hq-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_HQ, 'branch_id' => $otherProject->branch_id, 'is_active' => true,
        ]);
        $this->actingAs($hq)->get(route('akad.index', $project))->assertForbidden();
    }

    public function test_routes_are_project_scoped_and_have_three_named_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), '/akad'));

        $this->assertCount(3, $routes);
        $this->assertSame(
            ['akad.create', 'akad.index', 'akad.store'],
            $routes->map(fn ($route) => $route->getName())->sort()->values()->all(),
        );
    }

    private function scenario(string $projectCode = 'PRJ-JPR-KWS', string $branchCode = 'JPR'): array
    {
        $branch = Branch::query()->create(['code' => $branchCode, 'name' => $branchCode]);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => $projectCode,
            'name' => $projectCode,
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $admin = User::query()->create([
            'name' => 'Akad Admin', 'email' => 'akad-admin-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $admin->projects()->attach($project);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:'.uniqid(), 'name' => 'Akad Buyer']);
        $lot = Lot::query()->create([
            'project_id' => $project->id, 'code' => 'A1-'.uniqid(), 'source_key' => 'lot:'.uniqid(),
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:'.uniqid(), 'payment_method' => 'KPR',
            'status' => Booking::STATUS_ACTIVE, 'current_stage' => Booking::STAGE_PPJB,
        ]);
        $ppjb = PpjbRecord::query()->create([
            'booking_id' => $booking->id, 'project_id' => $project->id, 'source_key' => 'PPJB:'.uniqid(),
            'ppjb_number' => 'PPJB-'.uniqid(), 'signed_date' => '2026-11-17',
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
        ]);

        return [$project, $admin, $booking->fresh(['lot', 'ppjb']), $ppjb];
    }
}
