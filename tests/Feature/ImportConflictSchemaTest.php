<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\ImportConflict;
use App\Models\ImportConflictBooking;
use App\Models\ImportConflictEvent;
use App\Models\ImportConflictProposal;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportConflictSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_conflict_tables_expose_the_durable_workflow_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('import_conflicts', [
            'project_id',
            'current_import_issue_id',
            'supersedes_conflict_id',
            'stable_source_identity',
            'case_type',
            'status',
            'source_tab',
            'source_row',
            'source_key',
            'source_fingerprint',
            'reviewed_source_fingerprint',
            'source_value',
            'laravel_value',
            'inventory_impact',
            'resolved_at',
            'resolved_by',
            'resolution_type',
        ]));
        $this->assertTrue(Schema::hasColumns('import_conflict_lot', [
            'import_conflict_id',
            'lot_id',
        ]));
        $this->assertTrue(Schema::hasColumns('import_conflict_bookings', [
            'import_conflict_id',
            'booking_id',
            'role',
        ]));
        $this->assertTrue(Schema::hasColumns('import_conflict_proposals', [
            'import_conflict_id',
            'project_id',
            'proposed_by',
            'action',
            'reason',
            'evidence',
            'source_reference',
            'proposed_values',
            'idempotency_key',
            'request_hash',
            'pending_guard',
            'status',
            'approved_by',
            'approval_reason',
            'before',
            'after',
        ]));
        $this->assertTrue(Schema::hasColumns('import_conflict_events', [
            'import_conflict_id',
            'import_conflict_proposal_id',
            'actor_id',
            'event_type',
            'reason',
            'source_reference',
            'source_fingerprint',
            'before',
            'after',
        ]));
    }

    public function test_models_define_case_status_action_role_and_audit_constants(): void
    {
        $this->assertSame('COMPETING_CLAIMS', ImportConflict::CASE_COMPETING_CLAIMS);
        $this->assertSame('UNLINKED_BI', ImportConflict::CASE_UNLINKED_BI);
        $this->assertSame('STOCK_DISAGREEMENT', ImportConflict::CASE_STOCK_DISAGREEMENT);
        $this->assertSame('BANK_REJECT', ImportConflict::CASE_BANK_REJECT);
        $this->assertSame('SIGNED_DOCUMENT', ImportConflict::CASE_SIGNED_DOCUMENT);
        $this->assertSame('OPEN', ImportConflict::STATUS_OPEN);
        $this->assertSame('SUPERSEDED', ImportConflict::STATUS_SUPERSEDED);
        $this->assertSame('CONFIRM_LARAVEL', ImportConflictProposal::ACTION_CONFIRM_LARAVEL);
        $this->assertSame('CORRECT_LARAVEL', ImportConflictProposal::ACTION_CORRECT_LARAVEL);
        $this->assertSame('LINK_BI', ImportConflictProposal::ACTION_LINK_BI);
        $this->assertSame('CURRENT_HOLDER', ImportConflictBooking::ROLE_CURRENT_HOLDER);
        $this->assertSame('PROPOSAL_SUBMITTED', ImportConflictEvent::TYPE_PROPOSAL_SUBMITTED);
        $this->assertSame('import_conflict_resolved', BookingAudit::ACTION_IMPORT_CONFLICT_RESOLVED);
    }

    public function test_json_evidence_fields_are_cast_to_arrays(): void
    {
        $conflict = new ImportConflict([
            'source_value' => ['status' => 'PERLU CEK'],
            'laravel_value' => ['status' => 'NEEDS_REVIEW'],
            'inventory_impact' => ['position' => 'BOOKED'],
        ]);
        $proposal = new ImportConflictProposal([
            'proposed_values' => ['current_booking_id' => 42],
            'before' => ['holder' => 41],
            'after' => ['holder' => 42],
        ]);
        $event = new ImportConflictEvent([
            'before' => ['status' => 'OPEN'],
            'after' => ['status' => 'RESOLVED'],
        ]);

        $this->assertIsArray($conflict->source_value);
        $this->assertIsArray($conflict->laravel_value);
        $this->assertIsArray($conflict->inventory_impact);
        $this->assertIsArray($proposal->proposed_values);
        $this->assertIsArray($proposal->before);
        $this->assertIsArray($proposal->after);
        $this->assertIsArray($event->before);
        $this->assertIsArray($event->after);
    }

    public function test_only_open_allocation_cases_block_lots(): void
    {
        $this->assertTrue((new ImportConflict([
            'case_type' => ImportConflict::CASE_COMPETING_CLAIMS,
            'status' => ImportConflict::STATUS_OPEN,
        ]))->blocksAllocation());
        $this->assertTrue((new ImportConflict([
            'case_type' => ImportConflict::CASE_STOCK_DISAGREEMENT,
            'status' => ImportConflict::STATUS_PROPOSED,
        ]))->blocksAllocation());
        $this->assertFalse((new ImportConflict([
            'case_type' => ImportConflict::CASE_UNLINKED_BI,
            'status' => ImportConflict::STATUS_OPEN,
        ]))->blocksAllocation());
        $this->assertFalse((new ImportConflict([
            'case_type' => ImportConflict::CASE_COMPETING_CLAIMS,
            'status' => ImportConflict::STATUS_RESOLVED,
        ]))->blocksAllocation());
    }

    public function test_proposal_idempotency_key_is_unique(): void
    {
        [$conflict, , $user] = $this->scenario();
        $attributes = [
            'import_conflict_id' => $conflict->id,
            'project_id' => $conflict->project_id,
            'proposed_by' => $user->id,
            'action' => ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            'reason' => 'Review',
            'evidence' => 'Evidence',
            'idempotency_key' => 'conflict-request-1',
            'request_hash' => hash('sha256', 'same'),
            'status' => ImportConflictProposal::STATUS_PENDING,
        ];
        ImportConflictProposal::query()->create($attributes);

        $this->expectException(QueryException::class);
        ImportConflictProposal::query()->create([
            ...$attributes,
            'import_conflict_id' => $this->scenario('other')[0]->id,
        ]);
    }

    public function test_only_one_pending_proposal_is_allowed_per_conflict(): void
    {
        [$conflict, , $user] = $this->scenario();
        $attributes = [
            'import_conflict_id' => $conflict->id,
            'project_id' => $conflict->project_id,
            'proposed_by' => $user->id,
            'action' => ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            'reason' => 'Review',
            'evidence' => 'Evidence',
            'request_hash' => hash('sha256', 'same'),
            'status' => ImportConflictProposal::STATUS_PENDING,
        ];
        ImportConflictProposal::query()->create([
            ...$attributes,
            'idempotency_key' => 'pending-1',
        ]);

        $this->expectException(QueryException::class);
        ImportConflictProposal::query()->create([
            ...$attributes,
            'idempotency_key' => 'pending-2',
        ]);
    }

    public function test_exact_booking_role_is_unique(): void
    {
        [$conflict, $booking] = $this->scenario();
        ImportConflictBooking::query()->create([
            'import_conflict_id' => $conflict->id,
            'booking_id' => $booking->id,
            'role' => ImportConflictBooking::ROLE_CLAIM,
        ]);

        $this->expectException(QueryException::class);
        ImportConflictBooking::query()->create([
            'import_conflict_id' => $conflict->id,
            'booking_id' => $booking->id,
            'role' => ImportConflictBooking::ROLE_CLAIM,
        ]);
    }

    public function test_linked_booking_deletion_is_restricted_to_preserve_audit_history(): void
    {
        [$conflict, $booking] = $this->scenario();
        ImportConflictBooking::query()->create([
            'import_conflict_id' => $conflict->id,
            'booking_id' => $booking->id,
            'role' => ImportConflictBooking::ROLE_CLAIM,
        ]);

        $this->expectException(QueryException::class);
        $booking->delete();
    }

    private function scenario(string $suffix = 'default'): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-'.uniqid(),
            'name' => 'Branch '.$suffix,
        ]);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-'.strtoupper(substr(sha1($suffix.uniqid()), 0, 10)),
            'name' => 'Project '.$suffix,
        ]);
        $user = User::query()->create([
            'name' => 'Reviewer',
            'email' => uniqid().'@test.local',
            'password' => 'password',
            'role' => User::ROLE_HQ,
            'is_active' => true,
        ]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'LOT-'.uniqid(),
            'source_key' => 'lot:'.uniqid(),
        ]);
        $buyer = Buyer::query()->create([
            'source_key' => 'buyer:'.uniqid(),
            'name' => 'Buyer',
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:'.uniqid(),
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => true,
        ]);
        $conflict = ImportConflict::query()->create([
            'project_id' => $project->id,
            'stable_source_identity' => 'identity:'.uniqid(),
            'case_type' => ImportConflict::CASE_COMPETING_CLAIMS,
            'status' => ImportConflict::STATUS_OPEN,
            'source_fingerprint' => hash('sha256', uniqid()),
        ]);

        return [$conflict, $booking, $user];
    }
}
