<?php

namespace App\Filament\Resources\ExpressEventResource\Pages;

use App\Filament\Resources\ExpressEventResource;
use App\Filament\Resources\ExpressEventResource\Widgets\ExpressEventStatsOverview;
use App\Models\BoothSessionHpp;
use App\Models\BoothSessionHppSetting;
use App\Models\ExpressEvent;
use App\Services\BoothHppAccountingService;
use App\Services\BoothHppService;
use App\Services\HppDashboardService;
use App\Utils\RupiahHelper;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

class ListExpressEvents extends ListRecords
{
    protected static string $resource = ExpressEventResource::class;

    protected static string $view = 'filament.resources.express-events.pages.list-hpp';

    public ?string $activeTab = 'all'; // all, express, janus, golio

    public int $filterYear;
    public int $filterMonth;

    // Modal state for Setting Tariff
    public bool $isTariffModalOpen = false;
    public ?int $modalHppId = null;
    public ?string $modalRecapNumber = null;
    public ?int $modalSheets = null;
    public ?float $modalRate = null;

    // Modal state for Reopening HPP
    public bool $isReopenModalOpen = false;
    public ?int $reopenHppId = null;
    public string $reopenReason = '';

    // Modal state for Setting Standard Rate
    public bool $isStandardRateModalOpen = false;
    public string $modalBoothType = 'Janus';
    public ?float $modalStandardRate = null;
    public bool $applyToUnconfirmed = true;

    protected $queryString = [
        'activeTab' => ['except' => 'all'],
    ];

    public function mount(): void
    {
        parent::mount();

        $this->filterYear = (int) now()->year;
        $this->filterMonth = (int) now()->month;

        // Auto sync approved recaps for Janus and Golio on load
        try {
            app(BoothHppService::class)->syncApprovedRecaps();
        } catch (\Throwable $e) {
            // Graceful ignore during testing / migration
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Buat Event Baru')
                ->icon('heroicon-o-plus')
                ->visible(fn () => $this->activeTab === 'express'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ExpressEventStatsOverview::class,
        ];
    }

    public function getStartDate(): Carbon
    {
        return Carbon::createFromDate($this->filterYear, $this->filterMonth, 1)->startOfMonth();
    }

    public function getEndDate(): Carbon
    {
        return $this->getStartDate()->copy()->endOfMonth();
    }

    // ==========================================
    // Tab & Filter Handlers
    // ==========================================

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function updatedFilterMonth(): void
    {
        // Triggers re-render with new date range
    }

    public function updatedFilterYear(): void
    {
        // Triggers re-render with new date range
    }

    public function syncRecaps(): void
    {
        $service = app(BoothHppService::class);
        $result = $service->syncApprovedRecaps(null, $this->getStartDate(), $this->getEndDate());

        Notification::make()
            ->title('Sinkronisasi Selesai')
            ->body("Ditemukan {$result['created']} rekap baru dan {$result['updated']} diperbarui.")
            ->success()
            ->send();
    }

    // ==========================================
    // Data Properties for Janus & Golio
    // ==========================================

    public function getJanusRecordsProperty(): Collection
    {
        return BoothSessionHpp::query()
            ->forBooth(BoothSessionHpp::BOOTH_JANUS)
            ->inPeriod($this->getStartDate(), $this->getEndDate())
            ->with(['sessionReport', 'cabang'])
            ->orderByDesc('report_date')
            ->get();
    }

    public function getGolioRecordsProperty(): Collection
    {
        return BoothSessionHpp::query()
            ->forBooth(BoothSessionHpp::BOOTH_GOLIO)
            ->inPeriod($this->getStartDate(), $this->getEndDate())
            ->with(['sessionReport', 'cabang'])
            ->orderByDesc('report_date')
            ->get();
    }

    public function getConsolidatedRecordsProperty(): Collection
    {
        $start = $this->getStartDate()->toDateString();
        $end = $this->getEndDate()->toDateString();

        $items = collect();

        // 1. Express Events
        $expressEvents = ExpressEvent::query()
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                  ->orWhereBetween('end_date', [$start, $end]);
            })
            ->with(['hppSnapshots', 'recaps.sessionReport'])
            ->get();

        foreach ($expressEvents as $ev) {
            $latestHpp = $ev->hppSnapshots->sortByDesc('version')->first();
            $items->push([
                'type' => 'Express',
                'title' => $ev->event_name,
                'date' => $ev->start_date ? Carbon::parse($ev->start_date)->format('d M Y') : '-',
                'sheets' => $latestHpp ? (int) $latestHpp->total_sheets : 0,
                'rate' => $ev->hpp_rate_per_sheet !== null ? RupiahHelper::format($ev->hpp_rate_per_sheet) : '-',
                'total_hpp' => $latestHpp ? RupiahHelper::format($latestHpp->total_hpp) : '-',
                'status' => $ev->hpp_status_label,
                'status_color' => $ev->hpp_status_badge_color,
                'raw_status' => $ev->hpp_status,
                'event_id' => $ev->id,
            ]);
        }

        // 2. Janus Records
        $janusRecords = BoothSessionHpp::query()
            ->forBooth(BoothSessionHpp::BOOTH_JANUS)
            ->inPeriod($this->getStartDate(), $this->getEndDate())
            ->with(['sessionReport'])
            ->get();

        foreach ($janusRecords as $jr) {
            $items->push([
                'type' => 'Janus',
                'title' => $jr->sessionReport?->report_number ?? "SR-{$jr->session_report_id}",
                'date' => $jr->report_date ? $jr->report_date->format('d M Y') : '-',
                'sheets' => $jr->total_sheets,
                'rate' => $jr->formatted_rate,
                'total_hpp' => $jr->isConfirmed() ? $jr->formatted_total_hpp : '-',
                'status' => $jr->status_label,
                'status_color' => $jr->status_badge_color,
                'raw_status' => $jr->status,
                'hpp_id' => $jr->id,
            ]);
        }

        // 3. Golio Records
        $golioRecords = BoothSessionHpp::query()
            ->forBooth(BoothSessionHpp::BOOTH_GOLIO)
            ->inPeriod($this->getStartDate(), $this->getEndDate())
            ->with(['sessionReport'])
            ->get();

        foreach ($golioRecords as $gr) {
            $items->push([
                'type' => 'Golio',
                'title' => $gr->sessionReport?->report_number ?? "SR-{$gr->session_report_id}",
                'date' => $gr->report_date ? $gr->report_date->format('d M Y') : '-',
                'sheets' => $gr->total_sheets,
                'rate' => $gr->formatted_rate,
                'total_hpp' => $gr->isConfirmed() ? $gr->formatted_total_hpp : '-',
                'status' => $gr->status_label,
                'status_color' => $gr->status_badge_color,
                'raw_status' => $gr->status,
                'hpp_id' => $gr->id,
            ]);
        }

        return $items;
    }

    public function getConsolidatedSummaryProperty(): array
    {
        return app(HppDashboardService::class)->getSummary($this->getStartDate(), $this->getEndDate());
    }

    public function getStandardRatesProperty(): array
    {
        $settings = BoothSessionHppSetting::all()->keyBy('booth_type');

        return [
            'Janus' => (float) ($settings['Janus']->default_rate_per_sheet ?? 2500),
            'Golio' => (float) ($settings['Golio']->default_rate_per_sheet ?? 3000),
        ];
    }

    // ==========================================
    // Actions: Set Tariff Modal
    // ==========================================

    public function openTariffModal(int $id): void
    {
        $record = BoothSessionHpp::findOrFail($id);

        $this->modalHppId = $record->id;
        $this->modalRecapNumber = $record->sessionReport?->report_number ?? "SR-{$record->session_report_id}";
        $this->modalSheets = $record->total_sheets;
        $this->modalRate = $record->hpp_rate_per_sheet !== null ? (float) $record->hpp_rate_per_sheet : null;
        $this->isTariffModalOpen = true;
    }

    public function closeTariffModal(): void
    {
        $this->isTariffModalOpen = false;
        $this->modalHppId = null;
        $this->modalRate = null;
    }

    public function saveTariff(): void
    {
        if ($this->modalHppId === null || $this->modalRate === null || $this->modalRate < 0) {
            Notification::make()
                ->title('Tarif Tidak Valid')
                ->body('Tarif per lembar harus diisi dan tidak boleh negatif.')
                ->danger()
                ->send();
            return;
        }

        $record = BoothSessionHpp::findOrFail($this->modalHppId);
        $service = app(BoothHppService::class);
        $service->setTariff($record, (float) $this->modalRate, auth()->user());

        $this->closeTariffModal();

        Notification::make()
            ->title('Tarif Berhasil Disimpan')
            ->body("Tarif Rp " . number_format($this->modalRate, 0, ',', '.') . " per lembar berhasil diterapkan.")
            ->success()
            ->send();
    }

    // ==========================================
    // Actions: Confirm & Post
    // ==========================================

    public function confirmBoothHpp(int $id): void
    {
        $record = BoothSessionHpp::findOrFail($id);
        $service = app(BoothHppService::class);

        try {
            $service->confirmHpp($record, auth()->user());

            Notification::make()
                ->title('HPP Berhasil Dikonfirmasi')
                ->body("HPP rekap {$record->sessionReport?->report_number} sebesar " . RupiahHelper::format($record->total_hpp) . " telah disetujui.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Konfirmasi')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function postBoothHpp(int $id): void
    {
        $record = BoothSessionHpp::findOrFail($id);
        $service = app(BoothHppAccountingService::class);

        try {
            $tx = $service->postHpp($record, auth()->user());

            Notification::make()
                ->title('Berhasil Diposting ke Akuntansi')
                ->body("Transaksi pengeluaran #{$tx->id} berhasil dicatat di modul Akuntansi.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Posting ke Akuntansi')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ==========================================
    // Actions: Reopen Modal
    // ==========================================

    public function openReopenModal(int $id): void
    {
        $this->reopenHppId = $id;
        $this->reopenReason = '';
        $this->isReopenModalOpen = true;
    }

    public function closeReopenModal(): void
    {
        $this->isReopenModalOpen = false;
        $this->reopenHppId = null;
        $this->reopenReason = '';
    }

    public function submitReopen(): void
    {
        if (trim($this->reopenReason) === '') {
            Notification::make()
                ->title('Alasan Wajib Diisi')
                ->body('Harap masukkan alasan pembukaan kembali HPP.')
                ->warning()
                ->send();
            return;
        }

        $record = BoothSessionHpp::findOrFail($this->reopenHppId);
        $service = app(BoothHppService::class);

        try {
            $service->reopenHpp($record, $this->reopenReason, auth()->user());

            $this->closeReopenModal();

            Notification::make()
                ->title('HPP Berhasil Dibuka Kembali')
                ->body("Status HPP telah dikembalikan ke draft. Jurnal transaksi sebelumnya telah dibalikkan jika ada.")
                ->info()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal Membuka HPP')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ==========================================
    // Actions: Standard Rate Modal
    // ==========================================

    public function openStandardRateModal(string $boothType): void
    {
        $this->modalBoothType = $boothType;
        $this->modalStandardRate = $this->standardRates[$boothType] ?? 0;
        $this->applyToUnconfirmed = true;
        $this->isStandardRateModalOpen = true;
    }

    public function closeStandardRateModal(): void
    {
        $this->isStandardRateModalOpen = false;
    }

    public function saveStandardRate(): void
    {
        if ($this->modalStandardRate === null || $this->modalStandardRate < 0) {
            Notification::make()
                ->title('Tarif Tidak Valid')
                ->body('Tarif standar tidak boleh negatif.')
                ->danger()
                ->send();
            return;
        }

        $service = app(BoothHppService::class);
        $service->setStandardRate(
            $this->modalBoothType,
            (float) $this->modalStandardRate,
            $this->applyToUnconfirmed,
            auth()->user()
        );

        $this->closeStandardRateModal();

        Notification::make()
            ->title("Tarif Standar {$this->modalBoothType} Disimpan")
            ->body("Tarif standar diperbarui menjadi Rp " . number_format($this->modalStandardRate, 0, ',', '.') . " per lembar.")
            ->success()
            ->send();
    }

    // ==========================================
    // Bulk Actions
    // ==========================================

    public function bulkConfirmBooth(string $boothType): void
    {
        $service = app(BoothHppService::class);
        $count = $service->bulkConfirm($boothType, $this->getStartDate(), $this->getEndDate(), auth()->user());

        Notification::make()
            ->title("Konfirmasi Massal {$boothType} Selesai")
            ->body("Berhasil mengonfirmasi {$count} rekap sesi {$boothType}.")
            ->success()
            ->send();
    }
}
