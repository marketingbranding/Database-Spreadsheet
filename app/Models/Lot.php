<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lot extends Model
{
    public const OPENING_HISTORICAL_AKAD = 'AKAD_HISTORIS';

    protected $fillable = [
        'project_id', 'code', 'source_id_kavling', 'building_area', 'land_area',
        'klt_area', 'build_progress', 'ready_date', 'order_status',
        'historical_akad_before_2026', 'historical_marker', 'opening_status',
        'source_key', 'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'building_area' => 'decimal:2',
            'land_area' => 'decimal:2',
            'klt_area' => 'decimal:2',
            'build_progress' => 'decimal:2',
            'ready_date' => 'date',
            'historical_akad_before_2026' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function currentBooking(): HasOne
    {
        return $this->hasOne(Booking::class)->where('is_current_holder', true);
    }

    public function isAvailable(): bool
    {
        return ! $this->historical_akad_before_2026 && ! $this->currentBooking()->exists();
    }

    public function inventoryPosition(): string
    {
        if ($this->currentBooking()->exists()) {
            return $this->currentBooking->status === Booking::STATUS_COMPLETED ? 'SOLD' : 'BOOKED';
        }

        return $this->historical_akad_before_2026 ? 'HISTORICAL_AKAD' : 'AVAILABLE';
    }
}
