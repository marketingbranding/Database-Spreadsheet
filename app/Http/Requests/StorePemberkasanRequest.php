<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StorePemberkasanRequest extends FormRequest
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
            'file_number' => ['required', 'string', 'max:96'],
            'received_date' => ['nullable', 'date'],
            'bank' => ['nullable', 'string', 'max:160'],
            'bank_unit' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'max:48'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
