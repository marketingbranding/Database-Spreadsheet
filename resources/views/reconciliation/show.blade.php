@extends('layouts.app')

@section('title', 'Rekonsiliasi — ' . $report['project']->name)
@section('subtitle', $report['project']->branch?->name . ' · ' . $report['project']->code)

@section('content')
<section class="kpi-grid" aria-label="Ringkasan proyek">
    <div class="kpi">
        <div class="kpi-label">Total Kavling</div>
        <div class="kpi-value">{{ number_format($report['metrics']['total_lots']) }}</div>
    </div>
    <div class="kpi kpi--emerald">
        <div class="kpi-label">Tersedia</div>
        <div class="kpi-value">{{ number_format($report['metrics']['available']) }}</div>
    </div>
    <div class="kpi kpi--amber">
        <div class="kpi-label">Aktif</div>
        <div class="kpi-value">{{ number_format($report['metrics']['active_bookings']) }}</div>
    </div>
    <div class="kpi kpi--navy">
        <div class="kpi-label">Akad (periode)</div>
        <div class="kpi-value">{{ number_format($report['metrics']['akad']) }}</div>
    </div>
    <div class="kpi kpi--navy">
        <div class="kpi-label">BAST (periode)</div>
        <div class="kpi-value">{{ number_format($report['metrics']['bast']) }}</div>
    </div>
    <div class="kpi {{ $report['metrics']['unresolved_errors'] > 0 ? 'kpi--rose' : '' }}">
        <div class="kpi-label">Isu Terbuka</div>
        <div class="kpi-value">{{ number_format($report['metrics']['unresolved_total']) }}</div>
    </div>
</section>

<section class="card section" aria-labelledby="tabs-h">
    <div class="card-title">
        <h2 id="tabs-h">Baris Sumber vs Data Terimpor</h2>
        @if ($report['batch'])
            <span class="hint">Batch #{{ $report['batch']->id }} · {{ $report['batch']->finished_at?->format('d M Y H:i') }}</span>
        @else
            <span class="hint">Belum ada batch impor selesai</span>
        @endif
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Tab</th>
                    <th class="num">Baris Sumber</th>
                    <th class="num">Terimpor</th>
                    <th class="num">Selisih</th>
                    <th>Catatan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        <td><strong>{{ $row['tab'] }}</strong></td>
                        <td class="num">{{ $row['raw'] === null ? '—' : number_format($row['raw']) }}</td>
                        <td class="num">{{ number_format($row['imported']) }}</td>
                        <td class="num">
                            @if ($row['difference'] !== null)
                                <span class="{{ $row['difference'] === 0 ? 'pos' : 'neg' }}">{{ $row['difference'] > 0 ? '+' : '' }}{{ number_format($row['difference']) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="small muted">{{ $row['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="card section" aria-labelledby="hq-h">
    <div class="card-title">
        <h2 id="hq-h">vs hq_report_export (posisi saat ini)</h2>
        <span class="hint">angka HQ adalah posisi kumulatif, bukan aktual periode</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Metrik</th>
                    <th class="num">Dashboard</th>
                    <th class="num">HQ Export</th>
                    <th class="num">Selisih</th>
                    <th>Penjelasan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['hq_comparison'] as $item)
                    <tr>
                        <td><strong>{{ $item['label'] }}</strong></td>
                        <td class="num">{{ number_format($item['dashboard']) }}</td>
                        <td class="num">{{ $item['hq'] === null ? '—' : number_format((float) $item['hq']) }}</td>
                        <td class="num">
                            @if ($item['difference'] !== null)
                                <span class="{{ $item['difference'] === 0 ? 'pos' : 'neg' }}">{{ $item['difference'] > 0 ? '+' : '' }}{{ number_format($item['difference']) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="small muted">{{ $item['explanation'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="card section" aria-labelledby="issues-h">
    <div class="card-title">
        <h2 id="issues-h">Isu Impor Terbuka</h2>
        <a class="hint" href="{{ route('imports.index') }}">Semua batch &amp; isu</a>
    </div>
    @if ($report['open_issues']->isEmpty())
        <div class="empty">Tidak ada isu terbuka.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Kode</th>
                        <th>Pesan</th>
                        <th>Sumber</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['open_issues'] as $issue)
                        <tr>
                            <td><span class="badge badge--{{ $issue->severity }}">{{ strtoupper($issue->severity) }}</span></td>
                            <td class="small">{{ $issue->code }}</td>
                            <td>{{ $issue->message }}</td>
                            <td class="small muted">{{ $issue->source_tab }}{{ $issue->source_row ? ' baris ' . $issue->source_row : '' }}</td>
                            <td class="small">
                                @if (config('marison.import_conflicts_enabled') && in_array($report['project']->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true))
                                    <a class="btn btn--small btn--secondary" href="{{ route('import-conflicts.index', $report['project']) }}">Tinjau konflik</a>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<div class="section">
    <a class="btn btn--secondary" href="{{ route('reconciliation.index') }}">&larr; Semua proyek</a>
</div>
@endsection
