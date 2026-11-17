<?php

namespace App\Services\Metrics;

use App\Models\AkadRecord;
use App\Models\BastRecord;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\ImportIssue;
use App\Models\Lot;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PortfolioMetrics
{
    public function __construct(
        private readonly Collection $projects,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {
    }

    public static function forProjects(Collection $projects, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): self
    {
        return new self($projects, $from, $to);
    }

    public function summary(): array
    {
        $projectIds = $this->projects->pluck('id');

        $lots = Lot::query()->whereIn('project_id', $projectIds);
        $totalLots = (clone $lots)->count();
        $historical = (clone $lots)->where('historical_akad_before_2026', true)->count();

        $openBookingStatuses = [Booking::STATUS_ACTIVE, Booking::STATUS_COMPLETED, Booking::STATUS_NEEDS_REVIEW];
        $occupiedLots = (clone $lots)
            ->where('historical_akad_before_2026', false)
            ->whereHas('bookings', fn ($q) => $q->whereIn('status', $openBookingStatuses))
            ->count();

        $available = max(0, $totalLots - $historical - $occupiedLots);

        $activeBookings = Booking::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', Booking::STATUS_ACTIVE)
            ->where('is_current_holder', true)
            ->count();

        $needsReview = Booking::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', Booking::STATUS_NEEDS_REVIEW)
            ->count();

        $completedBookings = Booking::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', Booking::STATUS_COMPLETED)
            ->where('is_current_holder', true)
            ->count();

        $akad = $this->stageCount(AkadRecord::class, 'akad_date', $projectIds);
        $bast = $this->stageCount(BastRecord::class, 'bast_date', $projectIds);
        $newBookings = $this->bookingCount('booking_date', $projectIds);
        $withdrawn = $this->bookingCount('decision_date', $projectIds, Booking::STATUS_MUNDUR);
        $cancelled = $this->bookingCount('decision_date', $projectIds, Booking::STATUS_BATAL);

        $unresolved = ImportIssue::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('resolved_at')
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->all();

        return [
            'total_lots' => $totalLots,
            'historical_akad' => $historical,
            'available' => $available,
            'occupied' => $occupiedLots,
            'active_bookings' => $activeBookings,
            'needs_review' => $needsReview,
            'completed_bookings' => $completedBookings,
            'akad' => $akad,
            'bast' => $bast,
            'new_bookings' => $newBookings,
            'withdrawn' => $withdrawn,
            'cancelled' => $cancelled,
            'unresolved_errors' => (int) ($unresolved['error'] ?? 0),
            'unresolved_warnings' => (int) ($unresolved['warning'] ?? 0),
            'unresolved_total' => array_sum($unresolved),
        ];
    }

    public function byProject(): Collection
    {
        return $this->projects->map(function (Project $project) {
            $metrics = new self(collect([$project]), $this->from, $this->to);

            return array_merge([
                'project' => $project,
                'branch' => $project->branch,
            ], $metrics->summary());
        })->values();
    }

    public function stagePipeline(): array
    {        $ids = $this->projects->pluck('id');

        $rows = Booking::query()
            ->whereIn('project_id', $ids)
            ->where('is_current_holder', true)
            ->where('status', Booking::STATUS_ACTIVE)
            ->select('current_stage', DB::raw('count(*) as total'))
            ->groupBy('current_stage')
            ->pluck('total', 'current_stage')
            ->all();

        return array_map(fn (string $stage) => [
            'stage' => $stage,
            'label' => (new Booking(['current_stage' => $stage]))->stageLabel($stage),
            'total' => (int) ($rows[$stage] ?? 0),
        ], Booking::STAGES);
    }

    public function positions(): array
    {
        $ids = $this->projects->pluck('id');
        $base = Lot::query()->whereIn('project_id', $ids)->where('historical_akad_before_2026', false);

        $booked = (clone $base)->whereHas('bookings', fn ($q) => $q->where('status', Booking::STATUS_ACTIVE)->where('is_current_holder', true))->count();
        $sold = (clone $base)->whereHas('bookings', fn ($q) => $q->where('status', Booking::STATUS_COMPLETED)->where('is_current_holder', true))->count();
        $needs = (clone $base)
            ->whereHas('bookings', fn ($q) => $q->where('status', Booking::STATUS_NEEDS_REVIEW))
            ->whereDoesntHave('bookings', fn ($q) => $q->whereIn('status', [Booking::STATUS_ACTIVE, Booking::STATUS_COMPLETED]))
            ->count();
        $historical = Lot::query()->whereIn('project_id', $ids)->where('historical_akad_before_2026', true)->count();
        $total = Lot::query()->whereIn('project_id', $ids)->count();
        $available = max(0, $total - $historical - $booked - $sold - $needs);

        return [
            ['key' => 'available', 'label' => 'Available', 'total' => $available, 'tone' => 'emerald'],
            ['key' => 'booked', 'label' => 'Booked / in process', 'total' => $booked, 'tone' => 'amber'],
            ['key' => 'sold', 'label' => 'BAST / sold', 'total' => $sold, 'tone' => 'navy'],
            ['key' => 'historical', 'label' => 'Akad before 2026', 'total' => $historical, 'tone' => 'slate'],
            ['key' => 'needs_review', 'label' => 'Needs review', 'total' => $needs, 'tone' => 'rose'],
        ];
    }

    public function recentBookings(int $limit = 8): Collection
    {        return Booking::query()
            ->with(['lot', 'buyer', 'project'])
            ->whereIn('project_id', $this->projects->pluck('id'))
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function projectComparison(): Collection
    {
        $rows = $this->byProject();

        return $rows->map(fn ($row) => [
            'project' => $row['project']->name,
            'code' => $row['project']->code,
            'branch' => $row['branch']->name,
            'total_lots' => $row['total_lots'],
            'available' => $row['available'],
            'active' => $row['active_bookings'],
            'akad' => $row['akad'],
            'bast' => $row['bast'],
            'issues' => $row['unresolved_total'],
        ]);
    }

    private function stageCount(string $model, string $column, Collection $projectIds): int
    {
        $query = $model::query()->whereIn('project_id', $projectIds);
        if ($this->from && $this->to) {
            $query->whereBetween($column, [$this->from->startOfDay(), $this->to->endOfDay()]);
        } elseif ($this->from) {
            $query->where($column, '>=', $this->from->startOfDay());
        } elseif ($this->to) {
            $query->where($column, '<=', $this->to->endOfDay());
        }

        return $query->count();
    }

    private function bookingCount(string $column, Collection $projectIds, ?string $status = null): int
    {
        $query = Booking::query()->whereIn('project_id', $projectIds);
        if ($status) {
            $query->where('status', $status);
        }
        if ($this->from && $this->to) {
            $query->whereBetween($column, [$this->from->startOfDay(), $this->to->endOfDay()]);
        } elseif ($this->from) {
            $query->where($column, '>=', $this->from->startOfDay());
        } elseif ($this->to) {
            $query->where($column, '<=', $this->to->endOfDay());
        }

        return $query->count();
    }
}
