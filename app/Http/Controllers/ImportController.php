<?php

namespace App\Http\Controllers;

use App\Jobs\RunProjectImport;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Project;
use App\Services\Import\GoogleSheetsReader;
use App\Services\Import\ProjectImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        $projects = Project::query()
            ->visibleTo($request->user())
            ->where('is_active', true)
            ->with('branch')
            ->orderBy('name')
            ->get();

        $preview = null;
        if ($token = $request->session()->get('import_preview_token')) {
            $payload = Cache::get('web-import:'.$token);
            $previewProject = is_array($payload)
                ? $projects->firstWhere('id', (int) ($payload['project_id'] ?? 0))
                : null;
            if (is_array($payload)
                && $previewProject
                && (int) ($payload['user_id'] ?? 0) === $request->user()->id
                && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp) {
                $preview = ['token' => $token, 'project' => $previewProject, ...$payload];
            } else {
                $request->session()->forget('import_preview_token');
            }
        }

        return view('imports.index', compact('batches', 'issues', 'projects', 'preview'));
    }

    public function dryRun(Request $request): RedirectResponse
    {
        $data = $request->validate(['project' => ['required', 'integer', 'exists:projects,id']]);
        $project = Project::query()->findOrFail($data['project']);
        abort_unless($project->is_active && $request->user()->canAccessProject($project), 403);

        try {
            $report = (new ProjectImporter($this->reader($project)))->run($project, true);
        } catch (\Throwable $e) {
            Log::warning('Web import dry run failed.', [
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
                'exception' => $e::class,
            ]);

            return back()->withErrors(['project' => 'Dry run gagal. Periksa konfigurasi sumber data lalu coba lagi.']);
        }

        $token = (string) Str::uuid();
        $payload = [
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'checksum' => hash('sha256', json_encode($report->stats)),
            'report' => [
                'stats' => $report->stats,
                'issues' => $report->issues,
            ],
            'expires_at' => now()->addMinutes(15)->timestamp,
        ];
        Cache::put('web-import:'.$token, $payload, now()->addMinutes(15));

        return back()->with('import_preview_token', $token);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project' => ['required', 'integer', 'exists:projects,id'],
            'token' => ['required', 'string', 'uuid'],
        ]);
        $project = Project::query()->findOrFail($data['project']);
        abort_unless($project->is_active && $request->user()->canAccessProject($project), 403);
        $key = 'web-import:'.$data['token'];
        $payload = Cache::pull($key);
        if (! is_array($payload)
            || (int) ($payload['user_id'] ?? 0) !== $request->user()->id
            || (int) ($payload['project_id'] ?? 0) !== $project->id
            || (int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            return back()->withErrors(['token' => 'Preview dry run tidak valid atau sudah kedaluwarsa.']);
        }

        if ((int) data_get($payload, 'report.stats.issues.error', 0) > 0) {
            return back()->withErrors(['token' => 'Import memiliki error. Perbaiki sumber data lalu jalankan dry run ulang.']);
        }

        $batch = ImportBatch::query()->create([
            'project_id' => $project->id,
            'mode' => 'apply',
            'source' => 'google:'.$project->spreadsheet_id,
            'status' => 'queued',
            'spreadsheet_id' => $project->spreadsheet_id,
            'checksum' => $payload['checksum'],
        ]);
        RunProjectImport::dispatch($batch->id, $request->user()->id);

        return redirect()->route('imports.show', $batch)->with('status', 'Import masuk antrean.');
    }

    private function reader(Project $project): GoogleSheetsReader
    {
        if (! $project->spreadsheet_id) {
            throw new \RuntimeException('Project tidak memiliki spreadsheet ID.');
        }

        return new GoogleSheetsReader(
            $project->spreadsheet_id,
            config('marison.sheets.credentials'),
            config('marison.sheets.ca_bundle'),
            (int) config('marison.sheets.timeout', 30),
        );
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
