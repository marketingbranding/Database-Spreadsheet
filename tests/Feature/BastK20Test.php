<?php

namespace Tests\Feature;

use App\Models\BastRecord;
use App\Models\Booking;

class BastK20Test extends FixtureProjectTestCase
{
    public function test_k20_booking_chain_keeps_bast_links_exact_and_counts_each_record_once(): void
    {
        $lot = \App\Models\Lot::query()->where('code', 'K20')->firstOrFail();
        $bookings = Booking::query()->where('lot_id', $lot->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(1, $bookings->count());

        $bastRecords = BastRecord::query()->whereIn('booking_id', $bookings->pluck('id'))->get();
        $this->assertSame($bastRecords->count(), $bastRecords->unique('booking_id')->count());
        $this->assertTrue($bastRecords->every(
            fn (BastRecord $record) => $record->booking_id !== null
                && $record->project_id === $this->project->id
                && $record->booking_id === $record->booking?->id
        ));
    }
}
