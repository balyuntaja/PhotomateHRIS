<?php

namespace App\Models;

use App\Utils\InventoryBranches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $report_number
 * @property Carbon|null $report_date
 * @property string $status
 * @property string|null $event_note
 * @property Carbon|null $event_date
 * @property string|null $cabang_id
 * @property int|null $invoice_id
 * @property int|null $event_id
 * @property int $wedding_total_sessions
 * @property int $wedding_total_sheets
 * @property array|null $evidence_files
 * @property int $total_sessions
 * @property int $total_transactions
 * @property int $total_cash_amount
 * @property int $total_cash_sessions
 * @property int $total_cash_transactions
 * @property int $total_qris_amount
 * @property int $total_qris_sessions
 * @property int $total_qris_transactions
 * @property int $grand_total_amount
 * @property int $bonus_amount
 * @property string|null $validation_status
 * @property array|null $validation_issues
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $revision_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SessionReport extends Model
{
    use HasFactory;

    protected $table = 'session_reports';

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';
    const STATUS_REVISION = 'REVISION';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    const INPUT_WINDOW_DAYS = 2;

    const INVENTORY_CUTOFF_DATE = '2026-11-07';

    const HISTORY_BRANCH_FILTER_JANUS = 'newspaper_janus';
    const HISTORY_BRANCH_FILTER_EXPRESS = 'express';
    const HISTORY_BRANCH_FILTER_GOLIO = 'golio';

    protected bool $allowAuthorizedApprovedMutation = false;

    protected $fillable = [
        'report_number',
        'report_date',
        'event_date',
        'status',
        'event_note',
        'cabang_id',
        'invoice_id',
        'event_id',
        'wedding_total_sessions',
        'wedding_total_sheets',
        'evidence_files',
        'total_sessions',
        'total_transactions',
        'total_cash_amount',
        'total_cash_sessions',
        'total_cash_transactions',
        'total_qris_amount',
        'total_qris_sessions',
        'total_qris_transactions',
        'grand_total_amount',
        'bonus_amount',
        'validation_status',
        'validation_issues',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'revision_note',
    ];

    protected $casts = [
        'report_date' => 'date',
        'event_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'wedding_total_sessions' => 'integer',
        'wedding_total_sheets' => 'integer',
        'evidence_files' => 'array',
        'total_sessions' => 'integer',
        'total_transactions' => 'integer',
        'total_cash_amount' => 'integer',
        'total_cash_sessions' => 'integer',
        'total_cash_transactions' => 'integer',
        'total_qris_amount' => 'integer',
        'total_qris_sessions' => 'integer',
        'total_qris_transactions' => 'integer',
        'grand_total_amount' => 'integer',
        'bonus_amount' => 'integer',
        'validation_issues' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->report_number)) {
                $model->report_number = static::generateReportNumber($model->report_date);
            }

            static::assertInputWindowOpen($model);
        });

        static::updating(function ($model) {
            /** @var \App\Models\Karyawan|null $user */
            $user = \Illuminate\Support\Facades\Auth::user();
            $isSuperAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

            // Immutability rule: If original was APPROVED, reject modifications to core data.
            // Super Admin may update only event_note and event_date metadata on Express branches without affecting approval.
            if ($model->getOriginal('status') === self::STATUS_APPROVED && ! $model->allowAuthorizedApprovedMutation) {
                $dirty = array_keys($model->getDirty());
                $canUpdateEventMetadata = $isSuperAdmin && $model->supportsEventDetail();
                $allowedDirty = $canUpdateEventMetadata
                    ? ['event_note', 'event_date', 'updated_at']
                    : ['updated_at'];
                $disallowed = array_diff($dirty, $allowedDirty);
                if (!empty($disallowed)) {
                    throw new \RuntimeException('Data rekap sesi yang telah disetujui (APPROVED) tidak dapat diubah (immutable).');
                }
            }

            // Rekap berstatus REVISION dikecualikan dari batas H+2 agar crew dapat memperbaiki.
            if ($model->getOriginal('status') === self::STATUS_REVISION) {
                return;
            }

            static::assertInputWindowOpen($model);
        });

        static::saved(function (self $model) {
            if (
                $model->status === self::STATUS_APPROVED
                || $model->getOriginal('status') === self::STATUS_APPROVED
            ) {
                app(\App\Services\SessionReportInventoryService::class)->syncForReport($model);
            }
        });

        static::deleting(function ($model) {
            /** @var \App\Models\Karyawan|null $user */
            $user = \Illuminate\Support\Facades\Auth::user();
            $isSuperAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

            if ($model->status === self::STATUS_APPROVED && !$isSuperAdmin) {
                throw new \RuntimeException('Data rekap sesi yang telah disetujui (APPROVED) tidak dapat dihapus.');
            }

            static::assertInputWindowOpen($model);

            if (\Illuminate\Support\Facades\Schema::hasTable('express_event_recaps')) {
                $linkedRecaps = ExpressEventRecap::with('expressEvent')
                    ->where('session_report_id', $model->id)
                    ->get();

                foreach ($linkedRecaps as $linkedRecap) {
                    $event = $linkedRecap->expressEvent;
                    if ($event && ($event->isConfirmed() || $event->isFinalized())) {
                        throw new \RuntimeException(
                            "Rekap sesi ini terhubung ke Event HPP '{$event->event_name}' yang sudah dikonfirmasi. Buka kembali (reopen) Event HPP terlebih dahulu sebelum menghapus rekap sesi."
                        );
                    }
                }

                foreach ($linkedRecaps as $linkedRecap) {
                    $event = $linkedRecap->expressEvent;
                    $linkedRecap->delete();

                    if ($event) {
                        $remainingRecaps = $event->recaps()->count();
                        if ($remainingRecaps === 0) {
                            $event->update(['reconciliation_status' => ExpressEvent::RECON_UNALLOCATED]);
                        } elseif ($event->requiresInvoice() && $event->hasLinkedInvoices()) {
                            app(\App\Services\ExpressEventInvoiceService::class)->syncEventInvoiceAllocations($event);
                        }

                        ExpressEventAuditLog::record(
                            $event,
                            'RECAP_DELETED',
                            ['session_report_id' => $model->id, 'report_number' => $model->report_number],
                            null,
                            "Rekap sesi {$model->report_number} dihapus; alokasi disesuaikan."
                        );
                    }
                }
            }

            app(\App\Services\SessionReportInventoryService::class)
                ->reverseForReport($model, $user, 'Rekap sesi dihapus');
        });
    }

    public function allowAuthorizedApprovedMutation(bool $allow = true): self
    {
        $this->allowAuthorizedApprovedMutation = $allow;

        return $this;
    }

    /**
     * Batas waktu input/edit/delete rekap sesi: tanggal rekap + 2 hari pukul 23:59:59 (WIB).
     */
    public static function inputDeadlineFor($date): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        return Carbon::parse($date)
            ->copy()
            ->setTimezone(config('session_reports.timezone'))
            ->startOfDay()
            ->addDays(self::INPUT_WINDOW_DAYS)
            ->setTime(23, 59, 59);
    }

    public static function inputWindowClosedFor($date): bool
    {
        return (bool) static::inputDeadlineFor($date)?->isPast();
    }

    /**
     * Tanggal rekap paling lama yang masih boleh diinput crew pada hari ini.
     */
    public static function earliestInputDate(): Carbon
    {
        return Carbon::now(config('session_reports.timezone'))
            ->startOfDay()
            ->subDays(self::INPUT_WINDOW_DAYS);
    }

    public static function inputClosedMessageFor($date): string
    {
        $deadline = static::inputDeadlineFor($date);

        return sprintf(
            'Rekap sudah ditutup. Batas input dan perubahan rekap untuk tanggal %s adalah %s.',
            Carbon::parse($date)->translatedFormat('d F Y'),
            $deadline ? $deadline->translatedFormat('d F Y') . ' pukul ' . $deadline->format('H:i') : '-'
        );
    }

    public function inputDeadline(): ?Carbon
    {
        return static::inputDeadlineFor($this->report_date);
    }

    public function inputDeadlineDescription(): ?string
    {
        $deadline = $this->inputDeadline();

        return $deadline ? $deadline->translatedFormat('d F Y') . ' pukul ' . $deadline->format('H:i') : null;
    }

    public function isPastInputDeadline(): bool
    {
        return static::inputWindowClosedFor($this->report_date);
    }

    public function inputClosedMessage(): string
    {
        return static::inputClosedMessageFor($this->report_date);
    }

    protected static function assertInputWindowOpen($model): void
    {
        /** @var \App\Models\Karyawan|null $user */
        $user = \Illuminate\Support\Facades\Auth::user();

        if (!$user || !app(\App\Services\SessionWorkflowService::class)->isInputClosed($user, $model->report_date)) {
            return;
        }

        throw new \RuntimeException($model->inputClosedMessage());
    }

    public static function generateReportNumber($date = null): string
    {
        $d = $date ? Carbon::parse($date) : now();
        $prefix = 'SR-' . $d->format('Ymd');
        $countToday = static::whereDate('created_at', now()->toDateString())->count() + 1;
        $candidate = $prefix . '-' . str_pad($countToday, 3, '0', STR_PAD_LEFT);

        while (static::where('report_number', $candidate)->exists()) {
            $countToday++;
            $candidate = $prefix . '-' . str_pad($countToday, 3, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    public function crews(): BelongsToMany
    {
        return $this->belongsToMany(
            Karyawan::class,
            'session_report_crews',
            'session_report_id',
            'karyawan_id'
        )->withTimestamps();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SessionTransaction::class, 'session_report_id');
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class, 'session_report_id')->orderBy('created_at', 'desc');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'session_report_id')->orderBy('id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'submitted_by', 'karyawan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'approved_by', 'karyawan_id');
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id', 'cabang_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isWaitingApproval(): bool
    {
        return $this->status === self::STATUS_WAITING_APPROVAL;
    }

    public function isRevision(): bool
    {
        return $this->status === self::STATUS_REVISION;
    }

    public function getCrewNamesAttribute(): string
    {
        return $this->crews->pluck('nama_lengkap')->join(', ') ?: '-';
    }

    public function getResolvedEventNameAttribute(): ?string
    {
        if (filled($this->event_note)) {
            return trim((string) $this->event_note);
        }

        $operationalEventName = $this->relationLoaded('event')
            ? $this->event?->name
            : ($this->event_id ? Event::find($this->event_id)?->name : null);

        if (filled($operationalEventName)) {
            return trim((string) $operationalEventName);
        }

        $invoiceEventName = $this->relationLoaded('invoice')
            ? $this->invoice?->event_name
            : ($this->invoice_id ? Invoice::find($this->invoice_id)?->event_name : null);

        return filled($invoiceEventName) ? trim((string) $invoiceEventName) : null;
    }

    public function getOperationalEventDateAttribute(): ?Carbon
    {
        if ($this->event_date !== null) {
            return $this->event_date;
        }

        if ($this->event_id) {
            $evDate = $this->relationLoaded('event')
                ? $this->event?->date
                : Event::find($this->event_id)?->date;
            if ($evDate !== null) {
                return $evDate;
            }
        }

        if ($this->invoice_id) {
            $invDate = $this->relationLoaded('invoice')
                ? $this->invoice?->event_date
                : Invoice::find($this->invoice_id)?->event_date;
            if ($invDate !== null) {
                return $invDate;
            }
        }

        return null;
    }

    public function hasCompleteEventDetail(): bool
    {
        return filled($this->resolved_event_name) && $this->operational_event_date !== null;
    }

    /**
     * Resolusi identitas unik Event Operasional untuk pengelompokan HPP:
     * 1. event_id (relasi ke tabel events) -> "event:{event_id}"
     * 2. invoice_id (relasi kontrak invoice unik saat event_id kosong) -> "invoice:{invoice_id}"
     * 3. Manual lengkap (Sharing Profit / historis lengkap) -> "manual:{op_type}:{date}:{hash}"
     * 4. Data historis belum lengkap -> "unverified:report:{id}"
     *
     * @return array{
     *     key: string,
     *     source: string,
     *     has_explicit_id: bool,
     *     event_id: int|null,
     *     invoice_id: int|null,
     *     operational_type: string,
     *     normalized_name: string
     * }
     */
    public function resolveOperationalEventIdentity(): array
    {
        $cabangName = $this->resolveCabangWithTrashed()?->nama_cabang;
        $opType = ExpressEvent::classifyBranchOperationalType($cabangName) ?? ExpressEvent::TYPE_EXPRESS_GENERAL;
        $eventName = trim((string) ($this->resolved_event_name ?? ''));
        $normalizedName = preg_replace('/\s+/', ' ', strtolower($eventName)) ?? '';

        if ($this->event_id !== null && (int) $this->event_id > 0) {
            $evId = (int) $this->event_id;

            return [
                'key' => "event:{$evId}",
                'source' => 'event_id',
                'has_explicit_id' => true,
                'event_id' => $evId,
                'invoice_id' => $this->invoice_id ? (int) $this->invoice_id : null,
                'operational_type' => $opType,
                'normalized_name' => $normalizedName,
            ];
        }

        if ($this->invoice_id !== null && (int) $this->invoice_id > 0) {
            $invId = (int) $this->invoice_id;

            return [
                'key' => "invoice:{$invId}",
                'source' => 'invoice_id',
                'has_explicit_id' => true,
                'event_id' => null,
                'invoice_id' => $invId,
                'operational_type' => $opType,
                'normalized_name' => $normalizedName,
            ];
        }

        $opDate = $this->operational_event_date?->toDateString();
        if ($normalizedName !== '' && $opDate !== null) {
            $hash = substr(sha1($normalizedName), 0, 16);

            return [
                'key' => "manual:{$opType}:{$opDate}:{$hash}",
                'source' => 'manual_detail',
                'has_explicit_id' => false,
                'event_id' => null,
                'invoice_id' => null,
                'operational_type' => $opType,
                'normalized_name' => $normalizedName,
            ];
        }

        return [
            'key' => 'unverified:report:' . ($this->id ?? uniqid()),
            'source' => 'unverified',
            'has_explicit_id' => false,
            'event_id' => null,
            'invoice_id' => null,
            'operational_type' => $opType,
            'normalized_name' => $normalizedName,
        ];
    }

    public function hasAnyEventDetail(): bool
    {
        return filled($this->resolved_event_name) || $this->event_date !== null;
    }

    /**
     * Cek apakah nama cabang termasuk kelompok Express yang mendukung detail event
     * (Express, Photomate Express, Express Sharing Profit, Express Sewa, Express Self Run),
     * sambil mengecualikan Golio, Newspaper Janus, dan cabang non-Express lainnya.
     */
    public static function isExpressEventCabangName(?string $branchName): bool
    {
        $normalized = InventoryBranches::normalizeName($branchName);

        if ($normalized === '' || str_contains($normalized, 'janus') || str_contains($normalized, 'golio')) {
            return false;
        }

        return str_contains($normalized, 'express') || str_contains($normalized, 'sharing profit');
    }

    /**
     * Daftar ID cabang dari database (termasuk soft-deleted) yang memenuhi predicate.
     *
     * @param callable(Cabang): bool $predicate
     * @return array<int, string>
     */
    protected static function matchingCabangIds(callable $predicate): array
    {
        return Cabang::withTrashed()
            ->orderBy('cabang_id')
            ->get(['cabang_id', 'nama_cabang'])
            ->filter($predicate)
            ->pluck('cabang_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Daftar ID cabang dari database (termasuk soft-deleted) yang mendukung Detail Event.
     *
     * @return array<int, string>
     */
    public static function expressEventCabangIds(): array
    {
        return static::matchingCabangIds(
            fn (Cabang $cabang): bool => static::isExpressEventCabangName($cabang->nama_cabang)
        );
    }

    /**
     * Pilihan filter cabang pada Riwayat Rekap Sesi (Newspaper Janus, Express gabungan, Golio).
     *
     * @return array<string, string>
     */
    public static function historyCabangFilterOptions(): array
    {
        return [
            self::HISTORY_BRANCH_FILTER_JANUS => 'Newspaper Janus',
            self::HISTORY_BRANCH_FILTER_EXPRESS => 'Express',
            self::HISTORY_BRANCH_FILTER_GOLIO => 'Golio',
        ];
    }

    /**
     * Terjemahkan pilihan filter cabang Riwayat Rekap Sesi menjadi daftar cabang_id dari database.
     * Null berarti tampilkan semua cabang (pilihan All).
     *
     * @return array<int, string>|null
     */
    public static function resolveHistoryCabangFilterIds(?string $filterValue): ?array
    {
        if (blank($filterValue)) {
            return null;
        }

        return match ($filterValue) {
            self::HISTORY_BRANCH_FILTER_EXPRESS => static::expressEventCabangIds(),
            self::HISTORY_BRANCH_FILTER_JANUS => static::matchingCabangIds(
                fn (Cabang $cabang): bool => str_contains(InventoryBranches::normalizeName($cabang->nama_cabang), 'janus')
            ),
            self::HISTORY_BRANCH_FILTER_GOLIO => static::matchingCabangIds(
                fn (Cabang $cabang): bool => str_contains(InventoryBranches::normalizeName($cabang->nama_cabang), 'golio')
            ),
            default => [(string) $filterValue],
        };
    }

    /**
     * Resolve record Cabang berdasarkan cabang_id (termasuk soft-deleted) agar validasi
     * cabang selalu berdasar identitas backend.
     */
    public function resolveCabangWithTrashed(): ?Cabang
    {
        if (blank($this->cabang_id)) {
            return null;
        }

        if (
            $this->relationLoaded('cabang')
            && $this->cabang instanceof Cabang
            && (string) $this->cabang->cabang_id === (string) $this->cabang_id
        ) {
            return $this->cabang;
        }

        return Cabang::withTrashed()->find($this->cabang_id);
    }

    /**
     * Hanya rekap sesi dari cabang Express (termasuk Express Sharing Profit)
     * yang mendukung informasi dan edit detail event.
     */
    public function supportsEventDetail(): bool
    {
        $cabang = $this->resolveCabangWithTrashed();

        return static::isExpressEventCabangName($cabang?->nama_cabang);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_WAITING_APPROVAL => 'Waiting Approval',
            self::STATUS_REVISION => 'Revision',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_WAITING_APPROVAL => 'warning',
            self::STATUS_REVISION => 'danger',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'primary',
        };
    }

    public function isNewspaperJanus(): bool
    {
        return app(\App\Services\BranchBonusService::class)->isEligibleBranch($this->cabang);
    }

    /**
     * Cabang mode input manual (Wedding Photomate, Express Sewa, Express Self Run)
     * memakai total Jumlah Sesi + Jumlah Lembar, bukan detail sesi per transaksi.
     */
    public function usesManualSessionInput(): bool
    {
        return app(\App\Services\BranchInputModeService::class)->usesManualSessionInput($this->resolveCabangWithTrashed());
    }

    /**
     * Hitung Total Lembar efektif rekap sesi sebagai dasar pengurangan stok Kertas Foto.
     */
    public function totalSheetCount(): int
    {
        if ($this->usesManualSessionInput()) {
            if ($this->wedding_total_sheets !== null && (int) $this->wedding_total_sheets > 0) {
                return (int) $this->wedding_total_sheets;
            }

            return max(0, (int) ($this->total_sessions ?? 0));
        }

        if ((int) ($this->total_sessions ?? 0) > 0) {
            return (int) $this->total_sessions;
        }

        if ($this->relationLoaded('transactions')) {
            return max(0, (int) $this->transactions->sum('session_count'));
        }

        return max(0, (int) $this->transactions()->sum('session_count'));
    }

    public function isEligibleForInventoryDeduction(): bool
    {
        return app(\App\Services\SessionReportInventoryService::class)->isEligibleForAutoDeduction($this);
    }

    /**
     * Daftar evidence (foto/screenshot/dokumen) yang siap dirender di Blade.
     */
    public function getEvidenceItemsAttribute(): array
    {
        return collect($this->evidence_files ?? [])
            ->filter()
            ->map(function (string $path): array {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                return [
                    'name' => basename($path),
                    'path' => $path,
                    'url' => asset('storage/' . $path),
                    'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
                ];
            })
            ->values()
            ->all();
    }

    public function getBonusDetails(): array
    {
        return app(\App\Services\BranchBonusService::class)->calculateBonusForReport($this);
    }

    public function getBonusPerCrewAttribute(): float
    {
        $details = $this->getBonusDetails();
        return (float) ($details['bonus_per_crew_exact'] ?? 0);
    }
}
