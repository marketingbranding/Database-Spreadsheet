<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiCheck extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    protected $attributes = [
        'source_owner' => self::OWNER_SHEETS,
    ];

    protected $fillable = [
        'booking_id', 'project_id', 'source_key', 'bi_number', 'check_date', 'result',
        'decision', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag',
        'name_change_flag', 'notes', 'source_owner', 'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'check_date' => 'date',
            'withdrawn_flag' => 'boolean',
            'cancelled_flag' => 'boolean',
            'moved_lot_flag' => 'boolean',
            'name_change_flag' => 'boolean',
            'source_owner' => 'string',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
