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
        <button type="button" class="sidebar-backdrop" aria-label="Tutup menu navigasi" tabindex="-1"></button>
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
                @if ($canViewConflicts)
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
                    <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="sidebar-nav">Buka menu navigasi</button>
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

    <script>
        (function () {
            var body = document.body;
            var toggle = document.querySelector('.menu-toggle');
            var backdrop = document.querySelector('.sidebar-backdrop');
            var sidebar = document.querySelector('.sidebar');
            var nav = document.getElementById('sidebar-nav');
            var returnFocus = toggle;
            if (!toggle || !backdrop || !sidebar || !nav) return;

            function setOpen(open) {
                body.setAttribute('data-nav-open', open ? 'true' : 'false');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            function close(restoreFocus) {
                setOpen(false);
                if (restoreFocus && sidebar.contains(document.activeElement)) {
                    // Restore focus to the toggle so keyboard users stay oriented.
                    returnFocus.focus();
                }
            }

            setOpen(false);
            toggle.addEventListener('click', function () {
                if (body.getAttribute('data-nav-open') === 'true') {
                    close(false);
                    return;
                }
                returnFocus = document.activeElement || toggle;
                setOpen(true);
                // Focus first nav link so Tab continues inside the drawer.
                var first = nav.querySelector('a');
                if (first) first.focus();
            });
            backdrop.addEventListener('click', function () { close(true); });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') close(true);
            });
            document.querySelectorAll('.sidebar nav a').forEach(function (link) {
                link.addEventListener('click', function () { setOpen(false); });
            });
        })();
    </script>
</body>
</html>
