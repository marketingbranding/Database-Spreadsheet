<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingTransition;
use App\Models\Buyer;
use App\Models\PsjbRecord;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class BookingChangeImporterOwnershipTest extends FixtureProjectTestCase
{
    public function test_stale_sheets_import_preserves_laravel_change_source_and_successor(): void
    {
        $this->project->update(['management_mode' => 'laravel']);
        $source = Booking::query()
            ->where('source_owner', Booking::OWNER_SHEETS)
            ->where('status', Booking::STATUS_ACTIVE)
            ->whereNotNull('source_transaction_id')
            ->firstOrFail();
        $source->update([
            'status' => Booking::STATUS_REPLACED,
            'decision_stage' => Booking::STAGE_PSJB,
            'decision_date' => '2026-11-19',
            'decision_note' => 'Laravel GANTI NAMA',
            'is_current_holder' => false,
            'source_owner' => Booking::OWNER_LARAVEL,
        ]);
        $buyer = Buyer::query()->create([
            'source_key' => $this->project->code.':BUYER:LARAVEL-CHANGE-'.uniqid(),
            'name' => 'Laravel Change Successor',
        ]);
        $successor = Booking::query()->create([
            'project_id' => $this->project->id,
            'lot_id' => $source->lot_id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'LARAVEL:CHANGE:'.uniqid(),
            'source_transaction_id' => $source->source_transaction_id,
            'psjb_number' => 'PSJB-LARAVEL-CHANGE',
            'booking_date' => '2026-11-19',
            'payment_method' => $source->payment_method,
            'base_price' => $source->base_price,
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PSJB,
            'predecessor_booking_id' => $source->id,
            'transfer_type' => 'GANTI_NAMA',
            'is_current_holder' => true,
            'source_owner' => Booking::OWNER_LARAVEL,
        ]);
        PsjbRecord::query()->create([
            'booking_id' => $successor->id,
            'project_id' => $this->project->id,
            'source_key' => $successor->source_booking_key.':PSJB',
            'psjb_number' => 'PSJB-LARAVEL-CHANGE',
            'psjb_date' => '2026-11-19',
            'payment_method' => $source->payment_method,
            'source_status' => 'ACTIVE',
        ]);
        BookingTransition::query()->create([
            'project_id' => $this->project->id,
            'source_booking_id' => $source->id,
            'successor_booking_id' => $successor->id,
            'action' => BookingTransition::ACTION_NAME_CHANGE,
            'effective_date' => '2026-11-19',
            'reason' => 'Laravel GANTI NAMA',
            'idempotency_key' => 'import-change-'.uniqid(),
            'source_owner' => BookingTransition::OWNER_LARAVEL,
            'source_key' => $successor->source_booking_key.':TRANSITION',
            'source_hash' => hash('sha256', 'import-change'),
        ]);

        $report = (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project, false);

        $this->assertSame(Booking::STATUS_REPLACED, $source->fresh()->status);
        $this->assertFalse($source->fresh()->is_current_holder);
        $this->assertSame(Booking::OWNER_LARAVEL, $source->fresh()->source_owner);
        $this->assertSame(Booking::STATUS_ACTIVE, $successor->fresh()->status);
        $this->assertTrue($successor->fresh()->is_current_holder);
        $this->assertSame($buyer->id, $successor->fresh()->buyer_id);
        $this->assertTrue(collect($report->issues)->contains(
            fn (array $issue) => $issue['code'] === 'import_conflict'
        ));
    }
}
