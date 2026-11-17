@extends('layouts.app')

@section('title', 'TTD PPJB')
@section('subtitle', $project->name . ' · ' . $project->branch?->name)

@section('content')
<section class="card section" aria-labelledby="ttd-ppjb-heading">
    <div class="card-title">
        <h2 id="ttd-ppjb-heading">Antrian TTD PPJB</h2>
        <span class="hint">{{ $bookings->count() }} booking</span>
    </div>
    @if ($bookings->isEmpty())
        <p class="empty-state">Tidak ada booking yang memenuhi syarat TTD PPJB.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Kavling</th>
                        <th>Konsumen</th>
                        <th>Metode</th>
                        <th>Sumber</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($bookings as $booking)
                    <tr>
                        <td>{{ $booking->lot?->code }}</td>
                        <td>{{ $booking->buyer?->name }}</td>
                        <td><span class="badge">{{ $booking->payment_method }}</span></td>
                        <td>
                            @if ($booking->payment_method === 'KPR')
                                {{ $booking->bankSubmissions->count() }} putusan APPROVE
                            @else
                                Berkas {{ $booking->currentPemberkasan?->file_number ?? '—' }}
                            @endif
                        </td>
                        <td><a class="btn btn--small" href="{{ route('ppjb.create', [$project, $booking]) }}">TTD PPJB</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
