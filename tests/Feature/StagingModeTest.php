<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StagingModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mode_command_rejects_non_pilot_project(): void
    {
        config(['app.env' => 'staging']);
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-OTHER', 'name' => 'Other']);

        $this->artisan('marison:project-mode', ['project' => 'PRJ-JPR-OTHER', 'mode' => 'laravel', '--acknowledge' => true])
            ->assertExitCode(1);
    }

    public function test_mode_command_requires_acknowledgment_for_unresolved_issues(): void
    {
        config(['app.env' => 'staging']);
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create(['branch_id' => $branch->id, 'code' => 'PRJ-JPR-KWS', 'name' => 'Kuwasen']);
        $batch = ImportBatch::query()->create(['project_id' => $project->id, 'mode' => 'apply', 'source' => 'fixture:test', 'status' => 'completed']);
        ImportIssue::query()->create(['import_batch_id' => $batch->id, 'project_id' => $project->id, 'severity' => 'warning', 'code' => 'test', 'message' => 'Review']);

        $this->artisan('marison:project-mode', ['project' => $project->code, 'mode' => 'laravel'])
            ->assertExitCode(1);
        $this->assertSame(Project::MANAGEMENT_SHEETS, $project->fresh()->management_mode);

        $this->artisan('marison:project-mode', ['project' => $project->code, 'mode' => 'laravel', '--acknowledge' => true])
            ->assertExitCode(0);
        $this->assertSame(Project::MANAGEMENT_LARAVEL, $project->fresh()->management_mode);
    }

    public function test_both_pilot_projects_default_to_sheets(): void
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        foreach (['PRJ-JPR-KWS', 'PRJ-MGL-KLG'] as $code) {
            $project = Project::query()->create(['branch_id' => $branch->id, 'code' => $code, 'name' => $code]);
            $this->assertSame(Project::MANAGEMENT_SHEETS, $project->management_mode);
        }
    }
}
