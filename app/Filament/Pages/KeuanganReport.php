<?php

namespace App\Filament\Pages;

use App\Models\Cabang;
use App\Services\KeuanganService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KeuanganReport extends Page implements HasForms
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Laporan Keuangan';

    protected static ?string $slug = 'keuangan-laporan';

    protected static string $view = 'filament.pages.keuangan-report';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can('view_keuangan_report') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'periode' => KeuanganService::DEFAULT_PRESET,
            'cabang_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('periode')
                    ->label('Periode')
                    ->options(fn (): array => app(KeuanganService::class)->periodOptions())
                    ->default(KeuanganService::DEFAULT_PRESET)
                    ->selectablePlaceholder(false)
                    ->live(),

                Forms\Components\DatePicker::make('dari')
                    ->label('Dari Tanggal')
                    ->visible(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM)
                    ->required(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM),

                Forms\Components\DatePicker::make('sampai')
                    ->label('Sampai Tanggal')
                    ->visible(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM)
                    ->required(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM),

                Forms\Components\Select::make('cabang_id')
                    ->label('Cabang')
                    ->options(fn (): array => Cabang::query()
                        ->orderBy('nama_cabang')
                        ->pluck('nama_cabang', 'cabang_id')
                        ->all())
                    ->placeholder('Semua Cabang')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(4);
    }

    /**
     * @return array<string, mixed>
     */
    public function reportData(): array
    {
        $service = app(KeuanganService::class);

        $period = $service->resolvePeriod(
            $this->data['periode'] ?? null,
            $this->data['dari'] ?? null,
            $this->data['sampai'] ?? null,
        );

        $cabangId = filled($this->data['cabang_id'] ?? null) ? $this->data['cabang_id'] : null;

        return [
            'period' => $period,
            'cabangId' => $cabangId,
            'cabangName' => $cabangId
                ? (Cabang::find($cabangId)?->nama_cabang ?? 'Semua Cabang')
                : 'Semua Cabang',
            'summary' => $service->summary($period['start'], $period['end'], $cabangId),
            'categoryBreakdown' => $service->categoryBreakdown($period['start'], $period['end'], $cabangId),
            'branchBreakdown' => $service->branchBreakdown($period['start'], $period['end']),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn (): bool => Auth::user()?->can('export_keuangan_report') ?? false)
                ->action(function (): StreamedResponse {
                    $report = $this->reportData();

                    $records = app(KeuanganService::class)
                        ->transactionsForExport($report['period']['start'], $report['period']['end'], $report['cabangId'])
                        ->get();

                    $filename = 'laporan-keuangan-photomate-'
                        . $report['period']['start']->format('Ymd') . '-'
                        . $report['period']['end']->format('Ymd') . '.csv';

                    return response()->streamDownload(function () use ($records): void {
                        $handle = fopen('php://output', 'w');

                        fputs($handle, "\xEF\xBB\xBF");

                        fputcsv($handle, [
                            'ID',
                            'Tanggal',
                            'Jenis',
                            'Kategori',
                            'Nominal',
                            'Cabang',
                            'Metode Pembayaran',
                            'Deskripsi',
                            'Dibuat Oleh',
                            'Created At',
                            'Updated At',
                        ]);

                        foreach ($records as $record) {
                            fputcsv($handle, [
                                $record->id,
                                $record->transaction_date?->format('Y-m-d') ?? '-',
                                $record->type_label,
                                $record->category?->name ?? '-',
                                (int) $record->amount,
                                $record->cabang?->nama_cabang ?? 'Tanpa Cabang',
                                $record->paymentMethod?->name ?? '-',
                                $record->description ?: '-',
                                $record->creator?->nama_lengkap ?? '-',
                                $record->created_at?->format('Y-m-d H:i:s') ?? '-',
                                $record->updated_at?->format('Y-m-d H:i:s') ?? '-',
                            ]);
                        }

                        fclose($handle);
                    }, $filename, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                        'Content-Disposition' => "attachment; filename=\"$filename\"",
                    ]);
                }),

            Actions\Action::make('cetak')
                ->label('Cetak PDF')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->visible(fn (): bool => Auth::user()?->can('print_keuangan_report') ?? false)
                ->url(fn (): string => route('keuangan.laporan.cetak', [
                    'periode' => $this->data['periode'] ?? KeuanganService::DEFAULT_PRESET,
                    'dari' => $this->data['dari'] ?? null,
                    'sampai' => $this->data['sampai'] ?? null,
                    'cabang_id' => $this->data['cabang_id'] ?? null,
                ]))
                ->openUrlInNewTab(),
        ];
    }
}
