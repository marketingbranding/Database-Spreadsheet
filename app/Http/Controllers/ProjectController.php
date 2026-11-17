<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProjectAccess;
use App\Http\Controllers\Concerns\ResolvesPortfolioFilters;
use App\Models\Project;
use App\Services\Metrics\PortfolioMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    use ResolvesPortfolioFilters;
    use ResolvesProjectAccess;

    public function index(Request $request): View
    {
        $filters = $this->portfolioFilters($request);

        $rows = $filters['projects']->map(function (Project $project) {
            $metrics = PortfolioMetrics::forProjects(collect([$project]));

            return [
                'project' => $project,
                'summary' => $metrics->summary(),
                'lastBatch' => $project->importBatches()->latest('id')->first(),
            ];
        });

        return view('projects.index', [
            'filters' => $filters,
            'rows' => $rows,
        ]);
    }

    public function show(Request $request, Project $project): View
    {
        $this->authorizeProjectAccess($project);

        $filters = $this->portfolioFilters($request);
        $metrics = PortfolioMetrics::forProjects(collect([$project]), $filters['from'], $filters['to']);

        return view('projects.show', [
            'filters' => $filters,
            'project' => $project,
            'summary' => $metrics->summary(),
            'positions' => $metrics->positions(),
            'pipeline' => $metrics->stagePipeline(),
            'recent' => $metrics->recentBookings(12),
            'lastBatch' => $project->importBatches()->latest('id')->first(),
        ]);
    }
}
