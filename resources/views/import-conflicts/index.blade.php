@extends('layouts.app')

@section('title', 'Konflik Impor')
@section('subtitle', $project->name . ' · ' . $project->code)

@section('content')
<section class="card section" aria-labelledby="conflicts-h">
    <div class="card-title">
        <h2 id="conflicts-h">Daftar Konflik Impor</h2>
        <span class="hint">{{ $conflicts->count() }} konflik terbuka</span>
    </div>

    @if ($conflicts->isEmpty())
        <div class="empty">Tidak ada konflik impor yang perlu ditinjau.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Kasus</th>
                        <th>Kavling</th>
                        <th>Sumber</th>
                        <th>Dampak inventaris</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($conflicts as $conflict)
                        <tr>
                            <td class="small">{{ $conflict->case_type }}</td>
                            <td>{{ $conflict->lots->pluck('code')->join(', ') ?: '—' }}</td>
                            <td class="small muted">{{ $conflict->source_tab }}:{{ $conflict->source_row }}</td>
                            <td class="small">{{ data_get($conflict->inventory_impact ?? [], 'summary', '—') }}</td>
                            <td><span class="badge badge--warning">{{ $conflict->status }}</span></td>
                            <td>
                                <a class="btn btn--small btn--secondary" href="{{ route('import-conflicts.show', [$project, $conflict]) }}">Detail</a>
                                @if ($conflict->proposals->contains('status', App\Models\ImportConflictProposal::STATUS_PENDING))
                                    <span class="badge badge--info">Setujui / tolak usulan</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
