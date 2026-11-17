@extends('layouts.app')

@section('title', 'BI Checking ' . ($booking->lot?->code ?? $booking->id))
@section('subtitle', $project->name . ' · Booking #' . $booking->id)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="card section" aria-labelledby="bi-form-heading">
    <div class="card-title"><h2 id="bi-form-heading">BI Checking — {{ $booking->lot?->code }}</h2><span class="badge">KPR</span></div>
    <p>{{ $booking->buyer?->name }} · Booking #{{ $booking->id }}</p>
    <form class="form-grid" method="POST" action="{{ route('bi.update', [$project, $booking]) }}">
        @csrf
        <label class="auth-field"><span>No. BI</span><input class="input" name="bi_number" value="{{ old('bi_number', $booking->biCheck?->bi_number) }}" maxlength="96"></label>
        <label class="auth-field"><span>Tanggal BI *</span><input class="input" type="date" name="check_date" value="{{ old('check_date', $booking->biCheck?->check_date?->toDateString() ?? now()->toDateString()) }}" required></label>
        <label class="auth-field"><span>Hasil</span><input class="input" name="result" value="{{ old('result', $booking->biCheck?->result) }}" maxlength="32"></label>
        <label class="auth-field"><span>Keputusan *</span><select class="input" name="decision" required>
            <option value="LANJUT" @selected(old('decision', $booking->biCheck?->decision) === 'LANJUT')>LANJUT</option>
            <option value="TIDAK LANJUT" @selected(old('decision', $booking->biCheck?->decision) === 'TIDAK LANJUT')>TIDAK LANJUT</option>
        </select></label>
        <label class="auth-field form-grid__wide"><span>Catatan</span><textarea class="input" name="notes" rows="4" maxlength="2000">{{ old('notes', $booking->biCheck?->notes) }}</textarea></label>
        <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn" type="submit">Simpan BI Checking</button>
            <a class="btn btn--secondary" href="{{ route('bi.index', $project) }}">Kembali</a>
        </div>
    </form>
</section>
@endsection
