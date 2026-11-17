<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewImportConflictProposalRequest;
use App\Http\Requests\StoreImportConflictProposalRequest;
use App\Models\ImportConflict;
use App\Models\ImportConflictProposal;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\ImportConflictWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImportConflictController extends Controller
{
    private const PILOT_PROJECT_CODES = ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'];

    public function __construct(private readonly ImportConflictWorkflow $workflow) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorize($request, $project);

        $conflicts = ImportConflict::query()
            ->where('project_id', $project->id)
            ->whereNot('status', ImportConflict::STATUS_SUPERSEDED)
            ->with(['currentIssue', 'lots', 'bookingLinks.booking'])
            ->orderByRaw("CASE severity WHEN 'error' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderBy('id')
            ->get();

        return view('import-conflicts.index', [
            'project' => $project,
            'conflicts' => $conflicts,
        ]);
    }

    public function show(Request $request, Project $project, ImportConflict $conflict): View
    {
        $this->authorize($request, $project, $conflict);

        $conflict->load([
            'project',
            'currentIssue',
            'lots',
            'bookingLinks.booking',
            'proposals.proposer',
            'proposals.approver',
            'events.actor',
        ]);

        return view('import-conflicts.show', [
            'project' => $project,
            'conflict' => $conflict,
        ]);
    }

    public function storeProposal(
        StoreImportConflictProposalRequest $request,
        Project $project,
        ImportConflict $conflict,
    ): RedirectResponse {
        $this->authorize($request, $project, $conflict, role: User::ROLE_BRANCH_ADMIN);

        $this->workflow->propose($request->user(), $conflict, $request->validated());

        return back()->with('status', 'Usulan resolusi berhasil dikirim ke HQ.');
    }

    public function approve(
        ReviewImportConflictProposalRequest $request,
        Project $project,
        ImportConflict $conflict,
        ImportConflictProposal $proposal,
    ): RedirectResponse {
        $this->authorize($request, $project, $conflict, $proposal, User::ROLE_HQ);

        $this->workflow->approve($request->user(), $proposal, $request->validated());

        return back()->with('status', 'Resolusi disetujui dan diterapkan.');
    }

    public function reject(
        ReviewImportConflictProposalRequest $request,
        Project $project,
        ImportConflict $conflict,
        ImportConflictProposal $proposal,
    ): RedirectResponse {
        $this->authorize($request, $project, $conflict, $proposal, User::ROLE_HQ);

        $this->workflow->reject($request->user(), $proposal, (string) $request->validated('approval_reason'));

        return back()->with('status', 'Usulan resolusi ditolak.');
    }

    private function authorize(
        Request $request,
        Project $project,
        ?ImportConflict $conflict = null,
        ?ImportConflictProposal $proposal = null,
        ?string $role = null,
    ): void {
        $user = $request->user();
        abort_unless($user?->is_active === true, 403, 'Akun tidak aktif.');
        abort_unless(config('app.env') === 'staging', 403, 'Modul hanya tersedia di staging.');
        abort_unless(filter_var(env('MARISON_OPERATIONAL_WRITES', false), FILTER_VALIDATE_BOOL), 403, 'Operational writes are disabled outside staging.');
        abort_unless((bool) config('marison.import_conflicts_enabled', false), 403, 'Modul Konflik Impor belum diaktifkan.');
        abort_unless(in_array($project->code, self::PILOT_PROJECT_CODES, true), 403, 'Proyek tidak termasuk pilot.');
        abort_unless($user->isHq() || $user->canAccessProject($project), 403, 'Anda tidak memiliki akses ke proyek ini.');

        if ($role !== null) {
            abort_unless($user->role === $role, 403, 'Peran tidak memiliki akses ke tindakan ini.');
        }
        if ($conflict) {
            abort_unless($conflict->project_id === $project->id, 404, 'Konflik tidak ditemukan pada proyek ini.');
        }
        if ($proposal && $conflict) {
            abort_unless(
                $proposal->import_conflict_id === $conflict->id && $proposal->project_id === $project->id,
                404,
                'Usulan tidak ditemukan pada konflik ini.'
            );
        }
    }
}
