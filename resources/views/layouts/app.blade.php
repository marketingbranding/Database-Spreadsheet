<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Marison Database')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <a class="skip-link" href="#main">Ke konten utama</a>

    <div class="shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                Marison Database
                <span>Multi-Branch</span>
            </div>

            <nav id="sidebar-nav" aria-label="Navigasi utama">
                <div class="nav-group-label">Umum</div>
                <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Dashboard</a>
                <a href="{{ route('projects.index') }}" @class(['active' => request()->routeIs('projects.*')])>Proyek</a>
                <a href="{{ route('inventory.index') }}" @class(['active' => request()->routeIs('inventory.*')])>Stok Kavling</a>

                <div class="nav-group-label">Tahapan Operasional</div>
                @php($stageRoutes = ['psjb' => 'psjb.index', 'bi' => 'bi.index', 'pemberkasan' => 'pemberkasan.index', 'bank' => 'bank.index', 'ppjb' => 'ppjb.index', 'akad' => 'akad.index', 'bast' => 'bast.index'])
                @php($stageLabels = ['psjb' => 'PSJB / UTJ', 'bi' => 'BI Checking', 'pemberkasan' => 'Pemberkasan', 'bank' => 'Proses Bank', 'ppjb' => 'TTD PPJB', 'akad' => 'Akad', 'bast' => 'BAST'])
                @foreach ($stageRoutes as $stageKey => $stageRoute)
                    @if ($stageProject)
                        <a href="{{ route($stageRoute, $stageProject) }}" @class(['active' => request()->routeIs(str_replace('.index', '.*', $stageRoute))])>{{ $stageLabels[$stageKey] }}@if (($stageCounts[$stageKey] ?? 0) > 0)<span class="nav-badge">{{ $stageCounts[$stageKey] }}</span>@endif</a>
                    @else
                        <a href="{{ route('stages.select', ['stage' => $stageKey]) }}">{{ $stageLabels[$stageKey] }}</a>
                    @endif
                @endforeach

                <div class="nav-group-label">Data</div>
                <a href="{{ route('bookings.index') }}" @class(['active' => request()->routeIs('bookings.*')])>Semua Booking</a>
                @if ($stageProject && config('marison.import_conflicts_enabled'))
                    <a href="{{ route('import-conflicts.index', $stageProject) }}" @class(['active' => request()->routeIs('import-conflicts.*')])>Konflik Data</a>
                @endif
                <a href="{{ route('reconciliation.index') }}" @class(['active' => request()->routeIs('reconciliation.*')])>Rekonsiliasi</a>
                <a href="{{ route('imports.index') }}" @class(['active' => request()->routeIs('imports.*')])>Riwayat Impor</a>
            </nav>

            <div class="sidebar-user">
                <div>
                    <div class="user-name">{{ auth()->user()->name }}</div>
                    <div class="user-meta">
                        {{ auth()->user()->isHq() ? 'Pusat (HQ)' : (auth()->user()->branch?->name ?? 'Admin Cabang') }}
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Keluar</button>
                </form>
            </div>
        </aside>

        <div class="content">
            <header class="topbar">
                <div>
                    <h1>@yield('title')</h1>
                    <div class="subtitle">@yield('subtitle')</div>
                </div>
                <div class="topbar-actions">@yield('toolbar')</div>
            </header>

            @if (session('status'))
                <div class="flash" role="status">{{ session('status') }}</div>
            @endif

            <main id="main" class="page">
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
