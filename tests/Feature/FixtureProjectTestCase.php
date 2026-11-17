<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Project;
use App\Services\Import\FixtureSheetReader;
use App\Services\Import\ProjectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use \Tests\TestCase;

abstract class FixtureProjectTestCase extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::query()->create([
            'code' => 'JPR',
            'name' => 'Jepara',
            'city' => 'Jepara',
            'is_active' => true,
        ]);

        $this->project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Marison Regency Kuwasen',
            'spreadsheet_name' => 'Database Jepara 2026 (fixture)',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        (new ProjectImporter(
            new FixtureSheetReader(base_path('tests/Fixtures/kuwasen-regression.json'))
        ))->run($this->project, false);
    }
}
