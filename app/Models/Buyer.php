<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Buyer extends Model
{
    protected $fillable = [
        'source_key', 'source_customer_id', 'name', 'nik_encrypted', 'nik_hash',
        'nik_last4', 'phone_encrypted', 'source_tab', 'source_row',
    ];

    protected $hidden = ['nik_encrypted', 'phone_encrypted'];

    protected function casts(): array
    {
        return [
            'nik_encrypted' => 'encrypted',
            'phone_encrypted' => 'encrypted',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function maskedNik(): ?string
    {
        return $this->nik_last4 ? '••••••••' . $this->nik_last4 : null;
    }
}
