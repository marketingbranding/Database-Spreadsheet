<?php

namespace Tests\Feature;

use App\Exceptions\LotAllocationConflict;
use App\Models\AkadRecord;
use App\Models\BastRecord;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\ImportConflict;
use App\Models\ImportConflictBooking;
use App\Models\ImportConflictProposal;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;
use App\Services\Operations\ImportConflictWorkflow;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ImportConflictStagingRehearsalTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    private User $hq;

    private string $fixture;

    private ImportConflict $materialTarget;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'staging',
            'marison.import_conflicts_enabled' => true,
        ]);
        putenv('MARISON_OPERATIONAL_WRITES=true');

        $this->fixture = base_path('tests/Fixtures/kuwasen-regression.json');
        $branch = Branch::query()->create([
            'code' => 'JPR',
            'name' => 'Jepara',
            'city' => 'Jepara',
            'is_active' => true,
        ]);
        $this->project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Marison Regency Kuwasen',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $this->admin = User::query()->create([
            'name' => 'Rehearsal Branch Admin',
            'email' => 'rehearsal-branch@test.local',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->project);
        $this->hq = User::query()->create([
            'name' => 'Rehearsal HQ',
            'email' => 'rehearsal-hq@test.local',
            'password' => 'password',
            'role' => User::ROLE_HQ,
            'is_active' => true,
        ]);

        $this->importFixture($this->fixture);
        $this->materialTarget = ImportConflict::query()
            ->where('project_id', $this->project->id)
            ->where('case_type', ImportConflict::CASE_UNLINKED_BI)
            ->where('source_key', 'BI-')
            ->firstOrFail();
    }

    public function test_rehearsal_covers_pilot_cases_reimport_behavior_and_signed_preservation(): void
    {
        $this->assertGreaterThan(0, ImportConflict::query()->where('project_id', $this->project->id)->count());
        $this->assertDatabaseHas('import_conflicts', [
            'project_id' => $this->project->id,
            'case_type' => ImportConflict::CASE_COMPETING_CLAIMS,
        ]);
        $this->assertDatabaseHas('import_conflicts', [
            'project_id' => $this->project->id,
            'case_type' => ImportConflict::CASE_UNLINKED_BI,
        ]);
        $this->assertDatabaseHas('import_conflicts', [
            'project_id' => $this->project->id,
            'case_type' => ImportConflict::CASE_BANK_REJECT,
        ]);

        $this->assertAffectedLotBlockedWhileUnaffectedLotRemainsWritable();
        $this->assertBranchProposalAndHqConfirmationPreserveBookings();
        $this->assertApprovedCorrectionUsesExactFixtureCycles();
        $this->assertUnchangedReimportPreservesDecision();
        $this->assertMaterialSourceChangeOpensSuccessor();
        $this->assertSignedConflictPreservesDocuments();
    }

    private function assertAffectedLotBlockedWhileUnaffectedLotRemainsWritable(): void
    {
        $workflow = app(PsjbWorkflow::class);
        $disputed = ImportConflict::query()
            ->where('project_id', $this->project->id)
            ->where('case_type', ImportConflict::CASE_STOCK_DISAGREEMENT)
            ->where('status', ImportConflict::STATUS_OPEN)
            ->firstOrFail();
        $disputedLot = $disputed->lots()->firstOrFail();

        $unaffectedLot = $workflow->availableLots($this->project)->firstOrFail();
        $this->assertNotSame($disputedLot->id, $unaffectedLot->id);
        $this->assertFalse($workflow->validClaimQuery($disputedLot->id)->exists());

        DB::beginTransaction();
        try {
            $created = $workflow->create($this->admin, $this->project, [
                'lot_id' => $unaffectedLot->id,
                'buyer_name' => 'Rehearsal Unaffected Buyer',
                'sales_name' => 'Rehearsal Sales',
                'payment_method' => 'CASH',
                'psjb_date' => '2026-11-17',
            ]);
            $this->assertSame($unaffectedLot->id, $created->lot_id);

            try {
                $workflow->create($this->admin, $this->project, [
                    'lot_id' => $disputedLot->id,
                    'buyer_name' => 'Rehearsal Disputed Buyer',
                    'sales_name' => 'Rehearsal Sales',
                    'payment_method' => 'CASH',
                    'psjb_date' => '2026-11-17',
                ]);
                $this->fail('Expected disputed lot to be blocked.');
            } catch (LotAllocationConflict) {
                $this->assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    private function assertBranchProposalAndHqConfirmationPreserveBookings(): void
    {
        $conflict = ImportConflict::query()
            ->where('project_id', $this->project->id)
            ->where('case_type', ImportConflict::CASE_STOCK_DISAGREEMENT)
            ->where('status', ImportConflict::STATUS_OPEN)
            ->firstOrFail();
        $bookingCount = Booking::query()->where('project_id', $this->project->id)->count();
        $proposal = $this->propose($conflict, ImportConflictProposal::ACTION_CONFIRM_LARAVEL, [], 'rehearsal-confirm');
        app(ImportConflictWorkflow::class)->approve($this->hq, $proposal, [
            'approval_reason' => 'Laravel allocation is authoritative for this rehearsal.',
        ]);

        $this->assertSame(ImportConflict::STATUS_RESOLVED, $conflict->fresh()->status);
        $this->assertSame($conflict->source_fingerprint, $conflict->fresh()->reviewed_source_fingerprint);
        $this->assertSame($bookingCount, Booking::query()->where('project_id', $this->project->id)->count());
    }

    private function assertApprovedCorrectionUsesExactFixtureCycles(): void
    {
        $conflict = ImportConflict::query()
            ->where('project_id', $this->project->id)
            ->where('case_type', ImportConflict::CASE_COMPETING_CLAIMS)
            ->where('status', ImportConflict::STATUS_OPEN)
            ->whereHas('bookingLinks', fn ($q) => $q->where('role', ImportConflictBooking::ROLE_CURRENT_HOLDER))
            ->whereDoesntHave('bookingLinks.booking', fn ($q) => $q->whereHas('ppjb')
                ->orWhereHas('akad')
                ->orWhereHas('bast'))
            ->firstOrFail();

        $links = $conflict->bookingLinks()->with('booking')->get();
        $current = $links->firstWhere('role', ImportConflictBooking::ROLE_CURRENT_HOLDER)?->booking;
        $other = $links->firstWhere('role', ImportConflictBooking::ROLE_CLAIM)?->booking;
        if (! $current || ! $other) {
            $this->markTestSkipped('Fixture no longer contains a two-cycle competing claim.');
        }

        $proposal = $this->propose($conflict, ImportConflictProposal::ACTION_CORRECT_LARAVEL, [
            'current_booking_id' => $current->id,
            'dispositions' => [$other->id => 'MUNDUR'],
        ], 'rehearsal-correct');

        app(ImportConflictWorkflow::class)->approve($this->hq, $proposal, [
            'approval_reason' => 'Fixture-only correction rehearsal.',
        ]);

        $this->assertTrue($current->fresh()->is_current_holder);
        $this->assertSame(Booking::STATUS_MUNDUR, $other->fresh()->status);
        $this->assertSame(ImportConflict::STATUS_RESOLVED, $conflict->fresh()->status);
    }

    private function assertUnchangedReimportPreservesDecision(): void
    {
        $confirmed = ImportConflict::query()
            ->where('project_id', $this->project->id)
            ->where('status', ImportConflict::STATUS_RESOLVED)
            ->whereNotNull('reviewed_source_fingerprint')
            ->firstOrFail();
        $before = $confirmed->source_fingerprint;

        $this->importFixture($this->fixture);

        $this->assertSame(ImportConflict::STATUS_RESOLVED, $confirmed->fresh()->status);
        $this->assertSame($before, $confirmed->fresh()->source_fingerprint);
        $this->assertSame($before, $confirmed->fresh()->reviewed_source_fingerprint);
    }

    private function assertMaterialSourceChangeOpensSuccessor(): void
    {
        $staleProposal = $this->propose(
            $this->materialTarget,
            ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            [],
            'rehearsal-stale-approval'
        );
        $changed = tempnam(sys_get_temp_dir(), 'conflict-fixture-').'.json';
        $payload = json_decode((string) file_get_contents($this->fixture), true, flags: JSON_THROW_ON_ERROR);
        $tabs = $payload['tabs'] ?? $payload;
        $tab = $this->materialTarget->source_tab;
        $headerIndex = array_search('Keputusan', array_values((array) ($tabs[$tab][0] ?? [])), true);
        $rowIndex = (int) $this->materialTarget->source_row - 1;
        if ($headerIndex === false || ! isset($tabs[$tab][$rowIndex])) {
            $this->fail('Fixture no longer exposes the BI decision column.');
        }
        $tabs[$tab][$rowIndex][$headerIndex] = 'LANJUT';
        if (isset($payload['tabs'])) {
            $payload['tabs'] = $tabs;
            file_put_contents($changed, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            file_put_contents($changed, json_encode($tabs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $this->importFixture($changed);

        $this->assertDatabaseHas('import_conflicts', [
            'project_id' => $this->project->id,
            'supersedes_conflict_id' => $this->materialTarget->id,
            'status' => ImportConflict::STATUS_OPEN,
        ]);
        $this->assertSame(ImportConflict::STATUS_SUPERSEDED, $this->materialTarget->fresh()->status);

        try {
            app(ImportConflictWorkflow::class)->approve($this->hq, $staleProposal, [
                'approval_reason' => 'Should be blocked by supersession.',
            ]);
            $this->fail('Expected stale superseded proposal to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(ImportConflictProposal::STATUS_PENDING, $staleProposal->fresh()->status);
        unlink($changed);
    }

    private function assertSignedConflictPreservesDocuments(): void
    {
        $bast = BastRecord::query()->whereNotNull('bast_date')->firstOrFail();
        $booking = $bast->booking;
        $akad = AkadRecord::query()->where('booking_id', $booking->id)->first();
        $ppjb = PpjbRecord::query()->where('booking_id', $booking->id)->first();
        $before = [
            'bast_id' => $bast->id,
            'akad_id' => $akad?->id,
            'ppjb_id' => $ppjb?->id,
            'bast_akad_id' => $bast->akad_id,
        ];

        $conflict = ImportConflict::query()->create([
            'project_id' => $this->project->id,
            'stable_source_identity' => $this->project->code.'|SIGNED_DOCUMENT|BAST|rehearsal',
            'case_type' => ImportConflict::CASE_SIGNED_DOCUMENT,
            'status' => ImportConflict::STATUS_OPEN,
            'source_tab' => 'BAST',
            'source_row' => 10,
            'source_key' => 'BAST:'.$bast->id,
            'source_fingerprint' => hash('sha256', 'rehearsal-signed'),
        ]);
        $conflict->bookingLinks()->create([
            'booking_id' => $booking->id,
            'role' => ImportConflictBooking::ROLE_DOCUMENT_OWNER,
        ]);

        $proposal = $this->propose($conflict, ImportConflictProposal::ACTION_ESCALATE_SIGNED_DOCUMENT, [], 'rehearsal-signed');
        app(ImportConflictWorkflow::class)->approve($this->hq, $proposal, [
            'approval_reason' => 'Legal review required.',
        ]);

        $afterBast = BastRecord::query()->findOrFail($bast->id);
        $this->assertSame($before['bast_id'], $afterBast->id);
        $this->assertSame($before['akad_id'], AkadRecord::query()->where('booking_id', $booking->id)->first()?->id);
        $this->assertSame($before['ppjb_id'], PpjbRecord::query()->where('booking_id', $booking->id)->first()?->id);
        $this->assertSame($before['bast_akad_id'], $afterBast->akad_id);
        $this->assertSame(ImportConflict::STATUS_ESCALATED, $conflict->fresh()->status);
    }

    private function propose(
        ImportConflict $conflict,
        string $action,
        array $values,
        string $key,
    ): ImportConflictProposal {
        return app(ImportConflictWorkflow::class)->propose($this->admin, $conflict, [
            'action' => $action,
            'reason' => 'Staging rehearsal',
            'evidence' => 'Fixture evidence bundle',
            'source_reference' => $conflict->source_tab.':'.$conflict->source_row,
            'idempotency_key' => $key,
            'proposed_values' => $values,
        ]);
    }

    private function importFixture(string $path): void
    {
        (new ProjectImporter(new FixtureSheetReader($path)))->run($this->project, false);
    }
}
