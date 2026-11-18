<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Project $jpr;

    private Project $mgl;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $jprBranch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $mglBranch = Branch::query()->create(['code' => 'MGL', 'name' => 'Magelang']);
        $this->jpr = Project::query()->create([
            'branch_id' => $jprBranch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL, 'timezone' => 'Asia/Jakarta',
        ]);
        $this->mgl = Project::query()->create([
            'branch_id' => $mglBranch->id, 'code' => 'PRJ-MGL-KLG', 'name' => 'Kalinegoro',
            'management_mode' => Project::MANAGEMENT_LARAVEL, 'timezone' => 'Asia/Jakarta',
        ]);
        $sheets = Project::query()->create([
            'branch_id' => $mglBranch->id, 'code' => 'PRJ-SHEETS-X', 'name' => 'Sheets Proyek',
            'management_mode' => Project::MANAGEMENT_SHEETS, 'timezone' => 'Asia/Jakarta',
        ]);

        $this->admin = User::query()->create([
            'name' => 'Branch Admin', 'email' => 'admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $jprBranch->id, 'is_active' => true,
        ]);
        $this->admin->projects()->attach($this->jpr);

        $this->hq = User::query()->create([
            'name' => 'HQ', 'email' => 'hq@test', 'password' => 'password',
            'role' => User::ROLE_HQ, 'is_active' => true,
        ]);
    }

    public function test_sidebar_lists_all_seven_stages_in_order_with_badges(): void
    {
        $this->seedQueueBookings($this->jpr, 2);

        $response = $this->actingAs($this->admin)->get(route('bi.index', $this->jpr));

        $response->assertOk();
        $response->assertSeeInOrder([
            route('psjb.index', $this->jpr),
            route('bi.index', $this->jpr),
            route('pemberkasan.index', $this->jpr),
            route('bank.index', $this->jpr),
            route('ppjb.index', $this->jpr),
            route('akad.index', $this->jpr),
            route('bast.index', $this->jpr),
        ]);
        $response->assertSee('PSJB / UTJ');
        $response->assertSee('Pemberkasan');
        $response->assertSee('TTD PPJB');
        $response->assertSee('Semua Booking');
        $response->assertSee('Rekonsiliasi');
        $response->assertSee('Riwayat Impor');
        $response->assertSee('nav-badge">2</span>', false);
    }

    public function test_stage_links_carry_project_filter_from_portfolio_pages(): void
    {
        $this->admin->projects()->attach($this->mgl);

        $response = $this->actingAs($this->admin)->get('/dashboard?project=PRJ-MGL-KLG');

        $response->assertOk();
        $response->assertSee(route('bi.index', $this->mgl), false);
    }

    public function test_stage_links_without_project_context_open_the_selector(): void
    {
        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('stages.select', ['stage' => 'psjb']), false);
        $response->assertSee(route('stages.select', ['stage' => 'bast']), false);
    }

    public function test_selector_lists_authorized_projects_and_continues_to_stage(): void
    {
        $response = $this->actingAs($this->hq)->get(route('stages.select', ['stage' => 'bi', 'tab' => 'riwayat']));

        $response->assertOk();
        $response->assertSee(route('bi.index', ['project' => $this->jpr, 'tab' => 'riwayat']), false);
        $response->assertSee(route('bi.index', ['project' => $this->mgl, 'tab' => 'riwayat']), false);
        $response->assertSee('Dikelola melalui Sheets — tahapan operasional tidak tersedia.');
    }

    public function test_selector_shows_only_the_users_projects_for_branch_admin(): void
    {
        $response = $this->actingAs($this->admin)->get(route('stages.select', ['stage' => 'psjb']));

        $response->assertOk();
        $response->assertSee($this->jpr->name);
        $this->assertStringNotContainsString($this->mgl->name, (string) $response->getContent());
    }

    public function test_non_operational_project_query_param_does_not_drive_stage_links(): void
    {
        $response = $this->actingAs($this->hq)->get('/dashboard?project=PRJ-SHEETS-X');

        $response->assertOk();
        $this->assertStringNotContainsString('/projects/PRJ-SHEETS-X/', (string) $response->getContent());
        $response->assertSee(route('stages.select', ['stage' => 'psjb']), false);
    }

    public function test_unknown_stage_key_returns_404(): void
    {
        $this->actingAs($this->admin)->get(route('stages.select', ['stage' => 'import-conflicts']))->assertNotFound();
    }

    public function test_konflik_data_visibility_follows_the_gate(): void
    {
        config(['marison.import_conflicts_enabled' => true]);
        $shown = $this->actingAs($this->admin)->get(route('bi.index', $this->jpr));
        $shown->assertOk();
        $shown->assertSee(route('import-conflicts.index', $this->jpr), false);

        config(['marison.import_conflicts_enabled' => false]);
        $hidden = $this->actingAs($this->admin)->get(route('bi.index', $this->jpr));
        $hidden->assertOk();
        $this->assertStringNotContainsString(
            route('import-conflicts.index', $this->jpr),
            (string) $hidden->getContent(),
        );
    }

    public function test_mobile_sidebar_toggle_is_accessible_and_closes_on_selection(): void
    {
        $response = $this->actingAs($this->admin)->get(route('bi.index', $this->jpr));

        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString('class="menu-toggle"', $content);
        $this->assertStringContainsString('aria-expanded="false"', $content);
        $this->assertStringContainsString('aria-controls="sidebar-nav"', $content);
        $this->assertStringContainsString('id="sidebar-nav"', $content);
        $this->assertStringContainsString('data-nav-open', $content);
        $this->assertStringContainsString('Escape', $content);
        $this->assertStringContainsString('sidebar-backdrop', $content);
    }

    private function seedQueueBookings(Project $project, int $count): void
    {
        foreach (range(1, $count) as $i) {
            $lot = Lot::query()->create([
                'project_id' => $project->id, 'code' => 'N-'.uniqid(), 'source_key' => 'LOT:'.uniqid(),
            ]);
            $buyer = Buyer::query()->create([
                'source_key' => $project->code.':BUYER:'.uniqid(), 'name' => 'Buyer '.$i,
            ]);
            Booking::query()->create([
                'project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id,
                'source_booking_key' => 'PSJB:'.uniqid(), 'source_transaction_id' => 'TRX-'.uniqid(),
                'booking_date' => '2026-11-10', 'payment_method' => 'CASH', 'sales_name' => 'Sales',
                'status' => Booking::STATUS_ACTIVE, 'current_stage' => Booking::STAGE_PSJB,
                'is_current_holder' => true, 'source_owner' => Booking::OWNER_SHEETS,
            ]);
        }
    }
}
