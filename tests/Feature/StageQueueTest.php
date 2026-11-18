<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\ImportConflict;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use App\Services\Operations\StageQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageQueueTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');

        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $this->project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->admin = User::query()->create([
            'name' => 'Branch Admin', 'email' => 'admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->project);
    }

    public function test_staging_pilot_helpers_flag_only_pilot_laravel_projects(): void
    {
        $this->assertSame(['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], Project::stagingPilotCodes());
        $this->assertTrue($this->project->isOperationalStaging());

        $sheets = Project::query()->create([
            'branch_id' => $this->project->branch_id,
            'code' => 'PRJ-OTHER-SHEETS',
            'name' => 'Sheets Project',
            'management_mode' => Project::MANAGEMENT_SHEETS,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->assertFalse($sheets->isOperationalStaging());
    }

    public function test_psjb_queue_lists_only_active_bookings_at_psjb_stage(): void
    {
        $active = $this->booking('active', Booking::STATUS_ACTIVE, Booking::STAGE_PSJB);
        $this->booking('withdrawn', Booking::STATUS_MUNDUR, Booking::STAGE_PSJB);
        $this->booking('advanced', Booking::STATUS_ACTIVE, Booking::STAGE_BI);

        $queue = app(StageQueue::class)->psjbQueue($this->project)->get();

        $this->assertSame([$active->id], $queue->pluck('id')->all());
        $this->assertSame(1, app(StageQueue::class)->psjbQueueCount($this->project));
    }

    public function test_queue_counts_match_the_eligibility_queries(): void
    {
        $workflow = app(PsjbWorkflow::class);
        $this->booking('psjb', Booking::STATUS_ACTIVE, Booking::STAGE_PSJB);
        $this->booking('bi', Booking::STATUS_ACTIVE, Booking::STAGE_BI);
        $this->booking('completed', Booking::STATUS_COMPLETED, Booking::STAGE_BAST);

        $counts = app(StageQueue::class)->queueCounts($this->project, $workflow);

        $this->assertSame(1, $counts['psjb']);
        $this->assertSame($workflow->eligibleBiBookings($this->project)->count(), $counts['bi']);
        $this->assertSame($workflow->eligiblePemberkasanBookings($this->project)->count(), $counts['pemberkasan']);
        $this->assertSame($workflow->eligibleBankBookings($this->project)->count(), $counts['bank']);
        $this->assertSame($workflow->eligibleTtdPpjbBookings($this->project)->count(), $counts['ppjb']);
        $this->assertSame($workflow->eligibleAkadBookings($this->project)->count(), $counts['akad']);
        $this->assertSame($workflow->eligibleBastBookings($this->project)->count(), $counts['bast']);
    }

    public function test_block_reason_reports_open_allocation_dispute_on_the_lot(): void
    {
        $lot = Lot::query()->create(['project_id' => $this->project->id, 'code' => 'L02', 'source_key' => 'LOT:L02']);
        $booking = $this->booking('disputed', Booking::STATUS_ACTIVE, Booking::STAGE_PSJB, $lot->id);
        $this->conflict($lot, 'STOCK_DISAGREEMENT', 'stock');

        $blocked = app(StageQueue::class)->blockReason($this->admin, $booking, true, app(PsjbWorkflow::class));

        $this->assertNotNull($blocked);
        $this->assertSame('Kavling memiliki konflik alokasi yang belum selesai.', $blocked['reason']);
        $this->assertInstanceOf(ImportConflict::class, $blocked['conflict']);
    }

    public function test_block_reason_is_null_for_a_clean_cash_booking(): void
    {
        $booking = $this->booking('cash', Booking::STATUS_ACTIVE, Booking::STAGE_PSJB);

        $this->assertNull(app(StageQueue::class)->blockReason($this->admin, $booking, true, app(PsjbWorkflow::class)));
    }

    private function booking(string $suffix, string $status, string $stage, ?int $lotId = null): Booking
    {
        $lotId ??= Lot::query()->create([
            'project_id' => $this->project->id,
            'code' => 'LOT-'.strtoupper($suffix),
            'source_key' => 'LOT:'.uniqid(),
        ])->id;
        $buyer = Buyer::query()->create([
            'source_key' => $this->project->code.':BUYER:'.uniqid(),
            'name' => 'Buyer '.$suffix,
        ]);

        return Booking::query()->create([
            'project_id' => $this->project->id,
            'lot_id' => $lotId,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'PSJB:'.uniqid(),
            'source_transaction_id' => 'TRX-'.uniqid(),
            'booking_date' => '2026-11-10',
            'payment_method' => $suffix === 'cash' ? 'CASH' : 'KPR',
            'sales_name' => 'Sales '.$suffix,
            'status' => $status,
            'current_stage' => $stage,
            'is_current_holder' => $status === Booking::STATUS_ACTIVE || $status === Booking::STATUS_COMPLETED,
            'source_owner' => Booking::OWNER_SHEETS,
        ]);
    }

    private function conflict(Lot $lot, string $caseType, string $suffix): ImportConflict
    {
        $conflict = ImportConflict::query()->create([
            'project_id' => $this->project->id,
            'stable_source_identity' => $this->project->code.'|'.$caseType.'|'.$suffix,
            'case_type' => $caseType,
            'status' => ImportConflict::STATUS_OPEN,
            'severity' => 'warning',
            'source_tab' => 'HQ Report',
            'source_row' => 2,
            'source_key' => 'HQ:'.$suffix,
            'source_fingerprint' => hash('sha256', $caseType.$suffix),
            'source_value' => ['Status' => 'PERLU CEK'],
            'laravel_value' => ['status' => 'ACTIVE'],
            'inventory_impact' => ['summary' => 'BOOKED'],
        ]);
        $conflict->lots()->attach($lot);

        return $conflict;
    }
}
