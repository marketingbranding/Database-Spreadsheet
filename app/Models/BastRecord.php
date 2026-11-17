<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BastRecord extends Model
{
    protected $fillable = [
        'booking_id', 'project_id', 'source_key', 'bast_number', 'bast_date',
        'notes', 'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return ['bast_date' => 'date'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
