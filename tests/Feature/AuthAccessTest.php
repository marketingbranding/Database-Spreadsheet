<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use \Tests\TestCase;

class AuthAccessTest extends TestCase
{
    use RefreshDatabase;

    private Project $mine;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();

        $jepara = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara', 'is_active' => true]);
        $magelang = Branch::query()->create(['code' => 'MGL', 'name' => 'Magelang', 'is_active' => true]);

        $this->mine = Project::query()->create([
            'branch_id' => $jepara->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen', 'is_active' => true,
        ]);
        $this->other = Project::query()->create([
            'branch_id' => $magelang->id, 'code' => 'PRJ-MGL-KLG', 'name' => 'Kalinegoro', 'is_active' => true,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/projects')->assertRedirect('/login');
    }

    public function test_branch_admin_sees_only_assigned_projects(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Jepara',
            'email' => 'jepara@marison.test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $this->mine->branch_id,
            'is_active' => true,
        ]);
        $admin->projects()->sync([$this->mine->id]);

        $this->actingAs($admin);

        $this->get('/dashboard')->assertOk();
        $this->get("/projects/{$this->mine->code}")->assertOk();
        $this->get("/projects/{$this->other->code}")->assertForbidden();
        $this->get("/reconciliation/{$this->other->code}")->assertForbidden();

        // The other project is invisible from scoped pages.
        $response = $this->get('/projects');
        $response->assertOk();
        $response->assertDontSee('Kalinegoro');

        // Branch filter only offers accessible branches.
        $dashboard = $this->get('/dashboard');
        $dashboard->assertOk();
        $dashboard->assertSee('Jepara');
        $dashboard->assertDontSee('Magelang');
    }

    public function test_hq_sees_all_projects(): void
    {
        $hq = User::query()->create([
            'name' => 'HQ',
            'email' => 'hq@marison.test',
            'password' => 'password',
            'role' => User::ROLE_HQ,
            'is_active' => true,
        ]);

        $this->actingAs($hq);

        $this->get('/dashboard')->assertOk();
        $this->get("/projects/{$this->other->code}")->assertOk();
        $this->get('/projects')->assertSee('Kalinegoro')->assertSee('Kuwasen');
    }

    public function test_booking_of_other_project_is_forbidden(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Jepara',
            'email' => 'jepara@marison.test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $this->mine->branch_id,
            'is_active' => true,
        ]);
        $admin->projects()->sync([$this->mine->id]);

        $buyer = \App\Models\Buyer::query()->create([
            'project_id' => $this->other->id,
            'name' => 'Anonymized Buyer',
            'source_key' => $this->other->code . ':BUYER:NAME:test',
        ]);
        $lot = Lot::query()->create([
            'project_id' => $this->other->id, 'code' => 'M99', 'source_key' => $this->other->code . ':LOT:M99',
        ]);
        $booking = Booking::query()->create([
            'project_id' => $this->other->id, 'lot_id' => $lot->id, 'buyer_id' => $buyer->id,
            'source_booking_key' => $this->other->code . ':PSJB:1', 'source_transaction_id' => 'TRX-1',
            'booking_date' => '2026-01-10', 'status' => Booking::STATUS_ACTIVE, 'is_current_holder' => true,
        ]);

        $this->actingAs($admin)->get("/bookings/{$booking->id}")->assertForbidden();
    }

    public function test_inactive_users_cannot_log_in(): void
    {
        User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@marison.test',
            'password' => 'password',
            'role' => User::ROLE_HQ,
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'email' => 'inactive@marison.test',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_import_issue_can_be_resolved_by_assigned_user(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Jepara',
            'email' => 'jepara@marison.test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $this->mine->branch_id,
            'is_active' => true,
        ]);
        $admin->projects()->sync([$this->mine->id]);

        $batch = ImportBatch::query()->create([
            'project_id' => $this->mine->id, 'mode' => 'apply', 'source' => 'fixture:test',
            'status' => 'completed', 'started_at' => now(),
        ]);
        $issue = ImportIssue::query()->create([
            'import_batch_id' => $batch->id, 'project_id' => $this->mine->id,
            'severity' => 'warning', 'code' => 'test_issue', 'message' => 'Test issue',
        ]);

        $this->actingAs($admin)
            ->post("/imports/issues/{$issue->id}/resolve")
            ->assertRedirect();

        $this->assertNotNull($issue->fresh()->resolved_at);
    }
}
