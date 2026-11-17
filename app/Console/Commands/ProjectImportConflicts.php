<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\GoogleSheetsReader;
use App\Services\Import\ImportConflictProjector;
use App\Services\Import\ProjectImporter;
use App\Services\Import\SheetReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProjectImportConflicts extends Command
{
    private const PILOT_PROJECT_CODES = ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'];

    protected $signature = 'marison:conflicts:project
        {project : Pilot project code}
        {--apply : Persist conflict records only}
        {--fixture= : Optional fixture JSON path for rehearsal}';

    protected $description = 'Preview or project durable import conflicts without applying operational imports.';

    public function __construct(private readonly ImportConflictProjector $projector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (config('app.env') !== 'staging') {
            $this->error('Import conflict projection is available only in staging.');

            return self::FAILURE;
        }

        $project = Project::query()->where('code', (string) $this->argument('project'))->first();
        if (! $project || ! in_array($project->code, self::PILOT_PROJECT_CODES, true)) {
            $this->error('Project must be one of the two staging pilot projects.');

            return self::FAILURE;
        }

        try {
            $reader = $this->reader($project);
            $tabs = config('marison.import.tabs');
            $rawTabs = $reader->read([...$tabs, config('marison.import.hq_export_tab')]);
            $report = (new ProjectImporter($reader))->run($project, true, $rawTabs);
            $hqPositions = $this->projector->hqPositions(
                $project,
                $rawTabs[config('marison.import.hq_export_tab')] ?? []
            );
            $descriptors = $this->projector->preview($project, $report->issues, $rawTabs, $hqPositions);

            if ($this->option('apply')) {
                DB::transaction(fn () => $this->projector->persistCurrent(
                    $project,
                    $report->issues,
                    $rawTabs,
                    $hqPositions
                ));
            }
        } catch (\Throwable $exception) {
            $this->error('Conflict projection failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Case', 'Source', 'Lot IDs', 'Booking IDs', 'Fingerprint'],
            collect($descriptors)->map(fn (array $descriptor): array => [
                $descriptor['case_type'],
                ($descriptor['source_tab'] ?? 'unknown').':'.($descriptor['source_row'] ?? 'n/a'),
                implode(',', $descriptor['lot_ids']),
                implode(',', array_column($descriptor['booking_links'], 'booking_id')),
                substr($descriptor['source_fingerprint'], 0, 12),
            ])->all()
        );
        $this->info(sprintf(
            '%s %d conflict descriptor(s); operational imports were not applied.',
            $this->option('apply') ? 'Projected' : 'Previewed',
            count($descriptors)
        ));

        return self::SUCCESS;
    }

    private function reader(Project $project): SheetReader
    {
        if ($fixture = $this->option('fixture')) {
            $path = (string) $fixture;
            if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
                $path = base_path($path);
            }

            return new FixtureSheetReader($path);
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
