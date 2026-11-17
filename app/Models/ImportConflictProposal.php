<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportConflictProposal extends Model
{
    public const ACTION_CONFIRM_LARAVEL = 'CONFIRM_LARAVEL';

    public const ACTION_CORRECT_LARAVEL = 'CORRECT_LARAVEL';

    public const ACTION_LINK_BI = 'LINK_BI';

    public const ACTION_SELECT_CURRENT_CLAIM = 'SELECT_CURRENT_CLAIM';

    public const ACTION_ROUTE_BOOKING_DECISION = 'ROUTE_BOOKING_DECISION';

    public const ACTION_ESCALATE_SIGNED_DOCUMENT = 'ESCALATE_SIGNED_DOCUMENT';

    public const ACTIONS = [
        self::ACTION_CONFIRM_LARAVEL,
        self::ACTION_CORRECT_LARAVEL,
        self::ACTION_LINK_BI,
        self::ACTION_SELECT_CURRENT_CLAIM,
        self::ACTION_ROUTE_BOOKING_DECISION,
        self::ACTION_ESCALATE_SIGNED_DOCUMENT,
    ];

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'import_conflict_id', 'project_id', 'proposed_by', 'action', 'reason',
        'evidence', 'source_reference', 'proposed_values', 'idempotency_key',
        'request_hash', 'pending_guard', 'status', 'approved_by',
        'approval_reason', 'before', 'after', 'approved_at', 'rejected_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_values' => 'array',
            'before' => 'array',
            'after' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(ImportConflict::class, 'import_conflict_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ImportConflictEvent::class);
    }
}
