@extends('layouts.app')

@section('title', 'BI Checking')
@section('subtitle', $project->name . ' · ' . $project->branch?->name)

@section('content')
@include('partials.stage-tabs', ['active' => $tab])

@if ($tab === 'riwayat')
    @include('partials.stage-history', ['history' => $history, 'stage' => 'bi'])
@else
<section class="card section" aria-labelledby="bi-heading">
    <div class="card-title">
        <h2 id="bi-heading">Antrean BI Checking</h2>
        <span class="hint">KPR · {{ $bookings->count() }} booking</span>
    </div>
    @if ($bookings->isEmpty())
        <p class="empty-state">Tidak ada booking KPR menunggu BI Checking.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kavling</th><th>Konsumen</th><th>Tanggal PSJB</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($bookings as $booking)
                    <tr>
                        <td>{{ $booking->lot?->code }}</td>
                        <td>{{ $booking->buyer?->name }}</td>
                        <td>{{ $booking->booking_date?->format('d M Y') }}</td>
                        <td>{{ $booking->statusLabel() }}</td>
                        <td><a class="btn btn--small" href="{{ route('bi.edit', [$project, $booking]) }}">Periksa</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif
@endsection
