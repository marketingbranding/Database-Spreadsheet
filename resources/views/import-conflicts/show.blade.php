@extends('layouts.app')

@section('title', 'Konflik Impor #' . $conflict->id)
@section('subtitle', $project->name . ' · ' . $conflict->case_type)

@section('content')
<section class="card section" aria-labelledby="detail-h">
    <div class="card-title">
        <h2 id="detail-h">Bukti Konflik</h2>
        <span class="badge badge--warning">{{ $conflict->status }}</span>
    </div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Kavling</dt><dd>{{ $conflict->lots->pluck('code')->join(', ') ?: '—' }}</dd>
        <dt>Siklus booking</dt>
        <dd>
            @foreach ($conflict->bookingLinks as $link)
                #{{ $link->booking_id }} — {{ $link->role }} — {{ $link->booking?->status }} / {{ $link->booking?->current_stage }}
                @if (! $loop->last)<br>@endif
            @endforeach
        </dd>
        <dt>Sumber</dt><dd>{{ $conflict->source_tab }} baris {{ $conflict->source_row }} · {{ $conflict->source_key }}</dd>
        <dt>Sidik jari sumber</dt><dd class="small">{{ $conflict->source_fingerprint }}</dd>
        <dt>Nilai Sheets</dt><dd><pre class="pre-wrap">{{ json_encode($conflict->source_value ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></dd>
        <dt>Nilai Laravel</dt><dd><pre class="pre-wrap">{{ json_encode($conflict->laravel_value ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></dd>
        <dt>Dampak inventaris</dt><dd>{{ data_get($conflict->inventory_impact ?? [], 'summary', '—') }}</dd>
    </dl>

    @if ($conflict->case_type === App\Models\ImportConflict::CASE_SIGNED_DOCUMENT)
        <p class="notice"><strong>PERBAIKAN DOKUMEN TERTANDA:</strong> PPJB, Akad, atau BAST terlibat. Perubahan dokumen tertanda tidak diizinkan; hanya bukti dan eskalasi HQ.</p>
    @endif
    @if ($conflict->case_type === App\Models\ImportConflict::CASE_BANK_REJECT)
        <p class="notice">Kasus bank dialihkan ke alur keputusan booking yang sudah ada.</p>
        @foreach ($conflict->bookingLinks as $link)
            <p><a class="btn btn--small btn--secondary" href="{{ route('bookings.show', $link->booking) }}">Buka keputusan booking #{{ $link->booking_id }}</a></p>
        @endforeach
    @endif
</section>

@if (auth()->user()?->role === App\Models\User::ROLE_BRANCH_ADMIN && in_array($conflict->status, [App\Models\ImportConflict::STATUS_OPEN, App\Models\ImportConflict::STATUS_PROPOSED, App\Models\ImportConflict::STATUS_ESCALATED], true))
    <section class="card section" aria-labelledby="propose-h">
        <div class="card-title"><h2 id="propose-h">Ajukan Resolusi</h2></div>
        <form method="POST" action="{{ route('import-conflicts.proposal.store', [$project, $conflict]) }}" class="form-grid">
            @csrf
            <label>Aksi
                <select name="action" required>
                    <option value="CONFIRM_LARAVEL">Konfirmasi nilai Laravel</option>
                    <option value="CORRECT_LARAVEL">Koreksi Laravel</option>
                    <option value="LINK_BI">Tautkan BI</option>
                    <option value="ROUTE_BOOKING_DECISION">Alihkan ke keputusan booking</option>
                    <option value="ESCALATE_SIGNED_DOCUMENT">Eskalasi dokumen tertanda</option>
                </select>
            </label>
            <label>Alasan<textarea name="reason" required maxlength="2000">{{ old('reason') }}</textarea></label>
            <label>Bukti<textarea name="evidence" required maxlength="4000">{{ old('evidence') }}</textarea></label>
            <label>Referensi sumber<input name="source_reference" maxlength="255" value="{{ old('source_reference') }}"></label>
            <label>Kunci idempotensi<input name="idempotency_key" required maxlength="255" value="{{ old('idempotency_key') }}"></label>
            <label>Booking target (BI)<input name="proposed_values[booking_id]" type="number"></label>
            <label>Holder baru (koreksi)<input name="proposed_values[current_booking_id]" type="number"></label>
            <label>Status booking lain (koreksi)<input name="proposed_values[dispositions][{{ $conflict->bookingLinks->first()?->booking_id ?? 0 }}]" placeholder="MUNDUR atau BATAL"></label>
            <button type="submit" class="btn">Kirim usulan</button>
        </form>
    </section>
@endif

@if (auth()->user()?->isHq())
    <section class="card section" aria-labelledby="review-h">
        <div class="card-title"><h2 id="review-h">Peninjauan HQ</h2></div>
        @forelse ($conflict->proposals->where('status', App\Models\ImportConflictProposal::STATUS_PENDING) as $proposal)
            <article class="section">
                <h3>Usulan #{{ $proposal->id }} — {{ $proposal->action }}</h3>
                <p>{{ $proposal->proposer?->name }} · {{ $proposal->reason }}</p>
                <p class="small muted">Status: {{ $proposal->status }} · Pengirim: {{ $proposal->proposer?->name }} · Penyetuju: {{ $proposal->approver?->name ?? '—' }}</p>
                <pre class="pre-wrap">{{ $proposal->evidence }}</pre>
                <form method="POST" action="{{ route('import-conflicts.proposal.approve', [$project, $conflict, $proposal]) }}" class="form-inline">
                    @csrf
                    <input name="approval_reason" placeholder="Alasan persetujuan" required maxlength="2000">
                    <button type="submit" class="btn">Setujui</button>
                </form>
                <form method="POST" action="{{ route('import-conflicts.proposal.reject', [$project, $conflict, $proposal]) }}" class="form-inline">
                    @csrf
                    <input name="approval_reason" placeholder="Alasan penolakan" required maxlength="2000">
                    <button type="submit" class="btn btn--secondary">Tolak</button>
                </form>
            </article>
        @empty
            <div class="empty">Tidak ada usulan yang menunggu keputusan HQ.</div>
        @endforelse
    </section>
@endif

<section class="card section" aria-labelledby="events-h">
    <div class="card-title"><h2 id="events-h">Riwayat Audit</h2></div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        @forelse ($conflict->events as $event)
            <dt>{{ $event->created_at?->format('d M Y H:i') }}</dt>
            <dd>{{ $event->event_type }} — {{ $event->actor?->name }} — {{ $event->reason }}</dd>
        @empty
            <dt>—</dt><dd>Belum ada peristiwa.</dd>
        @endforelse
    </dl>
</section>
@endsection
