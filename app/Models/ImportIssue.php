<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportIssue extends Model
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    protected $fillable = [
        'import_batch_id', 'project_id', 'severity', 'code', 'message',
        'source_tab', 'source_row', 'source_key', 'context', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(ImportConflict::class, 'current_import_issue_id');
    }
}
