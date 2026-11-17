<?php

namespace App\Services\Operations;

use App\Exceptions\LotAllocationConflict;
use App\Models\AkadRecord;
use App\Models\BankSubmission;
use App\Models\BastRecord;
use App\Models\BiCheck;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BookingTransition;
use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Project;
use App\Models\PemberkasanRecord;
use App\Models\PpjbRecord;
use App\Models\PsjbRecord;
use App\Models\User;
use App\Support\Normalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PsjbWorkflow
{
    private const VALID_CLAIM_STATUSES = [
        Booking::STATUS_ACTIVE,
        Booking::STATUS_COMPLETED,
        Booking::STATUS_NEEDS_REVIEW,
    ];

    private const PILOT_PROJECT_CODES = ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'];

    private const BANK_PEMBERKASAN_STATUSES = ['Analisa', 'Input', 'OTS', 'LENGKAP'];

    private const CASH_PEMBERKASAN_STATUS = 'Pemberkasan CASH';

    public function availableLots(Project $project): Builder
    {
        return Lot::query()
            ->where('project_id', $project->id)
            ->where('historical_akad_before_2026', false)
            ->whereDoesntHave('bookings', fn (Builder $query) => $query->whereIn('status', self::VALID_CLAIM_STATUSES))
            ->orderBy('code');
    }

    public function validClaimQuery(int $lotId): Builder
    {
        return Booking::query()->where('lot_id', $lotId)->whereIn('status', self::VALID_CLAIM_STATUSES);
    }

    public function create(User $actor, Project $project, array $input): Booking
    {
        $this->authorizeWrite($actor, $project);

        return DB::transaction(function () use ($actor, $project, $input): Booking {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->authorizeWrite($actor, $project);

            $lot = Lot::query()->whereKey($input['lot_id'])->where('project_id', $project->id)->lockForUpdate()->first();
            if (! $lot || $lot->historical_akad_before_2026 || $this->validClaimQuery($lot->id)->exists()) {
                throw new LotAllocationConflict;
            }

            $buyer = $this->resolveBuyer($project, $input);
            $booking = Booking::query()->create([
                'project_id' => $project->id,
                'lot_id' => $lot->id,
                'buyer_id' => $buyer->id,
                'source_booking_key' => 'LARAVEL:PSJB:' . (string) Str::uuid(),
                'psjb_number' => $input['psjb_number'] ?? null,
                'booking_date' => $input['psjb_date'],
                'payment_method' => $input['payment_method'],
                'sales_name' => $input['sales_name'],
                'coordinator_name' => $input['coordinator_name'] ?? null,
                'base_price' => $input['price'] ?? null,
                'status' => Booking::STATUS_ACTIVE,
                'current_stage' => Booking::STAGE_PSJB,
                'is_current_holder' => true,
                'source_owner' => Booking::OWNER_LARAVEL,
                'source_tab' => 'Laravel',
            ]);

            PsjbRecord::query()->create([
                'booking_id' => $booking->id,
                'project_id' => $project->id,
                'source_key' => $booking->source_booking_key . ':PSJB',
                'psjb_number' => $input['psjb_number'] ?? null,
                'psjb_date' => $input['psjb_date'],
                'sales_name' => $input['sales_name'],
                'coordinator_name' => $input['coordinator_name'] ?? null,
                'price' => $input['price'] ?? null,
                'utj_date' => $input['utj_date'] ?? null,
                'utj_amount' => $input['utj_amount'] ?? null,
                'dp_all_in' => $input['dp_all_in'] ?? null,
                'installment_count' => $input['installment_count'] ?? null,
                'payment_method' => $input['payment_method'],
                'source_status' => 'ACTIVE',
                'notes' => $input['notes'] ?? null,
                'source_tab' => 'Laravel',
            ]);

            BookingAudit::record($booking, $actor, BookingAudit::ACTION_CREATED, null, BookingAudit::snapshot($booking), $this->requestId());

            return $booking->fresh(['project', 'lot', 'buyer', 'psjb']);
        }, 3);
    }

    public function eligibleBiBookings(Project $project): Builder
    {
        return Booking::query()
            ->where('project_id', $project->id)
            ->where('payment_method', 'KPR')
            ->whereIn('status', [Booking::STATUS_ACTIVE, Booking::STATUS_NEEDS_REVIEW])
            ->whereIn('current_stage', [Booking::STAGE_PSJB, Booking::STAGE_BI])
            ->whereDoesntHave('biCheck', fn (Builder $query) => $query->where('source_owner', BiCheck::OWNER_LARAVEL))
            ->with(['lot', 'buyer', 'biCheck'])
            ->orderBy('booking_date')
            ->orderBy('id');
    }

    public function changeName(User $actor, Booking $booking, array $input): Booking
    {
        return $this->changeBooking($actor, $booking, BookingTransition::ACTION_NAME_CHANGE, $input);
    }

    public function moveLot(User $actor, Booking $booking, array $input): Booking
    {
        return $this->changeBooking($actor, $booking, BookingTransition::ACTION_LOT_TRANSFER, $input);
    }

    private function changeBooking(User $actor, Booking $booking, string $action, array $input): Booking
    {
        $effectiveDate = Carbon::parse((string) ($input['effective_date'] ?? ''))->toDateString();
        $reason = trim((string) ($input['reason'] ?? ''));
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new HttpException(422, 'Alasan perubahan wajib diisi.');
        }
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 255) {
            throw new HttpException(422, 'Kunci idempotensi wajib diisi dan maksimal 255 karakter.');
        }

        $projectId = (int) $booking->project_id;

        return DB::transaction(function () use ($actor, $booking, $action, $input, $effectiveDate, $reason, $idempotencyKey, $projectId): Booking {
            $project = Project::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $source = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->authorizeWrite($actor, $project);

            if ($source->project_id !== $project->id) {
                throw new AccessDeniedHttpException('Booking tidak tersedia untuk perubahan ini.');
            }

            $fingerprint = $this->bookingChangeFingerprint($source, $action, $input, $effectiveDate, $reason);
            $existing = BookingTransition::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals((string) $existing->source_hash, $fingerprint)) {
                    throw new HttpException(409, 'Kunci idempotensi sudah dipakai untuk permintaan berbeda.');
                }

                return $existing->successorBooking()->with(['project', 'lot', 'buyer', 'psjb', 'transition'])->firstOrFail();
            }

            if ($source->status !== Booking::STATUS_ACTIVE) {
                throw new AccessDeniedHttpException('Booking tidak tersedia untuk perubahan ini.');
            }

            $this->assertChangeBeforeSignedDocuments($source);
            $sourceLot = Lot::query()->whereKey($source->lot_id)->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
            $destinationLot = $sourceLot;
            $successorBuyer = $source->buyer_id;
            $successorPrice = $source->base_price;

            if ($action === BookingTransition::ACTION_NAME_CHANGE) {
                $buyer = Buyer::query()->whereKey($input['buyer_id'] ?? 0)->first();
                if (! $buyer || $buyer->id === $source->buyer_id || ! $this->buyerBelongsToProject($buyer, $project)) {
                    throw new HttpException(422, 'Pembeli pengganti tidak valid untuk proyek ini.');
                }
                $successorBuyer = $buyer->id;
            } else {
                $destinationId = (int) ($input['lot_id'] ?? 0);
                $destinationLot = Lot::query()->whereKey($destinationId)->where('project_id', $project->id)->lockForUpdate()->first();
                if (! $destinationLot || $destinationLot->id === $sourceLot->id || $destinationLot->historical_akad_before_2026) {
                    throw new HttpException(422, 'Kavling tujuan tidak valid untuk pindah kavling.');
                }

                $destinationPrice = $input['price'] ?? $source->base_price;
                if ($destinationPrice !== null && ! is_numeric($destinationPrice)) {
                    throw new HttpException(422, 'Harga kavling tujuan harus berupa angka.');
                }
                $successorPrice = $destinationPrice;
                if ((float) ($successorPrice ?? 0) !== (float) ($source->base_price ?? 0)
                    && ! filter_var($input['confirm_price_change'] ?? false, FILTER_VALIDATE_BOOL)) {
                    throw new HttpException(422, 'Perubahan harga kavling tujuan harus dikonfirmasi.');
                }

                if ($this->validClaimQuery($destinationLot->id)->exists()) {
                    throw new HttpException(409, 'Kavling tujuan sudah dialokasikan.');
                }
            }

            $sourcePsjb = PsjbRecord::query()->where('booking_id', $source->id)->lockForUpdate()->first();
            $beforeSource = BookingAudit::snapshot($source);
            $source->update([
                'status' => $action === BookingTransition::ACTION_NAME_CHANGE ? Booking::STATUS_REPLACED : Booking::STATUS_MOVED,
                'decision_stage' => $source->current_stage,
                'decision_date' => $effectiveDate,
                'decision_note' => $reason,
                'is_current_holder' => false,
                'source_owner' => Booking::OWNER_LARAVEL,
            ]);

            $successor = Booking::query()->create([
                'project_id' => $project->id,
                'lot_id' => $destinationLot->id,
                'buyer_id' => $successorBuyer,
                'source_booking_key' => 'LARAVEL:CHANGE:' . (string) Str::uuid(),
                'source_transaction_id' => $source->source_transaction_id,
                'psjb_number' => $sourcePsjb?->psjb_number,
                'booking_date' => $effectiveDate,
                'payment_method' => $source->payment_method,
                'sales_name' => $source->sales_name,
                'coordinator_name' => $source->coordinator_name,
                'base_price' => $successorPrice,
                'status' => Booking::STATUS_ACTIVE,
                'current_stage' => Booking::STAGE_PSJB,
                'predecessor_booking_id' => $source->id,
                'transfer_type' => $action === BookingTransition::ACTION_NAME_CHANGE ? 'GANTI_NAMA' : 'PINDAH_KAVLING',
                'is_current_holder' => true,
                'source_owner' => Booking::OWNER_LARAVEL,
                'source_tab' => 'Laravel',
            ]);

            if ($sourcePsjb) {
                PsjbRecord::query()->create([
                    'booking_id' => $successor->id,
                    'project_id' => $project->id,
                    'source_key' => $successor->source_booking_key . ':PSJB',
                    'psjb_number' => $sourcePsjb->psjb_number,
                    'psjb_date' => $effectiveDate,
                    'sales_name' => $sourcePsjb->sales_name,
                    'coordinator_name' => $sourcePsjb->coordinator_name,
                    'price' => $successorPrice,
                    'utj_date' => $sourcePsjb->utj_date,
                    'utj_amount' => $sourcePsjb->utj_amount,
                    'utj_source_psjb_id' => $sourcePsjb->utj_source_psjb_id ?: $sourcePsjb->id,
                    'dp_all_in' => $sourcePsjb->dp_all_in,
                    'installment_count' => $sourcePsjb->installment_count,
                    'payment_method' => $sourcePsjb->payment_method,
                    'source_status' => 'ACTIVE',
                    'notes' => $sourcePsjb->notes,
                    'source_tab' => 'Laravel',
                ]);
            }

            $transition = BookingTransition::query()->create([
                'project_id' => $project->id,
                'source_booking_id' => $source->id,
                'successor_booking_id' => $successor->id,
                'user_id' => $actor->id,
                'action' => $action,
                'effective_date' => $effectiveDate,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'source_owner' => BookingTransition::OWNER_LARAVEL,
                'source_key' => $successor->source_booking_key . ':TRANSITION',
                'source_hash' => $fingerprint,
                'payload' => [
                    'source_booking_id' => $source->id,
                    'successor_booking_id' => $successor->id,
                    'action' => $action,
                    'effective_date' => $effectiveDate,
                    'reason' => $reason,
                    'source_lot_id' => $sourceLot->id,
                    'destination_lot_id' => $destinationLot->id,
                    'source_buyer_id' => $source->buyer_id,
                    'successor_buyer_id' => $successorBuyer,
                ],
            ]);

            BookingAudit::record($source->fresh(['psjb']), $actor, BookingAudit::ACTION_BOOKING_CHANGED, $beforeSource, BookingAudit::snapshot($source->fresh(['psjb'])), $this->requestId());
            BookingAudit::record($successor->fresh(['psjb', 'transition']), $actor, BookingAudit::ACTION_BOOKING_CHANGED, null, BookingAudit::snapshot($successor->fresh(['psjb'])), $this->requestId());

            return $successor->fresh(['project', 'lot', 'buyer', 'psjb', 'transition']);
        }, 3);
    }

    public function recordBiCheck(User $actor, Booking $booking, array $input): Booking
    {
        $projectId = (int) $booking->project_id;
        $decision = strtoupper(trim((string) ($input['decision'] ?? '')));
        if (! in_array($decision, ['LANJUT', 'TIDAK LANJUT'], true)) {
            throw new HttpException(422, 'Keputusan BI harus LANJUT atau TIDAK LANJUT.');
        }

        return DB::transaction(function () use ($actor, $booking, $input, $projectId, $decision): Booking {
            $project = Project::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->authorizeWrite($actor, $project);

            if ($locked->project_id !== $project->id || ! in_array($locked->payment_method, ['KPR'], true)) {
                throw new AccessDeniedHttpException('Booking tidak tersedia untuk BI Checking proyek ini.');
            }
            if (! in_array($locked->status, [Booking::STATUS_ACTIVE, Booking::STATUS_NEEDS_REVIEW], true)) {
                throw new HttpException(422, 'Booking sudah memiliki keputusan dan tidak dapat diperiksa ulang.');
            }

            Lot::query()->whereKey($locked->lot_id)->lockForUpdate()->firstOrFail();
            $before = BookingAudit::snapshot($locked);
            $bi = BiCheck::query()->where('booking_id', $locked->id)->lockForUpdate()->first();
            $biAttributes = [
                'booking_id' => $locked->id,
                'project_id' => $project->id,
                'source_key' => $bi?->source_key ?: 'LARAVEL:BI:' . (string) Str::uuid(),
                'bi_number' => $input['bi_number'] ?? $bi?->bi_number,
                'check_date' => $input['check_date'],
                'result' => $input['result'] ?? null,
                'decision' => $decision,
                'notes' => $input['notes'] ?? null,
                'withdrawn_flag' => false,
                'cancelled_flag' => false,
                'moved_lot_flag' => false,
                'name_change_flag' => false,
                'source_owner' => BiCheck::OWNER_LARAVEL,
                'source_tab' => 'Laravel',
            ];
            if ($bi) {
                $bi->update($biAttributes);
            } else {
                $bi = BiCheck::query()->create($biAttributes);
            }

            $locked->update([
                'current_stage' => Booking::STAGE_BI,
                'source_owner' => Booking::OWNER_LARAVEL,
            ]);
            BookingAudit::record($locked->fresh('biCheck'), $actor, BookingAudit::ACTION_BI_CHECKED, $before, BookingAudit::snapshot($locked->fresh('biCheck')), $this->requestId());

            return $locked->fresh(['project', 'lot', 'buyer', 'psjb', 'biCheck']);
        }, 3);
    }

    public function eligiblePemberkasanBookings(Project $project): Builder
    {
        return Booking::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [Booking::STATUS_ACTIVE, Booking::STATUS_NEEDS_REVIEW])
            ->where(function (Builder $query): void {
                $query->where('payment_method', 'CASH')
                    ->orWhere(function (Builder $kpr): void {
                        $kpr->where('payment_method', 'KPR')
                            ->whereHas('biCheck', function (Builder $bi): void {
                                $bi->where('source_owner', BiCheck::OWNER_LARAVEL)
                                    ->where('decision', 'LANJUT')
                                    ->whereRaw('bi_checks.id = (select latest.id from bi_checks as latest where latest.booking_id = bi_checks.booking_id order by latest.updated_at desc, latest.id desc limit 1)');
                            });
                    });
            })
            ->whereIn('current_stage', [Booking::STAGE_PSJB, Booking::STAGE_BI, Booking::STAGE_PEMBERKASAN])
            ->with(['lot', 'buyer', 'biCheck', 'currentPemberkasan'])
            ->orderBy('booking_date')
            ->orderBy('id');
    }

    public function recordPemberkasan(User $actor, Booking $booking, array $input): Booking
    {
        $fileNumber = trim((string) ($input['file_number'] ?? ''));
        if ($fileNumber === '') {
            throw new HttpException(422, 'Nomor berkas wajib diisi.');
        }

        return DB::transaction(function () use ($actor, $booking, $input, $fileNumber): Booking {
            $project = Project::query()->whereKey($booking->project_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->authorizeWrite($actor, $project);

            if (! in_array($project->code, ['PRJ-JPR-KWS', 'PRJ-MGL-KLG'], true) || $locked->project_id !== $project->id) {
                throw new AccessDeniedHttpException('Booking tidak tersedia untuk Pemberkasan proyek ini.');
            }
            if (! in_array($locked->status, [Booking::STATUS_ACTIVE, Booking::STATUS_NEEDS_REVIEW], true)) {
                throw new HttpException(422, 'Booking sudah ditutup.');
            }
            if (! $this->isPemberkasanEligible($locked)) {
                throw new HttpException(422, 'Booking belum memenuhi syarat Pemberkasan.');
            }

            Lot::query()->whereKey($locked->lot_id)->lockForUpdate()->firstOrFail();
            if (PemberkasanRecord::query()->where('booking_id', $locked->id)->where('file_number', $fileNumber)->exists()) {
                throw new HttpException(409, 'Nomor berkas sudah tercatat untuk booking ini.');
            }

            PemberkasanRecord::query()->where('booking_id', $locked->id)->update(['is_current' => false]);
            $revision = (int) PemberkasanRecord::query()->where('booking_id', $locked->id)->max('revision') + 1;
            $record = PemberkasanRecord::query()->create([
                'booking_id' => $locked->id,
                'project_id' => $project->id,
                'source_key' => 'LARAVEL:BRK:' . (string) Str::uuid(),
                'file_number' => $fileNumber,
                'received_date' => $input['received_date'] ?? null,
                'bank' => $input['bank'] ?? null,
                'bank_unit' => $input['bank_unit'] ?? null,
                'status' => $input['status'] ?? null,
                'withdrawn_flag' => false,
                'cancelled_flag' => false,
                'moved_lot_flag' => false,
                'name_change_flag' => false,
                'notes' => $input['notes'] ?? null,
                'source_owner' => PemberkasanRecord::OWNER_LARAVEL,
                'revision' => $revision,
                'is_current' => true,
                'source_tab' => 'Laravel',
            ]);

            $before = BookingAudit::snapshot($locked);
            $locked->update(['current_stage' => Booking::STAGE_PEMBERKASAN, 'source_owner' => Booking::OWNER_LARAVEL]);
            BookingAudit::record($locked->fresh(['pemberkasan', 'currentPemberkasan']), $actor, BookingAudit::ACTION_PEMBERKASAN_CREATED, $before, BookingAudit::snapshot($locked->fresh(['pemberkasan', 'currentPemberkasan'])), $this->requestId());

            return $locked->fresh(['project', 'lot', 'buyer', 'psjb', 'biCheck', 'pemberkasan', 'currentPemberkasan']);
        }, 3);
    }

    public function eligibleBankBookings(Project $project): Builder
    {
        return Booking::query()
            ->where('project_id', $project->id)
            ->where('payment_method', 'KPR')
            ->where('status', Booking::STATUS_ACTIVE)
            ->whereIn('current_stage', [Booking::STAGE_PEMBERKASAN, Booking::STAGE_BANK])
            ->whereHas('currentPemberkasan', function (Builder $query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->whereIn('status', self::BANK_PEMBERKASAN_STATUSES);
            })
            ->with(['lot', 'buyer', 'currentPemberkasan', 'bankSubmissions'])
            ->orderBy('booking_date')
            ->orderBy('id');
    }

    public function recordBankSubmission(User $actor, Booking $booking, PemberkasanRecord $pemberkasan, array $input): BankSubmission
    {
        [$bank, $bankUnit, $submittedDate, $notes, $idempotencyKey] = $this->bankSubmissionPayload($input);
        $project = Project::query()->whereKey($booking->project_id)->firstOrFail();
        $this->authorizeBankWrite($actor, $project);

        try {
            return DB::transaction(function () use ($actor, $booking, $pemberkasan, $project, $bank, $bankUnit, $submittedDate, $notes, $idempotencyKey): BankSubmission {
                $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $this->authorizeBankWrite($actor, $project);

                $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
                Lot::query()->whereKey($booking->lot_id)->lockForUpdate()->firstOrFail();
                $pemberkasan = PemberkasanRecord::query()->whereKey($pemberkasan->id)->lockForUpdate()->firstOrFail();

                $fingerprint = $this->bankFingerprint(
                    $booking->id,
                    $pemberkasan->id,
                    $bank,
                    $bankUnit,
                    $submittedDate,
                    $notes,
                );
                $existing = BankSubmission::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->resolveBankSubmissionReplay($existing, $fingerprint);
                }

                $this->assertBankSubmissionEligible($booking, $pemberkasan, $project);

                $before = BookingAudit::snapshot($booking);
                $submission = BankSubmission::query()->create([
                    'booking_id' => $booking->id,
                    'project_id' => $project->id,
                    'pemberkasan_id' => $pemberkasan->id,
                    'source_key' => 'LARAVEL:BANK:' . (string) Str::uuid(),
                    'file_number' => $pemberkasan->file_number,
                    'submission_sequence' => (int) BankSubmission::query()->where('booking_id', $booking->id)->max('submission_sequence') + 1,
                    'bank' => $bank,
                    'bank_unit' => $bankUnit,
                    'submitted_date' => $submittedDate,
                    'result' => null,
                    'approved_amount' => null,
                    'tenor' => null,
                    'withdrawn_flag' => false,
                    'cancelled_flag' => false,
                    'moved_lot_flag' => false,
                    'name_change_flag' => false,
                    'notes' => $notes,
                    'source_owner' => BankSubmission::OWNER_LARAVEL,
                    'idempotency_key' => $idempotencyKey,
                    'source_tab' => 'Laravel',
                    'source_hash' => $fingerprint,
                ]);

                $booking->update([
                    'current_stage' => Booking::STAGE_BANK,
                    'source_owner' => Booking::OWNER_LARAVEL,
                ]);
                BookingAudit::record(
                    $booking->fresh(),
                    $actor,
                    BookingAudit::ACTION_BANK_SUBMISSION_CREATED,
                    $before,
                    BookingAudit::snapshot($booking->fresh()),
                    $this->requestId(),
                );

                return $submission->fresh(['booking', 'pemberkasan']);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isBankSubmissionIdempotencyConflict($exception)) {
                throw $exception;
            }

            return DB::transaction(function () use ($exception, $booking, $pemberkasan, $idempotencyKey, $bank, $bankUnit, $submittedDate, $notes): BankSubmission {
                $existing = BankSubmission::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    throw $exception;
                }

                return $this->resolveBankSubmissionReplay(
                    $existing,
                    $this->bankFingerprint(
                        $booking->id,
                        $pemberkasan->id,
                        $bank,
                        $bankUnit,
                        $submittedDate,
                        $notes,
                    ),
                );
            }, 3);
        }
    }

    private function resolveBankSubmissionReplay(BankSubmission $submission, string $fingerprint): BankSubmission
    {
        if (! hash_equals((string) $submission->source_hash, $fingerprint)) {
            throw new HttpException(409, 'Kunci idempotensi sudah dipakai untuk permintaan berbeda.');
        }

        return $submission->fresh(['booking', 'pemberkasan']);
    }

    private function isBankSubmissionIdempotencyConflict(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $message = strtolower($exception->getMessage());

        return ((string) ($errorInfo[0] ?? $exception->getCode()) === '23000'
                && (int) ($errorInfo[1] ?? 0) === 1062)
            && str_contains($message, 'idempotency_key');
    }

    public function recordBankResult(User $actor, BankSubmission $submission, array $input): BankSubmission
    {
        $changes = $this->bankResultChanges($input);
        $project = Project::query()->whereKey($submission->project_id)->firstOrFail();
        $this->authorizeBankWrite($actor, $project);

        return DB::transaction(function () use ($actor, $submission, $project, $input, $changes): BankSubmission {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->authorizeBankWrite($actor, $project);

            $booking = Booking::query()->whereKey($submission->booking_id)->lockForUpdate()->firstOrFail();
            Lot::query()->whereKey($booking->lot_id)->lockForUpdate()->firstOrFail();
            if ($submission->pemberkasan_id) {
                PemberkasanRecord::query()->whereKey($submission->pemberkasan_id)->lockForUpdate()->firstOrFail();
            }
            $submission = BankSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            $this->assertBankResultTarget($submission, $booking, $project);
            $this->assertUnmodifiedIdentifiers($submission, $input);

            if ($submission->source_owner !== BankSubmission::OWNER_LARAVEL) {
                throw new HttpException(422, 'Submission Bank milik Sheets tidak dapat diubah dari Laravel.');
            }

            if ($submission->result !== null) {
                if ($this->bankResultMatches($submission, $changes)) {
                    return $submission->fresh(['booking', 'pemberkasan']);
                }

                throw new HttpException(409, 'Hasil Bank sudah tercatat dan tidak dapat diubah.');
            }

            $before = BookingAudit::snapshot($booking);
            $submission->update($changes);
            BookingAudit::record(
                $booking->fresh(),
                $actor,
                BookingAudit::ACTION_BANK_RESULT_RECORDED,
                $before,
                BookingAudit::snapshot($booking->fresh()),
                $this->requestId(),
            );

            return $submission->fresh(['booking', 'pemberkasan']);
        }, 3);
    }

    public function eligibleTtdPpjbBookings(Project $project): Builder
    {
        return Booking::query()
            ->where('project_id', $project->id)
            ->where('status', Booking::STATUS_ACTIVE)
            ->whereDoesntHave('ppjb')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $kpr): void {
                    $kpr->where('payment_method', 'KPR')
                        ->where('current_stage', Booking::STAGE_BANK)
                        ->whereHas('bankSubmissions', fn (Builder $bank) => $bank->where('result', 'APPROVE'));
                })->orWhere(function (Builder $cash): void {
                    $cash->where('payment_method', 'CASH')
                        ->where('current_stage', Booking::STAGE_PEMBERKASAN)
                        ->whereHas('currentPemberkasan', fn (Builder $file) => $file->where('status', self::CASH_PEMBERKASAN_STATUS));
                });
            })
            ->with(['lot', 'buyer', 'currentPemberkasan', 'bankSubmissions' => fn ($q) => $q->where('result', 'APPROVE')])
            ->orderBy('booking_date')
            ->orderBy('id');
    }

    public function recordPpjb(User $actor, Booking $booking, array $input): PpjbRecord
    {
        [$ppjbNumber, $signedDate, $dpStatus, $notes, $idempotencyKey, $bankSubmissionId, $pemberkasanId] = $this->ppjbPayload($input);
        $project = Project::query()->whereKey($booking->project_id)->firstOrFail();
        $this->authorizePpjbWrite($actor, $project);

        return DB::transaction(function () use (
            $actor, $booking, $project, $ppjbNumber, $signedDate, $dpStatus, $notes, $idempotencyKey, $bankSubmissionId, $pemberkasanId,
        ): PpjbRecord {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->authorizePpjbWrite($actor, $project);

            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            Lot::query()->whereKey($booking->lot_id)->lockForUpdate()->firstOrFail();

            $bankSubmission = $bankSubmissionId
                ? BankSubmission::query()->whereKey($bankSubmissionId)->lockForUpdate()->first()
                : null;
            $pemberkasan = $pemberkasanId
                ? PemberkasanRecord::query()->whereKey($pemberkasanId)->lockForUpdate()->first()
                : null;

            $fingerprint = $this->ppjbFingerprint($booking->id, $bankSubmissionId, $pemberkasanId, $ppjbNumber, $signedDate, $dpStatus, $notes);
            $existing = PpjbRecord::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->source_hash, $fingerprint)) {
                    throw new HttpException(409, 'Kunci idempotensi sudah dipakai untuk permintaan berbeda.');
                }

                return $existing->fresh(['booking', 'bankSubmission', 'pemberkasan']);
            }

            $this->assertPpjbEligible($booking, $project, $bankSubmission, $pemberkasan);

            if (PpjbRecord::query()->where('booking_id', $booking->id)->exists()) {
                throw new HttpException(409, 'Booking ini sudah memiliki PPJB yang ditandatangani.');
            }

            $before = BookingAudit::snapshot($booking);
            $record = PpjbRecord::query()->create([
                'booking_id' => $booking->id,
                'bank_submission_id' => $bankSubmission?->id,
                'pemberkasan_id' => $pemberkasan?->id,
                'project_id' => $project->id,
                'source_key' => 'LARAVEL:PPJB:' . (string) Str::uuid(),
                'ppjb_number' => $ppjbNumber,
                'signed_date' => $signedDate,
                'dp_status' => $dpStatus,
                'withdrawn_flag' => false,
                'cancelled_flag' => false,
                'moved_lot_flag' => false,
                'name_change_flag' => false,
                'notes' => $notes,
                'source_owner' => PpjbRecord::OWNER_LARAVEL,
                'idempotency_key' => $idempotencyKey,
                'source_tab' => 'Laravel',
                'source_hash' => $fingerprint,
            ]);

            $booking->update([
                'current_stage' => Booking::STAGE_PPJB,
                'source_owner' => Booking::OWNER_LARAVEL,
            ]);
            BookingAudit::record(
                $booking->fresh(),
                $actor,
                BookingAudit::ACTION_PPJB_CREATED,
                $before,
                BookingAudit::snapshot($booking->fresh()),
                $this->requestId(),
            );

            return $record->fresh(['booking', 'bankSubmission', 'pemberkasan']);
        }, 3);
    }

    public function eligibleAkadBookings(Project $project): Builder
    {
        return Booking::query()
            ->where('project_id', $project->id)
            ->where('status', Booking::STATUS_ACTIVE)
            ->where('current_stage', Booking::STAGE_PPJB)
            ->whereDoesntHave('akad')
            ->whereHas('ppjb', function (Builder $query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->where('withdrawn_flag', false)
                    ->where('cancelled_flag', false);
            })
            ->with(['lot', 'buyer', 'ppjb'])
            ->orderBy('booking_date')
            ->orderBy('id');
    }

    public function recordAkad(User $actor, Booking $booking, array $input): AkadRecord
    {
        [$ppjbId, $akadNumber, $akadDate, $quality, $notes, $idempotencyKey] = $this->akadPayload($input);
        $project = Project::query()->whereKey($booking->project_id)->firstOrFail();
        $this->authorizeAkadWrite($actor, $project);

        return DB::transaction(function () use (
            $actor, $booking, $project, $ppjbId, $akadNumber, $akadDate, $quality, $notes, $idempotencyKey,
        ): AkadRecord {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAkadWrite($actor, $project);

            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            Lot::query()->whereKey($booking->lot_id)->lockForUpdate()->firstOrFail();
            $ppjb = PpjbRecord::query()->whereKey($ppjbId)->lockForUpdate()->first();

            $fingerprint = $this->akadFingerprint($booking->id, $ppjbId, $akadNumber, $akadDate, $quality, $notes);
            $existing = AkadRecord::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->source_hash, $fingerprint)) {
                    throw new HttpException(409, 'Kunci idempotensi sudah dipakai untuk permintaan berbeda.');
                }

                return $existing->fresh(['booking', 'ppjb']);
            }

            $this->assertAkadEligible($booking, $project, $ppjb);

            if (AkadRecord::query()->where('booking_id', $booking->id)->exists()) {
                throw new HttpException(409, 'Booking ini sudah memiliki Akad.');
            }

            $before = BookingAudit::snapshot($booking);
            $record = AkadRecord::query()->create([
                'booking_id' => $booking->id,
                'ppjb_id' => $ppjb->id,
                'project_id' => $project->id,
                'source_key' => 'LARAVEL:AKAD:' . (string) Str::uuid(),
                'akad_number' => $akadNumber,
                'akad_date' => $akadDate,
                'quality' => $quality,
                'withdrawn_flag' => false,
                'cancelled_flag' => false,
                'moved_lot_flag' => false,
                'name_change_flag' => false,
                'notes' => $notes,
                'source_owner' => AkadRecord::OWNER_LARAVEL,
                'idempotency_key' => $idempotencyKey,
                'source_tab' => 'Laravel',
                'source_hash' => $fingerprint,
            ]);
            $booking->update([
                'current_stage' => Booking::STAGE_AKAD,
                'akad_date' => $akadDate,
                'source_owner' => Booking::OWNER_LARAVEL,
            ]);
            BookingAudit::record(
                $booking->fresh(),
                $actor,
                BookingAudit::ACTION_AKAD_CREATED,
                $before,
                BookingAudit::snapshot($booking->fresh()),
                $this->requestId(),
            );

            return $record->fresh(['booking', 'ppjb']);
        }, 3);
    }

    public function eligibleBastBookings(Project $project): Builder
    {
        return Booking::query()
            ->where('project_id', $project->id)
            ->where('status', Booking::STATUS_ACTIVE)
            ->where('current_stage', Booking::STAGE_AKAD)
            ->whereDoesntHave('bast')
            ->whereHas('akad', function (Builder $query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->where('withdrawn_flag', false)
                    ->where('cancelled_flag', false);
            })
            ->with(['lot', 'buyer', 'akad'])
            ->orderBy('booking_date')
            ->orderBy('id');
    }

    public function recordBast(User $actor, Booking $booking, array $input): BastRecord
    {
        [$akadId, $bastNumber, $bastDate, $notes, $idempotencyKey] = $this->bastPayload($input);
        $project = Project::query()->whereKey($booking->project_id)->firstOrFail();
        $this->authorizeBastWrite($actor, $project);

        return DB::transaction(function () use (
            $actor, $booking, $project, $akadId, $bastNumber, $bastDate, $notes, $idempotencyKey,
        ): BastRecord {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->authorizeBastWrite($actor, $project);

            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            Lot::query()->whereKey($booking->lot_id)->lockForUpdate()->firstOrFail();
            $akad = AkadRecord::query()->whereKey($akadId)->lockForUpdate()->first();

            $fingerprint = $this->bastFingerprint($booking->id, $akadId, $bastNumber, $bastDate, $notes);
            $existing = BastRecord::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->source_hash, $fingerprint)) {
                    throw new HttpException(409, 'Kunci idempotensi sudah dipakai untuk permintaan berbeda.');
                }

                return $existing->fresh(['booking', 'akad']);
            }

            $this->assertBastEligible($booking, $project, $akad);

            if (BastRecord::query()->where('booking_id', $booking->id)->exists()) {
                throw new HttpException(409, 'Booking ini sudah memiliki BAST.');
            }

            $before = BookingAudit::snapshot($booking);
            $record = BastRecord::query()->create([
                'booking_id' => $booking->id,
                'akad_id' => $akad->id,
                'project_id' => $project->id,
                'source_key' => 'LARAVEL:BAST:' . (string) Str::uuid(),
                'bast_number' => $bastNumber,
                'bast_date' => $bastDate,
                'notes' => $notes,
                'source_owner' => BastRecord::OWNER_LARAVEL,
                'idempotency_key' => $idempotencyKey,
                'source_tab' => 'Laravel',
                'source_hash' => $fingerprint,
            ]);
            $booking->update([
                'current_stage' => Booking::STAGE_BAST,
                'status' => Booking::STATUS_COMPLETED,
                'bast_date' => $bastDate,
                'is_current_holder' => true,
                'source_owner' => Booking::OWNER_LARAVEL,
            ]);
            BookingAudit::record(
                $booking->fresh(),
                $actor,
                BookingAudit::ACTION_BAST_CREATED,
                $before,
                BookingAudit::snapshot($booking->fresh()),
                $this->requestId(),
            );

            return $record->fresh(['booking', 'akad']);
        }, 3);
    }

    private function assertBastEligible(Booking $booking, Project $project, ?AkadRecord $akad): void
    {
        if ($booking->project_id !== $project->id) {
            throw new AccessDeniedHttpException('Booking tidak sesuai dengan proyek ini.');
        }
        if ($booking->status !== Booking::STATUS_ACTIVE) {
            throw new HttpException(422, 'Booking harus berstatus Aktif untuk BAST.');
        }
        if ($booking->current_stage !== Booking::STAGE_AKAD) {
            throw new HttpException(422, 'Booking belum berada pada tahap Akad.');
        }
        if (! $akad || $akad->booking_id !== $booking->id || $akad->project_id !== $project->id) {
            throw new AccessDeniedHttpException('Akad tidak sesuai dengan booking atau proyek ini.');
        }
        if ($akad->withdrawn_flag || $akad->cancelled_flag) {
            throw new HttpException(422, 'Akad yang dipilih tidak berstatus aktif.');
        }
    }

    private function bastPayload(array $input): array
    {
        $bastNumber = trim((string) ($input['bast_number'] ?? ''));
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        $validator = Validator::make(
            [
                'bast_number' => $bastNumber,
                'bast_date' => $input['bast_date'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'bast_number' => ['required', 'string', 'max:96'],
                'bast_date' => ['required', 'date'],
                'idempotency_key' => ['required', 'string', 'max:255'],
            ],
        );
        if ($validator->fails()) {
            throw new HttpException(422, 'Data BAST tidak valid.');
        }

        $akadId = $input['akad_id'] ?? null;
        if (! $akadId) {
            throw new HttpException(422, 'Akad sumber wajib dipilih.');
        }
        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            (int) $akadId,
            $bastNumber,
            Carbon::parse($input['bast_date'])->toDateString(),
            $notes === '' ? null : $notes,
            $idempotencyKey,
        ];
    }

    private function bastFingerprint(
        int $bookingId,
        int $akadId,
        string $bastNumber,
        string $bastDate,
        ?string $notes,
    ): string {
        return hash('sha256', json_encode([
            'booking_id' => $bookingId,
            'akad_id' => $akadId,
            'bast_number' => $bastNumber,
            'bast_date' => $bastDate,
            'notes' => $notes,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertAkadEligible(Booking $booking, Project $project, ?PpjbRecord $ppjb): void
    {
        if ($booking->project_id !== $project->id) {
            throw new AccessDeniedHttpException('Booking tidak sesuai dengan proyek ini.');
        }
        if ($booking->status !== Booking::STATUS_ACTIVE) {
            throw new HttpException(422, 'Booking harus berstatus Aktif untuk Akad.');
        }
        if ($booking->current_stage !== Booking::STAGE_PPJB) {
            throw new HttpException(422, 'Booking belum berada pada tahap PPJB.');
        }
        if (! $ppjb || $ppjb->booking_id !== $booking->id || $ppjb->project_id !== $project->id) {
            throw new AccessDeniedHttpException('PPJB tidak sesuai dengan booking atau proyek ini.');
        }
        if ($ppjb->withdrawn_flag || $ppjb->cancelled_flag) {
            throw new HttpException(422, 'PPJB yang dipilih tidak berstatus aktif.');
        }
    }

    private function akadPayload(array $input): array
    {
        $akadNumber = trim((string) ($input['akad_number'] ?? ''));
        $quality = trim((string) ($input['quality'] ?? ''));
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        $validator = Validator::make(
            [
                'akad_number' => $akadNumber,
                'akad_date' => $input['akad_date'] ?? null,
                'quality' => $quality,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'akad_number' => ['required', 'string', 'max:96'],
                'akad_date' => ['required', 'date'],
                'quality' => ['required', 'string', 'max:64'],
                'idempotency_key' => ['required', 'string', 'max:255'],
            ],
        );
        if ($validator->fails()) {
            throw new HttpException(422, 'Data Akad tidak valid.');
        }

        $ppjbId = $input['ppjb_id'] ?? null;
        if (! $ppjbId) {
            throw new HttpException(422, 'PPJB sumber wajib dipilih.');
        }
        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            (int) $ppjbId,
            $akadNumber,
            Carbon::parse($input['akad_date'])->toDateString(),
            $quality,
            $notes === '' ? null : $notes,
            $idempotencyKey,
        ];
    }

    private function akadFingerprint(
        int $bookingId,
        int $ppjbId,
        string $akadNumber,
        string $akadDate,
        string $quality,
        ?string $notes,
    ): string {
        return hash('sha256', json_encode([
            'booking_id' => $bookingId,
            'ppjb_id' => $ppjbId,
            'akad_number' => $akadNumber,
            'akad_date' => $akadDate,
            'quality' => $quality,
            'notes' => $notes,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertPpjbEligible(Booking $booking, Project $project, ?BankSubmission $bankSubmission, ?PemberkasanRecord $pemberkasan): void
    {
        if ($booking->project_id !== $project->id) {
            throw new AccessDeniedHttpException('Booking tidak sesuai dengan proyek ini.');
        }
        if ($booking->status !== Booking::STATUS_ACTIVE) {
            throw new HttpException(422, 'Booking harus berstatus Aktif untuk TTD PPJB.');
        }

        if ($booking->payment_method === 'KPR') {
            if ($booking->current_stage !== Booking::STAGE_BANK) {
                throw new HttpException(422, 'Booking belum berada pada tahap Proses Bank.');
            }
            if (! $bankSubmission || $bankSubmission->booking_id !== $booking->id || $bankSubmission->project_id !== $project->id) {
                throw new AccessDeniedHttpException('Submission Bank tidak sesuai dengan booking atau proyek ini.');
            }
            if ($bankSubmission->result !== 'APPROVE') {
                throw new HttpException(422, 'Submission Bank yang dipilih belum disetujui (APPROVE).');
            }

            return;
        }

        if ($booking->payment_method === 'CASH') {
            if ($booking->current_stage !== Booking::STAGE_PEMBERKASAN) {
                throw new HttpException(422, 'Booking belum berada pada tahap Pemberkasan.');
            }
            if (! $pemberkasan || $pemberkasan->booking_id !== $booking->id || $pemberkasan->project_id !== $project->id) {
                throw new AccessDeniedHttpException('Berkas Pemberkasan tidak sesuai dengan booking atau proyek ini.');
            }
            if (! $pemberkasan->is_current) {
                throw new HttpException(422, 'Berkas yang dipilih bukan revisi terbaru.');
            }
            if ((string) $pemberkasan->status !== self::CASH_PEMBERKASAN_STATUS) {
                throw new HttpException(422, 'Status berkas belum memenuhi syarat TTD PPJB.');
            }

            return;
        }

        throw new HttpException(422, 'Metode pembayaran tidak dikenal.');
    }

    private function ppjbPayload(array $input): array
    {
        $ppjbNumber = trim((string) ($input['ppjb_number'] ?? ''));
        if ($ppjbNumber === '') {
            throw new HttpException(422, 'No. PPJB wajib diisi.');
        }

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 255) {
            throw new HttpException(422, 'Kunci idempotensi wajib diisi dan maksimal 255 karakter.');
        }

        $validator = Validator::make(
            ['signed_date' => $input['signed_date'] ?? null],
            ['signed_date' => ['required', 'date']],
        );
        if ($validator->fails()) {
            throw new HttpException(422, 'Tanggal TTD PPJB tidak valid.');
        }

        $dpStatus = trim((string) ($input['dp_status'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $bankSubmissionId = $input['bank_submission_id'] ?? null;
        $pemberkasanId = $input['pemberkasan_id'] ?? null;

        if ((bool) $bankSubmissionId === (bool) $pemberkasanId) {
            throw new HttpException(422, 'Pilih tepat satu sumber: submission Bank atau berkas Pemberkasan.');
        }

        return [
            $ppjbNumber,
            Carbon::parse($input['signed_date'])->toDateString(),
            $dpStatus === '' ? null : $dpStatus,
            $notes === '' ? null : $notes,
            $idempotencyKey,
            $bankSubmissionId ? (int) $bankSubmissionId : null,
            $pemberkasanId ? (int) $pemberkasanId : null,
        ];
    }

    private function ppjbFingerprint(
        int $bookingId,
        ?int $bankSubmissionId,
        ?int $pemberkasanId,
        string $ppjbNumber,
        string $signedDate,
        ?string $dpStatus,
        ?string $notes,
    ): string {
        return hash('sha256', json_encode([
            'booking_id' => $bookingId,
            'bank_submission_id' => $bankSubmissionId,
            'pemberkasan_id' => $pemberkasanId,
            'ppjb_number' => $ppjbNumber,
            'signed_date' => $signedDate,
            'dp_status' => $dpStatus,
            'notes' => $notes,
        ], JSON_THROW_ON_ERROR));
    }

    private function authorizeBankWrite(User $actor, Project $project): void
    {
        $this->authorizePilotWrite($actor, $project, 'Proses Bank hanya tersedia untuk proyek pilot.');
    }

    private function authorizePpjbWrite(User $actor, Project $project): void
    {
        $this->authorizePilotWrite($actor, $project, 'TTD PPJB hanya tersedia untuk proyek pilot.');
    }

    private function authorizeAkadWrite(User $actor, Project $project): void
    {
        $this->authorizePilotWrite($actor, $project, 'Akad hanya tersedia untuk proyek pilot.');
    }

    private function authorizeBastWrite(User $actor, Project $project): void
    {
        $this->authorizePilotWrite($actor, $project, 'BAST hanya tersedia untuk proyek pilot.');
    }

    private function authorizePilotWrite(User $actor, Project $project, string $message): void
    {
        $this->authorizeWrite($actor, $project);

        if (! in_array($project->code, self::PILOT_PROJECT_CODES, true)) {
            throw new AccessDeniedHttpException($message);
        }
    }

    private function assertBankSubmissionEligible(Booking $booking, PemberkasanRecord $pemberkasan, Project $project): void
    {
        if ($booking->project_id !== $project->id
            || $pemberkasan->booking_id !== $booking->id
            || $pemberkasan->project_id !== $project->id) {
            throw new AccessDeniedHttpException('Booking atau berkas tidak sesuai dengan proyek ini.');
        }

        if ($booking->payment_method !== 'KPR') {
            throw new HttpException(422, 'Proses Bank hanya untuk booking KPR.');
        }
        if ($booking->status !== Booking::STATUS_ACTIVE) {
            throw new HttpException(422, 'Booking harus berstatus Aktif untuk Proses Bank.');
        }
        if (! in_array($booking->current_stage, [Booking::STAGE_PEMBERKASAN, Booking::STAGE_BANK], true)) {
            throw new HttpException(422, 'Booking belum berada pada tahap Pemberkasan atau Proses Bank.');
        }
        if (! $pemberkasan->is_current) {
            throw new HttpException(422, 'Berkas yang dipilih bukan revisi terbaru.');
        }
        if (! in_array((string) $pemberkasan->status, self::BANK_PEMBERKASAN_STATUSES, true)) {
            throw new HttpException(422, 'Status berkas belum memenuhi syarat Proses Bank.');
        }
    }

    private function assertBankResultTarget(BankSubmission $submission, Booking $booking, Project $project): void
    {
        if ($submission->booking_id !== $booking->id || $submission->project_id !== $project->id) {
            throw new AccessDeniedHttpException('Submission Bank tidak sesuai dengan booking atau proyek.');
        }
        if (! $submission->pemberkasan_id) {
            throw new HttpException(422, 'Submission Bank tidak memiliki revisi berkas yang pasti.');
        }

        $pemberkasan = PemberkasanRecord::query()->whereKey($submission->pemberkasan_id)->first();
        if (! $pemberkasan || $pemberkasan->booking_id !== $booking->id || $pemberkasan->project_id !== $project->id) {
            throw new AccessDeniedHttpException('Berkas submission tidak sesuai dengan booking atau proyek.');
        }
    }

    private function assertUnmodifiedIdentifiers(BankSubmission $submission, array $input): void
    {
        foreach (['booking_id', 'project_id', 'pemberkasan_id'] as $field) {
            if (array_key_exists($field, $input) && (int) $input[$field] !== (int) $submission->{$field}) {
                throw new HttpException(422, 'Identitas submission tidak boleh diubah.');
            }
        }
    }

    private function bankSubmissionPayload(array $input): array
    {
        $bank = trim((string) ($input['bank'] ?? ''));
        if ($bank === '') {
            throw new HttpException(422, 'Nama bank wajib diisi.');
        }

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 255) {
            throw new HttpException(422, 'Kunci idempotensi wajib diisi dan maksimal 255 karakter.');
        }

        $validator = Validator::make(
            ['submitted_date' => $input['submitted_date'] ?? null],
            ['submitted_date' => ['required', 'date']],
        );
        if ($validator->fails()) {
            throw new HttpException(422, 'Tanggal pengajuan Bank tidak valid.');
        }

        $bankUnit = trim((string) ($input['bank_unit'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            $bank,
            $bankUnit === '' ? null : $bankUnit,
            Carbon::parse($input['submitted_date'])->toDateString(),
            $notes === '' ? null : $notes,
            $idempotencyKey,
        ];
    }

    private function bankFingerprint(
        int $bookingId,
        int $pemberkasanId,
        string $bank,
        ?string $bankUnit,
        string $submittedDate,
        ?string $notes,
    ): string {
        return hash('sha256', json_encode([
            'booking_id' => $bookingId,
            'pemberkasan_id' => $pemberkasanId,
            'bank' => $bank,
            'bank_unit' => $bankUnit,
            'submitted_date' => $submittedDate,
            'notes' => $notes,
        ], JSON_THROW_ON_ERROR));
    }

    private function bankResultChanges(array $input): array
    {
        $result = trim((string) ($input['result'] ?? ''));
        if (! in_array($result, BankSubmission::RESULTS, true)) {
            throw new HttpException(422, 'Hasil Bank harus APPROVE, REJECT, atau REVISI.');
        }

        $changes = ['result' => $result];

        if (array_key_exists('decision_date', $input)) {
            $date = $input['decision_date'];
            if ($date === null || $date === '') {
                $changes['decision_date'] = null;
            } else {
                $validator = Validator::make(['decision_date' => $date], ['decision_date' => ['date']]);
                if ($validator->fails()) {
                    throw new HttpException(422, 'Tanggal putusan Bank tidak valid.');
                }
                $changes['decision_date'] = Carbon::parse($date)->toDateString();
            }
        }

        if (array_key_exists('approved_amount', $input)) {
            $amount = $input['approved_amount'];
            if ($amount === null || $amount === '') {
                $changes['approved_amount'] = null;
            } elseif (! is_numeric($amount)) {
                throw new HttpException(422, 'Plafon disetujui harus berupa angka.');
            } else {
                $changes['approved_amount'] = $amount;
            }
        }

        foreach (['tenor', 'notes'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = trim((string) ($input[$field] ?? ''));
                $changes[$field] = $value === '' ? null : $value;
            }
        }

        return $changes;
    }

    private function bankResultMatches(BankSubmission $submission, array $changes): bool
    {
        foreach ($changes as $field => $value) {
            $current = $submission->{$field};

            if ($field === 'decision_date') {
                $current = $current?->toDateString();
                $value = $value === null ? null : Carbon::parse($value)->toDateString();
            } elseif ($field === 'approved_amount') {
                $current = $current === null ? null : (float) $current;
                $value = $value === null ? null : (float) $value;
            } else {
                $current = $current === null ? null : (string) $current;
                $value = $value === null ? null : (string) $value;
            }

            if ($current !== $value) {
                return false;
            }
        }

        return true;
    }

    private function isPemberkasanEligible(Booking $booking): bool
    {
        if ($booking->payment_method === 'CASH') {
            return true;
        }
        if ($booking->payment_method !== 'KPR') {
            return false;
        }

        $latest = BiCheck::query()->where('booking_id', $booking->id)->latest('updated_at')->latest('id')->first();
        return $latest?->source_owner === BiCheck::OWNER_LARAVEL && $latest->decision === 'LANJUT';
    }

    private function assertChangeBeforeSignedDocuments(Booking $booking): void
    {
        if ($booking->ppjb()->exists() || $booking->akad()->exists() || $booking->bast()->exists()) {
            throw new HttpException(422, 'Ganti nama atau pindah kavling setelah dokumen ditandatangani masih dikelola melalui Sheets.');
        }
    }

    private function buyerBelongsToProject(Buyer $buyer, Project $project): bool
    {
        return str_starts_with((string) $buyer->source_key, $project->code . ':')
            || $buyer->bookings()->where('project_id', $project->id)->exists();
    }

    private function bookingChangeFingerprint(
        Booking $source,
        string $action,
        array $input,
        string $effectiveDate,
        string $reason,
    ): string {
        return hash('sha256', json_encode([
            'source_booking_id' => $source->id,
            'action' => $action,
            'buyer_id' => $input['buyer_id'] ?? null,
            'lot_id' => $input['lot_id'] ?? null,
            'price' => $input['price'] ?? null,
            'confirm_price_change' => filter_var($input['confirm_price_change'] ?? false, FILTER_VALIDATE_BOOL),
            'effective_date' => $effectiveDate,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }

    public function withdraw(User $actor, Booking $booking, ?string $note = null): Booking
    {
        return $this->decide($actor, $booking, Booking::STATUS_MUNDUR, BookingAudit::ACTION_WITHDRAWN, $note);
    }

    public function cancel(User $actor, Booking $booking, ?string $note = null): Booking
    {
        return $this->decide($actor, $booking, Booking::STATUS_BATAL, BookingAudit::ACTION_CANCELLED, $note);
    }

    private function decide(User $actor, Booking $booking, string $status, string $action, ?string $note): Booking
    {
        return DB::transaction(function () use ($actor, $booking, $status, $action, $note): Booking {
            $project = Project::query()->whereKey($booking->project_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->authorizeWrite($actor, $project);

            if (! in_array($locked->status, [Booking::STATUS_ACTIVE, Booking::STATUS_NEEDS_REVIEW], true)) {
                throw new HttpException(422, 'Booking sudah memiliki keputusan dan tidak dapat diubah.');
            }

            $before = BookingAudit::snapshot($locked);
            Lot::query()->whereKey($locked->lot_id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status' => $status,
                'decision_stage' => in_array($locked->current_stage, [Booking::STAGE_BI, Booking::STAGE_PEMBERKASAN], true) ? $locked->current_stage : Booking::STAGE_PSJB,
                'decision_date' => now($project->timezone)->toDateString(),
                'decision_note' => $note,
                'is_current_holder' => false,
                'source_owner' => Booking::OWNER_LARAVEL,
            ]);

            $claims = $this->validClaimQuery($locked->lot_id)->lockForUpdate()->get();
            if ($claims->count() === 1) {
                $claims->first()->update(['is_current_holder' => true]);
            } elseif ($claims->count() > 1) {
                $claims->each->update(['is_current_holder' => false]);
            }

            BookingAudit::record($locked, $actor, $action, $before, BookingAudit::snapshot($locked), $this->requestId());

            return $locked->fresh(['project', 'lot', 'buyer', 'psjb']);
        }, 3);
    }

    private function requestId(): string
    {
        return app()->bound('request') ? (request()?->header('X-Request-ID') ?? (string) Str::uuid()) : (string) Str::uuid();
    }

    private function resolveBuyer(Project $project, array $input): Buyer
    {
        if (! empty($input['buyer_id'])) {
            return Buyer::query()
                ->whereKey($input['buyer_id'])
                ->where(function (Builder $query) use ($project) {
                    $query->where('source_key', 'like', $project->code . ':%')
                        ->orWhereHas('bookings', fn (Builder $bookings) => $bookings->where('project_id', $project->id));
                })
                ->firstOrFail();
        }

        $nik = Normalizer::text($input['nik'] ?? null);
        $nikHash = Normalizer::nikHash($nik);
        $query = Buyer::query()->where('source_key', 'like', $project->code . ':%');
        if ($nikHash) {
            $buyer = (clone $query)->where('nik_hash', $nikHash)->first();
            if ($buyer) {
                return $buyer;
            }
        }

        $name = Normalizer::text($input['buyer_name'] ?? null);
        $buyer = $query
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $name)])
            ->first();
        if ($buyer) {
            return $buyer;
        }

        return Buyer::query()->create([
            'source_key' => $project->code . ':BUYER:LARAVEL:' . (string) Str::uuid(),
            'name' => $name,
            'nik_encrypted' => $nik,
            'nik_hash' => $nikHash,
            'nik_last4' => $nik ? substr($nik, -4) : null,
            'phone_encrypted' => Normalizer::text($input['phone'] ?? null),
            'source_tab' => 'Laravel',
        ]);
    }

    private function authorizeWrite(User $actor, Project $project): void
    {
        if ($actor->role !== User::ROLE_BRANCH_ADMIN || ! $actor->canAccessProject($project)) {
            throw new AccessDeniedHttpException('Operational writes are limited to assigned branch admins.');
        }

        if (config('app.env') !== 'staging' || ! filter_var(env('MARISON_OPERATIONAL_WRITES', false), FILTER_VALIDATE_BOOL)) {
            throw new HttpException(403, 'Operational writes are disabled outside staging.');
        }

        if ($project->management_mode !== Project::MANAGEMENT_LARAVEL) {
            throw new HttpException(422, 'Project masih dikelola melalui Sheets.');
        }
    }
}
