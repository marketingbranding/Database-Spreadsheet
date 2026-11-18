<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\ImportConflict;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
     * Why the next stage action for a queue row cannot be opened, or null when
     * the routing link may be shown. Priority: lot allocation dispute, then
     * actor permissions, then stage eligibility.
     *
     * @return array{reason: string, conflict: ?ImportConflict}|null
     */
    public function blockReason(User $user, Booking $booking, bool $canViewConflicts, PsjbWorkflow $workflow): ?array
    {
        if ($booking->lot_id) {
            $conflict = $canViewConflicts
                ? ImportConflict::openAllocationDisputesForLot((int) $booking->project_id, (int) $booking->lot_id)->first()
                : null;

            if (ImportConflict::openAllocationDisputesForLot((int) $booking->project_id, (int) $booking->lot_id)->exists()) {
                return [
                    'reason' => 'Kavling memiliki konflik alokasi yang belum selesai.',
                    'conflict' => $conflict,
                ];
            }
        }

        if ($user->role !== User::ROLE_BRANCH_ADMIN) {
            return ['reason' => 'Aksi memerlukan admin cabang.', 'conflict' => null];
        }

        if ($booking->payment_method === 'CASH'
            && ! $workflow->eligiblePemberkasanBookings($booking->project)->whereKey($booking->id)->exists()) {
            return ['reason' => 'Booking belum memenuhi syarat Pemberkasan.', 'conflict' => null];
        }

        return null;
    }
}
