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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProsesBankControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_assigned_admin_sees_queue_and_create_form_with_exact_file_and_revision(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $this->stalePemberkasan($booking, 'BRK-STALE-01');
        $current = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-CURRENT-02');

        $this->actingAs($admin)
            ->get(route('bank.index', $project))
            ->assertOk()
            ->assertSee('Proses Bank')
            ->assertSee('1 booking')
            ->assertSee($lot->code)
            ->assertSee($booking->buyer->name)
            ->assertSee('BRK-CURRENT-02')
            ->assertSee('revisi 2')
            ->assertSee('Buat pengajuan')
            ->assertDontSee('Tidak ada booking KPR yang memenuhi syarat Proses Bank.');

        $this->actingAs($admin)
            ->get(route('bank.create', [$project, $booking]))
            ->assertOk()
            ->assertSee('Booking #'.$booking->id)
            ->assertSee($lot->code)
            ->assertSee($booking->buyer->name)
            ->assertSee('BRK-CURRENT-02')
            ->assertSee('revisi 2')
            ->assertSee('LENGKAP')
            ->assertSee('name="pemberkasan_id"', false)
            ->assertSee('value="'.$current->id.'"', false)
            ->assertSee('name="idempotency_key"', false)
            ->assertSee('Simpan Pengajuan');
    }

    public function test_create_post_redirects_persists_exact_links_and_timeline_shows_bank_data(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-CREATE-01');

        $response = $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'bank_unit' => 'Jepara',
            'submitted_date' => '2026-11-18',
            'notes' => 'Pengajuan awal',
            'idempotency_key' => 'http-create-1',
        ]);

        $response->assertRedirect(route('bookings.show', $booking));

        $submission = BankSubmission::query()->where('idempotency_key', 'http-create-1')->firstOrFail();
        $this->assertSame($booking->id, $submission->booking_id);
        $this->assertSame($project->id, $submission->project_id);
        $this->assertSame($pemberkasan->id, $submission->pemberkasan_id);
        $this->assertSame('BRK-CREATE-01', $submission->file_number);
        $this->assertSame(1, $submission->submission_sequence);
        $this->assertSame('BTN', $submission->bank);
        $this->assertSame('Jepara', $submission->bank_unit);
        $this->assertSame('2026-11-18', $submission->submitted_date->toDateString());
        $this->assertNull($submission->result);
        $this->assertSame(BankSubmission::OWNER_LARAVEL, $submission->source_owner);
        $this->assertSame(Booking::STAGE_BANK, $booking->fresh()->current_stage);

        $this->actingAs($admin)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Proses Bank')
            ->assertSee('BRK-CREATE-01')
            ->assertSee('BTN')
            ->assertSee('18 Nov 2026');
    }

    public function test_same_idempotency_key_replays_and_new_key_adds_history_row(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-IDEM-01');
        $payload = [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'bank_unit' => 'Jepara',
            'submitted_date' => '2026-11-18',
            'notes' => 'Pengajuan awal',
            'idempotency_key' => 'http-idem-1',
        ];

        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), $payload)
            ->assertRedirect(route('bookings.show', $booking));
        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), $payload)
            ->assertRedirect(route('bookings.show', $booking));

        $this->assertSame(1, BankSubmission::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(
            1,
            $booking->fresh()->audits()->where('action', BookingAudit::ACTION_BANK_SUBMISSION_CREATED)->count(),
        );

        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), [...$payload, 'idempotency_key' => 'http-idem-2'])
            ->assertRedirect(route('bookings.show', $booking));

        $submissions = $booking->fresh()->bankSubmissions;
        $this->assertCount(2, $submissions);
        $this->assertSame([1, 2], $submissions->pluck('submission_sequence')->all());

        $this->actingAs($admin)
            ->get(route('bank.index', $project))
            ->assertOk()
            ->assertSee('2')
            ->assertSee('Buat pengajuan');
    }

    public function test_result_post_saves_exact_result_without_changing_booking_decision(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-RESULT-01');
        $submission = $this->submission($admin, $booking, $pemberkasan, 'http-result-1');
        $before = $booking->fresh();

        $this->actingAs($admin)
            ->get(route('bank.result.edit', [$project, $submission]))
            ->assertOk()
            ->assertSee('Submission #'.$submission->id)
            ->assertSee('#'.$pemberkasan->id)
            ->assertSee('BRK-RESULT-01')
            ->assertSee('revisi 1')
            ->assertSee('Booking #'.$booking->id)
            ->assertSee('name="booking_id" value="'.$booking->id.'"', false)
            ->assertSee('name="project_id" value="'.$project->id.'"', false)
            ->assertSee('name="pemberkasan_id" value="'.$pemberkasan->id.'"', false)
            ->assertSee('Simpan Hasil Bank');

        $response = $this->actingAs($admin)->post(route('bank.result.store', [$project, $submission]), [
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'pemberkasan_id' => $pemberkasan->id,
            'result' => 'APPROVE',
            'decision_date' => '2026-11-20',
            'approved_amount' => '150000000',
            'tenor' => '15 tahun',
            'notes' => 'Disetujui',
        ]);

        $response->assertRedirect(route('bookings.show', $booking));

        $fresh = $submission->fresh();
        $this->assertSame('APPROVE', $fresh->result);
        $this->assertSame('2026-11-20', $fresh->decision_date->toDateString());
        $this->assertSame('150000000.00', (string) $fresh->approved_amount);
        $this->assertSame('15 tahun', $fresh->tenor);
        $this->assertSame('Disetujui', $fresh->notes);

        $after = $booking->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->decision_stage, $after->decision_stage);
        $this->assertSame($before->decision_date, $after->decision_date);
        $this->assertSame(Booking::STAGE_BANK, $after->current_stage);
    }

    public function test_identical_result_replay_is_accepted_and_conflicting_result_returns_409(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-REPLAY-01');
        $submission = $this->submission($admin, $booking, $pemberkasan, 'http-replay-1');
        $payload = [
            'result' => 'APPROVE',
            'decision_date' => '2026-11-20',
            'approved_amount' => '150000000',
            'tenor' => '15 tahun',
            'notes' => 'Disetujui',
        ];

        $this->actingAs($admin)->post(route('bank.result.store', [$project, $submission]), $payload)
            ->assertRedirect(route('bookings.show', $booking));
        $audits = $booking->fresh()->audits()->where('action', BookingAudit::ACTION_BANK_RESULT_RECORDED)->count();

        $this->actingAs($admin)->post(route('bank.result.store', [$project, $submission->fresh()]), $payload)
            ->assertRedirect(route('bookings.show', $booking));

        $this->assertSame($audits, $booking->fresh()->audits()->where('action', BookingAudit::ACTION_BANK_RESULT_RECORDED)->count());
        $this->assertSame('APPROVE', $submission->fresh()->result);

        $this->actingAs($admin)->post(route('bank.result.store', [$project, $submission->fresh()]), [...$payload, 'notes' => 'Diubah'])
            ->assertStatus(409);
        $this->assertSame('Disetujui', $submission->fresh()->notes);
    }

    public function test_cross_project_booking_route_is_forbidden(): void
    {
        [$branch, $mine] = $this->project('PRJ-JPR-KWS', 'JPR');
        [, $other, $otherLot] = $this->project('PRJ-MGL-KLG', 'MGL');
        $admin = $this->admin($branch, $mine);
        $otherBooking = $this->booking($other, $otherLot);
        $this->currentPemberkasan($otherBooking, 'LENGKAP', 'BRK-OTHER-01');

        $this->actingAs($admin)->get(route('bank.create', [$mine, $otherBooking]))->assertForbidden();
        $this->actingAs($admin)->post(route('bank.store', [$mine, $otherBooking]), [
            'pemberkasan_id' => $otherBooking->currentPemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'cross-booking-1',
        ])->assertForbidden();
    }

    public function test_cross_project_submission_route_is_forbidden(): void
    {
        [$branch, $mine] = $this->project('PRJ-JPR-KWS', 'JPR');
        [$otherBranch, $other, $otherLot] = $this->project('PRJ-MGL-KLG', 'MGL');
        $admin = $this->admin($branch, $mine);
        $otherAdmin = $this->admin($otherBranch, $other);
        $otherBooking = $this->booking($other, $otherLot);
        $otherPemberkasan = $this->currentPemberkasan($otherBooking, 'LENGKAP', 'BRK-OTHER-02');
        $otherSubmission = $this->submission($otherAdmin, $otherBooking, $otherPemberkasan, 'cross-submission-1');

        $this->actingAs($admin)->get(route('bank.result.edit', [$mine, $otherSubmission]))->assertForbidden();
        $this->actingAs($admin)->post(route('bank.result.store', [$mine, $otherSubmission]), [
            'result' => 'APPROVE',
        ])->assertForbidden();
    }

    public function test_tampered_pemberkasan_and_result_identifiers_are_rejected(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        [, $other, $otherLot] = $this->project('PRJ-MGL-KLG', 'MGL');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-TAMPER-01');
        $otherBooking = $this->booking($other, $otherLot);
        $otherPemberkasan = $this->currentPemberkasan($otherBooking, 'LENGKAP', 'BRK-TAMPER-02');
        $otherProjectPemberkasan = $this->currentPemberkasan($otherBooking, 'LENGKAP', 'BRK-TAMPER-03');

        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $otherPemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'tampered-file-1',
        ])->assertForbidden();
        $this->assertSame(0, BankSubmission::query()->count());

        $submission = $this->submission($admin, $booking, $pemberkasan, 'tampered-result-1');
        foreach ([
            'booking_id' => $otherBooking->id,
            'project_id' => $other->id,
            'pemberkasan_id' => $otherProjectPemberkasan->id,
        ] as $field => $value) {
            $this->actingAs($admin)->post(route('bank.result.store', [$project, $submission]), [
                'result' => 'APPROVE',
                $field => $value,
            ])->assertStatus(422);
        }
        $this->assertNull($submission->fresh()->result);
    }

    public function test_unauthorized_role_and_unassigned_project_are_forbidden(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-AUTH-01');

        $hq = User::query()->create([
            'name' => 'HQ', 'email' => 'bank-hq-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_HQ, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $outsider = User::query()->create([
            'name' => 'Outsider', 'email' => 'bank-outsider-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);

        $this->actingAs($hq)->get(route('bank.index', $project))->assertForbidden();
        $this->actingAs($hq)->get(route('bank.create', [$project, $booking]))->assertForbidden();
        $this->actingAs($hq)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'hq-write-1',
        ])->assertForbidden();

        $this->actingAs($outsider)->get(route('bank.index', $project))->assertForbidden();
        $this->actingAs($outsider)->get(route('bank.create', [$project, $booking]))->assertForbidden();
        $this->actingAs($outsider)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'outsider-write-1',
        ])->assertForbidden();

        $this->assertSame(0, BankSubmission::query()->count());
    }

    public function test_sheets_managed_and_non_pilot_projects_are_forbidden_for_queue_and_writes(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-PROJECT-01');

        $project->update(['management_mode' => Project::MANAGEMENT_SHEETS]);
        $this->actingAs($admin)->get(route('bank.index', $project))->assertForbidden();
        $this->actingAs($admin)->get(route('bank.create', [$project, $booking]))->assertForbidden();
        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'sheets-project-1',
        ])->assertForbidden();

        $project->update(['management_mode' => Project::MANAGEMENT_LARAVEL, 'code' => 'PRJ-JPR-OTHER']);
        $this->actingAs($admin)->get(route('bank.index', $project))->assertForbidden();
        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'non-pilot-1',
        ])->assertForbidden();

        $this->assertSame(0, BankSubmission::query()->count());
    }

    public function test_non_staging_and_missing_write_flag_reject_posts(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-FLAG-01');
        $payload = [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'flag-create-1',
        ];

        config(['app.env' => 'local']);
        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), $payload)->assertForbidden();

        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=false');
        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), $payload)->assertForbidden();

        putenv('MARISON_OPERATIONAL_WRITES=true');
        $submission = $this->submission($admin, $booking, $pemberkasan, 'flag-result-1');
        putenv('MARISON_OPERATIONAL_WRITES=false');
        $this->actingAs($admin)->post(route('bank.result.store', [$project, $submission]), [
            'result' => 'APPROVE',
        ])->assertForbidden();

        $this->assertSame(0, BankSubmission::query()->where('idempotency_key', 'flag-create-1')->count());
        $this->assertNull($submission->fresh()->result);
    }

    public function test_cash_and_ineligible_pemberkasan_are_rejected(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);

        $cash = $this->booking($project, $lot, 'CASH');
        $cashFile = $this->currentPemberkasan($cash, 'LENGKAP', 'BRK-CASH-01');
        $this->actingAs($admin)->get(route('bank.index', $project))->assertOk()
            ->assertDontSee('BRK-CASH-01')
            ->assertSee('Tidak ada booking KPR yang memenuhi syarat Proses Bank.');
        $this->actingAs($admin)->post(route('bank.store', [$project, $cash]), [
            'pemberkasan_id' => $cashFile->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'cash-write-1',
        ])->assertStatus(422);

        $ineligible = $this->booking($project, $lot);
        $ineligibleFile = $this->currentPemberkasan($ineligible, 'Pemberkasan CASH', 'BRK-INELIGIBLE-01');
        $this->actingAs($admin)->get(route('bank.create', [$project, $ineligible]))->assertStatus(422);
        $this->actingAs($admin)->post(route('bank.store', [$project, $ineligible]), [
            'pemberkasan_id' => $ineligibleFile->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'ineligible-write-1',
        ])->assertStatus(422);

        $booking = $this->booking($project, $lot);
        $stale = $this->stalePemberkasan($booking, 'BRK-STALE-02');
        $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-CURRENT-02');
        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $stale->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'stale-file-write-1',
        ])->assertStatus(422);

        $this->assertSame(0, BankSubmission::query()->count());
    }

    public function test_needs_review_booking_is_excluded_from_queue_and_direct_create_is_rejected(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-NEEDS-REVIEW-01');
        $booking->update(['status' => Booking::STATUS_NEEDS_REVIEW]);

        $this->actingAs($admin)->get(route('bank.index', $project))->assertOk()
            ->assertDontSee('BRK-NEEDS-REVIEW-01')
            ->assertSee('Tidak ada booking KPR yang memenuhi syarat Proses Bank.');

        $this->actingAs($admin)->get(route('bank.create', [$project, $booking]))->assertStatus(422);
        $this->actingAs($admin)->post(route('bank.store', [$project, $booking]), [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'needs-review-write-1',
        ])->assertStatus(422);

        $this->assertSame(0, BankSubmission::query()->count());

        // History stays reachable through the booking timeline regardless of eligibility.
        $this->actingAs($admin)->get(route('bookings.show', $booking))->assertOk();
    }

    public function test_sheets_owned_submission_result_form_and_store_are_rejected(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-SHEETS-01');
        $sheets = BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'pemberkasan_id' => $pemberkasan->id,
            'source_key' => 'BANK:sheets-'.uniqid(),
            'source_owner' => BankSubmission::OWNER_SHEETS,
            'submitted_date' => '2026-11-18',
        ]);

        $this->actingAs($admin)->get(route('bank.result.edit', [$project, $sheets]))->assertStatus(422);
        $this->actingAs($admin)->post(route('bank.result.store', [$project, $sheets]), [
            'result' => 'APPROVE',
        ])->assertStatus(422);
        $this->assertNull($sheets->fresh()->result);
    }

    public function test_invalid_create_and_result_inputs_return_422(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->booking($project, $lot);
        $pemberkasan = $this->currentPemberkasan($booking, 'LENGKAP', 'BRK-VALIDATION-01');
        $validCreate = [
            'pemberkasan_id' => $pemberkasan->id,
            'bank' => 'BTN',
            'submitted_date' => '2026-11-18',
            'idempotency_key' => 'validation-create-1',
        ];

        $this->actingAs($admin)->postJson(route('bank.store', [$project, $booking]), [...$validCreate, 'bank' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('bank');
        $this->actingAs($admin)->postJson(route('bank.store', [$project, $booking]), [...$validCreate, 'submitted_date' => null])
            ->assertStatus(422)->assertJsonValidationErrors('submitted_date');
        $this->actingAs($admin)->postJson(route('bank.store', [$project, $booking]), [...$validCreate, 'idempotency_key' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $submission = $this->submission($admin, $booking, $pemberkasan, 'validation-result-1');
        $this->actingAs($admin)->postJson(route('bank.result.store', [$project, $submission]), ['result' => 'DITOLAK'])
            ->assertStatus(422)->assertJsonValidationErrors('result');
        $this->actingAs($admin)->postJson(route('bank.result.store', [$project, $submission]), [
            'result' => 'APPROVE', 'approved_amount' => 'bukan-angka',
        ])->assertStatus(422)->assertJsonValidationErrors('approved_amount');
        $this->actingAs($admin)->postJson(route('bank.result.store', [$project, $submission]), [
            'result' => 'APPROVE', 'approved_amount' => '-1',
        ])->assertStatus(422)->assertJsonValidationErrors('approved_amount');

        $this->assertSame(0, BankSubmission::query()->where('idempotency_key', 'validation-create-1')->count());
        $this->assertNull($submission->fresh()->result);
    }

    public function test_route_list_contains_exactly_five_named_proses_bank_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'proses-bank'));

        $this->assertCount(5, $routes);
        $this->assertSame(
            ['bank.create', 'bank.index', 'bank.result.edit', 'bank.result.store', 'bank.store'],
            $routes->map(fn ($route) => $route->getName())->sort()->values()->all(),
        );
    }

    private function project(string $code, string $branchCode): array
    {
        $branch = Branch::query()->firstOrCreate(['code' => $branchCode], ['name' => $branchCode]);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => $code,
            'name' => $code,
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => $code.'-A1',
            'source_key' => 'lot:'.$code.':'.uniqid(),
        ]);

        return [$branch, $project, $lot];
    }

    private function admin(Branch $branch, Project $project): User
    {
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'bank-admin-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $admin->projects()->attach($project);

        return $admin;
    }

    private function booking(Project $project, Lot $lot, string $method = 'KPR'): Booking
    {
        $buyer = Buyer::query()->create(['source_key' => 'buyer:'.uniqid(), 'name' => 'Buyer '.uniqid()]);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:'.uniqid(),
            'booking_date' => '2026-11-17',
            'payment_method' => $method,
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PEMBERKASAN,
            'is_current_holder' => true,
        ]);
    }

    private function stalePemberkasan(Booking $booking, string $file): PemberkasanRecord
    {
        PemberkasanRecord::query()->where('booking_id', $booking->id)->update(['is_current' => false]);

        return PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:'.uniqid(),
            'file_number' => $file,
            'received_date' => '2026-11-17',
            'bank' => 'BTN',
            'status' => 'LENGKAP',
            'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
            'revision' => (int) PemberkasanRecord::query()->where('booking_id', $booking->id)->max('revision') + 1,
            'is_current' => false,
        ]);
    }

    private function currentPemberkasan(Booking $booking, string $status, string $file): PemberkasanRecord
    {
        PemberkasanRecord::query()->where('booking_id', $booking->id)->update(['is_current' => false]);

        return PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:'.uniqid(),
            'file_number' => $file,
            'received_date' => '2026-11-17',
            'bank' => 'BTN',
            'status' => $status,
            'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
            'revision' => (int) PemberkasanRecord::query()->where('booking_id', $booking->id)->max('revision') + 1,
            'is_current' => true,
        ]);
    }

    private function submission(User $actor, Booking $booking, PemberkasanRecord $pemberkasan, string $key): BankSubmission
    {
        return app(PsjbWorkflow::class)->recordBankSubmission(
            $actor,
            $booking,
            $pemberkasan,
            [
                'bank' => 'BTN',
                'bank_unit' => 'Jepara',
                'submitted_date' => '2026-11-18',
                'notes' => 'Pengajuan bank',
                'idempotency_key' => $key,
            ],
        );
    }
}
