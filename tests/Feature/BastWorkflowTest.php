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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BastWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame('AKD-TEST-01', $snapshot['akad']['akad_number']);
    }

    private function scenario(): array
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:bast-test', 'name' => 'BAST Buyer']);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'B1',
            'source_key' => 'lot:bast-test',
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:bast-test',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_AKAD,
            'payment_method' => 'KPR',
        ]);
        $akad = AkadRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'AKAD:bast-test',
            'akad_number' => 'AKD-TEST-01',
            'akad_date' => '2026-11-18',
            'quality' => 'Akad Sempurna',
            'source_owner' => AkadRecord::OWNER_LARAVEL,
        ]);

        return [$project, $booking, $akad];
    }
}
