<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BastControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_assigned_admin_sees_queue_form_and_can_submit_bast(): void
    {
        [$project, $admin, $booking, $akad] = $this->scenario('KPR', 'kpr');

        $this->actingAs($admin)->get(route('bast.index', $project))
            ->assertOk()
            ->assertSee($booking->lot->code)
            ->assertSee($booking->buyer->name)
            ->assertSee($booking->payment_method)
            ->assertSee($akad->akad_date->format('d M Y'))
            ->assertSee('Catat BAST');
        $this->actingAs($admin)->get(route('bast.create', [$project, $booking]))
            ->assertOk()
            ->assertSee($booking->buyer->name)
            ->assertSee($akad->akad_number)
            ->assertSee('Tanggal Serah Terima Aktual')
            ->assertSee('Simpan BAST');

        $validation = $this->followingRedirects()->actingAs($admin)->post(route('bast.store', [$project, $booking]), [
            'akad_id' => $akad->id,
            'bast_number' => 'BAST-RETAINED',
            'bast_date' => '',
            'idempotency_key' => 'bast-validation-1',
        ]);
        $validation->assertSee('BAST-RETAINED')
            ->assertSee('The bast date field is required.')
            ->assertSee('field-error');

        $this->actingAs($admin)->post(route('bast.store', [$project, $booking]), [
            'akad_id' => $akad->id,
            'bast_number' => 'BAST-WEB-01',
            'bast_date' => '2026-11-20',
            'notes' => 'Web handover',
            'idempotency_key' => 'bast-web-1',
        ])->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('status', 'BAST berhasil dicatat.');

        $this->assertDatabaseHas('bast_records', [
            'booking_id' => $booking->id,
            'akad_id' => $akad->id,
            'bast_number' => 'BAST-WEB-01',
        ]);
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->fresh()->status);
        $this->assertSame(1, BookingAudit::query()->where('booking_id', $booking->id)->where('action', BookingAudit::ACTION_BAST_CREATED)->count());
        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('BAST')
            ->assertSee('20 Nov 2026')
            ->assertSee('BAST-WEB-01')
            ->assertSee('Akad #'.$akad->id);
    }

    public function test_cash_flow_and_cross_project_or_role_tampering_are_rejected(): void
    {
        [$cashProject, $cashAdmin, $cashBooking, $cashAkad] = $this->scenario('CASH', 'cash');
        [$otherProject, , $otherBooking, $otherAkad] = $this->scenario('KPR', 'other');
        $this->actingAs($cashAdmin)->get(route('bast.create', [$cashProject, $otherBooking]))->assertForbidden();
        $this->actingAs($cashAdmin)->post(route('bast.store', [$cashProject, $cashBooking]), [
            'akad_id' => $otherAkad->id,
            'bast_number' => 'BAST-CROSS',
            'bast_date' => '2026-11-21',
            'idempotency_key' => 'bast-cross',
        ])->assertForbidden();

        $this->actingAs($cashAdmin)->post(route('bast.store', [$cashProject, $cashBooking]), [
            'akad_id' => $cashAkad->id,
            'bast_number' => 'BAST-CASH-01',
            'bast_date' => '2026-11-21',
            'idempotency_key' => 'bast-cash-1',
        ])->assertRedirect(route('bookings.show', $cashBooking));

        $hq = User::query()->create([
            'name' => 'HQ',
            'email' => 'bast-hq-'.uniqid().'@test',
            'password' => 'password',
            'role' => User::ROLE_HQ,
            'branch_id' => $otherProject->branch_id,
            'is_active' => true,
        ]);
        $this->actingAs($hq)->get(route('bast.index', $cashProject))->assertForbidden();
    }

    public function test_routes_are_project_scoped_and_have_three_named_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), '/bast'));

        $this->assertCount(3, $routes);
        $this->assertSame(
            ['bast.create', 'bast.index', 'bast.store'],
            $routes->map(fn ($route) => $route->getName())->sort()->values()->all(),
        );
    }

    private function scenario(string $paymentMethod, string $suffix): array
    {
        $branch = Branch::query()->create(['code' => 'JPR'.$suffix, 'name' => 'Jepara '.$suffix]);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => $suffix === 'other' ? 'PRJ-MGL-KLG' : 'PRJ-JPR-KWS',
            'name' => 'BAST '.$suffix,
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $admin = User::query()->create([
            'name' => 'BAST Admin',
            'email' => 'bast-'.$suffix.'-'.uniqid().'@test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $admin->projects()->attach($project);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:bast-'.$suffix, 'name' => 'BAST Buyer '.$suffix]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'B-'.$suffix,
            'source_key' => 'lot:bast-'.$suffix,
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:bast-'.$suffix,
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_AKAD,
            'payment_method' => $paymentMethod,
        ]);
        $akad = AkadRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'AKAD:bast-'.$suffix,
            'akad_number' => 'AKD-BAST-'.$suffix,
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'source_owner' => AkadRecord::OWNER_LARAVEL,
        ]);

        return [$project, $admin, $booking->fresh(['lot', 'buyer', 'akad']), $akad];
    }
}
