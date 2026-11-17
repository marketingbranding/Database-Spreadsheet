<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAkadRequest;
use App\Models\Booking;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AkadController extends Controller
{
    private const PILOT_PROJECT_CODES = ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'];

    public function __construct(private readonly PsjbWorkflow $workflow) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorizeProject($request, $project);

        return view('akad.index', [
            'project' => $project,
            'bookings' => $this->workflow->eligibleAkadBookings($project)->get(),
        ]);
    }

    public function create(Request $request, Project $project, Booking $booking): View
    {
        $this->authorizeBooking($request, $project, $booking);
        $booking->load(['lot', 'buyer', 'ppjb']);

        return view('akad.edit', compact('project', 'booking'));
    }

    public function store(StoreAkadRequest $request, Project $project, Booking $booking): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        abort_unless($booking->project_id === $project->id, 403);

        $this->workflow->recordAkad($request->user(), $booking, $request->validated());

        return redirect()->route('bookings.show', $booking)->with('status', 'Akad berhasil disimpan.');
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($request->user()?->canAccessProject($project), 403);
        abort_unless(in_array($project->code, self::PILOT_PROJECT_CODES, true), 403, 'Akad hanya tersedia untuk proyek pilot.');
        abort_unless($project->management_mode === Project::MANAGEMENT_LARAVEL, 403, 'Akad staging belum aktif untuk proyek ini.');
        abort_unless($request->user()->role === User::ROLE_BRANCH_ADMIN, 403);
    }

    private function authorizeBooking(Request $request, Project $project, Booking $booking): void
    {
        $this->authorizeProject($request, $project);
        abort_unless($booking->project_id === $project->id, 403);
        abort_unless(
            $this->workflow->eligibleAkadBookings($project)->whereKey($booking->id)->exists(),
            422,
            'Booking belum memenuhi syarat Akad.',
        );
    }
}
