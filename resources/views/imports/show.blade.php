@extends('layouts.app')

@section('title', 'Batch Impor #' . $batch->id)
@section('subtitle', $batch->project?->name . ' · ' . strtoupper($batch->mode) . ' · ' . $batch->source)

@section('content')
<section class="card section" aria-labelledby="batch-h">
    <div class="card-title">
        <h2 id="batch-h">Batch #{{ $batch->id }}</h2>
        <span class="badge badge--{{ strtolower($batch->status) }}">{{ $batch->status }}</span>
    </div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Proyek</dt><dd>{{ $batch->project?->name }} ({{ $batch->project?->code }})</dd>
        <dt>Mode</dt><dd>{{ strtoupper($batch->mode) }} — {{ $batch->mode === 'dry-run' ? 'tidak ada data yang disimpan' : 'data disimpan' }}</dd>
        <dt>Sumber</dt><dd>{{ $batch->source }}{{ $batch->spreadsheet_id ? ' · spreadsheet ' . \Illuminate\Support\Str::limit($batch->spreadsheet_id, 20) : '' }}</dd>
        <dt>Mulai</dt><dd>{{ $batch->started_at?->format('d M Y H:i:s') }}</dd>
        <dt>Selesai</dt><dd>{{ $batch->finished_at?->format('d M Y H:i:s') }}</dd>
        @if ($batch->error)
            <dt>Error</dt><dd><span class="neg">{{ $batch->error }}</span></dd>
        @endif
    </dl>
</section>

@if (isset($batch->stats['raw_counts']) && is_array($batch->stats['raw_counts']))
    <section class="card section" aria-labelledby="raw-h">
        <div class="card-title"><h2 id="raw-h">Baris Sumber Terbaca</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tab</th><th class="num">Baris</th></tr></thead>
                <tbody>
                    @foreach ($batch->stats['raw_counts'] as $tab => $count)
                        <tr><td>{{ $tab }}</td><td class="num">{{ number_format((int) $count) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@php
    $statRows = collect($batch->stats ?? [])->reject(fn ($v, $k) => in_array($k, ['raw_counts', 'hq_export']));
@endphp

@if ($statRows->isNotEmpty())
    <section class="card section" aria-labelledby="stats-h">
        <div class="card-title"><h2 id="stats-h">Statistik Batch</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Metrik</th><th class="num">Nilai</th></tr></thead>
                <tbody>
                    @foreach ($statRows as $key => $value)
                        <tr>
                            <td>{{ str_replace('_', ' ', ucfirst($key)) }}</td>
                            <td class="num">{{ is_array($value) ? count($value) : (is_numeric($value) ? number_format((float) $value) : $value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="card section" aria-labelledby="bissues-h">
    <div class="card-title">
        <h2 id="bissues-h">Isu Batch Ini</h2>
        <a class="hint" href="{{ route('reconciliation.show', $batch->project) }}">Rekonsiliasi proyek</a>
    </div>
    @if ($batch->issues->isEmpty())
        <div class="empty">Tidak ada isu pada batch ini.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Kode</th>
                        <th>Pesan</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($batch->issues as $issue)
                        <tr>
                            <td><span class="badge badge--{{ $issue->severity }}">{{ strtoupper($issue->severity) }}</span></td>
                            <td class="small">{{ $issue->code }}</td>
                            <td>{{ $issue->message }}</td>
                            <td class="small muted">{{ $issue->source_tab }}{{ $issue->source_row ? ' baris ' . $issue->source_row : '' }}</td>
                            <td>
                                @if ($issue->resolved_at)
                                    <span class="badge badge--success">selesai</span>
                                @else
                                    <span class="badge badge--warning">terbuka</span>
                                @endif
                            </td>
                            <td class="small">
                                @if (config('marison.import_conflicts_enabled') && in_array($batch->project?->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true))
                                    <a class="btn btn--small btn--secondary" href="{{ route('import-conflicts.index', $batch->project) }}">Tinjau konflik</a>
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
    <a class="btn btn--secondary" href="{{ route('imports.index') }}">&larr; Semua batch</a>
</div>
@endsection
