<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BankSubmission;
use App\Models\Booking;
use App\Models\BookingTransition;
use App\Models\PemberkasanRecord;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

trait BuildsStageHistory
{
    /**
     * Recorded stage events for one stage, paginated by booking so a single
     * booking's events stay together on one page.
     *
     * @return LengthAwarePaginator<array{booking: Booking, events: Collection, cycle: array}>
     */
    public function stageHistory(Project $project, string $stage): LengthAwarePaginator
    {
        $bookings = $this->historyQuery($project, $stage)
            ->with($this->historyRelations())
            ->orderBy('booking_date')
            ->orderBy('id')
            ->paginate(20);

        $bookings->setCollection($bookings->getCollection()->map(
            fn (Booking $booking) => $this->historyRow($stage, $booking),
        ));

        return $bookings;
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(string $stage, Booking $booking): array
    {
        $events = match ($stage) {
            'psjb' => $this->psjbEvents($booking),
            'bi' => $this->biCheckEvents($booking),
            'pemberkasan' => $this->pemberkasanEvents($booking),
            'bank' => $this->bankEvents($booking),
            'ppjb' => $this->ppjbEvents($booking),
            'akad' => $this->akadEvents($booking),
            'bast' => $this->bastEvents($booking),
            default => collect(),
        };

        return [
            'booking' => $booking,
            'events' => $events,
            'cycle' => [
                'predecessor_id' => $booking->predecessor_booking_id,
                'successor_ids' => $booking->successors->pluck('id')->values()->all(),
            ],
        ];
    }

    private function historyQuery(Project $project, string $stage): Builder
    {
        $query = Booking::query()->where('project_id', $project->id);

        return match ($stage) {
            'bi' => $query->whereHas('biCheck'),
            'pemberkasan' => $query->whereHas('pemberkasan'),
            'bank' => $query->whereHas('bankSubmissions'),
            'ppjb' => $query->whereHas('ppjb'),
            'akad' => $query->whereHas('akad'),
            'bast' => $query->whereHas('bast'),
            default => $query,
        };
    }

    /**
     * @return array<int, string>
     */
    private function historyRelations(): array
    {
        return ['lot', 'buyer', 'psjb', 'biCheck', 'pemberkasan', 'bankSubmissions', 'ppjb', 'akad', 'bast', 'predecessor', 'successors', 'outgoingTransitions', 'transition'];
    }

    /**
     * @return Collection<int, array{date: ?Carbon, label: string, detail: string}>
     */
    private function psjbEvents(Booking $booking): Collection
    {
        $events = collect();

        $events->push([
            'date' => $booking->psjb?->psjb_date ?? $booking->booking_date,
            'label' => 'PSJB dibuat',
            'detail' => $booking->psjb?->psjb_number ?? '-',
        ]);

        foreach ($booking->outgoingTransitions->concat([$booking->transition])->filter() as $transition) {
            $events->push([
                'date' => $transition->effective_date,
                'label' => $transition->action === BookingTransition::ACTION_NAME_CHANGE ? 'Ganti nama' : 'Pindah kavling',
                'detail' => (string) $transition->reason,
            ]);
        }

        return $events;
    }

    /**
     * @return Collection<int, array{date: ?Carbon, label: string, detail: string}>
     */
    private function biCheckEvents(Booking $booking): Collection
    {
        $events = collect();

        if ($booking->biCheck) {
            $events->push([
                'date' => $booking->biCheck->check_date,
                'label' => 'BI Checking',
                'detail' => $booking->biCheck->decision ?? $booking->biCheck->result ?? '-',
            ]);
        }

        return $events;
    }

    /**
     * @return Collection<int, array{date: ?Carbon, label: string, detail: string}>
     */
    private function pemberkasanEvents(Booking $booking): Collection
    {
        return $booking->pemberkasan->map(fn (PemberkasanRecord $record) => [
            'date' => $record->received_date,
            'label' => 'Pemberkasan rev. '.$record->revision,
            'detail' => (string) $record->status,
        ]);
    }

    /**
     * @return Collection<int, array{date: ?Carbon, label: string, detail: string}>
     */
    private function bankEvents(Booking $booking): Collection
    {
        return $booking->bankSubmissions->map(fn (BankSubmission $submission) => [
            'date' => $submission->submitted_date,
            'label' => 'Pengajuan bank #'.$submission->submission_sequence,
            'detail' => $submission->result ?? 'Menunggu',
        ]);
    }

    /**
     * @return Collection<int, array{date: ?Carbon, label: string, detail: string}>
     */
    private function ppjbEvents(Booking $booking): Collection
    {
        $events = collect();

        if ($booking->ppjb) {
            $events->push([
                'date' => $booking->ppjb->signed_date,
                'label' => 'PPJB ditandatangani',
                'detail' => $booking->ppjb->ppjb_number ?? '-',
            ]);
        }

        return $events;
    }

    /**
     * @return Collection<int, array{date: ?Carbon, label: string, detail: string}>
     */
    private function akadEvents(Booking $booking): Collection
    {
        $events = collect();

        if ($booking->akad) {
            $events->push([
                'date' => $booking->akad->akad_date,
                'label' => 'Akad',
                'detail' => $booking->akad->akad_number ?? '-',
            ]);
        }

        return $events;
    }

    /**
     * @return Collection<int, array{date: ?Carbon, label: string, detail: string}>
     */
    private function bastEvents(Booking $booking): Collection
    {
        $events = collect();

        if ($booking->bast) {
            $events->push([
                'date' => $booking->bast->bast_date,
                'label' => 'BAST',
                'detail' => $booking->bast->bast_number ?? '-',
            ]);
        }

        return $events;
    }
}
