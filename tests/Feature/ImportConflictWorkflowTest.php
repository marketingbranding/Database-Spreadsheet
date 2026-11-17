<?php

namespace Tests\Feature;

use App\Exceptions\LotAllocationConflict;
use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BookingTransition;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\ImportConflict;
use App\Models\ImportConflictBooking;
use App\Models\ImportConflictEvent;
use App\Models\ImportConflictProposal;
use App\Models\Lot;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\ImportConflictWorkflow;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ImportConflictWorkflowTest extends TestCase
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

    public function test_allocation_disputes_block_only_their_lots_and_create_rechecks_under_lock(): void
    {
        [$project, $admin, $blockedLot, , , $claim] = $this->scenario();
        $freeLot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'FREE-1',
            'source_key' => 'LOT:FREE-1',
        ]);
        $this->allocationConflict($project, $blockedLot, ImportConflict::CASE_COMPETING_CLAIMS, [$claim->id]);

        foreach ([ImportConflict::CASE_UNLINKED_BI, ImportConflict::CASE_BANK_REJECT, ImportConflict::CASE_SIGNED_DOCUMENT] as $case) {
            $this->conflict($project, $freeLot, $case, 'non-allocation-'.$case);
        }

        $available = app(PsjbWorkflow::class)->availableLots($project)->pluck('id')->all();
        $this->assertNotContains($blockedLot->id, $available);
        $this->assertContains($freeLot->id, $available);

        try {
            app(PsjbWorkflow::class)->create($admin, $project, $this->createPayload($blockedLot));
            $this->fail('Expected blocked lot to be rejected.');
        } catch (LotAllocationConflict) {
            $this->assertTrue(true);
        }
    }

    public function test_unresolved_allocation_conflict_blocks_a_lot_with_no_claims(): void
    {
        [$project, $admin, $disputedLot] = $this->scenario();
        $cleanLot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'CLEAN-1',
            'source_key' => 'LOT:CLEAN-1',
        ]);
        $this->allocationConflict($project, $disputedLot, ImportConflict::CASE_COMPETING_CLAIMS, []);

        $available = app(PsjbWorkflow::class)->availableLots($project)->pluck('id')->all();
        $this->assertNotContains($disputedLot->id, $available);
        $this->assertContains($cleanLot->id, $available);

        try {
            app(PsjbWorkflow::class)->create($admin, $project, $this->createPayload($disputedLot));
            $this->fail('Expected blocked lot to be rejected.');
        } catch (LotAllocationConflict) {
            $this->assertTrue(true);
        }
    }

    public function test_transfer_into_lot_with_unresolved_allocation_dispute_is_rejected(): void
    {
        [$project, $admin] = $this->scenario();
        $sourceLot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'TRANSFER-SOURCE',
            'source_key' => 'LOT:TRANSFER-SOURCE',
        ]);
        $source = $this->booking($project, $sourceLot, 'transfer-source', true);
        $destination = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'BLOCKED-DEST',
            'source_key' => 'LOT:BLOCKED-DEST',
        ]);
        $this->allocationConflict($project, $destination, ImportConflict::CASE_STOCK_DISAGREEMENT, []);

        $before = Booking::query()->where('project_id', $project->id)->count();
        try {
            app(PsjbWorkflow::class)->moveLot($admin, $source, [
                'lot_id' => $destination->id,
                'effective_date' => '2026-11-17',
                'reason' => 'Should be blocked',
                'idempotency_key' => 'blocked-destination-'.uniqid(),
            ]);
            $this->fail('Expected blocked destination to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame($before, Booking::query()->where('project_id', $project->id)->count());
        $this->assertSame($source->lot_id, $source->fresh()->lot_id);
        $this->assertTrue($source->fresh()->is_current_holder);
        $this->assertSame(0, BookingTransition::query()->where('source_booking_id', $source->id)->count());
    }

    public function test_stock_disagreement_also_blocks_its_lot(): void
    {
        [$project, , $blockedLot, , , $claim] = $this->scenario();
        $this->allocationConflict($project, $blockedLot, ImportConflict::CASE_STOCK_DISAGREEMENT, [$claim->id]);

        $this->assertFalse(app(PsjbWorkflow::class)
            ->availableLots($project)
            ->whereKey($blockedLot->id)
            ->exists());
    }

    public function test_approving_link_bi_uses_exact_booking_and_canonical_source_fields(): void
    {
        [$project, , $lot, $admin, $hq, , $claim] = $this->scenario();
        $conflict = $this->conflict(
            $project,
            $lot,
            ImportConflict::CASE_UNLINKED_BI,
            'bi-case',
            [
                'source_tab' => 'BI Checking',
                'source_row' => 5,
                'source_value' => [
                    '[SYS] ID BI' => 'BI-123',
                    'Tanggal BI' => '03 Sep 2026',
                    'Hasil SLIK' => 'KOL 5',
                    'Keputusan' => 'REJECT',
                ],
            ]
        );
        $conflict->bookingLinks()->create([
            'booking_id' => $claim->id,
            'role' => ImportConflictBooking::ROLE_LINK_TARGET,
        ]);
        $proposal = $this->propose($admin, $conflict, ImportConflictProposal::ACTION_LINK_BI, [
            'booking_id' => $claim->id,
        ], 'bi-proposal-1');

        app(ImportConflictWorkflow::class)->approve($hq, $proposal, [
            'approval_reason' => 'BI row belongs to this cycle.',
        ]);

        $bi = BiCheck::query()->where('booking_id', $claim->id)->firstOrFail();
        $this->assertSame(BiCheck::OWNER_LARAVEL, $bi->source_owner);
        $this->assertSame('BI-123', $bi->bi_number);
        $this->assertSame('2026-09-03', $bi->check_date->toDateString());
        $this->assertSame('KOL 5', $bi->result);
        $this->assertSame('TIDAK LANJUT', $bi->decision);
        $this->assertSame(ImportConflict::STATUS_RESOLVED, $conflict->fresh()->status);
        $this->assertSame(ImportConflict::RESOLUTION_LINK_BI, $conflict->fresh()->resolution_type);
        $this->assertSame(Booking::STAGE_BI, $claim->fresh()->current_stage);
        $this->assertDatabaseHas('booking_audits', [
            'booking_id' => $claim->id,
            'action' => BookingAudit::ACTION_BI_CHECKED,
        ]);
    }

    public function test_link_bi_refuses_ambiguous_or_laravel_owned_target(): void
    {
        [$project, , $lot, $admin, $hq, , $claim] = $this->scenario();
        $conflict = $this->conflict($project, $lot, ImportConflict::CASE_UNLINKED_BI, 'bi-ambiguous');
        $proposal = $this->propose($admin, $conflict, ImportConflictProposal::ACTION_LINK_BI, [], 'bi-proposal-ambiguous');
        try {
            app(ImportConflictWorkflow::class)->approve($hq, $proposal, ['approval_reason' => 'Proceed']);
            $this->fail('Expected ambiguous BI target to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $biConflict = $this->conflict($project, $lot, ImportConflict::CASE_UNLINKED_BI, 'bi-owned');
        $biConflict->bookingLinks()->create([
            'booking_id' => $claim->id,
            'role' => ImportConflictBooking::ROLE_LINK_TARGET,
        ]);
        BiCheck::query()->create([
            'booking_id' => $claim->id,
            'project_id' => $project->id,
            'source_key' => 'LARAVEL:BI:existing',
            'check_date' => '2026-09-01',
            'decision' => 'LANJUT',
            'source_owner' => BiCheck::OWNER_LARAVEL,
        ]);
        $proposal = $this->propose($admin, $biConflict, ImportConflictProposal::ACTION_LINK_BI, [
            'booking_id' => $claim->id,
        ], 'bi-proposal-owned');
        try {
            app(ImportConflictWorkflow::class)->approve($hq, $proposal, ['approval_reason' => 'Proceed']);
            $this->fail('Expected Laravel-owned BI to block relinking.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_approved_correction_disposes_other_claims_and_keeps_exact_cycle_as_holder(): void
    {
        [$project, , $lot, $admin, $hq, , $current, $other] = $this->scenario();
        $conflict = $this->allocationConflict($project, $lot, ImportConflict::CASE_COMPETING_CLAIMS, [$current->id, $other->id]);
        $before = BookingAudit::snapshot($current->fresh());
        $proposal = $this->propose($admin, $conflict, ImportConflictProposal::ACTION_CORRECT_LARAVEL, [
            'current_booking_id' => $current->id,
            'dispositions' => [$other->id => 'MUNDUR'],
        ], 'correction-1');

        app(ImportConflictWorkflow::class)->approve($hq, $proposal, [
            'approval_reason' => 'HQ approved current holder.',
        ]);

        $this->assertSame(Booking::STATUS_NEEDS_REVIEW, $current->fresh()->status);
        $this->assertTrue($current->fresh()->is_current_holder);
        $this->assertSame(Booking::STATUS_MUNDUR, $other->fresh()->status);
        $this->assertFalse($other->fresh()->is_current_holder);
        $this->assertSame('BOOKED', $lot->fresh()->inventoryPosition());
        $this->assertDatabaseHas('booking_audits', [
            'booking_id' => $other->id,
            'action' => BookingAudit::ACTION_WITHDRAWN,
        ]);
        $this->assertDatabaseHas('booking_audits', [
            'booking_id' => $current->id,
            'action' => BookingAudit::ACTION_IMPORT_CONFLICT_RESOLVED,
            'before' => json_encode($before),
        ]);
        $event = ImportConflictEvent::query()
            ->where('import_conflict_id', $conflict->id)
            ->where('event_type', ImportConflictEvent::TYPE_RESOLUTION_APPLIED)
            ->firstOrFail();
        $this->assertSame($hq->id, $event->actor_id);
        $this->assertSame($proposal->id, $event->import_conflict_proposal_id);
        $this->assertSame('HQ approved current holder.', $event->reason);
    }

    public function test_correction_rejects_unlinked_booking_ids_and_stale_claims_or_fingerprint(): void
    {
        [$project, , $lot, $admin, $hq, , $current, $other] = $this->scenario();
        $conflict = $this->allocationConflict($project, $lot, ImportConflict::CASE_COMPETING_CLAIMS, [$current->id]);
        $foreign = $this->booking($project, $lot, 'foreign');

        $proposal = $this->propose($admin, $conflict, ImportConflictProposal::ACTION_CORRECT_LARAVEL, [
            'current_booking_id' => $current->id,
            'dispositions' => [$foreign->id => 'MUNDUR'],
        ], 'correction-unlinked');
        try {
            app(ImportConflictWorkflow::class)->approve($hq, $proposal, ['approval_reason' => 'Approve']);
            $this->fail('Expected unlinked booking to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(Booking::STATUS_ACTIVE, $foreign->fresh()->status);

        $proposal->update(['status' => ImportConflictProposal::STATUS_REJECTED, 'pending_guard' => null]);
        $conflict->update(['source_fingerprint' => hash('sha256', 'changed-after-proposal')]);
        $staleProposal = $this->propose($admin, $conflict->fresh(), ImportConflictProposal::ACTION_CORRECT_LARAVEL, [
            'current_booking_id' => $current->id,
            'dispositions' => [$other->id => 'MUNDUR'],
        ], 'correction-stale-fingerprint');
        $conflict->update(['source_fingerprint' => hash('sha256', 'changed-again')]);
        try {
            app(ImportConflictWorkflow::class)->approve($hq, $staleProposal, ['approval_reason' => 'Approve']);
            $this->fail('Expected stale fingerprint to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_resolution_releases_only_its_conflict_and_preserves_active_holder_availability(): void
    {
        [$project, , $lot, $admin, $hq, , $current, $other] = $this->scenario();
        $first = $this->allocationConflict($project, $lot, ImportConflict::CASE_COMPETING_CLAIMS, [$current->id, $other->id]);
        $second = $this->allocationConflict($project, $lot, ImportConflict::CASE_STOCK_DISAGREEMENT, [$current->id], 'second');
        $proposal = $this->propose($admin, $first, ImportConflictProposal::ACTION_CORRECT_LARAVEL, [
            'current_booking_id' => $current->id,
            'dispositions' => [$other->id => 'MUNDUR'],
        ], 'release-1');

        app(ImportConflictWorkflow::class)->approve($hq, $proposal, ['approval_reason' => 'Approved']);

        $this->assertFalse(app(PsjbWorkflow::class)->availableLots($project)->whereKey($lot->id)->exists());
        $this->assertTrue($second->fresh()->blocksAllocation());
        $second->update([
            'status' => ImportConflict::STATUS_RESOLVED,
            'resolution_type' => ImportConflict::RESOLUTION_CONFIRM_LARAVEL,
        ]);
        $this->assertFalse(app(PsjbWorkflow::class)->availableLots($project)->whereKey($lot->id)->exists());
        $this->assertSame('BOOKED', $lot->fresh()->inventoryPosition());
    }

    public function test_signed_document_conflicts_allow_escalation_but_reject_mutation(): void
    {
        [$project, , $lot, $admin, $hq, , $current] = $this->scenario();
        PpjbRecord::query()->create([
            'booking_id' => $current->id,
            'project_id' => $project->id,
            'source_key' => 'PPJB:signed-1',
            'ppjb_number' => 'PPJB-1',
            'signed_date' => '2026-11-17',
            'source_owner' => PpjbRecord::OWNER_LARAVEL,
        ]);
        $conflict = $this->conflict($project, $lot, ImportConflict::CASE_SIGNED_DOCUMENT, 'signed-case', [
            'source_tab' => 'PPJB Developer',
        ]);
        $conflict->bookingLinks()->create([
            'booking_id' => $current->id,
            'role' => ImportConflictBooking::ROLE_DOCUMENT_OWNER,
        ]);

        $escalation = $this->propose($admin, $conflict, ImportConflictProposal::ACTION_ESCALATE_SIGNED_DOCUMENT, [], 'signed-escalation');
        app(ImportConflictWorkflow::class)->approve($hq, $escalation, ['approval_reason' => 'Legal review required.']);
        $this->assertSame(ImportConflict::STATUS_ESCALATED, $conflict->fresh()->status);
        $this->assertNotNull($current->fresh()->ppjb);

        $confirm = $this->propose($admin, $conflict->fresh(), ImportConflictProposal::ACTION_CONFIRM_LARAVEL, [], 'signed-confirm');
        app(ImportConflictWorkflow::class)->approve($hq, $confirm, ['approval_reason' => 'Existing links verified.']);
        $this->assertSame(ImportConflict::STATUS_RESOLVED, $conflict->fresh()->status);
        $this->assertNotNull($current->fresh()->ppjb);

        try {
            $this->propose($admin, $conflict->fresh(), ImportConflictProposal::ACTION_CORRECT_LARAVEL, [
                'current_booking_id' => $current->id,
                'dispositions' => [],
            ], 'signed-mutation');
            $this->fail('Expected signed mutation proposal to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertNotNull($current->fresh()->ppjb);
    }

    public function test_conflict_resolution_rejects_non_allowlisted_disposition_status(): void
    {
        [$project, , $lot, , $hq, , , $other] = $this->scenario();
        $conflict = $this->allocationConflict($project, $lot, ImportConflict::CASE_COMPETING_CLAIMS, [$other->id]);

        try {
            app(PsjbWorkflow::class)->applyConflictClaimResolution($hq, $project, [
                'conflict_id' => $conflict->id,
                'current_booking_id' => $other->id,
                'dispositions' => [$other->id => 'APPROVE'],
                'reason' => 'Invalid',
                'source_reference' => 'PSJB:2',
            ]);
            $this->fail('Expected invalid disposition status to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(Booking::STATUS_ACTIVE, $other->fresh()->status);
        $this->assertFalse($other->fresh()->is_current_holder);
    }

    public function test_injected_failure_rolls_back_booking_audit_proposal_event_and_conflict(): void
    {
        [$project, , $lot, $admin, $hq, , $current, $other] = $this->scenario();
        $conflict = $this->allocationConflict($project, $lot, ImportConflict::CASE_COMPETING_CLAIMS, [$current->id, $other->id]);
        $proposal = $this->propose($admin, $conflict, ImportConflictProposal::ACTION_CORRECT_LARAVEL, [
            'current_booking_id' => $current->id,
            'dispositions' => [$other->id => 'MUNDUR'],
        ], 'rollback-1');
        $auditCount = BookingAudit::query()->count();
        $eventCount = ImportConflictEvent::query()->count();

        $thrown = false;
        Booking::updated(function () use (&$thrown): void {
            if (! $thrown) {
                $thrown = true;
                throw new \RuntimeException('Injected failure');
            }
        });

        try {
            app(ImportConflictWorkflow::class)->approve($hq, $proposal, ['approval_reason' => 'Approve']);
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected failure', $exception->getMessage());
        } finally {
            Booking::flushEventListeners();
        }

        $this->assertSame(Booking::STATUS_NEEDS_REVIEW, $current->fresh()->status);
        $this->assertSame(Booking::STATUS_ACTIVE, $other->fresh()->status);
        $this->assertSame($auditCount, BookingAudit::query()->count());
        $this->assertSame($eventCount, ImportConflictEvent::query()->count());
        $this->assertSame(ImportConflictProposal::STATUS_PENDING, $proposal->fresh()->status);
        $this->assertSame(ImportConflict::STATUS_PROPOSED, $conflict->fresh()->status);
    }

    private function scenario(): array
    {
        $branch = Branch::query()->create(['code' => 'JPR-'.uniqid(), 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
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
            'source_key' => 'LOT:M22',
        ]);
        $current = $this->booking($project, $lot, 'current', true, Booking::STATUS_NEEDS_REVIEW);
        $other = $this->booking($project, $lot, 'other', false);

        return [$project, $admin, $lot, $admin, $hq, $current, $current, $other];
    }

    private function booking(
        Project $project,
        Lot $lot,
        string $suffix,
        bool $holder = false,
        string $status = Booking::STATUS_ACTIVE,
    ): Booking {
        $buyer = Buyer::query()->create([
            'source_key' => $project->code.':BUYER:'.uniqid(),
            'name' => 'Buyer '.$suffix,
        ]);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'PSJB:'.uniqid(),
            'source_transaction_id' => 'TRX-'.uniqid(),
            'booking_date' => '2026-10-14',
            'payment_method' => 'KPR',
            'status' => $status,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => $holder,
            'source_owner' => Booking::OWNER_SHEETS,
        ]);
    }

    private function conflict(
        Project $project,
        Lot $lot,
        string $caseType,
        string $suffix,
        array $extra = [],
    ): ImportConflict {
        $conflict = ImportConflict::query()->create(array_merge([
            'project_id' => $project->id,
            'stable_source_identity' => $project->code.'|'.$caseType.'|'.$suffix,
            'case_type' => $caseType,
            'status' => ImportConflict::STATUS_OPEN,
            'severity' => 'warning',
            'source_tab' => 'PSJB',
            'source_row' => 2,
            'source_key' => 'PSJB:'.$suffix,
            'source_fingerprint' => hash('sha256', $caseType.$suffix),
            'source_value' => ['Status' => 'PERLU CEK'],
            'laravel_value' => ['status' => 'NEEDS_REVIEW'],
            'inventory_impact' => ['summary' => 'BOOKED'],
        ], $extra));
        $conflict->lots()->attach($lot);

        return $conflict;
    }

    private function allocationConflict(
        Project $project,
        Lot $lot,
        string $caseType,
        array $bookingIds,
        string $suffix = 'allocation',
    ): ImportConflict {
        $conflict = $this->conflict($project, $lot, $caseType, $suffix);
        foreach ($bookingIds as $index => $bookingId) {
            $conflict->bookingLinks()->create([
                'booking_id' => $bookingId,
                'role' => $index === 0 ? ImportConflictBooking::ROLE_CURRENT_HOLDER : ImportConflictBooking::ROLE_CLAIM,
            ]);
        }

        return $conflict;
    }

    private function propose(
        User $admin,
        ImportConflict $conflict,
        string $action,
        array $values,
        string $key,
    ): ImportConflictProposal {
        return app(ImportConflictWorkflow::class)->propose($admin, $conflict, [
            'action' => $action,
            'reason' => 'Branch review',
            'evidence' => 'Evidence bundle reviewed',
            'source_reference' => 'PSJB:2',
            'idempotency_key' => $key,
            'proposed_values' => $values,
        ]);
    }

    private function createPayload(Lot $lot): array
    {
        return [
            'lot_id' => $lot->id,
            'buyer_name' => 'New Buyer',
            'sales_name' => 'Sales',
            'payment_method' => 'CASH',
            'psjb_date' => '2026-11-20',
        ];
    }
}
