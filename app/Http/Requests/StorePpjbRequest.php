<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StorePpjbRequest extends FormRequest
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
            'bank_submission_id' => ['nullable', 'integer'],
            'pemberkasan_id' => ['nullable', 'integer'],
            'ppjb_number' => ['required', 'string', 'max:96'],
            'signed_date' => ['required', 'date'],
            'dp_status' => ['nullable', 'string', 'max:48'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
