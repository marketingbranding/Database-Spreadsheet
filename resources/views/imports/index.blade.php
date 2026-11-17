@extends('layouts.app')

@section('title', 'Impor')
@section('subtitle', 'Batch impor dan isu yang perlu ditangani')

@section('content')
<section class="card section" aria-labelledby="issues-h">
    <div class="card-title">
        <h2 id="issues-h">Isu Terbuka</h2>
        <span class="hint">{{ $issues->count() }} isu ditampilkan</span>
    </div>
    @if ($issues->isEmpty())
        <div class="empty">Tidak ada isu terbuka. 👍</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Proyek</th>
                        <th>Kode</th>
                        <th>Pesan</th>
                        <th>Sumber</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($issues as $issue)
                        <tr>
                            <td><span class="badge badge--{{ $issue->severity }}">{{ strtoupper($issue->severity) }}</span></td>
                            <td class="small">{{ $issue->project?->name }}</td>
                            <td class="small">{{ $issue->code }}</td>
                            <td>{{ $issue->message }}</td>
                            <td class="small muted">{{ $issue->source_tab }}{{ $issue->source_row ? ' baris ' . $issue->source_row : '' }}</td>
                            <td>
                                <form method="POST" action="{{ route('imports.issues.resolve', $issue) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--small btn--secondary">Selesai</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="card section" aria-labelledby="batches-h">
    <div class="card-title"><h2 id="batches-h">50 Batch Terakhir</h2></div>
    @if ($batches->isEmpty())
        <div class="empty">Belum ada batch impor. Jalankan <code>php artisan marison:import --project=…</code>.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Proyek</th>
                        <th>Mode</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th>Mulai</th>
                        <th>Selesai</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($batches as $batch)
                        <tr>
                            <td>#{{ $batch->id }}</td>
                            <td>{{ $batch->project?->name }}</td>
                            <td>{{ strtoupper($batch->mode) }}</td>
                            <td class="small">{{ $batch->source }}</td>
                            <td><span class="badge badge--{{ strtolower($batch->status) }}">{{ $batch->status }}</span></td>
                            <td class="small">{{ $batch->started_at?->format('d M Y H:i') }}</td>
                            <td class="small">{{ $batch->finished_at?->format('H:i') }}</td>
                            <td><a class="btn btn--small btn--secondary" href="{{ route('imports.show', $batch) }}">Detail</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
