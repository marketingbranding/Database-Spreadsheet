@extends('layouts.app')

@section('title', 'Pilih Proyek')
@section('subtitle', $stageLabel . ' · pilih proyek untuk melanjutkan')

@section('content')
<section class="card section" aria-labelledby="stage-select-heading">
    <div class="card-title">
        <h2 id="stage-select-heading">Pilih proyek untuk {{ $stageLabel }}</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Proyek</th><th>Cabang</th><th>Mode</th><th></th></tr></thead>
            <tbody>
            @foreach ($projects as $entry)
                @php($project = $entry['project'])
                <tr>
                    <td>{{ $project->name }} <span class="hint">{{ $project->code }}</span></td>
                    <td>{{ $project->branch?->name ?? '—' }}</td>
                    <td>
                        @if ($entry['available'])
                            <span class="badge badge--success">Laravel</span>
                        @else
                            <span class="badge badge--info">{{ $project->management_mode === \App\Models\Project::MANAGEMENT_SHEETS ? 'Sheets' : 'Laravel' }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($entry['available'])
                            <a class="btn btn--small" href="{{ $entry['url'] }}">Buka {{ $stageLabel }}</a>
                        @else
                            <span class="hint">{{ $entry['note'] }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
