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

            <nav aria-label="Navigasi utama">
                <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Dashboard</a>
                <a href="{{ route('projects.index') }}" @class(['active' => request()->routeIs('projects.*')])>Proyek</a>
                <a href="{{ route('inventory.index') }}" @class(['active' => request()->routeIs('inventory.*')])>Stok Kavling</a>
                <a href="{{ route('bookings.index') }}" @class(['active' => request()->routeIs('bookings.*')])>Booking</a>
                @if (request()->route('project'))
                    <a href="{{ route('bi.index', request()->route('project')) }}" @class(['active' => request()->routeIs('bi.*')])>BI Checking</a>
                    <a href="{{ route('pemberkasan.index', request()->route('project')) }}" @class(['active' => request()->routeIs('pemberkasan.*')])>Pemberkasan</a>
                    @if (request()->route('project') instanceof \App\Models\Project && request()->route('project')->management_mode === \App\Models\Project::MANAGEMENT_LARAVEL && in_array(request()->route('project')->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true))
                        <a href="{{ route('bank.index', request()->route('project')) }}" @class(['active' => request()->routeIs('bank.*')])>Proses Bank</a>
                        <a href="{{ route('ppjb.index', request()->route('project')) }}" @class(['active' => request()->routeIs('ppjb.*')])>TTD PPJB</a>
                    @endif
                @endif
                <a href="{{ route('reconciliation.index') }}" @class(['active' => request()->routeIs('reconciliation.*')])>Rekonsiliasi</a>
                <a href="{{ route('imports.index') }}" @class(['active' => request()->routeIs('imports.*')])>Impor</a>
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
