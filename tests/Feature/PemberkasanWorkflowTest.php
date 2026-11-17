<?php

namespace Tests\Feature;

use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PemberkasanWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $admin;
    private PsjbWorkflow $workflow;
    private Lot $lot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $this->project = Project::query()->create([
            'branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL, 'timezone' => 'Asia/Jakarta',
        ]);
        $this->admin = User::query()->create([
            'name' => 'Admin', 'email' => 'pemberkasan-admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->project);
        $this->lot = Lot::query()->create(['project_id' => $this->project->id, 'code' => 'P-' . uniqid(), 'source_key' => 'lot:' . uniqid()]);
        $this->workflow = app(PsjbWorkflow::class);
    }

    private function booking(string $method = 'KPR'): Booking
    {
        $buyer = Buyer::query()->create(['source_key' => 'buyer:' . uniqid(), 'name' => 'Buyer ' . $method]);
        return Booking::query()->create([
            'project_id' => $this->project->id, 'lot_id' => $this->lot->id, 'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:' . uniqid(), 'payment_method' => $method,
            'status' => Booking::STATUS_ACTIVE, 'current_stage' => Booking::STAGE_PSJB, 'is_current_holder' => true,
        ]);
    }

    private function bi(Booking $booking, string $decision, string $owner = BiCheck::OWNER_LARAVEL): BiCheck
    {
        return BiCheck::query()->create([
            'booking_id' => $booking->id, 'project_id' => $booking->project_id,
            'source_key' => 'BI:' . uniqid(), 'check_date' => '2026-11-17',
            'decision' => $decision, 'source_owner' => $owner,
        ]);
    }

    private function input(string $file): array
    {
        return ['file_number' => $file, 'received_date' => '2026-11-18', 'bank' => 'BTN', 'bank_unit' => 'Jepara', 'status' => 'LENGKAP', 'notes' => 'Dokumen lengkap'];
    }

    public function test_kpr_lanjut_enters_pemberkasan_and_records_audit(): void
    {
        $booking = $this->booking();
        $this->bi($booking, 'LANJUT');

        $this->assertTrue($this->workflow->eligiblePemberkasanBookings($this->project)->whereKey($booking->id)->exists());
        $saved = $this->workflow->recordPemberkasan($this->admin, $booking, $this->input('BRK-001'));

        $this->assertSame(Booking::STAGE_PEMBERKASAN, $saved->current_stage);
        $this->assertSame(PemberkasanRecord::OWNER_LARAVEL, $saved->currentPemberkasan->source_owner);
        $this->assertSame(1, $saved->currentPemberkasan->revision);
        $this->assertSame(PemberkasanRecord::OWNER_LARAVEL, $saved->source_owner);
        $this->assertSame('pemberkasan_created', $saved->audits()->latest('id')->value('action'));
    }

    public function test_cash_enters_without_bi_but_kpr_without_or_rejected_bi_stays_out(): void
    {
        $cash = $this->booking('CASH');
        $this->assertTrue($this->workflow->eligiblePemberkasanBookings($this->project)->whereKey($cash->id)->exists());
        $this->workflow->recordPemberkasan($this->admin, $cash, $this->input('CASH-001'));

        $noBi = $this->booking();
        $rejected = $this->booking();
        $this->bi($rejected, 'TIDAK LANJUT');
        $queue = $this->workflow->eligiblePemberkasanBookings($this->project);
        $this->assertFalse($queue->whereKey($noBi->id)->exists());
        $this->assertFalse($queue->whereKey($rejected->id)->exists());

        $this->expectException(HttpException::class);
        $this->workflow->recordPemberkasan($this->admin, $noBi, $this->input('NO-BI'));
    }

    public function test_latest_bi_decision_controls_kpr_eligibility(): void
    {
        $booking = $this->booking();
        $this->bi($booking, 'LANJUT');
        $latest = $this->bi($booking, 'TIDAK LANJUT');
        $latest->touch();

        $this->assertFalse($this->workflow->eligiblePemberkasanBookings($this->project)->whereKey($booking->id)->exists());
    }

    public function test_latest_bi_uses_timestamp_then_id_without_cross_row_max_mixup(): void
    {
        $booking = $this->booking();
        $newer = $this->bi($booking, 'LANJUT');
        $older = $this->bi($booking, 'TIDAK LANJUT');
        BiCheck::query()->whereKey($newer->id)->update(['updated_at' => '2026-11-17 00:01:00']);
        BiCheck::query()->whereKey($older->id)->update(['updated_at' => '2026-11-17 00:00:00']);

        $this->assertTrue($this->workflow->eligiblePemberkasanBookings($this->project)->whereKey($booking->id)->exists());
    }

    public function test_new_file_preserves_previous_and_duplicate_file_is_rejected(): void
    {
        $booking = $this->booking();
        $this->bi($booking, 'LANJUT');
        $this->workflow->recordPemberkasan($this->admin, $booking, $this->input('BRK-001'));
        $second = $this->workflow->recordPemberkasan($this->admin, $booking->fresh(), $this->input('BRK-002'));

        $this->assertCount(2, $second->pemberkasan);
        $this->assertSame(2, $second->currentPemberkasan->revision);
        $this->assertSame('BRK-001', $second->pemberkasan->first()->file_number);
        $this->assertFalse($second->pemberkasan->first()->is_current);

        $this->expectException(HttpException::class);
        $this->workflow->recordPemberkasan($this->admin, $second, $this->input('BRK-002'));
    }

    public function test_withdraw_then_repeat_booking_preserves_pemberkasan_history(): void
    {
        $booking = $this->booking();
        $this->bi($booking, 'LANJUT');
        $this->workflow->recordPemberkasan($this->admin, $booking, $this->input('BRK-001'));
        $this->workflow->withdraw($this->admin, $booking->fresh(), 'Buyer mundur saat pemberkasan');
        $repeat = $this->workflow->create($this->admin, $this->project, [
            'lot_id' => $booking->lot_id, 'buyer_name' => 'Repeat', 'sales_name' => 'Sales',
            'payment_method' => 'CASH', 'psjb_date' => '2026-11-20',
        ]);

        $this->assertNotSame($booking->id, $repeat->id);
        $this->assertSame('BRK-001', $booking->fresh()->currentPemberkasan->file_number);
        $this->assertSame(Booking::STAGE_PEMBERKASAN, $booking->fresh()->decision_stage);
        $this->assertSame(Booking::STAGE_PSJB, $repeat->current_stage);
    }
}
