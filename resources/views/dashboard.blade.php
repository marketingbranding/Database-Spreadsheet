@extends('layouts.app')

@section('title', 'Dashboard')
@section('subtitle', 'Ringkasan portofolio proyek pilot')

@section('content')
@if (! $summary)
    <div class="card">
        <div class="empty">Belum ada proyek yang ditugaskan untuk akun Anda.</div>
    </div>
@else
    <form class="filterbar" method="GET" action="{{ route('dashboard') }}">
        @include('partials.filters')
    </form>

    <section aria-label="Ringkasan utama">
        <div class="kpi-grid">
            <div class="kpi">
                <div class="kpi-label">Total Kavling</div>
                <div class="kpi-value">{{ number_format($summary['total_lots']) }}</div>
                <div class="kpi-note">{{ number_format($summary['historical_akad']) }} akad historis &lt;= 2025</div>
            </div>
            <div class="kpi kpi--emerald">
                <div class="kpi-label">Stok Tersedia</div>
                <div class="kpi-value">{{ number_format($summary['available']) }}</div>
                <div class="kpi-note">di luar kavling historis</div>
            </div>
            <div class="kpi kpi--amber">
                <div class="kpi-label">Booking Aktif</div>
                <div class="kpi-value">{{ number_format($summary['active_bookings']) }}</div>
                <div class="kpi-note">{{ number_format($summary['needs_review']) }} perlu cek</div>
            </div>
            <div class="kpi kpi--navy">
                <div class="kpi-label">Akad (periode)</div>
                <div class="kpi-value">{{ number_format($summary['akad']) }}</div>
                <div class="kpi-note">selesai BAST: {{ number_format($summary['completed_bookings']) }}</div>
            </div>
            <div class="kpi kpi--navy">
                <div class="kpi-label">BAST (periode)</div>
                <div class="kpi-value">{{ number_format($summary['bast']) }}</div>
                <div class="kpi-note">dihitung dari tanggal BAST</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Mundur / Batal</div>
                <div class="kpi-value">{{ number_format($summary['withdrawn']) }} / {{ number_format($summary['cancelled']) }}</div>
                <div class="kpi-note">keputusan dalam periode</div>
            </div>
            <div class="kpi {{ ($summary['unresolved_errors'] ?? 0) > 0 ? 'kpi--rose' : '' }}">
                <div class="kpi-label">Isu Impor</div>
                <div class="kpi-value">{{ number_format($summary['unresolved_total']) }}</div>
                <div class="kpi-note">{{ $summary['unresolved_errors'] }} error · {{ $summary['unresolved_warnings'] }} warning</div>
            </div>
        </div>
    </section>

    <section class="card section" aria-labelledby="positions-h">
        <div class="card-title">
            <h2 id="positions-h">Posisi Kavling</h2>
            <span class="hint">posisi terkini, bukan periode</span>
        </div>
        <div class="pipeline">
            @foreach ($positions as $position)
                @php $max = collect($positions)->max('total') ?: 1; @endphp
                <div class="pipeline-row">
                    <span class="pipeline-label">{{ $position['label'] }}</span>
                    <span class="pipeline-track">
                        <span class="pipeline-fill" style="width: {{ $position['total'] > 0 ? max(3, round($position['total'] / $max * 100)) : 0 }}%"></span>
                    </span>
                    <span class="pipeline-count">{{ number_format($position['total']) }}</span>
                </div>
            @endforeach
        </div>
    </section>

    @if ($perProject->count() > 1)
        <section class="card section" aria-labelledby="perproject-h">
            <div class="card-title"><h2 id="perproject-h">Per Proyek</h2></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Proyek</th>
                            <th class="num">Kavling</th>
                            <th class="num">Tersedia</th>
                            <th class="num">Aktif</th>
                            <th class="num">Akad</th>
                            <th class="num">BAST</th>
                            <th class="num">Isu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($perProject as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('projects.show', $row['project']) }}">{{ $row['project']->name }}</a>
                                    <div class="small muted">{{ $row['branch']->name }}</div>
                                </td>
                                <td class="num">{{ number_format($row['total_lots']) }}</td>
                                <td class="num">{{ number_format($row['available']) }}</td>
                                <td class="num">{{ number_format($row['active_bookings']) }}</td>
                                <td class="num">{{ number_format($row['akad']) }}</td>
                                <td class="num">{{ number_format($row['bast']) }}</td>
                                <td class="num">{{ number_format($row['unresolved_total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card section" aria-labelledby="pipeline-h">
        <div class="card-title">
            <h2 id="pipeline-h">Pipeline Booking Aktif</h2>
            <span class="hint">posisi tahap terkini, booking aktif</span>
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
    </section>

    <section class="card section" aria-labelledby="recent-h">
        <div class="card-title">
            <h2 id="recent-h">Booking Terbaru</h2>
            <a class="hint" href="{{ route('bookings.index') }}">Lihat semua</a>
        </div>
        @if ($recent->isEmpty())
            <div class="empty">Belum ada booking pada filter ini.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Kavling</th>
                            <th>Konsumen</th>
                            <th>Proyek</th>
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
                                <td class="small">{{ $booking->project?->name }}</td>
                                <td><span class="badge badge--{{ strtolower($booking->status) }}">{{ $booking->statusLabel() }}</span></td>
                                <td>{{ $booking->stageLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
@endsection
