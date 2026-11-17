@extends('layouts.app')

@section('title', 'Booking ' . ($booking->lot?->code ?? $booking->source_transaction_id))
@section('subtitle', $booking->project?->name . ' · ' . $booking->source_transaction_id)

@section('content')
<section class="card section" aria-label="Ringkasan booking">
    <div class="card-title">
        <h2>{{ $booking->lot?->code }} — {{ $booking->buyer?->name }}</h2>
        <span class="badge badge--{{ strtolower($booking->status) }}">{{ $booking->statusLabel() }}</span>
    </div>

    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Proyek</dt><dd>{{ $booking->project?->name }} ({{ $booking->project?->branch?->name }})</dd>
        <dt>Kavling</dt><dd>{{ $booking->lot?->code }} — luas {{ $booking->lot?->building_area ? number_format((float) $booking->lot->building_area, 2, ',', '.') : '—' }} m² bangunan</dd>
        <dt>Tanggal booking</dt><dd>{{ $booking->booking_date?->format('d M Y') }}</dd>
        <dt>Tahap terkini</dt><dd>{{ $booking->stageLabel() }}</dd>
        @if ($booking->decision_date)
            <dt>Keputusan</dt>
            <dd>{{ $booking->statusLabel() }} @if ($booking->decision_stage)di tahap {{ $booking->stageLabel($booking->decision_stage) }}@endif, {{ $booking->decision_date->format('d M Y') }}{{ $booking->decision_note ? ' — ' . $booking->decision_note : '' }}</dd>
        @endif
        <dt>Harga dasar</dt><dd>{{ $booking->base_price ? 'Rp ' . number_format((float) $booking->base_price, 0, ',', '.') : '—' }}</dd>
        <dt>Sales / Koordinator</dt><dd>{{ $booking->sales_name ?? '—' }} / {{ $booking->coordinator_name ?? '—' }}</dd>
    </dl>

    @if (! empty($flags))
        <p style="margin: 0.75rem 0 0;">
            @foreach ($flags as $flag => $on)
                <span class="badge badge--mundur">{{ $flag }}</span>
            @endforeach
        </p>
    @endif
</section>

@if (auth()->user()->role === 'branch_admin' && in_array($booking->status, [\App\Models\Booking::STATUS_ACTIVE, \App\Models\Booking::STATUS_NEEDS_REVIEW], true))
<section class="card section" aria-label="Keputusan booking">
    <div class="card-title"><h2>Aksi operasional</h2><span class="hint">Aksi tidak dapat dibatalkan</span></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <form method="POST" action="{{ route('bookings.withdraw', $booking) }}">
            @csrf
            <button class="btn btn--secondary" type="submit">Withdraw / Mundur</button>
        </form>
        <form method="POST" action="{{ route('bookings.cancel', $booking) }}">
            @csrf
            <button class="btn btn--danger" type="submit">Cancel / Batal</button>
        </form>
    </div>
</section>
@endif

@if ($booking->predecessor || $booking->successors->isNotEmpty())
    <section class="card section" aria-label="Rantai transfer">
        <div class="card-title"><h2>Rantai Transfer</h2></div>
        @if ($booking->predecessor)
            <p style="margin:0 0 0.5rem;">
                Sebelumnya:
                <a href="{{ route('bookings.show', $booking->predecessor) }}">{{ $booking->predecessor->lot?->code }} — {{ $booking->predecessor->buyer?->name }}</a>
                <span class="badge badge--{{ strtolower($booking->predecessor->status) }}">{{ $booking->predecessor->statusLabel() }}</span>
            </p>
        @endif
        @if ($booking->successors->isNotEmpty())
            <p style="margin:0;">
                @forelse ($booking->successors as $successor)
                    Lanjutan: <a href="{{ route('bookings.show', $successor) }}">{{ $successor->lot?->code }} — {{ $successor->buyer?->name }}</a>
                    <span class="badge badge--{{ strtolower($successor->status) }}">{{ $successor->statusLabel() }}</span><br>
                @empty
                @endforelse
            </p>
        @endif
    </section>
@endif

<section class="card section" aria-labelledby="timeline-h">
    <div class="card-title">
        <h2 id="timeline-h">Linimasa Tahapan</h2>
        <span class="hint">berdasarkan tanggal dokumen tiap tab</span>
    </div>

    @if (empty($timeline))
        <div class="empty">Tidak ada catatan tahapan.</div>
    @else
        <ol class="timeline">
            @foreach ($timeline as $entry)
                <li>
                    <div class="timeline-stage">
                        <span class="stage-name">{{ $entry['stage'] }}</span>
                        <span class="stage-date">{{ $entry['date'] ?? 'tanpa tanggal' }}</span>
                    </div>
                    <dl>
                        @foreach ($entry['details'] as $label => $value)
                            <dt>{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        @endforeach
                    </dl>
                </li>
            @endforeach
        </ol>
    @endif
</section>

<div class="section">
    <a class="btn btn--secondary" href="{{ route('bookings.index') }}">&larr; Kembali ke daftar booking</a>
</div>
@endsection
