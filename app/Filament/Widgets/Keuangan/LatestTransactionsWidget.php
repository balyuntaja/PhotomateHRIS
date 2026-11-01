<?php

namespace App\Filament\Widgets\Keuangan;

use App\Filament\Resources\FinancialTransactionResource;
use App\Models\FinancialTransaction;
use App\Services\KeuanganService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

class LatestTransactionsWidget extends TableWidget
{
    use InteractsWithPageFilters;

    public const PER_PAGE = 10;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $heading = 'Transaksi Terbaru';

    protected int|string|array $columnSpan = 'full';

    public ?string $filtersSignature = null;

    public function table(Table $table): Table
    {
        $service = app(KeuanganService::class);

        $period = $service->resolvePeriod(
            $this->filters['periode'] ?? null,
            $this->filters['dari'] ?? null,
            $this->filters['sampai'] ?? null,
        );

        $this->useIndonesianPaginationOverview();

        return $table
            ->query(
                $service->baseQuery($period['start'], $period['end'], $this->filters['cabang_id'] ?? null)
                    ->with(['category', 'cabang', 'paymentMethod'])
                    ->orderByDesc('transaction_date')
                    ->orderByDesc('id')
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
            ->paginated([self::PER_PAGE])
            ->defaultPaginationPageOption(self::PER_PAGE)
            ->emptyStateHeading('Belum ada transaksi')
            ->emptyStateDescription('Mulai catat pemasukan dan pengeluaran Photomate di sini.')
            ->emptyStateIcon('heroicon-o-wallet');
    }

    public function bootedInteractsWithTable(): void
    {
        parent::bootedInteractsWithTable();

        $this->resetPageWhenFiltersChange();
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        return $query->paginate(
            perPage: self::PER_PAGE,
            pageName: $this->getTablePaginationPageName(),
        )->onEachSide(0);
    }

    protected function resetPageWhenFiltersChange(): void
    {
        $signature = md5(json_encode([
            $this->filters['periode'] ?? null,
            $this->filters['dari'] ?? null,
            $this->filters['sampai'] ?? null,
            $this->filters['cabang_id'] ?? null,
        ]));

        if ($this->filtersSignature !== null && $this->filtersSignature !== $signature) {
            $this->resetPage();
        }

        $this->filtersSignature = $signature;
    }

    protected function useIndonesianPaginationOverview(): void
    {
        Lang::addLines([
            'components/pagination.overview' => '{1} Menampilkan 1 transaksi|[2,*] Menampilkan :first–:last dari :total transaksi',
        ], app()->getLocale(), 'filament');
    }
}
