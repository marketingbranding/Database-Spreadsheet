<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsStageHistory;
use App\Http\Requests\StoreBiCheckRequest;
use App\Models\Booking;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BiCheckController extends Controller
{
    use BuildsStageHistory;

    public function __construct(private readonly PsjbWorkflow $workflow) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorizeProject($request, $project);
        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'antrean';

        return view('bi.index', [
            'project' => $project,
            'tab' => $tab,
            'bookings' => $tab === 'antrean' ? $this->workflow->eligibleBiBookings($project)->get() : collect(),
            'history' => $tab === 'riwayat' ? $this->stageHistory($project, 'bi') : null,
        ]);
    }

    public function edit(Request $request, Project $project, Booking $booking): View
    {
        $this->authorizeProjectBooking($request, $project, $booking);

        return view('bi.edit', compact('project', 'booking'));
    }

    public function update(StoreBiCheckRequest $request, Project $project, Booking $booking): RedirectResponse
    {
        $this->authorizeProjectBooking($request, $project, $booking);
        $this->workflow->recordBiCheck($request->user(), $booking, $request->validated());

        return redirect()->route('bookings.show', $booking)->with('status', 'BI Checking berhasil disimpan.');
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($request->user()->canAccessProject($project), 403);
        abort_unless($project->management_mode === Project::MANAGEMENT_LARAVEL, 403, 'BI Checking staging belum aktif untuk proyek ini.');
    }

    private function authorizeProjectBooking(Request $request, Project $project, Booking $booking): void
    {
        $this->authorizeProject($request, $project);
        abort_unless($booking->project_id === $project->id, 403);
        abort_unless($booking->payment_method === 'KPR', 422, 'Booking bukan KPR.');
        abort_unless($request->user()->role === User::ROLE_BRANCH_ADMIN, 403);
    }
}
