<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\PsjbRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_name_and_transfer_forms_are_project_scoped_and_show_source_preview(): void
    {
        [$project, $admin, $booking] = $this->scenario();
        $newBuyer = Buyer::query()->create([
            'source_key' => $project->code.':BUYER:controller-'.uniqid(),
            'name' => 'New Controller Buyer',
        ]);
        $destination = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'DEST-CONTROLLER',
            'source_key' => 'lot:controller-destination',
        ]);

        $this->actingAs($admin)
            ->get(route('bookings.change-name.create', [$project, $booking]))
            ->assertOk()
            ->assertSee('Booking sumber')
            ->assertSee($booking->buyer->name)
            ->assertSee($newBuyer->name);

        $this->actingAs($admin)
            ->get(route('bookings.show', $booking))
            ->assertSee('Ganti Nama')
            ->assertSee('Pindah Kavling');

        $this->actingAs($admin)
            ->get(route('bookings.move-lot.create', [$project, $booking]))
            ->assertOk()
            ->assertSee('DEST-CONTROLLER')
            ->assertSee('Saya mengonfirmasi harga tujuan bila berbeda');
    }

    public function test_post_creates_successor_and_redirects_to_successor_timeline(): void
    {
        [$project, $admin, $booking] = $this->scenario();
        $newBuyer = Buyer::query()->create([
            'source_key' => $project->code.':BUYER:post-'.uniqid(),
            'name' => 'Posted Buyer',
        ]);

        $response = $this->actingAs($admin)->post(route('bookings.change-name.store', [$project, $booking]), [
            'buyer_id' => $newBuyer->id,
            'effective_date' => '2026-11-19',
            'reason' => 'Controller substitution',
            'idempotency_key' => 'controller-name-1',
        ]);

        $successor = Booking::query()->where('predecessor_booking_id', $booking->id)->firstOrFail();
        $response->assertRedirect(route('bookings.show', $successor));
        $this->get(route('bookings.show', $successor))->assertOk()->assertSee('GANTI NAMA');
    }

    public function test_cross_project_url_tampering_is_rejected(): void
    {
        [$project, $admin, $booking] = $this->scenario('first');
        [$otherProject, , $otherBooking] = $this->scenario('other');

        $this->actingAs($admin)
            ->get(route('bookings.change-name.create', [$project, $otherBooking]))
            ->assertForbidden();
        $this->assertNotSame($project->id, $otherProject->id);
        $this->assertSame(Booking::STATUS_ACTIVE, $otherBooking->fresh()->status);
    }

    public function test_signed_document_form_url_is_rejected_before_rendering(): void
    {
        [$project, $admin, $booking] = $this->scenario('signed');
        PpjbRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'PPJB:controller-signed-'.uniqid(),
            'ppjb_number' => 'PPJB-CONTROLLER-SIGNED',
            'signed_date' => '2026-11-18',
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
        ]);

        $this->actingAs($admin)
            ->get(route('bookings.change-name.create', [$project, $booking]))
            ->assertStatus(422);
    }

    private function scenario(string $suffix = 'default'): array
    {
        $branch = Branch::query()->create(['code' => 'JPR-'.uniqid(), 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS-'.strtoupper(substr(sha1($suffix), 0, 4)),
            'name' => 'Kuwasen '.$suffix,
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $admin = User::query()->create([
            'name' => 'Controller Admin',
            'email' => 'controller-'.uniqid().'@test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $admin->projects()->attach($project);
        $buyer = Buyer::query()->create([
            'source_key' => $project->code.':BUYER:source-'.uniqid(),
            'name' => 'Controller Source Buyer',
        ]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'SRC-CONTROLLER-'.uniqid(),
            'source_key' => 'lot:controller-source-'.uniqid(),
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:controller-'.uniqid(),
            'booking_date' => '2026-11-18',
            'payment_method' => 'CASH',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => true,
            'source_owner' => Booking::OWNER_LARAVEL,
        ]);
        PsjbRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'PSJB:controller-'.uniqid(),
            'psjb_number' => 'PSJB-CONTROLLER',
            'psjb_date' => '2026-11-18',
            'payment_method' => 'CASH',
            'source_status' => 'ACTIVE',
        ]);

        return [$project, $admin, $booking->fresh(['lot', 'buyer', 'psjb'])];
    }
}
