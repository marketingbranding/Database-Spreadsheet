<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\BuildsStageHistory;
use App\Http\Controllers\Controller;
use App\Models\BankSubmission;
use App\Models\Booking;
use App\Models\BookingTransition;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private object $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $this->project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->builder = new class extends Controller
        {
            use BuildsStageHistory;
        };
    }

    public function test_bank_history_groups_repeated_submissions_under_one_booking(): void
    {
        $booking = $this->booking('banked');
        BankSubmission::query()->create($this->bankAttributes($booking, 1, ['result' => null]));
        BankSubmission::query()->create($this->bankAttributes($booking, 2, ['result' => 'APPROVE']));

        $page = $this->builder->stageHistory($this->project, 'bank');

        $this->assertSame(1, $page->count());
        $events = $page->items()[0]['events'];
        $this->assertCount(2, $events);
        $this->assertSame('Pengajuan bank #1', $events[0]['label']);
        $this->assertSame('Menunggu', $events[0]['detail']);
        $this->assertSame('Pengajuan bank #2', $events[1]['label']);
        $this->assertSame('APPROVE', $events[1]['detail']);
    }

    public function test_pemberkasan_history_keeps_every_revision(): void
    {
        $booking = $this->booking('files');
        foreach ([1, 2, 3] as $revision) {
            PemberkasanRecord::query()->create([
                'booking_id' => $booking->id,
                'project_id' => $this->project->id,
                'source_key' => 'PB:'.uniqid(),
                'file_number' => 'F-'.$revision,
                'received_date' => '2026-11-0'.$revision,
                'status' => 'Analisa',
                'source_owner' => PemberkasanRecord::OWNER_SHEETS,
                'revision' => $revision,
                'is_current' => $revision === 3,
            ]);
        }

        $page = $this->builder->stageHistory($this->project, 'pemberkasan');

        $this->assertSame(1, $page->count());
        $this->assertCount(3, $page->items()[0]['events']);
        $this->assertSame('Pemberkasan rev. 1', $page->items()[0]['events'][0]['label']);
    }

    public function test_psjb_history_preserves_transition_cycle_links(): void
    {
        $original = $this->booking('original');
        $successor = $this->booking('successor');
        $successor->update(['predecessor_booking_id' => $original->id]);
        BookingTransition::query()->create([
            'project_id' => $this->project->id,
            'source_booking_id' => $original->id,
            'successor_booking_id' => $successor->id,
            'user_id' => $this->adminId(),
            'action' => BookingTransition::ACTION_NAME_CHANGE,
            'effective_date' => '2026-11-12',
            'reason' => 'Buyer replacement',
            'idempotency_key' => 'tr-'.uniqid(),
            'source_key' => 'TRANS:'.uniqid(),
            'source_hash' => hash('sha256', 'transition-'.uniqid()),
            'source_owner' => BookingTransition::OWNER_LARAVEL,
        ]);

        $page = $this->builder->stageHistory($this->project, 'psjb');

        $this->assertSame(2, $page->count());
        $rows = collect($page->items())->keyBy(fn (array $row) => $row['booking']->id);
        $successorRow = $rows[$successor->id];
        $this->assertSame($original->id, $successorRow['cycle']['predecessor_id']);
        $originalRow = $rows[$original->id];
        $this->assertSame([$successor->id], $originalRow['cycle']['successor_ids']);
        $labels = collect($originalRow['events'])->pluck('label')->all();
        $this->assertContains('PSJB dibuat', $labels);
        $this->assertContains('Ganti nama', collect($successorRow['events'])->pluck('label')->all());
    }

    public function test_history_paginates_by_booking(): void
    {
        foreach (range(1, 21) as $i) {
            $this->booking('page'.$i);
        }

        $page = $this->builder->stageHistory($this->project, 'psjb');

        $this->assertSame(21, $page->total());
        $this->assertSame(2, $page->lastPage());
        $this->assertCount(20, $page->items());
    }

    private function booking(string $suffix): Booking
    {
        $lot = Lot::query()->create([
            'project_id' => $this->project->id,
            'code' => 'L-'.strtoupper($suffix).'-'.uniqid(),
            'source_key' => 'LOT:'.uniqid(),
        ]);
        $buyer = Buyer::query()->create([
            'source_key' => $this->project->code.':BUYER:'.uniqid(),
            'name' => 'Buyer '.$suffix,
        ]);

        return Booking::query()->create([
            'project_id' => $this->project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'PSJB:'.uniqid(),
            'source_transaction_id' => 'TRX-'.uniqid(),
            'booking_date' => '2026-11-10',
            'payment_method' => 'KPR',
            'sales_name' => 'Sales '.$suffix,
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => true,
            'source_owner' => Booking::OWNER_SHEETS,
        ]);
    }

    private function adminId(): int
    {
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'stage-'.uniqid().'@test.local', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $this->project->branch_id, 'is_active' => true,
        ]);
        $admin->projects()->attach($this->project);

        return $admin->id;
    }

    private function bankAttributes(Booking $booking, int $sequence, array $extra): array
    {
        return array_merge([
            'booking_id' => $booking->id,
            'project_id' => $this->project->id,
            'source_key' => 'BANK:'.uniqid(),
            'file_number' => 'FILE-'.$sequence,
            'submission_sequence' => $sequence,
            'bank' => 'BPR',
            'submitted_date' => '2026-11-0'.$sequence,
            'source_owner' => BankSubmission::OWNER_SHEETS,
        ], $extra);
    }
}
