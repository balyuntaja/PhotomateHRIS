<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SessionApprovalResource\Pages;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SessionApprovalResource extends Resource
{
    protected static ?string $model = SessionReport::class;

    protected static ?string $slug = 'session-management/approval';

    protected static ?string $navigationGroup = 'Session Management';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationLabel = 'Approval';

    protected static ?string $modelLabel = 'Approval Rekap Sesi';

    protected static ?string $pluralModelLabel = 'Approval Rekap Sesi';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        /** @var \App\Models\Karyawan|null $user */
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        return app(SessionWorkflowService::class)->isSupervisorOrAdmin($user);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = SessionReport::where('status', SessionReport::STATUS_WAITING_APPROVAL)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                // Strictly show only WAITING_APPROVAL
                $query->where('status', SessionReport::STATUS_WAITING_APPROVAL);
            })
            ->columns([
                Tables\Columns\TextColumn::make('report_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('report_number')
                    ->label('No Rekap')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary')
                    ->copyable(),

                Tables\Columns\TextColumn::make('crews.nama_lengkap')
                    ->label('Crew')
                    ->badge()
                    ->separator(', ')
                    ->searchable(),

                Tables\Columns\TextColumn::make('total_transactions')
                    ->label('Total Sesi')
                    ->formatStateUsing(fn ($state) => $state . ' sesi')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_sessions')
                    ->label('Total Lembar')
                    ->formatStateUsing(fn ($state) => $state . ' lembar')
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_qris_amount')
                    ->label('Total QRIS')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_cash_amount')
                    ->label('Total Tunai')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cabang.nama_cabang')
                    ->label('Cabang')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('grand_total_amount')
                    ->label('Grand Total')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('bonus_amount')
                    ->label('Bonus Crew')
                    ->getStateUsing(function (SessionReport $record): string {
                        if (!$record->isNewspaperJanus()) {
                            return '-';
                        }
                        $bonus = (int) $record->bonus_amount;
                        $crewCount = $record->crews->count();
                        if ($bonus > 0) {
                            $perCrew = $crewCount > 0 ? (int) round($bonus / $crewCount) : 0;
                            return 'Rp ' . number_format($bonus, 0, ',', '.') . ($crewCount > 0 ? ' (@ Rp ' . number_format($perCrew, 0, ',', '.') . ')' : '');
                        }
                        return 'Rp 0 (Target 30 sesi)';
                    })
                    ->badge()
                    ->color(function (string $state): string {
                        if ($state === '-') {
                            return 'gray';
                        }
                        return str_contains($state, 'Rp 50.000') ? 'success' : 'warning';
                    })
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted At')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('submitter.nama_lengkap')
                    ->label('Submitted By')
                    ->placeholder('-'),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->emptyStateHeading('Tidak ada rekap yang perlu diperiksa')
            ->emptyStateDescription('Semua rekap sesi saat ini telah selesai ditinjau.')
            ->actions([
                // Review Action
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->modalHeading(fn (SessionReport $record) => 'Review Rekap Sesi #' . $record->report_number)
                    ->modalWidth('4xl')
                    ->modalContent(fn (SessionReport $record) => view('filament.resources.session-reports.review-modal', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                // Approve Action
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Session Report?')
                    ->modalDescription(fn (SessionReport $record) => "Apakah Anda yakin ingin approve rekap sesi #{$record->report_number} untuk tanggal {$record->report_date->format('d M Y')}? Setelah disetujui, data akan terkunci (immutable).")
                    ->modalSubmitActionLabel('Ya, Approve')
                    ->action(function (SessionReport $record): void {
                        try {
                            app(SessionWorkflowService::class)->approve($record, Auth::user());
                            Notification::make()
                                ->title('Rekap sesi berhasil di-approve.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Gagal melakukan approval')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // Request Revision Action
                Tables\Actions\Action::make('request_revision')
                    ->label('Request Revision')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->modalHeading('Request Revision')
                    ->modalDescription('Masukkan alasan revisi yang jelas untuk Crew.')
                    ->modalSubmitActionLabel('Kirim Permintaan Revisi')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason for Revision / Catatan Revisi')
                            ->placeholder('Contoh: Jumlah sesi QRIS tidak sesuai dengan laporan fisik event.')
                            ->required()
                            ->rows(3)
                            ->helperText('Wajib diisi agar crew dapat memperbaiki kesalahan.'),
                    ])
                    ->action(function (SessionReport $record, array $data): void {
                        try {
                            app(SessionWorkflowService::class)->requestRevision($record, Auth::user(), $data['reason']);
                            Notification::make()
                                ->title('Rekap sesi perlu diperbaiki. Catatan revisi telah dikirim ke Crew.')
                                ->warning()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Gagal meminta revisi')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSessionApprovals::route('/'),
        ];
    }
}
