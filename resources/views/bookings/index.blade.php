@extends('layouts.app')

@section('title', 'Booking')
@section('subtitle', 'Riwayat booking per proyek')

@section('content')
<form class="filterbar" method="GET" action="{{ route('bookings.index') }}">
    @include('partials.filters')

    <label class="filter-field">
        <span>Status</span>
        <select name="status">
            <option value="">Semua status</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ (new App\Models\Booking(['status' => $s]))->statusLabel() }}</option>
            @endforeach
        </select>
    </label>

    <label class="filter-field">
        <span>Cari</span>
        <input type="search" name="q" value="{{ $search }}" placeholder="Kavling, konsumen, ID transaksi" aria-label="Cari booking">
    </label>

    <div class="filter-actions">
        <button type="submit" class="btn">Terapkan</button>
        <a class="btn btn--secondary" href="{{ route('bookings.index') }}">Reset</a>
    </div>
</form>

<div class="card">
    @if ($bookings->isEmpty())
        <div class="empty">Tidak ada booking yang cocok dengan filter.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>ID Transaksi</th>
                        <th>Kavling</th>
                        <th>Konsumen</th>
                        <th>Proyek</th>
                        <th>Status</th>
                        <th>Tahap</th>
                        <th class="num">Harga Dasar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bookings as $booking)
                        <tr>
                            <td>{{ $booking->booking_date?->format('d M Y') }}</td>
                            <td class="small">{{ $booking->source_transaction_id }}</td>
                            <td><a href="{{ route('bookings.show', $booking) }}">{{ $booking->lot?->code }}</a></td>
                            <td>{{ $booking->buyer?->name }}</td>
                            <td class="small">{{ $booking->project?->name }}</td>
                            <td>
                                <span class="badge badge--{{ strtolower($booking->status) }}">{{ $booking->statusLabel() }}</span>
                                @unless ($booking->is_current_holder || in_array($booking->status, App\Models\Booking::OUTCOME_STATUSES, true))
                                    <span class="small muted" style="display:block;">non-pemegang</span>
                                @endunless
                            </td>
                            <td>{{ $booking->stageLabel() }}</td>
                            <td class="num">{{ $booking->base_price ? 'Rp ' . number_format((float) $booking->base_price, 0, ',', '.') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $bookings->links() }}
    @endif
</div>
@endsection
