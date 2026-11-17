<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function index(Request $request): View
    {
        $batches = ImportBatch::query()
            ->with('project.branch')
            ->when(! $request->user()->isHq(), fn ($q) => $q->whereIn('project_id', $request->user()->projects()->select('projects.id')))
            ->latest('id')
            ->limit(50)
            ->get();

        $issues = ImportIssue::query()
            ->with(['project', 'batch'])
            ->when(! $request->user()->isHq(), fn ($q) => $q->whereIn('project_id', $request->user()->projects()->select('projects.id')))
            ->whereNull('resolved_at')
            ->orderByRaw("CASE severity WHEN 'error' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderBy('project_id')
            ->limit(100)
            ->get();

        return view('imports.index', ['batches' => $batches, 'issues' => $issues]);
    }

    public function show(Request $request, ImportBatch $batch): View
    {
        abort_unless($request->user()?->canAccessProject($batch->project), 403, 'You do not have access to this project.');

        $batch->load(['project.branch', 'issues' => fn ($q) => $q
            ->orderByRaw("CASE severity WHEN 'error' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderBy('source_tab')
            ->orderBy('source_row')]);

        return view('imports.show', ['batch' => $batch]);
    }

}
