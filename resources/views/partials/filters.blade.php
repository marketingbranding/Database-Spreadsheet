{{-- Shared scope controls: branch, project, period. Pages wrap this in their own GET <form>. --}}
@php
    $filters = $filters ?? [];
    $showPeriod = $showPeriod ?? true;
@endphp

<label class="filter-field">
    <span>Cabang</span>
    <select name="branch" onchange="this.form.submit()">
        <option value="">Semua cabang</option>
        @foreach ($filters['branches'] ?? collect() as $branch)
            <option value="{{ $branch->code }}" @selected(($filters['branch'] ?? null) === $branch->code)>{{ $branch->name }}</option>
        @endforeach
    </select>
</label>

<label class="filter-field">
    <span>Proyek</span>
    <select name="project" onchange="this.form.submit()">
        <option value="">Semua proyek</option>
        @foreach ($filters['projects'] ?? collect() as $project)
            <option value="{{ $project->code }}" @selected(($filters['project'] ?? null) === $project->code)>{{ $project->name }}</option>
        @endforeach
    </select>
</label>

@if ($showPeriod)
    <label class="filter-field">
        <span>Periode</span>
        <select name="period" onchange="this.form.submit()">
            <option value="ytd" @selected(($filters['period'] ?? 'ytd') === 'ytd')>Tahun berjalan</option>
            <option value="2026" @selected(($filters['period'] ?? '') === '2026')>2026</option>
            <option value="2025" @selected(($filters['period'] ?? '') === '2025')>2025</option>
            <option value="all" @selected(($filters['period'] ?? '') === 'all')>Semua waktu</option>
            <option value="custom" @selected(($filters['period'] ?? '') === 'custom')>Kustom</option>
        </select>
    </label>

    <label class="filter-field">
        <span>Dari</span>
        <input type="date" name="from" value="{{ request('from') }}">
    </label>

    <label class="filter-field">
        <span>Sampai</span>
        <input type="date" name="to" value="{{ request('to') }}">
    </label>
@endif

<noscript><button type="submit" class="btn btn--secondary">Terapkan</button></noscript>
