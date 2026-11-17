<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class SetProjectManagementMode extends Command
{
    protected $signature = 'marison:project-mode
        {project : Project code}
        {mode : sheets or laravel}
        {--acknowledge : Confirm unresolved import issues before switching}';

    protected $description = 'Set project ownership mode for staging operations.';

    public function handle(): int
    {
        if (config('app.env') !== 'staging') {
            $this->error('Project mode changes are disabled in production.');

            return self::FAILURE;
        }

        $mode = (string) $this->argument('mode');
        if (! in_array($mode, [Project::MANAGEMENT_SHEETS, Project::MANAGEMENT_LARAVEL], true)) {
            $this->error('Mode must be sheets or laravel.');

            return self::FAILURE;
        }

        $project = Project::query()->where('code', $this->argument('project'))->first();
        if (! $project) {
            $this->error('Project not found.');

            return self::FAILURE;
        }
        if (! in_array($project->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true)) {
            $this->error('Operational mode is limited to pilot projects.');

            return self::FAILURE;
        }

        $unresolved = $project->importIssues()->whereNull('resolved_at')->count();
        if ($mode === Project::MANAGEMENT_LARAVEL && $unresolved > 0 && ! $this->option('acknowledge')) {
            $this->error("{$unresolved} unresolved import issue(s). Re-run with --acknowledge after review.");

            return self::FAILURE;
        }

        $project->update(['management_mode' => $mode]);
        $this->info("{$project->code} management mode: {$mode}");

        return self::SUCCESS;
    }
}
