<?php

namespace Tests\Feature;

use App\Jobs\RunProjectImport;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $hq;

    private User $branchAdmin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $this->project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'spreadsheet_id' => 'sheet-id',
            'is_active' => true,
        ]);
        $otherBranch = Branch::query()->create(['code' => 'MGL', 'name' => 'Magelang']);
        $otherProject = Project::query()->create([
            'branch_id' => $otherBranch->id,
            'code' => 'PRJ-MGL-KLG',
            'name' => 'Kalinegoro',
            'spreadsheet_id' => 'other-sheet-id',
            'is_active' => true,
        ]);
        $this->hq = User::query()->create([
            'name' => 'HQ', 'email' => 'hq@test', 'password' => 'password',
            'role' => User::ROLE_HQ, 'is_active' => true,
        ]);
        $this->branchAdmin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin@test', 'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $this->hq->projects()->attach([$this->project->id, $otherProject->id]);
        $this->branchAdmin->projects()->attach($this->project);
    }

    public function test_import_page_shows_accessible_projects_and_import_form(): void
    {
        $response = $this->actingAs($this->branchAdmin)->get('/imports');

        $response->assertOk();
        $response->assertSee('Dry Run');
        $response->assertSee('Kuwasen');
        $response->assertDontSee('Kalinegoro');
        $response->assertSee('/imports/dry-run', false);
    }

    public function test_branch_admin_cannot_import_unassigned_project(): void
    {
        $other = Project::query()->where('code', 'PRJ-MGL-KLG')->firstOrFail();

        $this->actingAs($this->branchAdmin)
            ->post('/imports/dry-run', ['project' => $other->id])
            ->assertForbidden();
    }

    public function test_apply_requires_valid_dry_run_token_and_dispatches_queued_batch(): void
    {
        Bus::fake();
        $token = '11111111-1111-4111-8111-111111111111';
        Cache::put('web-import:'.$token, [
            'user_id' => $this->hq->id,
            'project_id' => $this->project->id,
            'checksum' => 'checksum',
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], now()->addMinutes(10));

        $response = $this->actingAs($this->hq)->post('/imports/apply', [
            'project' => $this->project->id,
            'token' => $token,
        ]);

        $batch = ImportBatch::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('imports.show', ['batch' => $batch]));
        $this->assertSame('queued', $batch->status);
        Bus::assertDispatched(RunProjectImport::class);
        $this->assertNull(Cache::get('web-import:'.$token));
    }

    public function test_apply_rejects_expired_or_missing_token(): void
    {
        $this->actingAs($this->hq)
            ->post('/imports/apply', ['project' => $this->project->id, 'token' => 'missing'])
            ->assertSessionHasErrors('token');
    }

    public function test_queued_job_rechecks_requester_project_access(): void
    {
        $batch = ImportBatch::query()->create([
            'project_id' => $this->project->id,
            'mode' => 'apply',
            'source' => 'google:sheet-id',
            'status' => 'queued',
        ]);
        $this->branchAdmin->projects()->detach($this->project);

        $this->expectException(AuthorizationException::class);
        (new RunProjectImport($batch->id, $this->branchAdmin->id))->handle();
    }

    public function test_apply_rejects_preview_with_import_errors(): void
    {
        Bus::fake();
        $token = '22222222-2222-4222-8222-222222222222';
        Cache::put('web-import:'.$token, [
            'user_id' => $this->hq->id,
            'project_id' => $this->project->id,
            'checksum' => 'checksum',
            'report' => ['stats' => ['issues' => ['error' => 1]]],
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], now()->addMinutes(10));

        $this->actingAs($this->hq)
            ->post('/imports/apply', ['project' => $this->project->id, 'token' => $token])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('import_batches', 0);
        Bus::assertNothingDispatched();
    }
}
