<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\ImportConflict;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StageQueue
{
    public function psjbQueue(Project $project): Builder
    {
        return Booking::query()
            ->where('project_id', $project->id)
            ->where('status', Booking::STATUS_ACTIVE)
            ->where('current_stage', Booking::STAGE_PSJB)
            ->with(['lot', 'buyer', 'psjb'])
            ->orderBy('booking_date')
            ->orderBy('id');
    }

    public function psjbQueueCount(Project $project): int
    {
        return $this->psjbQueue($project)->count();
    }

    /**
     * Badge counts keyed by sidebar stage prefix. Every entry reuses the exact
     * query the matching Antrean tab renders.
     *
     * @return array<string, int>
     */
    public function queueCounts(Project $project, PsjbWorkflow $workflow): array
    {
        return [
            'psjb' => $this->psjbQueueCount($project),
            'bi' => $workflow->eligibleBiBookings($project)->count(),
            'pemberkasan' => $workflow->eligiblePemberkasanBookings($project)->count(),
            'bank' => $workflow->eligibleBankBookings($project)->count(),
            'ppjb' => $workflow->eligibleTtdPpjbBookings($project)->count(),
            'akad' => $workflow->eligibleAkadBookings($project)->count(),
            'bast' => $workflow->eligibleBastBookings($project)->count(),
        ];
    }

    /**
     * Batch form of blockReason(): disputes and CASH eligibility are fetched
     * once for the whole queue instead of once per row.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, array{reason: string, conflict: ?ImportConflict}|null> keyed by booking id
     */
    public function blockReasons(
        Project $project,
        User $user,
        Collection $bookings,
        bool $canViewConflicts,
        PsjbWorkflow $workflow,
    ): Collection {
        if ($bookings->isEmpty()) {
            return collect();
        }

        $lotIds = $bookings->pluck('lot_id')->filter()->unique()->values()->all();
        $disputes = collect();
        if ($lotIds !== []) {
            foreach (ImportConflict::openAllocationDisputesForLots($project->id, $lotIds)->with('lots')->get() as $conflict) {
                foreach ($conflict->lots as $lot) {
                    $disputes->put($lot->id, $conflict);
                }
            }
        }

        $cashIds = $bookings->where('payment_method', 'CASH')->pluck('id')->all();
        $eligibleCashIds = $cashIds === []
            ? collect()
            : $workflow->eligiblePemberkasanBookings($project)->whereKey($cashIds)->pluck('id');

        return $bookings->mapWithKeys(fn (Booking $booking) => [
            $booking->id => $this->blockReason(
                $user,
                $booking,
                $canViewConflicts,
                $workflow,
                $disputes,
                $eligibleCashIds,
            ),
        ]);
    }

    /**
     * Why the next stage action for a queue row cannot be opened, or null when
     * the routing link may be shown. Priority: lot allocation dispute, then
     * actor permissions, then stage eligibility.
     *
     * @return array{reason: string, conflict: ?ImportConflict}|null
     */
    public function blockReason(
        User $user,
        Booking $booking,
        bool $canViewConflicts,
        PsjbWorkflow $workflow,
        ?Collection $disputes = null,
        ?Collection $eligibleCashIds = null,
    ): ?array {
        if ($booking->lot_id) {
            $conflict = $disputes !== null
                ? $disputes->get($booking->lot_id)
                : ($canViewConflicts
                    ? ImportConflict::openAllocationDisputesForLot((int) $booking->project_id, (int) $booking->lot_id)->first()
                    : null);

            $hasDispute = $conflict !== null || (! $canViewConflicts && $disputes === null
                && ImportConflict::openAllocationDisputesForLot((int) $booking->project_id, (int) $booking->lot_id)->exists());

            if ($hasDispute) {
                return [
                    'reason' => 'Kavling memiliki konflik alokasi yang belum selesai.',
                    'conflict' => $canViewConflicts ? $conflict : null,
                ];
            }
        }

        if ($user->role !== User::ROLE_BRANCH_ADMIN) {
            return ['reason' => 'Aksi memerlukan admin cabang.', 'conflict' => null];
        }

        if ($booking->payment_method === 'CASH'
            && ! ($eligibleCashIds
                ? $eligibleCashIds->contains($booking->id)
                : $workflow->eligiblePemberkasanBookings($booking->project)->whereKey($booking->id)->exists())) {
            return ['reason' => 'Booking belum memenuhi syarat Pemberkasan.', 'conflict' => null];
        }

        return null;
    }
}
