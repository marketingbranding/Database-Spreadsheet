@extends('layouts.app')

@section('title', $mode === 'name' ? 'Ganti Nama' : 'Pindah Kavling')
@section('subtitle', $project->name . ' · konfirmasi perubahan sebelum menyimpan')

@section('content')
<section class="card section" aria-labelledby="change-title">
    <div class="card-title">
        <h2 id="change-title">{{ $mode === 'name' ? 'Ganti Nama' : 'Pindah Kavling' }}</h2>
        <span class="hint">Sumber booking tetap tersimpan</span>
    </div>

    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Booking sumber</dt><dd>#{{ $booking->id }} · {{ $booking->lot?->code }} · {{ $booking->buyer?->name }}</dd>
        <dt>Tahap sumber</dt><dd>{{ $booking->stageLabel() }} · {{ $booking->statusLabel() }}</dd>
        <dt>Harga saat ini</dt><dd>{{ $booking->base_price ? 'Rp ' . number_format((float) $booking->base_price, 0, ',', '.') : '—' }}</dd>
        <dt>UTJ</dt><dd>{{ $booking->psjb?->utj_date?->format('d M Y') ?? '—' }}{{ $booking->psjb?->utj_amount ? ' · Rp ' . number_format((float) $booking->psjb->utj_amount, 0, ',', '.') : '' }}</dd>
    </dl>
</section>

<section class="card section" aria-labelledby="form-title">
    <div class="card-title"><h2 id="form-title">Detail perubahan</h2></div>
    @if ($errors->has('change'))
        <div class="flash flash--error" role="alert">{{ $errors->first('change') }}</div>
    @endif
    <form method="POST" action="{{ $mode === 'name' ? route('bookings.change-name.store', [$project, $booking]) : route('bookings.move-lot.store', [$project, $booking]) }}" class="form-grid">
        @csrf
        @if ($mode === 'name')
            <label>
                Pembeli baru
                <select name="buyer_id" required>
                    <option value="">Pilih pembeli</option>
                    @foreach ($buyers as $buyer)
                        <option value="{{ $buyer->id }}" @selected(old('buyer_id') == $buyer->id)>{{ $buyer->name }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <label>
                Kavling tujuan
                <select name="lot_id" required>
                    <option value="">Pilih kavling</option>
                    @foreach ($lots as $lot)
                        <option value="{{ $lot->id }}" @selected(old('lot_id') == $lot->id)>{{ $lot->code }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                Harga tujuan (isi bila berbeda)
                <input type="number" min="0" step="0.01" name="price" value="{{ old('price', $booking->base_price) }}">
            </label>
            <label class="checkbox-label">
                <input type="checkbox" name="confirm_price_change" value="1" @checked(old('confirm_price_change'))>
                Saya mengonfirmasi harga tujuan bila berbeda
            </label>
        @endif
        <label>
            Tanggal efektif
            <input type="date" name="effective_date" value="{{ old('effective_date', now()->toDateString()) }}" required>
        </label>
        <label>
            Alasan
            <textarea name="reason" rows="4" required>{{ old('reason') }}</textarea>
        </label>
        <label>
            Kunci idempotensi
            <input type="text" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}" maxlength="255" required>
        </label>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn btn--primary" type="submit">Simpan perubahan</button>
            <a class="btn btn--secondary" href="{{ route('bookings.show', $booking) }}">Batal</a>
        </div>
    </form>
</section>
@endsection
