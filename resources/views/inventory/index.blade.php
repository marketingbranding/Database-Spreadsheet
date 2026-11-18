@extends('layouts.app')

@section('title', 'Stok Kavling')
@section('subtitle', 'Posisi kavling per proyek')

@section('content')
<form class="filterbar" method="GET" action="{{ route('inventory.index') }}">
    @include('partials.filters', ['showPeriod' => false])

    <label class="filter-field">
        <span>Posisi</span>
        <select name="position">
            <option value="all" @selected($position === 'all')>Semua</option>
            <option value="available" @selected($position === 'available')>Tersedia</option>
            <option value="booked" @selected($position === 'booked')>Booking aktif</option>
            <option value="sold" @selected($position === 'sold')>BAST / terjual</option>
            <option value="needs_review" @selected($position === 'needs_review')>Perlu cek</option>
            <option value="historical" @selected($position === 'historical')>Akad historis</option>
        </select>
    </label>

    <label class="filter-field">
        <span>Cari</span>
        <input type="search" name="q" value="{{ $search }}" placeholder="Kode kavling, konsumen, ID transaksi" aria-label="Cari kavling">
    </label>
</form>

<div class="card">
    @if ($lots->isEmpty())
        <div class="empty">Tidak ada kavling yang cocok dengan filter.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Kavling</th>
                        <th>Proyek</th>
                        <th class="num">Luas Bangunan</th>
                        <th class="num">Luas Tanah</th>
                        <th>Posisi</th>
                        <th>Pemegang Saat Ini</th>
                        <th>Status Booking</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lots as $lot)
                        @php $holder = $lot->currentBooking; @endphp
                        <tr>
                            <td><strong>{{ $lot->code }}</strong></td>
                            <td class="small">{{ $lot->project?->name }}</td>
                            <td class="num">{{ $lot->building_area ? number_format((float) $lot->building_area, 2, ',', '.') . ' m²' : '—' }}</td>
                            <td class="num">{{ $lot->land_area ? number_format((float) $lot->land_area, 2, ',', '.') . ' m²' : '—' }}</td>
                            <td>
                                @php
                                    $positionClass = match ($lot->inventoryPosition()) {
                                        'SOLD' => 'sold', 'BOOKED' => 'booked',
                                        'HISTORICAL_AKAD' => 'historical', default => 'available',
                                    };
                                @endphp
                                <span class="badge badge--{{ $positionClass }}">{{ $lot->inventoryPosition() === 'SOLD' ? 'BAST' : ($lot->inventoryPosition() === 'BOOKED' ? 'BOOKING AKTIF' : ($lot->inventoryPosition() === 'HISTORICAL_AKAD' ? 'AKAD HISTORIS' : 'TERSEDIA')) }}</span>
                            </td>
                            <td>
                                @if ($holder)
                                    <a href="{{ route('bookings.show', $holder) }}">{{ $holder->buyer?->name }}</a>
                                @elseif ($lot->historical_akad_before_2026)
                                    <span class="small muted">akad s.d. 2025</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($holder)
                                    <span class="badge badge--{{ strtolower($holder->status) }}">{{ $holder->statusLabel() }}</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $lots->links() }}
    @endif
</div>
@endsection
