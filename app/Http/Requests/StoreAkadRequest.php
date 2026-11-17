<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreAkadRequest extends FormRequest
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
            'ppjb_id' => ['required', 'integer'],
            'akad_number' => ['required', 'string', 'max:96'],
            'akad_date' => ['required', 'date'],
            'quality' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
