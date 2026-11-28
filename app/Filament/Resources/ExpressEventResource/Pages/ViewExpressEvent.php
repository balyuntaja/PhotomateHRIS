<?php

namespace App\Filament\Resources\ExpressEventResource\Pages;

use App\Filament\Resources\ExpressEventResource;
use App\Models\ExpressEvent;
use App\Services\ExpressEventAccountingService;
use App\Services\ExpressEventHppService;
use App\Utils\RupiahHelper;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewExpressEvent extends ViewRecord
{
    protected static string $resource = ExpressEventResource::class;

    #[On('express-event-updated')]
    public function refreshOnEventUpdated(): void
    {
        $this->record?->refresh();
        $this->record?->unsetRelations();
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Informasi Event')
                    ->schema([
                        Infolists\Components\TextEntry::make('event_name')
                            ->label('Nama Event')
                            ->weight('bold')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large),

                        Infolists\Components\TextEntry::make('period_label')
                            ->label('Tanggal Event')
                            ->state(function (ExpressEvent $record): string {
                                $start = $record->start_date?->format('d/m/Y');
                                $end = $record->end_date?->format('d/m/Y');
                                if (! $start && ! $end) {
                                    return '-';
                                }

                                return ($start === $end || ! $end) ? ($start ?? '-') : "{$start} s/d {$end}";
                            }),

                        Infolists\Components\TextEntry::make('operational_type_display')
                            ->label('Jenis Operasional')
                            ->badge()
                            ->state(fn (ExpressEvent $record): string => $record->operational_type_label)
                            ->color(fn (ExpressEvent $record): string => $record->isSharingProfit() ? 'success' : 'info'),

                        Infolists\Components\TextEntry::make('recap_count')
                            ->label('Jumlah Rekap Sesi')
                            ->state(fn (ExpressEvent $record): string => number_format($record->recaps()->where('status', 'active')->count(), 0, ',', '.') . ' rekap sesi')
                            ->weight('semibold'),

                        Infolists\Components\TextEntry::make('total_sessions')
                            ->label('Total Sesi')
                            ->state(fn (ExpressEvent $record): string => number_format($record->total_allocated_sessions, 0, ',', '.') . ' sesi')
                            ->helperText(fn (ExpressEvent $record): string => number_format($record->total_allocated_sheets, 0, ',', '.') . ' lembar cetak')
                            ->weight('semibold'),

                        Infolists\Components\TextEntry::make('notes')
                            ->label('Catatan Event')
                            ->placeholder('Tidak ada catatan.')
                            ->columnSpan(3),
                    ])
                    ->columns(4),

                Infolists\Components\Section::make('Informasi Pendapatan')
                    ->schema([
                        // Untuk Express Sharing Profit: QRIS, Tunai, Grand Total dari Rekap Sesi
                        Infolists\Components\TextEntry::make('sharing_qris')
                            ->label('Total QRIS')
                            ->state(fn (ExpressEvent $record): string => RupiahHelper::format($record->total_recap_qris_amount))
                            ->weight('semibold')
                            ->visible(fn (ExpressEvent $record): bool => $record->isSharingProfit()),

                        Infolists\Components\TextEntry::make('sharing_cash')
                            ->label('Total Tunai')
                            ->state(fn (ExpressEvent $record): string => RupiahHelper::format($record->total_recap_cash_amount))
                            ->weight('semibold')
                            ->visible(fn (ExpressEvent $record): bool => $record->isSharingProfit()),

                        Infolists\Components\TextEntry::make('sharing_grand_total')
                            ->label('Grand Total')
                            ->state(fn (ExpressEvent $record): string => RupiahHelper::format($record->effective_revenue))
                            ->helperText('Bersumber langsung dari Rekap Sesi Approved')
                            ->weight('bold')
                            ->color('primary')
                            ->visible(fn (ExpressEvent $record): bool => $record->isSharingProfit()),

                        // Untuk Express Self Run & Express Sewa: Daftar Invoice, Total Harga Invoice, Status Keterhubungan
                        Infolists\Components\TextEntry::make('invoice_connection_status')
                            ->label('Status Keterhubungan Invoice')
                            ->badge()
                            ->state(fn (ExpressEvent $record): string => $record->invoice_connection_status_label)
                            ->color(fn (ExpressEvent $record): string => $record->invoice_connection_status_color)
                            ->visible(fn (ExpressEvent $record): bool => $record->requiresInvoice()),

                        Infolists\Components\TextEntry::make('linked_invoice_summary')
                            ->label('Daftar Invoice Terkait')
                            ->state(function (ExpressEvent $record): string {
                                $numbers = $record->invoices()->pluck('invoice_number')->all();

                                return $numbers !== [] ? implode(', ', $numbers) : 'Belum ada invoice terhubung';
                            })
                            ->weight('medium')
                            ->visible(fn (ExpressEvent $record): bool => $record->requiresInvoice()),

                        Infolists\Components\TextEntry::make('total_invoice_price')
                            ->label('Total Harga Invoice')
                            ->state(fn (ExpressEvent $record): string => RupiahHelper::format($record->total_invoice_amount))
                            ->helperText('Dihitung dari total nilai kontrak invoice (bukan nominal DP)')
                            ->weight('bold')
                            ->color('primary')
                            ->visible(fn (ExpressEvent $record): bool => $record->requiresInvoice()),

                        // Fallback untuk event Express umum (historis sebelum pemisahan sub-kategori)
                        Infolists\Components\TextEntry::make('general_total_revenue')
                            ->label('Total Omzet Alokasi')
                            ->state(fn (ExpressEvent $record): string => RupiahHelper::format($record->effective_revenue))
                            ->weight('bold')
                            ->color('primary')
                            ->visible(fn (ExpressEvent $record): bool => ! $record->isSharingProfit() && ! $record->requiresInvoice()),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Informasi Pengelolaan HPP')
                    ->schema([
                        Infolists\Components\TextEntry::make('completeness_status')
                            ->label('Status Kelengkapan Data HPP')
                            ->badge()
                            ->state(fn (ExpressEvent $record): string => $record->completeness_status_label)
                            ->color(fn (ExpressEvent $record): string => $record->completeness_status_color),

                        Infolists\Components\TextEntry::make('hpp_status')
                            ->label('Status Konfirmasi')
                            ->badge()
                            ->state(fn (ExpressEvent $record): string => $record->hpp_status_label)
                            ->color(fn (ExpressEvent $record): string => $record->hpp_status_color),

                        Infolists\Components\TextEntry::make('reconciliation_status')
                            ->label('Status Rekonsiliasi')
                            ->badge()
                            ->state(fn (ExpressEvent $record): string => $record->reconciliation_label)
                            ->color(fn (ExpressEvent $record): string => $record->reconciliation_color),

                        Infolists\Components\TextEntry::make('hpp_rate')
                            ->label('Tarif HPP per Lembar')
                            ->state(fn (ExpressEvent $record): string => $record->hpp_rate_per_sheet !== null ? RupiahHelper::format($record->hpp_rate_per_sheet) . ' /lbr' : 'Belum ditentukan')
                            ->weight('semibold'),

                        Infolists\Components\TextEntry::make('total_hpp')
                            ->label('Total Beban HPP')
                            ->state(fn (ExpressEvent $record): string => $record->hpp_rate_per_sheet !== null ? RupiahHelper::format($record->calculated_hpp) : '-')
                            ->weight('bold')
                            ->color('warning'),

                        Infolists\Components\TextEntry::make('gross_profit')
                            ->label('Estimasi Laba Kotor')
                            ->state(fn (ExpressEvent $record): string => $record->isReadyForHppCalculation() ? RupiahHelper::format($record->gross_profit) : 'Menunggu Invoice Terhubung')
                            ->weight('bold')
                            ->color(fn (ExpressEvent $record): string => ! $record->isReadyForHppCalculation() ? 'warning' : ($record->gross_profit >= 0 ? 'success' : 'danger')),

                        Infolists\Components\TextEntry::make('creator.nama_lengkap')
                            ->label('Dibuat Oleh')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('confirmer.nama_lengkap')
                            ->label('Dikonfirmasi Oleh')
                            ->placeholder('-')
                            ->helperText(fn (ExpressEvent $record): ?string => $record->confirmed_at?->format('d/m/Y H:i')),
                    ])
                    ->columns(4),

                Infolists\Components\ViewEntry::make('details')
                    ->view('filament.resources.express-events.view-details')
                    ->columnSpanFull(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var ExpressEvent $record */
        $record = $this->record;

        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => ! $this->record->isFinalized()),

            Actions\Action::make('set_hpp_rate')
                ->label('Atur Tarif HPP')
                ->icon('heroicon-o-calculator')
                ->color('info')
                ->visible(fn (): bool => ! $this->record->isFinalized() && (auth()->user()?->can('manageHpp', $this->record) ?? false))
                ->fillForm(fn (): array => [
                    'hpp_rate_per_sheet' => $this->record->hpp_rate_per_sheet,
                ])
                ->form([
                    Forms\Components\TextInput::make('hpp_rate_per_sheet')
                        ->label('Tarif HPP per Lembar')
                        ->rupiah()
                        ->required()
                        ->helperText('Masukkan 0 jika bebas biaya HPP.'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(ExpressEventHppService::class)->updateRate(
                            $this->record,
                            (int) $data['hpp_rate_per_sheet'],
                            auth()->user()
                        );

                        $this->record->refresh();

                        Notification::make()
                            ->title('Tarif HPP Diperbarui')
                            ->body('Tarif HPP per lembar berhasil disimpan.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal Menyimpan Tarif')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('confirm_hpp')
                ->label('Konfirmasi HPP')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Konfirmasi Perhitungan HPP Event')
                ->modalDescription('Apakah Anda yakin ingin mengonfirmasi perhitungan HPP event ini? Sistem akan menyimpan snapshot tarif dan nominal HPP.')
                ->visible(fn (): bool => ! in_array($this->record->hpp_status, [ExpressEvent::HPP_CONFIRMED, ExpressEvent::HPP_POSTED], true)
                    && $this->record->hpp_rate_per_sheet !== null
                    && $this->record->isReadyForHppCalculation())
                ->action(function () {
                    try {
                        app(ExpressEventHppService::class)->confirmHpp($this->record, auth()->user());

                        Notification::make()
                            ->title('HPP Berhasil Dikonfirmasi')
                            ->body('Snapshot perhitungan HPP telah disimpan. Event siap diposting ke Jurnal Keuangan.')
                            ->success()
                            ->send();

                        $this->record->refresh();
                        $this->refreshFormData(['hpp_status', 'confirmed_at', 'confirmed_by']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal Konfirmasi HPP')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('post_accounting')
                ->label('Posting ke Jurnal')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Posting HPP ke Modul Keuangan')
                ->modalDescription('HPP akan dicatat sebagai transaksi pengeluaran (beban HPP Express) pada modul Keuangan & Jurnal Umum.')
                ->visible(fn (): bool => $record->hpp_status === ExpressEvent::HPP_CONFIRMED)
                ->action(function () use ($record) {
                    try {
                        $transactions = app(ExpressEventAccountingService::class)->postHpp($record, auth()->user());

                        Notification::make()
                            ->title('HPP Berhasil Diposting ke Jurnal')
                            ->body(count($transactions) . ' transaksi pengeluaran HPP berhasil dicatat di Jurnal Keuangan.')
                            ->success()
                            ->send();

                        $this->refreshFormData(['hpp_status']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal Posting ke Jurnal')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('reopen_event')
                ->label('Buka Kembali (Reopen)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => in_array($record->hpp_status, [ExpressEvent::HPP_CONFIRMED, ExpressEvent::HPP_POSTED], true) || $record->isFinalized())
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan Pembukaan Kembali')
                        ->required()
                        ->placeholder('Tuliskan alasan penyesuaian tarif, perubahan alokasi rekap, atau koreksi perhitungan...'),
                ])
                ->action(function (array $data) use ($record) {
                    try {
                        app(ExpressEventHppService::class)->reopenEvent($record, $data['reason'], auth()->user());

                        Notification::make()
                            ->title('Event Dibuka Kembali')
                            ->body('Event dapat diedit kembali. Entri jurnal sebelumnya telah dibalik secara aman.')
                            ->warning()
                            ->send();

                        $this->refreshFormData(['hpp_status', 'finalized_at', 'confirmed_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal Membuka Kembali Event')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('finalize_event')
                ->label('Finalisasi Event')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Finalisasi Event Express')
                ->modalDescription('Event yang difinalisasi akan dikunci dari pengeditan langsung. Lanjutkan?')
                ->visible(fn (): bool => $record->isConfirmed() && ! $record->isFinalized())
                ->action(function () use ($record) {
                    try {
                        app(ExpressEventHppService::class)->finalizeEvent($record, auth()->user());

                        Notification::make()
                            ->title('Event Telah Difinalisasi')
                            ->body('Event telah dikunci secara resmi.')
                            ->success()
                            ->send();

                        $this->refreshFormData(['finalized_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal Finalisasi')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
