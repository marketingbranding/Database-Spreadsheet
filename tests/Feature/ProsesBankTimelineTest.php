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
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesBankTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_renders_each_submission_with_exact_file_revision_bank_details_and_history(): void
    {
        [$branch, $project, $lot, $booking] = $this->booking();
        $user = $this->user($branch, $project);

        $firstFile = $this->pemberkasan($booking, 'BRK-ONE', 'BTN', 'Jepara', 1, false, '2026-04-10');
        $secondFile = $this->pemberkasan($booking, 'BRK-TWO', 'BNI', 'Kudus', 2, true, '2026-05-01');

        $first = $this->submission($booking, $firstFile, [
            'submission_sequence' => 1,
            'submitted_date' => '2026-04-15',
            'decision_date' => '2026-04-20',
            'result' => 'REJECT',
            'notes' => 'Berkas kurang',
        ]);
        $second = $this->submission($booking, $secondFile, [
            'submission_sequence' => 2,
            'submitted_date' => '2026-05-02',
            'decision_date' => '2026-05-10',
            'result' => 'APPROVE',
            'approved_amount' => 150000000,
            'tenor' => '15 tahun',
            'notes' => 'Disetujui BNI',
        ]);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSeeInOrder([
                'ID Submission',
                (string) $first->id,
                'ID Berkas',
                (string) $firstFile->id,
                'No. berkas',
                'BRK-ONE',
                'Revisi',
                '1',
                'Bank',
                'BTN Jepara',
                'Pengajuan ke-',
                '1',
                'Keputusan',
                'REJECT',
                'Tgl keputusan',
                '20 Apr 2026',
                'Catatan',
                'Berkas kurang',
                'ID Submission',
                (string) $second->id,
                'ID Berkas',
                (string) $secondFile->id,
                'No. berkas',
                'BRK-TWO',
                'Revisi',
                '2',
                'BNI Kudus',
                'Pengajuan ke-',
                '2',
                'APPROVE',
                '10 May 2026',
                'Rp 150.000.000',
                '15 tahun',
                'Disetujui BNI',
            ]);
    }

    public function test_timeline_renders_all_bank_results_without_rewriting_booking_decision(): void
    {
        [$branch, $project, $lot, $booking] = $this->booking([
            'status' => Booking::STATUS_MUNDUR,
            'decision_stage' => Booking::STAGE_PEMBERKASAN,
            'decision_date' => '2026-11-25',
            'decision_note' => 'Buyer mundur',
        ]);
        $user = $this->user($branch, $project);
        $file = $this->pemberkasan($booking, 'BRK-RESULTS', 'BTN', 'Jepara', 1, true, '2026-11-01');

        foreach (BankSubmission::RESULTS as $index => $result) {
            $this->submission($booking, $file, [
                'submission_sequence' => $index + 1,
                'submitted_date' => '2026-11-0'.($index + 2),
                'decision_date' => '2026-11-1'.($index + 2),
                'result' => $result,
                'notes' => 'Hasil '.$result,
            ]);
        }

        $response = $this->actingAs($user)->get(route('bookings.show', $booking));

        $response->assertOk()
            ->assertSee('Mundur')
            ->assertSee('Buyer mundur')
            ->assertSeeInOrder(['APPROVE', 'REJECT', 'REVISI']);

        $this->assertSame(Booking::STATUS_MUNDUR, $booking->fresh()->status);
        $this->assertSame('2026-11-25', $booking->fresh()->decision_date->toDateString());
    }

    public function test_timeline_puts_undated_bank_submission_last(): void
    {
        [$branch, $project, $lot, $booking] = $this->booking();
        $user = $this->user($branch, $project);

        $this->submission($booking, null, [
            'file_number' => 'BRK-UNDATED',
            'submission_sequence' => 2,
            'submitted_date' => null,
            'notes' => 'Tanpa tanggal pengajuan',
        ]);
        $this->submission($booking, null, [
            'file_number' => 'BRK-DATED',
            'submission_sequence' => 1,
            'submitted_date' => '2026-11-18',
            'notes' => 'Bertanggal',
        ]);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSeeInOrder(['18 Nov 2026', 'BRK-DATED', 'tanpa tanggal', 'BRK-UNDATED']);
    }

    public function test_g09_cycle_booking_pages_do_not_leak_other_cycle_bank_history(): void
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Marison Regency Kuwasen',
            'timezone' => 'Asia/Jakarta',
        ]);
        (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($project, false);

        $lot = Lot::query()->where('code', 'G09')->firstOrFail();
        $bookings = Booking::query()->where('lot_id', $lot->id)->get();
        $april = $bookings->firstWhere('status', Booking::STATUS_MUNDUR);
        $september = $bookings->firstWhere('status', Booking::STATUS_COMPLETED);

        $this->assertNotNull($april);
        $this->assertNotNull($september);
        $this->assertNotSame($april->id, $september->id);

        $user = User::query()->create([
            'name' => 'HQ', 'email' => 'timeline-g09@test', 'password' => 'password',
            'role' => User::ROLE_HQ, 'branch_id' => $branch->id, 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('bookings.show', $april))
            ->assertOk()
            ->assertSee('Proses Bank')
            ->assertSee('BSN Kudus')
            ->assertSee('BRK-TRX-JPR-3299000000000121-G09')
            ->assertSee('REJECT')
            ->assertDontSee('BRK-TRX-JPR-3299000000000121-G09-R02')
            ->assertDontSee('BNI Jepara');

        $this->actingAs($user)
            ->get(route('bookings.show', $september))
            ->assertOk()
            ->assertSee('Proses Bank')
            ->assertSee('BNI Jepara')
            ->assertSee('BRK-TRX-JPR-3299000000000121-G09-R02')
            ->assertDontSee('BSN Kudus');
    }

    public function test_bank_create_and_result_audits_keep_safe_exact_revision_snapshots(): void
    {
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');

        [$branch, $project, $lot, $booking] = $this->booking();
        $user = $this->user($branch, $project);
        $file = $this->pemberkasan($booking, 'BRK-AUDIT-01', 'BTN', 'Jepara', 3, true, '2026-11-01');

        $workflow = app(PsjbWorkflow::class);
        $submission = $workflow->recordBankSubmission($user, $booking, $file, [
            'bank' => 'BTN',
            'bank_unit' => 'Jepara',
            'submitted_date' => '2026-11-18',
            'notes' => 'Pengajuan audit',
            'idempotency_key' => 'timeline-audit-create-1',
        ]);
        $workflow->recordBankResult($user, $submission, [
            'result' => 'APPROVE',
            'decision_date' => '2026-11-20',
            'approved_amount' => '150000000',
            'tenor' => '15 tahun',
            'notes' => 'Disetujui audit',
        ]);

        $created = BookingAudit::query()
            ->where('action', BookingAudit::ACTION_BANK_SUBMISSION_CREATED)
            ->sole();
        $result = BookingAudit::query()
            ->where('action', BookingAudit::ACTION_BANK_RESULT_RECORDED)
            ->sole();

        foreach ([$created->after, $result->before, $result->after] as $snapshot) {
            $entry = collect($snapshot['bank_submissions'])->firstWhere('id', $submission->id);

            $this->assertNotNull($entry);
            $this->assertSame($booking->id, $entry['booking_id']);
            $this->assertSame($file->id, $entry['pemberkasan_id']);
            $this->assertSame('BRK-AUDIT-01', $entry['file_number']);
            $this->assertSame(3, $entry['pemberkasan_revision']);
        }

        $this->assertNull(collect($result->before['bank_submissions'])->firstWhere('id', $submission->id)['result']);
        $this->assertSame('APPROVE', collect($result->after['bank_submissions'])->firstWhere('id', $submission->id)['result']);

        $forbidden = ['nik', 'nik_encrypted', 'phone', 'phone_encrypted', 'password', 'remember_token'];
        foreach ([$created->before, $created->after, $result->before, $result->after] as $snapshot) {
            $this->assertSafeKeys($snapshot, $forbidden);
        }
    }

    private function booking(array $attributes = []): array
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'JPR'], ['name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'A1',
            'source_key' => 'lot:timeline:'.uniqid(),
        ]);
        $buyer = Buyer::query()->create([
            'source_key' => 'buyer:timeline:'.uniqid(),
            'name' => 'Buyer Timeline',
        ]);
        $booking = Booking::query()->create(array_merge([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:timeline:'.uniqid(),
            'booking_date' => '2026-04-01',
            'payment_method' => 'KPR',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_BANK,
            'is_current_holder' => true,
        ], $attributes));

        return [$branch, $project, $lot, $booking];
    }

    private function user(Branch $branch, Project $project): User
    {
        $user = User::query()->create([
            'name' => 'Admin', 'email' => 'timeline-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $user->projects()->attach($project);

        return $user;
    }

    private function pemberkasan(
        Booking $booking,
        string $file,
        string $bank,
        string $unit,
        int $revision,
        bool $current,
        string $received,
    ): PemberkasanRecord {
        return PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:'.uniqid(),
            'file_number' => $file,
            'received_date' => $received,
            'bank' => $bank,
            'bank_unit' => $unit,
            'status' => 'LENGKAP',
            'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
            'revision' => $revision,
            'is_current' => $current,
        ]);
    }

    private function submission(Booking $booking, ?PemberkasanRecord $file, array $attributes): BankSubmission
    {
        return BankSubmission::query()->create(array_merge([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'pemberkasan_id' => $file?->id,
            'source_key' => 'BANK:'.uniqid(),
            'file_number' => $file?->file_number,
            'bank' => $file?->bank,
            'bank_unit' => $file?->bank_unit,
            'source_owner' => BankSubmission::OWNER_LARAVEL,
        ], $attributes));
    }

    private function assertSafeKeys(array $snapshot, array $forbidden): void
    {
        foreach ($snapshot as $key => $value) {
            $this->assertNotContains($key, $forbidden);
            if (is_array($value)) {
                $this->assertSafeKeys($value, $forbidden);
            }
        }
    }
}
