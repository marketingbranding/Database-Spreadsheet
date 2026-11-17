<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportConflict extends Model
{
    public const CASE_COMPETING_CLAIMS = 'COMPETING_CLAIMS';

    public const CASE_UNLINKED_BI = 'UNLINKED_BI';

    public const CASE_STOCK_DISAGREEMENT = 'STOCK_DISAGREEMENT';

    public const CASE_BANK_REJECT = 'BANK_REJECT';

    public const CASE_SIGNED_DOCUMENT = 'SIGNED_DOCUMENT';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_PROPOSED = 'PROPOSED';

    public const STATUS_ESCALATED = 'ESCALATED';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const RESOLUTION_CONFIRM_LARAVEL = 'CONFIRM_LARAVEL';

    public const RESOLUTION_CORRECT_LARAVEL = 'CORRECT_LARAVEL';

    public const RESOLUTION_LINK_BI = 'LINK_BI';

    public const RESOLUTION_BOOKING_DECISION = 'BOOKING_DECISION';

    public const RESOLUTION_SIGNED_ESCALATION = 'SIGNED_ESCALATION';

    protected $fillable = [
        'project_id', 'current_import_issue_id', 'supersedes_conflict_id',
        'stable_source_identity', 'case_type', 'status', 'severity', 'source_tab',
        'source_row', 'source_key', 'source_fingerprint', 'reviewed_source_fingerprint',
        'source_value', 'laravel_value', 'inventory_impact', 'resolved_at',
        'resolved_by', 'resolution_type',
    ];

    protected function casts(): array
    {
        return [
            'source_value' => 'array',
            'laravel_value' => 'array',
            'inventory_impact' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function currentIssue(): BelongsTo
    {
        return $this->belongsTo(ImportIssue::class, 'current_import_issue_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_conflict_id');
    }

    public function lots(): BelongsToMany
    {
        return $this->belongsToMany(Lot::class, 'import_conflict_lot')->withTimestamps();
    }

    public function bookingLinks(): HasMany
    {
        return $this->hasMany(ImportConflictBooking::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(ImportConflictProposal::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ImportConflictEvent::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function blocksAllocation(): bool
    {
        return in_array($this->case_type, [
            self::CASE_COMPETING_CLAIMS,
            self::CASE_STOCK_DISAGREEMENT,
        ], true) && in_array($this->status, [
            self::STATUS_OPEN,
            self::STATUS_PROPOSED,
            self::STATUS_ESCALATED,
        ], true);
    }

    public static function openAllocationDisputesForLot(int $projectId, int $lotId): Builder
    {
        return self::query()
            ->where('project_id', $projectId)
            ->whereIn('case_type', [
                self::CASE_COMPETING_CLAIMS,
                self::CASE_STOCK_DISAGREEMENT,
            ])
            ->whereIn('status', [
                self::STATUS_OPEN,
                self::STATUS_PROPOSED,
                self::STATUS_ESCALATED,
            ])
            ->whereHas('lots', fn (Builder $query) => $query->whereKey($lotId));
    }
}
