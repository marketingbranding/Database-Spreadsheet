<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\BankSubmission;
use App\Models\BastRecord;
use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StageTabsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function stageProvider(): array
    {
        return [
            'bi' => ['bi.index', 'Periksa', 'BI Checking'],
            'pemberkasan' => ['pemberkasan.index', 'Buka berkas', 'Pemberkasan rev.'],
            'bank' => ['bank.index', 'Catat hasil', 'Pengajuan bank'],
            'ppjb' => ['ppjb.index', 'TTD PPJB', 'PPJB ditandatangani'],
            'akad' => ['akad.index', 'Catat Akad', 'Akad'],
            'bast' => ['bast.index', 'Catat BAST', 'BAST'],
        ];
    }

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
        $this->admin = User::query()->create([
            'name' => 'Branch Admin', 'email' => 'admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->project);
    }

    #[DataProvider('stageProvider')]
    public function test_antrean_and_riwayat_tabs_render_per_stage(string $route, string $antreanAction, string $eventLabel): void
    {
        [$queueBooking, $historyBooking] = $this->seedStage($route);

        $antrean = $this->actingAs($this->admin)->get(route($route, $this->project));
        $antrean->assertOk();
        $antrean->assertSee($queueBooking->lot->code);
        $antrean->assertSee($antreanAction);

        $riwayat = $this->actingAs($this->admin)->get(route($route, ['project' => $this->project, 'tab' => 'riwayat']));
        $riwayat->assertOk();
        $riwayat->assertSee($eventLabel);
        $riwayat->assertSee(route('bookings.show', $historyBooking));

        $fallback = $this->actingAs($this->admin)->get(route($route, ['project' => $this->project, 'tab' => 'nonsense']));
        $fallback->assertOk();
        $fallback->assertSee($antreanAction);
    }

    public function test_empty_riwayat_shows_an_empty_state(): void
    {
        $response = $this->actingAs($this->admin)->get(route('bi.index', ['project' => $this->project, 'tab' => 'riwayat']));

        $response->assertOk();
        $response->assertSee('Belum ada riwayat untuk tahap ini.');
    }

    public function test_tab_links_preserve_search_and_filter_context(): void
    {
        $response = $this->actingAs($this->admin)->get(route('bi.index', ['project' => $this->project, 'q' => 'abc', 'branch' => 'JPR']));

        $response->assertOk();
        $response->assertSee(e(route('bi.index', ['project' => $this->project, 'q' => 'abc', 'branch' => 'JPR', 'tab' => 'riwayat'])), false);
    }

    public function test_stage_authorization_rejects_other_branch_admin(): void
    {
        $otherBranch = Branch::query()->create(['code' => 'MGL', 'name' => 'Magelang']);
        $other = User::query()->create([
            'name' => 'Other Admin', 'email' => 'other@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $otherBranch->id, 'is_active' => true,
        ]);

        $this->actingAs($other)->get(route('bi.index', $this->project))->assertForbidden();
        $this->actingAs($other)->get(route('psjb.index', $this->project))->assertForbidden();
    }

    /**
     * @return array{0: Booking, 1: Booking}
     */
    private function seedStage(string $route): array
    {
        return match ($route) {
            'bi.index' => [
                $this->booking('KPR', Booking::STAGE_PSJB),
                $this->withBiCheck($this->booking('KPR', Booking::STAGE_BI)),
            ],
            'pemberkasan.index' => [
                $this->booking('CASH', Booking::STAGE_PSJB),
                $this->withPemberkasan($this->booking('CASH', Booking::STAGE_PEMBERKASAN)),
            ],
            'bank.index' => [
                $this->withBankSubmission($this->booking('KPR', Booking::STAGE_PEMBERKASAN), 'Analisa', null, BankSubmission::OWNER_LARAVEL),
                $this->withBankSubmission($this->booking('KPR', Booking::STAGE_BANK), 'Analisa', 'APPROVE'),
            ],
            'ppjb.index' => [
                $this->ppjbable(),
                $this->withPpjb($this->booking('KPR', Booking::STAGE_PPJB)),
            ],
            'akad.index' => [
                $this->withPpjb($this->booking('KPR', Booking::STAGE_PPJB)),
                $this->withAkad($this->booking('KPR', Booking::STAGE_AKAD)),
            ],
            'bast.index' => [
                $this->withAkad($this->booking('KPR', Booking::STAGE_AKAD)),
                $this->withBast($this->booking('KPR', Booking::STAGE_BAST)),
            ],
            default => throw new \LogicException($route),
        };
    }

    private function booking(string $paymentMethod, string $stage): Booking
    {
        $lot = Lot::query()->create([
            'project_id' => $this->project->id,
            'code' => 'S-'.uniqid(),
            'source_key' => 'LOT:'.uniqid(),
        ]);
        $buyer = Buyer::query()->create([
            'source_key' => $this->project->code.':BUYER:'.uniqid(),
            'name' => 'Buyer '.$stage,
        ]);

        return Booking::query()->create([
            'project_id' => $this->project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'PSJB:'.uniqid(),
            'source_transaction_id' => 'TRX-'.uniqid(),
            'booking_date' => '2026-11-10',
            'payment_method' => $paymentMethod,
            'sales_name' => 'Sales',
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => $stage,
            'is_current_holder' => true,
            'source_owner' => Booking::OWNER_SHEETS,
        ]);
    }

    private function withBiCheck(Booking $booking): Booking
    {
        BiCheck::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $this->project->id,
            'source_key' => 'BI:'.uniqid(),
            'bi_number' => 'BI-1',
            'check_date' => '2026-11-12',
            'result' => 'LANJUT',
            'decision' => 'LANJUT',
            'source_owner' => BiCheck::OWNER_LARAVEL,
        ]);

        return $booking;
    }

    private function withPemberkasan(Booking $booking, string $status = 'Analisa', bool $current = true): Booking
    {
        PemberkasanRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $this->project->id,
            'source_key' => 'PB:'.uniqid(),
            'file_number' => 'F-'.uniqid(),
            'received_date' => '2026-11-11',
            'status' => $status,
            'source_owner' => PemberkasanRecord::OWNER_SHEETS,
            'revision' => 1,
            'is_current' => $current,
        ]);

        return $booking;
    }

    private function withBankSubmission(Booking $booking, string $status, ?string $result, string $owner = BankSubmission::OWNER_SHEETS): Booking
    {
        $file = $this->withPemberkasan($booking, $status);
        BankSubmission::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $this->project->id,
            'source_key' => 'BANK:'.uniqid(),
            'file_number' => $file->pemberkasan()->first()->file_number,
            'submission_sequence' => 1,
            'bank' => 'BPR',
            'submitted_date' => '2026-11-12',
            'result' => $result,
            'source_owner' => $owner,
        ]);

        return $booking;
    }

    private function ppjbable(): Booking
    {
        return $this->withBankSubmission($this->booking('KPR', Booking::STAGE_BANK), 'Analisa', 'APPROVE');
    }

    private function withPpjb(Booking $booking): Booking
    {
        PpjbRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $this->project->id,
            'source_key' => 'PPJB:'.uniqid(),
            'ppjb_number' => 'PPJB-1',
            'signed_date' => '2026-11-13',
            'source_owner' => PpjbRecord::OWNER_SHEETS,
        ]);

        return $booking;
    }

    private function withAkad(Booking $booking): Booking
    {
        $ppjb = $this->withPpjb($booking)->ppjb()->first();
        AkadRecord::query()->create([
            'booking_id' => $booking->id,
            'ppjb_id' => $ppjb->id,
            'project_id' => $this->project->id,
            'source_key' => 'AKAD:'.uniqid(),
            'akad_number' => 'AKAD-1',
            'akad_date' => '2026-11-14',
            'source_owner' => AkadRecord::OWNER_SHEETS,
        ]);

        return $booking;
    }

    private function withBast(Booking $booking): Booking
    {
        $akad = $this->withAkad($booking)->akad()->first();
        BastRecord::query()->create([
            'booking_id' => $booking->id,
            'akad_id' => $akad->id,
            'project_id' => $this->project->id,
            'source_key' => 'BAST:'.uniqid(),
            'bast_number' => 'BAST-1',
            'bast_date' => '2026-11-15',
            'source_owner' => BastRecord::OWNER_SHEETS,
        ]);

        return $booking;
    }
}
