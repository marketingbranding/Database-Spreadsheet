<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use App\Services\Import\GoogleSheetsReader;
use App\Services\Import\ProjectImporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunProjectImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $batchId,
        public int $requestedBy,
    ) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $batch = ImportBatch::query()->findOrFail($this->batchId);
        $project = Project::query()->findOrFail($batch->project_id);
        $requester = User::query()->findOrFail($this->requestedBy);
        if (! $project->is_active || ! $requester->canAccessProject($project)) {
            throw new AuthorizationException('Import requester no longer has project access.');
        }

        $reader = new GoogleSheetsReader(
            $project->spreadsheet_id,
            config('marison.sheets.credentials'),
            config('marison.sheets.ca_bundle'),
            (int) config('marison.sheets.timeout', 30),
        );

        (new ProjectImporter($reader))->run($project, false, null, $batch);
    }

    public function failed(?\Throwable $exception): void
    {
        ImportBatch::query()->whereKey($this->batchId)->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'Import job failed.', 0, 2000),
            'finished_at' => now(),
        ]);
    }
}
