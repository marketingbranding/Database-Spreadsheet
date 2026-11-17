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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AkadWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame('PPJB-TEST-01', $snapshot['ppjb']['ppjb_number']);
    }

    private function bookingWithPpjb(): array
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:akad-test', 'name' => 'Akad Buyer']);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'A1',
            'source_key' => 'lot:akad-test',
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:akad-test',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PPJB,
            'payment_method' => 'KPR',
        ]);
        PpjbRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'PPJB:akad-test',
            'ppjb_number' => 'PPJB-TEST-01',
            'signed_date' => '2026-11-17',
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
        ]);

        return [$project, $booking->fresh('ppjb')];
    }
}
