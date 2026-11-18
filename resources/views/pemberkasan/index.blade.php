@extends('layouts.app')

@section('title', 'Pemberkasan')
@section('subtitle', $project->name . ' · ' . $project->branch?->name)

@section('content')
@include('partials.stage-tabs', ['active' => $tab])

@if ($tab === 'riwayat')
    @include('partials.stage-history', ['history' => $history, 'stage' => 'pemberkasan'])
@else
<section class="card section" aria-labelledby="pemberkasan-heading">
    <div class="card-title">
        <h2 id="pemberkasan-heading">Antrean Pemberkasan</h2>
        <span class="hint">KPR LANJUT / CASH · {{ $bookings->count() }} booking</span>
    </div>
    @if ($bookings->isEmpty())
        <p class="empty-state">Tidak ada booking yang memenuhi syarat Pemberkasan.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kavling</th><th>Konsumen</th><th>Metode</th><th>Tahap</th><th></th></tr></thead>
                <tbody>
                @foreach ($bookings as $booking)
                    <tr>
                        <td>{{ $booking->lot?->code }}</td>
                        <td>{{ $booking->buyer?->name }}</td>
                        <td>{{ $booking->payment_method }}</td>
                        <td>{{ $booking->stageLabel() }}</td>
                        <td><a class="btn btn--small" href="{{ route('pemberkasan.edit', [$project, $booking]) }}">Buka berkas</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif
@endsection
