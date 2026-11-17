<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\ImportConflict;
use App\Models\ImportConflictBooking;
use App\Models\ImportConflictEvent;
use App\Models\ImportConflictProposal;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportConflictControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'staging',
            'marison.import_conflicts_enabled' => true,
        ]);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_branch_admin_can_view_scoped_register_and_detail_for_assigned_pilot_project(): void
    {
        [$project, , , $admin] = $this->scenario('PRJ-JPR-KWS');

        $this->actingAs($admin)
            ->get(route('import-conflicts.index', $project))
            ->assertOk()
            ->assertSee('Konflik Impor')
            ->assertSee('M22')
            ->assertSee('PSJB:2');

        $this->actingAs($admin)
            ->get(route('import-conflicts.show', [$project, $this->conflictFor($project)]))
            ->assertOk()
            ->assertSee('M22')
            ->assertSee('PERLU CEK')
            ->assertSee('NEEDS_REVIEW')
            ->assertSee('Ajukan Resolusi');
    }

    public function test_branch_admin_cannot_view_other_project_or_cross_project_conflict(): void
    {
        [$project, , , $admin] = $this->scenario('PRJ-JPR-KWS');
        [$otherProject, , , $otherAdmin] = $this->scenario('PRJ-MGL-KLG');

        $this->actingAs($otherAdmin)
            ->get(route('import-conflicts.index', $project))
            ->assertForbidden();
        $hq = User::query()->where('role', User::ROLE_HQ)->firstOrFail();
        $this->actingAs($hq)
            ->get(route('import-conflicts.show', [$otherProject, $this->conflictFor($project)]))
            ->assertNotFound();
        $this->assertNotSame($project->id, $otherProject->id);
    }

    public function test_hq_can_view_all_pilot_projects_but_cannot_submit_branch_proposals(): void
    {
        [$project, , , $admin, $hq] = $this->scenario('PRJ-JPR-KWS');
        $conflict = $this->conflictFor($project);

        $this->actingAs($hq)
            ->get(route('import-conflicts.index', $project))
            ->assertOk()
            ->assertSee('Daftar Konflik Impor');
        $this->actingAs($hq)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $this->proposalPayload())
            ->assertForbidden();
        $this->assertSame(0, ImportConflictProposal::query()->count());
        $this->assertNotSame($admin->id, $hq->id);
    }

    public function test_non_pilot_and_disabled_gates_are_rejected(): void
    {
        [$project] = $this->scenario('PRJ-OTHER');
        [$pilot] = $this->scenario('PRJ-MGL-KLG');
        $hq = User::query()->where('role', User::ROLE_HQ)->firstOrFail();

        config(['marison.import_conflicts_enabled' => false]);
        $this->actingAs($hq)
            ->get(route('import-conflicts.index', $pilot))
            ->assertForbidden();

        config(['marison.import_conflicts_enabled' => true, 'app.env' => 'production']);
        $this->actingAs($hq)
            ->get(route('import-conflicts.index', $pilot))
            ->assertForbidden();

        config(['app.env' => 'staging']);
        $this->actingAs($hq)
            ->get(route('import-conflicts.index', $project))
            ->assertForbidden();

        putenv('MARISON_OPERATIONAL_WRITES=false');
        $this->actingAs($hq)
            ->get(route('import-conflicts.index', $pilot))
            ->assertForbidden();
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_inactive_user_is_rejected(): void
    {
        [$project, , , $admin] = $this->scenario('PRJ-JPR-KWS');
        $admin->update(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('import-conflicts.index', $project))
            ->assertRedirect()
            ->assertSessionHasErrors('email');
    }

    public function test_proposal_validation_enforces_action_specific_fields_and_signed_allowlist(): void
    {
        [$project, , , $admin] = $this->scenario('PRJ-JPR-KWS');
        $conflict = $this->conflictFor($project);

        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), [])
            ->assertSessionHasErrors(['action', 'reason', 'evidence', 'idempotency_key']);

        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), [
                ...$this->proposalPayload('conflict-action-correct'),
                'action' => ImportConflictProposal::ACTION_CORRECT_LARAVEL,
                'proposed_values' => [],
            ])
            ->assertSessionHasErrors(['proposed_values.current_booking_id', 'proposed_values.dispositions']);

        $signed = ImportConflict::query()->create([
            'project_id' => $project->id,
            'stable_source_identity' => $project->code.'|SIGNED|PPJB Developer|1',
            'case_type' => ImportConflict::CASE_SIGNED_DOCUMENT,
            'status' => ImportConflict::STATUS_OPEN,
            'source_tab' => 'PPJB Developer',
            'source_row' => 1,
            'source_fingerprint' => hash('sha256', 'signed'),
        ]);
        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $signed]), [
                ...$this->proposalPayload('conflict-action-signed'),
                'action' => ImportConflictProposal::ACTION_CORRECT_LARAVEL,
                'proposed_values' => [
                    'current_booking_id' => $this->bookingFor($project)->id,
                    'dispositions' => [$this->bookingFor($project)->id => 'MUNDUR'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_identical_retry_reuses_proposal_and_changed_payload_conflicts(): void
    {
        [$project, , , $admin] = $this->scenario('PRJ-JPR-KWS');
        $conflict = $this->conflictFor($project);
        $payload = $this->proposalPayload('conflict-retry-1');

        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $payload)
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $payload)
            ->assertRedirect();
        $this->assertSame(1, ImportConflictProposal::query()->count());

        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), [
                ...$payload,
                'reason' => 'Changed reason',
            ])
            ->assertStatus(409);
        $this->assertSame(1, ImportConflictProposal::query()->count());
    }

    public function test_second_pending_proposal_and_duplicate_guard_return_conflict(): void
    {
        [$project, , , $admin] = $this->scenario('PRJ-JPR-KWS');
        $conflict = $this->conflictFor($project);

        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $this->proposalPayload('pending-1'))
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $this->proposalPayload('pending-2'))
            ->assertStatus(409);
        $this->assertSame(1, ImportConflictProposal::query()->count());

        ImportConflictProposal::query()->first()->update([
            'pending_guard' => null,
            'status' => ImportConflictProposal::STATUS_REJECTED,
        ]);
        ImportConflictProposal::query()->insert([
            'import_conflict_id' => $conflict->id,
            'project_id' => $project->id,
            'proposed_by' => $admin->id,
            'action' => ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            'reason' => 'Existing',
            'evidence' => 'Existing',
            'idempotency_key' => 'guard-collision',
            'request_hash' => hash('sha256', 'guard'),
            'pending_guard' => 1,
            'status' => ImportConflictProposal::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $this->proposalPayload('pending-3'))
            ->assertStatus(409);
    }

    public function test_hq_can_approve_confirmation_and_reject_proposals_with_audit_events(): void
    {
        [$project, , , $admin, $hq] = $this->scenario('PRJ-JPR-KWS');
        $conflict = $this->conflictFor($project);
        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $this->proposalPayload('review-approve'));
        $proposal = ImportConflictProposal::query()->firstOrFail();

        $this->actingAs($hq)
            ->post(route('import-conflicts.proposal.approve', [$project, $conflict, $proposal]), [
                'approval_reason' => 'Existing holder is correct.',
            ])
            ->assertRedirect();

        $this->assertSame(ImportConflict::STATUS_RESOLVED, $conflict->fresh()->status);
        $this->assertSame(ImportConflict::RESOLUTION_CONFIRM_LARAVEL, $conflict->fresh()->resolution_type);
        $this->assertSame($conflict->source_fingerprint, $conflict->fresh()->reviewed_source_fingerprint);
        $this->assertSame(ImportConflictProposal::STATUS_APPLIED, $proposal->fresh()->status);
        $this->assertSame($hq->id, $proposal->fresh()->approved_by);
        $this->assertDatabaseHas('import_conflict_events', [
            'import_conflict_id' => $conflict->id,
            'event_type' => ImportConflictEvent::TYPE_RESOLUTION_APPLIED,
        ]);

        $rejectConflict = ImportConflict::query()->create([
            'project_id' => $project->id,
            'stable_source_identity' => $project->code.'|UNLINKED_BI|BI Checking|9',
            'case_type' => ImportConflict::CASE_UNLINKED_BI,
            'status' => ImportConflict::STATUS_OPEN,
            'source_fingerprint' => hash('sha256', 'reject-case'),
        ]);
        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $rejectConflict]), [
                ...$this->proposalPayload('review-reject'),
                'action' => ImportConflictProposal::ACTION_LINK_BI,
                'proposed_values' => ['booking_id' => $this->bookingFor($project)->id],
            ])->assertRedirect();
        $rejectProposal = ImportConflictProposal::query()
            ->where('import_conflict_id', $rejectConflict->id)
            ->firstOrFail();

        $this->actingAs($hq)
            ->post(route('import-conflicts.proposal.reject', [$project, $rejectConflict, $rejectProposal]), [
                'approval_reason' => 'Insufficient evidence.',
            ])
            ->assertRedirect();

        $this->assertSame(ImportConflictProposal::STATUS_REJECTED, $rejectProposal->fresh()->status);
        $this->assertSame(ImportConflict::STATUS_OPEN, $rejectConflict->fresh()->status);
        $this->assertDatabaseHas('import_conflict_events', [
            'import_conflict_id' => $rejectConflict->id,
            'event_type' => ImportConflictEvent::TYPE_PROPOSAL_REJECTED,
        ]);
    }

    public function test_branch_admin_cannot_approve_and_direct_issue_resolution_route_is_removed(): void
    {
        [$project, , , $admin] = $this->scenario('PRJ-JPR-KWS');
        $conflict = $this->conflictFor($project);
        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.store', [$project, $conflict]), $this->proposalPayload('branch-approve'));
        $proposal = ImportConflictProposal::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('import-conflicts.proposal.approve', [$project, $conflict, $proposal]), [
                'approval_reason' => 'Self approval',
            ])
            ->assertForbidden();

        $this->post('/imports/issues/1/resolve')->assertNotFound();
    }

    private function scenario(string $projectCode): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-'.uniqid(),
            'name' => 'Branch '.$projectCode,
        ]);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => $projectCode,
            'name' => 'Project '.$projectCode,
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $admin = User::query()->create([
            'name' => 'Branch Admin',
            'email' => 'branch-'.uniqid().'@test.local',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $admin->projects()->attach($project);
        $hq = User::query()->create([
            'name' => 'HQ',
            'email' => 'hq-'.uniqid().'@test.local',
            'password' => 'password',
            'role' => User::ROLE_HQ,
            'is_active' => true,
        ]);

        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'M22',
            'source_key' => $projectCode.':LOT:M22',
        ]);
        $booking = $this->bookingFor($project, $lot);
        $conflict = ImportConflict::query()->create([
            'project_id' => $project->id,
            'stable_source_identity' => $projectCode.'|COMPETING_CLAIMS|PSJB|M22',
            'case_type' => ImportConflict::CASE_COMPETING_CLAIMS,
            'status' => ImportConflict::STATUS_OPEN,
            'severity' => 'warning',
            'source_tab' => 'PSJB',
            'source_row' => 2,
            'source_key' => 'PSJB:M22',
            'source_fingerprint' => hash('sha256', $projectCode.'-m22'),
            'source_value' => ['Status' => 'PERLU CEK', 'Kavling' => 'M22'],
            'laravel_value' => ['status' => 'NEEDS_REVIEW', 'current_holder_booking_id' => $booking->id],
            'inventory_impact' => ['summary' => 'M22 remains BOOKED.'],
        ]);
        $conflict->lots()->attach($lot);
        $conflict->bookingLinks()->create([
            'booking_id' => $booking->id,
            'role' => ImportConflictBooking::ROLE_CURRENT_HOLDER,
        ]);

        return [$project, $lot, $booking, $admin, $hq];
    }

    private function bookingFor(Project $project, ?Lot $lot = null): Booking
    {
        $lot ??= Lot::query()->where('project_id', $project->id)->firstOrFail();
        $buyer = Buyer::query()->create([
            'source_key' => $project->code.':BUYER:'.uniqid(),
            'name' => 'Buyer '.uniqid(),
        ]);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'PSJB:'.uniqid(),
            'source_transaction_id' => 'TRX-'.uniqid(),
            'booking_date' => '2026-10-14',
            'payment_method' => 'KPR',
            'status' => Booking::STATUS_NEEDS_REVIEW,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => true,
            'source_owner' => Booking::OWNER_SHEETS,
        ]);
    }

    private function conflictFor(Project $project): ImportConflict
    {
        return ImportConflict::query()->where('project_id', $project->id)->firstOrFail();
    }

    private function proposalPayload(string $key = 'default-proposal'): array
    {
        return [
            'action' => ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            'reason' => 'Review completed',
            'evidence' => 'Signed source review attached in case file',
            'source_reference' => 'PSJB:2',
            'idempotency_key' => $key,
            'proposed_values' => [],
        ];
    }
}
