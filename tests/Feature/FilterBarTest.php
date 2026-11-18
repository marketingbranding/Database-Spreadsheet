<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterBarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
        ]);
        $this->admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $this->admin->projects()->attach($project);
    }

    public function test_dashboard_renders_period_popover_with_active_custom_range(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/dashboard?project=PRJ-JPR-KWS&period=custom&from=2026-09-01&to=2026-09-30');

        $response->assertOk();
        $response->assertSee('Bulanan');
        $response->assertSee('Kuartalan');
        $response->assertSee('Custom');
        $response->assertSee('September 2026');
        $response->assertSee('data-range-picker', false);
        $response->assertSee('data-range-prev', false);
        $response->assertSee('data-range-next', false);
        $response->assertSee('data-range-months', false);
        $response->assertSee('data-range-from', false);
        $response->assertSee('data-range-to', false);
        $response->assertSee('name="period"', false);
        $response->assertSee('name="from" value="2026-09-01"', false);
        $response->assertSee('name="to" value="2026-09-30"', false);
        $response->assertDontSee('type="date"', false);
        $response->assertDontSee('onchange="this.form.submit()"');
    }

    public function test_filters_apply_on_submit_and_reset_clears_all_filters(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/dashboard?project=PRJ-JPR-KWS&from=2026-09-01&to=2026-11-30');

        $response->assertOk();
        $response->assertSee('<button type="submit" class="btn">Terapkan</button>', false);
        $response->assertSee('<a class="btn btn--secondary" href="'.url('/dashboard').'">Reset</a>', false);
    }

    public function test_pages_without_period_keep_period_controls_hidden(): void
    {
        $response = $this->actingAs($this->admin)->get('/inventory');

        $response->assertOk();
        $response->assertDontSee('Bulanan');
        $response->assertDontSee('Kuartalan');
        $response->assertDontSee('name="period"', false);
    }

    public function test_shared_filter_actions_are_not_duplicated_on_pages_with_extra_filters(): void
    {
        foreach (['/bookings', '/inventory'] as $uri) {
            $response = $this->actingAs($this->admin)->get($uri);

            $response->assertOk();
            $response->assertDontSee('onchange="this.form.submit()"');
            $this->assertSame(1, substr_count($response->getContent(), 'class="btn">Terapkan</button>'));
            $this->assertSame(1, substr_count($response->getContent(), '>Reset</a>'));
        }
    }
}
