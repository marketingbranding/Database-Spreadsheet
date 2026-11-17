@extends('layouts.app')

@section('title', 'Proyek')
@section('subtitle', 'Daftar proyek yang dapat Anda akses')

@section('content')
<form class="filterbar" method="GET" action="{{ route('projects.index') }}">
    @include('partials.filters', ['showPeriod' => false])
</form>

@if ($rows->isEmpty())
    <div class="card"><div class="empty">Tidak ada proyek yang cocok dengan filter.</div></div>
@else
    <div class="card section">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Proyek</th>
                        <th>Cabang</th>
                        <th class="num">Kavling</th>
                        <th class="num">Tersedia</th>
                        <th class="num">Booking Aktif</th>
                        <th class="num">Akad</th>
                        <th class="num">BAST</th>
                        <th class="num">Isu Impor</th>
                        <th>Impor Terakhir</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route('projects.show', $row['project']) }}"><strong>{{ $row['project']->name }}</strong></a>
                                <div class="small muted">{{ $row['project']->code }}</div>
                            </td>
                            <td>{{ $row['project']->branch?->name }}</td>
                            <td class="num">{{ number_format($row['summary']['total_lots']) }}</td>
                            <td class="num">{{ number_format($row['summary']['available']) }}</td>
                            <td class="num">{{ number_format($row['summary']['active_bookings']) }}</td>
                            <td class="num">{{ number_format($row['summary']['akad']) }}</td>
                            <td class="num">{{ number_format($row['summary']['bast']) }}</td>
                            <td class="num">
                                @if ($row['summary']['unresolved_total'] > 0)
                                    <span class="badge badge--{{ $row['summary']['unresolved_errors'] > 0 ? 'error' : 'warning' }}">{{ number_format($row['summary']['unresolved_total']) }}</span>
                                @else
                                    <span class="badge badge--success">0</span>
                                @endif
                            </td>
                            <td class="small">
                                @if ($row['lastBatch'])
                                    {{ $row['lastBatch']->finished_at?->format('d M Y H:i') }}
                                    <span class="badge badge--{{ strtolower($row['lastBatch']->status) }}">{{ $row['lastBatch']->status }}</span>
                                @else
                                    <span class="muted">belum ada</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
