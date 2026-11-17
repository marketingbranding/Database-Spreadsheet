<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankSubmission extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    public const RESULTS = ['APPROVE', 'REJECT', 'REVISI'];

    protected $fillable = [
        'booking_id', 'project_id', 'pemberkasan_id', 'source_key', 'file_number', 'decision_number',
        'submission_sequence', 'bank', 'bank_unit', 'submitted_date', 'decision_date',
        'result', 'approved_amount', 'tenor', 'withdrawn_flag', 'cancelled_flag',
        'moved_lot_flag', 'name_change_flag', 'notes', 'source_owner', 'idempotency_key',
        'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'submitted_date' => 'date',
            'decision_date' => 'date',
            'approved_amount' => 'decimal:2',
            'submission_sequence' => 'integer',
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

    public function pemberkasan(): BelongsTo
    {
        return $this->belongsTo(PemberkasanRecord::class);
    }
}
