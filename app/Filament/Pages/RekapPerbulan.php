<?php

namespace App\Filament\Pages;

use App\Services\RekapPerbulanService;
use App\Utils\MonthHelper;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class RekapPerbulan extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Dashboard';

    protected static ?string $slug = 'rekap-perbulan';

    protected static string $view = 'filament.pages.rekap-perbulan';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin()
            || ($user?->can('view_keuangan_report') ?? false)
            || ($user?->can('view_keuangan_dashboard') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'bulan' => (int) now()->month,
            'tahun' => (int) now()->year,
        ]);
    }

    public function form(Form $form): Form
    {
        $service = app(RekapPerbulanService::class);

        return $form
            ->schema([
                Forms\Components\Select::make('bulan')
                    ->label('Bulan')
                    ->options(MonthHelper::getMonthOptions())
                    ->default((int) now()->month)
                    ->selectablePlaceholder(false)
                    ->live(),

                Forms\Components\Select::make('tahun')
                    ->label('Tahun')
                    ->options($service->getAvailableYears())
                    ->default((int) now()->year)
                    ->selectablePlaceholder(false)
                    ->live(),
            ])
            ->statePath('data')
            ->columns([
                'default' => 2,
                'sm' => 2,
                'md' => 4,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reportData(): array
    {
        $month = (int) ($this->data['bulan'] ?? now()->month);
        $year = (int) ($this->data['tahun'] ?? now()->year);

        return app(RekapPerbulanService::class)->getReportData($year, $month);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_pdf')
                ->label('Export PDF')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->url(fn (): string => route('keuangan.rekap-perbulan.cetak', [
                    'bulan' => (int) ($this->data['bulan'] ?? now()->month),
                    'tahun' => (int) ($this->data['tahun'] ?? now()->year),
                ]))
                ->openUrlInNewTab(),
        ];
    }
}
