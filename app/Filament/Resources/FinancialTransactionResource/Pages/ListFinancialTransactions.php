<?php

namespace App\Filament\Resources\FinancialTransactionResource\Pages;

use App\Filament\Resources\FinancialTransactionResource;
use App\Filament\Resources\Pages\ListRecords;
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
            Actions\CreateAction::make()
                ->label('Tambah Transaksi')
                ->icon('heroicon-o-plus')
                ->modalHeading('Tambah Transaksi')
                ->modalSubmitActionLabel('Simpan Transaksi')
                ->createAnother(false)
                ->successNotificationTitle('Transaksi berhasil disimpan.'),
        ];

        if (Auth::user()?->isSuperAdmin() || Auth::user()?->can('export_keuangan_report')) {
            $actions[] = Actions\Action::make('export')
                ->label('Export Excel / CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function (): StreamedResponse {
                    $records = $this->getFilteredTableQuery()
                        ->with(['category', 'cabang', 'paymentMethod', 'creator'])
                        ->get();

                    $filename = 'transaksi-keuangan-photomate-' . now()->format('Ymd-His') . '.csv';

                    return response()->streamDownload(function () use ($records): void {
                        $handle = fopen('php://output', 'w');

                        // UTF-8 BOM agar terbaca otomatis oleh Microsoft Excel
                        fputs($handle, "\xEF\xBB\xBF");

                        fputcsv($handle, [
                            'ID',
                            'Tanggal',
                            'Jenis',
                            'Kategori',
                            'Nominal',
                            'Cabang',
                            'Metode Pembayaran',
                            'Deskripsi',
                            'Dibuat Oleh',
                            'Created At',
                            'Updated At',
                        ]);

                        foreach ($records as $record) {
                            fputcsv($handle, [
                                $record->id,
                                $record->transaction_date?->format('Y-m-d') ?? '-',
                                $record->type_label,
                                $record->category?->name ?? '-',
                                (int) $record->amount,
                                $record->cabang?->nama_cabang ?? 'Tanpa Cabang',
                                $record->paymentMethod?->name ?? '-',
                                $record->description ?: '-',
                                $record->creator?->nama_lengkap ?? '-',
                                $record->created_at?->format('Y-m-d H:i:s') ?? '-',
                                $record->updated_at?->format('Y-m-d H:i:s') ?? '-',
                            ]);
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
