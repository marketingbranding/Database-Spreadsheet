<?php

namespace Tests\Feature;

use App\Models\BankSubmission;
use App\Models\Booking;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;

class ImporterBankOwnershipTest extends FixtureProjectTestCase
{
    public function test_fresh_sheets_import_marks_ownership_links_exact_pemberkasan_and_is_idempotent(): void
    {
        $rows = BankSubmission::query()->get();
        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            $this->assertSame(BankSubmission::OWNER_SHEETS, $row->source_owner);
            $this->assertNull($row->idempotency_key);

            $exact = PemberkasanRecord::query()
                ->where('booking_id', $row->booking_id)
                ->where('file_number', $row->file_number)
                ->orderBy('id')
                ->first();

            if ($exact) {
                $this->assertSame($exact->id, $row->pemberkasan_id, "Bank row {$row->id} must link its exact Pemberkasan.");
            } else {
                $this->assertNull($row->pemberkasan_id, "Bank row {$row->id} has no exact Pemberkasan to link.");
            }
        }

        $before = BankSubmission::query()->count();
        $report = $this->import();

        $this->assertSame(0, $report->created('bank_submissions'));
        $this->assertSame($before, BankSubmission::query()->count());
    }

    public function test_laravel_owned_bank_row_survives_stale_reimport_and_reports_conflict(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);

        $submission = BankSubmission::query()->whereNotNull('file_number')->firstOrFail();
        $booking = $submission->booking;
        $booking->update(['source_owner' => Booking::OWNER_LARAVEL]);
        $submission->update([
            'source_owner' => BankSubmission::OWNER_LARAVEL,
            'bank' => 'Laravel Bank',
            'result' => 'REVISI',
            'notes' => 'Laravel bank note',
            'idempotency_key' => 'laravel-bank-owner-test',
        ]);

        $before = [
            'booking_id' => $submission->booking_id,
            'pemberkasan_id' => $submission->pemberkasan_id,
            'file_number' => $submission->file_number,
            'decision_number' => $submission->decision_number,
            'bank' => $submission->bank,
            'result' => $submission->result,
            'notes' => $submission->notes,
            'stage' => $booking->current_stage,
            'status' => $booking->status,
            'decision_stage' => $booking->decision_stage,
            'decision_date' => $booking->decision_date?->toDateString(),
            'holder' => $booking->is_current_holder,
        ];

        $report = $this->import();
        $fresh = $submission->fresh();
        $freshBooking = $booking->fresh();

        $this->assertSame(BankSubmission::OWNER_LARAVEL, $fresh->source_owner);
        $this->assertSame('laravel-bank-owner-test', $fresh->idempotency_key);
        $this->assertSame('Laravel Bank', $fresh->bank);
        $this->assertSame('REVISI', $fresh->result);
        $this->assertSame('Laravel bank note', $fresh->notes);
        foreach (['booking_id', 'pemberkasan_id', 'file_number', 'decision_number'] as $field) {
            $this->assertSame($before[$field], $fresh->getAttribute($field));
        }

        $this->assertSame($before['stage'], $freshBooking->current_stage);
        $this->assertSame($before['status'], $freshBooking->status);
        $this->assertSame($before['decision_stage'], $freshBooking->decision_stage);
        $this->assertSame($before['decision_date'], $freshBooking->decision_date?->toDateString());
        $this->assertSame($before['holder'], $freshBooking->is_current_holder);

        $this->assertTrue(collect($report->issues)->contains(
            fn (array $issue) => $issue['code'] === 'import_conflict'
                && in_array('result', $issue['context']['fields'] ?? [], true)
        ));
    }

    public function test_different_source_key_matches_business_row_without_duplicate_or_retargeting(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);

        $submission = BankSubmission::query()->whereNotNull('file_number')->orderByDesc('id')->firstOrFail();
        $submission->update([
            'source_owner' => BankSubmission::OWNER_LARAVEL,
            'source_key' => 'BANK:LARAVEL-BUSINESS-MATCH',
            'pemberkasan_id' => null,
        ]);

        $bookingCount = BankSubmission::query()->where('booking_id', $submission->booking_id)->count();
        $totalCount = BankSubmission::query()->count();

        $this->import();

        $fresh = $submission->fresh();
        $this->assertSame('BANK:LARAVEL-BUSINESS-MATCH', $fresh->source_key);
        $this->assertNull($fresh->pemberkasan_id, 'An existing exact Pemberkasan link must not be retargeted or guessed.');
        $this->assertSame($totalCount, BankSubmission::query()->count());
        $this->assertSame($bookingCount, BankSubmission::query()->where('booking_id', $submission->booking_id)->count());
    }

    public function test_protected_bank_row_does_not_move_booking_while_sheets_row_updates(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_LARAVEL]);

        $protected = BankSubmission::query()
            ->whereHas('booking', fn ($query) => $query->where('current_stage', Booking::STAGE_BANK))
            ->has('booking.bankSubmissions', '=', 1)
            ->firstOrFail();
        $protected->update([
            'source_owner' => BankSubmission::OWNER_LARAVEL,
            'bank' => 'Laravel Protected Bank',
            'result' => 'REVISI',
        ]);

        $protectedBooking = $protected->booking;
        $before = [
            'stage' => $protectedBooking->current_stage,
            'status' => $protectedBooking->status,
            'decision_stage' => $protectedBooking->decision_stage,
            'decision_date' => $protectedBooking->decision_date?->toDateString(),
            'holder' => $protectedBooking->is_current_holder,
        ];

        $sheets = BankSubmission::query()
            ->whereKeyNot($protected->id)
            ->where('source_owner', BankSubmission::OWNER_SHEETS)
            ->whereHas('booking', fn ($query) => $query->whereKeyNot($protectedBooking->id))
            ->firstOrFail();
        $sheetsBooking = $sheets->booking;
        $sheetsStage = $sheetsBooking->current_stage;
        $sheetsStatus = $sheetsBooking->status;
        $sheetsResult = $sheets->result;
        $sheetsNotes = $sheets->notes;
        $sheets->update(['result' => 'REVISI', 'notes' => 'stale sheet value']);
        $sheetsBooking->update(['current_stage' => Booking::STAGE_PSJB, 'status' => Booking::STATUS_NEEDS_REVIEW]);

        $this->import();

        $freshProtectedBooking = $protectedBooking->fresh();
        $this->assertSame('Laravel Protected Bank', $protected->fresh()->bank);
        $this->assertSame('REVISI', $protected->fresh()->result);
        $this->assertSame($before['stage'], $freshProtectedBooking->current_stage);
        $this->assertSame($before['status'], $freshProtectedBooking->status);
        $this->assertSame($before['decision_stage'], $freshProtectedBooking->decision_stage);
        $this->assertSame($before['decision_date'], $freshProtectedBooking->decision_date?->toDateString());
        $this->assertSame($before['holder'], $freshProtectedBooking->is_current_holder);

        $this->assertSame($sheetsResult, $sheets->fresh()->result);
        $this->assertSame($sheetsNotes, $sheets->fresh()->notes);
        $this->assertSame($sheetsStage, $sheetsBooking->fresh()->current_stage);
        $this->assertSame($sheetsStatus, $sheetsBooking->fresh()->status);
    }

    public function test_g09_bank_rows_keep_exact_booking_and_pemberkasan_links(): void
    {
        $lot = Lot::query()->where('code', 'G09')->firstOrFail();
        $bookings = Booking::query()->where('lot_id', $lot->id)->get();
        $april = $bookings->firstWhere('status', Booking::STATUS_MUNDUR);
        $september = $bookings->firstWhere('status', Booking::STATUS_COMPLETED);

        $this->assertNotNull($april);
        $this->assertNotNull($september);
        $this->assertNotSame($april->id, $september->id);

        $aprilBank = BankSubmission::query()->where('booking_id', $april->id)->sole();
        $septemberBank = BankSubmission::query()->where('booking_id', $september->id)->sole();

        $this->assertSame('BRK-TRX-JPR-3299000000000121-G09', $aprilBank->file_number);
        $this->assertSame('BRK-TRX-JPR-3299000000000121-G09-R02', $septemberBank->file_number);
        $this->assertSame('REJECT', $aprilBank->result);
        $this->assertTrue($aprilBank->withdrawn_flag);
        $this->assertSame('APPROVE', $septemberBank->result);
        $this->assertFalse($septemberBank->withdrawn_flag);

        $this->assertSame(
            PemberkasanRecord::query()->where('booking_id', $april->id)->where('file_number', $aprilBank->file_number)->value('id'),
            $aprilBank->pemberkasan_id,
        );
        $this->assertSame(
            PemberkasanRecord::query()->where('booking_id', $september->id)->where('file_number', $septemberBank->file_number)->value('id'),
            $septemberBank->pemberkasan_id,
        );

        $this->assertSame(2, BankSubmission::query()->whereIn('booking_id', [$april->id, $september->id])->count());
        $this->assertSame($april->id, $aprilBank->booking_id);
        $this->assertSame($september->id, $septemberBank->booking_id);
    }

    private function import()
    {
        return (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project->fresh(), false);
    }
}
