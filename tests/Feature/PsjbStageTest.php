<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\ImportConflict;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PsjbStageTest extends TestCase
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

    public function test_antrean_routes_kpr_to_bi_and_cash_to_pemberkasan(): void
    {
        $kpr = $this->booking('kpr', 'KPR');
        $cash = $this->booking('cash', 'CASH');

        $response = $this->actingAs($this->admin)->get(route('psjb.index', $this->project));

        $response->assertOk();
        $response->assertSee($kpr->lot->code);
        $response->assertSee(route('bi.edit', [$this->project, $kpr]));
        $response->assertSee(route('pemberkasan.edit', [$this->project, $cash]));
    }

    public function test_disputed_lot_row_shows_reason_and_conflict_link_when_viewable(): void
    {
        config(['marison.import_conflicts_enabled' => true]);
        $booking = $this->booking('blocked', 'KPR');
        $conflict = $this->conflict($booking->lot, 'STOCK_DISAGREEMENT', 'blocked');

        $response = $this->actingAs($this->admin)->get(route('psjb.index', $this->project));

        $response->assertOk();
        $response->assertSee('Kavling memiliki konflik alokasi yang belum selesai.');
        $response->assertSee(route('import-conflicts.show', [$this->project, $conflict]));
    }

    public function test_conflict_link_hidden_when_gate_disabled(): void
    {
        config(['marison.import_conflicts_enabled' => false]);
        $booking = $this->booking('blocked', 'KPR');
        $conflict = $this->conflict($booking->lot, 'STOCK_DISAGREEMENT', 'blocked');

        $response = $this->actingAs($this->admin)->get(route('psjb.index', $this->project));

        $response->assertOk();
        $response->assertSee('Kavling memiliki konflik alokasi yang belum selesai.');
        $this->assertStringNotContainsString(
            route('import-conflicts.show', [$this->project, $conflict]),
            (string) $response->getContent(),
        );
    }

    public function test_hq_sees_queue_without_create_button(): void
    {
        $hq = User::query()->create([
            'name' => 'HQ', 'email' => 'hq@test', 'password' => 'password',
            'role' => User::ROLE_HQ, 'is_active' => true,
        ]);
        $this->booking('kpr', 'KPR');

        $response = $this->actingAs($hq)->get(route('psjb.index', $this->project));

        $response->assertOk();
        $this->assertStringNotContainsString(route('psjb.create', $this->project), (string) $response->getContent());
    }

    public function test_branch_admin_sees_create_button(): void
    {
        $this->booking('kpr', 'KPR');

        $response = $this->actingAs($this->admin)->get(route('psjb.index', $this->project));

        $response->assertOk();
        $response->assertSee(route('psjb.create', $this->project));
    }

    public function test_sheets_mode_project_is_forbidden(): void
    {
        $this->project->update(['management_mode' => Project::MANAGEMENT_SHEETS]);

        $this->actingAs($this->admin)->get(route('psjb.index', $this->project))->assertForbidden();
    }

    public function test_riwayat_tab_renders_history_with_cycle_links(): void
    {
        $original = $this->booking('original', 'KPR');
        $successor = $this->booking('successor', 'CASH');
        $successor->update(['predecessor_booking_id' => $original->id, 'status' => Booking::STATUS_REPLACED]);

        $response = $this->actingAs($this->admin)->get(route('psjb.index', ['project' => $this->project, 'tab' => 'riwayat']));

        $response->assertOk();
        $response->assertSee(route('bookings.show', $original));
        $response->assertSee('Siklus');
    }

    private function booking(string $suffix, string $paymentMethod): Booking
    {
        $lot = Lot::query()->create([
            'project_id' => $this->project->id,
            'code' => 'L-'.strtoupper($suffix),
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
            'payment_method' => $paymentMethod,
            'sales_name' => 'Sales '.$suffix,
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_PSJB,
            'is_current_holder' => true,
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
