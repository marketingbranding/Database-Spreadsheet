<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public const MANAGEMENT_SHEETS = 'sheets';

    public const MANAGEMENT_LARAVEL = 'laravel';

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    protected $attributes = [
        'management_mode' => self::MANAGEMENT_SHEETS,
    ];

    protected $fillable = [
        'branch_id', 'code', 'name', 'source_name', 'spreadsheet_id',
        'spreadsheet_name', 'timezone', 'is_active', 'notes', 'management_mode',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'management_mode' => 'string',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function bookingAudits(): HasMany
    {
        return $this->hasMany(BookingAudit::class);
    }

    public function importIssues(): HasMany
    {
        return $this->hasMany(ImportIssue::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isHq()) {
            return $query;
        }

        return $query->whereIn('id', $user->projects()->select('projects.id'));
    }
}
