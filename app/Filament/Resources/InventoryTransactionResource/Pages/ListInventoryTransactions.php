<?php

namespace App\Filament\Resources\InventoryTransactionResource\Pages;

use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\ListRecords;
use App\Services\SessionReportInventoryService;
use App\Utils\InventoryAccess;
use Filament\Actions;
use Filament\Notifications\Notification;

class ListInventoryTransactions extends ListRecords
{
    protected static string $resource = InventoryTransactionResource::class;

    protected static ?string $title = 'Riwayat Stok';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_session_reports')
                ->label('Sinkronkan Rekap Sesi')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => InventoryAccess::allowed(auth()->user()))
                ->action(function (): void {
                    InventoryAccess::authorize();

                    $service = app(SessionReportInventoryService::class);
                    $paperItem = $service->resolvePhotoPaperItem();

                    if (! $paperItem) {
                        Notification::make()
                            ->title('Master Barang Kertas Foto Belum Ditemukan')
                            ->body('Pastikan barang aktif dengan nama "Kertas Foto" sudah terdaftar di menu Persediaan.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $result = $service->syncEligibleReports();

                    if ($result['created'] > 0) {
                        Notification::make()
                            ->title('Sinkronisasi Rekap Sesi Berhasil')
                            ->body(sprintf(
                                'Berhasil mencatat %d transaksi Barang Keluar otomatis dari %d rekap sesi Approved (Express & Newspaper Janus mulai 7 Nov 2026).',
                                $result['created'],
                                $result['processed'],
                            ))
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Riwayat Stok Sudah Tersinkronisasi')
                        ->body(sprintf(
                            'Seluruh %d rekap sesi Approved yang memenuhi kriteria (mulai 7 Nov 2026) sudah tercatat tanpa duplikasi.',
                            $result['processed'],
                        ))
                        ->info()
                        ->send();
                }),

            Actions\CreateAction::make()
                ->label('Catat Transaksi')
                ->icon('heroicon-o-plus'),
        ];
    }
}
