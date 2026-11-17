<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePsjbRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->role === User::ROLE_BRANCH_ADMIN
            && $this->user()->canAccessProject($project);
    }

    public function rules(): array
    {
        return [
            'lot_id' => ['required', 'integer', 'exists:lots,id'],
            'buyer_id' => ['nullable', 'integer', 'exists:buyers,id', 'required_without:buyer_name'],
            'buyer_name' => ['nullable', 'string', 'max:160', 'required_without:buyer_id'],
            'nik' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'sales_name' => ['required', 'string', 'max:160'],
            'coordinator_name' => ['nullable', 'string', 'max:160'],
            'payment_method' => ['required', Rule::in(['KPR', 'CASH', 'INHOUSE', 'CASH_KERAS'])],
            'psjb_number' => ['nullable', 'string', 'max:96'],
            'psjb_date' => ['required', 'date'],
            'utj_date' => ['nullable', 'date'],
            'utj_amount' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'dp_all_in' => ['nullable', 'numeric', 'min:0'],
            'installment_count' => ['nullable', 'integer', 'min:0', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
