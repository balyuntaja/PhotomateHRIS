<?php

namespace App\Filament\Pages;

use App\Models\Event;
use App\Models\QueueEntry;
use App\Filament\Widgets\QueueStatsOverview;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;

class QueueDashboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Sistem Antrean';

    protected static ?string $navigationLabel = 'Dashboard Monitoring';

    protected static ?string $title = 'Monitoring Antrean Digital';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.queue-dashboard';

    public static function canAccess(): bool
    {
        /** @var \App\Models\Karyawan $user */
        $user = auth()->user();
        return $user && (
            $user->role_id === 'R01' || 
            $user->role_id === 'R02' || 
            $user->role_id === 'R03' || 
            $user->role_id === 'R06' || 
            $user->hasRole(['Admin', 'admin', 'CEO', 'ceo', 'Manager HRD', 'manager hrd', 'Staff HRD', 'staff hrd'])
        );
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QueueStatsOverview::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Event::query()->whereIn('status', ['OPEN', 'PAUSED', 'DRAFT'])
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Event')
                    ->description(fn ($record) => "Kode: " . $record->event_code . " • Lokasi: " . ($record->location ?? '-'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'DRAFT' => 'gray',
                        'OPEN' => 'success',
                        'PAUSED' => 'warning',
                        'CLOSED' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('waiting_count')
                    ->label('Menunggu')
                    ->getStateUsing(fn ($record) => QueueEntry::where('event_id', $record->id)->where('status', 'WAITING')->count())
                    ->badge()
                    ->color('warning')
                    ->alignCenter(),

                TextColumn::make('active_serving')
                    ->label('Nomor Aktif')
                    ->getStateUsing(function ($record) {
                        $entry = QueueEntry::where('event_id', $record->id)
                            ->whereIn('status', ['CALLED', 'SERVING'])
                            ->first();
                        return $entry ? $entry->formatted_number : '-';
                    })
                    ->badge()
                    ->color(fn ($state) => $state !== '-' ? 'primary' : 'gray')
                    ->alignCenter(),
            ])
            ->actions([
                Action::make('operator')
                    ->label('Buka Operator')
                    ->icon('heroicon-m-users')
                    ->color('success')
                    ->button()
                    ->url(fn ($record) => \App\Filament\Resources\EventResource::getUrl('operator', ['record' => $record])),

                Action::make('display')
                    ->label('Layar TV')
                    ->icon('heroicon-m-computer-desktop')
                    ->color('gray')
                    ->button()
                    ->url(fn ($record) => url('/queue/' . $record->event_code . '/display'))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Tidak ada event antrean aktif')
            ->emptyStateDescription('Mulai buat event baru atau ubah status event menjadi OPEN/PAUSED di menu Kelola Antrean.');
    }
}
