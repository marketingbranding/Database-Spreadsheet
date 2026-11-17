@extends('layouts.app')

@section('title', 'Buat PSJB')
@section('subtitle', $project->name . ' · ' . $project->branch?->name)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert">
        <strong>Periksa data:</strong>
        <ul>
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<form class="card section form-grid" method="POST" action="{{ route('psjb.store', $project) }}">
    @csrf
    <div class="card-title form-grid__wide"><h2>Detail booking</h2><span class="hint">Semua perubahan tercatat</span></div>

    <label class="auth-field">
        <span>Kavling *</span>
        <select class="input" name="lot_id" required>
            <option value="">Pilih kavling tersedia</option>
            @foreach ($lots as $lot)
                <option value="{{ $lot->id }}" @selected(old('lot_id') == $lot->id)>{{ $lot->code }}</option>
            @endforeach
        </select>
    </label>

    <label class="auth-field">
        <span>Konsumen lama</span>
        <select class="input" name="buyer_id">
            <option value="">Pilih jika sudah terdaftar</option>
            @foreach ($buyers as $buyer)
                <option value="{{ $buyer->id }}" @selected(old('buyer_id') == $buyer->id)>{{ $buyer->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="auth-field">
        <span>Konsumen baru</span>
        <input class="input" name="buyer_name" value="{{ old('buyer_name') }}" maxlength="160" placeholder="Isi jika konsumen lama kosong">
    </label>

    <label class="auth-field"><span>NIK</span><input class="input" name="nik" value="{{ old('nik') }}" maxlength="32" inputmode="numeric"></label>
    <label class="auth-field"><span>No. HP</span><input class="input" name="phone" value="{{ old('phone') }}" maxlength="32"></label>
    <label class="auth-field"><span>Sales *</span><input class="input" name="sales_name" value="{{ old('sales_name') }}" required maxlength="160"></label>
    <label class="auth-field"><span>Koordinator</span><input class="input" name="coordinator_name" value="{{ old('coordinator_name') }}" maxlength="160"></label>
    <label class="auth-field"><span>Metode pembayaran *</span><select class="input" name="payment_method" required>
        @foreach (['KPR', 'CASH', 'INHOUSE', 'CASH_KERAS'] as $method)<option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $method }}</option>@endforeach
    </select></label>
    <label class="auth-field"><span>No. PSJB</span><input class="input" name="psjb_number" value="{{ old('psjb_number') }}" maxlength="96"></label>
    <label class="auth-field"><span>Tanggal PSJB *</span><input class="input" type="date" name="psjb_date" value="{{ old('psjb_date', now()->toDateString()) }}" required></label>
    <label class="auth-field"><span>Tanggal UTJ</span><input class="input" type="date" name="utj_date" value="{{ old('utj_date') }}"></label>
    <label class="auth-field"><span>Nominal UTJ</span><input class="input" type="number" min="0" step="0.01" name="utj_amount" value="{{ old('utj_amount') }}"></label>
    <label class="auth-field"><span>Harga unit</span><input class="input" type="number" min="0" step="0.01" name="price" value="{{ old('price') }}"></label>
    <label class="auth-field"><span>DP all-in</span><input class="input" type="number" min="0" step="0.01" name="dp_all_in" value="{{ old('dp_all_in') }}"></label>
    <label class="auth-field"><span>Jumlah cicilan DP</span><input class="input" type="number" min="0" max="120" name="installment_count" value="{{ old('installment_count') }}"></label>
    <label class="auth-field form-grid__wide"><span>Catatan</span><textarea class="input" name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea></label>

    <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <button class="btn" type="submit">Buat PSJB</button>
        <a class="btn btn--secondary" href="{{ route('projects.show', $project) }}">Batal</a>
    </div>
</form>
@endsection
