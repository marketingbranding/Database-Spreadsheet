<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingNameChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'branch_admin';
    }

    public function rules(): array
    {
        return [
            'buyer_id' => ['required', 'integer', 'exists:buyers,id'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
