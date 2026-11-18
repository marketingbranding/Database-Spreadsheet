@extends('layouts.app')

@section('title', 'Impor')
@section('subtitle', 'Dry run, antrean impor, dan isu yang perlu ditangani')

@section('content')
<section class="card section" aria-labelledby="import-form-h">
    <div class="card-title">
        <h2 id="import-form-h">Import Data Project</h2>
        <span class="hint">Jalankan dry run sebelum menerapkan data</span>
    </div>
    @if (session('status'))
        <p class="flash flash--success" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="flash flash--error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('imports.dry-run') }}" class="filterbar">
        @csrf
        <label class="filter-field">
            <span>Project</span>
            <select name="project" required>
                <option value="">Pilih project</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected((string) old('project', $preview['project_id'] ?? '') === (string) $project->id)>
                        {{ $project->branch?->name }} — {{ $project->name }}
                    </option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn">Dry Run</button>
    </form>
</section>

@if ($preview)
<section class="card section" aria-labelledby="preview-h">
    <div class="card-title">
        <h2 id="preview-h">Preview Dry Run</h2>
        <span class="hint">Berlaku 15 menit</span>
    </div>
    <p>Project: <strong>{{ $preview['project']->name }}</strong></p>
    <div class="import-preview-grid">
        @foreach (($preview['report']['stats'] ?? []) as $key => $value)
            @if (is_scalar($value))
                <div><small>{{ str_replace('_', ' ', $key) }}</small><strong>{{ $value }}</strong></div>
            @endif
        @endforeach
    </div>
    @php($errorCount = (int) data_get($preview, 'report.stats.issues.error', 0))
    <p class="small">Issue: {{ count($preview['report']['issues'] ?? []) }} · Error: {{ $errorCount }}</p>
    @if ($errorCount === 0)
        <form method="POST" action="{{ route('imports.apply') }}">
            @csrf
            <input type="hidden" name="project" value="{{ $preview['project_id'] }}">
            <input type="hidden" name="token" value="{{ $preview['token'] }}">
            <button type="submit" class="btn">Terapkan Import</button>
        </form>
    @else
        <p class="flash flash--error" role="alert">Import memiliki error. Perbaiki sumber data lalu jalankan dry run ulang.</p>
    @endif
</section>
@endif

<section class="card section" aria-labelledby="issues-h">
    <div class="card-title">
        <h2 id="issues-h">Isu Terbuka</h2>
        <span class="hint">{{ $issues->count() }} isu ditampilkan</span>
    </div>
    @if ($issues->isEmpty())
        <div class="empty">Tidak ada isu terbuka.</div>
    @else
        <div class="table-wrap"><table><thead><tr><th>Severity</th><th>Proyek</th><th>Kode</th><th>Pesan</th><th>Sumber</th><th></th></tr></thead><tbody>
            @foreach ($issues as $issue)
                <tr>
                    <td><span class="badge badge--{{ $issue->severity }}">{{ strtoupper($issue->severity) }}</span></td>
                    <td class="small">{{ $issue->project?->name }}</td>
                    <td class="small">{{ $issue->code }}</td>
                    <td>{{ $issue->message }}</td>
                    <td class="small muted">{{ $issue->source_tab }}{{ $issue->source_row ? ' baris ' . $issue->source_row : '' }}</td>
                    <td class="small">
                        @if (config('marison.import_conflicts_enabled') && in_array($issue->project?->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true))
                            <a class="btn btn--small btn--secondary" href="{{ route('import-conflicts.index', $issue->project) }}">Tinjau konflik</a>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody></table></div>
    @endif
</section>

<section class="card section" aria-labelledby="batches-h">
    <div class="card-title"><h2 id="batches-h">50 Batch Terakhir</h2></div>
    @if ($batches->isEmpty())
        <div class="empty">Belum ada batch impor.</div>
    @else
        <div class="table-wrap"><table><thead><tr><th>Batch</th><th>Proyek</th><th>Mode</th><th>Sumber</th><th>Status</th><th>Mulai</th><th>Selesai</th><th></th></tr></thead><tbody>
            @foreach ($batches as $batch)
                <tr>
                    <td>#{{ $batch->id }}</td><td>{{ $batch->project?->name }}</td><td>{{ strtoupper($batch->mode) }}</td><td class="small">{{ $batch->source }}</td>
                    <td><span class="badge badge--{{ strtolower($batch->status) }}">{{ $batch->status }}</span></td><td class="small">{{ $batch->started_at?->format('d M Y H:i') }}</td><td class="small">{{ $batch->finished_at?->format('H:i') }}</td>
                    <td><a class="btn btn--small btn--secondary" href="{{ route('imports.show', $batch) }}">Detail</a></td>
                </tr>
            @endforeach
        </tbody></table></div>
    @endif
</section>
@endsection
