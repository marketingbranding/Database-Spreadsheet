<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsStageHistory;
use App\Http\Requests\StorePpjbRequest;
use App\Models\Booking;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TtdPpjbController extends Controller
{
    use BuildsStageHistory;

    private const PILOT_PROJECT_CODES = ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'];

    public function __construct(private readonly PsjbWorkflow $workflow) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorizeProject($request, $project);
        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'antrean';

        return view('ttd-ppjb.index', [
            'project' => $project,
            'tab' => $tab,
            'bookings' => $tab === 'antrean' ? $this->workflow->eligibleTtdPpjbBookings($project)->get() : collect(),
            'history' => $tab === 'riwayat' ? $this->stageHistory($project, 'ppjb') : null,
        ]);
    }

    public function create(Request $request, Project $project, Booking $booking): View
    {
        $this->authorizeBooking($request, $project, $booking);
        $booking->load(['lot', 'buyer', 'currentPemberkasan', 'bankSubmissions' => fn ($q) => $q->where('result', 'APPROVE')]);

        return view('ttd-ppjb.edit', compact('project', 'booking'));
    }

    public function store(StorePpjbRequest $request, Project $project, Booking $booking): RedirectResponse
    {
        // Only project scoping/role is checked here; the workflow enforces business
        // eligibility, source-record ownership, and idempotent replay so a retry on an
        // already-signed booking still returns the existing record instead of a stale 422.
        $this->authorizeProject($request, $project);
        abort_unless($booking->project_id === $project->id, 403);

        $this->workflow->recordPpjb($request->user(), $booking, $request->validated());

        return redirect()->route('bookings.show', $booking)->with('status', 'PPJB berhasil ditandatangani.');
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($request->user()?->canAccessProject($project), 403);
        abort_unless(in_array($project->code, self::PILOT_PROJECT_CODES, true), 403, 'TTD PPJB hanya tersedia untuk proyek pilot.');
        abort_unless($project->management_mode === Project::MANAGEMENT_LARAVEL, 403, 'TTD PPJB staging belum aktif untuk proyek ini.');
        abort_unless($request->user()->role === User::ROLE_BRANCH_ADMIN, 403);
    }

    private function authorizeBooking(Request $request, Project $project, Booking $booking): void
    {
        $this->authorizeProject($request, $project);
        abort_unless($booking->project_id === $project->id, 403);
        abort_unless(
            $this->workflow->eligibleTtdPpjbBookings($project)->whereKey($booking->id)->exists(),
            422,
            'Booking belum memenuhi syarat TTD PPJB.',
        );
    }
}
