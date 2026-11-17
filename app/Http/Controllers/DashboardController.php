<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPortfolioFilters;
use App\Services\Metrics\PortfolioMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResolvesPortfolioFilters;

    public function __invoke(Request $request): View
    {
        $filters = $this->portfolioFilters($request);

        if ($filters['projects']->isEmpty()) {
            return view('dashboard', [
                'filters' => $filters,
                'summary' => null,
                'perProject' => collect(),
                'pipeline' => [],
                'positions' => [],
                'comparison' => collect(),
                'recent' => collect(),
            ]);
        }

        $metrics = PortfolioMetrics::forProjects($filters['projects'], $filters['from'], $filters['to']);

        return view('dashboard', [
            'filters' => $filters,
            'summary' => $metrics->summary(),
            'perProject' => $metrics->byProject(),
            'pipeline' => $metrics->stagePipeline(),
            'positions' => $metrics->positions(),
            'comparison' => $metrics->projectComparison(),
            'recent' => $metrics->recentBookings(),
        ]);
    }
}
