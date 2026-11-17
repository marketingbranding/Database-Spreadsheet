@extends('layouts.app')

@section('title', 'Akad')
@section('subtitle', $project->name . ' · ' . $project->branch?->name)

@section('content')
<section class="card section" aria-labelledby="akad-heading">
    <div class="card-title">
        <h2 id="akad-heading">Antrian Akad</h2>
        <span class="hint">{{ $bookings->count() }} booking</span>
    </div>
    @if ($bookings->isEmpty())
        <p class="empty-state">Tidak ada booking yang memenuhi syarat Akad.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Kavling</th><th>Konsumen</th><th>Metode</th><th>PPJB sumber</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($bookings as $booking)
                    <tr>
                        <td>{{ $booking->lot?->code }}</td>
                        <td>{{ $booking->buyer?->name }}</td>
                        <td><span class="badge">{{ $booking->payment_method }}</span></td>
                        <td>{{ $booking->ppjb?->ppjb_number }} · {{ $booking->ppjb?->signed_date?->format('d M Y') ?? '—' }}</td>
                        <td><a class="btn btn--small" href="{{ route('akad.create', [$project, $booking]) }}">Catat Akad</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
