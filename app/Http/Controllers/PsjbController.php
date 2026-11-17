<?php

namespace App\Http\Controllers;

use App\Exceptions\LotAllocationConflict;
use App\Http\Requests\BookingDecisionRequest;
use App\Http\Requests\StorePsjbRequest;
use App\Models\Booking;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PsjbController extends Controller
{
    public function __construct(private readonly PsjbWorkflow $workflow) {}

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
