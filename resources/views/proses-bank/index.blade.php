@extends('layouts.app')

@section('title', 'Proses Bank')
@section('subtitle', $project->name . ' · ' . $project->branch?->name)

@section('content')
@include('partials.stage-tabs', ['active' => $tab])

@if ($tab === 'riwayat')
    @include('partials.stage-history', ['history' => $history, 'stage' => 'bank'])
@else
<section class="card section" aria-labelledby="proses-bank-heading">
    <div class="card-title">
        <h2 id="proses-bank-heading">Antrean Proses Bank</h2>
        <span class="hint">KPR · {{ $bookings->count() }} booking</span>
    </div>
    @if ($bookings->isEmpty())
        <p class="empty-state">Tidak ada booking KPR yang memenuhi syarat Proses Bank.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Kavling</th>
                        <th>Konsumen</th>
                        <th>No. berkas / revisi</th>
                        <th>Status Pemberkasan</th>
                        <th class="num">Pengajuan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($bookings as $booking)
                    @php($file = $booking->currentPemberkasan)
                    <tr>
                        <td>{{ $booking->lot?->code }}</td>
                        <td>{{ $booking->buyer?->name }}</td>
                        <td>
                            {{ $file?->file_number ?? '—' }}
                            <span class="hint">revisi {{ $file?->revision ?? '—' }}</span>
                        </td>
                        <td>{{ $file?->status ?? '—' }}</td>
                        <td class="num">{{ $booking->bankSubmissions->count() }}</td>
                        <td><a class="btn btn--small" href="{{ route('bank.create', [$project, $booking]) }}">Buat pengajuan</a></td>
                    </tr>
                    @if ($booking->bankSubmissions->isNotEmpty())
                        <tr>
                            <td colspan="6">
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
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif
@endsection
