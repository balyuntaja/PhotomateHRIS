<?php

namespace App\Filament\Widgets\Keuangan;

use App\Filament\Resources\FinancialTransactionResource;
use App\Models\FinancialTransaction;
use App\Services\KeuanganService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class LatestTransactionsWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $heading = 'Transaksi Terbaru';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $service = app(KeuanganService::class);

        $period = $service->resolvePeriod(
            $this->filters['periode'] ?? null,
            $this->filters['dari'] ?? null,
            $this->filters['sampai'] ?? null,
        );

        return $table
            ->query(
                $service->baseQuery($period['start'], $period['end'], $this->filters['cabang_id'] ?? null)
                    ->with(['category', 'cabang', 'paymentMethod'])
                    ->orderByDesc('transaction_date')
                    ->orderByDesc('id')
                    ->limit(8)
            )
            ->columns([
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->description(fn (FinancialTransaction $record): ?string => $record->category?->name)
                    ->placeholder('-')
                    ->limit(40)
                    ->wrap(),

                Tables\Columns\TextColumn::make('cabang.nama_cabang')
                    ->label('Cabang')
                    ->placeholder('Tanpa Cabang')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(fn (FinancialTransaction $record): string => $record->signed_formatted_amount)
                    ->color(fn (FinancialTransaction $record): string => $record->is_income ? 'success' : 'danger')
                    ->weight('semibold')
                    ->alignEnd(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('lihat_semua')
                    ->label('Lihat Semua Transaksi')
                    ->icon('heroicon-o-arrow-right')
                    ->color('gray')
                    ->url(fn (): string => FinancialTransactionResource::getUrl('index'))
                    ->visible(fn (): bool => Auth::user()?->can('view_any_keuangan_transaction') ?? false),
            ])
            ->paginated(false)
            ->emptyStateHeading('Belum ada transaksi')
            ->emptyStateDescription('Mulai catat pemasukan dan pengeluaran Photomate di sini.')
            ->emptyStateIcon('heroicon-o-wallet');
    }
}
