@extends('layouts.app')

@section('title', 'BAST ' . ($booking->lot?->code ?? $booking->id))
@section('subtitle', $project->name . ' · Booking #' . $booking->id)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="card section" aria-labelledby="bast-form-heading">
    <div class="card-title">
        <h2 id="bast-form-heading">Catat BAST — {{ $booking->lot?->code }}</h2>
        <span class="badge">{{ $booking->payment_method }}</span>
    </div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Booking</dt><dd>Booking #{{ $booking->id }}</dd>
        <dt>Konsumen</dt><dd>{{ $booking->buyer?->name }}</dd>
        <dt>Kavling</dt><dd>{{ $booking->lot?->code }}</dd>
        <dt>Akad sumber</dt><dd>{{ $booking->akad?->akad_number }} · {{ $booking->akad?->akad_date?->format('d M Y') ?? '—' }}</dd>
    </dl>

    <form class="form-grid" method="POST" action="{{ route('bast.store', [$project, $booking]) }}">
        @csrf
        <input type="hidden" name="akad_id" value="{{ old('akad_id', $booking->akad?->id) }}">
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <label class="auth-field">
            <span>No. BAST *</span>
            <input class="input" name="bast_number" value="{{ old('bast_number') }}" maxlength="96" required>
            @error('bast_number')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </label>
        <label class="auth-field">
            <span>Tanggal Serah Terima Aktual *</span>
            <input class="input" type="date" name="bast_date" value="{{ old('bast_date', now()->toDateString()) }}" required>
            <small class="field-hint">Berbeda dari tanggal Akad: {{ $booking->akad?->akad_date?->format('d M Y') ?? '—' }}</small>
            @error('bast_date')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </label>
        <label class="auth-field form-grid__wide">
            <span>Catatan</span>
            <textarea class="input" name="notes" rows="4" maxlength="2000">{{ old('notes') }}</textarea>
            @error('notes')<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </label>
        @error('akad_id')<p class="form-grid__wide field-error" role="alert">{{ $message }}</p>@enderror
        @error('idempotency_key')<p class="form-grid__wide field-error" role="alert">{{ $message }}</p>@enderror
        <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn" type="submit">Simpan BAST</button>
            <a class="btn btn--secondary" href="{{ route('bast.index', $project) }}">Kembali</a>
        </div>
    </form>
</section>
@endsection
