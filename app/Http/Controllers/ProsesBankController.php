<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankResultRequest;
use App\Http\Requests\StoreBankSubmissionRequest;
use App\Models\BankSubmission;
use App\Models\Booking;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\Operations\PsjbWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProsesBankController extends Controller
{
    private const PILOT_PROJECT_CODES = ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'];

    public function __construct(private readonly PsjbWorkflow $workflow) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorizeProject($request, $project);

        return view('proses-bank.index', [
            'project' => $project,
            'bookings' => $this->workflow->eligibleBankBookings($project)
                ->with('bankSubmissions.pemberkasan')
                ->get(),
        ]);
    }

    public function create(Request $request, Project $project, Booking $booking): View
    {
        $this->authorizeBooking($request, $project, $booking);
        $booking->load(['lot', 'buyer', 'currentPemberkasan', 'pemberkasan', 'bankSubmissions.pemberkasan']);

        return view('proses-bank.edit', compact('project', 'booking'));
    }

    public function store(StoreBankSubmissionRequest $request, Project $project, Booking $booking): RedirectResponse
    {
        $this->authorizeBooking($request, $project, $booking);

        $pemberkasan = PemberkasanRecord::query()
            ->whereKey($request->integer('pemberkasan_id'))
            ->where('booking_id', $booking->id)
            ->where('project_id', $project->id)
            ->first();
        abort_unless($pemberkasan instanceof PemberkasanRecord, 403);

        $this->workflow->recordBankSubmission($request->user(), $booking, $pemberkasan, $request->validated());

        return redirect()->route('bookings.show', $booking)->with('status', 'Pengajuan Bank berhasil disimpan.');
    }

    public function editResult(Request $request, Project $project, BankSubmission $submission): View
    {
        $this->authorizeSubmission($request, $project, $submission);
        $submission->load(['booking.lot', 'booking.buyer', 'pemberkasan']);

        return view('proses-bank.result', compact('project', 'submission'));
    }

    public function storeResult(StoreBankResultRequest $request, Project $project, BankSubmission $submission): RedirectResponse
    {
        $this->authorizeSubmission($request, $project, $submission);

        $this->workflow->recordBankResult($request->user(), $submission, $request->validated());

        return redirect()
            ->route('bookings.show', $submission->booking_id)
            ->with('status', 'Hasil Bank berhasil disimpan.');
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($request->user()?->canAccessProject($project), 403);
        abort_unless(in_array($project->code, self::PILOT_PROJECT_CODES, true), 403, 'Proses Bank hanya tersedia untuk proyek pilot.');
        abort_unless($project->management_mode === Project::MANAGEMENT_LARAVEL, 403, 'Proses Bank staging belum aktif untuk proyek ini.');
        abort_unless($request->user()->role === User::ROLE_BRANCH_ADMIN, 403);
    }

    private function authorizeBooking(Request $request, Project $project, Booking $booking): void
    {
        $this->authorizeProject($request, $project);
        abort_unless($booking->project_id === $project->id, 403);
        abort_unless(
            $this->workflow->eligibleBankBookings($project)->whereKey($booking->id)->exists(),
            422,
            'Booking belum memenuhi syarat Proses Bank.',
        );
    }

    private function authorizeSubmission(Request $request, Project $project, BankSubmission $submission): void
    {
        $this->authorizeProject($request, $project);
        abort_unless($submission->project_id === $project->id, 403);
        abort_unless($submission->booking?->project_id === $project->id, 403);
        abort_unless(
            $submission->source_owner === BankSubmission::OWNER_LARAVEL,
            422,
            'Submission Bank milik Sheets tidak dapat diubah dari Laravel.',
        );
    }
}
