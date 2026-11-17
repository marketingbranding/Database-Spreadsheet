<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AkadRecord extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    protected $fillable = [
        'booking_id', 'ppjb_id', 'project_id', 'source_key', 'akad_number', 'akad_date',
        'quality', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag',
        'name_change_flag', 'notes', 'source_owner', 'idempotency_key',
        'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'akad_date' => 'date',
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

    public function ppjb(): BelongsTo
    {
        return $this->belongsTo(PpjbRecord::class);
    }
}
