<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinancialPaymentMethodResource\Pages;
use App\Models\FinancialPaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FinancialPaymentMethodResource extends Resource
{
    protected static ?string $model = FinancialPaymentMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Metode Pembayaran';

    protected static ?string $modelLabel = 'Metode Pembayaran';

    protected static ?string $pluralModelLabel = 'Metode Pembayaran';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'keuangan-metode-pembayaran';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_payment_method') ?? false);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_keuangan_payment_method') ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Metode Pembayaran')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Metode')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'required' => 'Nama metode wajib diisi.',
                            'unique' => 'Metode pembayaran ini sudah ada.',
                        ]),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->helperText('Metode nonaktif tidak muncul saat input transaksi baru.')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Metode Pembayaran')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('transactions_count')
                    ->label('Dipakai')
                    ->counts('transactions')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->modalHeading('Edit Metode Pembayaran')
                    ->modalSubmitActionLabel('Simpan Perubahan')
                    ->successNotificationTitle('Metode pembayaran berhasil diperbarui.'),

                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus metode pembayaran ini?')
                    ->modalSubmitActionLabel('Hapus')
                    ->action(function (FinancialPaymentMethod $record): void {
                        if ($record->isUsed()) {
                            $record->update(['is_active' => false]);

                            Notification::make()
                                ->title('Metode sudah dipakai transaksi')
                                ->body('Metode tidak dihapus, tetapi dinonaktifkan agar transaksi lama tetap valid.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Metode pembayaran berhasil dihapus.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada metode pembayaran')
            ->emptyStateDescription('Tambahkan metode pembayaran seperti Cash, Transfer, atau QRIS.')
            ->emptyStateIcon('heroicon-o-credit-card');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancialPaymentMethods::route('/'),
        ];
    }
}
