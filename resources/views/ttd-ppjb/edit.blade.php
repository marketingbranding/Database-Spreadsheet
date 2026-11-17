@extends('layouts.app')

@section('title', 'TTD PPJB ' . ($booking->lot?->code ?? $booking->id))
@section('subtitle', $project->name . ' · Booking #' . $booking->id)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

@php($file = $booking->currentPemberkasan)
@php($approvedSubmissions = $booking->bankSubmissions)

<section class="card section" aria-labelledby="ppjb-form-heading">
    <div class="card-title">
        <h2 id="ppjb-form-heading">TTD PPJB — {{ $booking->lot?->code }}</h2>
        <span class="badge">{{ $booking->payment_method }}</span>
    </div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Booking</dt><dd>Booking #{{ $booking->id }}</dd>
        <dt>Konsumen</dt><dd>{{ $booking->buyer?->name }}</dd>
        <dt>Kavling</dt><dd>{{ $booking->lot?->code }}</dd>
        @if ($booking->payment_method === 'KPR')
            <dt>Submission Bank (APPROVE)</dt><dd>{{ $approvedSubmissions->count() }} tersedia</dd>
        @else
            <dt>No. berkas</dt><dd>{{ $file?->file_number ?? '—' }} · status {{ $file?->status ?? '—' }}</dd>
        @endif
    </dl>

    <form class="form-grid" method="POST" action="{{ route('ppjb.store', [$project, $booking]) }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">

        @if ($booking->payment_method === 'KPR')
            @if ($approvedSubmissions->count() > 1)
                <fieldset class="form-grid__wide">
                    <legend>Pilih submission Bank (APPROVE) *</legend>
                    @foreach ($approvedSubmissions as $submission)
                        <label class="auth-field">
                            <span>
                                <input type="radio" name="bank_submission_id" value="{{ $submission->id }}" {{ old('bank_submission_id') == $submission->id ? 'checked' : '' }} required>
                                Submission #{{ $submission->id }} · {{ $submission->bank }} · diputuskan {{ $submission->decision_date?->format('d M Y') ?? '—' }}
                            </span>
                        </label>
                    @endforeach
                </fieldset>
            @else
                <input type="hidden" name="bank_submission_id" value="{{ old('bank_submission_id', $approvedSubmissions->first()?->id) }}">
            @endif
        @else
            <input type="hidden" name="pemberkasan_id" value="{{ old('pemberkasan_id', $file?->id) }}">
        @endif

        <label class="auth-field"><span>No. PPJB *</span><input class="input" name="ppjb_number" value="{{ old('ppjb_number') }}" maxlength="96" required></label>
        <label class="auth-field"><span>Tanggal TTD PPJB *</span><input class="input" type="date" name="signed_date" value="{{ old('signed_date', now()->toDateString()) }}" required></label>
        <label class="auth-field"><span>Status DP</span><input class="input" name="dp_status" value="{{ old('dp_status') }}" maxlength="48"></label>
        <label class="auth-field form-grid__wide"><span>Catatan</span><textarea class="input" name="notes" rows="4" maxlength="2000">{{ old('notes') }}</textarea></label>
        @error('bank_submission_id')
            <p class="form-grid__wide flash flash--error" role="alert">{{ $message }}</p>
        @enderror
        @error('idempotency_key')
            <p class="form-grid__wide flash flash--error" role="alert">{{ $message }}</p>
        @enderror
        <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn" type="submit">Simpan TTD PPJB</button>
            <a class="btn btn--secondary" href="{{ route('ppjb.index', $project) }}">Kembali</a>
        </div>
    </form>
</section>
@endsection
