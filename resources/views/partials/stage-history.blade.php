@php($psjbStage = $stage === 'psjb')
@if ($history->isEmpty())
    <p class="empty-state">Belum ada riwayat untuk tahap ini.</p>
@else
<div class="table-wrap">
    <table class="stage-history">
        <thead>
        <tr>
            <th>Kavling</th>
            <th>Konsumen</th>
            <th>Tanggal</th>
            <th>Status</th>
            <th>Peristiwa</th>
            @if ($psjbStage)
                <th>Siklus</th>
            @endif
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($history as $row)
            @php($booking = $row['booking'])
            <tr>
                <td>{{ $booking->lot?->code }}</td>
                <td>{{ $booking->buyer?->name }}</td>
                <td>
                    @foreach ($row['events'] as $event)
                        @if ($loop->first)
                            {{ $event['date']?->format('d M Y') ?? '—' }}
                        @endif
                    @endforeach
                </td>
                <td><span class="badge badge--{{ strtolower($booking->status) }}">{{ $booking->statusLabel() }}</span></td>
                <td>
                    <ul class="event-list">
                        @forelse ($row['events'] as $event)
                            <li>
                                <span class="event-date">{{ $event['date']?->format('d M Y') ?? '—' }}</span>
                                <span class="event-label">{{ $event['label'] }}</span>
                                <span class="event-detail">{{ $event['detail'] }}</span>
                            </li>
                        @empty
                            <li class="event-empty">Belum ada peristiwa tercatat.</li>
                        @endforelse
                    </ul>
                </td>
                @if ($psjbStage)
                    <td class="cycle-cell">
                        @if ($row['cycle']['predecessor_id'])
                            <a href="{{ route('bookings.show', $row['cycle']['predecessor_id']) }}">← #{{ $row['cycle']['predecessor_id'] }}</a>
                        @endif
                        @foreach ($row['cycle']['successor_ids'] as $successorId)
                            <a href="{{ route('bookings.show', $successorId) }}">→ #{{ $successorId }}</a>
                        @endforeach
                        @if (! $row['cycle']['predecessor_id'] && ! $row['cycle']['successor_ids'])
                            <span class="hint">Siklus tunggal</span>
                        @endif
                    </td>
                @endif
                <td><a class="btn btn--small" href="{{ route('bookings.show', $booking) }}">Lihat Riwayat</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
{{ $history->withQueryString()->links() }}
@endif
