<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProjectAccess;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Project;
use App\Services\Metrics\Reconciliation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    use ResolvesProjectAccess;

    public function index(Request $request): View
    {
        $projects = Project::query()
            ->with(['branch', 'importBatches' => fn ($q) => $q->latest('id')->limit(1)])
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get();

        $openIssues = ImportIssue::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->whereNull('resolved_at')
            ->selectRaw('project_id, count(*) as total')
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        return view('reconciliation.index', [
            'projects' => $projects,
            'openIssues' => $openIssues,
        ]);
    }

    public function show(Request $request, Project $project): View
    {
        $this->authorizeProjectAccess($project);

        $report = (new Reconciliation($project))->report();

        return view('reconciliation.show', ['report' => $report]);
    }
}
