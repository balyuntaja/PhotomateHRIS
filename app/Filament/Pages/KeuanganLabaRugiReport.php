<?php

namespace App\Filament\Pages;

use App\Models\FinancialAccount;
use App\Services\KeuanganService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KeuanganLabaRugiReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Laporan Laba Rugi';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Laporan Laba Rugi';

    protected static ?string $slug = 'keuangan-laba-rugi';

    protected static string $view = 'filament.pages.keuangan-laba-rugi-report';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_keuangan_report') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'periode' => KeuanganService::DEFAULT_PRESET,
            'account_id' => null,
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
                    ->required(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM)
                    ->live(),

                Forms\Components\DatePicker::make('sampai')
                    ->label('Sampai Tanggal')
                    ->visible(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM)
                    ->required(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM)
                    ->live(),

                Forms\Components\Select::make('account_id')
                    ->label('Akun')
                    ->options(fn (): array => FinancialAccount::query()
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->placeholder('Semua Akun')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(4);
    }

    /**
     * @return array{period: array{preset: string, start: \Illuminate\Support\Carbon, end: \Illuminate\Support\Carbon, label: string}, accountId: ?int, accountName: string}
     */
    protected function resolveReportFilters(): array
    {
        $service = app(KeuanganService::class);

        $period = $service->resolvePeriod(
            $this->data['periode'] ?? null,
            $this->data['dari'] ?? null,
            $this->data['sampai'] ?? null,
        );

        $requestedAccountId = $this->data['account_id'] ?? null;
        $accountId = is_scalar($requestedAccountId) && filled($requestedAccountId)
            ? FinancialAccount::query()->whereKey($requestedAccountId)->value('id')
            : null;

        return [
            'period' => $period,
            'accountId' => $accountId,
            'accountName' => $accountId
                ? (FinancialAccount::find($accountId)?->name ?? 'Semua Akun')
                : 'Semua Akun',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reportData(): array
    {
        $filters = $this->resolveReportFilters();

        $labaRugi = app(KeuanganService::class)->labaRugiReport(
            $filters['period']['start'],
            $filters['period']['end'],
            $filters['accountId'],
            true
        );

        return [
            'period' => $filters['period'],
            'accountId' => $filters['accountId'],
            'accountName' => $filters['accountName'],
            'pendapatan' => $labaRugi['pendapatan'],
            'beban' => $labaRugi['beban'],
            'ringkasan' => $labaRugi['ringkasan'],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn (): bool => Auth::user()?->isSuperAdmin() || (Auth::user()?->can('export_keuangan_report') ?? false))
                ->action(function (): StreamedResponse {
                    $report = $this->reportData();

                    $filename = 'laporan-laba-rugi-photomate-'
                        . $report['period']['start']->format('Ymd') . '-'
                        . $report['period']['end']->format('Ymd') . '.csv';

                    return response()->streamDownload(function () use ($report): void {
                        $handle = fopen('php://output', 'w');

                        // UTF-8 BOM agar terbaca dengan baik di Microsoft Excel
                        fwrite($handle, "\xEF\xBB\xBF");

                        fputcsv($handle, ['LAPORAN LABA RUGI PHOTOMATE']);
                        fputcsv($handle, ['Periode', $report['period']['label']]);
                        fputcsv($handle, ['Akun', $report['accountName']]);
                        fputcsv($handle, []);

                        // A. Pendapatan Usaha
                        fputcsv($handle, ['PENDAPATAN USAHA']);
                        fputcsv($handle, ['Keterangan', 'Nominal']);
                        foreach ($report['pendapatan']['rows'] as $branch => $nominal) {
                            fputcsv($handle, [$branch, $nominal]);
                        }
                        fputcsv($handle, ['Total Pendapatan', $report['pendapatan']['total']]);
                        fputcsv($handle, []);

                        // B. Beban Usaha
                        fputcsv($handle, ['BEBAN USAHA']);
                        fputcsv($handle, ['Keterangan', 'Nominal']);
                        foreach ($report['beban']['rows'] as $kategori => $nominal) {
                            fputcsv($handle, [$kategori, $nominal]);
                        }
                        if (empty($report['beban']['rows'])) {
                            fputcsv($handle, ['Beban Usaha', 0]);
                        }
                        fputcsv($handle, ['Total Beban Usaha', $report['beban']['total']]);
                        fputcsv($handle, []);

                        // C. Ringkasan Laba / Rugi
                        fputcsv($handle, ['RINGKASAN LABA / RUGI']);
                        fputcsv($handle, ['Total Pendapatan', $report['ringkasan']['total_pendapatan']]);
                        fputcsv($handle, ['Total Beban Usaha', $report['ringkasan']['total_beban']]);
                        fputcsv($handle, [
                            $report['ringkasan']['status_label'] . ' Bersih',
                            $report['ringkasan']['laba_rugi'],
                        ]);

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
                ->visible(fn (): bool => Auth::user()?->isSuperAdmin() || (Auth::user()?->can('print_keuangan_report') ?? false))
                ->url(fn (): string => route('keuangan.laporan.cetak', [
                    'format' => 'laba_rugi',
                    'periode' => $this->data['periode'] ?? KeuanganService::DEFAULT_PRESET,
                    'dari' => $this->data['dari'] ?? null,
                    'sampai' => $this->data['sampai'] ?? null,
                    'account_id' => $this->data['account_id'] ?? null,
                ]))
                ->openUrlInNewTab(),
        ];
    }
}
