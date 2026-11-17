<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    public const OWNER_SHEETS = 'sheets';

    public const OWNER_LARAVEL = 'laravel';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_MUNDUR = 'MUNDUR';

    public const STATUS_BATAL = 'BATAL';

    public const STATUS_REPLACED = 'REPLACED';

    public const STATUS_MOVED = 'MOVED';

    public const STATUS_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const STAGE_PSJB = 'PSJB';

    public const STAGE_BI = 'BI_CHECKING';

    public const STAGE_PEMBERKASAN = 'PEMBERKASAN';

    public const STAGE_BANK = 'PROSES_BANK';

    public const STAGE_PPJB = 'PPJB';

    public const STAGE_AKAD = 'AKAD';

    public const STAGE_BAST = 'BAST';

    public const STAGES = [
        self::STAGE_PSJB,
        self::STAGE_BI,
        self::STAGE_PEMBERKASAN,
        self::STAGE_BANK,
        self::STAGE_PPJB,
        self::STAGE_AKAD,
        self::STAGE_BAST,
    ];

    public const OUTCOME_STATUSES = [
        self::STATUS_MUNDUR,
        self::STATUS_BATAL,
        self::STATUS_REPLACED,
        self::STATUS_MOVED,
    ];

    protected $attributes = [
        'source_owner' => self::OWNER_SHEETS,
    ];

    protected $fillable = [
        'project_id', 'lot_id', 'buyer_id', 'source_booking_key', 'source_transaction_id',
        'psjb_number', 'booking_date', 'payment_method', 'sales_name', 'coordinator_name',
        'base_price', 'status', 'current_stage', 'decision_stage', 'decision_date',
        'decision_note', 'predecessor_booking_id', 'transfer_type', 'is_current_holder', 'source_owner',
        'akad_date', 'bast_date', 'source_tab', 'source_row', 'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'decision_date' => 'date',
            'akad_date' => 'date',
            'bast_date' => 'date',
            'base_price' => 'decimal:2',
            'is_current_holder' => 'boolean',
            'source_owner' => 'string',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_booking_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'predecessor_booking_id');
    }

    public function transition(): HasOne
    {
        return $this->hasOne(BookingTransition::class, 'successor_booking_id');
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(BookingTransition::class, 'source_booking_id');
    }

    public function psjb(): HasOne
    {
        return $this->hasOne(PsjbRecord::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(BookingAudit::class);
    }

    public function biCheck(): HasOne
    {
        return $this->hasOne(BiCheck::class);
    }

    public function pemberkasan(): HasMany
    {
        return $this->hasMany(PemberkasanRecord::class)
            ->orderByRaw('received_date IS NULL')
            ->orderBy('received_date')
            ->orderBy('id');
    }

    public function currentPemberkasan(): HasOne
    {
        return $this->hasOne(PemberkasanRecord::class)->where('is_current', true);
    }

    public function bankSubmissions(): HasMany
    {
        return $this->hasMany(BankSubmission::class)->orderBy('submitted_date')->orderBy('id');
    }

    public function ppjb(): HasOne
    {
        return $this->hasOne(PpjbRecord::class);
    }

    public function akad(): HasOne
    {
        return $this->hasOne(AkadRecord::class);
    }

    public function bast(): HasOne
    {
        return $this->hasOne(BastRecord::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_COMPLETED], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Lanjut',
            self::STATUS_COMPLETED => 'Selesai (BAST)',
            self::STATUS_MUNDUR => 'Mundur',
            self::STATUS_BATAL => 'Batal',
            self::STATUS_REPLACED => 'Ganti Nama',
            self::STATUS_MOVED => 'Pindah Kavling',
            self::STATUS_NEEDS_REVIEW => 'Perlu Cek',
            default => $this->status,
        };
    }

    public function stageLabel(?string $stage = null): string
    {
        $stage ??= $this->current_stage;

        return match ($stage) {
            self::STAGE_PSJB => 'PSJB',
            self::STAGE_BI => 'BI Checking',
            self::STAGE_PEMBERKASAN => 'Pemberkasan',
            self::STAGE_BANK => 'Proses Bank',
            self::STAGE_PPJB => 'PPJB Developer',
            self::STAGE_AKAD => 'Akad',
            self::STAGE_BAST => 'BAST',
            default => (string) $stage,
        };
    }
}
