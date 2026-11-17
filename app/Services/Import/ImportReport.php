<?php

namespace App\Services\Import;

use App\Models\Project;
use Illuminate\Support\Collection;

class ImportReport
{
    public function __construct(
        public readonly Project $project,
        public readonly bool $dryRun,
        public readonly array $stats,
        public readonly array $issues,
        public readonly ?int $batchId = null,
    ) {
    }

    public function errorCount(): int
    {
        return $this->stats['issues']['error'] ?? 0;
    }

    public function warningCount(): int
    {
        return $this->stats['issues']['warning'] ?? 0;
    }

    public function infoCount(): int
    {
        return $this->stats['issues']['info'] ?? 0;
    }

    public function issuesBySeverity(string $severity): Collection
    {
        return collect($this->issues)->where('severity', $severity)->values();
    }

    public function created(string $key): int
    {
        return $this->stats[$key]['created'] ?? 0;
    }

    public function updated(string $key): int
    {
        return $this->stats[$key]['updated'] ?? 0;
    }

    public function summaryLines(): array
    {
        $lines = [
            ($this->dryRun ? 'DRY RUN' : 'APPLIED') . ' — ' . $this->project->code . ' · ' . $this->project->name,
            'Source: ' . ($this->stats['source'] ?? 'unknown'),
            'Lots: ' . $this->created('lots') . ' created, ' . $this->updated('lots') . ' updated',
            'Buyers: ' . $this->created('buyers') . ' created, ' . $this->updated('buyers') . ' updated',
            'Bookings: ' . $this->created('bookings') . ' created, ' . $this->updated('bookings') . ' updated',
            'PSJB: ' . $this->created('psjb_records') . ' created, ' . $this->updated('psjb_records') . ' updated',
            'BI: ' . $this->created('bi_checks') . ' / ' . $this->updated('bi_checks') . ' · ' .
                'Pemberkasan: ' . $this->created('pemberkasan_records') . ' / ' . $this->updated('pemberkasan_records'),
            'Bank: ' . $this->created('bank_submissions') . ' / ' . $this->updated('bank_submissions') . ' · ' .
                'PPJB: ' . $this->created('ppjb_records') . ' / ' . $this->updated('ppjb_records'),
            'Akad: ' . $this->created('akad_records') . ' / ' . $this->updated('akad_records') . ' · ' .
                'BAST: ' . $this->created('bast_records') . ' / ' . $this->updated('bast_records'),
            'Issues: ' . $this->errorCount() . ' error, ' . $this->warningCount() . ' warning, ' . $this->infoCount() . ' info',
        ];

        if (isset($this->stats['hq_export']['metrics'])) {
            $m = $this->stats['hq_export']['metrics'];
            $lines[] = 'HQ export: total=' . ($m['TOTAL_RUMAH'] ?? '?') . ' available=' . ($m['BELUM_TERJUAL'] ?? '?') .
                ' akad(position)=' . ($m['AKAD'] ?? '?') . ' serah_terima=' . ($m['SERAH_TERIMA'] ?? '?');
        }

        return $lines;
    }

    public function issueBreakdown(): array
    {
        $breakdown = [];
        foreach ($this->issues as $issue) {
            $key = $issue['code'];
            $breakdown[$key] = ($breakdown[$key] ?? 0) + 1;
        }
        arsort($breakdown);

        return $breakdown;
    }
}
