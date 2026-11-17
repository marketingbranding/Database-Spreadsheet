@extends('layouts.app')

@section('title', 'Akad ' . ($booking->lot?->code ?? $booking->id))
@section('subtitle', $project->name . ' · Booking #' . $booking->id)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="card section" aria-labelledby="akad-form-heading">
    <div class="card-title">
        <h2 id="akad-form-heading">Input Akad — {{ $booking->lot?->code }}</h2>
        <span class="badge">{{ $booking->payment_method }}</span>
    </div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Booking</dt><dd>Booking #{{ $booking->id }}</dd>
        <dt>Konsumen</dt><dd>{{ $booking->buyer?->name }}</dd>
        <dt>Kavling</dt><dd>{{ $booking->lot?->code }}</dd>
        <dt>PPJB sumber</dt><dd>{{ $booking->ppjb?->ppjb_number }} · signed {{ $booking->ppjb?->signed_date?->format('d M Y') ?? '—' }}</dd>
    </dl>

    <form class="form-grid" method="POST" action="{{ route('akad.store', [$project, $booking]) }}">
        @csrf
        <input type="hidden" name="ppjb_id" value="{{ $booking->ppjb?->id }}">
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <label class="auth-field">
            <span>No. Akad *</span>
            <input class="input" name="akad_number" value="{{ old('akad_number') }}" maxlength="96" required>
            @error('akad_number')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </label>
        <label class="auth-field">
            <span>Tanggal Akad Aktual *</span>
            <input class="input" type="date" name="akad_date" value="{{ old('akad_date', now()->toDateString()) }}" required>
            <small class="field-hint">Berbeda dari tanggal TTD PPJB: {{ $booking->ppjb?->signed_date?->format('d M Y') ?? '—' }}</small>
            @error('akad_date')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </label>
        <label class="auth-field">
            <span>Kualitas Akad *</span>
            <input class="input" name="quality" value="{{ old('quality') }}" maxlength="64" required>
            @error('quality')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </label>
        <label class="auth-field form-grid__wide">
            <span>Catatan</span>
            <textarea class="input" name="notes" rows="4" maxlength="2000">{{ old('notes') }}</textarea>
            @error('notes')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </label>
        @error('ppjb_id')<p class="form-grid__wide field-error" role="alert">{{ $message }}</p>@enderror
        @error('idempotency_key')<p class="form-grid__wide field-error" role="alert">{{ $message }}</p>@enderror
        <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn" type="submit">Simpan Akad</button>
            <a class="btn btn--secondary" href="{{ route('akad.index', $project) }}">Kembali</a>
        </div>
    </form>
</section>
@endsection
