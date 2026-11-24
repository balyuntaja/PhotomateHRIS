<?php

namespace App\Services;

use App\Models\BoothHppAuditLog;
use App\Models\BoothSessionHpp;
use App\Models\BoothSessionHppSetting;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BoothHppService
{
    public function __construct(
        protected RekapPerbulanService $rekapService,
        protected BranchInputModeService $inputModeService
    ) {}

    /**
     * Sinkronisasi data Rekap Sesi approved untuk booth Janus dan/atau Golio ke tabel booth_session_hpp.
     *
     * @return array{created: int, updated: int}
     */
    public function syncApprovedRecaps(?string $boothType = null, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $query = SessionReport::query()
            ->where('status', SessionReport::STATUS_APPROVED)
            ->with(['cabang', 'transactions']);

        if ($start && $end) {
            $query->whereBetween('report_date', [$start->toDateString(), $end->toDateString()]);
        }

        $reports = $query->get();

        $created = 0;
        $updated = 0;

        // Ambil default setting tarif
        $settings = BoothSessionHppSetting::all()->keyBy('booth_type');

        foreach ($reports as $report) {
            $resolvedBooth = $this->rekapService->resolveBoothName($report->cabang?->nama_cabang);

            if (! in_array($resolvedBooth, [BoothSessionHpp::BOOTH_JANUS, BoothSessionHpp::BOOTH_GOLIO], true)) {
                continue;
            }

            if ($boothType !== null && $resolvedBooth !== $boothType) {
                continue;
            }

            // Hitung lembar & sesi
            $stats = $this->extractSheetsAndSessions($report);
            $sheets = $stats['sheets'];
            $sessions = $stats['sessions'];
            $revenue = (float) $report->grand_total_amount;

            $record = BoothSessionHpp::where('session_report_id', $report->id)->first();

            if (! $record) {
                $defaultRate = (float) ($settings[$resolvedBooth]->default_rate_per_sheet ?? 0);
                $hasDefaultRate = $defaultRate > 0;
                $rate = $hasDefaultRate ? $defaultRate : null;
                $totalHpp = $hasDefaultRate ? round($sheets * $defaultRate) : 0;
                $status = $hasDefaultRate ? BoothSessionHpp::STATUS_CALCULATED : BoothSessionHpp::STATUS_PENDING;

                BoothSessionHpp::create([
                    'session_report_id' => $report->id,
                    'booth_type' => $resolvedBooth,
                    'cabang_id' => $report->cabang_id,
                    'report_date' => $report->report_date,
                    'total_sheets' => $sheets,
                    'total_sessions' => $sessions,
                    'revenue' => $revenue,
                    'hpp_rate_per_sheet' => $rate,
                    'total_hpp' => $totalHpp,
                    'status' => $status,
                    'reconciliation_status' => BoothSessionHpp::RECON_BALANCED,
                ]);

                $created++;
            } else {
                // Jika status belum confirmed/posted, update jika ada perubahan di rekap sesi operasional
                if (! $record->isConfirmed()) {
                    $dirty = false;
                    if ($record->total_sheets !== $sheets) {
                        $record->total_sheets = $sheets;
                        $dirty = true;
                    }
                    if ($record->total_sessions !== $sessions) {
                        $record->total_sessions = $sessions;
                        $dirty = true;
                    }
                    if ((float) $record->revenue !== $revenue) {
                        $record->revenue = $revenue;
                        $dirty = true;
                    }

                    if ($dirty) {
                        if ($record->hpp_rate_per_sheet !== null) {
                            $record->total_hpp = round($sheets * (float) $record->hpp_rate_per_sheet);
                        }
                        $record->save();
                        $updated++;
                    }
                }
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Hitung jumlah lembar dan sesi dari rekap sesi.
     *
     * @return array{sheets: int, sessions: int}
     */
    public function extractSheetsAndSessions(SessionReport $report): array
    {
        if ($report->cabang && $this->inputModeService->usesManualSessionInput($report->cabang)) {
            $sessions = (int) ($report->wedding_total_sessions ?: $report->total_transactions ?: $report->total_sessions);
            $sheets = (int) ($report->wedding_total_sheets ?: $report->total_sessions);
        } else {
            // Rekap reguler: total_transactions = sesi, total_sessions = lembar cetak
            $sessions = (int) $report->total_transactions;
            $sheets = (int) $report->total_sessions;

            if ($sessions === 0 && $report->transactions->isNotEmpty()) {
                $sessions = $report->transactions->count();
            }
            if ($sheets === 0 && $report->transactions->isNotEmpty()) {
                $sheets = (int) $report->transactions->sum('session_count');
            }
        }

        return [
            'sheets' => max(0, $sheets),
            'sessions' => max(0, $sessions),
        ];
    }

    /**
     * Tentukan tarif HPP per lembar untuk satu rekap sesi.
     */
    public function setTariff(
        BoothSessionHpp $record,
        float $rate,
        ?Karyawan $actor = null,
        ?string $reason = null
    ): BoothSessionHpp {
        if ($rate < 0) {
            throw new InvalidArgumentException('Tarif HPP per lembar tidak boleh negatif.');
        }

        if ($record->status === BoothSessionHpp::STATUS_POSTED) {
            throw new RuntimeException('Data HPP yang sudah diposting ke Akuntansi harus dibuka kembali (reopen) terlebih dahulu.');
        }

        return DB::transaction(function () use ($record, $rate, $actor, $reason) {
            $oldValues = [
                'hpp_rate_per_sheet' => $record->hpp_rate_per_sheet,
                'total_hpp' => $record->total_hpp,
                'status' => $record->status,
            ];

            $totalHpp = (float) round($record->total_sheets * $rate);

            $record->hpp_rate_per_sheet = $rate;
            $record->total_hpp = $totalHpp;

            if ($record->status === BoothSessionHpp::STATUS_PENDING) {
                $record->status = BoothSessionHpp::STATUS_CALCULATED;
            }

            $record->save();

            $newValues = [
                'hpp_rate_per_sheet' => $record->hpp_rate_per_sheet,
                'total_hpp' => $record->total_hpp,
                'status' => $record->status,
            ];

            $this->logAudit(
                $record,
                'rate_updated',
                $actor,
                $reason ?: "Update tarif HPP menjadi Rp " . number_format($rate, 0, ',', '.'),
                $oldValues,
                $newValues
            );

            return $record;
        });
    }

    /**
     * Konfirmasi HPP satu rekap sesi.
     */
    public function confirmHpp(BoothSessionHpp $record, ?Karyawan $actor = null): BoothSessionHpp
    {
        if ($record->hpp_rate_per_sheet === null) {
            throw new InvalidArgumentException('Tarif HPP per lembar harus diisi sebelum HPP dapat dikonfirmasi.');
        }

        return DB::transaction(function () use ($record, $actor) {
            $oldValues = [
                'status' => $record->status,
                'confirmed_by' => $record->confirmed_by,
                'confirmed_at' => $record->confirmed_at?->toIso8601String(),
            ];

            $record->status = BoothSessionHpp::STATUS_CONFIRMED;
            $record->confirmed_by = $actor?->karyawan_id;
            $record->confirmed_at = now();
            $record->save();

            $newValues = [
                'status' => $record->status,
                'confirmed_by' => $record->confirmed_by,
                'confirmed_at' => $record->confirmed_at?->toIso8601String(),
            ];

            $this->logAudit(
                $record,
                'confirmed',
                $actor,
                "HPP dikonfirmasi oleh " . ($actor?->nama_lengkap ?? 'System'),
                $oldValues,
                $newValues
            );

            return $record;
        });
    }

    /**
     * Buka kembali (reopen) HPP yang sudah dikonfirmasi / diposting.
     */
    public function reopenHpp(
        BoothSessionHpp $record,
        string $reason,
        ?Karyawan $actor = null
    ): BoothSessionHpp {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new InvalidArgumentException('Alasan buka kembali wajib diisi.');
        }

        return DB::transaction(function () use ($record, $trimmedReason, $actor) {
            $lockedRecord = BoothSessionHpp::where('id', $record->id)->lockForUpdate()->firstOrFail();

            // Jika sudah terlanjur diposting ke akuntansi, lakukan reversal
            if ($lockedRecord->status === BoothSessionHpp::STATUS_POSTED || $lockedRecord->financial_transaction_id) {
                app(BoothHppAccountingService::class)->reverseHpp($lockedRecord, $actor, $trimmedReason);
            }

            $oldValues = [
                'status' => $lockedRecord->status,
                'confirmed_by' => $lockedRecord->confirmed_by,
                'confirmed_at' => $lockedRecord->confirmed_at?->toIso8601String(),
            ];

            $lockedRecord->status = BoothSessionHpp::STATUS_CALCULATED;
            $lockedRecord->confirmed_by = null;
            $lockedRecord->confirmed_at = null;
            $lockedRecord->financial_transaction_id = null;
            $lockedRecord->save();

            $newValues = [
                'status' => $lockedRecord->status,
                'confirmed_by' => null,
                'confirmed_at' => null,
            ];

            $this->logAudit(
                $lockedRecord,
                'reopened',
                $actor,
                "Buka kembali HPP dengan alasan: {$trimmedReason}",
                $oldValues,
                $newValues
            );

            return $lockedRecord;
        });
    }

    /**
     * Atur tarif standar untuk jenis booth tertentu (Janus / Golio).
     */
    public function setStandardRate(
        string $boothType,
        float $rate,
        bool $applyToUnconfirmed = true,
        ?Karyawan $actor = null
    ): BoothSessionHppSetting {
        if ($rate < 0) {
            throw new InvalidArgumentException('Tarif standar tidak boleh bernilai negatif.');
        }

        return DB::transaction(function () use ($boothType, $rate, $applyToUnconfirmed, $actor) {
            $setting = BoothSessionHppSetting::updateOrCreate(
                ['booth_type' => $boothType],
                [
                    'default_rate_per_sheet' => $rate,
                    'updated_by' => $actor?->karyawan_id,
                ]
            );

            if ($applyToUnconfirmed) {
                $unconfirmed = BoothSessionHpp::where('booth_type', $boothType)
                    ->whereIn('status', [BoothSessionHpp::STATUS_PENDING, BoothSessionHpp::STATUS_CALCULATED])
                    ->get();

                foreach ($unconfirmed as $item) {
                    $item->hpp_rate_per_sheet = $rate;
                    $item->total_hpp = round($item->total_sheets * $rate);
                    $item->status = BoothSessionHpp::STATUS_CALCULATED;
                    $item->save();

                    $this->logAudit(
                        $item,
                        'rate_updated',
                        $actor,
                        "Penerapan tarif standar {$boothType}: Rp " . number_format($rate, 0, ',', '.')
                    );
                }
            }

            return $setting;
        });
    }

    /**
     * Bulk konfirmasi seluruh rekap sesi booth yang tarifnya sudah terisi.
     */
    public function bulkConfirm(
        string $boothType,
        Carbon $start,
        Carbon $end,
        ?Karyawan $actor = null
    ): int {
        $records = BoothSessionHpp::where('booth_type', $boothType)
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', BoothSessionHpp::STATUS_CALCULATED)
            ->whereNotNull('hpp_rate_per_sheet')
            ->get();

        $count = 0;
        foreach ($records as $record) {
            $this->confirmHpp($record, $actor);
            $count++;
        }

        return $count;
    }

    /**
     * Catat log audit jejak perubahan.
     */
    public function logAudit(
        BoothSessionHpp $record,
        string $action,
        ?Karyawan $actor = null,
        ?string $reason = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): BoothHppAuditLog {
        return BoothHppAuditLog::create([
            'booth_session_hpp_id' => $record->id,
            'user_id' => $actor?->karyawan_id,
            'action' => $action,
            'reason' => $reason,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
