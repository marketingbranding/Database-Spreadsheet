@extends('layouts.app')

@section('title', 'PSJB / UTJ')
@section('subtitle', $project->name . ' · ' . $project->branch?->name)

@php($blocked = $blocked ?? collect())

@section('toolbar')
    @if ($canCreatePsjb)
        <a class="btn" href="{{ route('psjb.create', $project) }}">Buat PSJB</a>
    @endif
@endsection

@section('content')
@include('partials.stage-tabs', ['active' => $tab])

@if ($tab === 'riwayat')
    @include('partials.stage-history', ['history' => $history, 'stage' => 'psjb'])
@else
    <section class="card section" aria-labelledby="psjb-heading">
        <div class="card-title">
            <h2 id="psjb-heading">Antrean PSJB / UTJ</h2>
            <span class="hint">Aktif · {{ $bookings->count() }} booking</span>
        </div>
        @if ($bookings->isEmpty())
            <p class="empty-state">Tidak ada booking aktif di tahap PSJB.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Kavling</th><th>Konsumen</th><th>Tanggal PSJB/UTJ</th><th>Metode</th><th>Sales</th><th>Tindakan</th></tr></thead>
                    <tbody>
                    @foreach ($bookings as $booking)
                        @php($block = $blocked->get($booking->id))
                        <tr>
                            <td>{{ $booking->lot?->code }}</td>
                            <td>{{ $booking->buyer?->name }}</td>
                            <td>{{ $booking->psjb?->psjb_date?->format('d M Y') ?? $booking->booking_date?->format('d M Y') }}
                                @if ($booking->psjb?->utj_date)
                                    <span class="hint">UTJ {{ $booking->psjb->utj_date->format('d M Y') }}</span>
                                @endif
                            </td>
                            <td><span class="badge badge--{{ strtolower($booking->payment_method) }}">{{ $booking->payment_method }}</span></td>
                            <td>{{ $booking->sales_name ?? '—' }}</td>
                            <td>
                                @if ($block)
                                    <span class="hint">{{ $block['reason'] }}</span>
                                    @if ($block['conflict'] && $canViewConflicts)
                                        <a class="btn btn--small" href="{{ route('import-conflicts.show', [$project, $block['conflict']]) }}">Lihat konflik</a>
                                    @endif
                                @elseif ($booking->payment_method === 'KPR')
                                    <a class="btn btn--small" href="{{ route('bi.edit', [$project, $booking]) }}">Periksa BI</a>
                                @else
                                    <a class="btn btn--small" href="{{ route('pemberkasan.edit', [$project, $booking]) }}">Buka berkas</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
@endsection
