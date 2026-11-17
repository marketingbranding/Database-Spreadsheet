<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PpjbRecord extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    protected $fillable = [
        'booking_id', 'bank_submission_id', 'pemberkasan_id', 'project_id', 'source_key', 'ppjb_number', 'signed_date',
        'dp_status', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag',
        'name_change_flag', 'notes', 'source_owner', 'idempotency_key',
        'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'signed_date' => 'date',
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

    public function bankSubmission(): BelongsTo
    {
        return $this->belongsTo(BankSubmission::class);
    }

    public function pemberkasan(): BelongsTo
    {
        return $this->belongsTo(PemberkasanRecord::class);
    }

    public function akad(): HasOne
    {
        return $this->hasOne(AkadRecord::class);
    }
}
