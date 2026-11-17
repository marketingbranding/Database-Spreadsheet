<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Lot;

class ProsesBankG09Test extends FixtureProjectTestCase
{
    public function test_g09_april_withdrawal_and_september_approval_keep_separate_history(): void
    {
        $lot = Lot::query()->where('code', 'G09')->first();
        $this->assertNotNull($lot, 'Fixture must contain lot G09.');

        $bookings = Booking::query()->where('lot_id', $lot->id)->get();
        $april = $bookings->firstWhere('status', Booking::STATUS_MUNDUR);
        $september = $bookings->firstWhere('status', Booking::STATUS_COMPLETED);

        $this->assertNotNull($april);
        $this->assertNotNull($september);
        $this->assertNotSame($april->id, $september->id);

        $aprilSubmissions = $april->fresh()->bankSubmissions;
        $septemberSubmissions = $september->fresh()->bankSubmissions;

        $this->assertCount(1, $aprilSubmissions);
        $this->assertCount(1, $septemberSubmissions);

        $aprilBank = $aprilSubmissions->first();
        $septemberBank = $septemberSubmissions->first();

        $this->assertSame($april->id, $aprilBank->booking_id);
        $this->assertSame('REJECT', $aprilBank->result);
        $this->assertTrue($aprilBank->withdrawn_flag);
        $this->assertSame('PUTUSAN-JPR-3299000000000121-G09-01', $aprilBank->decision_number);
        $this->assertSame('BRK-TRX-JPR-3299000000000121-G09', $aprilBank->file_number);
        $this->assertSame(Booking::STAGE_BANK, $april->decision_stage);

        $this->assertSame($september->id, $septemberBank->booking_id);
        $this->assertSame('APPROVE', $septemberBank->result);
        $this->assertFalse($septemberBank->withdrawn_flag);
        $this->assertSame('PUTUSAN-JPR-3299000000000121-G09-02-02', $septemberBank->decision_number);
        $this->assertSame('BRK-TRX-JPR-3299000000000121-G09-R02', $septemberBank->file_number);
        $this->assertSame('2026-09-30', $september->bast_date?->toDateString());

        $this->assertNotSame($aprilBank->decision_number, $septemberBank->decision_number);
        $this->assertNotSame($aprilBank->id, $septemberBank->id);
    }
}
