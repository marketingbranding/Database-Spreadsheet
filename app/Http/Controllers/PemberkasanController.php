<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsStageHistory;
use App\Http\Requests\StorePemberkasanRequest;
use App\Models\Booking;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PemberkasanController extends Controller
{
    use BuildsStageHistory;

    public function __construct(private readonly PsjbWorkflow $workflow) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorizeProject($request, $project);
        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'antrean';

        return view('pemberkasan.index', [
            'project' => $project,
            'tab' => $tab,
            'bookings' => $tab === 'antrean' ? $this->workflow->eligiblePemberkasanBookings($project)->get() : collect(),
            'history' => $tab === 'riwayat' ? $this->stageHistory($project, 'pemberkasan') : null,
        ]);
    }

    public function edit(Request $request, Project $project, Booking $booking): View
    {
        $this->authorizeBooking($request, $project, $booking);

        return view('pemberkasan.edit', compact('project', 'booking'));
    }

    public function update(StorePemberkasanRequest $request, Project $project, Booking $booking): RedirectResponse
    {
        $this->authorizeBooking($request, $project, $booking);
        $this->workflow->recordPemberkasan($request->user(), $booking, $request->validated());

        return redirect()->route('bookings.show', $booking)->with('status', 'Pemberkasan berhasil disimpan.');
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($request->user()->canAccessProject($project), 403);
        abort_unless(in_array($project->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true), 403);
        abort_unless($project->management_mode === Project::MANAGEMENT_LARAVEL, 403, 'Pemberkasan staging belum aktif untuk proyek ini.');
    }

    private function authorizeBooking(Request $request, Project $project, Booking $booking): void
    {
        $this->authorizeProject($request, $project);
        abort_unless($booking->project_id === $project->id, 403);
        abort_unless($request->user()->role === User::ROLE_BRANCH_ADMIN, 403);
        abort_unless($this->workflow->eligiblePemberkasanBookings($project)->whereKey($booking->id)->exists(), 422, 'Booking belum memenuhi syarat Pemberkasan.');
    }
}
