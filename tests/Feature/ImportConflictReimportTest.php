<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\ImportBatch;
use App\Models\ImportConflict;
use App\Models\ImportConflictEvent;
use App\Models\ImportIssue;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Import\ImportConflictProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportConflictReimportTest extends TestCase
{
    use RefreshDatabase;

    public function test_unchanged_confirmed_source_reuses_resolution_and_resolves_new_issue(): void
    {
        [$project, $lot] = $this->claimScenario();
        $projector = app(ImportConflictProjector::class);
        $rawTabs = $this->claimRows('  PERLU   CEK ');
        $firstIssue = $this->issue($project, 1);

        $projector->persistCurrent($project, [$firstIssue], $rawTabs, []);
        $conflict = ImportConflict::query()->firstOrFail();
        $conflict->update([
            'status' => ImportConflict::STATUS_RESOLVED,
            'reviewed_source_fingerprint' => $conflict->source_fingerprint,
            'resolution_type' => ImportConflict::RESOLUTION_CONFIRM_LARAVEL,
            'resolved_at' => now(),
        ]);
        $secondIssue = $this->issue($project, 2);

        $projector->persistCurrent($project, [$secondIssue], $this->claimRows('perlu cek'), []);

        $this->assertSame(1, ImportConflict::query()->count());
        $this->assertSame(ImportConflict::STATUS_RESOLVED, $conflict->fresh()->status);
        $this->assertSame($secondIssue->id, $conflict->fresh()->current_import_issue_id);
        $this->assertNotNull($secondIssue->fresh()->resolved_at);
        $this->assertTrue($conflict->fresh()->lots->contains($lot));
    }

    public function test_material_source_change_opens_successor_and_preserves_prior_decision(): void
    {
        [$project] = $this->claimScenario();
        $projector = app(ImportConflictProjector::class);
        $issue = $this->issue($project, 1);
        $projector->persistCurrent($project, [$issue], $this->claimRows('PERLU CEK'), []);
        $prior = ImportConflict::query()->firstOrFail();
        $prior->update([
            'status' => ImportConflict::STATUS_RESOLVED,
            'reviewed_source_fingerprint' => $prior->source_fingerprint,
            'resolution_type' => ImportConflict::RESOLUTION_CONFIRM_LARAVEL,
            'resolved_at' => now(),
        ]);

        $projector->persistCurrent($project, [$this->issue($project, 2)], $this->claimRows('LANJUT'), []);

        $this->assertSame(2, ImportConflict::query()->count());
        $successor = ImportConflict::query()->where('status', ImportConflict::STATUS_OPEN)->firstOrFail();
        $this->assertSame($prior->id, $successor->supersedes_conflict_id);
        $this->assertSame(ImportConflict::STATUS_RESOLVED, $prior->fresh()->status);
        $this->assertSame(ImportConflict::RESOLUTION_CONFIRM_LARAVEL, $prior->fresh()->resolution_type);
    }

    public function test_material_source_change_supersedes_an_unresolved_prior_version(): void
    {
        [$project] = $this->claimScenario();
        $projector = app(ImportConflictProjector::class);
        $projector->persistCurrent($project, [$this->issue($project, 1)], $this->claimRows('PERLU CEK'), []);
        $prior = ImportConflict::query()->firstOrFail();

        $projector->persistCurrent($project, [$this->issue($project, 2)], $this->claimRows('LANJUT'), []);

        $this->assertSame(ImportConflict::STATUS_SUPERSEDED, $prior->fresh()->status);
        $this->assertDatabaseHas('import_conflict_events', [
            'import_conflict_id' => $prior->id,
            'event_type' => ImportConflictEvent::TYPE_SOURCE_CHANGED,
        ]);
    }

    public function test_source_reverting_to_reviewed_fingerprint_preserves_decision_and_supersedes_intermediate_review(): void
    {
        [$project] = $this->claimScenario();
        $projector = app(ImportConflictProjector::class);
        $projector->persistCurrent($project, [$this->issue($project, 1)], $this->claimRows('PERLU CEK'), []);
        $confirmed = ImportConflict::query()->firstOrFail();
        $confirmed->update([
            'status' => ImportConflict::STATUS_RESOLVED,
            'reviewed_source_fingerprint' => $confirmed->source_fingerprint,
            'resolution_type' => ImportConflict::RESOLUTION_CONFIRM_LARAVEL,
            'resolved_at' => now(),
        ]);
        $projector->persistCurrent($project, [$this->issue($project, 2)], $this->claimRows('LANJUT'), []);
        $intermediate = ImportConflict::query()->where('status', ImportConflict::STATUS_OPEN)->firstOrFail();

        $projector->persistCurrent($project, [$this->issue($project, 3)], $this->claimRows('PERLU CEK'), []);

        $this->assertSame(2, ImportConflict::query()->count());
        $this->assertSame(ImportConflict::STATUS_RESOLVED, $confirmed->fresh()->status);
        $this->assertSame(ImportConflict::STATUS_SUPERSEDED, $intermediate->fresh()->status);
    }

    public function test_fingerprint_normalizes_case_spacing_and_date_formatting(): void
    {
        $projector = app(ImportConflictProjector::class);

        $first = $projector->fingerprint(ImportConflict::CASE_UNLINKED_BI, [
            'status' => '  REJECT ',
            'date' => '03 Sep 2026',
        ]);
        $second = $projector->fingerprint(ImportConflict::CASE_UNLINKED_BI, [
            'date' => '3 september 2026',
            'status' => 'reject',
        ]);

        $this->assertSame($first, $second);
    }

    public function test_bank_reject_closes_only_after_existing_booking_decision_is_recorded(): void
    {
        [$project, $lot, $booking] = $this->claimScenario();
        $bankConflict = ImportConflict::query()->create([
            'project_id' => $project->id,
            'stable_source_identity' => $project->code.'|BANK_REJECT|Proses Bank|bank-1',
            'case_type' => ImportConflict::CASE_BANK_REJECT,
            'status' => ImportConflict::STATUS_OPEN,
            'source_tab' => 'Proses Bank',
            'source_key' => $booking->source_booking_key,
            'source_fingerprint' => hash('sha256', 'bank'),
        ]);
        $bankConflict->lots()->attach($lot);
        $other = ImportConflict::query()->create([
            'project_id' => $project->id,
            'stable_source_identity' => $project->code.'|UNLINKED_BI|BI Checking|orphan',
            'case_type' => ImportConflict::CASE_UNLINKED_BI,
            'status' => ImportConflict::STATUS_OPEN,
            'source_tab' => 'BI Checking',
            'source_fingerprint' => hash('sha256', 'orphan'),
        ]);
        $booking->update([
            'status' => Booking::STATUS_MUNDUR,
            'decision_stage' => Booking::STAGE_BANK,
            'decision_date' => '2026-11-17',
            'is_current_holder' => false,
        ]);

        app(ImportConflictProjector::class)->persistCurrent($project, [], [], []);

        $this->assertSame(ImportConflict::STATUS_RESOLVED, $bankConflict->fresh()->status);
        $this->assertSame(ImportConflict::RESOLUTION_BOOKING_DECISION, $bankConflict->fresh()->resolution_type);
        $this->assertSame(ImportConflict::STATUS_OPEN, $other->fresh()->status);
        $this->assertDatabaseHas('import_conflict_events', [
            'import_conflict_id' => $bankConflict->id,
            'event_type' => ImportConflictEvent::TYPE_BOOKING_DECISION_RESOLVED,
        ]);
    }

    public function test_preview_never_persists_conflicts_or_events(): void
    {
        [$project] = $this->claimScenario();

        $descriptors = app(ImportConflictProjector::class)
            ->preview($project, [$this->issue($project, 1)], $this->claimRows('PERLU CEK'), []);

        $this->assertCount(1, $descriptors);
        $this->assertSame(0, ImportConflict::query()->count());
        $this->assertSame(0, ImportConflictEvent::query()->count());
    }

    private function claimScenario(): array
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
        ]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'M22',
            'source_key' => 'LOT:M22',
        ]);
        $buyer = Buyer::query()->create(['source_key' => 'BUYER:1', 'name' => 'Norma']);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'PSJB:M22',
            'source_transaction_id' => 'TRX-M22',
            'booking_date' => '2026-10-14',
            'payment_method' => 'KPR',
            'status' => Booking::STATUS_NEEDS_REVIEW,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => true,
            'source_owner' => Booking::OWNER_SHEETS,
        ]);

        return [$project, $lot, $booking];
    }

    private function issue(Project $project, int $batchSequence): ImportIssue
    {
        $batch = ImportBatch::query()->create([
            'project_id' => $project->id,
            'mode' => 'apply',
            'source' => 'fixture:'.$batchSequence,
            'status' => 'completed',
        ]);

        return ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'project_id' => $project->id,
            'severity' => ImportIssue::SEVERITY_WARNING,
            'code' => 'multiple_active_bookings',
            'message' => 'More than one open booking exists for this lot.',
            'source_tab' => 'PSJB',
            'source_row' => 2,
            'source_key' => 'PSJB:M22',
            'context' => ['lot' => 'M22', 'bookings' => 2],
        ]);
    }

    private function claimRows(string $status): array
    {
        return [
            'PSJB' => [
                ['Nama', 'Kavling', 'Status', '[SYS] ID Transaksi', '[AUTO] No. PSJB'],
                ['Norma', 'M22', $status, 'TRX-M22', 'M22'],
            ],
        ];
    }
}
