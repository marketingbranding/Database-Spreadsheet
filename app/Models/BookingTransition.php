<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingTransition extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    public const ACTION_NAME_CHANGE = 'ganti_nama';

    public const ACTION_LOT_TRANSFER = 'pindah_kavling';

    protected $fillable = [
        'project_id', 'source_booking_id', 'successor_booking_id', 'user_id',
        'action', 'effective_date', 'reason', 'idempotency_key', 'source_owner',
        'source_key', 'source_hash', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'payload' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'source_booking_id');
    }

    public function successorBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'successor_booking_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
