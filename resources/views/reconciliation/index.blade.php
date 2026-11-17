@extends('layouts.app')

@section('title', 'Rekonsiliasi')
@section('subtitle', 'Cocokkan data impor dengan tab Ringkasan Transaksi dan hq_report_export')

@section('content')
<div class="card">
    <div class="card-title"><h2>Pilih Proyek</h2></div>
    @if ($projects->isEmpty())
        <div class="empty">Belum ada proyek yang ditugaskan.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Proyek</th>
                        <th>Cabang</th>
                        <th>Batch Terakhir</th>
                        <th class="num">Isu Terbuka</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projects as $project)
                        <tr>
                            <td><strong>{{ $project->name }}</strong><div class="small muted">{{ $project->code }}</div></td>
                            <td>{{ $project->branch?->name }}</td>
                            <td class="small">
                                @if ($project->importBatches->first())
                                    {{ $project->importBatches->first()->finished_at?->format('d M Y H:i') }}
                                    <span class="badge badge--{{ strtolower($project->importBatches->first()->status) }}">{{ $project->importBatches->first()->status }}</span>
                                @else
                                    <span class="muted">belum ada</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format((int) ($openIssues[$project->id] ?? 0)) }}</td>
                            <td><a class="btn btn--small btn--secondary" href="{{ route('reconciliation.show', $project) }}">Buka</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
