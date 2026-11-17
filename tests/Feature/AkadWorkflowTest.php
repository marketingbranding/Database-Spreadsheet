<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AkadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_akad_contract_supports_laravel_ownership_idempotency_and_ppjb_source(): void
    {
        $this->assertSame('sheets', AkadRecord::OWNER_SHEETS);
        $this->assertSame('laravel', AkadRecord::OWNER_LARAVEL);
        $this->assertSame('akad_created', BookingAudit::ACTION_AKAD_CREATED);
        $this->assertTrue(Schema::hasColumns('akad_records', [
            'ppjb_id',
            'source_owner',
            'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasTable('akad_records'));
        $this->assertTrue(Schema::hasColumn('akad_records', 'source_hash'));
    }

    public function test_akad_audit_snapshot_contains_safe_record_and_ppjb_source_fields(): void
    {
        [$project, $booking] = $this->bookingWithPpjb();
        $akad = AkadRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'ppjb_id' => $booking->ppjb->id,
            'source_key' => 'AKAD:test',
            'akad_number' => 'AKD-TEST-01',
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'source_owner' => AkadRecord::OWNER_LARAVEL,
            'idempotency_key' => 'akad-test-1',
            'source_hash' => hash('sha256', 'akad-test-1'),
        ]);
        $booking->update(['current_stage' => Booking::STAGE_AKAD]);

        $snapshot = BookingAudit::snapshot($booking->fresh());

        $this->assertSame($akad->id, $snapshot['akad']['id']);
        $this->assertSame($booking->ppjb->id, $snapshot['akad']['ppjb_id']);
        $this->assertSame('AKD-TEST-01', $snapshot['akad']['akad_number']);
        $this->assertSame('PPJB-TEST-test', $snapshot['ppjb']['ppjb_number']);
    }

    public function test_kpr_and_cash_signed_ppjb_bookings_are_eligible_and_record_once(): void
    {
        [$project, $admin] = $this->projectAndAdmin();
        [, $kpr] = $this->bookingWithPpjb($project, 'KPR', 'kpr');
        [, $cash] = $this->bookingWithPpjb($project, 'CASH', 'cash');
        $kprPpjb = $kpr->ppjb;
        $cashPpjb = $cash->ppjb;
        $workflow = app(PsjbWorkflow::class);

        $this->assertSame(2, $workflow->eligibleAkadBookings($project)->count());

        $kprAkad = $workflow->recordAkad($admin, $kpr, [
            'ppjb_id' => $kprPpjb->id,
            'akad_number' => 'AKD-KPR-01',
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'idempotency_key' => 'akad-kpr-1',
        ]);
        $cashAkad = $workflow->recordAkad($admin, $cash, [
            'ppjb_id' => $cashPpjb->id,
            'akad_number' => 'AKD-CASH-01',
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'idempotency_key' => 'akad-cash-1',
        ]);

        $this->assertSame($kprPpjb->id, $kprAkad->ppjb_id);
        $this->assertSame($cashPpjb->id, $cashAkad->ppjb_id);
        $this->assertSame(Booking::STAGE_AKAD, $kpr->fresh()->current_stage);
        $this->assertSame(Booking::STAGE_AKAD, $cash->fresh()->current_stage);
        $this->assertSame(Booking::STATUS_ACTIVE, $kpr->fresh()->status);
        $this->assertSame(1, $kpr->audits()->where('action', BookingAudit::ACTION_AKAD_CREATED)->count());
    }

    public function test_cross_booking_ppjb_is_rejected_inside_transaction(): void
    {
        [$project, $admin] = $this->projectAndAdmin();
        [, $booking] = $this->bookingWithPpjb($project, 'KPR', 'first');
        [, $otherBooking] = $this->bookingWithPpjb($project, 'KPR', 'second');
        $otherPpjb = $otherBooking->ppjb;

        try {
            app(PsjbWorkflow::class)->recordAkad($admin, $booking, [
                'ppjb_id' => $otherPpjb->id,
                'akad_number' => 'AKD-CROSS-01',
                'akad_date' => '2026-11-18',
                'quality' => 'Akad Sempurna',
                'idempotency_key' => 'akad-cross-1',
            ]);
            $this->fail('Expected cross-booking PPJB to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_sequential_retry_returns_existing_record_and_conflicting_payload_is_rejected(): void
    {
        [$project, $admin] = $this->projectAndAdmin();
        [, $booking] = $this->bookingWithPpjb($project, 'KPR', 'retry');
        $ppjb = $booking->ppjb;
        $workflow = app(PsjbWorkflow::class);
        $payload = [
            'ppjb_id' => $ppjb->id,
            'akad_number' => 'AKD-RETRY-01',
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'idempotency_key' => 'akad-retry-1',
        ];

        $first = $workflow->recordAkad($admin, $booking, $payload);
        $second = $workflow->recordAkad($admin, $booking->fresh(), $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AkadRecord::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, $booking->fresh()->audits()->where('action', BookingAudit::ACTION_AKAD_CREATED)->count());

        $payload['quality'] = 'Akad Tidak Sempurna';
        try {
            $workflow->recordAkad($admin, $booking->fresh(), $payload);
            $this->fail('Expected conflicting idempotency payload to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    private function projectAndAdmin(): array
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $admin = User::query()->create([
            'name' => 'Akad Admin',
            'email' => 'akad-admin-'.uniqid().'@test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $admin->projects()->attach($project);

        return [$project, $admin];
    }

    private function bookingWithPpjb(?Project $project = null, string $paymentMethod = 'KPR', string $suffix = 'test'): array
    {
        if (! $project) {
            $project = $this->projectAndAdmin()[0];
        }
        $buyer = Buyer::query()->create(['source_key' => 'buyer:akad-'.$suffix.'-'.uniqid(), 'name' => 'Akad Buyer '.$suffix]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'A1-'.$suffix,
            'source_key' => 'lot:akad-'.$suffix.'-'.uniqid(),
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:akad-'.$suffix.'-'.uniqid(),
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PPJB,
            'payment_method' => $paymentMethod,
        ]);
        PpjbRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'PPJB:akad-'.$suffix.'-'.uniqid(),
            'ppjb_number' => 'PPJB-TEST-'.$suffix,
            'signed_date' => '2026-11-17',
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
        ]);

        return [$project, $booking->fresh('ppjb')];
    }
}
