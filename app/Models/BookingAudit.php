<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingAudit extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_WITHDRAWN = 'withdrawn';

    public const ACTION_CANCELLED = 'cancelled';

    public const ACTION_BI_CHECKED = 'bi_checked';

    public const ACTION_PEMBERKASAN_CREATED = 'pemberkasan_created';

    public const ACTION_BANK_SUBMISSION_CREATED = 'bank_submission_created';

    public const ACTION_BANK_RESULT_RECORDED = 'bank_result_recorded';

    public const ACTION_PPJB_CREATED = 'ppjb_created';

    public const ACTION_AKAD_CREATED = 'akad_created';

    public const ACTION_BAST_CREATED = 'bast_created';

    public const ACTION_BOOKING_CHANGED = 'booking_changed';

    protected $fillable = [
        'project_id', 'booking_id', 'user_id', 'action', 'before', 'after', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        Booking $booking,
        ?User $user,
        string $action,
        ?array $before,
        ?array $after,
        ?string $requestId = null,
    ): self {
        return self::query()->create([
            'project_id' => $booking->project_id,
            'booking_id' => $booking->id,
            'user_id' => $user?->id,
            'action' => $action,
            'before' => self::safePayload($before),
            'after' => self::safePayload($after),
            'request_id' => $requestId,
        ]);
    }

    public static function snapshot(Booking $booking): array
    {
        $booking->loadMissing(['psjb', 'pemberkasan', 'bankSubmissions.pemberkasan', 'ppjb', 'akad', 'bast']);

        $snapshot = $booking->only([
            'project_id', 'lot_id', 'buyer_id', 'source_booking_key', 'psjb_number',
            'booking_date', 'payment_method', 'sales_name', 'coordinator_name', 'base_price',
            'status', 'current_stage', 'decision_stage', 'decision_date', 'decision_note',
            'predecessor_booking_id', 'transfer_type', 'is_current_holder', 'akad_date',
            'bast_date', 'source_owner',
        ]);

        $snapshot['bi_check'] = $booking->biCheck?->only([
            'bi_number', 'check_date', 'result', 'decision', 'withdrawn_flag',
            'cancelled_flag', 'moved_lot_flag', 'name_change_flag', 'notes', 'source_owner',
        ]);

        $snapshot['psjb'] = $booking->psjb?->only([
            'psjb_number', 'psjb_date', 'sales_name', 'coordinator_name', 'price',
            'utj_date', 'utj_amount', 'utj_source_psjb_id', 'dp_all_in', 'installment_count', 'payment_method',
            'source_status', 'notes',
        ]);

        $snapshot['pemberkasan'] = $booking->pemberkasan->map(fn (PemberkasanRecord $record) => $record->only([
            'id', 'file_number', 'received_date', 'bank', 'bank_unit', 'status',
            'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag',
            'notes', 'source_owner', 'revision', 'is_current',
        ]))->all();

        $snapshot['bank_submissions'] = $booking->bankSubmissions->map(function (BankSubmission $submission): array {
            $fields = $submission->only([
                'id', 'booking_id', 'project_id', 'pemberkasan_id', 'file_number', 'decision_number',
                'submission_sequence', 'bank', 'bank_unit', 'submitted_date', 'decision_date',
                'result', 'approved_amount', 'tenor', 'withdrawn_flag', 'cancelled_flag',
                'moved_lot_flag', 'name_change_flag', 'notes', 'source_owner',
            ]);
            $fields['pemberkasan_revision'] = $submission->pemberkasan?->revision;

            return $fields;
        })->all();

        $snapshot['ppjb'] = $booking->ppjb?->only([
            'id', 'booking_id', 'bank_submission_id', 'pemberkasan_id', 'ppjb_number', 'signed_date',
            'dp_status', 'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag',
            'notes', 'source_owner',
        ]);

        $snapshot['akad'] = $booking->akad?->only([
            'id', 'booking_id', 'ppjb_id', 'akad_number', 'akad_date', 'quality',
            'withdrawn_flag', 'cancelled_flag', 'moved_lot_flag', 'name_change_flag',
            'notes', 'source_owner',
        ]);

        $snapshot['bast'] = $booking->bast?->only([
            'id', 'booking_id', 'akad_id', 'project_id', 'bast_number', 'bast_date',
            'notes', 'source_owner',
        ]);

        return self::safePayload($snapshot);
    }

    private static function safePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return collect($payload)
            ->except(['nik', 'nik_encrypted', 'phone', 'phone_encrypted', 'password', 'remember_token'])
            ->all();
    }
}
