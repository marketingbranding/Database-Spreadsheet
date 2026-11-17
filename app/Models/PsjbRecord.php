<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsjbRecord extends Model
{
    protected $fillable = [
        'booking_id', 'project_id', 'source_key', 'psjb_number', 'psjb_date',
        'sales_name', 'coordinator_name', 'price', 'utj_date', 'utj_amount',
        'dp_all_in', 'installment_count', 'payment_method', 'source_status',
        'notes', 'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'psjb_date' => 'date',
            'utj_date' => 'date',
            'price' => 'decimal:2',
            'utj_amount' => 'decimal:2',
            'dp_all_in' => 'decimal:2',
            'installment_count' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
