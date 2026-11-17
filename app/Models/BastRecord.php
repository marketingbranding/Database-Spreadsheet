<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BastRecord extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    protected $fillable = [
        'booking_id', 'akad_id', 'project_id', 'source_key', 'bast_number', 'bast_date',
        'notes', 'source_owner', 'idempotency_key', 'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'bast_date' => 'date',
            'source_owner' => 'string',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function akad(): BelongsTo
    {
        return $this->belongsTo(AkadRecord::class);
    }
}
