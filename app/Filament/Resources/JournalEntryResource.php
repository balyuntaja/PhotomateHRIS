<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JournalEntryResource\Pages;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\JournalPostingService;
use App\Utils\RupiahHelper;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Jurnal Umum';

    protected static ?string $modelLabel = 'Jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal Umum';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'akuntansi-jurnal-umum';

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_journal_entry') ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['fiscalPeriod', 'creator', 'poster', 'reversalOf'])
            ->withCount('lines');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Informasi Jurnal')
                ->schema([
                    Infolists\Components\TextEntry::make('journal_number')
                        ->label('Nomor Jurnal')
                        ->weight('bold'),

                    Infolists\Components\TextEntry::make('journal_date')
                        ->label('Tanggal')
                        ->date('d M Y'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => JournalEntry::STATUS_LABELS[$state] ?? $state)
                        ->color(fn (string $state): string => $state === JournalEntry::STATUS_POSTED ? 'success' : 'warning'),

                    Infolists\Components\TextEntry::make('fiscalPeriod.label')
                        ->label('Periode Akuntansi'),

                    Infolists\Components\TextEntry::make('reference_label')
                        ->label('Referensi'),

                    Infolists\Components\TextEntry::make('creator.nama_lengkap')
                        ->label('Dibuat Oleh')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('posted_at')
                        ->label('Diposting Pada')
                        ->dateTime('d M Y H:i')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('poster.nama_lengkap')
                        ->label('Diposting Oleh')
                        ->placeholder('-'),

                    Infolists\Components\TextEntry::make('description')
                        ->label('Deskripsi')
                        ->placeholder('-')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Baris Jurnal')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('chartOfAccount.code')
                                ->label('Kode'),

                            Infolists\Components\TextEntry::make('chartOfAccount.name')
                                ->label('Akun'),

                            Infolists\Components\TextEntry::make('description')
                                ->label('Deskripsi')
                                ->placeholder('-'),

                            Infolists\Components\TextEntry::make('debit')
                                ->label('Debit')
                                ->formatStateUsing(fn ($state): string => (float) $state > 0 ? RupiahHelper::format($state) : '-')
                                ->alignEnd(),

                            Infolists\Components\TextEntry::make('credit')
                                ->label('Kredit')
                                ->formatStateUsing(fn ($state): string => (float) $state > 0 ? RupiahHelper::format($state) : '-')
                                ->alignEnd(),
                        ])
                        ->columns(5),
                ]),

            Infolists\Components\Section::make('Total')
                ->schema([
                    Infolists\Components\TextEntry::make('total_debit')
                        ->label('Total Debit')
                        ->formatStateUsing(fn ($state): string => RupiahHelper::format($state))
                        ->weight('bold')
                        ->alignEnd(),

                    Infolists\Components\TextEntry::make('total_credit')
                        ->label('Total Kredit')
                        ->formatStateUsing(fn ($state): string => RupiahHelper::format($state))
                        ->weight('bold')
                        ->alignEnd(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('journal_number')
                    ->label('Nomor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('journal_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(50)
                    ->placeholder('-')
                    ->searchable(),

                Tables\Columns\TextColumn::make('reference_label')
                    ->label('Referensi')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('fiscalPeriod.label')
                    ->label('Periode')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => JournalEntry::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === JournalEntry::STATUS_POSTED ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('total_debit')
                    ->label('Debit')
                    ->formatStateUsing(fn ($state): string => RupiahHelper::format($state))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_credit')
                    ->label('Kredit')
                    ->formatStateUsing(fn ($state): string => RupiahHelper::format($state))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->label('Baris')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.nama_lengkap')
                    ->label('Dibuat Oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Diposting')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('journal_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(JournalEntry::STATUS_LABELS),

                Tables\Filters\SelectFilter::make('fiscal_period_id')
                    ->label('Periode Akuntansi')
                    ->options(fn (): array => FiscalPeriod::query()
                        ->orderByDesc('year')
                        ->orderByDesc('month')
                        ->get()
                        ->mapWithKeys(fn (FiscalPeriod $period): array => [
                            $period->getKey() => $period->label.' ('.$period->status.')',
                        ])
                        ->all()),

                Tables\Filters\Filter::make('journal_date')
                    ->label('Rentang Tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('dari')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('sampai')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['dari'] ?? null), fn (Builder $q): Builder => $q->whereDate('journal_date', '>=', $data['dari']))
                            ->when(filled($data['sampai'] ?? null), fn (Builder $q): Builder => $q->whereDate('journal_date', '<=', $data['sampai']));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Detail')
                    ->modalHeading('Detail Jurnal Umum')
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\Action::make('post')
                    ->label('Posting')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Posting jurnal ini?')
                    ->modalDescription('Jurnal posted tidak dapat diubah. Perubahan harus melalui reversal.')
                    ->visible(fn (JournalEntry $record): bool => Auth::user()?->can('post', $record) ?? false)
                    ->action(function (JournalEntry $record): void {
                        abort_unless(Auth::user()?->can('post', $record) ?? false, 403);

                        try {
                            app(JournalPostingService::class)->post($record);

                            Notification::make()
                                ->title('Jurnal berhasil diposting.')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Gagal memposting jurnal.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('reverse')
                    ->label('Reversal')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Buat jurnal reversal?')
                    ->modalDescription('Jurnal asli tetap tersimpan. Sistem membuat jurnal baru dengan debit dan kredit berlawanan.')
                    ->form([
                        Forms\Components\DatePicker::make('journal_date')
                            ->label('Tanggal Reversal')
                            ->default(now())
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->label('Alasan / Deskripsi')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->visible(fn (JournalEntry $record): bool => Auth::user()?->can('reverse', $record) ?? false)
                    ->action(function (JournalEntry $record, array $data): void {
                        abort_unless(Auth::user()?->can('reverse', $record) ?? false, 403);

                        try {
                            app(JournalPostingService::class)->reverse(
                                $record,
                                $data['description'] ?? null,
                                $data['journal_date'] ?? null,
                            );

                            Notification::make()
                                ->title('Jurnal reversal berhasil dibuat.')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Gagal membuat reversal.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada jurnal')
            ->emptyStateDescription('Jurnal dibentuk secara eksplisit melalui JournalPostingService.')
            ->emptyStateIcon('heroicon-o-document-chart-bar');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
            'view' => Pages\ViewJournalEntry::route('/{record}'),
        ];
    }
}
