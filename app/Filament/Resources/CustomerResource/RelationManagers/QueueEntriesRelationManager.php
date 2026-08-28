<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class QueueEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'queueEntries';

    protected static ?string $title = 'Riwayat Antrean';

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('queue_number')
            ->columns([
                Tables\Columns\TextColumn::make('event.name')
                    ->label('Nama Event')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('event.date')
                    ->label('Tanggal Event')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('formatted_number')
                    ->label('Nomor Antrean')
                    ->fontFamily('mono')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'WAITING' => 'gray',
                        'CALLED' => 'warning',
                        'SERVING' => 'info',
                        'COMPLETED' => 'success',
                        'SKIPPED' => 'danger',
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('joined_at')
                    ->label('Waktu Bergabung')
                    ->dateTime('d M Y H:i'),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
