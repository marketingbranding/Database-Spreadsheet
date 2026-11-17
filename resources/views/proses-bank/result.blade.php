@extends('layouts.app')

@section('title', 'Hasil Bank ' . ($submission->booking?->lot?->code ?? $submission->booking_id))
@section('subtitle', $project->name . ' · Submission #' . $submission->id)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="card section" aria-labelledby="bank-result-heading">
    <div class="card-title">
        <h2 id="bank-result-heading">Hasil Bank — {{ $submission->booking?->lot?->code }}</h2>
        <span class="badge">{{ $submission->result ?? 'BELUM ADA HASIL' }}</span>
    </div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Submission</dt><dd>Submission #{{ $submission->id }}</dd>
        <dt>Pemberkasan</dt><dd>#{{ $submission->pemberkasan_id }} · {{ $submission->file_number ?? '—' }} · revisi {{ $submission->pemberkasan?->revision ?? '—' }}</dd>
        <dt>Booking</dt><dd>Booking #{{ $submission->booking_id }} · {{ $submission->booking?->buyer?->name }}</dd>
        <dt>Bank / unit</dt><dd>{{ trim((string) $submission->bank . ' ' . (string) $submission->bank_unit) ?: '—' }}</dd>
        <dt>Tanggal pengajuan</dt><dd>{{ $submission->submitted_date?->format('d M Y') ?? '—' }}</dd>
    </dl>

    <form class="form-grid" method="POST" action="{{ route('bank.result.store', [$project, $submission]) }}">
        @csrf
        <input type="hidden" name="booking_id" value="{{ $submission->booking_id }}">
        <input type="hidden" name="project_id" value="{{ $project->id }}">
        <input type="hidden" name="pemberkasan_id" value="{{ $submission->pemberkasan_id }}">

        <label class="auth-field"><span>Hasil *</span><select class="input" name="result" required>
            <option value="APPROVE" @selected(old('result', $submission->result) === 'APPROVE')>APPROVE</option>
            <option value="REJECT" @selected(old('result', $submission->result) === 'REJECT')>REJECT</option>
            <option value="REVISI" @selected(old('result', $submission->result) === 'REVISI')>REVISI</option>
        </select></label>
        <label class="auth-field"><span>Tanggal Keputusan</span><input class="input" type="date" name="decision_date" value="{{ old('decision_date', $submission->decision_date?->toDateString()) }}"></label>
        <label class="auth-field"><span>Plafon Disetujui</span><input class="input" type="number" name="approved_amount" value="{{ old('approved_amount', $submission->approved_amount) }}" min="0" step="0.01"></label>
        <label class="auth-field"><span>Tenor</span><input class="input" name="tenor" value="{{ old('tenor', $submission->tenor) }}" maxlength="32"></label>
        <label class="auth-field form-grid__wide"><span>Catatan</span><textarea class="input" name="notes" rows="4" maxlength="2000">{{ old('notes', $submission->notes) }}</textarea></label>
        @error('result')
            <p class="form-grid__wide flash flash--error" role="alert">{{ $message }}</p>
        @enderror
        @error('approved_amount')
            <p class="form-grid__wide flash flash--error" role="alert">{{ $message }}</p>
        @enderror
        <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn" type="submit">Simpan Hasil Bank</button>
            <a class="btn btn--secondary" href="{{ route('bank.index', $project) }}">Kembali</a>
        </div>
    </form>
</section>
@endsection
