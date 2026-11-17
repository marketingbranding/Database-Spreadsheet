<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;

trait ResolvesProjectAccess
{
    protected function authorizeProjectAccess(Project $project): void
    {
        abort_unless(request()->user()?->canAccessProject($project), 403, 'You do not have access to this project.');
    }
}
