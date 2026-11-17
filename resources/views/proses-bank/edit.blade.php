@extends('layouts.app')

@section('title', 'Proses Bank ' . ($booking->lot?->code ?? $booking->id))
@section('subtitle', $project->name . ' · Booking #' . $booking->id)

@section('content')
@if ($errors->any())
    <div class="flash flash--error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

@php($file = $booking->currentPemberkasan)

<section class="card section" aria-labelledby="bank-form-heading">
    <div class="card-title">
        <h2 id="bank-form-heading">Pengajuan Bank — {{ $booking->lot?->code }}</h2>
        <span class="badge">{{ $booking->payment_method }}</span>
    </div>
    <dl class="timeline" style="grid-template-columns:max-content 1fr;">
        <dt>Booking</dt><dd>Booking #{{ $booking->id }}</dd>
        <dt>Konsumen</dt><dd>{{ $booking->buyer?->name }}</dd>
        <dt>Kavling</dt><dd>{{ $booking->lot?->code }}</dd>
        <dt>No. berkas</dt><dd>{{ $file?->file_number ?? '—' }} · revisi {{ $file?->revision ?? '—' }}</dd>
        <dt>Status Pemberkasan</dt><dd>{{ $file?->status ?? '—' }}</dd>
    </dl>

    <form class="form-grid" method="POST" action="{{ route('bank.store', [$project, $booking]) }}">
        @csrf
        <input type="hidden" name="pemberkasan_id" value="{{ old('pemberkasan_id', $file?->id) }}">
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">

        <label class="auth-field"><span>Bank *</span><input class="input" name="bank" value="{{ old('bank', $file?->bank) }}" maxlength="160" required></label>
        <label class="auth-field"><span>KC / Unit</span><input class="input" name="bank_unit" value="{{ old('bank_unit', $file?->bank_unit) }}" maxlength="160"></label>
        <label class="auth-field"><span>Tanggal Pengajuan *</span><input class="input" type="date" name="submitted_date" value="{{ old('submitted_date', now()->toDateString()) }}" required></label>
        <label class="auth-field form-grid__wide"><span>Catatan</span><textarea class="input" name="notes" rows="4" maxlength="2000">{{ old('notes') }}</textarea></label>
        @error('pemberkasan_id')
            <p class="form-grid__wide flash flash--error" role="alert">{{ $message }}</p>
        @enderror
        @error('idempotency_key')
            <p class="form-grid__wide flash flash--error" role="alert">{{ $message }}</p>
        @enderror
        <div class="form-grid__wide" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="btn" type="submit">Simpan Pengajuan</button>
            <a class="btn btn--secondary" href="{{ route('bank.index', $project) }}">Kembali</a>
        </div>
    </form>
</section>

@if ($booking->bankSubmissions->isNotEmpty())
<section class="card section" aria-labelledby="bank-history-heading">
    <div class="card-title">
        <h2 id="bank-history-heading">Riwayat Pengajuan Bank</h2>
        <span class="hint">{{ $booking->bankSubmissions->count() }} pengajuan</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Berkas</th>
                    <th>Bank / unit</th>
                    <th>Tanggal pengajuan</th>
                    <th>Hasil</th>
                    <th>Tanggal keputusan</th>
                    <th>Plafon / tenor</th>
                    <th>Catatan</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($booking->bankSubmissions as $submission)
                <tr>
                    <td>
                        {{ $submission->file_number ?? '—' }}
                        <span class="hint">revisi {{ $submission->pemberkasan?->revision ?? '—' }}</span>
                    </td>
                    <td>{{ trim((string) $submission->bank . ' ' . (string) $submission->bank_unit) ?: '—' }}</td>
                    <td>{{ $submission->submitted_date?->format('d M Y') ?? '—' }}</td>
                    <td>{{ $submission->result ?? '—' }}</td>
                    <td>{{ $submission->decision_date?->format('d M Y') ?? '—' }}</td>
                    <td>
                        {{ $submission->approved_amount ? 'Rp ' . number_format((float) $submission->approved_amount, 0, ',', '.') : '—' }}
                        @if ($submission->tenor) · {{ $submission->tenor }} @endif
                    </td>
                    <td>{{ $submission->notes ?? '—' }}</td>
                    <td>
                        @if ($submission->source_owner === \App\Models\BankSubmission::OWNER_LARAVEL && $submission->result === null)
                            <a class="btn btn--small" href="{{ route('bank.result.edit', [$project, $submission]) }}">Catat hasil</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif
@endsection
