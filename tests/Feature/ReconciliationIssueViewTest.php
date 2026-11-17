<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationIssueViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_page_renders_with_open_import_issues(): void
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
        ]);
        $user = User::query()->create([
            'name' => 'HQ',
            'email' => 'hq@test.local',
            'password' => 'password',
            'role' => User::ROLE_HQ,
            'is_active' => true,
        ]);
        $batch = ImportBatch::query()->create([
            'project_id' => $project->id,
            'mode' => 'apply',
            'source' => 'fixture:test',
            'status' => 'completed',
        ]);
        ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'project_id' => $project->id,
            'severity' => 'warning',
            'code' => 'test_issue',
            'message' => 'Open issue for rendering',
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.show', $project))
            ->assertOk()
            ->assertSee('Open issue for rendering');
    }
}
