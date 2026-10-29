<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GroupedStockRecordResolver;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InventoryTransactionResource;
use App\Models\InventoryStock;
use App\Services\InventoryService;
use App\Utils\InventoryAccess;
use App\Utils\InventoryBranches;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class InventoryOverview extends Page implements HasForms, HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Ringkasan';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Ringkasan Inventory';

    protected static ?string $slug = 'inventory';

    protected static string $view = 'filament.pages.inventory-overview';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return InventoryAccess::allowed(Auth::user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'cabang_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('cabang_id')
                    ->label('Cabang')
                    ->options(fn (): array => InventoryBranches::options())
                    ->placeholder('Semua Cabang')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(1);
    }

    public function updatedData(): void
    {
        $this->resetPage();
    }

    protected function resolveTableRecord(?string $key): ?Model
    {
        return GroupedStockRecordResolver::resolve($this, $key);
    }

    public function getSummaryData(): array
    {
        return app(InventoryService::class)->summary($this->data['cabang_id'] ?? null);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(InventoryService::class)->attentionQuery($this->data['cabang_id'] ?? null))
            ->columns([
                Tables\Columns\TextColumn::make('item.nama_barang')
                    ->label('Barang')
                    ->weight('semibold')
                    ->description(fn (InventoryStock $record): string => $record->item?->kode_barang ?? '-'),

                Tables\Columns\TextColumn::make('inventory_branch_label')
                    ->label('Cabang')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('jumlah')
                    ->label('Stok')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('min_stok')
                    ->label('Batas Minimum')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => InventoryStock::STATUS_LABELS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => InventoryStock::STATUS_COLORS[$state] ?? 'gray')
                    ->tooltip(fn (InventoryStock $record): ?string => match ($record->status) {
                        InventoryStock::STATUS_HABIS => 'Barang terdaftar pada cabang ini dengan stok 0. Barang yang belum terdaftar tidak termasuk.',
                        InventoryStock::STATUS_MENIPIS => 'Stok masih di atas 0 tetapi sudah mencapai atau melewati batas minimum cabang.',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('last_transaction_at')
                    ->label('Terakhir Transaksi')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Belum ada transaksi'),
            ])
            ->actions([
                Tables\Actions\Action::make('catat_transaksi')
                    ->label('Catat Transaksi')
                    ->icon('heroicon-m-plus')
                    ->url(fn (InventoryStock $record): string => InventoryTransactionResource::getUrl('create', [
                        'item' => $record->inventory_item_id,
                        'cabang' => $record->cabang_id,
                    ])),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Semua stok dalam kondisi aman')
            ->emptyStateDescription('Tidak ada barang dengan stok menipis atau habis pada filter yang dipilih.')
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('tambah_barang')
                ->label('Tambah Barang')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => InventoryItemResource::getUrl('create')),

            Actions\Action::make('catat_transaksi')
                ->label('Catat Transaksi')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->url(fn (): string => InventoryTransactionResource::getUrl('create')),
        ];
    }
}
