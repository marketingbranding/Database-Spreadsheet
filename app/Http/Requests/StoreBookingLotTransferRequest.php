<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingLotTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'branch_admin';
    }

    public function rules(): array
    {
        return [
            'lot_id' => ['required', 'integer', 'exists:lots,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'confirm_price_change' => ['nullable', 'boolean'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
