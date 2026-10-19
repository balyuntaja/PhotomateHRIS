<?php

namespace App\Filament\Resources\FinancialTransactionResource\Pages;

use App\Filament\Pages\KeuanganInputTransaksi;
use App\Filament\Resources\FinancialTransactionResource;
use App\Filament\Resources\Pages\ListRecords;
use App\Services\KeuanganService;
use Filament\Actions;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListFinancialTransactions extends ListRecords
{
    protected static string $resource = FinancialTransactionResource::class;

    protected static ?string $title = 'Transaksi Keuangan';

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\Action::make('tambah_transaksi')
                ->label('Tambah Transaksi')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => KeuanganInputTransaksi::getUrl())
                ->authorize(fn (): bool => FinancialTransactionResource::canCreate()),
        ];

        if (Auth::user()?->isSuperAdmin() || Auth::user()?->can('export_keuangan_report')) {
            $actions[] = Actions\Action::make('export')
                ->label('Export Excel / CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function (): StreamedResponse {
                    $records = $this->getFilteredTableQuery()
                        ->with(['category', 'account', 'paymentMethod', 'submitter', 'creator', 'cabang'])
                        ->get();

                    $service = app(KeuanganService::class);

                    $filename = 'transaksi-keuangan-photomate-' . now()->format('Ymd-His') . '.csv';

                    return response()->streamDownload(function () use ($records, $service): void {
                        $handle = fopen('php://output', 'w');

                        // UTF-8 BOM agar terbaca otomatis oleh Microsoft Excel
                        fputs($handle, "\xEF\xBB\xBF");

                        fputcsv($handle, KeuanganService::EXPORT_HEADERS);

                        foreach ($records as $record) {
                            fputcsv($handle, $service->exportRow($record));
                        }

                        fclose($handle);
                    }, $filename, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                        'Content-Disposition' => "attachment; filename=\"$filename\"",
                    ]);
                });
        }

        return $actions;
    }
}
