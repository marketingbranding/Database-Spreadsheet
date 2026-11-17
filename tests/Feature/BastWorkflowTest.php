<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\BastRecord;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BastWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_bast_contract_supports_ownership_idempotency_and_akad_source(): void
    {
        $this->assertSame('sheets', BastRecord::OWNER_SHEETS);
        $this->assertSame('laravel', BastRecord::OWNER_LARAVEL);
        $this->assertSame('bast_created', BookingAudit::ACTION_BAST_CREATED);
        $this->assertTrue(Schema::hasColumns('bast_records', [
            'akad_id',
            'source_owner',
            'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasTable('bast_records'));
        $this->assertTrue(Schema::hasColumn('bast_records', 'source_hash'));
    }

    public function test_bast_audit_snapshot_contains_safe_record_and_akad_source_fields(): void
    {
        [$project, $booking, $akad] = $this->scenario();
        $bast = BastRecord::query()->create([
            'booking_id' => $booking->id,
            'akad_id' => $akad->id,
            'project_id' => $project->id,
            'source_key' => 'BAST:test',
            'bast_number' => 'BAST-TEST-01',
            'bast_date' => '2026-11-20',
            'source_owner' => BastRecord::OWNER_LARAVEL,
            'idempotency_key' => 'bast-test-1',
            'source_hash' => hash('sha256', 'bast-test-1'),
        ]);
        $booking->update([
            'current_stage' => Booking::STAGE_BAST,
            'status' => Booking::STATUS_COMPLETED,
            'bast_date' => '2026-11-20',
        ]);

        $snapshot = BookingAudit::snapshot($booking->fresh());

        $this->assertSame($bast->id, $snapshot['bast']['id']);
        $this->assertSame($akad->id, $snapshot['bast']['akad_id']);
        $this->assertSame('BAST-TEST-01', $snapshot['bast']['bast_number']);
        $this->assertSame('AKD-TEST-test', $snapshot['akad']['akad_number']);
    }

    public function test_kpr_and_cash_akad_bookings_complete_once_and_leave_the_queue(): void
    {
        [$project, $admin] = $this->projectAndAdmin();
        [, $kprBooking, $kprAkad] = $this->scenario($project, 'KPR', 'kpr');
        [, $cashBooking, $cashAkad] = $this->scenario($project, 'CASH', 'cash');
        $workflow = app(PsjbWorkflow::class);

        $this->assertSame(2, $workflow->eligibleBastBookings($project)->count());

        $kprBast = $workflow->recordBast($admin, $kprBooking, [
            'akad_id' => $kprAkad->id,
            'bast_number' => 'BAST-KPR-01',
            'bast_date' => '2026-11-20',
            'notes' => 'KPR handover',
            'idempotency_key' => 'bast-kpr-1',
        ]);
        $cashBast = $workflow->recordBast($admin, $cashBooking, [
            'akad_id' => $cashAkad->id,
            'bast_number' => 'BAST-CASH-01',
            'bast_date' => '2026-11-21',
            'idempotency_key' => 'bast-cash-1',
        ]);

        $this->assertSame($kprAkad->id, $kprBast->akad_id);
        $this->assertSame($cashAkad->id, $cashBast->akad_id);
        $this->assertSame(Booking::STAGE_BAST, $kprBooking->fresh()->current_stage);
        $this->assertSame(Booking::STATUS_COMPLETED, $kprBooking->fresh()->status);
        $this->assertTrue($kprBooking->fresh()->is_current_holder);
        $this->assertSame(0, $workflow->eligibleBastBookings($project)->count());
        $this->assertSame(1, $kprBooking->fresh()->audits()->where('action', BookingAudit::ACTION_BAST_CREATED)->count());
        $this->assertSame('SOLD', $kprBooking->lot->fresh()->inventoryPosition());
    }

    public function test_cross_booking_akad_is_rejected_inside_transaction(): void
    {
        [$project, $admin] = $this->projectAndAdmin();
        [, $booking] = $this->scenario($project, 'KPR', 'first');
        [, , $otherAkad] = $this->scenario($project, 'KPR', 'second');

        try {
            app(PsjbWorkflow::class)->recordBast($admin, $booking, [
                'akad_id' => $otherAkad->id,
                'bast_number' => 'BAST-CROSS-01',
                'bast_date' => '2026-11-20',
                'idempotency_key' => 'bast-cross-1',
            ]);
            $this->fail('Expected cross-booking Akad to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('bast_records', ['bast_number' => 'BAST-CROSS-01']);
        $this->assertSame(Booking::STAGE_AKAD, $booking->fresh()->current_stage);
    }

    public function test_retry_after_completion_replays_and_changed_payload_returns_conflict(): void
    {
        [$project, $admin] = $this->projectAndAdmin();
        [, $booking, $akad] = $this->scenario($project, 'KPR', 'retry');
        $workflow = app(PsjbWorkflow::class);
        $payload = [
            'akad_id' => $akad->id,
            'bast_number' => 'BAST-RETRY-01',
            'bast_date' => '2026-11-20',
            'notes' => 'same payload',
            'idempotency_key' => 'bast-retry-1',
        ];

        $first = $workflow->recordBast($admin, $booking, $payload);
        $second = $workflow->recordBast($admin, $booking->fresh(), $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BastRecord::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, $booking->fresh()->audits()->where('action', BookingAudit::ACTION_BAST_CREATED)->count());

        $payload['bast_number'] = 'BAST-RETRY-CHANGED';
        try {
            $workflow->recordBast($admin, $booking->fresh(), $payload);
            $this->fail('Expected changed idempotency payload to return 409.');
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
            'name' => 'BAST Admin',
            'email' => 'bast-admin-'.uniqid().'@test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $admin->projects()->attach($project);

        return [$project, $admin];
    }

    private function scenario(?Project $project = null, string $paymentMethod = 'KPR', string $suffix = 'test'): array
    {
        if (! $project) {
            [$project] = $this->projectAndAdmin();
        }
        $buyer = Buyer::query()->create(['source_key' => 'buyer:bast-'.$suffix.'-'.uniqid(), 'name' => 'BAST Buyer '.$suffix]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'B1-'.$suffix,
            'source_key' => 'lot:bast-'.$suffix.'-'.uniqid(),
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:bast-'.$suffix.'-'.uniqid(),
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_AKAD,
            'payment_method' => $paymentMethod,
        ]);
        $akad = AkadRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'AKAD:bast-'.$suffix.'-'.uniqid(),
            'akad_number' => 'AKD-TEST-'.$suffix,
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'source_owner' => AkadRecord::OWNER_LARAVEL,
        ]);

        return [$project, $booking->fresh(['lot', 'akad']), $akad];
    }
}
