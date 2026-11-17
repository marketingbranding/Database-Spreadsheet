@extends('layouts.app')

@section('title', $project->name)
@section('subtitle', $project->branch?->name . ' · ' . $project->code)

@section('content')
<form class="filterbar" method="GET" action="{{ route('projects.show', $project) }}">
    @include('partials.filters')
</form>

@if ($project->management_mode === \App\Models\Project::MANAGEMENT_LARAVEL)
    <div class="section" style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a class="btn btn--secondary" href="{{ route('bi.index', $project) }}">BI Checking</a>
        @if ($project->management_mode === \App\Models\Project::MANAGEMENT_LARAVEL && in_array($project->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true))
            <a class="btn btn--secondary" href="{{ route('pemberkasan.index', $project) }}">Pemberkasan</a>
            <a class="btn btn--secondary" href="{{ route('bank.index', $project) }}">Proses Bank</a>
            <a class="btn btn--secondary" href="{{ route('ppjb.index', $project) }}">TTD PPJB</a>
            <a class="btn btn--secondary" href="{{ route('akad.index', $project) }}">Akad</a>
            <a class="btn btn--secondary" href="{{ route('bast.index', $project) }}">BAST</a>
        @endif
        @if (auth()->user()->role === 'branch_admin' && config('app.env') === 'staging' && filter_var(env('MARISON_OPERATIONAL_WRITES', false), FILTER_VALIDATE_BOOL))
            <a class="btn" href="{{ route('psjb.create', $project) }}">Buat PSJB</a>
        @endif
    </div>
@endif

<section class="section" aria-label="Ringkasan proyek">
    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label">Total Kavling</div>
            <div class="kpi-value">{{ number_format($summary['total_lots']) }}</div>
        </div>
        <div class="kpi kpi--emerald">
            <div class="kpi-label">Tersedia</div>
            <div class="kpi-value">{{ number_format($summary['available']) }}</div>
        </div>
        <div class="kpi kpi--amber">
            <div class="kpi-label">Aktif</div>
            <div class="kpi-value">{{ number_format($summary['active_bookings']) }}</div>
        </div>
        <div class="kpi kpi--navy">
            <div class="kpi-label">Akad (periode)</div>
            <div class="kpi-value">{{ number_format($summary['akad']) }}</div>
        </div>
        <div class="kpi kpi--navy">
            <div class="kpi-label">BAST (periode)</div>
            <div class="kpi-value">{{ number_format($summary['bast']) }}</div>
        </div>
        <div class="kpi {{ $summary['unresolved_errors'] > 0 ? 'kpi--rose' : '' }}">
            <div class="kpi-label">Isu Impor</div>
            <div class="kpi-value">{{ number_format($summary['unresolved_total']) }}</div>
        </div>
    </div>
</section>

<div class="card section" aria-labelledby="pos-h">
    <div class="card-title">
        <h2 id="pos-h">Posisi Kavling</h2>
        <a class="hint" href="{{ route('inventory.index', ['project' => $project->code]) }}">Buka stok kavling</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Posisi</th><th class="num">Jumlah</th></tr></thead>
            <tbody>
                @foreach ($positions as $position)
                    <tr>
                        <td>{{ $position['label'] }}</td>
                        <td class="num">{{ number_format($position['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card section" aria-labelledby="pipe-h">
    <div class="card-title">
        <h2 id="pipe-h">Pipeline Aktif</h2>
        <span class="hint">booking aktif per tahap</span>
    </div>
    <div class="pipeline">
        @php $pipelineMax = collect($pipeline)->max('total') ?: 1; @endphp
        @foreach ($pipeline as $stage)
            <div class="pipeline-row">
                <span class="pipeline-label">{{ $stage['label'] }}</span>
                <span class="pipeline-track">
                    <span class="pipeline-fill" style="width: {{ $stage['total'] > 0 ? max(3, round($stage['total'] / $pipelineMax * 100)) : 0 }}%"></span>
                </span>
                <span class="pipeline-count">{{ number_format($stage['total']) }}</span>
            </div>
        @endforeach
    </div>
</div>

<div class="card section" aria-labelledby="recent-h">
    <div class="card-title">
        <h2 id="recent-h">Booking Terbaru</h2>
        @if ($lastBatch)
            <span class="hint">Impor terakhir: {{ $lastBatch->finished_at?->format('d M Y H:i') }}</span>
        @endif
    </div>
    @if ($recent->isEmpty())
        <div class="empty">Belum ada booking.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kavling</th>
                        <th>Konsumen</th>
                        <th>Status</th>
                        <th>Tahap</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recent as $booking)
                        <tr>
                            <td>{{ $booking->booking_date?->format('d M Y') }}</td>
                            <td><a href="{{ route('bookings.show', $booking) }}">{{ $booking->lot?->code }}</a></td>
                            <td>{{ $booking->buyer?->name }}</td>
                            <td><span class="badge badge--{{ strtolower($booking->status) }}">{{ $booking->statusLabel() }}</span></td>
                            <td>{{ $booking->stageLabel() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card section" aria-labelledby="links-h">
    <div class="card-title"><h2 id="links-h">Tautan Terkait</h2></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a class="btn btn--secondary" href="{{ route('reconciliation.show', $project) }}">Rekonsiliasi proyek ini</a>
        @if ($lastBatch)
            <a class="btn btn--secondary" href="{{ route('imports.show', $lastBatch) }}">Batch impor terakhir</a>
        @endif
    </div>
</div>
@endsection
