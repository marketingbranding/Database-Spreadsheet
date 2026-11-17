<?php

namespace Tests\Feature;

use App\Models\BankSubmission;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProsesBankWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    private PsjbWorkflow $workflow;

    private Lot $lot;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');

        $this->branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $this->project = Project::query()->create([
            'branch_id' => $this->branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->admin = User::query()->create([
            'name' => 'Admin', 'email' => 'proses-bank-admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->project);
        $this->lot = Lot::query()->create([
            'project_id' => $this->project->id,
            'code' => 'P-'.uniqid(),
            'source_key' => 'lot:'.uniqid(),
        ]);
        $this->workflow = app(PsjbWorkflow::class);
    }

    public function test_bank_submission_schema_is_nullable_and_legacy_compatible(): void
    {
        $this->assertTrue(Schema::hasColumn('bank_submissions', 'pemberkasan_id'));
        $this->assertTrue(Schema::hasColumn('bank_submissions', 'source_owner'));
        $this->assertTrue(Schema::hasColumn('bank_submissions', 'idempotency_key'));

        $booking = $this->booking();
        $legacy = BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BANK:legacy',
        ]);

        $this->assertNull($legacy->fresh()->pemberkasan_id);
        $this->assertNull($legacy->fresh()->source_owner);
        $this->assertNull($legacy->fresh()->idempotency_key);
    }

    public function test_bank_submission_model_casts_fields_and_resolves_exact_pemberkasan(): void
    {
        $booking = $this->booking();
        $pemberkasan = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:bank',
            'file_number' => 'FILE-01',
            'revision' => 3,
        ]);

        $submission = BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'pemberkasan_id' => $pemberkasan->id,
            'source_key' => 'BANK:laravel',
            'source_owner' => BankSubmission::OWNER_LARAVEL,
            'idempotency_key' => 'request-1',
            'submission_sequence' => '2',
            'submitted_date' => '2026-11-17',
            'decision_date' => '2026-11-18',
            'approved_amount' => '125000000.00',
            'withdrawn_flag' => 1,
            'cancelled_flag' => 0,
            'moved_lot_flag' => 1,
            'name_change_flag' => 0,
        ]);

        $fresh = $submission->fresh();
        $this->assertSame(BankSubmission::OWNER_LARAVEL, $fresh->source_owner);
        $this->assertSame($pemberkasan->id, $fresh->pemberkasan->id);
        $this->assertSame(3, $fresh->pemberkasan->revision);
        $this->assertSame(2, $fresh->submission_sequence);
        $this->assertSame('2026-11-17', $fresh->submitted_date->toDateString());
        $this->assertSame('2026-11-18', $fresh->decision_date->toDateString());
        $this->assertSame('125000000.00', (string) $fresh->approved_amount);
        $this->assertTrue($fresh->withdrawn_flag);
        $this->assertFalse($fresh->cancelled_flag);
        $this->assertTrue($fresh->moved_lot_flag);
        $this->assertFalse($fresh->name_change_flag);
        $this->assertCount(1, $pemberkasan->fresh()->bankSubmissions);
    }

    public function test_booking_bank_history_is_ordered_by_submitted_date_then_id(): void
    {
        $booking = $this->booking();
        $common = [
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
        ];
        $later = BankSubmission::query()->create($common + [
            'source_key' => 'BANK:later',
            'submitted_date' => '2026-11-18',
        ]);
        $first = BankSubmission::query()->create($common + [
            'source_key' => 'BANK:first',
            'submitted_date' => '2026-11-17',
        ]);
        $sameDate = BankSubmission::query()->create($common + [
            'source_key' => 'BANK:same-date',
            'submitted_date' => '2026-11-17',
        ]);

        $this->assertSame([$first->id, $sameDate->id, $later->id], $booking->fresh()->bankSubmissions->modelKeys());
    }

    public function test_bank_audit_snapshot_contains_safe_submission_and_file_revision_fields(): void
    {
        $booking = $this->booking();
        $pemberkasan = PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:snapshot',
            'file_number' => 'FILE-02',
            'revision' => 4,
        ]);
        $submission = BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'pemberkasan_id' => $pemberkasan->id,
            'source_key' => 'BANK:snapshot',
            'file_number' => 'FILE-02',
            'submission_sequence' => 1,
            'bank' => 'BTN',
            'bank_unit' => 'Jepara',
            'submitted_date' => '2026-11-17',
            'decision_date' => '2026-11-19',
            'result' => BankSubmission::RESULTS[0],
            'approved_amount' => 150000000,
            'tenor' => '15 tahun',
            'notes' => 'Catatan bank',
            'source_owner' => BankSubmission::OWNER_LARAVEL,
        ]);

        $snapshot = BookingAudit::snapshot($booking->fresh());
        $bank = collect($snapshot['bank_submissions'])->firstWhere('id', $submission->id);

        $this->assertSame($submission->id, $bank['id']);
        $this->assertSame($pemberkasan->id, $bank['pemberkasan_id']);
        $this->assertSame('FILE-02', $bank['file_number']);
        $this->assertSame(4, $bank['pemberkasan_revision']);
        $this->assertSame('BTN', $bank['bank']);
        $this->assertSame('Jepara', $bank['bank_unit']);
        $this->assertSame('2026-11-17', $bank['submitted_date']->toDateString());
        $this->assertSame('2026-11-19', $bank['decision_date']->toDateString());
        $this->assertSame(BankSubmission::RESULTS[0], $bank['result']);
        $this->assertSame('150000000.00', (string) $bank['approved_amount']);
        $this->assertSame('15 tahun', $bank['tenor']);
        $this->assertSame('Catatan bank', $bank['notes']);
        $this->assertSame(BankSubmission::OWNER_LARAVEL, $bank['source_owner']);
        $this->assertArrayNotHasKey('nik', $snapshot);
        $this->assertArrayNotHasKey('phone', $snapshot);
        $this->assertArrayNotHasKey('password', $snapshot);
    }

    public function test_bank_constants_and_audit_actions_define_contract(): void
    {
        $this->assertSame('sheets', BankSubmission::OWNER_SHEETS);
        $this->assertSame('laravel', BankSubmission::OWNER_LARAVEL);
        $this->assertSame(['APPROVE', 'REJECT', 'REVISI'], BankSubmission::RESULTS);
        $this->assertSame('bank_submission_created', BookingAudit::ACTION_BANK_SUBMISSION_CREATED);
        $this->assertSame('bank_result_recorded', BookingAudit::ACTION_BANK_RESULT_RECORDED);
    }

    public function test_idempotency_key_is_unique_when_present_but_nullable_for_sheets_rows(): void
    {
        $booking = $this->booking();
        $attributes = [
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BANK:one',
            'idempotency_key' => 'same-request',
        ];
        BankSubmission::query()->create($attributes);

        $this->expectException(QueryException::class);
        BankSubmission::query()->create([...$attributes, 'source_key' => 'BANK:two']);
    }

    public function test_queue_includes_only_current_kpr_with_eligible_file_status(): void
    {
        $analisa = $this->booking(bookingDate: '2026-11-03');
        $this->currentPemberkasan($analisa, 'Analisa');
        $input = $this->booking(bookingDate: '2026-11-01');
        $this->currentPemberkasan($input, 'Input');
        $ots = $this->booking(bookingDate: '2026-11-04');
        $this->currentPemberkasan($ots, 'OTS');
        $lengkap = $this->booking(bookingDate: '2026-11-02');
        $this->currentPemberkasan($lengkap, 'LENGKAP');
        $alreadyBank = $this->booking(stage: Booking::STAGE_BANK);
        $this->currentPemberkasan($alreadyBank, 'LENGKAP');

        $excluded = [];

        $cash = $this->booking('CASH');
        $this->currentPemberkasan($cash, 'LENGKAP');
        $excluded[] = $cash->id;

        $pemberkasanCash = $this->booking();
        $this->currentPemberkasan($pemberkasanCash, 'Pemberkasan CASH');
        $excluded[] = $pemberkasanCash->id;

        $nonKpr = $this->booking('KPR_BERSAMA');
        $this->currentPemberkasan($nonKpr, 'LENGKAP');
        $excluded[] = $nonKpr->id;

        $stale = $this->booking();
        $this->currentPemberkasan($stale, 'LENGKAP', current: false);
        $excluded[] = $stale->id;

        $closed = $this->booking(status: Booking::STATUS_MUNDUR);
        $this->currentPemberkasan($closed, 'LENGKAP');
        $excluded[] = $closed->id;

        $wrongStage = $this->booking(stage: Booking::STAGE_PSJB);
        $this->currentPemberkasan($wrongStage, 'LENGKAP');
        $excluded[] = $wrongStage->id;

        $otherProject = $this->otherProject();
        $otherBooking = $this->booking(project: $otherProject);
        $this->currentPemberkasan($otherBooking, 'LENGKAP');
        $excluded[] = $otherBooking->id;

        $queue = $this->workflow->eligibleBankBookings($this->project);
        $this->assertSame(
            [$input->id, $lengkap->id, $analisa->id, $ots->id, $alreadyBank->id],
            $queue->pluck('id')->all(),
        );
        foreach ($excluded as $id) {
            $this->assertNotContains($id, $queue->pluck('id')->all());
        }

        $first = $queue->first();
        $this->assertTrue($first->relationLoaded('lot'));
        $this->assertTrue($first->relationLoaded('buyer'));
        $this->assertTrue($first->relationLoaded('currentPemberkasan'));
        $this->assertTrue($first->relationLoaded('bankSubmissions'));
    }

    public function test_submission_saves_exact_links_sequence_and_advances_only_stage(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', file: 'BRK-EXACT-01');
        $before = $booking->fresh();

        $submission = $this->workflow->recordBankSubmission($this->admin, $booking, $pemberkasan, [
            'bank' => '  BTN  ',
            'bank_unit' => 'Jepara',
            'submitted_date' => '2026-11-18',
            'notes' => 'Pengajuan awal',
            'idempotency_key' => 'create-exact-1',
        ]);

        $this->assertSame($booking->id, $submission->booking_id);
        $this->assertSame($this->project->id, $submission->project_id);
        $this->assertSame($pemberkasan->id, $submission->pemberkasan_id);
        $this->assertSame('BRK-EXACT-01', $submission->file_number);
        $this->assertSame(1, $submission->submission_sequence);
        $this->assertSame('BTN', $submission->bank);
        $this->assertSame('Jepara', $submission->bank_unit);
        $this->assertSame('2026-11-18', $submission->submitted_date->toDateString());
        $this->assertSame(BankSubmission::OWNER_LARAVEL, $submission->source_owner);
        $this->assertSame('Laravel', $submission->source_tab);
        $this->assertStringStartsWith('LARAVEL:BANK:', $submission->source_key);
        $this->assertSame('create-exact-1', $submission->idempotency_key);
        $this->assertSame(64, strlen((string) $submission->source_hash));

        $fresh = $booking->fresh();
        $this->assertSame(Booking::STAGE_BANK, $fresh->current_stage);
        $this->assertSame(Booking::OWNER_LARAVEL, $fresh->source_owner);
        $this->assertSame($before->status, $fresh->status);
        $this->assertSame($before->decision_stage, $fresh->decision_stage);
        $this->assertSame($before->decision_date?->toDateString(), $fresh->decision_date?->toDateString());
        $this->assertTrue($fresh->is_current_holder);

        $audit = $fresh->audits()->latest('id')->first();
        $this->assertSame(BookingAudit::ACTION_BANK_SUBMISSION_CREATED, $audit->action);
        $this->assertSame($submission->id, collect($audit->after['bank_submissions'])->firstWhere('id', $submission->id)['id']);
        $this->assertSame('LENGKAP', $audit->after['pemberkasan'][0]['status']);
    }

    public function test_idempotent_replay_and_new_key_preserve_history(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);
        $input = $this->submissionInput('idem-key-1');

        $first = $this->workflow->recordBankSubmission($this->admin, $booking, $pemberkasan, $input);
        $replay = $this->workflow->recordBankSubmission($this->admin, $booking->fresh(), $pemberkasan, $input);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, BankSubmission::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, $booking->fresh()->audits()->where('action', BookingAudit::ACTION_BANK_SUBMISSION_CREATED)->count());

        $this->assertHttpStatus(409, fn () => $this->workflow->recordBankSubmission(
            $this->admin, $booking->fresh(), $pemberkasan, $this->submissionInput('idem-key-1', bank: 'BRI'),
        ));

        $second = $this->workflow->recordBankSubmission(
            $this->admin, $booking->fresh(), $pemberkasan, $this->submissionInput('idem-key-2'),
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $second->submission_sequence);
        $this->assertSame(2, BankSubmission::query()->where('booking_id', $booking->id)->count());
    }

    public function test_needs_review_booking_is_rejected_for_new_submission(): void
    {
        $booking = $this->booking(status: Booking::STATUS_NEEDS_REVIEW);
        $pemberkasan = $this->currentPemberkasan($booking);

        $this->assertHttpStatus(422, fn () => $this->workflow->recordBankSubmission(
            $this->admin, $booking, $pemberkasan, $this->submissionInput('needs-review-1'),
        ));
        $this->assertSame(0, BankSubmission::query()->where('booking_id', $booking->id)->count());
    }

    public function test_later_current_file_revision_does_not_retarget_prior_submission(): void
    {
        $booking = $this->booking();
        $firstFile = $this->currentPemberkasan($booking, 'LENGKAP', file: 'BRK-REV-01');
        $first = $this->workflow->recordBankSubmission($this->admin, $booking, $firstFile, $this->submissionInput('revision-1'));

        $secondFile = $this->currentPemberkasan($booking->fresh(), 'LENGKAP', file: 'BRK-REV-02');
        $second = $this->workflow->recordBankSubmission($this->admin, $booking->fresh(), $secondFile, $this->submissionInput('revision-2'));

        $this->assertSame($firstFile->id, $first->fresh()->pemberkasan_id);
        $this->assertSame('BRK-REV-01', $first->fresh()->file_number);
        $this->assertSame($secondFile->id, $second->fresh()->pemberkasan_id);
        $this->assertSame('BRK-REV-02', $second->fresh()->file_number);
        $this->assertSame([$first->id], $firstFile->fresh()->bankSubmissions->modelKeys());
        $this->assertSame([$second->id], $secondFile->fresh()->bankSubmissions->modelKeys());
        $this->assertSame([$first->id, $second->id], $booking->fresh()->bankSubmissions->modelKeys());
    }

    public function test_all_bank_results_are_accepted_without_booking_decision_changes(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);
        $before = $booking->fresh();

        foreach (BankSubmission::RESULTS as $index => $result) {
            $submission = $this->workflow->recordBankSubmission(
                $this->admin, $booking->fresh(), $pemberkasan, $this->submissionInput('result-'.$index, bank: 'BANK-'.$index),
            );
            $updated = $this->workflow->recordBankResult($this->admin, $submission, [
                'result' => $result,
                'decision_date' => '2026-11-20',
                'approved_amount' => $result === 'APPROVE' ? '150000000' : null,
                'tenor' => $result === 'APPROVE' ? '15 tahun' : null,
                'notes' => 'Hasil '.$result,
            ]);

            $this->assertSame($result, $updated->result);
            $this->assertSame('2026-11-20', $updated->decision_date->toDateString());
            $this->assertSame('Hasil '.$result, $updated->notes);
        }

        $fresh = $booking->fresh();
        $this->assertSame($before->status, $fresh->status);
        $this->assertSame($before->decision_stage, $fresh->decision_stage);
        $this->assertSame($before->decision_date, $fresh->decision_date);
        $this->assertSame(Booking::STAGE_BANK, $fresh->current_stage);
        $this->assertTrue($fresh->is_current_holder);
        $this->assertFalse($this->workflow->availableLots($this->project)->whereKey($this->lot->id)->exists());
        $this->assertSame(
            3,
            $fresh->audits()->where('action', BookingAudit::ACTION_BANK_RESULT_RECORDED)->count(),
        );
    }

    public function test_modified_identifiers_and_sheets_owned_submission_are_rejected(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);
        $submission = $this->workflow->recordBankSubmission($this->admin, $booking, $pemberkasan, $this->submissionInput('target-1'));

        foreach (['booking_id', 'project_id', 'pemberkasan_id'] as $field) {
            $this->assertHttpStatus(422, fn () => $this->workflow->recordBankResult($this->admin, $submission, [
                'result' => BankSubmission::RESULTS[0],
                $field => (int) $submission->{$field} + 999,
            ]));
        }

        $sheets = BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $this->project->id,
            'pemberkasan_id' => $pemberkasan->id,
            'source_key' => 'BANK:sheets-result',
            'source_owner' => BankSubmission::OWNER_SHEETS,
            'submitted_date' => '2026-11-18',
        ]);
        $this->assertHttpStatus(422, fn () => $this->workflow->recordBankResult($this->admin, $sheets, [
            'result' => BankSubmission::RESULTS[0],
        ]));
        $this->assertNull($sheets->fresh()->result);
    }

    public function test_identical_result_replay_is_unchanged_and_conflicting_result_is_rejected(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);
        $submission = $this->workflow->recordBankSubmission($this->admin, $booking, $pemberkasan, $this->submissionInput('result-idem'));
        $input = [
            'result' => 'APPROVE',
            'decision_date' => '2026-11-20',
            'approved_amount' => '150000000',
            'tenor' => '15 tahun',
            'notes' => 'Disetujui',
        ];

        $first = $this->workflow->recordBankResult($this->admin, $submission, $input);
        $auditCount = $booking->fresh()->audits()->where('action', BookingAudit::ACTION_BANK_RESULT_RECORDED)->count();
        $replay = $this->workflow->recordBankResult($this->admin, $submission->fresh(), $input);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame($first->result, $replay->result);
        $this->assertSame($first->decision_date->toDateString(), $replay->decision_date->toDateString());
        $this->assertSame((string) $first->approved_amount, (string) $replay->approved_amount);
        $this->assertSame('Disetujui', $replay->notes);
        $this->assertSame(
            $auditCount,
            $booking->fresh()->audits()->where('action', BookingAudit::ACTION_BANK_RESULT_RECORDED)->count(),
        );

        $this->assertHttpStatus(409, fn () => $this->workflow->recordBankResult($this->admin, $submission->fresh(), [
            ...$input,
            'notes' => 'Diubah',
        ]));
        $this->assertHttpStatus(409, fn () => $this->workflow->recordBankResult($this->admin, $submission->fresh(), [
            ...$input,
            'result' => 'REJECT',
        ]));
    }

    public function test_failed_audit_rolls_back_submission_and_stage_change(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);

        Schema::rename('booking_audits', 'booking_audits_failure');
        $threw = false;
        try {
            $this->workflow->recordBankSubmission($this->admin, $booking, $pemberkasan, $this->submissionInput('rollback-1'));
        } catch (\Throwable) {
            $threw = true;
        } finally {
            Schema::rename('booking_audits_failure', 'booking_audits');
        }

        $this->assertTrue($threw);
        $this->assertSame(0, BankSubmission::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(Booking::STAGE_PEMBERKASAN, $booking->fresh()->current_stage);
    }

    public function test_non_staging_write_is_rejected(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);
        config(['app.env' => 'local']);

        $this->assertHttpStatus(403, fn () => $this->workflow->recordBankSubmission(
            $this->admin, $booking, $pemberkasan, $this->submissionInput('non-staging'),
        ));
        $this->assertSame(0, BankSubmission::query()->count());
    }

    public function test_unauthorized_non_pilot_and_sheets_managed_writes_are_rejected(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);

        $outsider = User::query()->create([
            'name' => 'Outsider', 'email' => 'outsider@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
        $this->assertHttpStatus(403, fn () => $this->workflow->recordBankSubmission(
            $outsider, $booking, $pemberkasan, $this->submissionInput('unauthorized'),
        ));

        $this->project->update(['code' => 'PRJ-JPR-OTHER']);
        $this->assertHttpStatus(403, fn () => $this->workflow->recordBankSubmission(
            $this->admin, $booking, $pemberkasan, $this->submissionInput('non-pilot'),
        ));

        $this->project->update(['code' => 'PRJ-JPR-KWS', 'management_mode' => Project::MANAGEMENT_SHEETS]);
        $this->assertHttpStatus(422, fn () => $this->workflow->recordBankSubmission(
            $this->admin, $booking, $pemberkasan, $this->submissionInput('sheets-managed'),
        ));

        $this->assertSame(0, BankSubmission::query()->count());
    }

    public function test_non_staging_result_write_is_rejected(): void
    {
        $booking = $this->booking();
        $pemberkasan = $this->currentPemberkasan($booking);
        $submission = $this->workflow->recordBankSubmission($this->admin, $booking, $pemberkasan, $this->submissionInput('result-security'));

        config(['app.env' => 'local']);
        $this->assertHttpStatus(403, fn () => $this->workflow->recordBankResult($this->admin, $submission, [
            'result' => BankSubmission::RESULTS[0],
        ]));
        $this->assertNull($submission->fresh()->result);
    }

    private function booking(
        string $method = 'KPR',
        string $status = Booking::STATUS_ACTIVE,
        string $stage = Booking::STAGE_PEMBERKASAN,
        ?Lot $lot = null,
        ?Project $project = null,
        ?string $bookingDate = null,
    ): Booking {
        $buyer = Buyer::query()->create(['source_key' => 'buyer:'.uniqid(), 'name' => 'Buyer '.$method]);

        return Booking::query()->create([
            'project_id' => ($project ?? $this->project)->id,
            'lot_id' => ($lot ?? $this->lot)->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:'.uniqid(),
            'booking_date' => $bookingDate ?? '2026-11-17',
            'payment_method' => $method,
            'status' => $status,
            'current_stage' => $stage,
            'is_current_holder' => true,
        ]);
    }

    private function currentPemberkasan(
        Booking $booking,
        string $status = 'LENGKAP',
        ?string $file = null,
        bool $current = true,
    ): PemberkasanRecord {
        PemberkasanRecord::query()->where('booking_id', $booking->id)->update(['is_current' => false]);
        $revision = (int) PemberkasanRecord::query()->where('booking_id', $booking->id)->max('revision') + 1;

        return PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:'.uniqid(),
            'file_number' => $file ?? 'BRK-'.uniqid(),
            'received_date' => '2026-11-17',
            'bank' => 'BTN',
            'status' => $status,
            'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
            'revision' => $revision,
            'is_current' => $current,
        ]);
    }

    private function submissionInput(string $key, string $bank = 'BTN'): array
    {
        return [
            'bank' => $bank,
            'bank_unit' => 'Jepara',
            'submitted_date' => '2026-11-18',
            'notes' => 'Pengajuan bank',
            'idempotency_key' => $key,
        ];
    }

    private function otherProject(): Project
    {
        return Project::query()->create([
            'branch_id' => $this->branch->id,
            'code' => 'PRJ-MGL-KLG',
            'name' => 'Klangenan',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    private function assertHttpStatus(int $status, Closure $callback): void
    {
        try {
            $callback();
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode(), $exception->getMessage());

            return;
        }

        $this->fail('Expected HTTP '.$status.' exception.');
    }
}
