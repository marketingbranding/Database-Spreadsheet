<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingLotTransferRequest;
use App\Http\Requests\StoreBookingNameChangeRequest;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BookingChangeController extends Controller
{
    public function __construct(private readonly PsjbWorkflow $workflow) {}

    public function nameCreate(Request $request, Project $project, Booking $booking): View
    {
        $this->authorizePage($request, $project, $booking);

        return view('booking-changes.create', [
            'project' => $project,
            'booking' => $booking->load(['lot', 'buyer', 'psjb']),
            'mode' => 'name',
            'buyers' => Buyer::query()
                ->where('source_key', 'like', $project->code.':%')
                ->where('id', '<>', $booking->buyer_id)
                ->orderBy('name')
                ->get(),
            'lots' => collect(),
        ]);
    }

    public function lotCreate(Request $request, Project $project, Booking $booking): View
    {
        $this->authorizePage($request, $project, $booking);

        return view('booking-changes.create', [
            'project' => $project,
            'booking' => $booking->load(['lot', 'buyer', 'psjb']),
            'mode' => 'lot',
            'buyers' => collect(),
            'lots' => $this->workflow->availableLots($project)->where('lots.id', '<>', $booking->lot_id)->get(),
        ]);
    }

    public function nameStore(StoreBookingNameChangeRequest $request, Project $project, Booking $booking): RedirectResponse
    {
        $this->authorizePage($request, $project, $booking);

        try {
            $successor = $this->workflow->changeName($request->user(), $booking, $request->validated());
        } catch (HttpException $exception) {
            return back()->withInput()->withErrors(['change' => $exception->getMessage()]);
        }

        return redirect()->route('bookings.show', $successor)->with('status', 'Ganti nama berhasil dicatat.');
    }

    public function lotStore(StoreBookingLotTransferRequest $request, Project $project, Booking $booking): RedirectResponse
    {
        $this->authorizePage($request, $project, $booking);

        try {
            $successor = $this->workflow->moveLot($request->user(), $booking, $request->validated());
        } catch (HttpException $exception) {
            return back()->withInput()->withErrors(['change' => $exception->getMessage()]);
        }

        return redirect()->route('bookings.show', $successor)->with('status', 'Pindah kavling berhasil dicatat.');
    }

    private function authorizePage(Request $request, Project $project, Booking $booking): void
    {
        abort_unless(
            $request->user()?->role === User::ROLE_BRANCH_ADMIN
            && $request->user()->canAccessProject($project)
            && $booking->project_id === $project->id,
            403,
        );
        abort_unless(config('app.env') === 'staging' && filter_var(env('MARISON_OPERATIONAL_WRITES', false), FILTER_VALIDATE_BOOL), 403, 'Operational writes are disabled outside staging.');
        abort_unless($project->management_mode === Project::MANAGEMENT_LARAVEL, 422, 'Project masih dikelola melalui Sheets.');
    }
}
