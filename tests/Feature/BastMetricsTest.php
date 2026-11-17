<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\User;
use App\Services\Metrics\PortfolioMetrics;
use App\Services\Operations\PsjbWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BastMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging']);
        putenv('MARISON_OPERATIONAL_WRITES=true');
    }

    public function test_completed_bast_counts_once_by_actual_handover_date_after_older_akad(): void
    {
        [$project, $admin, $booking, $akad] = $this->scenario('2025-12-31', false);
        $metrics = fn () => PortfolioMetrics::forProjects(
            collect([$project]),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        )->summary()['bast'];

        $this->assertSame(0, $metrics());

        app(PsjbWorkflow::class)->recordBast($admin, $booking, [
            'akad_id' => $akad->id,
            'bast_number' => 'BAST-METRICS-01',
            'bast_date' => '2026-01-02',
            'idempotency_key' => 'bast-metrics-1',
        ]);

        $this->assertSame(1, $metrics());
        $this->assertSame(1, $metrics());
    }

    public function test_historical_inventory_marker_alone_does_not_create_bast_event(): void
    {
        [$project] = $this->scenario('2025-12-31', true);

        $summary = PortfolioMetrics::forProjects(
            collect([$project]),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        )->summary();

        $this->assertSame(0, $summary['bast']);
    }

    private function scenario(string $akadDate, bool $historical): array
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);
        $project = Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
            'management_mode' => Project::MANAGEMENT_LARAVEL,
        ]);
        $admin = User::query()->create([
            'name' => 'Metrics Admin',
            'email' => 'metrics-bast-'.uniqid().'@test',
            'password' => 'password',
            'role' => User::ROLE_BRANCH_ADMIN,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $admin->projects()->attach($project);
        $buyer = Buyer::query()->create(['source_key' => 'buyer:metrics-bast-'.uniqid(), 'name' => 'Metrics BAST Buyer']);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'M-BLAST-'.uniqid(),
            'source_key' => 'lot:metrics-bast-'.uniqid(),
            'historical_akad_before_2026' => $historical,
        ]);
        $booking = Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:metrics-bast-'.uniqid(),
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_AKAD,
            'payment_method' => 'KPR',
        ]);
        $akad = AkadRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'AKAD:metrics-bast-'.uniqid(),
            'akad_number' => 'AKD-METRICS-'.uniqid(),
            'akad_date' => $akadDate,
            'quality' => 'Akad Sempurna',
            'source_owner' => AkadRecord::OWNER_LARAVEL,
        ]);

        return [$project, $admin, $booking, $akad];
    }
}
