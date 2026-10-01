<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Keuangan\IncomeExpenseChart;
use App\Filament\Widgets\Keuangan\KeuanganStatsOverview;
use App\Filament\Widgets\Keuangan\LatestTransactionsWidget;
use App\Models\Cabang;
use App\Services\KeuanganService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Illuminate\Support\Facades\Auth;

class KeuanganDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Keuangan';

    protected static ?string $slug = 'keuangan';

    // Halaman ini extends Dashboard, sehingga path route diambil dari $routePath (bukan $slug).
    protected static string $routePath = '/keuangan';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('view_keuangan_dashboard') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Hanya widget modul keuangan yang tampil di halaman ini.
     *
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [
            KeuanganStatsOverview::class,
            IncomeExpenseChart::class,
            LatestTransactionsWidget::class,
        ];
    }

    public function getTitle(): string
    {
        return 'Keuangan';
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('periode')
                ->label('Periode')
                ->options(fn (): array => app(KeuanganService::class)->periodOptions())
                ->default(KeuanganService::DEFAULT_PRESET)
                ->selectablePlaceholder(false)
                ->live(),

            Forms\Components\DatePicker::make('dari')
                ->label('Dari Tanggal')
                ->visible(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM),

            Forms\Components\DatePicker::make('sampai')
                ->label('Sampai Tanggal')
                ->visible(fn (Get $get): bool => $get('periode') === KeuanganService::PRESET_CUSTOM),

            Forms\Components\Select::make('cabang_id')
                ->label('Cabang')
                ->options(fn (): array => Cabang::query()
                    ->orderBy('nama_cabang')
                    ->pluck('nama_cabang', 'cabang_id')
                    ->all())
                ->placeholder('Semua Cabang')
                ->live(),
        ]);
    }
}
