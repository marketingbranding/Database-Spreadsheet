<?php

namespace App\Services\Import;

use App\Models\Booking;
use App\Models\ImportBatch;
use App\Models\ImportConflict;
use App\Models\ImportConflictBooking;
use App\Models\ImportConflictEvent;
use App\Models\ImportIssue;
use App\Models\Lot;
use App\Models\Project;
use App\Models\PsjbRecord;
use App\Support\Normalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ImportConflictProjector
{
    private const CLAIM_STATUSES = [
        Booking::STATUS_ACTIVE,
        Booking::STATUS_COMPLETED,
        Booking::STATUS_NEEDS_REVIEW,
    ];

    /**
     * @param  array<int, array<string, mixed>|ImportIssue>  $issues
     * @param  array<string, array<int, array<int, mixed>>>  $rawTabs
     * @param  array<string, string>  $hqPositions
     * @return array<int, array<string, mixed>>
     */
    public function preview(Project $project, array $issues, array $rawTabs, array $hqPositions): array
    {
        $descriptors = [];

        foreach ($issues as $issue) {
            $attributes = $issue instanceof ImportIssue ? $issue->toArray() : $issue;
            $descriptor = $this->describeIssue($project, $attributes, $rawTabs);
            if ($descriptor !== null) {
                $descriptors[$descriptor['stable_source_identity']] = $descriptor;
            }
        }

        foreach ($this->stockDescriptors($project, $hqPositions) as $descriptor) {
            $descriptors[$descriptor['stable_source_identity']] = $descriptor;
        }

        return array_values($descriptors);
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $rawTabs
     * @param  array<string, string>  $hqPositions
     */
    public function persist(Project $project, ImportBatch $batch, array $rawTabs, array $hqPositions): void
    {
        $issues = ImportIssue::query()
            ->where('project_id', $project->id)
            ->where('import_batch_id', $batch->id)
            ->get()
            ->all();

        $this->persistCurrent($project, $issues, $rawTabs, $hqPositions);
    }

    /**
     * @param  array<int, array<string, mixed>|ImportIssue>  $issues
     * @param  array<string, array<int, array<int, mixed>>>  $rawTabs
     * @param  array<string, string>  $hqPositions
     */
    public function persistCurrent(Project $project, array $issues, array $rawTabs, array $hqPositions): void
    {
        foreach ($this->preview($project, $issues, $rawTabs, $hqPositions) as $descriptor) {
            $conflict = ImportConflict::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'stable_source_identity' => $descriptor['stable_source_identity'],
                    'source_fingerprint' => $descriptor['source_fingerprint'],
                ],
                Arr::only($descriptor, [
                    'current_import_issue_id',
                    'case_type',
                    'status',
                    'severity',
                    'source_tab',
                    'source_row',
                    'source_key',
                    'source_value',
                    'laravel_value',
                    'inventory_impact',
                ])
            );

            if (! $conflict->wasRecentlyCreated) {
                $conflict->fill(Arr::only($descriptor, [
                    'current_import_issue_id',
                    'severity',
                    'source_tab',
                    'source_row',
                    'source_key',
                    'source_value',
                    'laravel_value',
                    'inventory_impact',
                ]))->save();
            }

            $conflict->lots()->syncWithoutDetaching($descriptor['lot_ids']);
            foreach ($descriptor['booking_links'] as $link) {
                ImportConflictBooking::query()->firstOrCreate([
                    'import_conflict_id' => $conflict->id,
                    'booking_id' => $link['booking_id'],
                    'role' => $link['role'],
                ]);
            }
            ImportConflictEvent::query()->firstOrCreate([
                'import_conflict_id' => $conflict->id,
                'event_type' => ImportConflictEvent::TYPE_PROJECTED,
                'source_fingerprint' => $conflict->source_fingerprint,
            ], [
                'source_reference' => $this->sourceReference($descriptor),
                'after' => [
                    'source_value' => $descriptor['source_value'],
                    'laravel_value' => $descriptor['laravel_value'],
                    'inventory_impact' => $descriptor['inventory_impact'],
                ],
            ]);
        }
    }

    public function fingerprint(string $caseType, array $sourceValue): string
    {
        $normalized = $this->normalizeFingerprintValue($sourceValue);

        return hash('sha256', json_encode([
            'case_type' => $caseType,
            'source' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, string>
     */
    public function hqPositions(Project $project, array $rows): array
    {
        $positions = [];

        foreach ($rows as $row) {
            $row = array_values((array) $row);
            $id = Normalizer::text($row[23] ?? null);
            $position = Normalizer::text($row[24] ?? null);
            if (! $id || ! $position || strtolower($id) === 'id_kavling') {
                continue;
            }
            $code = Normalizer::lotCode($id, $project->name);
            if ($code) {
                $positions[$code] = strtoupper($position);
            }
        }

        return $positions;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<string, array<int, array<int, mixed>>>  $rawTabs
     * @return array<string, mixed>|null
     */
    private function describeIssue(Project $project, array $issue, array $rawTabs): ?array
    {
        $caseType = $this->caseType($issue);
        if ($caseType === null) {
            return null;
        }

        $tab = $issue['source_tab'] ?? null;
        $row = isset($issue['source_row']) ? (int) $issue['source_row'] : null;
        $sourceValue = $this->namedSourceValue($caseType, $tab, $row, $rawTabs);
        $lot = $this->resolveLot($project, $issue, $sourceValue);
        $bookings = $this->resolveBookings($project, $issue, $lot);
        $bookingLinks = $bookings->map(fn (Booking $booking): array => [
            'booking_id' => $booking->id,
            'role' => $booking->is_current_holder
                ? ImportConflictBooking::ROLE_CURRENT_HOLDER
                : ImportConflictBooking::ROLE_CLAIM,
        ])->values()->all();

        $laravelValue = [
            'lot_id' => $lot?->id,
            'lot_code' => $lot?->code,
            'inventory_position' => $lot?->inventoryPosition(),
            'current_holder_booking_id' => $bookings->firstWhere('is_current_holder', true)?->id,
            'bookings' => $bookings->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'source_booking_key' => $booking->source_booking_key,
                'status' => $booking->status,
                'current_stage' => $booking->current_stage,
                'is_current_holder' => (bool) $booking->is_current_holder,
                'source_owner' => $booking->source_owner,
            ])->values()->all(),
        ];

        $inventoryImpact = match ($caseType) {
            ImportConflict::CASE_UNLINKED_BI => [
                'summary' => 'No booking is linked; inventory is unchanged.',
                'laravel_position' => $lot?->inventoryPosition(),
            ],
            default => [
                'summary' => $lot
                    ? "Lot {$lot->code} remains {$lot->inventoryPosition()} pending review."
                    : 'No lot could be resolved from the source evidence.',
                'laravel_position' => $lot?->inventoryPosition(),
                'valid_claim_count' => $bookings->whereIn('status', self::CLAIM_STATUSES)->count(),
            ],
        };

        $identityPart = $issue['source_key']
            ?? ($lot?->code ? 'lot:'.$lot->code : 'row:'.($row ?? 'unknown'));
        $stableIdentity = implode('|', [
            $project->code,
            $caseType,
            $tab ?? 'unknown',
            Str::lower(trim((string) $identityPart)),
        ]);
        $fingerprintSource = [
            'source_key' => $issue['source_key'] ?? null,
            'source_tab' => $tab,
            'source_value' => $sourceValue,
            'lot_code' => $lot?->code,
        ];

        return [
            'current_import_issue_id' => $issue['id'] ?? null,
            'stable_source_identity' => $stableIdentity,
            'case_type' => $caseType,
            'status' => ImportConflict::STATUS_OPEN,
            'severity' => $issue['severity'] ?? ImportIssue::SEVERITY_WARNING,
            'source_tab' => $tab,
            'source_row' => $row,
            'source_key' => $issue['source_key'] ?? null,
            'source_fingerprint' => $this->fingerprint($caseType, $fingerprintSource),
            'source_value' => $sourceValue,
            'laravel_value' => $laravelValue,
            'inventory_impact' => $inventoryImpact,
            'lot_ids' => $lot ? [$lot->id] : [],
            'booking_links' => $bookingLinks,
        ];
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function caseType(array $issue): ?string
    {
        $code = $issue['code'] ?? null;

        if ($code === 'multiple_active_bookings' || $code === 'multiple_psjb_cycles') {
            return ImportConflict::CASE_COMPETING_CLAIMS;
        }
        if ($code === 'orphan_bi') {
            return ImportConflict::CASE_UNLINKED_BI;
        }
        if (in_array($code, ['bank_reject_without_booking_decision', 'unmapped_psjb_status'], true)) {
            return ImportConflict::CASE_BANK_REJECT;
        }
        if ($code === 'transfer_link_mismatch') {
            return ImportConflict::CASE_STOCK_DISAGREEMENT;
        }
        if ($code === 'import_conflict' && in_array($issue['source_tab'] ?? null, [
            'PPJB Developer', 'Akad', 'BAST',
        ], true)) {
            return ImportConflict::CASE_SIGNED_DOCUMENT;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<string, mixed>  $sourceValue
     */
    private function resolveLot(Project $project, array $issue, array $sourceValue): ?Lot
    {
        $context = (array) ($issue['context'] ?? []);
        $code = $context['lot'] ?? $context['source_lot'] ?? $context['destination_lot'] ?? null;

        if (! $code) {
            $locator = implode(' ', array_map(
                fn ($value): string => is_scalar($value) ? (string) $value : '',
                $sourceValue
            ));
            if (preg_match('/(?:^|\|\s*|\b)([A-Z]\d{2})(?:\b|\s*\|)/i', $locator, $matches)) {
                $code = strtoupper($matches[1]);
            }
        }

        if (! $code && ($booking = $this->bookingForSource($project, $issue['source_key'] ?? null))) {
            return $booking->lot;
        }

        return $code
            ? Lot::query()->where('project_id', $project->id)->where('code', strtoupper(trim((string) $code)))->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return Collection<int, Booking>
     */
    private function resolveBookings(Project $project, array $issue, ?Lot $lot): Collection
    {
        $caseType = $this->caseType($issue);
        if ($lot && in_array($caseType, [
            ImportConflict::CASE_COMPETING_CLAIMS,
            ImportConflict::CASE_STOCK_DISAGREEMENT,
        ], true)) {
            return Booking::query()
                ->where('project_id', $project->id)
                ->where('lot_id', $lot->id)
                ->orderBy('id')
                ->get();
        }

        $booking = $this->bookingForSource($project, $issue['source_key'] ?? null);

        return $booking ? collect([$booking]) : collect();
    }

    private function bookingForSource(Project $project, ?string $sourceKey): ?Booking
    {
        if (! $sourceKey) {
            return null;
        }

        $booking = Booking::query()
            ->where('project_id', $project->id)
            ->where(function ($query) use ($sourceKey): void {
                $query->where('source_booking_key', $sourceKey)
                    ->orWhere('source_transaction_id', $sourceKey);
            })
            ->first();
        if ($booking) {
            return $booking;
        }

        $psjbKey = str_starts_with($sourceKey, 'PSJB:') ? $sourceKey : 'PSJB:'.$sourceKey;
        $psjb = PsjbRecord::query()
            ->where('project_id', $project->id)
            ->where(function ($query) use ($sourceKey, $psjbKey): void {
                $query->where('source_key', $sourceKey)
                    ->orWhere('source_key', $psjbKey)
                    ->orWhere('psjb_number', str_replace('PSJB:', '', $sourceKey));
            })
            ->first();

        return $psjb?->booking;
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $rawTabs
     * @return array<string, mixed>
     */
    private function namedSourceValue(string $caseType, ?string $tab, ?int $row, array $rawTabs): array
    {
        if (! $tab || ! $row || ! isset($rawTabs[$tab][0], $rawTabs[$tab][$row - 1])) {
            return [];
        }

        $headers = array_values((array) $rawTabs[$tab][0]);
        $values = array_values((array) $rawTabs[$tab][$row - 1]);
        $allowed = $this->allowedHeaders($caseType);
        $result = [];

        foreach ($headers as $index => $header) {
            $name = trim((string) $header);
            if ($name === '' || ! in_array($name, $allowed, true)) {
                continue;
            }
            $result[$name] = $values[$index] ?? null;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function allowedHeaders(string $caseType): array
    {
        return match ($caseType) {
            ImportConflict::CASE_UNLINKED_BI => [
                'Pilih Konsumen', 'Tanggal BI', 'Hasil SLIK', 'Keputusan',
                '[SYS] ID BI', '[SYS] ID Transaksi', '[SYS] Nama Current',
                '[SYS] Kavling Current',
            ],
            ImportConflict::CASE_BANK_REJECT => [
                'Pilih Pemberkasan', 'Status Transaksi', 'Status Konsumen',
                'Tanggal Pengajuan', 'Tanggal Putusan', 'Hasil Putusan',
                '[SYS] ID Transaksi', '[SYS] ID Proses Bank',
            ],
            default => [
                'Nama', 'Kavling', 'Status', 'Metode Pembayaran', 'Jenis PSJB',
                '[SYS] ID Transaksi', '[AUTO] No. PSJB', '[SYS] Nama Current',
                '[SYS] Kavling Current', '[SYS] ID Akad Sebelumnya',
            ],
        };
    }

    /**
     * @param  array<string, string>  $hqPositions
     * @return array<int, array<string, mixed>>
     */
    private function stockDescriptors(Project $project, array $hqPositions): array
    {
        $descriptors = [];
        $lots = Lot::query()->where('project_id', $project->id)->whereIn('code', array_keys($hqPositions))->get()->keyBy('code');

        foreach ($hqPositions as $code => $position) {
            $lot = $lots->get($code);
            if (! $lot) {
                continue;
            }
            $laravelPosition = $lot->inventoryPosition();
            $hqAvailable = strtoupper($position) === 'BELUM_TERJUAL';
            $laravelAvailable = $laravelPosition === 'AVAILABLE';
            if ($hqAvailable === $laravelAvailable) {
                continue;
            }

            $sourceValue = ['lot_code' => $lot->code, 'hq_position' => strtoupper($position)];
            $bookings = $lot->bookings()->orderBy('id')->get();
            $stableIdentity = implode('|', [
                $project->code,
                ImportConflict::CASE_STOCK_DISAGREEMENT,
                'hq_report_export',
                'lot:'.Str::lower($lot->code),
            ]);
            $descriptors[] = [
                'current_import_issue_id' => null,
                'stable_source_identity' => $stableIdentity,
                'case_type' => ImportConflict::CASE_STOCK_DISAGREEMENT,
                'status' => ImportConflict::STATUS_OPEN,
                'severity' => ImportIssue::SEVERITY_WARNING,
                'source_tab' => 'hq_report_export',
                'source_row' => null,
                'source_key' => $lot->source_key ?: $lot->code,
                'source_fingerprint' => $this->fingerprint(ImportConflict::CASE_STOCK_DISAGREEMENT, $sourceValue),
                'source_value' => $sourceValue,
                'laravel_value' => [
                    'lot_id' => $lot->id,
                    'lot_code' => $lot->code,
                    'inventory_position' => $laravelPosition,
                    'valid_claim_booking_ids' => $bookings->whereIn('status', self::CLAIM_STATUSES)->pluck('id')->values()->all(),
                ],
                'inventory_impact' => [
                    'availability_disagrees' => true,
                    'hq_available' => $hqAvailable,
                    'laravel_available' => $laravelAvailable,
                    'summary' => "HQ reports {$position}; Laravel derives {$laravelPosition}.",
                ],
                'lot_ids' => [$lot->id],
                'booking_links' => $bookings->map(fn (Booking $booking): array => [
                    'booking_id' => $booking->id,
                    'role' => $booking->is_current_holder
                        ? ImportConflictBooking::ROLE_CURRENT_HOLDER
                        : ImportConflictBooking::ROLE_CLAIM,
                ])->values()->all(),
            ];
        }

        return $descriptors;
    }

    private function normalizeFingerprintValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($item) => $this->normalizeFingerprintValue($item), $value);
            }
            ksort($value);

            return array_map(fn ($item) => $this->normalizeFingerprintValue($item), $value);
        }
        if (is_string($value)) {
            return Str::lower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function sourceReference(array $descriptor): string
    {
        return ($descriptor['source_tab'] ?? 'unknown').':'.($descriptor['source_row'] ?? 'n/a');
    }
}
