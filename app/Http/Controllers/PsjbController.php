<?php

namespace App\Http\Controllers;

use App\Exceptions\LotAllocationConflict;
use App\Http\Controllers\Concerns\BuildsStageHistory;
use App\Http\Requests\BookingDecisionRequest;
use App\Http\Requests\StorePsjbRequest;
use App\Models\Booking;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use App\Services\Operations\StageQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PsjbController extends Controller
{
    use BuildsStageHistory;

    public function __construct(private readonly PsjbWorkflow $workflow, private readonly StageQueue $stageQueue) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorizeStage($request, $project);

        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'antrean';
        $canViewConflicts = (bool) config('marison.import_conflicts_enabled') && $project->isOperationalStaging();
        $queue = $tab === 'antrean' ? $this->stageQueue->psjbQueue($project)->get() : collect();

        return view('psjb.index', [
            'project' => $project,
            'tab' => $tab,
            'bookings' => $queue,
            'history' => $tab === 'riwayat' ? $this->stageHistory($project, 'psjb') : null,
            'canCreatePsjb' => $this->canCreatePsjb($request),
            'canViewConflicts' => $canViewConflicts,
            'blocked' => $queue->mapWithKeys(fn ($booking) => [
                $booking->id => $this->stageQueue->blockReason($request->user(), $booking, $canViewConflicts, $this->workflow),
            ]),
        ]);
    }

    private function canCreatePsjb(Request $request): bool
    {
        return $request->user()->role === User::ROLE_BRANCH_ADMIN
            && config('app.env') === 'staging'
            && filter_var(env('MARISON_OPERATIONAL_WRITES', false), FILTER_VALIDATE_BOOL);
    }

    private function authorizeStage(Request $request, Project $project): void
    {
        abort_unless($request->user()->canAccessProject($project), 403);
        abort_unless($project->isOperationalStaging(), 403, 'Tahapan operasional belum aktif untuk proyek ini.');
    }

    public function create(Request $request, Project $project): View
    {
        abort_unless($request->user()->role === User::ROLE_BRANCH_ADMIN && $request->user()->canAccessProject($project), 403);
        abort_unless(config('app.env') === 'staging' && filter_var(env('MARISON_OPERATIONAL_WRITES', false), FILTER_VALIDATE_BOOL), 403, 'Operational writes are disabled outside staging.');
        abort_unless($project->management_mode === Project::MANAGEMENT_LARAVEL, 422, 'Project masih dikelola melalui Sheets.');

        return view('psjb.create', [
            'project' => $project,
            'lots' => $this->workflow->availableLots($project)->get(),
            'buyers' => $project->bookings()->with('buyer')->get()->pluck('buyer')->unique('id')->sortBy('name'),
        ]);
    }

    public function store(StorePsjbRequest $request, Project $project): RedirectResponse
    {
        try {
            $booking = $this->workflow->create($request->user(), $project, $request->validated());
        } catch (LotAllocationConflict $e) {
            throw ValidationException::withMessages(['lot_id' => $e->getMessage()]);
        }

        return redirect()->route('bookings.show', $booking)->with('status', 'PSJB berhasil dibuat.');
    }

    public function withdraw(BookingDecisionRequest $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->role === 'branch_admin' && $request->user()->canAccessProject($booking->project), 403);
        $this->workflow->withdraw($request->user(), $booking, $request->validated('note'));

        return back()->with('status', 'Booking ditandai Mundur.');
    }

    public function cancel(BookingDecisionRequest $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->role === 'branch_admin' && $request->user()->canAccessProject($booking->project), 403);
        $this->workflow->cancel($request->user(), $booking, $request->validated('note'));

        return back()->with('status', 'Booking ditandai Batal.');
    }
}
