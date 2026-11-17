<?php

namespace App\Services\Metrics;

use App\Models\AkadRecord;
use App\Models\BankSubmission;
use App\Models\BastRecord;
use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\ImportBatch;
use App\Models\ImportIssue;
use App\Models\Lot;
use App\Models\PemberkasanRecord;
use App\Models\PpjbRecord;
use App\Models\Project;
use App\Models\PsjbRecord;
use Carbon\CarbonImmutable;

class Reconciliation
{
    public function __construct(
        private readonly Project $project,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {
    }

    public function report(): array
    {
        $batch = ImportBatch::query()
            ->where('project_id', $this->project->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        $raw = $batch?->stats['raw_counts'] ?? [];
        $rows = [
            $this->row('Data Kav', $raw['Data Kav'] ?? null, Lot::where('project_id', $this->project->id)->count(), 'Coded lot rows imported as inventory. Blank formula rows are ignored.'),
            $this->row('Data Konsumen', $raw['Data Konsumen'] ?? null, Buyer::whereHas('bookings', fn ($q) => $q->where('project_id', $this->project->id))->count(), 'Buyers linked to this project. The buyer table can be shared by more than one booking.'),
            $this->row('PSJB', $raw['PSJB'] ?? null, PsjbRecord::where('project_id', $this->project->id)->count(), 'Each PSJB identifier becomes its own booking cycle, even when the legacy transaction ID repeats.'),
            $this->row('BI Checking', $raw['BI Checking'] ?? null, BiCheck::where('project_id', $this->project->id)->count(), 'Rows are linked by transaction ID plus PSJB cycle date.'),
            $this->row('Pemberkasan', $raw['Pemberkasan'] ?? null, PemberkasanRecord::where('project_id', $this->project->id)->count(), 'Stale PSJB predecessor references are resolved against the nearest PSJB cycle.'),
            $this->row('Proses Bank', $raw['Proses Bank'] ?? null, BankSubmission::where('project_id', $this->project->id)->count(), 'Bank approvals and rejections are stored separately from booking decisions.'),
            $this->row('PPJB Developer', $raw['PPJB Developer'] ?? null, PpjbRecord::where('project_id', $this->project->id)->count(), 'Linked through the bank decision reference or nearest cycle.'),
            $this->row('Akad', $raw['Akad'] ?? null, AkadRecord::where('project_id', $this->project->id)->count(), 'Historical pre-2026 Akad records are retained but excluded from 2026 actuals.'),
            $this->row('BAST', $raw['BAST'] ?? null, BastRecord::where('project_id', $this->project->id)->count(), 'BAST is counted by its own date even when Akad happened before 2026.'),
        ];

        $issuesByTab = ImportIssue::query()
            ->where('project_id', $this->project->id)
            ->whereNull('resolved_at')
            ->selectRaw('source_tab, severity, count(*) as total')
            ->groupBy('source_tab', 'severity')
            ->get()
            ->groupBy('source_tab');

        $metrics = PortfolioMetrics::forProjects(collect([$this->project]), $this->from, $this->to)->summary();
        $hq = $batch?->stats['hq_export'] ?? [];
        $statusCounts = Booking::query()
            ->where('project_id', $this->project->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'project' => $this->project,
            'batch' => $batch,
            'raw' => $raw,
            'rows' => $rows,
            'metrics' => $metrics,
            'status_counts' => $statusCounts,
            'hq_export' => $hq,
            'hq_comparison' => $this->hqComparison($metrics, $hq),
            'issues_by_tab' => $issuesByTab,
            'open_issues' => ImportIssue::query()
                ->where('project_id', $this->project->id)
                ->whereNull('resolved_at')
                ->orderByRaw("CASE severity WHEN 'error' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
                ->orderBy('source_tab')
                ->limit(100)
                ->get(),
            'period' => [
                'from' => $this->from?->toDateString(),
                'to' => $this->to?->toDateString(),
            ],
        ];
    }

    private function row(string $tab, ?int $raw, int $imported, string $note): array
    {
        return [
            'tab' => $tab,
            'raw' => $raw,
            'imported' => $imported,
            'difference' => $raw === null ? null : $imported - $raw,
            'note' => $note,
        ];
    }

    private function hqComparison(array $metrics, array $hq): array
    {
        $m = $hq['metrics'] ?? [];
        $items = [];

        $items[] = $this->comparison(
            'Total lots',
            $metrics['total_lots'],
            $m['TOTAL_RUMAH'] ?? null,
            'Inventory comes from coded Data Kav rows. hq_report_export TOTAL_RUMAH is its own root count.',
        );
        $items[] = $this->comparison(
            'Available stock',
            $metrics['available'],
            $m['BELUM_TERJUAL'] ?? null,
            'Available excludes historical Akad markers and every lot with an open or completed booking.',
        );
        $items[] = $this->comparison(
            'Sold / handed over (position)',
            $metrics['completed_bookings'],
            $m['SERAH_TERIMA'] ?? null,
            'Dashboard counts current BAST holders. hq SERAH_TERIMA is a current-position bucket, not a period actual.',
        );
        $items[] = $this->comparison(
            'Akad in selected period',
            $metrics['akad'],
            null,
            'The HQ export does not expose period Akad actuals; its AKAD value is the number currently sitting at the Akad stage.',
        );
        $items[] = $this->comparison(
            'BAST in selected period',
            $metrics['bast'],
            null,
            'Dashboard BAST is an event count by BAST date. HQ SERAH_TERIMA is cumulative current position.',
        );

        return $items;
    }

    private function comparison(string $label, int $dashboard, ?float $hq, string $explanation): array
    {
        return [
            'label' => $label,
            'dashboard' => $dashboard,
            'hq' => $hq,
            'difference' => $hq === null ? null : $dashboard - $hq,
            'explanation' => $explanation,
        ];
    }
}
