<?php

namespace App\Services\Operations;

use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\ImportConflict;
use App\Models\ImportConflictBooking;
use App\Models\ImportConflictEvent;
use App\Models\ImportConflictProposal;
use App\Models\Project;
use App\Models\User;
use App\Support\Normalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImportConflictWorkflow
{
    public function __construct(private readonly PsjbWorkflow $psjbWorkflow) {}

    private const PILOT_PROJECT_CODES = ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'];

    private const CASE_ACTIONS = [
        ImportConflict::CASE_COMPETING_CLAIMS => [
            ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            ImportConflictProposal::ACTION_CORRECT_LARAVEL,
            ImportConflictProposal::ACTION_SELECT_CURRENT_CLAIM,
        ],
        ImportConflict::CASE_STOCK_DISAGREEMENT => [
            ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            ImportConflictProposal::ACTION_CORRECT_LARAVEL,
        ],
        ImportConflict::CASE_UNLINKED_BI => [
            ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            ImportConflictProposal::ACTION_LINK_BI,
        ],
        ImportConflict::CASE_BANK_REJECT => [
            ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            ImportConflictProposal::ACTION_ROUTE_BOOKING_DECISION,
        ],
        ImportConflict::CASE_SIGNED_DOCUMENT => [
            ImportConflictProposal::ACTION_CONFIRM_LARAVEL,
            ImportConflictProposal::ACTION_ESCALATE_SIGNED_DOCUMENT,
        ],
    ];

    public function propose(User $actor, ImportConflict $conflict, array $input): ImportConflictProposal
    {
        $this->assertActive($actor);
        if ($actor->role !== User::ROLE_BRANCH_ADMIN || ! $actor->canAccessProject($conflict->project)) {
            throw new AccessDeniedHttpException('Only an assigned branch admin may submit a resolution proposal.');
        }
        $this->assertPilot($conflict->project);

        $action = (string) ($input['action'] ?? '');
        $this->assertActionAllowed($conflict->case_type, $action);

        $proposedValues = $this->canonicalize((array) ($input['proposed_values'] ?? []));
        $payload = [
            'action' => $action,
            'reason' => trim((string) ($input['reason'] ?? '')),
            'evidence' => trim((string) ($input['evidence'] ?? '')),
            'source_reference' => trim((string) ($input['source_reference'] ?? '')),
            'proposed_values' => $proposedValues,
        ];
        $requestHash = $this->hash($payload);
        $idempotencyKey = (string) ($input['idempotency_key'] ?? '');

        return DB::transaction(function () use (
            $actor,
            $conflict,
            $action,
            $payload,
            $proposedValues,
            $requestHash,
            $idempotencyKey
        ): ImportConflictProposal {
            $locked = ImportConflict::query()
                ->whereKey($conflict->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOpen($locked);

            $existing = ImportConflictProposal::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (
                    $existing->import_conflict_id !== $locked->id
                    || $existing->project_id !== $locked->project_id
                    || $existing->proposed_by !== $actor->id
                    || $existing->request_hash !== $requestHash
                ) {
                    throw new HttpException(409, 'Idempotency key was already used for a different proposal.');
                }

                return $existing;
            }

            try {
                $proposal = ImportConflictProposal::query()->create([
                    'import_conflict_id' => $locked->id,
                    'project_id' => $locked->project_id,
                    'proposed_by' => $actor->id,
                    'action' => $action,
                    'reason' => $payload['reason'],
                    'evidence' => $payload['evidence'],
                    'source_reference' => $payload['source_reference'] !== '' ? $payload['source_reference'] : null,
                    'proposed_values' => $this->withInternalFingerprint($proposedValues, $locked),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'pending_guard' => 1,
                    'status' => ImportConflictProposal::STATUS_PENDING,
                ]);
            } catch (QueryException $exception) {
                throw new HttpException(409, 'A pending proposal or idempotency key already exists for this conflict.', $exception);
            }

            $locked->update([
                'status' => $locked->status === ImportConflict::STATUS_OPEN
                    ? ImportConflict::STATUS_PROPOSED
                    : $locked->status,
            ]);
            ImportConflictEvent::query()->create([
                'import_conflict_id' => $locked->id,
                'import_conflict_proposal_id' => $proposal->id,
                'actor_id' => $actor->id,
                'event_type' => ImportConflictEvent::TYPE_PROPOSAL_SUBMITTED,
                'reason' => $payload['reason'],
                'source_reference' => $proposal->source_reference,
                'source_fingerprint' => $locked->source_fingerprint,
                'before' => ['status' => ImportConflict::STATUS_OPEN],
                'after' => [
                    'status' => $locked->fresh()->status,
                    'action' => $action,
                    'proposal_id' => $proposal->id,
                ],
            ]);

            return $proposal;
        }, 3);
    }

    public function approve(User $hq, ImportConflictProposal $proposal, array $input): ImportConflict
    {
        $this->assertHq($hq);
        $approvalReason = trim((string) ($input['approval_reason'] ?? ''));
        if ($approvalReason === '' || mb_strlen($approvalReason) > 2000) {
            throw new HttpException(422, 'Alasan persetujuan wajib diisi dan maksimal 2000 karakter.');
        }

        return DB::transaction(function () use ($hq, $proposal, $approvalReason): ImportConflict {
            $lockedProposal = ImportConflictProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();
            $conflict = ImportConflict::query()
                ->whereKey($lockedProposal->import_conflict_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertPending($lockedProposal);
            $this->assertUnchangedFingerprint($lockedProposal, $conflict);

            $before = $this->conflictBefore($conflict);
            $proposedValues = (array) ($lockedProposal->proposed_values ?? []);
            $result = match ($lockedProposal->action) {
                ImportConflictProposal::ACTION_CONFIRM_LARAVEL => $this->confirmLaravel($conflict),
                ImportConflictProposal::ACTION_ESCALATE_SIGNED_DOCUMENT => $this->escalateSignedDocument($conflict),
                ImportConflictProposal::ACTION_ROUTE_BOOKING_DECISION => $this->routeBookingDecision($conflict),
                ImportConflictProposal::ACTION_LINK_BI => $this->applyLinkBi($lockedProposal, $hq, $conflict),
                ImportConflictProposal::ACTION_CORRECT_LARAVEL,
                ImportConflictProposal::ACTION_SELECT_CURRENT_CLAIM => $this->applyCorrection($lockedProposal, $hq, $conflict),
                default => throw new HttpException(422, 'Aksi resolusi tidak didukung.'),
            };

            $lockedProposal->fill([
                'status' => $result['proposal_status'] ?? ImportConflictProposal::STATUS_APPLIED,
                'approved_by' => $hq->id,
                'approval_reason' => $approvalReason,
                'approved_at' => now(),
                'applied_at' => $result['applied'] ?? now(),
                'pending_guard' => null,
                'before' => $before,
                'after' => $this->conflictBefore($conflict->fresh()),
            ])->save();

            ImportConflictEvent::query()->create([
                'import_conflict_id' => $conflict->id,
                'import_conflict_proposal_id' => $lockedProposal->id,
                'actor_id' => $hq->id,
                'event_type' => $result['event_type'] ?? ImportConflictEvent::TYPE_RESOLUTION_APPLIED,
                'reason' => $approvalReason,
                'source_reference' => $lockedProposal->source_reference,
                'source_fingerprint' => $conflict->source_fingerprint,
                'before' => $before,
                'after' => [
                    ...$this->conflictBefore($conflict->fresh()),
                    'approval' => [
                        'action' => $lockedProposal->action,
                        'proposed_by' => $lockedProposal->proposed_by,
                        'approved_by' => $hq->id,
                        'approval_reason' => $approvalReason,
                        'reason' => $lockedProposal->reason,
                        'evidence' => $lockedProposal->evidence,
                        'source_reference' => $lockedProposal->source_reference,
                    ],
                    'resolution' => $result,
                ],
            ]);

            return $conflict->fresh();
        }, 3);
    }

    public function reject(User $hq, ImportConflictProposal $proposal, string $reason): ImportConflictProposal
    {
        $this->assertHq($hq);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new HttpException(422, 'Alasan penolakan wajib diisi dan maksimal 2000 karakter.');
        }

        return DB::transaction(function () use ($hq, $proposal, $reason): ImportConflictProposal {
            $lockedProposal = ImportConflictProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();
            $conflict = ImportConflict::query()
                ->whereKey($lockedProposal->import_conflict_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertPending($lockedProposal);

            $before = ['status' => $conflict->status];
            $restoredStatus = $conflict->status === ImportConflict::STATUS_ESCALATED
                ? ImportConflict::STATUS_ESCALATED
                : ImportConflict::STATUS_OPEN;
            $conflict->update(['status' => $restoredStatus]);
            $lockedProposal->fill([
                'status' => ImportConflictProposal::STATUS_REJECTED,
                'approved_by' => $hq->id,
                'approval_reason' => $reason,
                'rejected_at' => now(),
                'pending_guard' => null,
                'after' => ['status' => $restoredStatus],
            ])->save();
            ImportConflictEvent::query()->create([
                'import_conflict_id' => $conflict->id,
                'import_conflict_proposal_id' => $lockedProposal->id,
                'actor_id' => $hq->id,
                'event_type' => ImportConflictEvent::TYPE_PROPOSAL_REJECTED,
                'reason' => $reason,
                'source_reference' => $lockedProposal->source_reference,
                'source_fingerprint' => $conflict->source_fingerprint,
                'before' => $before,
                'after' => ['status' => $restoredStatus],
            ]);

            return $lockedProposal;
        }, 3);
    }

    private function confirmLaravel(ImportConflict $conflict): array
    {
        $conflict->fill([
            'status' => ImportConflict::STATUS_RESOLVED,
            'reviewed_source_fingerprint' => $conflict->source_fingerprint,
            'resolution_type' => ImportConflict::RESOLUTION_CONFIRM_LARAVEL,
            'resolved_at' => now(),
        ])->save();

        return [
            'event_type' => ImportConflictEvent::TYPE_RESOLUTION_APPLIED,
        ];
    }

    private function escalateSignedDocument(ImportConflict $conflict): array
    {
        $conflict->fill([
            'status' => ImportConflict::STATUS_ESCALATED,
        ])->save();

        return [
            'proposal_status' => ImportConflictProposal::STATUS_APPROVED,
            'event_type' => ImportConflictEvent::TYPE_SIGNED_ESCALATED,
        ];
    }

    private function routeBookingDecision(ImportConflict $conflict): array
    {
        return [
            'proposal_status' => ImportConflictProposal::STATUS_APPROVED,
            'event_type' => ImportConflictEvent::TYPE_PROPOSAL_APPROVED,
        ];
    }

    private function applyLinkBi(ImportConflictProposal $proposal, User $hq, ImportConflict $conflict): array
    {
        $values = (array) ($proposal->proposed_values ?? []);
        $bookingId = (int) ($values['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new HttpException(409, 'Pilih siklus booking yang tepat untuk BI.');
        }

        $linked = ImportConflictBooking::query()
            ->where('import_conflict_id', $conflict->id)
            ->where('booking_id', $bookingId)
            ->exists();
        if (! $linked) {
            throw new HttpException(409, 'Booking BI tidak termasuk bukti konflik ini.');
        }

        $booking = Booking::query()
            ->whereKey($bookingId)
            ->where('project_id', $conflict->project_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($booking->ppjb || $booking->akad || $booking->bast) {
            throw new HttpException(422, 'Dokumen tertanda mencegah perubahan linkage BI.');
        }
        if (BiCheck::query()
            ->where('booking_id', $booking->id)
            ->where('source_owner', BiCheck::OWNER_LARAVEL)
            ->exists()) {
            throw new HttpException(409, 'Booking sudah memiliki BI milik Laravel.');
        }

        $source = (array) ($conflict->source_value ?? []);
        $result = (string) ($source['Hasil SLIK'] ?? $source['[AUTO] Status Konsumen'] ?? '');
        $decisionRaw = strtoupper(trim((string) ($source['Keputusan'] ?? $source['[AUTO] Status Transaksi'] ?? '')));
        $decision = in_array($decisionRaw, ['LANJUT', 'OK'], true) ? 'LANJUT' : 'TIDAK LANJUT';
        $checkDate = Normalizer::date($source['Tanggal BI'] ?? null)?->toDateString();

        $this->psjbWorkflow->recordBiCheck($proposal->proposer, $booking, [
            'bi_number' => $source['[SYS] ID BI'] ?? $source['[SYS] ID Transaksi'] ?? null,
            'check_date' => $checkDate,
            'result' => $result !== '' ? $result : null,
            'decision' => $decision,
            'notes' => 'Konflik impor: '.($proposal->source_reference ?: 'BI Checking'),
        ]);

        $conflict->update([
            'status' => ImportConflict::STATUS_RESOLVED,
            'reviewed_source_fingerprint' => $conflict->source_fingerprint,
            'resolution_type' => ImportConflict::RESOLUTION_LINK_BI,
            'resolved_at' => now(),
            'resolved_by' => $hq->id,
        ]);

        return [
            'resolution_type' => ImportConflict::RESOLUTION_LINK_BI,
            'booking_id' => $booking->id,
            'before_bi_owner' => BiCheck::query()->where('booking_id', $booking->id)->value('source_owner'),
        ];
    }

    private function applyCorrection(ImportConflictProposal $proposal, User $hq, ImportConflict $conflict): array
    {
        if (in_array($conflict->case_type, [
            ImportConflict::CASE_SIGNED_DOCUMENT,
            ImportConflict::CASE_UNLINKED_BI,
            ImportConflict::CASE_BANK_REJECT,
        ], true)) {
            throw new HttpException(422, 'Koreksi alokasi tidak tersedia untuk jenis konflik ini.');
        }

        $values = (array) ($proposal->proposed_values ?? []);
        $result = $this->psjbWorkflow->applyConflictClaimResolution($hq, $conflict->project, [
            'conflict_id' => $conflict->id,
            'current_booking_id' => $values['current_booking_id'] ?? null,
            'dispositions' => $values['dispositions'] ?? [],
            'reason' => $proposal->reason,
            'source_reference' => $proposal->source_reference ?: ($conflict->source_tab ?? 'import'),
        ]);

        $conflict->update([
            'status' => ImportConflict::STATUS_RESOLVED,
            'reviewed_source_fingerprint' => $conflict->source_fingerprint,
            'resolution_type' => ImportConflict::RESOLUTION_CORRECT_LARAVEL,
            'resolved_at' => now(),
            'resolved_by' => $hq->id,
        ]);

        return $result;
    }

    private function assertActive(User $actor): void
    {
        if (! $actor->is_active) {
            throw new AccessDeniedHttpException('User is not active.');
        }
    }

    private function assertHq(User $actor): void
    {
        $this->assertActive($actor);
        if (! $actor->isHq()) {
            throw new AccessDeniedHttpException('Only HQ may review import conflict proposals.');
        }
    }

    private function assertPilot(Project $project): void
    {
        if (! in_array($project->code, self::PILOT_PROJECT_CODES, true)) {
            throw new AccessDeniedHttpException('Import conflict review is limited to the staging pilot projects.');
        }
    }

    private function assertOpen(ImportConflict $conflict): void
    {
        if (! in_array($conflict->status, [
            ImportConflict::STATUS_OPEN,
            ImportConflict::STATUS_PROPOSED,
            ImportConflict::STATUS_ESCALATED,
        ], true)) {
            throw new HttpException(409, 'This conflict is no longer open for proposals.');
        }
    }

    private function assertPending(ImportConflictProposal $proposal): void
    {
        if ($proposal->status !== ImportConflictProposal::STATUS_PENDING) {
            throw new HttpException(409, 'This proposal has already been reviewed.');
        }
    }

    private function assertUnchangedFingerprint(ImportConflictProposal $proposal, ImportConflict $conflict): void
    {
        $reviewed = (array) ($proposal->proposed_values ?? []);
        if (($reviewed['_source_fingerprint'] ?? null) !== $conflict->source_fingerprint) {
            throw new HttpException(409, 'Source evidence changed while the proposal was pending.');
        }
    }

    private function assertActionAllowed(string $caseType, string $action): void
    {
        if (! in_array($action, self::CASE_ACTIONS[$caseType] ?? [], true)) {
            throw new HttpException(422, 'Aksi resolusi tidak tersedia untuk jenis konflik ini.');
        }
    }

    private function withInternalFingerprint(array $values, ImportConflict $conflict): array
    {
        $values['_source_fingerprint'] = $conflict->source_fingerprint;

        return $this->canonicalize($values);
    }

    private function canonicalize(array $values): array
    {
        return Arr::sortRecursive($values);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function conflictBefore(ImportConflict $conflict): array
    {
        return [
            'status' => $conflict->status,
            'resolution_type' => $conflict->resolution_type,
            'reviewed_source_fingerprint' => $conflict->reviewed_source_fingerprint,
            'resolved_at' => $conflict->resolved_at?->toIso8601String(),
        ];
    }
}
