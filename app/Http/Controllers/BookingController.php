<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPortfolioFilters;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ResolvesPortfolioFilters;

    public function index(Request $request): View
    {
        $filters = $this->portfolioFilters($request);
        $status = $request->string('status')->toString();
        $search = $request->string('q')->toString();

        $bookings = Booking::query()
            ->whereIn('project_id', $filters['projects']->pluck('id'))
            ->with(['lot', 'buyer', 'project.branch'])
            ->orderByDesc('booking_date')
            ->orderByDesc('id');

        if ($status !== '' && in_array($status, array_unique(array_merge(Booking::OUTCOME_STATUSES, [
            Booking::STATUS_ACTIVE,
            Booking::STATUS_COMPLETED,
            Booking::STATUS_NEEDS_REVIEW,
        ])), true)) {
            $bookings->where('status', $status);
        }

        if ($filters['from'] && $filters['to']) {
            $bookings->whereBetween('booking_date', [$filters['from']->startOfDay(), $filters['to']->endOfDay()]);
        }

        if ($search !== '') {
            $bookings->where(function ($q) use ($search) {
                $q->where('source_transaction_id', 'like', "%{$search}%")
                    ->orWhere('psjb_number', 'like', "%{$search}%")
                    ->orWhereHas('lot', fn ($lot) => $lot->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('buyer', fn ($buyer) => $buyer->where('name', 'like', "%{$search}%"));
            });
        }

        return view('bookings.index', [
            'filters' => $filters,
            'bookings' => $bookings->paginate(50)->withQueryString(),
            'status' => $status,
            'statuses' => [
                Booking::STATUS_ACTIVE,
                Booking::STATUS_COMPLETED,
                Booking::STATUS_MUNDUR,
                Booking::STATUS_BATAL,
                Booking::STATUS_REPLACED,
                Booking::STATUS_MOVED,
                Booking::STATUS_NEEDS_REVIEW,
            ],
            'search' => $search,
        ]);
    }

    public function show(Request $request, Booking $booking): View
    {
        abort_unless($request->user()?->canAccessProject($booking->project), 403, 'You do not have access to this project.');

        $booking->load([
            'project.branch', 'lot', 'buyer',
            'predecessor.lot', 'predecessor.buyer', 'successors.buyer', 'successors.lot',
            'transition.user', 'outgoingTransitions.user',
            'psjb', 'biCheck', 'pemberkasan', 'bankSubmissions.pemberkasan', 'ppjb', 'akad', 'bast',
        ]);

        $timeline = collect()
            ->when($booking->psjb, fn ($c, $r) => $c->push($this->entry('PSJB', $r->psjb_date, [
                'No. PSJB' => $r->psjb_number,
                'Status' => $r->source_status,
                'Metode bayar' => $r->payment_method,
                'DP all-in' => $r->dp_all_in ? 'Rp ' . number_format((float) $r->dp_all_in, 0, ',', '.') : null,
                'Cicilan' => $r->installment_count,
                'UTJ' => $r->utj_date?->format('d M Y') . ($r->utj_amount ? ' · ' . number_format((float) $r->utj_amount, 0, ',', '.') : ''),
                'Catatan' => $r->notes,
            ])))
            ->when($booking->biCheck, fn ($c, $r) => $c->push($this->entry('BI Checking', $r->check_date, [
                'No. BI' => $r->bi_number,
                'Hasil' => $r->result,
                'Keputusan' => $r->decision,
                'Catatan' => $r->notes,
            ])))
            ->when($booking->pemberkasan->isNotEmpty(), fn ($c) => $c->merge($booking->pemberkasan->map(fn ($r) => $this->entry('Pemberkasan', $r->received_date, [
                'No. berkas' => $r->file_number,
                'Bank' => trim((string) $r->bank . ' ' . (string) $r->bank_unit),
                'Status' => $r->status,
                'Revisi' => $r->revision,
                'Catatan' => $r->notes,
                'Kondisi' => $r->is_current ? 'Terkini' : 'Riwayat',
            ]))))
            ->map(fn ($entry) => $entry)
            ->merge($booking->bankSubmissions->map(fn ($r) => $this->entry('Proses Bank', $r->submitted_date, [
                'ID Submission' => $r->id,
                'ID Berkas' => $r->pemberkasan_id,
                'No. berkas' => $r->file_number,
                'Revisi' => $r->pemberkasan?->revision,
                'Bank' => trim((string) $r->bank . ' ' . (string) $r->bank_unit),
                'Pengajuan ke-' => $r->submission_sequence,
                'Keputusan' => $r->result,
                'Tgl keputusan' => $r->decision_date?->format('d M Y'),
                'Plafon' => $r->approved_amount ? 'Rp ' . number_format((float) $r->approved_amount, 0, ',', '.') : null,
                'Tenor' => $r->tenor,
                'Catatan' => $r->notes,
            ])))
            ->when($booking->ppjb, fn ($c, $r) => $c->push($this->entry('PPJB Developer', $r->signed_date, [
                'No. PPJB' => $r->ppjb_number,
                'Status DP' => $r->dp_status,
                'Sumber' => $r->bank_submission_id ? 'Submission Bank #' . $r->bank_submission_id : ($r->pemberkasan_id ? 'Berkas #' . $r->pemberkasan_id : null),
                'Catatan' => $r->notes,
            ])))
            ->when($booking->akad, fn ($c, $r) => $c->push($this->entry('Akad', $r->akad_date, [
                'No. Akad' => $r->akad_number,
                'Kualitas' => $r->quality,
                'Sumber PPJB' => $r->ppjb_id ? 'PPJB #' . $r->ppjb_id : null,
                'Catatan' => $r->notes,
            ])))
            ->when($booking->bast, fn ($c, $r) => $c->push($this->entry('BAST', $r->bast_date, [
                'No. BAST' => $r->bast_number,
                'Sumber Akad' => $r->akad_id ? 'Akad #' . $r->akad_id : null,
                'Catatan' => $r->notes,
            ])))
            ->when($booking->transition, fn ($c, $r) => $c->push($this->entry($r->action === \App\Models\BookingTransition::ACTION_NAME_CHANGE ? 'GANTI NAMA' : 'PINDAH KAVLING', $r->effective_date, [
                'Booking sumber' => '#' . $r->source_booking_id,
                'Booking penerus' => '#' . $r->successor_booking_id,
                'Alasan' => $r->reason,
                'Aktor' => $r->user?->name,
            ])))
            ->when($booking->outgoingTransitions->isNotEmpty(), fn ($c) => $c->merge($booking->outgoingTransitions->map(fn ($r) => $this->entry($r->action === \App\Models\BookingTransition::ACTION_NAME_CHANGE ? 'GANTI NAMA' : 'PINDAH KAVLING', $r->effective_date, [
                'Booking sumber' => '#' . $r->source_booking_id,
                'Booking penerus' => '#' . $r->successor_booking_id,
                'Alasan' => $r->reason,
                'Aktor' => $r->user?->name,
            ]))))
            ->sortBy(fn (array $entry) => [$entry['sort'] === '' ? 1 : 0, $entry['sort']])
            ->values();

        $flags = [
            'Mundur' => $booking->bankSubmissions->contains(fn ($r) => $r->withdrawn_flag)
                || (bool) $booking->biCheck?->withdrawn_flag,
            'Batal' => $booking->bankSubmissions->contains(fn ($r) => $r->cancelled_flag)
                || (bool) $booking->biCheck?->cancelled_flag,
            'Ganti nama' => $booking->bankSubmissions->contains(fn ($r) => $r->name_change_flag)
                || (bool) $booking->biCheck?->name_change_flag,
            'Pindah kavling' => $booking->bankSubmissions->contains(fn ($r) => $r->moved_lot_flag)
                || (bool) $booking->biCheck?->moved_lot_flag,
        ];

        return view('bookings.show', [
            'booking' => $booking,
            'timeline' => $timeline->all(),
            'flags' => array_filter($flags),
        ]);
    }

    private function entry(string $stage, $date, array $details): array
    {
        $details = array_filter($details, fn ($value) => $value !== null && $value !== '');

        return [
            'stage' => $stage,
            'date' => $date?->format('d M Y'),
            'sort' => $date?->format('Y-m-d') ?? '',
            'details' => $details,
        ];
    }
}
