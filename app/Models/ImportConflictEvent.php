<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportConflictEvent extends Model
{
    public const TYPE_PROJECTED = 'PROJECTED';

    public const TYPE_PROPOSAL_SUBMITTED = 'PROPOSAL_SUBMITTED';

    public const TYPE_PROPOSAL_APPROVED = 'PROPOSAL_APPROVED';

    public const TYPE_PROPOSAL_REJECTED = 'PROPOSAL_REJECTED';

    public const TYPE_RESOLUTION_APPLIED = 'RESOLUTION_APPLIED';

    public const TYPE_LARAVEL_CONFIRMED = 'LARAVEL_CONFIRMED';

    public const TYPE_SOURCE_CHANGED = 'SOURCE_CHANGED';

    public const TYPE_BOOKING_DECISION_RESOLVED = 'BOOKING_DECISION_RESOLVED';

    public const TYPE_SIGNED_ESCALATED = 'SIGNED_ESCALATED';

    protected $fillable = [
        'import_conflict_id', 'import_conflict_proposal_id', 'actor_id',
        'event_type', 'reason', 'source_reference', 'source_fingerprint',
        'before', 'after',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(ImportConflict::class, 'import_conflict_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ImportConflictProposal::class, 'import_conflict_proposal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
