<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PemberkasanRecord extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    protected $attributes = [
        'source_owner' => self::OWNER_SHEETS,
        'revision' => 1,
        'is_current' => true,
    ];

    protected $fillable = [
        'booking_id', 'project_id', 'source_key', 'file_number', 'received_date',
        'bank', 'bank_unit', 'status', 'withdrawn_flag', 'cancelled_flag',
        'moved_lot_flag', 'name_change_flag', 'notes', 'source_owner', 'revision', 'is_current',
        'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'withdrawn_flag' => 'boolean',
            'cancelled_flag' => 'boolean',
            'moved_lot_flag' => 'boolean',
            'name_change_flag' => 'boolean',
            'source_owner' => 'string',
            'revision' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bankSubmissions(): HasMany
    {
        return $this->hasMany(BankSubmission::class, 'pemberkasan_id')->orderBy('submitted_date')->orderBy('id');
    }
}
