<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SessionHistoryResource\Pages;
use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SessionHistoryResource extends Resource
{
    protected static ?string $model = SessionReport::class;

    protected static ?string $slug = 'session-management/history';

    protected static ?string $navigationGroup = 'Session Management';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'History';

    protected static ?string $modelLabel = 'Riwayat Rekap Sesi';

    protected static ?string $pluralModelLabel = 'Riwayat Rekap Sesi';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                /** @var Karyawan $user */
                $user = Auth::user();
                $workflow = app(SessionWorkflowService::class);

                // Crew only sees own reports; Supervisor/Admin sees all
                if ($user && !$workflow->isSupervisorOrAdmin($user)) {
                    $query->where(function ($q) use ($user) {
                        $q->where('submitted_by', $user->karyawan_id)
                            ->orWhereHas('crews', function ($c) use ($user) {
                                $c->where('karyawan.karyawan_id', $user->karyawan_id);
                            });
                    });
                }
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

                Tables\Columns\TextColumn::make('cabang.nama_cabang')
                    ->label('Cabang')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_sessions')
                    ->label('Total Sesi')
                    ->formatStateUsing(fn ($state) => $state . ' sesi')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
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

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (SessionReport $record): string => $record->status_color)
                    ->formatStateUsing(fn (SessionReport $record): string => $record->status_label)
                    ->sortable(),

                Tables\Columns\TextColumn::make('approver.nama_lengkap')
                    ->label('Approved By')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Approved At')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('report_date', 'desc')
            ->filters([
                // 1. Tanggal Filter
                Tables\Filters\Filter::make('tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('dari_tanggal')
                            ->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('sampai_tanggal')
                            ->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['dari_tanggal'],
                                fn (Builder $query, $date): Builder => $query->whereDate('report_date', '>=', $date),
                            )
                            ->when(
                                $data['sampai_tanggal'],
                                fn (Builder $query, $date): Builder => $query->whereDate('report_date', '<=', $date),
                            );
                    }),

                // 2. Cabang Filter
                Tables\Filters\SelectFilter::make('cabang_id')
                    ->label('Cabang')
                    ->options(\App\Models\Cabang::pluck('nama_cabang', 'cabang_id')),

                // 3. Status Filter
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        SessionReport::STATUS_DRAFT => 'Draft',
                        SessionReport::STATUS_WAITING_APPROVAL => 'Waiting Approval',
                        SessionReport::STATUS_REVISION => 'Revision',
                        SessionReport::STATUS_APPROVED => 'Approved',
                    ]),

                // 3. Crew Filter
                Tables\Filters\SelectFilter::make('crew')
                    ->label('Crew')
                    ->options(Karyawan::orderBy('nama_lengkap')->pluck('nama_lengkap', 'karyawan_id'))
                    ->query(function (Builder $query, array $data): Builder {
                        $val = $data['value'] ?? null;
                        return filled($val)
                            ? $query->whereHas('crews', fn ($c) => $c->where('karyawan.karyawan_id', $val))
                            : $query;
                    }),

                // 4. Payment Method Filter
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Metode Pembayaran')
                    ->options([
                        'CASH' => 'Tunai',
                        'QRIS' => 'QRIS',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $val = $data['value'] ?? null;
                        return filled($val)
                            ? $query->whereHas('transactions', fn ($t) => $t->where('payment_method', $val))
                            : $query;
                    }),

                // 5. Supervisor Filter
                Tables\Filters\SelectFilter::make('approved_by')
                    ->label('Supervisor (Approver)')
                    ->options(Karyawan::whereIn('role_id', ['R01', 'R06', 'R08', 'R03'])
                        ->orderBy('nama_lengkap')
                        ->pluck('nama_lengkap', 'karyawan_id')),
            ])
            ->actions([
                Tables\Actions\Action::make('detail')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->color('secondary')
                    ->modalHeading(fn (SessionReport $record) => 'Detail Riwayat Rekap #' . $record->report_number)
                    ->modalWidth('4xl')
                    ->modalContent(fn (SessionReport $record) => view('filament.resources.session-reports.review-modal', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),
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
            'index' => Pages\ListSessionHistories::route('/'),
        ];
    }
}
