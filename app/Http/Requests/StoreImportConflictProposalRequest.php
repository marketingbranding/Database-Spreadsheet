<?php

namespace App\Http\Requests;

use App\Models\ImportConflictProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportConflictProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(ImportConflictProposal::ACTIONS)],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['required', 'string', 'max:4000'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'proposed_values' => ['nullable', 'array'],
            'proposed_values.booking_id' => [
                'required_if:action,'.ImportConflictProposal::ACTION_LINK_BI,
                'integer',
                'exists:bookings,id',
            ],
            'proposed_values.current_booking_id' => [
                'required_if:action,'.ImportConflictProposal::ACTION_CORRECT_LARAVEL.','.ImportConflictProposal::ACTION_SELECT_CURRENT_CLAIM,
                'integer',
                'exists:bookings,id',
            ],
            'proposed_values.dispositions' => [
                'required_if:action,'.ImportConflictProposal::ACTION_CORRECT_LARAVEL,
                'array',
                'min:1',
            ],
            'proposed_values.dispositions.*' => [
                'string',
                Rule::in(['MUNDUR', 'BATAL']),
            ],
            'proposed_values.decision' => [
                'required_if:action,'.ImportConflictProposal::ACTION_ROUTE_BOOKING_DECISION,
                'string',
                Rule::in(['MUNDUR', 'BATAL']),
            ],
        ];
    }
}
