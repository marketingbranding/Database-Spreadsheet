@extends('layouts.app')

@section('title', 'Pemberkasan ' . ($booking->lot?->code ?? $booking->id))
@section('subtitle', $project->name . ' · Booking #' . $booking->id)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="card section" aria-labelledby="pemberkasan-form-heading">
    <div class="card-title"><h2 id="pemberkasan-form-heading">Pemberkasan — {{ $booking->lot?->code }}</h2><span class="badge">{{ $booking->payment_method }}</span></div>
    <p>{{ $booking->buyer?->name }} · Booking #{{ $booking->id }}</p>
    @if ($booking->pemberkasan->isNotEmpty())
        <p class="hint">Riwayat: {{ $booking->pemberkasan->count() }} berkas tersimpan. Form membuat versi baru.</p>
    @endif
    <form class="form-grid" method="POST" action="{{ route('pemberkasan.update', [$project, $booking]) }}">
        @csrf
        <label class="auth-field"><span>No. berkas *</span><input class="input" name="file_number" value="{{ old('file_number') }}" maxlength="96" required></label>
        <label class="auth-field"><span>Tanggal Terima Bank</span><input class="input" type="date" name="received_date" value="{{ old('received_date', now()->toDateString()) }}"></label>
        <label class="auth-field"><span>Bank</span><input class="input" name="bank" value="{{ old('bank') }}" maxlength="160"></label>
        <label class="auth-field"><span>KC / Unit Bank</span><input class="input" name="bank_unit" value="{{ old('bank_unit') }}" maxlength="160"></label>
        <label class="auth-field"><span>Status Pemberkasan</span><input class="input" name="status" value="{{ old('status') }}" maxlength="48"></label>
        <label class="auth-field form-grid__wide"><span>Keterangan</span><textarea class="input" name="notes" rows="4" maxlength="2000">{{ old('notes') }}</textarea></label>
        <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn" type="submit">Simpan Pemberkasan</button>
            <a class="btn btn--secondary" href="{{ route('pemberkasan.index', $project) }}">Kembali</a>
        </div>
    </form>
</section>
@endsection
