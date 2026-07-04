<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\Action;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    
    public static function canAccess(): bool
    {
        /** @var \App\Models\Karyawan $user */
        $user = auth()->user();
        return $user && (
            $user->role_id === 'R01' || 
            $user->role_id === 'R06' || 
            $user->hasRole(['Admin', 'admin', 'CEO', 'ceo'])
        );
    }
    
    protected static ?string $navigationGroup = 'Invoice';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Invoice')
                    ->schema([
                        TextInput::make('invoice_number')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Auto Generated')
                            ->columnSpan(1),
                        TextInput::make('client_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('event_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('event_location')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('person_in_charge')
                            ->required()
                            ->maxLength(255),
                        DatePicker::make('event_date')
                            ->label('Tanggal Acara (Mulai)')
                            ->minDate(now()->startOfDay())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                $endDate = $get('event_end_date');
                                if (!$endDate || \Carbon\Carbon::parse($endDate)->lt(\Carbon\Carbon::parse($state))) {
                                    $set('event_end_date', $state);
                                }
                            }),
                        DatePicker::make('event_end_date')
                            ->label('Tanggal Acara (Selesai)')
                            ->minDate(fn (Get $get) => $get('event_date') ?: now()->startOfDay())
                            ->nullable(),
                        DatePicker::make('invoice_date')
                            ->default(now())
                            ->required(),
                        DatePicker::make('due_date')
                            ->minDate(now()->startOfDay())
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Detail Layanan')
                    ->schema([
                        Repeater::make('invoiceItems')
                            ->relationship()
                            ->schema([
                                Select::make('item_type')
                                    ->label('Tipe')
                                    ->options([
                                        'service' => 'Jasa / Layanan',
                                        'product' => 'Barang / Item',
                                    ])
                                    ->default('service')
                                    ->afterStateHydrated(function (Get $get, Set $set) {
                                        $duration = $get('duration');
                                        if ($duration === '-' || (empty($get('start_time')) && empty($get('end_time')))) {
                                            $set('item_type', 'product');
                                        } else {
                                            $set('item_type', 'service');
                                        }
                                    })
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                        if ($state === 'product') {
                                            $set('start_time', null);
                                            $set('end_time', null);
                                            $set('duration', '-');
                                        } else {
                                            $set('duration', '0 Jam');
                                            self::calculateDuration($get, $set);
                                        }
                                    })
                                    ->required(),
                                TextInput::make('service_description')
                                    ->datalist([
                                        'Sewa Photobooth Paket Platinum',
                                        'Sewa Photobooth Paket Gold',
                                        'Sewa Photobooth Paket Silver',
                                    ])
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?string $state, Set $set) {
                                        $prices = [
                                            'Sewa Photobooth Paket Platinum' => 2699000,
                                            'Sewa Photobooth Paket Gold' => 2199000,
                                            'Sewa Photobooth Paket Silver' => 1599000,
                                        ];
                                        if ($state && array_key_exists($state, $prices)) {
                                            $set('amount', $prices[$state]);
                                        }
                                    })
                                    ->required(),
                                TextInput::make('duration')
                                    ->default('-')
                                    ->required()
                                    ->dehydrated(true)
                                    ->visible(fn (Get $get) => $get('item_type') === 'service')
                                    ->readOnly(),
                                TextInput::make('start_time')
                                    ->label('Waktu Mulai')
                                    ->type('time')
                                    ->visible(fn (Get $get) => $get('item_type') === 'service')
                                    ->required(fn (Get $get) => $get('item_type') === 'service')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateDuration($get, $set)),
                                TextInput::make('end_time')
                                    ->label('Waktu Selesai')
                                    ->type('time')
                                    ->visible(fn (Get $get) => $get('item_type') === 'service')
                                    ->required(fn (Get $get) => $get('item_type') === 'service')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateDuration($get, $set)),
                                TextInput::make('amount')
                                    ->prefix('Rp')
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateTotals($get, $set)),
                            ])
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateTotals($get, $set))
                            ->columns(6)
                            ->defaultItems(1)
                    ]),

                Forms\Components\Section::make('Perhitungan')
                    ->schema([
                        TextInput::make('subtotal')
                            ->prefix('Rp')
                            ->required()
                            ->numeric()
                            ->readOnly()
                            ->default(0.00),
                        TextInput::make('discount')
                            ->prefix('Rp')
                            ->required()
                            ->numeric()
                            ->default(0.00)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateTotals($get, $set)),
                        TextInput::make('total')
                            ->prefix('Rp')
                            ->required()
                            ->numeric()
                            ->readOnly()
                            ->default(0.00),
                    ])->columns(3),

                Forms\Components\Section::make('Metode Pembayaran')
                    ->schema([
                        TextInput::make('payment_bank')
                            ->maxLength(255)
                            ->default('Bank Jago'),
                        TextInput::make('payment_account_name')
                            ->maxLength(255)
                            ->default('Billy Aldo Yudha Perwira'),
                        TextInput::make('payment_account_number')
                            ->maxLength(255)
                            ->default('104332064900'),
                    ])->columns(3),

                Forms\Components\Section::make('Status & Catatan')
                    ->schema([
                        Select::make('status')
                            ->options([
                                'Draft' => 'Draft',
                                'Belum Dibayar' => 'Belum Dibayar',
                                'Sebagian Dibayar' => 'Sebagian Dibayar',
                                'Lunas' => 'Lunas',
                                'Dibatalkan' => 'Dibatalkan',
                            ])
                            ->default('Draft')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                if ($state !== 'Sebagian Dibayar') {
                                    $set('down_payment', 0);
                                }
                            }),
                        TextInput::make('down_payment')
                            ->label('Down Payment (DP) yang Dibayar')
                            ->prefix('Rp')
                            ->numeric()
                            ->default(0.00)
                            ->visible(fn (Get $get) => $get('status') === 'Sebagian Dibayar')
                            ->required(fn (Get $get) => $get('status') === 'Sebagian Dibayar')
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get) => 'Sisa Pembayaran: Rp ' . number_format(max(0, floatval($get('total') ?? 0) - floatval($get('down_payment') ?? 0)), 0, ',', '.'))
                            ->maxValue(fn (Get $get) => floatval($get('total') ?? 0))
                            ->minValue(0),
                        Textarea::make('notes')
                            ->default("Invoice ini merupakan tagihan resmi layanan Photomate.\nMohon melakukan pembayaran sebelum tanggal jatuh tempo.\nKonfirmasi pembayaran melalui WhatsApp admin.\nBiaya tambahan di luar paket akan ditagihkan terpisah.")
                            ->columnSpanFull(),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('event_date')
                    ->label('Tanggal Acara')
                    ->formatStateUsing(function ($record) {
                        if (!$record->event_date) {
                            return '-';
                        }
                        if ($record->event_end_date && $record->event_end_date->ne($record->event_date)) {
                            return $record->event_date->format('d M Y') . ' - ' . $record->event_end_date->format('d M Y');
                        }
                        return $record->event_date->format('d M Y');
                    })
                    ->sortable(),
                TextColumn::make('total')
                    ->money('IDR', locale: 'id')
                    ->description(fn (Invoice $record): ?string => 
                        $record->status === 'Sebagian Dibayar' 
                            ? 'DP: Rp ' . number_format($record->down_payment, 0, ',', '.') . ' (Sisa: Rp ' . number_format($record->total - $record->down_payment, 0, ',', '.') . ')' 
                            : null
                    )
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Draft' => 'gray',
                        'Belum Dibayar' => 'warning',
                        'Sebagian Dibayar' => 'info',
                        'Lunas' => 'success',
                        'Dibatalkan' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'Draft' => 'Draft',
                        'Belum Dibayar' => 'Belum Dibayar',
                        'Sebagian Dibayar' => 'Sebagian Dibayar',
                        'Lunas' => 'Lunas',
                        'Dibatalkan' => 'Dibatalkan',
                    ]),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('pdf')
                        ->label('Download PDF')
                        ->icon('heroicon-o-document-arrow-down')
                        ->url(fn (Invoice $record) => route('invoice.pdf', $record))
                        ->openUrlInNewTab(),
                    Action::make('markAsPaid')
                        ->label('Mark as Paid')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Invoice $record) => $record->update(['status' => 'Lunas']))
                        ->visible(fn (Invoice $record) => $record->status !== 'Lunas'),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }

    public static function updateTotals(Get $get, Set $set): void
    {
        $items = $get('invoiceItems');
        $subtotal = 0;
        
        if (is_array($items)) {
            foreach ($items as $item) {
                $subtotal += floatval($item['amount'] ?? 0);
            }
        }
        
        $discount = floatval($get('discount') ?? 0);
        $total = max(0, $subtotal - $discount);
        
        $set('subtotal', $subtotal);
        $set('total', $total);
    }

    public static function calculateDuration(Get $get, Set $set): void
    {
        $start = $get('start_time');
        $end = $get('end_time');
        
        if ($get('item_type') === 'product') {
            $set('duration', '-');
            return;
        }
        
        if ($start && $end) {
            try {
                $startTime = \Carbon\Carbon::parse($start);
                $endTime = \Carbon\Carbon::parse($end);
                
                if ($endTime->lt($startTime)) {
                    $endTime->addDay();
                }
                
                $diffInMinutes = $startTime->diffInMinutes($endTime);
                $hours = floor($diffInMinutes / 60);
                $minutes = $diffInMinutes % 60;
                
                $durationStr = '';
                if ($hours > 0) {
                    $durationStr .= $hours . ' Jam';
                }
                if ($minutes > 0) {
                    $durationStr .= ($hours > 0 ? ' ' : '') . $minutes . ' Menit';
                }
                
                $set('duration', $durationStr ?: '0 Jam');
            } catch (\Exception $e) {
                // ignore
            }
        } else {
            $set('duration', '0 Jam');
        }
    }
}
