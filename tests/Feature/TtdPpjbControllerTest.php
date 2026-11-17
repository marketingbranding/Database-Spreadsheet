<?php

namespace Tests\Feature;

use App\Models\BankSubmission;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TtdPpjbControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_kpr_booking_with_approved_bank_submission_can_sign_ppjb(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $submission = $this->approvedSubmission($booking);

        $this->actingAs($admin)
            ->get(route('ppjb.index', $project))
            ->assertOk()
            ->assertSee($lot->code)
            ->assertSee('TTD PPJB');

        $this->actingAs($admin)
            ->get(route('ppjb.create', [$project, $booking]))
            ->assertOk()
            ->assertSee('Booking #'.$booking->id)
            ->assertSee('name="ppjb_number"', false);

        $response = $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), [
            'bank_submission_id' => $submission->id,
            'ppjb_number' => 'PPJB-KPR-01',
            'signed_date' => '2026-11-18',
            'dp_status' => 'LUNAS',
            'idempotency_key' => 'ppjb-kpr-1',
        ]);
        $response->assertRedirect(route('bookings.show', $booking));

        $record = PpjbRecord::query()->where('idempotency_key', 'ppjb-kpr-1')->firstOrFail();
        $this->assertSame($booking->id, $record->booking_id);
        $this->assertSame($submission->id, $record->bank_submission_id);
        $this->assertNull($record->pemberkasan_id);
        $this->assertSame(PpjbRecord::OWNER_LARAVEL, $record->source_owner);
        $this->assertSame(Booking::STAGE_PPJB, $booking->fresh()->current_stage);

        $this->actingAs($admin)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('PPJB-KPR-01');
    }

    public function test_cash_booking_with_pemberkasan_cash_status_can_sign_ppjb(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->cashBooking($project, $lot);
        $file = $this->pemberkasanCash($booking);

        $response = $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), [
            'pemberkasan_id' => $file->id,
            'ppjb_number' => 'PPJB-CASH-01',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-cash-1',
        ]);
        $response->assertRedirect(route('bookings.show', $booking));

        $record = PpjbRecord::query()->where('idempotency_key', 'ppjb-cash-1')->firstOrFail();
        $this->assertSame($booking->id, $record->booking_id);
        $this->assertSame($file->id, $record->pemberkasan_id);
        $this->assertNull($record->bank_submission_id);
    }

    public function test_same_idempotency_key_replays_single_record_and_single_audit(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $submission = $this->approvedSubmission($booking);
        $payload = [
            'bank_submission_id' => $submission->id,
            'ppjb_number' => 'PPJB-RETRY-01',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-retry-1',
        ];

        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), $payload)
            ->assertRedirect(route('bookings.show', $booking));
        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), $payload)
            ->assertRedirect(route('bookings.show', $booking));

        $this->assertSame(1, PpjbRecord::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(
            1,
            $booking->fresh()->audits()->where('action', BookingAudit::ACTION_PPJB_CREATED)->count(),
        );
    }

    public function test_booking_without_approved_submission_or_terminal_cash_file_is_ineligible(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);

        $kprNoApproval = $this->kprBooking($project, $lot);
        $this->actingAs($admin)->get(route('ppjb.create', [$project, $kprNoApproval]))->assertStatus(422);
        $this->actingAs($admin)->post(route('ppjb.store', [$project, $kprNoApproval]), [
            'ppjb_number' => 'PPJB-NONE-01',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-none-1',
        ])->assertStatus(422);

        $cashNotDone = $this->cashBooking($project, $lot);
        $this->pemberkasanCash($cashNotDone, 'Analisa');
        $this->actingAs($admin)->get(route('ppjb.create', [$project, $cashNotDone]))->assertStatus(422);

        $this->assertSame(0, PpjbRecord::query()->count());
    }

    public function test_tampered_cross_project_and_cross_booking_ids_are_rejected(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        [, $other, $otherLot] = $this->project('PRJ-MGL-KLG', 'MGL');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $this->approvedSubmission($booking);

        $otherBooking = $this->kprBooking($other, $otherLot);
        $otherSubmission = $this->approvedSubmission($otherBooking);

        $this->actingAs($admin)->get(route('ppjb.create', [$project, $otherBooking]))->assertForbidden();
        $this->actingAs($admin)->post(route('ppjb.store', [$project, $otherBooking]), [
            'bank_submission_id' => $otherSubmission->id,
            'ppjb_number' => 'PPJB-CROSS-01',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-cross-1',
        ])->assertForbidden();

        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), [
            'bank_submission_id' => $otherSubmission->id,
            'ppjb_number' => 'PPJB-CROSS-02',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-cross-2',
        ])->assertStatus(403);

        $this->assertSame(0, PpjbRecord::query()->count());
    }

    public function test_bank_submission_without_approve_result_is_rejected(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $submission = BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'BANK:'.uniqid(),
            'source_owner' => BankSubmission::OWNER_LARAVEL,
            'submitted_date' => '2026-11-10',
            'result' => 'REJECT',
            'decision_date' => '2026-11-12',
        ]);

        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), [
            'bank_submission_id' => $submission->id,
            'ppjb_number' => 'PPJB-REJECTED-01',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-rejected-1',
        ])->assertStatus(422);

        $this->assertSame(0, PpjbRecord::query()->count());
    }

    public function test_duplicate_ppjb_on_same_booking_is_rejected(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $submission = $this->approvedSubmission($booking);

        app(PsjbWorkflow::class)->recordPpjb($admin, $booking, [
            'bank_submission_id' => $submission->id,
            'ppjb_number' => 'PPJB-FIRST-01',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-first-1',
        ]);

        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking->fresh()]), [
            'bank_submission_id' => $submission->id,
            'ppjb_number' => 'PPJB-SECOND-01',
            'signed_date' => '2026-11-19',
            'idempotency_key' => 'ppjb-second-1',
        ])->assertStatus(422);

        $this->assertSame(1, PpjbRecord::query()->where('booking_id', $booking->id)->count());
    }

    public function test_reychan_g09_april_decision_cannot_qualify_september_booking(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR', 'G09');
        $admin = $this->admin($branch, $project);

        $april = $this->kprBooking($project, $lot, '2026-04-14');
        $aprilSubmission = $this->approvedSubmission($april, '2026-04-20');
        $april->update(['status' => Booking::STATUS_MUNDUR, 'current_stage' => Booking::STAGE_BANK]);

        $september = $this->kprBooking($project, $lot, '2026-09-07');

        $this->actingAs($admin)->post(route('ppjb.store', [$project, $september]), [
            'bank_submission_id' => $aprilSubmission->id,
            'ppjb_number' => 'PPJB-G09-INVALID',
            'signed_date' => '2026-09-28',
            'idempotency_key' => 'ppjb-g09-invalid-1',
        ])->assertForbidden();

        $this->assertSame(0, PpjbRecord::query()->count());
    }

    public function test_unauthorized_role_and_non_pilot_project_are_forbidden(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $submission = $this->approvedSubmission($booking);

        $hq = User::query()->create([
            'name' => 'HQ', 'email' => 'ppjb-hq-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_HQ, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $this->actingAs($hq)->get(route('ppjb.index', $project))->assertForbidden();
        $this->actingAs($hq)->post(route('ppjb.store', [$project, $booking]), [
            'bank_submission_id' => $submission->id,
            'ppjb_number' => 'PPJB-HQ-01',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-hq-1',
        ])->assertForbidden();

        $project->update(['code' => 'PRJ-JPR-OTHER']);
        $this->actingAs($admin)->get(route('ppjb.index', $project))->assertForbidden();

        $this->assertSame(0, PpjbRecord::query()->count());
    }

    public function test_route_list_contains_exactly_three_named_ttd_ppjb_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'ttd-ppjb'));

        $this->assertCount(3, $routes);
        $this->assertSame(
            ['ppjb.create', 'ppjb.index', 'ppjb.store'],
            $routes->map(fn ($route) => $route->getName())->sort()->values()->all(),
        );
    }

    public function test_mixed_source_ids_cannot_attach_an_unrelated_file_to_kpr_ppjb(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $submission = $this->approvedSubmission($booking);
        $other = $this->cashBooking($project, $lot);
        $file = $this->pemberkasanCash($other);

        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), [
            'bank_submission_id' => $submission->id,
            'pemberkasan_id' => $file->id,
            'ppjb_number' => 'PPJB-MIXED',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-mixed',
        ])->assertStatus(422);

        $this->assertDatabaseCount('ppjb_records', 0);
    }

    public function test_multiple_approvals_require_selection_and_link_survives_later_result_change(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->kprBooking($project, $lot);
        $first = $this->approvedSubmission($booking);
        $second = $this->approvedSubmission($booking);
        $this->actingAs($admin)->get(route('ppjb.create', [$project, $booking]))
            ->assertOk()->assertSee('type="radio"', false);
        $payload = [
            'ppjb_number' => 'PPJB-SELECT',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-select',
        ];
        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), $payload)
            ->assertStatus(422);
        $payload['bank_submission_id'] = $first->id;
        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), $payload)
            ->assertRedirect();
        $first->update(['result' => 'REVISI']);
        $this->assertSame($first->id, $booking->fresh()->ppjb->bank_submission_id);
        $this->assertNotSame($second->id, $booking->fresh()->ppjb->bank_submission_id);
        $this->actingAs($admin)->post(route('ppjb.store', [$project, $booking]), $payload)
            ->assertRedirect();
        $this->assertSame(1, $booking->ppjb()->count());
        $this->assertSame(1, $booking->audits()->where('action', BookingAudit::ACTION_PPJB_CREATED)->count());
    }

    public function test_cash_source_link_survives_new_file_revision(): void
    {
        [$branch, $project, $lot] = $this->project('PRJ-JPR-KWS', 'JPR');
        $admin = $this->admin($branch, $project);
        $booking = $this->cashBooking($project, $lot);
        $file = $this->pemberkasanCash($booking);
        $record = app(PsjbWorkflow::class)->recordPpjb($admin, $booking, [
            'pemberkasan_id' => $file->id,
            'ppjb_number' => 'PPJB-FROZEN',
            'signed_date' => '2026-11-18',
            'idempotency_key' => 'ppjb-frozen',
        ]);
        $newFile = $this->pemberkasanCash($booking);
        $this->assertSame($file->id, $record->fresh()->pemberkasan_id);
        $this->assertNotSame($newFile->id, $record->fresh()->pemberkasan_id);
    }

    private function project(string $code, string $branchCode, string $lotCode = 'A1'): array
    {
        $branch = Branch::query()->firstOrCreate(['code' => $branchCode], ['name' => $branchCode]);
        $project = Project::query()->firstOrCreate(['code' => $code], [
            'branch_id' => $branch->id,
            'name' => $code,
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $lot = Lot::query()->firstOrCreate(
            ['project_id' => $project->id, 'code' => $lotCode],
            ['source_key' => 'lot:'.$code.':'.$lotCode.':'.uniqid()],
        );

        return [$branch, $project, $lot];
    }

    private function admin(Branch $branch, Project $project): User
    {
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'ppjb-admin-'.uniqid().'@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $admin->projects()->attach($project);

        return $admin;
    }

    private function kprBooking(Project $project, Lot $lot, string $bookingDate = '2026-11-17'): Booking
    {
        $buyer = Buyer::query()->create(['source_key' => 'buyer:'.uniqid(), 'name' => 'Buyer '.uniqid()]);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:'.uniqid(),
            'booking_date' => $bookingDate,
            'payment_method' => 'KPR',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_BANK,
            'is_current_holder' => true,
        ]);
    }

    private function cashBooking(Project $project, Lot $lot): Booking
    {
        $buyer = Buyer::query()->create(['source_key' => 'buyer:'.uniqid(), 'name' => 'Buyer '.uniqid()]);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:'.uniqid(),
            'booking_date' => '2026-11-17',
            'payment_method' => 'CASH',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PEMBERKASAN,
            'is_current_holder' => true,
        ]);
    }

    private function approvedSubmission(Booking $booking, string $decisionDate = '2026-11-12'): BankSubmission
    {
        return BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BANK:'.uniqid(),
            'source_owner' => BankSubmission::OWNER_LARAVEL,
            'submitted_date' => '2026-11-05',
            'result' => 'APPROVE',
            'decision_date' => $decisionDate,
        ]);
    }

    private function pemberkasanCash(Booking $booking, string $status = 'Pemberkasan CASH'): PemberkasanRecord
    {
        PemberkasanRecord::query()->where('booking_id', $booking->id)->update(['is_current' => false]);

        return PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $booking->project_id,
            'source_key' => 'BRK:'.uniqid(),
            'file_number' => 'BRK-CASH-'.uniqid(),
            'received_date' => '2026-11-10',
            'status' => $status,
            'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
            'revision' => (int) PemberkasanRecord::query()->where('booking_id', $booking->id)->max('revision') + 1,
            'is_current' => true,
        ]);
    }
}
