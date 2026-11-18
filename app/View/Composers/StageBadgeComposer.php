<?php

namespace App\View\Composers;

use App\Models\ImportConflict;
use App\Models\Project;
use App\Services\Operations\PsjbWorkflow;
use App\Services\Operations\StageQueue;
use Illuminate\View\View;

class StageBadgeComposer
{
    public function __construct(
        private readonly StageQueue $stageQueue,
        private readonly PsjbWorkflow $workflow,
    ) {}

    public function compose(View $view): void
    {
        $stageProject = $this->resolveStageProject();

        $user = auth()->user();

        $view->with('stageProject', $stageProject);
        $view->with('stageCounts', $stageProject ? $this->stageQueue->queueCounts($stageProject, $this->workflow) : null);
        $view->with('canViewConflicts', $stageProject && $user ? ImportConflict::canView($user, $stageProject) : false);
    }

    private function resolveStageProject(): ?Project
    {
        $user = auth()->user();
        $routeProject = request()->route('project');
        if ($routeProject instanceof Project
            && $routeProject->isOperationalStaging()
            && $user?->canAccessProject($routeProject)) {
            return $routeProject;
        }

        $code = request()->query('project');
        if (is_string($code) && $code !== '' && $user) {
            $candidate = Project::query()->where('code', $code)->first();
            if ($candidate && $candidate->isOperationalStaging() && $user->canAccessProject($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
