<?php

namespace App\Http\Requests;

use App\Models\BankSubmission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankResultRequest extends FormRequest
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
            'result' => ['required', Rule::in(BankSubmission::RESULTS)],
            'decision_date' => ['nullable', 'date'],
            'approved_amount' => ['nullable', 'numeric', 'min:0'],
            'tenor' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'booking_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'pemberkasan_id' => ['nullable', 'integer'],
        ];
    }
}
