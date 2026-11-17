<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportConflictBooking extends Model
{
    public const ROLE_CLAIM = 'CLAIM';

    public const ROLE_CURRENT_HOLDER = 'CURRENT_HOLDER';

    public const ROLE_LINK_TARGET = 'LINK_TARGET';

    public const ROLE_DOCUMENT_OWNER = 'DOCUMENT_OWNER';

    protected $fillable = ['import_conflict_id', 'booking_id', 'role'];

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(ImportConflict::class, 'import_conflict_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
