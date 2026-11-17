<?php

namespace Tests\Feature;

use App\Models\AkadRecord;
use App\Models\BastRecord;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Services\Metrics\PortfolioMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AkadMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_akad_period_total_uses_actual_akad_date_and_excludes_bast_date(): void
    {
        $project = $this->project();
        $oldBooking = $this->booking($project, 'old');
        $currentBooking = $this->booking($project, 'current');
        $bastOnlyBooking = $this->booking($project, 'bast');

        $this->akad($project, $oldBooking, '2025-12-31', 'AKD-OLD');
        $this->akad($project, $currentBooking, '2026-09-30', 'AKD-CURRENT');
        BastRecord::query()->create([
            'booking_id' => $bastOnlyBooking->id,
            'project_id' => $project->id,
            'source_key' => 'BAST:metrics',
            'bast_number' => 'BAST-METRICS',
            'bast_date' => '2026-09-30',
        ]);

        $summary = PortfolioMetrics::forProjects(
            collect([$project]),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        )->summary();

        $this->assertSame(1, $summary['akad']);
        $this->assertSame(1, $summary['bast']);
    }

    private function project(): Project
    {
        $branch = Branch::query()->create(['code' => 'JPR', 'name' => 'Jepara']);

        return Project::query()->create([
            'branch_id' => $branch->id,
            'code' => 'PRJ-JPR-KWS',
            'name' => 'Kuwasen',
        ]);
    }

    private function booking(Project $project, string $suffix): Booking
    {
        $buyer = Buyer::query()->create(['source_key' => 'buyer:metrics-'.$suffix, 'name' => 'Metrics '.$suffix]);
        $lot = Lot::query()->create([
            'project_id' => $project->id,
            'code' => 'M-'.$suffix,
            'source_key' => 'lot:metrics-'.$suffix,
        ]);

        return Booking::query()->create([
            'project_id' => $project->id,
            'lot_id' => $lot->id,
            'buyer_id' => $buyer->id,
            'source_booking_key' => 'booking:metrics-'.$suffix,
            'status' => Booking::STATUS_ACTIVE,
            'current_stage' => Booking::STAGE_AKAD,
        ]);
    }

    private function akad(Project $project, Booking $booking, string $date, string $number): void
    {
        AkadRecord::query()->create([
            'booking_id' => $booking->id,
            'project_id' => $project->id,
            'source_key' => 'AKAD:metrics-'.$number,
            'akad_number' => $number,
            'akad_date' => $date,
            'quality' => 'Akad Sempurna',
        ]);
    }
}
