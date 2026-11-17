<?php

namespace Tests\Feature;

use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $admin;
    private PsjbWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');

        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $this->project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->admin = User::query()->create([
            'name' => 'Admin Jepara', 'email' => 'bi-admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->project);
        $this->workflow = app(PsjbWorkflow::class);
    }

    private function createBooking(string $method = 'KPR'): Booking
    {
        $lot = Lot::query()->create([
            'project_id' => $this->project->id,
            'code' => 'BI-' . $method . '-' . Lot::query()->count(),
            'source_key' => 'lot:bi:' . Lot::query()->count(),
        ]);

        return $this->workflow->create($this->admin, $this->project, [
            'lot_id' => $lot->id,
            'buyer_name' => 'Buyer ' . $method . ' ' . $lot->id,
            'sales_name' => 'Sales',
            'payment_method' => $method,
            'psjb_date' => '2026-11-16',
        ]);
    }

    public function test_kpr_queue_and_lanjut_move_booking_to_bi_and_audit(): void
    {
        $booking = $this->createBooking();
        $this->createBooking('CASH');

        $this->assertTrue($this->workflow->eligibleBiBookings($this->project)->whereKey($booking->id)->exists());
        $this->assertSame(1, $this->workflow->eligibleBiBookings($this->project)->count());

        $updated = $this->workflow->recordBiCheck($this->admin, $booking, [
            'check_date' => '2026-11-17',
            'result' => 'Lolos BI',
            'decision' => 'LANJUT',
            'notes' => 'Dokumen lengkap',
        ]);

        $this->assertSame(Booking::STAGE_BI, $updated->current_stage);
        $this->assertSame('LANJUT', $updated->biCheck->decision);
        $this->assertSame(BiCheck::OWNER_LARAVEL, $updated->biCheck->source_owner);
        $this->assertTrue($updated->is_current_holder);
        $this->assertSame(2, $updated->audits()->count());
    }

    public function test_tidak_lanjut_keeps_lot_claimed_until_explicit_withdraw(): void
    {
        $booking = $this->createBooking();
        $this->workflow->recordBiCheck($this->admin, $booking, [
            'check_date' => '2026-11-17',
            'result' => 'Tidak memenuhi',
            'decision' => 'TIDAK LANJUT',
            'notes' => 'Perlu penolakan manual',
        ]);

        $this->assertFalse($this->workflow->availableLots($this->project)->whereKey($booking->lot_id)->exists());
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->is_current_holder);

        $withdrawn = $this->workflow->withdraw($this->admin, $booking->fresh(), 'BI tidak lanjut');
        $this->assertSame(Booking::STATUS_MUNDUR, $withdrawn->status);
        $this->assertSame(Booking::STAGE_BI, $withdrawn->decision_stage);
        $this->assertFalse($withdrawn->is_current_holder);
        $this->assertSame('TIDAK LANJUT', $withdrawn->biCheck->decision);
    }

    public function test_repeat_booking_after_bi_withdraw_gets_new_id_and_preserves_old_decision(): void
    {
        $booking = $this->createBooking();
        $this->workflow->recordBiCheck($this->admin, $booking, [
            'check_date' => '2026-11-17', 'result' => 'Gagal', 'decision' => 'TIDAK LANJUT',
        ]);
        $this->workflow->withdraw($this->admin, $booking->fresh(), 'Buyer mundur setelah BI');

        $repeat = $this->workflow->create($this->admin, $this->project, [
            'lot_id' => $booking->lot_id,
            'buyer_name' => 'Repeat Buyer',
            'sales_name' => 'New Sales',
            'payment_method' => 'KPR',
            'psjb_date' => '2026-11-20',
        ]);

        $this->assertNotSame($booking->id, $repeat->id);
        $this->assertSame('TIDAK LANJUT', $booking->fresh()->biCheck->decision);
        $this->assertSame(Booking::STAGE_PSJB, $repeat->current_stage);
    }

    public function test_invalid_bi_decision_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->workflow->recordBiCheck($this->admin, $this->createBooking(), [
            'check_date' => '2026-11-17', 'decision' => 'REJECT',
        ]);
    }
}
