{{-- Shared scope controls: branch, project, period. Pages wrap this in their own GET <form>. --}}
@php
    $filters = $filters ?? [];
    $showPeriod = $showPeriod ?? true;
    $activePeriod = $filters['period'] ?? 'ytd';
    $activeFrom = request('from', '');
    $activeTo = request('to', '');
    $monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $activePeriodLabel = match ($activePeriod) {
        'all' => 'Semua waktu',
        '2025', '2026' => 'Tahun ' . $activePeriod,
        'custom' => 'Kustom',
        default => 'Tahun berjalan',
    };
    if ($activePeriod === 'custom' && $activeFrom && $activeTo && preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $activeFrom, $fromParts) && preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $activeTo, $toParts)) {
        $activePeriodLabel = $activeFrom === $activeTo
            ? $activeFrom
            : ($fromParts[1] === $toParts[1] && $fromParts[2] === $toParts[2]
                ? $monthNames[(int) $fromParts[2] - 1] . ' ' . $fromParts[1]
                : $activeFrom . ' – ' . $activeTo);
    }
    $periodYear = now()->year;
    $activeYear = $activeFrom && preg_match('/^(\d{4})-/', $activeFrom, $yearParts) ? (int) $yearParts[1] : ($activePeriod === '2025' || $activePeriod === '2026' ? (int) $activePeriod : $periodYear);
@endphp

<label class="filter-field">
    <span>Cabang</span>
    <select name="branch">
        <option value="">Semua cabang</option>
        @foreach ($filters['branches'] ?? collect() as $branch)
            <option value="{{ $branch->code }}" @selected(($filters['branch'] ?? null) === $branch->code)>{{ $branch->name }}</option>
        @endforeach
    </select>
</label>

<label class="filter-field">
    <span>Proyek</span>
    <select name="project">
        <option value="">Semua proyek</option>
        @foreach ($filters['projects'] ?? collect() as $project)
            <option value="{{ $project->code }}" @selected(($filters['project'] ?? null) === $project->code)>{{ $project->name }}</option>
        @endforeach
    </select>
</label>

@if ($showPeriod)
    <div class="filter-field period-filter" data-period-filter data-active-period="{{ $activePeriod }}" data-active-from="{{ $activeFrom }}" data-active-to="{{ $activeTo }}" data-current-year="{{ $periodYear }}">
        <span>Periode</span>
        <button type="button" class="period-filter__trigger" data-period-trigger aria-expanded="false" aria-controls="period-popover">
            <span data-period-label>{{ $activePeriodLabel }}</span>
            <span aria-hidden="true">⌄</span>
        </button>
        <input type="hidden" name="period" value="{{ $activePeriod }}" data-period-value>
        <input type="hidden" name="from" value="{{ $activeFrom }}" data-from-value>
        <input type="hidden" name="to" value="{{ $activeTo }}" data-to-value>

        <section id="period-popover" class="period-filter__popover" data-period-popover hidden aria-label="Pilih periode">
            <div class="period-filter__tabs" role="tablist" aria-label="Jenis periode">
                <button type="button" role="tab" aria-selected="true" aria-controls="period-monthly" data-period-tab="monthly">Bulanan</button>
                <button type="button" role="tab" aria-selected="false" aria-controls="period-quarterly" data-period-tab="quarterly">Kuartalan</button>
                <button type="button" role="tab" aria-selected="false" aria-controls="period-custom" data-period-tab="custom">Custom</button>
            </div>

            <div class="period-filter__panel" id="period-monthly" role="tabpanel" data-period-panel="monthly">
                <label class="period-filter__year">
                    <span>Tahun</span>
                    <select data-period-year>
                        @for ($year = $periodYear - 2; $year <= $periodYear + 1; $year++)
                            <option value="{{ $year }}" @selected($year === $activeYear)>{{ $year }}</option>
                        @endfor
                    </select>
                </label>
                <div class="period-filter__month-grid">
                    @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $month)
                        <button type="button" data-month="{{ $loop->iteration }}">{{ $month }}</button>
                    @endforeach
                </div>
            </div>

            <div class="period-filter__panel" id="period-quarterly" role="tabpanel" data-period-panel="quarterly" hidden>
                <label class="period-filter__year">
                    <span>Tahun</span>
                    <select data-period-quarter-year>
                        @for ($year = $periodYear - 2; $year <= $periodYear + 1; $year++)
                            <option value="{{ $year }}" @selected($year === $activeYear)>{{ $year }}</option>
                        @endfor
                    </select>
                </label>
                <div class="period-filter__quarter-grid">
                    <button type="button" data-quarter="1"><strong>Q1</strong><span>Januari – Maret</span></button>
                    <button type="button" data-quarter="2"><strong>Q2</strong><span>April – Juni</span></button>
                    <button type="button" data-quarter="3"><strong>Q3</strong><span>Juli – September</span></button>
                    <button type="button" data-quarter="4"><strong>Q4</strong><span>Oktober – Desember</span></button>
                </div>
            </div>

            <div class="period-filter__panel period-filter__custom" id="period-custom" role="tabpanel" data-period-panel="custom" hidden>
                <div class="period-filter__range-picker" data-range-picker>
                    <div class="period-filter__range-fields" aria-label="Tanggal periode">
                        <div class="period-filter__range-field">
                            <span>Dari tanggal</span>
                            <strong data-range-from>{{ $activeFrom ? date('d/m/Y', strtotime($activeFrom)) : 'Pilih tanggal' }}</strong>
                        </div>
                        <div class="period-filter__range-field">
                            <span>Sampai tanggal</span>
                            <strong data-range-to>{{ $activeTo ? date('d/m/Y', strtotime($activeTo)) : 'Pilih tanggal' }}</strong>
                        </div>
                    </div>
                    <div class="period-filter__calendar-toolbar">
                        <button type="button" class="period-filter__calendar-nav" data-range-prev aria-label="Bulan sebelumnya">‹</button>
                        <span data-range-months-label aria-live="polite"></span>
                        <button type="button" class="period-filter__calendar-nav" data-range-next aria-label="Bulan berikutnya">›</button>
                    </div>
                    <div class="period-filter__calendars" data-range-months></div>
                    <p class="period-filter__range-error" data-range-error role="alert" hidden></p>
                </div>
            </div>

            <div class="period-filter__summary">
                <div>
                    <small>Periode dipilih</small>
                    <strong data-period-summary>{{ $activePeriodLabel }}</strong>
                    <span data-period-range>{{ $activeFrom && $activeTo ? $activeFrom . ' – ' . $activeTo : '' }}</span>
                </div>
                <div class="period-filter__actions">
                    <button type="button" class="btn btn--secondary" data-period-cancel>Batal</button>
                    <button type="button" class="btn" data-period-apply>Terapkan</button>
                </div>
            </div>
        </section>
    </div>
@endif

<div class="filter-actions">
    <button type="submit" class="btn">Terapkan</button>
    <a class="btn btn--secondary" href="{{ request()->url() }}">Reset</a>
</div>

@if ($showPeriod)
<script>
(function () {
    document.querySelectorAll('[data-period-filter]').forEach(function (root) {
        var form = root.closest('form');
        var trigger = root.querySelector('[data-period-trigger]');
        var popover = root.querySelector('[data-period-popover]');
        var label = root.querySelector('[data-period-label]');
        var summary = root.querySelector('[data-period-summary]');
        var range = root.querySelector('[data-period-range]');
        var periodValue = root.querySelector('[data-period-value]');
        var fromValue = root.querySelector('[data-from-value]');
        var toValue = root.querySelector('[data-to-value]');
        var rangePicker = root.querySelector('[data-range-picker]');
        var rangeFrom = root.querySelector('[data-range-from]');
        var rangeTo = root.querySelector('[data-range-to]');
        var rangeMonths = root.querySelector('[data-range-months]');
        var rangeMonthsLabel = root.querySelector('[data-range-months-label]');
        var rangeError = root.querySelector('[data-range-error]');
        var monthYear = root.querySelector('[data-period-year]');
        var quarterYear = root.querySelector('[data-period-quarter-year]');
        var months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        var initial = {
            period: root.dataset.activePeriod || 'ytd',
            from: root.dataset.activeFrom || '',
            to: root.dataset.activeTo || ''
        };
        var draft = { period: initial.period, from: initial.from, to: initial.to };
        var draftTab = initial.period === 'custom' ? 'custom' : 'monthly';
        var viewStart = { year: initial.from ? Number(initial.from.slice(0, 4)) : Number(root.dataset.currentYear), month: initial.from ? Number(initial.from.slice(5, 7)) : 1 };
        viewStart.month = viewStart.month || 1;

        function pad(value) { return String(value).padStart(2, '0'); }
        function dateValue(year, month, day) { return year + '-' + pad(month) + '-' + pad(day); }
        function monthEnd(year, month) { return new Date(year, month, 0).getDate(); }
        function shiftMonth(year, month, offset) {
            var date = new Date(Date.UTC(year, month - 1 + offset, 1));
            return { year: date.getUTCFullYear(), month: date.getUTCMonth() + 1 };
        }
        function compareDates(left, right) { return left < right ? -1 : left > right ? 1 : 0; }
        function monthLabel(year, month) { return months[month - 1] + ' ' + year; }
        function calendarDateLabel(value) { return formatDate(value); }
        function renderRangePicker() {
            if (!rangePicker) return;
            var second = shiftMonth(viewStart.year, viewStart.month, 1);
            rangeMonthsLabel.textContent = monthLabel(viewStart.year, viewStart.month) + ' / ' + monthLabel(second.year, second.month);
            rangeMonths.innerHTML = [viewStart, second].map(function (month) { return renderCalendar(month.year, month.month); }).join('');
            rangeFrom.textContent = draft.from ? calendarDateLabel(draft.from) : 'Pilih tanggal';
            rangeTo.textContent = draft.to ? calendarDateLabel(draft.to) : 'Pilih tanggal';
            rangePicker.querySelectorAll('[data-range-date]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.stopPropagation();
                    var value = button.dataset.rangeDate;
                    if (!draft.from || (draft.from && draft.to)) {
                        draft = { period: 'custom', from: value, to: '' };
                    } else if (compareDates(value, draft.from) < 0) {
                        draft = { period: 'custom', from: value, to: '' };
                    } else {
                        draft = { period: 'custom', from: draft.from, to: value };
                    }
                    rangeError.hidden = true;
                    renderDraft();
                });
            });
        }
        function renderCalendar(year, month) {
            var firstDay = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
            var days = monthEnd(year, month);
            var html = '<div class="period-filter__calendar" role="group" aria-label="' + monthLabel(year, month) + '"><strong class="period-filter__calendar-title">' + monthLabel(year, month) + '</strong><div class="period-filter__weekdays" aria-hidden="true"><span>Mg</span><span>Sn</span><span>Sl</span><span>Rb</span><span>Km</span><span>Jm</span><span> Sb</span></div><div class="period-filter__days">';
            for (var index = 0; index < firstDay; index++) html += '<span class="period-filter__day period-filter__day--empty"></span>';
            for (var day = 1; day <= days; day++) {
                var value = dateValue(year, month, day);
                var selected = value === draft.from || value === draft.to;
                var inRange = draft.from && draft.to && compareDates(value, draft.from) >= 0 && compareDates(value, draft.to) <= 0;
                html += '<button type="button" class="period-filter__day' + (selected ? ' is-selected' : '') + (inRange ? ' is-in-range' : '') + '" data-range-date="' + value + '" aria-label="' + calendarDateLabel(value) + '" aria-pressed="' + (selected ? 'true' : 'false') + '">' + day + '</button>';
            }
            return html + '</div></div>';
        }
        function formatDate(value) {
            if (!value) return '';
            var parts = value.split('-');
            return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
        }
        function draftLabel() {
            if (draft.period === 'all') return 'Semua waktu';
            if (draft.period === 'ytd') return 'Tahun berjalan';
            if (draft.period === '2025' || draft.period === '2026') return 'Tahun ' + draft.period;
            if (draft.from && draft.to) {
                var from = draft.from.split('-');
                var to = draft.to.split('-');
                if (from.length === 3 && to.length === 3 && from[0] === to[0] && from[1] === to[1] && from[2] === '01' && Number(to[2]) === monthEnd(Number(to[0]), Number(to[1]))) {
                    return months[Number(from[1]) - 1] + ' ' + from[0];
                }
                return formatDate(draft.from) + ' – ' + formatDate(draft.to);
            }
            return 'Custom';
        }
        function setTab(tab) {
            draftTab = tab;
            root.querySelectorAll('[data-period-tab]').forEach(function (button) {
                var active = button.dataset.periodTab === tab;
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            root.querySelectorAll('[data-period-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.periodPanel !== tab;
            });
        }
        function markSelected() {
            var selectedFrom = draft.from;
            var selectedTo = draft.to;
            root.querySelectorAll('[data-month]').forEach(function (button) {
                var month = Number(button.dataset.month);
                var year = Number(monthYear.value);
                button.classList.toggle('is-selected', selectedFrom === dateValue(year, month, 1) && selectedTo === dateValue(year, month, monthEnd(year, month)));
                button.setAttribute('aria-pressed', button.classList.contains('is-selected') ? 'true' : 'false');
            });
            root.querySelectorAll('[data-quarter]').forEach(function (button) {
                var quarter = Number(button.dataset.quarter);
                var year = Number(quarterYear.value);
                var startMonth = (quarter - 1) * 3 + 1;
                button.classList.toggle('is-selected', selectedFrom === dateValue(year, startMonth, 1) && selectedTo === dateValue(year, startMonth + 2, monthEnd(year, startMonth + 2)));
                button.setAttribute('aria-pressed', button.classList.contains('is-selected') ? 'true' : 'false');
            });
        }
        function renderDraft() {
            summary.textContent = draftLabel();
            range.textContent = draft.from && draft.to ? formatDate(draft.from) + ' – ' + formatDate(draft.to) : '';
            renderRangePicker();
            markSelected();
        }
        function open() {
            draft = { period: periodValue.value, from: fromValue.value, to: toValue.value };
            draftTab = draft.period === 'custom' ? 'custom' : 'monthly';
            setTab(draftTab);
            renderDraft();
            popover.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }
        function close() {
            popover.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }
        trigger.addEventListener('click', function () { popover.hidden ? open() : close(); });
        root.querySelectorAll('[data-period-tab]').forEach(function (button) {
            button.addEventListener('click', function () { setTab(button.dataset.periodTab); });
        });
        root.querySelectorAll('[data-month]').forEach(function (button) {
            button.addEventListener('click', function () {
                var year = Number(monthYear.value);
                var month = Number(button.dataset.month);
                draft = { period: 'custom', from: dateValue(year, month, 1), to: dateValue(year, month, monthEnd(year, month)) };
                renderDraft();
            });
        });
        root.querySelectorAll('[data-quarter]').forEach(function (button) {
            button.addEventListener('click', function () {
                var year = Number(quarterYear.value);
                var quarter = Number(button.dataset.quarter);
                var startMonth = (quarter - 1) * 3 + 1;
                draft = { period: 'custom', from: dateValue(year, startMonth, 1), to: dateValue(year, startMonth + 2, monthEnd(year, startMonth + 2)) };
                renderDraft();
            });
        });
        monthYear.addEventListener('change', markSelected);
        quarterYear.addEventListener('change', markSelected);
        if (rangePicker) {
            rangePicker.querySelector('[data-range-prev]').addEventListener('click', function () {
                viewStart = shiftMonth(viewStart.year, viewStart.month, -1);
                renderRangePicker();
            });
            rangePicker.querySelector('[data-range-next]').addEventListener('click', function () {
                viewStart = shiftMonth(viewStart.year, viewStart.month, 1);
                renderRangePicker();
            });
        }
        root.querySelector('[data-period-cancel]').addEventListener('click', close);
        root.querySelector('[data-period-apply]').addEventListener('click', function () {
            if (draftTab === 'custom' && (!draft.from || !draft.to)) {
                rangeError.textContent = 'Pilih tanggal mulai dan tanggal akhir.';
                rangeError.hidden = false;
                return;
            }
            periodValue.value = draft.period;
            fromValue.value = draft.from;
            toValue.value = draft.to;
            label.textContent = draftLabel();
            close();
            if (form && form.requestSubmit) form.requestSubmit();
        });
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target) && !popover.hidden) close();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !popover.hidden) { close(); trigger.focus(); }
        });
        setTab(draftTab);
        renderDraft();
    });
})();
</script>
@endif
