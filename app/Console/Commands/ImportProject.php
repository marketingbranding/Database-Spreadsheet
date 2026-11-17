<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\GoogleSheetsReader;
use App\Services\Import\ProjectImporter;
use App\Services\Import\SheetReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportProject extends Command
{
    protected $signature = 'marison:import
        {--project= : Project code (for example PRJ-JPR-KWS)}
        {--dry-run : Read and validate without committing operational changes}
        {--source=google : google or fixture}
        {--fixture= : Path to a fixture JSON file when --source=fixture}
        {--report= : Optional path (relative to storage/app) for a JSON import report}
        {--json : Print the summary as JSON}';

    protected $description = 'Import one Marison project from its read-only Google Sheet (or a fixture).';

    public function handle(): int
    {
        $code = (string) $this->option('project');
        if ($code === '') {
            $this->error('Pass --project=<code>. One project is imported per run.');

            return self::FAILURE;
        }

        $project = Project::query()->where('code', $code)->first();
        if (! $project) {
            $this->error("Project [{$code}] was not found. Known projects: " . Project::query()->pluck('code')->implode(', '));

            return self::FAILURE;
        }

        try {
            $reader = $this->makeReader($project);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = (new ProjectImporter($reader))->run($project, $dryRun);
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'project' => $project->code,
            'name' => $project->name,
            'dry_run' => $dryRun,
            'batch_id' => $report->batchId,
            'stats' => $report->stats,
            'issues' => $report->issues,
        ];

        if ($path = $this->option('report')) {
            Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('Report written to storage/app/' . $path);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'project' => $project->code,
                'dry_run' => $dryRun,
                'batch_id' => $report->batchId,
                'stats' => $report->stats,
                'issues' => array_slice($report->issues, 0, 50),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(($dryRun ? 'DRY RUN' : 'APPLIED') . " — {$project->code} · {$project->name}");
        $this->line('Source: ' . ($report->stats['source'] ?? 'unknown'));
        $this->newLine();

        $rows = [];
        foreach ([
            'lots' => 'Lots',
            'buyers' => 'Buyers',
            'bookings' => 'Bookings',
            'psjb_records' => 'PSJB records',
            'bi_checks' => 'BI checks',
            'pemberkasan_records' => 'Pemberkasan',
            'bank_submissions' => 'Bank submissions',
            'ppjb_records' => 'PPJB',
            'akad_records' => 'Akad',
            'bast_records' => 'BAST',
        ] as $key => $label) {
            $rows[] = [$label, $report->created($key), $report->updated($key)];
        }
        $this->table(['Entity', 'Created', 'Updated'], $rows);

        $this->line(sprintf(
            'Issues: %d error, %d warning, %d info',
            $report->errorCount(),
            $report->warningCount(),
            $report->infoCount(),
        ));

        if ($report->issues !== []) {
            $breakdown = [];
            foreach ($report->issueBreakdown() as $issueCode => $count) {
                $breakdown[] = [$issueCode, $count];
            }
            $this->table(['Issue code', 'Count'], $breakdown);
        }

        if (isset($report->stats['hq_export']['metrics'])) {
            $m = $report->stats['hq_export']['metrics'];
            $this->line('HQ export benchmark: total=' . ($m['TOTAL_RUMAH'] ?? '?')
                . ', available=' . ($m['BELUM_TERJUAL'] ?? '?')
                . ', serah_terima=' . ($m['SERAH_TERIMA'] ?? '?')
                . ', omitted lots=' . ($report->stats['hq_export']['omitted_lots'] ?? '?'));
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run completed inside a rolled-back transaction. No operational changes were committed.');
        }

        return self::SUCCESS;
    }

    private function makeReader(Project $project): SheetReader
    {
        $source = (string) $this->option('source');

        if ($source === 'fixture') {
            $path = (string) $this->option('fixture');
            if ($path === '') {
                throw new \InvalidArgumentException('Pass --fixture=<path> when --source=fixture.');
            }
            if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
                $path = base_path($path);
            }

            return new FixtureSheetReader($path);
        }

        if ($source !== 'google') {
            throw new \InvalidArgumentException('--source must be google or fixture.');
        }

        if (! $project->spreadsheet_id) {
            throw new \RuntimeException("Project {$project->code} has no spreadsheet_id.");
        }

        return new GoogleSheetsReader(
            $project->spreadsheet_id,
            config('marison.sheets.credentials'),
            config('marison.sheets.ca_bundle'),
            (int) config('marison.sheets.timeout', 30),
        );
    }
}
