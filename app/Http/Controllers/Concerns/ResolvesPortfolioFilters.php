<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait ResolvesPortfolioFilters
{
    /**
     * @return array{projects: Collection, from: ?CarbonImmutable, to: ?CarbonImmutable, branch: ?string, project: ?string, period: string}
     */
    protected function portfolioFilters(Request $request): array
    {
        $accessible = Project::query()
            ->with('branch')
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get();

        $branchCode = $request->string('branch')->toString() ?: null;
        $projectCode = $request->string('project')->toString() ?: null;

        $projects = $accessible;

        if ($branchCode) {
            $projects = $projects->filter(fn (Project $project) => $project->branch?->code === $branchCode);
        }

        if ($projectCode) {
            $selected = $accessible->firstWhere('code', $projectCode);
            abort_unless($selected, 403, 'You do not have access to this project.');
            abort_unless($projects->contains('id', $selected->id), 403, 'Project is outside the selected branch.');
            $projects = collect([$selected]);
        }

        [$from, $to, $period] = $this->resolvePeriod($request);

        return [
            'projects' => $projects->values(),
            'branches' => $accessible
                ->map(fn (Project $project) => $project->branch)
                ->filter()
                ->unique('id')
                ->sortBy('name')
                ->values(),
            'from' => $from,
            'to' => $to,
            'branch' => $branchCode,
            'project' => $projectCode,
            'period' => $period,
        ];
    }

    private function resolvePeriod(Request $request): array
    {
        $period = $request->string('period')->toString() ?: 'ytd';
        $now = CarbonImmutable::now('Asia/Jakarta');

        if ($request->filled('from') || $request->filled('to')) {
            $from = $this->safeDate($request->string('from')->toString()) ?? $now->startOfYear();
            $to = $this->safeDate($request->string('to')->toString()) ?? $now;

            return [$from->startOfDay(), $to->endOfDay(), 'custom'];
        }

        return match ($period) {
            'all' => [null, null, 'all'],
            '2025' => [CarbonImmutable::create(2025, 1, 1), CarbonImmutable::create(2025, 12, 31)->endOfDay(), '2025'],
            '2026' => [CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31)->endOfDay(), '2026'],
            default => [$now->startOfYear(), $now->endOfDay(), 'ytd'],
        };
    }

    private function safeDate(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Asia/Jakarta');
        } catch (\Throwable) {
            return null;
        }
    }
}
