<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBiCheckRequest extends FormRequest
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
            'bi_number' => ['nullable', 'string', 'max:96'],
            'check_date' => ['required', 'date'],
            'result' => ['nullable', 'string', 'max:32'],
            'decision' => ['required', Rule::in(['LANJUT', 'TIDAK LANJUT'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
