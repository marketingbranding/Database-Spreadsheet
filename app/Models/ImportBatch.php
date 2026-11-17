<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    protected $fillable = [
        'project_id', 'mode', 'source', 'status', 'spreadsheet_id', 'checksum',
        'stats', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues()
    {
        return $this->hasMany(ImportIssue::class);
    }
}
